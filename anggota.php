<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/** @var PDO $pdo */
global $pdo;

if (!function_exists('requireAccess')) {
    function requireAccess(): void {}
}

requireAccess();

if (file_exists(__DIR__ . '/activity_helper.php')) {
    require_once __DIR__ . '/activity_helper.php';
}

$activeMenu = 'anggota';
$pageTitle  = 'Anggota';
$backUrl    = 'dashboard.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$userRole = (string)($_SESSION['user']['role'] ?? 'kasir');

// Akses halaman sudah dikontrol oleh requireAccess() + config_roles.php.
// Role yang diizinkan mengikuti config_roles.php, jadi kasir bisa membuka menu anggota
// jika 'anggota.php' sudah ditambahkan di ROLE_ACCESS['kasir']['pages'].
if (!in_array($userRole, ['admin', 'ksp', 'kasir'], true)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    /** @param mixed $v */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_ag')) {
    /** @param mixed $n */
    function rupiah_ag($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_ag')) {
    /** @param mixed $n */
    function angka_ag($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_ag')) {
    /** @param mixed $v */
    function tanggal_ag($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('table_exists_ag')) {
    function table_exists_ag(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('status_badge_ag')) {
    function status_badge_ag(string $status): string
    {
        $status = strtolower(trim($status));

        if ($status === 'aktif') {
            return 'bg-green-50 text-green-700 border-green-200';
        }

        return 'bg-gray-50 text-gray-700 border-gray-200';
    }
}


if (!function_exists('pagination_url_ag')) {
    function pagination_url_ag(int $page, int $perPage): string
    {
        $query = $_GET;
        $query['page'] = $page;
        $query['per_page'] = $perPage;
        return 'anggota.php?' . http_build_query($query);
    }
}

if (!function_exists('next_member_code_ag')) {
    function next_member_code_ag(PDO $pdo): string
    {
        try {
            $last = $pdo->query("
                SELECT kode
                FROM member
                WHERE kode LIKE 'MBR%'
                ORDER BY CAST(SUBSTRING(kode, 4) AS UNSIGNED) DESC
                LIMIT 1
            ")->fetchColumn();

            $num = 1;
            if ($last && preg_match('/MBR(\d+)/i', (string)$last, $m)) {
                $num = ((int)$m[1]) + 1;
            }

            return 'MBR' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            return 'MBR' . date('His');
        }
    }
}


if (!function_exists('read_member_csv_ag')) {
    function read_member_csv_ag(string $path): array
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
            $tab = substr_count($firstLine, "\t");
            $semicolon = substr_count($firstLine, ';');
            $comma = substr_count($firstLine, ',');

            if ($tab >= $semicolon && $tab >= $comma && $tab > 0) {
                $delimiter = "\t";
            } elseif ($semicolon >= $comma && $semicolon > 0) {
                $delimiter = ';';
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


if (!function_exists('xlsx_col_index_ag')) {
    function xlsx_col_index_ag(string $cellRef): int
    {
        if (!preg_match('/^[A-Z]+/i', (string)$cellRef, $m)) {
            return 0;
        }
        $letters = strtoupper($m[0]);
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $num - 1);
    }
}

if (!function_exists('read_member_xlsx_ag')) {
    function read_member_xlsx_ag(string $path): array
    {
        $rows = [];

        if (!class_exists('ZipArchive')) {
            throw new Exception('File XLSX membutuhkan extension PHP ZipArchive. Aktifkan extension zip di server, atau upload CSV.');
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

        foreach ($sheetNames as $sheetName) {
            $sheetXml = $zip->getFromName($sheetName);
            if ($sheetXml === false) {
                continue;
            }
            $xml = @simplexml_load_string($sheetXml);
            if (!$xml || !isset($xml->sheetData->row)) {
                continue;
            }

            foreach ($xml->sheetData->row as $row) {
                $line = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $idx = xlsx_col_index_ag($ref);
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
                    if (trim(implode('', $full)) !== '') {
                        $rows[] = $full;
                    }
                }
            }
        }

        $zip->close();
        return $rows;
    }
}

if (!function_exists('read_member_rows_ag')) {
    function read_member_rows_ag(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return read_member_xlsx_ag($path);
        }
        if ($ext === 'csv' || $ext === 'txt') {
            return read_member_csv_ag($path);
        }
        throw new Exception('Format file tidak didukung. Gunakan XLSX, CSV, atau TXT.');
    }
}

if (!function_exists('normalize_header_ag')) {
    function normalize_header_ag(string $v): string
    {
        $v = strtolower(trim($v));
        $v = str_replace([' ', '-', '.', '/', '\\'], '_', $v);
        $v = preg_replace('/[^a-z0-9_]+/', '', $v);
        return (string)$v;
    }
}

if (!function_exists('find_column_ag')) {
    function find_column_ag(array $map, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $key = normalize_header_ag($candidate);
            if (isset($map[$key])) {
                return (int)$map[$key];
            }
        }
        return null;
    }
}

if (!function_exists('clean_phone_ag')) {
    function clean_phone_ag(string $v): string
    {
        $v = preg_replace('/[^0-9+]/', '', trim($v));
        return substr((string)$v, 0, 20);
    }
}

if (!function_exists('parse_member_import_ag')) {
    function parse_member_import_ag(string $path, string $filename = ''): array
    {
        $rawRows = read_member_rows_ag($path, $filename);
        if (!$rawRows) {
            return [];
        }

        $header = array_map('normalize_header_ag', $rawRows[0]);
        $map = [];

        foreach ($header as $i => $name) {
            if ($name !== '') {
                $map[$name] = $i;
            }
        }

        $namaIdx = find_column_ag($map, ['nama', 'nama_anggota', 'name', 'member', 'anggota']);
        $hpIdx = find_column_ag($map, ['no_hp', 'hp', 'telepon', 'telp', 'phone', 'nomor_hp', 'no_telp']);
        $kodeIdx = find_column_ag($map, ['kode', 'kode_member', 'id_member', 'no_anggota', 'nomor_anggota', 'nip']);
        $createdIdx = find_column_ag($map, ['created_at', 'tanggal_daftar', 'tgl_daftar', 'tanggal', 'date']);

        $hasHeader = $namaIdx !== null || $hpIdx !== null;
        $start = $hasHeader ? 1 : 0;

        $items = [];

        for ($i = $start; $i < count($rawRows); $i++) {
            $row = $rawRows[$i];

            if (!$hasHeader) {
                $nama = trim((string)($row[1] ?? $row[0] ?? ''));
                $kodeLama = trim((string)($row[0] ?? ''));

                $noHp = '';
                foreach ($row as $cell) {
                    $digits = preg_replace('/\D+/', '', (string)$cell);
                    if (strlen($digits) >= 9 && preg_match('/^(08|628|62|8)/', $digits)) {
                        $noHp = clean_phone_ag((string)$cell);
                        break;
                    }
                }
            } else {
                $nama = trim((string)($row[$namaIdx ?? 0] ?? ''));
                $noHp = $hpIdx !== null ? clean_phone_ag((string)($row[$hpIdx] ?? '')) : '';
                $kodeLama = $kodeIdx !== null ? trim((string)($row[$kodeIdx] ?? '')) : '';
            }

            if ($nama === '' || strtolower($nama) === 'nama') {
                continue;
            }

            $createdAt = date('Y-m-d H:i:s');
            if ($createdIdx !== null && !empty($row[$createdIdx])) {
                $ts = strtotime((string)$row[$createdIdx]);
                if ($ts) {
                    $createdAt = date('Y-m-d H:i:s', $ts);
                }
            }

            $items[] = [
                'row_no' => $i + 1,
                'kode_lama' => $kodeLama,
                'nama' => $nama,
                'no_hp' => $noHp,
                'created_at' => $createdAt,
            ];
        }

        return $items;
    }
}

if (!function_exists('member_exists_ag')) {
    function member_exists_ag(PDO $pdo, string $nama, string $noHp): bool
    {
        $nama = trim($nama);
        $noHp = clean_phone_ag($noHp);

        if ($nama === '') {
            return false;
        }

        // Aturan baru:
        // - Nama sama + nomor HP sama = duplikat.
        // - Nama sama + nomor HP beda = boleh import.
        // - Kalau nomor HP kosong, pakai nama sebagai pengaman duplikat.
        if ($noHp !== '') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM member
                WHERE LOWER(TRIM(nama)) = LOWER(TRIM(:nama))
                  AND TRIM(COALESCE(no_hp, '')) = :no_hp
                LIMIT 1
            ");
            $stmt->execute([
                ':nama' => $nama,
                ':no_hp' => $noHp,
            ]);

            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM member
            WHERE LOWER(TRIM(nama)) = LOWER(TRIM(:nama))
              AND TRIM(COALESCE(no_hp, '')) = ''
            LIMIT 1
        ");
        $stmt->execute([':nama' => $nama]);

        return (int)$stmt->fetchColumn() > 0;
    }
}


$success = '';
$error = '';

$importPreview = [];
$importSummary = [
    'valid' => 0,
    'duplikat' => 0,
    'gagal' => 0,
];

$importToken = '';
$lastImportFailedRows = [];
if (isset($_SESSION['anggota_import_failed_rows']) && is_array($_SESSION['anggota_import_failed_rows'])) {
    $lastImportFailedRows = $_SESSION['anggota_import_failed_rows'];
    unset($_SESSION['anggota_import_failed_rows']);
}
$importLimit = 50;
$importPage = max(1, (int)($_GET['import_page'] ?? 1));
$importPerPageOptions = [25, 50, 100];
$importPerPage = (int)($_GET['import_per_page'] ?? 50);
if (!in_array($importPerPage, $importPerPageOptions, true)) {
    $importPerPage = 50;
}
$importFilter = (string)($_GET['import_filter'] ?? 'semua');
if (!in_array($importFilter, ['semua', 'valid', 'duplikat', 'gagal'], true)) {
    $importFilter = 'semua';
}
$importTotalRows = 0;
$importTotalPages = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'bulk_delete') {
            $ids = $_POST['selected_member'] ?? [];

            if (!is_array($ids) || !$ids) {
                throw new Exception('Pilih minimal satu anggota.');
            }

            $cleanIds = array_values(array_filter(array_map('intval', $ids), static function ($id): bool {
                return $id > 0;
            }));

            if (!$cleanIds) {
                throw new Exception('Data anggota tidak valid.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM member WHERE id IN ($placeholders)");
            $stmt->execute($cleanIds);

            $pdo->commit();

            $success = 'Berhasil menghapus ' . count($cleanIds) . ' anggota.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'delete', 'Anggota', 'Hapus banyak anggota: ' . count($cleanIds) . ' data');
            }
        }

        if ($action === 'bulk_status') {
            $ids = $_POST['selected_member'] ?? [];
            $newStatus = (string)($_POST['bulk_status'] ?? '');

            if (!in_array($newStatus, ['aktif', 'nonaktif'], true)) {
                throw new Exception('Status bulk tidak valid.');
            }

            if (!is_array($ids) || !$ids) {
                throw new Exception('Pilih minimal satu anggota.');
            }

            $cleanIds = array_values(array_filter(array_map('intval', $ids), static function ($id): bool {
                return $id > 0;
            }));

            if (!$cleanIds) {
                throw new Exception('Data anggota tidak valid.');
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE member SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$newStatus], $cleanIds));

            $pdo->commit();

            $success = 'Berhasil mengubah status ' . count($cleanIds) . ' anggota menjadi ' . $newStatus . '.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'update', 'Anggota', 'Bulk status anggota: ' . count($cleanIds) . ' data menjadi ' . $newStatus);
            }
        }

        if ($action === 'preview_import') {
            if (empty($_FILES['file_import']['tmp_name']) || !is_uploaded_file($_FILES['file_import']['tmp_name'])) {
                throw new Exception('File import belum dipilih.');
            }

            $rows = parse_member_import_ag((string)$_FILES['file_import']['tmp_name'], (string)($_FILES['file_import']['name'] ?? ''));

            $fullImportRows = [];

            foreach ($rows as $row) {
                $namaImport = trim((string)($row['nama'] ?? ''));
                $noHpImport = clean_phone_ag((string)($row['no_hp'] ?? ''));
                $row['no_hp'] = $noHpImport;
                $row['error_detail'] = '-';

                if ($namaImport === '') {
                    $row['status_import'] = 'gagal';
                    $row['status_label'] = 'Gagal';
                    $row['error_detail'] = 'Nama kosong / tidak terbaca dari file.';
                    $importSummary['gagal']++;
                } elseif (strlen($namaImport) > 190) {
                    $row['status_import'] = 'gagal';
                    $row['status_label'] = 'Gagal';
                    $row['error_detail'] = 'Nama terlalu panjang, periksa kolom nama di file.';
                    $importSummary['gagal']++;
                } elseif (member_exists_ag($pdo, $namaImport, $noHpImport)) {
                    $row['status_import'] = 'duplikat';
                    $row['status_label'] = 'Duplikat';
                    $row['error_detail'] = 'Member sudah ada di database dengan nama dan nomor HP yang sama.';
                    $importSummary['duplikat']++;
                } else {
                    $row['status_import'] = 'valid';
                    $row['status_label'] = 'Siap import';
                    $row['error_detail'] = '-';
                    $importSummary['valid']++;
                }

                $fullImportRows[] = $row;
            }

            usort($fullImportRows, function ($a, $b) {
                $sa = (string)($a['status_import'] ?? '');
                $sb = (string)($b['status_import'] ?? '');
                $rank = ['valid' => 0, 'duplikat' => 1, 'gagal' => 2];
                $ra = isset($rank[$sa]) ? $rank[$sa] : 9;
                $rb = isset($rank[$sb]) ? $rank[$sb] : 9;
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }
                return strcasecmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
            });

            if (!$fullImportRows) {
                throw new Exception('Tidak ada data anggota yang bisa dibaca dari file import.');
            }

            if (!isset($_SESSION['anggota_import_cache']) || !is_array($_SESSION['anggota_import_cache'])) {
                $_SESSION['anggota_import_cache'] = [];
            }

            $importToken = bin2hex(random_bytes(16));
            $_SESSION['anggota_import_cache'][$importToken] = [
                'rows' => $fullImportRows,
                'summary' => $importSummary,
                'created_at' => time(),
            ];

            $importPreview = $fullImportRows;
            $importPage = 1;

            $success = 'Preview import berhasil. Valid ' . $importSummary['valid'] . ', duplikat ' . $importSummary['duplikat'] . ', gagal ' . $importSummary['gagal'] . '. Gunakan pagination/filter preview untuk cek data sebelum simpan.';
        }

        if ($action === 'process_import') {
            $token = (string)($_POST['import_token'] ?? '');

            if ($token === '' || empty($_SESSION['anggota_import_cache'][$token]['rows'])) {
                throw new Exception('Data import sudah kadaluarsa. Silakan upload ulang file import.');
            }

            $rows = $_SESSION['anggota_import_cache'][$token]['rows'];

            $editIndex = $_POST['edit_index'] ?? [];
            $editNama = $_POST['edit_nama'] ?? [];
            $editNoHp = $_POST['edit_no_hp'] ?? [];
            $selectedImport = $_POST['selected_import'] ?? [];
            $processAllValid = (string)($_POST['process_all_valid'] ?? '0') === '1';
            if (!is_array($selectedImport)) {
                $selectedImport = [];
            }
            $selectedMap = [];
            foreach ($selectedImport as $selectedRaw) {
                $selectedMap[(int)$selectedRaw] = true;
            }
            if ($processAllValid) {
                foreach ($rows as $idxAll => $rowAll) {
                    if (($rowAll['status_import'] ?? '') === 'valid') {
                        $selectedMap[(int)$idxAll] = true;
                    }
                }
            }

            if (is_array($editIndex)) {
                foreach ($editIndex as $iRaw) {
                    $i = (int)$iRaw;
                    if (!isset($rows[$i])) {
                        continue;
                    }

                    if (isset($editNama[$i])) {
                        $rows[$i]['nama'] = trim((string)$editNama[$i]);
                    }

                    if (isset($editNoHp[$i])) {
                        $rows[$i]['no_hp'] = clean_phone_ag((string)$editNoHp[$i]);
                    }
                }
            }

            $berhasil = 0;
            $skip = 0;
            $importProcessFailedRows = [];

            $pdo->beginTransaction();

            $stmtInsert = $pdo->prepare("
                INSERT INTO member
                (
                    kode,
                    nama,
                    no_hp,
                    point,
                    total_belanja,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :kode,
                    :nama,
                    :no_hp,
                    0,
                    0,
                    'aktif',
                    :created_at,
                    NOW()
                )
            ");

            foreach ($rows as $idxRow => $row) {
                $rowNo = (int)($row['row_no'] ?? ($idxRow + 1));
                $namaPreview = trim((string)($row['nama'] ?? ''));
                $noHpPreview = clean_phone_ag((string)($row['no_hp'] ?? ''));

                if (!isset($selectedMap[(int)$idxRow])) {
                    $skip++;
                    $importProcessFailedRows[] = [
                        'row_no' => $rowNo,
                        'nama' => $namaPreview,
                        'no_hp' => $noHpPreview,
                        'status_import' => 'dilewati',
                        'error_detail' => 'Tidak dicentang untuk diimport.',
                    ];
                    continue;
                }

                if (($row['status_import'] ?? '') !== 'valid') {
                    $skip++;
                    $importProcessFailedRows[] = [
                        'row_no' => $rowNo,
                        'nama' => $namaPreview,
                        'no_hp' => $noHpPreview,
                        'status_import' => (string)($row['status_import'] ?? 'gagal'),
                        'error_detail' => (string)($row['error_detail'] ?? 'Data tidak valid saat preview.'),
                    ];
                    continue;
                }

                $nama = $namaPreview;
                $noHp = $noHpPreview;

                if ($nama === '') {
                    $skip++;
                    $importProcessFailedRows[] = [
                        'row_no' => $rowNo,
                        'nama' => $nama,
                        'no_hp' => $noHp,
                        'status_import' => 'gagal',
                        'error_detail' => 'Nama kosong setelah diedit.',
                    ];
                    continue;
                }

                if (member_exists_ag($pdo, $nama, $noHp)) {
                    $skip++;
                    $importProcessFailedRows[] = [
                        'row_no' => $rowNo,
                        'nama' => $nama,
                        'no_hp' => $noHp,
                        'status_import' => 'duplikat',
                        'error_detail' => 'Member sudah ada di database saat proses simpan.',
                    ];
                    continue;
                }

                $createdAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
                $ts = strtotime($createdAt);
                $createdAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

                $kode = next_member_code_ag($pdo);

                try {
                    $stmtInsert->execute([
                        ':kode' => $kode,
                        ':nama' => $nama,
                        ':no_hp' => $noHp,
                        ':created_at' => $createdAt,
                    ]);
                    $berhasil++;
                } catch (Throwable $rowError) {
                    $skip++;
                    $importProcessFailedRows[] = [
                        'row_no' => $rowNo,
                        'nama' => $nama,
                        'no_hp' => $noHp,
                        'status_import' => 'gagal',
                        'error_detail' => $rowError->getMessage(),
                    ];
                }
            }

            $pdo->commit();

            unset($_SESSION['anggota_import_cache'][$token]);

            $importPreview = [];
            $importToken = '';

            $lastImportFailedRows = $importProcessFailedRows;
            $_SESSION['anggota_import_failed_rows'] = $importProcessFailedRows;

            $success = 'Import anggota selesai. Berhasil ' . $berhasil . ' data, dilewati/gagal ' . $skip . ' data.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'import', 'Anggota', 'Import anggota dari file sebanyak ' . $berhasil . ' data');
            }
        }

        if ($action === 'create') {
            $kode = trim((string)($_POST['kode'] ?? ''));
            $nama = trim((string)($_POST['nama'] ?? ''));
            $noHp = trim((string)($_POST['no_hp'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'aktif'));

            if ($kode === '') {
                $kode = next_member_code_ag($pdo);
            }

            if ($nama === '') {
                throw new Exception('Nama anggota wajib diisi.');
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                $status = 'aktif';
            }

            $stmt = $pdo->prepare("
                INSERT INTO member
                (
                    kode,
                    nama,
                    no_hp,
                    point,
                    total_belanja,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :kode,
                    :nama,
                    :no_hp,
                    0,
                    0,
                    :status,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                ':kode' => $kode,
                ':nama' => $nama,
                ':no_hp' => $noHp,
                ':status' => $status,
            ]);

            $success = 'Anggota baru berhasil ditambahkan.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'create', 'Anggota', 'Menambahkan anggota ' . $nama . ' (' . $kode . ')');
            }
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $kode = trim((string)($_POST['kode'] ?? ''));
            $nama = trim((string)($_POST['nama'] ?? ''));
            $noHp = trim((string)($_POST['no_hp'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'aktif'));

            if ($id < 1) {
                throw new Exception('ID anggota tidak valid.');
            }

            if ($kode === '' || $nama === '') {
                throw new Exception('Kode dan nama wajib diisi.');
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                $status = 'aktif';
            }

            $stmt = $pdo->prepare("
                UPDATE member
                SET kode = :kode,
                    nama = :nama,
                    no_hp = :no_hp,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                ':kode' => $kode,
                ':nama' => $nama,
                ':no_hp' => $noHp,
                ':status' => $status,
                ':id' => $id,
            ]);

            $success = 'Data anggota berhasil diperbarui.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'update', 'Anggota', 'Mengubah anggota ' . $nama . ' (' . $kode . ')');
            }
        }

        if ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = (string)($_POST['new_status'] ?? 'aktif');

            if ($id < 1) {
                throw new Exception('ID anggota tidak valid.');
            }

            if (!in_array($newStatus, ['aktif', 'nonaktif'], true)) {
                throw new Exception('Status tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE member
                SET status = :status,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                ':status' => $newStatus,
                ':id' => $id,
            ]);

            $success = 'Status anggota berhasil diperbarui.';

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'update', 'Anggota', 'Mengubah status anggota ID #' . $id . ' menjadi ' . $newStatus);
            }
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}


