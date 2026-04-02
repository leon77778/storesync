<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrderJob;
use App\Models\ImportBatch;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ImportController extends Controller
{
    /**
     * The canonical column names we require, and every variation we'll accept.
     *
     * Keys   = the internal name used in code.
     * Values = all header strings that should map to that internal name.
     *          Comparison is case-insensitive and ignores spaces/underscores/hyphens.
     */
    private const COLUMN_ALIASES = [
        'order_ref'      => ['order_ref', 'orderref', 'order ref', 'order-ref', 'ref', 'order_number', 'ordernumber', 'order number'],
        'customer_name'  => ['customer_name', 'customername', 'customer name', 'customer-name', 'name', 'full_name', 'fullname'],
        'customer_email' => ['customer_email', 'customeremail', 'customer email', 'customer-email', 'email', 'email_address', 'emailaddress'],
        'product_name'   => ['product_name', 'productname', 'product name', 'product-name', 'product', 'item', 'item_name', 'itemname'],
        'quantity'       => ['quantity', 'qty', 'amount', 'count'],
        'unit_price'     => ['unit_price', 'unitprice', 'unit price', 'unit-price', 'price', 'cost', 'unit_cost'],
    ];

    // -------------------------------------------------------------------------

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
     */
    public function store(Request $request): RedirectResponse
    {
        // --- File-level validation -------------------------------------------
        // Checks the upload exists, is a real file, is CSV/txt, and under 2MB.
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file     = $request->file('csv_file');
        $filename = $file->getClientOriginalName();
        $path     = $file->storeAs('imports', $filename);

        // Open the file — Storage::path() handles Windows/Linux path differences.
        $fullPath = Storage::path($path);
        $handle   = fopen($fullPath, 'r');

        if ($handle === false) {
            return back()->withErrors(['csv_file' => 'Could not read the uploaded file.']);
        }

        // --- Header validation -----------------------------------------------
        $rawHeader = fgetcsv($handle);

        if ($rawHeader === false) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'The file appears to be empty.']);
        }

        // Normalise every header cell: lowercase, strip spaces/underscores/hyphens.
        // "Customer Email", "customer_email", "CUSTOMER-EMAIL" all become "customeremail".
        // This lets us match regardless of how the user formatted their headers.
        $normalisedHeader = array_map(
            fn(string $col): string => strtolower(preg_replace('/[\s_\-]+/', '', $col)),
            $rawHeader
        );

        // Build a map of internal_name → column index by checking each header
        // cell against our alias lists.
        $columnMap = $this->resolveColumnMap($normalisedHeader);

        // Check which required columns are still missing after alias resolution.
        $required = ['order_ref', 'customer_name', 'customer_email', 'product_name', 'quantity', 'unit_price'];
        $missing  = array_filter($required, fn($col) => ! isset($columnMap[$col]));

        if (! empty($missing)) {
            fclose($handle);
            // Tell the user exactly which columns are missing so they can fix their CSV.
            return back()->withErrors([
                'csv_file' => 'Missing required columns: ' . implode(', ', $missing) . '. '
                            . 'Check your header row — column names are flexible but must be recognisable '
                            . '(e.g. "email", "Email", "customer_email" all work for the email column).',
            ]);
        }

        // --- Read data rows --------------------------------------------------
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (array_filter($row)) { // skip blank lines
                $rows[] = $row;
            }
        }
        fclose($handle);

        if (empty($rows)) {
            return back()->withErrors(['csv_file' => 'The CSV file contains no data rows.']);
        }

        // --- Create batch and dispatch jobs ----------------------------------
        $batch = ImportBatch::create([
            'filename'   => $filename,
            'total_rows' => count($rows),
            'status'     => 'pending',
        ]);

        foreach ($rows as $row) {
            // Read each value by looking up its resolved column index.
            // If a column is somehow absent in a data row, default to ''.
            $orderRef      = trim($row[$columnMap['order_ref']]      ?? '');
            $customerName  = trim($row[$columnMap['customer_name']]  ?? '');
            $customerEmail = trim($row[$columnMap['customer_email']] ?? '');

            $lineItems = [[
                'name'       => trim($row[$columnMap['product_name']] ?? ''),
                'qty'        => trim($row[$columnMap['quantity']]     ?? ''),
                'unit_price' => trim($row[$columnMap['unit_price']]   ?? ''),
            ]];

            $order = Order::create([
                'import_batch_id' => $batch->id,
                'order_ref'       => $orderRef,
                'customer_name'   => $customerName,
                'customer_email'  => $customerEmail,
                'line_items'      => $lineItems,
                'status'          => 'pending',
            ]);

            ProcessOrderJob::dispatch($order);
        }

        return redirect()
            ->route('dashboard.index')
            ->with('success', "Imported {$batch->total_rows} orders from \"{$filename}\". Jobs are processing in the background.");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Given a normalised header row, return a map of internal_name → column index.
     *
     * For each column in the CSV header we check it against every alias list.
     * The first match wins. Unrecognised columns are simply ignored.
     *
     * @param  array<int, string> $normalisedHeader
     * @return array<string, int>  e.g. ['order_ref' => 0, 'customer_email' => 2, ...]
     */
    private function resolveColumnMap(array $normalisedHeader): array
    {
        $map = [];

        foreach ($normalisedHeader as $index => $normalisedValue) {
            foreach (self::COLUMN_ALIASES as $internalName => $aliases) {
                if (in_array($normalisedValue, $aliases, strict: true) && ! isset($map[$internalName])) {
                    $map[$internalName] = $index;
                    break; // move on to the next header column
                }
            }
        }

        return $map;
    }
}
