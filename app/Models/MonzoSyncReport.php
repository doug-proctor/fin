<?php

namespace App\Models;

use Database\Factories\MonzoSyncReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one Monzo sync did.
 *
 * A run that fails is recorded too. Monzo forces a reconnect every 90 days,
 * and without a failed row on the list a broken connection is indistinguishable
 * from a quiet week.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property int $imported
 * @property Carbon|null $oldest_booked_at
 * @property Carbon|null $newest_booked_at
 * @property Carbon|null $gap_from
 * @property Carbon|null $gap_to
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'status',
    'imported',
    'oldest_booked_at',
    'newest_booked_at',
    'gap_from',
    'gap_to',
    'error',
    'started_at',
    'finished_at',
])]
class MonzoSyncReport extends Model
{
    /** @use HasFactory<MonzoSyncReportFactory> */
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported' => 'integer',
            'oldest_booked_at' => 'datetime',
            'newest_booked_at' => 'datetime',
            'gap_from' => 'datetime',
            'gap_to' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Transactions dated between the end of the previous run and the oldest
     * point this one could reach were never offered to either, and Monzo will
     * not serve them now.
     */
    public function hasGap(): bool
    {
        return $this->gap_from !== null && $this->gap_to !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
