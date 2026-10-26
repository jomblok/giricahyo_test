<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemberitahuan dalam aplikasi.
 *
 * Semua endpoint di sini bekerja pada akun yang sedang login dan TIDAK PERNAH
 * menerima id akun dari pemanggil. Itu disengaja: notifikasi memuat kalimat
 * seperti "laporan Anda ditolak karena ..." dan "sertifikat Anda akan habis",
 * jadi endpoint yang menerima `account_id` dari luar akan langsung menjadi
 * jalur membaca pemberitahuan orang lain hanya dengan menebak ID.
 */
class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $daftar = Notification::where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'notifications' => $daftar,
            'unread'        => Notification::where('account_id', $account->id)
                                   ->whereNull('read_at')
                                   ->count(),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     */
    public function read(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Disaring berdasarkan account_id, BUKAN findOrFail lalu dicek —
        // bentuk ini tidak bisa dipakai untuk menebak notifikasi orang lain
        // lewat selisih balasan 403 dan 404.
        $notif = Notification::where('id', $id)
            ->where('account_id', $account->id)
            ->first();

        if (! $notif) {
            return response()->json(['message' => 'Pemberitahuan tidak ditemukan.'], 404);
        }

        if (! $notif->read_at) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Ditandai sudah dibaca.']);
    }

    /**
     * PATCH /api/notifications/read-all
     */
    public function readAll(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');

        $jumlah = Notification::where('account_id', $account->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$jumlah} pemberitahuan ditandai sudah dibaca.",
        ]);
    }
}
