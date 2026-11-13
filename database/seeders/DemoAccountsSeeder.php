<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\PriceRule;
use Illuminate\Database\Seeder;

/**
 * Sample customers and pricing rules for trying the drafter.
 * Fictional companies; .example email domains. Safe to re-run.
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $shah = Account::updateOrCreate(['name' => 'Shah Construction'], [
            'contacts' => [
                ['name' => 'Priya Shah', 'email' => 'priya@shahconstruction.example', 'phone' => '555-201-3344'],
                ['name' => 'Tom Reyes', 'email' => 'tom@shahconstruction.example', 'phone' => '555-201-3350'],
            ],
            'ship_to' => "Maple Street Medical Office\n120 Maple St\nSpringfield, IL 62701",
            'payment_terms' => 'Net 30',
            'discount_pct' => 10,
            'max_discount_pct' => 15,
            'notes' => 'Repeat GC. Contract discount agreed 2026.',
        ]);

        $northside = Account::updateOrCreate(['name' => 'Northside School District'], [
            'contacts' => [['name' => 'Dana Whitfield', 'email' => 'dwhitfield@northside.example', 'phone' => '555-410-0099']],
            'ship_to' => "Facilities Dept.\n800 North Ave\nSpringfield, IL 62702",
            'payment_terms' => 'Net 45',
            'tax_exempt' => true,
            'discount_pct' => 5,
            'max_discount_pct' => 10,
        ]);

        Account::updateOrCreate(['name' => 'Riverbend Builders'], [
            'contacts' => [['name' => 'Marcus Lee', 'email' => 'marcus@riverbend.example', 'phone' => '555-330-7788']],
            'ship_to' => "Riverbend Yard\n45 River Rd\nDecatur, IL 62521",
            'payment_terms' => 'Due on receipt',
            'discount_pct' => 0,
            'max_discount_pct' => null, // company default
        ]);

        $rules = [
            ['label' => 'Volume: 10+ access doors', 'product_type' => 'access_door', 'min_quantity' => 10, 'discount_pct' => 5],
            ['label' => 'Volume: 25+ units, any product', 'min_quantity' => 25, 'discount_pct' => 8],
            ['label' => 'Shah: roof hatch contract', 'account_id' => $shah->id, 'product_type' => 'roof_hatch', 'discount_pct' => 12],
            ['label' => 'Northside: floor doors', 'account_id' => $northside->id, 'product_type' => 'floor_door', 'discount_pct' => 8],
        ];

        foreach ($rules as $rule) {
            PriceRule::updateOrCreate(['label' => $rule['label']], $rule + ['account_id' => null, 'product_type' => null, 'min_quantity' => 1]);
        }
    }
}
