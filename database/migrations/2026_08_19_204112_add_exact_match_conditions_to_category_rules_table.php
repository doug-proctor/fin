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
        Schema::table('category_rules', function (Blueprint $table) {
            /**
             * An exact amount, alongside the existing bounds. Signed minor
             * units, so -1250 matches a £12.50 charge and not a £12.50
             * refund.
             */
            $table->integer('amount_minor')->nullable()->after('amount_max_minor');

            /**
             * An exact date. Held as a date rather than a datetime because
             * booked_at carries a time and no user would ever pin a rule to
             * the second.
             */
            $table->date('booked_on')->nullable()->after('amount_minor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_rules', function (Blueprint $table) {
            $table->dropColumn(['amount_minor', 'booked_on']);
        });
    }
};
