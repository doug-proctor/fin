<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both flags were Monzo's own opinion about its spending pie chart, copied
     * verbatim and never shown, edited or set on an AMEX row. The Type and
     * Direction filters cover the same ground using columns that are visible.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['is_load', 'include_in_spending']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_load')->default(false);
            $table->boolean('include_in_spending')->default(true);
        });
    }
};
