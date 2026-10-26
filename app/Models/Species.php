<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Species extends Model {
    protected $fillable = ['name', 'scientific_name', 'density_rho', 'density_source', 'density_reference'];
    protected $casts    = ['density_rho' => 'float'];
}
