<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periode pengukuran — yang mengubah inventaris jadi monitoring.
 *
 * Keadaan sebelum ini: 179 pohon, 179 pengukuran. Tepat satu per pohon,
 * seluruhnya dari impor survei. Aplikasinya SUDAH bisa mencatat pengukuran
 * baru lewat empat jalur, tapi tidak ada yang menggerakkan siklusnya — tidak
 * ada yang menyatakan "sekarang musim ukur", tidak ada yang tahu sudah berapa
 * yang selesai, dan tidak ada yang selesai.
 *
 * Itulah bedanya M dalam MRV: bukan sekali ukur lalu disimpan, melainkan
 * diulang dan dibandingkan.
 *
 * KENAPA TIDAK ADA FOREIGN KEY DARI carbon_measurements KE SINI. Keanggotaan
 * sebuah pengukuran pada sebuah periode diturunkan dari TANGGALNYA, bukan
 * dicatat sebagai tautan. Dua alasan:
 *
 *   1. Periode bisa dibuat setelah pengukurannya masuk (mis. pengurus baru
 *      mencatat periode di tengah musim). Dengan tautan, pengukuran yang
 *      sudah terlanjur masuk tidak akan pernah terhitung.
 *   2. Satu pengukuran tidak boleh hilang kalau periodenya dihapus. Angka
 *      karbonnya tetap sah; yang hilang hanya pengelompokannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_cycles', function (Blueprint $table) {
            $table->id();

            // "Pengukuran Semester 2 2026"
            $table->string('name', 100);
            // Kode pendek untuk dicocokkan dengan periode gaji karbon: 2026-S2
            $table->string('period', 20)->unique();

            $table->date('starts_on');
            $table->date('ends_on');

            // berjalan | selesai | dibatalkan
            $table->string('status', 16)->default('berjalan');

            // Catatan pengurus: cakupannya apa, siapa yang turun, alat apa.
            $table->text('notes')->nullable();

            $table->string('created_by', 20)->nullable();
            $table->string('created_by_name', 100)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_cycles');
    }
};
