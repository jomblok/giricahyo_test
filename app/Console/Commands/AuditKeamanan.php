<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Memeriksa kesiapan keamanan sebelum sistem dipakai sungguhan.
 *
 * KENAPA SEBUAH PERINTAH, BUKAN LANGSUNG DIPERBAIKI. Lima hal di SECURITY.md
 * bagian 4 semuanya bersifat waktu-deploy, dan menjalankannya SEKARANG di mesin
 * pengembangan justru merusak tanpa menghasilkan keamanan apa pun:
 *
 *   - mengganti kata sandi demo mengunci pengurus dari dasbornya sendiri saat
 *     demo PKM masih berjalan
 *   - APP_DEBUG=false menyembunyikan pesan galat yang justru dibutuhkan saat
 *     mengembangkan, dan di localhost tidak ada yang bisa membacanya
 *   - memberi sandi MySQL root memutus .env dan phpMyAdmin sampai keduanya
 *     ikut diperbarui
 *   - memindahkan JWT ke cookie httpOnly menyentuh autentikasi, CORS, dan CSRF
 *     sekaligus — perubahan arsitektur, bukan penyetelan
 *
 * Jadi yang dikerjakan di sini adalah MEMERIKSA dan MELAPORKAN, supaya keadaan
 * sebenarnya terlihat kapan saja tanpa harus membaca ulang dokumen. Perbaikan
 * yang sesungguhnya dijalankan sendiri saat deploy.
 *
 * Keluarannya sengaja menyebut APA YANG HARUS DILAKUKAN, bukan cuma lulus atau
 * gagal — daftar periksa yang tidak memberi tahu langkah berikutnya akan
 * diabaikan.
 */
class AuditKeamanan extends Command
{
    protected $signature = 'audit:keamanan';

    protected $description = 'Periksa lima hal keamanan yang wajib dibereskan sebelum go-live';

    private int $aman = 0;
    private int $bahaya = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Audit keamanan — Giricahyo Carbon</>');
        $this->line('  ' . str_repeat('─', 62));
        $this->newLine();

        $this->periksaKataSandi();
        $this->periksaAppDebug();
        $this->periksaMysql();
        $this->periksaJwt();
        $this->periksaCsp();

        $this->newLine();
        $this->line('  ' . str_repeat('─', 62));
        $this->line(sprintf('  %d aman · <fg=red>%d perlu dibereskan sebelum go-live</>', $this->aman, $this->bahaya));
        $this->newLine();
        $this->line('  Rinciannya di SECURITY.md bagian 4.');
        $this->newLine();

        // Selalu keluar 0: ini laporan, bukan gerbang CI. Menggagalkan perintah
        // saat masih di mesin pengembangan — di mana semua temuan ini WAJAR —
        // akan membuat orang terbiasa mengabaikan keluarannya.
        return self::SUCCESS;
    }

    private function lapor(bool $lulus, string $judul, string $keadaan, string $tindakan): void
    {
        if ($lulus) {
            $this->aman++;
            $this->line("  <fg=green>✓</> <options=bold>{$judul}</>");
            $this->line("    {$keadaan}");
        } else {
            $this->bahaya++;
            $this->line("  <fg=red>✗</> <options=bold>{$judul}</>");
            $this->line("    {$keadaan}");
            $this->line("    <fg=yellow>→ {$tindakan}</>");
        }
        $this->newLine();
    }

    /**
     * Kata sandi demo diuji dengan benar-benar MENCOCOKKANNYA ke hash di basis
     * data, bukan dengan menebak dari nama berkas seeder. Seeder bisa saja
     * sudah diubah; yang menentukan adalah isi tabelnya.
     */
    private function periksaKataSandi(): void
    {
        $lemah = ['admin123', 'petani123', 'buyer123', 'password', '12345678'];
        $kena = [];

        foreach (Account::all(['id', 'email', 'password']) as $akun) {
            foreach ($lemah as $sandi) {
                if (Hash::check($sandi, $akun->password)) {
                    $kena[] = $akun->email;
                    break;
                }
            }
        }

        $this->lapor(
            empty($kena),
            'Kata sandi akun',
            empty($kena)
                ? 'Tidak ada akun yang memakai kata sandi demo.'
                : count($kena) . ' akun memakai kata sandi demo: ' . implode(', ', array_slice($kena, 0, 4))
                    . (count($kena) > 4 ? ' dan ' . (count($kena) - 4) . ' lagi' : ''),
            'Ganti lewat menu Pengguna, atau PATCH /api/accounts/{id}/password.'
        );
    }

    private function periksaAppDebug(): void
    {
        $debug = (bool) config('app.debug');
        $env = (string) config('app.env');
        $produksi = $env === 'production';

        // Di lingkungan lokal, APP_DEBUG=true memang BENAR. Yang berbahaya
        // hanya kombinasi produksi + debug menyala.
        $this->lapor(
            ! ($produksi && $debug),
            'APP_DEBUG',
            "APP_ENV={$env}, APP_DEBUG=" . ($debug ? 'true' : 'false')
                . ($produksi ? '' : ' — wajar untuk pengembangan lokal'),
            'Setel APP_DEBUG=false di .env sebelum deploy; ia membocorkan jejak tumpukan dan konfigurasi.'
        );
    }

    private function periksaMysql(): void
    {
        $sandi = (string) config('database.connections.mysql.password');
        $user = (string) config('database.connections.mysql.username');

        $this->lapor(
            $sandi !== '',
            'Kredensial basis data',
            $sandi === ''
                ? "Pengguna '{$user}' terhubung TANPA kata sandi."
                : "Pengguna '{$user}' memakai kata sandi.",
            'Beri kata sandi, dan pakai akun terbatas — bukan root — lalu perbarui .env.'
        );
    }

    private function periksaJwt(): void
    {
        // Tidak bisa diperiksa dari sisi server: penyimpanan token adalah
        // keputusan frontend. Yang bisa diperiksa, JWT_SECRET sudah terisi.
        $rahasia = (string) config('jwt.secret');

        $this->lapor(
            false,
            'Penyimpanan token JWT',
            $rahasia === ''
                ? 'JWT_SECRET BELUM dibuat — jalankan php artisan jwt:secret.'
                : 'JWT_SECRET terisi. Frontend menyimpan token di localStorage, '
                  . 'sehingga satu XSS bisa membacanya dan membajak sesi.',
            'Pindahkan ke cookie httpOnly + Secure + SameSite. Perubahan arsitektur — rencanakan tersendiri.'
        );
    }

    private function periksaCsp(): void
    {
        // CSP disusun di vite.config.js dan kini diturunkan dari VITE_API_URL,
        // jadi yang diperiksa dari sini adalah apakah backend sudah dilayani
        // lewat HTTPS — syarat agar HSTS dan cookie Secure berarti.
        $url = (string) config('app.url');
        $https = str_starts_with($url, 'https://');

        $this->lapor(
            $https,
            'HTTPS',
            "APP_URL={$url}" . ($https ? '' : ' — masih HTTP'),
            'Deploy di belakang HTTPS. SecurityHeaders mengirim HSTS otomatis begitu koneksinya aman, '
                . 'dan CSP connect-src mengikuti VITE_API_URL tanpa perlu diubah manual.'
        );
    }
}
