<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * There are exactly two accounts, Monzo and Amex, told apart by provider.
     * The bank's own account type and identifying numbers were written but
     * never read or shown.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['type', 'sort_code', 'account_number_last4', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('account_number_last4', 4)->nullable();
            $table->timestamp('closed_at')->nullable();
        });
    }
};
