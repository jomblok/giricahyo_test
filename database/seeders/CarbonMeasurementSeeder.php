<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

// ── Carbon Measurements ───────────────────────────────────────────────────────
// Satu pengukuran awal per pohon (bisa ditambah setiap tahun)
class CarbonMeasurementSeeder extends Seeder
{
    public function run(): void
    {
        $now  = Carbon::now();
        $date = '2026-05-01';

        // AGB = 0.0673 * (rho * D^2 * H)^0.976
        // BGB = AGB * 0.24
        // TB  = AGB + BGB
        // C   = TB * 0.47
        // CO2 = C * 3.67
        $rows = [
            ['tree_id' => 'GJR-001-001', 'co2_eq_kg' => 87.71],
            ['tree_id' => 'GJR-002-001', 'co2_eq_kg' => 87.71],
            ['tree_id' => 'GJR-003-001', 'co2_eq_kg' => 147.42],
            ['tree_id' => 'GJR-004-001', 'co2_eq_kg' => 225.91],
            ['tree_id' => 'GJR-005-001', 'co2_eq_kg' => 290.88],
            ['tree_id' => 'GJR-001-002', 'co2_eq_kg' => 57.77],
            ['tree_id' => 'GJR-006-001', 'co2_eq_kg' => 31.20],
            ['tree_id' => 'GJR-007-001', 'co2_eq_kg' => 94.30],
            ['tree_id' => 'GJR-008-001', 'co2_eq_kg' => 71.40],
            ['tree_id' => 'GJR-003-002', 'co2_eq_kg' => 52.10],
        ];

        foreach ($rows as $r) {
            $co2 = $r['co2_eq_kg'];
            $c   = $co2 / 3.67;
            $tb  = $c  / 0.47;
            $agb = $tb / 1.24;
            $bgb = $agb * 0.24;

            DB::table('carbon_measurements')->insert([
                'tree_id'          => $r['tree_id'],
                'measurement_date' => $date,
                'agb_kg'           => round($agb, 2),
                'bgb_kg'           => round($bgb, 2),
                'total_biomass_kg' => round($tb, 2),
                'carbon_stock_kg'  => round($c, 2),
                'co2_eq_kg'        => $co2,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }
}
