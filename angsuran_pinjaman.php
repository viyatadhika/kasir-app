<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/**
 * Helper untuk Intelephense / VSCode.
 * $pdo berasal dari config.php.
 *
 * @var PDO $pdo
 */
global $pdo;

if (!function_exists('requireAccess')) {
    function requireAccess(): void
    {
        // Fallback aman jika project belum punya helper requireAccess().
        // Jika config.php sudah punya requireAccess(), fungsi asli tetap dipakai.
    }
}

requireAccess();

require_once __DIR__ . '/activity_helper.php';

$activeMenu = 'angsuran_pinjaman';
$pageTitle  = 'Angsuran Pinjaman';
$backUrl    = 'dashboard.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$userRole = isset($_SESSION['user']['role']) ? $_SESSION['user']['role'] : 'kasir';
if (!in_array($userRole, ['admin', 'ksp'], true)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    /**
     * @param mixed $v
     */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_ap')) {
    /**
     * @param mixed $n
     */
    function rupiah_ap($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_ap')) {
    /**
     * @param mixed $n
     */
    function angka_ap($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_ap')) {
    /**
     * @param mixed $v
     */
    function tanggal_ap($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('waktu_ap')) {
    /**
     * @param mixed $v
     */
    function waktu_ap($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('status_angsuran_label_ap')) {
    function status_angsuran_label_ap(string $status): string
    {
        $status = strtolower(trim($status));

        $map = [
            'belum_bayar' => 'Belum Bayar',
            'belum'       => 'Belum Bayar',
            'pending'     => 'Belum Bayar',
            'lunas'       => 'Lunas',
            'dibayar'     => 'Lunas',
            'bayar'       => 'Lunas',
            'telat'       => 'Telat',
        ];

        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status ?: '-'));
    }
}

if (!function_exists('status_angsuran_class_ap')) {
    function status_angsuran_class_ap(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, ['lunas', 'dibayar', 'bayar'], true)) {
            return 'bg-green-50 text-green-700 border-green-200';
        }

        if ($status === 'telat') {
            return 'bg-red-50 text-red-700 border-red-200';
        }

        return 'bg-amber-50 text-amber-700 border-amber-200';
    }
}


if (!function_exists('angsuran_is_lunas_ap')) {
    /**
     * @param mixed $status
     */
    function angsuran_is_lunas_ap($status): bool
    {
        $status = strtolower(trim((string)$status));
        return in_array($status, ['lunas', 'dibayar', 'bayar'], true);
    }
}

if (!function_exists('progress_width_ap')) {
    function progress_width_ap(int $lunas, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return max(0, min(100, (int)round(($lunas / $total) * 100)));
    }
}

if (!function_exists('coa_id_ap')) {
    function coa_id_ap(PDO $pdo, string $kode): int
    {
        $stmt = $pdo->prepare("
            SELECT id
            FROM coa
            WHERE kode = ?
            LIMIT 1
        ");
        $stmt->execute([$kode]);

        $id = $stmt->fetchColumn();

        if (!$id) {
            throw new Exception("COA kode {$kode} tidak ditemukan.");
        }

        return (int)$id;
    }
}

if (!function_exists('buat_jurnal_ap')) {
    function buat_jurnal_ap(
        PDO $pdo,
        string $tanggal,
        string $keterangan,
        string $refTabel,
        int $refId,
        ?int $userId = null
    ): int {
        $kodeJurnal = 'JR-ANG-' . date('YmdHis') . '-' . random_int(100, 999);

        $stmt = $pdo->prepare("
            INSERT INTO jurnal_umum
            (
                tanggal,
                kode_jurnal,
                keterangan,
                ref_tabel,
                ref_id,
                dibuat_oleh,
                created_at
            )
            VALUES
            (
                :tanggal,
                :kode_jurnal,
                :keterangan,
                :ref_tabel,
                :ref_id,
                :dibuat_oleh,
                NOW()
            )
        ");

        $stmt->execute([
            ':tanggal' => $tanggal,
            ':kode_jurnal' => $kodeJurnal,
            ':keterangan' => $keterangan,
            ':ref_tabel' => $refTabel,
            ':ref_id' => $refId,
            ':dibuat_oleh' => $userId ?: null,
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('tambah_jurnal_detail_ap')) {
    function tambah_jurnal_detail_ap(
        PDO $pdo,
        int $jurnalId,
        int $coaId,
        float $debit = 0,
        float $kredit = 0
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO jurnal_detail
            (
                jurnal_id,
                coa_id,
                debit,
                kredit,
                created_at
            )
            VALUES
            (
                :jurnal_id,
                :coa_id,
                :debit,
                :kredit,
                NOW()
            )
        ");

        $stmt->execute([
            ':jurnal_id' => $jurnalId,
            ':coa_id' => $coaId,
            ':debit' => $debit,
            ':kredit' => $kredit,
        ]);
    }
}

if (!function_exists('auto_jurnal_angsuran_ap')) {
    function auto_jurnal_angsuran_ap(
        PDO $pdo,
        int $angsuranId,
        float $jumlahPokok,
        float $jumlahBunga,
        float $jumlahTotal,
        ?int $userId = null
    ): void {
        if ($angsuranId < 1 || $jumlahTotal <= 0) {
            return;
        }

        try {
            // Cegah jurnal ganda.
            $cek = $pdo->prepare("
                SELECT COUNT(*)
                FROM jurnal_umum
                WHERE ref_tabel = 'angsuran_pinjaman'
                  AND ref_id = ?
            ");
            $cek->execute([$angsuranId]);

            if ((int)$cek->fetchColumn() > 0) {
                return;
            }

            $jurnalId = buat_jurnal_ap(
                $pdo,
                date('Y-m-d'),
                'Pembayaran Angsuran Pinjaman #' . $angsuranId,
                'angsuran_pinjaman',
                $angsuranId,
                $userId
            );

            // Debit: Kas
            tambah_jurnal_detail_ap(
                $pdo,
                $jurnalId,
                coa_id_ap($pdo, '101'),
                $jumlahTotal,
                0
            );

            // Kredit: Piutang Pinjaman
            if ($jumlahPokok > 0) {
                tambah_jurnal_detail_ap(
                    $pdo,
                    $jurnalId,
                    coa_id_ap($pdo, '103'),
                    0,
                    $jumlahPokok
                );
            }

            // Kredit: Pendapatan Bunga Pinjaman
            if ($jumlahBunga > 0) {
                tambah_jurnal_detail_ap(
                    $pdo,
                    $jurnalId,
                    coa_id_ap($pdo, '403'),
                    0,
                    $jumlahBunga
                );
            }
        } catch (Throwable $e) {
            // Auto jurnal tidak boleh menggagalkan pembayaran angsuran.
            error_log('AUTO JURNAL ANGSURAN ERROR: ' . $e->getMessage());
        }
    }
}


if (!function_exists('parse_nominal_ap')) {
    /**
     * @param mixed $v
     */
    function parse_nominal_ap($v): float
    {
        $v = trim((string)($v ?? ''));

        if ($v === '') {
            return 0;
        }

        $v = str_replace(['Rp', 'rp', ' ', '.'], '', $v);
        $v = str_replace(',', '.', $v);

        return (float)$v;
    }
}

if (!function_exists('format_import_date_ap')) {
    /**
     * @param mixed $v
     */
    function format_import_date_ap($v): string
    {
        $v = trim((string)($v ?? ''));

        if ($v === '') {
            return date('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) {
            $parts = explode('/', $v);
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }

        if (is_numeric($v)) {
            // Excel serial date.
            $unix = ((int)$v - 25569) * 86400;
            if ($unix > 0) {
                return gmdate('Y-m-d', $unix);
            }
        }

        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }
}

if (!function_exists('read_csv_rows_ap')) {
    /**
     * @return array<int,array<int,string>>
     */
    function read_csv_rows_ap(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if (!$handle) {
            return $rows;
        }

        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            $semicolon = substr_count($firstLine, ';');
            $comma = substr_count($firstLine, ',');
            $tab = substr_count($firstLine, "\t");

            if ($semicolon > $comma && $semicolon >= $tab) {
                $delimiter = ';';
            } elseif ($tab > $comma && $tab > $semicolon) {
                $delimiter = "\t";
            }
        }

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(static function ($item): string {
                return trim((string)$item);
            }, $data);
        }

        fclose($handle);

        return $rows;
    }
}

if (!function_exists('xlsx_col_index_ap')) {
    function xlsx_col_index_ap(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $m);
        $letters = strtoupper($m[0] ?? 'A');
        $num = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $num - 1);
    }
}

if (!function_exists('read_xlsx_rows_ap')) {
    /**
     * Parser XLSX ringan tanpa PhpSpreadsheet.
     * Mendukung semua sheet, sharedStrings, inlineStr, numeric cell.
     * Baris dari semua sheet digabung supaya file bank dengan PNS/PPPK/outsourcing
     * bisa dibaca dalam satu kali upload.
     *
     * @return array<int,array<int,string>>
     */
    function read_xlsx_rows_ap(string $path): array
    {
        $rows = [];

        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive belum aktif. Untuk XLSX aktifkan extension zip PHP, atau upload CSV.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('File XLSX tidak bisa dibuka.');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedXml !== false) {
            $xml = @simplexml_load_string($sharedXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $text = '';

                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                    }

                    $shared[] = $text;
                }
            }
        }

        $sheetNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetNames[] = $name;
            }
        }

        natsort($sheetNames);
        $sheetNames = array_values($sheetNames);

        if (!$sheetNames) {
            $zip->close();
            throw new Exception('Sheet tidak ditemukan di XLSX.');
        }

        foreach ($sheetNames as $sheetName) {
            $sheetXml = $zip->getFromName($sheetName);

            if ($sheetXml === false) {
                continue;
            }

            $xml = @simplexml_load_string($sheetXml);
            if (!$xml || !isset($xml->sheetData->row)) {
                continue;
            }

            // Separator kosong antar sheet agar pendeteksi header tidak bercampur.
            if ($rows) {
                $rows[] = [];
            }

            foreach ($xml->sheetData->row as $row) {
                $line = [];

                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $idx = xlsx_col_index_ap($ref);
                    $type = (string)$c['t'];
                    $val = '';

                    if ($type === 's') {
                        $key = (int)($c->v ?? -1);
                        $val = $shared[$key] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = (string)($c->is->t ?? '');
                    } else {
                        $val = (string)($c->v ?? '');
                    }

                    $line[$idx] = trim($val);
                }

                if ($line) {
                    ksort($line);
                    $max = max(array_keys($line));
                    $full = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $full[] = $line[$i] ?? '';
                    }
                    $rows[] = $full;
                }
            }
        }

        $zip->close();

        return $rows;
    }
}

if (!function_exists('read_import_rows_ap')) {
    /**
     * @return array<int,array<int,string>>
     */
    function read_import_rows_ap(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            return read_csv_rows_ap($path);
        }

        if ($ext === 'xlsx') {
            return read_xlsx_rows_ap($path);
        }

        throw new Exception('Format file tidak didukung. Gunakan CSV atau XLSX.');
    }
}

if (!function_exists('normal_key_import_ap')) {
    /**
     * @param mixed $v
     */
    function normal_key_import_ap($v): string
    {
        $v = strtolower(trim((string)($v ?? '')));
        $v = str_replace(["\r", "\n", "\t"], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v) ?: '';
        $v = str_replace([' ', '-', '.', '/', '\\'], '_', $v);
        $v = preg_replace('/_+/', '_', $v) ?: '';
        return trim($v, '_ ');
    }
}

if (!function_exists('compact_name_import_ap')) {
    /**
     * @param mixed $v
     */
    function compact_name_import_ap($v): string
    {
        $v = strtolower(trim((string)($v ?? '')));
        $v = preg_replace('/[^a-z0-9]+/i', '', $v) ?: '';
        return $v;
    }
}

