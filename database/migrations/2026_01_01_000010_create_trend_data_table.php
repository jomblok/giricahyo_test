<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregat tahunan untuk grafik tren di dashboard admin.
        // Bisa diisi manual atau dengan scheduled job tiap akhir tahun.
        Schema::create('trend_data', function (Blueprint $table) {
            $table->id();
            $table->year('year')->unique();
            $table->decimal('total_co2_ton', 10, 2)->default(0);
            $table->integer('certificates_sold')->default(0);
            $table->integer('total_trees')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_data');
    }
};
