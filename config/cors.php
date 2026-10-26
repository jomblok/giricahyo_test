<?php

// Daftar origin yang boleh memanggil API.
//
// Sebelumnya '*' — artinya SITUS MANA PUN di internet bisa memanggil API ini
// dari browser pengunjungnya. Sekarang dibatasi ke frontend sendiri, diambil
// dari FRONTEND_URL di .env supaya saat deploy cukup mengubah satu baris di
// .env, bukan menyunting file konfigurasi.
//
// CORS_ALLOWED_ORIGINS (opsional, dipisah koma) untuk kasus sah dengan lebih
// dari satu alamat — misalnya saat pengujian lapangan, ketika petugas membuka
// dashboard dari ponsel lewat IP LAN (http://192.168.1.10:5173) sementara
// FRONTEND_URL masih localhost. Tanpa itu request dari ponsel akan ditolak
// dan gejalanya membingungkan: API "tidak bisa dihubungi" padahal hidup.
$origins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));

if (empty($origins)) {
    $origins = array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]);
}

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique($origins)),

    // Pola tambahan HANYA saat APP_ENV bukan production.
    //
    // Saat mengembangkan, alamat frontend bisa bermacam-macam tanpa disadari:
    // localhost:5173, 127.0.0.1:5173, [::1]:5173 (Vite kerap hanya mendengarkan
    // IPv6), port bergeser ke 5174 kalau 5173 terpakai, atau IP LAN saat diuji
    // dari ponsel. Setiap alamat itu adalah origin BERBEDA bagi browser, dan
    // begitu satu di antaranya tidak terdaftar, permintaannya gagal dengan
    // pesan "Failed to fetch" yang sama sekali tidak menyebut CORS — sangat
    // membingungkan untuk didiagnosis.
    //
    // Jadi di lokal semua loopback dan rentang IP privat diizinkan pada port
    // mana pun. Di produksi daftar ini KOSONG, sehingga hanya FRONTEND_URL
    // (dan CORS_ALLOWED_ORIGINS) yang berlaku — pengetatan yang sesungguhnya
    // tetap utuh di tempat yang penting.
    // env('APP_ENV'), BUKAN app()->environment(): berkas konfigurasi dimuat
    // sebelum service container siap, sehingga app() di sini melempar
    // "Target class [env] does not exist" dan seluruh aplikasi gagal boot.
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#',
        '#^https?://(10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|192\.168\.\d+\.\d+)(:\d+)?$#',
    ],

    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    // Hasil preflight boleh disimpan browser 1 jam — mengurangi permintaan
    // OPTIONS berulang yang sebelumnya menyertai hampir setiap request.
    'max_age' => 3600,

    // Token dikirim lewat header Authorization, bukan cookie, jadi tidak perlu.
    'supports_credentials' => false,
];
