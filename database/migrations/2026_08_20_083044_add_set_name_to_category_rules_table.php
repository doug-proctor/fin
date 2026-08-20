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
             * A replacement name for the transactions this rule matches, so a
             * bank's payment reference can be read as the merchant behind it.
             * Null leaves the name the bank sent alone.
             */
            $table->string('set_name')->nullable()->after('set_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_rules', function (Blueprint $table) {
            $table->dropColumn('set_name');
        });
    }
};
