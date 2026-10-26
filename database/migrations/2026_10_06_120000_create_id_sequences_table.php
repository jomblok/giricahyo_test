<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatat nomor terakhir untuk ID bertekstur teks: petani, buyer, akun.
 *
 * Sama persis dengan `tree_sequences` dan `certificate_sequences`, hanya untuk
 * tiga tabel yang tertinggal. Sebelum ini ketiganya memakai pola:
 *
 *     $lastId = Farmer::orderByDesc('id')->value('id') ?? 'F000';
 *     $nextNum = intval(substr($lastId, 1)) + 1;
 *
 * Dua hal rusak di situ.
 *
 * PERTAMA, urutannya teks, bukan angka. Selama ID-nya tiga digit berpadding
 * nol ('F009' < 'F010' < 'F099') itu kebetulan benar. Pada 'F999' nomor
 * berikutnya 'F1000' — dan secara teks 'F999' > 'F1000', karena '9' > '1'.
 * Jadi pembacaan berikutnya mengembalikan 'F999' lagi, dan ID yang sama
 * diterbitkan terus-menerus sampai ada yang menyadarinya.
 *
 * KEDUA, pembacaannya tidak dikunci. Dua pengurus yang membuat akun pada
 * detik yang sama membaca nilai maksimum yang sama, lalu keduanya menulis ID
 * yang sama. Pada akun, ID itu dipakai sebagai `linked_id` — jadi dua orang
 * bisa berakhir menunjuk record petani yang sama.
 *
 * Seperti dua tabel sequence sebelumnya, yang disimpan adalah nomor TERAKHIR
 * YANG PERNAH DIPAKAI, bukan hasil menghitung baris yang masih ada. Menghapus
 * petani meninggalkan lompatan nomor, dan lompatan itu benar: ID lama tidak
 * boleh dipakai ulang oleh orang yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_sequences', function (Blueprint $table) {
            // 'farmers' | 'buyers' | 'accounts'. Primary key, jadi satu baris
            // per jenis ID dan tidak mungkin ada dua pencatat untuk hal sama.
            $table->string('name', 32)->primary();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });

        // Ditanam dari data yang ADA SEKARANG, bukan dari nol. Kalau dimulai
        // dari nol, akun berikutnya akan mendapat ID yang sudah dipakai.
        //
        // Angkanya diambil dengan membaca bagian numerik setiap ID lalu
        // mengambil yang terbesar secara ANGKA — justru hal yang tidak
        // dilakukan kode lama.
        foreach ([
            'farmers'  => ['tabel' => 'farmers',  'potong' => 1],
            'buyers'   => ['tabel' => 'buyers',   'potong' => 1],
            'accounts' => ['tabel' => 'accounts', 'potong' => 3],
        ] as $nama => $cfg) {
            $maks = 0;

            foreach (DB::table($cfg['tabel'])->pluck('id') as $id) {
                $angka = (int) substr((string) $id, $cfg['potong']);
                if ($angka > $maks) {
                    $maks = $angka;
                }
            }

            DB::table('id_sequences')->insert([
                'name'       => $nama,
                'last_no'    => $maks,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
    }
};
