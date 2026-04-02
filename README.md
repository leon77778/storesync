# StoreSync — eCommerce Order Import Pipeline

A Laravel application built for magic42's technical interview assignment,
demonstrating agentic coding practices with Claude Code.

---

## What it does

StoreSync automates the processing of bulk eCommerce orders from a CSV upload:

1. A user uploads a CSV file of orders via a clean web interface
2. Each row is immediately dispatched as an independent background job
3. Each job validates the order data, calculates subtotal + 20% VAT + total, and sends a confirmation email to the customer
4. A live dashboard polls for status updates every 4 seconds — showing each order as pending → processing → completed / failed, with a progress bar per upload batch

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 / Laravel 11 |
| Database | SQLite (dev) / MySQL (production) |
| Queue driver | Database (dev) / Redis (production) |
| Queue dashboard | Laravel Horizon |
| Frontend | Tailwind CSS v4 + Vite |
| Email testing | Mailtrap (SMTP sandbox) |
| AI coding tool | Claude Code — Anthropic VS Code extension |

---

## Setup Instructions

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+ and npm
- SQLite (built into PHP) or MySQL

### 1. Clone the repository

```bash
git clone https://github.com/leon77778/storesync.git
cd storesync
```

### 2. Install dependencies

```bash
composer install
npm install
```

### 3. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and set your database connection. For local development, SQLite
requires no extra setup:

```env
DB_CONNECTION=sqlite
```

For MySQL, uncomment and fill in:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=storesync
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Run migrations

```bash
php artisan migrate
```

This creates: `import_batches`, `orders`, plus Laravel's built-in `jobs`,
`failed_jobs`, `cache`, and `sessions` tables.

### 5. Configure email (optional for development)

By default `MAIL_MAILER=log` — emails are written to `storage/logs/laravel.log`
instead of being sent. This is intentional for development (no external
dependencies, works offline).

