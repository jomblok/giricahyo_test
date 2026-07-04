<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Species extends Model {
    protected $fillable = ['name', 'density_rho'];
    protected $casts    = ['density_rho' => 'float'];
}
