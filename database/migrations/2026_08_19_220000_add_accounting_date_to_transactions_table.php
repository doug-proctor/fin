<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A charge does not always land in the month it belongs to: a meal eaten
     * in May can be settled up with a friend in June. Editing the booked date
     * would fix the month view by lying about what the bank recorded, so the
     * month a row counts towards is held separately and left null whenever
     * the booked date is already right.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('accounting_date')->nullable()->after('booked_at');
            $table->index(['user_id', 'accounting_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            /** SQLite refuses to drop a column an index still points at. */
            $table->dropIndex(['user_id', 'accounting_date']);
            $table->dropColumn('accounting_date');
        });
    }
};
