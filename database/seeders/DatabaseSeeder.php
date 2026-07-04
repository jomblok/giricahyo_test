<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FarmerSeeder::class,
            BuyerSeeder::class,
            SpeciesSeeder::class,
            AccountSeeder::class,
            TreeSeeder::class,
            CarbonMeasurementSeeder::class,
            CarbonSalarySeeder::class,
            CarbonFundSeeder::class,
            TreeAdoptionSeeder::class,
            TrendDataSeeder::class,
        ]);

        $this->command->info('✅ Semua data awal berhasil diisi.');
    }
}
