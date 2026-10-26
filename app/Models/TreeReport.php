<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Laporan lapangan dari petani yang menunggu verifikasi admin.
 *
 * Alur hidupnya: petani mengajukan (status `menunggu`) → admin meninjau →
 * `disetujui` (perubahannya diterapkan ke tabel trees) atau `ditolak`.
 * Alasan pemisahan ini ada di komentar panjang migrasinya.
 */
class TreeReport extends Model
{
    protected $fillable = [
        'type',
        'tree_id',
        'farmer_id',
        'payload',
        'note',
        'photo_url',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function tree()
    {
        return $this->belongsTo(Tree::class, 'tree_id');
    }

    public function farmer()
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    /**
     * Laporan yang masih menunggu, terlama dulu.
     *
     * Urutannya sengaja menaik: antrean verifikasi harus dikerjakan dari yang
     * paling lama menggantung, bukan dari yang terbaru. Kalau terbaru dulu,
     * laporan yang sudah lama justru terdorong makin ke bawah setiap ada
     * laporan baru dan tidak pernah tertangani.
     */
    public function scopeMenunggu($query)
    {
        return $query->where('status', 'menunggu')->orderBy('created_at');
    }
}
