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

    /**
     * The categorisation rules the default user starts with.
     *
     * @var array<int, array<string, mixed>>
     */
    private const CATEGORY_RULES = [
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
            'set_category' => 'expenses',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],

        /* Flights, filed with the trains and hotels already above. */
        [
            'name' => 'Qatar Airways',
            'match_field' => 'any',
            'match_type' => 'regex',
            'match_value' => 'QATAR ?AIRWAYS',
            'set_category' => self::TRIPS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Wizz Air',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'WIZZAIR',
            'set_category' => self::TRIPS,
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Thai VietJet',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'VIETJET',
            'set_category' => self::TRIPS,
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
            'name' => 'The Hawk Inn',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'THE HAWK INN',
            'set_category' => 'eating_out',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
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
        [
            'name' => 'Deliveroo',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'DELIVEROO',
            'set_category' => 'eating_out',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Wetherspoons',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'WETHERSPOONS',
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
            'name' => 'Woolwich Works',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'WOOLWICH WORKS',
            'set_category' => 'entertainment',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => '2BILS',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => '2BILS',
            'set_category' => 'eating_out',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
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
        [
            'name' => 'SNB S200658',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'SNB S200658',
            'set_category' => 'general',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
    ];

    /**
     * Seed the categorisation rules.
     *
     * Matched on name so the seeder can be re-run on its own to pick up newly
     * added rules without duplicating the ones already there.
     */
    public function run(): void
    {
        $user = User::query()->oldest('id')->firstOrFail();

        foreach (self::CATEGORY_RULES as $rule) {
            CategoryRule::query()->updateOrCreate(
                ['user_id' => $user->id, 'name' => $rule['name']],
                $rule,
            );
        }
    }
}
