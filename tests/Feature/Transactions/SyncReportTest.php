<?php

use App\Models\AmexSyncReport;
use App\Models\MonzoSyncReport;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('the sync reports page requires a signed in user', function () {
    $this->get(route('sync-reports.index'))->assertRedirect(route('login'));
});

test('the page lists this user reports newest first', function () {
    MonzoSyncReport::factory()->for($this->user)->create([
        'imported' => 4,
        'started_at' => now()->subDay(),
    ]);
    MonzoSyncReport::factory()->for($this->user)->create([
        'imported' => 11,
        'started_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('reports', 2)
            ->where('reports.0.imported', 11)
            ->where('reports.1.imported', 4));
});

test('a failed report carries its error so the page can show it', function () {
    MonzoSyncReport::factory()->for($this->user)->failed()->create();

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.0.status', 'failed')
            ->where('reports.0.error', 'Monzo returned 403: reauthentication required.'));
});

test('one user cannot see another user sync reports', function () {
    MonzoSyncReport::factory()->for(User::factory())->create();
    AmexSyncReport::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page->has('reports', 0));
});

test('the page reports a gap so it stays visible after later syncs succeed', function () {
    MonzoSyncReport::factory()->for($this->user)->create([
        'gap_from' => now()->subDays(200),
        'gap_to' => now()->subDays(89),
        'started_at' => now()->subDays(89),
    ]);

    /** A clean run afterwards must not hide it; the transactions are still gone. */
    MonzoSyncReport::factory()->for($this->user)->create(['started_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.0.gapFrom', null)
            ->where('reports.1.gapFrom', fn ($value) => $value !== null));
});

test('monzo syncs and amex uploads share one list ordered by when they ran', function () {
    MonzoSyncReport::factory()->for($this->user)->create([
        'started_at' => now()->subDays(2),
    ]);
    AmexSyncReport::factory()->for($this->user)->create([
        'started_at' => now()->subDay(),
    ]);
    MonzoSyncReport::factory()->for($this->user)->create([
        'started_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->has('reports', 3)
            ->where('reports.0.provider', 'monzo')
            ->where('reports.1.provider', 'amex')
            ->where('reports.2.provider', 'monzo'));
});

test('an amex upload reports the rows it added, updated and skipped', function () {
    AmexSyncReport::factory()->for($this->user)->create([
        'filename' => 'activity.csv',
        'rows_imported' => 12,
        'rows_updated' => 3,
        'rows_skipped' => 1,
    ]);

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.0.provider', 'amex')
            ->where('reports.0.filename', 'activity.csv')
            ->where('reports.0.imported', 12)
            ->where('reports.0.updated', 3)
            ->where('reports.0.skipped', 1)
            /** Only Monzo has a window a run can fall outside of. */
            ->where('reports.0.gapFrom', null));
});

/**
 * An AMEX report stores row counts, not dates, so the span it covers has to
 * come back off the transactions it wrote.
 */
test('an amex upload shows the span of the transactions it wrote', function () {
    $report = AmexSyncReport::factory()->for($this->user)->create();

    Transaction::factory()->for($this->user)->create([
        'amex_sync_report_id' => $report->id,
        'booked_at' => now()->subDays(10)->startOfDay(),
    ]);
    Transaction::factory()->for($this->user)->create([
        'amex_sync_report_id' => $report->id,
        'booked_at' => now()->subDays(2)->startOfDay(),
    ]);

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.0.oldestBookedAt', fn ($value) => str_starts_with(
                (string) $value,
                now()->subDays(10)->toDateString(),
            ))
            ->where('reports.0.newestBookedAt', fn ($value) => str_starts_with(
                (string) $value,
                now()->subDays(2)->toDateString(),
            )));
});

test('an amex upload that brought nothing in has no span', function () {
    AmexSyncReport::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('sync-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.0.oldestBookedAt', null)
            ->where('reports.0.newestBookedAt', null));
});
