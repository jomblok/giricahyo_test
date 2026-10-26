<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan siapa melakukan apa.
 *
 * Sebelum ini tidak ada sama sekali, kecuali `verified_at`/`verified_by` pada
 * satu tabel. Siapa menghapus pohon, siapa mengubah pemasukan dana, siapa
 * menyetel ulang kata sandi orang lain — semuanya tidak meninggalkan jejak.
 *
 * Untuk sistem MRV yang sertifikat karbonnya dijual ke pihak luar, itu bukan
 * sekadar kurang nyaman: kalau ada yang mempertanyakan satu angka, tidak ada
 * cara menjawab siapa yang terakhir menyentuhnya dan kapan.
 *
 * YANG DICATAT: aksi yang mengubah uang, sertifikat, data pohon, dan akun.
 * Pembacaan TIDAK dicatat — volumenya ratusan kali lipat dan nilainya nyaris
 * nol untuk pertanggungjawaban.
 *
 * Tabel ini SENGAJA tidak punya foreign key ke accounts: catatan audit harus
 * tetap ada walau akun pelakunya kemudian dihapus. Karena itu nama pelakunya
 * ikut disalin, bukan cuma ID-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->string('account_id', 20)->nullable();
            // Disalin, bukan di-join: lihat alasannya di atas.
            $table->string('account_name', 100)->nullable();
            $table->string('account_role', 20)->nullable();

            // 'tree.update', 'adoption.delete', 'income.create', ...
            $table->string('action', 50);

            // Entitas yang disentuh, supaya bisa disaring "apa saja yang
            // pernah terjadi pada pohon ini".
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 60)->nullable();

            // Kalimat siap baca dalam bahasa Indonesia. Disimpan jadi, bukan
            // dirangkai saat ditampilkan: arti sebuah aksi bergantung pada
            // keadaan saat itu, dan keadaan itu sudah berubah ketika log
            // dibaca berbulan-bulan kemudian.
            $table->string('summary', 255);

            // Rincian tambahan (nilai sebelum/sesudah). Nullable supaya aksi
            // sederhana tidak dipaksa mengarang isi.
            $table->json('meta')->nullable();

            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Dua pola baca yang dipakai halaman audit: urut terbaru, dan
            // "riwayat satu entitas".
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
