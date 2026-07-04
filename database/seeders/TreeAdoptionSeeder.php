<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TreeAdoptionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('tree_adoptions')->insert([
            [
                'tree_id'        => 'GJR-002-001',
                'buyer_id'       => 'B001',
                'buyer_name'     => 'PT Hijau Lestari',
                'adopted_date'   => '2024-12-05',
                'package_name'   => 'Tree Adoption — 1 Pohon',
                'certificate_no' => 'GJR-CERT-2024-0001',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'tree_id'        => 'GJR-005-001',
                'buyer_id'       => 'B001',
                'buyer_name'     => 'PT Hijau Lestari',
                'adopted_date'   => '2024-12-05',
                'package_name'   => 'Tree Adoption — 1 Pohon',
                'certificate_no' => 'GJR-CERT-2024-0002',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ]);
    }
}
