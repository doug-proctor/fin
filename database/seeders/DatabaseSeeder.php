<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Categories the user created in the Monzo app, mapped to the names they
     * gave them here. Monzo only ever sends these as an opaque id and will not
     * tell a third party client what the user called them, so the labels only
     * exist in this database and have to be restated to survive a wipe.
     *
     * @var array<string, string>
     */
    public const CUSTOM_CATEGORIES = [
        'category_0000B86WnKknuzF8vd1v9g' => 'Subscriptions',
        'category_0000B86WxhUOFB3OnAn0y2' => 'Mum',
        'category_0000B86Wu1Qy9CR4om4isT' => 'Reverie',
        'category_0000B87NoI6kXL3914UzCr' => 'James',
        'category_0000B86XIELXn5iyeVs6Zp' => 'Social',
        'category_0000B87NKzENdqVoflYV3C' => 'Trips',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'id' => 1,
            'name' => 'Doug',
            'email' => 'services@dougproctor.co.uk',
            'password' => bcrypt('qwe'),
            'email_verified_at' => now(),
        ]);

        /**
         * WithoutModelEvents suppresses the User::created hook that would
         * normally do this, so the built-in set is seeded explicitly.
         */
        Category::seedDefaults($user->id);

        foreach (self::CUSTOM_CATEGORIES as $value => $label) {
            Category::query()->updateOrCreate(
                ['user_id' => $user->id, 'value' => $value],
                ['label' => $label],
            );
        }

        $this->call(CategoryRuleSeeder::class);
    }
}
