<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Penulis jejak audit.
 *
 * Alasan kenapa tabelnya ada: lihat migrasi create_audit_logs_table.
 *
 * ATURAN PALING PENTING di kelas ini: mencatat TIDAK BOLEH menggagalkan aksi
 * yang sedang dicatat. Kalau penulisan log gagal, pohonnya tetap terhapus dan
 * sertifikatnya tetap terbit; kegagalannya masuk ke log aplikasi, bukan
 * dilempar ke pengguna. Audit adalah pengamat, bukan penjaga gerbang.
 */
class Audit
{
    public static function catat(
        string $action,
        string $summary,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $meta = null
    ): void {
        try {
            // Akun pelaku diambil dari atribut request yang dipasang
            // JwtMiddleware — sumber yang sama dengan yang dipakai controller
            // untuk memutuskan izin, jadi tidak mungkin melenceng darinya.
            $account = Request::instance()->attributes->get('account');

            AuditLog::create([
                'account_id'   => $account->id ?? null,
                'account_name' => $account->name ?? null,
                'account_role' => $account->role ?? null,
                'action'       => $action,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'summary'      => mb_substr($summary, 0, 255),
                'meta'         => $meta,
                'ip'           => Request::instance()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis audit log: ' . $e->getMessage(), [
                'action'  => $action,
                'subject' => $subjectType . ':' . $subjectId,
            ]);
        }
    }
}
