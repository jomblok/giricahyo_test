<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class BuyerSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('buyers')->insert([
            ['id' => 'B001', 'name' => 'PT Hijau Lestari', 'email' => 'buyer@giricahyo.id', 'phone' => '', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
