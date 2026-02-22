<?php

/* ================= CONFIG ================= */

$BOT_TOKEN = getenv("BOT_TOKEN");
$SUPA_URL  = getenv("SUPABASE_URL");
$SUPA_KEY  = getenv("SUPABASE_KEY");

$API = "https://api.telegram.org/bot$BOT_TOKEN/";

/* ================= HELPERS ================= */

function tg($m,$d){
  global $API;
  file_get_contents($API.$m."?".http_build_query($d));
}

function supa($method,$table,$query="",$body=null){
  global $SUPA_URL,$SUPA_KEY;
  $ch = curl_init("$SUPA_URL/rest/v1/$table$query");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_HTTPHEADER=>[
      "apikey: $SUPA_KEY",
      "Authorization: Bearer $SUPA_KEY",
      "Content-Type: application/json",
      "Prefer: return=representation"
    ],
    CURLOPT_POSTFIELDS=>$body ? json_encode($body):null
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return json_decode($res,true);
}

function isAdmin($id){
  $r = supa("GET","admins","?user_id=eq.$id");
  return count($r)>0;
}

function setting($k){
  $r = supa("GET","settings","?key=eq.$k");
  return $r[0]["value"] ?? "";
}

function setSetting($k,$v){
  supa("PATCH","settings","?key=eq.$k",["value"=>$v]);
}

function stepFile($id){ return sys_get_temp_dir()."/step_$id"; }

/* ================= UPDATE ================= */

$u = json_decode(file_get_contents("php://input"), true);
$m = $u["message"] ?? null;
$c = $u["callback_query"] ?? null;

$chat_id = $m["chat"]["id"] ?? $c["message"]["chat"]["id"];
$text = $m["text"] ?? null;
$cb = $c["data"] ?? null;

/* ================= START ================= */

if($text=="/start"){
  $kb=[
    [["text"=>"🛒 Buy Coupon"]],
    [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
    [["text"=>"🆘 Support"]]
  ];
  if(isAdmin($chat_id)) $kb[]=[["text"=>"🔐 Admin Panel"]];

  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Welcome to Coupon Selling Bot",
    "reply_markup"=>json_encode(["keyboard"=>$kb,"resize_keyboard"=>true])
  ]);
}

/* ================= USER ================= */

if($text=="📦 Stock"){
  $r = supa("GET","coupons","?used=eq.false");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Stock: ".count($r)]);
}

if($text=="🆘 Support"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Contact: @Slursupportrobot"]);
}

if($text=="📜 My Orders"){
  $r = supa("GET","orders","?user_id=eq.$chat_id&status=eq.approved");
  $out="";
  foreach($r as $o){ $out.="• Order {$o["id"]}\n"; }
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>$out ?: "No orders"]);
}

/* ================= BUY FLOW ================= */

if($text=="🛒 Buy Coupon"){
  $price = setting("price");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"500 OFF on 500 (₹$price)\nHow many coupons?"]);
  file_put_contents(stepFile($chat_id),"qty");
}

$step = file_exists(stepFile($chat_id)) ? file_get_contents(stepFile($chat_id)) : null;

if($step=="qty" && is_numeric($text)){
  file_put_contents(stepFile($chat_id),"terms|$text");
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"⚠️ No refunds. All sales final.\nAccept?",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[[["text"=>"✅ Accept","callback_data"=>"ACCEPT"]]]
    ])
  ]);
}

if($cb=="ACCEPT"){
  $qty = explode("|",file_get_contents(stepFile($chat_id)))[1];
  $total = $qty * setting("price");
  file_put_contents(stepFile($chat_id),"pay|$qty");
  tg("sendPhoto",[
    "chat_id"=>$chat_id,
    "photo"=>setting("qr"),
    "caption"=>"Pay ₹$total",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[[["text"=>"✅ I have done payment","callback_data"=>"PAID"]]]
    ])
  ]);
}

if($cb=="PAID"){
  file_put_contents(stepFile($chat_id),"payer");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payer name"]);
}

if($step=="payer"){
  file_put_contents(stepFile($chat_id),"proof|$text");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payment screenshot"]);
}

if(isset($m["photo"]) && str_starts_with($step,"proof")){
  $payer = explode("|",$step)[1];
  $qty = explode("|",file_get_contents(stepFile($chat_id)))[1];
  $id = uniqid();

  supa("POST","orders",[
    "id"=>$id,
    "user_id"=>$chat_id,
    "qty"=>$qty,
    "payer"=>$payer,
    "status"=>"pending"
  ]);

  foreach(supa("GET","admins") as $a){
    tg("sendMessage",[
      "chat_id"=>$a["user_id"],
      "text"=>"Payment pending\nUser:$chat_id\nQty:$qty\nPayer:$payer",
      "reply_markup"=>json_encode([
        "inline_keyboard"=>[
          [["text"=>"✅ Approve","callback_data"=>"OK|$id"]],
          [["text"=>"❌ Decline","callback_data"=>"NO|$id"]]
        ]
      ])
    ]);
  }
  unlink(stepFile($chat_id));
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"⏳ Waiting for admin approval"]);
}

/* ================= ADMIN PANEL ================= */

if($text=="🔐 Admin Panel" && isAdmin($chat_id)){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Admin Panel",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"➕ Add Coupons","callback_data"=>"ADD"]],
        [["text"=>"➖ Remove Coupons","callback_data"=>"REM"]],
        [["text"=>"💰 Change Price","callback_data"=>"PRICE"]],
        [["text"=>"🎁 Free Coupon","callback_data"=>"FREE"]],
        [["text"=>"🖼 Update QR","callback_data"=>"QR"]]
      ]
    ])
  ]);
}

/* ================= ADMIN CALLBACKS ================= */

if($cb && isAdmin($chat_id)){

  if($cb=="ADD"){ file_put_contents(stepFile($chat_id),"add"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send coupon codes"]); }

  if($cb=="PRICE"){ file_put_contents(stepFile($chat_id),"price"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send new price"]); }

  if($cb=="QR"){ file_put_contents(stepFile($chat_id),"qr"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send QR image"]); }

  if(str_starts_with($cb,"OK")){
    $oid = explode("|",$cb)[1];
    $o = supa("GET","orders","?id=eq.$oid")[0];
    $c = supa("GET","coupons","?used=eq.false&limit=".$o["qty"]);

    foreach($c as $cc){
      supa("PATCH","coupons","?id=eq.{$cc["id"]}",["used"=>true]);
      tg("sendMessage",["chat_id"=>$o["user_id"],"text"=>"🎟 Coupon:\n".$cc["code"]]);
    }

    supa("PATCH","orders","?id=eq.$oid",["status"=>"approved"]);
  }

  if(str_starts_with($cb,"NO")){
    $oid = explode("|",$cb)[1];
    supa("PATCH","orders","?id=eq.$oid",["status"=>"declined"]);
  }
}

/* ================= ADMIN STEPS ================= */

if($step=="add"){
  foreach(explode("\n",$text) as $c){
    if(trim($c)) supa("POST","coupons",["code"=>trim($c)]);
  }
  unlink(stepFile($chat_id));
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Coupons added"]);
}

if($step=="price"){
  setSetting("price",(int)$text);
  unlink(stepFile($chat_id));
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Price updated"]);
}

if($step=="qr" && isset($m["photo"])){
  setSetting("qr",$m["photo"][0]["file_id"]);
  unlink(stepFile($chat_id));
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"QR updated"]);
}
