<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This app holds exactly two accounts, Monzo and Amex, and SyncAccounts
     * keys on the owner and provider alone. Leaving external_id in the unique
     * key let the database hold a second Monzo account that the sync could
     * then only fail on.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider', 'external_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider']);
            $table->unique(['user_id', 'provider', 'external_id']);
        });
    }
};
