<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Tree extends Model
{
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'id', 'farmer_id', 'species', 'dbh_cm', 'height_m', 'density_rho',
        'latitude', 'longitude', 'health_status', 'planted_date', 'last_updated', 'co2_eq_kg',
    ];

    protected $casts = [
        'dbh_cm'      => 'float',
        'height_m'    => 'float',
        'density_rho' => 'float',
        'latitude'    => 'float',
        'longitude'   => 'float',
        'co2_eq_kg'   => 'float',
    ];

    // ── Formula karbon (dipakai saat insert baru) ─────────────────────────
    // AGB = 0.0673 × (ρ × D² × H)^0.976
    // BGB = AGB × 0.24
    // TB  = AGB + BGB
    // C   = TB × 0.47
    // CO₂ = C × 3.67
    public static function hitungCo2(float $dbhCm, float $heightM, float $densityRho): array
    {
        $agb = 0.0673 * pow($densityRho * $dbhCm * $dbhCm * $heightM, 0.976);
        $bgb = $agb * 0.24;
        $tb  = $agb + $bgb;
        $c   = $tb * 0.47;
        $co2 = $c * 3.67;

        return [
            // MASUKAN ikut dikembalikan, bukan cuma keluarannya.
            //
            // Seluruh pemanggil menyebarkan hasil fungsi ini langsung ke
            // CarbonMeasurement::create(), jadi mengembalikan masukannya di
            // sini membuat setiap pengukuran tercatat lengkap dengan angka
            // yang melahirkannya — tanpa perlu mengubah lima titik tulis
            // satu per satu dan tanpa risiko ada yang terlewat.
            'dbh_cm'           => $dbhCm,
            'height_m'         => $heightM,
            'density_rho'      => $densityRho,

            'agb_kg'           => round($agb, 2),
            'bgb_kg'           => round($bgb, 2),
            'total_biomass_kg' => round($tb, 2),
            'carbon_stock_kg'  => round($c, 2),
            'co2_eq_kg'        => round($co2, 2),
        ];
    }

    /**
     * ID pohon berikutnya, berpola GJR-YY-NNN.
     *
     * Diangkat ke sini dari dalam TreeController::store() ketika pendaftaran
     * pohon dari lapangan ditambahkan: dua tempat yang masing-masing menyusun
     * ID sendiri adalah cara ID kembar lahir, dan ID pohon dipakai sebagai
     * isi QR yang ditempel di batang — dua pohon ber-ID sama berarti dua
     * batang berbeda yang mengarah ke halaman yang sama.
     *
     * HARUS dipanggil di dalam transaksi yang sama dengan insert-nya.
     * `lockForUpdate()` menahan barisnya sampai transaksi selesai, sehingga
     * dua orang yang menyimpan pada detik yang sama tidak sama-sama membaca
     * nomor terakhir yang sama.
     *
     * Nomornya diambil dari tabel `tree_sequences`, BUKAN dari ID tertinggi
     * yang masih ada. Cara lama memakai ulang ID pohon yang dihapus — dan
     * karena ID pohon adalah isi QR yang tercetak dan tertempel di batang,
     * QR lama akan menunjuk pohon yang berbeda. Alasan lengkapnya ada di
     * komentar migrasi tree_sequences.
     */
    public static function generateId(): string
    {
        $tahun  = (int) now()->format('y');
        $prefix = 'GJR-' . now()->format('y') . '-';

        // where('year'), BUKAN find(): find() pada query builder selalu
        // mencari kolom bernama `id`, sedangkan kunci utama tabel ini `year`.
        $baris = DB::table('tree_sequences')
            ->where('year', $tahun)
            ->lockForUpdate()
            ->first();

        if ($baris) {
            $nomor = $baris->last_no + 1;
            DB::table('tree_sequences')
                ->where('year', $tahun)
                ->update(['last_no' => $nomor, 'updated_at' => now()]);
        } else {
            $nomor = 1;
            DB::table('tree_sequences')->insert([
                'year'       => $tahun,
                'last_no'    => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $prefix . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
    }

    // ── Relasi ───────────────────────────────────────────────────────────────
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function adoption(): HasOne
    {
        return $this->hasOne(TreeAdoption::class, 'tree_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(CarbonMeasurement::class, 'tree_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TreePhoto::class, 'tree_id')->orderByDesc('measurement_date');
    }
}
