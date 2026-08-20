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
         * Category::DEFAULTS is the whole set. Most of them are what the rules
         * below file transactions under; a category with no rule behind it is
         * one filed by hand. WithoutModelEvents suppresses the User::created
         * hook that would normally seed them, so it is called explicitly.
         */
        Category::seedDefaults($user->id);

        $this->call(CategoryRuleSeeder::class);
    }
}
