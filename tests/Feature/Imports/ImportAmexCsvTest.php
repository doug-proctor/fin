<?php

use App\Actions\Imports\ImportAmexCsv;
use App\Models\Account;
use App\Models\AmexSyncReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->amex = Account::factory()->amex()->for($this->user)->create();
});

test('an amex export is imported into the same table as monzo rows', function () {
    $batch = importFixture($this->amex, 'amex-activity.csv');

    expect($batch->status)->toBe('completed');
    expect($batch->rows_total)->toBe(4);
    expect($batch->rows_imported)->toBe(4);
    expect(Transaction::count())->toBe(4);
});

test('every imported row starts unprocessed', function () {
    importFixture($this->amex, 'amex-activity.csv');

    expect(Transaction::where('processed', false)->count())->toBe(4);
});

test('a charge becomes money out and a payment becomes money in', function () {
    importFixture($this->amex, 'amex-activity.csv');

    $charge = Transaction::where('description', 'TESCO STORES 3297')->first();
    $payment = Transaction::where('description', 'PAYMENT RECEIVED - THANK YOU')->first();

    /**
     * American Express writes a charge as positive; Monzo writes money leaving
     * the account as negative. The importer flips so one convention holds.
     */
    expect($charge->amount_minor)->toBe(-4520);
    expect($charge->money_out_minor)->toBe(4520);
    expect($charge->money_in_minor)->toBe(0);

    expect($payment->amount_minor)->toBe(25000);
    expect($payment->money_in_minor)->toBe(25000);
});

test('uk dates are read day first', function () {
    importFixture($this->amex, 'amex-activity.csv');

    $transaction = Transaction::where('description', 'TESCO STORES 3297')->first();

    expect($transaction->booked_at->toDateString())->toBe('2026-03-05');
});

test('hash tags in the extended details become tags', function () {
    importFixture($this->amex, 'amex-activity.csv');

    expect(Transaction::where('description', 'TRAINLINE')->first()->tags)->toBe(['work']);
});

test('re-uploading an overlapping statement updates rather than duplicates', function () {
    importFixture($this->amex, 'amex-activity.csv');
    $second = importFixture($this->amex, 'amex-activity.csv');

    expect(Transaction::count())->toBe(4);
    expect($second->rows_imported)->toBe(0);
    expect($second->rows_updated)->toBe(4);
});

test('two identical purchases on the same day both survive', function () {
    importFixture($this->amex, 'amex-no-reference.csv');

    /**
     * Without a reference the rows are otherwise indistinguishable, so the
     * occurrence within the file is part of their identity.
     */
    expect(Transaction::where('description', 'CAFFE NERO')->count())->toBe(2);
    expect(Transaction::count())->toBe(3);
});

test('a file without references still dedupes on re-upload', function () {
    importFixture($this->amex, 'amex-no-reference.csv');
    $second = importFixture($this->amex, 'amex-no-reference.csv');

    expect(Transaction::count())->toBe(3);
    expect($second->rows_updated)->toBe(3);
});

test('columns may be renamed and reordered', function () {
    importFixture($this->amex, 'amex-reordered.csv');

    $transaction = Transaction::first();

    expect($transaction->description)->toBe('SAINSBURYS');
    expect($transaction->booked_at->toDateString())->toBe('2026-03-12');
});

test('thousands separators are parsed', function () {
    importFixture($this->amex, 'amex-reordered.csv');

    expect(Transaction::first()->amount_minor)->toBe(-123456);
});

test('a file missing a required column explains what is missing', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "Date,Something Else\n05/03/2026,x\n");

    try {
        app(ImportAmexCsv::class)->handle($this->amex, $path, 'broken.csv');

        $this->fail('The import should have refused a file it cannot read.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('description');
        expect($exception->getMessage())->toContain('amount');
        /** The headers it did find are echoed back so the fix is obvious. */
        expect($exception->getMessage())->toContain('Something Else');
    }

    expect(Transaction::count())->toBe(0);

    unlink($path);
});

test('a failed import is recorded rather than lost', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "Date,Nope\n05/03/2026,x\n");

    try {
        app(ImportAmexCsv::class)->handle($this->amex, $path, 'broken.csv');
    } catch (RuntimeException) {
        // expected
    }

    $batch = AmexSyncReport::latest()->first();

    expect($batch->status)->toBe('failed');
    expect($batch->error)->not->toBeNull();

    unlink($path);
});

test('an amex import is attributed to its batch', function () {
    $batch = importFixture($this->amex, 'amex-activity.csv');

    expect(Transaction::whereNull('amex_sync_report_id')->count())->toBe(0);
    expect($batch->transactions()->count())->toBe(4);
});

