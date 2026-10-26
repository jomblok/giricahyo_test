<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Buyer extends Model
{
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';
    protected $fillable   = ['id', 'name', 'email', 'phone', 'share_token'];

    // Token berbagi TIDAK pernah ikut terserialisasi secara tidak sengaja.
    // Ia setara kunci: siapa pun yang memilikinya bisa membuka halaman dampak.
    protected $hidden = ['share_token'];

    public function adoptions(): HasMany
    {
        return $this->hasMany(TreeAdoption::class, 'buyer_id');
    }
}
