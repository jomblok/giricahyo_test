<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\FarmerController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\TreeController;
use App\Http\Controllers\SpeciesController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CarbonFundController;
use App\Http\Controllers\MeasurementCycleController;
use App\Http\Controllers\TreeAdoptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\TreeReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CarbonSalaryController;

/*
|--------------------------------------------------------------------------
| Giricahyo Carbon — API Routes
|--------------------------------------------------------------------------
|
| Semua endpoint diawali /api/ (dikonfigurasi di bootstrap/app.php).
|
| Guard:
|   jwt         → harus login (semua role)
|   jwt:admin   → hanya admin
|   jwt:farmer  → hanya petani
|   jwt:buyer   → hanya buyer
|   -            → publik, tidak perlu login
|
*/

// ── Auth (publik) ────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    // throttle:login jauh lebih ketat daripada throttle:api umum — lihat
    // AppServiceProvider::configureRateLimiting().
    Route::post('login',  [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('jwt');
    Route::get('me',      [AuthController::class, 'me'])->middleware('jwt');
});

// ── Publik: kartu identitas pohon (dibuka saat QR di-scan) ───────────────────
// SENGAJA pakai method terpisah (showPublic) yang hanya mengembalikan info
// terbatas — jangan diarahkan ke show() biasa, karena itu menyertakan data
// buyer/sertifikat yang seharusnya tidak terekspos ke publik tanpa login.
Route::get('trees/{id}/public', [TreeController::class, 'showPublic']);

// ── Publik: angka agregat untuk halaman login ────────────────────────────────
// Hanya jumlah pohon, total CO2e, dan jumlah spesies. Lihat PublicController
// untuk alasan pembatasannya.
Route::get('public/stats', [PublicController::class, 'stats']);
// Halaman dampak yang dibagikan buyer. TANPA LOGIN, dibuka lewat token acak —
// alasan kenapa bukan /dampak/{id} ada di migrasi add_share_token_to_buyers.
Route::get('dampak-publik/{token}', [BuyerController::class, 'dampakPublik']);

// ── Dashboard & KPI (hanya admin) ────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('dashboard/trend',   [DashboardController::class, 'trend']);
});

// ── Pohon ────────────────────────────────────────────────────────────────────
Route::middleware('jwt')->group(function () {
    Route::get('trees',                   [TreeController::class, 'index']);   // admin & farmer (difilter di controller)
    // WAJIB sebelum trees/{id} — kalau tidak, "available" akan tertangkap
    // sebagai nilai {id} lebih dulu dan tidak pernah sampai ke method ini.
    Route::get('trees/available',         [TreeController::class, 'available']);
    // WAJIB sebelum trees/{id}, alasan sama dengan 'available' di atas.
    Route::get('trees/map',               [TreeController::class, 'map']);
    Route::get('trees/{id}',              [TreeController::class, 'show']);
    Route::get('trees/{id}/growth',       [TreeController::class, 'growth']);
    Route::post('trees/{id}/photos',      [TreeController::class, 'storePhoto']);
});
Route::middleware('jwt:admin')->group(function () {
    Route::post('trees',                  [TreeController::class, 'store']);
    Route::patch('trees/{id}',            [TreeController::class, 'update'])->middleware('jwt:admin');
    Route::patch('trees/{id}/health',     [TreeController::class, 'updateHealth']);
    // Menolak kalau pohonnya masih terikat sertifikat — lihat catatan panjang
    // di TreeController::destroy().
    Route::delete('trees/{id}',           [TreeController::class, 'destroy']);
});

// ── Petani ────────────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('farmers', [FarmerController::class, 'index']);
});
Route::middleware('jwt')->group(function () {
    // Petani boleh akses data dirinya sendiri; admin bisa akses semua
    // Segmen statis WAJIB sebelum farmers/{id}.
    Route::get('farmers/perbandingan', [FarmerController::class, 'perbandingan'])->middleware('jwt:admin');
    Route::get('farmers/{id}/profil-publik', [FarmerController::class, 'profilPublik']);
    Route::get('farmers/{id}', [FarmerController::class, 'show']);
});
Route::middleware('jwt:admin')->group(function () {
    Route::patch('farmers/{id}/status', [FarmerController::class, 'updateStatus']);
});

// ── Buyer ─────────────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('buyers', [BuyerController::class, 'index']);
});
Route::middleware('jwt')->group(function () {
    Route::get('buyers/{id}', [BuyerController::class, 'show']);
    Route::post('buyers/{id}/share', [BuyerController::class, 'terbitkanToken']);
    Route::delete('buyers/{id}/share', [BuyerController::class, 'cabutToken']);
});

// ── Jenis pohon / Katalog ─────────────────────────────────────────────────────
Route::get('species',  [SpeciesController::class, 'index'])->middleware('jwt');
Route::post('species', [SpeciesController::class, 'store'])->middleware('jwt:admin');
Route::patch('species/{id}', [SpeciesController::class, 'update'])->middleware('jwt:admin');
// Menolak kalau jenisnya masih dipakai pohon — trees.species cuma kolom teks,
// jadi database tidak akan menghalanginya sendiri.
Route::delete('species/{id}', [SpeciesController::class, 'destroy'])->middleware('jwt:admin');

