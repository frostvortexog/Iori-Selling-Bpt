<?php
// ================= CONFIG =================
$BOT_TOKEN = getenv("BOT_TOKEN");
$SUPA_URL = getenv("SUPABASE_URL");
$SUPA_KEY = getenv("SUPABASE_KEY");
$ADMIN_IDS = array_map('trim', explode(",", getenv("ADMIN_IDS")));
$SUPPORT = getenv("SUPPORT_USERNAME") ?: "@Slursupportrobot";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";

// ================= HELPERS =================
function tg($method, $data){
    global $API;
    $ch = curl_init($API.$method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    curl_exec($ch); curl_close($ch);
}

function sb($endpoint,$method="GET",$body=null){
    global $SUPA_URL,$SUPA_KEY;
    $ch = curl_init("$SUPA_URL/rest/v1/$endpoint");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>[
            "apikey: $SUPA_KEY",
            "Authorization: Bearer $SUPA_KEY",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS=>$body?json_encode($body):null
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res,true);
}

function setting($key){ $r=sb("settings?key=eq.$key"); return $r[0]['value']??""; }
function stock(){ $r=sb("coupons?is_used=eq.false"); return count($r); }
function isAdmin($id){ global $ADMIN_IDS; return in_array($id,$ADMIN_IDS); }

// ================= STATE =================
$STATE_FILE = __DIR__."/state.json";
$STATE = file_exists($STATE_FILE)?json_decode(file_get_contents($STATE_FILE),true):[];
function setState($id,$data){ global $STATE,$STATE_FILE;$STATE[$id]=$data;file_put_contents($STATE_FILE,json_encode($STATE));}
function getState($id){ global $STATE; return $STATE[$id]??null;}
function clearState($id){ global $STATE,$STATE_FILE; unset($STATE[$id]); file_put_contents($STATE_FILE,json_encode($STATE));}

// ================= UPDATE =================
$update = json_decode(file_get_contents("php://input"), true);
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$user_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
$text = $update['message']['text'] ?? null;
$data = $update['callback_query']['data'] ?? null;

// ================= START MENU =================
if($text=="/start" || $text=="🔄 Menu"){
    clearState($chat_id);
    $inline_buttons = [
        [["text"=>"🛒 Buy Coupon","callback_data"=>"buy"]],
        [["text"=>"📦 Stock","callback_data"=>"stock"]],
        [["text"=>"📞 Support","callback_data"=>"support"]],
        [["text"=>"📝 Orders","callback_data"=>"orders"]]
    ];
    if(isAdmin($user_id)) $inline_buttons[]=[["text"=>"⚙ Admin Panel","callback_data"=>"admin"]];
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"🎟 Welcome to Coupon Bot","reply_markup"=>json_encode(["inline_keyboard"=>$inline_buttons])]);
}

// ================= USER BUTTONS =================
if($data=="stock"){ tg("sendMessage",["chat_id"=>$chat_id,"text"=>"📦 Available coupons: ".stock()]); }
if($data=="support"){ tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Contact: $SUPPORT"]); }
if($data=="orders"){
    $orders=sb("orders?user_id=eq.$user_id");
    $msg="Your Orders:\n";
    foreach($orders as $o){ $msg.="ID:".$o['id']." Qty:".$o['qty']." Total:".$o['total']." Status:".$o['status']."\n"; }
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
}

// ================= BUY COUPON FLOW =================
if($data=="buy"){
    setState($chat_id,["step"=>"terms"]);
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"⚠️ Disclaimer

1. Once coupon is delivered, no returns or refunds will be accepted.
2. All coupons are fresh and valid. Please check usage instructions carefully.
3. All sales are final. No refunds, no replacements, no exceptions.

✅ By purchasing, you agree to these terms.",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[[["text"=>"✅ Accept Terms","callback_data"=>"accept_terms"]],[["text"=>"❌ Cancel","callback_data"=>"cancel"]]]
        ])
    ]);
}

