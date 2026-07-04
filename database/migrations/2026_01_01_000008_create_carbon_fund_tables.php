<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pemasukan carbon fund dari berbagai sumber
        // (guest, ESG, CSR, carbon tourism, donor, dll.)
        Schema::create('carbon_fund_income', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('source', 50);       // "ESG Fund", "CSR", "Donor", dst
            $table->integer('qty')->default(1); // jumlah unit/orang/paket
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_amount', 14, 2);
            $table->timestamps();
        });

        // Distribusi alokasi dana per komponen per periode
        Schema::create('fund_distribution', function (Blueprint $table) {
            $table->id();
            $table->string('period', 10);           // e.g. "2026-Q2"
            $table->string('component', 50);        // "Gaji Karbon Petani", "Operasional", dst
            $table->decimal('percentage', 5, 2);    // % dari total dana
            $table->decimal('amount', 14, 2);       // nominal Rupiah
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_distribution');
        Schema::dropIfExists('carbon_fund_income');
    }
};
