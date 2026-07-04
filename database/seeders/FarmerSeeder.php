<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FarmerSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('farmers')->insert([
            ['id' => 'F001', 'name' => 'Pak Slamet Riyadi', 'group_coop' => 'Kelompok Tani Giricahyo I',   'address' => 'Dusun Karang, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F002', 'name' => 'Bu Wartini',         'group_coop' => 'Kelompok Tani Giricahyo I',   'address' => 'Dusun Karang, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F003', 'name' => 'Pak Darmaji',        'group_coop' => 'Kelompok Tani Giricahyo II',  'address' => 'Dusun Sumber, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F004', 'name' => 'Pak Suyono',         'group_coop' => 'Kelompok Tani Giricahyo II',  'address' => 'Dusun Sumber, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F005', 'name' => 'Bu Ngatinem',        'group_coop' => 'Kelompok Tani Giricahyo III', 'address' => 'Dusun Kayen, Giricahyo, Purwosari, Gunungkidul',  'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F006', 'name' => 'Pak Tukiman',        'group_coop' => 'Kelompok Tani Giricahyo III', 'address' => 'Dusun Kayen, Giricahyo, Purwosari, Gunungkidul',  'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F007', 'name' => 'Bu Sumarni',         'group_coop' => 'Kelompok Tani Giricahyo I',   'address' => 'Dusun Karang, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
            ['id' => 'F008', 'name' => 'Pak Wagiman',        'group_coop' => 'Kelompok Tani Giricahyo II',  'address' => 'Dusun Sumber, Giricahyo, Purwosari, Gunungkidul', 'status' => 'active',   'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
