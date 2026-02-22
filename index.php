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

function isAdmin($id){
  global $ADMIN_IDS;
  return in_array((string)$id,$ADMIN_IDS,true);
}

/* ---------- START ---------- */

if($text=="/start"){
  supa("POST","users",["user_id"=>$chat_id],);
  $keyboard = [
    [["text"=>"🛒 Buy Coupon"]],
    [["text"=>"📦 Stock"],["text"=>"📜 My Orders"]],
    [["text"=>"🆘 Support"]]
  ];
  if(isAdmin($chat_id)){
    $keyboard[] = [["text"=>"🔐 Admin Panel"]];
  }
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Welcome to Coupon Bot",
    "reply_markup"=>json_encode([
      "keyboard"=>$keyboard,
      "resize_keyboard"=>true
    ])
  ]);
}

/* ---------- BUY COUPON ---------- */

if($text=="🛒 Buy Coupon"){
  $stock = count(supa("GET","coupons?select=id"));
  if($stock<=0){
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"❌ No stock available"]);
    exit;
  }
  $price = supa("GET","settings?id=eq.1")[0]["price"];
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Choose coupon:",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"₹500 OFF on ₹500 (₹$price)","callback_data"=>"buy_500"]]
      ]
    ])
  ]);
}

if($data=="buy_500"){
  supa("POST","user_steps",["user_id"=>$chat_id,"step"=>"qty"]);
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter quantity"]);
}

$step = supa("GET","user_steps?user_id=eq.$chat_id")[0] ?? null;

if($step && $step["step"]=="qty" && is_numeric($text)){
  supa("PATCH","user_steps?user_id=eq.$chat_id",["step"=>"terms","temp"=>$text]);
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"⚠️ No refunds. Final sale.",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"✅ Accept Terms","callback_data"=>"accept_terms"]]
      ]
    ])
  ]);
}

if($data=="accept_terms"){
  $u = supa("GET","user_steps?user_id=eq.$chat_id")[0];
  $qty = $u["temp"];
  $price = supa("GET","settings?id=eq.1")[0]["price"];
  $qr = supa("GET","settings?id=eq.1")[0]["qr_file_id"];
  $total = $qty*$price;

  supa("PATCH","user_steps?user_id=eq.$chat_id",["step"=>"paid"]);

  tg("sendPhoto",[
    "chat_id"=>$chat_id,
    "photo"=>$qr,
    "caption"=>"Pay ₹$total",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"✅ I have paid","callback_data"=>"paid"]]
      ]
    ])
  ]);
}

if($data=="paid"){
  supa("PATCH","user_steps?user_id=eq.$chat_id",["step"=>"payer"]);
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter payer name"]);
}

if($step && $step["step"]=="payer"){
  supa("PATCH","user_steps?user_id=eq.$chat_id",["step"=>"proof","temp"=>$text]);
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payment screenshot"]);
}

if($step && $step["step"]=="proof" && $photo){
  $file=end($photo)["file_id"];
  $qty=$step["temp"];
  $price=supa("GET","settings?id=eq.1")[0]["price"];
  $total=$qty*$price;

  $order=supa("POST","orders",[
    "user_id"=>$chat_id,
    "quantity"=>$qty,
    "total_price"=>$total,
    "payer_name"=>$step["temp"],
    "payment_proof"=>$file,
    "status"=>"pending"
  ])[0];

  supa("DELETE","user_steps?user_id=eq.$chat_id");

  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"⏳ Waiting for admin approval"]);

  foreach($ADMIN_IDS as $aid){
    tg("sendPhoto",[
      "chat_id"=>$aid,
      "photo"=>$file,
      "caption"=>"Order #{$order["id"]}\nQty:$qty\n₹$total",
      "reply_markup"=>json_encode([
        "inline_keyboard"=>[
          [["text"=>"✅ Accept","callback_data"=>"ok_{$order["id"]}"],
           ["text"=>"❌ Decline","callback_data"=>"no_{$order["id"]}"]]
        ]
      ])
    ]);
  }
}

/* ---------- ADMIN APPROVAL ---------- */

if($data && isAdmin($chat_id)){
  if(strpos($data,"ok_")===0){
    $oid=explode("_",$data)[1];
    $o=supa("GET","orders?id=eq.$oid")[0];
    $list=supa("GET","coupons?limit=".$o["quantity"]);
    $msg="🎉 Your Coupons:\n";
    foreach($list as $c){
      $msg.=$c["code"]."\n";
      supa("DELETE","coupons?id=eq.".$c["id"]);
    }
    supa("PATCH","orders?id=eq.$oid",["status"=>"approved"]);
    tg("sendMessage",["chat_id"=>$o["user_id"],"text"=>$msg]);
  }
  if(strpos($data,"no_")===0){
    $oid=explode("_",$data)[1];
    supa("PATCH","orders?id=eq.$oid",["status"=>"declined"]);
    tg("sendMessage",["chat_id"=>supa("GET","orders?id=eq.$oid")[0]["user_id"],"text"=>"❌ Payment Declined"]);
  }
}

/* ---------- ADMIN PANEL ---------- */

if($text=="🔐 Admin Panel" && isAdmin($chat_id)){
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

/* ---------- BASIC ---------- */

if($text=="📦 Stock"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Stock: ".count(supa("GET","coupons?select=id"))]);
}
if($text=="🆘 Support"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"@Slursupportrobot"]);
}
if($text=="📜 My Orders"){
  $o=supa("GET","orders?user_id=eq.$chat_id");
  $msg="Your Orders:\n";
  foreach($o as $x){$msg.="ID {$x["id"]} - {$x["status"]}\n";}
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
}