// Ambil ulang preview import dari session saat pindah halaman/filter preview.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $tokenFromGet = (string)($_GET['import_token'] ?? '');
    if ($tokenFromGet !== '' && !empty($_SESSION['anggota_import_cache'][$tokenFromGet]['rows'])) {
        $cache = $_SESSION['anggota_import_cache'][$tokenFromGet];
        $importToken = $tokenFromGet;
        $importSummary = is_array($cache['summary'] ?? null) ? $cache['summary'] : $importSummary;
        $importPreview = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
    }
}

// Terapkan filter dan pagination preview import tanpa menghapus data asli di session.
$importPreviewAllRows = $importPreview;
if ($importPreviewAllRows) {
    if ($importFilter !== 'semua') {
        $importPreviewAllRows = array_filter($importPreviewAllRows, function ($row) use ($importFilter) {
            return (string)($row['status_import'] ?? '') === $importFilter;
        });
    }

    $importTotalRows = count($importPreviewAllRows);
    $importTotalPages = max(1, (int)ceil($importTotalRows / $importPerPage));
    if ($importPage > $importTotalPages) {
        $importPage = $importTotalPages;
    }
    $importOffset = ($importPage - 1) * $importPerPage;

    // Simpan index asli supaya checkbox/edit tetap mengarah ke data session yang benar.
    $importPreviewPaged = [];
    foreach ($importPreviewAllRows as $idxOriginal => $row) {
        $row['_original_index'] = $idxOriginal;
        $importPreviewPaged[] = $row;
    }
    $importPreview = array_slice($importPreviewPaged, $importOffset, $importPerPage);
}

