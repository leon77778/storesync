# StoreSync — Claude Code Session 1
**Date:** 2026-04-01
**Tool:** Claude Code (Anthropic) — VS Code Extension
**Model:** claude-sonnet-4-6

---

## What we built this session
- README.md (project overview)
- CLAUDE.md (context file for Claude Code)
- Two database migrations
- Two Eloquent models (ImportBatch, Order)
- ProcessOrderJob (background worker)
- OrderConfirmationMail (email class)
- Email Blade view
- This transcript

---

## Step 1 — README.md

**What:** Replaced the default Laravel README with a project-specific one.
**Why:** Documents what StoreSync does, the tech stack, and leaves placeholder
sections to fill in as the build progresses.

---

## Step 2 — CLAUDE.md

**What:** Created a context file that Claude Code reads at the start of every session.
**Why:** It tells the AI about the project stack, constraints, and how to collaborate —
so it doesn't need re-explaining each time. Key rules set: propose options before
implementing, explain trade-offs, flag security issues.

---

## Step 3 — Architecture Planning (no code)

**The question:** Before writing any code, what models, migrations, jobs,
controllers, and routes do we need?

**Decisions made:**

| Decision | Choice | Reason |
|---|---|---|
| Track CSV uploads? | Yes — ImportBatch model | Gives batch-level visibility (e.g. "48/50 complete") |
| Store line items? | JSON column on orders table | Simpler than a separate table; enough for this scope |
| Parse CSV where? | Synchronous in controller | Avoids two-stage job chain; fine for small files |
| Dashboard updates? | Polling every 3–5s | Zero extra infrastructure vs WebSockets |

---

## Step 4 — Migration: create_import_batches_table

**File:** `database/migrations/2026_04_01_000001_create_import_batches_table.php`

**What it does:**
Creates the `import_batches` table. One row = one CSV file upload.

**Columns explained:**
- `filename` — the original CSV filename, shown in the dashboard
- `total_rows` — how many order rows were in the CSV
- `completed_rows` / `failed_rows` — counters incremented by jobs as they finish
- `status` — overall batch state: pending → processing → completed / failed / partial

**What "partial" means:** Some rows succeeded and some failed — more honest than
calling it either completed or failed.

---

## Step 5 — Migration: create_orders_table

**File:** `database/migrations/2026_04_01_000002_create_orders_table.php`

**What it does:**
Creates the `orders` table. One row = one order from the CSV = one background job.

**Key design decisions explained:**

**Why store money as integers (pence)?**
Floating-point numbers can't represent money exactly in any programming language.
`0.1 + 0.2` in PHP = `0.30000000000000004`. Storing as pence (e.g. £19.98 = 1998)
means we only ever do integer arithmetic, which is exact. We divide by 100 only
at display time.

**Why index the status column?**
The dashboard polls `orders` by status very frequently. An index makes those
queries fast even with thousands of rows.

**Why no unique constraint on order_ref?**
The same order reference could legitimately appear in multiple CSV uploads
(e.g. a re-upload after a failed batch), so we don't enforce uniqueness.

---

## Step 6 — Model: ImportBatch

**File:** `app/Models/ImportBatch.php`

**What it does:**
The PHP class that represents one row in the `import_batches` table.

**Key parts:**
- `$fillable` — lists which columns can be set in bulk (security measure —
  prevents someone sneaking in extra columns via a form submission)
- `$casts` — tells Laravel to treat the counter columns as PHP integers,
  not strings (databases return everything as strings by default)
- `orders()` relationship — lets us write `$batch->orders` to get all orders
  belonging to this upload, instead of writing a manual SQL query
- `pendingRows()` — calculated as total minus completed minus failed
- `progressPercent()` — returns 0–100 for a progress bar in the dashboard
- `recalculateStatus()` — called by each job when it finishes; updates the
  batch's overall status based on the current row counters

---

## Step 7 — Model: Order

**File:** `app/Models/Order.php`

**What it does:**
The PHP class that represents one row in the `orders` table (one CSV order).

