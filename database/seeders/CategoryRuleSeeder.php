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
            'match_values' => ['TFL TRAVEL CHARGE'],
            'set_category' => 'transport',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Groceries',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => [
                'SAINSBURYS',
                'Waitrose Beckenham',
                'M&S',
                'Tesco',
                "SAINSBURY'S SUPERMARKET BECKENHAM",
                "SAINSBURY'S",
                'Waitrose & Partners',
            ],
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Amex repayments',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['PAYMENT RECEIVED - THANK YOU', 'American Express'],
            'set_category' => 'transfers',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Trips',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['TRAINLINE', 'BOOKING.COM'],
            'set_category' => 'trips',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'SHOTSMITHS',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['SHOTSMITHS'],
            'set_category' => 'groceries',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Self care',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['MINDFUL THERAPIST', 'Mytime Active'],
            'set_category' => 'personal_care',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'AWS',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['AWS EMEA'],
            'set_category' => 'subscriptions',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Apple subs',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['Apple'],
            'amount_minor' => -899,
            'day_of_month' => 3,
            'set_category' => 'subscriptions',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Apple subs',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['Apple'],
            'amount_minor' => -899,
            'day_of_month' => 21,
            'set_category' => 'subscriptions',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Bear',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['Apple'],
            'amount_minor' => -299,
            'day_of_month' => 6,
            'set_category' => 'subscriptions',
            'set_name' => 'Bear',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Purpleport',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['Purpleport'],
            'set_category' => 'subscriptions',
            'set_tags' => ['reverie'],
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'James',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['JAMES PROCTOR'],
            'set_category' => 'james',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Bills',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => [
                'Virgin Media',
                'O2',
                'Octopus Energy',
                'Thames Water',
                'London Borough of Bromley',
                'Nationwide Mortgages',
            ],
            'set_category' => 'bills',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ],
        [
            'name' => 'Mum',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['Magazines Direct', 'Vodafone'],
            'set_category' => 'mum',
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
