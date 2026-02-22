import os
import asyncio
from aiogram import Bot, Dispatcher, F, types
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import StatesGroup, State
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton
from supabase import create_client

# ─── ENV ───
BOT_TOKEN = os.getenv("BOT_TOKEN")
SUPABASE_URL = os.getenv("SUPABASE_URL")
SUPABASE_KEY = os.getenv("SUPABASE_KEY")
ADMIN_ID = int(os.getenv("ADMIN_ID"))

bot = Bot(BOT_TOKEN, parse_mode="HTML")
dp = Dispatcher()
db = create_client(SUPABASE_URL, SUPABASE_KEY)

# ─── STATES ───
class BuyFlow(StatesGroup):
    quantity = State()
    payer = State()
    screenshot = State()

class AdminFlow(StatesGroup):
    price = State()
    add = State()
    remove = State()
    free = State()
    qr = State()

# ─── KEYBOARDS ───
def user_menu():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🛒 Buy Coupon", callback_data="buy")],
        [InlineKeyboardButton(text="📦 Stock", callback_data="stock")],
        [InlineKeyboardButton(text="🧾 My Orders", callback_data="orders")],
        [InlineKeyboardButton(text="🆘 Support", callback_data="support")]
    ])

def admin_menu():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="💰 Change Price", callback_data="admin_price")],
        [InlineKeyboardButton(text="➕ Add Coupons", callback_data="admin_add")],
        [InlineKeyboardButton(text="➖ Remove Coupons", callback_data="admin_remove")],
        [InlineKeyboardButton(text="🎁 Free Coupon", callback_data="admin_free")],
        [InlineKeyboardButton(text="📸 Update QR", callback_data="admin_qr")]
    ])

# ─── HELPERS ───
def get_price():
    return db.table("settings").select("price").eq("id", 1).execute().data[0]["price"]

def get_qr():
    return db.table("settings").select("qr").eq("id", 1).execute().data[0]["qr"]

def stock_count():
    return len(db.table("coupons").select("id").eq("used", False).execute().data)

# ─── START ───
@dp.message(Command("start"))
async def start(msg: types.Message):
    await msg.answer("Welcome to Coupon Store 👋", reply_markup=user_menu())
    if msg.from_user.id == ADMIN_ID:
        await msg.answer("👑 Admin Panel", reply_markup=admin_menu())

# ─── BUY FLOW ───
@dp.callback_query(F.data == "buy")
async def buy(c: types.CallbackQuery, state: FSMContext):
    if stock_count() <= 0:
        await c.message.answer("❌ No stock available")
        return
    await c.message.answer(
        f"🎟 <b>500 OFF on 500</b>\n💰 Price: ₹{get_price()}",
        reply_markup=InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(text="Buy", callback_data="buy_confirm")]]
        )
    )

@dp.callback_query(F.data == "buy_confirm")
async def buy_confirm(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("How many coupons do you want?")
    await state.set_state(BuyFlow.quantity)

@dp.message(BuyFlow.quantity)
async def set_qty(msg: types.Message, state: FSMContext):
    if not msg.text.isdigit() or int(msg.text) <= 0:
        await msg.answer("Enter a valid quantity")
        return
    await state.update_data(quantity=int(msg.text))
    await msg.answer(
        "⚠️ <b>Disclaimer</b>\n"
        "1. No refunds\n"
        "2. Coupons are valid\n"
        "3. All sales are final\n\n"
        "✅ By purchasing, you agree",
        reply_markup=InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(text="✅ Accept Terms", callback_data="accept_terms")]]
        )
    )

@dp.callback_query(F.data == "accept_terms")
async def accept_terms(c: types.CallbackQuery, state: FSMContext):
    data = await state.get_data()
    total = data["quantity"] * get_price()
    qr = get_qr()

    await c.message.answer_photo(
        qr,
        caption=f"💳 Pay ₹{total}",
        reply_markup=InlineKeyboardMarkup(
            inline_keyboard=[[InlineKeyboardButton(text="I have done payment", callback_data="paid")]]
        )
    )

