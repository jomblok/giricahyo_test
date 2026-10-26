<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BuyerController extends Controller
{
    // GET /api/buyers
    public function index(): JsonResponse
    {
        return response()->json(Buyer::orderBy('name')->get());
    }

    // GET /api/buyers/{id} — dashboard data untuk buyer yang login
    /**
     * POST /api/buyers/{id}/share — menerbitkan (atau mengganti) token berbagi.
     *
     * Menerbitkan ulang MENCABUT tautan lama: itulah cara buyer membatalkan
     * halaman yang terlanjur tersebar. Jadi tombolnya harus mengatakan itu,
     * bukan sekadar "buat tautan".
     */
    public function terbitkanToken(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        if ($account->role !== 'admin' && !($account->role === 'buyer' && $account->linked_id === $id)) {
            return response()->json(['message' => 'Anda tidak boleh mengubah tautan berbagi ini.'], 403);
        }

        $buyer = Buyer::findOrFail($id);
        // 48 karakter hex: cukup panjang untuk tidak bisa ditebak, dan tetap
        // muat di satu baris saat tautannya disalin lewat pesan.
        $buyer->update(['share_token' => bin2hex(random_bytes(24))]);

        Audit::catat('buyer.share_token', "Menerbitkan tautan dampak publik untuk {$buyer->name}", 'buyer', $buyer->id);

        return response()->json([
            'message' => 'Tautan berbagi diterbitkan. Tautan lama tidak berlaku lagi.',
            'token'   => $buyer->share_token,
        ]);
    }

    /**
     * DELETE /api/buyers/{id}/share — mencabut tautan tanpa membuat yang baru.
     */
    public function cabutToken(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        if ($account->role !== 'admin' && !($account->role === 'buyer' && $account->linked_id === $id)) {
            return response()->json(['message' => 'Anda tidak boleh mengubah tautan berbagi ini.'], 403);
        }

        $buyer = Buyer::findOrFail($id);
        $buyer->update(['share_token' => null]);

        Audit::catat('buyer.share_revoke', "Mencabut tautan dampak publik {$buyer->name}", 'buyer', $buyer->id);

        return response()->json(['message' => 'Tautan berbagi dicabut.']);
    }

    /**
     * GET /api/dampak-publik/{token} — TANPA LOGIN.
     *
     * Yang dikirim sengaja sangat sempit: nama pendukung (yang memang ingin
     * ditampilkan oleh pemilik tautan), jumlah pohon, total CO2, dan daftar
     * jenis pohon. TIDAK ADA harga, nomor sertifikat, nama petani, koordinat,
     * atau ID pohon — halaman ini bisa dibuka siapa saja yang menerima
     * tautannya, termasuk yang diteruskan ke orang lain.
     */
    public function dampakPublik(string $token): JsonResponse
    {
        $buyer = Buyer::where('share_token', $token)->first();

        // 404, bukan 403: token yang salah tidak boleh bisa dibedakan dari
        // token yang tidak ada, supaya tidak bisa dipakai menebak.
        if (! $buyer) {
            return response()->json(['message' => 'Tautan tidak ditemukan atau sudah dicabut.'], 404);
        }

        $adopsi = \App\Models\TreeAdoption::where('buyer_id', $buyer->id)
            ->with('tree:id,species,co2_eq_kg')
            ->get();

        return response()->json([
            'buyer_name'   => $buyer->name,
            'tree_count'   => $adopsi->count(),
            'total_co2_kg' => round($adopsi->sum(fn ($a) => (float) ($a->tree->co2_eq_kg ?? 0)), 2),
            'species'      => $adopsi->pluck('tree.species')->filter()->unique()->values(),
            'since'        => $adopsi->min('adopted_date'),
            'village'      => 'Desa Giricahyo, Purwosari, Gunungkidul',
            'cooperative'  => 'Koperasi Griya Jati Rasa',
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Daftar-putih — lihat catatan panjang di FarmerController::show().
        // Cacat yang sama pernah ada di sini: akun petani memanggil
        // GET /buyers/B001 dan menerima HTTP 200 berisi email buyer beserta
        // seluruh sertifikatnya.
        $boleh = $account->role === 'admin'
              || ($account->role === 'buyer' && $account->linked_id === $id);

        if (! $boleh) {
            return response()->json(['message' => 'Anda tidak boleh mengakses data buyer ini.'], 403);
        }

        // Foto dibatasi 4 terbaru PER POHON, dan ini yang paling kritis di
        // seluruh aplikasi.
        //
        // Foto disimpan sebagai data URI base64 (~220 KB per foto), dan
        // endpoint ini memuat foto untuk SETIAP pohon yang didukung buyer
        // sekaligus. Tanpa batas, buyer dengan 10 pohon berisi 10 foto
        // menerima ~22 MB dalam satu balasan — halaman dukungan praktis tidak
        // bisa dibuka dari ponsel di lapangan.
        //
        // Empat cukup untuk kartu perkembangan di dasbor; linimasa penuh ada
        // di halaman pohonnya sendiri. Perbaikan sesungguhnya (menyimpan foto
        // sebagai berkas, bukan base64) dicatat di SECURITY.md.
        $buyer = Buyer::with([
            'adoptions.tree.farmer',
            'adoptions.tree.photos' => fn ($q) => $q->orderByDesc('measurement_date')->limit(4),
        ])->findOrFail($id);

        $adoptions = $buyer->adoptions->map(fn($a) => [
            'id'             => $a->id,
            'tree_id'        => $a->tree_id,
            'adopted_date'   => $a->adopted_date,
            'package_name'   => $a->package_name,
            // Tingkat dan harga ikut dikirim supaya kartu adopsi bisa
            // menuliskan "5 Pohon" alih-alih nama paket mentah. Nama paket
            // sendiri sama untuk ketiga tingkat ("Tree Adoption Package"),
            // jadi tanpa package_tier label di layar tidak bisa dibedakan.
            'package_tier'   => $a->package_tier,
            'price'          => $a->price,
            'certificate_no' => $a->certificate_no,
            'buyer_name'     => $a->buyer_name,
            'tree'           => $a->tree,
            'farmer'         => $a->tree?->farmer,
            'photos'         => $a->tree?->photos ?? [],
        ]);

        return response()->json([
            'buyer'       => $buyer,
            'adoptions'   => $adoptions,
            'total_co2_kg'=> round($adoptions->sum(fn($a) => $a['tree']?->co2_eq_kg ?? 0), 2),
        ]);
    }
}
