# Setup Backend Giricahyo Carbon (Laravel)

## Apa yang sudah dibenarkan dari versi sebelumnya

Folder zip yang kamu upload sebelumnya **tidak punya file `artisan`** dan
seluruh kerangka Laravel (folder `public/`, `resources/`, `storage/`, dll)
karena AI sebelumnya salah menjalankan command pembuatan folder (brace
expansion `{app/{Http,...}}` gagal di shell yang dipakai, sehingga
membuat folder bernama literal `{app` dkk, bukan struktur folder
sungguhan).

Yang saya lakukan: mengambil skeleton resmi Laravel 11.6.1 (sesuai versi
yang diminta `composer.json` kamu) dan menggabungkannya dengan semua kode
kustom yang sudah ditulis (Models, Controllers, migrations, seeders,
routes, config). Saat mengaudit kode itu, saya temukan dan benarkan
beberapa bug:

1. **Bug autoloading fatal**: `app/Models/CarbonFundIncome.php` berisi
   TIGA class dalam satu file (`CarbonFundIncome`, `FundDistribution`,
   `TrendData`). PSR-4 autoloading Laravel mengharuskan satu file = satu
   class senama dengan file-nya — dua class lainnya tidak akan pernah
   ditemukan dan akan error "Class not found" saat dipanggil. Sudah saya
   pisah jadi 3 file terpisah.
2. **Bug keamanan**: `FarmerController::show()` dan `BuyerController::show()`
   tidak mengecek siapa yang sedang login — petani/buyer manapun bisa
   melihat data petani/buyer LAIN hanya dengan mengganti ID di URL. Sudah
   ditambahkan pengecekan otorisasi.
3. **Bug keamanan**: `TreeController::index()` memfilter berdasarkan
   `farmer_id` dari query parameter yang dikirim client, bukan dari
   identitas akun yang login — petani bisa melihat pohon petani lain
   dengan mengganti parameter di URL. Sudah dipaksa pakai `linked_id`
   dari token JWT untuk role farmer.
4. **Bug kebocoran data**: endpoint publik (`/trees/{id}/public`, yang
   dibuka tanpa login lewat scan QR) memanggil method yang sama dengan
   endpoint privat — artinya info buyer/sertifikat ikut terekspos ke
   publik. Sudah dipisah jadi method `showPublic()` yang hanya
   mengembalikan 4 info yang memang dimaksudkan untuk publik.
5. **Fitur yang hilang**: tidak ada endpoint untuk mengubah status
   aktif/nonaktif petani (`farmers.status`) — padahal ini sudah jadi
   fitur di frontend mock sebelumnya. Sudah ditambahkan
   `PATCH /api/farmers/{id}/status`, sinkron otomatis dengan status
   login akun terkait (dua arah, baik diubah dari sisi farmer atau
   account).
6. **Validasi data finansial lemah**: `CarbonFundController::storeIncome()`
   sebelumnya menerima `total_amount` mentah dari client, padahal
   seharusnya dihitung backend dari `qty x unit_price` supaya data
   finansial yang akan diaudit tidak bisa dimanipulasi dari sisi client.
7. **Statistik admin yang belum lengkap**: `DashboardController::summary()`
   ditambahkan `top_farmers` (3 kontributor CO2 teratas) dan
   `total_buyers`, supaya konsisten dengan kartu insight yang sudah ada
   di frontend.

## Yang BELUM saya verifikasi — perlu kamu tes sendiri

Saya audit semua kode secara manual (baca baris per baris + `php -l`
untuk cek syntax), TAPI saya tidak punya akses ke packagist.org dari
environment saya untuk menjalankan `composer install` dan benar-benar
menyalakan server untuk tes langsung. Jadi:
- Saya yakin tidak ada syntax error (sudah dicek dengan `php -l` di
  semua file)
- Saya cukup yakin logikanya benar berdasarkan pembacaan manual
- Saya TIDAK bisa memastikan 100% tidak ada runtime error (misalnya
  typo nama kolom yang baru ketahuan saat query benar-benar dijalankan)
  sampai kamu coba sendiri

## Langkah setup di komputer kamu

### 1. Install dependency PHP
```bash
cd giricahyo-backend
composer install
```

### 2. Siapkan file environment
```bash
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

### 3. Siapkan database MySQL
Buat database baru bernama `giricahyo_carbon` (atau sesuaikan nama di
`.env`), lalu edit `.env` sesuai kredensial MySQL kamu:
```
DB_DATABASE=giricahyo_carbon
DB_USERNAME=root
DB_PASSWORD=isi_password_mysql_kamu
```

### 4. Jalankan migration + seeder
```bash
php artisan migrate --seed
```
Kalau berhasil, akan muncul pesan "Semua data awal berhasil diisi." dari
`DatabaseSeeder.php`.

### 5. Jalankan server
```bash
php artisan serve
```
Server akan jalan di `http://localhost:8000`. Test dengan:
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@giricahyo.id","password":"admin123"}'
```
Kalau berhasil, akan mengembalikan JSON berisi `token`.

### Kalau ada error saat langkah-langkah di atas
Kabari saya persis pesan errornya (copy-paste lengkap dari terminal).
Karena saya tidak bisa menjalankan `composer install` dari sisi saya,
kemungkinan ada error yang baru muncul di tahap ini yang belum saya bisa
deteksi lewat audit manual — paling cepat saya bantu kalau saya tahu
pesan errornya persis.

## Akun login untuk testing

| Role   | Email                 | Password    |
|--------|------------------------|-------------|
| Admin  | admin@giricahyo.id    | admin123    |
| Petani | slamet@giricahyo.id   | petani123   |
| Buyer  | buyer@giricahyo.id    | buyer123    |

(Lihat `database/seeders/AccountSeeder.php` untuk daftar lengkap akun
petani lainnya.)

## Pertanyaan desain yang belum saya putuskan sepihak

Saat audit, saya temukan `tree_adoptions.tree_id` punya constraint
`unique` — artinya satu pohon hanya bisa punya satu sertifikat/adopsi
selamanya. Ini masuk akal untuk paket "Tree Adoption" (personal,
eksklusif), tapi mungkin terlalu kaku untuk paket "Basic Carbon
Contribution" yang sifatnya kontribusi umum ke carbon fund (tidak harus
terikat ke satu pohon spesifik, dan mungkin boleh berulang dari buyer
berbeda). Saya TIDAK mengubah ini karena ini keputusan bisnis, bukan bug
teknis — diskusikan dulu sebelum constraint ini diubah.