function import_pagination_url_ag(string $token, int $page, int $perPage, string $filter): string
{
    $query = $_GET;
    $query['import_token'] = $token;
    $query['import_page'] = $page;
    $query['import_per_page'] = $perPage;
    $query['import_filter'] = $filter;
    return 'anggota.php?' . http_build_query($query);
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$offset = ($page - 1) * $perPage;
$totalFiltered = 0;
$totalPages = 1;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(m.kode LIKE :q1 OR m.nama LIKE :q2 OR m.no_hp LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}

if ($statusFilter !== '') {
    $where[] = 'm.status = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$hasPinjaman = table_exists_ag($pdo, 'pinjaman');
$hasAngsuran = table_exists_ag($pdo, 'angsuran_pinjaman');

$summary = [
    'total' => 0,
    'aktif' => 0,
    'nonaktif' => 0,
    'punya_pinjaman' => 0,
    'menunggak' => 0,
    'total_belanja' => 0,
];

$anggotaList = [];
$nextKode = next_member_code_ag($pdo);

try {
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) AS aktif,
            SUM(CASE WHEN status <> 'aktif' OR status IS NULL THEN 1 ELSE 0 END) AS nonaktif,
            COALESCE(SUM(total_belanja), 0) AS total_belanja
        FROM member
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $summary['total'] = (int)($summaryRow['total'] ?? 0);
    $summary['aktif'] = (int)($summaryRow['aktif'] ?? 0);
    $summary['nonaktif'] = (int)($summaryRow['nonaktif'] ?? 0);
    $summary['total_belanja'] = (float)($summaryRow['total_belanja'] ?? 0);

    if ($hasPinjaman) {
        $summary['punya_pinjaman'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT member_id)
            FROM pinjaman
            WHERE status IN ('aktif', 'berjalan')
        ")->fetchColumn();
    }

    if ($hasAngsuran) {
        $summary['menunggak'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT member_id)
            FROM angsuran_pinjaman
            WHERE status NOT IN ('lunas','dibayar','bayar')
              AND jatuh_tempo < CURDATE()
        ")->fetchColumn();
    }

    $selectExtra = "0 AS total_pinjaman_aktif, 0 AS sisa_tagihan, 0 AS angsuran_telat";
    $joinExtra = "";

    if ($hasPinjaman || $hasAngsuran) {
        $selectExtra = "
            COALESCE(pj.total_pinjaman_aktif, 0) AS total_pinjaman_aktif,
            COALESCE(ag.sisa_tagihan, 0) AS sisa_tagihan,
            COALESCE(ag.angsuran_telat, 0) AS angsuran_telat
        ";

        $joinExtra = "
            LEFT JOIN (
                SELECT member_id, COUNT(*) AS total_pinjaman_aktif
                FROM pinjaman
                WHERE status IN ('aktif','berjalan')
                GROUP BY member_id
            ) pj ON pj.member_id = m.id

            LEFT JOIN (
                SELECT
                    member_id,
                    COALESCE(SUM(CASE WHEN status NOT IN ('lunas','dibayar','bayar') THEN jumlah_total ELSE 0 END), 0) AS sisa_tagihan,
                    SUM(CASE WHEN status NOT IN ('lunas','dibayar','bayar') AND jatuh_tempo < CURDATE() THEN 1 ELSE 0 END) AS angsuran_telat
                FROM angsuran_pinjaman
                GROUP BY member_id
            ) ag ON ag.member_id = m.id
        ";
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM member m
        WHERE $whereSql
    ");
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalFiltered / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $stmt = $pdo->prepare("
        SELECT
            m.*,
            $selectExtra
        FROM member m
        $joinExtra
        WHERE $whereSql
        ORDER BY CASE WHEN LOWER(TRIM(COALESCE(m.status,''))) = 'aktif' THEN 0 ELSE 1 END, m.nama ASC, m.id ASC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $anggotaList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data anggota: ' . $e->getMessage();
}

if (function_exists('catat_view_once')) {
    catat_view_once($pdo, 'Anggota', 'Membuka halaman Anggota');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Anggota — Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111;
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
            background: #fafafa;
        }

        .member-table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .member-table th {
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

        .member-table td {
            padding: 15px 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }

        .member-card-list {
            display: none;
        }

        .modal-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-bg.show {
            display: flex;
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

            .main-wrap,
            .content {
                margin-left: 0 !important;
            }

            .main-content {
                padding: 1rem !important;
                padding-bottom: 6rem !important;
            }

            .member-desktop {
                display: none;
            }

            .member-card-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .75rem;
            }
        }

        @media (max-width: 640px) {
            .member-card-list {
                grid-template-columns: 1fr;
            }
        }

        .import-panel {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
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

        @media (max-width: 1023px) {
            .import-panel {
                grid-template-columns: 1fr;
            }
        }


        .check-ag {
            width: 16px;
            height: 16px;
            accent-color: #111;
        }

        .bulk-toolbar-ag {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }

        .preview-input-ag {
            width: 100%;
            background: #fff;
            border: 1px solid #e5e5e5;
            padding: 8px 10px;
            font-size: 12px;
        }

        .preview-input-ag:focus {
            outline: none;
            border-color: #111;
        }

        @media (max-width: 640px) {
            .bulk-toolbar-ag {
                align-items: stretch;
                flex-direction: column;
            }

            .bulk-toolbar-ag>div {
                width: 100%;
            }

            .bulk-toolbar-ag button,
            .bulk-toolbar-ag select {
                width: 100%;
            }
        }


        /* CLEAN TABLE FINAL */
        .member-table {
            min-width: 1120px;
        }

        .member-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .member-table th {
            padding: 11px 12px !important;
            font-size: 9px !important;
        }

        .member-table td {
            padding: 12px 12px !important;
        }

        .member-table .compact-number {
            font-size: 13px;
            font-weight: 900;
            white-space: nowrap;
        }

        .update-clean {
            text-align: center;
            white-space: nowrap;
        }

        .update-clean-date {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            line-height: 1.2;
        }

        .update-clean-time {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 3px;
            line-height: 1.2;
        }

        .member-action-wrap {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .member-action-wrap button,
        .member-action-wrap a {
            white-space: nowrap;
        }

        .smooth-scroll {
            scroll-behavior: smooth;
        }
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <?php require_once __DIR__ . '/navbar.php'; ?>

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

            <?php if (!empty($lastImportFailedRows)): ?>
                <section class="bg-white border border-red-100 overflow-hidden">
                    <div class="px-5 py-4 border-b border-red-100 bg-red-50">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-red-700">Data dilewati / gagal import terakhir</h2>
                        <p class="text-xs text-red-600 mt-1"><?= angka_ag(count($lastImportFailedRows)) ?> data tidak masuk. Tabel ini membantu cek baris, nama, nomor HP, dan alasannya.</p>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:820px">
                            <thead class="bg-gray-50 border-b border-subtle">
                                <tr>
                                    <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Row</th>
                                    <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nama</th>
                                    <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">No HP</th>
                                    <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                    <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Alasan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php foreach (array_slice($lastImportFailedRows, 0, 100) as $fr): ?>
                                    <tr>
                                        <td class="px-5 py-3 text-xs font-mono text-gray-500"><?= angka_ag($fr['row_no'] ?? 0) ?></td>
                                        <td class="px-5 py-3 text-xs font-bold"><?= h($fr['nama'] ?? '-') ?></td>
                                        <td class="px-5 py-3 text-xs text-gray-500"><?= h($fr['no_hp'] ?? '-') ?></td>
                                        <td class="px-5 py-3 text-[10px] font-black uppercase text-red-600"><?= h($fr['status_import'] ?? 'gagal') ?></td>
                                        <td class="px-5 py-3 text-xs text-gray-600"><?= h($fr['error_detail'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($lastImportFailedRows) > 100): ?>
                        <div class="px-5 py-3 bg-gray-50 text-[10px] font-bold uppercase tracking-widest text-gray-400">Ditampilkan 100 data pertama dari <?= angka_ag(count($lastImportFailedRows)) ?> data.</div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="bg-white border border-subtle p-5 md:p-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Data Koperasi</p>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight mt-1">Anggota</h1>
                        <p class="text-xs text-gray-400 mt-2">Kelola data member koperasi berdasarkan tabel member yang sudah tersedia.</p>
                    </div>

                    <button type="button" onclick="openCreateModal()" class="px-5 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                        Tambah Anggota
                    </button>
                </div>
            </section>

            <section class="import-panel">
                <div class="import-card">
                    <div class="mb-4">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Import Anggota Lama</p>
                        <h2 class="text-lg font-black mt-1">Upload Excel / CSV Anggota</h2>
                        <p class="text-xs text-gray-400 mt-1">Cocok untuk data dari aplikasi lama. Bisa XLSX, CSV, atau TXT. Preview semua data dulu sebelum simpan ke database.</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="import-upload">
                        <input type="hidden" name="action" value="preview_import">

                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">
                            File Excel / CSV / TXT
                        </label>

                        <input type="file"
                            name="file_import"
                            accept=".xlsx,.csv,.txt"
                            required
                            class="w-full bg-white border border-gray-200 px-3 py-3 text-sm">

                        <button type="submit"
                            class="w-full mt-4 px-5 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                            Preview Import
                        </button>
                    </form>
                </div>

                <div class="import-card">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Status Preview</p>
                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Valid</p>
                            <p class="text-lg font-black text-green-600"><?= angka_ag($importSummary['valid'] ?? 0) ?></p>
                        </div>
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Duplikat</p>
                            <p class="text-lg font-black text-amber-600"><?= angka_ag($importSummary['duplikat'] ?? 0) ?></p>
                        </div>
                        <div class="border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-[9px] font-bold uppercase text-gray-400">Gagal</p>
                            <p class="text-lg font-black text-red-600"><?= angka_ag($importSummary['gagal'] ?? 0) ?></p>
                        </div>
                    </div>

                    <div class="mt-4 text-[11px] text-gray-500 leading-relaxed bg-gray-50 border border-gray-100 p-3">
                        Data yang diambil dari XLSX/CSV/TXT: <strong>nama</strong>, <strong>no_hp</strong>, kode lama, dan tanggal daftar jika tersedia. Kode member baru tetap dibuat otomatis.
                    </div>
                </div>
            </section>

            <?php if ($importPreview): ?>
                <section class="bg-white border border-subtle overflow-hidden">
                    <form method="POST" onsubmit="return confirm('Import data yang dicentang ke tabel member?');">
                        <input type="hidden" name="action" value="process_import">
                        <input type="hidden" name="import_token" value="<?= h($importToken) ?>">
                        <input type="hidden" name="process_all_valid" value="1">

                        <div class="bulk-toolbar-ag">
                            <div>
                                <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Preview Import</h2>
                                <p class="text-xs text-gray-400 mt-1">Preview memakai pagination agar tidak panjang. Data tetap tersimpan di session sampai diproses.</p>
                                <p class="text-[10px] text-amber-600 font-bold mt-1">Tombol simpan akan memproses semua data valid. Edit hanya berlaku untuk data yang sedang tampil.</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <label class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500">
                                    <input type="checkbox" id="checkAllImport" class="check-ag">
                                    Data Valid
                                </label>

                                <?php if (($importSummary['valid'] ?? 0) > 0): ?>
                                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                        Simpan Semua Valid
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="px-5 py-3 border-b border-subtle bg-gray-50 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div class="text-xs text-gray-500">
                                Menampilkan <strong><?= angka_ag(count($importPreview)) ?></strong> dari <strong><?= angka_ag($importTotalRows) ?></strong> data preview
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <select onchange="window.location.href=this.value" class="bg-white border border-gray-200 px-3 py-2 text-xs font-bold uppercase text-gray-500">
                                    <?php foreach ([25, 50, 100] as $opt): ?>
                                        <option value="<?= h(import_pagination_url_ag($importToken, 1, $opt, $importFilter)) ?>" <?= $importPerPage === $opt ? 'selected' : '' ?>><?= angka_ag($opt) ?> / halaman</option>
                                    <?php endforeach; ?>
                                </select>
                                <select onchange="window.location.href=this.value" class="bg-white border border-gray-200 px-3 py-2 text-xs font-bold uppercase text-gray-500">
                                    <?php foreach (['semua' => 'Semua', 'valid' => 'Valid', 'duplikat' => 'Duplikat', 'gagal' => 'Gagal'] as $keyFilter => $labelFilter): ?>
                                        <option value="<?= h(import_pagination_url_ag($importToken, 1, $importPerPage, $keyFilter)) ?>" <?= $importFilter === $keyFilter ? 'selected' : '' ?>><?= h($labelFilter) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:1100px">
                                <thead class="bg-gray-50 border-b border-subtle">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 w-[44px]">Pilih</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nama</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">No HP</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode Lama</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Alasan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php foreach ($importPreview as $idx => $row): ?>
                                        <?php $originalIdx = (int)($row['_original_index'] ?? $idx); ?>
                                        <?php
                                        $statusImport = (string)($row['status_import'] ?? '');
                                        $canImport = $statusImport === 'valid';
                                        $cls = 'bg-gray-50 text-gray-700 border-gray-200';
                                        if ($statusImport === 'valid') {
                                            $cls = 'bg-green-50 text-green-700 border-green-200';
                                        } elseif ($statusImport === 'duplikat') {
                                            $cls = 'bg-amber-50 text-amber-700 border-amber-200';
                                        } elseif ($statusImport === 'gagal') {
                                            $cls = 'bg-red-50 text-red-700 border-red-200';
                                        }
                                        ?>
                                        <tr class="import-preview-row">
                                            <td class="px-5 py-3 align-top">
                                                <?php if ($canImport): ?>
                                                    <input type="checkbox"
                                                        name="selected_import[]"
                                                        value="<?= h((string)$originalIdx) ?>"
                                                        class="check-ag import-check"
                                                        checked>
                                                <?php endif; ?>

                                                <input type="hidden" name="edit_index[]" value="<?= h((string)$originalIdx) ?>">
                                                <input type="hidden" name="import_status[<?= h((string)$originalIdx) ?>]" value="<?= h($statusImport) ?>">
                                                <input type="hidden" name="import_created_at[<?= h((string)$originalIdx) ?>]" value="<?= h($row['created_at'] ?? date('Y-m-d H:i:s')) ?>">
                                            </td>

                                            <td class="px-5 py-3 align-top">
                                                <input type="text"
                                                    name="edit_nama[<?= h((string)$originalIdx) ?>]"
                                                    value="<?= h($row['nama'] ?? '') ?>"
                                                    class="preview-input-ag"
                                                    <?= $canImport ? '' : 'readonly' ?>>
                                                <p class="text-[10px] text-gray-400 mt-1">Row <?= angka_ag($row['row_no'] ?? 0) ?></p>
                                            </td>

                                            <td class="px-5 py-3 align-top">
                                                <input type="text"
                                                    name="edit_no_hp[<?= h((string)$originalIdx) ?>]"
                                                    value="<?= h($row['no_hp'] ?? '') ?>"
                                                    class="preview-input-ag"
                                                    <?= $canImport ? '' : 'readonly' ?>>
                                            </td>

                                            <td class="px-5 py-3 align-top text-xs text-gray-400">
                                                <?= h($row['kode_lama'] ?? '-') ?>
                                            </td>

                                            <td class="px-5 py-3 align-top text-xs text-gray-500">
                                                <?= h(tanggal_ag($row['created_at'] ?? null)) ?>
                                            </td>

                                            <td class="px-5 py-3 align-top">
                                                <span class="<?= h($cls) ?> border text-[9px] font-bold uppercase px-2 py-1">
                                                    <?= h($row['status_label'] ?? '-') ?>
                                                </span>
                                            </td>

                                            <td class="px-5 py-3 align-top text-xs text-gray-600 max-w-[260px]">
                                                <?= h($row['error_detail'] ?? '-') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($importTotalPages > 1): ?>
                            <div class="px-5 py-4 border-t border-subtle flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white">
                                <div class="text-xs text-gray-400">
                                    Halaman <strong class="text-gray-700"><?= angka_ag($importPage) ?></strong>
                                    dari <strong class="text-gray-700"><?= angka_ag($importTotalPages) ?></strong>
                                    · Total <?= angka_ag($importTotalRows) ?> data preview
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php if ($importPage > 1): ?>
                                        <a href="<?= h(import_pagination_url_ag($importToken, 1, $importPerPage, $importFilter)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">Awal</a>
                                        <a href="<?= h(import_pagination_url_ag($importToken, $importPage - 1, $importPerPage, $importFilter)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">Prev</a>
                                    <?php endif; ?>

                                    <?php for ($pgImp = max(1, $importPage - 2); $pgImp <= min($importTotalPages, $importPage + 2); $pgImp++): ?>
                                        <a href="<?= h(import_pagination_url_ag($importToken, $pgImp, $importPerPage, $importFilter)) ?>" class="px-3 py-2 border text-[10px] font-black uppercase tracking-widest <?= $pgImp === $importPage ? 'bg-black text-white border-black' : 'border-gray-200 hover:bg-gray-50' ?>">
                                            <?= angka_ag($pgImp) ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($importPage < $importTotalPages): ?>
                                        <a href="<?= h(import_pagination_url_ag($importToken, $importPage + 1, $importPerPage, $importFilter)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">Next</a>
                                        <a href="<?= h(import_pagination_url_ag($importToken, $importTotalPages, $importPerPage, $importFilter)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">Akhir</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total</p>
                    <p class="text-2xl font-black text-blue-600"><?= angka_ag($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Semua anggota</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Aktif</p>
                    <p class="text-2xl font-black text-green-600"><?= angka_ag($summary['aktif']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Status aktif</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nonaktif</p>
                    <p class="text-2xl font-black text-gray-600"><?= angka_ag($summary['nonaktif']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Tidak aktif</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Peminjam</p>
                    <p class="text-2xl font-black text-purple-600"><?= angka_ag($summary['punya_pinjaman']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Pinjaman aktif</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Menunggak</p>
                    <p class="text-2xl font-black text-red-600"><?= angka_ag($summary['menunggak']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Lewat tempo</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Belanja</p>
                    <p class="text-xl font-black"><?= rupiah_ag($summary['total_belanja']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Akumulasi POS</p>
                </div>
            </div>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-[2fr_1fr_1fr_auto_auto] gap-3 items-end">
                    <input type="hidden" name="page" value="1">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Anggota</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Nama / kode / nomor HP">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua Status</option>
                            <option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                            <option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Per Halaman</label>
                        <select name="per_page" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25 data</option>
                            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 data</option>
                            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 data</option>
                        </select>
                    </div>

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                        Tampilkan
                    </button>

                    <a href="anggota.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section class="bg-white border border-subtle overflow-hidden">
                <div class="bulk-toolbar-ag">
                    <div>
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Daftar Anggota</h2>
                        <p class="text-xs text-gray-400 mt-1"><?= angka_ag(count($anggotaList)) ?> dari <?= angka_ag($totalFiltered) ?> anggota ditampilkan</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" id="bulkMemberForm" onsubmit="return confirm('Lanjutkan aksi untuk anggota terpilih?');">
                            <input type="hidden" name="action" id="bulkMemberAction" value="">
                        </form>

                        <label class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500">
                            <input type="checkbox" id="checkAllMember" class="check-ag">
                            Pilih Semua
                        </label>

                        <select name="bulk_status" form="bulkMemberForm" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500">
                            <option value="aktif">Aktifkan</option>
                            <option value="nonaktif">Nonaktifkan</option>
                        </select>

                        <button type="submit" form="bulkMemberForm" onclick="document.getElementById('bulkMemberAction').value='bulk_status'" class="px-4 py-2.5 border border-gray-200 text-[10px] font-black uppercase tracking-widest">
                            Ubah Status
                        </button>

                        <button type="submit" form="bulkMemberForm" onclick="document.getElementById('bulkMemberAction').value='bulk_delete'" class="px-4 py-2.5 bg-red-600 text-white text-[10px] font-black uppercase tracking-widest">
                            Hapus Terpilih
                        </button>
                    </div>
                </div>

                <div class="member-desktop overflow-x-auto no-scrollbar smooth-scroll">
                    <table class="member-table text-left">
                        <thead>
                            <tr>
                                <th style="width:42px">Pilih</th>
                                <th style="width:220px">Member</th>
                                <th style="width:155px">Kontak</th>
                                <th style="width:85px" class="text-right">Point</th>
                                <th style="width:135px" class="text-right">Total Belanja</th>
                                <th style="width:120px" class="text-right">Pinjaman</th>
                                <th style="width:135px" class="text-right">Sisa Tagihan</th>
                                <th style="width:90px">Status</th>
                                <th style="width:115px" class="text-center">Update Terakhir</th>
                                <th style="width:230px" class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$anggotaList): ?>
                                <tr>
                                    <td colspan="10" class="py-16 text-center text-xs text-gray-400">
                                        Belum ada data anggota
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($anggotaList as $m): ?>
                                <?php
                                $status = strtolower((string)($m['status'] ?? 'aktif'));
                                $nextStatus = $status === 'aktif' ? 'nonaktif' : 'aktif';
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_member[]" value="<?= (int)$m['id'] ?>" form="bulkMemberForm" class="check-ag member-check">
                                    </td>
                                    <td>
                                        <div class="border-l-4 border-black pl-3">
                                            <p class="text-sm font-black"><?= h($m['nama']) ?></p>
                                            <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?= h($m['kode']) ?></p>
                                        </div>
                                    </td>

                                    <td>
                                        <p class="text-sm font-bold"><?= h($m['no_hp'] ?: '-') ?></p>
                                    </td>

                                    <td class="text-right">
                                        <p class="compact-number"><?= angka_ag($m['point'] ?? 0) ?></p>
                                    </td>

                                    <td class="text-right">
                                        <p class="compact-number"><?= rupiah_ag($m['total_belanja'] ?? 0) ?></p>
                                    </td>

                                    <td class="text-right">
                                        <p class="compact-number"><?= angka_ag($m['total_pinjaman_aktif'] ?? 0) ?></p>
                                        <p class="text-[10px] text-gray-400">pinjaman</p>
                                    </td>

                                    <td class="text-right">
                                        <p class="compact-number text-red-600"><?= rupiah_ag($m['sisa_tagihan'] ?? 0) ?></p>
                                        <?php if ((int)($m['angsuran_telat'] ?? 0) > 0): ?>
                                            <p class="text-[10px] text-red-500"><?= angka_ag($m['angsuran_telat']) ?> telat</p>
                                        <?php else: ?>
                                            <p class="text-[10px] text-gray-400">tidak ada telat</p>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="<?= h(status_badge_ag($status)) ?> border text-[9px] font-black uppercase px-2 py-1">
                                            <?= h($status) ?>
                                        </span>
                                    </td>

                                    <td class="update-clean">
                                        <?php $updateAt = $m['updated_at'] ?? $m['created_at'] ?? null; ?>
                                        <?php if ($updateAt): ?>
                                            <div class="update-clean-date"><?= h(date('d/m/y', strtotime((string)$updateAt))) ?></div>
                                            <div class="update-clean-time"><?= h(date('H:i', strtotime((string)$updateAt))) ?> WIB</div>
                                        <?php else: ?>
                                            <div class="update-clean-date">-</div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-right">
                                        <div class="member-action-wrap">
                                            <button type="button"
                                                onclick='openEditModal(<?= json_encode([
                                                                            'id' => (int)$m['id'],
                                                                            'kode' => (string)$m['kode'],
                                                                            'nama' => (string)$m['nama'],
                                                                            'no_hp' => (string)$m['no_hp'],
                                                                            'status' => (string)$status,
                                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                                Edit
                                            </button>

                                            <a href="pinjaman.php?q=<?= urlencode((string)$m['kode']) ?>" class="px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                                Pinjaman
                                            </a>

                                            <form method="POST" class="inline-block" onsubmit="return confirm('Ubah status anggota ini?');">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= h($nextStatus) ?>">
                                                <button type="submit" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                                    <?= $status === 'aktif' ? 'Nonaktif' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="member-card-list p-4">
                    <?php if (!$anggotaList): ?>
                        <div class="py-10 text-center text-xs text-gray-400">Belum ada data anggota</div>
                    <?php endif; ?>

                    <?php foreach ($anggotaList as $m): ?>
                        <?php
                        $status = strtolower((string)($m['status'] ?? 'aktif'));
                        $nextStatus = $status === 'aktif' ? 'nonaktif' : 'aktif';
                        ?>
                        <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                            <label class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500">
                                <input type="checkbox" name="selected_member[]" value="<?= (int)$m['id'] ?>" form="bulkMemberForm" class="check-ag member-check">
                                Pilih anggota
                            </label>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-black"><?= h($m['nama']) ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?= h($m['kode']) ?> · <?= h($m['no_hp'] ?: '-') ?></p>
                                </div>

                                <span class="<?= h(status_badge_ag($status)) ?> border text-[9px] font-black uppercase px-2 py-1">
                                    <?= h($status) ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Point</span>
                                    <p class="font-black"><?= angka_ag($m['point'] ?? 0) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Belanja</span>
                                    <p class="font-black"><?= rupiah_ag($m['total_belanja'] ?? 0) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Pinjaman Aktif</span>
                                    <p class="font-black"><?= angka_ag($m['total_pinjaman_aktif'] ?? 0) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Sisa Tagihan</span>
                                    <p class="font-black text-red-600"><?= rupiah_ag($m['sisa_tagihan'] ?? 0) ?></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle">
                                <button type="button"
                                    onclick='openEditModal(<?= json_encode([
                                                                'id' => (int)$m['id'],
                                                                'kode' => (string)$m['kode'],
                                                                'nama' => (string)$m['nama'],
                                                                'no_hp' => (string)$m['no_hp'],
                                                                'status' => (string)$status,
                                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest">
                                    Edit
                                </button>

                                <a href="pinjaman.php?q=<?= urlencode((string)$m['kode']) ?>" class="px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest text-center">
                                    Pinjaman
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="px-5 py-4 border-t border-subtle flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white">
                        <div class="text-xs text-gray-400">
                            Halaman <strong class="text-gray-700"><?= angka_ag($page) ?></strong>
                            dari <strong class="text-gray-700"><?= angka_ag($totalPages) ?></strong>
                            · Total <?= angka_ag($totalFiltered) ?> anggota
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="<?= h(pagination_url_ag(1, $perPage)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                    Awal
                                </a>
                                <a href="<?= h(pagination_url_ag($page - 1, $perPage)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                    Prev
                                </a>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>

                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <a href="<?= h(pagination_url_ag($p, $perPage)) ?>"
                                    class="px-3 py-2 border text-[10px] font-black uppercase tracking-widest <?= $p === $page ? 'bg-black text-white border-black' : 'border-gray-200 hover:bg-gray-50' ?>">
                                    <?= angka_ag($p) ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= h(pagination_url_ag($page + 1, $perPage)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                    Next
                                </a>
                                <a href="<?= h(pagination_url_ag($totalPages, $perPage)) ?>" class="px-3 py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                    Akhir
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <div id="modal-anggota" class="modal-bg">
        <div class="bg-white w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 id="modal-title" class="text-xs font-black uppercase tracking-widest">Tambah Anggota</h2>
                <button type="button" onclick="closeModal()" class="p-2 hover:bg-gray-100 text-xl leading-none">&times;</button>
            </div>

            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" id="form-action" value="create">
                <input type="hidden" name="id" id="form-id" value="">

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kode Member</label>
                    <input type="text" name="kode" id="form-kode" value="<?= h($nextKode) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    <p class="text-[10px] text-gray-400 mt-1">Boleh dikosongkan saat tambah, sistem akan isi otomatis.</p>
                </div>

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Nama</label>
                    <input type="text" name="nama" id="form-nama" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" required>
                </div>

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Nomor HP</label>
                    <input type="text" name="no_hp" id="form-nohp" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                </div>

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                    <select name="status" id="form-status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-4 border-t border-gray-100">
                    <button type="button" onclick="closeModal()" class="flex-1 py-3 border border-gray-200 text-[10px] font-black uppercase tracking-widest">
                        Batal
                    </button>

                    <button type="submit" class="flex-1 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                        Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modal-title').textContent = 'Tambah Anggota';
            document.getElementById('form-action').value = 'create';
            document.getElementById('form-id').value = '';
            document.getElementById('form-kode').value = '<?= h($nextKode) ?>';
            document.getElementById('form-nama').value = '';
            document.getElementById('form-nohp').value = '';
            document.getElementById('form-status').value = 'aktif';
            document.getElementById('modal-anggota').classList.add('show');
        }

        function openEditModal(data) {
            document.getElementById('modal-title').textContent = 'Edit Anggota';
            document.getElementById('form-action').value = 'update';
            document.getElementById('form-id').value = data.id || '';
            document.getElementById('form-kode').value = data.kode || '';
            document.getElementById('form-nama').value = data.nama || '';
            document.getElementById('form-nohp').value = data.no_hp || '';
            document.getElementById('form-status').value = data.status || 'aktif';
            document.getElementById('modal-anggota').classList.add('show');
        }

        function closeModal() {
            document.getElementById('modal-anggota').classList.remove('show');
        }

        document.getElementById('modal-anggota').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        var checkAllImport = document.getElementById('checkAllImport');
        if (checkAllImport) {
            checkAllImport.addEventListener('change', function() {
                document.querySelectorAll('.import-check').forEach(function(cb) {
                    cb.checked = checkAllImport.checked;
                });
            });
        }

        var checkAllMember = document.getElementById('checkAllMember');
        if (checkAllMember) {
            checkAllMember.addEventListener('change', function() {
                document.querySelectorAll('.member-check').forEach(function(cb) {
                    cb.checked = checkAllMember.checked;
                });
            });
        }
    </script>

</body>

</html>