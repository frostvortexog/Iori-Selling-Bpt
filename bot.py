import os
import asyncio
from aiogram import Bot, Dispatcher, F
from aiogram.types import (
    Message, CallbackQuery,
    ReplyKeyboardMarkup, KeyboardButton,
    InlineKeyboardMarkup, InlineKeyboardButton
)
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import StatesGroup, State
from supabase import create_client

# ================== ENV ==================
BOT_TOKEN = os.getenv("BOT_TOKEN")
ADMIN_ID = int(os.getenv("ADMIN_ID"))
SUPABASE_URL = os.getenv("SUPABASE_URL")
SUPABASE_KEY = os.getenv("SUPABASE_KEY")

bot = Bot(BOT_TOKEN)
dp = Dispatcher()
db = create_client(SUPABASE_URL, SUPABASE_KEY)

# ================== STATES ==================
class BuyState(StatesGroup):
    quantity = State()
    payer_name = State()
    screenshot = State()

class AdminState(StatesGroup):
    change_price = State()
    add_coupon = State()
    remove_coupon = State()
    free_coupon = State()

# ================== KEYBOARDS ==================
def main_menu():
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="🛒 Buy Coupon")],
            [KeyboardButton(text="📦 Stock"), KeyboardButton(text="🆘 Support")],
            [KeyboardButton(text="📜 My Orders")]
        ],
        resize_keyboard=True
    )

def admin_menu():
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="💸 Change Price")],
            [KeyboardButton(text="➕ Add Coupon"), KeyboardButton(text="➖ Remove Coupon")],
            [KeyboardButton(text="🎁 Free Coupon"), KeyboardButton(text="🖼 Update QR")]
        ],
        resize_keyboard=True
    )

# ================== START ==================
@dp.message(Command("start"))
async def start(msg: Message):
    db.table("users").upsert({
        "user_id": msg.from_user.id,
        "username": msg.from_user.username
    }).execute()
    await msg.answer("Welcome to Coupon Store", reply_markup=main_menu())

# ================== BUY COUPON ==================
@dp.message(F.text == "🛒 Buy Coupon")
async def buy(msg: Message):
    stock = db.table("coupons").select("*").eq("used", False).execute()
    if len(stock.data) == 0:
        await msg.answer("❌ No stock available")
        return

    price = db.table("settings").select("price").eq("id", 1).execute().data[0]["price"]
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(
            text=f"500 OFF on 500 (₹{price})",
            callback_data="buy_500"
        )]
    ])
    await msg.answer("Choose coupon:", reply_markup=kb)

@dp.callback_query(F.data == "buy_500")
async def ask_quantity(call: CallbackQuery, state: FSMContext):
    await call.message.answer("How many 500 coupons do you want to buy?")
    await state.set_state(BuyState.quantity)
    await call.answer()

@dp.message(BuyState.quantity)
async def disclaimer(msg: Message, state: FSMContext):
    qty = int(msg.text)
    await state.update_data(quantity=qty)

    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Accept Terms", callback_data="accept_terms")]
    ])

    await msg.answer(
        "⚠️ Disclaimer\n\n"
        "1. No refunds\n"
        "2. Coupons are fresh & valid\n"
        "3. All sales final\n\n"
        "✅ By purchasing, you agree to these terms.",
        reply_markup=kb
    )

@dp.callback_query(F.data == "accept_terms")
async def send_payment(call: CallbackQuery, state: FSMContext):
    data = await state.get_data()
    qty = data["quantity"]

    settings = db.table("settings").select("*").eq("id", 1).execute().data[0]
    total = qty * settings["price"]

    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="💰 I Have Done Payment", callback_data="paid")]
    ])

    await call.message.answer_photo(
        settings["qr_file_id"],
        caption=(
            f"Coupons: {qty}\n"
            f"Price per coupon: ₹{settings['price']}\n"
            f"Total: ₹{total}"
        ),
        reply_markup=kb
    )
    await call.answer()

@dp.callback_query(F.data == "paid")
async def ask_payer(call: CallbackQuery, state: FSMContext):
    await call.message.answer("Enter payer name:")
    await state.set_state(BuyState.payer_name)
    await call.answer()

