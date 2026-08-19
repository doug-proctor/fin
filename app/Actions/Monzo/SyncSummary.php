<?php

namespace App\Actions\Monzo;

use Illuminate\Support\Carbon;

readonly class SyncSummary
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        /**
         * The span of the transactions this run actually brought in, which is
         * empty on a run that found nothing new. Refreshed rows are excluded:
         * the report is about what arrived, not what was re-read.
         */
        public ?Carbon $oldestImported = null,
        public ?Carbon $newestImported = null,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            oldestImported: self::earliest($this->oldestImported, $other->oldestImported),
            newestImported: self::latest($this->newestImported, $other->newestImported),
        );
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }

    private static function earliest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a->min($b),
        };
    }

    private static function latest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a->max($b),
        };
    }
}
