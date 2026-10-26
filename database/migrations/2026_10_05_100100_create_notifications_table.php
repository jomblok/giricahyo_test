<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemberitahuan dalam aplikasi.
 *
 * KENAPA SEKARANG, BUKAN SEJAK AWAL. Selama sistem ini hanya dipakai admin,
 * notifikasi tidak ada gunanya: admin membuka dasbor dan melihat semuanya
 * sekaligus. Yang mengubahnya adalah tiga hal yang baru ditambahkan, dan
 * ketiganya punya ciri sama — satu peran melakukan sesuatu, peran LAIN yang
 * perlu tahu:
 *
 *   laporan petani masuk      → admin perlu tahu ada yang menunggu ditinjau
 *   admin menyetujui/menolak  → petani perlu tahu hasilnya
 *   sertifikat mendekati habis → buyer perlu tahu sebelum terlambat
 *
 * Tanpa tabel ini ketiganya hanya terlihat kalau orangnya kebetulan membuka
 * halaman yang tepat. Sertifikat kedaluwarsa diam-diam adalah pendapatan
 * berulang yang hilang tanpa ada yang sadar.
 *
 * SENGAJA SEDERHANA. Ini bukan antrean pesan: tidak ada surel, tidak ada
 * push, tidak ada percobaan ulang. Hanya baris yang dibaca saat pengguna
 * membuka aplikasi. Menambahkan surel nanti berarti membaca tabel yang sama,
 * bukan menggantinya.
 *
 * Laravel punya tabel `notifications` bawaannya sendiri dengan kolom
 * morph (`notifiable_type`/`notifiable_id`) dan payload JSON. Tidak dipakai di
 * sini karena akun di aplikasi ini ber-ID varchar(10) buatan sendiri
 * (`ACC001`), bukan ID numerik, dan seluruh kode sudah memakai pola itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Penerima. Akun, bukan petani/buyer — karena admin juga menerima
            // notifikasi dan admin tidak punya baris di farmers atau buyers.
            $table->string('account_id', 10);

            // Dipakai untuk memilih ikon dan warna di antarmuka, dan nanti
            // untuk menyaring jenis mana yang boleh dikirim lewat surel.
            // Contoh: laporan_masuk, laporan_disetujui, laporan_ditolak,
            // sertifikat_akan_habis.
            $table->string('type', 40);

            $table->string('title', 150);
            $table->string('body', 500);

            // Rute di frontend yang dituju saat notifikasi diklik, mis.
            // "/laporan" atau "/dukungan". NULL untuk notifikasi yang memang
            // tidak menuju ke mana pun.
            $table->string('link', 200)->nullable();

            // NULL berarti belum dibaca. Memakai timestamp, bukan boolean,
            // supaya nanti bisa dijawab "berapa lama laporan menggantung
            // sebelum ada yang melihatnya" tanpa menambah kolom baru.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Satu-satunya pola kueri: notifikasi milik saya, terbaru dulu.
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
