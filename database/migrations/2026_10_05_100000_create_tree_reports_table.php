<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan lapangan dari petani, menunggu verifikasi admin.
 *
 * KENAPA LAPORAN, BUKAN PERUBAHAN LANGSUNG.
 *
 * Sebelum tabel ini ada, petani tidak bisa mencatat apa pun tentang pohonnya
 * kecuali mengunggah foto — terverifikasi dari daftar rute: dari 36 endpoint,
 * 23 hanya untuk admin, dan peran lain cuma punya dua aksi tulis. Petani yang
 * melihat pohonnya tumbang harus menelepon admin.
 *
 * Godaannya adalah membuka `PATCH /trees/{id}/health` untuk petani pemiliknya
 * dan selesai. Itu salah untuk sistem ini. Huruf V pada MRV berarti
 * *Verification*: angka karbon di sini dijual ke pembeli dan dipakai sebagai
 * bukti klaim, jadi tidak boleh ada jalur di mana satu orang mengubah catatan
 * resmi tanpa ada yang memeriksa. Kalau petani bisa langsung menandai pohonnya
 * "buruk", ia juga bisa langsung mengubah dasar perhitungan pendapatannya
 * sendiri.
 *
 * Jadi laporan masuk ke sini dulu. Admin memverifikasi, dan baru saat
 * disetujui perubahannya diterapkan ke tabel `trees`. Hasil sampingannya
 * justru yang paling berharga untuk laporan PKM: ada jejak lengkap siapa
 * melaporkan apa, kapan, dengan foto apa, dan siapa yang menyetujuinya.
 *
 * SATU TABEL UNTUK DUA JENIS LAPORAN.
 *
 * `kondisi`    — pohon yang sudah terdaftar berubah keadaannya.
 * `pohon_baru` — petani mendaftarkan pohon yang belum pernah tercatat.
 *
 * Keduanya berbagi alur yang sama persis (ajukan → tinjau → terapkan), jadi
 * memisahkannya jadi dua tabel berarti menduplikasi seluruh antrean verifikasi
 * beserta UI-nya. Yang berbeda hanya isi `payload`, dan itu sebabnya kolom itu
 * JSON: bentuk data pohon baru (jenis, DBH, tinggi, koordinat) tidak sama
 * dengan laporan kondisi (status + catatan).
 *
 * `tree_id` boleh NULL justru karena `pohon_baru`: pohonnya belum ada saat
 * laporan dibuat, dan ID-nya baru terbit setelah admin menyetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_reports', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['kondisi', 'pohon_baru']);

            // NULL untuk laporan pohon_baru yang belum disetujui — pohonnya
            // memang belum ada. Diisi dengan ID pohon yang terbit begitu
            // laporannya diterima, supaya jejaknya tetap tersambung.
            $table->string('tree_id', 20)->nullable();

            $table->string('farmer_id', 10);

            // Isi laporan. Bentuknya berbeda per jenis:
            //   kondisi    => {"health_status": "buruk"}
            //   pohon_baru => {"species","dbh_cm","height_m","latitude",
            //                  "longitude","density_rho","planted_date"}
            $table->json('payload');

            $table->string('note', 500)->nullable();

            // Foto bukti. longtext karena foto di aplikasi ini disimpan sebagai
            // data URI base64, sama seperti kolom photo_url di tree_photos —
            // bukan path berkas.
            $table->longText('photo_url')->nullable();

            $table->enum('status', ['menunggu', 'disetujui', 'ditolak'])
                  ->default('menunggu');

            // Alasan admin menolak. Wajib diisi saat menolak (dipaksa di
            // controller, bukan di sini) — petani yang laporannya ditolak
            // tanpa alasan tidak akan tahu apa yang harus diperbaiki, dan
            // berhenti melapor.
            $table->string('review_note', 500)->nullable();

            $table->string('reviewed_by', 10)->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // Antrean admin selalu dibuka dengan "yang menunggu, terlama dulu",
            // dan halaman petani selalu "punya saya, terbaru dulu".
            $table->index(['status', 'created_at']);
            $table->index(['farmer_id', 'created_at']);
            $table->index('tree_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_reports');
    }
};
