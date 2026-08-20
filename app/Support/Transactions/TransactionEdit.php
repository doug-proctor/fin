<?php

namespace App\Support\Transactions;

use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * One transaction's hand edits, lifted off the row so they can outlive it.
 *
 * A wipe and re-import rebuilds every transaction from the bank, which throws
 * away everything the user typed. This is what is carried across: it holds the
 * edits and the identity to reattach them to, and nothing the import will
 * rebuild by itself.
 *
 * Identity is the account's provider plus `dedupe_hash`, never an id. Ids are
 * handed out again by the wipe; the hash is derived from what the provider
 * sent, so it survives one. The user is matched by email for the same reason.
 */
readonly class TransactionEdit
{
    /** Bumped when the file's shape changes, so an old file is refused rather than half read. */
    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $overridden  Bank field => the hand-edited value. The keys are the overrides map.
     */
    public function __construct(
        public string $email,
        public string $provider,
        public string $dedupeHash,
        public array $overridden,
        public ?string $category,
        public ?string $categorisedBy,
        public ?string $accountingDate,
        public bool $processed,
        /** Carried only so an entry that matches nothing can be named in the report. */
        public string $bookedAt,
        public int $amountMinor,
        public ?string $description,
    ) {}

    /**
     * Whether this row holds anything a re-import would not produce on its own.
     *
     * A row filed by a rule is not an edit: the rules run again on the way in
     * and file it again. Only what the user did themselves is worth carrying.
     */
    public static function isEdited(Transaction $transaction): bool
    {
        return $transaction->overrides !== null
            || $transaction->categorised_by === 'user'
            || $transaction->processed
            || $transaction->accounting_date !== null;
    }

    public static function fromTransaction(Transaction $transaction): self
    {
        /**
         * The map is narrowed to BANK_FIELDS on the way out. A row edited
         * before category stopped being a bank field still carries
         * `overrides['category']`, and ApplyCategoryRules reads that key to
         * decide whether it may file a row. Replaying it would block every
         * rule against that row for good, with nothing on screen saying why.
         * `categorised_by` is what protects a hand-chosen category now.
         */
        $fields = array_intersect(
            array_keys($transaction->overrides ?? []),
            Transaction::BANK_FIELDS,
        );

        $overridden = [];

        foreach ($fields as $field) {
            $overridden[$field] = $transaction->getAttribute($field);
        }

        return new self(
            email: $transaction->user->email,
            provider: $transaction->account->provider,
            dedupeHash: $transaction->dedupe_hash,
            overridden: $overridden,
            /** Only a category the user chose. A rule's is rebuilt by the rules. */
            category: $transaction->categorised_by === 'user' ? $transaction->category : null,
            categorisedBy: $transaction->categorised_by === 'user' ? 'user' : null,
            accountingDate: $transaction->accounting_date?->toDateString(),
            processed: $transaction->processed,
            bookedAt: $transaction->booked_at->toDateString(),
            amountMinor: $transaction->amount_minor,
            description: $transaction->description,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            email: (string) $row['email'],
            provider: (string) $row['provider'],
            dedupeHash: (string) $row['dedupe_hash'],
            overridden: is_array($row['overridden'] ?? null) ? $row['overridden'] : [],
            category: self::stringOrNull($row['category'] ?? null),
            categorisedBy: self::stringOrNull($row['categorised_by'] ?? null),
            accountingDate: self::stringOrNull($row['accounting_date'] ?? null),
            processed: (bool) ($row['processed'] ?? false),
            bookedAt: (string) ($row['booked_at'] ?? ''),
            amountMinor: (int) ($row['amount_minor'] ?? 0),
            description: self::stringOrNull($row['description'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'provider' => $this->provider,
            'dedupe_hash' => $this->dedupeHash,
            'booked_at' => $this->bookedAt,
            'amount_minor' => $this->amountMinor,
            'description' => $this->description,
            'overridden' => $this->overridden,
            'category' => $this->category,
            'categorised_by' => $this->categorisedBy,
            'accounting_date' => $this->accountingDate,
            'processed' => $this->processed,
        ];
    }

    /**
     * Write these edits back onto a freshly imported row.
     *
     * The overrides map is rebuilt from the keys rather than recomputed by
     * UpdateTransaction, so a field the user cleared to null comes back
     * protected rather than looking untouched.
     */
    public function applyTo(Transaction $transaction): void
    {
        if ($this->overridden !== []) {
            $transaction->fill($this->overridden);
            $transaction->markOverridden(array_keys($this->overridden));
        }

        if ($this->categorisedBy === 'user') {
            $transaction->category = $this->category;
            $transaction->categorised_by = 'user';
            $transaction->category_rule_id = null;
        }

        $transaction->accounting_date = $this->accountingDate === null
            ? null
            : Carbon::parse($this->accountingDate);

        /** Outside the fillable list on purpose, as in UpdateTransaction. */
        $transaction->processed = $this->processed;
    }

    /** A line naming this entry, for reporting one that matched no row. */
    public function label(): string
    {
        return sprintf(
            '%s  %s  %s  %s',
            $this->provider,
            $this->bookedAt,
            number_format($this->amountMinor / 100, 2),
            $this->description ?? '(no description)',
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
