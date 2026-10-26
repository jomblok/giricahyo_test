<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan MASUKAN pengukuran, bukan cuma hasilnya.
 *
 * Sebelum ini carbon_measurements menyimpan agb_kg, bgb_kg, total_biomass_kg,
 * carbon_stock_kg dan co2_eq_kg — seluruhnya KELUARAN rumus Chave et al. 2014.
 * Yang tidak tersimpan justru yang dimasukkan ke rumus itu: diameter, tinggi,
 * dan densitas kayu.
 *
 * Akibatnya tidak satu pun angka karbon di riwayat bisa diturunkan ulang.
 * Kalau penguji atau calon pembeli bertanya "dari mana 706,27 kg untuk pohon
 * ini di Juni 2026", jawabannya tidak ada di basis data. Nilai dbh dan tinggi
 * memang ada di tabel trees, tapi itu nilai TERKINI — begitu pohon diukur
 * ulang, angka yang melahirkan pengukuran lama tertimpa dan hilang.
 *
 * Itulah yang membedakan sistem MRV dari basis data pohon biasa: rantai utuh
 * dari angka yang diukur di lapangan sampai klaim karbon yang dijual. Tanpa
 * kolom-kolom ini, huruf R (Reporting) dan V (Verification) tidak punya dasar.
 *
 * `source` mencatat BAGAIMANA pengukuran masuk, bukan sekadar siapa. Untuk
 * baris hasil impor tidak ada akun yang bertanggung jawab, dan memaksakan
 * satu hanya akan mengarang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_measurements', function (Blueprint $table) {
            // Nullable: baris lama diisi lewat backfill di bawah, dan baris
            // dari sumber yang tidak punya angkanya tidak boleh dipaksa.
            $table->decimal('dbh_cm', 8, 2)->nullable()->after('measurement_date');
            $table->decimal('height_m', 6, 2)->nullable()->after('dbh_cm');
            $table->decimal('density_rho', 4, 2)->nullable()->after('height_m');

            // Siapa yang mencatat. Nama disalin, bukan di-join: catatan
            // pengukuran harus tetap terbaca walau akunnya kemudian dihapus —
            // alasan yang sama dengan audit_logs.
            $table->string('recorded_by', 20)->nullable()->after('co2_eq_kg');
            $table->string('recorded_by_name', 100)->nullable()->after('recorded_by');

            // impor_survei | pendaftaran | foto | laporan_petani | koreksi_admin
            $table->string('source', 24)->nullable()->after('recorded_by_name');

            $table->index('source');
        });

        // BACKFILL. Ke-179 baris yang ada seluruhnya berasal dari impor survei
        // lapangan, dan belum satu pohon pun diukur ulang (terverifikasi: 179
        // pohon, 179 pengukuran, tepat satu per pohon). Jadi nilai dbh/tinggi/
        // densitas yang sekarang ada di tabel trees MASIH nilai yang melahirkan
        // pengukuran itu, dan menyalinnya ke sini benar.
        //
        // Kalau sudah pernah ada pengukuran kedua, penyalinan ini akan salah
        // untuk baris lama — karena itu syaratnya diperiksa, bukan diasumsikan.
        $gandaAda = DB::table('carbon_measurements')
            ->select('tree_id')
            ->groupBy('tree_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $gandaAda) {
            DB::statement('
                UPDATE carbon_measurements m
                JOIN trees t ON t.id = m.tree_id
                SET m.dbh_cm      = t.dbh_cm,
                    m.height_m    = t.height_m,
                    m.density_rho = t.density_rho,
                    m.source      = "impor_survei"
                WHERE m.dbh_cm IS NULL
            ');
        } else {
            // Ada pohon dengan lebih dari satu pengukuran: nilai di tabel trees
            // tidak lagi mewakili baris yang mana pun secara pasti. Dibiarkan
            // NULL supaya "tidak diketahui" jujur, bukan ditebak.
            DB::table('carbon_measurements')->whereNull('source')->update(['source' => 'tidak_tercatat']);
        }
    }

    public function down(): void
    {
        Schema::table('carbon_measurements', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['dbh_cm', 'height_m', 'density_rho', 'recorded_by', 'recorded_by_name', 'source']);
        });
    }
};
