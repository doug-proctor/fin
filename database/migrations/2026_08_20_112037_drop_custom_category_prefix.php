<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every category is one the user owns now, so the `custom_` prefix that once
 * told a user-added value apart from a built-in one no longer says anything
 * and is dropped from the value.
 *
 * The categories nothing points at go in the same pass. A category earns its
 * place by being what a rule files transactions under or by having a
 * transaction already filed under it; the rest were only ever the built-in
 * list the app shipped with, and they clutter every category control.
 */
return new class extends Migration
{
    private const PREFIX = 'custom_';

    public function up(): void
    {
        $this->dropPrefix();
        $this->deleteUnusedCategories();
    }

    /**
     * Strip the prefix from every category that carries it, repointing the
     * transactions and rules that name it in the same pass.
     */
    private function dropPrefix(): void
    {
        $prefixed = DB::table('categories')
            ->get()
            ->filter(fn (object $category): bool => Str::startsWith($category->value, self::PREFIX));

        foreach ($prefixed as $category) {
            $value = $this->availableValue(
                $category->user_id,
                Str::after($category->value, self::PREFIX),
            );

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
     * The unprefixed value, stepped past anything the unique index already
     * holds for that user, exactly as Category::createCustom does.
     */
    private function availableValue(int $userId, string $base): string
    {
        $value = $base;
        $suffix = 1;

        while (DB::table('categories')->where('user_id', $userId)->where('value', $value)->exists()) {
            $suffix++;
            $value = $base.'_'.$suffix;
        }

        return $value;
    }

    /**
     * Drop every category no rule and no transaction names.
     *
     * Scoped per user, so one user's empty category is not kept alive by
     * another user happening to file something under the same value.
     */
    private function deleteUnusedCategories(): void
    {
        foreach (DB::table('users')->pluck('id') as $userId) {
            $inUse = DB::table('category_rules')
                ->where('user_id', $userId)
                ->whereNotNull('set_category')
                ->distinct()
                ->pluck('set_category')
                ->merge(DB::table('transactions')
                    ->where('user_id', $userId)
                    ->whereNotNull('category')
                    ->distinct()
                    ->pluck('category'))
                ->unique()
                ->all();

            DB::table('categories')
                ->where('user_id', $userId)
                ->whereNotIn('value', $inUse)
                ->delete();
        }
    }

    /**
     * Nothing to reverse: neither the prefix nor which categories were
     * deleted is recorded anywhere once this has run.
     */
    public function down(): void {}
};
