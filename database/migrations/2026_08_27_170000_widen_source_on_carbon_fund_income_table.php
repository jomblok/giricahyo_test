<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melebarkan carbon_fund_income.source dari varchar(50) ke varchar(120).
 *
 * KENAPA: kolom ini awalnya dirancang untuk label pendek yang diketik admin
 * ("Donor", "CSR", "ESG Fund"), dan 50 karakter memang cukup untuk itu. Tapi
 * sejak sertifikat otomatis mencatatkan dirinya ke Dana Karbon, isinya bukan
 * lagi label ketikan manusia melainkan string yang dirakit sistem:
 *
 *   "Sertifikat: " + package_name
 *
 * Untuk paket Basic Carbon, package_name sendiri sudah menyertakan tingkatan
 * dan harganya — "Basic Carbon Contribution — Basic Small (Rp 20.000)", 51
 * karakter. Ditambah awalannya jadi 63 karakter, dan MySQL menolaknya dengan
 * SQLSTATE[22001] "Data too long for column 'source'". Karena penulisannya ada
 * di dalam DB::transaction bersama pembuatan sertifikat, SELURUH pembuatan
 * sertifikat ikut batal — jadi gejalanya bukan "dana tidak tercatat",
 * melainkan "sertifikat tidak bisa dibuat sama sekali".
 *
 * 120 dipilih dengan hitungan, bukan dikira-kira: package_name terpanjang yang
 * mungkin dihasilkan katalog paket saat ini 51 karakter, awalannya 12, dan
 * validasi baru di TreeAdoptionController membatasi package_name pada 150 —
 * tapi batas itu untuk menahan kiriman ngawur, bukan ukuran yang wajar. 120
 * memberi ruang lebih dari dua kali lipat kebutuhan nyata sekarang, sementara
 * tetap terasa sebagai "label", bukan kolom catatan bebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_fund_income', function (Blueprint $table) {
            $table->string('source', 120)->change();
        });
    }

    public function down(): void
    {
        // Memendekkan kembali BISA memotong data yang sudah ada. Baris apa pun
        // yang sumbernya lebih dari 50 karakter dipangkas dulu supaya rollback
        // tidak gagal di tengah jalan dengan galat yang sama.
        \Illuminate\Support\Facades\DB::table('carbon_fund_income')
            ->whereRaw('CHAR_LENGTH(source) > 50')
            ->update(['source' => \Illuminate\Support\Facades\DB::raw('LEFT(source, 50)')]);

        Schema::table('carbon_fund_income', function (Blueprint $table) {
            $table->string('source', 50)->change();
        });
    }
};
