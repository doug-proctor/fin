<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /**
             * 'YYYY-MM', the same string the URL and the month prop carry. Not
             * a date column: the date cast writes 'Y-m-d H:i:s' while a raw
             * binding leaves 'Y-m-d', and SQLite compares both as text, so the
             * two never match. See TransactionQuery::countsTowardsMonthCondition.
             */
            $table->char('month', 7);
            /** What transactions.category stores, so the two can be compared. */
            $table->string('category');
            /** A target has no direction, so this is a positive magnitude. */
            $table->unsignedInteger('amount_minor');
            $table->timestamps();

            /** Also the read index: the only query is user_id plus month. */
            $table->unique(['user_id', 'month', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_targets');
    }
};
