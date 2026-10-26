<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_photos', function (Blueprint $table) {
            // Ubah photo_url menjadi LONGTEXT untuk menampung base64 foto kamera
            $table->longText('photo_url')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tree_photos', function (Blueprint $table) {
            $table->text('photo_url')->change();
        });
    }
};
