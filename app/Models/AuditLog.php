<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    // Tabel ini hanya punya created_at; tidak ada updated_at, karena catatan
    // audit tidak pernah diubah setelah ditulis.
    public $timestamps = false;

    protected $fillable = [
        'account_id', 'account_name', 'account_role',
        'action', 'subject_type', 'subject_id',
        'summary', 'meta', 'ip',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];
}
