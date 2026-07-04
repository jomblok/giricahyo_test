<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gaji karbon per petani per periode (kuartalan / tahunan).
        // Formula:
        //   base_salary = (total_co2_kg / total_co2_komunitas) * dana_bersih_petani
        //   final_salary = base_salary * (1 + social% + ecological% + participation%)
        Schema::create('carbon_salaries', function (Blueprint $table) {
            $table->id();
            $table->string('farmer_id', 10);
            $table->string('period', 10);                       // e.g. "2026-Q2"
            $table->decimal('total_co2_kg', 10, 2);
            $table->decimal('co2_proportion_pct', 5, 2);        // % dari total komunitas
            $table->decimal('base_salary', 14, 2);
            $table->decimal('social_incentive_pct', 5, 2)->default(0);
            $table->decimal('ecological_incentive_pct', 5, 2)->default(0);
            $table->decimal('participation_incentive_pct', 5, 2)->default(0);
            $table->decimal('final_salary', 14, 2);

            $table->foreign('farmer_id')->references('id')->on('farmers');
            $table->unique(['farmer_id', 'period']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_salaries');
    }
};
