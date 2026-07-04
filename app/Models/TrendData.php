<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TrendData extends Model {
    protected $table    = 'trend_data';
    protected $fillable = ['year','total_co2_ton','certificates_sold','total_trees'];
    protected $casts    = ['total_co2_ton'=>'float'];
}
