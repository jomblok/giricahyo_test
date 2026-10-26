<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\Tree;
use App\Support\PhotoStorage;
use App\Models\CarbonMeasurement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TreeController extends Controller
{
    // Lihat alasannya di storePhoto().
    private const MAKS_FOTO_PER_POHON = 40;

    // ── Otorisasi per-pohon ──────────────────────────────────────────────────
    //
    // index() sudah memfilter daftar pohon sesuai role, tapi endpoint yang
    // menerima {id} langsung TIDAK ikut terlindungi oleh filter itu — id pohon
    // berurutan (GJR-L1-001, -002, …) jadi gampang ditebak satu per satu.
    // Helper ini dipakai show() dan storePhoto() supaya aturannya cuma
    // ditulis sekali dan tidak bisa lupa dipasang di endpoint {id} berikutnya.
    //
    //   admin  → semua pohon
    //   farmer → hanya pohon miliknya
    //   buyer  → hanya pohon yang dia adopsi, dan HANYA untuk dibaca
    //            (buyer tidak pernah boleh mengubah data ukur pohon —
    //            aturan yang sama sudah dipakai frontend di TreeTable.jsx:
    //            `canUploadPhoto = role === "admin" || role === "farmer"`)
    private function authorizeTree($account, Tree $tree, bool $forWrite): ?JsonResponse
    {
        if ($account->role === 'admin') {
            return null;
        }

        if ($account->role === 'farmer') {
            return $tree->farmer_id === $account->linked_id
                ? null
                : response()->json(['message' => 'Anda tidak boleh mengakses pohon petani lain.'], 403);
        }

        if ($account->role === 'buyer') {
            if ($forWrite) {
                return response()->json(['message' => 'Buyer tidak boleh mengubah data pohon.'], 403);
            }
            return $tree->adoption && $tree->adoption->buyer_id === $account->linked_id
                ? null
                : response()->json(['message' => 'Anda hanya bisa melihat pohon yang Anda adopsi.'], 403);
        }

        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    // GET /api/trees — daftar semua pohon + nama petani
    // Admin: bisa lihat semua, boleh filter manapun.
    // Petani: HANYA bisa lihat pohon miliknya sendiri (dipaksa dari akun
    // yang login, bukan dari query parameter — supaya tidak bisa
    // mengintip pohon petani lain dengan mengganti farmer_id di URL).
    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $query = Tree::with('farmer:id,name,group_coop')
                     ->with('adoption:tree_id,certificate_no,buyer_name');

        if ($account->role === 'farmer') {
            $query->where('farmer_id', $account->linked_id);
        } elseif ($account->role === 'buyer') {
            // Buyer hanya melihat pohon yang benar-benar didukungnya.
            //
            // Sebelumnya cabang ini tidak ada, jadi buyer jatuh ke `else` dan
            // menerima SELURUH inventaris — terbukti 180 pohon lengkap dengan
            // nama petani pemiliknya. Buyer tidak punya kepentingan pada pohon
            // yang bukan miliknya, dan nama petani bukan miliknya untuk dibaca.
            $query->whereIn('id', \App\Models\TreeAdoption::where('buyer_id', $account->linked_id)->pluck('tree_id'));
        } elseif ($request->farmer_id) {
            // Admin boleh memfilter berdasarkan farmer_id manapun.
            $query->where('farmer_id', $request->farmer_id);
        }

        if ($request->health_status) {
            $query->where('health_status', $request->health_status);
        }
        if ($request->species) {
            $query->where('species', $request->species);
        }

        $trees = $query->orderBy('id')->get();

        return response()->json($trees->map(fn($t) => [
            'id'            => $t->id,
            'farmer_id'     => $t->farmer_id,
            'farmer_name'   => $t->farmer?->name,
            'species'       => $t->species,
            'dbh_cm'        => $t->dbh_cm,
            'height_m'      => $t->height_m,
            'density_rho'   => $t->density_rho,
            'latitude'      => $t->latitude,
            'longitude'     => $t->longitude,
            'health_status' => $t->health_status,
            'planted_date'  => $t->planted_date,
            'last_updated'  => $t->last_updated,
            'co2_eq_kg'     => $t->co2_eq_kg,
            'certificate_no'=> $t->adoption?->certificate_no,
        ]));
    }

    // GET /api/trees/available — pohon yang BELUM punya sertifikat/adopsi
    // apa pun, untuk katalog "Pohon Tersedia untuk Diadopsi" di dashboard
    // buyer. Sengaja bentuk responsnya SAMA PERSIS dengan index() di atas
    // (snake_case, farmer_name rata/flat) supaya konsisten dengan endpoint
    // pohon lain — bukan endpoint admin, jadi middleware-nya 'jwt' biasa
    // (siapa pun yang login boleh lihat, termasuk buyer).
    //
    // PENTING: rute ini harus didaftarkan di routes/api.php SEBELUM
    // GET trees/{id}, kalau tidak Laravel akan mencocokkan "available"
    // sebagai nilai {id} lebih dulu.
    /**
     * GET /api/trees/map — muatan ringan khusus peta.
     *
     * Peta TIDAK bisa dipaginasi: satu titik hilang berarti peta berbohong
     * tentang sebaran. Jadi satu-satunya cara menekan muatannya adalah
     * mengirim lebih sedikit KOLOM, bukan lebih sedikit baris.
     *
     * index() mengirim 12 kolom per pohon karena tabel memang membutuhkan
     * semuanya. Peta cuma menggambar titik berwarna dengan satu popup kecil,
     * jadi density_rho, planted_date, last_updated, farmer_id dan
     * certificate_no tidak pernah dibaca sama sekali — ikut terkirim hanya
     * karena endpointnya dipakai bersama.
     *
     * Peran disaring persis seperti index(), supaya endpoint ini tidak jadi
     * pintu sebelah yang melewati penyaringan di sana.
     */
    public function map(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $query = Tree::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select(['id', 'species', 'latitude', 'longitude', 'health_status', 'co2_eq_kg', 'farmer_id', 'last_updated']);

        if ($account->role === 'farmer') {
            $query->where('farmer_id', $account->linked_id);
        } elseif ($account->role === 'buyer') {
            $query->whereIn('id', \App\Models\TreeAdoption::where('buyer_id', $account->linked_id)->pluck('tree_id'));
        } elseif ($request->farmer_id) {
            $query->where('farmer_id', $request->farmer_id);
        }

        // farmer_name dipakai popup penanda; dimuat lewat relasi ramping
        // (dua kolom) supaya tidak menjadi N+1 satu kueri per penanda.
        $trees = $query->with('farmer:id,name')->orderBy('id')->get();

        return response()->json($trees->map(fn ($t) => [
            'id'            => $t->id,
            'species'       => $t->species,
            'latitude'      => $t->latitude,
            'longitude'     => $t->longitude,
            'health_status' => $t->health_status,
            'co2_eq_kg'     => $t->co2_eq_kg,
            'farmer_name'   => $t->farmer?->name,
            // Dipakai lonceng notifikasi (pohon yang lama tidak diukur) dan
            // kartu Perlu Diperiksa. Keduanya dulu menarik SELURUH inventaris
            // hanya untuk membaca kolom ini.
            'last_updated'  => $t->last_updated,
        ]));
    }

    public function available(Request $request): JsonResponse
    {
        // Katalog adopsi: hanya buyer (yang memang mengadopsi) dan admin.
        //
        // Sebelumnya endpoint ini tidak menyaring peran sama sekali, padahal
        // index() di atasnya menyaring ketat. Akibatnya penyaringan itu bisa
        // dilewati lewat pintu sebelah: petani F009 menerima 72 pohon dari
        // GET /trees, tapi 179 pohon milik F009+F010+F011 dari endpoint ini —
        // lengkap dengan nama petani lain dan koordinat persis petaknya.
        //
        // Tidak ada satu pun layar petani yang memanggilnya; satu-satunya
        // pemanggil adalah dasbor buyer.
        $account = $request->attributes->get('account');
        if (! in_array($account->role, ['buyer', 'admin'], true)) {
            return response()->json([
                'message' => 'Katalog adopsi hanya untuk pendukung dan pengurus koperasi.',
            ], 403);
        }

        $trees = Tree::whereDoesntHave('adoption')
                     ->with('farmer:id,name')
                     ->orderBy('id')
                     ->get();

        return response()->json($trees->map(fn($t) => [
            'id'            => $t->id,
            'farmer_id'     => $t->farmer_id,
            'farmer_name'   => $t->farmer?->name,
            'species'       => $t->species,
            'dbh_cm'        => $t->dbh_cm,
            'height_m'      => $t->height_m,
            'density_rho'   => $t->density_rho,
            'latitude'      => $t->latitude,
            'longitude'     => $t->longitude,
            'health_status' => $t->health_status,
            'planted_date'  => $t->planted_date,
            'co2_eq_kg'     => $t->co2_eq_kg,
        ]));
    }

    // GET /api/trees/{id} — detail LENGKAP satu pohon, untuk user yang
    // sudah login (admin/petani/buyer). Termasuk info buyer/sertifikat.
    public function show(Request $request, string $id): JsonResponse
    {
        // JUMLAH FOTO DIBATASI, dan ini bukan hiasan.
        //
        // Foto disimpan sebagai data URI base64 di kolom longtext, bukan
        // sebagai berkas. Satu foto hasil compressImage ~220 KB. Terukur:
        // balasan endpoint ini 1,1 KB tanpa foto, dan 2.203 KB dengan LIMA
        // foto — 1972 kali lipat. Di jaringan desa 1 Mbps itu ~18 detik hanya
        // untuk membuka satu pohon.
        //
        // Enam foto cukup untuk linimasa yang berarti (dua tahun pemantauan
        // triwulanan) dan menahan muatan di bawah ~1,3 MB. Foto lebih lama
        // tetap ada di basis data dan bisa diambil lewat endpoint tersendiri
        // kalau nanti dibutuhkan — yang dibatasi pemuatan bawaannya, bukan
        // datanya.
        //
        // Perbaikan sesungguhnya adalah menyimpan foto sebagai BERKAS di disk
        // dan mengirim URL-nya, bukan base64 di basis data. Itu perubahan
        // skema; dicatat di SECURITY.md. Batas ini menahan dampaknya sampai
        // perubahan itu dikerjakan.
        $tree = Tree::with([
            'farmer',
            'adoption.buyer',
            'measurements' => fn ($q) => $q->orderByDesc('measurement_date')->limit(5),
            'photos'       => fn ($q) => $q->orderByDesc('measurement_date')->limit(6),
        ])->findOrFail($id);

        // Tanpa cek ini, satu akun petani/buyer bisa menyapu seluruh kebun
        // dengan menebak id berurutan — termasuk nama & alamat petani lain
        // dan identitas buyer di field `adoption`/`buyer` di bawah.
        if ($denied = $this->authorizeTree($request->attributes->get('account'), $tree, forWrite: false)) {
            return $denied;
        }

        // Disimpan dulu sebelum relasinya dilepas dari objek `tree`.
        $farmer   = $tree->farmer;
        $adoption = $tree->adoption;
        $photos   = $tree->photos;

        // SETIAP RELASI DIKIRIM SEKALI SAJA.
        //
        // Sebelumnya balasannya memuat `tree` beserta seluruh relasi yang
        // sudah ter-eager-load DI DALAMNYA, lalu mengulang keempatnya lagi di
        // tingkat atas — jadi farmer, adoption, buyer, dan photos semuanya
        // terkirim dua kali. Untuk tiga field pertama itu cuma pemborosan
        // kecil; untuk `photos` yang berisi base64 ~220 KB per foto, ia
        // MELIPATGANDAKAN muatan: terukur 2.643 KB padahal isinya hanya 6 foto
        // (~1.320 KB).
        //
        // Bentuk tingkat-atas yang dipertahankan, karena itu yang dibaca
        // frontend; relasi di dalam `tree` yang dilepas.
        $tree->unsetRelation('farmer')
             ->unsetRelation('adoption')
             ->unsetRelation('photos');

        return response()->json([
            'tree'    => $tree,
            'farmer'  => $farmer,
            'adoption'=> $adoption,
            'buyer'   => $adoption?->buyer,
            'photos'  => $photos,
        ]);
    }

    // POST /api/trees/{id}/photos — Tambah foto perkembangan pohon
    public function storePhoto(Request $request, string $id): JsonResponse
    {
        $tree = Tree::findOrFail($id);

        if ($denied = $this->authorizeTree($request->attributes->get('account'), $tree, forWrite: true)) {
            return $denied;
        }

        // Batas ukuran photo_url: kolom ini menerima URL biasa MAUPUN data URI
        // base64 dari kamera, jadi tanpa batas satu request bisa menitipkan
        // gambar puluhan MB ke database. 2 juta karakter ≈ 1,5 MB gambar —
        // cukup untuk foto ponsel, tidak cukup untuk dipakai membanjiri DB.
        // Rentang dbh/height dijaga sama seperti store() supaya data ukur yang
        // masuk lewat jalur foto tidak bisa lebih longgar daripada jalur utama.
        $validated = $request->validate([
            // regex skema: hanya http(s) atau data URI gambar.
            //
            // Tanpa ini kolomnya menerima string apa pun, dan isinya dipasang
            // langsung ke <img src> di halaman pohon PUBLIK. Akibatnya siapa
            // pun yang bisa menambah foto dapat menaruh URL ke server mana pun
            // — setiap pengunjung halaman itu lalu memuatnya, membocorkan
            // alamat IP dan jejak kunjungan mereka ke pihak ketiga. Skema
            // seperti javascript: atau data:text/html juga tersaring di sini,
            // bukan menggantungkan diri pada peramban yang kebetulan menolak.
            'photo_url'        => ['required', 'string', 'max:2000000', 'regex:/^(https?:\/\/|data:image\/(png|jpe?g|webp|gif);base64,)/i'],
            'caption'          => 'nullable|string|max:255',
            // before_or_equal:today — sebelumnya aturannya cuma `date`, jadi
            // foto bisa diberi tanggal ukur 2099 dan akan selalu
            // menduduki puncak linimasa pertumbuhan, mendahului foto nyata.
            // Pola ini sudah dipakai TreeReportController pada planted_date;
            // lima field tanggal lain tertinggal tanpa batas sama sekali.
            'measurement_date' => 'nullable|date|before_or_equal:today',
            'dbh_cm'           => 'nullable|numeric|min:0.1|max:1000',
            'height_m'         => 'nullable|numeric|min:0.1|max:150',
        ], [
            'measurement_date.before_or_equal' => 'Tanggal pengukuran tidak boleh di masa depan.',
            'photo_url.required' => 'URL Foto atau data gambar wajib diisi.',
            'photo_url.max'      => 'Ukuran foto terlalu besar. Perkecil gambar lalu coba lagi.',
            'photo_url.regex'    => 'Foto harus berupa tautan http/https atau gambar yang diunggah langsung.',
            'dbh_cm.max'         => 'DBH di luar batas wajar (maksimal 1000 cm).',
            'height_m.max'       => 'Tinggi pohon di luar batas wajar (maksimal 150 m).',
        ]);

        $measurementDate = $validated['measurement_date'] ?? now()->toDateString();

        // Batas jumlah foto per pohon.
        //
        // Pembacaannya sudah lama dibatasi 6, tapi PENULISANNYA tidak dibatasi
        // sama sekali: satu foto boleh 2 MB dan batas laju umum 600 permintaan
        // per menit, jadi satu akun petani bisa menitipkan ratusan megabyte ke
        // disk tanpa melanggar aturan apa pun. Endpoint laporan lapangan sudah
        // punya batas 20 yang menggantung; endpoint ini tertinggal.
        //
        // 40 dipilih supaya jauh di atas pemakaian wajar — satu foto per
        // pengukuran, beberapa kali setahun, bertahun-tahun — sambil tetap
        // memberi batas atas yang pasti. Foto lama harus dihapus lebih dulu
        // kalau benar-benar perlu menambah, jadi tidak ada yang hilang diam-diam.
        $jumlahFoto = $tree->photos()->count();
        if ($jumlahFoto >= self::MAKS_FOTO_PER_POHON) {
            return response()->json([
                'message' => "Pohon {$tree->id} sudah punya {$jumlahFoto} foto (batas " . self::MAKS_FOTO_PER_POHON . "). Hapus foto lama lebih dulu sebelum menambah yang baru.",
            ], 422);
        }

        // Gambar yang diunggah disimpan sebagai BERKAS, dan yang masuk ke
        // kolom hanya URL-nya. Sebelum ini data URI base64 masuk utuh ke
        // longtext: 1.000 foto ~215 MB di dalam MySQL, dan satu balasan
        // GET /trees/{id} membengkak dari 1,1 KB jadi 2,2 MB hanya dengan 5
        // foto. Alasan lengkapnya di App\Support\PhotoStorage.
        //
        // URL http(s) dari luar dilewatkan apa adanya — tidak ada gunanya
        // mengunduh lalu menyimpan ulang gambar yang sudah punya alamat.
        $validated['photo_url'] = PhotoStorage::simpan($validated['photo_url'], $tree->id);

        // $request tidak ikut tertangkap closure di bawah, jadi akunnya
        // diambil lebih dulu — sama seperti di store() dan update().
        $pencatat = $request->attributes->get('account');

        $photo = DB::transaction(function () use ($tree, $validated, $measurementDate, $pencatat) {
            $photo = $tree->photos()->create([
                'photo_url'        => $validated['photo_url'],
                'caption'          => $validated['caption'] ?? 'Update foto pertumbuhan pohon',
                'measurement_date' => $measurementDate,
                'dbh_cm'           => $validated['dbh_cm'] ?? $tree->dbh_cm,
                'height_m'         => $validated['height_m'] ?? $tree->height_m,
            ]);

            // Kalau ukuran pohon ikut diperbarui, co2_eq_kg WAJIB dihitung ulang.
            // Sebelumnya dbh/tinggi berubah tapi co2_eq_kg dibiarkan memakai
            // angka lama, jadi pohon menyimpan dua fakta yang saling
            // bertentangan — dan angka karbon itulah yang dijual sertifikatnya.
            // Pengukuran barunya juga dicatat ke carbon_measurements, memakai
            // jalur yang sama persis dengan store() supaya riwayat karbon satu
            // pohon tetap utuh, tidak bolong tiap kali diperbarui lewat foto.
            if (isset($validated['dbh_cm']) || isset($validated['height_m'])) {
                $dbh    = $validated['dbh_cm'] ?? $tree->dbh_cm;
                $height = $validated['height_m'] ?? $tree->height_m;
                $carbon = Tree::hitungCo2($dbh, $height, $tree->density_rho);

                $tree->update([
                    'dbh_cm'       => $dbh,
                    'height_m'     => $height,
                    'co2_eq_kg'    => $carbon['co2_eq_kg'],
                    'last_updated' => now()->toDateString(),
                ]);

                CarbonMeasurement::catat(
                    $tree->id, $measurementDate, $carbon, 'foto', $pencatat
                );
            }

            return $photo;
        });

        return response()->json([
            'message' => 'Foto perkembangan pohon berhasil ditambahkan.',
            'photo'   => $photo,
        ], 201);
    }

    // GET /api/trees/{id}/public — info TERBATAS, tanpa login, untuk
    // halaman kartu identitas pohon yang dibuka dari scan QR fisik.
    // SENGAJA tidak menyertakan data buyer/sertifikat/data teknis lain —
    // hanya 4 info yang aman dipublikasikan ke siapa saja.
    public function showPublic(string $id): JsonResponse
    {
        $tree = Tree::with('farmer:id,name')->findOrFail($id);

        // BENTUKNYA BERSARANG, bukan datar.
        //
        // Dulu endpoint ini membalas objek datar sementara PublicTreePage.jsx
        // mencari `res.tree`. Akibatnya `res.tree` selalu undefined, halaman
        // selalu jatuh ke data contoh lokal, dan setelah 179 pohon survei asli
        // masuk — yang ID-nya tidak ada di data contoh — halaman QR selalu
        // menulis "Pohon tidak ditemukan". Endpoint-nya sendiri selalu sehat;
        // yang tidak cocok cuma bentuknya.
        //
        // YANG BOLEH DILIHAT PUBLIK, dan alasannya. Halaman ini dituju QR yang
        // tertempel di batang, jadi pembacanya siapa saja yang lewat.
        //
        //   ditampilkan — ukuran, kesehatan, tanggal tanam, pengukuran terakhir.
        //     Justru inilah bukti MRV-nya: angka CO2 tanpa DBH dan tinggi yang
        //     mendasarinya adalah klaim tanpa dasar. Tidak ada satu pun dari
        //     angka ini yang merugikan petani kalau diketahui orang.
        //
        //   TIDAK ditampilkan — foto. Foto kebun bisa memperlihatkan rumah,
        //     wajah, atau penanda yang memulihkan lokasi persisnya, sementara
        //     koordinat di bawah SENGAJA dikaburkan ke 3 desimal (~110 m)
        //     supaya lahan petani tidak bisa dipetakan orang asing. Menampilkan
        //     foto akan membatalkan pengaburan itu. Buyer tetap melihat foto
        //     di dasbornya sendiri, yang butuh login.
        //
        //   TIDAK ditampilkan — nama buyer, harga, nomor sertifikat, gaji.
        //     Tidak ada hubungannya dengan pohon yang sedang dipindai.
        return response()->json(['tree' => [
            'id'            => $tree->id,
            'species'       => $tree->species,
            'farmer_name'   => $tree->farmer?->name,
            'dbh_cm'        => $tree->dbh_cm,
            'height_m'      => $tree->height_m,
            'health_status' => $tree->health_status,
            'planted_date'  => $tree->planted_date,
            'last_updated'  => $tree->last_updated,
            // Koordinat SENGAJA dibulatkan ke 3 angka desimal (~110 meter).
            //
            // Endpoint ini terbuka tanpa login dan ID pohonnya berurutan
            // (GJR-L1-001, -002, ...), jadi siapa pun bisa menyusurinya satu
            // per satu. Dengan koordinat penuh, hasilnya peta lengkap letak
            // lahan setiap petani beserta namanya — persis yang dibutuhkan
            // orang yang berniat mencuri kayu atau mengganggu.
            //
            // Ketelitian 110 meter masih cukup untuk menunjukkan "pohon ini
            // ada di dusun sebelah sini" pada kartu QR, tapi tidak lagi
            // menunjuk satu batang pohon.
            'latitude'     => $tree->latitude  === null ? null : round((float) $tree->latitude, 3),
            'longitude'    => $tree->longitude === null ? null : round((float) $tree->longitude, 3),
            'co2_eq_kg'    => $tree->co2_eq_kg,
        ],
            // Dikirim terpisah supaya halaman bisa menampilkan nama petani
            // sebagai pemilik, tanpa memuat kolom lain dari tabel farmers
            // (alamat, status, kelompok) yang tidak ada urusannya dengan orang
            // yang baru memindai QR di pinggir jalan.
            'farmer' => $tree->farmer ? ['name' => $tree->farmer->name] : null,

            // Selalu kosong, dan itu disengaja — lihat catatan di atas.
            // Dikirim sebagai array kosong, bukan dihilangkan, supaya halaman
            // tidak perlu membedakan "tidak ada foto" dari "field tidak ada".
            'photos' => [],
        ]);
    }

    // POST /api/trees — tambah pohon baru (dari halaman Generate QR)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farmer_id'    => 'required|exists:farmers,id',
            'species'      => 'required|string|max:50',
            'dbh_cm'       => 'required|numeric|min:0.1',
            'height_m'     => 'required|numeric|min:0.1',
            'density_rho'  => 'required|numeric|min:0.1|max:1.5',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'health_status'=> 'required|in:baik,sedang,buruk',
            // before_or_equal:today — sebelumnya aturannya cuma `date`, jadi
            // pohon bisa dicatat "ditanam 2099", dan seluruh cerita umur
            // serta pertumbuhannya jadi omong kosong sejak baris pertama.
            // Pola ini sudah dipakai TreeReportController pada planted_date;
            // lima field tanggal lain tertinggal tanpa batas sama sekali.
            'planted_date' => 'required|date|before_or_equal:today',
        ], [
            'farmer_id.exists'    => 'Petani yang dipilih tidak ditemukan di database.',
            'farmer_id.required'  => 'Petani wajib dipilih.',
            'species.required'    => 'Jenis pohon wajib dipilih.',
            'dbh_cm.required'     => 'Ukuran DBH wajib diisi.',
            'height_m.required'    => 'Tinggi pohon wajib diisi.',
            'density_rho.required'=> 'Densitas kayu wajib diisi.',
            'planted_date.before_or_equal' => 'Tanggal tanam tidak boleh di masa depan.',
            'latitude.between'    => 'Koordinat Lintang (Latitude) di luar jangkauan.',
            'longitude.between'   => 'Koordinat Bujur (Longitude) di luar jangkauan.',
        ]);

        // Diambil SEBELUM closure: $request tidak ikut tertangkap use(), dan
        // mengambilnya di dalam transaksi akan gagal saat dijalankan.
        $pencatat = $request->attributes->get('account');

        return DB::transaction(function () use ($validated, $pencatat) {
            // Dipindah ke Tree::generateId() saat pendaftaran pohon dari
            // lapangan ditambahkan — dua tempat yang menyusun ID sendiri
            // adalah cara ID kembar lahir. Versi di model juga mengunci baris
            // terakhirnya, yang di sini dulu tidak dilakukan.
            $kodeQr = Tree::generateId();

            // Hitung karbon otomatis dari formula
            $carbon = Tree::hitungCo2(
                $validated['dbh_cm'],
                $validated['height_m'],
                $validated['density_rho']
            );

            $tree = Tree::create([
                'id'           => $kodeQr,
                ...$validated,
                'co2_eq_kg'    => $carbon['co2_eq_kg'],
                'last_updated' => now()->toDateString(),
            ]);

            // Pengukuran pertama, lengkap dengan asal-usulnya.
            CarbonMeasurement::catat(
                $tree->id, now()->toDateString(), $carbon, 'pendaftaran', $pencatat
            );

            return response()->json([
                'message' => 'Pohon berhasil ditambahkan.',
                'tree'    => $tree,
                'carbon'  => $carbon,
                'qr_url'  => url("/pohon/{$tree->id}"),
            ], 201);
        });
    }

    // PATCH /api/trees/{id}/health — update status kesehatan
    /**
     * PATCH /api/trees/{id} — koreksi data pohon. Admin saja.
     *
     * Sebelum ini pohon bisa dibuat, dihapus, diubah kondisinya, dan diukur
     * ulang lewat foto — tapi salah ketik jenis, salah petani, atau koordinat
     * tertukar tidak bisa diperbaiki sama sekali. Satu-satunya jalan adalah
     * menghapus lalu membuat ulang, dan itu membuang seluruh riwayat
     * pengukurannya sekaligus menerbitkan ID baru — yang berarti QR yang
     * sudah tercetak dan tertempel di batang menjadi mati.
     *
     * Semua medan opsional: form koreksi mengirim yang berubah saja.
     *
     * Yang TIDAK bisa diubah di sini: id (itulah isi QR fisik) dan co2_eq_kg
     * (selalu turunan, tidak pernah dimasukkan tangan).
     */
    /**
     * GET /api/trees/{id}/growth — deret pengukuran satu pohon.
     *
     * Dipisah dari show() dan dari daftar pohon dengan sengaja: grafik
     * pertumbuhan hanya dilihat saat seseorang membukanya, jadi menyisipkan
     * deretnya ke setiap baris daftar berarti membayar muatan itu untuk 179
     * pohon padahal yang dilihat satu.
     *
     * Hak aksesnya menumpang aturan yang sama dengan show(): petani hanya
     * pohonnya sendiri, buyer hanya pohon yang diadopsinya.
     */
    public function growth(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');
        $tree    = Tree::findOrFail($id);

        if ($account->role === 'farmer' && $tree->farmer_id !== $account->linked_id) {
            return response()->json(['message' => 'Anda tidak boleh mengakses pohon petani lain.'], 403);
        }

        if ($account->role === 'buyer') {
            $punya = \App\Models\TreeAdoption::where('buyer_id', $account->linked_id)
                ->where('tree_id', $tree->id)
                ->exists();
            if (! $punya) {
                return response()->json(['message' => 'Anda hanya bisa melihat pohon yang Anda adopsi.'], 403);
            }
        }

        // Urut NAIK: grafik dibaca kiri ke kanan, dan membalik urutan di
        // klien adalah pekerjaan yang tidak perlu ada.
        $deret = CarbonMeasurement::where('tree_id', $tree->id)
            ->orderBy('measurement_date')
            ->limit(200)
            // Masukan ikut dikirim, bukan cuma hasilnya: itulah yang membuat
            // setiap angka karbon bisa ditelusuri balik ke apa yang diukur
            // di lapangan, bukan sekadar dipercaya.
            ->get([
                'measurement_date', 'dbh_cm', 'height_m', 'density_rho',
                'agb_kg', 'bgb_kg', 'total_biomass_kg', 'carbon_stock_kg',
                'co2_eq_kg', 'source', 'recorded_by_name',
            ]);

        return response()->json([
            'tree_id'      => $tree->id,
            'species'      => $tree->species,
            'measurements' => $deret,
            // Dihitung di sini supaya antarmuka tidak perlu memutuskan sendiri
            // kapan sebuah deret "cukup" untuk digambar sebagai garis. Satu
            // titik bukan pertumbuhan; menggambarnya sebagai grafik adalah
            // klaim yang tidak didukung datanya.
            'cukup_untuk_grafik' => $deret->count() >= 2,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tree = Tree::findOrFail($id);

        $validated = $request->validate([
            'farmer_id'     => 'sometimes|required|exists:farmers,id',
            'species'       => 'sometimes|required|string|max:100',
            'dbh_cm'        => 'sometimes|required|numeric|min:0.1|max:1000',
            'height_m'      => 'sometimes|required|numeric|min:0.1|max:150',
            'density_rho'   => 'sometimes|required|numeric|min:0.1|max:1.5',
            'latitude'      => 'sometimes|required|numeric|between:-90,90',
            'longitude'     => 'sometimes|required|numeric|between:-180,180',
            'health_status' => 'sometimes|required|in:baik,sedang,buruk',
            'planted_date'  => 'sometimes|required|date|before_or_equal:today',
        ], [
            'farmer_id.exists'             => 'Petani yang dipilih tidak ada di basis data.',
            'dbh_cm.max'                   => 'DBH di luar batas wajar (maksimal 1000 cm).',
            'height_m.max'                 => 'Tinggi pohon di luar batas wajar (maksimal 150 m).',
            'density_rho.min'              => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
            'density_rho.max'              => 'Densitas kayu di luar jangkauan wajar (0,1 - 1,5).',
            'latitude.between'             => 'Koordinat Lintang di luar jangkauan.',
            'longitude.between'            => 'Koordinat Bujur di luar jangkauan.',
            'planted_date.before_or_equal' => 'Tanggal tanam tidak boleh di masa depan.',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'Tidak ada data yang diubah.'], 422);
        }

        $pencatat = $request->attributes->get('account');

        return DB::transaction(function () use ($tree, $validated, $pencatat) {
            $ukuranBerubah = isset($validated['dbh_cm'])
                          || isset($validated['height_m'])
                          || isset($validated['density_rho']);

            if ($ukuranBerubah) {
                // co2_eq_kg WAJIB ikut dihitung ulang. Membiarkan ukuran
                // berubah sementara angka karbonnya memakai nilai lama
                // menjadikan pohon menyimpan dua fakta yang bertentangan —
                // dan angka karbon itulah yang dijual sertifikatnya.
                $carbon = Tree::hitungCo2(
                    $validated['dbh_cm']      ?? $tree->dbh_cm,
                    $validated['height_m']    ?? $tree->height_m,
                    $validated['density_rho'] ?? $tree->density_rho
                );
                $validated['co2_eq_kg']    = $carbon['co2_eq_kg'];
                $validated['last_updated'] = now()->toDateString();
            }

            $tree->update($validated);

            // Pengukuran barunya dicatat ke carbon_measurements, jalur yang
            // sama dengan store() dan storePhoto(), supaya riwayat karbon satu
            // pohon tidak bolong tiap kali dikoreksi.
            if ($ukuranBerubah) {
                CarbonMeasurement::catat(
                    $tree->id, now()->toDateString(),
                    Tree::hitungCo2($tree->dbh_cm, $tree->height_m, $tree->density_rho),
                    'koreksi_admin', $pencatat
                );
            }

            Audit::catat('tree.update', "Mengoreksi data pohon {$tree->id}", 'tree', $tree->id, [
                'diubah' => array_keys($validated),
                'karbon_dihitung_ulang' => $ukuranBerubah,
            ]);

            return response()->json([
                'message' => "Data pohon {$tree->id} diperbarui.",
                'tree'    => $tree->fresh()->load('farmer:id,name'),
                'carbon_recalculated' => $ukuranBerubah,
            ]);
        });
    }

    public function updateHealth(Request $request, string $id): JsonResponse
    {
        $request->validate(['health_status' => 'required|in:baik,sedang,buruk']);

        $tree = Tree::findOrFail($id);
        $tree->update([
            'health_status' => $request->health_status,
            'last_updated'  => now()->toDateString(),
        ]);

        Audit::catat('tree.health', "Mengubah kondisi pohon {$tree->id} jadi {$request->health_status}", 'tree', $tree->id);

        return response()->json(['message' => 'Status kesehatan pohon diperbarui.', 'tree' => $tree]);
    }

    // DELETE /api/trees/{id} — admin saja.
    //
    // Tiga tabel menggantung pada satu pohon, dan ketiganya diperlakukan
    // BERBEDA — bukan semuanya diseret ikut terhapus:
    //
    //   tree_photos          → ikut terhapus otomatis (foreign key-nya sudah
    //                          onDelete('cascade')). Foto memang milik pohon
    //                          itu sendiri dan tidak berarti tanpa pohonnya.
    //   carbon_measurements  → dihapus eksplisit di sini. Foreign key-nya TIDAK
    //                          cascade, jadi tanpa baris ini MySQL menolak
    //                          penghapusan dengan galat constraint yang tidak
    //                          bisa dibaca admin.
    //   tree_adoptions       → TIDAK ikut dihapus. Justru penghapusannya
    //                          DITOLAK. Alasannya di bawah.
    //
    // Kenapa sertifikat menghalangi, bukan ikut terhapus: sertifikat adalah
    // dokumen yang sudah dipegang buyer sebagai bukti klaim karbon, dan uangnya
    // sudah tercatat di Carbon Fund. Menghapus pohon lalu diam-diam ikut
    // menghapus sertifikat dan pemasukannya berarti satu klik menghilangkan
    // klaim pihak ketiga beserta jejak uangnya. Jadi admin diminta menghapus
    // sertifikatnya lebih dulu — langkah sadar, dengan konfirmasinya sendiri.
    public function destroy(string $id): JsonResponse
    {
        $tree = Tree::findOrFail($id);

        $adoption = \App\Models\TreeAdoption::where('tree_id', $tree->id)->first();
        if ($adoption !== null) {
            return response()->json([
                'message' => "Pohon {$tree->id} tidak bisa dihapus karena masih terikat sertifikat {$adoption->certificate_no} atas nama {$adoption->buyer_name}. Hapus sertifikatnya lebih dulu di menu Sertifikat.",
            ], 409);
        }

        return DB::transaction(function () use ($tree) {
            $jumlahUkuran = CarbonMeasurement::where('tree_id', $tree->id)->count();
            // Berkas fotonya dihapus SEBELUM barisnya hilang — begitu baris
            // tree_photos terhapus (otomatis lewat cascade foreign key), tidak
            // ada lagi yang menyimpan nama berkasnya, dan berkas itu akan
            // menghuni disk selamanya tanpa ada yang tahu asalnya.
            //
            // Aman untuk foto lama berbentuk base64 dan untuk URL dari luar:
            // PhotoStorage::hapus() hanya menyentuh berkas yang memang
            // disimpannya sendiri.
            foreach ($tree->photos as $foto) {
                PhotoStorage::hapus($foto->photo_url);
            }

            CarbonMeasurement::where('tree_id', $tree->id)->delete();

            $treeId = $tree->id;
            $tree->delete();

            Audit::catat('tree.delete', "Menghapus pohon {$treeId}", 'tree', $treeId, [
                'pengukuran_ikut_terhapus' => $jumlahUkuran,
            ]);

            return response()->json([
                'message'              => "Pohon {$treeId} dihapus.",
                'tree_id'              => $treeId,
                'measurements_removed' => $jumlahUkuran,
            ]);
        });
    }
}
