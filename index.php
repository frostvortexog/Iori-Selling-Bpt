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
    setState($uid,"qty");
    tg("sendMessage",[
        "chat_id"=>$cid,
        "text"=>"Enter quantity of coupons to buy (Available stock: $stock):"
    ]);
}

/* ---------- AFTER USER ENTERS QUANTITY ---------- */
$state = getState($uid);
if($state && $state["step"]=="qty" && is_numeric($text)){
    $qty = intval($text);
    $set = supa("/rest/v1/settings?id=eq.1")[0];
    $price = $set["coupon_price"];
    $total = $qty * $price;
    $purchase_time = date("Y-m-d H:i:s");

    // Save everything in state
    setState($uid,"terms",[
        "qty"=>$qty,
        "price"=>$price,
        "total"=>$total,
        "purchase_time"=>$purchase_time
    ]);

    $disclaimer = "⚠️ Disclaimer\n\n".
                  "1. Once coupon is delivered, no returns or refunds will be accepted.\n".
                  "2. All coupons are fresh and valid. Please check usage instructions carefully.\n".
                  "3. All sales are final. No refunds, no replacements, no exceptions.\n\n".
                  "✅ By purchasing, you agree to these terms.";

    tg("sendMessage",[
        "chat_id"=>$cid,
        "text"=>$disclaimer,
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"✅ Accept Terms","callback_data"=>"accept_terms"]]
            ]
        ])
    ]);
}

/* ---------- CALLBACK HANDLERS ---------- */
if($cb){
    $data = $cb["data"];
    
    // User Accept Terms
    if($data=="accept_terms"){
        $state = getState($uid);
        if(!$state) exit;

        $qty = $state["data"]["qty"];
        $total = $state["data"]["total"];
        $purchase_time = $state["data"]["purchase_time"];

        $set = supa("/rest/v1/settings?id=eq.1")[0];

        // Move to paid step
        setState($uid,"paid",$state["data"]);

        tg("sendPhoto",[
            "chat_id"=>$cid,
            "photo"=>$set["qr_file_id"],
            "caption"=>"🎟️ Coupon Purchase\n\nQty: $qty\nTotal: ₹$total\nTime: $purchase_time",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [["text"=>"💸 I have done the payment","callback_data"=>"paid"]]
                ]
            ])
        ]);
    }

    // User Done Payment
    if($data=="paid"){
        $state = getState($uid);
        if(!$state) exit;
        setState($uid,"payer",$state["data"]);
        tg("sendMessage",["chat_id"=>$cid,"text"=>"Enter payer name:"]);
    }

    /* ---------- ADMIN PANEL BUTTONS ---------- */
    if(in_array($uid,$ADMIN_IDS)){
        switch($data){
            case "change_price":
                setState($uid,"change_price");
                tg("sendMessage",["chat_id"=>$cid,"text"=>"Enter new price:"]);
                break;
            case "add_coupon":
                setState($uid,"add_coupon");
                tg("sendMessage",["chat_id"=>$cid,"text"=>"Send coupon codes (comma or newline separated):"]);
                break;
            case "remove_coupon":
                setState($uid,"remove_coupon");
                tg("sendMessage",["chat_id"=>$cid,"text"=>"How many coupons to remove?"]);
                break;
            case "free_coupon":
                setState($uid,"free_coupon");
                tg("sendMessage",["chat_id"=>$cid,"text"=>"How many free coupons to give?"]);
                break;
            case "update_qr":
                setState($uid,"update_qr");
                tg("sendMessage",["chat_id"=>$cid,"text"=>"Send QR image"]);
                break;
        }
    }

    /* ---------- ADMIN APPROVE / DECLINE ORDERS ---------- */
    foreach($ADMIN_IDS as $admin){
        if($uid==$admin && strpos($data,"approve_")===0){
            $user_id=str_replace("approve_","",$data);
            $orders=supa("/rest/v1/orders?user_id=eq.$user_id&status=eq.pending&order=created_at.desc");
            if(!$orders) exit;
            $order=$orders[0];
            $qty=$order["quantity"];
            $coupons=supa("/rest/v1/coupons?is_used=eq.false&limit=$qty");
            if(count($coupons)<$qty){ tg("sendMessage",["chat_id"=>$admin,"text"=>"Not enough coupons in stock"]); exit; }
            $codes=[]; foreach($coupons as $c){ $codes[]=$c["code"]; supa("/rest/v1/coupons?id=eq.{$c['id']}","PATCH",["is_used"=>true]); }
            supa("/rest/v1/orders?id=eq.{$order['id']}","PATCH",["status"=>"approved"]);
            tg("sendMessage",["chat_id"=>$user_id,"text"=>"✅ Order #{$order['id']} approved!\n\nYour coupons:\n".implode("\n",$codes)]);
            tg("sendMessage",["chat_id"=>$admin,"text"=>"Order #{$order['id']} approved."]);
        }
        if($uid==$admin && strpos($data,"decline_")===0){
            $user_id=str_replace("decline_","",$data);
            $orders=supa("/rest/v1/orders?user_id=eq.$user_id&status=eq.pending&order=created_at.desc");
            if(!$orders) exit;
            $order=$orders[0];
            supa("/rest/v1/orders?id=eq.{$order['id']}","PATCH",["status"=>"declined"]);
            tg("sendMessage",["chat_id"=>$user_id,"text"=>"❌ Order #{$order['id']} declined."]);
            tg("sendMessage",["chat_id"=>$admin,"text"=>"Order #{$order['id']} declined."]);
        }
    }
}

