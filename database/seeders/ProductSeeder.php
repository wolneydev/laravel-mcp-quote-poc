<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $products = [
            [
                'code' => 'PROD-000001',
                'name' => 'Access Doors and Panels',
                'description' => 'Access doors and wall panels for equipment rooms',
                'unit' => 'unit',
                'price' => 450.00,
                'currency' => 'CAD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PROD-000002',
                'name' => 'Ladders',
                'description' => 'Fixed and portable industrial ladders',
                'unit' => 'unit',
                'price' => 129.90,
                'currency' => 'CAD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PROD-000003',
                'name' => 'Popular Picks',
                'description' => 'Assorted high-demand access and safety kit',
                'unit' => 'box',
                'price' => 89.00,
                'currency' => 'CAD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PROD-000004',
                'name' => 'Safety Rails',
                'description' => 'Guard rails for roofs, platforms, and walkways',
                'unit' => 'meter',
                'price' => 75.50,
                'currency' => 'CAD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PROD-000005',
                'name' => 'Vent Covers',
                'description' => 'Protective covers for roof and wall vents',
                'unit' => 'unit',
                'price' => 48.50,
                'currency' => 'CAD',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        Product::upsert(
            $products,
            ['code'],
            ['name', 'description', 'unit', 'price', 'currency', 'active', 'updated_at'],
        );
    }
}
