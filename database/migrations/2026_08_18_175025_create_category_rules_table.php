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
        Schema::create('category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /**
             * Null applies the rule across every account, which is what makes
             * one category taxonomy span both Monzo and AMEX.
             */
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('match_field')->default('any');
            $table->string('match_type')->default('contains');
            $table->string('match_value');
            $table->integer('amount_min_minor')->nullable();
            $table->integer('amount_max_minor')->nullable();

            $table->string('set_category')->nullable();
            $table->json('set_tags')->nullable();

            $table->integer('priority')->default(0);
            $table->boolean('stops_processing')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_applied')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_rules');
    }
};
