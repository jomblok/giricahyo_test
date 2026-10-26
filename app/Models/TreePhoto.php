<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreePhoto extends Model
{
    protected $fillable = [
        'tree_id',
        'photo_url',
        'caption',
        'measurement_date',
        'dbh_cm',
        'height_m',
    ];

    public function tree()
    {
        return $this->belongsTo(Tree::class);
    }
}
