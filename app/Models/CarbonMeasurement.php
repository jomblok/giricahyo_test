<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarbonMeasurement extends Model
{
    protected $table    = 'carbon_measurements';
    protected $fillable = ['tree_id','measurement_date','agb_kg','bgb_kg','total_biomass_kg','carbon_stock_kg','co2_eq_kg'];
    protected $casts    = ['agb_kg'=>'float','bgb_kg'=>'float','total_biomass_kg'=>'float','carbon_stock_kg'=>'float','co2_eq_kg'=>'float'];
    public function tree(): BelongsTo { return $this->belongsTo(Tree::class, 'tree_id'); }
}
