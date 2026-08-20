<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\TransactionEdit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts the edits from `transactions:export-edits` back onto freshly imported
 * rows.
 *
 * Run this last, after every sync and CSV import has finished. The rules run
 * on the way in and file rows as they arrive; these edits outrank them, so
 * they have to be written on top rather than underneath.
 */
class ImportTransactionEditsCommand extends Command
{
    protected $signature = 'transactions:import-edits
                            {--path= : The file to read}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Restore hand edits from the json file written by transactions:export-edits';

    /**
     * Built once per run rather than memoised, so a command instance the
     * container hands out twice cannot serve ids from the previous run.
     *
     * @var array<string, int>
     */
    private array $userIds = [];

    /** @var array<string, int> */
    private array $accountIds = [];

    public function handle(): int
    {
        try {
            $edits = $this->read();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($edits === []) {
            $this->components->warn('The file holds no edits.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->userIds = User::query()->pluck('id', 'email')->all();

        $this->accountIds = Account::query()
            ->get(['id', 'user_id', 'provider'])
            ->mapWithKeys(fn (Account $account): array => [
                $account->user_id.':'.$account->provider => $account->id,
            ])
            ->all();

        $applied = 0;

        /** @var array<int, TransactionEdit> $unmatched */
        $unmatched = [];

        DB::transaction(function () use ($edits, $dryRun, &$applied, &$unmatched): void {
            foreach ($edits as $edit) {
                $transaction = $this->match($edit);

                if ($transaction === null) {
                    $unmatched[] = $edit;

                    continue;
                }

                $edit->applyTo($transaction);

                if (! $transaction->isDirty()) {
                    continue;
                }

                if (! $dryRun) {
                    $transaction->save();
                }

                $applied++;
            }
        });

        $this->report($applied, $unmatched, count($edits), $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array<int, TransactionEdit>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('No file at %s. Run transactions:export-edits first.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read %s.', $path));
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_array($decoded['edits'] ?? null)) {
            throw new RuntimeException(sprintf('%s is not a transaction edits file.', $path));
        }

        /**
         * Refused rather than half read. A file whose shape has moved on would
         * otherwise restore some fields and silently drop the rest.
         */
        if (($decoded['version'] ?? null) !== TransactionEdit::VERSION) {
            throw new RuntimeException(sprintf(
                'That file is version %s; this command reads version %d.',
                json_encode($decoded['version'] ?? null),
                TransactionEdit::VERSION,
            ));
        }

        return array_map(
            fn (array $row): TransactionEdit => TransactionEdit::fromArray($row),
            array_values(array_filter($decoded['edits'], is_array(...))),
        );
    }

    /**
     * The row these edits belong to, or null if the re-import never produced
     * it. Matched on email, provider and dedupe hash, none of which a wipe
     * reassigns.
     */
    private function match(TransactionEdit $edit): ?Transaction
    {
        $userId = $this->userIds[$edit->email] ?? null;

        if ($userId === null) {
            return null;
        }

        $accountId = $this->accountIds[$userId.':'.$edit->provider] ?? null;

        if ($accountId === null) {
            return null;
        }

        return Transaction::query()
            ->where('account_id', $accountId)
            ->where('dedupe_hash', $edit->dedupeHash)
            ->first();
    }

    /**
     * @param  array<int, TransactionEdit>  $unmatched
     */
    private function report(int $applied, array $unmatched, int $total, bool $dryRun): void
    {
        $unchanged = $total - $applied - count($unmatched);

        $this->components->info(sprintf(
            '%s %s of %s %s.',
            $dryRun ? 'Would restore' : 'Restored',
            number_format($applied),
            number_format($total),
            $total === 1 ? 'edit' : 'edits',
        ));

        if ($unchanged > 0) {
            $this->components->info(sprintf(
                '%s already matched the stored row and needed no write.',
                number_format($unchanged),
            ));
        }

        if ($unmatched === []) {
            return;
        }

        /**
         * Named rather than counted. An entry that matches nothing means the
         * re-import did not produce that transaction — a Monzo row outside
         * the 90 day window, or an AMEX statement not uploaded yet — and the
         * only way to act on it is to see which one.
         */
        $this->components->warn(sprintf(
            '%s %s matched no transaction:',
            number_format(count($unmatched)),
            count($unmatched) === 1 ? 'edit' : 'edits',
        ));

        foreach ($unmatched as $edit) {
            $this->components->twoColumnDetail($edit->label());
        }
    }

    private function path(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== ''
            ? $path
            : storage_path('app/transaction-edits.json');
    }
}
