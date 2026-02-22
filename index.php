<?php

$BOT_TOKEN = getenv("BOT_TOKEN");
$SUPA_URL  = getenv("SUPABASE_URL");
$SUPA_KEY  = getenv("SUPABASE_KEY");
$ADMIN_IDS = array_map("trim", explode(",", getenv("ADMIN_IDS")));

$update = json_decode(file_get_contents("php://input"), true);

$chat_id =
    $update["message"]["chat"]["id"]
    ?? $update["callback_query"]["message"]["chat"]["id"]
    ?? null;

$text  = $update["message"]["text"] ?? null;
$photo = $update["message"]["photo"] ?? null;
$data  = $update["callback_query"]["data"] ?? null;

/* ---------- HELPERS ---------- */

function tg($method, $data){
    global $BOT_TOKEN;
    file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/$method?" . http_build_query($data));
}

function supa($method, $endpoint, $data=null){
    global $SUPA_URL, $SUPA_KEY;
    $opts = [
        "http" => [
            "method" => $method,
            "header" =>
                "apikey: $SUPA_KEY\r\n".
                "Authorization: Bearer $SUPA_KEY\r\n".
                "Content-Type: application/json\r\n",
            "content" => $data ? json_encode($data) : null
        ]
    ];
    return json_decode(
        file_get_contents("$SUPA_URL/rest/v1/$endpoint", false, stream_context_create($opts)),
        true
    );
}

function isAdmin($id){
    global $ADMIN_IDS;
    return in_array((string)$id, $ADMIN_IDS, true);
}

/* ---------- ADMIN STEP HELPERS ---------- */

function getAdminStep($admin_id){
    $r = supa("GET", "admin_steps?admin_id=eq.$admin_id");
    return $r[0] ?? null;
}

function setAdminStep($admin_id, $step){
    supa("POST", "admin_steps", [
        "admin_id" => $admin_id,
        "step" => $step,
        "temp" => ""
    ]);
}

function updateAdminStep($admin_id, $step, $temp=""){
    supa("PATCH", "admin_steps?admin_id=eq.$admin_id", [
        "step" => $step,
        "temp" => $temp
    ]);
}

function clearAdminStep($admin_id){
    supa("DELETE", "admin_steps?admin_id=eq.$admin_id");
}

/* ---------- START ---------- */

if ($text === "/start") {
    tg("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Welcome to Coupon Bot",
        "reply_markup" => json_encode([
            "keyboard" => array_values(array_filter([
                [["text"=>"🛒 Buy Coupon"]],
                [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
                [["text"=>"🆘 Support"]],
                isAdmin($chat_id) ? [["text"=>"🔐 Admin Panel"]] : null
            ])),
            "resize_keyboard" => true
        ])
    ]);
}

/* ---------- ADMIN PANEL ---------- */

if ($text === "🔐 Admin Panel" && isAdmin($chat_id)) {
    clearAdminStep($chat_id);
    tg("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🔐 Admin Panel",
        "reply_markup" => json_encode([
            "inline_keyboard" => [
                [["text"=>"💰 Change Price","callback_data"=>"ap_price"]],
                [["text"=>"➕ Add Coupons","callback_data"=>"ap_add"]],
                [["text"=>"➖ Remove Coupons","callback_data"=>"ap_remove"]],
                [["text"=>"🎁 Free Coupons","callback_data"=>"ap_free"]],
                [["text"=>"🖼 Update QR","callback_data"=>"ap_qr"]],
                [["text"=>"📦 View Stock","callback_data"=>"ap_stock"]]
            ]
        ])
    ]);
}

/* ---------- ADMIN CALLBACK HANDLER ---------- */

if ($data && isAdmin($chat_id)) {

    clearAdminStep($chat_id);

    if ($data === "ap_price") {
        setAdminStep($chat_id, "price");
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"Send new coupon price"]);
    }

    if ($data === "ap_add") {
        setAdminStep($chat_id, "add");
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"Send coupon codes (one per line)"]);
    }

    if ($data === "ap_remove") {
        setAdminStep($chat_id, "remove");
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"How many coupons to remove?"]);
    }

    if ($data === "ap_free") {
        setAdminStep($chat_id, "free");
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"How many coupons to give free?"]);
    }

    if ($data === "ap_qr") {
        setAdminStep($chat_id, "qr");
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"Send QR image"]);
    }

    if ($data === "ap_stock") {
        $count = count(supa("GET","coupons?select=id"));
        tg("sendMessage", ["chat_id"=>$chat_id,"text"=>"📦 Stock: $count"]);
    }
}

/* ---------- ADMIN STEP PROCESSING ---------- */

$adminStep = isAdmin($chat_id) ? getAdminStep($chat_id) : null;

if ($adminStep) {

    switch ($adminStep["step"]) {

        case "price":
            if (is_numeric($text)) {
                supa("PATCH","settings?id=eq.1",["price"=>$text]);
                tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Price updated"]);
                clearAdminStep($chat_id);
            }
            break;

        case "add":
            if ($text) {
                foreach (explode("\n",$text) as $c) {
                    supa("POST","coupons",["code"=>trim($c)]);
                }
                tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Coupons added"]);
                clearAdminStep($chat_id);
            }
            break;

        case "remove":
            if (is_numeric($text)) {
                $list = supa("GET","coupons?limit=$text");
                foreach ($list as $c) {
                    supa("DELETE","coupons?id=eq.".$c["id"]);
                }
                tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Coupons removed"]);
                clearAdminStep($chat_id);
            }
            break;

        case "free":
            if (is_numeric($text)) {
                $list = supa("GET","coupons?limit=$text");
                $msg="🎁 Free Coupons:\n";
                foreach ($list as $c) {
                    $msg.=$c["code"]."\n";
                    supa("DELETE","coupons?id=eq.".$c["id"]);
                }
                tg("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
                clearAdminStep($chat_id);
            }
            break;

        case "qr":
            if ($photo) {
                $file = end($photo)["file_id"];
                supa("PATCH","settings?id=eq.1",["qr_file_id"=>$file]);
                tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ QR updated"]);
                clearAdminStep($chat_id);
            }
            break;
    }
}

/* ---------- BASIC ---------- */

if ($text === "📦 Stock") {
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Stock: ".count(supa("GET","coupons?select=id"))]);
}

if ($text === "🆘 Support") {
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"@Slursupportrobot"]);
}
