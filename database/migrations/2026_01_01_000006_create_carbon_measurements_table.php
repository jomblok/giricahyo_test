<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historis pengukuran karbon per pohon.
        // Setiap kali pohon diukur ulang (tahunan), simpan satu baris baru
        // sehingga tren pertumbuhan bisa dilihat per pohon.
        Schema::create('carbon_measurements', function (Blueprint $table) {
            $table->id();
            $table->string('tree_id', 20);
            $table->date('measurement_date');
            $table->decimal('agb_kg', 10, 2);           // Above-Ground Biomass
            $table->decimal('bgb_kg', 10, 2);           // Below-Ground Biomass
            $table->decimal('total_biomass_kg', 10, 2);
            $table->decimal('carbon_stock_kg', 10, 2);
            $table->decimal('co2_eq_kg', 10, 2);        // CO₂ equivalent

            $table->foreign('tree_id')->references('id')->on('trees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_measurements');
    }
};
