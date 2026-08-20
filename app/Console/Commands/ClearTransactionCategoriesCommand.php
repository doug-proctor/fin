<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Unfiles every transaction, putting the ledger back to the state it is in
 * before any rule has run. The user's category list is not touched — this
 * empties the `category` column, it does not delete any category.
 */
class ClearTransactionCategoriesCommand extends Command
{
    protected $signature = 'transactions:clear-categories
                            {--user= : Only clear this user id}
                            {--force : Clear without asking}';

    protected $description = 'Clear the category from every transaction, leaving the category list itself alone';

    public function handle(): int
    {
        $filed = $this->transactions()->whereNotNull('category')->count();

        if ($filed === 0) {
            $this->components->warn('No transactions have a category.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Clear the category from %s %s? This cannot be undone.',
            number_format($filed),
            $filed === 1 ? 'transaction' : 'transactions',
        ))) {
            $this->components->warn('Command cancelled.');

            return self::FAILURE;
        }

        $cleared = $this->clear();

        $this->components->info(sprintf(
            'Cleared the category from %s %s.',
            number_format($cleared),
            $cleared === 1 ? 'transaction' : 'transactions',
        ));

        return self::SUCCESS;
    }

    /**
     * Every row is walked rather than only those holding a category, because a
     * row can carry a categorised_by, a rule id or a category override with no
     * category behind it. isDirty() is what decides whether one was changed, so
     * the reported figure is the number of rows actually written.
     */
    private function clear(): int
    {
        $cleared = 0;

        $this->transactions()->chunkById(500, function (Collection $transactions) use (&$cleared): void {
            foreach ($transactions as $transaction) {
                $this->unfile($transaction);

                if ($transaction->isDirty()) {
                    $transaction->save();
                    $cleared++;
                }
            }
        });

        return $cleared;
    }

    private function unfile(Transaction $transaction): void
    {
        $transaction->category = null;
        $transaction->categorised_by = null;
        $transaction->category_rule_id = null;

        /**
         * The category override goes with the category it was protecting. Left
         * behind it would be permanent: the overrides map means "the user owns
         * this field", so every later rule run would skip the row and nothing
         * on screen would say why. Every other override is the user's and stays.
         */
        $overrides = $transaction->overrides ?? [];

        unset($overrides['category']);

        $transaction->overrides = $overrides === [] ? null : $overrides;
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(): Builder
    {
        return Transaction::query()
            ->when($this->option('user'), fn (Builder $query, string $userId) => $query->where('user_id', $userId));
    }
}
