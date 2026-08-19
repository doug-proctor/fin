<?php

namespace App\Models;

use Database\Factories\BankConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string|null $external_user_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $oauth_state
 * @property Carbon|null $authorised_at
 * @property Carbon|null $sca_confirmed_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Account> $accounts
 */
#[Fillable([
    'user_id',
    'provider',
    'external_user_id',
    'access_token',
    'refresh_token',
    'expires_at',
    'oauth_state',
    'authorised_at',
    'sca_confirmed_at',
    'last_synced_at',
    'last_sync_error',
    'revoked_at',
])]
#[Hidden(['access_token', 'refresh_token', 'oauth_state'])]
class BankConnection extends Model
{
    /** @use HasFactory<BankConnectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'authorised_at' => 'datetime',
            'sca_confirmed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * A connection is usable once the tokens are stored and the user has
     * approved the strong customer authentication push in their bank app.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->access_token !== null
            && $this->sca_confirmed_at !== null;
    }

    /**
     * Whether the access token has expired, or is close enough to expiring
     * that a sync would likely fail part way through.
     */
    public function needsRefresh(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->subMinutes(2)->isPast();
    }
}
