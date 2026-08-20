<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule now looks for a list of strings rather than one, matching a
 * transaction when any of them matches.
 *
 * The single `match_value` column is replaced outright rather than kept
 * alongside the list: two columns holding the same thing can disagree, and one
 * cannot. Every existing rule becomes a list of one, which behaves exactly as
 * it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_rules', function (Blueprint $table): void {
            $table->json('match_values')->nullable()->after('match_type');
        });

        DB::table('category_rules')
            ->select(['id', 'match_value'])
            ->orderBy('id')
            ->each(fn (object $rule) => DB::table('category_rules')
                ->where('id', $rule->id)
                ->update(['match_values' => json_encode([$rule->match_value])]));

        Schema::table('category_rules', function (Blueprint $table): void {
            $table->dropColumn('match_value');
        });
    }

    /**
     * Only the first string survives going back: the old column holds one, and
     * a rule's first string is the one it was written with.
     */
    public function down(): void
    {
        Schema::table('category_rules', function (Blueprint $table): void {
            $table->string('match_value')->nullable()->after('match_type');
        });

        DB::table('category_rules')
            ->select(['id', 'match_values'])
            ->orderBy('id')
            ->each(function (object $rule): void {
                /** @var array<int, string> $values */
                $values = json_decode((string) $rule->match_values, true) ?: [];

                DB::table('category_rules')
                    ->where('id', $rule->id)
                    ->update(['match_value' => $values[0] ?? '']);
            });

        Schema::table('category_rules', function (Blueprint $table): void {
            $table->dropColumn('match_values');
        });
    }
};
