<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Farmer extends Model
{
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = ['id', 'name', 'group_coop', 'address', 'status'];

    public function trees(): HasMany
    {
        return $this->hasMany(Tree::class, 'farmer_id');
    }

    public function carbonSalaries(): HasMany
    {
        return $this->hasMany(CarbonSalary::class, 'farmer_id');
    }

    // Gaji periode terbaru
    public function latestSalary()
    {
        return $this->carbonSalaries()->latest('id')->first();
    }
}
