<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // Dipaginasi sejak awal, tidak seperti koleksi lain yang baru ketahuan
    // tumbuh tanpa batas setelah dipakai setahun. Tabel ini bertambah setiap
    // kali ada yang menyentuh uang, sertifikat, pohon, atau akun — ia tumbuh
    // paling cepat di antara semua tabel di sistem ini.
    private const PER_HALAMAN = 50;
    private const PER_HALAMAN_MAKS = 200;

    /**
     * GET /api/audit-logs
     *
     * Saringan: ?action=tree.delete  ?subject_type=tree  ?subject_id=GJR-L1-001
     *           ?account_id=ACC001   ?dari=2026-10-01    ?sampai=2026-10-31
     */
    public function index(Request $request): JsonResponse
    {
        $perHalaman = min(
            max((int) $request->input('per_page', self::PER_HALAMAN), 1),
            self::PER_HALAMAN_MAKS
        );

        $query = AuditLog::query()->orderByDesc('id');

        foreach (['action', 'subject_type', 'subject_id', 'account_id'] as $kolom) {
            if ($request->filled($kolom)) {
                $query->where($kolom, $request->input($kolom));
            }
        }

        if ($request->filled('dari')) {
            $query->where('created_at', '>=', $request->input('dari') . ' 00:00:00');
        }
        if ($request->filled('sampai')) {
            $query->where('created_at', '<=', $request->input('sampai') . ' 23:59:59');
        }

        $halaman = $query->paginate($perHalaman);

        return response()->json([
            'logs'  => $halaman->items(),
            'total' => $halaman->total(),
            'page'  => $halaman->currentPage(),
            'pages' => $halaman->lastPage(),
            // Daftar aksi yang BENAR-BENAR ada di tabel, bukan daftar tetap
            // yang ditulis tangan: kalau nanti ada titik audit baru, saringan
            // di layar ikut mengenalinya tanpa disentuh.
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
