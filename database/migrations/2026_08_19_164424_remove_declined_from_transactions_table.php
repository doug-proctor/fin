<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declined transactions are turned away at the door now, so nothing is
     * left for the flag to mark. The rows already stored go with it, since
     * without the flag they would start appearing as ordinary spending.
     */
    public function up(): void
    {
        DB::table('transactions')->where('declined', true)->delete();

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('declined');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('declined')->default(false);
        });
    }
};
