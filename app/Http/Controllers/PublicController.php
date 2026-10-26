<?php

namespace App\Http\Controllers;

use App\Models\Tree;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Endpoint yang sengaja dibuka tanpa login.
 *
 * Dipisah ke controller sendiri — BUKAN ditambahkan ke DashboardController —
 * supaya permukaan publik aplikasi ini bisa dibaca dalam satu berkas. Di
 * DashboardController semua method dijaga 'jwt:admin'; menyisipkan satu method
 * publik di sana membuat siapa pun yang menambah method berikutnya berasumsi
 * dirinya ikut terjaga, padahal tidak. Nama berkas ini yang menyatakan
 * jaminannya: apa pun di sini terlihat oleh publik.
 */
class PublicController extends Controller
{
    // GET /api/public/stats — angka agregat untuk halaman login.
    //
    // HANYA tiga hitungan. Tidak ada nama petani, tidak ada titik GPS, tidak
    // ada nilai rupiah, tidak ada data pembeli — tidak satu pun yang bisa
    // ditelusuri balik ke perorangan. Batas itu disengaja: halaman login
    // dilihat siapa saja, termasuk yang tidak punya akun.
    public function stats(): JsonResponse
    {
        // Di-cache 5 menit. Tanpa ini, endpoint tanpa login berarti tiga
        // agregasi tabel penuh setiap kali ada yang membuka halaman login —
        // termasuk kalau ada yang menyegarkannya terus-menerus. Angkanya
        // sendiri berubah hitungan minggu (saat survei baru diimpor), jadi
        // basi 5 menit tidak ada ruginya.
        $stats = Cache::remember('public.stats', 300, fn () => [
            'total_trees'   => Tree::count(),
            'total_co2_kg'  => round((float) Tree::sum('co2_eq_kg'), 2),
            'total_species' => Tree::distinct()->count('species'),

            // Porsi CO2 tiap spesies terhadap total, urut menaik — ANGKA SAJA,
            // tanpa nama spesiesnya.
            //
            // Dipakai halaman login untuk menggambar lingkaran tahun di latar:
            // satu cincin mewakili satu spesies, dan jaraknya mengikuti porsi
            // ini. Sebelumnya jarak cincin dihasilkan Math.sin() — pola yang
            // kelihatan organik tapi tidak berarti apa-apa. Sekarang bentuk
            // latar itu adalah komposisi hutannya sendiri.
            //
            // Namanya sengaja tidak ikut dikirim. Bukan karena nama spesies
            // rahasia, melainkan karena endpoint ini terbuka tanpa login dan
            // aturannya di berkas ini: yang keluar hanya yang tidak bisa
            // ditelusuri balik ke siapa pun. Deretan angka tanpa label memenuhi
            // kebutuhan gambarnya tanpa menambah apa pun yang bisa dibaca
            // sebagai informasi tentang kebun siapa.
            'species_co2_share' => self::porsiSpesies(),
        ]);

        return response()->json($stats);
    }

    private static function porsiSpesies(): array
    {
        $per = Tree::selectRaw('species, SUM(co2_eq_kg) as total')
            ->groupBy('species')
            ->pluck('total')
            ->map(fn ($v) => (float) $v)
            ->all();

        $jumlah = array_sum($per);
        if ($jumlah <= 0) return [];

        sort($per);
        // Dibulatkan 5 angka di belakang koma: cukup untuk menggambar, dan
        // menjaga balasannya tetap ringkas.
        return array_map(fn ($v) => round($v / $jumlah, 5), $per);
    }
}
