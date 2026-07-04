<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TreeSeeder extends Seeder
{
    // Koordinat berpusat di Giricahyo, Purwosari, Gunungkidul, DIY
    const BASE_LAT = -7.9847;
    const BASE_LNG = 110.4734;

    public function run(): void
    {
        $now = Carbon::now();
        $b   = self::BASE_LAT;
        $l   = self::BASE_LNG;

        DB::table('trees')->insert([
            ['id' => 'GJR-001-001', 'farmer_id' => 'F001', 'species' => 'Jati',       'dbh_cm' => 13,  'height_m' => 6.2, 'density_rho' => 0.65, 'latitude' => $b + 0.004,  'longitude' => $l + 0.002,  'health_status' => 'baik',   'planted_date' => '2022-03-10', 'last_updated' => '2026-05-01', 'co2_eq_kg' => 87.71,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-002-001', 'farmer_id' => 'F002', 'species' => 'Jati',       'dbh_cm' => 13,  'height_m' => 6.0, 'density_rho' => 0.65, 'latitude' => $b + 0.006,  'longitude' => $l - 0.003,  'health_status' => 'baik',   'planted_date' => '2022-03-12', 'last_updated' => '2026-05-01', 'co2_eq_kg' => 87.71,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-003-001', 'farmer_id' => 'F003', 'species' => 'Mahoni',     'dbh_cm' => 16,  'height_m' => 7.5, 'density_rho' => 0.62, 'latitude' => $b - 0.003,  'longitude' => $l + 0.005,  'health_status' => 'baik',   'planted_date' => '2021-11-20', 'last_updated' => '2026-05-03', 'co2_eq_kg' => 147.42, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-004-001', 'farmer_id' => 'F004', 'species' => 'Nyamplung',  'dbh_cm' => 19,  'height_m' => 8.0, 'density_rho' => 0.60, 'latitude' => $b - 0.005,  'longitude' => $l - 0.002,  'health_status' => 'sedang', 'planted_date' => '2021-09-15', 'last_updated' => '2026-04-28', 'co2_eq_kg' => 225.91, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-005-001', 'farmer_id' => 'F005', 'species' => 'Nyamplung',  'dbh_cm' => 21,  'height_m' => 8.4, 'density_rho' => 0.60, 'latitude' => $b + 0.008,  'longitude' => $l + 0.006,  'health_status' => 'baik',   'planted_date' => '2021-08-02', 'last_updated' => '2026-05-02', 'co2_eq_kg' => 290.88, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-001-002', 'farmer_id' => 'F001', 'species' => 'Akasia',     'dbh_cm' => 11,  'height_m' => 5.4, 'density_rho' => 0.55, 'latitude' => $b + 0.0035, 'longitude' => $l + 0.0025, 'health_status' => 'baik',   'planted_date' => '2022-05-18', 'last_updated' => '2026-05-01', 'co2_eq_kg' => 57.77,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-006-001', 'farmer_id' => 'F006', 'species' => 'Kayu Putih', 'dbh_cm' => 9,   'height_m' => 4.1, 'density_rho' => 0.50, 'latitude' => $b - 0.007,  'longitude' => $l + 0.004,  'health_status' => 'buruk',  'planted_date' => '2023-01-10', 'last_updated' => '2026-03-15', 'co2_eq_kg' => 31.20,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-007-001', 'farmer_id' => 'F007', 'species' => 'Jambu Mete', 'dbh_cm' => 14,  'height_m' => 6.6, 'density_rho' => 0.58, 'latitude' => $b + 0.002,  'longitude' => $l - 0.005,  'health_status' => 'baik',   'planted_date' => '2022-07-22', 'last_updated' => '2026-05-04', 'co2_eq_kg' => 94.30,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-008-001', 'farmer_id' => 'F008', 'species' => 'Lamtoro',    'dbh_cm' => 12,  'height_m' => 5.8, 'density_rho' => 0.52, 'latitude' => $b - 0.002,  'longitude' => $l - 0.006,  'health_status' => 'sedang', 'planted_date' => '2022-09-05', 'last_updated' => '2026-04-20', 'co2_eq_kg' => 71.40,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 'GJR-003-002', 'farmer_id' => 'F003', 'species' => 'Jati',       'dbh_cm' => 10,  'height_m' => 5.0, 'density_rho' => 0.65, 'latitude' => $b - 0.0025, 'longitude' => $l + 0.0035, 'health_status' => 'baik',   'planted_date' => '2023-02-14', 'last_updated' => '2026-05-03', 'co2_eq_kg' => 52.10,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
