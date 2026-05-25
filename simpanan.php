<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
require_once 'activity_helper.php';

$activeMenu = 'simpanan';
$pageTitle  = 'Simpanan';
$backUrl    = 'dashboard.php';

requireAccess();

if (!has_role('admin', 'ksp')) {
    header('Location: dashboard.php');
    exit;
}

// ==========================================================
// HELPER
// ==========================================================
if (!function_exists('h')) {
    /** @param mixed $v */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_sp')) {
    /** @param mixed $n */
    function rupiah_sp($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_sp')) {
    /** @param mixed $n */
    function angka_sp($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('json_response_sp')) {
    function json_response_sp(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('normal_nama_sp')) {
    function normal_nama_sp(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = str_replace(['’', '`'], "'", $name);
        $name = preg_replace('/\([^)]*\)/', ' ', $name) ?: $name; // buang keterangan seperti (pensiun)
        $name = preg_replace('/[^A-Z0-9]+/u', ' ', $name) ?: $name;
        $name = preg_replace('/\s+/', ' ', $name) ?: $name;
        return trim($name);
    }
}

if (!function_exists('nama_key_sp')) {
    function nama_key_sp(string $name): string
    {
        $name = normal_nama_sp($name);
        if ($name === '') {
            return '';
        }

        $gelar = [
            'A',
            'AB',
            'AG',
            'AK',
            'AMD',
            'AMDKB',
            'B',
            'C',
            'DR',
            'DRS',
            'DRA',
            'DRG',
            'H',
            'HJ',
            'IR',
            'M',
            'MA',
            'MAG',
            'MAK',
            'MHI',
            'MH',
            'MHUM',
            'MKN',
            'MM',
            'MMPD',
            'MMSI',
            'MPD',
            'MSI',
            'PSI',
            'S',
            'SAG',
            'SCOM',
            'SE',
            'SH',
            'SHI',
            'SHUM',
            'SI',
            'SKOM',
            'SOS',
            'SPD',
            'SPSI',
            'SS',
            'SSI',
            'ST',
            'PENSIUN',
            'N'
        ];
        $stop = array_fill_keys($gelar, true);

        $tokens = explode(' ', $name);
        $clean = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || isset($stop[$token])) {
                continue;
            }
            // Buang token gelar gabungan umum, contoh SHMH, SHMHUM, SEMAK.
            if (preg_match('/^(S|M)?(H|HUM|HI|AG|PD|SI|M|AK|KOM|SOS|T|S|PSI)+$/', $token)) {
                continue;
            }
            $clean[] = $token;
        }

        return trim(implode(' ', $clean));
    }
}

if (!function_exists('nama_match_score_sp')) {
    function nama_match_score_sp(string $importName, string $dbName): int
    {
        $a = nama_key_sp($importName);
        $b = nama_key_sp($dbName);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }

        $ta = array_values(array_unique(array_filter(explode(' ', $a))));
        $tb = array_values(array_unique(array_filter(explode(' ', $b))));
        if (!$ta || !$tb) {
            return 0;
        }

        $intersect = array_intersect($ta, $tb);
        $matchCount = count($intersect);
        $minCount = min(count($ta), count($tb));
        $maxCount = max(count($ta), count($tb));

        // Nama satu kata hanya boleh cocok kalau benar-benar sama supaya tidak salah orang.
        if ($minCount <= 1) {
            return ($matchCount === 1 && $a === $b) ? 100 : 0;
        }

        if ($matchCount === $minCount && $minCount >= 2) {
            return 95;
        }

        $ratio = $matchCount / max(1, $maxCount);
        if ($ratio >= 0.85 && $matchCount >= 2) {
            return 90;
        }
        if ($ratio >= 0.70 && $matchCount >= 3) {
            return 80;
        }

        return 0;
    }
}

if (!function_exists('bulan_nama_sp')) {
    function bulan_nama_sp(int $bulan): string
    {
        $map = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return $map[$bulan] ?? '-';
    }
}

