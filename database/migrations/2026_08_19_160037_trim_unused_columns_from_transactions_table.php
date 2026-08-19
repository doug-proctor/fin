<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns that were stored but never shown and never queried. The table
     * keeps only what the screen renders, what a filter needs, and identity.
     *
     * @var array<int, string>
     */
    private const DROPPED = [
        'source',
        'source_category',
        'settled_at',
        'local_amount_minor',
        'local_currency',
        'emoji',
        'merchant_external_id',
        'counterparty',
        'decline_reason',
        'metadata',
        'raw',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(self::DROPPED);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->string('source_category')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->integer('local_amount_minor')->nullable();
            $table->char('local_currency', 3)->nullable();
            $table->string('emoji')->nullable();
            $table->string('merchant_external_id')->nullable();
            $table->json('counterparty')->nullable();
            $table->string('decline_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw')->nullable();
        });
    }
};
