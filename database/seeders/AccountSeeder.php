<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Password di-hash dengan bcrypt.
        // Default password untuk semua akun seed:
        //   admin  → admin123
        //   petani → petani123
        //   buyer  → buyer123
        // Minta pengguna ganti password setelah login pertama kali.

        DB::table('accounts')->insert([
            // Admin
            [
                'id'          => 'ACC001',
                'email'       => 'admin@giricahyo.id',
                'password'    => Hash::make('admin123'),
                'role'        => 'admin',
                'name'        => 'Koperasi Giricahyo',
                'linked_id'   => null,
                'deactivated' => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            // Petani — satu akun per petani aktif
            ['id' => 'ACC002', 'email' => 'slamet@giricahyo.id',   'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Pak Slamet Riyadi', 'linked_id' => 'F001', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC003', 'email' => 'wartini@giricahyo.id',   'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Bu Wartini',         'linked_id' => 'F002', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC004', 'email' => 'darmaji@giricahyo.id',   'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Pak Darmaji',        'linked_id' => 'F003', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC005', 'email' => 'suyono@giricahyo.id',    'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Pak Suyono',         'linked_id' => 'F004', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC006', 'email' => 'ngatinem@giricahyo.id',  'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Bu Ngatinem',        'linked_id' => 'F005', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC007', 'email' => 'sumarni@giricahyo.id',   'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Bu Sumarni',         'linked_id' => 'F007', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'ACC008', 'email' => 'wagiman@giricahyo.id',   'password' => Hash::make('petani123'), 'role' => 'farmer', 'name' => 'Pak Wagiman',        'linked_id' => 'F008', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
            // Buyer
            ['id' => 'ACC009', 'email' => 'buyer@giricahyo.id',     'password' => Hash::make('buyer123'),  'role' => 'buyer',  'name' => 'PT Hijau Lestari',   'linked_id' => 'B001', 'deactivated' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
