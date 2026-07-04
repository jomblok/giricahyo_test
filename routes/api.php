<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\FarmerController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\TreeController;
use App\Http\Controllers\SpeciesController;
use App\Http\Controllers\CarbonFundController;
use App\Http\Controllers\TreeAdoptionController;
use App\Http\Controllers\DashboardController;

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
    Route::post('login',  [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('jwt');
    Route::get('me',      [AuthController::class, 'me'])->middleware('jwt');
});

// ── Publik: kartu identitas pohon (dibuka saat QR di-scan) ───────────────────
// SENGAJA pakai method terpisah (showPublic) yang hanya mengembalikan info
// terbatas — jangan diarahkan ke show() biasa, karena itu menyertakan data
// buyer/sertifikat yang seharusnya tidak terekspos ke publik tanpa login.
Route::get('trees/{id}/public', [TreeController::class, 'showPublic']);

// ── Dashboard & KPI (hanya admin) ────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('dashboard/trend',   [DashboardController::class, 'trend']);
});

// ── Pohon ────────────────────────────────────────────────────────────────────
Route::middleware('jwt')->group(function () {
    Route::get('trees',                   [TreeController::class, 'index']);   // admin & farmer (difilter di controller)
    Route::get('trees/{id}',              [TreeController::class, 'show']);
});
Route::middleware('jwt:admin')->group(function () {
    Route::post('trees',                  [TreeController::class, 'store']);
    Route::patch('trees/{id}/health',     [TreeController::class, 'updateHealth']);
});

// ── Petani ────────────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('farmers', [FarmerController::class, 'index']);
});
Route::middleware('jwt')->group(function () {
    // Petani boleh akses data dirinya sendiri; admin bisa akses semua
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
});

// ── Jenis pohon / Katalog ─────────────────────────────────────────────────────
Route::get('species',  [SpeciesController::class, 'index'])->middleware('jwt');
Route::post('species', [SpeciesController::class, 'store'])->middleware('jwt:admin');

// ── Akun / User management (hanya admin) ────────────────────────────────────
Route::middleware('jwt:admin')->prefix('accounts')->group(function () {
    Route::get('/',                        [AccountController::class, 'index']);
    Route::post('/',                       [AccountController::class, 'store']);
    Route::patch('{id}/deactivate',        [AccountController::class, 'deactivate']);
    Route::patch('{id}/password',          [AccountController::class, 'updatePassword']);
});

// ── Carbon Fund ───────────────────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('carbon-fund/income',       [CarbonFundController::class, 'income']);
    Route::post('carbon-fund/income',      [CarbonFundController::class, 'storeIncome']);
    Route::get('carbon-fund/distribution', [CarbonFundController::class, 'distribution']);
    Route::get('carbon-fund/summary',      [CarbonFundController::class, 'summary']);
});

// ── Sertifikat / Adopsi pohon ─────────────────────────────────────────────────
Route::middleware('jwt:admin')->group(function () {
    Route::get('adoptions',  [TreeAdoptionController::class, 'index']);
    Route::post('adoptions', [TreeAdoptionController::class, 'store']);
});
