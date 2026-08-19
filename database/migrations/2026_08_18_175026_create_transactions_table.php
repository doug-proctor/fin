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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            /**
             * Identity and provenance.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('external_id')->nullable();

            /**
             * Stable identity for a row within its account. Monzo rows hash
             * their transaction id; CSV rows hash date, amount, description
             * and an occurrence index, because AMEX exports carry no id.
             */
            $table->string('dedupe_hash', 40);

            /**
             * Bank truth. Refreshed on every sync unless the field is listed
             * in the overrides map below.
             */
            $table->timestamp('booked_at');
            $table->timestamp('settled_at')->nullable();
            $table->integer('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->integer('local_amount_minor')->nullable();
            $table->char('local_currency', 3)->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('source_category')->nullable();
            $table->string('type')->nullable();
            $table->string('emoji')->nullable();
            $table->string('merchant_external_id')->nullable();
            $table->string('merchant_name')->nullable();
            $table->string('merchant_address')->nullable();
            $table->json('counterparty')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_load')->default(false);
            $table->boolean('include_in_spending')->default(true);
            $table->boolean('declined')->default(false);
            $table->string('decline_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw')->nullable();

            /**
             * Local state. A map of column name to true for every field the
             * user has edited by hand; the importer never writes over a key
             * present here.
             */
            $table->json('overrides')->nullable();
            $table->string('categorised_by')->nullable();
            $table->foreignId('category_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'dedupe_hash']);
            $table->index(['user_id', 'booked_at']);
            $table->index(['account_id', 'booked_at']);
            $table->index('category');
            $table->index('processed_at');
            $table->index('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
