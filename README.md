# Backend — Giricahyo Carbon

REST API dengan Laravel 11.54 dan MySQL/MariaDB. Autentikasi memakai JWT
(`tymon/jwt-auth`).

Dokumen ini hanya berisi hal yang khusus backend. Untuk **cara memasang**,
**daftar 36 endpoint**, dan **rumus karbon**, buka
[README utama di akar proyek](../README.md) — supaya tidak ada dua salinan yang
bisa saling bertentangan.

---

## Struktur direktori

```
giricahyo-backend/
├── app/
│   ├── Console/Commands/
│   │   └── ImportTreeSurvey.php      impor data survei lapangan dari .xlsx
│   ├── Http/
│   │   ├── Controllers/              11 berkas
│   │   │   ├── AuthController        login / logout / me
│   │   │   ├── DashboardController   KPI dan tren (admin)
│   │   │   ├── TreeController        CRUD pohon, foto, halaman publik
│   │   │   ├── FarmerController      data petani
│   │   │   ├── BuyerController       data buyer
│   │   │   ├── SpeciesController     katalog jenis pohon
│   │   │   ├── CarbonFundController  pemasukan & distribusi dana
│   │   │   ├── TreeAdoptionController sertifikat adopsi
│   │   │   ├── AccountController     kelola akun (admin)
│   │   │   └── PublicController      statistik tanpa login
│   │   └── Middleware/
│   │       ├── JwtMiddleware         cek token + peran
│   │       └── SecurityHeaders       header keamanan tiap respons
│   └── Models/                       12 model Eloquent + User
├── database/
│   ├── migrations/                   22 berkas
│   └── seeders/                      11 berkas
├── routes/api.php                    36 endpoint
└── .env.example
```

---

## Tiga hal yang jangan diubah tanpa berpikir dua kali

**`Tree::hitungCo2()`** memegang rumus alometrik. Ia satu-satunya tempat CO₂
dihitung, dan itu disengaja — kalau ada jalur perhitungan kedua, dua bagian
dasbor bisa menampilkan angka berbeda untuk pohon yang sama. Rumus dan contoh
perhitungannya ada di [README utama](../README.md#4-cara-serapan-karbon-dihitung).

**`TreeAdoption::generateCertNo()`** membuat nomor sertifikat
`GJR-CERT-{TAHUN}-{NNNN}`. Nomornya diambil dari tabel `certificate_sequences`
di dalam transaksi ber-`lockForUpdate()`, bukan dari `COUNT(*) + 1`. Sebabnya:
dua admin yang menekan Simpan pada detik yang sama akan mendapat nomor kembar
kalau memakai cara menghitung.

**`FIXED_PACKAGE_PRICES`** di `TreeAdoption.php` menyimpan nama paket Tree
Adoption gaya lama. Jangan dihapus — sertifikat yang sudah terbit menyimpan nama
itu di kolom `package_name`, dan tanpa tabel ini harganya berubah jadi `null`
saat ditampilkan ulang.

---

## Pola otorisasi

Setiap endpoint yang menerima `{id}` milik orang lain **wajib** memakai bentuk
daftar-putih:

```php
$boleh = $account->role === 'admin'
      || ($account->role === 'farmer' && $account->linked_id === $id);

if (! $boleh) {
    return response()->json(['message' => '…'], 403);
}
```

Bentuk sebaliknya — *"kalau peran X dan bukan miliknya, tolak"* — pernah dipakai
di sini dan terbukti bocor: setiap peran yang tidak disebut lolos tanpa
diperiksa. Buktinya dan cara mengujinya ada di
[SECURITY.md](../SECURITY.md) bagian 3.

Identitas selalu diambil dari token (`$request->attributes->get('account')`),
tidak pernah dari parameter yang dikirim peramban.

---

## Menjalankan secara lokal

Butuh **PHP 8.2.35 atau lebih baru**, MySQL/MariaDB, dan ekstensi `pdo_mysql`,
`mbstring`, `openssl`, `fileinfo`, `gd`. Batas bawah versi itu bukan formalitas:
PHP 8.2.12–8.2.34 rentan PHP2Shell (CVE-2026-109618/109619), RCE tanpa login
yang sudah dieksploitasi. Periksa dengan `php -v` sebelum mulai.

```bash
# 1. Dependensi. composer.phar ikut di repo, jadi Composer global tidak wajib.
php composer.phar install

# 2. Environment
cp .env.example .env
php artisan key:generate
php artisan jwt:secret

# 3. Buat basis data kosong dengan nama sesuai DB_DATABASE di .env
#    (bawaannya giricahyo_carbon), lalu isi DB_USERNAME / DB_PASSWORD.

# 4. Tabel + data demo — HANYA pada basis data kosong, lihat bagian Seeder
php artisan migrate --seed

# 5. Server
php artisan serve --host=127.0.0.1 --port=8000
```

`--host=127.0.0.1` disengaja: frontend tidak memanggil port ini langsung,
melainkan lewat proxy Vite (`/api` → `127.0.0.1:8000`), jadi backend tidak
perlu terlihat dari jaringan. Cek dengan:

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@giricahyo.id","password":"admin123"}'
```

Balasan berisi `token` berarti berhasil. Akun demo dari seeder:
`admin@giricahyo.id` / `admin123` dan `buyer@giricahyo.id` / `buyer123`; daftar
lengkapnya di `database/seeders/AccountSeeder.php`. **Kata sandi ini publik** —
tertulis di repositori ini — jadi wajib diganti sebelum deploy.

---

## Seeder

`DatabaseSeeder` memanggil 10 seeder berurutan dan menghasilkan satu sistem demo
yang lengkap. Seeder memakai `DB::table()->insert()` dengan ID tetap, jadi
**hanya boleh dijalankan pada basis data kosong** — kalau tidak, ia berhenti di
`Duplicate entry 'F001' for key 'PRIMARY'`.

`TreeSeeder` membuat 10 pohon contoh berpola `GJR-001-001`. Basis data yang
sedang berjalan **tidak memakainya** — 180 pohon di sana berasal dari
`import:tree-survey` dan berpola `GJR-L1-…`. Keduanya tidak saling menimpa,
tetapi jangan menjalankan seeder di atas data survei yang asli.

---

## Catatan deploy

1. `APP_ENV=production` dan `APP_DEBUG=false` di `.env` — `APP_DEBUG=true` di
   produksi membocorkan jejak tumpukan dan potongan konfigurasi ke penyerang
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan config:cache && php artisan route:cache`
4. Arahkan document root server ke folder `public/`, bukan ke akar proyek
5. `storage/` dan `bootstrap/cache/` harus bisa ditulis (chmod 775)
6. Apache: `mod_rewrite` aktif dan `.htaccess` ada di `public/`
7. Ekstensi PHP yang dibutuhkan: `pdo_mysql`, `mbstring`, `xml`
8. Setelah HTTPS aktif, `SecurityHeaders` otomatis mengirim HSTS — ia memeriksa
   `$request->isSecure()` sendiri, jadi tidak perlu diubah

Daftar lengkap hal yang wajib dibereskan sebelum go-live ada di
[SECURITY.md](../SECURITY.md) bagian 4.

---

## Konvensi respons

Pesan galat memakai Bahasa Indonesia dan menyebut apa yang harus dilakukan,
bukan hanya apa yang salah.

Sebagian besar endpoint membalas `{ message, data }`. Pengecualiannya
`GET /trees/{id}/public`, yang membalas objek datar tanpa pembungkus — dan
ketidakcocokan inilah yang membuat halaman publik rusak (lihat
[SECURITY.md](../SECURITY.md) bagian 5).
