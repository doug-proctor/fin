<?php

use App\Actions\Imports\ImportAmexCsv;
use App\Models\Account;
use App\Models\AmexSyncReport;
use App\Models\User;
use App\Support\Transactions\TransactionFilters;
use App\Support\Transactions\TransactionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build a transactions query straight from query-string style input, so a test
 * can exercise the same parsing the controller uses.
 *
 * @param  array<string, mixed>  $input
 */
function query(User $user, array $input = []): TransactionQuery
{
    return new TransactionQuery($user->id, TransactionFilters::fromArray($input));
}

/**
 * Run one of the checked in American Express exports through the importer.
 */
function importFixture(Account $account, string $fixture): AmexSyncReport
{
    return app(ImportAmexCsv::class)->handle(
        $account,
        base_path("tests/Fixtures/{$fixture}"),
        $fixture,
    );
}
