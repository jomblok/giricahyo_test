<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Penerbit ID berurutan untuk tabel yang kunci utamanya berupa teks berpola
 * (F009, B002, ACC011).
 *
 * Alasan lengkap kenapa pola lama rusak ada di migrasi
 * create_id_sequences_table. Ringkasnya: membaca ID tertinggi dari tabelnya
 * sendiri salah dua kali — urutannya teks (jadi 'F999' > 'F1000'), dan
 * pembacaannya tidak dikunci (jadi dua permintaan serentak menerbitkan ID
 * yang sama).
 *
 * Kelas ini menyalin pola yang sudah terbukti di Tree::generateId().
 */
class IdSequence
{
    /**
     * Mengambil nomor berikutnya dan langsung menyimpannya.
     *
     * WAJIB dipanggil di dalam transaksi: lockForUpdate() hanya menahan baris
     * selama transaksi berjalan. Di luar transaksi, kuncinya dilepas begitu
     * kueri selesai dan dua permintaan serentak tetap bisa membaca nilai yang
     * sama — persis bug yang hendak diperbaiki.
     */
    public static function berikutnya(string $nama, string $prefix, int $panjang = 3): string
    {
        $baris = DB::table('id_sequences')
            ->where('name', $nama)
            ->lockForUpdate()
            ->first();

        if ($baris) {
            $nomor = $baris->last_no + 1;
            DB::table('id_sequences')
                ->where('name', $nama)
                ->update(['last_no' => $nomor, 'updated_at' => now()]);
        } else {
            // Baris pencatatnya belum ada (mis. jenis ID baru ditambahkan
            // setelah migrasi). Dimulai dari 1, bukan gagal.
            $nomor = 1;
            DB::table('id_sequences')->insert([
                'name'       => $nama,
                'last_no'    => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $prefix . str_pad((string) $nomor, $panjang, '0', STR_PAD_LEFT);
    }
}