@dp.message(BuyState.payer_name)
async def ask_screenshot(msg: Message, state: FSMContext):
    await state.update_data(payer_name=msg.text)
    await msg.answer("Send payment screenshot:")
    await state.set_state(BuyState.screenshot)

@dp.message(BuyState.screenshot)
async def submit_order(msg: Message, state: FSMContext):
    data = await state.get_data()
    qty = data["quantity"]

    price = db.table("settings").select("price").eq("id", 1).execute().data[0]["price"]
    total = qty * price

    order = db.table("orders").insert({
        "user_id": msg.from_user.id,
        "quantity": qty,
        "total_price": total,
        "payer_name": data["payer_name"],
        "screenshot_file_id": msg.photo[-1].file_id,
        "status": "PENDING"
    }).execute().data[0]

    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Accept", callback_data=f"approve_{order['id']}")],
        [InlineKeyboardButton(text="❌ Decline", callback_data=f"decline_{order['id']}")]
    ])

    await bot.send_photo(
        ADMIN_ID,
        msg.photo[-1].file_id,
        caption=(
            f"New Order #{order['id']}\n"
            f"User: @{msg.from_user.username}\n"
            f"Coupons: {qty}\n"
            f"Total: ₹{total}\n"
            f"Payer: {data['payer_name']}"
        ),
        reply_markup=kb
    )

    await msg.answer("⏳ Wait for admin approval")
    await state.clear()

# ================== ADMIN APPROVAL ==================
@dp.callback_query(F.data.startswith("approve_"))
async def approve(call: CallbackQuery):
    order_id = int(call.data.split("_")[1])
    order = db.table("orders").select("*").eq("id", order_id).execute().data[0]

    coupons = db.table("coupons").select("*").eq("used", False).limit(order["quantity"]).execute().data
    codes = "\n".join([c["code"] for c in coupons])

    for c in coupons:
        db.table("coupons").update({"used": True}).eq("id", c["id"]).execute()

    db.table("orders").update({"status": "COMPLETED"}).eq("id", order_id).execute()

    await bot.send_message(order["user_id"], f"✅ Payment approved\n\nYour coupons:\n{codes}")
    await call.message.edit_caption(call.message.caption + "\n\n✅ Approved")
    await call.answer()

@dp.callback_query(F.data.startswith("decline_"))
async def decline(call: CallbackQuery):
    order_id = int(call.data.split("_")[1])
    order = db.table("orders").select("*").eq("id", order_id).execute().data[0]

    db.table("orders").update({"status": "DECLINED"}).eq("id", order_id).execute()
    await bot.send_message(order["user_id"], "❌ Payment declined by admin")
    await call.answer()

# ================== STOCK ==================
@dp.message(F.text == "📦 Stock")
async def stock(msg: Message):
    stock = db.table("coupons").select("*").eq("used", False).execute()
    await msg.answer(f"Available Coupons: {len(stock.data)}")

# ================== SUPPORT ==================
@dp.message(F.text == "🆘 Support")
async def support(msg: Message):
    await msg.answer("Contact support: @Slursupportrobot")

# ================== MY ORDERS ==================
@dp.message(F.text == "📜 My Orders")
async def orders(msg: Message):
    orders = db.table("orders").select("*").eq("user_id", msg.from_user.id).execute()
    if not orders.data:
        await msg.answer("No orders yet")
        return

    text = ""
    for o in orders.data:
        text += f"Order #{o['id']} | Coupons: {o['quantity']} | ₹{o['total_price']} | {o['status']}\n"
    await msg.answer(text)

# ================== ADMIN MENU ==================
@dp.message(Command("admin"))
async def admin(msg: Message):
    if msg.from_user.id == ADMIN_ID:
        await msg.answer("Admin Panel", reply_markup=admin_menu())

# ================== UPDATE QR ==================
@dp.message(F.text == "🖼 Update QR")
async def update_qr(msg: Message):
    if msg.from_user.id != ADMIN_ID:
        return
    await msg.answer("Send new QR image")

@dp.message(F.photo)
async def save_qr(msg: Message):
    if msg.from_user.id == ADMIN_ID:
        db.table("settings").update({
            "qr_file_id": msg.photo[-1].file_id
        }).eq("id", 1).execute()
        await msg.answer("✅ QR Updated")

# ================== RUN ==================
async def main():
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())
