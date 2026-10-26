<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Mencadangkan basis data ke berkas .sql.
 *
 * KENAPA INI YANG PALING MENDESAK. 179 pohon di basis data adalah hasil survei
 * lapangan yang TIDAK BISA DIHASILKAN ULANG: perintah `import:tree-survey`
 * mencari sheet bernama `lokasi 1 `, `lokasi 2`, `lokasi 3`, sedangkan berkas
 * Excel yang tersedia sekarang memakai nama lain dan sama sekali tidak punya
 * sheet lokasi 2. Sudah diuji — dry-run-nya menghasilkan `Diimpor: 0`.
 *
 * Artinya satu disk rusak, satu `DROP DATABASE` salah ketik, atau satu
 * instalasi XAMPP yang korup menghapus seluruh data lapangan secara permanen.
 * Semua pekerjaan lain di sistem ini — angka karbon, gaji, sertifikat — berdiri
 * di atas 179 baris itu.
 *
 * SENGAJA MEMAKAI mysqldump, BUKAN MENULIS SQL SENDIRI. Dump buatan sendiri
 * selalu melewatkan sesuatu (urutan foreign key, charset, kolom yang baru
 * ditambah migrasi) dan kesalahannya baru ketahuan saat pemulihan — yaitu saat
 * paling buruk untuk menemukannya. mysqldump sudah dipasang XAMPP dan
 * hasilnya bisa dipulihkan dengan perintah mysql biasa.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--simpan=10 : Berapa cadangan terakhir yang disimpan}
                            {--ke= : Folder tujuan (bawaan: storage/app/backups)}';

    protected $description = 'Cadangkan basis data ke berkas .sql dan buang cadangan lama';

    public function handle(): int
    {
        $db = (string) config('database.connections.mysql.database');
        $user = (string) config('database.connections.mysql.username');
        $sandi = (string) config('database.connections.mysql.password');
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port');

        $folder = $this->option('ke') ?: storage_path('app/backups');
        File::ensureDirectoryExists($folder);

        $berkas = $folder . DIRECTORY_SEPARATOR
            . sprintf('%s_%s.sql', $db, now()->format('Y-m-d_His'));

        $dump = $this->cariMysqldump();

        if (! $dump) {
            $this->error('  mysqldump tidak ditemukan. Pasang MySQL/MariaDB client, atau');
            $this->error('  jalankan dengan PATH yang memuat folder bin-nya.');
            return self::FAILURE;
        }

        // Jumlah baris dicatat SEBELUM dan dibandingkan dengan isi dump —
        // cadangan yang ternyata kosong lebih berbahaya daripada tidak punya
        // cadangan sama sekali, karena ia memberi rasa aman yang palsu.
        $sebelum = [];
        foreach (['trees', 'carbon_measurements', 'farmers', 'accounts', 'species'] as $t) {
            try {
                $sebelum[$t] = DB::table($t)->count();
            } catch (\Throwable) {
                $sebelum[$t] = null;
            }
        }

        $this->newLine();
        $this->line("  Mencadangkan <options=bold>{$db}</>...");

        $argumen = [
            $dump,
            "--host={$host}",
            "--port={$port}",
            "--user={$user}",
            // Kunci tabel saat dump supaya hasilnya konsisten — tanpa ini,
            // sertifikat yang terbit di tengah proses bisa tercatat tanpa
            // baris dana masuk pasangannya.
            '--single-transaction',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
            $db,
        ];

        // Kata sandi lewat argumen terpisah, dan hanya kalau memang ada —
        // `--password=` kosong membuat MariaDB meminta input interaktif, yang
        // akan menggantung selamanya di perintah non-interaktif seperti ini.
        if ($sandi !== '') {
            array_splice($argumen, 4, 0, ["--password={$sandi}"]);
        }

        $proses = new Process($argumen, timeout: 600);
        $proses->run();

        if (! $proses->isSuccessful()) {
            $this->error('  Gagal: ' . trim(substr($proses->getErrorOutput(), 0, 300)));
            return self::FAILURE;
        }

        File::put($berkas, $proses->getOutput());
        $ukuran = File::size($berkas);

        // Pemeriksaan isi: dump yang berhasil tapi tidak memuat tabel inti
        // berarti ada yang salah dengan hak akses, bukan dengan perintahnya.
        $isi = File::get($berkas);
        $hilang = [];
        foreach (array_keys($sebelum) as $t) {
            if (! str_contains($isi, "`{$t}`")) {
                $hilang[] = $t;
            }
        }

        if ($hilang) {
            $this->error('  Cadangan TIDAK memuat tabel: ' . implode(', ', $hilang));
            $this->error('  Berkasnya dihapus supaya tidak dikira cadangan yang sah.');
            File::delete($berkas);
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=green>✓</> ' . basename($berkas));
        $this->line(sprintf('    %s · %s', $this->ukuranTerbaca($ukuran), $folder));
        $this->newLine();

        foreach ($sebelum as $t => $n) {
            $this->line(sprintf('    %-22s %s baris', $t, $n ?? '—'));
        }

        $this->buangYangLama($folder, (int) $this->option('simpan'));

        $this->newLine();
        $this->line('  Memulihkan:');
        $this->line("    mysql -u {$user} " . ($sandi !== '' ? '-p ' : '') . "{$db} < \"{$berkas}\"");
        $this->newLine();
        $this->line('  <fg=yellow>Cadangan di disk yang sama dengan basis datanya hanya melindungi dari</>');
        $this->line('  <fg=yellow>kesalahan, bukan dari disk rusak. Salin keluar secara berkala.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * mysqldump sering tidak ada di PATH pada pemasangan XAMPP, jadi lokasi
     * lazimnya ikut dicoba sebelum menyerah.
     */
    private function cariMysqldump(): ?string
    {
        $kandidat = [
            'mysqldump',
            'C:/xampp/mysql/bin/mysqldump.exe',
            'C:/Program Files/MariaDB/bin/mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];

        foreach ($kandidat as $c) {
            if ($c === 'mysqldump') {
                $p = new Process([$c, '--version']);
                $p->run();
                if ($p->isSuccessful()) {
                    return $c;
                }
                continue;
            }
            if (is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Menyimpan N cadangan terbaru. Tanpa ini folder cadangan tumbuh tanpa
     * batas sampai disknya penuh — dan disk penuh menghentikan basis datanya
     * juga, jadi cadangan yang tidak dirapikan justru bisa menyebabkan
     * kegagalan yang seharusnya ia cegah.
     */
    private function buangYangLama(string $folder, int $simpan): void
    {
        if ($simpan < 1) {
            return;
        }

        $berkas = collect(File::files($folder))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        $buang = $berkas->slice($simpan);

        foreach ($buang as $f) {
            File::delete($f->getPathname());
        }

        if ($buang->isNotEmpty()) {
            $this->newLine();
            $this->line(sprintf('    %d cadangan lama dibuang, %d disimpan',
                $buang->count(), min($berkas->count(), $simpan)));
        }
    }

    private function ukuranTerbaca(int $bita): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $satuan) {
            if ($bita < 1024 || $satuan === 'GB') {
                return sprintf('%.1f %s', $bita, $satuan);
            }
            $bita /= 1024;
        }
        return (string) $bita;
    }
}
