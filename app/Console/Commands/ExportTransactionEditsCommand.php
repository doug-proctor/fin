<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Support\Transactions\TransactionEdit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Writes every hand edit to a file, so a wipe and re-import can put them back.
 *
 * A file rather than a table, because a table goes with the wipe. Run this
 * before `migrate:fresh`, and `transactions:import-edits` after the re-import
 * has finished.
 */
class ExportTransactionEditsCommand extends Command
{
    protected $signature = 'transactions:export-edits
                            {--path= : Where to write the file}
                            {--user= : Only export this user id}';

    protected $description = 'Write every hand edit to a json file so it survives a database wipe';

    public function handle(): int
    {
        $edits = $this->collect();

        if ($edits === []) {
            $this->components->warn('No transaction carries a hand edit. Nothing was written.');

            return self::SUCCESS;
        }

        $path = $this->path();

        $written = file_put_contents($path, json_encode([
            'version' => TransactionEdit::VERSION,
            'exported_at' => Carbon::now()->toIso8601String(),
            'count' => count($edits),
            'edits' => array_map(fn (TransactionEdit $edit): array => $edit->toArray(), $edits),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        if ($written === false) {
            $this->components->error(sprintf('Could not write to %s.', $path));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Wrote %s %s to %s.',
            number_format(count($edits)),
            count($edits) === 1 ? 'edit' : 'edits',
            $path,
        ));

        $this->summarise($edits);

        return self::SUCCESS;
    }

    /**
     * @return array<int, TransactionEdit>
     */
    private function collect(): array
    {
        $edits = [];

        /**
         * Every row is walked rather than only those holding an overrides
         * map, because a row can be an edit by way of its category, its
         * accounting date or its processed flag alone. isEdited() is the one
         * place that decides.
         */
        $this->transactions()->chunkById(500, function (Collection $transactions) use (&$edits): void {
            foreach ($transactions as $transaction) {
                if (TransactionEdit::isEdited($transaction)) {
                    $edits[] = TransactionEdit::fromTransaction($transaction);
                }
            }
        });

        return $edits;
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(): Builder
    {
        return Transaction::query()
            ->with(['user:id,email', 'account:id,provider'])
            ->when($this->option('user'), fn (Builder $query, string $userId) => $query->where('user_id', $userId));
    }

    private function path(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== ''
            ? $path
            : storage_path('app/transaction-edits.json');
    }

    /**
     * @param  array<int, TransactionEdit>  $edits
     */
    private function summarise(array $edits): void
    {
        $rows = [
            ['Corrected fields', count(array_filter($edits, fn (TransactionEdit $edit): bool => $edit->overridden !== []))],
            ['Categorised by hand', count(array_filter($edits, fn (TransactionEdit $edit): bool => $edit->categorisedBy === 'user'))],
            ['Counting in another month', count(array_filter($edits, fn (TransactionEdit $edit): bool => $edit->accountingDate !== null))],
            ['Marked processed', count(array_filter($edits, fn (TransactionEdit $edit): bool => $edit->processed))],
        ];

        $this->table(['What was kept', 'Rows'], array_map(
            fn (array $row): array => [$row[0], number_format($row[1])],
            $rows,
        ));
    }
}
