<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarbonMeasurement extends Model
{
    protected $table    = 'carbon_measurements';
    protected $fillable = [
        'tree_id', 'measurement_date',
        // Masukan rumus — tanpa ini angka karbonnya tidak bisa diturunkan ulang.
        'dbh_cm', 'height_m', 'density_rho',
        'agb_kg', 'bgb_kg', 'total_biomass_kg', 'carbon_stock_kg', 'co2_eq_kg',
        'recorded_by', 'recorded_by_name', 'source',
    ];
    protected $casts    = ['dbh_cm'=>'float','height_m'=>'float','density_rho'=>'float','agb_kg'=>'float','bgb_kg'=>'float','total_biomass_kg'=>'float','carbon_stock_kg'=>'float','co2_eq_kg'=>'float'];
    /**
     * Mencatat satu pengukuran lengkap dengan asal-usulnya.
     *
     * Dipakai lima jalur yang berbeda (impor survei, pendaftaran pohon, unggah
     * foto berukuran, koreksi admin, dan laporan petani yang disetujui). Dibuat
     * satu pintu supaya kolom asal-usulnya tidak bisa terlewat di salah satu
     * jalur — pengukuran tanpa asal-usul persis masalah yang hendak ditutup.
     *
     * $hitung adalah hasil Tree::hitungCo2(), yang sudah memuat masukan
     * (dbh, tinggi, rho) maupun keluarannya.
     */
    public static function catat(string $treeId, string $tanggal, array $hitung, string $source, $account = null): self
    {
        return self::create(array_merge($hitung, [
            'tree_id'          => $treeId,
            'measurement_date' => $tanggal,
            'source'           => $source,
            // Nama disalin, bukan di-join: catatan pengukuran harus tetap
            // terbaca walau akun pencatatnya kemudian dihapus.
            'recorded_by'      => $account->id ?? null,
            'recorded_by_name' => $account->name ?? null,
        ]));
    }

    public function tree(): BelongsTo { return $this->belongsTo(Tree::class, 'tree_id'); }
}
