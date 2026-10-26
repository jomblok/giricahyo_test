<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarbonFundIncome extends Model {
    protected $table    = 'carbon_fund_income';
    protected $fillable = ['date','source','qty','unit_price','total_amount','tree_adoption_id'];
    protected $casts    = ['unit_price'=>'float','total_amount'=>'float'];

    // NULL = dicatat manual oleh admin (boleh diedit/dihapus lewat menu Dana
    // Karbon). Terisi = dibuat otomatis saat sertifikat diterbitkan — lihat
    // TreeAdoptionController::store() dan CarbonFundController::updateIncome().
    public function adoption(): BelongsTo { return $this->belongsTo(TreeAdoption::class, 'tree_adoption_id'); }
}