// ================= ACCEPT TERMS =================
if($data=="accept_terms"){
    setState($chat_id,["step"=>"qty"]);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter quantity of coupons to buy:"]);
}

// ================= QUANTITY =================
$state = getState($chat_id);
if($state && $state['step']=="qty" && is_numeric($text)){
    setState($chat_id,["step"=>"payer","qty"=>$text]);
    $price = setting("price_500"); // single coupon price
    $total = $price*$text;
    tg("sendPhoto",["chat_id"=>$chat_id,"photo"=>setting("qr"),"caption"=>"Total amount: ₹$total\nSend payer name after payment."]);
}

// ================= PAYER NAME =================
$state = getState($chat_id);
if($state && $state['step']=="payer" && !empty($text)){
    $state['step']="proof"; $state['payer']=$text; setState($chat_id,$state);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payment screenshot"]);
}

// ================= PAYMENT PROOF =================
$state = getState($chat_id);
if($state && $state['step']=="proof" && isset($update['message']['photo'])){
    $file_id=end($update['message']['photo'])['file_id'];
    $qty=$state['qty']; $payer=$state['payer'];
    $price=setting("price_500"); $total=$price*$qty;

    $order=sb("orders","POST",[
        "user_id"=>$user_id,
        "username"=>"@".$update['message']['from']['username'],
        "qty"=>$qty,
        "total"=>$total,
        "payer"=>$payer,
        "proof"=>$file_id,
        "quality"=>"500_off",
        "status"=>"pending"
    ]);

    foreach($ADMIN_IDS as $admin){
        tg("sendPhoto",[
            "chat_id"=>$admin,
            "photo"=>$file_id,
            "caption"=>"🧾 Order #".$order[0]['id']."\nUser: @$user_id\nQty: $qty\nTotal: ₹$total\nPayer: $payer",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[[["text"=>"✅ Approve","callback_data"=>"approve_".$order[0]['id']]], [["text"=>"❌ Decline","callback_data"=>"decline_".$order[0]['id']]]]
            ])
        ]);
    }
    clearState($chat_id);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"⏳ Waiting for admin approval"]);
}

// ================= ADMIN PANEL =================
if($data=="admin" && isAdmin($user_id)){
    $admin_buttons = [
        [["text"=>"➕ Add Coupons","callback_data"=>"add_coupon"]],
        [["text"=>"➖ Remove Coupons","callback_data"=>"remove_coupon"]],
        [["text"=>"💰 Change Price","callback_data"=>"change_price"]],
        [["text"=>"🖼 Update QR","callback_data"=>"update_qr"]],
        [["text"=>"📦 View Stock","callback_data"=>"view_stock"]]
    ];
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"⚙ Admin Panel","reply_markup"=>json_encode(["inline_keyboard"=>$admin_buttons])]);
}

// ================= ADMIN APPROVE / DECLINE =================
if(strpos($data,"approve_")===0 && isAdmin($user_id)){
    $oid = str_replace("approve_","",$data);
    $order = sb("orders?id=eq.$oid")[0];
    $coupons = sb("coupons?is_used=eq.false&limit=".$order['qty']);
    if(!$coupons){ tg("sendMessage",["chat_id"=>$order['user_id'],"text"=>"❌ Not enough stock"]); }
    else{
        foreach($coupons as $c) sb("coupons?id=eq.".$c['id'],"PATCH",["is_used"=>true]);
        tg("sendMessage",["chat_id"=>$order['user_id'],"text"=>"✅ Payment approved!\nYour coupons:\n".implode("\n",array_column($coupons,'code'))]);
        sb("orders?id=eq.$oid","PATCH",["status"=>"approved"]);
    }
}
if(strpos($data,"decline_")===0 && isAdmin($user_id)){
    $oid = str_replace("decline_","",$data);
    $order = sb("orders?id=eq.$oid")[0];
    tg("sendMessage",["chat_id"=>$order['user_id'],"text"=>"❌ Payment declined by admin"]);
    sb("orders?id=eq.$oid","PATCH",["status"=>"declined"]);
}
