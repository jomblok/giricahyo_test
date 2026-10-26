<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\CarbonFundIncome;
use App\Models\Tree;
use App\Models\FundDistribution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CarbonFundController extends Controller
{
    // GET /api/carbon-fund/income?month=8&year=2026
    public function income(Request $request): JsonResponse
    {
        $query = CarbonFundIncome::query();

        if ($request->filled('month')) {
            $query->whereMonth('date', $request->month);
        }
        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        // Saringan rentang tanggal. Tanpa ini endpoint selalu mengembalikan
        // SELURUH isi tabel, dan tabel pemasukan tumbuh seumur hidup koperasi
        // tanpa pernah menyusut — tidak terasa pada tahun pertama, terasa
        // sekali pada tahun kelima. Pemanggil yang cuma butuh beberapa pekan
        // terakhir (lonceng notifikasi) sekarang bisa mengatakannya.
        if ($request->filled('dari')) {
            $query->whereDate('date', '>=', $request->input('dari'));
        }
        if ($request->filled('sampai')) {
            $query->whereDate('date', '<=', $request->input('sampai'));
        }

        return response()->json($query->orderByDesc('date')->get());
    }

    // POST /api/carbon-fund/income
    // total_amount SENGAJA dihitung di backend (qty x unit_price), bukan
    // menerima langsung dari client — supaya data finansial yang akan
    // diaudit tidak bisa dimanipulasi dengan mengirim angka yang tidak
    // konsisten antara qty/unit_price dan total_amount.
    public function storeIncome(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // before_or_equal:today — sebelumnya cuma `date`, jadi dana masuk
            // bisa dicatat bertanggal 2099 dan muncul di laporan keuangan
            // koperasi sebagai pemasukan yang belum pernah terjadi.
            'date'       => 'required|date|before_or_equal:today',
            'source'     => 'required|string|max:120',
            'qty'        => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ], [
            'date.required'        => 'Tanggal wajib diisi.',
            'date.before_or_equal' => 'Tanggal dana masuk tidak boleh di masa depan.',
        ]);

        $income = CarbonFundIncome::create([
            ...$validated,
            'total_amount' => $validated['qty'] * $validated['unit_price'],
        ]);

        Audit::catat('income.create', "Mencatat dana masuk {$income->source} Rp " . number_format($income->total_amount, 0, ',', '.'), 'income', (string) $income->id, [
            'date'  => $income->date,
            'total' => (float) $income->total_amount,
        ]);

        return response()->json(['message' => 'Dana masuk berhasil dicatat.', 'income' => $income], 201);
    }

    // PATCH /api/carbon-fund/income/{id}
    // Cuma untuk baris yang dicatat manual (tree_adoption_id NULL) — baris
    // yang dibuat otomatis dari sertifikat harus tetap konsisten dengan
    // sertifikatnya, jadi sengaja ditolak di sini. total_amount tetap
    // dihitung server-side, sama seperti storeIncome().
    public function updateIncome(Request $request, string $id): JsonResponse
    {
        $income = CarbonFundIncome::findOrFail($id);

        if ($income->tree_adoption_id !== null) {
            return response()->json([
                'message' => 'Data ini dibuat otomatis dari sebuah sertifikat, tidak bisa diubah di sini — batalkan/perbarui lewat menu Kelola Sertifikat.',
            ], 422);
        }

        $validated = $request->validate([
            // before_or_equal:today — sebelumnya cuma `date`, jadi dana masuk
            // bisa dicatat bertanggal 2099 dan muncul di laporan keuangan
            // koperasi sebagai pemasukan yang belum pernah terjadi.
            'date'       => 'required|date|before_or_equal:today',
            'source'     => 'required|string|max:120',
            'qty'        => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ], [
            'date.required'        => 'Tanggal wajib diisi.',
            'date.before_or_equal' => 'Tanggal dana masuk tidak boleh di masa depan.',
        ]);

        $income->update([
            ...$validated,
            'total_amount' => $validated['qty'] * $validated['unit_price'],
        ]);

        Audit::catat('income.update', "Mengubah dana masuk #{$income->id} jadi Rp " . number_format($income->total_amount, 0, ',', '.'), 'income', (string) $income->id, [
            'total_baru' => (float) $income->total_amount,
        ]);

        return response()->json(['message' => 'Dana masuk berhasil diperbarui.', 'income' => $income]);
    }

    // DELETE /api/carbon-fund/income/{id} — aturan sama seperti updateIncome().
    public function destroyIncome(string $id): JsonResponse
    {
        $income = CarbonFundIncome::findOrFail($id);

        if ($income->tree_adoption_id !== null) {
            return response()->json([
                'message' => 'Data ini dibuat otomatis dari sebuah sertifikat, tidak bisa dihapus di sini — batalkan/perbarui lewat menu Kelola Sertifikat.',
            ], 422);
        }

        $income->delete();

        Audit::catat('income.delete', "Menghapus dana masuk #{$id}", 'income', (string) $id);

        return response()->json(['message' => 'Dana masuk berhasil dihapus.']);
    }

    // GET /api/carbon-fund/distribution?month=8&year=2026
    /**
     * Pembagian dana menurut paket.
     *
     * PDF "Penguatan Manajemen Bisnis Karbon" menyebut dua pembagian berbeda:
     * paket wisata karbon dibagi 70/15/15, kemitraan ESG/CSR 80/10/10.
     * Sebelum ini sistem memakai satu pembagian untuk semua paket.
     *
     * ANGKA INI PERLU DIKONFIRMASI ULANG KE PDF SEBELUM DIPAKAI MEMBAYAR.
     * Ia diambil dari pembacaan halaman PDF yang berupa pindaian (tanpa lapisan
     * teks), jadi tidak bisa diverifikasi ulang secara otomatis seperti angka
     * lain di sistem ini. Kalau koperasi memutuskan pembagian lain, ubah di
     * sini — satu tempat, bukan tersebar.
     */
    private const PEMBAGIAN_PAKET = [
        'Carbon Tourism'  => ['petani' => 70, 'koperasi' => 15, 'operasional' => 15],
        'ESG'             => ['petani' => 80, 'koperasi' => 10, 'operasional' => 10],
        'CSR'             => ['petani' => 80, 'koperasi' => 10, 'operasional' => 10],
    ];

    /** Dipakai untuk paket yang tidak cocok dengan satu pun kunci di atas. */
    private const PEMBAGIAN_BAWAAN = ['petani' => 80, 'koperasi' => 10, 'operasional' => 10];

    private static function pembagianUntuk(?string $namaPaket): array
    {
        foreach (self::PEMBAGIAN_PAKET as $kunci => $bagian) {
            if ($namaPaket && str_contains($namaPaket, $kunci)) {
                return $bagian;
            }
        }
        return self::PEMBAGIAN_BAWAAN;
    }

    /**
     * GET /api/carbon-fund/distribution
     *
     * NOMINALNYA DIHITUNG, BUKAN DIBACA DARI TABEL. Tabel `fund_distribution`
     * menyimpan pembagian atas Rp 15.000.000 dari seeder; selama pemasukan
     * sungguhan berbeda dari angka itu — dan ia selalu berbeda — mengirim isi
     * tabel apa adanya berarti menjanjikan uang yang tidak ada.
     *
     * Persentasenya TETAP dibaca dari tabel: itu kebijakan koperasi dan memang
     * sah disimpan. Yang tidak boleh disimpan adalah rupiahnya.
     */
    /**
     * GET /api/carbon-fund/transparansi — ringkasan dana untuk PETANI.
     *
     * Seluruh endpoint dana lainnya khusus admin, dan memang seharusnya:
     * di dalamnya ada rincian per transaksi, nama pembeli, dan nominal yang
     * bisa diubah. Tapi petani adalah pihak yang pohonnya dijual jasanya —
     * mereka berhak tahu berapa uang yang masuk dan ke mana pembagiannya,
     * tanpa harus bertanya ke pengurus.
     *
     * Yang dikirim di sini hanya AGREGAT: total masuk, pembagiannya, dan
     * kontribusi kelompoknya sendiri. Tidak ada nama pembeli, tidak ada
     * nominal per transaksi, tidak ada data petani lain.
     */
    public function transparansi(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        if (! in_array($account->role, ['farmer', 'admin'], true)) {
            return response()->json(['message' => 'Halaman ini untuk petani dan pengurus koperasi.'], 403);
        }

        $tahun = (int) ($request->input('year') ?: now()->year);

        $pemasukan = CarbonFundIncome::whereYear('date', $tahun);
        $total     = (float) (clone $pemasukan)->sum('total_amount');

        // Pembagian memakai sumber yang sama dengan halaman admin, supaya
        // petani dan pengurus tidak pernah melihat dua angka berbeda untuk
        // hal yang sama.
        // SELURUH komponen pembagian, bukan satu baris terakhir: pembagian
        // itu sendiri terdiri dari beberapa pos (petani, kas koperasi, MRV),
        // dan mengirim satu di antaranya bukan "pembagian" melainkan potongan
        // yang menyesatkan.
        $bagian = FundDistribution::orderBy('id')->get();

        // Kontribusi kelompok petani ini terhadap total CO2 desa. Bukan janji
        // pembayaran — pembagian dananya diputuskan koperasi — tapi inilah
        // angka yang menjelaskan posisi mereka.
        $co2Desa = (float) Tree::sum('co2_eq_kg');
        $co2Saya = $account->role === 'farmer'
            ? (float) Tree::where('farmer_id', $account->linked_id)->sum('co2_eq_kg')
            : $co2Desa;

        return response()->json([
            'year'            => $tahun,
            'total_income'    => round($total, 2),
            'entry_count'     => (clone $pemasukan)->count(),
            'distribution'    => $bagian,
            'co2_total_kg'    => round($co2Desa, 2),
            'co2_saya_kg'     => round($co2Saya, 2),
            'porsi_co2_persen' => $co2Desa > 0 ? round($co2Saya / $co2Desa * 100, 2) : 0,
            // Dieja supaya tidak ada yang mengira angka di atas adalah nominal
            // yang akan diterima. Pembagian ke petani dihitung lewat gaji
            // karbon, yang punya rumusnya sendiri.
            'catatan' => 'Angka di atas adalah dana masuk koperasi dan pembagiannya secara keseluruhan, bukan nominal yang akan Anda terima. Pembayaran ke petani dihitung terpisah lewat Gaji Karbon.',
        ]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $incomeQuery = CarbonFundIncome::query();
        if ($request->filled('month')) {
            $incomeQuery->whereMonth('date', $request->month);
        }
        if ($request->filled('year')) {
            $incomeQuery->whereYear('date', $request->year);
        }

        // Pemasukan dikelompokkan per paket lewat RELASI (tree_adoption_id),
        // bukan dengan mencocokkan isi kolom `source` — string itu bentuknya
        // berbeda-beda antar paket dan pecah begitu nama paketnya diubah
        // sedikit. Pola yang sama dipakai kartu dana masuk.
        $baris = $incomeQuery->with('adoption:id,package_name')->get();

        $total = 0.0;
        $akumulasi = ['petani' => 0.0, 'koperasi' => 0.0, 'operasional' => 0.0];

        foreach ($baris as $row) {
            $jumlah = (float) $row->total_amount;
            $total += $jumlah;

            $bagian = self::pembagianUntuk($row->adoption?->package_name);
            foreach ($bagian as $komponen => $persen) {
                $akumulasi[$komponen] += $jumlah * $persen / 100;
            }
        }

        // Bentuk balasannya dijaga sama seperti sebelumnya (component /
        // percentage / amount) supaya kartu Distribusi Dana di frontend tidak
        // perlu diubah.
        $label = [
            'petani'      => 'Gaji Karbon Petani',
            'koperasi'    => 'Tata Kelola Koperasi',
            'operasional' => 'MRV & Operasional',
        ];

        $hasil = [];
        foreach ($akumulasi as $komponen => $nominal) {
            $hasil[] = [
                'id'         => $komponen,
                'component'  => $label[$komponen],
                // Persentase efektif: hasil campuran beberapa paket yang
                // pembagiannya berbeda, jadi tidak selalu bulat 80/10/10.
                'percentage' => $total > 0 ? round($nominal / $total * 100, 2) : 0,
                'amount'     => round($nominal, 2),
            ];
        }

        return response()->json($hasil);
    }

    // GET /api/carbon-fund/summary?month=8&year=2026
    public function summary(Request $request): JsonResponse
    {
        $incomeQuery = CarbonFundIncome::query();
        if ($request->filled('month')) {
            $incomeQuery->whereMonth('date', $request->month);
        }
        if ($request->filled('year')) {
            $incomeQuery->whereYear('date', $request->year);
        }

        $total = (float) $incomeQuery->sum('total_amount');

        return response()->json([
            'total_income' => $total,
            'by_source'    => (clone $incomeQuery)->selectRaw('source, SUM(total_amount) as total')
                                   ->groupBy('source')->get(),
            'by_package'   => self::perPaket(clone $incomeQuery),

            // Persentasenya kebijakan koperasi, jadi tetap dibaca dari tabel.
            // TAPI rupiahnya dihitung ulang dari dana masuk yang sesungguhnya —
            // kolom `amount` di tabel itu berisi angka contoh dari seeder
            // (Rp 15.000.000, mengikuti Template 8 PDF) dan tidak pernah
            // diperbarui. Akibatnya kartu Distribusi Dana menampilkan pembagian
            // atas Rp 15 juta sementara dana masuk sungguhan Rp 210.000, dan
            // angkanya juga tidak ikut berubah saat filter bulan/tahun diganti.
            'distribution' => FundDistribution::orderByDesc('id')->get()
                ->map(function ($d) use ($total) {
                    $d->amount = round($total * ((float) $d->percentage / 100), 2);
                    return $d;
                }),
        ]);
    }

    // Dana masuk dikelompokkan menurut PAKET asalnya.
    //
    // Tidak memakai kolom `source` walaupun di situ ada nama paketnya: isi
    // kolom itu string bebas yang bentuknya berbeda-beda ("Sertifikat: Basic
    // Carbon Contribution — Basic Small (Rp 20.000)" lawan "Sertifikat: Tree
    // Adoption — 1 Pohon"), dan mengelompokkan dengan mencocokkan teks akan
    // pecah begitu nama paketnya diubah sedikit. Yang dipakai tautan
    // tree_adoption_id → package_name, satu-satunya hubungan yang benar.
    //
    // Baris tanpa tautan berarti dicatat manual admin (donor, CSR langsung),
    // dan itu kelompoknya sendiri — bukan dibuang, karena uangnya nyata.
    private static function perPaket($query): array
    {
        $baris = $query
            ->leftJoin('tree_adoptions', 'carbon_fund_income.tree_adoption_id', '=', 'tree_adoptions.id')
            ->selectRaw('tree_adoptions.package_name as paket, SUM(carbon_fund_income.total_amount) as total, COUNT(*) as jumlah')
            ->groupBy('tree_adoptions.package_name')
            ->get();

        $kelompok = [];
        foreach ($baris as $b) {
            $nama = self::keluargaPaket($b->paket);
            if (!isset($kelompok[$nama])) {
                $kelompok[$nama] = ['package' => $nama, 'total' => 0.0, 'count' => 0];
            }
            $kelompok[$nama]['total'] += (float) $b->total;
            $kelompok[$nama]['count'] += (int) $b->jumlah;
        }

        $hasil = array_values($kelompok);
        usort($hasil, fn ($a, $b) => $b['total'] <=> $a['total']);
        return $hasil;
    }

    // Nama paket disederhanakan ke EMPAT keluarga sesuai halaman 11 PDF.
    // Tree Adoption punya bentuk nama lama ("Tree Adoption — 5 Pohon") dan baru
    // ("Tree Adoption Package"); Basic Carbon meng-encode tingkat dan harganya
    // ke dalam namanya sendiri. Keduanya harus jatuh ke satu keluarga.
    private static function keluargaPaket(?string $nama): string
    {
        if ($nama === null || $nama === '') return 'Pencatatan Manual';
        if (str_starts_with($nama, 'Basic Carbon Contribution')) return 'Basic Carbon Contribution';
        if (str_starts_with($nama, 'Tree Adoption'))             return 'Tree Adoption';
        if (str_starts_with($nama, 'Carbon Tourism'))            return 'Carbon Tourism Experience';
        if (str_starts_with($nama, 'ESG'))                       return 'ESG/CSR Partnership';
        return $nama;
    }
}
