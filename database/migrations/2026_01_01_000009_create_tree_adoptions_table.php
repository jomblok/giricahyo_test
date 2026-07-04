<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_adoptions', function (Blueprint $table) {
            $table->id();
            $table->string('tree_id', 20)->unique();    // 1 pohon hanya 1 adopsi aktif
            $table->string('buyer_id', 10);
            $table->string('buyer_name');               // Denormalisasi untuk kemudahan tampil
            $table->date('adopted_date');
            $table->string('package_name');             // "Tree Adoption — 1 Pohon", dll
            $table->string('certificate_no')->unique(); // GJR-CERT-2026-0001

            $table->foreign('tree_id')->references('id')->on('trees');
            $table->foreign('buyer_id')->references('id')->on('buyers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_adoptions');
    }
};
