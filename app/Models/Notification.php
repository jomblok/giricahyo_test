<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemberitahuan dalam aplikasi untuk satu akun.
 *
 * Alasan tabel ini ada — dan kenapa tabel `notifications` bawaan Laravel tidak
 * dipakai — ada di komentar migrasinya.
 */
class Notification extends Model
{
    protected $fillable = [
        'account_id',
        'type',
        'title',
        'body',
        'link',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Kirim pemberitahuan ke satu akun.
     *
     * Dibuat sebagai pembantu di model, bukan ditulis berulang di tiap
     * controller, karena pemanggilnya tersebar: laporan masuk, laporan
     * ditinjau, sertifikat mau habis. Kalau nanti perlu ditambah surel, satu
     * tempat ini yang diubah.
     *
     * SENGAJA TIDAK MELEMPAR GALAT. Notifikasi adalah efek samping, bukan inti
     * transaksinya. Petani yang laporannya berhasil tersimpan tidak boleh
     * melihat galat hanya karena pemberitahuan ke admin gagal dibuat — jadi
     * kegagalannya ditelan di sini, bukan merembet ke pemanggilnya.
     */
    public static function kirim(
        ?string $accountId,
        string $type,
        string $title,
        string $body,
        ?string $link = null
    ): void {
        if (! $accountId) {
            return;
        }

        try {
            static::create([
                'account_id' => $accountId,
                'type'       => $type,
                'title'      => mb_substr($title, 0, 150),
                'body'       => mb_substr($body, 0, 500),
                'link'       => $link,
            ]);
        } catch (\Throwable $e) {
            // Sengaja diam — lihat catatan di atas.
        }
    }

    /**
     * Kirim ke SEMUA admin yang masih aktif.
     *
     * Dipakai saat laporan petani masuk: yang perlu tahu bukan satu admin
     * tertentu, melainkan siapa pun yang bertugas meninjau.
     */
    public static function kirimKeAdmin(
        string $type,
        string $title,
        string $body,
        ?string $link = null
    ): void {
        $adminIds = Account::where('role', 'admin')
            ->where('deactivated', false)
            ->pluck('id');

        foreach ($adminIds as $id) {
            static::kirim($id, $type, $title, $body, $link);
        }
    }
}
