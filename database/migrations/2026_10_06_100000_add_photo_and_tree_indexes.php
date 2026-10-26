<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk pola kueri yang benar-benar dipakai.
 *
 * Ditambahkan setelah mengukur, bukan menebak:
 *
 * tree_photos (tree_id, measurement_date)
 *   Linimasa foto SELALU dibaca dengan pola "foto pohon ini, terbaru dulu".
 *   Tabelnya hanya punya PRIMARY dan foreign key tree_id, jadi pengurutan
 *   tanggalnya dikerjakan dengan filesort setiap kali. Masih tak terasa pada
 *   nol baris; pada ribuan foto — dan tabel ini tumbuh paling cepat karena
 *   setiap laporan lapangan berfoto menambah satu baris — ia jadi beban.
 *
 * trees (farmer_id, health_status)
 *   Dasbor petani menyaring "pohon saya" lalu menghitung per kondisi. Indeks
 *   farmer_id dan health_status sudah ada TERPISAH, dan MySQL hanya bisa
 *   memakai satu per tabel. Indeks gabungan membuat keduanya terpakai sekaligus.
 *
 * carbon_measurements (tree_id, measurement_date)
 *   TreeController::show() mengambil 5 pengukuran terbaru per pohon. Sama
 *   seperti foto: tanpa indeks ini, pengurutannya filesort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_photos', function (Blueprint $table) {
            $table->index(['tree_id', 'measurement_date'], 'tree_photos_tree_date_index');
        });

        Schema::table('trees', function (Blueprint $table) {
            $table->index(['farmer_id', 'health_status'], 'trees_farmer_health_index');
        });

        Schema::table('carbon_measurements', function (Blueprint $table) {
            $table->index(['tree_id', 'measurement_date'], 'carbon_meas_tree_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('tree_photos', function (Blueprint $table) {
            $table->dropIndex('tree_photos_tree_date_index');
        });

        Schema::table('trees', function (Blueprint $table) {
            $table->dropIndex('trees_farmer_health_index');
        });

        Schema::table('carbon_measurements', function (Blueprint $table) {
            $table->dropIndex('carbon_meas_tree_date_index');
        });
    }
};
