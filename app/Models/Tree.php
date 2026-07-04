<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'agb_kg'           => round($agb, 2),
            'bgb_kg'           => round($bgb, 2),
            'total_biomass_kg' => round($tb, 2),
            'carbon_stock_kg'  => round($c, 2),
            'co2_eq_kg'        => round($co2, 2),
        ];
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
}
