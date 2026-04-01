<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrderJob;
use App\Models\ImportBatch;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    /**
     * Show the CSV upload form.
     * GET /import
     */
    public function create(): View
    {
        return view('import.create');
    }

    /**
     * Handle the uploaded CSV file.
     * POST /import
     *
     * Steps:
     *  1. Validate the uploaded file
     *  2. Create an ImportBatch record (the "folder" for this upload)
     *  3. Parse the CSV row by row
     *  4. Create one Order record per row
     *  5. Dispatch one ProcessOrderJob per order
     *  6. Redirect to the dashboard
     */
    public function store(Request $request): RedirectResponse
    {
        // Step 1 — validate the upload.
        // 'file' means it must be a real file upload (not a string).
        // 'mimes:csv,txt' allows both .csv and plain-text CSV files.
        // 'max:2048' limits to 2MB — prevents huge files locking up the server.
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        // Step 2 — store the uploaded file in storage/app/imports temporarily
        // so we can read it. getClientOriginalName() gives us the original filename.
        $file     = $request->file('csv_file');
        $filename = $file->getClientOriginalName();
        $path     = $file->storeAs('imports', $filename);

        // Step 3 — open the CSV and read all rows into an array.
        // fgetcsv reads one comma-separated line at a time.
        $fullPath = storage_path("app/{$path}");
        $handle   = fopen($fullPath, 'r');

        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'Could not read the uploaded file.']);
        }

        // Read and discard the header row (first line).
        // We assume the CSV always has: order_ref, customer_name, customer_email,
        // then one or more item columns. The exact item structure is handled below.
        $header = fgetcsv($handle);

        if ($header === false || count($header) < 3) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'CSV must have at least 3 columns: order_ref, customer_name, customer_email.']);
        }

        // Collect all data rows first so we know the total count
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            // Skip blank lines
            if (array_filter($row)) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        if (empty($rows)) {
            return back()->withErrors(['csv_file' => 'The CSV file contains no data rows.']);
        }

        // Step 4 — create the ImportBatch now that we know total_rows.
        $batch = ImportBatch::create([
            'filename'   => $filename,
            'total_rows' => count($rows),
            'status'     => 'pending',
        ]);

        // Step 5 — loop through every row, create an Order, dispatch a job.
        foreach ($rows as $row) {
            // Map positional CSV columns to named values.
            // We use null coalescing (?? '') so missing columns don't crash.
            $orderRef      = trim($row[0] ?? '');
            $customerName  = trim($row[1] ?? '');
            $customerEmail = trim($row[2] ?? '');

            // Columns from index 3 onward are line items.
            // Expected format per cell: "Product Name:qty:unit_price"
            // e.g. "Blue Widget:2:9.99"
            $lineItems = [];
            for ($i = 3; $i < count($row); $i++) {
                $parts = explode(':', trim($row[$i]));
                if (count($parts) === 3) {
                    $lineItems[] = [
                        'name'       => trim($parts[0]),
                        'qty'        => trim($parts[1]),
                        'unit_price' => trim($parts[2]),
                    ];
                }
            }

            // Create the Order record with status 'pending'.
            // Totals start at 0 — the job will calculate and fill them in.
            $order = Order::create([
                'import_batch_id' => $batch->id,
                'order_ref'       => $orderRef,
                'customer_name'   => $customerName,
                'customer_email'  => $customerEmail,
                'line_items'      => $lineItems,
                'status'          => 'pending',
            ]);

            // Dispatch the background job for this order.
            // dispatch() puts it on the queue — it doesn't run right now.
            ProcessOrderJob::dispatch($order);
        }

        // Step 6 — redirect to the dashboard with a success flash message.
        return redirect()
            ->route('dashboard.index')
            ->with('success', "Imported {$batch->total_rows} orders from \"{$filename}\". Jobs are processing in the background.");
    }
}
