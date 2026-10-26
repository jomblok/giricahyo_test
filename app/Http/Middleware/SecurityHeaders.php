<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan pada setiap respons API.
 *
 * Sebelum berkas ini ada, respons API TIDAK memuat satu pun header keamanan —
 * terverifikasi dengan memeriksa header balasan /api/public/stats. Header di
 * bawah tidak menambal celah tertentu; ia mempersempit apa yang bisa dilakukan
 * penyerang KALAU nanti ada celah lain, dan itu justru gunanya.
 *
 * Yang dipasang dan alasannya:
 *
 * X-Content-Type-Options: nosniff
 *   Melarang peramban menebak-nebak jenis isi. Tanpa itu, balasan JSON yang
 *   isinya kebetulan diawali markup bisa ditafsirkan sebagai HTML dan
 *   dijalankan — jalur XSS yang tidak lewat kode kita sama sekali.
 *
 * X-Frame-Options: DENY
 *   Melarang halaman ini dipasang di dalam <iframe> situs lain. Itu menutup
 *   clickjacking: penyerang menumpuk halaman transparan di atas antarmuka
 *   asli, dan petugas mengira sedang menekan tombolnya sendiri padahal menekan
 *   tombol penyerang. Sasaran paling mahal di sini tombol Hapus dan Buat
 *   Sertifikat.
 *
 * Referrer-Policy: strict-origin-when-cross-origin
 *   Saat pengguna mengeklik tautan keluar, alamat halaman asalnya tidak ikut
 *   terkirim utuh. Penting karena URL di aplikasi ini memuat ID pohon dan ID
 *   sertifikat.
 *
 * Permissions-Policy
 *   Mematikan kamera, mikrofon, dan geolokasi untuk KONTEN PIHAK KETIGA yang
 *   mungkin tertanam. Kamera tetap bisa dipakai halaman kita sendiri
 *   (self) — pemindai QR membutuhkannya.
 *
 * CSP TIDAK dipasang di sini. Header ini hanya melewati balasan API (JSON),
 * sementara CSP bekerja pada dokumen HTML — yang di aplikasi ini disajikan
 * oleh server statis frontend, bukan Laravel. Menaruhnya di sini akan terasa
 * seperti sudah terlindungi padahal tidak. Tempat yang benar: konfigurasi web
 * server yang menyajikan hasil `npm run build` (lihat SECURITY.md).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');

        // HSTS hanya berarti kalau permintaannya sudah lewat HTTPS. Dipasang
        // pada koneksi HTTP biasa (mis. saat pengembangan di localhost) header
        // ini diabaikan peramban, tapi memasangnya tetap salah sinyal — jadi
        // dijaga syaratnya di sini.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
