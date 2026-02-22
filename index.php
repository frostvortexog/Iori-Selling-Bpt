<?php

$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = getenv("ADMIN_ID");
$SUPA_URL  = getenv("SUPABASE_URL");
$SUPA_KEY  = getenv("SUPABASE_KEY");

$update = json_decode(file_get_contents("php://input"), true);

$chat_id = $update["message"]["chat"]["id"] ?? $update["callback_query"]["message"]["chat"]["id"] ?? null;
$user_id = $chat_id;
$text    = $update["message"]["text"] ?? "";
$photo   = $update["message"]["photo"] ?? null;
$data    = $update["callback_query"]["data"] ?? null;

/* ---------- HELPERS ---------- */

function tg($method,$data){
  global $BOT_TOKEN;
  file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/$method?".http_build_query($data));
}

function supa($method,$endpoint,$data=null){
  global $SUPA_URL,$SUPA_KEY;
  $opts=["http"=>[
    "method"=>$method,
    "header"=>"apikey: $SUPA_KEY\r\nAuthorization: Bearer $SUPA_KEY\r\nContent-Type: application/json\r\n",
    "content"=>$data?json_encode($data):null
  ]];
  return json_decode(file_get_contents("$SUPA_URL/rest/v1/$endpoint",false,stream_context_create($opts)),true);
}

function setAdminStep($step,$val=""){
  global $ADMIN_ID;
  supa("POST","admin_steps",["admin_id"=>$ADMIN_ID,"step"=>$step,"temp_value"=>$val]);
}

function getAdminStep(){
  global $ADMIN_ID;
  $r=supa("GET","admin_steps?admin_id=eq.$ADMIN_ID");
  return $r[0]??null;
}

function clearAdminStep(){
  global $ADMIN_ID;
  supa("DELETE","admin_steps?admin_id=eq.$ADMIN_ID");
}

/* ---------- START ---------- */

if($text=="/start"){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Welcome",
    "reply_markup"=>json_encode([
      "keyboard"=>[
        [["text"=>"🛒 Buy Coupon"]],
        [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
        [["text"=>"🆘 Support"]],
        $chat_id==$ADMIN_ID?[["text"=>"🔐 Admin Panel"]]:[]
      ],
      "resize_keyboard"=>true
    ])
  ]);
}

/* ---------- ADMIN PANEL ---------- */

if($text=="🔐 Admin Panel" && $chat_id==$ADMIN_ID){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Admin Panel",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
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

/* ---------- ADMIN CALLBACKS ---------- */

if($data && $chat_id==$ADMIN_ID){

  if($data=="ap_price"){
    setAdminStep("price");
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send new price per coupon"]);
  }

  if($data=="ap_add"){
    setAdminStep("add");
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send coupon codes (one per line)"]);
  }

  if($data=="ap_remove"){
    setAdminStep("remove");
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"How many coupons to remove?"]);
  }

  if($data=="ap_free"){
    setAdminStep("free");
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"How many coupons you want?"]);
  }

  if($data=="ap_qr"){
    setAdminStep("qr");
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send new QR image"]);
  }

  if($data=="ap_stock"){
    $count=count(supa("GET","coupons?select=id"));
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Available Coupons: $count"]);
  }
}

/* ---------- ADMIN STEP HANDLER ---------- */

$step=getAdminStep();

if($step && $chat_id==$ADMIN_ID){

  if($step["step"]=="price" && is_numeric($text)){
    supa("PATCH","settings?id=eq.1",["price"=>$text]);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Price Updated"]);
    clearAdminStep();
  }

  if($step["step"]=="add"){
    $codes=explode("\n",$text);
    foreach($codes as $c){
      supa("POST","coupons",["code"=>trim($c)]);
    }
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Coupons Added"]);
    clearAdminStep();
  }

  if($step["step"]=="remove" && is_numeric($text)){
    $list=supa("GET","coupons?limit=$text");
    foreach($list as $c){
      supa("DELETE","coupons?id=eq.".$c["id"]);
    }
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Coupons Removed"]);
    clearAdminStep();
  }

  if($step["step"]=="free" && is_numeric($text)){
    $list=supa("GET","coupons?limit=$text");
    $msg="🎁 Free Coupons:\n";
    foreach($list as $c){
      $msg.=$c["code"]."\n";
      supa("DELETE","coupons?id=eq.".$c["id"]);
    }
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
    clearAdminStep();
  }

  if($step["step"]=="qr" && $photo){
    $file=end($photo)["file_id"];
    supa("PATCH","settings?id=eq.1",["qr_url"=>$file]);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ QR Updated"]);
    clearAdminStep();
  }
}

/* ---------- USER BASIC FEATURES (STOCK / SUPPORT / ORDERS) ---------- */

if($text=="📦 Stock"){
  $count=count(supa("GET","coupons?select=id"));
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Available Coupons: $count"]);
}

if($text=="🆘 Support"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Contact: @Slursupportrobot"]);
}

if($text=="📜 My Orders"){
  $orders=supa("GET","orders?user_id=eq.$chat_id");
  $msg="Your Orders:\n";
  foreach($orders as $o){
    $msg.="ID {$o["id"]} | {$o["status"]}\n";
  }
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
}
