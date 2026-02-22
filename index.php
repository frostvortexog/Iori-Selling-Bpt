<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

/* ================= CONFIG ================= */

$BOT_TOKEN = getenv("BOT_TOKEN");
$SUPA_URL  = getenv("SUPABASE_URL");
$SUPA_KEY  = getenv("SUPABASE_KEY");

$API = "https://api.telegram.org/bot$BOT_TOKEN/";

/* ================= HELPERS ================= */

function tg($m, $d){
    global $API;
    file_get_contents($API.$m."?".http_build_query($d));
}

function supa($method, $table, $query="", $body=null){
    global $SUPA_URL, $SUPA_KEY;
    $ch = curl_init("$SUPA_URL/rest/v1/$table$query");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPA_KEY",
            "Authorization: Bearer $SUPA_KEY",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ],
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function isAdmin($id){
    $r = supa("GET","admins","?user_id=eq.$id");
    return count($r) > 0;
}

function setting($key){
    $r = supa("GET","settings","?key=eq.$key");
    return $r[0]["value"] ?? "";
}

function setSetting($key,$value){
    supa("PATCH","settings","?key=eq.$key",["value"=>$value]);
}

function stepFile($id){
    return sys_get_temp_dir()."/step_$id";
}

/* ================= UPDATE SAFE PARSE ================= */

$update = json_decode(file_get_contents("php://input"), true);

if (isset($update["message"]["chat"]["id"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $text    = $update["message"]["text"] ?? null;
    $msg     = $update["message"];
    $cb      = null;
}
elseif (isset($update["callback_query"]["message"]["chat"]["id"])) {
    $chat_id = $update["callback_query"]["message"]["chat"]["id"];
    $cb      = $update["callback_query"]["data"];
    $text    = null;
    $msg     = $update["callback_query"]["message"];
}
else {
    exit; // Ignore unsupported updates safely
}

/* ================= START ================= */

if ($text === "/start") {

    $keyboard = [
        [["text"=>"🛒 Buy Coupon"]],
        [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
        [["text"=>"🆘 Support"]]
    ];

    if (isAdmin($chat_id)) {
        $keyboard[] = [["text"=>"🔐 Admin Panel"]];
    }

    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Welcome to Coupon Selling Bot",
        "reply_markup"=>json_encode([
            "keyboard"=>$keyboard,
            "resize_keyboard"=>true
        ])
    ]);
}

/* ================= USER ================= */

if ($text === "📦 Stock") {
    $r = supa("GET","coupons","?used=eq.false");
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Available coupons: ".count($r)
    ]);
}

if ($text === "🆘 Support") {
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Contact support: @Slursupportrobot"
    ]);
}

if ($text === "📜 My Orders") {
    $r = supa("GET","orders","?user_id=eq.$chat_id&status=eq.approved");
    $out = "";
    foreach ($r as $o) {
        $out .= "• Order ID: ".$o["id"]."\n";
    }
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>$out ?: "No orders found"
    ]);
}

/* ================= BUY FLOW ================= */

if ($text === "🛒 Buy Coupon") {
    $price = setting("price");
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"500 OFF on 500 (₹$price)\n\nHow many coupons do you want?"
    ]);
    file_put_contents(stepFile($chat_id),"qty");
}

$step = file_exists(stepFile($chat_id)) ? file_get_contents(stepFile($chat_id)) : null;

if ($step === "qty" && is_numeric($text)) {
    file_put_contents(stepFile($chat_id),"terms|$text");
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"⚠️ Once delivered, no refunds.\n\nDo you accept?",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"✅ Accept Terms","callback_data"=>"ACCEPT"]]
            ]
        ])
    ]);
}

if ($cb === "ACCEPT") {
    $qty = explode("|",file_get_contents(stepFile($chat_id)))[1];
    $total = $qty * setting("price");
    file_put_contents(stepFile($chat_id),"pay|$qty");

    tg("sendPhoto",[
        "chat_id"=>$chat_id,
        "photo"=>setting("qr"),
        "caption"=>"Pay ₹$total\nAfter payment click below",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"✅ I have done payment","callback_data"=>"PAID"]]
            ]
        ])
    ]);
}

