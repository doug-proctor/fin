<?php

namespace Database\Seeders;

use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoryRuleSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The "Trips" category the user created in the Monzo app. A custom
     * category only ever arrives as an opaque id, so that id is what a rule
     * has to set; the readable name is restated in
     * DatabaseSeeder::CUSTOM_CATEGORIES.
     *
     * php artisan db:seed --class=CategoryRuleSeeder
     */
    private const TRIPS = 'category_0000B87NKzENdqVoflYV3C';

    /** The user's "Subscriptions" category, opaque for the same reason. */
    private const SUBSCRIPTIONS = 'category_0000B86WnKknuzF8vd1v9g';

    /**
     * The categorisation rules the default user starts with.
     *
     * Public so the seeder test can assert every declared rule survives a run
     * without restating any of them, and so stays green as this list changes.
     *
     * @var array<int, array<string, mixed>>
     */
    public const CATEGORY_RULES = [
        [
            'name' => 'TFL',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'TFL TRAVEL CHARGE',
            'set_category' => 'transport',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => "Sainsbury's",
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => "Sainsbury's",
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Waitrose Beckenham',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'Waitrose Beckenham',
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'PAYMENT RECEIVED - THANK YOU',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'PAYMENT RECEIVED - THANK YOU',
            'set_category' => 'transfers',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'TRAINLINE',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'TRAINLINE',
            'set_category' => self::TRIPS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'BOOKING.COM',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'BOOKING.COM',
            'set_category' => self::TRIPS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'SHOTSMITHS',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'SHOTSMITHS',
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],

        /*
         * AMEX rows below this point. Monzo categorises its own transactions,
         * so everything a rule has to reach is a card row whose only clue is
         * the padded description the statement carries.
         */
        [
            'name' => 'The Mindful Therapist',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'MINDFUL THERAPIST',
            'set_category' => 'personal_care',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'AWS',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'AWS EMEA',
            'set_category' => self::SUBSCRIPTIONS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],

        /* Supermarkets. */
        [
            'name' => 'Lidl',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'LIDL',
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Tesco',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'TESCO',
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'M&S',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'M&S',
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],

        /* Pubs, cafes and takeaways. */

        [
            'name' => 'Pret A Manger',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'PRET A MANGER',
            'set_category' => 'eating_out',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],

        /*
         * The last three are the guesses with the least behind them. The card
         * terminal brand is all the statement gives for the first two, and the
         * third is an airport concession.
         */

        [
            'name' => 'Avolta',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'AVOLTA',
            'set_category' => 'shopping',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        /*
         * Two Apple charges of the same size land each month, one on the 3rd
         * and one on the 21st. The amount and the day are what separate them
         * from every other Apple charge, so both are pinned.
         */
        [
            'name' => 'Apple subs',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'Apple',
            'amount_minor' => -899,
            'day_of_month' => 3,
            'set_category' => self::SUBSCRIPTIONS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Apple subs',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'Apple',
            'amount_minor' => -899,
            'day_of_month' => 21,
            'set_category' => self::SUBSCRIPTIONS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
    ];

    /**
     * Seed the categorisation rules.
     *
     * Matched on name so the seeder can be re-run on its own to pick up newly
     * added rules without duplicating the ones already there. The day of the
     * month is part of that identity because the two Apple rules share a
     * name and differ only in which day they fire on; on every other rule it
     * is null and narrows nothing.
     */
    public function run(): void
    {
        $user = User::query()->oldest('id')->firstOrFail();

        foreach (self::CATEGORY_RULES as $rule) {
            CategoryRule::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $rule['name'],
                    'day_of_month' => $rule['day_of_month'] ?? null,
                ],
                $rule,
            );
        }
    }
}
