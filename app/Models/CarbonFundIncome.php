<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CarbonFundIncome extends Model {
    protected $table    = 'carbon_fund_income';
    protected $fillable = ['date','source','qty','unit_price','total_amount'];
    protected $casts    = ['unit_price'=>'float','total_amount'=>'float'];
}
