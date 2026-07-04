<?php

namespace App\Http\Controllers;

use App\Models\Tree;
use App\Models\Farmer;
use App\Models\TrendData;
use App\Models\CarbonFundIncome;
use App\Models\TreeAdoption;
use App\Models\CarbonSalary;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    // GET /api/dashboard/summary — KPI global untuk admin
    public function summary(): JsonResponse
    {
        // Top 3 kontributor CO2, dihitung dari data gaji karbon periode
        // terakhir per petani (bukan dari Tree langsung, supaya konsisten
        // dengan logika proporsi gaji yang sudah dipakai di tempat lain).
        $topFarmers = CarbonSalary::with('farmer:id,name,group_coop')
            ->whereIn('id', function ($q) {
                // Ambil baris carbon_salaries TERBARU per farmer_id
                $q->selectRaw('MAX(id)')
                  ->from('carbon_salaries')
                  ->groupBy('farmer_id');
            })
            ->orderByDesc('total_co2_kg')
            ->limit(3)
            ->get()
            ->map(fn($s) => [
                'farmer_id'   => $s->farmer_id,
                'name'        => $s->farmer?->name,
                'group_coop'  => $s->farmer?->group_coop,
                'total_co2_kg'=> $s->total_co2_kg,
            ]);

        return response()->json([
            'total_trees'    => Tree::count(),
            'total_co2_kg'   => round(Tree::sum('co2_eq_kg'), 2),
            'active_farmers' => Farmer::where('status', 'active')->count(),
            'total_income'   => CarbonFundIncome::sum('total_amount'),
            'total_certs'    => TreeAdoption::count(),
            'total_buyers'   => TreeAdoption::distinct('buyer_id')->count('buyer_id'),
            'health_summary' => Tree::selectRaw('health_status, COUNT(*) as count')
                                    ->groupBy('health_status')
                                    ->pluck('count', 'health_status'),
            'top_farmers'    => $topFarmers,
        ]);
    }

    // GET /api/dashboard/trend — data tren tahunan untuk grafik
    public function trend(): JsonResponse
    {
        return response()->json(TrendData::orderBy('year')->get());
    }
}
