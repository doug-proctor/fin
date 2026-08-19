<?php

namespace App\Support\Imports;

/**
 * Resolves a CSV's headers onto the fields the importer understands.
 *
 * American Express lets you add, remove and reorder the columns in an export,
 * so matching on exact header text in a fixed order would break the first time
 * the user changed their view. Headers are therefore normalised and matched
 * against a set of aliases instead.
 */
class CsvColumnMap
{
    /**
     * Canonical field to the header aliases that mean it, already normalised.
     *
     * @var array<string, array<int, string>>
     */
    public const ALIASES = [
        'date' => ['date', 'transactiondate', 'dateprocessed', 'postdate', 'posteddate'],
        'description' => ['description', 'appearsonyourstatementas', 'merchant', 'payee', 'details'],
        'amount' => ['amount', 'amountgbp', 'value', 'transactionamount'],
        'extendedDetails' => ['extendeddetails', 'additionalinformation', 'notes'],
        'reference' => ['reference', 'referencenumber', 'transactionid', 'transactionreference'],
    ];

    /**
     * Fields without which a row cannot be turned into a transaction.
     *
     * @var array<int, string>
     */
    public const REQUIRED = ['date', 'description', 'amount'];

    /**
     * @param  array<string, int>  $positions  Canonical field to column index.
     * @param  array<int, string>  $headers  The headers as they appeared.
     */
    private function __construct(
        private readonly array $positions,
        public readonly array $headers,
    ) {}

    /**
     * @param  array<int, string>  $headers
     */
    public static function resolve(array $headers): self
    {
        $positions = [];

        foreach ($headers as $index => $header) {
            $normalised = self::normalise($header);

            foreach (self::ALIASES as $field => $aliases) {
                if (isset($positions[$field])) {
                    continue;
                }

                if (in_array($normalised, $aliases, true)) {
                    $positions[$field] = $index;

                    break;
                }
            }
        }

        return new self($positions, $headers);
    }

    /**
     * Which required fields could not be found, so the user can be told what
     * to re-export rather than shown an empty import.
     *
     * @return array<int, string>
     */
    public function missingRequired(): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $field): bool => ! isset($this->positions[$field]),
        ));
    }

    public function isUsable(): bool
    {
        return $this->missingRequired() === [];
    }

    /**
     * Read one field out of a row.
     *
     * @param  array<int, string|null>  $row
     */
    public function value(array $row, string $field): ?string
    {
        $index = $this->positions[$field] ?? null;

        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function has(string $field): bool
    {
        return isset($this->positions[$field]);
    }

    /**
     * Strip everything that varies between exports: case, spaces, punctuation
     * and any byte order mark left on the first header.
     */
    private static function normalise(string $header): string
    {
        $header = str_replace("\u{FEFF}", '', $header);

        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($header)));
    }
}
