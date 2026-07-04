<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreeAdoption extends Model
{
    protected $table    = 'tree_adoptions';
    protected $fillable = ['tree_id','buyer_id','buyer_name','adopted_date','package_name','certificate_no'];

    public function tree(): BelongsTo { return $this->belongsTo(Tree::class, 'tree_id'); }
    public function buyer(): BelongsTo { return $this->belongsTo(Buyer::class, 'buyer_id'); }

    // Generate nomor sertifikat otomatis: GJR-CERT-{TAHUN}-{SEQ}
    public static function generateCertNo(): string
    {
        $year  = date('Y');
        $count = static::whereYear('adopted_date', $year)->count();
        return sprintf('GJR-CERT-%s-%04d', $year, $count + 1);
    }
}
