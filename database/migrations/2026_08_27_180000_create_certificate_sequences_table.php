<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatat nomor sertifikat terakhir per tahun.
 *
 * KENAPA PERLU TABEL SENDIRI: sebelumnya nomor dibuat dengan menghitung baris
 * yang ada — `count() + 1`. Selama sertifikat tidak pernah dihapus, cara itu
 * kebetulan benar. Begitu fitur hapus ada, ia langsung salah:
 *
 *   terbitkan 3 sertifikat  → 0001, 0002, 0003
 *   hapus 0003              → tersisa 2 baris
 *   terbitkan lagi          → count() + 1 = 3 → 0003 LAGI
 *
 * Dua dokumen berbeda dengan nomor sama, dan yang pertama mungkin sudah
 * dipegang buyer. Untuk sertifikat karbon yang dijadikan bukti klaim, itu
 * bukan cacat kecil.
 *
 * Cara ini menyimpan nomor TERAKHIR YANG PERNAH DIPAKAI, bukan menghitung
 * yang masih ada — jadi menghapus sertifikat meninggalkan lompatan nomor
 * (0001, 0002, ...0004), persis seperti buku faktur yang lembarnya dibatalkan.
 * Lompatan itu wajar dan justru terbaca sebagai jejak; nomor kembar tidak.
 *
 * Sekaligus memperbaiki masalah kedua yang selama ini diam: `count()` lalu
 * `insert` adalah dua langkah terpisah. Dua admin yang menekan "Buat
 * Sertifikat" pada detik yang sama sama-sama membaca hitungan yang sama dan
 * mendapat nomor yang sama — yang satu gagal karena kolomnya unique. Sekarang
 * pengambilan nomor memakai penguncian baris (lockForUpdate), jadi yang kedua
 * menunggu dan mendapat nomor berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });

        // Isi dari data yang sudah ada, supaya sertifikat yang terlanjur
        // terbit tidak tertimpa nomornya. Dibaca dari certificate_no yang
        // tersimpan (angka setelah tanda hubung terakhir), BUKAN dari jumlah
        // baris — kalau sudah pernah ada yang terhapus manual lewat database,
        // menghitung baris akan memberi angka yang terlalu kecil.
        $baris = DB::table('tree_adoptions')
            ->selectRaw("YEAR(adopted_date) as tahun, MAX(CAST(SUBSTRING_INDEX(certificate_no, '-', -1) AS UNSIGNED)) as terakhir")
            ->whereNotNull('certificate_no')
            ->groupBy('tahun')
            ->get();

        foreach ($baris as $b) {
            DB::table('certificate_sequences')->insert([
                'year'       => (int) $b->tahun,
                'last_no'    => (int) $b->terakhir,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_sequences');
    }
};
