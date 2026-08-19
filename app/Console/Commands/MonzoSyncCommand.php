<?php

namespace App\Console\Commands;

use App\Actions\Monzo\SyncMonzoConnection;
use App\Exceptions\Monzo\ScaRequiredException;
use App\Models\BankConnection;
use Illuminate\Console\Command;
use Throwable;

class MonzoSyncCommand extends Command
{
    protected $signature = 'monzo:sync
                            {--user= : Only sync this user id}';

    protected $description = 'Pull transactions from Monzo into the local ledger';

    public function handle(SyncMonzoConnection $sync): int
    {
        $connections = BankConnection::query()
            ->where('provider', 'monzo')
            ->whereNull('revoked_at')
            ->whereNotNull('sca_confirmed_at')
            ->when($this->option('user'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->get();

        if ($connections->isEmpty()) {
            $this->components->warn('No connected Monzo accounts to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($connections as $connection) {
            try {
                $summary = $sync->handle($connection);

                $this->components->info(sprintf(
                    'User %d: %d new, %d updated.',
                    $connection->user_id,
                    $summary->created,
                    $summary->updated,
                ));
            } catch (ScaRequiredException) {
                $failed++;
                $this->components->error(sprintf(
                    'User %d: Monzo is waiting for approval in the app.',
                    $connection->user_id,
                ));
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error(sprintf(
                    'User %d: %s',
                    $connection->user_id,
                    $exception->getMessage(),
                ));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
