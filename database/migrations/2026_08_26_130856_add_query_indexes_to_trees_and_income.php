<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk kolom yang dipakai memfilter, tapi belum ter-index.
 *
 * Saat ini baru 179 pohon, jadi MySQL memindai seluruh tabel pun masih terasa
 * cepat — index ini disiapkan untuk pertumbuhan datanya, bukan untuk mengejar
 * selisih milidetik hari ini. Kolom yang dipilih adalah yang benar-benar
 * muncul di klausa WHERE pada kode:
 *
 *   trees.health_status  → TreeController::index() (?health_status=)
 *                          + DashboardController::summary() (GROUP BY)
 *   trees.species        → TreeController::index() (?species=)
 *   carbon_fund_income.date → CarbonFundController::income()/summary()
 *                             (whereMonth/whereYear + orderByDesc)
 *
 * farmer_id dan tree_id sudah ter-index otomatis lewat foreign key, jadi tidak
 * diulang di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trees', function (Blueprint $table) {
            $table->index('health_status', 'trees_health_status_index');
            $table->index('species', 'trees_species_index');
        });

        Schema::table('carbon_fund_income', function (Blueprint $table) {
            $table->index('date', 'carbon_fund_income_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('trees', function (Blueprint $table) {
            $table->dropIndex('trees_health_status_index');
            $table->dropIndex('trees_species_index');
        });

        Schema::table('carbon_fund_income', function (Blueprint $table) {
            $table->dropIndex('carbon_fund_income_date_index');
        });
    }
};
