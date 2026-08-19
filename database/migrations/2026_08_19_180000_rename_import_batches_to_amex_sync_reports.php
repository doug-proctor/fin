<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monzo syncs and AMEX uploads each get their own report table, so this
     * one is named for the only thing that still writes to it.
     */
    public function up(): void
    {
        Schema::rename('import_batches', 'amex_sync_reports');

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('import_batch_id', 'amex_sync_report_id');
        });

        /** Every row in an AMEX-only table came from an AMEX CSV. */
        Schema::table('amex_sync_reports', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }

    public function down(): void
    {
        Schema::table('amex_sync_reports', function (Blueprint $table) {
            $table->string('source')->default('amex_csv');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('amex_sync_report_id', 'import_batch_id');
        });

        Schema::rename('amex_sync_reports', 'import_batches');
    }
};
