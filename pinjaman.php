<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'activity_helper.php';

$activeMenu = 'pinjaman';
$pageTitle  = 'Pengajuan Pinjaman';
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

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    /**
     * @param mixed $v
     * @return string
     */
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('rupiah_sp')) {
    /**
     * @param mixed $n
     * @return string
     */
    function rupiah_sp($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}


if (!function_exists('sp_coa_id')) {
    function sp_coa_id(PDO $pdo, string $kode): int
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

if (!function_exists('sp_buat_jurnal')) {
    function sp_buat_jurnal(
        PDO $pdo,
        string $tanggal,
        string $keterangan,
        string $refTabel,
        int $refId,
        ?int $userId = null
    ): int {
        $kodeJurnal = 'JR-SP-' . date('YmdHis') . '-' . random_int(100, 999);

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

if (!function_exists('sp_tambah_jurnal_detail')) {
    function sp_tambah_jurnal_detail(
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

if (!function_exists('sp_auto_jurnal_pinjaman_cair')) {
    function sp_auto_jurnal_pinjaman_cair(
        PDO $pdo,
        int $pinjamanId,
        float $pokok,
        ?int $userId = null
    ): void {
        if ($pinjamanId < 1 || $pokok <= 0) {
            return;
        }

        try {
            $jurnalId = sp_buat_jurnal(
                $pdo,
                date('Y-m-d'),
                'Pencairan Pinjaman #' . $pinjamanId,
                'pinjaman',
                $pinjamanId,
                $userId
            );

            // Debit: Piutang Pinjaman
            sp_tambah_jurnal_detail(
                $pdo,
                $jurnalId,
                sp_coa_id($pdo, '103'),
                $pokok,
                0
            );

            // Kredit: Kas
            sp_tambah_jurnal_detail(
                $pdo,
                $jurnalId,
                sp_coa_id($pdo, '101'),
                0,
                $pokok
            );
        } catch (Throwable $e) {
            // Auto jurnal tidak boleh menggagalkan proses pencairan.
            error_log('AUTO JURNAL PINJAMAN CAIR ERROR: ' . $e->getMessage());
        }
    }
}


if (!function_exists('hitung_angsuran_sp')) {
    /**
     * Skema bunga:
     * bunga_persen diambil dari konfigurasi_sp sebagai total persen bunga dari pokok.
     * Contoh: pokok 5.000.000, bunga 10%, tenor 10 bulan
     * total bunga = 500.000, bunga/bulan = 50.000.
     *
     * @return array{pokok:int,total_bunga:int,bunga_bulan:int,total_bulan:int,total_pinjaman:int}
     */
    function hitung_angsuran_sp(float $pokok, int $tenor, float $bungaPersen): array
    {
        $pokok = (float)$pokok;
        $tenor = max(1, (int)$tenor);
        $bungaPersen = (float)$bungaPersen;

        $angsuranPokok = (int)round($pokok / $tenor);
        $totalBunga = (int)round($pokok * $bungaPersen / 100);
        $angsuranBunga = (int)round($totalBunga / $tenor);
        $angsuranTotal = $angsuranPokok + $angsuranBunga;
        $totalPinjaman = $angsuranTotal * $tenor;

        return [
            'pokok' => $angsuranPokok,
            'total_bunga' => $totalBunga,
            'bunga_bulan' => $angsuranBunga,
            'total_bulan' => $angsuranTotal,
            'total_pinjaman' => $totalPinjaman,
        ];
    }
}

if (!function_exists('format_no_wa_sp')) {
    function format_no_wa_sp(string $noHp): string
    {
        $no = preg_replace('/\D+/', '', (string)$noHp);
        if ($no === '') return '';
        if (strpos($no, '0') === 0) {
            $no = '62' . substr($no, 1);
        } elseif (strpos($no, '62') !== 0) {
            $no = '62' . $no;
        }
        return $no;
    }
}

if (!function_exists('buat_link_wa_sp')) {
    function buat_link_wa_sp(string $noHp, string $pesan): string
    {
        $no = format_no_wa_sp($noHp);
        if ($no === '') return '';
        return 'https://wa.me/' . $no . '?text=' . rawurlencode($pesan);
    }
}

if (!function_exists('kirim_notif_wa_sp')) {
    /**
     * Placeholder aman untuk integrasi WhatsApp gateway.
     * Jika nanti sudah punya endpoint gateway, isi kode CURL di sini.
     * Saat ini fungsi mengembalikan link WA manual agar tidak membuat error.
     */
    function kirim_notif_wa_sp(string $noHp, string $pesan): string
    {
        return buat_link_wa_sp($noHp, $pesan);
    }
}


if (!function_exists('progress_pinjaman_sp')) {
    function progress_pinjaman_sp(int $lunas, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return max(0, min(100, (int)round(($lunas / $total) * 100)));
    }
}

if (!function_exists('status_pinjaman_label_sp')) {
    function status_pinjaman_label_sp(string $status): string
    {
        $status = strtolower(trim($status));

        if ($status === '') {
            return 'Batal Pengajuan';
        }

        $map = [
            'pending' => 'Pending',
            'diseleksi' => 'Diseleksi',
            'disetujui' => 'Disetujui',
            'ditolak' => 'Ditolak',
            'dibatalkan' => 'Batal Pengajuan',
            'dicairkan' => 'Dicairkan',
        ];

        return $map[$status] ?? ucfirst($status);
    }
}


// ── Import Pinjaman dari Excel Perhitungan Tahunan ───────────────────────────
if (!function_exists('sp_parse_nominal')) {
    /**
     * @param mixed $v
     */
    function sp_parse_nominal($v): float
    {
        if (is_numeric($v)) {
            return (float)$v;
        }

        $s = trim((string)($v ?? ''));
        if ($s === '') {
            return 0;
        }

        $s = str_ireplace(['rp', 'idr', ' '], '', $s);
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]/', '', $s) ?: '';

        return $s === '' ? 0 : (float)$s;
    }
}

if (!function_exists('sp_normal_key')) {
    /**
     * @param mixed $v
     */
    function sp_normal_key($v): string
    {
        $v = strtolower(trim((string)($v ?? '')));
        $v = str_replace(["\r", "\n", "\t"], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v) ?: '';
        $v = str_replace([' ', '-', '.', '/', '\\', '(', ')'], '_', $v);
        $v = preg_replace('/_+/', '_', $v) ?: '';
        return trim($v, '_ ');
    }
}

if (!function_exists('sp_compact_text')) {
    /**
     * @param mixed $v
     */
    function sp_compact_text($v): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)($v ?? '')) ?: '');
    }
}

if (!function_exists('sp_xlsx_col_index')) {
    function sp_xlsx_col_index(string $cellRef): int
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

if (!function_exists('sp_read_xlsx_sheets')) {
    /**
     * Parser XLSX ringan tanpa PhpSpreadsheet.
     *
     * @return array<int,array{name:string,rows:array<int,array<int,string>>}>
     */
    function sp_read_xlsx_sheets(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('Extension ZipArchive belum aktif. Aktifkan extension zip PHP atau upload CSV.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('File Excel tidak bisa dibuka.');
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

        $relMap = [];
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml !== false) {
            $rels = @simplexml_load_string($relsXml);
            if ($rels) {
                foreach ($rels->Relationship as $rel) {
                    $id = (string)$rel['Id'];
                    $target = (string)$rel['Target'];
                    if ($id !== '' && $target !== '') {
                        $relMap[$id] = 'xl/' . ltrim($target, '/');
                    }
                }
            }
        }

        $sheetFiles = [];
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml !== false) {
            $wb = @simplexml_load_string($workbookXml);
            if ($wb && isset($wb->sheets->sheet)) {
                $namespaces = $wb->getNamespaces(true);
                foreach ($wb->sheets->sheet as $sheet) {
                    $attrs = $sheet->attributes();
                    $rattrs = isset($namespaces['r']) ? $sheet->attributes($namespaces['r']) : null;
                    $name = (string)($attrs['name'] ?? '');
                    $rid = $rattrs ? (string)($rattrs['id'] ?? '') : '';
                    $file = $relMap[$rid] ?? '';
                    if ($name !== '' && $file !== '') {
                        $sheetFiles[] = ['name' => $name, 'file' => $file];
                    }
                }
            }
        }

        if (!$sheetFiles) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
                    $sheetFiles[] = ['name' => 'Sheet ' . $m[1], 'file' => $name];
                }
            }
        }

        $result = [];
        foreach ($sheetFiles as $sheetInfo) {
            $sheetXml = $zip->getFromName($sheetInfo['file']);
            if ($sheetXml === false) {
                continue;
            }

            $xml = @simplexml_load_string($sheetXml);
            if (!$xml || !isset($xml->sheetData->row)) {
                continue;
            }

            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $line = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $idx = sp_xlsx_col_index($ref);
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

            $result[] = ['name' => (string)$sheetInfo['name'], 'rows' => $rows];
        }

        $zip->close();
        return $result;
    }
}

if (!function_exists('sp_find_header_col')) {
    /**
     * @param array<int,string> $header
     * @param array<int,string> $needles
     */
    function sp_find_header_col(array $header, array $needles): int
    {
        foreach ($header as $i => $h) {
            $n = sp_normal_key($h);
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($n, $needle) !== false) {
                    return (int)$i;
                }
            }
        }
        return -1;
    }
}

if (!function_exists('sp_import_year_from_name')) {
    function sp_import_year_from_name(string $name): int
    {
        if (preg_match('/(20\d{2})/', $name, $m)) {
            return (int)$m[1];
        }
        return (int)date('Y');
    }
}

if (!function_exists('sp_sheet_is_pinjaman')) {
    function sp_sheet_is_pinjaman(string $sheetName): bool
    {
        $n = strtoupper(trim($sheetName));
        if ($n === '') {
            return false;
        }

        return strpos($n, 'PINJAM') !== false || strpos($n, 'BARANG') !== false || strpos($n, 'UANG') !== false;
    }
}

if (!function_exists('sp_guess_jenis_from_sheet')) {
    function sp_guess_jenis_from_sheet(string $sheetName): string
    {
        $n = strtoupper($sheetName);
        if (strpos($n, 'BARANG') !== false) {
            return 'barang';
        }
        return 'uang';
    }
}

if (!function_exists('sp_member_score')) {
    function sp_member_score(string $dbName, string $importName): int
    {
        $db = sp_compact_text($dbName);
        $imp = sp_compact_text($importName);

        if ($db === '' || $imp === '') {
            return 0;
        }

        if ($db === $imp) {
            return 100;
        }

        if (strpos($db, $imp) !== false || strpos($imp, $db) !== false) {
            return 92;
        }

        similar_text($db, $imp, $pct);
        return (int)round($pct);
    }
}

if (!function_exists('sp_find_member_for_import')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    function sp_find_member_for_import(PDO $pdo, array $row): ?array
    {
        $kode = preg_replace('/[^A-Za-z0-9]+/', '', (string)($row['kode'] ?? '')) ?: '';
        $nama = trim((string)($row['nama'] ?? ''));

        if ($kode !== '') {
            $stmt = $pdo->prepare("
                SELECT id, nama, kode, no_hp
                FROM member
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(kode,''),'.',''),'-',''),' ',''),'/','') = :kode_a
                   OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp,''),'.',''),'-',''),' ',''),'/','') = :kode_b
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([':kode_a' => $kode, ':kode_b' => $kode]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($member) {
                return $member;
            }
        }

        if ($nama === '') {
            return null;
        }

        $cleanName = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $nama) ?: '');
        $parts = preg_split('/\s+/', trim($cleanName)) ?: [];
        $parts = array_values(array_filter($parts, static function ($part): bool {
            return strlen($part) >= 3 && !in_array($part, ['dan', 'bin', 'binti', 'sdr', 'sdri'], true);
        }));

        if (!$parts) {
            return null;
        }

        $sql = "SELECT id, nama, kode, no_hp FROM member WHERE 1=1";
        $params = [];
        foreach (array_slice($parts, 0, 2) as $idx => $part) {
            $key = ':nama_pinjam_' . $idx;
            $sql .= " AND LOWER(nama) LIKE " . $key;
            $params[$key] = '%' . $part . '%';
        }
        $sql .= " ORDER BY id ASC LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($members, static function ($a, $b) use ($nama): int {
            return sp_member_score((string)($b['nama'] ?? ''), $nama) <=> sp_member_score((string)($a['nama'] ?? ''), $nama);
        });

        $best = $members[0] ?? null;
        if ($best && sp_member_score((string)($best['nama'] ?? ''), $nama) >= 50) {
            return $best;
        }

        return null;
    }
}

if (!function_exists('sp_excel_month_year_to_date')) {
    function sp_excel_month_year_to_date(string $text, int $fallbackYear): string
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
            'juni' => 6,
            'jun' => 6,
            'juli' => 7,
            'jul' => 7,
            'agustus' => 8,
            'agt' => 8,
            'agst' => 8,
            'agu' => 8,
            'aug' => 8,
            'september' => 9,
            'sept' => 9,
            'sep' => 9,
            'oktober' => 10,
            'okt' => 10,
            'november' => 11,
            'nopember' => 11,
            'nov' => 11,
            'nop' => 11,
            'desember' => 12,
            'december' => 12,
            'des' => 12,
            'dec' => 12,
        ];

        if (preg_match('/([a-z]+)\s+(20\d{2})/i', $text, $m)) {
            $bulan = $map[strtolower($m[1])] ?? 0;
            $tahun = (int)$m[2];
            if ($bulan > 0) {
                return sprintf('%04d-%02d-01', $tahun, $bulan);
            }
        }

        if (preg_match('/([a-z]+)/i', $text, $m)) {
            $bulan = $map[strtolower($m[1])] ?? 0;
            if ($bulan > 0) {
                return sprintf('%04d-%02d-01', $fallbackYear, $bulan);
            }
        }

        return sprintf('%04d-01-01', $fallbackYear);
    }
}

