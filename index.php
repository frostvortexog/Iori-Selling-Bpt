<?php
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_IDS = explode(",", getenv("ADMIN_IDS"));
$SUPA_URL  = getenv("SUPABASE_URL");
$SUPA_KEY  = getenv("SUPABASE_KEY");

$update = json_decode(file_get_contents("php://input"), true);
if(!$update) exit;

function tg($method,$data){
    global $BOT_TOKEN;
    file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/$method", false,
        stream_context_create([
            "http"=>[
                "method"=>"POST",
                "header"=>"Content-Type: application/json",
                "content"=>json_encode($data)
            ]
        ])
    );
}

function supa($endpoint,$method="GET",$body=null){
    global $SUPA_URL,$SUPA_KEY;
    $opts = [
        "http"=>[
            "method"=>$method,
            "header"=>"apikey:$SUPA_KEY\r\nAuthorization:Bearer $SUPA_KEY\r\nContent-Type:application/json",
            "content"=>$body ? json_encode($body) : null
        ]
    ];
    return json_decode(file_get_contents($SUPA_URL.$endpoint,false,stream_context_create($opts)),true);
}

// incoming message or callback
$msg = $update["message"] ?? null;
$cb  = $update["callback_query"] ?? null;

$uid = $msg["from"]["id"] ?? $cb["from"]["id"];
$cid = $msg["chat"]["id"] ?? $cb["message"]["chat"]["id"];
$text = $msg["text"] ?? null;

/* ---------- STATE HANDLING ---------- */
function setState($uid,$step,$data=[]){
    supa("/rest/v1/user_state","POST",["user_id"=>$uid,"step"=>$step,"data"=>$data]);
}
function getState($uid){
    $r = supa("/rest/v1/user_state?user_id=eq.$uid");
    return $r[0] ?? null;
}
function clearState($uid){
    supa("/rest/v1/user_state?user_id=eq.$uid","DELETE");
}

/* ---------- START ---------- */
if($text=="/start"){
    clearState($uid);
    tg("sendMessage",[
        "chat_id"=>$cid,
        "text"=>"🎟️ Welcome to Coupon Store",
        "reply_markup"=>json_encode([
            "keyboard"=>[
                [["text"=>"🛒 Buy Coupon"]],
                [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
                [["text"=>"🆘 Support"]]
            ],
            "resize_keyboard"=>true
        ])
    ]);
}

/* ---------- USER BUTTONS ---------- */
if($text=="🆘 Support"){
    tg("sendMessage",["chat_id"=>$cid,"text"=>"Contact support:\n@Slursupportrobot"]);
}
if($text=="📦 Stock"){
    $stock = count(supa("/rest/v1/coupons?is_used=eq.false"));
    tg("sendMessage",["chat_id"=>$cid,"text"=>"📦 Available Coupons: $stock"]);
}
if($text=="📜 My Orders"){
    $orders = supa("/rest/v1/orders?user_id=eq.$uid&order=created_at.desc");
    if(!$orders){
        tg("sendMessage",["chat_id"=>$cid,"text"=>"No orders yet"]);
        exit;
    }
    $out="";
    foreach($orders as $o){
        $out.="🆔 {$o['id']} | Qty {$o['quantity']} | ₹{$o['total']} | {$o['status']}\n";
    }
    tg("sendMessage",["chat_id"=>$cid,"text"=>$out]);
}

/* ---------- BUY FLOW ---------- */
if($text=="🛒 Buy Coupon"){
    $stock = count(supa("/rest/v1/coupons?is_used=eq.false"));
    if($stock<=0){
        tg("sendMessage",["chat_id"=>$cid,"text"=>"❌ No stock available"]);
        exit;
    }
    $price = supa("/rest/v1/settings?id=eq.1")[0]["coupon_price"];
    setState($uid,"qty");
    tg("sendMessage",["chat_id"=>$cid,"text"=>"₹500 OFF on ₹500 (₹$price)\n\nEnter quantity:"]);
}

$state = getState($uid);
if($state && $state["step"]=="qty" && is_numeric($text)){
    setState($uid,"terms",["qty"=>$text]);
    tg("sendMessage",[
        "chat_id"=>$cid,
        "text"=>"⚠️ Disclaimer\n\n1. No refund\n2. Fresh coupons\n3. Final sale",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"✅ Accept Terms","callback_data"=>"accept_terms"]]
            ]
        ])
    ]);
}

