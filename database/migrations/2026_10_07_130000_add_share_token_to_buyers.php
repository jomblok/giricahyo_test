<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token berbagi halaman dampak.
 *
 * KENAPA TOKEN, BUKAN ID BUYER. Alternatif yang jelas adalah membuat halaman
 * publik di /dampak/B001. Tapi ID buyer berurutan, jadi siapa pun bisa
 * menelusuri B001, B002, B003 dan memanen nama seluruh pendukung koperasi
 * beserta berapa pohon yang mereka danai. Itu bukan data yang mereka setujui
 * untuk disiarkan hanya karena satu di antaranya ingin membagikan miliknya.
 *
 * Token acak memutus hubungan itu: halaman hanya bisa dibuka oleh yang
 * dikirimi tautannya, dan buyer bisa mencabutnya kapan saja dengan menerbitkan
 * token baru.
 *
 * NULL = belum pernah dibagikan, dan halamannya tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn('share_token');
        });
    }
};
