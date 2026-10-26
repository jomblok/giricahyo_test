<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\Species;
use App\Models\Tree;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SpeciesController extends Controller
{
    // GET /api/species
    public function index(): JsonResponse
    {
        return response()->json(Species::orderBy('name')->get());
    }

    // POST /api/species
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // max:100 — TERUKUR: tanpa batas ini, nama 5.000 karakter
            // diteruskan langsung ke kolom varchar(255) dan pelanggarannya
            // muncul sebagai galat SQL mentah (HTTP 500), bukan pesan yang
            // bisa dibaca. Angkanya disamakan dengan update() di bawah, yang
            // sudah punya batas — dua jalur yang menulis kolom sama tidak
            // boleh punya aturan berbeda.
            'name'            => 'required|string|max:100|unique:species,name',
            'scientific_name' => 'nullable|string|max:150',
            'density_rho'     => 'required|numeric|min:0.1|max:1.5',
            // Dari mana angka densitas itu diambil. Dibiarkan opsional supaya
            // jenis lama tidak mendadak tidak bisa disunting — tapi kosongnya
            // ditandai jelas di layar, bukan disembunyikan.
            'density_source'    => 'nullable|string|max:200',
            'density_reference' => 'nullable|string|max:300',
        ], [
            'name.required'        => 'Nama jenis pohon wajib diisi.',
            'name.max'             => 'Nama jenis pohon maksimal 100 karakter.',
            'name.unique'          => 'Jenis pohon dengan nama itu sudah ada.',
            'density_rho.required' => 'Densitas kayu wajib diisi.',
            'density_rho.min'      => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
            'density_rho.max'      => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
        ]);
        $species = Species::create($validated);
        return response()->json(['message' => 'Jenis pohon berhasil ditambahkan.', 'species' => $species], 201);
    }

    // DELETE /api/species/{id} — admin saja.
    //
    // trees.species BUKAN foreign key, melainkan kolom teks berisi nama
    // jenisnya (lihat migrasi create_trees_table). Artinya database TIDAK akan
    // menghalangi apa pun: baris katalognya hilang tanpa suara, sementara
    // ratusan pohon tetap menyandang nama jenis yang katalognya sudah tidak
    // ada. Akibatnya baru terasa belakangan dan sulit dilacak — pencarian
    // kerapatan kayu (ρ) di form Generate QR berhenti menemukan apa pun untuk
    // jenis itu, sehingga pohon berikutnya harus diisi ρ manual atau, lebih
    // buruk, diisi asal.
    //
    // Karena itu penjagaannya ditulis di sini. Bukan sekadar menolak: pesannya
    // menyebut ada berapa pohon yang memakainya, supaya admin tahu apa yang
    // sebenarnya menghalangi, bukan cuma "tidak bisa".
    /**
     * PATCH /api/species/{id}
     *
     * Sebelum ini jenis pohon hanya bisa ditambah dan dihapus. Densitas kayu
     * yang salah ketik berarti SELURUH pohon jenis itu salah hitung karbonnya,
     * dan satu-satunya "koreksi" yang tersedia adalah menghapus jenisnya —
     * yang justru memutus pohon yang memakainya.
     *
     * Mengubah density_rho TIDAK otomatis menghitung ulang pohon yang sudah
     * ada. Itu disengaja: pohon menyimpan density_rho-nya sendiri pada saat
     * didata, dan menghitung ulang ratusan pohon diam-diam karena satu
     * suntingan katalog adalah efek samping yang terlalu besar untuk sebuah
     * form. Jumlah pohon terdampak dikembalikan supaya pengurus tahu persis
     * apa yang perlu ditinjau.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $species = Species::findOrFail($id);

        $validated = $request->validate([
            'name'            => 'required|string|max:100|unique:species,name,' . $species->id,
            'scientific_name' => 'nullable|string|max:150',
            'density_rho'     => 'required|numeric|min:0.1|max:1.5',
            // Dari mana angka densitas itu diambil. Dibiarkan opsional supaya
            // jenis lama tidak mendadak tidak bisa disunting — tapi kosongnya
            // ditandai jelas di layar, bukan disembunyikan.
            'density_source'    => 'nullable|string|max:200',
            'density_reference' => 'nullable|string|max:300',
        ], [
            'name.required'        => 'Nama jenis pohon wajib diisi.',
            'name.unique'          => 'Jenis pohon dengan nama itu sudah ada.',
            'density_rho.required' => 'Densitas kayu wajib diisi.',
            'density_rho.min'      => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
            'density_rho.max'      => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
        ]);

        $namaLama = $species->name;
        $species->update($validated);

        // Nama jenis dipakai sebagai teks di kolom trees.species, bukan foreign
        // key. Jadi kalau namanya berubah, pohon yang memakainya harus ikut
        // diubah — kalau tidak, mereka menunjuk jenis yang tidak ada lagi.
        $terdampak = 0;
        if ($namaLama !== $species->name) {
            $terdampak = Tree::where('species', $namaLama)->update(['species' => $species->name]);
        } else {
            $terdampak = Tree::where('species', $species->name)->count();
        }

        Audit::catat('species.update', "Menyunting jenis pohon {$species->name}", 'species', (string) $species->id, [
            'nama_lama'      => $namaLama,
            'pohon_terdampak' => $terdampak,
        ]);

        return response()->json([
            'message'   => 'Jenis pohon diperbarui.',
            'species'   => $species,
            'trees_affected' => $terdampak,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $species = Species::findOrFail($id);

        $terpakai = \App\Models\Tree::where('species', $species->name)->count();
        if ($terpakai > 0) {
            return response()->json([
                'message' => "Jenis \"{$species->name}\" masih dipakai oleh {$terpakai} pohon, jadi tidak bisa dihapus. Ubah dulu jenis pohon-pohon itu, atau biarkan tetap di katalog.",
                'trees_using' => $terpakai,
            ], 409);
        }

        $nama = $species->name;
        $species->delete();

        return response()->json(['message' => "Jenis pohon \"{$nama}\" dihapus."]);
    }
}
