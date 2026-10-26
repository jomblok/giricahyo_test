<?php

namespace App\Console\Commands;

use App\Models\Tree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Mengekspor data pohon ke CSV yang bisa dibaca manusia.
 *
 * LAPISAN KEDUA, BUKAN PENGGANTI `db:backup`. Keduanya menjawab kegagalan yang
 * berbeda:
 *
 *   db:backup (.sql)  — memulihkan SELURUH sistem persis seperti semula.
 *                       Butuh MySQL untuk dibaca, dan tidak berguna bagi
 *                       manusia.
 *   export (.csv)     — hanya data pohonnya, tapi bisa dibuka siapa pun di
 *                       Excel, dikirim ke dosen, dilampirkan ke laporan PKM,
 *                       dan diperiksa barisnya satu per satu.
 *
 * Kalau dump SQL-nya korup atau versinya tidak cocok dengan MySQL di tempat
 * lain, CSV ini tetap terbaca. Dan sebaliknya, CSV tidak memuat sertifikat,
 * gaji, atau akun — jadi ia bukan pengganti cadangan.
 *
 * KENAPA CSV, BUKAN XLSX. phpspreadsheet tidak terpasang di proyek ini, dan
 * menambah dependensi 10 MB untuk satu perintah ekspor tidak sepadan —
 * terlebih setelah sesi ini membuktikan bahwa setiap paket npm/composer baru
 * adalah permukaan serang baru. CSV juga bisa dibaca diff git, jadi perubahan
 * pada data lapangan terlihat di riwayat versi; XLSX adalah berkas biner yang
 * diff-nya tidak berarti apa-apa.
 */
class ExportTreeSurvey extends Command
{
    protected $signature = 'export:tree-survey
                            {--ke= : Folder tujuan (bawaan: storage/app/backups)}';

    protected $description = 'Ekspor data pohon ke CSV yang bisa dibuka Excel';

    public function handle(): int
    {
        $folder = $this->option('ke') ?: storage_path('app/backups');
        File::ensureDirectoryExists($folder);

        $berkas = $folder . DIRECTORY_SEPARATOR
            . sprintf('pohon_%s.csv', now()->format('Y-m-d_His'));

        $pohon = Tree::with(['farmer:id,name,group_coop'])
            ->orderBy('id')
            ->get();

        if ($pohon->isEmpty()) {
            $this->error('  Tidak ada pohon untuk diekspor.');
            return self::FAILURE;
        }

        $f = fopen($berkas, 'w');

        // BOM UTF-8. Tanpa ini Excel di Windows membaca CSV sebagai ANSI, dan
        // setiap "ñ", "°", atau "₂" di nama jenis berubah jadi sampah. Diuji:
        // nama seperti "Johar (belum teridentifikasi pasti)" aman, tapi simbol
        // derajat pada catatan koordinat tidak.
        fwrite($f, "\xEF\xBB\xBF");

        fputcsv($f, [
            'id_pohon', 'id_petani', 'nama_petani', 'kelompok',
            'jenis', 'dbh_cm', 'tinggi_m', 'kerapatan_rho',
            'latitude', 'longitude', 'kondisi', 'tanggal_tanam',
            'terakhir_diperbarui', 'co2_eq_kg',
        ]);

        foreach ($pohon as $p) {
            fputcsv($f, [
                $p->id,
                $p->farmer_id,
                $p->farmer?->name,
                $p->farmer?->group_coop,
                $p->species,
                $p->dbh_cm,
                $p->height_m,
                $p->density_rho,
                // Koordinat PENUH, tidak dibulatkan seperti di endpoint publik.
                // Berkas ini untuk koperasi dan dosen, bukan untuk pengunjung
                // yang memindai QR — dan tanpa presisi penuh ia tidak bisa
                // dipakai mengimpor ulang.
                $p->latitude,
                $p->longitude,
                $p->health_status,
                $p->planted_date,
                $p->last_updated,
                $p->co2_eq_kg,
            ]);
        }

        fclose($f);

        $ukuran = File::size($berkas);
        $totalCo2 = $pohon->sum(fn ($p) => (float) $p->co2_eq_kg);

        $perLokasi = $pohon->groupBy(fn ($p) => implode('-', array_slice(explode('-', $p->id), 0, 2)))
            ->map->count()
            ->sortDesc();

        $this->newLine();
        $this->line('  <fg=green>✓</> ' . basename($berkas));
        $this->line(sprintf('    %d pohon · %.1f KB · %s', $pohon->count(), $ukuran / 1024, $folder));
        $this->newLine();

        foreach ($perLokasi as $lok => $n) {
            $this->line(sprintf('    %-12s %3d pohon', $lok, $n));
        }

        $this->newLine();
        $this->line(sprintf('    Total CO₂ : %s kg', number_format($totalCo2, 2, ',', '.')));
        $this->newLine();
        $this->line('  <fg=yellow>Berkas ini tidak memuat sertifikat, gaji, atau akun.</>');
        $this->line('  <fg=yellow>Untuk pemulihan penuh pakai `php artisan db:backup`.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
