<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dari mana angka densitas kayu itu berasal.
 *
 * `density_rho` mengali LANGSUNG ke setiap angka karbon lewat rumus Chave et
 * al. 2014: AGB = 0,0673 × (ρ·D²·H)^0,976. Jati 0,6 bukan konstanta alam —
 * ia nilai yang diambil seseorang dari suatu sumber, dan sampai sekarang
 * sumber itu tidak tercatat di mana pun.
 *
 * Untuk sistem yang sertifikat karbonnya dijual, itu lubang yang sama
 * bentuknya dengan pengukuran tanpa masukan: angkanya ada, dasarnya tidak.
 * Pertanyaan "ρ Jati 0,6 itu dari mana" adalah pertanyaan paling mudah
 * ditanyakan penguji dan paling sulit dijawab tanpa kolom ini.
 *
 * Dibiarkan NULL untuk 26 jenis yang sudah ada, bukan diisi tebakan. "Belum
 * dicantumkan" adalah keadaan yang jujur dan bisa diperbaiki; sumber karangan
 * tidak bisa dibedakan dari sumber sungguhan setelah tertulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            // "Zanne et al. 2009, Global Wood Density Database"
            $table->string('density_source', 200)->nullable()->after('density_rho');
            // Tautan atau DOI, kalau ada.
            $table->string('density_reference', 300)->nullable()->after('density_source');
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn(['density_source', 'density_reference']);
        });
    }
};
