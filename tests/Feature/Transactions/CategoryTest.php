<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('a new user starts with monzo built-in categories', function () {
    expect(Category::where('user_id', $this->user->id)->count())
        ->toBe(count(Category::MONZO_DEFAULTS));

    expect(Category::where('user_id', $this->user->id)->where('value', 'groceries')->value('label'))
        ->toBe('Groceries');
});

test('the categories page lists them with how many transactions use each', function () {
    $account = Account::factory()->monzo()->for($this->user)->create();
    Transaction::factory()->forAccount($account)->count(3)->create(['category' => 'groceries']);

    $this->actingAs($this->user)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories', fn ($categories) => collect($categories)
                ->firstWhere('value', 'groceries')['count'] === 3));
});

test('a category can be renamed and the new name reaches the table', function () {
    $account = Account::factory()->monzo()->for($this->user)->create();
    Transaction::factory()->forAccount($account)->create(['category' => 'groceries']);

    $category = Category::where('user_id', $this->user->id)->where('value', 'groceries')->first();

    $this->actingAs($this->user)
        ->patch(route('categories.update', $category), ['label' => 'Food shopping'])
        ->assertRedirect();

    expect($category->fresh()->label)->toBe('Food shopping');

    /** The stored value is untouched, so a re-sync still matches the row. */
    expect($category->fresh()->value)->toBe('groceries');

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.0.categoryLabel', 'Food shopping'));
});

test('one user cannot rename another user category', function () {
    $other = User::factory()->create();
    $category = Category::where('user_id', $other->id)->where('value', 'bills')->first();

    $this->actingAs($this->user)
        ->patch(route('categories.update', $category), ['label' => 'Hijacked'])
        ->assertForbidden();

    expect($category->fresh()->label)->toBe('Bills');
});

test('a category with no name yet is flagged for the user to name', function () {
    Category::ensure($this->user->id, 'category_0000B86WnKknuzF8vd1v9g');

    $this->actingAs($this->user)
        ->get(route('categories.index'))
        ->assertInertia(fn ($page) => $page
            ->where('categories', fn ($categories) => collect($categories)
                ->firstWhere('value', 'category_0000B86WnKknuzF8vd1v9g')['isUnnamed'] === true));
});

test('a category cannot be renamed to nothing', function () {
    $category = Category::where('user_id', $this->user->id)->where('value', 'bills')->first();

    $this->actingAs($this->user)
        ->patch(route('categories.update', $category), ['label' => ''])
        ->assertSessionHasErrors('label');
});

test('a category can be created and gets a value of its own', function () {
    $this->actingAs($this->user)
        ->post(route('categories.store'), ['label' => 'Coffee'])
        ->assertRedirect();

    $category = Category::where('user_id', $this->user->id)->where('label', 'Coffee')->first();

    expect($category)->not->toBeNull();

    /** No sync will send this value, so it is built from the name. */
    expect($category->value)->toBe('custom_coffee');
});

test('a category created here can be used to categorise a transaction', function () {
    $account = Account::factory()->monzo()->for($this->user)->create();
    $transaction = Transaction::factory()->forAccount($account)->create(['category' => null]);

    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Coffee']);

    $this->actingAs($this->user)
        ->patch(route('transactions.update', $transaction), ['category' => 'custom_coffee'])
        ->assertRedirect();

    expect($transaction->fresh()->category)->toBe('custom_coffee');
});

test('a category cannot be created with a name already in use', function () {
    $this->actingAs($this->user)
        ->post(route('categories.store'), ['label' => 'Groceries'])
        ->assertSessionHasErrors('label');

    expect(Category::where('user_id', $this->user->id)->where('label', 'Groceries')->count())
        ->toBe(1);
});

test('a category cannot be created without a name', function () {
    $this->actingAs($this->user)
        ->post(route('categories.store'), ['label' => ''])
        ->assertSessionHasErrors('label');
});

test('two names that read the same way still get separate values', function () {
    /*
     * Both slug to custom_eating_in, which the unique index would reject, so
     * the second is stepped past rather than colliding.
     */
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Eating in']);
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Eating In!']);

    expect(Category::where('user_id', $this->user->id)->where('label', 'Eating in')->value('value'))
        ->toBe('custom_eating_in');

    expect(Category::where('user_id', $this->user->id)->where('label', 'Eating In!')->value('value'))
        ->toBe('custom_eating_in_2');
});

test('a category created here is listed as the user own', function () {
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Coffee']);

    $this->actingAs($this->user)
        ->get(route('categories.index'))
        ->assertInertia(fn ($page) => $page
            ->where('categories', fn ($categories) => collect($categories)
                ->firstWhere('value', 'custom_coffee')['isUnnamed'] === false));
});