/* ---------- USER PAYMENT FLOW ---------- */
$state = getState($uid);
if($state){
    switch($state["step"]){
        case "payer":
            if($text){
                $d=$state["data"];
                $d["payer"]=$text;
                setState($uid,"screenshot",$d);
                tg("sendMessage",["chat_id"=>$cid,"text"=>"Send payment screenshot:"]);
            }
            break;
        case "screenshot":
            if(isset($msg["photo"])){
                $d=$state["data"];
                $file=end($msg["photo"])["file_id"];
                supa("/rest/v1/orders","POST",[
                    "user_id"=>$uid,
                    "quantity"=>$d["qty"],
                    "total"=>$d["total"],
                    "payer_name"=>$d["payer"],
                    "screenshot"=>$file,
                    "status"=>"pending",
                    "created_at"=>$d["purchase_time"]
                ]);
                clearState($uid);
                tg("sendMessage",["chat_id"=>$cid,"text"=>"⏳ Waiting for admin approval"]);
                foreach($ADMIN_IDS as $admin){
                    tg("sendPhoto",[
                        "chat_id"=>$admin,
                        "photo"=>$file,
                        "caption"=>"📥 New Order\nUser: $uid\nQty: {$d['qty']}\nTotal: ₹{$d['total']}\nPayer: {$d['payer']}\nTime: {$d['purchase_time']}",
                        "reply_markup"=>json_encode([
                            "inline_keyboard"=>[
                                [["text"=>"✅ Approve","callback_data"=>"approve_$uid"]],
                                [["text"=>"❌ Decline","callback_data"=>"decline_$uid"]]
                            ]
                        ])
                    ]);
                }
            }
            break;
    }
}

/* ---------- ADMIN STATE HANDLERS ---------- */
if($state && in_array($uid,$ADMIN_IDS)){
    switch($state["step"]){
        case "change_price":
            if(is_numeric($text)){
                supa("/rest/v1/settings?id=eq.1","PATCH",["coupon_price"=>intval($text)]);
                tg("sendMessage",["chat_id"=>$cid,"text"=>"✅ Price updated to ₹$text"]);
                clearState($uid);
            }
            break;
        case "add_coupon":
            $codes = preg_split("/[\r\n,]+/", $text);
            foreach($codes as $code){
                $code=trim($code);
                if($code=="") continue;
                supa("/rest/v1/coupons","POST",["code"=>$code]);
            }
            tg("sendMessage",["chat_id"=>$cid,"text"=>"✅ Coupons added successfully"]);
            clearState($uid);
            break;
        case "remove_coupon":
            if(is_numeric($text)){
                $qty=intval($text);
                $coupons=supa("/rest/v1/coupons?is_used=eq.false&limit=$qty");
                foreach($coupons as $c){ supa("/rest/v1/coupons?id=eq.{$c['id']}","PATCH",["is_used"=>true]); }
                tg("sendMessage",["chat_id"=>$cid,"text"=>"✅ $qty coupons removed from stock"]);
                clearState($uid);
            }
            break;
        case "free_coupon":
            if(is_numeric($text)){
                $qty=intval($text);
                $coupons=supa("/rest/v1/coupons?is_used=eq.false&limit=$qty");
                $codes=[];
                foreach($coupons as $c){
                    $codes[]=$c["code"];
                    supa("/rest/v1/coupons?id=eq.{$c['id']}","PATCH",["is_used"=>true]);
                }
                tg("sendMessage",["chat_id"=>$cid,"text"=>"✅ Free coupons:\n".implode("\n",$codes)]);
                clearState($uid);
            }
            break;
        case "update_qr":
            if(isset($msg["photo"])){
                $file=end($msg["photo"])["file_id"];
                supa("/rest/v1/settings?id=eq.1","PATCH",["qr_file_id"=>$file]);
                tg("sendMessage",["chat_id"=>$cid,"text"=>"✅ QR updated successfully"]);
                clearState($uid);
            }
            break;
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
