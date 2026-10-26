<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatat nomor pohon terakhir per tahun.
 *
 * Persis masalah yang sama dengan `certificate_sequences`, dan diperbaiki
 * dengan cara yang sama — tapi untuk pohon akibatnya lebih parah.
 *
 * Sebelumnya `Tree::generateId()` mencari ID tertinggi YANG MASIH ADA lalu
 * menambah satu. Selama pohon tidak pernah dihapus, itu kebetulan benar.
 * Begitu ada penghapusan, ID langsung terpakai ulang — dan itu bukan skenario
 * karangan: terbukti saat menguji pendaftaran pohon dari lapangan, pohon baru
 * mendapat `GJR-26-001`, persis ID pohon uji yang dihapus beberapa menit
 * sebelumnya.
 *
 * KENAPA LEBIH PARAH DARIPADA NOMOR SERTIFIKAT: ID pohon adalah isi QR yang
 * DICETAK DAN DITEMPEL DI BATANG. Sertifikat bernomor kembar masih bisa
 * ditarik dan diterbitkan ulang; QR yang sudah terpaku di pohon di lereng
 * Gunungkidul tidak. Pengunjung yang memindai QR lama akan dibawa ke halaman
 * pohon yang sama sekali berbeda — jenisnya lain, petaninya lain, angka
 * karbonnya lain — tanpa ada tanda bahwa itu keliru.
 *
 * Seperti pada sertifikat, tabel ini menyimpan nomor TERAKHIR YANG PERNAH
 * DIPAKAI, bukan menghitung yang masih ada. Menghapus pohon meninggalkan
 * lompatan nomor, dan lompatan itu justru benar: ia menandakan ada sesuatu
 * yang pernah ada di situ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_sequences', function (Blueprint $table) {
            // Dua digit tahun, sama dengan pola ID-nya: GJR-26-001.
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });

        // Diisi dari ID pohon yang sudah ada, supaya nomor tidak mundur dan
        // menabrak pohon yang sudah terdaftar. Hanya pohon berpola
        // GJR-<2 digit>-<nomor> yang ikut; pohon hasil impor survei berpola
        // GJR-L1-001 dan sengaja TIDAK dihitung di sini — huruf L bukan tahun,
        // dan penomorannya memang terpisah per lokasi.
        $baris = DB::table('trees')
            ->where('id', 'REGEXP', '^GJR-[0-9]{2}-[0-9]+$')
            ->pluck('id');

        $maks = [];

        foreach ($baris as $id) {
            [, $tahun, $nomor] = explode('-', $id);
            $tahun = (int) $tahun;
            $nomor = (int) $nomor;

            if (! isset($maks[$tahun]) || $nomor > $maks[$tahun]) {
                $maks[$tahun] = $nomor;
            }
        }

        foreach ($maks as $tahun => $nomor) {
            DB::table('tree_sequences')->insert([
                'year'       => $tahun,
                'last_no'    => $nomor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_sequences');
    }
};
