<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Models\Account;
use App\Models\CarbonMeasurement;
use App\Models\Notification;
use App\Models\Species;
use App\Models\Tree;
use App\Models\TreePhoto;
use App\Models\TreeReport;
use App\Support\PhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laporan lapangan petani dan antrean verifikasinya.
 *
 * Inilah bagian "Reporting" dan "Verification" dari MRV, yang sebelumnya tidak
 * ada: petani hanya bisa melihat pohonnya, tidak bisa mencatat apa pun
 * tentangnya. Alasan laporan ditahan dulu alih-alih langsung mengubah data
 * resmi ada di komentar migrasi `tree_reports`.
 */
class TreeReportController extends Controller
{
    /**
     * GET /api/reports
     *
     * Admin melihat seluruh antrean; petani hanya melihat laporannya sendiri.
     *
     * Pembatasan ini memakai DAFTAR-PUTIH, bukan daftar-hitam — pola yang sama
     * dengan FarmerController::show(). Bentuk "kalau petani, saring" akan
     * meloloskan buyer tanpa diperiksa sama sekali.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $query = TreeReport::with(['farmer:id,name', 'tree:id,species,farmer_id']);

        if ($account->role === 'admin') {
            // Antrean admin: yang menunggu selalu di atas, terlama dulu —
            // lihat alasan urutannya di scope TreeReport::menunggu().
            $query->orderByRaw("FIELD(status,'menunggu','disetujui','ditolak')")
                  ->orderBy('created_at');
        } elseif ($account->role === 'farmer') {
            $query->where('farmer_id', $account->linked_id)
                  ->orderByDesc('created_at');
        } else {
            return response()->json(['message' => 'Anda tidak boleh mengakses laporan lapangan.'], 403);
        }

        if (in_array($request->status, ['menunggu', 'disetujui', 'ditolak'], true)) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'reports'  => $query->limit(200)->get(),
            'menunggu' => TreeReport::where('status', 'menunggu')->count(),
        ]);
    }

    /**
     * POST /api/reports — petani mengajukan laporan.
     *
     * Dua jenis, satu endpoint: `kondisi` untuk pohon yang sudah terdaftar,
     * `pohon_baru` untuk pohon yang belum pernah tercatat. Lihat migrasinya
     * untuk alasan keduanya berbagi satu tabel.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        if ($account->role !== 'farmer') {
            return response()->json([
                'message' => 'Hanya petani yang bisa mengirim laporan lapangan.',
            ], 403);
        }

        $request->validate([
            'type'      => 'required|in:kondisi,pohon_baru',
            'note'      => 'nullable|string|max:500',
            'photo_url' => [
                'nullable', 'string', 'max:2000000',
                'regex:/^(https?:\/\/|data:image\/(png|jpe?g|webp|gif);base64,)/i',
            ],
        ], [
            'type.required'   => 'Jenis laporan wajib dipilih.',
            'note.max'        => 'Catatan terlalu panjang. Maksimal 500 karakter.',
            'photo_url.max'   => 'Ukuran foto terlalu besar. Perkecil gambar lalu coba lagi.',
            'photo_url.regex' => 'Foto harus berupa tautan http/https atau gambar yang diunggah langsung.',
        ]);

        // BATAS LAPORAN MENGGANTUNG PER PETANI.
        //
        // Terukur: tanpa batas ini satu petani berhasil membuat 62 laporan
        // dalam satu rentetan. Throttle bawaan (60 permintaan/menit) tidak
        // menolongnya karena masalahnya bukan laju permintaan melainkan APA
        // yang dibawa: setiap laporan boleh memuat foto sampai 2 MB, jadi satu
        // akun bisa menumbuhkan basis data ~120 MB per menit.
        //
        // Dan ini tidak perlu niat jahat. Petani yang laporannya belum
        // ditanggapi lalu menekan kirim berulang kali menghasilkan hal yang
        // sama persis.
        //
        // Dua puluh dipilih karena itu jauh di atas pemakaian wajar — satu
        // petani di sini memiliki 27-80 pohon dan tidak mungkin 20 di antaranya
        // berubah kondisi sebelum admin sempat meninjau satu pun — tapi cukup
        // rendah untuk membuat penumpukan berhenti pada puluhan megabita,
        // bukan gigabita.
        //
        // Batasnya pada yang MENGGANTUNG, bukan total seumur hidup: begitu
        // admin meninjau, kuotanya pulih sendiri. Jadi petani yang rajin tidak
        // pernah terhalang, sementara antrean yang tidak tertangani berhenti
        // tumbuh.
        $menggantung = TreeReport::where('farmer_id', $account->linked_id)
            ->where('status', 'menunggu')
            ->count();

        if ($menggantung >= 20) {
            return response()->json([
                'message' => "Anda punya {$menggantung} laporan yang masih menunggu diverifikasi. "
                    . 'Tunggu admin meninjau sebagian dulu sebelum mengirim laporan baru.',
            ], 429);
        }

        return $request->type === 'kondisi'
            ? $this->simpanLaporanKondisi($request, $account)
            : $this->simpanLaporanPohonBaru($request, $account);
    }

    private function simpanLaporanKondisi(Request $request, $account): JsonResponse
    {
        $request->validate([
            'tree_id'       => 'required|string|exists:trees,id',
            'health_status' => 'required|in:baik,sedang,buruk',
        ], [
            'tree_id.exists'         => 'Pohon yang dilaporkan tidak ditemukan.',
            'health_status.required' => 'Kondisi pohon wajib dipilih.',
        ]);

        $tree = Tree::findOrFail($request->tree_id);

        // Petani hanya boleh melaporkan pohon miliknya sendiri. Tanpa
        // pemeriksaan ini, satu petani bisa menandai pohon petani lain
        // "buruk" — dan karena kondisi pohon ikut menentukan bagi hasil, itu
        // jalur merugikan orang lain secara langsung.
        if ($tree->farmer_id !== $account->linked_id) {
            return response()->json([
                'message' => 'Anda hanya bisa melaporkan pohon milik Anda sendiri.',
            ], 403);
        }

        if ($tree->health_status === $request->health_status) {
            return response()->json([
                'message' => "Pohon {$tree->id} sudah tercatat berkondisi {$request->health_status}. Tidak ada yang perlu dilaporkan.",
            ], 422);
        }

        // Satu pohon tidak boleh punya dua laporan menggantung sekaligus —
        // admin akan melihat dua baris yang saling bertentangan tanpa tahu
        // mana yang terbaru.
        $sudahAda = TreeReport::where('tree_id', $tree->id)
            ->where('status', 'menunggu')
            ->exists();

        if ($sudahAda) {
            return response()->json([
                'message' => "Laporan untuk pohon {$tree->id} masih menunggu verifikasi admin. Tunggu hasilnya dulu sebelum mengirim laporan baru.",
            ], 422);
        }

        $report = TreeReport::create([
            'type'      => 'kondisi',
            'tree_id'   => $tree->id,
            'farmer_id' => $account->linked_id,
            'payload'   => ['health_status' => $request->health_status],
            'note'      => $request->note,
            'photo_url' => $request->photo_url,
            'status'    => 'menunggu',
        ]);

        Notification::kirimKeAdmin(
            'laporan_masuk',
            'Laporan kondisi pohon',
            "{$account->name} melaporkan pohon {$tree->id} berkondisi {$request->health_status}.",
            '/laporan'
        );

        return response()->json([
            'message' => 'Laporan terkirim. Admin akan memverifikasinya.',
            'report'  => $report,
        ], 201);
    }

    private function simpanLaporanPohonBaru(Request $request, $account): JsonResponse
    {
        $request->validate([
            'species'      => 'required|string|max:50',
            'dbh_cm'       => 'required|numeric|min:0.1|max:999',
            'height_m'     => 'required|numeric|min:0.1|max:150',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'planted_date' => 'required|date|before_or_equal:today',
        ], [
            'species.required'      => 'Jenis pohon wajib diisi.',
            'dbh_cm.required'       => 'Diameter batang (DBH) wajib diisi.',
            'dbh_cm.max'            => 'DBH di atas 999 cm hampir pasti salah ukur. Periksa lagi.',
            'height_m.required'     => 'Tinggi pohon wajib diisi.',
            'latitude.required'     => 'Koordinat belum terambil. Izinkan akses lokasi lalu coba lagi.',
            'planted_date.before_or_equal' => 'Tanggal tanam tidak boleh di masa depan.',
        ]);

        // Kerapatan kayu diambil dari katalog, bukan diminta ke petani.
        // Petani di lapangan tidak punya cara mengukur rho, dan katalognya
        // sudah berisi 26 jenis beserta nilainya.
        $species = Species::where('name', $request->species)->first();

        $report = TreeReport::create([
            'type'      => 'pohon_baru',
            'tree_id'   => null,
            'farmer_id' => $account->linked_id,
            'payload'   => [
                'species'      => $request->species,
                'dbh_cm'       => (float) $request->dbh_cm,
                'height_m'     => (float) $request->height_m,
                'latitude'     => (float) $request->latitude,
                'longitude'    => (float) $request->longitude,
                'planted_date' => $request->planted_date,
                // NULL kalau jenisnya belum ada di katalog — admin yang
                // mengisinya saat verifikasi, karena dialah yang punya akses
                // ke tabel rujukan kerapatan kayu.
                'density_rho'  => $species?->density_rho,
            ],
            'note'      => $request->note,
            'photo_url' => $request->photo_url,
            'status'    => 'menunggu',
        ]);

        Notification::kirimKeAdmin(
            'laporan_masuk',
            'Pendaftaran pohon baru',
            "{$account->name} mendaftarkan pohon {$request->species} baru dari lapangan.",
            '/laporan'
        );

        return response()->json([
            'message' => 'Pohon baru diajukan. Admin akan memverifikasi sebelum masuk ke sistem.',
            'report'  => $report,
        ], 201);
    }

    /**
     * PATCH /api/reports/{id}/review — admin menyetujui atau menolak.
     *
     * Seluruh penerapan dibungkus transaksi: kalau pembuatan pohon gagal di
     * tengah jalan, laporannya tidak boleh terlanjur bertanda "disetujui".
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'keputusan'   => 'required|in:setuju,tolak',
            'review_note' => 'required_if:keputusan,tolak|nullable|string|max:500',
            'density_rho' => 'nullable|numeric|min:0.1|max:1.5',
        ], [
            'keputusan.required'   => 'Keputusan wajib dipilih.',
            'review_note.required_if' => 'Alasan penolakan wajib diisi supaya petani tahu apa yang harus diperbaiki.',
        ]);

        $account = $request->attributes->get('account');
        $report  = TreeReport::findOrFail($id);

        if ($report->status !== 'menunggu') {
            return response()->json([
                'message' => 'Laporan ini sudah ditinjau sebelumnya.',
            ], 422);
        }

        $akunPetani = Account::where('role', 'farmer')
            ->where('linked_id', $report->farmer_id)
            ->value('id');

        if ($request->keputusan === 'tolak') {
            $report->update([
                'status'      => 'ditolak',
                'review_note' => $request->review_note,
                'reviewed_by' => $account->id,
                'reviewed_at' => now(),
            ]);

            Notification::kirim(
                $akunPetani,
                'laporan_ditolak',
                'Laporan Anda belum bisa diterima',
                $request->review_note,
                '/laporan-saya'
            );

            Audit::catat('report.reject', "Menolak laporan #{$report->id} dari {$report->farmer_id}", 'report', (string) $report->id, [
                'tree_id' => $report->tree_id,
            ]);

            return response()->json(['message' => 'Laporan ditolak.', 'report' => $report]);
        }

        $hasil = DB::transaction(function () use ($report, $request, $account) {
            return $report->type === 'kondisi'
                ? $this->terapkanKondisi($report, $account)
                : $this->terapkanPohonBaru($report, $request, $account);
        });

        if (isset($hasil['error'])) {
            return response()->json(['message' => $hasil['error']], 422);
        }

        Notification::kirim(
            $akunPetani,
            'laporan_disetujui',
            'Laporan Anda disetujui',
            $hasil['pesan'],
            '/laporan-saya'
        );

        Audit::catat('report.approve', "Menyetujui laporan #{$report->id} dari {$report->farmer_id}", 'report', (string) $report->id, [
            'tree_id' => $report->tree_id,
            'type'    => $report->type,
        ]);

        return response()->json([
            'message' => $hasil['pesan'],
            'report'  => $report->fresh(),
        ]);
    }

    private function terapkanKondisi(TreeReport $report, $account): array
    {
        $tree = Tree::find($report->tree_id);

        if (! $tree) {
            return ['error' => 'Pohon yang dilaporkan sudah tidak ada di sistem.'];
        }

        $tree->update([
            'health_status' => $report->payload['health_status'],
            'last_updated'  => now()->toDateString(),
        ]);

        // Foto bukti disimpan ke linimasa pohon, bukan hanya menempel di
        // laporan. Dengan begitu buyer yang mendukung pohon ini ikut melihat
        // perkembangannya — itu satu-satunya hal yang menyambungkan petani
        // dan buyer di aplikasi ini.
        if ($report->photo_url) {
            TreePhoto::create([
                'tree_id'          => $tree->id,
                'photo_url'        => PhotoStorage::simpan($report->photo_url, $tree->id),
                'caption'          => $report->note ?: "Laporan kondisi: {$report->payload['health_status']}",
                'measurement_date' => $report->created_at->toDateString(),
            ]);
        }

        $report->update([
            'status'      => 'disetujui',
            'reviewed_by' => $account->id,
            'reviewed_at' => now(),
        ]);

        return ['pesan' => "Kondisi pohon {$tree->id} diperbarui menjadi {$report->payload['health_status']}."];
    }

    private function terapkanPohonBaru(TreeReport $report, Request $request, $account): array
    {
        $p   = $report->payload;
        $rho = $request->density_rho ?? $p['density_rho'] ?? null;

        if (! $rho) {
            return ['error' => "Jenis \"{$p['species']}\" belum ada kerapatan kayunya di katalog. Isi nilai rho saat menyetujui, atau tambahkan jenisnya lebih dulu di Katalog Pohon."];
        }

        $tree = Tree::create([
            'id'            => Tree::generateId(),
            'farmer_id'     => $report->farmer_id,
            'species'       => $p['species'],
            'dbh_cm'        => $p['dbh_cm'],
            'height_m'      => $p['height_m'],
            'density_rho'   => $rho,
            'latitude'      => $p['latitude'],
            'longitude'     => $p['longitude'],
            'health_status' => 'baik',
            'planted_date'  => $p['planted_date'],
            'last_updated'  => now()->toDateString(),
        ]);

        // Dihitung lewat jalur yang SAMA dengan pohon yang ditambahkan admin —
        // Tree::hitungCo2(). Kalau pohon dari lapangan dihitung dengan cara
        // lain, dua pohon identik bisa menghasilkan angka berbeda tergantung
        // siapa yang memasukkannya.
        $hitung = Tree::hitungCo2($p['dbh_cm'], $p['height_m'], $rho);

        $tree->update(['co2_eq_kg' => $hitung['co2_eq_kg']]);

        // Pencatatnya PETANI yang melapor, bukan admin yang menyetujui:
        // angkanya diukur di lapangan oleh petani, admin hanya memverifikasi.
        CarbonMeasurement::catat(
            $tree->id, now()->toDateString(), $hitung, 'laporan_petani',
            App\Models\Account::where('role', 'farmer')->where('linked_id', $report->farmer_id)->first()
        );

        if ($report->photo_url) {
            TreePhoto::create([
                'tree_id'          => $tree->id,
                'photo_url'        => PhotoStorage::simpan($report->photo_url, $tree->id),
                'caption'          => $report->note ?: 'Foto saat pendaftaran',
                'measurement_date' => $report->created_at->toDateString(),
                'dbh_cm'           => $p['dbh_cm'],
                'height_m'         => $p['height_m'],
            ]);
        }

        $report->update([
            'status'      => 'disetujui',
            'tree_id'     => $tree->id,
            'reviewed_by' => $account->id,
            'reviewed_at' => now(),
        ]);

        return ['pesan' => "Pohon {$tree->id} terdaftar, menyerap " . number_format($hitung['co2_eq_kg'], 2) . ' kg CO₂.'];
    }
}