if (!function_exists('period_date_from_rows_ap')) {
    /**
     * Ambil tanggal bayar default dari judul sheet, contoh:
     * "BULAN MEI 2026" => 2026-05-01.
     *
     * @param array<int,array<int,string>> $rows
     */
    function period_date_from_rows_ap(array $rows, int $headerIndex): string
    {
        $bulanMap = [
            'januari' => '01',
            'jan' => '01',
            'februari' => '02',
            'feb' => '02',
            'maret' => '03',
            'mar' => '03',
            'april' => '04',
            'apr' => '04',
            'mei' => '05',
            'juni' => '06',
            'jun' => '06',
            'juli' => '07',
            'jul' => '07',
            'agustus' => '08',
            'agst' => '08',
            'agu' => '08',
            'aug' => '08',
            'september' => '09',
            'sept' => '09',
            'sep' => '09',
            'oktober' => '10',
            'okt' => '10',
            'november' => '11',
            'nov' => '11',
            'desember' => '12',
            'des' => '12',
            'dec' => '12',
        ];

        for ($i = max(0, $headerIndex - 6); $i < $headerIndex; $i++) {
            $text = strtolower(implode(' ', array_map('strval', $rows[$i] ?? [])));
            if (!preg_match('/(januari|jan|februari|feb|maret|mar|april|apr|mei|juni|jun|juli|jul|agustus|agst|agu|aug|september|sept|sep|oktober|okt|november|nov|desember|des|dec)\s+(20\d{2})/i', $text, $m)) {
                continue;
            }

            $bulan = $bulanMap[strtolower($m[1])] ?? '';
            $tahun = $m[2] ?? '';

            if ($bulan !== '' && $tahun !== '') {
                return $tahun . '-' . $bulan . '-01';
            }
        }

        return date('Y-m-d');
    }
}

if (!function_exists('find_header_col_import_ap')) {
    /**
     * @param array<int,string> $header
     * @param array<int,string> $needles
     */
    function find_header_col_import_ap(array $header, array $needles): int
    {
        foreach ($header as $i => $h) {
            $h = normal_key_import_ap($h);
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($h, $needle) !== false) {
                    return (int)$i;
                }
            }
        }

        return -1;
    }
}

if (!function_exists('excel_col_index_ap')) {
    function excel_col_index_ap(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $num - 1);
    }
}

if (!function_exists('row_value_by_letter_ap')) {
    /** @param array<int,string> $row */
    function row_value_by_letter_ap(array $row, string $col): string
    {
        $idx = excel_col_index_ap($col);
        return trim((string)($row[$idx] ?? ''));
    }
}

if (!function_exists('detect_bank_import_layout_ap')) {
    /**
     * @param array<int,string> $header
     * @return array<string,mixed>
     */
    function detect_bank_import_layout_ap(array $header): array
    {
        $norm = array_map('normal_key_import_ap', $header);
        $joined = implode('|', $norm);

        // Layout PNS: header di B:P, kolom pinjaman J, ke N.
        if (strpos($joined, 'simpan_pinjam_koperasi') !== false && strpos($joined, 'simpan_pinjam_ke') !== false) {
            return [
                'ok' => true,
                'nama_col' => 'C',
                'kode_col' => 'D',
                'jumlah_col' => 'J',
                'ke_col' => 'N',
                'jenis' => 'PNS',
            ];
        }

        // Layout PPPK: header di B:P, kolom pinjaman G, ke L.
        if (strpos($joined, 'iuran_simpan_pinjam_ke') !== false) {
            return [
                'ok' => true,
                'nama_col' => 'C',
                'kode_col' => 'D',
                'jumlah_col' => 'G',
                'ke_col' => 'L',
                'jenis' => 'PPPK',
            ];
        }

        // Layout outsourcing: header di B:J, kolom pinjaman F, ke J.
        if ((strpos($joined, 'nik') !== false) && (strpos($joined, 'simpan_pinjam') !== false) && (strpos($joined, 'iuran_ke') !== false)) {
            return [
                'ok' => true,
                'nama_col' => 'C',
                'kode_col' => 'D',
                'jumlah_col' => 'F',
                'ke_col' => 'J',
                'jenis' => 'OUTSOURCING',
            ];
        }

        return ['ok' => false];
    }
}


if (!function_exists('month_number_import_ap')) {
    function month_number_import_ap(string $text): int
    {
        $text = strtolower(trim($text));
        $map = [
            'januari' => 1,
            'jan' => 1,
            'februari' => 2,
            'feb' => 2,
            'maret' => 3,
            'mar' => 3,
            'april' => 4,
            'apr' => 4,
            'mei' => 5,
            'may' => 5,
            'juni' => 6,
            'jun' => 6,
            'juli' => 7,
            'jul' => 7,
            'agustus' => 8,
            'agu' => 8,
            'agst' => 8,
            'aug' => 8,
            'september' => 9,
            'sep' => 9,
            'sept' => 9,
            'oktober' => 10,
            'okt' => 10,
            'oct' => 10,
            'november' => 11,
            'nov' => 11,
            'nopember' => 11,
            'nop' => 11,
            'desember' => 12,
            'des' => 12,
            'december' => 12,
            'dec' => 12,
        ];

        foreach ($map as $name => $num) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $text)) {
                return (int)$num;
            }
        }

        return 0;
    }
}

if (!function_exists('year_from_text_import_ap')) {
    function year_from_text_import_ap(string $text, int $fallback = 0): int
    {
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            return (int)$m[1];
        }

        return $fallback > 0 ? $fallback : (int)date('Y');
    }
}

if (!function_exists('date_from_month_text_import_ap')) {
    function date_from_month_text_import_ap(string $text, int $fallbackYear = 0): string
    {
        $bulan = month_number_import_ap($text);
        $tahun = year_from_text_import_ap($text, $fallbackYear);

        if ($bulan < 1) {
            return date('Y-m-d');
        }

        return sprintf('%04d-%02d-01', $tahun, $bulan);
    }
}

if (!function_exists('infer_loan_kind_from_near_rows_ap')) {
    /**
     * @param array<int,array<int,string>> $rows
     */
    function infer_loan_kind_from_near_rows_ap(array $rows, int $idx, string $default = 'uang'): string
    {
        $start = max(0, $idx - 8);
        $end = min(count($rows) - 1, $idx + 8);
        $text = '';

        for ($i = $start; $i <= $end; $i++) {
            $text .= ' ' . strtolower(implode(' ', array_map('strval', $rows[$i] ?? [])));
        }

        if (strpos($text, 'pinjaman barang') !== false || strpos($text, 'nama barang') !== false) {
            return 'barang';
        }

        if (strpos($text, 'pinjaman uang') !== false || strpos($text, 'simpan pinjam') !== false) {
            return 'uang';
        }

        return $default;
    }
}

if (!function_exists('append_import_row_ap')) {
    /**
     * @param array<int,array<string,mixed>> $result
     * @param array<string,mixed> $row
     */
    function append_import_row_ap(array &$result, array $row): void
    {
        $nama = trim((string)($row['member_nama_import'] ?? ''));
        $jumlah = (float)($row['jumlah_bayar'] ?? 0);
        $tanggal = format_import_date_ap($row['tanggal_bayar'] ?? date('Y-m-d'));
        $jenis = strtolower(trim((string)($row['jenis_pinjaman_import'] ?? $row['jenis_import'] ?? '')));

        if ($nama === '' || $jumlah <= 0) {
            return;
        }

        $row['tanggal_bayar'] = $tanggal;
        $row['jumlah_bayar'] = $jumlah;
        $row['member_nama_import'] = $nama;
        $row['jenis_pinjaman_import'] = $jenis;

        $key = compact_name_import_ap($nama) . '|' . $tanggal . '|' . (string)round($jumlah) . '|' . $jenis;

        foreach ($result as $idx => $old) {
            $oldKey = compact_name_import_ap((string)($old['member_nama_import'] ?? '')) . '|' .
                format_import_date_ap($old['tanggal_bayar'] ?? date('Y-m-d')) . '|' .
                (string)round((float)($old['jumlah_bayar'] ?? 0)) . '|' .
                strtolower(trim((string)($old['jenis_pinjaman_import'] ?? $old['jenis_import'] ?? '')));

            if ($oldKey !== $key) {
                continue;
            }

            // Jika ada duplikat dari sheet ringkasan dan sheet detail, pakai yang punya nomor cicilan.
            if ((int)($row['ke'] ?? 0) > (int)($old['ke'] ?? 0)) {
                $result[$idx] = $row;
            }

            return;
        }

        $result[] = $row;
    }
}

