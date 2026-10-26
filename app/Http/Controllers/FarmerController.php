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
    /**
     * GET /api/farmers/{id}/profil-publik
     *
     * Profil petani yang boleh dibaca PENDUKUNG. Dibuat terpisah dari show()
     * dengan sengaja: show() mengirim seluruh inventaris pohon petani
     * beserta riwayat gajinya, dan buyer tidak punya kepentingan apa pun di
     * situ — endpoint itu memang menolak buyer dengan 403.
     *
     * Tapi buyer PUNYA kepentingan yang sah untuk tahu siapa yang merawat
     * pohon yang didukungnya; itu salah satu hal yang dijanjikan paket
     * adopsi. Yang dikirim di sini hanya yang menjawab pertanyaan itu.
     *
     * Aksesnya diikat pada hubungan nyata: buyer hanya boleh membaca profil
     * petani yang SALAH SATU POHONNYA ia adopsi. Tanpa syarat itu, endpoint
     * ini jadi daftar seluruh petani desa yang bisa dibaca akun pendukung
     * mana pun.
     */
    /**
     * GET /api/farmers/perbandingan — kelompok tani berdampingan. Admin saja.
     *
     * Pengurus perlu melihat kelompok mana yang tertinggal, bukan sekadar
     * angka desa secara keseluruhan: rata-rata menyembunyikan satu kelompok
     * yang pohonnya belum pernah diukur ulang di balik dua kelompok yang rajin.
     *
     * Dibatasi admin dengan sengaja. Membandingkan kelompok berdampingan
     * berguna bagi pengurus, tapi menampilkan peringkat antar-petani kepada
     * petani sendiri mengubah alat kerja jadi papan skor — dan yang di bawah
     * biasanya bukan yang paling malas, melainkan yang petaknya paling jauh.
     */
    public function perbandingan(Request $request): JsonResponse
    {
        $ambangHari = (int) ($request->input('stale_days') ?: 90);
        $batas      = now()->subDays($ambangHari)->toDateString();
        $co2Desa    = (float) Tree::sum('co2_eq_kg');

        $baris = Farmer::orderBy('name')->get()->map(function (Farmer $f) use ($batas, $co2Desa) {
            $pohon = Tree::where('farmer_id', $f->id);

            $jumlah = (clone $pohon)->count();
            $co2    = (float) (clone $pohon)->sum('co2_eq_kg');

            // Satu kueri per kelompok, bukan per pohon. Jumlah kelompok di
            // koperasi ini hitungan jari, jadi ini bukan N+1 yang berarti —
            // dan menuliskannya sebagai satu kueri agregat akan jauh lebih
            // sulit dibaca untuk keuntungan yang tidak terukur.
            $kondisi = (clone $pohon)
                ->selectRaw('health_status, COUNT(*) n')
                ->groupBy('health_status')
                ->pluck('n', 'health_status');

            return [
                'farmer_id'   => $f->id,
                'name'        => $f->name,
                'group_coop'  => $f->group_coop,
                'status'      => $f->status,
                'tree_count'  => $jumlah,
                'co2_kg'      => round($co2, 2),
                'co2_share'   => $co2Desa > 0 ? round($co2 / $co2Desa * 100, 2) : 0,
                'co2_rata'    => $jumlah > 0 ? round($co2 / $jumlah, 2) : 0,
                'baik'        => (int) ($kondisi['baik'] ?? 0),
                'sedang'      => (int) ($kondisi['sedang'] ?? 0),
                'buruk'       => (int) ($kondisi['buruk'] ?? 0),
                // Pohon yang belum diukur ulang melewati ambang. Inilah angka
                // yang paling menentukan kelompok mana yang perlu dibantu.
                'tertinggal'  => (clone $pohon)->where(function ($q) use ($batas) {
                    $q->whereNull('last_updated')->orWhere('last_updated', '<', $batas);
                })->count(),
            ];
        });

        return response()->json([
            'farmers'     => $baris,
            'stale_days'  => $ambangHari,
            'co2_total_kg'=> round($co2Desa, 2),
        ]);
    }

    public function profilPublik(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');
        $farmer  = Farmer::findOrFail($id);

        if ($account->role === 'buyer') {
            $terkait = \App\Models\TreeAdoption::where('buyer_id', $account->linked_id)
                ->whereIn('tree_id', Tree::where('farmer_id', $farmer->id)->pluck('id'))
                ->exists();

            if (! $terkait) {
                return response()->json([
                    'message' => 'Anda hanya bisa melihat profil petani yang pohonnya Anda dukung.',
                ], 403);
            }
        } elseif ($account->role === 'farmer' && $account->linked_id !== $farmer->id) {
            return response()->json(['message' => 'Anda tidak boleh mengakses data petani ini.'], 403);
        }

        $pohon = Tree::where('farmer_id', $farmer->id);

        return response()->json([
            'id'         => $farmer->id,
            'name'       => $farmer->name,
            'group_coop' => $farmer->group_coop,
            // Alamat desa, bukan alamat rumah — isinya memang tingkat desa
            // ("Desa Griya Jati Rasa, Purwosari, Gunungkidul").
            'address'    => $farmer->address,
            'status'     => $farmer->status,
            'tree_count' => (clone $pohon)->count(),
            'total_co2_kg' => round((float) (clone $pohon)->sum('co2_eq_kg'), 2),
            'species_count' => (clone $pohon)->distinct('species')->count('species'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Daftar-putih, BUKAN daftar-hitam.
        //
        // Versi sebelumnya hanya memeriksa `role === 'farmer'`, sehingga peran
        // LAIN lolos tanpa diperiksa sama sekali. Terbukti dengan serangan
        // sungguhan: akun buyer memanggil GET /farmers/F009 dan menerima
        // HTTP 200 berisi nama, alamat, status, seluruh pohon, dan riwayat gaji
        // petani itu.
        //
        // Bentuk "kalau peran X dan bukan miliknya, tolak" selalu punya lubang
        // ini: setiap peran yang tidak disebut otomatis diizinkan. Yang benar
        // menyebutkan siapa yang BOLEH, lalu menolak sisanya.
        $boleh = $account->role === 'admin'
              || ($account->role === 'farmer' && $account->linked_id === $id);

        if (! $boleh) {
            return response()->json(['message' => 'Anda tidak boleh mengakses data petani ini.'], 403);
        }

        $farmer = Farmer::with(['trees', 'carbonSalaries' => fn($q) => $q->orderByDesc('id')])->findOrFail($id);
        $allCo2 = Tree::sum('co2_eq_kg');
        // Disimpan ke variabel SEBELUM relasinya dilepas di bawah.
        $pohon  = $farmer->trees;
        $myCo2  = $pohon->sum('co2_eq_kg');

        // Daftar pohon dikirim SEKALI, lewat kunci 'trees'.
        //
        // Sebelumnya ia ikut tertanam di dalam 'farmer' juga, karena relasinya
        // sudah dimuat lewat with(['trees', ...]). Jadi setiap balasan memuat
        // inventaris lengkap dua kali. TERUKUR pada 2.179 pohon: 1.345,7 KB,
        // yang separuhnya salinan kedua yang tidak dibaca siapa pun — seluruh
        // pemanggil di frontend membaca data.trees, bukan data.farmer.trees.
        $farmer->unsetRelation('trees');

        return response()->json([
            'farmer'     => $farmer,
            'trees'      => $pohon,
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
