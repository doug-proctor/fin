<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('a new user starts with the default categories', function () {
    expect(Category::where('user_id', $this->user->id)->count())
        ->toBe(count(Category::DEFAULTS));

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

    /** The stored value is untouched, so every filed row still matches. */
    expect($category->fresh()->value)->toBe('groceries');

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.0.categoryLabel', 'Food shopping'));
});

test('one user cannot rename another user category', function () {
    $other = User::factory()->create();
    $category = Category::where('user_id', $other->id)->where('value', 'personal_care')->first();

    $this->actingAs($this->user)
        ->patch(route('categories.update', $category), ['label' => 'Hijacked'])
        ->assertForbidden();

    expect($category->fresh()->label)->toBe('Personal care');
});

test('a category cannot be renamed to nothing', function () {
    $category = Category::where('user_id', $this->user->id)->where('value', 'personal_care')->first();

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

    /** Built from the name, so the stored handle stays readable. */
    expect($category->value)->toBe('coffee');
});

test('a category created here can be used to categorise a transaction', function () {
    $account = Account::factory()->monzo()->for($this->user)->create();
    $transaction = Transaction::factory()->forAccount($account)->create(['category' => null]);

    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Coffee']);

    $this->actingAs($this->user)
        ->patch(route('transactions.update', $transaction), ['category' => 'coffee'])
        ->assertRedirect();

    expect($transaction->fresh()->category)->toBe('coffee');
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
     * Both slug to eating_in, which the unique index would reject, so
     * the second is stepped past rather than colliding.
     */
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Eating in']);
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Eating In!']);

    expect(Category::where('user_id', $this->user->id)->where('label', 'Eating in')->value('value'))
        ->toBe('eating_in');

    expect(Category::where('user_id', $this->user->id)->where('label', 'Eating In!')->value('value'))
        ->toBe('eating_in_2');
});

test('a category created here is listed alongside the default ones', function () {
    $this->actingAs($this->user)->post(route('categories.store'), ['label' => 'Coffee']);

    $this->actingAs($this->user)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories', fn ($categories) => collect($categories)
                ->firstWhere('value', 'coffee')['label'] === 'Coffee'));
});
