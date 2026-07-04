<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FundDistribution extends Model {
    protected $table    = 'fund_distribution';
    protected $fillable = ['period','component','percentage','amount'];
    protected $casts    = ['percentage'=>'float','amount'=>'float'];
}
