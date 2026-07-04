<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('species')->insert([
            ['name' => 'Jati',        'density_rho' => 0.65, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Mahoni',      'density_rho' => 0.62, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Nyamplung',   'density_rho' => 0.60, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Akasia',      'density_rho' => 0.55, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Kayu Putih',  'density_rho' => 0.50, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Jambu Mete',  'density_rho' => 0.58, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Lamtoro',     'density_rho' => 0.52, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Sono Keling', 'density_rho' => 0.70, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Trembesi',    'density_rho' => 0.40, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
