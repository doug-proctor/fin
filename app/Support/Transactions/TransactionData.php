<?php

namespace App\Support\Transactions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One transaction, normalised away from whichever provider produced it. Both
 * the Monzo API and the AMEX CSV importer build these, so the two sources
 * reach the database through exactly one write path with exactly one shape.
 *
 * Only fields the transactions screen renders, or a filter needs, are carried
 * here. Anything else a provider sends is read and discarded.
 */
readonly class TransactionData
{
    /**
     * @param  array<int, string>|null  $tags
     */
    public function __construct(
        public ?string $externalId,
        public string $dedupeHash,
        public Carbon $bookedAt,
        public int $amountMinor,
        public string $currency,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $category = null,
        public ?string $type = null,
        public ?string $merchantName = null,
        public ?string $notes = null,
        public ?array $tags = null,
    ) {}

    /**
     * The bank-truth attributes, ready to be merged into a model. Identity and
     * local state are deliberately absent so an import can never write over
     * them.
     *
     * @return array<string, mixed>
     */
    public function bankAttributes(): array
    {
        return [
            'booked_at' => $this->bookedAt,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'type' => $this->type,
            'merchant_name' => $this->merchantName,
            'notes' => $this->notes,
            'tags' => $this->tags,
        ];
    }

    /**
     * A card payment the bank turned down. It never moved any money, so it is
     * not imported at all.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isDeclined(array $payload): bool
    {
        return self::stringOrNull($payload['decline_reason'] ?? null) !== null;
    }

    /**
     * Build from a Monzo API transaction object, with the merchant expanded.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromMonzo(array $payload): self
    {
        $merchant = is_array($payload['merchant'] ?? null) ? $payload['merchant'] : null;
        $counterparty = is_array($payload['counterparty'] ?? null) ? $payload['counterparty'] : null;

        $notes = self::stringOrNull($payload['notes'] ?? null);
        $externalId = (string) $payload['id'];

        $counterpartyName = $counterparty['preferred_name'] ?? $counterparty['name'] ?? null;

        /**
         * Stored exactly as Monzo sends it. A custom category arrives as an
         * opaque id, which is the only stable handle there is: Monzo will not
         * tell a third party client what the user named it.
         */
        $category = self::stringOrNull($payload['category'] ?? null);
        $description = self::stringOrNull($payload['description'] ?? null);

        return new self(
            externalId: $externalId,
            /**
             * Monzo hands out a stable transaction id, so the hash is just a
             * fixed-width restatement of it.
             */
            dedupeHash: sha1('monzo:'.$externalId),
            bookedAt: Carbon::parse((string) $payload['created']),
            amountMinor: (int) $payload['amount'],
            currency: (string) ($payload['currency'] ?? 'GBP'),
            name: self::stringOrNull($merchant['name'] ?? null)
                ?? self::stringOrNull(is_string($counterpartyName) ? $counterpartyName : null)
                ?? $description,
            description: $description,
            category: $category,
            type: self::schemeToType(self::stringOrNull($payload['scheme'] ?? null), $payload),
            merchantName: self::stringOrNull($merchant['name'] ?? null),
            notes: $notes,
            tags: self::parseTags($notes),
        );
    }

    /**
     * Monzo's notes field doubles as a tag store: any "#word" in a note is
     * lifted out as a tag. AMEX notes are read the same way.
     *
     * @return array<int, string>|null
     */
    public static function parseTags(?string $notes): ?array
    {
        if ($notes === null || $notes === '') {
            return null;
        }

        preg_match_all('/#([\p{L}\p{N}_-]+)/u', $notes, $matches);

        $tags = array_values(array_unique(array_map(
            fn (string $tag): string => mb_strtolower($tag),
            $matches[1],
        )));

        return $tags === [] ? null : $tags;
    }

    /**
     * Map Monzo's payment scheme onto a label that means the same thing on an
     * AMEX row.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function schemeToType(?string $scheme, array $payload): ?string
    {
        /** Monzo flags a top up separately rather than giving it a scheme. */
        if (($payload['is_load'] ?? false) === true) {
            return 'top_up';
        }

        if ($scheme === null) {
            return null;
        }

        return match ($scheme) {
            'gps_mastercard', 'mastercard' => 'card_payment',
            'payport_faster_payments', 'faster_payments' => 'faster_payment',
            'bacs' => 'direct_debit',
            'uk_retail_pot' => 'pot_transfer',
            'p2p_payment' => 'peer_payment',
            default => Str::snake($scheme),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