if (!function_exists('coa_id_by_kode_sp')) {
    function coa_id_by_kode_sp(PDO $pdo, string $kode, string $nama = '', string $kategori = '', string $subkategori = ''): int
    {
        $stmt = $pdo->prepare("SELECT id FROM coa WHERE kode = :kode LIMIT 1");
        $stmt->execute([':kode' => $kode]);
        $id = (int)$stmt->fetchColumn();

        if ($id > 0) {
            return $id;
        }

        if ($nama === '' || $kategori === '') {
            throw new Exception('Akun COA ' . $kode . ' belum tersedia.');
        }

        $stmtInsert = $pdo->prepare("\n            INSERT INTO coa (kode, nama, kategori, subkategori, is_active, created_at)\n            VALUES (:kode, :nama, :kategori, :subkategori, 1, NOW())\n        ");
        $stmtInsert->execute([
            ':kode' => $kode,
            ':nama' => $nama,
            ':kategori' => $kategori,
            ':subkategori' => $subkategori,
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('auto_jurnal_simpanan_sp')) {
    function auto_jurnal_simpanan_sp(PDO $pdo, int $simpananId, int $memberId, string $jenis, float $jumlah, int $bulan, int $tahun, int $userId, string $keterangan = ''): void
    {
        if ($jumlah <= 0 || $bulan < 1 || $bulan > 12 || $tahun < 2000) {
            return;
        }

        $jenis = strtolower(trim($jenis));
        $akunKas = coa_id_by_kode_sp($pdo, '101', 'Kas', 'aktiva', 'Aktiva Lancar');

        $akunSimpananMap = [
            'pokok' => ['kode' => '201', 'nama' => 'Simpanan Pokok Member'],
            'wajib' => ['kode' => '202', 'nama' => 'Simpanan Wajib Member'],
            'sukarela' => ['kode' => '203', 'nama' => 'Simpanan Sukarela Member'],
        ];

        if (!isset($akunSimpananMap[$jenis])) {
            return;
        }

        $akunSimpanan = coa_id_by_kode_sp($pdo, $akunSimpananMap[$jenis]['kode'], $akunSimpananMap[$jenis]['nama'], 'kewajiban', 'Kewajiban');
        $tanggal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $ketJurnal = 'Simpanan ' . ucfirst($jenis) . ' member #' . $memberId . ' - ' . bulan_nama_sp($bulan) . ' ' . $tahun;
        if ($keterangan !== '') {
            $ketJurnal .= ' - ' . $keterangan;
        }

        $cek = $pdo->prepare("\n            SELECT id FROM jurnal_umum\n            WHERE ref_tabel = 'simpanan' AND ref_id = :ref_id\n            LIMIT 1\n        ");
        $cek->execute([':ref_id' => $simpananId]);
        $jurnalLamaId = (int)$cek->fetchColumn();

        if ($jurnalLamaId > 0) {
            $hapusDetail = $pdo->prepare("DELETE FROM jurnal_detail WHERE jurnal_id = :id");
            $hapusDetail->execute([':id' => $jurnalLamaId]);

            $updateJurnal = $pdo->prepare("\n                UPDATE jurnal_umum\n                SET tanggal = :tanggal, keterangan = :keterangan\n                WHERE id = :id\n                LIMIT 1\n            ");
            $updateJurnal->execute([
                ':tanggal' => $tanggal,
                ':keterangan' => $ketJurnal,
                ':id' => $jurnalLamaId,
            ]);
            $jurnalId = $jurnalLamaId;
        } else {
            $kodeJurnal = 'JS-' . date('YmdHis') . '-' . $simpananId;
            $insertJurnal = $pdo->prepare("\n                INSERT INTO jurnal_umum (tanggal, kode_jurnal, keterangan, ref_tabel, ref_id)\n                VALUES (:tanggal, :kode_jurnal, :keterangan, 'simpanan', :ref_id)\n            ");
            $insertJurnal->execute([
                ':tanggal' => $tanggal,
                ':kode_jurnal' => $kodeJurnal,
                ':keterangan' => $ketJurnal,
                ':ref_id' => $simpananId,
            ]);
            $jurnalId = (int)$pdo->lastInsertId();
        }

        $insertDetail = $pdo->prepare("\n            INSERT INTO jurnal_detail (jurnal_id, coa_id, debit, kredit)\n            VALUES (:jurnal_id, :coa_id, :debit, :kredit)\n        ");

        $insertDetail->execute([
            ':jurnal_id' => $jurnalId,
            ':coa_id' => $akunKas,
            ':debit' => $jumlah,
            ':kredit' => 0,
        ]);

        $insertDetail->execute([
            ':jurnal_id' => $jurnalId,
            ':coa_id' => $akunSimpanan,
            ':debit' => 0,
            ':kredit' => $jumlah,
        ]);
    }
}

if (!function_exists('find_member_id_by_name_sp')) {
    function find_member_id_by_name_sp(PDO $pdo, string $nama): int
    {
        $nama = trim($nama);
        if ($nama === '') {
            return 0;
        }

        // 1) Cocok persis, tidak peduli huruf besar/kecil dan spasi depan/belakang.
        $stmt = $pdo->prepare("
            SELECT id
            FROM member
            WHERE UPPER(TRIM(nama)) = UPPER(TRIM(:nama))
            LIMIT 1
        ");
        $stmt->execute([':nama' => $nama]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        // 2) Cocok berdasarkan nama yang sudah dinormalisasi: gelar/titik/koma/spasi diabaikan.
        $importKey = nama_key_sp($nama);
        if ($importKey === '') {
            return 0;
        }

        $bestId = 0;
        $bestScore = 0;
        $secondScore = 0;

        $stmtAll = $pdo->query("SELECT id, nama FROM member");
        while ($row = $stmtAll->fetch(PDO::FETCH_ASSOC)) {
            $dbNama = (string)($row['nama'] ?? '');
            $score = nama_match_score_sp($nama, $dbNama);
            if ($score > $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $score;
                $bestId = (int)$row['id'];
            } elseif ($score > $secondScore) {
                $secondScore = $score;
            }
        }

        // Ambil hanya kalau match kuat dan tidak ambigu.
        if ($bestScore >= 90 && ($bestScore - $secondScore) >= 5) {
            return $bestId;
        }

        return 0;
    }
}

if (!function_exists('upsert_simpanan_sp')) {
    function upsert_simpanan_sp(PDO $pdo, int $memberId, string $jenis, float $jumlah, int $bulan, int $tahun, string $keterangan, int $importId, int $userId): int
    {
        if ($memberId <= 0 || $jumlah <= 0 || $bulan < 1 || $bulan > 12 || $tahun < 2000) {
            return 0;
        }

        $jenis = strtolower(trim($jenis));
        if (!in_array($jenis, ['pokok', 'wajib', 'sukarela'], true)) {
            return 0;
        }

        $find = $pdo->prepare("\n            SELECT id\n            FROM simpanan\n            WHERE member_id = :mid AND jenis = :jenis AND bulan = :bulan AND tahun = :tahun\n            LIMIT 1\n        ");
        $find->execute([
            ':mid' => $memberId,
            ':jenis' => $jenis,
            ':bulan' => $bulan,
            ':tahun' => $tahun,
        ]);
        $simpananId = (int)$find->fetchColumn();

        if ($simpananId > 0) {
            $update = $pdo->prepare("\n                UPDATE simpanan\n                SET jumlah = :jumlah, keterangan = :ket, import_id = :iid\n                WHERE id = :id\n                LIMIT 1\n            ");
            $update->execute([
                ':jumlah' => $jumlah,
                ':ket' => $keterangan,
                ':iid' => $importId ?: null,
                ':id' => $simpananId,
            ]);
        } else {
            $insert = $pdo->prepare("\n                INSERT INTO simpanan (member_id, jenis, jumlah, bulan, tahun, keterangan, import_id)\n                VALUES (:mid, :jenis, :jumlah, :bulan, :tahun, :ket, :iid)\n            ");
            $insert->execute([
                ':mid' => $memberId,
                ':jenis' => $jenis,
                ':jumlah' => $jumlah,
                ':bulan' => $bulan,
                ':tahun' => $tahun,
                ':ket' => $keterangan,
                ':iid' => $importId ?: null,
            ]);
            $simpananId = (int)$pdo->lastInsertId();
        }

        auto_jurnal_simpanan_sp($pdo, $simpananId, $memberId, $jenis, $jumlah, $bulan, $tahun, $userId, $keterangan);
        return $simpananId;
    }
}

// ==========================================================
// FLASH
// ==========================================================
$error = '';
$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ==========================================================
// IMPORT JSON DARI EXCEL FORMAT PERHITUNGAN 31 DESEMBER 2025
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') !== false) {
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            json_response_sp(['ok' => false, 'message' => 'Payload tidak valid.'], 400);
        }

        $action = (string)($payload['action'] ?? '');
        if ($action !== 'import_excel_simpanan_2025') {
            json_response_sp(['ok' => false, 'message' => 'Action tidak valid.'], 400);
        }

        $tahun = (int)($payload['tahun'] ?? 0);
        $filename = trim((string)($payload['filename'] ?? 'import_simpanan.xlsx'));
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        // Fallback aman: kalau client belum mengirim tahun valid, ambil dari nama file.
        if ($tahun < 2000 && preg_match('/(20\d{2})/', $filename, $m)) {
            $tahun = (int)$m[1];
        }

        if ($tahun < 2000) {
            json_response_sp(['ok' => false, 'message' => 'Tahun tidak valid.'], 422);
        }
        if (!$rows) {
            json_response_sp(['ok' => false, 'message' => 'Tidak ada data simpanan yang bisa diimport.'], 422);
        }

        $userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
        $totalMasuk = 0;
        $totalLewat = 0;
        $totalMemberTidakKetemu = 0;
        $jenisCount = ['pokok' => 0, 'wajib' => 0, 'sukarela' => 0];
        $logPreview = [];
        $failedRows = [];

        try {
            $pdo->beginTransaction();

            $importId = 0;
            try {
                $logInsert = $pdo->prepare("\n                    INSERT INTO simpanan_import\n                    (filename, sheet_name, bulan, tahun, jumlah_baris, status, imported_by)\n                    VALUES (:fn, :sheet, 0, :tahun, 0, 'proses', :uid)\n                ");
                $logInsert->execute([
                    ':fn' => $filename,
                    ':sheet' => 'Perhitungan Simpanan Tahunan',
                    ':tahun' => $tahun,
                    ':uid' => $userId ?: null,
                ]);
                $importId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                // Log import tidak wajib. Import tetap jalan jika tabel log berbeda.
                $importId = 0;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $nama = trim((string)($row['nama'] ?? ''));
                $jenis = strtolower(trim((string)($row['jenis'] ?? '')));
                $bulan = (int)($row['bulan'] ?? 0);
                $jumlah = (float)($row['jumlah'] ?? 0);
                $sheet = trim((string)($row['sheet'] ?? ''));

                if ($nama === '' || !in_array($jenis, ['pokok', 'wajib', 'sukarela'], true) || $bulan < 1 || $bulan > 12 || $jumlah <= 0) {
                    $totalLewat++;
                    $alasanGagal = [];
                    if ($nama === '') {
                        $alasanGagal[] = 'Nama kosong';
                    }
                    if (!in_array($jenis, ['pokok', 'wajib', 'sukarela'], true)) {
                        $alasanGagal[] = 'Jenis simpanan tidak valid';
                    }
                    if ($bulan < 1 || $bulan > 12) {
                        $alasanGagal[] = 'Bulan tidak valid';
                    }
                    if ($jumlah <= 0) {
                        $alasanGagal[] = 'Jumlah kosong / 0';
                    }
                    $failedRows[] = [
                        'nama' => $nama !== '' ? $nama : '-',
                        'jenis' => $jenis !== '' ? $jenis : '-',
                        'bulan' => $bulan,
                        'bulan_label' => ($bulan >= 1 && $bulan <= 12) ? bulan_nama_sp($bulan) : '-',
                        'jumlah' => $jumlah,
                        'jumlah_label' => rupiah_sp($jumlah),
                        'sheet' => $sheet !== '' ? $sheet : '-',
                        'alasan' => implode(', ', $alasanGagal),
                    ];
                    continue;
                }

                $memberId = find_member_id_by_name_sp($pdo, $nama);
                if ($memberId <= 0) {
                    $totalMemberTidakKetemu++;
                    $failedRows[] = [
                        'nama' => $nama,
                        'jenis' => $jenis,
                        'bulan' => $bulan,
                        'bulan_label' => bulan_nama_sp($bulan),
                        'jumlah' => $jumlah,
                        'jumlah_label' => rupiah_sp($jumlah),
                        'sheet' => $sheet !== '' ? $sheet : '-',
                        'alasan' => 'Member tidak ditemukan di database',
                    ];
                    if (count($logPreview) < 20) {
                        $logPreview[] = 'Member tidak ditemukan: ' . $nama . ' (' . ucfirst($jenis) . ' ' . bulan_nama_sp($bulan) . ' ' . rupiah_sp($jumlah) . ')';
                    }
                    continue;
                }

                $ket = trim($sheet . ' - Import Excel ' . $filename);
                $simpananId = upsert_simpanan_sp($pdo, $memberId, $jenis, $jumlah, $bulan, $tahun, $ket, $importId, $userId);
                if ($simpananId > 0) {
                    $totalMasuk++;
                    $jenisCount[$jenis]++;
                } else {
                    $totalLewat++;
                    $failedRows[] = [
                        'nama' => $nama,
                        'jenis' => $jenis,
                        'bulan' => $bulan,
                        'bulan_label' => bulan_nama_sp($bulan),
                        'jumlah' => $jumlah,
                        'jumlah_label' => rupiah_sp($jumlah),
                        'sheet' => $sheet !== '' ? $sheet : '-',
                        'alasan' => 'Data dilewati saat simpan / update',
                    ];
                }
            }

            if ($importId > 0) {
                try {
                    $updateLog = $pdo->prepare("UPDATE simpanan_import SET jumlah_baris = :jml, status = 'selesai' WHERE id = :id");
                    $updateLog->execute([':jml' => $totalMasuk, ':id' => $importId]);
                } catch (Throwable $e) {
                    // abaikan
                }
            }

            $pdo->commit();

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'import', 'Simpanan', 'Import Excel simpanan tahunan ' . $tahun . ' - ' . $totalMasuk . ' data');
            }

            $msg = 'Import selesai. Masuk ' . $totalMasuk . ' data. Pokok ' . $jenisCount['pokok'] . ', Wajib ' . $jenisCount['wajib'] . ', Sukarela ' . $jenisCount['sukarela'] . '. Gagal/lewat ' . count($failedRows) . ' data. Tidak ketemu member ' . $totalMemberTidakKetemu . '.';
            $_SESSION['flash_success'] = $msg;
            json_response_sp([
                'ok' => true,
                'message' => $msg,
                'total_masuk' => $totalMasuk,
                'tidak_ketemu' => $totalMemberTidakKetemu,
                'lewat' => $totalLewat,
                'sample_log' => $logPreview,
                'failed_rows' => $failedRows,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response_sp(['ok' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 500);
        }
    }
}

// ==========================================================
// QUERY DATA UNTUK TAMPILAN
// ==========================================================
$bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tahunDefault = (int)date('Y');
try {
    $tahunDb = (int)$pdo->query("SELECT COALESCE(MAX(tahun), 0) FROM simpanan")->fetchColumn();
    if ($tahunDb > 0) {
        $tahunDefault = $tahunDb;
    }
} catch (Throwable $e) {
    $tahunDefault = (int)date('Y');
}
$tahunAktif = (int)($_GET['tahun_filter'] ?? $tahunDefault);
$bulanAktif = (int)($_GET['bulan_filter'] ?? date('n'));
$pageAktif = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

if ($bulanAktif < 1 || $bulanAktif > 12) {
    $bulanAktif = (int)date('n');
}
$searchMember = trim((string)($_GET['q'] ?? ''));

$statTahun = ['total_member' => 0, 'total_pokok' => 0, 'total_wajib' => 0, 'total_sukarela' => 0, 'grand_total' => 0];
$statBulan = ['total_member' => 0, 'total_pokok' => 0, 'total_wajib' => 0, 'total_sukarela' => 0, 'grand_total' => 0];
$matrixRows = [];
$daftarBulan = [];
$importLog = [];
$matrixTotal = 0;
$daftarTotal = 0;
$matrixRowsPage = [];
$daftarBulanPage = [];

try {
    $stmt = $pdo->prepare("\n        SELECT\n            COUNT(DISTINCT member_id) AS total_member,\n            COALESCE(SUM(CASE WHEN jenis='pokok' THEN jumlah ELSE 0 END),0) AS total_pokok,\n            COALESCE(SUM(CASE WHEN jenis='wajib' THEN jumlah ELSE 0 END),0) AS total_wajib,\n            COALESCE(SUM(CASE WHEN jenis='sukarela' THEN jumlah ELSE 0 END),0) AS total_sukarela,\n            COALESCE(SUM(jumlah),0) AS grand_total\n        FROM simpanan\n        WHERE tahun = :tahun\n    ");
    $stmt->execute([':tahun' => $tahunAktif]);
    $statTahun = $stmt->fetch(PDO::FETCH_ASSOC) ?: $statTahun;
} catch (Throwable $e) {
    $statTahun = ['total_member' => 0, 'total_pokok' => 0, 'total_wajib' => 0, 'total_sukarela' => 0, 'grand_total' => 0];
}

try {
    $stmt = $pdo->prepare("\n        SELECT\n            COUNT(DISTINCT member_id) AS total_member,\n            COALESCE(SUM(CASE WHEN jenis='pokok' THEN jumlah ELSE 0 END),0) AS total_pokok,\n            COALESCE(SUM(CASE WHEN jenis='wajib' THEN jumlah ELSE 0 END),0) AS total_wajib,\n            COALESCE(SUM(CASE WHEN jenis='sukarela' THEN jumlah ELSE 0 END),0) AS total_sukarela,\n            COALESCE(SUM(jumlah),0) AS grand_total\n        FROM simpanan\n        WHERE bulan = :bulan AND tahun = :tahun\n    ");
    $stmt->execute([':bulan' => $bulanAktif, ':tahun' => $tahunAktif]);
    $statBulan = $stmt->fetch(PDO::FETCH_ASSOC) ?: $statBulan;
} catch (Throwable $e) {
    $statBulan = ['total_member' => 0, 'total_pokok' => 0, 'total_wajib' => 0, 'total_sukarela' => 0, 'grand_total' => 0];
}

try {
    $where = ['s.tahun = :tahun'];
    $params = [':tahun' => $tahunAktif];

    if ($searchMember !== '') {
        $where[] = '(m.nama LIKE :q1 OR m.kode LIKE :q2)';
        $params[':q1'] = '%' . $searchMember . '%';
        $params[':q2'] = '%' . $searchMember . '%';
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("\n        SELECT\n            m.id AS member_id,\n            m.nama AS member_nama,\n            m.kode AS member_kode,\n            m.no_hp,\n            s.bulan,\n            SUM(CASE WHEN s.jenis='pokok' THEN s.jumlah ELSE 0 END) AS pokok,\n            SUM(CASE WHEN s.jenis='wajib' THEN s.jumlah ELSE 0 END) AS wajib,\n            SUM(CASE WHEN s.jenis='sukarela' THEN s.jumlah ELSE 0 END) AS sukarela,\n            SUM(s.jumlah) AS total\n        FROM simpanan s\n        JOIN member m ON m.id = s.member_id\n        WHERE $whereSql\n        GROUP BY m.id, m.nama, m.kode, m.no_hp, s.bulan\n        ORDER BY m.nama ASC, s.bulan ASC\n    ");
    $stmt->execute($params);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['member_id'];
        $bulan = (int)$row['bulan'];
        if (!isset($map[$mid])) {
            $map[$mid] = [
                'member_id' => $mid,
                'member_nama' => $row['member_nama'],
                'member_kode' => $row['member_kode'],
                'no_hp' => $row['no_hp'],
                'bulan' => [],
                'total_pokok' => 0,
                'total_wajib' => 0,
                'total_sukarela' => 0,
                'grand_total' => 0,
            ];
            for ($i = 1; $i <= 12; $i++) {
                $map[$mid]['bulan'][$i] = ['pokok' => 0, 'wajib' => 0, 'sukarela' => 0, 'total' => 0];
            }
        }

        $pokok = (float)$row['pokok'];
        $wajib = (float)$row['wajib'];
        $sukarela = (float)$row['sukarela'];
        $total = (float)$row['total'];

        $map[$mid]['bulan'][$bulan] = [
            'pokok' => $pokok,
            'wajib' => $wajib,
            'sukarela' => $sukarela,
            'total' => $total,
        ];
        $map[$mid]['total_pokok'] += $pokok;
        $map[$mid]['total_wajib'] += $wajib;
        $map[$mid]['total_sukarela'] += $sukarela;
        $map[$mid]['grand_total'] += $total;
    }
    $matrixRows = array_values($map);
    $matrixTotal = count($matrixRows);
    $matrixRowsPage = array_slice($matrixRows, ($pageAktif - 1) * $perPage, $perPage);
} catch (Throwable $e) {
    $matrixRows = [];
    $matrixRowsPage = [];
    $matrixTotal = 0;
    $error = $error ?: 'Gagal memuat rekap bulanan: ' . $e->getMessage();
}

try {
    $where = ['s.bulan = :bulan', 's.tahun = :tahun'];
    $params = [':bulan' => $bulanAktif, ':tahun' => $tahunAktif];
    if ($searchMember !== '') {
        $where[] = '(m.nama LIKE :q1 OR m.kode LIKE :q2)';
        $params[':q1'] = '%' . $searchMember . '%';
        $params[':q2'] = '%' . $searchMember . '%';
    }
    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("\n        SELECT\n            m.id AS member_id,\n            m.nama AS member_nama,\n            m.kode AS member_kode,\n            m.no_hp,\n            SUM(CASE WHEN s.jenis='pokok' THEN s.jumlah ELSE 0 END) AS total_pokok,\n            SUM(CASE WHEN s.jenis='wajib' THEN s.jumlah ELSE 0 END) AS total_wajib,\n            SUM(CASE WHEN s.jenis='sukarela' THEN s.jumlah ELSE 0 END) AS total_sukarela,\n            SUM(s.jumlah) AS grand_total\n        FROM simpanan s\n        JOIN member m ON m.id = s.member_id\n        WHERE $whereSql\n        GROUP BY m.id, m.nama, m.kode, m.no_hp\n        ORDER BY m.nama ASC\n    ");
    $stmt->execute($params);
    $daftarBulan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $daftarTotal = count($daftarBulan);
    $daftarBulanPage = array_slice($daftarBulan, ($pageAktif - 1) * $perPage, $perPage);
} catch (Throwable $e) {
    $daftarBulan = [];
    $daftarBulanPage = [];
    $daftarTotal = 0;
}

$paginationBase = 'tahun_filter=' . urlencode((string)$tahunAktif) . '&bulan_filter=' . urlencode((string)$bulanAktif) . '&per_page=' . urlencode((string)$perPage);
if ($searchMember !== '') {
    $paginationBase .= '&q=' . urlencode($searchMember);
}
$periodPageTotal = max(1, (int)ceil(max(0, $daftarTotal) / max(1, $perPage)));
$yearPageTotal = max(1, (int)ceil(max(0, $matrixTotal) / max(1, $perPage)));

try {
    $logStmt = $pdo->query("\n        SELECT si.*, u.nama AS imported_nama\n        FROM simpanan_import si\n        LEFT JOIN users u ON u.id = si.imported_by\n        ORDER BY si.created_at DESC\n        LIMIT 20\n    ");
    $importLog = $logStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $importLog = [];
    $matrixTotal = 0;
    $daftarTotal = 0;
    $matrixRowsPage = [];
    $daftarBulanPage = [];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simpanan — SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fafafa;
            color: #111;
        }

        @media (min-width:1024px) {

            .content,
            .main-wrap,
            .app-header,
            .page-header {
                margin-left: 220px;
            }
        }

        .tab-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tab-scroll::-webkit-scrollbar {
            height: 0;
        }

        .tab-btn {
            flex: 0 0 auto;
            padding: 11px 16px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
            border: 1px solid #e5e5e5;
            border-bottom: none;
            background: #fafafa;
            color: #9ca3af;
            position: relative;
            top: 1px;
            white-space: nowrap;
        }

        .tab-btn.active {
            background: #fff;
            color: #111;
            border-bottom-color: #fff;
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0;
        }

        .kpi {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 16px;
            border-left: 3px solid #111;
            min-width: 0;
        }

        .kpi p:last-child {
            overflow-wrap: anywhere;
        }

        .form-input {
            width: 100%;
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            min-height: 42px;
        }

        .form-input:focus {
            outline: none;
            border-color: #111;
            background: #fff;
        }

        .btn-primary,
        .btn-outline,
        .btn-success {
            min-height: 42px;
            padding: 10px 16px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
        }

        .btn-primary {
            background: #111;
            color: #fff;
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .btn-outline {
            background: #fff;
            color: #111;
            border: 1px solid #e5e5e5;
        }

        .btn-success {
            background: #15803d;
            color: #fff;
        }

        .alert {
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 18px;
            border: 1px solid;
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-wrap::-webkit-scrollbar {
            height: 6px;
        }

        .table-wrap::-webkit-scrollbar-track {
            background: #f3f4f6;
        }

        .table-wrap::-webkit-scrollbar-thumb {
            background: #d1d5db;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: #f9f9f9;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }

        .data-table td {
            padding: 12px;
            font-size: 12px;
            border-bottom: 1px solid #f7f7f7;
            vertical-align: top;
        }

        .data-table tbody tr:hover {
            background: #fafafa;
        }

        .sticky-member {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 1;
            box-shadow: 8px 0 12px rgba(255, 255, 255, .9);
        }

        thead .sticky-member {
            background: #f9f9f9;
            z-index: 3;
        }

        .money {
            font-weight: 900;
            white-space: nowrap;
        }

        .small-muted {
            font-size: 10px;
            color: #9ca3af;
        }

        .upload-zone {
            border: 2px dashed #e5e5e5;
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            background: #fff;
        }

        .upload-zone.has-file {
            border-color: #22c55e;
            background: #f0fdf4;
            border-style: solid;
        }

        .month-cell {
            min-width: 92px;
        }

        .mobile-list {
            display: none;
        }

        .member-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 14px;
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .mini-box {
            background: #fafafa;
            border: 1px solid #f0f0f0;
            padding: 10px;
            min-width: 0;
        }

        .month-scroll {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            -webkit-overflow-scrolling: touch;
        }

        .month-pill {
            flex: 0 0 92px;
            border: 1px solid #f0f0f0;
            background: #fafafa;
            padding: 9px;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(0, 0, 0, .5);
            padding: 10px;
        }

        .modal.open {
            display: flex;
        }

        .modal-inner {
            margin: auto;
            width: min(1200px, 100%);
            height: min(88vh, 850px);
            background: #fff;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        @media (max-width:1023px) {
            body {
                padding-bottom: 86px;
            }

            .desktop-table {
                display: none;
            }

            .mobile-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                padding: 12px;
            }

            .content {
                padding: 16px !important;
            }

            .filter-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width:640px) {
            .content {
                padding: 12px !important;
            }

            .tab-btn {
                padding: 10px 12px;
                font-size: 9px;
            }

            .kpi {
                padding: 13px;
            }

            .kpi .text-xl {
                font-size: 14px;
                line-height: 1.2;
            }

            .kpi .text-2xl {
                font-size: 18px;
            }

            .mobile-list {
                grid-template-columns: 1fr;
                padding: 10px;
            }

            .mini-grid {
                grid-template-columns: 1fr;
            }

            .modal {
                padding: 0;
            }

            .modal-inner {
                height: 100vh;
                width: 100%;
            }

            .modal-footer {
                flex-direction: column;
                align-items: stretch !important;
            }

            .modal-footer .flex {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
            }
        }


        /* FINAL RESPONSIVE OVERRIDE: tampilkan rekap tahunan sebagai kartu agar 12 bulan terbaca tanpa scroll horizontal */
        #tab-bulanan .desktop-table {
            display: none !important;
        }

        #tab-bulanan .mobile-list {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding: 14px;
            background: #fff;
        }

        #tab-bulanan .member-card {
            border: 1px solid #ececec;
            background: #fff;
            padding: 16px;
            min-width: 0;
        }

        #tab-bulanan .month-scroll {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 8px;
            overflow: visible;
            padding-bottom: 0;
        }

        #tab-bulanan .month-pill {
            flex: initial;
            min-width: 0;
            width: auto;
            padding: 8px;
            background: #fafafa;
            border: 1px solid #f0f0f0;
        }

        #tab-bulanan .month-pill p:last-child {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #tab-bulanan .mini-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        #tab-bulanan .mini-grid .mini-box:last-child {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        #tab-bulanan .mini-grid .mini-box:last-child .small-muted {
            color: rgba(255, 255, 255, .65);
        }

        .year-hint {
            background: #111;
            color: #fff;
            border: 1px solid #111;
            padding: 14px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .year-hint p {
            margin: 0;
        }

        .year-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 8px 12px;
            border: 1px solid rgba(255, 255, 255, .25);
            color: #fff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .year-link:hover {
            background: rgba(255, 255, 255, .08);
        }

        @media (max-width:1180px) {
            #tab-bulanan .mobile-list {
                grid-template-columns: 1fr;
            }

            #tab-bulanan .month-scroll {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }

        @media (max-width:768px) {
            #tab-bulanan .month-scroll {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            #tab-bulanan .mini-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width:480px) {
            #tab-bulanan .mobile-list {
                padding: 10px;
                gap: 10px;
            }

            #tab-bulanan .member-card {
                padding: 13px;
            }

            #tab-bulanan .month-scroll {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
            }

            #tab-bulanan .month-pill {
                padding: 7px;
            }

            #tab-bulanan .month-pill p:last-child {
                font-size: 10px;
            }
        }


        .sticky-filter {
            position: sticky;
            top: 72px;
            z-index: 30;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .03);
        }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            border-top: 1px solid #f0f0f0;
            background: #fff;
            flex-wrap: wrap;
        }

        .page-link {
            min-height: 36px;
            padding: 8px 12px;
            border: 1px solid #e5e5e5;
            background: #fff;
            color: #111;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .page-link.is-disabled {
            color: #cbd5e1;
            pointer-events: none;
            background: #f9fafb;
        }

        .detail-toggle summary {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .detail-toggle summary::-webkit-details-marker {
            display: none;
        }

        .detail-toggle summary::after {
            content: 'Buka 12 Bulan';
            flex: 0 0 auto;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 7px 10px;
            border: 1px solid #e5e5e5;
            color: #111;
        }

        .detail-toggle[open] summary::after {
            content: 'Tutup';
        }

        @media (max-width:1023px) {
            .sticky-filter {
                top: 0;
            }

            .desktop-table {
                display: none !important;
            }
        }

        @media (min-width:1024px) {
            .mobile-list.mobile-only {
                display: none !important;
            }
        }
    </style>
</head>

<body class="antialiased pb-24 lg:pb-0">
    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">
        <div id="js-alert" style="display:none" class="alert"></div>
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <div class="tab-scroll flex gap-0 border-b border-gray-200 mb-6">
            <button class="tab-btn active" id="btn-tab-periode" onclick="switchTab('tab-periode')">Daftar Per Bulan</button>
            <button class="tab-btn" id="btn-tab-bulanan" onclick="switchTab('tab-bulanan')">Rekap Tahunan</button>
            <button class="tab-btn" id="btn-tab-import" onclick="switchTab('tab-import')">Import Excel</button>
        </div>

        <form method="GET" class="card p-4 mb-6 sticky-filter">
            <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_2fr_1fr_auto_auto] gap-3 items-end">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Tahun</label>
                    <select name="tahun_filter" class="form-input" onchange="this.form.submit()">
                        <?php for ($y = (int)date('Y') + 1; $y >= 2023; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $tahunAktif ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Bulan</label>
                    <select name="bulan_filter" class="form-input" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $bulanAktif ? 'selected' : '' ?>><?= h($bulanNama[$m]) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Cari Member</label>
                    <input type="text" name="q" value="<?= h($searchMember) ?>" class="form-input" placeholder="Nama / kode member">
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Tampil</label>
                    <select name="per_page" class="form-input" onchange="this.form.submit()">
                        <?php foreach ([10, 25, 50, 100] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $pp === $perPage ? 'selected' : '' ?>><?= $pp ?> / halaman</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions flex gap-2 md:contents">
                    <button type="submit" class="btn-primary h-[42px] w-full">Tampilkan</button>
                    <a href="simpanan.php" class="btn-outline h-[42px] w-full">Reset</a>
                </div>
            </div>
        </form>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Member Tahun</p>
                <p class="text-2xl font-black mt-1"><?= angka_sp($statTahun['total_member'] ?? 0) ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Pokok Tahun</p>
                <p class="text-xl font-black mt-1 text-blue-700"><?= rupiah_sp($statTahun['total_pokok'] ?? 0) ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Wajib Tahun</p>
                <p class="text-xl font-black mt-1 text-green-700"><?= rupiah_sp($statTahun['total_wajib'] ?? 0) ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Sukarela Tahun</p>
                <p class="text-xl font-black mt-1 text-purple-700"><?= rupiah_sp($statTahun['total_sukarela'] ?? 0) ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Total Tahun</p>
                <p class="text-xl font-black mt-1"><?= rupiah_sp($statTahun['grand_total'] ?? 0) ?></p>
            </div>
        </div>

        <div class="year-hint">
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest opacity-60">Tampilan Tahun Aktif</p>
                <p class="text-lg font-black"><?= h($tahunAktif) ?> · Default mengikuti tahun terakhir di database; data 2025 tetap bisa dibuka dari filter tahun</p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a class="year-link" href="?tahun_filter=<?= (int)date('Y') ?>&bulan_filter=<?= $bulanAktif ?><?= $searchMember !== '' ? '&q=' . urlencode($searchMember) : '' ?>">Tahun Berjalan</a>
                <a class="year-link" href="?tahun_filter=<?= (int)date('Y') - 1 ?>&bulan_filter=<?= $bulanAktif ?><?= $searchMember !== '' ? '&q=' . urlencode($searchMember) : '' ?>">Tahun Sebelumnya</a>
            </div>
        </div>

        <section id="tab-bulanan" class="hidden">
            <div class="card overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Rekap Simpanan 12 Bulan Per Member</h2>
                        <p class="text-xs text-gray-400 mt-1">Menampilkan total pokok + wajib + sukarela setiap bulan untuk tahun <?= h($tahunAktif) ?>.</p>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-400"><?= angka_sp($matrixTotal) ?> member</span>
                </div>
                <div class="table-wrap desktop-table">
                    <table class="data-table" style="min-width:1500px">
                        <thead>
                            <tr>
                                <th style="width:44px">#</th>
                                <th class="sticky-member" style="width:260px">Member</th>
                                <?php for ($m = 1; $m <= 12; $m++): ?><th class="text-right month-cell"><?= substr($bulanNama[$m], 0, 3) ?></th><?php endfor; ?>
                                <th class="text-right" style="width:120px">Pokok</th>
                                <th class="text-right" style="width:120px">Wajib</th>
                                <th class="text-right" style="width:120px">Sukarela</th>
                                <th class="text-right" style="width:130px">Total Tahun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$matrixRowsPage): ?>
                                <tr>
                                    <td colspan="18" class="text-center py-16 text-gray-300 font-black uppercase tracking-widest">Belum ada data simpanan untuk tahun ini</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($matrixRowsPage as $i => $row): ?>
                                <tr>
                                    <td class="text-gray-300 font-black"><?= (($pageAktif - 1) * $perPage) + $i + 1 ?></td>
                                    <td class="sticky-member">
                                        <div class="font-black text-sm"><?= h($row['member_nama']) ?></div>
                                        <div class="small-muted font-mono"><?= h($row['member_kode']) ?> · <?= h($row['no_hp'] ?: '-') ?></div>
                                    </td>
                                    <?php for ($m = 1; $m <= 12; $m++): $v = (float)($row['bulan'][$m]['total'] ?? 0); ?>
                                        <td class="text-right month-cell"><?= $v > 0 ? '<span class="money">' . rupiah_sp($v) . '</span>' : '<span class="text-gray-300">-</span>' ?></td>
                                    <?php endfor; ?>
                                    <td class="text-right text-blue-700 money"><?= rupiah_sp($row['total_pokok']) ?></td>
                                    <td class="text-right text-green-700 money"><?= rupiah_sp($row['total_wajib']) ?></td>
                                    <td class="text-right text-purple-700 money"><?= rupiah_sp($row['total_sukarela']) ?></td>
                                    <td class="text-right money"><?= rupiah_sp($row['grand_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-list">
                    <?php if (!$matrixRowsPage): ?>
                        <div class="member-card text-center text-gray-300 font-black uppercase tracking-widest text-[10px]">Belum ada data simpanan untuk tahun ini</div>
                    <?php endif; ?>
                    <?php foreach ($matrixRowsPage as $i => $row): ?>
                        <div class="member-card">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="min-w-0">
                                    <p class="text-[10px] text-gray-300 font-black">#<?= (($pageAktif - 1) * $perPage) + $i + 1 ?></p>
                                    <p class="font-black text-sm leading-tight"><?= h($row['member_nama']) ?></p>
                                    <p class="small-muted font-mono truncate"><?= h($row['member_kode']) ?> · <?= h($row['no_hp'] ?: '-') ?></p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="small-muted font-black uppercase">Total</p>
                                    <p class="font-black text-sm"><?= rupiah_sp($row['grand_total']) ?></p>
                                </div>
                            </div>
                            <div class="mini-grid mb-3">
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Pokok</p>
                                    <p class="money text-blue-700"><?= rupiah_sp($row['total_pokok']) ?></p>
                                </div>
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Wajib</p>
                                    <p class="money text-green-700"><?= rupiah_sp($row['total_wajib']) ?></p>
                                </div>
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Sukarela</p>
                                    <p class="money text-purple-700"><?= rupiah_sp($row['total_sukarela']) ?></p>
                                </div>
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Total Tahun</p>
                                    <p class="money"><?= rupiah_sp($row['grand_total']) ?></p>
                                </div>
                            </div>
                            <p class="small-muted font-black uppercase tracking-widest mb-2">Rincian 12 Bulan</p>
                            <div class="month-scroll no-scrollbar">
                                <?php for ($m = 1; $m <= 12; $m++): $v = (float)($row['bulan'][$m]['total'] ?? 0); ?>
                                    <div class="month-pill">
                                        <p class="small-muted font-black uppercase"><?= substr($bulanNama[$m], 0, 3) ?></p>
                                        <p class="text-xs font-black <?= $v > 0 ? 'text-gray-900' : 'text-gray-300' ?>"><?= $v > 0 ? rupiah_sp($v) : '-' ?></p>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="pagination-bar">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Menampilkan <?= angka_sp(count($matrixRowsPage)) ?> dari <?= angka_sp($matrixTotal) ?> member · Halaman <?= angka_sp($pageAktif) ?>/<?= angka_sp($yearPageTotal) ?></p>
                    <div class="flex gap-2">
                        <a class="page-link <?= $pageAktif <= 1 ? 'is-disabled' : '' ?>" href="?<?= h($paginationBase) ?>&page=<?= max(1, $pageAktif - 1) ?>&tab=tahunan">Prev</a>
                        <a class="page-link <?= $pageAktif >= $yearPageTotal ? 'is-disabled' : '' ?>" href="?<?= h($paginationBase) ?>&page=<?= min($yearPageTotal, $pageAktif + 1) ?>&tab=tahunan">Next</a>
                    </div>
                </div>
            </div>
        </section>

        <section id="tab-periode">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <div class="kpi">
                    <p class="small-muted font-black uppercase tracking-widest">Member Bulan</p>
                    <p class="text-2xl font-black mt-1"><?= angka_sp($statBulan['total_member'] ?? 0) ?></p>
                </div>
                <div class="kpi">
                    <p class="small-muted font-black uppercase tracking-widest">Pokok</p>
                    <p class="text-xl font-black mt-1 text-blue-700"><?= rupiah_sp($statBulan['total_pokok'] ?? 0) ?></p>
                </div>
                <div class="kpi">
                    <p class="small-muted font-black uppercase tracking-widest">Wajib</p>
                    <p class="text-xl font-black mt-1 text-green-700"><?= rupiah_sp($statBulan['total_wajib'] ?? 0) ?></p>
                </div>
                <div class="kpi">
                    <p class="small-muted font-black uppercase tracking-widest">Sukarela</p>
                    <p class="text-xl font-black mt-1 text-purple-700"><?= rupiah_sp($statBulan['total_sukarela'] ?? 0) ?></p>
                </div>
                <div class="kpi">
                    <p class="small-muted font-black uppercase tracking-widest">Total Bulan</p>
                    <p class="text-xl font-black mt-1"><?= rupiah_sp($statBulan['grand_total'] ?? 0) ?></p>
                </div>
            </div>
            <div class="card overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Daftar Simpanan <?= h($bulanNama[$bulanAktif]) ?> <?= h($tahunAktif) ?></h2>
                </div>
                <div class="table-wrap desktop-table">
                    <table class="data-table" style="min-width:900px">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Member</th>
                                <th class="text-right">Pokok</th>
                                <th class="text-right">Wajib</th>
                                <th class="text-right">Sukarela</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$daftarBulanPage): ?><tr>
                                    <td colspan="6" class="text-center py-16 text-gray-300 font-black uppercase tracking-widest">Belum ada data bulan ini</td>
                                </tr><?php endif; ?>
                            <?php foreach ($daftarBulanPage as $i => $row): ?>
                                <tr>
                                    <td class="text-gray-300 font-black"><?= (($pageAktif - 1) * $perPage) + $i + 1 ?></td>
                                    <td>
                                        <div class="font-black text-sm"><?= h($row['member_nama']) ?></div>
                                        <div class="small-muted font-mono"><?= h($row['member_kode']) ?> · <?= h($row['no_hp'] ?: '-') ?></div>
                                    </td>
                                    <td class="text-right text-blue-700 money"><?= (float)$row['total_pokok'] > 0 ? rupiah_sp($row['total_pokok']) : '-' ?></td>
                                    <td class="text-right text-green-700 money"><?= (float)$row['total_wajib'] > 0 ? rupiah_sp($row['total_wajib']) : '-' ?></td>
                                    <td class="text-right text-purple-700 money"><?= (float)$row['total_sukarela'] > 0 ? rupiah_sp($row['total_sukarela']) : '-' ?></td>
                                    <td class="text-right money"><?= rupiah_sp($row['grand_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-list">
                    <?php if (!$daftarBulanPage): ?>
                        <div class="member-card text-center text-gray-300 font-black uppercase tracking-widest text-[10px]">Belum ada data bulan ini</div>
                    <?php endif; ?>
                    <?php foreach ($daftarBulanPage as $i => $row): ?>
                        <div class="member-card">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="min-w-0">
                                    <p class="text-[10px] text-gray-300 font-black">#<?= (($pageAktif - 1) * $perPage) + $i + 1 ?></p>
                                    <p class="font-black text-sm leading-tight"><?= h($row['member_nama']) ?></p>
                                    <p class="small-muted font-mono truncate"><?= h($row['member_kode']) ?> · <?= h($row['no_hp'] ?: '-') ?></p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="small-muted font-black uppercase">Total</p>
                                    <p class="font-black text-sm"><?= rupiah_sp($row['grand_total']) ?></p>
                                </div>
                            </div>
                            <div class="mini-grid">
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Pokok</p>
                                    <p class="money text-blue-700"><?= (float)$row['total_pokok'] > 0 ? rupiah_sp($row['total_pokok']) : '-' ?></p>
                                </div>
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Wajib</p>
                                    <p class="money text-green-700"><?= (float)$row['total_wajib'] > 0 ? rupiah_sp($row['total_wajib']) : '-' ?></p>
                                </div>
                                <div class="mini-box">
                                    <p class="small-muted font-black uppercase">Sukarela</p>
                                    <p class="money text-purple-700"><?= (float)$row['total_sukarela'] > 0 ? rupiah_sp($row['total_sukarela']) : '-' ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="pagination-bar">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Menampilkan <?= angka_sp(count($daftarBulanPage)) ?> dari <?= angka_sp($daftarTotal) ?> member · Halaman <?= angka_sp($pageAktif) ?>/<?= angka_sp($periodPageTotal) ?></p>
                    <div class="flex gap-2">
                        <a class="page-link <?= $pageAktif <= 1 ? 'is-disabled' : '' ?>" href="?<?= h($paginationBase) ?>&page=<?= max(1, $pageAktif - 1) ?>">Prev</a>
                        <a class="page-link <?= $pageAktif >= $periodPageTotal ? 'is-disabled' : '' ?>" href="?<?= h($paginationBase) ?>&page=<?= min($periodPageTotal, $pageAktif + 1) ?>">Next</a>
                    </div>
                </div>
            </div>
        </section>

        <section id="tab-import" class="hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card p-5">
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Import Excel Simpanan Tahunan</h2>
                    <p class="text-sm font-bold mb-4">Format didukung: file “Perhitungan 31 Desember 2025 - Revisi.xlsx”.</p>
                    <div class="bg-amber-50 border border-amber-100 p-4 text-[11px] text-amber-800 font-semibold leading-relaxed mb-5">
                        Data yang dibaca: <strong>01_Simpanan Pokok</strong>, <strong>02_Simpanan Wajib</strong>, dan <strong>03_Simpanan Sukarela</strong>. Tahun import otomatis dibaca dari nama file/sheet, misalnya <strong>2025</strong> dari “Perhitungan 31 Desember 2025”.
                    </div>
                    <form id="excel-form" onsubmit="return false;">
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Tahun File / Otomatis</label>
                                <select id="tahun-import" class="form-input">
                                    <?php for ($y = (int)date('Y') + 1; $y >= 2023; $y--): ?>
                                        <option value="<?= $y ?>" <?= $y === $tahunAktif ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Bulan Pokok</label>
                                <select id="bulan-pokok" class="form-input">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $m === 12 ? 'selected' : '' ?>><?= h($bulanNama[$m]) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <input type="file" accept=".xlsx,.xls" class="hidden" id="file-input" onchange="handleExcelFile(this)">
                        <label for="file-input" class="upload-zone block" id="upload-label">
                            <div class="text-3xl text-gray-300 mb-2">＋</div>
                            <p id="file-label" class="text-[11px] font-black uppercase tracking-widest text-gray-400">Klik untuk pilih file Excel</p>
                            <p class="text-[10px] text-gray-300 mt-1">.xlsx / .xls</p>
                        </label>
                        <button type="button" onclick="parseSelectedExcel()" class="btn-primary w-full mt-4 py-3">Upload & Preview</button>
                    </form>
                    <div id="import-ready" class="hidden mt-5 border border-green-200 bg-green-50 p-4">
                        <p class="text-[10px] font-black uppercase tracking-widest text-green-700">File siap diimport</p>
                        <p id="ready-summary" class="text-sm font-black text-green-900 mt-1"></p>
                        <div id="ready-detail" class="text-[11px] text-green-700 mt-1"></div>
                        <div class="grid grid-cols-2 gap-2 mt-4">
                            <button type="button" onclick="batalImport()" class="btn-outline py-2.5">Batal</button>
                            <button type="button" id="btn-import" onclick="confirmImport()" class="btn-success py-2.5">Konfirmasi & Import</button>
                        </div>
                    </div>
                </div>

                <div class="card p-5">
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">Riwayat Import</h2>
                    <?php if (!$importLog): ?>
                        <div class="py-16 text-center text-gray-300 font-black uppercase tracking-widest text-[10px]">Belum ada riwayat import</div>
                    <?php else: ?>
                        <div class="space-y-3 max-h-[480px] overflow-y-auto no-scrollbar">
                            <?php foreach ($importLog as $log): ?>
                                <div class="border-b border-gray-100 pb-3">
                                    <p class="text-sm font-black truncate"><?= h($log['filename'] ?? '-') ?></p>
                                    <p class="small-muted mt-1">Sheet: <?= h($log['sheet_name'] ?? '-') ?> · Tahun <?= h($log['tahun'] ?? '-') ?> · <?= angka_sp($log['jumlah_baris'] ?? 0) ?> baris</p>
                                    <p class="small-muted"><?= h($log['imported_nama'] ?? 'Sistem') ?> · <?= !empty($log['created_at']) ? date('d/m/Y H:i', strtotime((string)$log['created_at'])) : '-' ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <div id="preview-modal" class="modal">
        <div class="modal-inner">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-900">Preview Import</p>
                    <p id="preview-meta" class="small-muted mt-1">-</p>
                </div>
                <button onclick="closePreviewModal()" class="w-9 h-9 border border-gray-200 text-xl">&times;</button>
            </div>
            <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row gap-3">
                <input type="text" id="preview-search" oninput="renderPreview()" class="form-input" placeholder="Cari nama / jenis / bulan...">
                <select id="preview-filter-jenis" onchange="renderPreview()" class="form-input sm:w-48">
                    <option value="">Semua Jenis</option>
                    <option value="pokok">Pokok</option>
                    <option value="wajib">Wajib</option>
                    <option value="sukarela">Sukarela</option>
                </select>
            </div>
            <div class="table-wrap flex-1 overflow-auto">
                <table class="data-table" style="min-width:900px">
                    <thead style="position:sticky;top:0;z-index:2">
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Jenis</th>
                            <th>Bulan</th>
                            <th class="text-right">Jumlah</th>
                            <th>Sheet</th>
                        </tr>
                    </thead>
                    <tbody id="preview-tbody"></tbody>
                </table>
            </div>
            <div class="modal-footer px-5 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3">
                <p id="preview-total" class="text-xs font-black text-gray-600">-</p>
                <div class="flex gap-2">
                    <button onclick="closePreviewModal()" class="btn-outline">Cek Lagi</button>
                    <button onclick="confirmImport()" id="btn-import-modal" class="btn-success">Konfirmasi & Import</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabId) {
            ['tab-bulanan', 'tab-periode', 'tab-import'].forEach(function(id) {
                var el = document.getElementById(id),
                    btn = document.getElementById('btn-' + id);
                if (!el || !btn) return;
                if (id === tabId) {
                    el.classList.remove('hidden');
                    btn.classList.add('active');
                } else {
                    el.classList.add('hidden');
                    btn.classList.remove('active');
                }
            });
        }
        (function() {
            var t = new URLSearchParams(window.location.search).get('tab');
            if (t === 'import') switchTab('tab-import');
            else if (t === 'tahunan') switchTab('tab-bulanan');
            else switchTab('tab-periode');
        }());

        function escapeHtml(v) {
            return String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function showAlert(type, message) {
            var box = document.getElementById('js-alert');
            box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
            box.innerHTML = escapeHtml(message);
            box.style.display = 'block';
            box.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function renderFailedRows(rows) {
            if (!rows || !rows.length) return;
            var box = document.getElementById('js-alert');
            var html = '<div class="mt-3 border-t border-red-200 pt-3">';
            html += '<p class="font-black mb-2">Data gagal/lewat import (' + rows.length + ' data)</p>';
            html += '<div class="max-h-72 overflow-y-auto bg-white/60 border border-red-100">';
            html += '<table class="w-full text-left text-[11px]"><thead><tr>';
            html += '<th class="p-2">Nama</th><th class="p-2">Jenis</th><th class="p-2">Bulan</th><th class="p-2 text-right">Jumlah</th><th class="p-2">Alasan</th>';
            html += '</tr></thead><tbody>';
            rows.forEach(function(r) {
                html += '<tr class="border-t border-red-100">';
                html += '<td class="p-2 font-bold">' + escapeHtml(r.nama || '-') + '</td>';
                html += '<td class="p-2">' + escapeHtml(r.jenis || '-') + '</td>';
                html += '<td class="p-2">' + escapeHtml(r.bulan_label || r.bulan || '-') + '</td>';
                html += '<td class="p-2 text-right">' + escapeHtml(r.jumlah_label || rupiahJS(r.jumlah || 0)) + '</td>';
                html += '<td class="p-2 text-red-700 font-bold">' + escapeHtml(r.alasan || '-') + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div></div>';
            box.innerHTML += html;
        }

        function rupiahJS(n) {
            return 'Rp ' + parseInt(n || 0, 10).toLocaleString('id-ID');
        }

        function normalizeCell(v) {
            return (v === null || v === undefined) ? '' : String(v).trim();
        }

        function toNumber(v) {
            if (v === null || v === undefined || v === '') return 0;
            if (typeof v === 'number') return Math.round(v);
            var c = String(v).replace(/[^0-9\-]/g, '');
            return c ? parseInt(c, 10) : 0;
        }

        var selectedExcelFile = null;
        var importPayload = null;
        var bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        var monthMap = {
            JANUARI: 1,
            FEBRUARI: 2,
            MARET: 3,
            APRIL: 4,
            MEI: 5,
            JUNI: 6,
            JULI: 7,
            AGUSTUS: 8,
            SEPTEMBER: 9,
            OKTOBER: 10,
            NOVEMBER: 11,
            NOPEMBER: 11,
            DESEMBER: 12
        };

        function detectYearFromText(text) {
            var m = String(text || '').match(/(20\d{2})/);
            return m ? parseInt(m[1], 10) : 0;
        }

        function detectYearFromWorkbook(wb, fileName) {
            var y = detectYearFromText(fileName);
            if (y) return y;

            for (var i = 0; i < wb.SheetNames.length; i++) {
                y = detectYearFromText(wb.SheetNames[i]);
                if (y) return y;
            }

            // Cari 30 baris x 10 kolom pertama dari beberapa sheet.
            for (var s = 0; s < Math.min(wb.SheetNames.length, 6); s++) {
                var rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[s]], {
                    header: 1,
                    defval: ''
                });
                for (var r = 0; r < Math.min(rows.length, 30); r++) {
                    for (var c = 0; c < Math.min((rows[r] || []).length, 10); c++) {
                        y = detectYearFromText(rows[r][c]);
                        if (y) return y;
                    }
                }
            }
            return 0;
        }

        function setImportYearIfAvailable(year) {
            if (!year || year < 2000) return;
            var select = document.getElementById('tahun-import');
            if (!select) return;
            var value = String(year);
            var exists = false;
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value === value) {
                    exists = true;
                    break;
                }
            }
            if (!exists) {
                var opt = document.createElement('option');
                opt.value = value;
                opt.textContent = value;
                select.insertBefore(opt, select.firstChild);
            }
            select.value = value;
        }

        function handleExcelFile(input) {
            selectedExcelFile = (input.files && input.files[0]) ? input.files[0] : null;
            importPayload = null;
            var lbl = document.getElementById('file-label'),
                zone = document.getElementById('upload-label');
            if (selectedExcelFile) {
                lbl.textContent = selectedExcelFile.name;
                zone.classList.add('has-file');
                setImportYearIfAvailable(detectYearFromText(selectedExcelFile.name));
            } else {
                lbl.textContent = 'Klik untuk pilih file Excel';
                zone.classList.remove('has-file');
            }
            document.getElementById('import-ready').classList.add('hidden');
        }

        function getSheetByName(wb, wanted) {
            var found = null;
            wb.SheetNames.forEach(function(name) {
                if (String(name).toUpperCase().indexOf(wanted.toUpperCase()) !== -1) found = name;
            });
            return found;
        }

        function findHeaderRow(rows, text) {
            text = String(text).toUpperCase();
            for (var r = 0; r < Math.min(rows.length, 30); r++) {
                for (var c = 0; c < (rows[r] || []).length; c++) {
                    if (String(rows[r][c] || '').toUpperCase().indexOf(text) !== -1) return r;
                }
            }
            return -1;
        }

        function parseMonthFromHeader(v) {
            var up = String(v || '').toUpperCase();
            for (var k in monthMap) {
                if (up.indexOf(k) !== -1) return monthMap[k];
            }
            return 0;
        }

        function parsePokokSheet(wb, rowsOut, bulanPokok, tahun) {
            var sheetName = getSheetByName(wb, '01_Simpanan Pokok');
            if (!sheetName) return {
                count: 0,
                total: 0
            };
            var rows = XLSX.utils.sheet_to_json(wb.Sheets[sheetName], {
                header: 1,
                defval: ''
            });
            var header = findHeaderRow(rows, 'Nama Anggota');
            if (header < 0) return {
                count: 0,
                total: 0
            };
            var sub = rows[header + 1] || [];
            var colNama = 1,
                colAkhir = -1;
            for (var c = 0; c < Math.max((rows[header] || []).length, sub.length); c++) {
                var h = (normalizeCell((rows[header] || [])[c]) + ' ' + normalizeCell(sub[c])).toUpperCase();
                if (h.indexOf('NAMA ANGGOTA') !== -1) colNama = c;
                if (h.indexOf('POKOK AKHIR') !== -1) colAkhir = c;
            }
            if (colAkhir < 0) colAkhir = 4;
            var count = 0,
                total = 0;
            for (var r = header + 2; r < rows.length; r++) {
                var row = rows[r] || [];
                var nama = normalizeCell(row[colNama]);
                if (!nama || !isNaN(nama)) continue;
                var up = nama.toUpperCase();
                if (up.indexOf('JUMLAH') !== -1 || up.indexOf('TOTAL') !== -1 || up.indexOf('NAMA') !== -1) continue;
                var jumlah = toNumber(row[colAkhir]);
                if (jumlah > 0) {
                    rowsOut.push({
                        nama: nama,
                        jenis: 'pokok',
                        bulan: bulanPokok,
                        tahun: tahun,
                        jumlah: jumlah,
                        sheet: sheetName
                    });
                    count++;
                    total += jumlah;
                }
            }
            return {
                count: count,
                total: total
            };
        }

        function parseMonthlySheet(wb, sheetKey, jenis, iuranKeyword, rowsOut, tahun) {
            var sheetName = getSheetByName(wb, sheetKey);
            if (!sheetName) return {
                count: 0,
                total: 0
            };
            var rows = XLSX.utils.sheet_to_json(wb.Sheets[sheetName], {
                header: 1,
                defval: ''
            });
            var header = findHeaderRow(rows, 'Nama Anggota');
            if (header < 0) return {
                count: 0,
                total: 0
            };
            var rowMonth = rows[header] || [];
            var rowSub = rows[header + 1] || [];
            var colNama = 1;
            for (var c = 0; c < rowMonth.length; c++) {
                if (String(rowMonth[c] || '').toUpperCase().indexOf('NAMA ANGGOTA') !== -1) colNama = c;
            }
            var monthCols = [];
            var maxCols = Math.max(rowMonth.length, rowSub.length);
            for (var c = 0; c < maxCols; c++) {
                var bulan = parseMonthFromHeader(rowMonth[c]);
                if (!bulan) continue;
                var iuranCol = -1;
                for (var j = c; j < Math.min(c + 4, maxCols); j++) {
                    var sub = String(rowSub[j] || '').toUpperCase();
                    if (sub.indexOf('IURAN') !== -1 && sub.indexOf(iuranKeyword.toUpperCase()) !== -1) {
                        iuranCol = j;
                        break;
                    }
                }
                if (iuranCol < 0) {
                    for (var j = c; j < Math.min(c + 4, maxCols); j++) {
                        var sub = String(rowSub[j] || '').toUpperCase();
                        if (sub.indexOf('IURAN') !== -1) {
                            iuranCol = j;
                            break;
                        }
                    }
                }
                if (iuranCol >= 0) monthCols.push({
                    bulan: bulan,
                    col: iuranCol
                });
            }
            var count = 0,
                total = 0;
            for (var r = header + 2; r < rows.length; r++) {
                var row = rows[r] || [];
                var nama = normalizeCell(row[colNama]);
                if (!nama || !isNaN(nama)) continue;
                var up = nama.toUpperCase();
                if (up.indexOf('JUMLAH') !== -1 || up.indexOf('TOTAL') !== -1 || up.indexOf('NAMA') !== -1) continue;
                monthCols.forEach(function(mc) {
                    var jumlah = toNumber(row[mc.col]);
                    if (jumlah > 0) {
                        rowsOut.push({
                            nama: nama,
                            jenis: jenis,
                            bulan: mc.bulan,
                            tahun: tahun,
                            jumlah: jumlah,
                            sheet: sheetName
                        });
                        count++;
                        total += jumlah;
                    }
                });
            }
            return {
                count: count,
                total: total
            };
        }

        function parseSelectedExcel() {
            if (!selectedExcelFile) {
                showAlert('error', 'Silakan pilih file Excel terlebih dahulu.');
                return;
            }
            var ext = selectedExcelFile.name.split('.').pop().toLowerCase();
            if (['xlsx', 'xls'].indexOf(ext) === -1) {
                showAlert('error', 'File harus .xlsx atau .xls');
                return;
            }
            var tahun = parseInt(document.getElementById('tahun-import').value, 10);
            var bulanPokok = parseInt(document.getElementById('bulan-pokok').value, 10);
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = new Uint8Array(e.target.result);
                    var wb = XLSX.read(data, {
                        type: 'array'
                    });
                    var detectedYear = detectYearFromWorkbook(wb, selectedExcelFile.name);
                    if (detectedYear) {
                        tahun = detectedYear;
                        setImportYearIfAvailable(detectedYear);
                    }
                    var rows = [];
                    var statPokok = parsePokokSheet(wb, rows, bulanPokok, tahun);
                    var statWajib = parseMonthlySheet(wb, '02_Simpanan Wajib', 'wajib', 'WAJIB', rows, tahun);
                    var statSukarela = parseMonthlySheet(wb, '03_Simpanan Sukarela', 'sukarela', 'SKRELA', rows, tahun);
                    if (!rows.length) {
                        showAlert('error', 'Data tidak ditemukan. Pastikan file memakai sheet 01_Simpanan Pokok, 02_Simpanan Wajib, 03_Simpanan Sukarela.');
                        return;
                    }
                    importPayload = {
                        action: 'import_excel_simpanan_2025',
                        filename: selectedExcelFile.name,
                        tahun: tahun,
                        rows: rows
                    };
                    document.getElementById('ready-summary').textContent = rows.length + ' data siap diimport untuk tahun ' + tahun;
                    document.getElementById('ready-detail').innerHTML = 'Pokok: ' + statPokok.count + ' data (' + rupiahJS(statPokok.total) + ') · Wajib: ' + statWajib.count + ' data (' + rupiahJS(statWajib.total) + ') · Sukarela: ' + statSukarela.count + ' data (' + rupiahJS(statSukarela.total) + ')';
                    document.getElementById('import-ready').classList.remove('hidden');
                    renderPreview();
                    openPreviewModal();
                    showAlert('success', 'File berhasil dibaca. Silakan cek preview sebelum import.');
                } catch (err) {
                    showAlert('error', 'Gagal membaca file: ' + err.message);
                }
            };
            reader.readAsArrayBuffer(selectedExcelFile);
        }

        function filteredPreviewRows() {
            if (!importPayload) return [];
            var kw = (document.getElementById('preview-search') || {
                value: ''
            }).value.toLowerCase().trim();
            var jenis = (document.getElementById('preview-filter-jenis') || {
                value: ''
            }).value;
            return importPayload.rows.filter(function(r) {
                if (jenis && r.jenis !== jenis) return false;
                if (!kw) return true;
                return (r.nama + ' ' + r.jenis + ' ' + bulanNama[r.bulan] + ' ' + r.sheet).toLowerCase().indexOf(kw) !== -1;
            });
        }

        function renderPreview() {
            var tbody = document.getElementById('preview-tbody');
            if (!tbody) return;
            var rows = filteredPreviewRows();
            var total = 0;
            var html = '';
            rows.slice(0, 500).forEach(function(r, i) {
                total += parseInt(r.jumlah || 0, 10);
                html += '<tr><td class="text-gray-300 font-black">' + (i + 1) + '</td><td class="font-black">' + escapeHtml(r.nama) + '</td><td>' + escapeHtml(r.jenis) + '</td><td>' + escapeHtml(bulanNama[r.bulan]) + '</td><td class="text-right money">' + rupiahJS(r.jumlah) + '</td><td class="small-muted">' + escapeHtml(r.sheet) + '</td></tr>';
            });
            tbody.innerHTML = html || '<tr><td colspan="6" class="text-center py-16 text-gray-300 font-black uppercase tracking-widest">Tidak ada data preview</td></tr>';
            document.getElementById('preview-meta').textContent = (importPayload ? importPayload.filename : '') + ' · ' + rows.length + ' data tampil' + (rows.length > 500 ? ' (dibatasi 500 baris)' : '');
            document.getElementById('preview-total').textContent = 'Total tampil: ' + rupiahJS(total);
        }

        function openPreviewModal() {
            document.getElementById('preview-modal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closePreviewModal() {
            document.getElementById('preview-modal').classList.remove('open');
            document.body.style.overflow = '';
        }

        function confirmImport() {
            if (!importPayload || !importPayload.rows.length) {
                showAlert('error', 'Tidak ada data untuk diimport.');
                return;
            }
            if (!confirm('Lanjutkan import simpanan? Data simpanan dengan member, jenis, bulan, dan tahun yang sama akan ditimpa.')) return;
            var btns = ['btn-import', 'btn-import-modal'].map(function(id) {
                return document.getElementById(id);
            }).filter(Boolean);
            btns.forEach(function(b) {
                b.disabled = true;
                b.textContent = 'Memproses...';
            });
            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(importPayload)
                })
                .then(function(res) {
                    return res.json().then(function(data) {
                        if (!res.ok || !data.ok) throw new Error(data.message || 'Gagal import.');
                        return data;
                    });
                })
                .then(function(data) {
                    closePreviewModal();
                    showAlert('success', data.message || 'Import berhasil.');
                    renderFailedRows(data.failed_rows || []);
                    if (!data.failed_rows || !data.failed_rows.length) {
                        setTimeout(function() {
                            window.location.href = '?tahun_filter=' + encodeURIComponent(importPayload.tahun) + '&bulan_filter=12';
                        }, 1200);
                    }
                })
                .catch(function(err) {
                    showAlert('error', err.message);
                    btns.forEach(function(b) {
                        b.disabled = false;
                        b.textContent = 'Konfirmasi & Import';
                    });
                });
        }

        function batalImport() {
            selectedExcelFile = null;
            importPayload = null;
            document.getElementById('file-input').value = '';
            document.getElementById('file-label').textContent = 'Klik untuk pilih file Excel';
            document.getElementById('upload-label').classList.remove('has-file');
            document.getElementById('import-ready').classList.add('hidden');
        }
    </script>
</body>

</html>