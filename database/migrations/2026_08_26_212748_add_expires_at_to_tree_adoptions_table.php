<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\TreeAdoption;

/**
 * Tanggal berakhirnya masa berlaku sertifikat.
 *
 * Sebelumnya masa berlaku hanya ada di frontend, dihitung dengan MEMBACA TEKS
 * package_name ("Basic Carbon Contribution — Basic Plus"). Dua akibatnya:
 * hanya paket Basic Carbon yang punya masa berlaku, dan begitu nama paket
 * diubah sedikit saja perhitungannya berhenti bekerja tanpa error.
 *
 * Dengan disimpan di sini, tanggalnya ditetapkan sekali saat sertifikat
 * dibuat dan tidak bisa berubah sendiri kalau penamaan paket direvisi.
 * Nullable: paket yang memang tidak berbatas waktu tetap boleh kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_adoptions', function (Blueprint $table) {
            $table->date('expires_at')->nullable()->after('adopted_date');
            $table->index('expires_at', 'tree_adoptions_expires_at_index');
        });

        // Isi ulang sertifikat yang sudah ada memakai aturan durasi yang sama.
        TreeAdoption::whereNull('expires_at')->get()->each(function ($a) {
            $exp = TreeAdoption::resolveExpiry($a->package_name, $a->package_tier, $a->adopted_date);
            if ($exp) DB::table('tree_adoptions')->where('id', $a->id)->update(['expires_at' => $exp]);
        });
    }

    public function down(): void
    {
        Schema::table('tree_adoptions', function (Blueprint $table) {
            $table->dropIndex('tree_adoptions_expires_at_index');
            $table->dropColumn('expires_at');
        });
    }
};
