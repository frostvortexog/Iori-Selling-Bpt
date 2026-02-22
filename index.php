<?php

$BOT_TOKEN = "PUT_YOUR_BOT_TOKEN_HERE";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";

$dataFile = "data.json";
$data = json_decode(file_get_contents($dataFile), true);

$update = json_decode(file_get_contents("php://input"), true);

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

$chat_id = $message["chat"]["id"] ?? $callback["message"]["chat"]["id"];
$text = $message["text"] ?? null;
$cb = $callback["data"] ?? null;

function saveData($data){
  global $dataFile;
  file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
}

function tg($method,$data){
  global $API;
  file_get_contents($API.$method."?".http_build_query($data));
}

function isAdmin($id){
  global $data;
  return in_array($id, $data["admins"]);
}

/* ===== START ===== */
if($text == "/start"){
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

/* ===== USER ===== */

if($text == "📦 Stock"){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Available coupons: ".count($data["coupons"])
  ]);
}

if($text == "🛒 Buy Coupon"){
  if(count($data["coupons"]) == 0){
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"❌ Out of stock"]);
    exit;
  }

  $coupon = array_shift($data["coupons"]);
  $data["orders"][] = [
    "user"=>$chat_id,
    "coupon"=>$coupon
  ];
  saveData($data);

  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"✅ Coupon purchased:\n`$coupon`\nPrice: ₹".$data["price"],
    "parse_mode"=>"Markdown"
  ]);
}

if($text == "📜 My Orders"){
  $list = "";
  foreach($data["orders"] as $o){
    if($o["user"] == $chat_id){
      $list .= "• ".$o["coupon"]."\n";
    }
  }
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>$list ?: "No orders found"
  ]);
}

if($text == "🆘 Support"){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"Contact: @YourSupportUsername"
  ]);
}

/* ===== ADMIN PANEL ===== */

if($text == "🔐 Admin Panel" && isAdmin($chat_id)){
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"🔐 Admin Panel",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"➕ Add Coupons","callback_data"=>"ADD"]],
        [["text"=>"➖ Remove Coupon","callback_data"=>"REMOVE"]],
        [["text"=>"📦 View Stock","callback_data"=>"STOCK"]],
        [["text"=>"💰 Change Price","callback_data"=>"PRICE"]]
      ]
    ])
  ]);
}

/* ===== ADMIN CALLBACKS ===== */

if($cb && isAdmin($chat_id)){

  if($cb == "STOCK"){
    tg("sendMessage",[
      "chat_id"=>$chat_id,
      "text"=>"Stock: ".count($data["coupons"])
    ]);
  }

  if($cb == "ADD"){
    tg("sendMessage",[
      "chat_id"=>$chat_id,
      "text"=>"Send coupon codes (one per line)"
    ]);
    file_put_contents("step_$chat_id.txt","add");
  }

  if($cb == "REMOVE"){
    array_pop($data["coupons"]);
    saveData($data);
    tg("sendMessage",[
      "chat_id"=>$chat_id,
      "text"=>"❌ One coupon removed"
    ]);
  }

  if($cb == "PRICE"){
    tg("sendMessage",[
      "chat_id"=>$chat_id,
      "text"=>"Send new price"
    ]);
    file_put_contents("step_$chat_id.txt","price");
  }
}

/* ===== ADMIN STEPS ===== */

$stepFile = "step_$chat_id.txt";
if(file_exists($stepFile)){
  $step = file_get_contents($stepFile);

  if($step == "add"){
    $lines = explode("\n",$text);
    foreach($lines as $c){
      $c = trim($c);
      if($c) $data["coupons"][] = $c;
    }
    saveData($data);
    unlink($stepFile);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Coupons added"]);
  }

  if($step == "price"){
    $data["price"] = (int)$text;
    saveData($data);
    unlink($stepFile);
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"✅ Price updated"]);
  }
}
