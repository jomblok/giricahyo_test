<?php

namespace App\Http\Controllers;

use App\Models\Tree;
use App\Models\CarbonMeasurement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TreeController extends Controller
{
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

    // GET /api/trees/{id} — detail LENGKAP satu pohon, untuk user yang
    // sudah login (admin/petani/buyer). Termasuk info buyer/sertifikat.
    public function show(string $id): JsonResponse
    {
        $tree = Tree::with(['farmer', 'adoption.buyer', 'measurements' => function ($q) {
            $q->orderByDesc('measurement_date')->limit(5);
        }])->findOrFail($id);

        return response()->json([
            'tree'    => $tree,
            'farmer'  => $tree->farmer,
            'adoption'=> $tree->adoption,
            'buyer'   => $tree->adoption?->buyer,
        ]);
    }

    // GET /api/trees/{id}/public — info TERBATAS, tanpa login, untuk
    // halaman kartu identitas pohon yang dibuka dari scan QR fisik.
    // SENGAJA tidak menyertakan data buyer/sertifikat/data teknis lain —
    // hanya 4 info yang aman dipublikasikan ke siapa saja.
    public function showPublic(string $id): JsonResponse
    {
        $tree = Tree::with('farmer:id,name')->findOrFail($id);

        return response()->json([
            'id'           => $tree->id,
            'species'      => $tree->species,
            'farmer_name'  => $tree->farmer?->name,
            'latitude'     => $tree->latitude,
            'longitude'    => $tree->longitude,
            'co2_eq_kg'    => $tree->co2_eq_kg,
        ]);
    }

    // POST /api/trees — tambah pohon baru (dari halaman Generate QR)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'           => 'required|string|unique:trees,id|regex:/^[A-Z]{3}-\d{3}-\d{3}$/',
            'farmer_id'    => 'required|exists:farmers,id',
            'species'      => 'required|string|max:50',
            'dbh_cm'       => 'required|numeric|min:0.1',
            'height_m'     => 'required|numeric|min:0.1',
            'density_rho'  => 'required|numeric|min:0.1|max:1.5',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'health_status'=> 'required|in:baik,sedang,buruk',
            'planted_date' => 'required|date',
        ]);

        return DB::transaction(function () use ($validated) {
            // Hitung karbon otomatis dari formula
            $carbon = Tree::hitungCo2(
                $validated['dbh_cm'],
                $validated['height_m'],
                $validated['density_rho']
            );

            $tree = Tree::create([
                ...$validated,
                'co2_eq_kg'    => $carbon['co2_eq_kg'],
                'last_updated' => now()->toDateString(),
            ]);

            // Simpan pengukuran pertama
            CarbonMeasurement::create([
                'tree_id'          => $tree->id,
                'measurement_date' => now()->toDateString(),
                ...$carbon,
            ]);

            return response()->json([
                'message' => 'Pohon berhasil ditambahkan.',
                'tree'    => $tree,
                'carbon'  => $carbon,
                'qr_url'  => url("/pohon/{$tree->id}"),
            ], 201);
        });
    }

    // PATCH /api/trees/{id}/health — update status kesehatan
    public function updateHealth(Request $request, string $id): JsonResponse
    {
        $request->validate(['health_status' => 'required|in:baik,sedang,buruk']);

        $tree = Tree::findOrFail($id);
        $tree->update([
            'health_status' => $request->health_status,
            'last_updated'  => now()->toDateString(),
        ]);

        return response()->json(['message' => 'Status kesehatan pohon diperbarui.', 'tree' => $tree]);
    }
}
