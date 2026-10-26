<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_photos', function (Blueprint $table) {
            $table->id();
            $table->string('tree_id', 20);
            $table->text('photo_url');
            $table->string('caption')->nullable();
            $table->date('measurement_date')->default(now()->toDateString());
            $table->decimal('dbh_cm', 8, 2)->nullable();
            $table->decimal('height_m', 8, 2)->nullable();

            $table->foreign('tree_id')->references('id')->on('trees')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_photos');
    }
};
