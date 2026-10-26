<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tugas terjadwal
|--------------------------------------------------------------------------
|
| Cadangan yang harus DIINGAT manusia bukan cadangan. Ia dikerjakan rajin
| selama beberapa minggu pertama, lalu dilupakan — dan baru disadari hilang
| pada hari disknya rusak.
|
| AGAR JADWAL INI BERJALAN, satu baris harus dipasang di sistem operasi.
| Tanpa itu blok di bawah tidak mengerjakan apa pun.
|
|   Linux / server kampus (crontab -e):
|     * * * * * cd /path/ke/giricahyo-backend && php artisan schedule:run >> /dev/null 2>&1
|
|   Windows (Task Scheduler, jalankan tiap menit):
|     php artisan schedule:run
|
| Satu baris itu memanggil Laravel setiap menit; Laravel yang memutuskan tugas
| mana yang jatuh tempo. Jadi menambah tugas baru di sini tidak perlu menyentuh
| cron lagi.
*/

// Cadangan penuh tiap malam pukul 02:00 — jam ketika tidak ada yang memakai
// dasbor, sehingga `--single-transaction` tidak menahan siapa pun.
Schedule::command('db:backup --simpan=14')
    ->dailyAt('02:00')
    // Kalau server mati pada jam 02:00 dan baru hidup jam 09:00, tugas yang
    // terlewat TIDAK dikejar — mengejar cadangan yang terlambat tujuh jam
    // tidak ada gunanya, dan justru membebani saat jam sibuk.
    ->withoutOverlapping()
    ->runInBackground();

// Ekspor CSV tiap Senin pagi. Lebih jarang daripada dump karena gunanya
// berbeda: ia untuk dibaca manusia dan ikut ter-commit ke git, jadi membuatnya
// tiap hari hanya menumpuk berkas yang isinya nyaris sama di riwayat versi.
Schedule::command('export:tree-survey')
    ->weeklyOn(1, '02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Audit keamanan tiap Senin. Keluarannya masuk log, bukan untuk ditonton
// setiap minggu — gunanya supaya saat sistem dipasang di server sungguhan,
// temuan "masih pakai kata sandi demo" tercatat tanggalnya, bukan baru
// ditemukan setelah ada yang masuk tanpa izin.
Schedule::command('audit:keamanan')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping();
