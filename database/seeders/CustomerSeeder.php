<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $customers = [
            [
                'code' => 'CUST-000001',
                'name' => 'Northwind Industrial Ltd',
                'document' => '123456789',
                'email' => 'purchasing@northwind.test',
                'phone' => '+14165550101',
                'contact_name' => 'Alice Hart',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-000002',
                'name' => 'Harbour Roofing Co',
                'document' => '987654321',
                'email' => 'ops@harbourroofing.test',
                'phone' => '+14165550102',
                'contact_name' => 'Ben Ortega',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-000003',
                'name' => 'Summit Safety Group',
                'document' => '456789123',
                'email' => 'quotes@summitsafety.test',
                'phone' => '+14165550103',
                'contact_name' => 'Clara Nguyen',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-000004',
                'name' => 'Maple Access Systems',
                'document' => '321654987',
                'email' => 'contact@mapleaccess.test',
                'phone' => '+14165550104',
                'contact_name' => 'Diego Silva',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CUST-000005',
                'name' => 'Pacific Ventilation Inc',
                'document' => '654321789',
                'email' => 'info@pacificvent.test',
                'phone' => '+14165550105',
                'contact_name' => 'Elena Brooks',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        Customer::upsert(
            $customers,
            ['code'],
            ['name', 'document', 'email', 'phone', 'contact_name', 'active', 'updated_at'],
        );
    }
}