test('a day past the twelfth is still read day first', function () {
    importFixture($this->amex, 'amex-awkward-dates.csv');

    /**
     * A month-first reader would reject or misread 13/03, so this pins the
     * order rather than trusting the first format that happens to parse.
     */
    expect(Transaction::where('description', 'THIRTEENTH')->first()->booked_at->toDateString())
        ->toBe('2026-03-13');
    expect(Transaction::where('description', 'FIFTH MARCH')->first()->booked_at->toDateString())
        ->toBe('2026-03-05');
});

test('an iso date is accepted alongside the uk format', function () {
    importFixture($this->amex, 'amex-awkward-dates.csv');

    expect(Transaction::where('description', 'ISO FORMAT')->first()->booked_at->toDateString())
        ->toBe('2026-03-14');
});

test('an unreadable date skips the row instead of failing the import', function () {
    $batch = importFixture($this->amex, 'amex-awkward-dates.csv');

    expect($batch->status)->toBe('completed');
    expect($batch->rows_total)->toBe(4);
    expect($batch->rows_imported)->toBe(3);
    expect($batch->rows_skipped)->toBe(1);
    expect(Transaction::where('description', 'UNPARSEABLE')->exists())->toBeFalse();
});

test('uploading creates the amex account on first use and reuses it after', function () {
    $upload = fn (): UploadedFile => new UploadedFile(
        base_path('tests/Fixtures/amex-activity.csv'),
        'amex-activity.csv',
        'text/csv',
        null,
        true,
    );

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), ['file' => $upload()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    /** The card is not asked for; there is only ever one. */
    $accounts = Account::where('user_id', $this->user->id)->where('provider', 'amex')->get();

    expect($accounts)->toHaveCount(1);
    expect($accounts->first()->name)->toBe('Amex');

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), ['file' => $upload()]);

    expect(Account::where('user_id', $this->user->id)->where('provider', 'amex')->count())->toBe(1);
});

test('a successful upload reports its counts back to the dialog', function () {
    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), [
            'file' => new UploadedFile(
                base_path('tests/Fixtures/amex-activity.csv'),
                'amex-activity.csv',
                'text/csv',
                null,
                true,
            ),
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHas('importResult', fn (array $result): bool => $result['status'] === 'success'
            && $result['filename'] === 'amex-activity.csv'
            && $result['imported'] === 4
            && $result['updated'] === 0
            && $result['total'] === 4);
});

test('a re-upload reports rows as updated rather than new', function () {
    $upload = fn (): UploadedFile => new UploadedFile(
        base_path('tests/Fixtures/amex-activity.csv'),
        'amex-activity.csv',
        'text/csv',
        null,
        true,
    );

    $this->actingAs($this->user)->post(route('transactions.import.store'), ['file' => $upload()]);

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), ['file' => $upload()])
        ->assertSessionHas('importResult', fn (array $result): bool => $result['imported'] === 0
            && $result['updated'] === 4);
});

test('a file the importer cannot read reports why, rather than failing silently', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "Date,Something Else\n05/03/2026,x\n");

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), [
            'file' => new UploadedFile($path, 'broken.csv', 'text/csv', null, true),
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHas('importResult', fn (array $result): bool => $result['status'] === 'error'
            && str_contains($result['message'], 'description')
            && str_contains($result['message'], 'amount'));

    expect(Transaction::count())->toBe(0);
});

test('a file that is not a csv is rejected before any import runs', function () {
    $path = tempnam(sys_get_temp_dir(), 'img');
    /** Real PNG bytes, so the mime check sees what a browser would send. */
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), [
            'file' => new UploadedFile($path, 'statement.png', 'image/png', null, true),
        ])
        ->assertSessionHasErrors('file');

    expect(Transaction::count())->toBe(0);

    unlink($path);
});

test('the upload requires a file', function () {
    $this->actingAs($this->user)
        ->post(route('transactions.import.store'))
        ->assertSessionHasErrors('file');
});

test('imports are scoped to the signed in user', function () {
    $other = User::factory()->create();
    Account::factory()->amex()->for($other)->create();

    $this->actingAs($this->user)
        ->post(route('transactions.import.store'), [
            'file' => new UploadedFile(
                base_path('tests/Fixtures/amex-activity.csv'),
                'amex-activity.csv',
                'text/csv',
                null,
                true,
            ),
        ]);

    expect($this->user->transactions()->count())->toBe(4);
    expect($other->transactions()->count())->toBe(0);
});

test('amex rows arrive uncategorised rather than guessed at', function () {
    importFixture($this->amex, 'amex-activity.csv');

    /** No source is trusted to file a row; only a rule or the user can. */
    expect(Transaction::whereNotNull('category')->count())->toBe(0);
    expect(Transaction::count())->toBe(4);

    Transaction::all()->each(function (Transaction $transaction) {
        expect($transaction->category)->toBeNull();
        expect($transaction->categorised_by)->toBeNull();
    });
});
