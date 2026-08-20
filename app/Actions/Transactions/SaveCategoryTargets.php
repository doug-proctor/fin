<?php

namespace App\Actions\Transactions;

use App\Models\CategoryTarget;
use Illuminate\Support\Facades\DB;

/**
 * Writes one month's category targets.
 *
 * A stored row is the whole of "the user set a target for this category in
 * this month". So a cleared field deletes its row rather than storing a zero,
 * which is what makes a target possible to un-set; a stored 0 keeps its own
 * meaning of spending nothing. A category missing from the payload is left
 * exactly as it was, so a partial save can never wipe anything.
 */
class SaveCategoryTargets
{
    /**
     * @param  string  $month  'YYYY-MM'.
     * @param  array<string, int|null>  $amounts  Category value to pence; null clears.
     */
    public function handle(int $userId, string $month, array $amounts): void
    {
        DB::transaction(function () use ($userId, $month, $amounts): void {
            foreach ($amounts as $category => $minor) {
                $keys = [
                    'user_id' => $userId,
                    'month' => $month,
                    'category' => $category,
                ];

                if ($minor === null) {
                    CategoryTarget::query()->where($keys)->delete();

                    continue;
                }

                CategoryTarget::query()->updateOrCreate($keys, ['amount_minor' => $minor]);
            }
        });
    }
}
