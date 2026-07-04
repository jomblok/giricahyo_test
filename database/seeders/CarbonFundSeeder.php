<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CarbonFundSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('carbon_fund_income')->insert([
            ['date' => '2026-05-01', 'source' => 'Guest Contribution', 'qty' => 10, 'unit_price' => 200000,  'total_amount' => 2000000,  'created_at' => $now, 'updated_at' => $now],
            ['date' => '2026-05-07', 'source' => 'ESG Fund',           'qty' => 1,  'unit_price' => 5000000, 'total_amount' => 5000000,  'created_at' => $now, 'updated_at' => $now],
            ['date' => '2026-05-12', 'source' => 'Carbon Tourism',     'qty' => 6,  'unit_price' => 500000,  'total_amount' => 3000000,  'created_at' => $now, 'updated_at' => $now],
            ['date' => '2026-05-18', 'source' => 'CSR',                'qty' => 1,  'unit_price' => 4000000, 'total_amount' => 4000000,  'created_at' => $now, 'updated_at' => $now],
            ['date' => '2026-05-25', 'source' => 'Donor',              'qty' => 1,  'unit_price' => 1000000, 'total_amount' => 1000000,  'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('fund_distribution')->insert([
            ['period' => '2026-Q2', 'component' => 'Gaji Karbon Petani',  'percentage' => 80, 'amount' => 12000000, 'created_at' => $now, 'updated_at' => $now],
            ['period' => '2026-Q2', 'component' => 'Tata Kelola Koperasi','percentage' => 10, 'amount' => 1500000,  'created_at' => $now, 'updated_at' => $now],
            ['period' => '2026-Q2', 'component' => 'MRV & Operasional',   'percentage' => 10, 'amount' => 1500000,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
