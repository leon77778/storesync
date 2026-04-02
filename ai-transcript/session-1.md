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

---

## Step 11 — ImportController

**File:** `app/Http/Controllers/ImportController.php`

**What it does:**
Handles two things: showing the upload form (`create`) and processing the
submitted CSV (`store`).

**How `store()` works step by step:**
1. **Validate** — Laravel checks the file exists, is a CSV/txt, and is under 2MB.
   If not, it redirects back with error messages automatically.
2. **Store the file** — saves it to `storage/app/imports/` so we can read it.
3. **Open and parse the CSV** — `fgetcsv()` reads one line at a time. The first
   line (header row) is discarded. Every other line becomes a `$row` array.
4. **Create ImportBatch** — one record for the whole upload, now that we know
   how many rows there are.
5. **Loop through rows** — for each row, create an `Order` record (status = pending,
   totals = 0) and immediately dispatch `ProcessOrderJob`. The jobs go into the
   queue — they don't run during this request.
6. **Redirect to dashboard** with a success message.

**Line item format in the CSV:**
Items are encoded as `Name:qty:unit_price` in each column from index 3 onward.
Example: `Blue Widget:2:9.99`. The controller splits on `:` to build the array.

---

## Step 12 — DashboardController

**File:** `app/Http/Controllers/DashboardController.php`

**What it does:**
Two methods: the page (`index`) and the polling endpoint (`batchStatus`).

**`index()`** — loads all ImportBatches, newest first, 10 per page. The
`with('orders')` part is important: it eager-loads all orders for each batch
in one extra SQL query, instead of running a separate query for each batch
as it renders. This is called avoiding the "N+1 problem".

**`batchStatus()`** — returns JSON for one batch. The frontend JavaScript
calls this every 4 seconds. It returns only what's needed: batch counters and
per-order status/total. Laravel's route model binding automatically fetches
the `ImportBatch` from the `{batch}` URL parameter.

---

## Step 13 — Routes

**File:** `routes/web.php`

| Method | URL | Name | What it does |
|---|---|---|---|
| GET | `/` | — | Redirects to dashboard |
| GET | `/import` | `import.create` | Shows upload form |
| POST | `/import` | `import.store` | Handles CSV upload |
| GET | `/dashboard` | `dashboard.index` | Shows dashboard |
| GET | `/api/batches/{batch}/status` | `api.batch.status` | JSON polling endpoint |

Named routes (the strings after `->name(...)`) mean views can use
`route('dashboard.index')` instead of hardcoding `/dashboard` — if the URL
ever changes, only one place needs updating.

---

## Step 14 — Blade Layout

**File:** `resources/views/layouts/app.blade.php`

**What it does:**
The shared "shell" that wraps every page. Contains the `<head>`, navigation
bar, flash message display, and a `@yield('content')` placeholder where each
page's unique content slots in.

**`@vite([...])`** — tells Laravel to load the Tailwind CSS and JS through
Vite (the build tool). In development Vite serves them live with hot-reload;
in production they're compiled into minified files.

**Flash messages** — `session('success')` shows a green banner after a
redirect (e.g. after a successful upload). `$errors->any()` shows a red
banner for validation failures.

---

## Step 15 — Upload Form View

**File:** `resources/views/import/create.blade.php`

**What it does:**
A single-page upload form. Key things:

- `@csrf` — a hidden security token Laravel requires on every POST form.
  Without it, the request is rejected. It prevents "cross-site request
  forgery" attacks where another website tricks a logged-in user into
  submitting a form they didn't intend to.
- `enctype="multipart/form-data"` — required on any form that uploads a file.
  Without it, the file contents never reach the server.
- The JavaScript `onchange` handler updates the label to show the chosen
  filename instead of "Click to choose a file".
- A format hint box shows the exact CSV structure expected, so users know
  how to prepare their file.

---

## Step 16 — Dashboard View + Status Badge Partial

**Files:**
- `resources/views/dashboard/index.blade.php`
- `resources/views/dashboard/_status_badge.blade.php`

**What it does:**
Shows one card per ImportBatch. Each card has:
- A header with filename, time ago, progress fraction, and overall batch status
- A thin progress bar that fills as jobs complete
- A table of every order with its ref, customer, calculated total, and status badge

**The `_status_badge` partial** is a small reusable snippet included in
multiple places. It maps a status string (`pending`, `processing`, etc.) to
the right coloured badge. The underscore prefix is a convention meaning
"this is a partial, not a full page".

**How live updates work (the polling script):**
- `setInterval(..., 4000)` — runs a function every 4 seconds
- It finds all batch cards whose status isn't finished yet
- For each active batch, it calls `fetch(pollUrl)` to hit the JSON endpoint
- When the response comes back, it uses `replaceChildren()` to swap the
  badge elements in place — no page reload needed
- Once a batch reaches `completed`, `failed`, or `partial`, the card is
  excluded from future polls automatically

---

---

## Bug fixes

### Fix 1 — Windows fopen path error
**File:** `app/Http/Controllers/ImportController.php`
Replaced `storage_path("app/{$path}")` with `Storage::path($path)`.
On Windows, `storeAs()` can return backslash paths, making manual string
building fail. The Storage facade handles OS path separators correctly.

### Fix 2 — CSV parsing for standard column format
**File:** `app/Http/Controllers/ImportController.php`
The original parser expected items packed as `Name:qty:price` in one cell.
Real-world CSVs use separate columns. The controller now auto-detects which
format is being used based on whether column 3 contains a colon.

### Fix 4 — Flexible CSV header validation with clear error messages
**File:** `app/Http/Controllers/ImportController.php`

**Problem:** The controller previously read columns by fixed position (column 0,
1, 2…) and silently failed or produced garbage data when headers didn't match
exactly. A CSV with `"Email"` instead of `"customer_email"` would silently
map the wrong data.

**Change 1 — Normalisation:**
Every header cell is normalised before any comparison:
`strtolower(preg_replace('/[\s_\-]+/', '', $col))`
This strips spaces, underscores, and hyphens, then lowercases everything.
So `"Customer Email"`, `"customer_email"`, `"CUSTOMER-EMAIL"` all become
`"customeremail"` — and all match the same alias.

**Change 2 — Alias table (`COLUMN_ALIASES`):**
A constant maps each internal column name to every reasonable variation
a user might write. For example `customer_email` accepts: `email`,
`customer email`, `email_address`, etc. New aliases can be added in one place
without touching any other logic.

**Change 3 — `resolveColumnMap()`:**
A private method loops through the normalised headers and checks each one
against the alias lists. Returns a map of `internal_name → column index`
(e.g. `['order_ref' => 0, 'customer_email' => 2]`). Data rows are then
read by looking up the index from this map, not hardcoded positions.

**Change 4 — Missing column error:**
After building the map, we check which required columns are still absent.
If any are missing the user sees exactly which ones:
`"Missing required columns: quantity, unit_price"` — not a generic 500 error.

### Fix 3 — Add Items column to dashboard table
**File:** `resources/views/dashboard/index.blade.php`
Added an "Items" column between Customer and Email showing each line item
as `qty × name`, joined by commas (e.g. `2 × Running Shoes`).
Uses `collect()->map()->join()` — Laravel's collection helpers for
transforming and joining arrays cleanly in one line.

---

## Next steps (planned)
- [ ] Run migrations and test the full flow locally
- [ ] Configure Redis queue driver and Horizon
- [ ] Horizon dashboard setup
