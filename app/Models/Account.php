<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Account extends Authenticatable implements JWTSubject
{
    protected $table      = 'accounts';
    protected $primaryKey = 'id';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'id', 'email', 'password', 'role', 'name', 'linked_id', 'deactivated',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'deactivated' => 'boolean',
    ];

    // ── JWT ──────────────────────────────────────────────────────────────────
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role'      => $this->role,
            'name'      => $this->name,
            'linked_id' => $this->linked_id,
        ];
    }

    // ── Relasi ───────────────────────────────────────────────────────────────
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'linked_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'linked_id');
    }
}
