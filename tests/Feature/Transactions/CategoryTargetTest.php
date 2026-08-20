<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CategoryTarget;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->month = now()->format('Y-m');
});

/**
 * Save one month's targets the way the dialog does: every category the form
 * shows is sent, blank included.
 *
 * @param  array<string, string|null>  $targets
 */
function saveTargets(User $user, string $month, array $targets): TestResponse
{
    return test()
        ->actingAs($user)
        ->from(route('transactions.index'))
        ->post(route('category-targets.store'), ['month' => $month, 'targets' => $targets]);
}

test('saving targets requires a signed in user', function () {
    $this->post(route('category-targets.store'))->assertRedirect(route('login'));
});

test('a target is stored in minor units for the month it was set on', function () {
    saveTargets($this->user, $this->month, ['groceries' => '12.50'])
        ->assertRedirect(route('transactions.index'));

    $target = CategoryTarget::query()->sole();

    expect($target->user_id)->toBe($this->user->id);
    expect($target->month)->toBe($this->month);
    expect($target->category)->toBe('groceries');
    expect($target->amount_minor)->toBe(1250);
});

test('saving the same month again updates rather than duplicating', function () {
    saveTargets($this->user, $this->month, ['groceries' => '12.50']);
    saveTargets($this->user, $this->month, ['groceries' => '20.00']);

    expect(CategoryTarget::query()->count())->toBe(1);
    expect(CategoryTarget::query()->sole()->amount_minor)->toBe(2000);
});

test('each month keeps its own targets', function () {
    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    saveTargets($this->user, $lastMonth, ['groceries' => '10.00']);
    saveTargets($this->user, $this->month, ['groceries' => '30.00']);

    expect(CategoryTarget::forMonth($this->user->id, $lastMonth))->toBe(['groceries' => 1000]);
    expect(CategoryTarget::forMonth($this->user->id, $this->month))->toBe(['groceries' => 3000]);
});

/**
 * A row existing is the whole of "there is a target here", so clearing a field
 * has to remove the row. Writing a zero instead would make a target impossible
 * to un-set.
 */
test('a blank field deletes the target rather than storing zero', function () {
    saveTargets($this->user, $this->month, ['groceries' => '12.50']);
    saveTargets($this->user, $this->month, ['groceries' => null]);

    expect(CategoryTarget::query()->count())->toBe(0);
});

/** The other half of the pair above: zero is a target, not an absence. */
test('a target of zero is stored and is not the same as no target', function () {
    saveTargets($this->user, $this->month, ['groceries' => '0']);

    expect(CategoryTarget::query()->sole()->amount_minor)->toBe(0);
    expect(CategoryTarget::forMonth($this->user->id, $this->month))->toBe(['groceries' => 0]);
});

test('a category left out of the payload is untouched', function () {
    saveTargets($this->user, $this->month, ['groceries' => '12.50', 'bills' => '99.00']);
    saveTargets($this->user, $this->month, ['groceries' => '15.00']);

    expect(CategoryTarget::forMonth($this->user->id, $this->month))
        ->toBe(['bills' => 9900, 'groceries' => 1500]);
});

test('a target may not be negative', function () {
    saveTargets($this->user, $this->month, ['groceries' => '-5.00'])
        ->assertSessionHasErrors('targets.groceries');

    expect(CategoryTarget::query()->count())->toBe(0);
});

test('a category the user does not own is rejected', function () {
    saveTargets($this->user, $this->month, ['not_a_category' => '10.00'])
        ->assertSessionHasErrors('targets');

    expect(CategoryTarget::query()->count())->toBe(0);
});

/** The month arrows stop at the current month, so nothing later is reachable. */
test('a target for a future month is rejected', function () {
    saveTargets($this->user, now()->addMonthNoOverflow()->format('Y-m'), ['groceries' => '10.00'])
        ->assertSessionHasErrors('month');

    expect(CategoryTarget::query()->count())->toBe(0);
});

test('one user cannot write into another user targets', function () {
    $other = User::factory()->create();

    saveTargets($this->user, $this->month, ['groceries' => '12.50']);

    expect(CategoryTarget::query()->where('user_id', $other->id)->count())->toBe(0);
    expect(CategoryTarget::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('the page sends the targets for the month on screen', function () {
    CategoryTarget::factory()->for($this->user)->forMonth($this->month)->create([
        'category' => 'groceries',
        'amount_minor' => 25000,
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('targets.month', $this->month)
            ->where('targets.saved.groceries', 25000)
            ->where('targets.total', 25000)
            ->where('targets.copiedFrom', null));
});

/**
 * The 'ignore' category is held out of every money total, so counting its
 * target in the figure shown beside money out would make the month read as
 * permanently over.
 */
test('the target total skips categories held out of the totals', function () {
    CategoryTarget::factory()->for($this->user)->forMonth($this->month)->create([
        'category' => 'ignore',
        'amount_minor' => 50000,
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('targets.saved.ignore', 50000)
            ->where('targets.total', null));
});

test('a month with no targets is prefilled from the most recent earlier month', function () {
    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    CategoryTarget::factory()->for($this->user)->forMonth($lastMonth)->create([
        'category' => 'groceries',
        'amount_minor' => 25000,
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('targets.prefill.groceries', 25000)
            ->where('targets.copiedFrom', $lastMonth)
            /** A suggestion only, so nothing is shown as set yet. */
            ->where('targets.total', null));

    /** And nothing was written for the month being looked at. */
    expect(CategoryTarget::query()->where('month', $this->month)->count())->toBe(0);
});

test('a month with its own targets is not prefilled from an earlier one', function () {
    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    CategoryTarget::factory()->for($this->user)->forMonth($lastMonth)->create([
        'category' => 'bills',
        'amount_minor' => 40000,
    ]);

    CategoryTarget::factory()->for($this->user)->forMonth($this->month)->create([
        'category' => 'groceries',
        'amount_minor' => 25000,
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('targets.copiedFrom', null)
            ->where('targets.prefill.groceries', 25000)
            /** Deliberately left blank this month, so it stays blank. */
            ->missing('targets.prefill.bills'));
});

/**
 * The fallback is per month rather than per category, so emptying a month puts
 * it back to being prefilled. Asserted so it is a decision, not an accident.
 */
test('clearing every target in a month makes the next visit prefill again', function () {
    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    saveTargets($this->user, $lastMonth, ['groceries' => '10.00']);
    saveTargets($this->user, $this->month, ['groceries' => '30.00']);
    saveTargets($this->user, $this->month, ['groceries' => null]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('targets.copiedFrom', $lastMonth)
            ->where('targets.prefill.groceries', 1000));
});

/**
 * An empty PHP array encodes as a JSON array. If these went out as [], reading
 * a category off them in the browser would find an Array method instead of
 * undefined. assertInertia decodes to a PHP array and cannot tell the two
 * apart, so this reads the raw response.
 */
test('targets reach the browser as objects even when empty', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)
            ->version(Request::create(route('transactions.index'))))
        ->get(route('transactions.index'));

    $response->assertOk();

    $json = $response->getContent();

    expect($json)->toContain('"saved":{}');
    expect($json)->not->toContain('"saved":[]');
    expect($json)->toContain('"prefill":{}');
    expect($json)->not->toContain('"prefill":[]');
});