if (!function_exists('sp_normalize_import_pinjaman_rows')) {
    /**
     * Import pinjaman dari workbook tahunan.
     *
     * Catatan penting untuk file "Perhitungan 31 Desember 2025":
     * - Sheet 05_Simpan Pinjam / 06_Pinjaman Barang adalah rekap bulanan, tidak punya kolom tenor eksplisit.
     * - Sheet detail "PINJAMAN UANG" dan "PINJAMAN BARANG" punya blok per member.
     * - Tenor diambil dari nomor cicilan terbesar pada blok detail, contoh No. Cicilan 1 s.d. 10 => tenor 10.
     * - Pokok diambil dari saldo awal sebelum cicilan pertama.
     * - Angsuran Excel diambil dari Pokok Bayar + Adm pertama yang terbaca, supaya jadwal sama dengan file.
     *
     * @return array<int,array<string,mixed>>
     */
    function sp_normalize_import_pinjaman_rows(string $path, string $fileName): array
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new Exception('Untuk import pinjaman dari workbook tahunan gunakan file .xlsx.');
        }

        $sheets = sp_read_xlsx_sheets($path);
        $tahun = sp_import_year_from_name($fileName);
        $rowsOut = [];
        $detailFound = false;

        $addBlock = static function (array $block) use (&$rowsOut, $tahun): void {
            $nama = trim((string)($block['nama'] ?? ''));
            if ($nama === '') {
                return;
            }

            $pokok = (float)($block['pokok'] ?? 0);
            $tenor = (int)($block['tenor'] ?? 0);
            $angsuranPokok = (float)($block['angsuran_pokok'] ?? 0);
            $angsuranAdm = (float)($block['angsuran_adm'] ?? 0);
            $angsuranTotal = $angsuranPokok + $angsuranAdm;

            if ($pokok <= 0 && $angsuranPokok > 0 && $tenor > 0) {
                $pokok = $angsuranPokok * $tenor;
            }

            if ($tenor <= 0 || $pokok <= 0) {
                return;
            }

            $tanggalMulai = (string)($block['tanggal_mulai'] ?? '');
            if ($tanggalMulai === '') {
                $tanggalMulai = sprintf('%04d-01-01', $tahun);
            }

            $rowsOut[] = [
                'row_no' => (int)($block['row_no'] ?? 0),
                'sheet_name' => (string)($block['sheet_name'] ?? ''),
                'tahun' => (int)date('Y', strtotime($tanggalMulai)),
                'tanggal_mulai_excel' => $tanggalMulai,
                'nama' => $nama,
                'kode' => '',
                'jenis' => (string)($block['jenis'] ?? 'uang'),
                'nama_barang' => (string)($block['nama_barang'] ?? ''),
                'jumlah' => $pokok,
                'tenor' => $tenor,
                'angsuran_total_excel' => $angsuranTotal,
                'angsuran_pokok_excel' => $angsuranPokok,
                'bunga_excel' => $angsuranAdm,
                'status_import' => 'pending',
                'status_label' => 'Belum dicek',
                'member_id' => 0,
                'member_nama' => '',
                'member_kode' => '',
            ];
        };

        // Utama: baca sheet detail, karena di sinilah tenor benar-benar terlihat.
        foreach ($sheets as $sheet) {
            $sheetName = trim((string)$sheet['name']);
            $upper = strtoupper($sheetName);
            if (!in_array($upper, ['PINJAMAN UANG', 'PINJAMAN BARANG'], true)) {
                continue;
            }

            $detailFound = true;
            $jenis = strpos($upper, 'BARANG') !== false ? 'barang' : 'uang';
            $rows = $sheet['rows'];
            $block = [];
            $inSchedule = false;
            $lastKe = 0;

            foreach ($rows as $idx => $row) {
                $c0 = trim((string)($row[0] ?? ''));
                $c1 = trim((string)($row[1] ?? ''));
                $c2 = trim((string)($row[2] ?? ''));
                $c3 = trim((string)($row[3] ?? ''));
                $c4 = trim((string)($row[4] ?? ''));
                $c5 = trim((string)($row[5] ?? ''));
                $key0 = sp_normal_key($c0);

                if (strpos($key0, 'no_urut') !== false) {
                    $addBlock($block);
                    $block = [
                        'row_no' => $idx + 1,
                        'sheet_name' => $sheetName,
                        'jenis' => $jenis,
                        'nama' => '',
                        'nama_barang' => '',
                        'pokok' => 0,
                        'tenor' => 0,
                        'angsuran_pokok' => 0,
                        'angsuran_adm' => 0,
                        'tanggal_mulai' => '',
                    ];
                    $inSchedule = false;
                    $lastKe = 0;
                    continue;
                }

                if (!$block) {
                    continue;
                }

                if (strpos($key0, 'nama') !== false && strpos($key0, 'barang') === false && $c2 !== '') {
                    $block['nama'] = $c2;
                    continue;
                }

                if (strpos($key0, 'nama_barang') !== false && $c2 !== '') {
                    $block['nama_barang'] = $c2;
                    continue;
                }

                if (strpos($key0, 'no_cicilan') !== false || strpos($key0, 'no_cicil') !== false) {
                    $inSchedule = true;
                    continue;
                }

                if (!$inSchedule) {
                    continue;
                }

                $isNewBlockText = strpos($key0, 'no_urut') !== false || (strpos($key0, 'nama') !== false && $c2 !== '');
                if ($isNewBlockText) {
                    continue;
                }

                $ke = is_numeric($c0) ? (int)$c0 : 0;
                $bulanText = $c1;
                $saldo = sp_parse_nominal($c3);
                $pokokBayar = sp_parse_nominal($c4);
                $admBayar = sp_parse_nominal($c5);

                // Baris saldo awal sebelum cicilan pertama: kolom A kosong, bulan ada, saldo/pokok awal di kolom D.
                if ($ke <= 0 && $lastKe === 0 && $saldo > 0) {
                    $block['pokok'] = max((float)$block['pokok'], $saldo);
                    if (!empty($bulanText)) {
                        $block['tanggal_mulai'] = sp_excel_month_year_to_date($bulanText, $tahun);
                    }
                    continue;
                }

                if ($ke > 0) {
                    $lastKe = $ke;
                    $block['tenor'] = max((int)$block['tenor'], $ke);

                    if ($saldo > 0 && (float)$block['pokok'] <= 0) {
                        $block['pokok'] = $saldo;
                    }

                    if ($pokokBayar > 0 && (float)$block['angsuran_pokok'] <= 0) {
                        $block['angsuran_pokok'] = $pokokBayar;
                    }
                    if ($admBayar > 0 && (float)$block['angsuran_adm'] <= 0) {
                        $block['angsuran_adm'] = $admBayar;
                    }

                    if (!empty($bulanText) && empty($block['tanggal_mulai'])) {
                        // Tanggal mulai pinjaman dibuat 1 bulan sebelum cicilan pertama.
                        $firstPay = sp_excel_month_year_to_date($bulanText, $tahun);
                        $block['tanggal_mulai'] = date('Y-m-d', strtotime('-1 month', strtotime($firstPay)));
                    }
                    continue;
                }

                // Beberapa baris Excel memecah cicilan ke dua baris: nomor cicilan di baris pertama,
                // nilai Pokok Bayar/Adm ada di baris berikutnya tanpa nomor.
                if ($ke <= 0 && $lastKe > 0 && ($pokokBayar > 0 || $admBayar > 0)) {
                    if ($pokokBayar > 0 && (float)$block['angsuran_pokok'] <= 0) {
                        $block['angsuran_pokok'] = $pokokBayar;
                    }
                    if ($admBayar > 0 && (float)$block['angsuran_adm'] <= 0) {
                        $block['angsuran_adm'] = $admBayar;
                    }
                }
            }

            $addBlock($block);
        }

        if ($detailFound && $rowsOut) {
            return $rowsOut;
        }

        // Fallback lama: untuk workbook lain yang punya tabel biasa dengan kolom Tenor.
        foreach ($sheets as $sheet) {
            $sheetName = (string)$sheet['name'];
            if (!sp_sheet_is_pinjaman($sheetName)) {
                continue;
            }

            $rows = $sheet['rows'];
            if (!$rows) {
                continue;
            }

            $jenisFromSheet = sp_guess_jenis_from_sheet($sheetName);
            $headerIndex = -1;
            $header = [];

            for ($r = 0; $r < min(30, count($rows)); $r++) {
                $candidate = $rows[$r] ?? [];
                $joined = sp_normal_key(implode(' ', array_map('strval', $candidate)));
                if (
                    (strpos($joined, 'nama') !== false || strpos($joined, 'anggota') !== false || strpos($joined, 'pegawai') !== false)
                    && (strpos($joined, 'tenor') !== false || strpos($joined, 'jangka') !== false)
                ) {
                    $headerIndex = $r;
                    $header = $candidate;
                    break;
                }
            }

            if ($headerIndex < 0) {
                continue;
            }

            $colNama = sp_find_header_col($header, ['nama_anggota', 'nama_pegawai', 'nama_member', 'nama']);
            $colKode = sp_find_header_col($header, ['nip', 'nik', 'kode', 'no_anggota', 'id_anggota']);
            $colJenis = sp_find_header_col($header, ['jenis']);
            $colBarang = sp_find_header_col($header, ['nama_barang', 'barang']);
            $colJumlah = sp_find_header_col($header, ['jumlah_pinjaman', 'pokok', 'pinjaman', 'harga_barang', 'harga', 'jumlah']);
            $colTenor = sp_find_header_col($header, ['tenor', 'jangka_waktu']);
            $colAngsuran = sp_find_header_col($header, ['angsuran_total', 'angsuran_per_bulan', 'cicilan', 'iuran', 'potongan']);
            $colBunga = sp_find_header_col($header, ['bunga']);

            if ($colNama < 0 || $colJumlah < 0 || $colTenor < 0) {
                continue;
            }

            for ($i = $headerIndex + 1; $i < count($rows); $i++) {
                $row = $rows[$i] ?? [];
                $nama = trim((string)($row[$colNama] ?? ''));
                $kode = $colKode >= 0 ? trim((string)($row[$colKode] ?? '')) : '';
                $jenis = $colJenis >= 0 ? strtolower(trim((string)($row[$colJenis] ?? ''))) : $jenisFromSheet;
                $namaBarang = $colBarang >= 0 ? trim((string)($row[$colBarang] ?? '')) : '';
                $jumlah = $colJumlah >= 0 ? sp_parse_nominal($row[$colJumlah] ?? 0) : 0;
                $tenor = $colTenor >= 0 ? (int)sp_parse_nominal($row[$colTenor] ?? 0) : 0;
                $angsuran = $colAngsuran >= 0 ? sp_parse_nominal($row[$colAngsuran] ?? 0) : 0;
                $bunga = $colBunga >= 0 ? sp_parse_nominal($row[$colBunga] ?? 0) : 0;

                $namaLower = strtolower($nama);
                if ($nama === '' || is_numeric($nama) || strpos($namaLower, 'jumlah') !== false || strpos($namaLower, 'total') !== false || strpos($namaLower, 'nama') !== false) {
                    continue;
                }

                if (!in_array($jenis, ['uang', 'barang'], true)) {
                    $jenis = $jenisFromSheet;
                }

                if ($jumlah <= 0 || $tenor <= 0) {
                    continue;
                }

                $rowsOut[] = [
                    'row_no' => $i + 1,
                    'sheet_name' => $sheetName,
                    'tahun' => $tahun,
                    'nama' => $nama,
                    'kode' => $kode,
                    'jenis' => $jenis,
                    'nama_barang' => $jenis === 'barang' ? $namaBarang : '',
                    'jumlah' => $jumlah,
                    'tenor' => $tenor,
                    'angsuran_total_excel' => $angsuran,
                    'bunga_excel' => $bunga,
                    'status_import' => 'pending',
                    'status_label' => 'Belum dicek',
                    'member_id' => 0,
                    'member_nama' => '',
                    'member_kode' => '',
                ];
            }
        }

        return $rowsOut;
    }
}

