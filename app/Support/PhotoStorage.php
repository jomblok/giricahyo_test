<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan foto pohon sebagai BERKAS, bukan base64 di basis data.
 *
 * MASALAH YANG DIPECAHKAN. Foto sebelumnya disimpan utuh sebagai data URI di
 * kolom `longtext`. Satu foto hasil kompresi ~220 KB, dan base64 menambah ~33%
 * lagi di atas ukuran aslinya. Akibatnya terukur:
 *
 *   1.000 foto  ->  ~215 MB di dalam MySQL
 *   5.000 foto  ->  ~1 GB
 *
 * Itu bukan sekadar boros. Basis data sebesar itu membuat setiap `mysqldump`
 * berjam-jam, setiap pemulihan berisiko, dan setiap `SELECT *` yang lupa
 * mengecualikan kolomnya menarik ratusan megabita. Terukur juga: satu balasan
 * `GET /trees/{id}` membengkak dari 1,1 KB jadi 2,2 MB hanya dengan 5 foto.
 *
 * KENAPA SEKARANG. Tabel `tree_photos` saat ini berisi NOL baris. Mengubah
 * cara penyimpanan hari ini tidak perlu memigrasi apa pun; menundanya sampai
 * ada ribuan foto berarti memindahkan ratusan megabita sambil sistem berjalan.
 *
 * TETAP MENERIMA BENTUK LAMA. Kolom `photo_url` sekarang bisa berisi tiga hal:
 * path berkas (baru), data URI base64 (lama), atau URL http(s) ke luar. Ketiga
 * bentuk itu sama-sama sah di atribut `src` sebuah <img>, jadi antarmuka tidak
 * perlu tahu bedanya — dan foto lama, kalau nanti ada, tidak rusak.
 */
class PhotoStorage
{
    /** Disk publik, folder foto pohon. */
    private const FOLDER = 'foto-pohon';

    /**
     * Mengubah data URI base64 jadi berkas, lalu mengembalikan URL-nya.
     *
     * Masukan yang BUKAN data URI (URL http/https dari luar) dikembalikan apa
     * adanya — tidak ada gunanya mengunduh lalu menyimpan ulang gambar yang
     * sudah punya alamat sendiri, dan melakukannya justru menjadikan server
     * ini perantara yang bisa dipakai memuat berkas dari mana saja.
     */
    public static function simpan(string $isi, string $treeId): string
    {
        if (! str_starts_with(strtolower($isi), 'data:image/')) {
            return $isi;
        }

        if (! preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,(.+)$/is', $isi, $cocok)) {
            // Bentuknya mengaku data URI gambar tapi tidak bisa diurai.
            // Dikembalikan apa adanya supaya validasi di controller yang
            // menolaknya, bukan kelas ini yang melempar galat teknis ke
            // pengguna.
            return $isi;
        }

        $ekstensi = strtolower($cocok[1]) === 'jpeg' ? 'jpg' : strtolower($cocok[1]);
        $biner = base64_decode($cocok[2], true);

        if ($biner === false || $biner === '') {
            return $isi;
        }

        // Isinya diperiksa, bukan cuma label yang diklaim pengirim.
        //
        // Sebelum ini validasinya berhenti pada awalan data URI. Terbukti:
        // sebuah skrip PHP yang di-base64 lalu diberi awalan
        // "data:image/png;base64," diterima HTTP 201 dan tersimpan di disk
        // sebagai .png yang isinya justru kode PHP. Ekstensinya
        // mengikuti klaim pengirim, jadi di setup biasa PHP tidak
        // mengeksekusi .png — tapi menyimpan byte sembarang berlabel gambar
        // tetap salah, dan ini berjalan di XAMPP.
        //
        // getimagesizefromstring() membaca header gambar sungguhan; apa pun
        // yang bukan gambar mengembalikan false.
        $ukuran = @getimagesizefromstring($biner);
        if ($ukuran === false) {
            throw ValidationException::withMessages([
                'photo_url' => ['Berkas yang dikirim bukan gambar yang bisa dibaca.'],
            ]);
        }

        // Tipe yang SEBENARNYA harus cocok dengan yang diklaim. Tanpa ini,
        // GIF berlabel png lolos — perbedaan kecil, tapi artinya label di
        // data URI masih menentukan nama berkas sementara isinya lain.
        $tipeAsli = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
        ][$ukuran[2]] ?? null;

        if ($tipeAsli === null || $tipeAsli !== $ekstensi) {
            throw ValidationException::withMessages([
                'photo_url' => ['Jenis gambar tidak cocok dengan isinya.'],
            ]);
        }

        // Nama berkas memuat ID pohon supaya mudah ditelusuri saat memeriksa
        // folder langsung, dan sebuah string acak supaya dua unggahan untuk
        // pohon yang sama pada detik yang sama tidak saling menimpa.
        $nama = sprintf(
            '%s/%s_%s.%s',
            self::FOLDER,
            preg_replace('/[^A-Za-z0-9\-]/', '', $treeId),
            Str::lower(Str::random(10)),
            $ekstensi
        );

        Storage::disk('public')->put($nama, $biner);

        // URL penuh, bukan path relatif: frontend berjalan di origin yang
        // BERBEDA dari API (5173 vs 8000), jadi path relatif akan menunjuk ke
        // server frontend yang tidak punya berkasnya.
        return Storage::disk('public')->url($nama);
    }

    /**
     * Menghapus berkas yang pernah disimpan di sini.
     *
     * Aman dipanggil untuk nilai apa pun: data URI lama dan URL luar diabaikan
     * karena tidak ada berkas milik kita untuk dihapus. Tanpa penjagaan itu,
     * menghapus pohon berfoto lama akan melempar galat.
     */
    public static function hapus(?string $photoUrl): void
    {
        if (! $photoUrl) {
            return;
        }

        $tanda = '/storage/' . self::FOLDER . '/';
        $posisi = strpos($photoUrl, $tanda);

        if ($posisi === false) {
            return;
        }

        $relatif = self::FOLDER . '/' . substr($photoUrl, $posisi + strlen($tanda));

        if (Storage::disk('public')->exists($relatif)) {
            Storage::disk('public')->delete($relatif);
        }
    }
}