**Key parts:**
- `line_items` cast to `'array'` — Laravel automatically JSON-decodes this
  column when reading and JSON-encodes it when saving. We just work with a
  normal PHP array in our code.
- `processed_at` cast to `'datetime'` — gives us a Carbon date object,
  so we can write things like `$order->processed_at->diffForHumans()`
  (outputs "3 minutes ago")
- `importBatch()` relationship — the reverse of `ImportBatch::orders()`.
  Lets us write `$order->importBatch` to get the parent upload.
- `penceToCurrency()` — static helper that converts e.g. `1998` → `"£19.98"`.
  Three convenience wrappers (`formattedSubtotal`, `formattedTax`, `formattedTotal`)
  call this so Blade views can just write `{{ $order->formattedTotal() }}`.
- Status helpers (`isPending()`, `isCompleted()`, etc.) — make Blade templates
  more readable: `@if($order->isFailed())` instead of `@if($order->status === 'failed')`.

---

---

## Step 8 — ProcessOrderJob

**File:** `app/Jobs/ProcessOrderJob.php`

**What it does:**
This is the background worker — the thing that actually processes each order
after it's been dispatched from the CSV upload. Laravel puts it in a queue
(a waiting list) and a worker process picks it up and runs it.

**The lifecycle of one job:**
1. Job is dispatched → status set to `processing`
2. Validates the order data (name, email, line items all present and valid)
3. Calculates subtotal, 20% VAT, and total — stored as pence
4. Sends a confirmation email to the customer
5. Marks the order `completed`, updates the batch counters

**Key concepts explained:**

**`implements ShouldQueue`** — This tells Laravel "don't run this now, put it
in the queue." Without this interface, the job would run immediately and slow
down the upload request.

**`$tries = 3` and `$backoff = [10, 60, 300]`** — If the job throws an error,
Laravel retries it. First retry after 10 seconds, second after 60, third after
5 minutes. This handles temporary issues like a mail server being briefly down.

**`SerializesModels`** — When the job goes into the queue, Laravel can't store
the whole Order object. This trait converts it to just the ID, then fetches a
fresh copy from the database when the job runs. Prevents stale data.

**`failed()` method** — After all retries are exhausted, Laravel calls this.
We store the error message on the order (visible in the dashboard) and update
the batch counters so the overall status stays accurate.

**Why `intval(round(floatval($item['unit_price']) * 100))`?**
CSV prices are strings like `"9.99"`. `floatval` converts to a float, multiply
by 100 gives `999.0`, `round` ensures no floating-point drift, `intval` makes
it a whole integer. Result: 999 pence.

---

## Step 9 — OrderConfirmationMail

**File:** `app/Mail/OrderConfirmationMail.php`

**What it does:**
A Laravel Mailable — a class that represents one email. Think of it as a
"letter template" that knows: who to send to, what the subject is, and which
view to use for the body.

**Key parts:**
- `envelope()` — sets the subject line the customer sees in their inbox
- `content()` — points to the Blade view that contains the email HTML
- The `$order` property is `public`, which means Laravel automatically passes
  it to the Blade view — no extra code needed

---

## Step 10 — Email Blade View

**File:** `resources/views/emails/order-confirmation.blade.php`

**What it does:**
The HTML template for the customer confirmation email. Shows their name,
order reference, a line-items table with quantities and prices, and the
totals (subtotal, VAT, total).

**Why plain inline CSS (not Tailwind)?**
Email clients (Gmail, Outlook) strip out external stylesheets and don't
support modern CSS. Inline styles are the only reliable way to style emails.
Tailwind classes wouldn't work here.

**The line total calculation** happens in the template using `@php` blocks
because the database only stores `unit_price` and `qty` per item — the line
total is derived, not stored.

---

## Next steps (planned)
- [ ] ImportController — handles CSV upload form
- [ ] DashboardController — shows batch/order statuses
- [ ] Routes
- [ ] Blade views (upload form + dashboard)
- [ ] Horizon configuration