// ── Akun / User management (hanya admin) ────────────────────────────────────
// Periode pengukuran. Daftar bisa dibaca admin DAN petani (petani melihat
// kemajuan petaknya sendiri); membuat dan mengubahnya admin saja.
Route::get('measurement-cycles', [MeasurementCycleController::class, 'index'])->middleware('jwt');
Route::post('measurement-cycles', [MeasurementCycleController::class, 'store'])->middleware('jwt:admin');
Route::patch('measurement-cycles/{id}', [MeasurementCycleController::class, 'update'])->middleware('jwt:admin');
Route::get('measurement-cycles/{id}/breakdown', [MeasurementCycleController::class, 'breakdown'])->middleware('jwt:admin');

// Jejak audit — admin saja.
Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('jwt:admin');

// Transparansi dana untuk petani — agregat saja, tanpa rincian transaksi.
Route::get('carbon-fund/transparansi', [CarbonFundController::class, 'transparansi'])->middleware('jwt');

Route::middleware('jwt:admin')->prefix('accounts')->group(function () {
    Route::get('/',                        [AccountController::class, 'index']);
    Route::post('/',                       [AccountController::class, 'store']);
    Route::patch('{id}/deactivate',        [AccountController::class, 'deactivate']);
    Route::patch('{id}/activate',   [AccountController::class, 'activate']);
    Route::patch('{id}/password',          [AccountController::class, 'updatePassword']);
});

// ── Carbon Fund ───────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('carbon-fund/income',       [CarbonFundController::class, 'income']);
    Route::post('carbon-fund/income',      [CarbonFundController::class, 'storeIncome']);
    Route::patch('carbon-fund/income/{id}', [CarbonFundController::class, 'updateIncome']);
    Route::delete('carbon-fund/income/{id}', [CarbonFundController::class, 'destroyIncome']);
    Route::get('carbon-fund/distribution', [CarbonFundController::class, 'distribution']);
    Route::get('carbon-fund/summary',      [CarbonFundController::class, 'summary']);
});

// ── Sertifikat / Adopsi pohon ─────────────────────────────────────────────────
// GET & verifikasi tetap admin-saja. POST dibuka untuk semua role yang login
// — buyer boleh mengadopsi mandiri (satu pohon dari katalog), admin tetap
// bisa membuat sertifikat apa pun untuk buyer mana pun; keduanya dipisah di
// dalam TreeAdoptionController::store() sendiri (role dari token, bukan dari
// body request).
Route::middleware('jwt:admin')->group(function () {
    Route::get('adoptions', [TreeAdoptionController::class, 'index']);
    Route::patch('adoptions/{id}/verify', [TreeAdoptionController::class, 'verify']);
    // Admin SAJA, sengaja tidak dibuka untuk buyer meskipun buyer boleh
    // membuat sertifikatnya sendiri: membatalkan klaim karbon yang uangnya
    // sudah masuk ke koperasi adalah keputusan koperasi, bukan pembelinya.
    // Baris dana masuk yang tertaut ikut terhapus — lihat destroy().
    Route::delete('adoptions/{id}', [TreeAdoptionController::class, 'destroy']);
});
Route::middleware('jwt')->group(function () {
    Route::post('adoptions', [TreeAdoptionController::class, 'store']);
});

// ── Laporan lapangan petani ───────────────────────────────────────────────────
// Bagian "Reporting" dan "Verification" dari MRV. Sebelum ini petani tidak bisa
// mencatat apa pun tentang pohonnya kecuali mengunggah foto.
//
// Kenapa `index` dan `store` dibuka untuk semua peran yang login, bukan dibatasi
// di sini: keduanya berperilaku BERBEDA per peran (admin melihat seluruh
// antrean, petani hanya miliknya sendiri), dan pembatasannya memakai
// daftar-putih di dalam controller berdasarkan peran dari TOKEN — bukan dari
// parameter yang dikirim peramban.
Route::middleware('jwt')->group(function () {
    Route::get('reports',  [TreeReportController::class, 'index']);
    Route::post('reports', [TreeReportController::class, 'store']);
});
Route::middleware('jwt:admin')->group(function () {
    // Menyetujui laporan mengubah data karbon resmi, jadi admin saja.
    Route::patch('reports/{id}/review', [TreeReportController::class, 'review']);
});

// ── Pemberitahuan ─────────────────────────────────────────────────────────────
// Semuanya bekerja pada akun dari token dan tidak pernah menerima account_id
// dari pemanggil — lihat catatan di NotificationController.
Route::middleware('jwt')->group(function () {
    Route::get('notifications',             [NotificationController::class, 'index']);
    Route::patch('notifications/read-all',  [NotificationController::class, 'readAll']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'read']);
});

// ── Perpanjangan sertifikat ───────────────────────────────────────────────────
// Dibuka untuk buyer pemiliknya sendiri, bukan admin saja: membayar perpanjangan
// adalah keputusan pembeli. Pembatasan pemiliknya ada di dalam renew().
Route::middleware('jwt')->group(function () {
    // WAJIB sebelum adoptions/{id} kalau ada; segmen statis dulu.
    Route::get('adoptions/pohon-saya',  [TreeAdoptionController::class, 'pohonSaya']);
    Route::get('adoptions/expiring',    [TreeAdoptionController::class, 'expiring']);
    Route::post('adoptions/{id}/renew', [TreeAdoptionController::class, 'renew']);
});

// ── Gaji karbon ───────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('carbon-salaries',          [CarbonSalaryController::class, 'index']);
    Route::post('carbon-salaries/compute', [CarbonSalaryController::class, 'compute']);
});
