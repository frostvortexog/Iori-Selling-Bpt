<?php

$BOT_TOKEN = "PUT_BOT_TOKEN_HERE";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";
$dataFile = "data.json";
$data = json_decode(file_get_contents($dataFile), true);

$update = json_decode(file_get_contents("php://input"), true);

$msg = $update["message"] ?? null;
$cbq = $update["callback_query"] ?? null;

$chat_id = $msg["chat"]["id"] ?? $cbq["message"]["chat"]["id"];
$text = $msg["text"] ?? null;
$cb = $cbq["data"] ?? null;

function tg($method,$data){
  global $API;
  file_get_contents($API.$method."?".http_build_query($data));
}
function save(){
  global $data,$dataFile;
  file_put_contents($dataFile,json_encode($data,JSON_PRETTY_PRINT));
}
function isAdmin($id){
  global $data;
  return in_array($id,$data["admins"]);
}
function stepFile($id){ return "step_$id.txt"; }

/* ===== START ===== */
if($text=="/start"){
  $kb = [
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

/* ===== USER ===== */
if($text=="📦 Stock"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Available coupons: ".count($data["coupons"])]);
}

if($text=="🆘 Support"){
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Contact support: @Slursupportrobot"]);
}

if($text=="📜 My Orders"){
  $out="";
  foreach($data["orders"] as $o){
    if($o["user"]==$chat_id && $o["status"]=="approved"){
      $out.="• ".$o["coupon"]."\n";
    }
  }
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>$out ?: "No orders"]);
}

/* ===== BUY FLOW ===== */
if($text=="🛒 Buy Coupon"){
  if(count($data["coupons"])==0){
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"❌ No stock available"]);
    exit;
  }
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"500 OFF on 500 (₹".$data["price"].")\n\nHow many coupons?",
  ]);
  file_put_contents(stepFile($chat_id),"qty");
}

$step = file_exists(stepFile($chat_id)) ? file_get_contents(stepFile($chat_id)) : null;

if($step=="qty" && is_numeric($text)){
  file_put_contents(stepFile($chat_id),"terms|$text");
  tg("sendMessage",[
    "chat_id"=>$chat_id,
    "text"=>"⚠️ Disclaimer\nNo refunds. All sales final.\n\nAccept?",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"✅ Accept Terms","callback_data"=>"ACCEPT"]]
      ]
    ])
  ]);
}

/* ===== TERMS ACCEPT ===== */
if($cb=="ACCEPT"){
  $qty = explode("|",file_get_contents(stepFile($chat_id)))[1];
  $total = $qty * $data["price"];
  file_put_contents(stepFile($chat_id),"pay|$qty");
  tg("sendPhoto",[
    "chat_id"=>$chat_id,
    "photo"=>$data["qr"],
    "caption"=>"Pay ₹$total\nAfter payment click below",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"✅ I have done payment","callback_data"=>"PAID"]]
      ]
    ])
  ]);
}

/* ===== PAYMENT ===== */
if($cb=="PAID"){
  file_put_contents(stepFile($chat_id),"payer");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payer name"]);
}

if($step=="payer"){
  file_put_contents(stepFile($chat_id),"proof|$text");
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send payment screenshot"]);
}

if($msg && isset($msg["photo"]) && str_starts_with($step,"proof")){
  $payer = explode("|",$step)[1];
  $qty = explode("|",file_get_contents(stepFile($chat_id)))[1];
  $order = [
    "id"=>uniqid(),
    "user"=>$chat_id,
    "qty"=>$qty,
    "payer"=>$payer,
    "status"=>"pending"
  ];
  $data["orders"][]=$order;
  save();
  unlink(stepFile($chat_id));

  foreach($data["admins"] as $a){
    tg("sendMessage",[
      "chat_id"=>$a,
      "text"=>"New payment\nUser:$chat_id\nQty:$qty\nPayer:$payer",
      "reply_markup"=>json_encode([
        "inline_keyboard"=>[
          [["text"=>"✅ Approve","callback_data"=>"OK|".$order["id"]]],
          [["text"=>"❌ Decline","callback_data"=>"NO|".$order["id"]]]
        ]
      ])
    ]);
  }
  tg("sendMessage",["chat_id"=>$chat_id,"text"=>"⏳ Waiting for admin approval"]);
}

/* ===== ADMIN PANEL ===== */
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

/* ===== ADMIN CALLBACKS ===== */
if($cb && isAdmin($chat_id)){
  if($cb=="ADD"){ file_put_contents(stepFile($chat_id),"add"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send coupons"]); }
  if($cb=="PRICE"){ file_put_contents(stepFile($chat_id),"price"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send new price"]); }
  if($cb=="FREE"){ file_put_contents(stepFile($chat_id),"free"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"How many free coupons?"]); }
  if($cb=="REM"){ array_pop($data["coupons"]); save(); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Removed 1 coupon"]); }
  if($cb=="QR"){ file_put_contents(stepFile($chat_id),"qr"); tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Send QR image"]); }

  if(str_starts_with($cb,"OK")){
    $id = explode("|",$cb)[1];
    foreach($data["orders"] as &$o){
      if($o["id"]==$id){
        $o["status"]="approved";
        for($i=0;$i<$o["qty"];$i++){
          $c = array_shift($data["coupons"]);
          tg("sendMessage",["chat_id"=>$o["user"],"text"=>"🎟 Coupon:\n$c"]);
        }
      }
    }
    save();
  }
  if(str_starts_with($cb,"NO")){
    tg("sendMessage",["chat_id"=>$chat_id,"text"=>"Payment declined"]);
  }
}

/* ===== ADMIN STEPS ===== */
if($step=="add"){ foreach(explode("\n",$text) as $c){ if(trim($c))$data["coupons"][]=trim($c); } save(); unlink(stepFile($chat_id)); }
if($step=="price"){ $data["price"]=(int)$text; save(); unlink(stepFile($chat_id)); }
if($step=="free"){ for($i=0;$i<(int)$text;$i++){ $c=array_shift($data["coupons"]); tg("sendMessage",["chat_id"=>$chat_id,"text"=>$c]); } save(); unlink(stepFile($chat_id)); }
if($step=="qr" && isset($msg["photo"])){ $data["qr"]=$msg["photo"][0]["file_id"]; save(); unlink(stepFile($chat_id)); }
