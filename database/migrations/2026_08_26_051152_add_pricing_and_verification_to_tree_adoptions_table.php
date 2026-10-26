<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_adoptions', function (Blueprint $table) {
            // Harga sesungguhnya dari paket/tingkat yang dipilih — sebelumnya
            // tidak pernah tersimpan sama sekali (Basic Carbon meng-encode
            // harga ke dalam string package_name, paket lain tidak menyimpan
            // harga sama sekali). Nullable supaya baris lama tidak terganggu.
            $table->string('package_tier')->nullable()->after('package_name');
            $table->decimal('price', 12, 2)->nullable()->after('package_tier');

            // Verifikasi data karbon/sertifikat — sebelumnya "Verified" di
            // dashboard buyer cuma teks statis, bukan status sungguhan.
            $table->timestamp('verified_at')->nullable()->after('certificate_no');
            $table->string('verified_by', 10)->nullable()->after('verified_at');
        });

        // Backfill non-destruktif untuk data yang sudah ada: hanya baris
        // dengan package_name PERSIS "Tree Adoption — 1 Pohon" (harga tetap,
        // tidak perlu tier) yang diisi. Baris Basic Carbon lama (dengan atau
        // tanpa tier ter-encode di package_name) sengaja dibiarkan NULL —
        // frontend sudah punya fallback baca dari package_name lewat
        // lib/basicCarbon.js untuk kasus itu.
        DB::table('tree_adoptions')
            ->where('package_name', 'Tree Adoption — 1 Pohon')
            ->update(['price' => 150000]);
    }

    public function down(): void
    {
        Schema::table('tree_adoptions', function (Blueprint $table) {
            $table->dropColumn(['package_tier', 'price', 'verified_at', 'verified_by']);
        });
    }
};
