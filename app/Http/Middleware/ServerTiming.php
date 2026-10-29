<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header Server-Timing: rincian ke mana waktu sebuah permintaan habis.
 *
 * Dibuat karena di server Railway setiap permintaan yang menyentuh basis data
 * butuh ±1,8 detik, sedangkan yang tidak menyentuhnya ±0,3 detik — dan dari
 * luar server tidak ada cara membedakan apakah selisih itu habis untuk
 * menyambung ke MySQL, menjalankan query, atau menyalakan Laravel. Peramban
 * menampilkan header ini di DevTools → Network → Timing → "Server Timing",
 * jadi jawabannya terbaca tanpa akses ke server.
 *
 * Angka yang dilaporkan (milidetik):
 *   boot     — dari PHP mulai sampai middleware ini berjalan (memuat Laravel)
 *   db-first — query PERTAMA. Sambungan ke MySQL dibuka malas saat query
 *              pertama, jadi angka ini = membuka sambungan + query itu sendiri.
 *              Bila jauh di atas query lainnya, yang lambat adalah sambungannya.
 *   db       — total seluruh query, dengan jumlah query di keterangannya
 *   total    — dari PHP mulai sampai balasan siap dikirim
 *
 * Hanya aktif bila SERVER_TIMING=true. Isinya cuma durasi, tapi tidak ada
 * alasan memamerkannya ke semua orang setelah penyelidikannya selesai.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.server_timing')) {
            return $next($request);
        }

        $mulai = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $boot  = (microtime(true) - $mulai) * 1000;

        $pertama = null;
        $totalDb = 0.0;
        $jumlah  = 0;
        DB::listen(function (QueryExecuted $q) use (&$pertama, &$totalDb, &$jumlah) {
            $pertama ??= $q->time;
            $totalDb += $q->time;
            $jumlah++;
        });

        $response = $next($request);

        $total = (microtime(true) - $mulai) * 1000;
        $bagian = [sprintf('boot;dur=%.1f', $boot)];
        if ($jumlah > 0) {
            $bagian[] = sprintf('db-first;dur=%.1f', $pertama);
            $bagian[] = sprintf('db;dur=%.1f;desc="%d query"', $totalDb, $jumlah);
        }
        $bagian[] = sprintf('total;dur=%.1f', $total);

        $response->headers->set('Server-Timing', implode(', ', $bagian));

        return $response;
    }
}
