<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CarbonSalarySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('carbon_salaries')->insert([
            ['farmer_id' => 'F001', 'period' => '2026-Q2', 'total_co2_kg' => 145.48, 'co2_proportion_pct' => 12.7, 'base_salary' => 1524000, 'social_incentive_pct' => 5, 'ecological_incentive_pct' => 7, 'participation_incentive_pct' => 3, 'final_salary' => 1752600,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F002', 'period' => '2026-Q2', 'total_co2_kg' => 87.71,  'co2_proportion_pct' => 7.6,  'base_salary' => 912000,  'social_incentive_pct' => 4, 'ecological_incentive_pct' => 5, 'participation_incentive_pct' => 2, 'final_salary' => 1005120,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F003', 'period' => '2026-Q2', 'total_co2_kg' => 199.52, 'co2_proportion_pct' => 17.4, 'base_salary' => 2088000, 'social_incentive_pct' => 6, 'ecological_incentive_pct' => 8, 'participation_incentive_pct' => 4, 'final_salary' => 2463840,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F004', 'period' => '2026-Q2', 'total_co2_kg' => 225.91, 'co2_proportion_pct' => 19.7, 'base_salary' => 2364000, 'social_incentive_pct' => 8, 'ecological_incentive_pct' => 8, 'participation_incentive_pct' => 4, 'final_salary' => 2789520,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F005', 'period' => '2026-Q2', 'total_co2_kg' => 290.88, 'co2_proportion_pct' => 25.4, 'base_salary' => 3048000, 'social_incentive_pct' => 7, 'ecological_incentive_pct' => 9, 'participation_incentive_pct' => 5, 'final_salary' => 3686040,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F007', 'period' => '2026-Q2', 'total_co2_kg' => 94.30,  'co2_proportion_pct' => 8.2,  'base_salary' => 984000,  'social_incentive_pct' => 5, 'ecological_incentive_pct' => 6, 'participation_incentive_pct' => 3, 'final_salary' => 1121760,  'created_at' => $now, 'updated_at' => $now],
            ['farmer_id' => 'F008', 'period' => '2026-Q2', 'total_co2_kg' => 71.40,  'co2_proportion_pct' => 6.2,  'base_salary' => 744000,  'social_incentive_pct' => 3, 'ecological_incentive_pct' => 5, 'participation_incentive_pct' => 2, 'final_salary' => 818400,   'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
