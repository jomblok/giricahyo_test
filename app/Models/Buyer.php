<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Buyer extends Model
{
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    protected $fillable   = ['id', 'name', 'email', 'phone'];

    public function adoptions(): HasMany
    {
        return $this->hasMany(TreeAdoption::class, 'buyer_id');
    }
}