if (!function_exists('parse_annual_loan_summary_rows_ap')) {
    /**
     * Parser sheet ringkasan tahunan:
     * - 05_Simpan Pinjam
     * - 06_Pinjaman Barang
     * Kolom per bulan: Pinjaman Awal, Cicilan Bulan ini, Adm Pinjaman, Sisa Pinjaman.
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    function parse_annual_loan_summary_rows_ap(array $rows): array
    {
        $result = [];

        for ($i = 0; $i < count($rows) - 1; $i++) {
            $header = $rows[$i] ?? [];
            $sub = $rows[$i + 1] ?? [];
            $headerText = strtolower(implode(' ', array_map('strval', $header)));
            $subText = strtolower(implode(' ', array_map('strval', $sub)));

            if (strpos($headerText, 'nama anggota') === false) {
                continue;
            }

            if (strpos($subText, 'cicilan bulan') === false || strpos($subText, 'adm pinjaman') === false) {
                continue;
            }

            $jenis = infer_loan_kind_from_near_rows_ap($rows, $i, 'uang');
            $jenisLabel = $jenis === 'barang' ? 'PINJAMAN_BARANG_REKAP' : 'PINJAMAN_UANG_REKAP';

            $monthGroups = [];
            foreach ($header as $col => $value) {
                $label = trim((string)$value);
                if ($label === '') {
                    continue;
                }

                $bulan = month_number_import_ap($label);
                if ($bulan < 1) {
                    continue;
                }

                $tahun = year_from_text_import_ap($label);
                $monthGroups[] = [
                    'col' => (int)$col,
                    'tanggal' => sprintf('%04d-%02d-01', $tahun, $bulan),
                ];
            }

            if (!$monthGroups) {
                continue;
            }

            for ($r = $i + 2; $r < count($rows); $r++) {
                $row = $rows[$r] ?? [];
                $nama = trim((string)($row[1] ?? ''));

                if ($nama === '') {
                    // Ringkasan tahunan biasanya selesai ketika kolom nama kosong beberapa baris.
                    continue;
                }

                $namaLower = strtolower($nama);
                if (
                    $namaLower === 'nama anggota' ||
                    strpos($namaLower, 'jumlah') !== false ||
                    strpos($namaLower, 'total') !== false ||
                    strpos($namaLower, 'koperasi') !== false
                ) {
                    continue;
                }

                // Jika masuk ke sheet/area baru.
                if (strpos(strtolower(implode(' ', array_map('strval', $row))), 'no urut') !== false) {
                    break;
                }

                foreach ($monthGroups as $group) {
                    $baseCol = (int)$group['col'];
                    $pokok = parse_nominal_ap($row[$baseCol + 1] ?? 0); // Cicilan Bulan ini
                    $adm = parse_nominal_ap($row[$baseCol + 2] ?? 0);   // Adm Pinjaman
                    $jumlah = $pokok + $adm;

                    if ($jumlah <= 0) {
                        continue;
                    }

                    append_import_row_ap($result, [
                        'row_no' => $r + 1,
                        'pinjaman_id' => 0,
                        'ke' => 0,
                        'tanggal_bayar' => (string)$group['tanggal'],
                        'jumlah_bayar' => $jumlah,
                        'jumlah_pokok_import' => $pokok,
                        'jumlah_bunga_import' => $adm,
                        'metode_bayar' => 'import_excel_tahunan',
                        'catatan' => 'Import ' . ($jenis === 'barang' ? 'pinjaman barang' : 'pinjaman uang') . ' dari sheet rekap tahunan: ' . $nama,
                        'member_nama_import' => $nama,
                        'member_kode_import' => '',
                        'jenis_import' => $jenisLabel,
                        'jenis_pinjaman_import' => $jenis,
                    ]);
                }
            }
        }

        return $result;
    }
}

if (!function_exists('parse_detail_loan_rows_ap')) {
    /**
     * Parser sheet detail:
     * - PINJAMAN UANG
     * - PINJAMAN BARANG
     * Format blok: Nama, lalu tabel No. Cicilan / Bulan / Besar/Sisa / Pokok Bayar / Adm.
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    function parse_detail_loan_rows_ap(array $rows): array
    {
        $result = [];
        $currentName = '';
        $currentJenis = 'uang';
        $currentYear = (int)date('Y');

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            $first = strtolower(trim((string)($row[0] ?? '')));

            if ($first === 'nama' || $first === 'nama ') {
                $currentName = trim((string)($row[2] ?? ''));
                $currentJenis = infer_loan_kind_from_near_rows_ap($rows, $i, 'uang');

                $nearText = '';
                for ($j = max(0, $i - 4); $j <= min(count($rows) - 1, $i + 12); $j++) {
                    $nearText .= ' ' . implode(' ', array_map('strval', $rows[$j] ?? []));
                }
                $currentYear = year_from_text_import_ap($nearText, $currentYear);
                continue;
            }

            if ($currentName === '') {
                continue;
            }

            $ke = (int)parse_nominal_ap($row[0] ?? 0);
            $bulanText = trim((string)($row[1] ?? ''));
            $pokok = parse_nominal_ap($row[4] ?? 0);
            $adm = parse_nominal_ap($row[5] ?? 0);
            $jumlah = $pokok + $adm;

            if ($ke <= 0 || $bulanText === '' || $jumlah <= 0) {
                continue;
            }

            $tanggal = date_from_month_text_import_ap($bulanText, $currentYear);
            $jenisLabel = $currentJenis === 'barang' ? 'PINJAMAN_BARANG_DETAIL' : 'PINJAMAN_UANG_DETAIL';

            append_import_row_ap($result, [
                'row_no' => $i + 1,
                'pinjaman_id' => 0,
                'ke' => $ke,
                'tanggal_bayar' => $tanggal,
                'jumlah_bayar' => $jumlah,
                'jumlah_pokok_import' => $pokok,
                'jumlah_bunga_import' => $adm,
                'metode_bayar' => 'import_excel_detail',
                'catatan' => 'Import ' . ($currentJenis === 'barang' ? 'pinjaman barang' : 'pinjaman uang') . ' detail: ' . $currentName . ' ke-' . $ke,
                'member_nama_import' => $currentName,
                'member_kode_import' => '',
                'jenis_import' => $jenisLabel,
                'jenis_pinjaman_import' => $currentJenis,
            ]);
        }

        return $result;
    }
}


if (!function_exists('normalize_import_payment_rows_ap')) {
    /**
     * Mendukung:
     * 1. Template sistem: pinjaman_id, ke, tanggal_bayar, jumlah_bayar, metode_bayar, catatan.
     * 2. File bank/potongan BSDK multi-sheet: PNS, PPPK, outsourcing.
     * 3. File "Perhitungan 31 Desember 2025 - Revisi.xlsx":
     *    - 05_Simpan Pinjam / PINJAMAN UANG
     *    - 06_Pinjaman Barang / PINJAMAN BARANG
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    function normalize_import_payment_rows_ap(array $rows): array
    {
        $result = [];

        if (!$rows) {
            return $result;
        }

        // Format template standar: cari header di baris mana pun.
        $templateHeaderIndex = -1;
        $templateMap = [];
        foreach ($rows as $idx => $candidate) {
            $header = array_map('normal_key_import_ap', $candidate);
            if (in_array('pinjaman_id', $header, true) && in_array('ke', $header, true)) {
                $templateHeaderIndex = (int)$idx;
                foreach ($header as $i => $h) {
                    $templateMap[$h] = $i;
                }
                break;
            }
        }

        if ($templateHeaderIndex >= 0) {
            for ($i = $templateHeaderIndex + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $pinjamanId = (int)($row[$templateMap['pinjaman_id'] ?? 0] ?? 0);
                $ke = (int)($row[$templateMap['ke'] ?? 1] ?? 0);

                if ($pinjamanId <= 0 && $ke <= 0) {
                    continue;
                }

                append_import_row_ap($result, [
                    'row_no' => $i + 1,
                    'pinjaman_id' => $pinjamanId,
                    'ke' => $ke,
                    'tanggal_bayar' => format_import_date_ap($row[$templateMap['tanggal_bayar'] ?? 2] ?? ''),
                    'jumlah_bayar' => parse_nominal_ap($row[$templateMap['jumlah_bayar'] ?? 3] ?? 0),
                    'metode_bayar' => trim((string)($row[$templateMap['metode_bayar'] ?? 4] ?? '')),
                    'catatan' => trim((string)($row[$templateMap['catatan'] ?? 5] ?? '')),
                    'member_nama_import' => '',
                    'member_kode_import' => '',
                    'jenis_import' => 'TEMPLATE',
                    'jenis_pinjaman_import' => '',
                ]);
            }
            return $result;
        }

        // Format Excel tahunan koperasi: ambil dari sheet detail dan rekap.
        foreach (parse_detail_loan_rows_ap($rows) as $row) {
            append_import_row_ap($result, $row);
        }

        foreach (parse_annual_loan_summary_rows_ap($rows) as $row) {
            append_import_row_ap($result, $row);
        }

        if ($result) {
            return $result;
        }

        // Format bank/potongan lama.
        $layout = ['ok' => false];
        $tanggal = date('Y-m-d');

        foreach ($rows as $i => $row) {
            $detected = detect_bank_import_layout_ap($row);
            if (!empty($detected['ok'])) {
                $layout = $detected;
                $tanggal = period_date_from_rows_ap($rows, (int)$i);
                continue;
            }

            if (empty($layout['ok'])) {
                continue;
            }

            $nama = row_value_by_letter_ap($row, (string)$layout['nama_col']);
            $kode = row_value_by_letter_ap($row, (string)$layout['kode_col']);
            $jumlah = parse_nominal_ap(row_value_by_letter_ap($row, (string)$layout['jumlah_col']));
            $ke = (int)parse_nominal_ap(row_value_by_letter_ap($row, (string)$layout['ke_col']));

            $namaLower = strtolower(trim($nama));
            if ($nama === '' || $namaLower === 'nama pegawai' || $namaLower === 'nama' || $jumlah <= 0) {
                continue;
            }

            // Lewati baris total/catatan.
            if (strpos($namaLower, 'jumlah') !== false || strpos($namaLower, 'total') !== false) {
                continue;
            }

            append_import_row_ap($result, [
                'row_no' => $i + 1,
                'pinjaman_id' => 0,
                'ke' => $ke,
                'tanggal_bayar' => $tanggal,
                'jumlah_bayar' => $jumlah,
                'metode_bayar' => 'potongan_bank',
                'catatan' => 'Import potongan bank ' . ($layout['jenis'] ?? '') . ': ' . $nama,
                'member_nama_import' => $nama,
                'member_kode_import' => $kode,
                'jenis_import' => (string)($layout['jenis'] ?? 'BANK'),
                'jenis_pinjaman_import' => '',
            ]);
        }

        return $result;
    }
}


if (!function_exists('bayar_angsuran_by_id_ap')) {
    /**
     * Bayar satu angsuran, dipakai untuk bayar manual, bayar terpilih, dan import.
     */
    function bayar_angsuran_by_id_ap(
        PDO $pdo,
        int $angsuranId,
        int $userId,
        ?string $tanggalBayar = null
    ): array {
        if ($angsuranId < 1) {
            return ['ok' => false, 'message' => 'ID angsuran tidak valid.'];
        }

        $tanggalBayar = format_import_date_ap($tanggalBayar ?: date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT
                ap.*,
                p.id AS pinjaman_ref_id,
                p.status AS pinjaman_status
            FROM angsuran_pinjaman ap
            LEFT JOIN pinjaman p ON p.id = ap.pinjaman_id
            WHERE ap.id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':id' => $angsuranId]);
        $angsuran = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$angsuran) {
            return ['ok' => false, 'message' => 'Data angsuran tidak ditemukan.'];
        }

        if (angsuran_is_lunas_ap($angsuran['status'] ?? '')) {
            return ['ok' => false, 'message' => 'Angsuran sudah dibayar.'];
        }

        $jumlahPokok = (float)($angsuran['jumlah_pokok'] ?? 0);
        $jumlahBunga = (float)($angsuran['jumlah_bunga'] ?? 0);
        $jumlahTotal = (float)($angsuran['jumlah_total'] ?? ($jumlahPokok + $jumlahBunga));

        $stmtBayar = $pdo->prepare("
            UPDATE angsuran_pinjaman
            SET status = 'lunas',
                dibayar_at = :dibayar_at,
                dibayar_oleh = :user_id
            WHERE id = :id
            LIMIT 1
        ");
        $stmtBayar->execute([
            ':dibayar_at' => $tanggalBayar . ' ' . date('H:i:s'),
            ':user_id' => $userId ?: null,
            ':id' => $angsuranId,
        ]);

        auto_jurnal_angsuran_ap(
            $pdo,
            $angsuranId,
            $jumlahPokok,
            $jumlahBunga,
            $jumlahTotal,
            $userId
        );

        $stmtSisa = $pdo->prepare("
            SELECT COUNT(*)
            FROM angsuran_pinjaman
            WHERE pinjaman_id = :pinjaman_id
              AND (status IS NULL OR TRIM(status) = '' OR status NOT IN ('lunas', 'dibayar', 'bayar'))
        ");
        $stmtSisa->execute([
            ':pinjaman_id' => (int)$angsuran['pinjaman_id']
        ]);

        if ((int)$stmtSisa->fetchColumn() === 0) {
            $stmtLunas = $pdo->prepare("
                UPDATE pinjaman
                SET status = 'lunas'
                WHERE id = :id
                LIMIT 1
            ");
            $stmtLunas->execute([
                ':id' => (int)$angsuran['pinjaman_id']
            ]);
        }

        return [
            'ok' => true,
            'message' => 'Angsuran berhasil dibayar.',
            'jumlah_total' => $jumlahTotal,
            'pinjaman_id' => (int)$angsuran['pinjaman_id'],
            'ke' => (int)$angsuran['ke'],
        ];
    }
}

if (!function_exists('import_name_score_ap')) {
    function import_name_score_ap(string $dbName, string $importName): int
    {
        $dbName = trim($dbName);
        $importName = trim($importName);

        if ($dbName === '' || $importName === '') {
            return 0;
        }

        $db = compact_name_import_ap($dbName);
        $imp = compact_name_import_ap($importName);

        if ($db === '' || $imp === '') {
            return 0;
        }

        if ($db === $imp) {
            return 100;
        }

        if (strpos($db, $imp) !== false || strpos($imp, $db) !== false) {
            return 92;
        }

        $cleanDb = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $dbName) ?: $dbName);
        $cleanImp = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $importName) ?: $importName);
        $tokensDb = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($cleanDb)) ?: '')));
        $tokensImp = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($cleanImp)) ?: '')));

        $tokensImp = array_values(array_filter($tokensImp, static function ($token): bool {
            return strlen($token) >= 3 && !in_array($token, ['bin', 'binti', 'dan'], true);
        }));

        if (!$tokensDb || !$tokensImp) {
            return 0;
        }

        $hit = 0;
        foreach ($tokensImp as $token) {
            foreach ($tokensDb as $dbToken) {
                if ($token === $dbToken || strpos($dbToken, $token) !== false || strpos($token, $dbToken) !== false) {
                    $hit++;
                    break;
                }
            }
        }

        $tokenScore = (int)round(($hit / max(1, count($tokensImp))) * 88);

        similar_text($db, $imp, $percent);
        $similarScore = (int)round($percent);

        return max($tokenScore, $similarScore);
    }
}

