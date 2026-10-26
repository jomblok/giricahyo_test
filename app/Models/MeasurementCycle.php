<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementCycle extends Model
{
    protected $table = 'measurement_cycles';

    protected $fillable = [
        'name', 'period', 'starts_on', 'ends_on', 'status', 'notes',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on'   => 'date:Y-m-d',
    ];

    public function scopeBerjalan($q)
    {
        return $q->where('status', 'berjalan');
    }
}
