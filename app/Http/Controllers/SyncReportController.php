<?php

namespace App\Http\Controllers;

use App\Models\AmexSyncReport;
use App\Models\MonzoSyncReport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SyncReportController extends Controller
{
    /**
     * How many runs the page shows, across both providers combined.
     */
    private const LIMIT = 100;

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        /**
         * Both halves are cast to base collections first. An Eloquent
         * collection that maps to arrays only demotes itself when it has rows
         * to inspect, so an empty one stays Eloquent and merge() calls
         * getKey() on the arrays coming the other way.
         */
        $reports = $this->monzoReports($userId)
            ->merge($this->amexReports($userId))
            ->sortByDesc('startedAt')
            ->take(self::LIMIT)
            ->values()
            ->all();

        return Inertia::render('transactions/sync-reports', [
            'reports' => $reports,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function monzoReports(int $userId): Collection
    {
        return MonzoSyncReport::query()
            ->where('user_id', $userId)
            ->latest('started_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (MonzoSyncReport $report): array => [
                'id' => 'monzo-'.$report->id,
                'provider' => 'monzo',
                'status' => $report->status,
                'imported' => $report->imported,
                'updated' => null,
                'skipped' => null,
                'filename' => null,
                'oldestBookedAt' => $report->oldest_booked_at?->toIso8601String(),
                'newestBookedAt' => $report->newest_booked_at?->toIso8601String(),
                'error' => $report->error,
                'gapFrom' => $report->gap_from?->toIso8601String(),
                'gapTo' => $report->gap_to?->toIso8601String(),
                'startedAt' => $report->started_at->toIso8601String(),
            ])
            ->toBase();
    }

    /**
     * An AMEX upload records how many rows it read rather than the span they
     * cover, so the span is read back off the transactions it wrote.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function amexReports(int $userId): Collection
    {
        $reports = AmexSyncReport::query()
            ->where('user_id', $userId)
            ->latest('started_at')
            ->limit(self::LIMIT)
            ->get();

        $spans = $this->bookedSpans($reports->pluck('id')->all());

        return $reports->map(fn (AmexSyncReport $report): array => [
            'id' => 'amex-'.$report->id,
            'provider' => 'amex',
            'status' => $report->status,
            'imported' => $report->rows_imported,
            'updated' => $report->rows_updated,
            'skipped' => $report->rows_skipped,
            'filename' => $report->filename,
            'oldestBookedAt' => $spans[$report->id]['oldest'] ?? null,
            'newestBookedAt' => $spans[$report->id]['newest'] ?? null,
            'error' => $report->error,
            /** Only Monzo has a window that can be missed. */
            'gapFrom' => null,
            'gapTo' => null,
            'startedAt' => ($report->started_at ?? $report->created_at)?->toIso8601String(),
        ])->toBase();
    }

    /**
     * One query for every span rather than one per report.
     *
     * @param  array<int, int>  $reportIds
     * @return array<int, array{oldest: string, newest: string}>
     */
    private function bookedSpans(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }

        return Transaction::query()
            ->whereIn('amex_sync_report_id', $reportIds)
            ->groupBy('amex_sync_report_id')
            ->get([
                'amex_sync_report_id',
                DB::raw('MIN(booked_at) as oldest'),
                DB::raw('MAX(booked_at) as newest'),
            ])
            ->mapWithKeys(fn (Transaction $row): array => [
                (int) $row->getAttribute('amex_sync_report_id') => [
                    'oldest' => (string) $row->getAttribute('oldest'),
                    'newest' => (string) $row->getAttribute('newest'),
                ],
            ])
            ->all();
    }
}