/* ---------- CALLBACKS ---------- */
if($cb){
    $data = $cb["data"];

    if($data=="accept_terms"){
        $st = getState($uid);
        $qty = $st["data"]["qty"];
        $set = supa("/rest/v1/settings?id=eq.1")[0];
        $total = $qty * $set["coupon_price"];
        setState($uid,"paid",["qty"=>$qty,"total"=>$total]);
        tg("sendPhoto",[
            "chat_id"=>$cid,
            "photo"=>$set["qr_file_id"],
            "caption"=>"Qty: $qty\nTotal: ₹$total",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [["text"=>"💸 I have done the payment","callback_data"=>"paid"]]
                ]
            ])
        ]);
    }

    if($data=="paid"){
        setState($uid,"payer",getState($uid)["data"]);
        tg("sendMessage",["chat_id"=>$cid,"text"=>"Enter payer name"]);
    }

    /* ---------- ADMIN APPROVE / DECLINE ---------- */
    foreach($ADMIN_IDS as $admin){
        if($uid==$admin && strpos($data,"approve_")===0){
            $user_id = str_replace("approve_","",$data);
            $orders = supa("/rest/v1/orders?user_id=eq.$user_id&status=eq.pending&order=created_at.desc");
            if(!$orders) exit;
            $order = $orders[0];
            $qty = $order["quantity"];
            $coupons = supa("/rest/v1/coupons?is_used=eq.false&limit=$qty");
            if(count($coupons)<$qty){
                tg("sendMessage",["chat_id"=>$admin,"text"=>"Not enough coupons in stock"]);
                exit;
            }
            $codes=[];
            foreach($coupons as $c){
                $codes[]=$c["code"];
                supa("/rest/v1/coupons?id=eq.{$c['id']}","PATCH",["is_used"=>true]);
            }
            supa("/rest/v1/orders?id=eq.{$order['id']}","PATCH",["status"=>"approved"]);
            tg("sendMessage",["chat_id"=>$user_id,"text"=>"✅ Your order #{$order['id']} approved!\n\nYour coupons:\n".implode("\n",$codes)]);
            tg("sendMessage",["chat_id"=>$admin,"text"=>"Order #{$order['id']} approved and coupons delivered."]);
        }
        if($uid==$admin && strpos($data,"decline_")===0){
            $user_id = str_replace("decline_","",$data);
            $orders = supa("/rest/v1/orders?user_id=eq.$user_id&status=eq.pending&order=created_at.desc");
            if(!$orders) exit;
            $order = $orders[0];
            supa("/rest/v1/orders?id=eq.{$order['id']}","PATCH",["status"=>"declined"]);
            tg("sendMessage",["chat_id"=>$user_id,"text"=>"❌ Your order #{$order['id']} declined."]);
            tg("sendMessage",["chat_id"=>$admin,"text"=>"Order #{$order['id']} declined."]);
        }
    }

    /* ---------- ADMIN PANEL BUTTON CALLBACKS ---------- */
    if(in_array($uid,$ADMIN_IDS)){
        if($data=="change_price"){
            setState($uid,"change_price");
            tg("sendMessage",["chat_id"=>$cid,"text"=>"Enter new price for the coupon:"]);
        }
        if($data=="update_qr"){
            setState($uid,"update_qr");
            tg("sendMessage",["chat_id"=>$cid,"text"=>"Send new QR image"]);
        }
    }
}

/* ---------- PAYMENT FLOW ---------- */
if($state && $state["step"]=="payer" && $text){
    $d=$state["data"];
    $d["payer"]=$text;
    setState($uid,"screenshot",$d);
    tg("sendMessage",["chat_id"=>$cid,"text"=>"Send payment screenshot"]);
}
if($msg && isset($msg["photo"]) && $state && $state["step"]=="screenshot"){
    $file=end($msg["photo"])["file_id"];
    $d=$state["data"];
    supa("/rest/v1/orders","POST",[
        "user_id"=>$uid,
        "quantity"=>$d["qty"],
        "total"=>$d["total"],
        "payer_name"=>$d["payer"],
        "screenshot"=>$file,
        "status"=>"pending"
    ]);
    clearState($uid);
    tg("sendMessage",["chat_id"=>$cid,"text"=>"⏳ Waiting for admin approval"]);

    foreach($ADMIN_IDS as $admin){
        tg("sendPhoto",[
            "chat_id"=>$admin,
            "photo"=>$file,
            "caption"=>"New Order\nUser: $uid\nQty: {$d['qty']}\n₹{$d['total']}\nPayer: {$d['payer']}",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [["text"=>"✅ Approve","callback_data"=>"approve_$uid"]],
                    [["text"=>"❌ Decline","callback_data"=>"decline_$uid"]]
                ]
            ])
        ]);
    }
}

/* ---------- ADMIN PANEL MAIN MENU ---------- */
if(in_array($uid,$ADMIN_IDS) && $text=="/admin"){
    tg("sendMessage",[
        "chat_id"=>$cid,
        "text"=>"🛠 Admin Panel",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"Change Price","callback_data"=>"change_price"]],
                [["text"=>"Add Coupon","callback_data"=>"add_coupon"]],
                [["text"=>"Remove Coupon","callback_data"=>"remove_coupon"]],
                [["text"=>"Free Coupon","callback_data"=>"free_coupon"]],
                [["text"=>"Update QR","callback_data"=>"update_qr"]]
            ]
        ])
    ]);
}
