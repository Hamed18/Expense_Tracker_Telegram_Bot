```markdown
# Author Details
Name: Mohammod Hamed Hasan
Email: hamedhasan.dev@gmail.com
LinkedIn: https://www.linkedin.com/in/devhamed/

# Income & Expense Tracker Bot

A real-time, conversational financial ledger bot built with Laravel 12, Telegram Bot API, and XAMPP (MySQL). This application allows users to seamlessly track incomes and expenses directly through a Telegram chat interface, rendering line-by-line chronological reports and day-by-day monthly breakdowns.

---

## 📺 Project Presentation & Demo

Watch the full system walkthrough and video presentation to see the bot in action:

👉 [Watch the Demo Video on Loom](https://www.loom.com/share/c9a586c2b9d64e1d838a0cfa690fd19a)

---

## 🚀 Key Features

Live Dynamic Webhook Messaging: Real-time bidirectional communication between Telegram servers and your local development workspace utilizing secure Ngrok tunneling.
Income & Expense Tracking: Instant financial logging with support for optional transaction descriptions and auto-fallback defaults (Uncategorized Income/Uncategorized Expense).
Granular Daily Reports: Chronological line-by-line display of today's activities with individual timestamps, transaction indicators, and a final aggregated net balance.
Deep Monthly Breakdowns: Advanced day-by-day nested breakdown grouping transactions by calendar date, calculating overall monthly volumes and final balances.
Secure Ledger Reset: A database-backed transaction purge execution command (/reset) that gracefully wipes a user's ledger back to a clean 0.00 BDT slate.

---

## 🛠️ Technology Stack

Backend Framework: Laravel 12 (PHP 8.2+)
Database Engine: MySQL (via XAMPP)
Proxy Gateway: Ngrok (Secure SSL Tunneling)
API Ecosystem: Telegram Bot API (Webhook Architecture)
Time Utilities: Carbon (Timezone & Date Aggregation)

---

## 📟 Supported Telegram Commands

| Command | Syntax | Description |
| :--- | :--- | :--- |
| `/start` | Initializes user session and displays the interactive guide. |
| `/income [amount] [optional description]` | Logs a positive revenue inflow. |
| `/expense [amount] [optional description]` | Logs a financial cost or expenditure. |
| `/report` | Outputs today's line-by-line itemized ledger and totals. |
| `/report monthly` | Renders a day-by-day nested chronological monthly summary. |
| `/reset` | Permanently deletes all your transactions from the database. |

---

### 2. Environment Configuration

Create a `.env` file from the placeholder template:

```bash
cp .env.example .env

```

Open your `.env` file and configure your local MySQL database and Telegram Credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expense_tracker_db
DB_USERNAME=root
DB_PASSWORD=

TELEGRAM_BOT_TOKEN=your_bot_token_here

```

### 3. Initialize Database Tables

Ensure Apache and MySQL are running in your XAMPP Control Panel, then run the migration command:

```bash
php artisan migrate

```

### 4. Boot the Laravel Application

```bash
php artisan serve

```

Your application will run locally at `http://127.0.0.1:8000`

### 5. Expose Local Server via Ngrok

Open a separate terminal window and initiate your secure HTTP tunnel gateway on port 8000:

```bash
ngrok http 8000

```

Copy the secure `https://...` forwarding link generated in your Ngrok terminal dashboard (e.g., `https://sizzle-cardigan-replica.ngrok-free.dev`).

### 6. Register the Webhook Gateway

To route messages from Telegram directly to your local codebase, update your bot webhook via your browser address bar (replace placeholders with your active credentials):

```text
[https://api.telegram.org/bot](https://api.telegram.org/bot)<YOUR_TELEGRAM_BOT_TOKEN>/setWebhook?url=<YOUR_NGROK_SECURE_FORWARDING_URL>/api/telegram/webhook

```

Expected browser verification output: `{"ok":true,"result":true,"description":"Webhook was set"}`

---

## 📝 Architecture Highlights

**Webhook Security Handling:** Implemented safe routing resolution matching inside `TelegramController.php`, capturing chat metrics seamlessly while logging anomalous runtime exceptions to `storage/logs/laravel.log`.
**Database Transaction Isolation:** Heavy write/delete database manipulations (such as entry updates or structural purges via `/reset`) are fully isolated inside `DB::transaction()` callbacks to maintain ledger integrity and protect against partial runtime failures.
**Memory-Efficient Data Processing:** Instead of stressing the MySQL engine with multiple complex nested aggregation requests, historical logs are queried once inside an optimized date-bound index selection and then structured dynamically inside application memory using Laravel Eloquent's collection layer methods (`$transactions->groupBy()`).

```

```