if (!function_exists('find_angsuran_by_import_ap')) {
    /**
     * Matching import angsuran:
     * - Template lama: pinjaman_id + ke.
     * - Excel bank: cocokkan member via NIP/NIK/kode/no_hp, lalu nama.
     * - Ambil angsuran melalui ap.member_id ATAU p.member_id, karena beberapa DB hanya menyimpan member_id di tabel pinjaman.
     * - Nominal/ke hanya jadi prioritas, bukan syarat wajib.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    function find_angsuran_by_import_ap(PDO $pdo, array $row): ?array
    {
        $pinjamanId = (int)($row['pinjaman_id'] ?? 0);
        $ke = (int)($row['ke'] ?? 0);
        $memberKode = trim((string)($row['member_kode_import'] ?? ''));
        $memberNama = trim((string)($row['member_nama_import'] ?? ''));
        $jumlahBayar = (float)($row['jumlah_bayar'] ?? 0);
        $jenisImport = strtolower(trim((string)($row['jenis_pinjaman_import'] ?? '')));

        if ($pinjamanId > 0 && $ke > 0) {
            $stmt = $pdo->prepare("\n                SELECT\n                    ap.*,\n                    COALESCE(m_ap.nama, m_p.nama) AS member_nama,\n                    COALESCE(m_ap.kode, m_p.kode) AS member_kode,\n                    COALESCE(m_ap.no_hp, m_p.no_hp) AS member_hp\n                FROM angsuran_pinjaman ap\n                LEFT JOIN pinjaman p ON p.id = ap.pinjaman_id\n                LEFT JOIN member m_ap ON m_ap.id = ap.member_id\n                LEFT JOIN member m_p ON m_p.id = p.member_id\n                WHERE ap.pinjaman_id = :pinjaman_id AND ap.ke = :ke\n                LIMIT 1\n            ");
            $stmt->execute([':pinjaman_id' => $pinjamanId, ':ke' => $ke]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ?: null;
        }

        if ($memberKode === '' && $memberNama === '') {
            return null;
        }

        $memberIds = [];
        $kodeCompact = preg_replace('/[^A-Za-z0-9]+/', '', $memberKode) ?: '';

        if ($kodeCompact !== '') {
            $stmt = $pdo->prepare("\n                SELECT id\n                FROM member\n                WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(kode,''),'.',''),'-',''),' ',''),'/','') = :kode_a\n                   OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp,''),'.',''),'-',''),' ',''),'/','') = :kode_b\n                ORDER BY id ASC\n                LIMIT 20\n            ");
            $stmt->execute([':kode_a' => $kodeCompact, ':kode_b' => $kodeCompact]);
            $memberIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!$memberIds && $memberNama !== '') {
            $cleanName = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $memberNama) ?: '');
            $parts = preg_split('/\s+/', trim($cleanName)) ?: [];
            $parts = array_values(array_filter($parts, static function ($part): bool {
                return strlen($part) >= 3 && !in_array($part, ['dan', 'bin', 'binti', 'sdr', 'sdri'], true);
            }));

            if ($parts) {
                $sql = "SELECT id, nama FROM member WHERE 1=1";
                $params = [];
                foreach (array_slice($parts, 0, 2) as $idx => $part) {
                    $key = ':nama_like_' . $idx;
                    $sql .= " AND LOWER(nama) LIKE " . $key;
                    $params[$key] = '%' . $part . '%';
                }
                $sql .= " ORDER BY id ASC LIMIT 50";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

                usort($members, static function ($a, $b) use ($memberNama): int {
                    return import_name_score_ap((string)($b['nama'] ?? ''), $memberNama) <=> import_name_score_ap((string)($a['nama'] ?? ''), $memberNama);
                });

                foreach ($members as $m) {
                    if (import_name_score_ap((string)($m['nama'] ?? ''), $memberNama) >= 50) {
                        $memberIds[] = (int)$m['id'];
                    }
                }
            }
        }

        $memberIds = array_values(array_unique(array_filter($memberIds)));
        if (!$memberIds) {
            return null;
        }
        $placeholdersAp = [];
        $placeholdersP = [];
        $params = [];
        foreach ($memberIds as $idx => $memberId) {
            $keyAp = ':member_id_ap_' . $idx;
            $keyP = ':member_id_p_' . $idx;
            $placeholdersAp[] = $keyAp;
            $placeholdersP[] = $keyP;
            $params[$keyAp] = $memberId;
            $params[$keyP] = $memberId;
        }

        $memberWhereAp = implode(',', $placeholdersAp);
        $memberWhereP = implode(',', $placeholdersP);
        $sql = "
            SELECT
                ap.*,
                COALESCE(m_ap.nama, m_p.nama) AS member_nama,
                COALESCE(m_ap.kode, m_p.kode) AS member_kode,
                COALESCE(m_ap.no_hp, m_p.no_hp) AS member_hp
            FROM angsuran_pinjaman ap
            LEFT JOIN pinjaman p ON p.id = ap.pinjaman_id
            LEFT JOIN member m_ap ON m_ap.id = ap.member_id
            LEFT JOIN member m_p ON m_p.id = p.member_id
            WHERE (ap.member_id IN (" . $memberWhereAp . ") OR p.member_id IN (" . $memberWhereP . "))
            ORDER BY
                CASE WHEN LOWER(TRIM(COALESCE(ap.status,''))) IN ('lunas', 'dibayar', 'bayar') THEN 1 ELSE 0 END ASC,
                CASE WHEN :jenis_import_a <> '' AND LOWER(COALESCE(p.jenis,'')) LIKE :jenis_import_b THEN 0 ELSE 1 END ASC,
                CASE WHEN :ke_a > 0 AND ap.ke = :ke_b THEN 0 ELSE 1 END ASC,
                CASE
                    WHEN :jumlah_a > 0 AND ABS(ap.jumlah_total - :jumlah_b) <= 1 THEN 0
                    WHEN :jumlah_c > 0 AND ABS(ap.jumlah_total - :jumlah_d) <= 1000 THEN 1
                    ELSE 2
                END ASC,
                ap.jatuh_tempo ASC,
                ap.id ASC
            LIMIT 1
        ";

        $params[':jenis_import_a'] = $jenisImport;
        $params[':jenis_import_b'] = $jenisImport !== '' ? '%' . $jenisImport . '%' : '';
        $params[':ke_a'] = $ke;
        $params[':ke_b'] = $ke;
        $params[':jumlah_a'] = $jumlahBayar;
        $params[':jumlah_b'] = $jumlahBayar;
        $params[':jumlah_c'] = $jumlahBayar;
        $params[':jumlah_d'] = $jumlahBayar;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }
}


if (!function_exists('table_columns_ap')) {
    /** @return array<string,bool> */
    function table_columns_ap(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cols = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $cols[$field] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('SHOW COLUMNS ERROR ' . $table . ': ' . $e->getMessage());
        }

        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('first_existing_column_ap')) {
    /** @param array<string,bool> $cols */
    function first_existing_column_ap(array $cols, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (isset($cols[$candidate])) {
                return (string)$candidate;
            }
        }
        return '';
    }
}

if (!function_exists('find_member_rows_by_import_ap')) {
    /** @param array<string,mixed> $row @return array<int,array<string,mixed>> */
    function find_member_rows_by_import_ap(PDO $pdo, array $row): array
    {
        $memberKode = trim((string)($row['member_kode_import'] ?? ''));
        $memberNama = trim((string)($row['member_nama_import'] ?? ''));
        $found = [];
        $seen = [];
        $kodeCompact = preg_replace('/[^A-Za-z0-9]+/', '', $memberKode) ?: '';

        if ($kodeCompact !== '') {
            $stmt = $pdo->prepare("\n                SELECT id, nama, kode, no_hp\n                FROM member\n                WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(kode,''),'.',''),'-',''),' ',''),'/','') = :kode_a\n                   OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp,''),'.',''),'-',''),' ',''),'/','') = :kode_b\n                ORDER BY id ASC\n                LIMIT 20\n            ");
            $stmt->execute([':kode_a' => $kodeCompact, ':kode_b' => $kodeCompact]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $id = (int)($m['id'] ?? 0);
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $found[] = $m;
                }
            }
        }

        if ($memberNama !== '') {
            $cleanName = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $memberNama) ?: '');
            $parts = preg_split('/\s+/', trim($cleanName)) ?: [];
            $parts = array_values(array_filter($parts, static function ($part): bool {
                return strlen($part) >= 3 && !in_array($part, ['dan', 'bin', 'binti', 'sdr', 'sdri'], true);
            }));

            if ($parts) {
                $sql = "SELECT id, nama, kode, no_hp FROM member WHERE 1=1";
                $params = [];
                foreach (array_slice($parts, 0, 2) as $idx => $part) {
                    $key = ':nama_like_member_' . $idx;
                    $sql .= " AND LOWER(nama) LIKE " . $key;
                    $params[$key] = '%' . $part . '%';
                }
                $sql .= " ORDER BY id ASC LIMIT 80";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                usort($members, static function ($a, $b) use ($memberNama): int {
                    return import_name_score_ap((string)($b['nama'] ?? ''), $memberNama) <=> import_name_score_ap((string)($a['nama'] ?? ''), $memberNama);
                });
                foreach ($members as $m) {
                    $id = (int)($m['id'] ?? 0);
                    if ($id > 0 && !isset($seen[$id]) && import_name_score_ap((string)($m['nama'] ?? ''), $memberNama) >= 50) {
                        $seen[$id] = true;
                        $found[] = $m;
                    }
                }
            }
        }

        return $found;
    }
}

if (!function_exists('find_pinjaman_for_import_ap')) {
    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    function find_pinjaman_for_import_ap(PDO $pdo, array $row): ?array
    {
        $members = find_member_rows_by_import_ap($pdo, $row);
        $jenisImport = strtolower(trim((string)($row['jenis_pinjaman_import'] ?? '')));
        if (!$members) {
            return null;
        }

        $memberIds = array_values(array_unique(array_map(static function ($m): int {
            return (int)($m['id'] ?? 0);
        }, $members)));
        $memberIds = array_values(array_filter($memberIds));
        if (!$memberIds) {
            return null;
        }

        $pCols = table_columns_ap($pdo, 'pinjaman');
        $memberFk = first_existing_column_ap($pCols, [
            'member_id',
            'anggota_id',
            'id_member',
            'id_anggota',
            'nasabah_id',
            'member',
            'anggota',
            'user_id',
            'customer_id'
        ]);

        $statusExpr = isset($pCols['status']) ? "COALESCE(p.status,'')" : "''";
        $pokokExpr = isset($pCols['pokok']) ? 'p.pokok' : (isset($pCols['jumlah']) ? 'p.jumlah' : (isset($pCols['jumlah_pinjaman']) ? 'p.jumlah_pinjaman' : '0'));
        $tenorExpr = isset($pCols['tenor']) ? 'p.tenor' : '0';
        $createdExpr = isset($pCols['created_at']) ? 'p.created_at' : 'p.id';

        if ($memberFk !== '') {
            $ph = [];
            $params = [];
            foreach ($memberIds as $i => $id) {
                $k = ':pid_member_' . $i;
                $ph[] = $k;
                $params[$k] = $id;
            }

            $sql = "
                SELECT
                    p.id AS pinjaman_id,
                    p.`$memberFk` AS member_id,
                    $statusExpr AS pinjaman_status,
                    $pokokExpr AS pinjaman_pokok,
                    $tenorExpr AS pinjaman_tenor,
                    m.nama AS member_nama,
                    m.kode AS member_kode,
                    m.no_hp AS member_hp
                FROM pinjaman p
                LEFT JOIN member m ON m.id = p.`$memberFk`
                WHERE p.`$memberFk` IN (" . implode(',', $ph) . ")
                ORDER BY
                    CASE WHEN LOWER($statusExpr) IN ('aktif','disetujui','berjalan','belum_lunas','belum lunas') THEN 0 ELSE 1 END ASC,
                    CASE WHEN :jenis_pinjaman_a <> '' AND LOWER(COALESCE(p.jenis,'')) LIKE :jenis_pinjaman_b THEN 0 ELSE 1 END ASC,
                    p.id DESC,
                    $createdExpr DESC
                LIMIT 1
            ";

            $params[':jenis_pinjaman_a'] = $jenisImport;
            $params[':jenis_pinjaman_b'] = $jenisImport !== '' ? '%' . $jenisImport . '%' : '';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                return $data;
            }
        }

        // Fallback terakhir: beberapa database menyimpan nama/kode langsung di tabel pinjaman.
        $memberNama = trim((string)($row['member_nama_import'] ?? ''));
        $memberKode = preg_replace('/[^A-Za-z0-9]+/', '', (string)($row['member_kode_import'] ?? '')) ?: '';
        $nameCols = array_values(array_filter(['nama', 'nama_member', 'member_nama', 'nama_anggota', 'anggota_nama', 'nama_peminjam'], static function ($c) use ($pCols): bool {
            return isset($pCols[$c]);
        }));
        $codeCols = array_values(array_filter(['kode', 'kode_member', 'member_kode', 'nip', 'nik', 'no_induk'], static function ($c) use ($pCols): bool {
            return isset($pCols[$c]);
        }));

        $where = [];
        $params = [];
        if ($memberKode !== '' && $codeCols) {
            foreach ($codeCols as $i => $col) {
                $k = ':pinj_kode_' . $i;
                $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.`$col`,''),'.',''),'-',''),' ',''),'/','') = $k";
                $params[$k] = $memberKode;
            }
        }
        if ($memberNama !== '' && $nameCols) {
            $parts = preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $memberNama) ?: '')) ?: [];
            $parts = array_values(array_filter($parts, static function ($part): bool {
                return strlen($part) >= 3;
            }));
            if ($parts) {
                foreach ($nameCols as $i => $col) {
                    $k = ':pinj_nama_' . $i;
                    $where[] = "LOWER(p.`$col`) LIKE $k";
                    $params[$k] = '%' . $parts[0] . '%';
                }
            }
        }

        if (!$where) {
            return null;
        }

        $sql = "
            SELECT
                p.id AS pinjaman_id,
                0 AS member_id,
                $statusExpr AS pinjaman_status,
                $pokokExpr AS pinjaman_pokok,
                $tenorExpr AS pinjaman_tenor,
                :nama_preview AS member_nama,
                :kode_preview AS member_kode,
                '' AS member_hp
            FROM pinjaman p
            WHERE (" . implode(' OR ', $where) . ")
            ORDER BY
                CASE WHEN LOWER($statusExpr) IN ('aktif','disetujui','berjalan','belum_lunas','belum lunas') THEN 0 ELSE 1 END ASC,
                p.id DESC,
                $createdExpr DESC
            LIMIT 1
        ";
        $params[':nama_preview'] = $memberNama;
        $params[':kode_preview'] = $memberKode;
        $params[':jenis_pinjaman_a'] = $jenisImport;
        $params[':jenis_pinjaman_b'] = $jenisImport !== '' ? '%' . $jenisImport . '%' : '';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }
}

