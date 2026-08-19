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
             * A day of the month rather than a full date. Pinning a rule to
             * one calendar date only ever matches once, so the useful
             * question is "the 4th of any month", not "4 March 2026".
             */
            $table->unsignedTinyInteger('day_of_month')->nullable()->after('amount_minor');

            $table->dropColumn('booked_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_rules', function (Blueprint $table) {
            $table->date('booked_on')->nullable()->after('amount_minor');

            $table->dropColumn('day_of_month');
        });
    }
};