if ($cb === "PAID") {
    file_put_contents(stepFile($chat_id),"payer");
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Send payer name"
    ]);
}

if ($step === "payer" && $text) {
    file_put_contents(stepFile($chat_id),"proof|$text");
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Send payment screenshot"
    ]);
}

if (isset($msg["photo"]) && str_starts_with($step,"proof")) {

    $payer = explode("|",$step)[1];
    $qty   = explode("|",file_get_contents(stepFile($chat_id)))[1];
    $oid   = uniqid();

    supa("POST","orders",[
        "id"=>$oid,
        "user_id"=>$chat_id,
        "qty"=>$qty,
        "payer"=>$payer,
        "status"=>"pending"
    ]);

    foreach (supa("GET","admins") as $a) {
        tg("sendMessage",[
            "chat_id"=>$a["user_id"],
            "text"=>"💰 New Payment\nUser: $chat_id\nQty: $qty\nPayer: $payer",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [["text"=>"✅ Approve","callback_data"=>"OK|$oid"]],
                    [["text"=>"❌ Decline","callback_data"=>"NO|$oid"]]
                ]
            ])
        ]);
    }

    unlink(stepFile($chat_id));
    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"⏳ Waiting for admin approval"
    ]);
}

/* ================= ADMIN PANEL ================= */

if ($text === "🔐 Admin Panel" && isAdmin($chat_id)) {

    tg("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"🔐 Admin Panel",
        "reply_markup"=>json_encode([
            "inline_keyboard"=>[
                [["text"=>"➕ Add Coupons","callback_data"=>"ADD"]],
                [["text"=>"💰 Change Price","callback_data"=>"PRICE"]],
                [["text"=>"🖼 Update QR","callback_data"=>"QR"]]
            ]
        ])
    ]);
}

/* ================= ADMIN ACTIONS ================= */

if ($cb && isAdmin($chat_id)) {

    if ($cb === "ADD") {
        file_put_contents(stepFile($chat_id),"add");
        tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send coupon codes (one per line)"]);
    }

    if ($cb === "PRICE") {
        file_put_contents(stepFile($chat_id),"price");
        tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send new price"]);
    }

    if ($cb === "QR") {
        file_put_contents(stepFile($chat_id),"qr");
        tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send QR image"]);
    }

    if (str_starts_with($cb,"OK")) {
        $oid = explode("|",$cb)[1];
        $order = supa("GET","orders","?id=eq.$oid")[0];
        $coupons = supa("GET","coupons","?used=eq.false&limit=".$order["qty"]);

        foreach ($coupons as $c) {
            supa("PATCH","coupons","?id=eq.{$c["id"]}",["used"=>true]);
            tg("sendMessage",[
                "chat_id"=>$order["user_id"],
                "text"=>"🎟 Coupon:\n".$c["code"]
            ]);
        }

        supa("PATCH","orders","?id=eq.$oid",["status"=>"approved"]);
    }

    if (str_starts_with($cb,"NO")) {
        $oid = explode("|",$cb)[1];
        supa("PATCH","orders","?id=eq.$oid",["status"=>"declined"]);
    }
}

/* ================= ADMIN STEPS ================= */

if ($step === "add" && $text) {
    foreach (explode("\n",$text) as $c) {
        if (trim($c)) supa("POST","coupons",["code"=>trim($c)]);
    }
    unlink(stepFile($chat_id));
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Coupons added"]);
}

if ($step === "price" && is_numeric($text)) {
    setSetting("price",(int)$text);
    unlink(stepFile($chat_id));
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Price updated"]);
}

if ($step === "qr" && isset($msg["photo"])) {
    setSetting("qr",$msg["photo"][0]["file_id"]);
    unlink(stepFile($chat_id));
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"QR updated"]);
}
