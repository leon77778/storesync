<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each row represents one CSV file upload.
     * Tracks aggregate status across all orders in that upload.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();

            // Original filename shown in the dashboard
            $table->string('filename');

            // Total rows parsed from the CSV (excludes header row)
            $table->unsignedInteger('total_rows')->default(0);

            // Counters updated as jobs complete/fail
            $table->unsignedInteger('completed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // Overall batch status derived from row counters
            // pending   = jobs not yet started
            // processing = at least one job running
            // completed  = all rows succeeded
            // failed     = all rows finished but some failed
            // partial    = finished with a mix of success and failure
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial'])
                  ->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