To preview emails in a real inbox, set up [Mailtrap](https://mailtrap.io) and
update `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
```

### 6. Start the application

You need three processes running simultaneously — open three terminal tabs:

**Tab 1 — PHP development server:**
```bash
php artisan serve
```

**Tab 2 — Vite (Tailwind CSS compiler with hot-reload):**
```bash
npm run dev
```

**Tab 3 — Queue worker (processes background jobs):**
```bash
php artisan queue:work
```

Visit [http://127.0.0.1:8000](http://127.0.0.1:8000) — you'll be redirected
to the dashboard automatically.

### 7. Upload a test CSV

Navigate to `/import`. Your CSV must include these headers (case-insensitive,
spaces/underscores/hyphens all accepted):

```
order_ref, customer_name, customer_email, product_name, quantity, unit_price
```

Example row:
```
ORD-001, Jane Smith, jane@example.com, Running Shoes, 2, 49.99
```

---

## My Development Approach

This project was built using **Claude Code** (Anthropic's VS Code extension)
as an agentic coding assistant — not as an autocomplete tool, but as a
collaborative engineering partner.

### How the workflow worked

Every significant decision was made **before** any code was written. The
process for each feature was:

1. **Describe the problem** to Claude Code in plain English
2. **Receive a structured proposal** with multiple options and explicit trade-offs
3. **Make the decision** myself — approving, rejecting, or adjusting the proposal
4. **Claude implements** the chosen approach with thorough inline comments
5. **Review, test, and iterate** — feeding errors and outcomes back in

This mirrors how a senior developer would pair with a junior: the junior
proposes and implements, the senior decides and directs.

### What agentic means in practice

- The AI maintained context across the entire session — understanding that
  `ImportBatch` feeds `ProcessOrderJob` feeds the dashboard polling endpoint
- When a real bug appeared (Windows path separator issue with `fopen`), I
  described the error and Claude diagnosed the root cause and fixed it
- When the test CSV used different header names than expected, Claude built
  a flexible normalisation system rather than just patching the one failing case
- Every decision and trade-off is logged in [`/ai-transcript/session-1.md`](ai-transcript/session-1.md)

### What I decided, not the AI

- The overall architecture (queued jobs per row, ImportBatch model, polling dashboard)
- The CSV format and column naming
- To use `log` driver over `smtp` during development
- To store money as integers (pence) rather than decimals
- To keep CSV parsing synchronous rather than adding a second job layer

---

## Key Architectural Decisions

### 1. Queued jobs per CSV row — not bulk processing

Each CSV row becomes one independent `ProcessOrderJob`. The alternative would
be a single job that processes the entire file.

**Why per-row:**
- Each order can fail independently without affecting others. A bad email
  address on row 7 doesn't block rows 8–500.
- Individual retries. Each job retries up to 3 times with exponential backoff
  (10s → 60s → 5min). A bulk job would have to retry everything.
- The dashboard can show per-order status in real time, not just "batch done/failed".
- Laravel Horizon gives per-job visibility, timing, and failure inspection.

### 2. ImportBatch model — tracking uploads as first-class entities

A dedicated `import_batches` table records each CSV upload with counters
(`completed_rows`, `failed_rows`) and an overall status.

**Why a separate model:**
- The dashboard shows "48/50 complete" — that denominator has to come from
  somewhere. Without `ImportBatch`, you'd run an expensive `COUNT()` query
  on the orders table on every poll.
- `recalculateStatus()` on the model keeps status logic in one place. Jobs
  call it after every completion — no controller or view needs to know the rules.
- `partial` status (some succeeded, some failed) is a real outcome in eCommerce
  bulk imports. A binary completed/failed would hide that.

### 3. Money stored as integers (pence/cents)

All monetary values (`subtotal_pence`, `tax_pence`, `total_pence`) are stored
as integers representing the smallest currency unit.

**Why integers, not decimals:**
Floating-point arithmetic cannot represent most decimal fractions exactly.
`0.1 + 0.2` in PHP evaluates to `0.30000000000000004`. At scale, these
rounding errors compound across thousands of order totals. Storing as integers
(e.g. £19.98 → 1998) means all arithmetic is exact. The `penceToCurrency()`
helper on the `Order` model divides by 100 only at the display layer.

### 4. Flexible CSV header normalisation

The CSV parser normalises every header before matching: strips
spaces/underscores/hyphens, lowercases. Then checks against an alias table
where each required column lists every reasonable variation.

**Why not just enforce a fixed format:**
Real-world CSV exports from Shopify, WooCommerce, Magento, and spreadsheets
all use different column names. A rigid parser that rejects `"Email"` when
it expects `"customer_email"` creates unnecessary friction. The alias table
means the system is tolerant of the messy reality of data exports, while
still giving clear error messages when a genuinely required column is absent.

### 5. Synchronous CSV parsing, asynchronous order processing

The CSV is parsed and `Order` records are created synchronously within the
HTTP request. Only the per-order processing (validation, totals, email) is
queued.

**Why not queue the CSV parsing too:**
A two-stage queue (parse job → order jobs) adds complexity and makes it
impossible to give the user an immediate count of how many orders were found.
For files under 2MB (~5,000 rows), parsing is fast enough to do inline.
The user gets instant feedback ("50 orders imported, processing in background")
rather than a second wait for the parse job to run.

### 6. Polling over WebSockets for the live dashboard

The dashboard refreshes order statuses by calling a JSON endpoint every 4
seconds via `setInterval`, rather than using Laravel Echo + WebSockets.

**Why polling:**
WebSockets require a persistent server process (Laravel Reverb or Pusher),
additional configuration, and extra infrastructure. Polling at 4-second
intervals adds at most 4 seconds of latency to a status update — which is
imperceptible in the context of background jobs that take seconds to minutes.
Polling stops automatically once a batch reaches a terminal state, so it
doesn't run indefinitely.

---

## Project Structure

```
app/
├── Http/Controllers/
│   ├── ImportController.php     # CSV upload + parsing
│   └── DashboardController.php  # Dashboard page + JSON polling endpoint
├── Jobs/
│   └── ProcessOrderJob.php      # Background worker: validate, calculate, email
├── Mail/
│   └── OrderConfirmationMail.php
├── Models/
│   ├── ImportBatch.php           # One record per CSV upload
│   └── Order.php                 # One record per CSV row

database/migrations/
├── ..._create_import_batches_table.php
└── ..._create_orders_table.php

resources/views/
├── layouts/app.blade.php         # Shared nav + flash messages
├── import/create.blade.php       # CSV upload form
├── dashboard/
│   ├── index.blade.php           # Live dashboard with polling JS
│   └── _status_badge.blade.php  # Reusable coloured badge partial
└── emails/
    └── order-confirmation.blade.php
```

---

## AI Transcript

Full conversation history — every decision, trade-off, bug, and fix — is
documented in [`/ai-transcript/session-1.md`](ai-transcript/session-1.md).
