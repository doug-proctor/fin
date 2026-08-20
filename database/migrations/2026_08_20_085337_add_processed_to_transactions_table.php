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
        Schema::table('transactions', function (Blueprint $table) {
            /**
             * Local state, not bank truth: an imported row starts unprocessed
             * and only the user marks it off. The default is what gives every
             * row already in the table the same starting point.
             */
            $table->boolean('processed')->default(false);

            $table->index(['user_id', 'processed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'processed']);
            $table->dropColumn('processed');
        });
    }
};
