<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_fund_income', function (Blueprint $table) {
            // NULL = dicatat manual oleh admin (bisa diedit/dihapus).
            // Terisi = dibuat otomatis saat sertifikat diterbitkan (lihat
            // TreeAdoptionController::store()) — tidak boleh diubah lewat
            // form dana masuk biasa, harus lewat sertifikatnya.
            $table->foreignId('tree_adoption_id')
                ->nullable()
                ->after('id')
                ->constrained('tree_adoptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carbon_fund_income', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tree_adoption_id');
        });
    }
};
