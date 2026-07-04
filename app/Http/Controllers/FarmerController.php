<?php

namespace App\Http\Controllers;

use App\Models\Farmer;
use App\Models\CarbonSalary;
use App\Models\Tree;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FarmerController extends Controller
{
    // GET /api/farmers
    public function index(): JsonResponse
    {
        $farmers = Farmer::withCount('trees')
                         ->with(['carbonSalaries' => fn($q) => $q->orderByDesc('id')->limit(1)])
                         ->orderBy('name')
                         ->get();

        return response()->json($farmers->map(function ($f) {
            $salary = $f->carbonSalaries->first();
            return [
                'id'            => $f->id,
                'name'          => $f->name,
                'group_coop'    => $f->group_coop,
                'address'       => $f->address,
                'status'        => $f->status,
                'tree_count'    => $f->trees_count,
                'total_co2_kg'  => $salary?->total_co2_kg ?? 0,
                'final_salary'  => $salary?->final_salary ?? 0,
                'period'        => $salary?->period,
            ];
        }));
    }

    // GET /api/farmers/{id} — dashboard data untuk petani yang login
    public function show(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Petani hanya boleh mengakses data dirinya sendiri.
        // Admin boleh mengakses data petani manapun.
        if ($account->role === 'farmer' && $account->linked_id !== $id) {
            return response()->json(['message' => 'Anda tidak boleh mengakses data petani lain.'], 403);
        }

        $farmer = Farmer::with(['trees', 'carbonSalaries' => fn($q) => $q->orderByDesc('id')])->findOrFail($id);
        $allCo2 = Tree::sum('co2_eq_kg');
        $myCo2  = $farmer->trees->sum('co2_eq_kg');

        return response()->json([
            'farmer'     => $farmer,
            'trees'      => $farmer->trees,
            'salaries'   => $farmer->carbonSalaries,
            'total_co2_kg'  => round($myCo2, 2),
            'proportion_pct'=> $allCo2 > 0 ? round(($myCo2 / $allCo2) * 100, 2) : 0,
        ]);
    }

    // PATCH /api/farmers/{id}/status — admin saja.
    // Mengubah status aktif/nonaktif petani. Ini SATU-SATUNYA cara
    // mengubah status petani setelah dibuat.
    // Status ini mempengaruhi: (1) KPI "Petani Aktif" di dashboard admin,
    // (2) distribusi gaji karbon (petani nonaktif dikecualikan), dan
    // (3) login petani tersebut ikut diblokir/dibuka otomatis lewat akun
    // yang terhubung — supaya satu aksi admin konsisten di semua tempat.
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:active,inactive']);

        return DB::transaction(function () use ($request, $id) {
            $farmer = Farmer::findOrFail($id);
            $farmer->update(['status' => $request->status]);

            $account = Account::where('linked_id', $id)->where('role', 'farmer')->first();
            if ($account) {
                $account->update(['deactivated' => $request->status === 'inactive']);
            }

            return response()->json([
                'message' => 'Status petani berhasil diperbarui.',
                'farmer'  => $farmer,
            ]);
        });
    }
}
