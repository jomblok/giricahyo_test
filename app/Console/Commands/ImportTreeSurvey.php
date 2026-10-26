<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\CarbonMeasurement;
use App\Models\Farmer;
use App\Models\Species;
use App\Models\Tree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Impor data survei pohon lapangan sesungguhnya dari
 * "Pemetaan tanaman griya jati rasa.xlsx" — menggantikan data demo sintetis
 * dengan 187 pohon nyata dari 3 lokasi survei.
 *
 * Dibuat sebagai Artisan command (bukan skrip sekali pakai) supaya bisa
 * dijalankan ulang kalau workbook-nya direvisi — lihat --dry-run untuk
 * memeriksa hasil parsing tanpa menyentuh database.
 *
 *   php artisan import:tree-survey "path/ke/file.xlsx" --dry-run
 *   php artisan import:tree-survey "path/ke/file.xlsx"
 */
class ImportTreeSurvey extends Command
{
    protected $signature = 'import:tree-survey {path : Path ke file .xlsx} {--dry-run : Tampilkan hasil parsing tanpa menulis ke database}';
    protected $description = 'Impor 187 pohon dari workbook survei lapangan (lokasi 1/2/3) ke tabel trees/carbon_measurements/farmers/species';

    // Peta kolom PERSIS per sheet — diverifikasi manual satu-satu terhadap
    // file asli (openpyxl), karena 3 sheet ini TIDAK punya layout kolom yang
    // sama (lokasi 3 punya 2 kolom kosong ekstra sebelum kolom rho).
    // Urutan: no, nama, gps, h, dbh, rho.
    private const SHEET_CONFIG = [
        'lokasi 1 ' => ['code' => 'L1', 'farmer_name' => 'Petani Lokasi 1', 'date' => '2026-06-25',
            'col' => ['no' => 1, 'nama' => 2, 'gps' => 3, 'h' => 6, 'dbh' => 7, 'rho' => 9,
                'wb_agb' => 10, 'wb_bgb' => 11, 'wb_total' => 12, 'wb_carbon' => 13, 'wb_co2' => 14], 'last_row' => 78],
        'lokasi 2' => ['code' => 'L2', 'farmer_name' => 'Petani Lokasi 2', 'date' => '2026-07-09',
            'col' => ['no' => 1, 'nama' => 2, 'gps' => 3, 'h' => 6, 'dbh' => 7, 'rho' => 9,
                'wb_agb' => 10, 'wb_bgb' => 11, 'wb_total' => 12, 'wb_carbon' => 13, 'wb_co2' => 14], 'last_row' => 94],
        'lokasi 3' => ['code' => 'L3', 'farmer_name' => 'Kelompok 3', 'date' => '2026-06-10',
            'col' => ['no' => 1, 'nama' => 2, 'gps' => 3, 'h' => 6, 'dbh' => 7, 'rho' => 11,
                'wb_agb' => 12, 'wb_bgb' => 13, 'wb_total' => 14, 'wb_carbon' => 15, 'wb_co2' => 16], 'last_row' => 33],
    ];

    // ρ literatur untuk spesies yang tidak terukur di lapangan (lihat plan —
    // 8 pohon di lokasi 1: kelapa, flamboyan, "tanaman x (mungkin Johar)").
    // Bukan hasil ukur — ditandai jelas di log impor.
    private const RHO_FALLBACK = [
        'kelapa'    => 0.35,
        'flamboyan' => 0.40,
        'johar'     => 0.55,
    ];

    private array $log = []; // baris utuh untuk file audit CSV
    private array $speciesSeen = []; // nama => rho pertama ditemukan

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $spreadsheet = IOFactory::load($path);

        $this->importSpeciesFromHerbarium($spreadsheet, $dryRun);

        $totalImported = 0;
        $totalSkipped = 0;
        $rhoFallbackUsed = 0;

        foreach (self::SHEET_CONFIG as $sheetName => $cfg) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (!$sheet) {
                $this->warn("Sheet '{$sheetName}' tidak ditemukan, dilewati.");
                continue;
            }

            $farmerId = $dryRun ? '(dry-run)' : $this->ensureFarmer($cfg['farmer_name'], $cfg['code']);

            $this->info("== {$sheetName} → farmer {$farmerId} ({$cfg['farmer_name']}) ==");

