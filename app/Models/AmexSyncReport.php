<?php

namespace App\Models;

use Database\Factories\AmexSyncReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property string|null $filename
 * @property string $status
 * @property int $rows_total
 * @property int $rows_imported
 * @property int $rows_updated
 * @property int $rows_skipped
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Account|null $account
 * @property-read Collection<int, Transaction> $transactions
 */
#[Fillable([
    'user_id',
    'account_id',
    'filename',
    'status',
    'rows_total',
    'rows_imported',
    'rows_updated',
    'rows_skipped',
    'error',
    'started_at',
    'finished_at',
])]
class AmexSyncReport extends Model
{
    /** @use HasFactory<AmexSyncReportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
