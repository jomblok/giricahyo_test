<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trees', function (Blueprint $table) {
            $table->string('id', 20)->primary();        // GJR-001-001
            $table->string('farmer_id', 10);
            $table->string('species', 50);
            $table->decimal('dbh_cm', 5, 2);            // Diameter setinggi dada (cm)
            $table->decimal('height_m', 5, 2);          // Tinggi (m)
            $table->decimal('density_rho', 4, 2);       // Kerapatan kayu ρ (g/cm³)
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->enum('health_status', ['baik', 'sedang', 'buruk'])->default('baik');
            $table->date('planted_date');
            $table->date('last_updated');
            $table->decimal('co2_eq_kg', 10, 2)->default(0); // Cache hasil formula CO₂

            $table->foreign('farmer_id')->references('id')->on('farmers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trees');
    }
};