            $seq = 0;
            for ($row = 7; $row <= $cfg['last_row']; $row++) {
                $no = $sheet->getCell([$cfg['col']['no'], $row])->getCalculatedValue();
                if (!is_numeric($no)) {
                    continue; // baris kosong / baris ringkasan di bawah tabel (lokasi 2)
                }
                $seq++;

                $namaRaw = (string) $sheet->getCell([$cfg['col']['nama'], $row])->getCalculatedValue();
                $gpsRaw  = (string) $sheet->getCell([$cfg['col']['gps'], $row])->getCalculatedValue();
                $hRaw    = $sheet->getCell([$cfg['col']['h'], $row])->getCalculatedValue();
                $dbhRaw  = $sheet->getCell([$cfg['col']['dbh'], $row])->getCalculatedValue();
                $rhoRaw  = $sheet->getCell([$cfg['col']['rho'], $row])->getCalculatedValue();
                // Angka ASLI workbook (dihitung tim lapangan, konstanta beda
                // tipis dari Tree::hitungCo2() — lihat komentar plan) —
                // disimpan di log audit sebagai pembanding, tidak pernah
                // ditulis ke database.
                $wbAgb    = $sheet->getCell([$cfg['col']['wb_agb'], $row])->getCalculatedValue();
                $wbBgb    = $sheet->getCell([$cfg['col']['wb_bgb'], $row])->getCalculatedValue();
                $wbTotal  = $sheet->getCell([$cfg['col']['wb_total'], $row])->getCalculatedValue();
                $wbCarbon = $sheet->getCell([$cfg['col']['wb_carbon'], $row])->getCalculatedValue();
                $wbCo2    = $sheet->getCell([$cfg['col']['wb_co2'], $row])->getCalculatedValue();

                $species = $this->cleanSpeciesName((string) $namaRaw);
                $coords  = $this->parseDmsPair($gpsRaw);
                $heightM = $this->parseMeasurement((string) $hRaw, 'm');
                $dbhCm   = $this->parseMeasurement((string) $dbhRaw, 'cm');
                $rho     = is_numeric($rhoRaw) ? (float) $rhoRaw : $this->parseCommaFloat((string) $rhoRaw);

                $usedFallbackRho = false;
                if (!$rho) {
                    foreach (self::RHO_FALLBACK as $needle => $fallback) {
                        if (stripos($species, $needle) !== false || stripos($namaRaw, $needle) !== false) {
                            $rho = $fallback;
                            $usedFallbackRho = true;
                            $rhoFallbackUsed++;
                            break;
                        }
                    }
                }

                if (!$coords || !$heightM || !$dbhCm || !$rho) {
                    $totalSkipped++;
                    $this->warn("  [SKIP] {$sheetName} baris {$row} (No {$no}, {$species}): data tidak lengkap — gps=" .
                        ($coords ? 'ok' : 'HILANG') . " H=" . ($heightM ?: 'HILANG') . " DBH=" . ($dbhCm ?: 'HILANG') . " rho=" . ($rho ?: 'HILANG'));
                    continue;
                }

                // Batas kewajaran DBH: pohon beringin/asam jawa tertua di data
                // ini sah sampai ~640 cm (akar papan raksasa), jadi ambang di
                // 1000 cm masih longgar untuk pohon nyata manapun. Beberapa
                // baris "pohon jati" di lokasi 2 tertulis satuan "m" bukan
                // "cm" di sel aslinya (mis. "25,3m") — kalau diloloskan apa
                // adanya jadi batang setebal 25 meter, mustahil secara fisik.
                // Ini kesalahan input di lapangan, bukan sesuatu yang aman
                // ditebak nilainya di sini — dilewati dengan jelas, bukan
                // diam-diam "diperbaiki" jadi angka karangan.
                if ($dbhCm > 1000) {
                    $totalSkipped++;
                    $this->warn("  [SKIP] {$sheetName} baris {$row} (No {$no}, {$species}): DBH={$dbhCm}cm tidak masuk akal " .
                        "(kemungkinan salah satuan di sel asli — tertulis 'm', seharusnya 'cm'). Perlu dikonfirmasi ulang ke tim lapangan.");
                    continue;
                }

                $treeId = sprintf('GJR-%s-%03d', $cfg['code'], $seq);
                $carbon = Tree::hitungCo2($dbhCm, $heightM, $rho);

                if (!isset($this->speciesSeen[$species])) {
                    $this->speciesSeen[$species] = $rho;
                }

                $this->log[] = [
                    'tree_id' => $treeId, 'sheet' => $sheetName, 'row' => $row, 'species' => $species,
                    'lat' => $coords[0], 'lng' => $coords[1], 'h_m' => $heightM, 'dbh_cm' => $dbhCm,
                    'rho' => $rho, 'rho_fallback' => $usedFallbackRho ? 'ya' : '',
                    'co2_recomputed_kg' => $carbon['co2_eq_kg'],
                    'workbook_agb_kg' => $wbAgb, 'workbook_bgb_kg' => $wbBgb, 'workbook_total_biomass_kg' => $wbTotal,
                    'workbook_carbon_stock_kg' => $wbCarbon, 'workbook_co2_eq_kg' => $wbCo2,
                ];

                $this->line("  {$treeId}  {$species}  H={$heightM}m DBH={$dbhCm}cm rho={$rho}" .
                    ($usedFallbackRho ? ' (rho literatur)' : '') . "  CO2={$carbon['co2_eq_kg']}kg");

                if (!$dryRun) {
                    DB::transaction(function () use ($treeId, $farmerId, $species, $dbhCm, $heightM, $rho, $coords, $carbon, $cfg) {
                        Tree::create([
                            'id'            => $treeId,
                            'farmer_id'     => $farmerId,
                            'species'       => $species,
                            'dbh_cm'        => $dbhCm,
                            'height_m'      => $heightM,
                            'density_rho'   => $rho,
                            'latitude'      => $coords[0],
                            'longitude'     => $coords[1],
                            'health_status' => 'baik',
                            'planted_date'  => $cfg['date'],
                            'last_updated'  => $cfg['date'],
                            'co2_eq_kg'     => $carbon['co2_eq_kg'],
                        ]);
                        // source='impor_survei', tanpa akun pencatat: baris
                        // ini lahir dari berkas survei, bukan dari seseorang
                        // yang menekan tombol di aplikasi.
                        CarbonMeasurement::create([
                            'source'           => 'impor_survei',
                            'tree_id'          => $treeId,
                            'measurement_date' => $cfg['date'],
                            ...$carbon,
                        ]);
                    });
                }

                $totalImported++;
            }
        }

        if (!$dryRun) {
            $this->upsertSpeciesCatalog();
            $this->writeAuditLog();
        }

        $this->newLine();
        $this->info("Selesai. Diimpor: {$totalImported}  Dilewati: {$totalSkipped}  ρ literatur dipakai: {$rhoFallbackUsed}" .
            ($dryRun ? '  [DRY RUN — tidak ada perubahan database]' : ''));

        return self::SUCCESS;
    }

    // ── Petani per lokasi ────────────────────────────────────────────────
    private function ensureFarmer(string $name, string $groupSuffix): string
    {
        $existing = Farmer::where('name', $name)->first();
        if ($existing) {
            return $existing->id;
        }

        $lastId = Farmer::orderByDesc('id')->value('id'); // format F001, F002, ...
        $nextNum = $lastId ? ((int) substr($lastId, 1)) + 1 : 1;
        $farmerId = 'F' . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

        Farmer::create([
            'id' => $farmerId, 'name' => $name, 'group_coop' => "Lokasi {$groupSuffix}",
            'address' => 'Desa Griya Jati Rasa, Purwosari, Gunungkidul', 'status' => 'active',
        ]);

        $lastAcc = Account::orderByDesc('id')->value('id'); // format ACC001, ...
        $nextAccNum = $lastAcc ? ((int) substr($lastAcc, 3)) + 1 : 1;
        $accId = 'ACC' . str_pad((string) $nextAccNum, 3, '0', STR_PAD_LEFT);
        $emailSlug = strtolower(str_replace(' ', '', $groupSuffix));

        Account::create([
            'id' => $accId, 'email' => "{$emailSlug}@giricahyo.id", 'password' => Hash::make('petani123'),
            'role' => 'farmer', 'name' => $name, 'linked_id' => $farmerId, 'deactivated' => false,
        ]);

        $this->info("  + Petani baru {$farmerId} / akun {$accId} ({$emailSlug}@giricahyo.id / petani123)");
        return $farmerId;
    }

    // ── Katalog jenis pohon ──────────────────────────────────────────────
    private function importSpeciesFromHerbarium($spreadsheet, bool $dryRun): void
    {
        $sheet = $spreadsheet->getSheetByName('Herbarium');
        if (!$sheet) {
            return;
        }
        for ($row = 3; $row <= 46; $row++) {
            $namaLokal = $sheet->getCell([2, $row])->getCalculatedValue();
            if (!$namaLokal || !is_string($namaLokal)) {
                continue;
            }
            $species = $this->cleanSpeciesName($namaLokal);
            if (!isset($this->speciesSeen[$species])) {
                $this->speciesSeen[$species] = null; // rho diisi belakangan dari data pohon kalau ada
            }
        }
        if (!$dryRun) {
            // Diselesaikan di upsertSpeciesCatalog() setelah semua pohon diproses,
            // supaya spesies dari Herbarium yang juga punya pengukuran nyata
            // memakai rho terukur, bukan kosong.
        }
    }

    private function upsertSpeciesCatalog(): void
    {
        foreach ($this->speciesSeen as $name => $rho) {
            if ($rho === null) {
                continue; // spesies dari Herbarium tanpa pengukuran rho nyata — tidak berguna utk autofill, dilewati
            }
            Species::updateOrCreate(['name' => $name], ['density_rho' => $rho]);
        }
    }

    // ── Log audit (provenance) ───────────────────────────────────────────
    // carbon_measurements TIDAK punya kolom catatan bebas (skema sudah ada
    // sebelum tugas ini, tidak diubah untuk hal ini) dan tidak ada layar
    // manapun di app yang menampilkannya — jadi provenance angka ASLI dari
    // workbook (sebelum dihitung ulang lewat Tree::hitungCo2) disimpan
    // sebagai file CSV terpisah, bukan kolom database baru yang tidak akan
    // pernah terlihat siapa pun.
    private function writeAuditLog(): void
    {
        $path = storage_path('app/tree-survey-import-log.csv');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['tree_id', 'sheet', 'row', 'species', 'lat', 'lng', 'h_m', 'dbh_cm', 'rho', 'rho_fallback', 'co2_recomputed_kg',
            'workbook_agb_kg', 'workbook_bgb_kg', 'workbook_total_biomass_kg', 'workbook_carbon_stock_kg', 'workbook_co2_eq_kg']);
        foreach ($this->log as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
        $this->info("Log audit ditulis ke: {$path}");
    }

    // ── Parsing helpers ──────────────────────────────────────────────────

    // "8°01'38.8\"S 110°22'07.1\"E" -> [-8.027444, 110.368639]
    private function parseDmsPair(string $raw): ?array
    {
        $pattern = '/(\d+)°(\d+)\'([\d.]+)"\s*([NSEW])/u';
        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);
        if (count($matches) < 2) {
            return null;
        }
        $decimals = [];
        foreach (array_slice($matches, 0, 2) as $m) {
            [, $deg, $min, $sec, $hemi] = $m;
            $val = (float) $deg + ((float) $min) / 60 + ((float) $sec) / 3600;
            if (in_array($hemi, ['S', 'W'], true)) {
                $val = -$val;
            }
            $decimals[] = round($val, 6);
        }
        return $decimals; // [lat, lng] — sheet selalu tulis lat (S) dulu lalu lng (E)
    }

    // Angka Indonesia (koma desimal) + boleh lebih dari satu angka dalam satu
    // sel (pohon bercabang banyak, dirata-rata sesuai catatan di sheet) +
    // satuan campuran cm/m pada kolom DBH (satu baris tercatat dalam meter,
    // bukan cm — dikonversi ke satuan target kolom).
    private function parseMeasurement(string $raw, string $targetUnit): ?float
    {
        if ($raw === '' || $raw === null) {
            return null;
        }
        // Tangkap "angka" + satuan opsional (m / cm) berpasangan, dalam urutan kemunculan.
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(cm|m)?\b/iu', $raw, $matches, PREG_SET_ORDER);
        $values = [];
        foreach ($matches as $m) {
            $num = (float) str_replace(',', '.', $m[1]);
            $unit = strtolower($m[2] ?? '');
            if ($unit === 'm' && $targetUnit === 'cm') {
                $num *= 100;
            } elseif ($unit === 'cm' && $targetUnit === 'm') {
                $num /= 100;
            }
            // Tanpa satuan eksplisit: asumsikan sudah dalam satuan kolom (target).
            if ($num > 0) {
                $values[] = $num;
            }
        }
        if (!$values) {
            return null;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function parseCommaFloat(string $raw): ?float
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)/', $raw, $m)) {
            return null;
        }
        return (float) str_replace(',', '.', $m[1]);
    }

    // "pohon jati 1 (Tectona grandis)" -> "Jati"
    // "Pohon Beringin (4) \n(Ficus benjamina)" -> "Beringin"
    private function cleanSpeciesName(string $raw): string
    {
        $raw = str_replace(["\u{200E}", "\u{200F}"], '', $raw); // left/right-to-right marks
        $firstLine = trim(explode("\n", $raw)[0]);

        // Kasus khusus: identifikasi tidak pasti di lapangan ("tanaman x
        // (mungkin Johar)") — dicek SEBELUM prefix "tanaman " dibuang di
        // bawah, supaya ketidakpastiannya tetap kelihatan di nama alih-alih
        // diam-diam disingkat jadi cuma "X".
        if (stripos($firstLine, 'tanaman x') !== false) {
            return 'Johar (belum teridentifikasi pasti)';
        }

        $noParens = trim(preg_replace('/\([^)]*\)/u', '', $firstLine));
        $noPrefix = preg_replace('/^(pohon|tanaman)\s+/iu', '', $noParens);
        $noTrailingNum = trim(preg_replace('/\s*\(?\d+\)?\s*$/u', '', $noPrefix));
        $clean = $noTrailingNum !== '' ? $noTrailingNum : $noParens;

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }
}
