# Giricahyo Carbon — Backend API (Laravel 11 + MySQL)

Backend REST API untuk dashboard manajemen bisnis karbon komunitas Giricahyo,
Purwosari, Gunungkidul. Dibangun dengan **Laravel 11**, database **MySQL**.

---

## Struktur Direktori

```
giricahyo-backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php          ← Login / logout / me
│   │   │   ├── AccountController.php       ← Kelola akun pengguna (admin)
│   │   │   ├── DashboardController.php     ← KPI summary & tren (admin)
│   │   │   ├── TreeController.php          ← CRUD pohon + kalkulasi CO₂
│   │   │   ├── FarmerController.php        ← Data petani
│   │   │   ├── BuyerController.php         ← Data buyer
│   │   │   ├── SpeciesController.php       ← Katalog jenis pohon
│   │   │   ├── CarbonFundController.php    ← Pemasukan & distribusi dana
│   │   │   └── TreeAdoptionController.php  ← Sertifikat adopsi pohon
│   │   └── Middleware/
│   │       └── JwtMiddleware.php           ← Guard JWT + cek role
│   └── Models/                             ← Eloquent models
├── config/
│   ├── auth.php       ← Guard JWT
│   ├── cors.php       ← Izinkan frontend React
│   ├── database.php   ← Koneksi MySQL
│   └── jwt.php        ← Konfigurasi JWT
├── database/
│   ├── migrations/    ← 10 file migration (urut nomor)
│   └── seeders/       ← Data awal lengkap dari frontend
├── routes/
│   └── api.php        ← Semua endpoint API
├── bootstrap/
│   └── app.php        ← Bootstrap Laravel 11
├── .env.example       ← Template konfigurasi
└── composer.json
```

---

## Prasyarat

| Software | Versi minimal |
|---|---|
| PHP | 8.2+ |
| Composer | 2.x |
| MySQL | 8.0+ |
| Laravel | 11.x |

---

## Cara Setup (Pertama Kali)

### 1. Clone / copy project ke server

```bash
# Kalau pakai Git
git clone <repo-url> giricahyo-backend
cd giricahyo-backend

# Atau extract zip, lalu masuk ke folder
cd giricahyo-backend
```

### 2. Install dependency PHP

```bash
composer install
```

### 3. Salin dan isi file konfigurasi

```bash
cp .env.example .env
```

Edit `.env` sesuai server kampus:

```env
DB_HOST=127.0.0.1          # atau IP server MySQL
DB_PORT=3306
DB_DATABASE=giricahyo_carbon
DB_USERNAME=root            # ganti dengan user MySQL kampus
DB_PASSWORD=                # isi password MySQL

FRONTEND_URL=http://localhost:5173   # ganti dengan URL frontend saat production
```

### 4. Generate APP_KEY dan JWT_SECRET

```bash
php artisan key:generate
php artisan jwt:secret
```

### 5. Buat database di MySQL

Masuk ke MySQL dan jalankan:

```sql
CREATE DATABASE giricahyo_carbon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 6. Jalankan migration (buat semua tabel)

```bash
php artisan migrate
```

### 7. Isi data awal (seed)

```bash
php artisan db:seed
```

Perintah ini akan mengisi:
- 8 petani + 1 buyer + 9 akun login
- 9 jenis pohon (katalog)
- 10 pohon dengan data pengukuran karbon
- 7 data gaji karbon (periode 2026-Q2)
- 5 pemasukan carbon fund
- 3 distribusi dana
- 2 sertifikat adopsi pohon
- 5 data tren tahunan (2022–2026)

### 8. Jalankan server development

```bash
php artisan serve
# API tersedia di: http://localhost:8000/api
```

---

## Akun Default (setelah seed)

| Role | Email | Password |
|---|---|---|
| Admin | admin@giricahyo.id | admin123 |
| Petani | slamet@giricahyo.id | petani123 |
| Petani | wartini@giricahyo.id | petani123 |
| Petani | darmaji@giricahyo.id | petani123 |
| Petani | suyono@giricahyo.id | petani123 |
| Petani | ngatinem@giricahyo.id | petani123 |
| Petani | sumarni@giricahyo.id | petani123 |
| Petani | wagiman@giricahyo.id | petani123 |
| Buyer | buyer@giricahyo.id | buyer123 |

> **Penting:** Ganti semua password di atas setelah sistem berjalan di production.

---

## Daftar Endpoint API

### Auth
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| POST | `/api/auth/login` | Login, dapat JWT token | - |
| POST | `/api/auth/logout` | Logout, invalidate token | jwt |
| GET | `/api/auth/me` | Info akun yang sedang login | jwt |

### Dashboard (Admin)
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/dashboard/summary` | KPI global (total pohon, CO₂, petani, dana) | jwt:admin |
| GET | `/api/dashboard/trend` | Data tren tahunan untuk grafik | jwt:admin |

