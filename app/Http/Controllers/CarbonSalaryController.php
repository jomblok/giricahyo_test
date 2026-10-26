<?php

namespace App\Http\Controllers;

use App\Models\CarbonFundIncome;
use App\Models\CarbonSalary;
use App\Models\Farmer;
use App\Models\FundDistribution;
use App\Models\Notification;
use App\Models\Account;
use App\Models\Tree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Perhitungan gaji karbon petani.
 *
 * SEBELUM INI: isi tabel `carbon_salaries` seluruhnya berasal dari seeder dan
 * tidak ada satu baris kode pun yang menghitungnya. Akibatnya tujuh baris gaji
 * tercatat untuk petani yang hampir tidak punya pohon, sementara tiga petani
 * yang memiliki seluruh 179 pohon hasil survei tidak punya baris gaji sama
 * sekali — halaman Gaji Karbon menampilkan angka fiksi.
 *
 * RUMUSNYA dipulihkan dari angka seeder dan sudah dicocokkan ulang:
 *
 *     proporsi     = CO₂ petani ÷ CO₂ seluruh petani
 *     gaji pokok   = proporsi × dana yang dialokasikan untuk gaji
 *     gaji akhir   = gaji pokok × (1 + insentif sosial + ekologis + partisipasi)
 *
 * Diuji pada baris F003 seeder: 2.088.000 ÷ 12.000.000 = 17,4% (cocok dengan
 * co2_proportion_pct-nya), dan 2.463.840 ÷ 2.088.000 = 1,18 = 1 + 6% + 8% + 4%.
 *
 * YANG TIDAK DIHITUNG SISTEM: ketiga persentase insentif. Sosial, ekologis,
 * dan partisipasi adalah penilaian terhadap orangnya — kehadiran di pertemuan,
 * keterlibatan menjaga lahan — dan tidak ada di data pohon. Sistem menghitung
 * yang bisa dihitung; koperasi yang memutuskan sisanya. Bawaannya 0 supaya
 * angka yang muncul tanpa penilaian apa pun adalah gaji pokok murni, bukan
 * tebakan.
 */
class CarbonSalaryController extends Controller
{
    /**
     * GET /api/carbon-salaries?period=2026-Q4
     */
    public function index(Request $request): JsonResponse
    {
        $query = CarbonSalary::query()->orderByDesc('period')->orderByDesc('final_salary');

        if ($request->period) {
            $query->where('period', $request->period);
        }

        return response()->json([
            'salaries' => $query->limit(500)->get(),
            'periods'  => CarbonSalary::distinct()->orderByDesc('period')->pluck('period'),
        ]);
    }

    /**
     * POST /api/carbon-salaries/compute — hitung ulang satu periode.
     *
     * Bersifat idempoten: menjalankan ulang periode yang sama menimpa barisnya,
     * tidak menumpuk. Itu disengaja — angka CO₂ berubah setiap ada pohon baru
     * diverifikasi, jadi perhitungan ulang harus aman dilakukan kapan saja.
     */
    public function compute(Request $request): JsonResponse
    {
        $request->validate([
            'period'      => 'required|string|regex:/^\d{4}-Q[1-4]$/',
            'incentives'  => 'nullable|array',
            'incentives.*.social'        => 'nullable|numeric|min:0|max:100',
            'incentives.*.ecological'    => 'nullable|numeric|min:0|max:100',
            'incentives.*.participation' => 'nullable|numeric|min:0|max:100',
        ], [
            'period.required' => 'Periode wajib diisi.',
            'period.regex'    => 'Format periode harus seperti 2026-Q4.',
        ]);

        $periode   = $request->period;
        $insentif  = $request->incentives ?? [];
        $account   = $request->attributes->get('account');

        // Dana yang benar-benar masuk, bukan angka contoh di fund_distribution.
        // Tabel itu masih menyimpan pembagian atas Rp 15.000.000 dari seeder
        // sementara pemasukan sungguhan jauh lebih kecil — menghitung gaji dari
        // sana berarti menjanjikan uang yang tidak ada.
        $totalDana = (float) CarbonFundIncome::sum('total_amount');

        // Persentase alokasi untuk gaji TETAP dibaca dari fund_distribution:
        // itu kebijakan koperasi, dan persentasenya memang sah disimpan.
        $persenGaji = (float) (FundDistribution::where('component', 'like', '%Gaji%')
            ->value('percentage') ?? 80);

        $danaGaji = $totalDana * $persenGaji / 100;

        // Hanya petani yang benar-benar punya pohon. Petani tanpa pohon tidak
        // mendapat baris — bukan baris bernilai nol — supaya daftar gaji tidak
        // dipenuhi nama yang tidak relevan.
        $perPetani = Tree::select('farmer_id', DB::raw('SUM(co2_eq_kg) AS co2'), DB::raw('COUNT(*) AS n'))
            ->groupBy('farmer_id')
            ->having('co2', '>', 0)
            ->get();

        if ($perPetani->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada pohon dengan serapan CO₂ tercatat, jadi gaji belum bisa dihitung.',
            ], 422);
        }

        $totalCo2 = (float) $perPetani->sum('co2');

        $hasil = DB::transaction(function () use ($perPetani, $totalCo2, $danaGaji, $periode, $insentif) {
            CarbonSalary::where('period', $periode)->delete();

            $baris = [];

            foreach ($perPetani as $p) {
                $proporsi = $totalCo2 > 0 ? ($p->co2 / $totalCo2) : 0;
                $pokok    = $danaGaji * $proporsi;

                $i   = $insentif[$p->farmer_id] ?? [];
                $sos = (float) ($i['social'] ?? 0);
                $eko = (float) ($i['ecological'] ?? 0);
                $par = (float) ($i['participation'] ?? 0);

                $akhir = $pokok * (1 + ($sos + $eko + $par) / 100);

                $baris[] = CarbonSalary::create([
                    'farmer_id'                   => $p->farmer_id,
                    'period'                      => $periode,
                    'total_co2_kg'                => round($p->co2, 2),
                    'co2_proportion_pct'          => round($proporsi * 100, 2),
                    'base_salary'                 => round($pokok, 2),
                    'social_incentive_pct'        => $sos,
                    'ecological_incentive_pct'    => $eko,
                    'participation_incentive_pct' => $par,
                    'final_salary'                => round($akhir, 2),
                ]);
            }

            return $baris;
        });

        // Setiap petani diberi tahu gajinya sudah dihitung. Tanpa ini mereka
        // harus menebak kapan harus membuka halaman gaji.
        foreach ($hasil as $row) {
            $akun = Account::where('role', 'farmer')
                ->where('linked_id', $row->farmer_id)
                ->value('id');

            Notification::kirim(
                $akun,
                'gaji_dihitung',
                "Gaji karbon {$periode} sudah dihitung",
                'Gaji Anda periode ' . $periode . ': Rp ' . number_format($row->final_salary, 0, ',', '.')
                    . ' dari ' . number_format($row->total_co2_kg, 0, ',', '.') . ' kg CO₂.',
                '/gaji'
            );
        }

        return response()->json([
            'message'    => count($hasil) . " baris gaji dihitung untuk periode {$periode}.",
            'period'     => $periode,
            'total_co2'  => round($totalCo2, 2),
            'dana_gaji'  => round($danaGaji, 2),
            'persen'     => $persenGaji,
            'salaries'   => $hasil,
        ]);
    }
}