if (!function_exists('create_angsuran_from_import_ap')) {
    /** @param array<string,mixed> $row */
    function create_angsuran_from_import_ap(PDO $pdo, array $row): int
    {
        $pinjamanId = (int)($row['pinjaman_id'] ?? 0);
        $memberId = (int)($row['member_id'] ?? 0);
        $jumlah = (float)($row['jumlah_bayar'] ?? 0);
        $tanggal = format_import_date_ap($row['tanggal_bayar'] ?? date('Y-m-d'));
        $ke = (int)($row['ke'] ?? 0);

        if ($pinjamanId <= 0 || $memberId <= 0 || $jumlah <= 0) {
            throw new Exception('Data create angsuran tidak lengkap.');
        }

        if ($ke <= 0) {
            $stmtKe = $pdo->prepare("SELECT COALESCE(MAX(ke),0) + 1 FROM angsuran_pinjaman WHERE pinjaman_id = :pinjaman_id");
            $stmtKe->execute([':pinjaman_id' => $pinjamanId]);
            $ke = (int)$stmtKe->fetchColumn();
        }

        $cols = table_columns_ap($pdo, 'angsuran_pinjaman');
        $data = [];
        if (isset($cols['pinjaman_id'])) $data['pinjaman_id'] = $pinjamanId;
        if (isset($cols['member_id'])) $data['member_id'] = $memberId;
        if (isset($cols['ke'])) $data['ke'] = $ke;
        if (isset($cols['jatuh_tempo'])) $data['jatuh_tempo'] = $tanggal;
        if (isset($cols['jumlah_pokok'])) $data['jumlah_pokok'] = $jumlah;
        if (isset($cols['jumlah_bunga'])) $data['jumlah_bunga'] = 0;
        if (isset($cols['jumlah_total'])) $data['jumlah_total'] = $jumlah;
        if (isset($cols['status'])) $data['status'] = 'belum_bayar';
        if (isset($cols['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
        if (isset($cols['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

        if (!isset($data['pinjaman_id']) || !isset($data['jumlah_total'])) {
            throw new Exception('Kolom wajib angsuran_pinjaman tidak lengkap. Butuh pinjaman_id dan jumlah_total.');
        }

        $fieldSql = array_map(static function ($field): string {
            return '`' . str_replace('`', '', $field) . '`';
        }, array_keys($data));
        $placeholders = [];
        $params = [];
        foreach ($data as $field => $value) {
            $key = ':ins_' . $field;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        $stmt = $pdo->prepare('INSERT INTO angsuran_pinjaman (' . implode(',', $fieldSql) . ') VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('diagnose_import_match_ap')) {
    /** @param array<string,mixed> $row */
    function diagnose_import_match_ap(PDO $pdo, array $row): string
    {
        $memberKode = trim((string)($row['member_kode_import'] ?? ''));
        $memberNama = trim((string)($row['member_nama_import'] ?? ''));
        $jumlahBayar = (float)($row['jumlah_bayar'] ?? 0);
        $ke = (int)($row['ke'] ?? 0);

        if ($memberNama === '' && $memberKode === '') {
            return 'Nama/NIP kosong. Cek kolom Excel yang terbaca.';
        }

        $members = find_member_rows_by_import_ap($pdo, $row);
        if (!$members) {
            return 'Member tidak ditemukan: ' . ($memberNama !== '' ? $memberNama : $memberKode) . ' / Ke-' . $ke . ' / ' . rupiah_ap($jumlahBayar);
        }

        $memberIds = array_values(array_unique(array_map(static function ($m): int {
            return (int)($m['id'] ?? 0);
        }, $members)));
        $memberIds = array_values(array_filter($memberIds));
        $first = $members[0] ?? [];
        $firstText = 'Member ID ' . (int)($first['id'] ?? 0) . ' - ' . (string)($first['nama'] ?? $memberNama);

        $apCols = table_columns_ap($pdo, 'angsuran_pinjaman');
        $pCols = table_columns_ap($pdo, 'pinjaman');
        $pMemberFk = first_existing_column_ap($pCols, ['member_id', 'anggota_id', 'id_member', 'id_anggota', 'nasabah_id', 'member', 'anggota', 'user_id', 'customer_id']);

        $totalAngsuran = 0;
        if ($memberIds) {
            $phAp = [];
            $phP = [];
            $params = [];
            foreach ($memberIds as $i => $id) {
                $ka = ':dbg_ap_' . $i;
                $kp = ':dbg_p_' . $i;
                $phAp[] = $ka;
                $phP[] = $kp;
                $params[$ka] = $id;
                $params[$kp] = $id;
            }

            $whereParts = [];
            if (isset($apCols['member_id'])) {
                $whereParts[] = 'ap.member_id IN (' . implode(',', $phAp) . ')';
            }
            if ($pMemberFk !== '') {
                $whereParts[] = 'p.`' . str_replace('`', '', $pMemberFk) . '` IN (' . implode(',', $phP) . ')';
            }

            if ($whereParts) {
                $sql = "
                    SELECT COUNT(*)
                    FROM angsuran_pinjaman ap
                    LEFT JOIN pinjaman p ON p.id = ap.pinjaman_id
                    WHERE " . implode(' OR ', $whereParts) . "
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $totalAngsuran = (int)$stmt->fetchColumn();
            }
        }

        if ($totalAngsuran > 0) {
            return $firstText . ' ditemukan dan angsuran ada, tapi semua sudah lunas atau urutan/nominal tidak cocok.';
        }

        $pinjaman = find_pinjaman_for_import_ap($pdo, $row);
        if ($pinjaman) {
            return $firstText . ' punya pinjaman #' . (int)($pinjaman['pinjaman_id'] ?? 0) . ', tapi belum ada jadwal angsuran. Preview seharusnya valid_create.';
        }

        $fkInfo = $pMemberFk !== '' ? 'kolom relasi pinjaman.' . $pMemberFk : 'tidak ditemukan kolom relasi member di tabel pinjaman';
        return $firstText . ' ditemukan, tetapi tidak ada angsuran dan tidak ada pinjaman yang terhubung (' . $fkInfo . '). Buat data pinjaman dulu atau isi pinjaman_id di file import.';
    }
}

if (!function_exists('sisa_angsuran_ap')) {
    function sisa_angsuran_ap(int $total, int $lunas): int
    {
        return max(0, $total - $lunas);
    }
}



if (isset($_GET['download_template']) && (string)$_GET['download_template'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_angsuran.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['pinjaman_id', 'ke', 'tanggal_bayar', 'jumlah_bayar', 'metode_bayar', 'catatan']);
    fputcsv($out, [1, 1, date('Y-m-d'), 1100000, 'transfer', 'contoh pembayaran']);
    fclose($out);
    exit;
}


$success = '';
$error = '';


$importPreview = [];
$importSummary = [
    'valid' => 0,
    'skip' => 0,
    'gagal' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'bayar_angsuran') {
        $angsuranId = (int)($_POST['id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $result = bayar_angsuran_by_id_ap(
                $pdo,
                $angsuranId,
                $userId,
                date('Y-m-d')
            );

            if (!$result['ok']) {
                throw new Exception($result['message']);
            }

            $pdo->commit();

            $success = 'Angsuran berhasil dibayar dan jurnal otomatis dibuat.';
            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'update', 'Angsuran Pinjaman', 'Membayar angsuran ID #' . $angsuranId . ' sebesar ' . rupiah_ap($result['jumlah_total'] ?? 0));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Gagal membayar angsuran: ' . $e->getMessage();
        }
    }

    if ($action === 'bayar_terpilih') {
        $ids = $_POST['selected_angsuran'] ?? [];

        if (!is_array($ids) || !$ids) {
            $error = 'Pilih minimal satu angsuran.';
        } else {
            $berhasil = 0;
            $gagal = 0;
            $totalNominal = 0;

            try {
                $pdo->beginTransaction();

                foreach ($ids as $idRaw) {
                    $result = bayar_angsuran_by_id_ap(
                        $pdo,
                        (int)$idRaw,
                        $userId,
                        date('Y-m-d')
                    );

                    if ($result['ok']) {
                        $berhasil++;
                        $totalNominal += (float)($result['jumlah_total'] ?? 0);
                    } else {
                        $gagal++;
                    }
                }

                $pdo->commit();

                $success = 'Bayar terpilih selesai. Berhasil ' . $berhasil . ' data, gagal/lewati ' . $gagal . ' data. Total ' . rupiah_ap($totalNominal) . '.';

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'update', 'Angsuran Pinjaman', 'Bayar terpilih ' . $berhasil . ' angsuran sebesar ' . rupiah_ap($totalNominal));
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Gagal bayar terpilih: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'preview_import') {
        try {
            if (empty($_FILES['file_import']['tmp_name']) || !is_uploaded_file($_FILES['file_import']['tmp_name'])) {
                throw new Exception('File import belum dipilih.');
            }

            $rowsRaw = read_import_rows_ap(
                (string)$_FILES['file_import']['tmp_name'],
                (string)($_FILES['file_import']['name'] ?? '')
            );

            $rows = normalize_import_payment_rows_ap($rowsRaw);

            foreach ($rows as $row) {
                $found = find_angsuran_by_import_ap($pdo, $row);

                if (!$found) {
                    $pinjamanFallback = find_pinjaman_for_import_ap($pdo, $row);
                    if ($pinjamanFallback) {
                        $row['status_import'] = 'valid_create';
                        $row['status_label'] = 'Pinjaman ada, angsuran akan dibuat dari Excel';
                        $row['angsuran_id'] = 0;
                        $row['pinjaman_id'] = (int)($pinjamanFallback['pinjaman_id'] ?? 0);
                        $row['member_id'] = (int)($pinjamanFallback['member_id'] ?? 0);
                        $row['member_nama'] = (string)($pinjamanFallback['member_nama'] ?? '-');
                        $row['member_kode'] = (string)($pinjamanFallback['member_kode'] ?? '-');
                        $row['jumlah_total_db'] = (float)($row['jumlah_bayar'] ?? 0);
                        $importSummary['valid']++;
                    } else {
                        $row['status_import'] = 'gagal';
                        $row['status_label'] = diagnose_import_match_ap($pdo, $row);
                        $row['angsuran_id'] = 0;
                        $row['member_nama'] = (string)($row['member_nama_import'] ?? '-');
                        $row['member_kode'] = (string)($row['member_kode_import'] ?? '-');
                        $row['jumlah_total_db'] = (float)($row['jumlah_bayar'] ?? 0);
                        $importSummary['gagal']++;
                    }
                } elseif (angsuran_is_lunas_ap($found['status'] ?? '')) {
                    $row['status_import'] = 'skip';
                    $row['status_label'] = 'Sudah lunas';
                    $row['angsuran_id'] = (int)$found['id'];
                    $row['member_nama'] = (string)($found['member_nama'] ?? '-');
                    $row['member_kode'] = (string)($found['member_kode'] ?? '-');
                    $row['jumlah_total_db'] = (float)($found['jumlah_total'] ?? 0);
                    $importSummary['skip']++;
                } else {
                    $row['status_import'] = 'valid';
                    $row['status_label'] = 'Siap proses';
                    $row['angsuran_id'] = (int)$found['id'];
                    $row['member_nama'] = (string)($found['member_nama'] ?? '-');
                    $row['member_kode'] = (string)($found['member_kode'] ?? '-');
                    $row['jumlah_total_db'] = (float)($found['jumlah_total'] ?? 0);
                    $importSummary['valid']++;
                }

                $importPreview[] = $row;
            }

            if (!$importPreview) {
                throw new Exception('Tidak ada data valid dalam file import.');
            }

            $success = 'Preview import berhasil. Valid ' . $importSummary['valid'] . ', sudah lunas ' . $importSummary['skip'] . ', gagal ' . $importSummary['gagal'] . '.';
        } catch (Throwable $e) {
            $error = 'Gagal preview import: ' . $e->getMessage();
        }
    }

    if ($action === 'proses_import') {
        $payload = (string)($_POST['import_payload'] ?? '');
        $rows = json_decode(base64_decode($payload), true);

        if (!is_array($rows) || !$rows) {
            $error = 'Data import tidak valid atau sudah kadaluarsa. Silakan upload ulang.';
        } else {
            $berhasil = 0;
            $gagal = 0;
            $totalNominal = 0;

            try {
                $pdo->beginTransaction();

                foreach ($rows as $row) {
                    $statusImport = (string)($row['status_import'] ?? '');
                    if (!in_array($statusImport, ['valid', 'valid_create'], true)) {
                        continue;
                    }

                    $angsuranIdImport = (int)($row['angsuran_id'] ?? 0);
                    if ($statusImport === 'valid_create') {
                        $angsuranIdImport = create_angsuran_from_import_ap($pdo, $row);
                    }

                    $result = bayar_angsuran_by_id_ap(
                        $pdo,
                        $angsuranIdImport,
                        $userId,
                        (string)($row['tanggal_bayar'] ?? date('Y-m-d'))
                    );

                    if ($result['ok']) {
                        $berhasil++;
                        $totalNominal += (float)($result['jumlah_total'] ?? 0);
                    } else {
                        $gagal++;
                    }
                }

                $pdo->commit();

                $success = 'Import pembayaran selesai. Berhasil ' . $berhasil . ' data, gagal/lewati ' . $gagal . ' data. Total ' . rupiah_ap($totalNominal) . '.';

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'import', 'Angsuran Pinjaman', 'Import pembayaran angsuran ' . $berhasil . ' data sebesar ' . rupiah_ap($totalNominal));
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Gagal proses import: ' . $e->getMessage();
            }
        }
    }
}


$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$awal = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        m.nama LIKE :q1
        OR m.kode LIKE :q2
        OR ap.id LIKE :q3
        OR ap.pinjaman_id LIKE :q4
    )";
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
    $params[':q4'] = '%' . $q . '%';
}

if ($statusFilter !== '') {
    if ($statusFilter === 'belum_bayar') {
        $where[] = "(ap.status IS NULL OR TRIM(ap.status) = '' OR ap.status NOT IN ('lunas', 'dibayar', 'bayar'))";
    } elseif ($statusFilter === 'lunas') {
        $where[] = "LOWER(TRIM(COALESCE(ap.status,''))) IN ('lunas', 'dibayar', 'bayar')";
    }
}

if ($awal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
    $where[] = 'ap.jatuh_tempo >= :awal';
    $params[':awal'] = $awal;
}

if ($akhir !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) {
    $where[] = 'ap.jatuh_tempo <= :akhir';
    $params[':akhir'] = $akhir;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$summary = [
    'total' => 0,
    'belum_bayar' => 0,
    'lunas' => 0,
    'jatuh_tempo' => 0,
    'total_tagihan' => 0,
    'total_terbayar' => 0,
];

$angsuranList = [];

try {
    $stmtSummary = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('lunas', 'dibayar', 'bayar') THEN 0 ELSE 1 END),0) AS belum_bayar,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('lunas', 'dibayar', 'bayar') THEN 1 ELSE 0 END),0) AS lunas,
            COALESCE(SUM(CASE WHEN (status IS NULL OR TRIM(status) = '' OR status NOT IN ('lunas', 'dibayar', 'bayar')) AND jatuh_tempo < CURDATE() THEN 1 ELSE 0 END),0) AS jatuh_tempo,
            COALESCE(SUM(CASE WHEN (status IS NULL OR TRIM(status) = '' OR status NOT IN ('lunas', 'dibayar', 'bayar')) THEN jumlah_total ELSE 0 END),0) AS total_tagihan,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('lunas', 'dibayar', 'bayar') THEN jumlah_total ELSE 0 END),0) AS total_terbayar
        FROM angsuran_pinjaman
    ");
    $rowSummary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        $summary[$key] = (float)($rowSummary[$key] ?? 0);
    }

    $stmt = $pdo->prepare("
        SELECT
            ap.*,
            m.nama AS member_nama,
            m.kode AS member_kode,
            m.no_hp AS member_hp,
            p.jenis AS pinjaman_jenis,
            p.pokok AS pinjaman_pokok,
            p.tenor AS pinjaman_tenor,
            p.status AS pinjaman_status,
            u.nama AS dibayar_oleh_nama,

            COALESCE(pr.total_angsuran, 0) AS total_angsuran_pinjaman,
            COALESCE(pr.angsuran_lunas, 0) AS angsuran_lunas_pinjaman,
            COALESCE(pr.sisa_angsuran, 0) AS sisa_angsuran_pinjaman,
            COALESCE(pr.total_tagihan_pinjaman, 0) AS total_tagihan_pinjaman,
            COALESCE(pr.total_terbayar_pinjaman, 0) AS total_terbayar_pinjaman,
            COALESCE(pr.sisa_tagihan_pinjaman, 0) AS sisa_tagihan_pinjaman
        FROM angsuran_pinjaman ap
        LEFT JOIN member m ON m.id = ap.member_id
        LEFT JOIN pinjaman p ON p.id = ap.pinjaman_id
        LEFT JOIN users u ON u.id = ap.dibayar_oleh
        LEFT JOIN (
            SELECT
                pinjaman_id,
                COUNT(*) AS total_angsuran,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('lunas', 'dibayar', 'bayar') THEN 1 ELSE 0 END) AS angsuran_lunas,
                SUM(CASE WHEN (status IS NULL OR TRIM(status) = '' OR status NOT IN ('lunas', 'dibayar', 'bayar')) THEN 1 ELSE 0 END) AS sisa_angsuran,
                COALESCE(SUM(jumlah_total), 0) AS total_tagihan_pinjaman,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('lunas', 'dibayar', 'bayar') THEN jumlah_total ELSE 0 END), 0) AS total_terbayar_pinjaman,
                COALESCE(SUM(CASE WHEN (status IS NULL OR TRIM(status) = '' OR status NOT IN ('lunas', 'dibayar', 'bayar')) THEN jumlah_total ELSE 0 END), 0) AS sisa_tagihan_pinjaman
            FROM angsuran_pinjaman
            GROUP BY pinjaman_id
        ) pr ON pr.pinjaman_id = ap.pinjaman_id
        $whereSql
        ORDER BY
            CASE WHEN LOWER(TRIM(COALESCE(ap.status,''))) IN ('lunas', 'dibayar', 'bayar') THEN 1 ELSE 0 END ASC,
            ap.jatuh_tempo ASC,
            ap.id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $angsuranList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data angsuran: ' . $e->getMessage();
}


