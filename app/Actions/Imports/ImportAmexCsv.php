<?php

namespace App\Actions\Imports;

use App\Actions\Transactions\UpsertTransaction;
use App\Models\Account;
use App\Models\AmexSyncReport;
use App\Support\Imports\CsvColumnMap;
use App\Support\Transactions\TransactionData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Imports an American Express activity export.
 *
 * This exists because Monzo's API does not expose accounts connected inside
 * the Monzo app, so an AMEX card added there is unreachable programmatically.
 * Rows land in the same table, with the same columns, as everything synced
 * from Monzo.
 */
class ImportAmexCsv
{
    /**
     * American Express writes a charge as a positive number, the opposite of
     * Monzo, where money leaving the account is negative. Every amount is
     * therefore flipped on the way in so one sign convention holds across
     * every account.
     */
    private const SIGN = -1;

    public function __construct(private readonly UpsertTransaction $upsert) {}

    public function handle(Account $account, string $path, ?string $filename = null): AmexSyncReport
    {
        $batch = AmexSyncReport::query()->create([
            'user_id' => $account->user_id,
            'account_id' => $account->id,
            'filename' => $filename,
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        try {
            $this->import($account, $path, $batch);
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            throw $exception;
        }

        return $batch;
    }

    private function import(Account $account, string $path, AmexSyncReport $batch): void
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            $headers = fgetcsv($handle, escape: '');

            if (! is_array($headers)) {
                throw new RuntimeException('The file appears to be empty.');
            }

            $map = CsvColumnMap::resolve(array_map(strval(...), $headers));

            if (! $map->isUsable()) {
                throw new RuntimeException(sprintf(
                    'Could not find the %s column(s). The file has: %s.',
                    implode(', ', $map->missingRequired()),
                    implode(', ', array_map(strval(...), $headers)),
                ));
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $total = 0;

            /**
             * Counts how many times an identical date, amount and description
             * has already been seen in this file. Two genuinely separate but
             * identical purchases on one day must not collapse into one row.
             *
             * @var array<string, int> $occurrences
             */
            $occurrences = [];

            DB::transaction(function () use (
                $handle, $map, $account, $batch,
                &$created, &$updated, &$skipped, &$total, &$occurrences
            ): void {
                while (($row = fgetcsv($handle, escape: '')) !== false) {
                    if ($this->isBlank($row)) {
                        continue;
                    }

                    $total++;

                    $data = $this->toTransactionData($map, $account, array_map(
                        fn (mixed $value): ?string => is_string($value) ? $value : null,
                        $row,
                    ), $occurrences);

                    if ($data === null) {
                        $skipped++;

                        continue;
                    }

                    $result = $this->upsert->handle($account, $data, $batch);

                    $result->created ? $created++ : $updated++;
                }
            });

            $batch->forceFill([
                'status' => 'completed',
                'rows_total' => $total,
                'rows_imported' => $created,
                'rows_updated' => $updated,
                'rows_skipped' => $skipped,
                'finished_at' => Carbon::now(),
            ])->save();

            $account->forceFill(['last_synced_at' => Carbon::now()])->save();
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $occurrences
     */
    private function toTransactionData(
        CsvColumnMap $map,
        Account $account,
        array $row,
        array &$occurrences,
    ): ?TransactionData {
        $rawDate = $map->value($row, 'date');
        $rawAmount = $map->value($row, 'amount');
        $description = $map->value($row, 'description');

        if ($rawDate === null || $rawAmount === null || $description === null) {
            return null;
        }

        $bookedAt = $this->parseDate($rawDate);
        $amount = $this->parseAmount($rawAmount);

        if ($bookedAt === null || $amount === null) {
            return null;
        }

        $amountMinor = self::SIGN * $amount;
        $reference = $map->value($row, 'reference');
        $extendedDetails = $map->value($row, 'extendedDetails');

        return new TransactionData(
            externalId: $reference,
            dedupeHash: $this->dedupeHash($account, $reference, $bookedAt, $amountMinor, $description, $occurrences),
            bookedAt: $bookedAt,
            amountMinor: $amountMinor,
            currency: $account->currency,
            name: $this->merchantName($description),
            description: $description,
            /**
             * Deliberately left uncategorised. AMEX uses its own two-part
             * taxonomy, and translating it onto Monzo's categories guessed
             * wrong often enough to be worse than saying nothing.
             */
            category: null,
            type: 'card_payment',
            merchantName: $this->merchantName($description),
            notes: $extendedDetails,
            tags: TransactionData::parseTags($extendedDetails),
        );
    }

    /**
     * A stable reference from the export is the best identity available. When
     * the export omits one, identity falls back to the shape of the row plus
     * how many identical rows preceded it in the same file, so re-uploading an
     * overlapping statement updates rather than duplicates.
     *
     * @param  array<string, int>  $occurrences
     */
    private function dedupeHash(
        Account $account,
        ?string $reference,
        Carbon $bookedAt,
        int $amountMinor,
        string $description,
        array &$occurrences,
    ): string {
        if ($reference !== null) {
            return sha1('amex-ref:'.$reference);
        }

        $signature = implode('|', [
            $account->id,
            $bookedAt->toDateString(),
            $amountMinor,
            mb_strtolower($description),
        ]);

        $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;

        return sha1('amex-row:'.$signature.'|'.$occurrences[$signature]);
    }

    /**
     * American Express writes UK dates as day first, which would otherwise be
     * read as month first for the first twelve days of any month.
     */
    private function parseDate(string $value): ?Carbon
    {
        foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd M Y', 'd-M-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                /** This format does not fit; try the next one. */
                continue;
            }

            /**
             * Re-formatting and comparing rejects a loose match, so 13/03/2026
             * is not quietly accepted by a month-first format.
             */
            if ($date->format($format) === $value) {
                return $date->startOfDay();
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns pence.
     */
    private function parseAmount(string $value): ?int
    {
        $cleaned = (string) preg_replace('/[^0-9.,\-]/', '', $value);

        /** Strip thousands separators, keeping the decimal point. */
        if (str_contains($cleaned, '.') && str_contains($cleaned, ',')) {
            $cleaned = str_replace(',', '', $cleaned);
        } elseif (substr_count($cleaned, ',') === 1 && ! str_contains($cleaned, '.')) {
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (int) round((float) $cleaned * 100);
    }

    /**
     * Statement descriptions carry trailing location and reference noise, so
     * the first line is used as the merchant name.
     */
    private function merchantName(string $description): string
    {
        $firstLine = trim((string) strtok($description, "\n"));

        return $firstLine === '' ? $description : $firstLine;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
