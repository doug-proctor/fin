<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Avolta rule is retired, and `shopping` goes with it: that rule was the
 * only thing filing anything under it, so once it is gone the category would
 * sit in every category control with nothing behind it.
 *
 * A user who has already filed a transaction under `shopping` by hand keeps
 * the category, because deleting it would leave that row with no name to
 * show.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('category_rules')
            ->where('name', 'Avolta')
            ->where('match_value', 'AVOLTA')
            ->delete();

        DB::table('categories')
            ->where('value', 'shopping')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('transactions')
                ->whereColumn('transactions.user_id', 'categories.user_id')
                ->where('transactions.category', 'shopping'))
            ->delete();
    }

    /**
     * Nothing to reverse: the rule and the category are not recorded anywhere
     * once this has run.
     */
    public function down(): void {}
};
