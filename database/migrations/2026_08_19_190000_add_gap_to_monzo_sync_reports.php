<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A gap is recorded on the run that caused it, not worked out from the
     * last sync time when the page renders. Once a late run has finished,
     * the last sync time is current again and the gap would be invisible,
     * while the transactions it covers are missing permanently.
     */
    public function up(): void
    {
        Schema::table('monzo_sync_reports', function (Blueprint $table) {
            $table->timestamp('gap_from')->nullable();
            $table->timestamp('gap_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monzo_sync_reports', function (Blueprint $table) {
            $table->dropColumn(['gap_from', 'gap_to']);
        });
    }
};
