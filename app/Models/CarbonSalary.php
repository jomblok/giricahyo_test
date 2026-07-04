<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarbonSalary extends Model
{
    protected $table    = 'carbon_salaries';
    protected $fillable = [
        'farmer_id', 'period', 'total_co2_kg', 'co2_proportion_pct',
        'base_salary', 'social_incentive_pct', 'ecological_incentive_pct',
        'participation_incentive_pct', 'final_salary',
    ];
    protected $casts = [
        'total_co2_kg' => 'float', 'co2_proportion_pct' => 'float',
        'base_salary' => 'float', 'final_salary' => 'float',
        'social_incentive_pct' => 'float', 'ecological_incentive_pct' => 'float',
        'participation_incentive_pct' => 'float',
    ];

    public function farmer(): BelongsTo {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }
}
