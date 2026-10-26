<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Batas laju permintaan API.
     *
     * Laravel 11 TIDAK memasang throttle apa pun secara bawaan (baris
     * 'throttle:api' dinonaktifkan di skeleton-nya), jadi tanpa blok ini
     * endpoint login bisa ditebak paksa tanpa batas — 20 percobaan password
     * salah berturut-turut sebelumnya semuanya dilayani, nol yang diblokir.
     */
    private function configureRateLimiting(): void
    {
        // Batas umum untuk seluruh API — SELALU per IP, tidak bisa per akun:
        // middleware throttle berjalan lebih dulu daripada JwtMiddleware, jadi
        // di titik ini identitas akun memang belum diketahui (sudah diuji:
        // menghabiskan jatah dengan akun admin ikut memblokir akun petani dari
        // IP yang sama).
        //
        // Karena satu IP bisa dipakai bersama seluruh kantor koperasi, angkanya
        // sengaja longgar — tugasnya cuma memberi batas atas supaya API tidak
        // bisa dikeruk/dibanjiri, BUKAN mengatur pemakaian normal. Pengetatan
        // yang sesungguhnya ada di limiter 'login' di bawah, karena di situlah
        // letak risiko tebak-paksa.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Login jauh lebih ketat, dan sengaja dikunci ke KOMBINASI email+IP:
        // per-IP saja akan menghukum banyak petani yang berbagi satu koneksi
        // desa, sedangkan per-email saja membuat penyerang cukup berganti IP.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by(mb_strtolower($email) . '|' . $request->ip()),
                // Lapis kedua: satu IP tidak boleh membombardir banyak email
                // sekaligus (penyerangan menyapu daftar akun).
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
