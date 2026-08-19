<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;

readonly class UpsertResult
{
    public function __construct(
        public Transaction $transaction,
        public bool $created,
    ) {}

    public function updated(): bool
    {
        return ! $this->created;
    }
}
