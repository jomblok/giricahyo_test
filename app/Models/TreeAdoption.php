<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TreeAdoption extends Model
{
    protected $table    = 'tree_adoptions';
    protected $fillable = [
        'tree_id','buyer_id','buyer_name','adopted_date','package_name','package_tier',
        'price','certificate_no','verified_at','verified_by','expires_at',
    ];
    protected $casts = [
        'price'       => 'decimal:2',
        'verified_at' => 'datetime',
        'expires_at'  => 'date',
    ];

    public function tree(): BelongsTo { return $this->belongsTo(Tree::class, 'tree_id'); }
    public function buyer(): BelongsTo { return $this->belongsTo(Buyer::class, 'buyer_id'); }

    // Generate nomor sertifikat otomatis: GJR-CERT-{TAHUN}-{SEQ}
    // Nomor diambil dari tabel certificate_sequences, BUKAN dari menghitung
    // baris yang ada. Alasan lengkapnya ada di migrasi
    // create_certificate_sequences_table — ringkasnya: menghitung baris membuat
    // nomor terpakai ulang setelah sertifikat dihapus, dan memberi nomor kembar
    // kalau dua admin menekan tombol bersamaan.
    //
    // lockForUpdate() mengunci baris tahun ini sampai transaksi selesai,
    // sehingga pemanggil kedua menunggu dan membaca nilai yang sudah naik.
    // Pemanggilnya (TreeAdoptionController::store) sudah berada di dalam
    // DB::transaction, jadi kuncinya dilepas bersamaan dengan commit-nya.
    public static function generateCertNo(?string $adoptedDate = null): string
    {
        $year = (int) date('Y', strtotime($adoptedDate ?: 'now'));

        $baris = DB::table('certificate_sequences')->where('year', $year)->lockForUpdate()->first();

        if ($baris === null) {
            DB::table('certificate_sequences')->insert([
                'year'       => $year,
                'last_no'    => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nomor = 1;
        } else {
            $nomor = $baris->last_no + 1;
            DB::table('certificate_sequences')
                ->where('year', $year)
                ->update(['last_no' => $nomor, 'updated_at' => now()]);
        }

        return sprintf('GJR-CERT-%s-%04d', $year, $nomor);
    }

    // Harga resmi per tingkat/paket — satu sumber kebenaran di backend,
    // TIDAK PERNAH dipercaya dari input client (sama seperti total_amount di
    // CarbonFundController::storeIncome()). Angka mengikuti PDF "Penguatan
    // Manajemen Bisnis Karbon": Basic Carbon 3 tingkat, Tree Adoption 3
    // ukuran (harga tetap, tanpa tier terpisah), Carbon Tourism Experience
    // dan ESG/CSR Partnership masing-masing diberi RENTANG harga di PDF —
    // yang disimpan di sini adalah BATAS BAWAH tiap rentang sebagai angka
    // tunggal yang pasti.
    //
    // Kunci tier SENGAJA unik lintas seluruh paket (small/standard/plus,
    // half_day/full_day/premium, bronze/silver/gold/platinum) supaya lookup
    // ini bisa tetap satu tabel datar tanpa perlu mencocokkan package_name
    // yang string-nya bisa berubah-ubah (mis. Basic Carbon meng-encode tier
    // & harga ke dalam package_name itu sendiri).
    private const TIER_PRICES = [
        // Package 1 — Basic Carbon Contribution (lib/basicCarbon.js)
        'small'    => 20000,
        'standard' => 50000,
        'plus'     => 100000,
        // Package 2 — Tree Adoption (halaman 13 PDF). Harga PASTI, bukan
        // rentang. Sebelumnya ketiga ukuran ini hanya ada di
        // FIXED_PACKAGE_PRICES di bawah dan dikenali lewat NAMA paketnya,
        // sehingga Tree Adoption jadi satu-satunya paket tanpa tingkat.
        'tree_1'   => 150000,
        'tree_5'   => 700000,
        'tree_10'  => 1300000,
        // Package 3 — Carbon Tourism Experience (batas bawah tiap rentang)
        'half_day' => 250000,
        'full_day' => 500000,
        'premium'  => 1000000,
        // Package 4 — ESG/CSR Partnership (batas bawah tiap rentang)
        'bronze'   => 5000000,
        'silver'   => 25000000,
        'gold'     => 100000000,
        'platinum' => 500000000,
    ];

    // Nama paket Tree Adoption gaya LAMA, dari sebelum ketiga ukurannya
    // dijadikan tingkat. DIPERTAHANKAN dan tidak boleh dihapus: sertifikat yang
    // sudah terbit menyimpan nama ini di kolom package_name, dan tanpa tabel
    // ini harganya berubah jadi null saat ditampilkan ulang.
    private const FIXED_PACKAGE_PRICES = [
        'Tree Adoption — 1 Pohon'  => 150000,
        'Tree Adoption — 5 Pohon'  => 700000,
        'Tree Adoption — 10 Pohon' => 1300000,
    ];

    public static function resolvePrice(string $packageName, ?string $tier): ?int
    {
        if ($tier !== null && isset(self::TIER_PRICES[$tier])) {
            return self::TIER_PRICES[$tier];
        }
        return self::FIXED_PACKAGE_PRICES[$packageName] ?? null;
    }

    // ── Masa berlaku sertifikat ──────────────────────────────────────────────
    //
    // ANGKA SEMENTARA — ditetapkan agar fitur masa berlaku bisa berjalan untuk
    // SEMUA paket, bukan hanya Basic Carbon. Koperasi perlu memastikan/mengubah
    // durasinya sesuai kesepakatan yang sebenarnya; cukup ubah di tabel ini,
    // tidak ada tempat lain yang menyimpan durasi.
    //
    // Alasan angka yang dipilih:
    //   Basic Carbon  — sudah ditetapkan sebelumnya (1/3/6 bulan)
    //   Tree Adoption — 12 bulan, adopsi pohon lazimnya per tahun
    //   Carbon Tourism— 12 bulan, kunjungannya boleh ditukar dalam setahun
    //   ESG Partnership — 12 bulan, kemitraan korporat umumnya kontrak tahunan
    private const TIER_MONTHS = [
        'small' => 1, 'standard' => 3, 'plus' => 6,
        'tree_1' => 12, 'tree_5' => 12, 'tree_10' => 12,
        'half_day' => 12, 'full_day' => 12, 'premium' => 12,
        'bronze' => 12, 'silver' => 12, 'gold' => 12, 'platinum' => 12,
    ];

    private const FIXED_PACKAGE_MONTHS = [
        'Tree Adoption — 1 Pohon'  => 12,
        'Tree Adoption — 5 Pohon'  => 12,
        'Tree Adoption — 10 Pohon' => 12,
        'Tree Adoption Package'    => 12,
    ];

    public static function resolveMonths(string $packageName, ?string $tier): ?int
    {
        if ($tier !== null && isset(self::TIER_MONTHS[$tier])) {
            return self::TIER_MONTHS[$tier];
        }
        if (isset(self::FIXED_PACKAGE_MONTHS[$packageName])) {
            return self::FIXED_PACKAGE_MONTHS[$packageName];
        }
        // Basic Carbon meng-encode tier ke dalam package_name-nya sendiri
        // (mis. "Basic Carbon Contribution — Basic Plus"), jadi tier-nya
        // dicocokkan dari situ sebagai cadangan.
        foreach (['Basic Small' => 'small', 'Basic Standard' => 'standard', 'Basic Plus' => 'plus'] as $label => $key) {
            if (str_contains($packageName, $label)) return self::TIER_MONTHS[$key];
        }
        return null;
    }

    // Tanggal kedaluwarsa DIHITUNG SEKALI saat sertifikat dibuat, lalu disimpan.
    // Sebelumnya frontend menebaknya dengan membaca teks package_name — kalau
    // nama paketnya berubah sedikit saja, masa berlakunya diam-diam berhenti
    // bekerja tanpa error apa pun.
    public static function resolveExpiry(string $packageName, ?string $tier, string $adoptedDate): ?string
    {
        $months = self::resolveMonths($packageName, $tier);
        if ($months === null) return null;
        return \Carbon\Carbon::parse($adoptedDate)->addMonthsNoOverflow($months)->toDateString();
    }
}
