<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The app pulls a 90 day window and nothing more, so there is no longer a
     * one-shot deeper history to have missed or to record having caught.
     */
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn('history_backfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->timestamp('history_backfilled_at')->nullable();
        });
    }
};
