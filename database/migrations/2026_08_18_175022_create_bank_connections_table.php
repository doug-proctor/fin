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
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('monzo');
            $table->string('external_user_id')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('oauth_state')->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('sca_confirmed_at')->nullable();

            /**
             * Null means the one-shot five minute full-history window was never
             * successfully used, so only the last 90 days are recoverable
             * without disconnecting and re-authorising.
             */
            $table->timestamp('history_backfilled_at')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
