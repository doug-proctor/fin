<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /**
             * Null for accounts that have no OAuth connection behind them,
             * such as the AMEX card that is populated by CSV upload.
             */
            $table->foreignId('bank_connection_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->string('sort_code')->nullable();
            $table->string('account_number_last4', 4)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'external_id']);
            $table->index('bank_connection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