### Pohon
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/trees` | Daftar semua pohon + filter | jwt |
| GET | `/api/trees/{id}` | Detail pohon (butuh login) | jwt |
| GET | `/api/trees/{id}/public` | Detail pohon untuk QR scan publik | - |
| POST | `/api/trees` | Tambah pohon baru (hitung CO₂ otomatis) | jwt:admin |
| PATCH | `/api/trees/{id}/health` | Update status kesehatan pohon | jwt:admin |

### Petani
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/farmers` | Daftar semua petani + ringkasan gaji | jwt:admin |
| GET | `/api/farmers/{id}` | Detail petani + pohon + gaji | jwt |

### Buyer
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/buyers` | Daftar semua buyer | jwt:admin |
| GET | `/api/buyers/{id}` | Detail buyer + pohon adopsi + sertifikat | jwt |

### Jenis Pohon
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/species` | Daftar jenis pohon + densitas | jwt |
| POST | `/api/species` | Tambah jenis pohon baru | jwt:admin |

### Akun / User Management
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/accounts` | Daftar semua akun | jwt:admin |
| POST | `/api/accounts` | Buat akun baru (farmer/buyer/admin) | jwt:admin |
| PATCH | `/api/accounts/{id}/deactivate` | Nonaktifkan akun | jwt:admin |
| PATCH | `/api/accounts/{id}/password` | Ganti password akun | jwt:admin |

### Carbon Fund
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/carbon-fund/income` | Daftar pemasukan dana | jwt:admin |
| POST | `/api/carbon-fund/income` | Catat pemasukan baru | jwt:admin |
| GET | `/api/carbon-fund/distribution` | Distribusi alokasi dana | jwt:admin |
| GET | `/api/carbon-fund/summary` | Ringkasan dana + per sumber | jwt:admin |

### Sertifikat Adopsi
| Method | Endpoint | Deskripsi | Guard |
|---|---|---|---|
| GET | `/api/adoptions` | Daftar semua sertifikat | jwt:admin |
| POST | `/api/adoptions` | Buat sertifikat adopsi baru | jwt:admin |

---

## Cara Pakai Token JWT di Frontend

Setelah login, frontend menyimpan token dan menyertakannya di setiap request:

```js
// Login
const res = await fetch('http://localhost:8000/api/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'admin@giricahyo.id', password: 'admin123' }),
});
const { token, user } = await res.json();
localStorage.setItem('token', token);

// Request berikutnya
const trees = await fetch('http://localhost:8000/api/trees', {
  headers: { 'Authorization': `Bearer ${token}` },
});
```

---

## Formula Karbon yang Dipakai

```
AGB  = 0.0673 × (ρ × D² × H)^0.976    (Above-Ground Biomass, kg)
BGB  = AGB × 0.24                       (Below-Ground Biomass, kg)
TB   = AGB + BGB                        (Total Biomass, kg)
C    = TB × 0.47                        (Carbon Stock, kg)
CO₂  = C × 3.67                        (CO₂ Equivalent, kg)
```

Formula diimplementasi di `App\Models\Tree::hitungCo2()` dan dipanggil
otomatis setiap kali pohon baru ditambahkan via `POST /api/trees`.

---

## Tips Deploy ke Server Kampus

1. Upload seluruh folder ke server (pakai FTP/SFTP atau Git)
2. Pastikan PHP 8.2+ dan extension `pdo_mysql`, `mbstring`, `xml` aktif
3. Set `APP_ENV=production` dan `APP_DEBUG=false` di `.env`
4. Jalankan: `composer install --no-dev --optimize-autoloader`
5. Jalankan: `php artisan config:cache && php artisan route:cache`
6. Pastikan folder `storage/` dan `bootstrap/cache/` bisa ditulis (chmod 775)
7. Arahkan document root server ke folder `public/`
8. Untuk Apache: pastikan `mod_rewrite` aktif dan `.htaccess` ada di `public/`

---

## Catatan untuk Tim Backend

- Model `Tree::hitungCo2()` — formula karbon, jangan diubah tanpa konfirmasi
- `AccountController::store()` — membuat farmer/buyer record secara atomik dalam DB transaction
- `TreeAdoption::generateCertNo()` — format nomor sertifikat `GJR-CERT-{TAHUN}-{NNNN}`
- Semua response error sudah dalam Bahasa Indonesia
- Semua response sukses dalam format JSON `{ message, data }`