$pinjamanRingkas = [];
foreach ($angsuranList as $row) {
    $pid = (int)($row['pinjaman_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }

    if (!isset($pinjamanRingkas[$pid])) {
        $totalAng = (int)($row['total_angsuran_pinjaman'] ?? $row['pinjaman_tenor'] ?? 0);
        $lunasAng = (int)($row['angsuran_lunas_pinjaman'] ?? 0);
        $sisaAng = sisa_angsuran_ap($totalAng, $lunasAng);
        $progress = progress_width_ap($lunasAng, $totalAng);

        $pinjamanRingkas[$pid] = [
            'pinjaman_id' => $pid,
            'member_nama' => $row['member_nama'] ?? '-',
            'member_kode' => $row['member_kode'] ?? '-',
            'member_hp' => $row['member_hp'] ?? '-',
            'jenis' => $row['pinjaman_jenis'] ?? '-',
            'pokok' => $row['pinjaman_pokok'] ?? 0,
            'status_pinjaman' => $row['pinjaman_status'] ?? '-',
            'total_angsuran' => $totalAng,
            'lunas_angsuran' => $lunasAng,
            'sisa_angsuran' => $sisaAng,
            'progress' => $progress,
            'total_tagihan' => $row['total_tagihan_pinjaman'] ?? 0,
            'total_terbayar' => $row['total_terbayar_pinjaman'] ?? 0,
            'sisa_tagihan' => $row['sisa_tagihan_pinjaman'] ?? 0,
            'tagihan_aktif_id' => 0,
            'tagihan_aktif_ke' => '-',
            'tagihan_aktif_total' => 0,
            'tagihan_aktif_jatuh_tempo' => null,
            'ada_telat' => false,
            'detail' => [],
        ];
    }

    $isLunasRow = angsuran_is_lunas_ap($row['status'] ?? '');
    $isTelatRow = !$isLunasRow && !empty($row['jatuh_tempo']) && strtotime((string)$row['jatuh_tempo']) < strtotime(date('Y-m-d'));

    if ($isTelatRow) {
        $pinjamanRingkas[$pid]['ada_telat'] = true;
    }

    if (!$isLunasRow && (int)($pinjamanRingkas[$pid]['tagihan_aktif_id'] ?? 0) === 0) {
        $pinjamanRingkas[$pid]['tagihan_aktif_id'] = (int)($row['id'] ?? 0);
        $pinjamanRingkas[$pid]['tagihan_aktif_ke'] = (int)($row['ke'] ?? 0);
        $pinjamanRingkas[$pid]['tagihan_aktif_total'] = (float)($row['jumlah_total'] ?? 0);
        $pinjamanRingkas[$pid]['tagihan_aktif_jatuh_tempo'] = $row['jatuh_tempo'] ?? null;
    }

    $pinjamanRingkas[$pid]['detail'][] = $row;
}

$pinjamanRingkas = array_values($pinjamanRingkas);

if (function_exists('catat_view_once')) {
    catat_view_once($pdo, 'Angsuran Pinjaman', 'Membuka halaman Angsuran Pinjaman');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Angsuran Pinjaman — Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #1a1a1a;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #111 !important;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .05);
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        .ap-progress {
            width: 100%;
            height: 7px;
            background: #f3f4f6;
            border: 1px solid #eee;
            overflow: hidden;
        }

        .ap-progress-fill {
            height: 100%;
            background: #111;
        }

        .ap-member-box {
            border-left: 3px solid #111;
            padding-left: 10px;
        }

        .ap-mini-stat {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border: 1px solid #f0f0f0;
            background: #fafafa;
            font-size: 10px;
            font-weight: 800;
            color: #525252;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .import-box {
            border: 1px dashed #d4d4d4;
            background: #fff;
        }

        .ap-check {
            width: 16px;
            height: 16px;
            accent-color: #111;
        }

        .ap-sticky-actions {
            position: sticky;
            top: 76px;
            z-index: 20;
        }

        .card-list {
            display: none;
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .app-header,
            .page-header,
            .main-wrap,
            .content,
            .produk-header,
            .produk-main,
            .diskon-header,
            .diskon-main,
            .stok-header,
            .stok-main-wrap,
            .laporan-header,
            .laporan-main-wrap {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .app-header,
            .main-wrap,
            .content {
                margin-left: 0 !important;
            }

            .main-content {
                padding: 1rem !important;
                padding-bottom: 6rem !important;
            }

            .tbl-desktop {
                display: none !important;
            }

            .card-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .75rem;
                padding: .75rem;
                background: #fff;
            }
        }

        @media (max-width: 640px) {
            .card-list {
                grid-template-columns: 1fr;
            }
        }

        .import-panel {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 14px;
        }

        .import-card {
            border: 1px solid #e5e5e5;
            background: #fff;
            padding: 18px;
        }

        .import-upload {
            border: 1px dashed #a3a3a3;
            background: #fafafa;
            padding: 16px;
        }

        .import-note {
            background: #f9fafb;
            border: 1px solid #f0f0f0;
            padding: 12px;
            font-size: 11px;
            line-height: 1.6;
            color: #525252;
        }

        @media (max-width: 1023px) {
            .import-panel {
                grid-template-columns: 1fr;
            }

            .ap-sticky-actions {
                position: static;
            }
        }

        @media (max-width: 640px) {
            .import-card {
                padding: 14px;
            }

            .ap-sticky-actions {
                align-items: stretch !important;
            }

            .ap-sticky-actions>div:last-child {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
            }

            .ap-sticky-actions button {
                width: 100%;
            }
        }

        /* TABLE RAPI FINAL OVERRIDE */
        .import-panel {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 14px;
        }

        .import-card {
            border: 1px solid #f0f0f0;
            background: #fff;
            padding: 18px;
        }

        .import-upload {
            border: 1px dashed #d4d4d4;
            background: #fafafa;
            padding: 14px;
        }

        .ap-sticky-actions {
            position: static !important;
        }

        .angsuran-table-clean {
            width: 100%;
            min-width: 1180px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .angsuran-table-clean thead th {
            background: #fafafa;
            padding: 12px 10px !important;
            border-bottom: 1px solid #f0f0f0;
            font-size: 10px !important;
            line-height: 1.2;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .10em;
            white-space: nowrap;
        }

        .angsuran-table-clean tbody td {
            padding: 14px 10px !important;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }

        .angsuran-table-clean .w-check {
            width: 42px;
        }

        .angsuran-table-clean .w-member {
            width: 190px;
        }

        .angsuran-table-clean .w-pinjaman {
            width: 230px;
        }

        .angsuran-table-clean .w-ke {
            width: 70px;
        }

        .angsuran-table-clean .w-money {
            width: 125px;
        }

        .angsuran-table-clean .w-date {
            width: 115px;
        }

        .angsuran-table-clean .w-status {
            width: 90px;
        }

        .angsuran-table-clean .w-paid {
            width: 145px;
        }

        .angsuran-table-clean .w-action {
            width: 75px;
        }

        .member-title-clean {
            font-size: 14px;
            line-height: 1.25;
            font-weight: 900;
            color: #111;
        }

        .small-muted-clean {
            font-size: 10px;
            line-height: 1.35;
            color: #9ca3af;
        }

        .money-clean {
            font-size: 14px;
            font-weight: 900;
            white-space: nowrap;
        }

        .chip-clean {
            display: inline-flex;
            border: 1px solid #f0f0f0;
            background: #fafafa;
            padding: 5px 7px;
            font-size: 9px;
            line-height: 1;
            font-weight: 900;
            color: #525252;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        .btn-clean-pay {
            display: inline-flex;
            min-width: 60px;
            align-items: center;
            justify-content: center;
            background: #000;
            color: #fff;
            padding: 9px 10px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .toolbar-clean {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }

        .toolbar-clean-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        @media (max-width: 1023px) {
            .import-panel {
                grid-template-columns: 1fr;
            }

            .toolbar-clean {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-clean-actions {
                display: grid;
                grid-template-columns: 1fr;
            }
        }


        /* RINGKAS MODE FINAL */
        .summary-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .summary-table th {
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
            padding: 13px 14px;
            font-size: 10px;
            line-height: 1.2;
            font-weight: 900;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .10em;
            white-space: nowrap;
        }

        .summary-table td {
            padding: 16px 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }

        .detail-row {
            display: none;
            background: #fcfcfc;
        }

        .detail-row.is-open {
            display: table-row;
        }

        .detail-box {
            padding: 18px;
            border: 1px solid #f0f0f0;
            background: #fff;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
        }

        .detail-item {
            border: 1px solid #f0f0f0;
            background: #fafafa;
            padding: 10px;
        }

        .detail-item.is-paid {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .detail-item.is-late {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .btn-outline-clean {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e5e5;
            background: #fff;
            color: #111;
            padding: 9px 12px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .summary-card-list {
            display: none;
        }

        @media (max-width: 1023px) {
            .summary-table-wrap {
                display: none;
            }

            .summary-card-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .75rem;
                background: #fff;
                padding: .75rem;
            }

            .detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .summary-card-list {
                grid-template-columns: 1fr;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <div class="main-wrap">
        <main class="main-content p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-xs font-bold">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Angsuran</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka_ap($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Semua data</p>
                </div>

                <div class="bg-white border border-amber-100 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Belum Bayar</p>
                    <p class="text-2xl font-bold text-amber-600"><?= angka_ap($summary['belum_bayar']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Menunggu pembayaran</p>
                </div>

                <div class="bg-white border border-green-100 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Lunas</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka_ap($summary['lunas']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Sudah dibayar</p>
                </div>

                <div class="bg-white border border-red-100 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jatuh Tempo</p>
                    <p class="text-2xl font-bold text-red-600"><?= angka_ap($summary['jatuh_tempo']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Belum bayar lewat tempo</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tagihan Aktif</p>
                    <p class="text-xl font-bold"><?= rupiah_ap($summary['total_tagihan']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Belum dibayar</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Terbayar</p>
                    <p class="text-xl font-bold"><?= rupiah_ap($summary['total_terbayar']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Masuk jurnal</p>
                </div>
            </div>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto_auto] gap-3 items-end">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Angsuran</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Nama / kode member / ID angsuran / ID pinjaman">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <option value="belum_bayar" <?= $statusFilter === 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                            <option value="lunas" <?= $statusFilter === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Awal</label>
                        <input type="date" name="awal" value="<?= h($awal) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Akhir</label>
                        <input type="date" name="akhir" value="<?= h($akhir) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tampilkan
                    </button>

                    <a href="angsuran_pinjaman.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section class="import-panel">
                <div class="import-card">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                        <div>
                            <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Import Excel / CSV Pembayaran</h2>
                            <p class="text-sm font-black mt-1">Upload daftar pembayaran angsuran member.</p>
                            <p class="text-xs text-gray-400 mt-1">Support CSV dan XLSX multi-sheet tanpa PhpSpreadsheet, termasuk format bank potongan pegawai.</p>
                        </div>
                        <a href="?download_template=1" class="inline-flex justify-center px-4 py-2 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-600 hover:bg-gray-50">
                            Download Template
                        </a>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="import-upload">
                        <input type="hidden" name="action" value="preview_import">

                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Upload File</label>
                        <input type="file" name="file_import" accept=".csv,.txt,.xlsx" required class="w-full bg-white border border-gray-200 px-3 py-3 text-sm">

                        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 mt-4 items-center">
                            <p class="text-[11px] text-gray-500 leading-relaxed">
                                Format template: <strong>pinjaman_id, ke, tanggal_bayar, jumlah_bayar, metode_bayar, catatan</strong><br>Atau upload file bank berisi <strong>Nama Pegawai, NIP/NIK, Simpan Pinjam, Iuran Ke</strong>.
                            </p>
                            <button type="submit" class="px-5 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                                Preview Import
                            </button>
                        </div>
                    </form>
                </div>

                <div class="import-card">
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Status Preview Import</h2>
                    <div class="import-note mt-3">
                        <div><strong>1.</strong> Download template.</div>
                        <div><strong>2.</strong> Isi pinjaman_id dan cicilan ke.</div>
                        <div><strong>3.</strong> Upload file lalu preview.</div>
                        <div><strong>4.</strong> Proses hanya data yang valid.</div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Valid</p>
                            <p class="text-lg font-black text-green-600"><?= angka_ap($importSummary['valid'] ?? 0) ?></p>
                        </div>
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Skip</p>
                            <p class="text-lg font-black text-amber-600"><?= angka_ap($importSummary['skip'] ?? 0) ?></p>
                        </div>
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Gagal</p>
                            <p class="text-lg font-black text-red-600"><?= angka_ap($importSummary['gagal'] ?? 0) ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($importPreview): ?>
                <section class="bg-white border border-subtle overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Preview Import Pembayaran</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Cek data valid sebelum diproses menjadi pembayaran angsuran.</p>
                        </div>
                        <?php if (($importSummary['valid'] ?? 0) > 0): ?>
                            <form method="POST" onsubmit="return confirm('Proses semua data import yang valid?');">
                                <input type="hidden" name="action" value="proses_import">
                                <input type="hidden" name="import_payload" value="<?= h(base64_encode(json_encode($importPreview))) ?>">
                                <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                    Proses Import Valid
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:1000px">
                            <thead class="border-b border-subtle bg-gray-50">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Row</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Pinjaman</th>
                                    <th class="w-member">Member</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Ke</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Nominal</th>
                                    <th class="w-status">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php foreach ($importPreview as $row): ?>
                                    <?php
                                    $cls = 'bg-gray-50 text-gray-700 border-gray-200';
                                    if (in_array(($row['status_import'] ?? ''), ['valid', 'valid_create'], true)) $cls = 'bg-green-50 text-green-700 border-green-200';
                                    elseif (($row['status_import'] ?? '') === 'skip') $cls = 'bg-amber-50 text-amber-700 border-amber-200';
                                    elseif (($row['status_import'] ?? '') === 'gagal') $cls = 'bg-red-50 text-red-700 border-red-200';
                                    ?>
                                    <tr>
                                        <td class="px-5 py-3 text-xs font-mono text-gray-500"><?= angka_ap($row['row_no'] ?? 0) ?></td>
                                        <td class="px-5 py-3 text-sm font-bold">#<?= (int)($row['pinjaman_id'] ?? 0) ?></td>
                                        <td class="px-5 py-3">
                                            <div class="text-sm font-bold"><?= h($row['member_nama'] ?? '-') ?></div>
                                            <div class="text-[10px] text-gray-400"><?= h($row['member_kode'] ?? '-') ?></div>
                                        </td>
                                        <td class="px-5 py-3 text-center text-sm font-black"><?= angka_ap($row['ke'] ?? 0) ?></td>
                                        <td class="px-5 py-3 text-xs text-gray-500"><?= h(tanggal_ap($row['tanggal_bayar'] ?? null)) ?></td>
                                        <td class="px-5 py-3 text-right text-sm font-black"><?= rupiah_ap($row['jumlah_total_db'] ?? 0) ?></td>
                                        <td class="px-5 py-3">
                                            <span class="<?= h($cls) ?> border text-[9px] font-bold uppercase px-2 py-1"><?= h($row['status_label'] ?? '-') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>


            <section class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Ringkasan Per Pinjaman</h2>
                        <p class="text-xs text-gray-400 mt-0.5">Agar mudah melihat siapa yang mengangsur, cicilan ke berapa, dan sisa angsuran.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
                    <?php
                    $pinjamanGroups = [];
                    foreach ($angsuranList as $row) {
                        $pid = (int)($row['pinjaman_id'] ?? 0);
                        if ($pid <= 0 || isset($pinjamanGroups[$pid])) {
                            continue;
                        }
                        $totalAng = (int)($row['total_angsuran_pinjaman'] ?? $row['pinjaman_tenor'] ?? 0);
                        $lunasAng = (int)($row['angsuran_lunas_pinjaman'] ?? 0);
                        $sisaAng = sisa_angsuran_ap($totalAng, $lunasAng);
                        $persen = progress_width_ap($lunasAng, $totalAng);
                        $pinjamanGroups[$pid] = [
                            'id' => $pid,
                            'member_nama' => $row['member_nama'] ?? '-',
                            'member_kode' => $row['member_kode'] ?? '-',
                            'member_hp' => $row['member_hp'] ?? '-',
                            'jenis' => $row['pinjaman_jenis'] ?? '-',
                            'pokok' => $row['pinjaman_pokok'] ?? 0,
                            'status' => $row['pinjaman_status'] ?? '-',
                            'total' => $totalAng,
                            'lunas' => $lunasAng,
                            'sisa' => $sisaAng,
                            'persen' => $persen,
                            'tagihan' => $row['total_tagihan_pinjaman'] ?? 0,
                            'terbayar' => $row['total_terbayar_pinjaman'] ?? 0,
                            'sisa_tagihan' => $row['sisa_tagihan_pinjaman'] ?? 0,
                        ];
                    }
                    ?>

                    <?php if (!$pinjamanGroups): ?>
                        <div class="md:col-span-2 xl:col-span-3 py-10 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                            Belum ada ringkasan pinjaman
                        </div>
                    <?php endif; ?>

                    <?php foreach ($pinjamanGroups as $g): ?>
                        <div class="border border-subtle bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-black"><?= h($g['member_nama']) ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono"><?= h($g['member_kode']) ?> · <?= h($g['member_hp']) ?></p>
                                </div>
                                <span class="text-[9px] font-black uppercase px-2 py-1 border border-gray-200 bg-gray-50">
                                    #<?= (int)$g['id'] ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-3 gap-2 mt-4 text-center">
                                <div class="border border-gray-100 bg-gray-50 p-2">
                                    <p class="text-[9px] text-gray-400 font-bold uppercase">Lunas</p>
                                    <p class="text-sm font-black"><?= angka_ap($g['lunas']) ?>/<?= angka_ap($g['total']) ?></p>
                                </div>
                                <div class="border border-gray-100 bg-gray-50 p-2">
                                    <p class="text-[9px] text-gray-400 font-bold uppercase">Sisa</p>
                                    <p class="text-sm font-black text-red-600"><?= angka_ap($g['sisa']) ?>x</p>
                                </div>
                                <div class="border border-gray-100 bg-gray-50 p-2">
                                    <p class="text-[9px] text-gray-400 font-bold uppercase">Progress</p>
                                    <p class="text-sm font-black"><?= angka_ap($g['persen']) ?>%</p>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="ap-progress">
                                    <div class="ap-progress-fill" style="width: <?= (int)$g['persen'] ?>%;"></div>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <p class="text-gray-400 font-medium">Terbayar</p>
                                    <p class="font-black text-green-600"><?= rupiah_ap($g['terbayar']) ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 font-medium">Sisa Tagihan</p>
                                    <p class="font-black text-red-600"><?= rupiah_ap($g['sisa_tagihan']) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="angsuran-section">
                <div class="toolbar-clean">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Daftar Pinjaman Aktif</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka_ap(count($pinjamanRingkas)) ?> pinjaman/member ditampilkan secara ringkas</p>
                    </div>

                    <div class="toolbar-clean-actions">
                        <form method="POST" id="bulkPayForm" onsubmit="return confirm('Bayar semua tagihan aktif yang dipilih?');">
                            <input type="hidden" name="action" value="bayar_terpilih">
                        </form>

                        <label class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500">
                            <input type="checkbox" class="ap-check" id="checkAllAngsuran">
                            Pilih Semua Tagihan
                        </label>

                        <button type="submit" form="bulkPayForm" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                            Bayar Terpilih
                        </button>
                    </div>
                </div>

                <div class="summary-table-wrap overflow-x-auto no-scrollbar">
                    <table class="summary-table text-left">
                        <thead>
                            <tr>
                                <th style="width:42px">Pilih</th>
                                <th style="width:230px">Member</th>
                                <th style="width:210px">Pinjaman</th>
                                <th style="width:230px">Progress</th>
                                <th style="width:160px" class="text-right">Tagihan Aktif</th>
                                <th style="width:150px" class="text-right">Sisa</th>
                                <th style="width:120px">Status</th>
                                <th style="width:180px" class="text-right">Aksi</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$pinjamanRingkas): ?>
                                <tr>
                                    <td colspan="8" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada data pinjaman
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($pinjamanRingkas as $g): ?>
                                <?php
                                $aktifId = (int)($g['tagihan_aktif_id'] ?? 0);
                                $isLunasAll = $aktifId <= 0;
                                $statusRingkas = $isLunasAll ? 'Lunas' : (($g['ada_telat'] ?? false) ? 'Telat' : 'Belum Bayar');
                                $statusClass = $isLunasAll ? status_angsuran_class_ap('lunas') : (($g['ada_telat'] ?? false) ? status_angsuran_class_ap('telat') : status_angsuran_class_ap('belum_bayar'));
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isLunasAll): ?>
                                            <input type="checkbox" name="selected_angsuran[]" value="<?= $aktifId ?>" form="bulkPayForm" class="ap-check bulk-angsuran-check">
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="ap-member-box">
                                            <div class="member-title-clean"><?= h($g['member_nama']) ?></div>
                                            <div class="small-muted-clean font-mono mt-0.5"><?= h($g['member_kode']) ?> · <?= h($g['member_hp']) ?></div>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="font-black text-sm">Pinjaman #<?= (int)$g['pinjaman_id'] ?></div>
                                        <div class="small-muted-clean"><?= h(ucfirst((string)$g['jenis'])) ?> · Pokok <?= rupiah_ap($g['pokok']) ?></div>
                                    </td>

                                    <td>
                                        <div class="progress-text">
                                            <span><?= angka_ap($g['lunas_angsuran']) ?>/<?= angka_ap($g['total_angsuran']) ?> cicilan lunas</span>
                                            <span><?= angka_ap($g['progress']) ?>%</span>
                                        </div>
                                        <div class="ap-progress">
                                            <div class="ap-progress-fill" style="width: <?= (int)$g['progress'] ?>%;"></div>
                                        </div>
                                    </td>

                                    <td class="text-right">
                                        <div class="money-clean"><?= $isLunasAll ? '-' : rupiah_ap($g['tagihan_aktif_total']) ?></div>
                                        <div class="small-muted-clean">
                                            <?= $isLunasAll ? 'Tidak ada tagihan' : 'Ke-' . angka_ap($g['tagihan_aktif_ke']) . ' · ' . tanggal_ap($g['tagihan_aktif_jatuh_tempo']) ?>
                                        </div>
                                    </td>

                                    <td class="text-right">
                                        <div class="money-clean text-red-600"><?= rupiah_ap($g['sisa_tagihan']) ?></div>
                                        <div class="small-muted-clean">Sisa <?= angka_ap($g['sisa_angsuran']) ?>x</div>
                                    </td>

                                    <td>
                                        <span class="<?= h($statusClass) ?> border text-[9px] font-bold uppercase px-2 py-1">
                                            <?= h($statusRingkas) ?>
                                        </span>
                                    </td>

                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="btn-outline-clean" onclick="toggleDetailPinjaman('<?= (int)$g['pinjaman_id'] ?>')">Detail</button>

                                            <?php if (!$isLunasAll): ?>
                                                <form method="POST" class="inline-block" onsubmit="return confirm('Bayar tagihan aktif member ini?');">
                                                    <input type="hidden" name="action" value="bayar_angsuran">
                                                    <input type="hidden" name="id" value="<?= $aktifId ?>">
                                                    <button type="submit" class="btn-clean-pay">Bayar</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <tr id="detail-pinjaman-<?= (int)$g['pinjaman_id'] ?>" class="detail-row">
                                    <td colspan="8">
                                        <div class="detail-box">
                                            <div class="flex items-center justify-between gap-3 mb-4">
                                                <div>
                                                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Detail Cicilan</p>
                                                    <p class="text-sm font-black"><?= h($g['member_nama']) ?> · Pinjaman #<?= (int)$g['pinjaman_id'] ?></p>
                                                </div>
                                                <button type="button" class="btn-outline-clean" onclick="toggleDetailPinjaman('<?= (int)$g['pinjaman_id'] ?>')">Tutup</button>
                                            </div>

                                            <div class="detail-grid">
                                                <?php foreach ($g['detail'] as $d): ?>
                                                    <?php
                                                    $dLunas = angsuran_is_lunas_ap($d['status'] ?? '');
                                                    $dTelat = !$dLunas && !empty($d['jatuh_tempo']) && strtotime((string)$d['jatuh_tempo']) < strtotime(date('Y-m-d'));
                                                    $dClass = $dLunas ? 'is-paid' : ($dTelat ? 'is-late' : '');
                                                    $dStatus = $dLunas ? 'Lunas' : ($dTelat ? 'Telat' : 'Belum');
                                                    ?>
                                                    <div class="detail-item <?= h($dClass) ?>">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <p class="text-sm font-black">Ke <?= angka_ap($d['ke'] ?? 0) ?></p>
                                                            <span class="text-[9px] font-black uppercase"><?= h($dStatus) ?></span>
                                                        </div>
                                                        <p class="small-muted-clean mt-1"><?= h(tanggal_ap($d['jatuh_tempo'] ?? null)) ?></p>
                                                        <p class="text-sm font-black mt-2"><?= rupiah_ap($d['jumlah_total'] ?? 0) ?></p>
                                                        <?php if (!$dLunas): ?>
                                                            <form method="POST" class="mt-2" onsubmit="return confirm('Bayar cicilan ini?');">
                                                                <input type="hidden" name="action" value="bayar_angsuran">
                                                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                                                <button type="submit" class="btn-clean-pay w-full">Bayar</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="summary-card-list">
                    <?php foreach ($pinjamanRingkas as $g): ?>
                        <?php
                        $aktifId = (int)($g['tagihan_aktif_id'] ?? 0);
                        $isLunasAll = $aktifId <= 0;
                        $statusRingkas = $isLunasAll ? 'Lunas' : (($g['ada_telat'] ?? false) ? 'Telat' : 'Belum Bayar');
                        $statusClass = $isLunasAll ? status_angsuran_class_ap('lunas') : (($g['ada_telat'] ?? false) ? status_angsuran_class_ap('telat') : status_angsuran_class_ap('belum_bayar'));
                        ?>
                        <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-black text-sm"><?= h($g['member_nama']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= h($g['member_kode']) ?> · <?= h($g['member_hp']) ?></p>
                                </div>
                                <span class="<?= h($statusClass) ?> border text-[9px] font-bold uppercase px-2 py-1"><?= h($statusRingkas) ?></span>
                            </div>

                            <div class="pt-2 border-t border-subtle">
                                <p class="text-sm font-bold">Pinjaman #<?= (int)$g['pinjaman_id'] ?></p>
                                <p class="text-[10px] text-gray-400"><?= h(ucfirst((string)$g['jenis'])) ?> · <?= rupiah_ap($g['pokok']) ?></p>
                                <div class="ap-progress mt-2">
                                    <div class="ap-progress-fill" style="width: <?= (int)$g['progress'] ?>%;"></div>
                                </div>
                                <p class="text-[10px] text-gray-400 mt-1"><?= angka_ap($g['lunas_angsuran']) ?>/<?= angka_ap($g['total_angsuran']) ?> lunas · sisa <?= angka_ap($g['sisa_angsuran']) ?>x</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Tagihan Aktif</span>
                                    <p class="font-black"><?= $isLunasAll ? '-' : rupiah_ap($g['tagihan_aktif_total']) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Sisa Tagihan</span>
                                    <p class="font-black text-red-600"><?= rupiah_ap($g['sisa_tagihan']) ?></p>
                                </div>
                            </div>

                            <button type="button" class="w-full btn-outline-clean" onclick="toggleDetailPinjaman('m<?= (int)$g['pinjaman_id'] ?>')">Detail Cicilan</button>

                            <div id="detail-pinjaman-m<?= (int)$g['pinjaman_id'] ?>" class="hidden border-t border-subtle pt-3">
                                <div class="detail-grid">
                                    <?php foreach ($g['detail'] as $d): ?>
                                        <?php
                                        $dLunas = angsuran_is_lunas_ap($d['status'] ?? '');
                                        $dTelat = !$dLunas && !empty($d['jatuh_tempo']) && strtotime((string)$d['jatuh_tempo']) < strtotime(date('Y-m-d'));
                                        $dClass = $dLunas ? 'is-paid' : ($dTelat ? 'is-late' : '');
                                        ?>
                                        <div class="detail-item <?= h($dClass) ?>">
                                            <p class="text-sm font-black">Ke <?= angka_ap($d['ke'] ?? 0) ?></p>
                                            <p class="small-muted-clean"><?= h(tanggal_ap($d['jatuh_tempo'] ?? null)) ?></p>
                                            <p class="text-sm font-black mt-1"><?= rupiah_ap($d['jumlah_total'] ?? 0) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if (!$isLunasAll): ?>
                                <form method="POST" onsubmit="return confirm('Bayar tagihan aktif member ini?');">
                                    <input type="hidden" name="action" value="bayar_angsuran">
                                    <input type="hidden" name="id" value="<?= $aktifId ?>">
                                    <button type="submit" class="w-full btn-clean-pay">Bayar Tagihan Aktif</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        </main>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var checkAll = document.getElementById('checkAllAngsuran');
            if (!checkAll) return;

            checkAll.addEventListener('change', function() {
                document.querySelectorAll('.bulk-angsuran-check').forEach(function(cb) {
                    cb.checked = checkAll.checked;
                });
            });
        });
    </script>


    <script>
        function toggleDetailPinjaman(id) {
            var el = document.getElementById('detail-pinjaman-' + id);
            if (!el) return;

            if (el.classList.contains('detail-row')) {
                el.classList.toggle('is-open');
            } else {
                el.classList.toggle('hidden');
            }
        }
    </script>

</body>

</html>