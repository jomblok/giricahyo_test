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

            // Rincian per jenis, untuk barisan kartu jenis.
            //
            // DIHITUNG DI SINI, beda dengan halaman petani yang merangkumnya
            // di peramban (lihat lib/ringkasJenis.js). Perbedaannya bukan
            // selera: halaman petani SUDAH memuat seluruh pohon miliknya,
            // sedangkan dashboard ini hanya memuat ringkasan — daftar pohonnya
            // ditarik terpisah oleh tabelnya sendiri. Merangkum di peramban
            // berarti menarik seluruh 179 pohon untuk kedua kalinya hanya demi
            // beberapa angka.
            //
            // leftJoin, bukan join: pohon yang jenisnya belum terdaftar di
            // katalog tetap terhitung. Kalau tidak, jumlah di kartu diam-diam
            // berbeda dari total_trees di atas — dan selisih tanpa sebab yang
            // terlihat adalah hal tersulit untuk dilacak.
            //
            // TANPA BATAS JUMLAH. Dulu dipotong 8 teratas dengan alasan
            // "tidak ada yang menggulir sejauh itu". Alasan itu keliru: dari
            // 19 jenis yang punya pohon, 11 di antaranya hilang tanpa jejak.
            // Pengurus membaca barisan kartu sebagai rincian dari angka 179
            // di atasnya, dan rincian yang diam-diam terpotong membuat
            // jumlahnya tidak pernah bisa dicocokkan.
            //
            // Kalau suatu saat jenisnya terlalu banyak untuk digulir dengan
            // nyaman, jawabannya adalah mengubah cara menampilkannya —
            // bukan menyembunyikan sebagiannya tanpa memberi tahu.
            'by_species'     => Tree::selectRaw(
                                        'trees.species,
                                         MAX(species.scientific_name) as latin,
                                         COUNT(*)                     as jumlah,
                                         SUM(trees.co2_eq_kg)         as co2'
                                    )
                                    ->leftJoin('species', 'species.name', '=', 'trees.species')
                                    ->groupBy('trees.species')
                                    ->orderByDesc('jumlah')
                                    ->get()
                                    ->map(fn ($r) => [
                                        'nama'   => $r->species,
                                        'latin'  => $r->latin,
                                        'jumlah' => (int) $r->jumlah,
                                        'co2'    => round((float) $r->co2, 2),
                                    ]),
        ]);
    }

    // GET /api/dashboard/trend — data tren tahunan untuk grafik
    public function trend(): JsonResponse
    {
        return response()->json(TrendData::orderBy('year')->get());
    }
}
