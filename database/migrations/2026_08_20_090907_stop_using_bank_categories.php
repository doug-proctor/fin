<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The app no longer reads any bank's categorisation, so the two traces it
 * left behind are cleared out.
 *
 * Rows Monzo filed are set back to uncategorised, leaving the user's own
 * choices and anything a rule matched exactly as they are. The categories
 * Monzo would only ever send as an opaque `category_` id keep the names the
 * user gave them but are re-valued as ordinary ones of their own, so no bank
 * handle survives anywhere.
 */
return new class extends Migration
{
    private const MONZO_ID_PREFIX = 'category_';

    private const CUSTOM_PREFIX = 'custom_';

    public function up(): void
    {
        $this->revalueBankIssuedCategories();

        DB::table('transactions')
            ->where('categorised_by', 'source')
            ->update([
                'category' => null,
                'categorised_by' => null,
                'category_rule_id' => null,
            ]);
    }

    /**
     * A bank id becomes a value built from the name the user gave it, and
     * every reference to the old one is repointed in the same pass.
     */
    private function revalueBankIssuedCategories(): void
    {
        $categories = DB::table('categories')
            ->where('value', 'like', self::MONZO_ID_PREFIX.'%')
            ->get();

        foreach ($categories as $category) {
            $value = $this->availableValue($category->user_id, $category->label);

            DB::table('categories')->where('id', $category->id)->update(['value' => $value]);

            DB::table('transactions')
                ->where('user_id', $category->user_id)
                ->where('category', $category->value)
                ->update(['category' => $value]);

            DB::table('category_rules')
                ->where('user_id', $category->user_id)
                ->where('set_category', $category->value)
                ->update(['set_category' => $value]);
        }
    }

    /**
     * Mirrors Category::createCustom: a slug of the name behind the custom
     * prefix, stepped past anything the unique index already holds. A name
     * with no latin characters slugs to nothing, and an unnamed category
     * still carries its bank id as its label, so both fall back to a random
     * handle rather than colliding on an empty one.
     */
    private function availableValue(int $userId, string $label): string
    {
        $slug = Str::slug(Str::startsWith($label, self::MONZO_ID_PREFIX) ? '' : $label, '_');

        $base = self::CUSTOM_PREFIX.($slug !== '' ? $slug : Str::lower(Str::random(8)));

        $value = $base;
        $suffix = 1;

        while (DB::table('categories')->where('user_id', $userId)->where('value', $value)->exists()) {
            $suffix++;
            $value = $base.'_'.$suffix;
        }

        return $value;
    }

    /**
     * Nothing to reverse: neither the bank ids nor which rows Monzo had filed
     * are recorded anywhere once this has run.
     */
    public function down(): void {}
};
