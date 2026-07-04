<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TrendDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('trend_data')->insert([
            ['year' => 2022, 'total_co2_ton' => 18, 'certificates_sold' => 0,  'total_trees' => 1240, 'created_at' => $now, 'updated_at' => $now],
            ['year' => 2023, 'total_co2_ton' => 34, 'certificates_sold' => 12, 'total_trees' => 1480, 'created_at' => $now, 'updated_at' => $now],
            ['year' => 2024, 'total_co2_ton' => 52, 'certificates_sold' => 31, 'total_trees' => 1680, 'created_at' => $now, 'updated_at' => $now],
            ['year' => 2025, 'total_co2_ton' => 71, 'certificates_sold' => 58, 'total_trees' => 1810, 'created_at' => $now, 'updated_at' => $now],
            ['year' => 2026, 'total_co2_ton' => 89, 'certificates_sold' => 73, 'total_trees' => 1935, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
