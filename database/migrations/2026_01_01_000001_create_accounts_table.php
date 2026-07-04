<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->string('id', 10)->primary();   // ACC001, ACC002, dst
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'farmer', 'buyer']);
            $table->string('name');
            $table->string('linked_id', 10)->nullable(); // farmer_id atau buyer_id
            $table->boolean('deactivated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
