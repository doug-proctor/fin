<?php

namespace App\Jobs;

use App\Actions\Monzo\SyncMonzoConnection;
use App\Models\BankConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a routine sync in the background. The very first backfill after
 * connecting is deliberately not queued, because it has to finish inside
 * Monzo's five minute full-history window and a queue worker might not be
 * running.
 */
class SyncMonzoConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Only one sync per connection may be queued or running at a time.
     */
    public int $uniqueFor = 900;

    /**
     * Named bankConnection rather than connection, because Queueable already
     * uses that property for the queue connection name.
     */
    public function __construct(public BankConnection $bankConnection) {}

    public function uniqueId(): string
    {
        return (string) $this->bankConnection->id;
    }

    public function handle(SyncMonzoConnection $sync): void
    {
        $sync->handle($this->bankConnection);
    }
}
