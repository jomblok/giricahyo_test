<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\Tree;
use App\Models\TreeAdoption;
use App\Models\CarbonFundIncome;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreeAdoptionController extends Controller
{
    // Satu-satunya paket yang boleh dibuat sendiri oleh buyer lewat katalog
    // "Pohon Tersedia untuk Diadopsi" — buyer memilih SATU pohon spesifik,
    // jadi hanya masuk akal untuk paket Tree Adoption ukuran 1 pohon. Basic
    // Carbon/Tourism/ESG tetap harus lewat admin (kontribusi via QRIS/kasir
    // di luar aplikasi, koperasi baru menerbitkan sertifikatnya di sini —
    // lihat PDF "Penguatan Manajemen Bisnis Karbon").
    //
    // KETIGA TINGKATAN Tree Adoption boleh dipilih buyer sendiri, bukan satu.
    //
    // Sebelumnya konstanta ini berisi satu nilai: 'Tree Adoption — 1 Pohon'.
    // Itu nama gaya LAMA, dari sebelum paket dipecah jadi tingkatan. Ketika
    // tingkatan diperkenalkan, halaman admin ikut diperbarui tapi jalur buyer
    // tidak — sehingga dari 13 tingkatan yang ada di katalog, buyer hanya bisa
    // menjangkau satu, dan paket 5 pohon (Rp 700.000) serta 10 pohon
    // (Rp 1.300.000) tidak bisa dibeli sama sekali dari aplikasi.
    //
    // Tourism dan ESG tetap lewat admin, dan itu bukan karena teknis:
    // harganya "mulai dari" dan dirundingkan per kesepakatan, jadi tidak ada
    // angka pasti yang bisa ditagihkan sendiri oleh pembeli.
    private const BUYER_SELF_SERVE_PACKAGE = 'Tree Adoption Package';

    /** Nama gaya lama, tetap diterima supaya tautan/aplikasi lama tidak pecah. */
    private const BUYER_SELF_SERVE_LEGACY = 'Tree Adoption — 1 Pohon';

    /** Tingkatan yang boleh dipilih buyer tanpa lewat koperasi. */
    private const BUYER_SELF_SERVE_TIERS = ['tree_1', 'tree_5', 'tree_10'];

    // GET /api/adoptions — admin saja
    public function index(): JsonResponse
    {
        $adoptions = TreeAdoption::with(['tree.farmer', 'buyer'])
                                 ->orderByDesc('adopted_date')
                                 ->get();
        return response()->json($adoptions);
    }
    /**
     * GET /api/adoptions/pohon-saya — pohon PETANI INI yang sudah diadopsi.
     *
     * Petani merawat pohon yang sertifikatnya dijual, tapi sebelum ini tidak
     * punya satu pun layar yang menunjukkan pohon mana saja yang sudah punya
     * pendukung. Itu bagian paling memotivasi dari seluruh program, dan
     * selama ini hanya terlihat oleh pengurus.
     *
     * Yang dikirim sengaja sempit: nama pendukung dan tanggalnya, TANPA harga
     * dan tanpa nomor sertifikat. Berapa yang dibayar pembeli adalah urusan
     * koperasi dengan pembeli; yang menjadi hak petani adalah tahu pohonnya
     * didukung dan oleh siapa.
     */
    public function pohonSaya(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        if ($account->role !== 'farmer') {
            return response()->json(['message' => 'Daftar ini untuk petani.'], 403);
        }

        $idPohon = Tree::where('farmer_id', $account->linked_id)->pluck('id');

        $adopsi = TreeAdoption::whereIn('tree_id', $idPohon)
            ->with('tree:id,species,co2_eq_kg,health_status')
            ->orderByDesc('adopted_date')
            ->limit(200)
            ->get()
            ->map(fn ($a) => [
                'tree_id'      => $a->tree_id,
                'species'      => $a->tree?->species,
                'co2_eq_kg'    => $a->tree?->co2_eq_kg,
                'health_status'=> $a->tree?->health_status,
                'buyer_name'   => $a->buyer_name,
                'adopted_date' => $a->adopted_date,
                'expires_at'   => $a->expires_at,
            ]);

        return response()->json([
            'adoptions'     => $adopsi,
            'total_pohon'   => $idPohon->count(),
            'sudah_diadopsi'=> $adopsi->count(),
        ]);
    }


    // POST /api/adoptions
    // Admin: bisa membuat sertifikat untuk buyer manapun, paket apapun.
    // Buyer: HANYA boleh membuat untuk dirinya sendiri (buyer_id dipaksa dari
    // akun yang login, sama seperti pola self-scoping di TreeController —
    // lihat SETUP_INSTRUCTIONS.md), dan hanya paket Tree Adoption 1 pohon.
    public function store(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');
        $isBuyer = $account->role === 'buyer';

        $rules = [
            'tree_id'      => 'required|exists:trees,id|unique:tree_adoptions,tree_id',
            // max: sebelumnya tidak ada sama sekali. Akibatnya kiriman string
            // sepanjang apa pun diteruskan langsung ke kolom varchar, dan
            // pelanggaran panjangnya baru ketahuan sebagai galat SQL mentah
            // (500) — bukan pesan validasi yang bisa dibaca. Batasnya diambil
            // dari kolomnya: package_name varchar(255), package_tier
            // varchar(255), dan source di carbon_fund_income varchar(120) yang
            // menampung 'Sertifikat: ' + package_name.
            'package_name' => 'required|string|max:100',
            'package_tier' => 'nullable|string|max:40',
            // before_or_equal:today — sebelumnya aturannya cuma `date`, jadi
            // tanggal 2099 diterima: sertifikatnya bernomor seri tahun itu
            // (GJR-CERT-2099-0001), berlaku sampai 2100, dan ikut membuat
            // baris pemasukan Dana Karbon bertanggal 2099 juga.
            // Pola ini sudah dipakai TreeReportController pada planted_date;
            // lima field tanggal lain tertinggal tanpa batas sama sekali.
            'adopted_date' => 'required|date|before_or_equal:today',
        ];
        $pesan = [
            'tree_id.required'  => 'Pohon wajib dipilih.',
            'tree_id.exists'    => 'Pohon yang dipilih tidak ada di basis data.',
            'tree_id.unique'    => 'Pohon ini sudah punya sertifikat adopsi.',
            'adopted_date.required'        => 'Tanggal adopsi wajib diisi.',
            'adopted_date.before_or_equal' => 'Tanggal adopsi tidak boleh di masa depan.',
            'buyer_id.required' => 'Pendukung wajib dipilih.',
            'buyer_id.exists'   => 'Pendukung yang dipilih tidak ada di basis data.',
        ];
        // Buyer tidak mengirim buyer_id sama sekali (dipaksa dari token);
        // admin wajib mengirimnya.
        if (!$isBuyer) {
            $rules['buyer_id'] = 'required|exists:buyers,id';
        }
        $validated = $request->validate($rules, $pesan);

        if ($isBuyer) {
            $nama = $validated['package_name'];
            $tier = $validated['package_tier'] ?? null;

            $namaBoleh = in_array($nama, [self::BUYER_SELF_SERVE_PACKAGE, self::BUYER_SELF_SERVE_LEGACY], true);

            if (! $namaBoleh) {
                throw ValidationException::withMessages([
                    'package_name' => ['Paket "' . $nama . '" hanya bisa diterbitkan koperasi. Hubungi pengurus untuk Carbon Tourism dan ESG/CSR, karena harganya dirundingkan per kesepakatan.'],
                ]);
            }

            // Nama gaya baru WAJIB menyertakan tingkat — tanpa itu harganya
            // tidak bisa ditentukan dan sertifikat terbit tanpa nilai.
            if ($nama === self::BUYER_SELF_SERVE_PACKAGE && ! in_array($tier, self::BUYER_SELF_SERVE_TIERS, true)) {
                throw ValidationException::withMessages([
                    'package_tier' => ['Pilih jumlah pohon: 1, 5, atau 10.'],
                ]);
            }

            $buyerId = $account->linked_id;
        } else {
            $buyerId = $validated['buyer_id'];
        }

        $buyer = Buyer::findOrFail($buyerId);
        $price = TreeAdoption::resolvePrice($validated['package_name'], $validated['package_tier'] ?? null);
        // Masa berlaku ditetapkan SEKARANG dan disimpan, bukan dihitung ulang
        // tiap kali ditampilkan dengan membaca nama paket — lihat catatan di
        // TreeAdoption::resolveExpiry().
        $expiresAt = TreeAdoption::resolveExpiry(
            $validated['package_name'],
            $validated['package_tier'] ?? null,
            $validated['adopted_date']
        );

        return DB::transaction(function () use ($validated, $buyer, $buyerId, $price, $expiresAt) {
            $adoption = TreeAdoption::create([
                'tree_id'      => $validated['tree_id'],
                'buyer_id'     => $buyerId,
                'buyer_name'   => $buyer->name,
                'adopted_date' => $validated['adopted_date'],
                'package_name' => $validated['package_name'],
                'package_tier' => $validated['package_tier'] ?? null,
                'price'        => $price,
                'expires_at'   => $expiresAt,
                // Tahun pada nomornya diambil dari tanggal adopsi, bukan dari
                // tanggal hari ini. Dulu keduanya dicampur — tahunnya dari
                // date('Y') sementara hitungannya dari adopted_date — sehingga
                // sertifikat bertanggal mundur (mis. adopsi Desember lalu yang
                // baru dicatat sekarang) mendapat nomor bertahun ini padahal
                // dokumennya bertahun lalu.
                'certificate_no' => TreeAdoption::generateCertNo($validated['adopted_date']),
            ]);

            // Tautkan otomatis ke Dana Karbon hanya kalau harganya dikenali —
            // ini yang menghubungkan sertifikat dengan pemasukan carbon fund
            // (sebelumnya keduanya benar-benar terpisah, admin harus mencatat
            // dana masuk secara manual tanpa tautan ke sertifikat mana pun).
            if ($price !== null) {
                CarbonFundIncome::create([
                    'date'             => $validated['adopted_date'],
                    // Panjangnya dijaga oleh dua batas yang harus tetap
                    // sejalan: package_name divalidasi maksimal 100 karakter di
                    // atas, awalan 'Sertifikat: ' 12 karakter, jadi paling
                    // panjang 112 — sementara kolom source varchar(120).
                    // Kalau salah satu angka itu diubah, periksa yang lain;
                    // dulu kolomnya 50 dan baris inilah yang membuat seluruh
                    // pembuatan sertifikat gagal (lihat migrasi
                    // widen_source_on_carbon_fund_income_table).
                    'source'           => 'Sertifikat: ' . $validated['package_name'],
                    'qty'              => 1,
                    'unit_price'       => $price,
                    'total_amount'     => $price,
                    'tree_adoption_id' => $adoption->id,
                ]);
            }

            Audit::catat('adoption.create', "Menerbitkan sertifikat {$adoption->certificate_no} untuk {$buyer->name}", 'adoption', (string) $adoption->id, [
                'tree_id'      => $adoption->tree_id,
                'package_name' => $adoption->package_name,
                'price'        => $price,
            ]);

            return response()->json([
                'message'  => 'Sertifikat adopsi berhasil dibuat.',
                'adoption' => $adoption->load(['tree', 'buyer']),
            ], 201);
        });
    }

    // PATCH /api/adoptions/{id}/verify — admin saja. Menandai data karbon
    // sertifikat ini sudah diperiksa/diverifikasi (Template 6 PDF: kolom
    // "Status: Verified"). Sebelumnya "Verified" di dashboard buyer cuma
    // teks statis, tidak pernah benar-benar ada proses di baliknya.
    public function verify(Request $request, string $id): JsonResponse
    {
        $account  = $request->attributes->get('account');
        $adoption = TreeAdoption::findOrFail($id);

        $adoption->update([
            'verified_at' => now(),
            'verified_by' => $account->id,
        ]);

        return response()->json([
            'message'  => 'Sertifikat ditandai terverifikasi.',
            'adoption' => $adoption->load(['tree', 'buyer']),
        ]);
    }

    // DELETE /api/adoptions/{id} — admin saja.
    //
    // Menghapus sertifikat TIDAK cukup menghapus satu baris. Saat diterbitkan,
    // sertifikat ikut menulis satu baris pemasukan di carbon_fund_income
    // (lihat store() di atas), dan baris itu HARUS ikut hilang.
    //
    // Foreign key-nya sengaja tidak diandalkan: constraint-nya nullOnDelete,
    // jadi kalau baris sertifikatnya dihapus begitu saja, baris dananya justru
    // BERTAHAN dengan tree_adoption_id menjadi NULL. Akibatnya uangnya tetap
    // terhitung di total Carbon Fund padahal sertifikat sumbernya sudah tidak
    // ada — dan barisnya berubah wujud jadi seolah catatan manual, sehingga
    // tidak ada apa pun yang menandainya sebagai janggal. Karena itu
    // penghapusannya ditulis eksplisit di sini, di dalam satu transaksi.
    //
    // Nomor sertifikatnya TIDAK didaur ulang — certificate_sequences menyimpan
    // nomor terakhir yang pernah dipakai, bukan menghitung baris yang tersisa.
    // Lihat TreeAdoption::generateCertNo().
    public function destroy(string $id): JsonResponse
    {
        $adoption = TreeAdoption::findOrFail($id);

        return DB::transaction(function () use ($adoption) {
            $danaTerhapus = CarbonFundIncome::where('tree_adoption_id', $adoption->id)->sum('total_amount');
            CarbonFundIncome::where('tree_adoption_id', $adoption->id)->delete();

            $nomor  = $adoption->certificate_no;
            $treeId = $adoption->tree_id;
            $adoption->delete();

            Audit::catat('adoption.delete', "Menghapus sertifikat {$nomor}", 'adoption', $id, [
                'tree_id'        => $treeId,
                'dana_dihapus'   => (float) $danaTerhapus,
            ]);

            return response()->json([
                'message'         => "Sertifikat {$nomor} dihapus.",
                'certificate_no'  => $nomor,
                // Dikembalikan supaya frontend bisa memberi tahu persis apa yang
                // ikut berubah, bukan sekadar "berhasil".
                'tree_id'         => $treeId,
                'income_removed'  => (float) $danaTerhapus,
            ]);
        });
    }

    /**
     * POST /api/adoptions/{id}/renew — perpanjang sertifikat yang akan habis.
     *
     * KENAPA MEMPERPANJANG, BUKAN MENERBITKAN SERTIFIKAT BARU. Kolom
     * `tree_adoptions.tree_id` ber-constraint `unique`: satu pohon hanya boleh
     * punya satu baris adopsi. Jadi baris kedua untuk pohon yang sama memang
     * tidak mungkin dibuat tanpa mengubah skema, dan mengubahnya akan
     * merembet ke seluruh kueri yang mengandaikan satu pohon = satu pemilik.
     *
     * Konsekuensinya yang harus disadari: riwayat perpanjangan TIDAK tersimpan
     * sebagai baris sertifikat terpisah. Yang menyimpan jejaknya adalah baris
     * `carbon_fund_income` — satu baris per pembayaran, masing-masing dengan
     * tanggalnya sendiri. Jadi "sudah berapa kali diperpanjang" dijawab dengan
     * menghitung baris pemasukan yang menunjuk sertifikat ini, bukan dari
     * tabel sertifikat.
     *
     * Masa berlakunya disambung dari tanggal habis yang LAMA, bukan dari hari
     * ini — supaya buyer yang memperpanjang lebih awal tidak kehilangan sisa
     * masa yang sudah dibayarnya.
     */
    public function renew(Request $request, int $id): JsonResponse
    {
        $account  = $request->attributes->get('account');
        $adoption = TreeAdoption::findOrFail($id);

        // Daftar-putih: admin, atau buyer pemilik sertifikat itu sendiri.
        $boleh = $account->role === 'admin'
              || ($account->role === 'buyer' && $account->linked_id === $adoption->buyer_id);

        if (! $boleh) {
            return response()->json([
                'message' => 'Anda tidak boleh memperpanjang sertifikat ini.',
            ], 403);
        }

        if (! $adoption->expires_at) {
            return response()->json([
                'message' => 'Sertifikat ini tidak punya batas waktu, jadi tidak perlu diperpanjang.',
            ], 422);
        }

        $bulan = TreeAdoption::resolveMonths($adoption->package_name, $adoption->package_tier);

        if (! $bulan) {
            return response()->json([
                'message' => "Durasi paket \"{$adoption->package_name}\" belum terdaftar, jadi perpanjangannya tidak bisa dihitung otomatis. Hubungi pengurus koperasi.",
            ], 422);
        }

        $price = TreeAdoption::resolvePrice($adoption->package_name, $adoption->package_tier);

        return DB::transaction(function () use ($adoption, $bulan, $price) {
            $mulai = \Carbon\Carbon::parse($adoption->expires_at);

            // Kalau sudah terlanjur lewat, sambungan dimulai dari hari ini —
            // kalau tidak, buyer membayar untuk masa yang sudah berlalu.
            if ($mulai->isPast()) {
                $mulai = \Carbon\Carbon::today();
            }

            $habisBaru = $mulai->copy()->addMonthsNoOverflow($bulan);

            $adoption->update(['expires_at' => $habisBaru->toDateString()]);

            if ($price !== null) {
                CarbonFundIncome::create([
                    // Awalan 'Perpanjangan: ' 14 karakter + package_name
                    // maksimal 100 = 114, masih di bawah varchar(120).
                    // Lihat catatan panjang yang sama di store().
                    'date'             => now()->toDateString(),
                    'source'           => 'Perpanjangan: ' . $adoption->package_name,
                    'qty'              => 1,
                    'unit_price'       => $price,
                    'total_amount'     => $price,
                    'tree_adoption_id' => $adoption->id,
                ]);
            }

            \App\Models\Notification::kirimKeAdmin(
                'sertifikat_diperpanjang',
                'Sertifikat diperpanjang',
                "{$adoption->buyer_name} memperpanjang {$adoption->certificate_no} sampai {$habisBaru->toDateString()}.",
                '/sertifikat'
            );

            return response()->json([
                'message'    => "Sertifikat {$adoption->certificate_no} diperpanjang sampai {$habisBaru->translatedFormat('j F Y')}.",
                'adoption'   => $adoption->fresh(),
                'expires_at' => $habisBaru->toDateString(),
            ]);
        });
    }

    /**
     * GET /api/adoptions/expiring — sertifikat yang akan habis dalam N hari.
     *
     * Dipakai dua tempat: daftar admin, dan pengingat untuk buyer. Ambangnya
     * 60 hari secara bawaan karena perpanjangan butuh waktu untuk dibicarakan
     * dengan pembeli korporat; buyer perorangan biasanya cukup 30.
     */
    public function expiring(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');
        $hari    = min(365, max(1, (int) ($request->days ?? 60)));
        $batas   = now()->addDays($hari)->toDateString();

        $query = TreeAdoption::with('tree:id,species')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $batas)
            ->orderBy('expires_at');

        if ($account->role === 'buyer') {
            $query->where('buyer_id', $account->linked_id);
        } elseif ($account->role !== 'admin') {
            return response()->json(['message' => 'Anda tidak boleh mengakses daftar ini.'], 403);
        }

        $daftar = $query->limit(200)->get();

        return response()->json([
            'adoptions' => $daftar,
            'days'      => $hari,
            // Dipisah supaya antarmuka bisa membedakan "segera habis" dari
            // "sudah telanjur habis" tanpa menghitung ulang tanggal di klien.
            'expired'   => $daftar->filter(fn ($a) => \Carbon\Carbon::parse($a->expires_at)->isPast())->count(),
        ]);
    }
}