if (!function_exists('sp_preview_pinjaman_import')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    function sp_preview_pinjaman_import(PDO $pdo, array $rows): array
    {
        $summary = ['valid' => 0, 'duplikat' => 0, 'gagal' => 0];
        $out = [];

        foreach ($rows as $row) {
            $member = sp_find_member_for_import($pdo, $row);

            if (!$member) {
                // Jangan langsung gagal. Untuk import awal dari Excel tahunan,
                // beberapa peminjam bisa belum ada di tabel member. Baris tetap dibuat valid,
                // lalu member dibuat otomatis saat proses import.
                $row['status_import'] = 'valid_create_member';
                $row['status_label'] = 'Member belum ada, akan dibuat otomatis';
                $row['member_id'] = 0;
                $row['member_nama'] = (string)($row['nama'] ?? '-');
                $row['member_kode'] = (string)($row['kode'] ?? '');
                $summary['valid']++;
                $out[] = $row;
                continue;
            }

            $row['member_id'] = (int)$member['id'];
            $row['member_nama'] = (string)$member['nama'];
            $row['member_kode'] = (string)$member['kode'];

            $stmt = $pdo->prepare("
                SELECT id
                FROM pinjaman
                WHERE member_id = :mid
                  AND jenis = :jenis
                  AND ABS(COALESCE(pokok,0) - :pokok) <= 1
                  AND tenor = :tenor
                  AND status IN ('aktif','berjalan','lunas')
                LIMIT 1
            ");
            $stmt->execute([
                ':mid' => (int)$row['member_id'],
                ':jenis' => (string)$row['jenis'],
                ':pokok' => (float)$row['jumlah'],
                ':tenor' => (int)$row['tenor'],
            ]);
            $existingId = (int)$stmt->fetchColumn();

            if ($existingId > 0) {
                $row['status_import'] = 'duplikat';
                $row['status_label'] = 'Sudah ada pinjaman #' . $existingId;
                $row['pinjaman_existing_id'] = $existingId;
                $summary['duplikat']++;
            } else {
                $row['status_import'] = 'valid';
                $row['status_label'] = 'Siap import';
                $summary['valid']++;
            }

            $out[] = $row;
        }

        return ['rows' => $out, 'summary' => $summary];
    }
}


if (!function_exists('sp_create_member_from_import')) {
    /**
     * Membuat member minimal dari data Excel pinjaman jika belum ada di database.
     * Kolom yang dipakai hanya kolom umum: kode, nama, no_hp, status, created_at
     * dan akan otomatis menyesuaikan kalau sebagian kolom tidak ada.
     *
     * @param array<string,mixed> $row
     */
    function sp_create_member_from_import(PDO $pdo, array $row): int
    {
        $nama = trim((string)($row['nama'] ?? $row['member_nama'] ?? ''));
        $kode = trim((string)($row['kode'] ?? $row['member_kode'] ?? ''));

        if ($nama === '') {
            throw new Exception('Nama member kosong, tidak bisa membuat member otomatis.');
        }

        // Cek ulang berdasarkan nama compact agar tidak membuat duplikat.
        $existing = sp_find_member_for_import($pdo, [
            'nama' => $nama,
            'kode' => $kode,
        ]);
        if ($existing && (int)($existing['id'] ?? 0) > 0) {
            return (int)$existing['id'];
        }

        $cols = [];
        try {
            $stmtCols = $pdo->query("SHOW COLUMNS FROM member");
            foreach ($stmtCols->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $field = (string)($col['Field'] ?? '');
                if ($field !== '') {
                    $cols[$field] = true;
                }
            }
        } catch (Throwable $e) {
            throw new Exception('Tidak bisa membaca struktur tabel member: ' . $e->getMessage());
        }

        $data = [];
        if (isset($cols['kode'])) {
            $data['kode'] = $kode !== '' ? $kode : 'IMP-' . date('YmdHis') . '-' . random_int(100, 999);
        }
        if (isset($cols['nama'])) {
            $data['nama'] = $nama;
        }
        if (isset($cols['no_hp'])) {
            $data['no_hp'] = '';
        }
        if (isset($cols['status'])) {
            $data['status'] = 'aktif';
        }
        if (isset($cols['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if (!isset($data['nama'])) {
            throw new Exception('Kolom nama tidak ditemukan di tabel member.');
        }

        $fields = array_keys($data);
        $sqlFields = array_map(static function ($field): string {
            return '`' . str_replace('`', '', $field) . '`';
        }, $fields);
        $placeholders = [];
        $params = [];
        foreach ($data as $field => $value) {
            $key = ':member_' . $field;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        $stmt = $pdo->prepare('INSERT INTO member (' . implode(',', $sqlFields) . ') VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('sp_table_cols_import_pinjaman')) {
    /** @return array<string,bool> */
    function sp_table_cols_import_pinjaman(PDO $pdo, string $table): array
    {
        $cols = [];
        $safeTable = str_replace('`', '', $table);
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . $safeTable . "`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $field = (string)($col['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
        return $cols;
    }
}

if (!function_exists('sp_create_pengajuan_from_import')) {
    /**
     * Tabel pinjaman di database mewajibkan pengajuan_id.
     * Untuk import Excel historis, buat record pengajuan_pinjaman otomatis
     * dengan status dicairkan, lalu ID-nya dipakai saat insert pinjaman.
     *
     * @param array<string,mixed> $row
     */
    function sp_create_pengajuan_from_import(PDO $pdo, array $row, int $userId): int
    {
        $memberId = (int)($row['member_id'] ?? 0);
        $jenis = in_array((string)($row['jenis'] ?? ''), ['uang', 'barang'], true) ? (string)$row['jenis'] : 'uang';
        $jumlah = (float)($row['jumlah'] ?? 0);
        $tenor = max(1, (int)($row['tenor'] ?? 1));
        $namaBarang = trim((string)($row['nama_barang'] ?? ''));
        $catatan = 'Import Excel pinjaman ' . strtoupper($jenis);

        if ($memberId <= 0 || $jumlah <= 0) {
            throw new Exception('Data pengajuan import tidak lengkap.');
        }

        $cols = sp_table_cols_import_pinjaman($pdo, 'pengajuan_pinjaman');
        $now = date('Y-m-d H:i:s');
        $data = [];

        if (isset($cols['member_id'])) $data['member_id'] = $memberId;
        if (isset($cols['jenis'])) $data['jenis'] = $jenis;
        if (isset($cols['jumlah'])) $data['jumlah'] = $jumlah;
        if (isset($cols['tenor'])) $data['tenor'] = $tenor;
        if (isset($cols['keperluan'])) $data['keperluan'] = $catatan;
        if (isset($cols['nama_barang'])) $data['nama_barang'] = $jenis === 'barang' ? ($namaBarang !== '' ? $namaBarang : 'Barang import') : null;
        if (isset($cols['harga_barang'])) $data['harga_barang'] = $jenis === 'barang' ? $jumlah : null;
        if (isset($cols['status'])) $data['status'] = 'dicairkan';
        if (isset($cols['catatan_petugas'])) $data['catatan_petugas'] = $catatan;
        if (isset($cols['diseleksi_oleh'])) $data['diseleksi_oleh'] = $userId ?: null;
        if (isset($cols['disetujui_oleh'])) $data['disetujui_oleh'] = $userId ?: null;
        if (isset($cols['dicairkan_oleh'])) $data['dicairkan_oleh'] = $userId ?: null;
        if (isset($cols['diseleksi_at'])) $data['diseleksi_at'] = $now;
        if (isset($cols['disetujui_at'])) $data['disetujui_at'] = $now;
        if (isset($cols['dicairkan_at'])) $data['dicairkan_at'] = $now;
        if (isset($cols['created_at'])) $data['created_at'] = $now;
        if (isset($cols['updated_at'])) $data['updated_at'] = $now;

        foreach (['member_id', 'jenis', 'jumlah', 'tenor'] as $required) {
            if (isset($cols[$required]) && !array_key_exists($required, $data)) {
                throw new Exception('Kolom wajib pengajuan_pinjaman.' . $required . ' tidak terisi.');
            }
        }

        $fields = array_keys($data);
        if (!$fields) {
            throw new Exception('Struktur tabel pengajuan_pinjaman tidak terbaca.');
        }

        $sqlFields = array_map(static function ($field): string {
            return '`' . str_replace('`', '', $field) . '`';
        }, $fields);

        $placeholders = [];
        $params = [];
        foreach ($data as $field => $value) {
            $key = ':pengajuan_' . $field;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        $stmt = $pdo->prepare('INSERT INTO pengajuan_pinjaman (' . implode(',', $sqlFields) . ') VALUES (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('sp_create_pinjaman_from_import')) {
    /**
     * @param array<string,mixed> $row
     */
    function sp_create_pinjaman_from_import(PDO $pdo, array $row, array $konfig, int $userId): int
    {
        $memberId = (int)($row['member_id'] ?? 0);
        $jenis = in_array((string)($row['jenis'] ?? ''), ['uang', 'barang'], true) ? (string)$row['jenis'] : 'uang';
        $pokok = (float)($row['jumlah'] ?? 0);
        $tenor = max(1, (int)($row['tenor'] ?? 1));
        $tahun = (int)($row['tahun'] ?? date('Y'));

        if ($memberId <= 0 || $pokok <= 0) {
            throw new Exception('Data pinjaman import tidak lengkap.');
        }

        $bungaPct = $jenis === 'barang'
            ? (float)($konfig['bunga_barang'] ?? 1.5)
            : (float)($konfig['bunga_uang'] ?? 1.0);

        $hitung = hitung_angsuran_sp($pokok, $tenor, $bungaPct);
        $angPok = $hitung['pokok'];
        $angBng = $hitung['bunga_bulan'];
        $angTot = $hitung['total_bulan'];

        // Jika file Excel punya nilai cicilan bulanan, gunakan sebagai jumlah_total agar sama dengan file.
        $angsuranExcel = (float)($row['angsuran_total_excel'] ?? 0);
        if ($angsuranExcel > 0) {
            $angTot = (int)round($angsuranExcel);
            $angPok = (int)round($pokok / $tenor);
            $angBng = max(0, $angTot - $angPok);
        }

        $mulai = trim((string)($row['tanggal_mulai_excel'] ?? ''));
        if ($mulai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
            $mulai = sprintf('%04d-01-01', $tahun);
        }
        $selesai = date('Y-m-d', strtotime('+' . $tenor . ' months', strtotime($mulai)));

        $pengajuanId = sp_create_pengajuan_from_import($pdo, $row, $userId);

        $pdo->prepare("
            INSERT INTO pinjaman
            (
                pengajuan_id,
                member_id,
                jenis,
                pokok,
                bunga_persen,
                tenor,
                angsuran_pokok,
                angsuran_bunga,
                angsuran_total,
                tanggal_mulai,
                tanggal_selesai,
                status
            )
            VALUES
            (
                :pengajuan_id,
                :member_id,
                :jenis,
                :pokok,
                :bunga,
                :tenor,
                :angsuran_pokok,
                :angsuran_bunga,
                :angsuran_total,
                :tanggal_mulai,
                :tanggal_selesai,
                'aktif'
            )
        ")->execute([
            ':pengajuan_id' => $pengajuanId,
            ':member_id' => $memberId,
            ':jenis' => $jenis,
            ':pokok' => $pokok,
            ':bunga' => $bungaPct,
            ':tenor' => $tenor,
            ':angsuran_pokok' => $angPok,
            ':angsuran_bunga' => $angBng,
            ':angsuran_total' => $angTot,
            ':tanggal_mulai' => $mulai,
            ':tanggal_selesai' => $selesai,
        ]);

        $pinjamanId = (int)$pdo->lastInsertId();

        $insAng = $pdo->prepare("
            INSERT INTO angsuran_pinjaman
            (
                pinjaman_id,
                member_id,
                ke,
                bulan,
                tahun,
                jumlah_pokok,
                jumlah_bunga,
                jumlah_total,
                jatuh_tempo,
                status
            )
            VALUES
            (
                :pinjaman_id,
                :member_id,
                :ke,
                :bulan,
                :tahun,
                :jumlah_pokok,
                :jumlah_bunga,
                :jumlah_total,
                :jatuh_tempo,
                'belum_bayar'
            )
        ");

        for ($i = 1; $i <= $tenor; $i++) {
            $jatuh = date('Y-m-d', strtotime('+' . $i . ' months', strtotime($mulai)));
            $insAng->execute([
                ':pinjaman_id' => $pinjamanId,
                ':member_id' => $memberId,
                ':ke' => $i,
                ':bulan' => (int)date('n', strtotime($jatuh)),
                ':tahun' => (int)date('Y', strtotime($jatuh)),
                ':jumlah_pokok' => $angPok,
                ':jumlah_bunga' => $angBng,
                ':jumlah_total' => $angTot,
                ':jatuh_tempo' => $jatuh,
            ]);
        }

        sp_auto_jurnal_pinjaman_cair($pdo, $pinjamanId, $pokok, $userId);

        return $pinjamanId;
    }
}



if (!function_exists('sp_ensure_konfirmasi_member_schema')) {
    function sp_ensure_konfirmasi_member_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS konfirmasi_pinjaman_member (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                pengajuan_id INT NOT NULL,\n                member_id INT NOT NULL,\n                keputusan VARCHAR(20) NOT NULL DEFAULT 'pending',\n                nama_ktp VARCHAR(180) NULL,\n                alamat_ktp TEXT NULL,\n                no_hp VARCHAR(40) NULL,\n                nama_bank VARCHAR(100) NULL,\n                no_rekening VARCHAR(100) NULL,\n                nama_keluarga VARCHAR(180) NULL,\n                alamat_keluarga TEXT NULL,\n                no_hp_keluarga VARCHAR(40) NULL,\n                alamat_keluarga_sama TINYINT(1) NOT NULL DEFAULT 0,\n                dikonfirmasi_at DATETIME NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                UNIQUE KEY uq_konfirmasi_pinjaman_pengajuan (pengajuan_id),\n                INDEX idx_konfirmasi_pinjaman_member (member_id),\n                INDEX idx_konfirmasi_pinjaman_keputusan (keputusan)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");
    }
}

try {
    sp_ensure_konfirmasi_member_schema($pdo);
} catch (Throwable $e) {
    error_log('KONFIRMASI PINJAMAN MEMBER SCHEMA ERROR: ' . $e->getMessage());
}


if (!function_exists('sp_ensure_jumlah_disetujui_schema')) {
    function sp_ensure_jumlah_disetujui_schema(PDO $pdo): void
    {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM pengajuan_pinjaman")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $field = (string)($col['Field'] ?? '');
            if ($field !== '') $cols[$field] = true;
        }
        if (!isset($cols['jumlah_disetujui'])) {
            $pdo->exec("ALTER TABLE pengajuan_pinjaman ADD COLUMN jumlah_disetujui DECIMAL(15,2) NULL AFTER jumlah");
        }
    }
}

try {
    sp_ensure_jumlah_disetujui_schema($pdo);
} catch (Throwable $e) {
    error_log('JUMLAH DISETUJUI SCHEMA ERROR: ' . $e->getMessage());
}

/*
 * Pastikan kolom status tidak lagi terikat ENUM lama.
 * Database lama sering hanya mengenal pending/diseleksi/disetujui/ditolak,
 * sehingga saat pencairan menyimpan status 'dicairkan' MySQL/MariaDB
 * menghasilkan SQLSTATE 01000: Data truncated for column 'status'.
 * VARCHAR dipakai agar status baru seperti dibatalkan/dicairkan tetap aman.
 */
if (!function_exists('sp_ensure_status_columns_schema')) {
    function sp_ensure_status_columns_schema(PDO $pdo): void
    {
        $targets = [
            'pengajuan_pinjaman' => 'pending',
            'pinjaman' => 'aktif',
            'angsuran_pinjaman' => 'belum_bayar',
        ];

        foreach ($targets as $table => $defaultStatus) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
                $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (!$col) {
                    continue;
                }

                $type = strtolower(trim((string)($col['Type'] ?? '')));
                $needsChange = (strpos($type, 'enum(') === 0 || strpos($type, 'set(') === 0);
                if (!$needsChange && preg_match('/^varchar\((\d+)\)$/', $type, $m)) {
                    $needsChange = ((int)$m[1] < 30);
                }
                if (!$needsChange && preg_match('/^char\((\d+)\)$/', $type, $m)) {
                    $needsChange = ((int)$m[1] < 30);
                }
                if ($needsChange) {
                    $defaultSql = str_replace("'", "''", (string)$defaultStatus);
                    $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT '{$defaultSql}'");
                }
            } catch (Throwable $e) {
                error_log('STATUS COLUMN SCHEMA ERROR [' . $table . ']: ' . $e->getMessage());
            }
        }
    }
}

sp_ensure_status_columns_schema($pdo);

$userId = (int)(isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0);

$importPinjamanPreview = [];
$importPinjamanSummary = [
    'valid' => 0,
    'duplikat' => 0,
    'gagal' => 0,
];
$importPinjamanSuccess = '';
$importPinjamanError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    $formAction = (string)($_POST['action'] ?? '');

    if ($formAction === 'preview_import_pinjaman') {
        try {
            if (empty($_FILES['file_import_pinjaman']['tmp_name']) || !is_uploaded_file($_FILES['file_import_pinjaman']['tmp_name'])) {
                throw new Exception('File Excel pinjaman belum dipilih.');
            }

            $rows = sp_normalize_import_pinjaman_rows(
                (string)$_FILES['file_import_pinjaman']['tmp_name'],
                (string)($_FILES['file_import_pinjaman']['name'] ?? '')
            );

            if (!$rows) {
                throw new Exception('Data pinjaman tidak ditemukan. Pastikan sheet berisi kata PINJAM / UANG / BARANG dan memiliki kolom nama serta nominal pinjaman.');
            }

            $preview = sp_preview_pinjaman_import($pdo, $rows);
            $importPinjamanPreview = $preview['rows'];
            $importPinjamanSummary = $preview['summary'];
            $importPinjamanSuccess = 'Preview import berhasil. Valid ' . $importPinjamanSummary['valid'] . ', duplikat ' . $importPinjamanSummary['duplikat'] . ', gagal ' . $importPinjamanSummary['gagal'] . '.';
        } catch (Throwable $e) {
            $importPinjamanError = 'Gagal preview import pinjaman: ' . $e->getMessage();
        }
    }

    if ($formAction === 'proses_import_pinjaman') {
        $payload = (string)($_POST['import_pinjaman_payload'] ?? '');
        $rows = json_decode(base64_decode($payload), true);

        if (!is_array($rows) || !$rows) {
            $importPinjamanError = 'Data import pinjaman tidak valid atau sudah kadaluarsa. Silakan upload ulang.';
        } else {
            $berhasil = 0;
            $gagal = 0;

            try {
                $konfigImport = $pdo->query("SELECT * FROM konfigurasi_sp ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

                $pdo->beginTransaction();

                $debugErrors = [];
                foreach ($rows as $row) {
                    $statusImport = (string)($row['status_import'] ?? '');
                    if (!in_array($statusImport, ['valid', 'valid_create_member'], true)) {
                        $gagal++;
                        continue;
                    }

                    try {
                        if ($statusImport === 'valid_create_member' && (int)($row['member_id'] ?? 0) <= 0) {
                            $row['member_id'] = sp_create_member_from_import($pdo, $row);
                        }

                        sp_create_pinjaman_from_import($pdo, $row, $konfigImport, $userId);
                        $berhasil++;
                    } catch (Throwable $inner) {
                        $gagal++;
                        if (count($debugErrors) < 5) {
                            $debugErrors[] = (string)($row['nama'] ?? '-') . ': ' . $inner->getMessage();
                        }
                    }
                }

                $pdo->commit();

                $importPinjamanSuccess = 'Import pinjaman selesai. Berhasil ' . $berhasil . ' data, gagal/lewati ' . $gagal . ' data.';
                if ($debugErrors) {
                    $importPinjamanSuccess .= ' Catatan error awal: ' . implode(' | ', $debugErrors);
                }

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'import', 'Pinjaman', 'Import pinjaman dari Excel: ' . $berhasil . ' data');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $importPinjamanError = 'Gagal proses import pinjaman: ' . $e->getMessage();
            }
        }
    }
}



if (isset($_GET['action']) && $_GET['action'] === 'export_konfirmasi') {
    $stmtExport = $pdo->query("
        SELECT
            pp.id AS pengajuan_id,
            m.kode AS kode_member,
            m.nama AS nama_member,
            pp.jenis,
            pp.jumlah,
            COALESCE(NULLIF(pp.jumlah_disetujui, 0), pp.jumlah) AS jumlah_disetujui,
            pp.tenor,
            pp.status AS status_pengajuan,
            pp.keperluan, pp.catatan_petugas,
            pp.disetujui_at,
            COALESCE(k.keputusan, 'pending') AS keputusan_member,
            k.dikonfirmasi_at,
            k.nama_ktp, k.alamat_ktp, k.no_hp,
            k.nama_bank, k.no_rekening,
            k.nama_keluarga, k.alamat_keluarga, k.no_hp_keluarga
        FROM pengajuan_pinjaman pp
        LEFT JOIN member m ON m.id = pp.member_id
        LEFT JOIN konfirmasi_pinjaman_member k ON k.pengajuan_id = pp.id
        WHERE pp.status IN ('disetujui','dicairkan')
          AND k.keputusan IN ('ambil','tidak_ambil')
        ORDER BY k.dikonfirmasi_at DESC, pp.disetujui_at DESC, pp.id DESC
    ");
    $rowsExport = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $fpdfCandidates = [
        __DIR__ . '/fpdf/fpdf.php',
        __DIR__ . '/FPDF/fpdf.php',
        __DIR__ . '/vendor/fpdf/fpdf.php',
        __DIR__ . '/vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
    ];
    foreach ($fpdfCandidates as $fpdfFile) {
        if (is_file($fpdfFile)) {
            require_once $fpdfFile;
            break;
        }
    }
    if (!class_exists('FPDF')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Library FPDF tidak ditemukan. Pastikan fpdf/fpdf.php tersedia di aplikasi.";
        exit;
    }

    if (!function_exists('sp_pdf_text')) {
        /**
         * @param mixed $text
         * @return string
         */
        function sp_pdf_text($text): string
        {
            $text = (string)($text ?? '');
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
                if ($converted !== false) return $converted;
            }
            return $text;
        }
    }
    if (!function_exists('sp_pdf_date')) {
        /**
         * @param mixed $date
         * @param bool $withTime
         * @return string
         */
        function sp_pdf_date($date, bool $withTime = false): string
        {
            $date = trim((string)($date ?? ''));
            if ($date === '' || strtotime($date) === false) return '-';
            return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', strtotime($date));
        }
    }

    class SPPinjamanKonfirmasiPDF extends FPDF
    {
        public function Header()
        {
            $this->SetFont('Arial', 'B', 13);
            $this->Cell(0, 6, sp_pdf_text('KOPERASI BSDK SEJAHTERA'), 0, 1, 'C');
            $this->SetFont('Arial', '', 8);
            $this->Cell(0, 4, sp_pdf_text('Laporan Persetujuan dan Konfirmasi Pengambilan Pinjaman'), 0, 1, 'C');
            $this->Ln(2);
            $this->SetDrawColor(30, 30, 30);
            $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
            $this->Ln(4);
        }

        public function Footer()
        {
            $this->SetY(-10);
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(110, 110, 110);
            $this->Cell(0, 4, sp_pdf_text('Dicetak ' . date('d/m/Y H:i') . ' WIB  |  Halaman ' . $this->PageNo()), 0, 0, 'C');
            $this->SetTextColor(0, 0, 0);
        }

        public function sectionTitle(string $title): void
        {
            $this->SetFillColor(245, 245, 245);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(0, 7, sp_pdf_text($title), 0, 1, 'L', true);
            $this->Ln(1);
        }
    }

    $pdf = new SPPinjamanKonfirmasiPDF('L', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 13);
    $pdf->AddPage();

    $totalData = count($rowsExport);
    $totalAmbil = 0;
    $totalTidakAmbil = 0;
    $totalMenunggu = 0;
    $totalDisetujui = 0;
    $periodeKonfirmasiAwal = null;
    $periodeKonfirmasiAkhir = null;
    foreach ($rowsExport as $r) {
        $totalDisetujui += (float)($r['jumlah_disetujui'] ?? 0);
        $k = (string)($r['keputusan_member'] ?? 'pending');
        if ($k === 'ambil') $totalAmbil++;
        elseif ($k === 'tidak_ambil') $totalTidakAmbil++;
        else $totalMenunggu++;

        $tglPeriode = !empty($r['dikonfirmasi_at']) ? (string)$r['dikonfirmasi_at'] : (string)($r['disetujui_at'] ?? '');
        if ($tglPeriode !== '' && strtotime($tglPeriode) !== false) {
            $ts = strtotime($tglPeriode);
            if ($periodeKonfirmasiAwal === null || $ts < $periodeKonfirmasiAwal) $periodeKonfirmasiAwal = $ts;
            if ($periodeKonfirmasiAkhir === null || $ts > $periodeKonfirmasiAkhir) $periodeKonfirmasiAkhir = $ts;
        }
    }

    $periodeKonfirmasiText = '-';
    if ($periodeKonfirmasiAwal !== null && $periodeKonfirmasiAkhir !== null) {
        $periodeKonfirmasiText = date('d/m/Y', $periodeKonfirmasiAwal) . ' s.d. ' . date('d/m/Y', $periodeKonfirmasiAkhir);
    }

    // Periode laporan ditempatkan sebelum tabel agar konteks data langsung terlihat.
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(45, 6, sp_pdf_text('Periode Konfirmasi'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(232, 6, sp_pdf_text($periodeKonfirmasiText), 1, 1, 'L');
    $pdf->Ln(3);

    $pdf->sectionTitle('RINGKASAN PERSETUJUAN DAN KONFIRMASI');

    $headers = ['No', 'Member', 'Jenis', 'Diajukan', 'Disetujui', 'Tenor', 'Status', 'Konfirmasi', 'Tgl ACC', 'Tgl Konfirmasi'];
    $widths  = [9, 58, 18, 30, 30, 16, 24, 32, 28, 32];
    $pdf->SetFillColor(30, 30, 30);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    foreach ($headers as $i => $h) {
        $pdf->Cell($widths[$i], 7, sp_pdf_text($h), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 7);

    foreach ($rowsExport as $idx => $r) {
        if ($pdf->GetY() > 183) {
            $pdf->AddPage();
            $pdf->SetFillColor(30, 30, 30);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 7);
            foreach ($headers as $i => $h) $pdf->Cell($widths[$i], 7, sp_pdf_text($h), 1, 0, 'C', true);
            $pdf->Ln();
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 7);
        }
        $keputusan = (string)($r['keputusan_member'] ?? 'pending');
        $labelKonfirmasi = $keputusan === 'ambil' ? 'Akan Mengambil' : ($keputusan === 'tidak_ambil' ? 'Tidak Mengambil' : 'Belum Konfirmasi');
        $memberText = trim((string)($r['nama_member'] ?? '-')) . ' (' . trim((string)($r['kode_member'] ?? '-')) . ')';
        if (strlen($memberText) > 34) $memberText = substr($memberText, 0, 31) . '...';
        $statusLabel = ucfirst((string)($r['status_pengajuan'] ?? '-'));
        $rowData = [
            (string)($idx + 1),
            $memberText,
            ucfirst((string)($r['jenis'] ?? '-')),
            rupiah_sp((float)($r['jumlah'] ?? 0)),
            rupiah_sp((float)($r['jumlah_disetujui'] ?? 0)),
            (int)($r['tenor'] ?? 0) . ' bln',
            $statusLabel,
            $labelKonfirmasi,
            sp_pdf_date($r['disetujui_at'] ?? null, false),
            sp_pdf_date($r['dikonfirmasi_at'] ?? null, false),
        ];
        foreach ($rowData as $i => $val) {
            $align = in_array($i, [0, 2, 5, 6, 7, 8, 9], true) ? 'C' : ($i === 1 ? 'L' : 'R');
            $pdf->Cell($widths[$i], 6, sp_pdf_text((string)$val), 1, 0, $align);
        }
        $pdf->Ln();
    }

    $confirmed = array_values(array_filter($rowsExport, static function ($r) {
        return in_array((string)($r['keputusan_member'] ?? ''), ['ambil', 'tidak_ambil'], true);
    }));

    if ($confirmed) {
        $pdf->AddPage();
        $pdf->sectionTitle('DETAIL DATA MEMBER YANG SUDAH KONFIRMASI');
        foreach ($confirmed as $idx => $r) {
            if ($pdf->GetY() > 155) $pdf->AddPage();
            $keputusan = (string)($r['keputusan_member'] ?? '');
            $labelKonfirmasi = $keputusan === 'ambil' ? 'AKAN MENGAMBIL' : 'TIDAK MENGAMBIL';

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(0, 7, sp_pdf_text(($idx + 1) . '. ' . ($r['nama_member'] ?? '-') . ' - ' . $labelKonfirmasi), 1, 1, 'L', true);
            $pdf->SetFont('Arial', '', 7.5);

            $leftX = $pdf->GetX();
            $startY = $pdf->GetY();
            $half = 136;
            $labelW = 39;
            $valueW = $half - $labelW;

            $pairsLeft = [
                ['Kode Member', $r['kode_member'] ?? '-'],
                ['Jenis Pinjaman', ucfirst((string)($r['jenis'] ?? '-'))],
                ['Nominal Diajukan', rupiah_sp((float)($r['jumlah'] ?? 0))],
                ['Nominal Disetujui', rupiah_sp((float)($r['jumlah_disetujui'] ?? 0))],
                ['Tenor', (int)($r['tenor'] ?? 0) . ' bulan'],
                ['Keperluan', trim((string)($r['keperluan'] ?? '')) !== '' ? $r['keperluan'] : '-'],
                ['Nama Sesuai KTP', $r['nama_ktp'] ?? '-'],
                ['No. Handphone', $r['no_hp'] ?? '-'],
            ];
            $pairsRight = [
                ['Nama Bank', $r['nama_bank'] ?? '-'],
                ['No. Rekening', $r['no_rekening'] ?? '-'],
                ['Nama Keluarga', $r['nama_keluarga'] ?? '-'],
                ['No. HP Keluarga', $r['no_hp_keluarga'] ?? '-'],
                ['Tanggal ACC', sp_pdf_date($r['disetujui_at'] ?? null, true)],
                ['Tanggal Konfirmasi', sp_pdf_date($r['dikonfirmasi_at'] ?? null, true)],
                ['Status', $labelKonfirmasi],
            ];

            foreach ($pairsLeft as $pair) {
                $pdf->SetX($leftX);
                $pdf->Cell($labelW, 5.5, sp_pdf_text($pair[0]), 1, 0, 'L');
                $pdf->Cell($valueW, 5.5, sp_pdf_text((string)$pair[1]), 1, 1, 'L');
            }
            $leftEndY = $pdf->GetY();

            $pdf->SetXY($leftX + $half + 5, $startY);
            foreach ($pairsRight as $pair) {
                $pdf->SetX($leftX + $half + 5);
                $pdf->Cell($labelW, 5.5, sp_pdf_text($pair[0]), 1, 0, 'L');
                $pdf->Cell($valueW, 5.5, sp_pdf_text((string)$pair[1]), 1, 1, 'L');
            }
            $rightEndY = $pdf->GetY();
            $pdf->SetY(max($leftEndY, $rightEndY) + 1.5);

            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell(38, 5.5, sp_pdf_text('Alamat Sesuai KTP'), 1, 0, 'L');
            $pdf->SetFont('Arial', '', 7.5);
            $alamatKtp = trim((string)($r['alamat_ktp'] ?? '-')) ?: '-';
            $pdf->MultiCell(0, 5.5, sp_pdf_text($alamatKtp), 1, 'L');
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell(38, 5.5, sp_pdf_text('Alamat Keluarga'), 1, 0, 'L');
            $pdf->SetFont('Arial', '', 7.5);
            $alamatKel = trim((string)($r['alamat_keluarga'] ?? '-')) ?: '-';
            $pdf->MultiCell(0, 5.5, sp_pdf_text($alamatKel), 1, 'L');
            $pdf->Ln(3);
        }
    }

    // Ringkasan akhir: rata penuh dari margin kiri sampai margin kanan.
    if ($pdf->GetY() > 170) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(4);
    }
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(52, 6, sp_pdf_text('Total pengajuan disetujui'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(17, 6, number_format($totalData, 0, ',', '.'), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(38, 6, sp_pdf_text('Akan mengambil'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(17, 6, number_format($totalAmbil, 0, ',', '.'), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(38, 6, sp_pdf_text('Tidak mengambil'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(17, 6, number_format($totalTidakAmbil, 0, ',', '.'), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(35, 6, sp_pdf_text('Menunggu'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(63, 6, number_format($totalMenunggu, 0, ',', '.'), 1, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(69, 6, sp_pdf_text('Total nominal disetujui'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(208, 6, sp_pdf_text(rupiah_sp($totalDisetujui)), 1, 1, 'L');

    $filename = 'laporan_persetujuan_konfirmasi_pinjaman_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}


if (isset($_GET['action']) && $_GET['action'] === 'export_pengajuan') {
    $stmtExport = $pdo->query("
        SELECT
            pp.id AS pengajuan_id,
            m.kode AS kode_member,
            m.nama AS nama_member,
            m.no_hp AS no_hp_member,
            pp.jenis,
            pp.jumlah,
            COALESCE(NULLIF(pp.jumlah_disetujui, 0), 0) AS jumlah_disetujui,
            pp.tenor,
            pp.keperluan,
            pp.catatan_petugas,
            pp.status AS status_pengajuan,
            pp.created_at,
            pp.disetujui_at
        FROM pengajuan_pinjaman pp
        LEFT JOIN member m ON m.id = pp.member_id
        ORDER BY pp.created_at DESC, pp.id DESC
    ");
    $rowsExport = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $fpdfCandidates = [
        __DIR__ . '/fpdf/fpdf.php',
        __DIR__ . '/FPDF/fpdf.php',
        __DIR__ . '/vendor/fpdf/fpdf.php',
        __DIR__ . '/vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
    ];
    foreach ($fpdfCandidates as $fpdfFile) {
        if (is_file($fpdfFile)) {
            require_once $fpdfFile;
            break;
        }
    }
    if (!class_exists('FPDF')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Library FPDF tidak ditemukan. Pastikan fpdf/fpdf.php tersedia di aplikasi.";
        exit;
    }

    if (!function_exists('sp_pengajuan_pdf_text')) {
        /**
         * @param mixed $text
         * @return string
         */
        function sp_pengajuan_pdf_text($text): string
        {
            $text = (string)($text ?? '');
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
                if ($converted !== false) return $converted;
            }
            return $text;
        }
    }
    if (!function_exists('sp_pengajuan_pdf_date')) {
        /**
         * @param mixed $date
         * @param bool $withTime
         * @return string
         */
        function sp_pengajuan_pdf_date($date, bool $withTime = false): string
        {
            $date = trim((string)($date ?? ''));
            if ($date === '' || strtotime($date) === false) return '-';
            return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', strtotime($date));
        }
    }
    if (!function_exists('sp_pengajuan_pdf_status')) {
        /**
         * @param mixed $status
         * @return string
         */
        function sp_pengajuan_pdf_status($status): string
        {
            $status = strtolower(trim((string)$status));
            $map = [
                'pending' => 'Pending',
                'diseleksi' => 'Diseleksi',
                'disetujui' => 'Disetujui',
                'ditolak' => 'Ditolak',
                'dibatalkan' => 'Dibatalkan',
                'batal_pengajuan' => 'Dibatalkan',
                'dicairkan' => 'Dicairkan',
            ];
            return $map[$status] ?? ($status !== '' ? ucfirst($status) : 'Dibatalkan');
        }
    }

    class SPPengajuanPDF extends FPDF
    {
        public function Header()
        {
            $this->SetFont('Arial', 'B', 13);
            $this->Cell(0, 6, sp_pengajuan_pdf_text('KOPERASI BSDK SEJAHTERA'), 0, 1, 'C');
            $this->SetFont('Arial', '', 8);
            $this->Cell(0, 4, sp_pengajuan_pdf_text('Laporan Daftar Pengajuan Pinjaman Member'), 0, 1, 'C');
            $this->Ln(2);
            $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
            $this->Ln(4);
        }
        public function Footer()
        {
            $this->SetY(-10);
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(110, 110, 110);
            $this->Cell(0, 4, sp_pengajuan_pdf_text('Dicetak ' . date('d/m/Y H:i') . ' WIB  |  Halaman ' . $this->PageNo()), 0, 0, 'C');
            $this->SetTextColor(0, 0, 0);
        }
    }

    $pdf = new SPPengajuanPDF('L', 'mm', 'A4');
    $pdf->SetMargins(8, 10, 8);
    $pdf->SetAutoPageBreak(true, 13);
    $pdf->AddPage();

    $total = count($rowsExport);
    $totalNilai = 0;
    $periodePengajuanAwal = null;
    $periodePengajuanAkhir = null;
    foreach ($rowsExport as $r) {
        $totalNilai += (float)($r['jumlah'] ?? 0);
        $tglPengajuan = (string)($r['created_at'] ?? '');
        if ($tglPengajuan !== '' && strtotime($tglPengajuan) !== false) {
            $ts = strtotime($tglPengajuan);
            if ($periodePengajuanAwal === null || $ts < $periodePengajuanAwal) $periodePengajuanAwal = $ts;
            if ($periodePengajuanAkhir === null || $ts > $periodePengajuanAkhir) $periodePengajuanAkhir = $ts;
        }
    }
    $periodePengajuanText = '-';
    if ($periodePengajuanAwal !== null && $periodePengajuanAkhir !== null) {
        $periodePengajuanText = date('d/m/Y', $periodePengajuanAwal) . ' s.d. ' . date('d/m/Y', $periodePengajuanAkhir);
    }

    // Periode pengajuan tampil di atas tabel.
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(45, 6, sp_pengajuan_pdf_text('Periode Pengajuan'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(236, 6, sp_pengajuan_pdf_text($periodePengajuanText), 1, 1, 'L');
    $pdf->Ln(3);

    $headers = ['No', 'Member', 'Jenis', 'Diajukan', 'Tenor', 'Keperluan / Keterangan', 'Status', 'Tanggal'];
    $widths  = [9, 53, 18, 30, 16, 98, 25, 32];

    $drawHeader = static function ($pdf) use ($headers, $widths) {
        $pdf->SetFillColor(30, 30, 30);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);
        foreach ($headers as $i => $h) $pdf->Cell($widths[$i], 7, sp_pengajuan_pdf_text($h), 1, 0, 'C', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);
    };
    $drawHeader($pdf);

    foreach ($rowsExport as $idx => $r) {
        if ($pdf->GetY() > 181) {
            $pdf->AddPage();
            $drawHeader($pdf);
        }
        $memberText = trim((string)($r['nama_member'] ?? '-')) . ' (' . trim((string)($r['kode_member'] ?? '-')) . ')';
        if (strlen($memberText) > 31) $memberText = substr($memberText, 0, 28) . '...';
        $ket = trim((string)($r['keperluan'] ?? ''));
        if ($ket === '') $ket = '-';
        if (!empty($r['catatan_petugas'])) $ket .= ' | Catatan: ' . trim((string)$r['catatan_petugas']);
        if (strlen($ket) > 72) $ket = substr($ket, 0, 69) . '...';
        $row = [
            (string)($idx + 1),
            $memberText,
            ucfirst((string)($r['jenis'] ?? '-')),
            rupiah_sp((float)($r['jumlah'] ?? 0)),
            (int)($r['tenor'] ?? 0) . ' bln',
            $ket,
            sp_pengajuan_pdf_status($r['status_pengajuan'] ?? ''),
            sp_pengajuan_pdf_date($r['created_at'] ?? null, true)
        ];
        foreach ($row as $i => $val) {
            $align = in_array($i, [0, 2, 4, 6, 7], true) ? 'C' : ($i === 3 ? 'R' : 'L');
            $pdf->Cell($widths[$i], 6, sp_pengajuan_pdf_text((string)$val), 1, 0, $align);
        }
        $pdf->Ln();
    }

    // Ringkasan akhir: rata penuh dari margin kiri sampai margin kanan.
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(4);
    }
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(55, 6, sp_pengajuan_pdf_text('Total Pengajuan'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(30, 6, number_format($total, 0, ',', '.'), 1, 0, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(65, 6, sp_pengajuan_pdf_text('Total Nilai Diajukan'), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(131, 6, sp_pengajuan_pdf_text(rupiah_sp($totalNilai)), 1, 1, 'L');

    $filename = 'laporan_pengajuan_pinjaman_' . date('Ymd_His') . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}

// ── API Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $_GET['action'];

    try {
        if ($action === 'seleksi') {
            // Tandai sebagai diseleksi/disetujui/ditolak
            $id     = (int)(isset($input['id']) ? $input['id'] : 0);
            $status = isset($input['status']) ? $input['status'] : '';
            $catatan = trim(isset($input['catatan']) ? $input['catatan'] : '');
            $jumlahDisetujuiInput = isset($input['jumlah_disetujui']) ? (float)$input['jumlah_disetujui'] : 0;

            if (!in_array($status, ['disetujui', 'ditolak', 'diseleksi'], true)) {
                echo json_encode(['success' => false, 'message' => 'Status tidak valid.']);
                exit;
            }

            // Ambil data pengajuan
            $stmt = $pdo->prepare("
                SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode, m.no_hp AS member_hp
                FROM pengajuan_pinjaman pp
                LEFT JOIN member m ON m.id = pp.member_id
                WHERE pp.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pengajuan) {
                echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan.']);
                exit;
            }

            $setArr = [
                'status'         => $status,
                'catatan_petugas' => $catatan,
            ];

            if ($status === 'diseleksi') {
                $setArr['diseleksi_oleh'] = $userId;
                $setArr['diseleksi_at']   = date('Y-m-d H:i:s');
            } elseif ($status === 'disetujui') {
                $jumlahDiminta = (float)($pengajuan['jumlah'] ?? 0);
                if ($jumlahDisetujuiInput <= 0) {
                    $jumlahDisetujuiInput = $jumlahDiminta;
                }
                if ($jumlahDisetujuiInput > $jumlahDiminta) {
                    echo json_encode(['success' => false, 'message' => 'Nominal yang disetujui tidak boleh melebihi nominal pengajuan.']);
                    exit;
                }
                $setArr['jumlah_disetujui'] = $jumlahDisetujuiInput;
                $setArr['disetujui_oleh'] = $userId;
                $setArr['disetujui_at']   = date('Y-m-d H:i:s');
            }

            $setClauses = [];
            foreach (array_keys($setArr) as $col) {
                $setClauses[] = "$col = :$col";
            }
            $pdo->prepare("UPDATE pengajuan_pinjaman SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = :id")
                ->execute(array_merge($setArr, [':id' => $id]));

            // Kirim notifikasi ke member
            $pesanMap = [
                'diseleksi' => 'Pengajuan pinjaman Anda sedang dalam proses seleksi oleh petugas.',
                'disetujui' => 'Pengajuan pinjaman Anda telah disetujui sebesar ' . rupiah_sp((float)($setArr['jumlah_disetujui'] ?? $pengajuan['jumlah'])) . ' dari pengajuan ' . rupiah_sp((float)$pengajuan['jumlah']) . '. Silakan buka halaman Pinjaman di Member Dashboard lalu konfirmasi apakah pinjaman akan diambil.',
                'ditolak'   => 'Mohon maaf, pengajuan pinjaman Anda tidak dapat disetujui.' . ($catatan ? ' Alasan: ' . $catatan : ''),
            ];
            $judulMap = [
                'diseleksi' => 'Pengajuan Sedang Diseleksi',
                'disetujui' => 'Pengajuan Disetujui',
                'ditolak'   => 'Pengajuan Ditolak',
            ];
            $tipeMap = [
                'diseleksi' => 'pinjaman',
                'disetujui' => 'sukses',
                'ditolak'   => 'peringatan',
            ];

            $pdo->prepare("
                INSERT INTO notifikasi_member (member_id, judul, pesan, tipe, ref_id, ref_tipe)
                VALUES (:mid, :judul, :pesan, :tipe, :rid, 'pengajuan')
            ")->execute([
                ':mid'   => $pengajuan['member_id'],
                ':judul' => $judulMap[$status],
                ':pesan' => $pesanMap[$status],
                ':tipe'  => $tipeMap[$status],
                ':rid'   => $id,
            ]);

            $waLink = '';
            if (!empty($pengajuan['member_hp'])) {
                $waPesan = $pesanMap[$status];
                $waLink = kirim_notif_wa_sp((string)$pengajuan['member_hp'], (string)$waPesan);
            }

            catat_aktivitas($pdo, 'update', 'Pinjaman', "Ubah status pengajuan #$id ke $status");
            echo json_encode([
                'success' => true,
                'message' => 'Status berhasil diperbarui.',
                'wa_link' => $waLink
            ]);
        } elseif ($action === 'cairkan') {
            $id = (int)(isset($input['id']) ? $input['id'] : 0);

            $stmt = $pdo->prepare("
                SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode, m.no_hp AS member_hp
                FROM pengajuan_pinjaman pp
                LEFT JOIN member m ON m.id = pp.member_id
                WHERE pp.id = :id
                  AND pp.status = 'disetujui'
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pengajuan) {
                echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan atau belum disetujui.']);
                exit;
            }

            $stmtKonfirmasi = $pdo->prepare("SELECT keputusan FROM konfirmasi_pinjaman_member WHERE pengajuan_id = :id LIMIT 1");
            $stmtKonfirmasi->execute([':id' => $id]);
            $keputusanMember = (string)($stmtKonfirmasi->fetchColumn() ?: 'pending');
            if ($keputusanMember !== 'ambil') {
                echo json_encode([
                    'success' => false,
                    'message' => $keputusanMember === 'tidak_ambil'
                        ? 'Member mengonfirmasi tidak akan mengambil pinjaman ini.'
                        : 'Pinjaman belum dapat dicairkan karena member belum mengonfirmasi pengambilan.'
                ]);
                exit;
            }

            // Ambil konfigurasi bunga langsung dari database
            $konfig = $pdo->query("
                SELECT bunga_uang, bunga_barang
                FROM konfigurasi_sp
                ORDER BY id ASC
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC) ?: [];

            $bungaPct = $pengajuan['jenis'] === 'barang'
                ? (float)($konfig['bunga_barang'] ?? 1.5)
                : (float)($konfig['bunga_uang'] ?? 1.0);

            $pokok  = (int)((float)($pengajuan['jumlah_disetujui'] ?? 0) > 0 ? $pengajuan['jumlah_disetujui'] : $pengajuan['jumlah']);
            $tenor  = max(1, (int)$pengajuan['tenor']);
            $hitung = hitung_angsuran_sp($pokok, $tenor, $bungaPct);
            $angPok = $hitung['pokok'];
            $angBng = $hitung['bunga_bulan'];
            $angTot = $hitung['total_bulan'];

            $mulai   = date('Y-m-d');
            $selesai = date('Y-m-d', strtotime("+$tenor months"));

            $pdo->beginTransaction();

            // Insert pinjaman
            $pdo->prepare("
                INSERT INTO pinjaman (pengajuan_id, member_id, jenis, pokok, bunga_persen, tenor,
                    angsuran_pokok, angsuran_bunga, angsuran_total, tanggal_mulai, tanggal_selesai, status)
                VALUES (:pid, :mid, :jenis, :pokok, :bunga, :tenor, :apok, :abng, :atot, :mulai, :selesai, 'aktif')
            ")->execute([
                ':pid'   => $id,
                ':mid'   => $pengajuan['member_id'],
                ':jenis' => $pengajuan['jenis'],
                ':pokok' => $pokok,
                ':bunga' => $bungaPct,
                ':tenor' => $tenor,
                ':apok'  => $angPok,
                ':abng'  => $angBng,
                ':atot'  => $angTot,
                ':mulai' => $mulai,
                ':selesai' => $selesai,
            ]);
            $pinjamanId = (int)$pdo->lastInsertId();

            // Generate angsuran
            $insAng = $pdo->prepare("
                INSERT INTO angsuran_pinjaman (pinjaman_id, member_id, ke, bulan, tahun, jumlah_pokok, jumlah_bunga, jumlah_total, jatuh_tempo, status)
                VALUES (:pid, :mid, :ke, :bln, :thn, :jpok, :jbng, :jtot, :jatuh, 'belum_bayar')
            ");
            for ($i = 1; $i <= $tenor; $i++) {
                $jatuhDate = date('Y-m-d', strtotime("+$i months", strtotime($mulai)));
                $insAng->execute([
                    ':pid'  => $pinjamanId,
                    ':mid'  => $pengajuan['member_id'],
                    ':ke'   => $i,
                    ':bln'  => (int)date('n', strtotime($jatuhDate)),
                    ':thn'  => (int)date('Y', strtotime($jatuhDate)),
                    ':jpok' => $angPok,
                    ':jbng' => $angBng,
                    ':jtot' => $angTot,
                    ':jatuh' => $jatuhDate,
                ]);
            }

            // Auto jurnal pencairan pinjaman:
            // Debit Piutang Pinjaman (103), Kredit Kas (101).
            // Jika tabel jurnal/COA belum siap, pencairan tetap aman karena fungsi ini menangani error sendiri.
            sp_auto_jurnal_pinjaman_cair(
                $pdo,
                (int)$pinjamanId,
                (float)$pokok,
                (int)$userId
            );

            // Update status pengajuan
            $pdo->prepare("
                UPDATE pengajuan_pinjaman SET status = 'dicairkan', dicairkan_oleh = :uid, dicairkan_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ")->execute([':uid' => $userId, ':id' => $id]);

            // Notifikasi
            $pdo->prepare("
                INSERT INTO notifikasi_member (member_id, judul, pesan, tipe, ref_id, ref_tipe)
                VALUES (:mid, 'Pinjaman Dicairkan', :pesan, 'sukses', :rid, 'pinjaman')
            ")->execute([
                ':mid'  => $pengajuan['member_id'],
                ':pesan' => "Pinjaman " . rupiah_sp($pokok) . " telah dicairkan. Angsuran pertama jatuh tempo " . date('d/m/Y', strtotime('+1 month', strtotime($mulai))) . ".",
                ':rid'  => $pinjamanId,
            ]);

            $pdo->commit();

            $waLink = '';
            if (!empty($pengajuan['member_hp'])) {
                $waPesan = "Pinjaman Anda sebesar " . rupiah_sp($pokok) . " telah dicairkan. Angsuran per bulan " . rupiah_sp($angTot) . " selama {$tenor} bulan. Jatuh tempo pertama " . date('d/m/Y', strtotime('+1 month', strtotime($mulai))) . ".";
                $waLink = kirim_notif_wa_sp((string)$pengajuan['member_hp'], (string)$waPesan);
            }

            catat_aktivitas($pdo, 'create', 'Pinjaman', "Cairkan pinjaman #$id — " . rupiah_sp($pokok));
            echo json_encode([
                'success' => true,
                'message' => 'Pinjaman berhasil dicairkan. Angsuran sudah dibuat.',
                'wa_link' => $waLink
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterJenis  = isset($_GET['jenis'])  ? $_GET['jenis']  : '';
$q = trim(isset($_GET['q']) ? $_GET['q'] : '');

$perPagePengajuan = max(5, min(100, (int)($_GET['per_pengajuan'] ?? 25)));
$pagePengajuan = max(1, (int)($_GET['page_pengajuan'] ?? 1));
$offsetPengajuan = ($pagePengajuan - 1) * $perPagePengajuan;

$perPageAktif = max(5, min(100, (int)($_GET['per_aktif'] ?? 25)));
$pageAktif = max(1, (int)($_GET['page_aktif'] ?? 1));
$offsetAktif = ($pageAktif - 1) * $perPageAktif;

$where  = ['1=1'];
$params = [];

if ($filterStatus) {
    if ($filterStatus === 'dibatalkan') {
        // Status batal pengajuan pernah tersimpan berbeda-beda:
        // - dibatalkan
        // - batal_pengajuan
        // - string kosong
        // - NULL
        // Semua tetap dianggap sebagai Batal Pengajuan agar filter tidak kosong.
        $where[] = "(pp.status = 'dibatalkan' OR pp.status = 'batal_pengajuan' OR pp.status = '' OR pp.status IS NULL)";
    } else {
        $where[] = 'pp.status = :status';
        $params[':status'] = $filterStatus;
    }
}
if ($filterJenis) {
    $where[] = 'pp.jenis = :jenis';
    $params[':jenis'] = $filterJenis;
}
if ($q) {
    $where[] = '(m.nama LIKE :q OR m.kode LIKE :q2)';
    $params[':q']  = "%$q%";
    $params[':q2'] = "%$q%";
}

$whereStr = implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pengajuan_pinjaman pp
    LEFT JOIN member m ON m.id = pp.member_id
    WHERE $whereStr
");
$countStmt->execute($params);
$totalPengajuan = (int)$countStmt->fetchColumn();
$totalPagePengajuan = max(1, (int)ceil($totalPengajuan / $perPagePengajuan));
if ($pagePengajuan > $totalPagePengajuan) {
    $pagePengajuan = $totalPagePengajuan;
    $offsetPengajuan = ($pagePengajuan - 1) * $perPagePengajuan;
}

$stmt = $pdo->prepare("
    SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode, m.no_hp AS member_hp,
           u.nama AS petugas_nama,
           COALESCE(kpm.keputusan, 'pending') AS konfirmasi_keputusan,
           kpm.dikonfirmasi_at AS konfirmasi_at,
           kpm.nama_ktp AS konfirmasi_nama_ktp,
           kpm.nama_bank AS konfirmasi_nama_bank,
           kpm.no_rekening AS konfirmasi_no_rekening
    FROM pengajuan_pinjaman pp
    LEFT JOIN member m ON m.id = pp.member_id
    LEFT JOIN users u  ON u.id = pp.disetujui_oleh
    LEFT JOIN konfirmasi_pinjaman_member kpm ON kpm.pengajuan_id = pp.id
    WHERE $whereStr
    ORDER BY FIELD(pp.status,'pending','diseleksi','disetujui','ditolak','dibatalkan','dicairkan'), pp.created_at DESC
    LIMIT :limit_pengajuan OFFSET :offset_pengajuan
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit_pengajuan', $perPagePengajuan, PDO::PARAM_INT);
$stmt->bindValue(':offset_pengajuan', $offsetPengajuan, PDO::PARAM_INT);
$stmt->execute();

$pengajuanList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$peminjamBerjalan = [];
$totalPeminjamBerjalan = 0;
$totalPageAktif = 1;
try {
    $countAktifStmt = $pdo->query("
        SELECT COUNT(*)
        FROM pinjaman p
        WHERE p.status IN ('aktif','berjalan')
    ");
    $totalPeminjamBerjalan = (int)$countAktifStmt->fetchColumn();
    $totalPageAktif = max(1, (int)ceil($totalPeminjamBerjalan / $perPageAktif));
    if ($pageAktif > $totalPageAktif) {
        $pageAktif = $totalPageAktif;
        $offsetAktif = ($pageAktif - 1) * $perPageAktif;
    }

    $stmtAktif = $pdo->prepare("
        SELECT
            p.*,
            m.nama AS member_nama,
            m.kode AS member_kode,
            m.no_hp AS member_hp,
            COALESCE(a.total_angsuran, 0) AS total_angsuran,
            COALESCE(a.angsuran_lunas, 0) AS angsuran_lunas,
            COALESCE(a.sisa_angsuran, 0) AS sisa_angsuran,
            COALESCE(a.total_terbayar, 0) AS total_terbayar,
            COALESCE(a.sisa_tagihan, 0) AS sisa_tagihan,
            COALESCE(a.tagihan_terdekat, 0) AS tagihan_terdekat,
            a.jatuh_tempo_terdekat,
            COALESCE(hp.total_pinjaman_member, 0) AS total_pinjaman_member
        FROM pinjaman p
        LEFT JOIN member m ON m.id = p.member_id
        LEFT JOIN (
            SELECT
                pinjaman_id,
                COUNT(*) AS total_angsuran,
                SUM(CASE WHEN status IN ('lunas','dibayar','bayar') THEN 1 ELSE 0 END) AS angsuran_lunas,
                SUM(CASE WHEN status NOT IN ('lunas','dibayar','bayar') THEN 1 ELSE 0 END) AS sisa_angsuran,
                COALESCE(SUM(CASE WHEN status IN ('lunas','dibayar','bayar') THEN jumlah_total ELSE 0 END),0) AS total_terbayar,
                COALESCE(SUM(CASE WHEN status NOT IN ('lunas','dibayar','bayar') THEN jumlah_total ELSE 0 END),0) AS sisa_tagihan,
                MIN(CASE WHEN status NOT IN ('lunas','dibayar','bayar') THEN jatuh_tempo ELSE NULL END) AS jatuh_tempo_terdekat,
                MAX(CASE WHEN status NOT IN ('lunas','dibayar','bayar') THEN jumlah_total ELSE 0 END) AS tagihan_terdekat
            FROM angsuran_pinjaman
            GROUP BY pinjaman_id
        ) a ON a.pinjaman_id = p.id
        LEFT JOIN (
            SELECT
                member_id,
                COUNT(*) AS total_pinjaman_member
            FROM pinjaman
            GROUP BY member_id
        ) hp ON hp.member_id = p.member_id
        WHERE p.status IN ('aktif','berjalan')
        ORDER BY p.id DESC, p.created_at DESC
        LIMIT :limit_aktif OFFSET :offset_aktif
    ");
    $stmtAktif->bindValue(':limit_aktif', $perPageAktif, PDO::PARAM_INT);
    $stmtAktif->bindValue(':offset_aktif', $offsetAktif, PDO::PARAM_INT);
    $stmtAktif->execute();
    $peminjamBerjalan = $stmtAktif->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $peminjamBerjalan = [];
    $totalPeminjamBerjalan = 0;
    $totalPageAktif = 1;
}


// Summary
$sumStmt = $pdo->query("
    SELECT
        CASE
            WHEN status IS NULL OR status = '' OR status = 'batal_pengajuan' THEN 'dibatalkan'
            ELSE status
        END AS status,
        COUNT(*) AS total,
        SUM(jumlah) AS nilai
    FROM pengajuan_pinjaman
    GROUP BY
        CASE
            WHEN status IS NULL OR status = '' OR status = 'batal_pengajuan' THEN 'dibatalkan'
            ELSE status
        END
");
$summary = [];
foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $statusKey = strtolower(trim((string)($row['status'] ?? '')));

    // Jika status kosong, biasanya karena database enum belum mendukung nilai dibatalkan.
    // Tetap tampilkan sebagai Batal Pengajuan.
    if ($statusKey === '') {
        $statusKey = 'dibatalkan';
    }

    if (!isset($summary[$statusKey])) {
        $summary[$statusKey] = ['status' => $statusKey, 'total' => 0, 'nilai' => 0];
    }

    $summary[$statusKey]['total'] += (int)($row['total'] ?? 0);
    $summary[$statusKey]['nilai'] += (float)($row['nilai'] ?? 0);
}

$konfig = $pdo->query("SELECT * FROM konfigurasi_sp ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$rightActionHtml = '';


if (!function_exists('sp_pagination_url')) {
    function sp_pagination_url(string $param, int $page): string
    {
        $qs = $_GET;
        $qs[$param] = max(1, $page);
        return '?' . http_build_query($qs);
    }
}

if (!function_exists('sp_render_pagination')) {
    function sp_render_pagination(int $page, int $totalRows, int $perPage, string $param, string $label): void
    {
        $totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
        $page = max(1, min($page, $totalPages));
        $start = $totalRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $end = min($totalRows, $page * $perPage);
?>
        <div class="px-5 py-4 border-t border-gray-100 bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                <?php echo h($label); ?>: <?php echo number_format($start); ?>-<?php echo number_format($end); ?> dari <?php echo number_format($totalRows); ?> data
            </p>
            <?php if ($totalPages > 1): ?>
                <div class="flex flex-wrap items-center gap-1">
                    <a href="<?php echo h(sp_pagination_url($param, max(1, $page - 1))); ?>" class="px-3 py-2 text-[10px] font-black uppercase border border-gray-200 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50'; ?>">Prev</a>
                    <?php
                    $from = max(1, $page - 2);
                    $to = min($totalPages, $page + 2);
                    if ($from > 1) {
                        echo '<a href="' . h(sp_pagination_url($param, 1)) . '" class="px-3 py-2 text-[10px] font-black border border-gray-200 hover:bg-gray-50">1</a>';
                        if ($from > 2) echo '<span class="px-2 text-[10px] text-gray-400">...</span>';
                    }
                    for ($i = $from; $i <= $to; $i++) {
                        $active = $i === $page ? 'bg-black text-white border-black' : 'border-gray-200 hover:bg-gray-50';
                        echo '<a href="' . h(sp_pagination_url($param, $i)) . '" class="px-3 py-2 text-[10px] font-black border ' . $active . '">' . number_format($i) . '</a>';
                    }
                    if ($to < $totalPages) {
                        if ($to < $totalPages - 1) echo '<span class="px-2 text-[10px] text-gray-400">...</span>';
                        echo '<a href="' . h(sp_pagination_url($param, $totalPages)) . '" class="px-3 py-2 text-[10px] font-black border border-gray-200 hover:bg-gray-50">' . number_format($totalPages) . '</a>';
                    }
                    ?>
                    <a href="<?php echo h(sp_pagination_url($param, min($totalPages, $page + 1))); ?>" class="px-3 py-2 text-[10px] font-black uppercase border border-gray-200 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50'; ?>">Next</a>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}

catat_view_once($pdo, 'Pengajuan Pinjaman', 'Membuka halaman Pengajuan Pinjaman');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Pinjaman — Koperasi BSDK</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        /* Toolbar aksi dipindahkan dari header agar judul tidak terdesak */
        .pinjaman-action-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            margin-bottom: 20px;
            background: #fff;
            border: 1px solid #f0f0f0;
        }

        .pinjaman-action-toolbar-copy {
            min-width: 0;
        }

        .pinjaman-action-toolbar-title {
            margin: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #9ca3af;
        }

        .pinjaman-action-toolbar-sub {
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.45;
            color: #6b7280;
        }

        .pinjaman-action-buttons {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            width: min(100%, 520px);
            flex: 0 0 auto;
        }

        .pinjaman-action-btn {
            min-height: 42px;
            padding: 8px 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111;
            font-size: 10px;
            line-height: 1.3;
            font-weight: 900;
            letter-spacing: .07em;
            text-transform: uppercase;
            text-align: center;
            white-space: normal;
            text-decoration: none;
            transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        }

        .pinjaman-action-btn:hover {
            background: #f9fafb;
            border-color: #111;
        }

        .pinjaman-action-btn.primary {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .pinjaman-action-btn.primary:hover {
            background: #262626;
        }

        @media (max-width: 1023px) {
            .pinjaman-action-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .pinjaman-action-buttons {
                width: 100%;
            }
        }

        @media (max-width: 767px) {
            .pinjaman-action-toolbar {
                padding: 14px;
                margin-bottom: 16px;
            }

            .pinjaman-action-buttons {
                grid-template-columns: 1fr 1fr;
            }

            .pinjaman-action-btn {
                min-height: 44px;
                font-size: 9px;
                padding: 8px;
            }

            .pinjaman-action-btn:last-child {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 380px) {
            .pinjaman-action-buttons {
                grid-template-columns: 1fr;
            }

            .pinjaman-action-btn:last-child {
                grid-column: auto;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        @media (min-width: 1024px) {

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

        #toast {
            transition: all .3s cubic-bezier(.34, 1.56, .64, 1);
            transform: translateY(20px);
            opacity: 0;
        }

        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .active-loan-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .active-loan-table th {
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
            padding: 13px 14px;
            font-size: 10px;
            font-weight: 900;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .10em;
            white-space: nowrap;
        }

        .active-loan-table td {
            padding: 15px 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }

        .loan-progress {
            width: 100%;
            height: 7px;
            background: #f3f4f6;
            border: 1px solid #eee;
            overflow: hidden;
        }

        .loan-progress-fill {
            height: 100%;
            background: #111;
        }

        .loan-chip {
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

        @media (max-width: 1023px) {
            .active-loan-desktop {
                display: none;
            }

            .active-loan-mobile {
                display: grid;
                grid-template-columns: 1fr;
                gap: .75rem;
            }
        }

        @media (min-width: 1024px) {
            .active-loan-mobile {
                display: none;
            }
        }


        /* ===== Pengajuan responsive: mobile & tablet ===== */
        .pengajuan-mobile-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        .pengajuan-card {
            min-width: 0;
            overflow: hidden;
        }

        .pengajuan-card-actions {
            flex-wrap: wrap;
        }

        .pengajuan-card-actions>button,
        .pengajuan-card-actions>span {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #modal-detail .pinjaman-detail-panel,
        #modal-cairkan .pinjaman-cairkan-panel {
            scrollbar-width: thin;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            .pengajuan-mobile-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 1rem;
            }

            .pengajuan-card {
                height: 100%;
                display: flex;
                flex-direction: column;
            }

            .pengajuan-card-actions {
                margin-top: auto;
            }
        }

        @media (max-width: 639px) {
            main.content {
                padding: 12px !important;
            }

            .pengajuan-card {
                padding: 14px !important;
            }

            .pengajuan-card .grid.grid-cols-2 {
                gap: 8px !important;
            }

            .pengajuan-card-actions {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px !important;
            }

            .pengajuan-card-actions> :only-child {
                grid-column: 1 / -1;
            }

            .pengajuan-card-actions>span:last-child:nth-child(2),
            .pengajuan-card-actions>button:last-child:nth-child(2) {
                grid-column: auto;
            }

            #toast {
                left: 12px !important;
                right: 12px !important;
                bottom: 78px !important;
                width: auto !important;
            }
        }

        /* Detail: center on tablet/desktop, bottom-sheet on phone */
        @media (max-width: 639px) {

            #modal-detail,
            #modal-cairkan {
                align-items: flex-end !important;
                padding: 0 !important;
            }

            #modal-detail .pinjaman-detail-panel,
            #modal-cairkan .pinjaman-cairkan-panel {
                width: 100%;
                max-width: none;
                max-height: 88vh;
                border-radius: 0;
            }

            #modal-detail-body {
                padding: 16px !important;
            }

            #modal-detail-actions {
                padding: 12px !important;
                position: sticky;
                bottom: 0;
                background: #fff;
                z-index: 2;
                flex-wrap: wrap;
            }

            #modal-detail-actions>* {
                min-width: calc(50% - 6px);
            }
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            #modal-detail .pinjaman-detail-panel {
                max-width: 680px;
                width: calc(100vw - 48px);
            }

            #modal-cairkan .pinjaman-cairkan-panel {
                max-width: 520px;
                width: calc(100vw - 48px);
            }
        }

        .cairkan-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, .35);
            border-top-color: #fff;
            border-radius: 50%;
            display: inline-block;
            animation: cairkanSpin .7s linear infinite;
        }

        @keyframes cairkanSpin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body class="antialiased pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">

        <!-- Aksi laporan dipindahkan dari header agar responsif dan tidak memotong judul -->
        <section class="pinjaman-action-toolbar" aria-label="Aksi laporan pinjaman">
            <div class="pinjaman-action-toolbar-copy">
                <p class="pinjaman-action-toolbar-title">Laporan & Pengaturan</p>
                <p class="pinjaman-action-toolbar-sub">Unduh laporan pengajuan atau konfirmasi, serta buka konfigurasi simpan pinjam.</p>
            </div>
            <div class="pinjaman-action-buttons">
                <a href="pinjaman.php?action=export_pengajuan" class="pinjaman-action-btn">PDF Pengajuan</a>
                <a href="pinjaman.php?action=export_konfirmasi" class="pinjaman-action-btn primary">PDF Setelah Konfirmasi</a>
                <a href="sp.php" class="pinjaman-action-btn">Konfigurasi SP</a>
            </div>
        </section>

        <!-- Konfigurasi aktif -->
        <?php if ($konfig): ?>
            <div class="mb-6 flex flex-wrap gap-4 text-[10px] font-bold text-gray-500 bg-gray-50 border border-gray-100 px-4 py-3">
                <span>Bunga Uang: <strong class="text-black"><?php echo h($konfig['bunga_uang']); ?>% total</strong></span>
                <span>·</span>
                <span>Bunga Barang: <strong class="text-black"><?php echo h($konfig['bunga_barang']); ?>% total</strong></span>
                <span>·</span>
                <span>Tenor Maks Uang: <strong class="text-black"><?php echo h($konfig['tenor_maks_uang']); ?> bln</strong></span>
                <span>·</span>
                <span>Tenor Maks Barang: <strong class="text-black"><?php echo h($konfig['tenor_maks_barang']); ?> bln</strong></span>
            </div>
        <?php endif; ?>

        <?php if ($importPinjamanSuccess): ?>
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-xs font-bold">
                <?php echo h($importPinjamanSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($importPinjamanError): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                <?php echo h($importPinjamanError); ?>
            </div>
        <?php endif; ?>

        <!-- Import Pinjaman Excel -->
        <section class="bg-white border border-gray-100 p-4 md:p-5 mb-6">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Import Excel Pinjaman</p>
                    <h2 class="text-lg font-black mt-1">Import pinjaman uang & barang dari workbook tahunan</h2>
                    <p class="text-xs text-gray-400 mt-1 leading-relaxed">
                        Mendukung sheet yang mengandung kata <strong>PINJAM</strong>, <strong>PINJAMAN UANG</strong>, atau <strong>PINJAMAN BARANG</strong>.
                        Sistem akan mencocokkan nama/NIP ke tabel member, lalu membuat data pinjaman dan jadwal angsuran.
                    </p>
                </div>

                <form method="POST" enctype="multipart/form-data" class="w-full lg:w-[420px] border border-dashed border-gray-300 bg-gray-50 p-4">
                    <input type="hidden" name="action" value="preview_import_pinjaman">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">File Excel (.xlsx)</label>
                    <input type="file" name="file_import_pinjaman" accept=".xlsx" required class="w-full bg-white border border-gray-200 px-3 py-3 text-sm">
                    <button type="submit" class="mt-3 w-full px-5 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Preview Import Pinjaman
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-3 gap-2 mt-4 max-w-md">
                <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                    <p class="text-[9px] font-bold uppercase text-gray-400">Valid</p>
                    <p class="text-lg font-black text-green-600"><?php echo number_format($importPinjamanSummary['valid'] ?? 0); ?></p>
                </div>
                <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                    <p class="text-[9px] font-bold uppercase text-gray-400">Duplikat</p>
                    <p class="text-lg font-black text-amber-600"><?php echo number_format($importPinjamanSummary['duplikat'] ?? 0); ?></p>
                </div>
                <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                    <p class="text-[9px] font-bold uppercase text-gray-400">Gagal</p>
                    <p class="text-lg font-black text-red-600"><?php echo number_format($importPinjamanSummary['gagal'] ?? 0); ?></p>
                </div>
            </div>
        </section>

        <?php if ($importPinjamanPreview): ?>
            <section class="bg-white border border-gray-100 overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Preview Import Pinjaman</p>
                        <p class="text-xs text-gray-400 mt-1">Proses hanya baris yang statusnya valid. Data duplikat dan gagal akan dilewati.</p>
                    </div>
                    <?php if (($importPinjamanSummary['valid'] ?? 0) > 0): ?>
                        <form method="POST" onsubmit="return confirm('Proses semua data pinjaman yang valid? Jadwal angsuran akan dibuat otomatis.');">
                            <input type="hidden" name="action" value="proses_import_pinjaman">
                            <input type="hidden" name="import_pinjaman_payload" value="<?php echo h(base64_encode(json_encode($importPinjamanPreview))); ?>">
                            <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                Proses Import Valid
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:1050px">
                        <thead class="bg-gray-50 border-b border-gray-100">
                            <tr>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Sheet</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Member</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Jenis</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Jumlah</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Tenor</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Angsuran Excel</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($importPinjamanPreview as $importPreviewIndex => $row): ?>
                                <?php
                                $status = (string)($row['status_import'] ?? '');
                                $cls = 'bg-gray-50 text-gray-700 border-gray-200';
                                if ($status === 'valid') {
                                    $cls = 'bg-green-50 text-green-700 border-green-200';
                                } elseif ($status === 'duplikat') {
                                    $cls = 'bg-amber-50 text-amber-700 border-amber-200';
                                } elseif ($status === 'gagal') {
                                    $cls = 'bg-red-50 text-red-700 border-red-200';
                                }
                                ?>
                                <tr class="import-preview-row" data-index="<?php echo (int)$importPreviewIndex; ?>">
                                    <td class="px-4 py-3">
                                        <p class="text-xs font-bold"><?php echo h($row['sheet_name'] ?? '-'); ?></p>
                                        <p class="text-[10px] text-gray-400">Row <?php echo number_format((int)($row['row_no'] ?? 0)); ?> · <?php echo h($row['tahun'] ?? '-'); ?></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-black"><?php echo h($row['member_nama'] ?: ($row['nama'] ?? '-')); ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono"><?php echo h($row['member_kode'] ?: ($row['kode'] ?? '-')); ?></p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-[9px] font-black uppercase px-2 py-1 border border-gray-200 bg-gray-50">
                                            <?php echo h($row['jenis'] ?? '-'); ?>
                                        </span>
                                        <?php if (!empty($row['nama_barang'])): ?>
                                            <p class="text-[10px] text-gray-400 mt-1"><?php echo h($row['nama_barang']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-black"><?php echo rupiah_sp($row['jumlah'] ?? 0); ?></td>
                                    <td class="px-4 py-3 text-center text-sm font-black"><?php echo number_format((int)($row['tenor'] ?? 0)); ?> bln</td>
                                    <td class="px-4 py-3 text-right text-sm font-black"><?php echo rupiah_sp($row['angsuran_total_excel'] ?? 0); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="<?php echo h($cls); ?> border text-[9px] font-black uppercase px-2 py-1">
                                            <?php echo h($row['status_label'] ?? '-'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="import-preview-pagination" class="px-5 py-4 border-t border-gray-100 bg-white flex flex-col md:flex-row md:items-center md:justify-between gap-3"></div>
            </section>
        <?php endif; ?>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-8">
            <?php
            $statusInfo = [
                'pending'     => ['label' => 'Pending', 'color' => 'text-amber-600'],
                'diseleksi'   => ['label' => 'Diseleksi', 'color' => 'text-blue-600'],
                'disetujui'   => ['label' => 'Disetujui', 'color' => 'text-green-600'],
                'ditolak'     => ['label' => 'Ditolak', 'color' => 'text-red-600'],
                'dibatalkan'  => ['label' => 'Batal Pengajuan', 'color' => 'text-red-600'],
                'dicairkan'   => ['label' => 'Dicairkan', 'color' => 'text-purple-600'],
            ];
            foreach ($statusInfo as $st => $info):
                $d = isset($summary[$st]) ? $summary[$st] : ['total' => 0, 'nilai' => 0];
            ?>
                <a href="?status=<?php echo $st; ?>" class="bg-white border border-gray-100 p-4 hover:border-gray-300 transition-all <?php echo $filterStatus === $st ? 'border-black' : ''; ?>">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo $info['label']; ?></p>
                    <p class="text-2xl font-bold <?php echo $info['color']; ?>"><?php echo number_format($d['total']); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?php echo rupiah_sp($d['nilai']); ?></p>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter -->
        <div class="flex flex-col md:flex-row gap-3 mb-4 bg-white border border-gray-100 p-4">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="q-input" value="<?php echo h($q); ?>" placeholder="Cari nama atau kode member..."
                    class="w-full bg-gray-50 border border-gray-100 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:border-black">
            </div>
            <select id="jenis-filter" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:outline-none focus:border-black">
                <option value="">Semua Jenis</option>
                <option value="uang" <?php echo $filterJenis === 'uang'   ? 'selected' : ''; ?>>Uang</option>
                <option value="barang" <?php echo $filterJenis === 'barang' ? 'selected' : ''; ?>>Barang</option>
            </select>
            <select id="status-filter" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:outline-none focus:border-black">
                <option value="">Semua Status</option>
                <?php foreach ($statusInfo as $st => $info): ?>
                    <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo $info['label']; ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-xs text-gray-400 font-medium self-center hidden sm:block">
                <?php echo number_format($totalPengajuan); ?> pengajuan
            </span>
        </div>

        <!-- Peminjam Berjalan -->
        <section class="bg-white border border-gray-100 overflow-hidden mb-8">
            <div class="px-5 py-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Peminjam Berjalan</p>
                    <h2 class="text-lg font-black mt-1">Pinjaman Aktif Member</h2>
                    <p class="text-xs text-gray-400 mt-1"><?php echo number_format($totalPeminjamBerjalan); ?> member/pinjaman sedang berjalan</p>
                </div>
                <a href="angsuran_pinjaman.php" class="inline-flex justify-center px-4 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                    Kelola Angsuran
                </a>
            </div>

            <div class="active-loan-desktop overflow-x-auto no-scrollbar">
                <table class="active-loan-table text-left">
                    <thead>
                        <tr>
                            <th style="width:230px">Member</th>
                            <th style="width:180px">Pinjaman</th>
                            <th style="width:230px">Progress</th>
                            <th style="width:150px" class="text-right">Tagihan Aktif</th>
                            <th style="width:150px" class="text-right">Sisa Tagihan</th>
                            <th style="width:110px">Jatuh Tempo</th>
                            <th style="width:120px" class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($peminjamBerjalan)): ?>
                            <tr>
                                <td colspan="7" class="py-14 text-center text-xs text-gray-400">Belum ada pinjaman aktif berjalan</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($peminjamBerjalan as $pb): ?>
                            <?php
                            $totalAng = (int)($pb['total_angsuran'] ?? $pb['tenor'] ?? 0);
                            $lunasAng = (int)($pb['angsuran_lunas'] ?? 0);
                            $sisaAng = (int)($pb['sisa_angsuran'] ?? max(0, $totalAng - $lunasAng));
                            $progress = progress_pinjaman_sp($lunasAng, $totalAng);
                            $jatuh = $pb['jatuh_tempo_terdekat'] ?? null;
                            $telat = $jatuh && strtotime((string)$jatuh) < strtotime(date('Y-m-d'));
                            ?>
                            <tr>
                                <td>
                                    <div class="border-l-4 border-black pl-3">
                                        <p class="text-sm font-black"><?php echo h($pb['member_nama']); ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo h($pb['member_kode']); ?> · <?php echo h($pb['member_hp']); ?></p>
                                        <p class="text-[10px] text-gray-500 font-black mt-1 uppercase tracking-wider">
                                            Sudah <?php echo number_format((int)($pb['total_pinjaman_member'] ?? 0)); ?>x pinjaman
                                        </p>
                                    </div>
                                </td>

                                <td>
                                    <p class="text-sm font-black">Pinjaman #<?php echo (int)$pb['id']; ?></p>
                                    <p class="text-[10px] text-gray-400"><?php echo ucfirst(h($pb['jenis'])); ?> · <?php echo rupiah_sp($pb['pokok']); ?></p>
                                </td>

                                <td>
                                    <div class="flex items-center justify-between text-[10px] text-gray-400 font-bold mb-1">
                                        <span><?php echo number_format($lunasAng); ?>/<?php echo number_format($totalAng); ?> cicilan lunas</span>
                                        <span><?php echo number_format($progress); ?>%</span>
                                    </div>
                                    <div class="loan-progress">
                                        <div class="loan-progress-fill" style="width: <?php echo (int)$progress; ?>%;"></div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        <span class="loan-chip">Sisa <?php echo number_format($sisaAng); ?>x</span>
                                        <span class="loan-chip">Terbayar <?php echo rupiah_sp($pb['total_terbayar']); ?></span>
                                    </div>
                                </td>

                                <td class="text-right">
                                    <p class="text-sm font-black"><?php echo rupiah_sp($pb['tagihan_terdekat'] ?: $pb['angsuran_total']); ?></p>
                                    <p class="text-[10px] text-gray-400">angsuran aktif</p>
                                </td>

                                <td class="text-right">
                                    <p class="text-sm font-black text-red-600"><?php echo rupiah_sp($pb['sisa_tagihan']); ?></p>
                                    <p class="text-[10px] text-gray-400">belum terbayar</p>
                                </td>

                                <td>
                                    <p class="text-xs font-bold <?php echo $telat ? 'text-red-600' : 'text-gray-700'; ?>">
                                        <?php echo $jatuh ? date('d/m/Y', strtotime((string)$jatuh)) : '-'; ?>
                                    </p>
                                    <p class="text-[10px] text-gray-400"><?php echo $telat ? 'Telat' : 'Terdekat'; ?></p>
                                </td>

                                <td class="text-right">
                                    <a href="angsuran_pinjaman.php?q=<?php echo urlencode((string)$pb['member_kode']); ?>"
                                        class="inline-flex px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="active-loan-mobile p-4">
                <?php if (empty($peminjamBerjalan)): ?>
                    <div class="py-10 text-center text-xs text-gray-400">Belum ada pinjaman aktif berjalan</div>
                <?php endif; ?>

                <?php foreach ($peminjamBerjalan as $pb): ?>
                    <?php
                    $totalAng = (int)($pb['total_angsuran'] ?? $pb['tenor'] ?? 0);
                    $lunasAng = (int)($pb['angsuran_lunas'] ?? 0);
                    $sisaAng = (int)($pb['sisa_angsuran'] ?? max(0, $totalAng - $lunasAng));
                    $progress = progress_pinjaman_sp($lunasAng, $totalAng);
                    $jatuh = $pb['jatuh_tempo_terdekat'] ?? null;
                    $telat = $jatuh && strtotime((string)$jatuh) < strtotime(date('Y-m-d'));
                    ?>
                    <div class="border border-gray-100 p-4 bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-black"><?php echo h($pb['member_nama']); ?></p>
                                <p class="text-[10px] text-gray-400 font-mono"><?php echo h($pb['member_kode']); ?> · <?php echo h($pb['member_hp']); ?></p>
                                <p class="text-[10px] text-gray-500 font-black mt-1 uppercase tracking-wider">
                                    Sudah <?php echo number_format((int)($pb['total_pinjaman_member'] ?? 0)); ?>x pinjaman
                                </p>
                            </div>
                            <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo $telat ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'; ?>">
                                <?php echo $telat ? 'Telat' : 'Aktif'; ?>
                            </span>
                        </div>

                        <div class="mt-4 pt-3 border-t border-gray-100">
                            <p class="text-sm font-black">Pinjaman #<?php echo (int)$pb['id']; ?> · <?php echo rupiah_sp($pb['pokok']); ?></p>
                            <div class="loan-progress mt-2">
                                <div class="loan-progress-fill" style="width: <?php echo (int)$progress; ?>%;"></div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format($lunasAng); ?>/<?php echo number_format($totalAng); ?> lunas · sisa <?php echo number_format($sisaAng); ?>x</p>
                        </div>

                        <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
                            <div class="border border-gray-100 bg-gray-50 p-3">
                                <p class="text-[9px] text-gray-400 font-bold uppercase">Tagihan</p>
                                <p class="font-black"><?php echo rupiah_sp($pb['tagihan_terdekat'] ?: $pb['angsuran_total']); ?></p>
                            </div>
                            <div class="border border-gray-100 bg-gray-50 p-3">
                                <p class="text-[9px] text-gray-400 font-bold uppercase">Sisa</p>
                                <p class="font-black text-red-600"><?php echo rupiah_sp($pb['sisa_tagihan']); ?></p>
                            </div>
                        </div>

                        <a href="angsuran_pinjaman.php?q=<?php echo urlencode((string)$pb['member_kode']); ?>"
                            class="mt-3 w-full inline-flex justify-center px-4 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                            Detail Angsuran
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php sp_render_pagination($pageAktif, $totalPeminjamBerjalan, $perPageAktif, 'page_aktif', 'Pinjaman aktif'); ?>
        </section>

        <!-- DESKTOP: Tabel -->
        <div class="hidden lg:block bg-white border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto no-scrollbar">
                <table class="w-full text-left">
                    <thead class="border-b border-gray-100 bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Jenis</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Keterangan / Keperluan</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Jumlah</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Tenor</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Angsuran Est.</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if (empty($pengajuanList)): ?>
                            <tr>
                                <td colspan="9" class="py-16 text-center text-xs text-gray-400">Tidak ada pengajuan</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pengajuanList as $p):
                                $bungaPct   = $p['jenis'] === 'barang' ? (float)($konfig['bunga_barang'] ?? 1.5) : (float)($konfig['bunga_uang'] ?? 1.0);
                                $pokok      = (int)$p['jumlah'];
                                $tenor      = (int)$p['tenor'];
                                $hitung     = hitung_angsuran_sp($pokok, $tenor, $bungaPct);
                                $angPok     = $hitung['pokok'];
                                $angBng     = $hitung['bunga_bulan'];
                                $angTot     = $hitung['total_bulan'];
                                $statusKey  = strtolower(trim((string)($p['status'] ?? '')));
                                if ($statusKey === '') {
                                    $statusKey = 'dibatalkan';
                                }
                                $badgeClass = [
                                    'pending'     => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'diseleksi'   => 'bg-blue-50 text-blue-700 border-blue-200',
                                    'disetujui'   => 'bg-green-50 text-green-700 border-green-200',
                                    'ditolak'     => 'bg-red-50 text-red-700 border-red-200',
                                    'dibatalkan'  => 'bg-red-50 text-red-700 border-red-200',
                                    'dicairkan'   => 'bg-purple-50 text-purple-700 border-purple-200',
                                ][$statusKey] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold"><?php echo h($p['member_nama']); ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono"><?php echo h($p['member_kode']); ?> · <?php echo h($p['member_hp']); ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase px-2 py-1 <?php echo $p['jenis'] === 'barang' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                                            <?php echo ucfirst(h($p['jenis'])); ?>
                                        </span>
                                        <?php if ($p['nama_barang']): ?>
                                            <p class="text-[10px] text-gray-400 mt-1"><?php echo h($p['nama_barang']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 align-top min-w-[220px]">
                                        <p class="text-[10px] text-gray-700 leading-relaxed font-semibold"><?php echo !empty($p['keperluan']) ? h($p['keperluan']) : '-'; ?></p>
                                        <?php if (!empty($p['catatan_petugas'])): ?>
                                            <div class="mt-2 pt-2 border-t border-gray-100">
                                                <p class="text-[9px] font-black uppercase tracking-wider text-gray-400">Catatan Petugas</p>
                                                <p class="text-[10px] text-gray-600 mt-0.5 leading-relaxed"><?php echo h($p['catatan_petugas']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="font-bold text-sm"><?php echo rupiah_sp($pokok); ?></p>
                                        <?php if ((float)($p['jumlah_disetujui'] ?? 0) > 0): ?>
                                            <p class="text-[10px] text-green-700 font-bold mt-1">ACC <?php echo rupiah_sp($p['jumlah_disetujui']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center text-sm font-bold"><?php echo $tenor; ?> bln</td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm font-bold"><?php echo rupiah_sp($angTot); ?>/bln</p>
                                        <p class="text-[10px] text-gray-400">Pokok <?php echo rupiah_sp($angPok); ?> + Bunga <?php echo rupiah_sp($angBng); ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo $badgeClass; ?>">
                                            <?php echo h(status_pinjaman_label_sp($statusKey)); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-gray-500">
                                        <div class="font-semibold text-gray-700"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></div>
                                        <div class="text-[10px] text-gray-400 mt-1"><?php echo date('H:i', strtotime($p['created_at'])); ?> WIB</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="openDetail(<?php echo (int)$p['id']; ?>)"
                                                class="px-3 py-1.5 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50 transition-all">
                                                Detail
                                            </button>
                                            <?php if ($statusKey === 'pending' || $statusKey === 'diseleksi'): ?>
                                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'disetujui')"
                                                    class="px-3 py-1.5 text-[10px] font-black uppercase bg-green-600 text-white hover:bg-green-700 transition-all">
                                                    ACC
                                                </button>
                                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'ditolak')"
                                                    class="px-3 py-1.5 text-[10px] font-black uppercase border border-red-200 text-red-700 hover:bg-red-50 transition-all">
                                                    Tolak
                                                </button>
                                            <?php elseif ($statusKey === 'disetujui'): ?>
                                                <?php $konfirmasiKeputusan = (string)($p['konfirmasi_keputusan'] ?? 'pending'); ?>
                                                <?php if ($konfirmasiKeputusan === 'ambil'): ?>
                                                    <button onclick="requestCairkan(<?php echo (int)$p['id']; ?>, this)"
                                                        class="px-3 py-1.5 text-[10px] font-black uppercase bg-black text-white hover:bg-gray-800 transition-all">
                                                        Cairkan
                                                    </button>
                                                <?php elseif ($konfirmasiKeputusan === 'tidak_ambil'): ?>
                                                    <span class="px-3 py-1.5 text-[9px] font-black uppercase border border-red-200 bg-red-50 text-red-700">Tidak Diambil</span>
                                                <?php else: ?>
                                                    <span class="px-3 py-1.5 text-[9px] font-black uppercase border border-amber-200 bg-amber-50 text-amber-700">Menunggu Member</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MOBILE: Card -->
        <div class="lg:hidden pengajuan-mobile-grid">
            <?php if (empty($pengajuanList)): ?>
                <div class="py-16 text-center text-xs text-gray-400">Tidak ada pengajuan</div>
            <?php else: ?>
                <?php foreach ($pengajuanList as $p):
                    $bungaPct = $p['jenis'] === 'barang' ? (float)($konfig['bunga_barang'] ?? 1.5) : (float)($konfig['bunga_uang'] ?? 1.0);
                    $pokok    = (int)$p['jumlah'];
                    $tenor    = (int)$p['tenor'];
                    $hitung   = hitung_angsuran_sp($pokok, $tenor, $bungaPct);
                    $angTot   = $hitung['total_bulan'];
                    $statusKey  = strtolower(trim((string)($p['status'] ?? '')));
                    if ($statusKey === '') {
                        $statusKey = 'dibatalkan';
                    }
                    $badgeClass = [
                        'pending'     => 'bg-amber-50 text-amber-700 border-amber-200',
                        'diseleksi'   => 'bg-blue-50 text-blue-700 border-blue-200',
                        'disetujui'   => 'bg-green-50 text-green-700 border-green-200',
                        'ditolak'     => 'bg-red-50 text-red-700 border-red-200',
                        'dibatalkan'  => 'bg-red-50 text-red-700 border-red-200',
                        'dicairkan'   => 'bg-purple-50 text-purple-700 border-purple-200',
                    ][$statusKey] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                ?>
                    <div class="bg-white border border-gray-100 p-4 pengajuan-card">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div>
                                <p class="text-sm font-bold"><?php echo h($p['member_nama']); ?></p>
                                <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo h($p['member_kode']); ?></p>
                                <p class="text-[10px] text-gray-400 mt-1"><?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?> WIB</p>
                            </div>
                            <span class="text-[9px] font-black uppercase px-2 py-1 border flex-shrink-0 <?php echo $badgeClass; ?>">
                                <?php echo h(status_pinjaman_label_sp($statusKey)); ?>
                            </span>
                        </div>
                        <div class="border border-gray-100 bg-gray-50 p-3 mb-3">
                            <p class="text-[9px] font-black uppercase tracking-wider text-gray-400 mb-1">Keterangan / Keperluan</p>
                            <p class="text-xs text-gray-700 leading-relaxed"><?php echo !empty($p['keperluan']) ? h($p['keperluan']) : '-'; ?></p>
                            <?php if (!empty($p['catatan_petugas'])): ?>
                                <p class="text-xs text-gray-700 leading-relaxed mt-2 pt-2 border-t border-gray-200"><strong>Catatan Petugas:</strong> <?php echo h($p['catatan_petugas']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Jenis</p>
                                <p class="text-sm font-bold"><?php echo ucfirst(h($p['jenis'])); ?></p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Jumlah</p>
                                <p class="text-sm font-bold"><?php echo rupiah_sp($pokok); ?></p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Tenor</p>
                                <p class="text-sm font-bold"><?php echo $tenor; ?> bulan</p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Angsuran/bln</p>
                                <p class="text-sm font-bold"><?php echo rupiah_sp($angTot); ?></p>
                            </div>
                        </div>
                        <div class="pengajuan-card-actions flex gap-2 pt-3 border-t border-gray-100">
                            <button onclick="openDetail(<?php echo (int)$p['id']; ?>)"
                                class="flex-1 py-2 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50 transition-all">Detail</button>
                            <?php if ($statusKey === 'pending' || $statusKey === 'diseleksi'): ?>
                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'disetujui')"
                                    class="flex-1 py-2 text-[10px] font-black uppercase bg-green-600 text-white hover:bg-green-700">ACC</button>
                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'ditolak')"
                                    class="flex-1 py-2 text-[10px] font-black uppercase border border-red-200 text-red-700 hover:bg-red-50">Tolak</button>
                            <?php elseif ($statusKey === 'disetujui'): ?>
                                <?php $konfirmasiKeputusan = (string)($p['konfirmasi_keputusan'] ?? 'pending'); ?>
                                <?php if ($konfirmasiKeputusan === 'ambil'): ?>
                                    <button onclick="requestCairkan(<?php echo (int)$p['id']; ?>, this)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase bg-black text-white">Cairkan</button>
                                <?php elseif ($konfirmasiKeputusan === 'tidak_ambil'): ?>
                                    <span class="flex-1 py-2 text-center text-[9px] font-black uppercase border border-red-200 bg-red-50 text-red-700">Tidak Diambil</span>
                                <?php else: ?>
                                    <span class="flex-1 py-2 text-center text-[9px] font-black uppercase border border-amber-200 bg-amber-50 text-amber-700">Menunggu Member</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="mt-3 bg-white border border-gray-100 overflow-hidden">
            <?php sp_render_pagination($pagePengajuan, $totalPengajuan, $perPagePengajuan, 'page_pengajuan', 'Pengajuan pinjaman'); ?>
        </div>

    </main>

    <!-- Modal Detail -->
    <div id="modal-detail" class="fixed inset-0 z-[100] bg-black/40 items-center justify-center p-4" style="display:none">
        <div class="pinjaman-detail-panel bg-white w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="text-xs font-black uppercase tracking-widest">Detail Pengajuan</h2>
                <button onclick="closeDetail()" class="p-2 hover:bg-gray-100">&times;</button>
            </div>
            <div class="px-6 py-5" id="modal-detail-body">
                <p class="text-xs text-gray-400">Memuat...</p>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex gap-3" id="modal-detail-actions"></div>
        </div>
    </div>



    <!-- Modal Konfirmasi Pencairan (non-blocking, mengganti confirm() native) -->
    <div id="modal-cairkan" class="fixed inset-0 z-[160] bg-black/45 items-center justify-center p-4" style="display:none" aria-hidden="true">
        <div class="pinjaman-cairkan-panel bg-white w-full max-w-md shadow-2xl">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Konfirmasi</p>
                    <h3 class="text-sm font-black mt-1">Cairkan Pinjaman</h3>
                </div>
                <button type="button" onclick="closeCairkanModal()" class="w-9 h-9 inline-flex items-center justify-center border border-gray-100 hover:bg-gray-50 text-xl leading-none">&times;</button>
            </div>
            <div class="px-5 py-5">
                <p id="cairkan-confirm-text" class="text-sm text-gray-700 leading-relaxed">Pinjaman akan dicairkan dan angsuran dibuat otomatis.</p>
                <div class="mt-4 border border-amber-200 bg-amber-50 px-3 py-3 text-[11px] leading-relaxed text-amber-800">
                    Pastikan data member dan nominal yang disetujui sudah benar sebelum melanjutkan.
                </div>
            </div>
            <div class="px-5 py-4 border-t border-gray-100 grid grid-cols-2 gap-2 bg-white">
                <button type="button" id="btn-cairkan-batal" onclick="closeCairkanModal()" class="min-h-[42px] border border-gray-200 text-[10px] font-black uppercase tracking-wider hover:bg-gray-50">Batal</button>
                <button type="button" id="btn-cairkan-ok" onclick="prosesCairkan()" class="min-h-[42px] bg-black text-white text-[10px] font-black uppercase tracking-wider hover:bg-gray-800 inline-flex items-center justify-center gap-2">Ya, Cairkan</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 shadow-2xl pointer-events-none">
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

    <script>
        var PENGAJUAN_DATA = <?php echo json_encode(array_column($pengajuanList, null, 'id')); ?>;
        var KONFIG = <?php echo json_encode($konfig ?: []); ?>;


        // Pagination client-side untuk tabel Preview Import (karena datanya berasal dari POST upload).
        (function initImportPreviewPagination() {
            var rows = Array.prototype.slice.call(document.querySelectorAll('.import-preview-row'));
            var holder = document.getElementById('import-preview-pagination');
            if (!rows.length || !holder) return;

            var perPage = 25;
            var page = 1;
            var totalPages = Math.max(1, Math.ceil(rows.length / perPage));

            function render() {
                var start = (page - 1) * perPage;
                var end = start + perPage;
                rows.forEach(function(row, idx) {
                    row.style.display = idx >= start && idx < end ? '' : 'none';
                });

                var html = '<p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Preview import: ' + (start + 1) + '-' + Math.min(end, rows.length) + ' dari ' + rows.length + ' data</p>';
                if (totalPages > 1) {
                    html += '<div class="flex flex-wrap items-center gap-1">';
                    html += '<button type="button" data-page="' + Math.max(1, page - 1) + '" class="import-page-btn px-3 py-2 text-[10px] font-black uppercase border border-gray-200 ' + (page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50') + '">Prev</button>';
                    var from = Math.max(1, page - 2);
                    var to = Math.min(totalPages, page + 2);
                    for (var i = from; i <= to; i++) {
                        html += '<button type="button" data-page="' + i + '" class="import-page-btn px-3 py-2 text-[10px] font-black border ' + (i === page ? 'bg-black text-white border-black' : 'border-gray-200 hover:bg-gray-50') + '">' + i + '</button>';
                    }
                    html += '<button type="button" data-page="' + Math.min(totalPages, page + 1) + '" class="import-page-btn px-3 py-2 text-[10px] font-black uppercase border border-gray-200 ' + (page >= totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50') + '">Next</button>';
                    html += '</div>';
                }
                holder.innerHTML = html;
                Array.prototype.slice.call(holder.querySelectorAll('.import-page-btn')).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        page = parseInt(this.getAttribute('data-page') || '1', 10);
                        render();
                    });
                });
            }

            render();
        })();

        // ── Filter ───────────────────────────────────────────────────────────────────
        var searchTimer;
        document.getElementById('q-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('jenis-filter').addEventListener('change', applyFilter);
        document.getElementById('status-filter').addEventListener('change', applyFilter);

        function applyFilter() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('q-input').value);
            url.searchParams.set('jenis', document.getElementById('jenis-filter').value);
            url.searchParams.set('status', document.getElementById('status-filter').value);
            window.location.href = url.toString();
        }

        // ── Detail ───────────────────────────────────────────────────────────────────
        function openDetail(id) {
            var p = PENGAJUAN_DATA[id];
            if (!p) return;

            var bungaPct = p.jenis === 'barang' ? parseFloat(KONFIG.bunga_barang || 1.5) : parseFloat(KONFIG.bunga_uang || 1.0);
            var jumlahDiajukan = parseInt(p.jumlah || 0);
            var jumlahDisetujui = parseInt(p.jumlah_disetujui || 0);
            var pokok = (p.status === 'disetujui' || p.status === 'dicairkan') && jumlahDisetujui > 0 ? jumlahDisetujui : jumlahDiajukan;
            var tenor = parseInt(p.tenor || 0);
            var angPok = tenor > 0 ? Math.round(pokok / tenor) : 0;
            var totalBunga = Math.round(pokok * bungaPct / 100);
            var angBng = tenor > 0 ? Math.round(totalBunga / tenor) : 0;
            var angTot = angPok + angBng;

            var html = '<div class="space-y-3">';
            html += '<div class="grid grid-cols-2 gap-3">';
            html += box('Member', escHtml(p.member_nama || '-'));
            html += box('Kode', escHtml(p.member_kode || '-'));
            html += box('Jenis Pinjaman', ucFirst(p.jenis));
            html += box('Jumlah Diajukan', rupiah(jumlahDiajukan));
            if (jumlahDisetujui > 0) html += box('Jumlah Disetujui', rupiah(jumlahDisetujui));
            html += box('Tenor', tenor + ' bulan');
            html += box('Angsuran/bln', rupiah(angTot));
            html += box('Bunga', bungaPct + '% total');
            var statusLabelMap = {
                pending: 'Pending',
                diseleksi: 'Diseleksi',
                disetujui: 'Disetujui',
                ditolak: 'Ditolak',
                dibatalkan: 'Batal Pengajuan',
                dicairkan: 'Dicairkan'
            };
            html += box('Status', (String(p.status || '').trim() === '' ? 'Batal Pengajuan' : (statusLabelMap[String(p.status || '').toLowerCase()] || ucFirst(p.status))));
            if (p.nama_barang) html += box('Nama Barang', escHtml(p.nama_barang));
            if (p.keperluan) html += '<div class="col-span-2">' + box('Keperluan', escHtml(p.keperluan)) + '</div>';
            if (p.catatan_petugas) html += '<div class="col-span-2">' + box('Catatan Petugas', escHtml(p.catatan_petugas)) + '</div>';
            html += '</div>';

            // Simulasi angsuran
            html += '<p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mt-4 mb-2">Simulasi Angsuran</p>';
            html += '<div class="overflow-x-auto"><table class="w-full text-xs border border-gray-100">';
            html += '<thead class="bg-gray-50"><tr><th class="px-3 py-2 text-left">Ke</th><th class="px-3 py-2 text-right">Pokok</th><th class="px-3 py-2 text-right">Bunga</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody>';
            for (var i = 1; i <= Math.min(tenor, 6); i++) {
                html += '<tr class="border-t border-gray-50"><td class="px-3 py-1.5">' + i + '</td>';
                html += '<td class="px-3 py-1.5 text-right">' + rupiah(angPok) + '</td>';
                html += '<td class="px-3 py-1.5 text-right">' + rupiah(angBng) + '</td>';
                html += '<td class="px-3 py-1.5 text-right font-bold">' + rupiah(angTot) + '</td></tr>';
            }
            if (tenor > 6) {
                html += '<tr class="border-t border-gray-50"><td colspan="4" class="px-3 py-1.5 text-center text-gray-400">... dan ' + (tenor - 6) + ' angsuran lagi</td></tr>';
            }
            html += '<tr class="border-t border-gray-200 bg-gray-50 font-bold"><td class="px-3 py-2">Total</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(angPok * tenor) + '</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(totalBunga) + '</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(angTot * tenor) + '</td></tr>';
            html += '</tbody></table></div></div>';

            document.getElementById('modal-detail-body').innerHTML = html;

            var actHtml = '<button onclick="closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase border border-gray-200 hover:bg-gray-50">Tutup</button>';
            if (p.status === 'pending' || p.status === 'diseleksi') {
                actHtml += '<button onclick="aksiBulk(' + id + ',\'disetujui\');closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase bg-green-600 text-white hover:bg-green-700">ACC</button>';
                actHtml += '<button onclick="aksiBulk(' + id + ',\'ditolak\');closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase border border-red-200 text-red-700 hover:bg-red-50">Tolak</button>';
            } else if (p.status === 'disetujui') {
                var konf = String(p.konfirmasi_keputusan || 'pending');
                if (konf === 'ambil') {
                    actHtml += '<button onclick="requestCairkan(' + id + ', this)" class="flex-1 py-2.5 text-xs font-bold uppercase bg-black text-white hover:bg-gray-800">Cairkan</button>';
                } else if (konf === 'tidak_ambil') {
                    actHtml += '<span class="flex-1 py-2.5 text-center text-xs font-bold uppercase border border-red-200 bg-red-50 text-red-700">Tidak Diambil Member</span>';
                } else {
                    actHtml += '<span class="flex-1 py-2.5 text-center text-xs font-bold uppercase border border-amber-200 bg-amber-50 text-amber-700">Menunggu Konfirmasi Member</span>';
                }
            }
            document.getElementById('modal-detail-actions').innerHTML = actHtml;
            document.getElementById('modal-detail').style.display = 'flex';
        }

        function closeDetail() {
            document.getElementById('modal-detail').style.display = 'none';
        }

        document.getElementById('modal-detail').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        // ── Aksi ─────────────────────────────────────────────────────────────────────
        async function aksiBulk(id, status) {
            var p = PENGAJUAN_DATA[id] || {};
            var label = status === 'disetujui' ? 'menyetujui' : 'menolak';
            var catatan = '';
            var jumlahDisetujui = 0;

            if (status === 'ditolak') {
                catatan = prompt('Alasan penolakan (opsional):') || '';
            }

            if (status === 'disetujui') {
                var jumlahDiminta = Number(p.jumlah || 0);
                var defaultNominal = Math.round(jumlahDiminta).toLocaleString('id-ID');
                var raw = prompt(
                    'Nominal pengajuan: ' + rupiah(jumlahDiminta) + '\n\nMasukkan nominal yang DISETUJUI:',
                    defaultNominal
                );
                if (raw === null) return;
                jumlahDisetujui = Number(String(raw).replace(/[^0-9]/g, '')) || 0;
                if (jumlahDisetujui <= 0) {
                    showToast('Nominal yang disetujui wajib lebih dari 0.', 'error');
                    return;
                }
                if (jumlahDisetujui > jumlahDiminta) {
                    showToast('Nominal disetujui tidak boleh melebihi nominal pengajuan.', 'error');
                    return;
                }
                if (!confirm('Setujui pengajuan #' + id + ' sebesar ' + rupiah(jumlahDisetujui) + '?')) return;
            } else {
                if (!confirm('Yakin ' + label + ' pengajuan #' + id + '?')) return;
            }

            try {
                var res = await fetch('pinjaman.php?action=seleksi', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        status: status,
                        catatan: catatan,
                        jumlah_disetujui: jumlahDisetujui
                    })
                });
                var data = await res.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success && data.wa_link) {
                    setTimeout(function() {
                        window.open(data.wa_link, '_blank');
                    }, 300);
                }
                if (data.success) setTimeout(function() {
                    location.reload();
                }, 1200);
            } catch (e) {
                showToast('Terjadi kesalahan', 'error');
            }
        }

        var pendingCairkanId = 0;
        var pendingCairkanButton = null;
        var cairkanSedangProses = false;

        function requestCairkan(id, buttonEl) {
            if (cairkanSedangProses) return;
            pendingCairkanId = Number(id || 0);
            pendingCairkanButton = buttonEl || null;
            if (!pendingCairkanId) return;

            var text = document.getElementById('cairkan-confirm-text');
            if (text) text.textContent = 'Cairkan pinjaman #' + pendingCairkanId + '? Angsuran akan dibuat otomatis setelah pencairan berhasil.';

            var modal = document.getElementById('modal-cairkan');
            if (modal) {
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCairkanModal(force) {
            if (cairkanSedangProses && !force) return;
            var modal = document.getElementById('modal-cairkan');
            if (modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }
            document.body.style.overflow = '';
            if (!cairkanSedangProses) {
                pendingCairkanId = 0;
                pendingCairkanButton = null;
            }
        }

        async function prosesCairkan() {
            if (cairkanSedangProses || !pendingCairkanId) return;
            cairkanSedangProses = true;

            var id = pendingCairkanId;
            var okBtn = document.getElementById('btn-cairkan-ok');
            var batalBtn = document.getElementById('btn-cairkan-batal');
            if (okBtn) {
                okBtn.disabled = true;
                okBtn.innerHTML = '<span class="cairkan-spinner"></span><span>Memproses...</span>';
            }
            if (batalBtn) batalBtn.disabled = true;
            if (pendingCairkanButton) {
                pendingCairkanButton.disabled = true;
                pendingCairkanButton.classList.add('opacity-60', 'pointer-events-none');
            }

            // Beri browser kesempatan merender state loading sebelum request/network mulai.
            await new Promise(function(resolve) {
                requestAnimationFrame(function() {
                    setTimeout(resolve, 0);
                });
            });

            try {
                var res = await fetch('pinjaman.php?action=cairkan', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                });
                var raw = await res.text();
                var data;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    throw new Error(raw ? raw.substring(0, 220) : 'Respons server kosong.');
                }

                showToast(data.message || (data.success ? 'Pinjaman berhasil dicairkan.' : 'Pencairan gagal.'), data.success ? 'success' : 'error');

                if (data.success) {
                    closeDetail();
                    closeCairkanModal(true);
                    if (data.wa_link) {
                        setTimeout(function() {
                            window.open(data.wa_link, '_blank');
                        }, 150);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 650);
                    return;
                }
            } catch (e) {
                console.error('Pencairan pinjaman:', e);
                showToast('Terjadi kesalahan: ' + (e.message || 'pencairan gagal'), 'error');
            } finally {
                cairkanSedangProses = false;
                if (okBtn) {
                    okBtn.disabled = false;
                    okBtn.textContent = 'Ya, Cairkan';
                }
                if (batalBtn) batalBtn.disabled = false;
                if (pendingCairkanButton) {
                    pendingCairkanButton.disabled = false;
                    pendingCairkanButton.classList.remove('opacity-60', 'pointer-events-none');
                }
            }
        }

        var modalCairkanEl = document.getElementById('modal-cairkan');
        if (modalCairkanEl) {
            modalCairkanEl.addEventListener('click', function(e) {
                if (e.target === this) closeCairkanModal();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('modal-cairkan');
                if (modal && modal.style.display === 'flex') closeCairkanModal();
            }
        });

        // ── Helpers ──────────────────────────────────────────────────────────────────
        function box(label, val) {
            return '<div class="border border-gray-100 p-3 bg-gray-50"><p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">' + label + '</p><p class="text-sm font-bold">' + val + '</p></div>';
        }

        function rupiah(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function ucFirst(s) {
            s = String(s || '');
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = String(s || '');
            return d.innerHTML;
        }

        var toastTimer;

        function showToast(msg, type) {
            type = type || 'success';
            var t = document.getElementById('toast');
            document.getElementById('toast-msg').textContent = msg;
            t.style.background = type === 'error' ? '#dc2626' : '#111';
            t.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function() {
                t.classList.remove('show');
            }, 3000);
        }
    </script>

</body>

</html>