@dp.callback_query(F.data == "paid")
async def ask_payer(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("Enter payer name")
    await state.set_state(BuyFlow.payer)

@dp.message(BuyFlow.payer)
async def ask_ss(msg: types.Message, state: FSMContext):
    await state.update_data(payer=msg.text)
    await msg.answer("Send payment screenshot")
    await state.set_state(BuyFlow.screenshot)

@dp.message(BuyFlow.screenshot)
async def finalize_order(msg: types.Message, state: FSMContext):
    data = await state.get_data()
    total = data["quantity"] * get_price()

    order = db.table("orders").insert({
        "user_id": msg.from_user.id,
        "quantity": data["quantity"],
        "total_price": total,
        "payer_name": data["payer"],
        "screenshot": msg.photo[-1].file_id
    }).execute().data[0]

    await msg.answer("⏳ Waiting for admin approval")

    await bot.send_photo(
        ADMIN_ID,
        msg.photo[-1].file_id,
        caption=f"🧾 Order #{order['id']}\nQty: {data['quantity']}\n₹{total}\nPayer: {data['payer']}",
        reply_markup=InlineKeyboardMarkup(
            inline_keyboard=[
                [InlineKeyboardButton(text="✅ Approve", callback_data=f"approve:{order['id']}")],
                [InlineKeyboardButton(text="❌ Decline", callback_data=f"decline:{order['id']}")]
            ]
        )
    )
    await state.clear()

# ─── ADMIN APPROVE ───
@dp.callback_query(F.data.startswith("approve:"))
async def approve(c: types.CallbackQuery):
    oid = int(c.data.split(":")[1])
    order = db.table("orders").select("*").eq("id", oid).execute().data[0]

    coupons = db.table("coupons").select("*").eq("used", False).limit(order["quantity"]).execute().data
    if len(coupons) < order["quantity"]:
        await c.message.answer("❌ Not enough stock")
        return

    codes = "\n".join([x["code"] for x in coupons])
    ids = [x["id"] for x in coupons]

    db.table("coupons").update({"used": True}).in_("id", ids).execute()
    db.table("orders").update({
        "status": "approved",
        "coupon_codes": codes
    }).eq("id", oid).execute()

    await bot.send_message(order["user_id"], f"✅ Approved\n\n🎟 Coupons:\n{codes}")
    await c.message.edit_caption(c.message.caption + "\n\n✅ Approved")

@dp.callback_query(F.data.startswith("decline:"))
async def decline(c: types.CallbackQuery):
    oid = int(c.data.split(":")[1])
    order = db.table("orders").select("user_id").eq("id", oid).execute().data[0]
    db.table("orders").update({"status": "declined"}).eq("id", oid).execute()
    await bot.send_message(order["user_id"], "❌ Payment declined by admin")
    await c.message.edit_caption(c.message.caption + "\n\n❌ Declined")

# ─── USER BUTTONS ───
@dp.callback_query(F.data == "stock")
async def stock(c: types.CallbackQuery):
    await c.message.answer(f"📦 Available stock: {stock_count()}")

@dp.callback_query(F.data == "orders")
async def my_orders(c: types.CallbackQuery):
    orders = db.table("orders").select("id,quantity,status").eq("user_id", c.from_user.id).execute().data
    if not orders:
        await c.message.answer("No orders found")
        return
    text = "\n".join([f"#{o['id']} | {o['quantity']} | {o['status']}" for o in orders])
    await c.message.answer(text)

@dp.callback_query(F.data == "support")
async def support(c: types.CallbackQuery):
    await c.message.answer("🆘 Support: @Slursupportrobot")

# ─── ADMIN PANEL ───
@dp.callback_query(F.data == "admin_price")
async def admin_price(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("Send new price")
    await state.set_state(AdminFlow.price)

@dp.message(AdminFlow.price)
async def set_price(msg: types.Message, state: FSMContext):
    db.table("settings").update({"price": int(msg.text)}).eq("id", 1).execute()
    await msg.answer("✅ Price updated")
    await state.clear()

@dp.callback_query(F.data == "admin_add")
async def admin_add(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("Send coupons (one per line)")
    await state.set_state(AdminFlow.add)

@dp.message(AdminFlow.add)
async def save_add(msg: types.Message, state: FSMContext):
    for line in msg.text.splitlines():
        if line.strip():
            db.table("coupons").insert({"code": line.strip()}).execute()
    await msg.answer("✅ Coupons added")
    await state.clear()

@dp.callback_query(F.data == "admin_remove")
async def admin_remove(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("How many coupons to remove?")
    await state.set_state(AdminFlow.remove)

@dp.message(AdminFlow.remove)
async def do_remove(msg: types.Message, state: FSMContext):
    rows = db.table("coupons").select("id").eq("used", False).limit(int(msg.text)).execute().data
    ids = [r["id"] for r in rows]
    db.table("coupons").delete().in_("id", ids).execute()
    await msg.answer("✅ Coupons removed")
    await state.clear()

@dp.callback_query(F.data == "admin_free")
async def admin_free(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("How many free coupons?")
    await state.set_state(AdminFlow.free)

@dp.message(AdminFlow.free)
async def send_free(msg: types.Message, state: FSMContext):
    rows = db.table("coupons").select("*").eq("used", False).limit(int(msg.text)).execute().data
    codes = "\n".join([r["code"] for r in rows])
    ids = [r["id"] for r in rows]
    db.table("coupons").update({"used": True}).in_("id", ids).execute()
    await msg.answer(f"🎁 Free Coupons:\n{codes}")
    await state.clear()

@dp.callback_query(F.data == "admin_qr")
async def admin_qr(c: types.CallbackQuery, state: FSMContext):
    await c.message.answer("Send QR image")
    await state.set_state(AdminFlow.qr)

@dp.message(AdminFlow.qr)
async def save_qr(msg: types.Message, state: FSMContext):
    db.table("settings").update({"qr": msg.photo[-1].file_id}).eq("id", 1).execute()
    await msg.answer("✅ QR updated")
    await state.clear()

# ─── RUN ───
async def main():
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())
