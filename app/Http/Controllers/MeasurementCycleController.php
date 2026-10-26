<?php

namespace App\Http\Controllers;

use App\Models\CarbonMeasurement;
use App\Models\Farmer;
use App\Models\MeasurementCycle;
use App\Models\Tree;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementCycleController extends Controller
{
    /**
     * Kemajuan satu periode.
     *
     * Dihitung dari TANGGAL pengukuran, bukan dari tautan ke periode — lihat
     * alasannya di migrasi create_measurement_cycles_table. Konsekuensinya
     * bagus: periode yang dibuat belakangan tetap menghitung pengukuran yang
     * sudah telanjur masuk.
     *
     * $farmerId membatasi perhitungan ke petak satu petani, untuk layar petani.
     */
    private function kemajuan(MeasurementCycle $siklus, ?string $farmerId = null): array
    {
        $pohon = Tree::query();
        if ($farmerId) {
            $pohon->where('farmer_id', $farmerId);
        }
        $idPohon = $pohon->pluck('id');
        $total   = $idPohon->count();

        // distinct: satu pohon yang diukur dua kali dalam satu periode tetap
        // dihitung satu. Yang ditanyakan "berapa pohon sudah terukur", bukan
        // "berapa kali mengukur".
        $sudah = CarbonMeasurement::whereIn('tree_id', $idPohon)
            ->whereBetween('measurement_date', [
                $siklus->starts_on->toDateString(),
                $siklus->ends_on->toDateString(),
            ])
            ->distinct('tree_id')
            ->count('tree_id');

        return [
            'total'  => $total,
            'sudah'  => $sudah,
            'sisa'   => max(0, $total - $sudah),
            'persen' => $total > 0 ? round($sudah / $total * 100, 1) : 0.0,
        ];
    }

    // GET /api/measurement-cycles
    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Daftar-putih: admin dan petani saja.
        //
        // Rutenya memakai middleware 'jwt' biasa supaya petani bisa masuk,
        // dan itu berarti buyer ikut lolos kalau tidak disaring di sini.
        // Kemajuan pengukuran adalah data operasional koperasi — berapa
        // pohon yang belum sempat didatangi petugas — dan bukan urusan
        // pendukung dari luar.
        if (! in_array($account->role, ['admin', 'farmer'], true)) {
            return response()->json([
                'message' => 'Periode pengukuran hanya untuk pengurus koperasi dan petani.',
            ], 403);
        }

        // Petani melihat kemajuan PETAKNYA SENDIRI, bukan kemajuan desa.
        // Angka desa tidak salah, tapi tidak menjawab pertanyaan yang dia
        // punya: "pohon saya tinggal berapa lagi".
        $farmerId = $account->role === 'farmer' ? $account->linked_id : null;

        $daftar = MeasurementCycle::orderByDesc('starts_on')->limit(50)->get()
            ->map(function (MeasurementCycle $c) use ($farmerId) {
                $c->setAttribute('progress', $this->kemajuan($c, $farmerId));
                return $c;
            });

        return response()->json([
            'cycles' => $daftar,
            'scope'  => $farmerId ? 'petani' : 'koperasi',
        ]);
    }

    // POST /api/measurement-cycles — admin saja
    public function store(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'period'    => 'required|string|max:20|unique:measurement_cycles,period',
            'starts_on' => 'required|date',
            // Boleh berakhir di masa depan — periode memang direncanakan ke
            // depan. Yang dilarang hanya berakhir SEBELUM mulai.
            'ends_on'   => 'required|date|after_or_equal:starts_on',
            'notes'     => 'nullable|string|max:1000',
        ], [
            'name.required'          => 'Nama periode wajib diisi.',
            'period.required'        => 'Kode periode wajib diisi.',
            'period.unique'          => 'Kode periode itu sudah dipakai.',
            'starts_on.required'     => 'Tanggal mulai wajib diisi.',
            'ends_on.required'       => 'Tanggal selesai wajib diisi.',
            'ends_on.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $siklus = MeasurementCycle::create([
            ...$validated,
            'status'          => 'berjalan',
            'created_by'      => $account->id ?? null,
            'created_by_name' => $account->name ?? null,
        ]);

        Audit::catat('cycle.create', "Membuka periode pengukuran {$siklus->name}", 'cycle', (string) $siklus->id, [
            'period' => $siklus->period,
        ]);

        $siklus->setAttribute('progress', $this->kemajuan($siklus));

        return response()->json(['message' => 'Periode pengukuran dibuka.', 'cycle' => $siklus], 201);
    }

    // PATCH /api/measurement-cycles/{id} — admin saja
    public function update(Request $request, string $id): JsonResponse
    {
        $siklus = MeasurementCycle::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|required|string|max:100',
            'starts_on' => 'sometimes|required|date',
            'ends_on'   => 'sometimes|required|date',
            'status'    => 'sometimes|required|in:berjalan,selesai,dibatalkan',
            'notes'     => 'sometimes|nullable|string|max:1000',
        ], [
            'status.in' => 'Status periode hanya boleh berjalan, selesai, atau dibatalkan.',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'Tidak ada data yang diubah.'], 422);
        }

        // Diperiksa terhadap nilai GABUNGAN, bukan hanya yang dikirim: mengubah
        // salah satu tanggal saja tetap bisa membuat rentangnya terbalik.
        $mulai   = $validated['starts_on'] ?? $siklus->starts_on->toDateString();
        $selesai = $validated['ends_on']   ?? $siklus->ends_on->toDateString();
        if ($selesai < $mulai) {
            return response()->json([
                'message' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ], 422);
        }

        $siklus->update($validated);

        Audit::catat('cycle.update', "Mengubah periode pengukuran {$siklus->name}", 'cycle', (string) $siklus->id, [
            'diubah' => array_keys($validated),
        ]);

        $siklus->setAttribute('progress', $this->kemajuan($siklus));

        return response()->json(['message' => 'Periode pengukuran diperbarui.', 'cycle' => $siklus]);
    }

    /**
     * GET /api/measurement-cycles/{id}/breakdown — rincian per petani.
     *
     * Admin saja: isinya kemajuan setiap kelompok tani berdampingan, yang
     * bukan urusan satu petani terhadap petani lain.
     */
    public function breakdown(string $id): JsonResponse
    {
        $siklus = MeasurementCycle::findOrFail($id);

        $rincian = Farmer::orderBy('name')->get()->map(function (Farmer $f) use ($siklus) {
            return [
                'farmer_id'  => $f->id,
                'name'       => $f->name,
                'group_coop' => $f->group_coop,
                ...$this->kemajuan($siklus, $f->id),
            ];
        });

        return response()->json([
            'cycle'    => $siklus,
            'progress' => $this->kemajuan($siklus),
            'farmers'  => $rincian,
        ]);
    }
}
