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

// ── Helper ───────────────────────────────────────────────────────────────────
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
if (!function_exists('json_response_sp')) {
    /** @param array<string,mixed> $data */
    function json_response_sp(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}

$error     = '';
$success   = '';
$importLog = [];

if (isset($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error   = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ── Proses import JSON ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput    = file_get_contents('php://input');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') !== false) {
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) json_response_sp(['ok' => false, 'message' => 'Payload tidak valid.'], 400);

        $action = (string)($payload['action'] ?? '');
        if ($action !== 'import_json') json_response_sp(['ok' => false, 'message' => 'Action tidak valid.'], 400);

        $bulan   = (int)($payload['bulan']    ?? 0);
        $tahun   = (int)($payload['tahun']    ?? 0);
        $oriName = trim((string)($payload['filename'] ?? 'import.xlsx'));
        $sheets  = is_array($payload['sheets'] ?? null) ? $payload['sheets'] : [];

        if ($bulan < 1 || $bulan > 12 || $tahun < 2000) json_response_sp(['ok' => false, 'message' => 'Bulan/tahun tidak valid.'], 422);
        if (empty($sheets)) json_response_sp(['ok' => false, 'message' => 'Tidak ada data Excel.'], 422);

        try {
            $targetUpper = ['PNS', 'PPPK', 'PPNPN', 'NON PPNPN'];
            $totalBaris  = 0;
            $totalSheet  = 0;
            $userId      = (int)($_SESSION['user']['id'] ?? 0);

            $logInsert      = $pdo->prepare("INSERT INTO simpanan_import (filename, sheet_name, bulan, tahun, jumlah_baris, status, imported_by) VALUES (:fn, :sheet, :bulan, :tahun, 0, 'proses', :uid)");
            $insertSimpanan = $pdo->prepare("INSERT INTO simpanan (member_id, jenis, jumlah, bulan, tahun, keterangan, import_id) VALUES (:mid, :jenis, :jumlah, :bulan, :tahun, :ket, :iid) ON DUPLICATE KEY UPDATE jumlah = VALUES(jumlah)");
            $memberStmt     = $pdo->prepare("SELECT id FROM member WHERE nama LIKE :nama LIMIT 1");
            $updateLog      = $pdo->prepare("UPDATE simpanan_import SET jumlah_baris = :jml, status = 'selesai' WHERE id = :id");

            $pdo->beginTransaction();

            foreach ($sheets as $sheetData) {
                if (!is_array($sheetData)) continue;
                $sheetNameClean = trim((string)($sheetData['sheet_name'] ?? ''));
                if ($sheetNameClean === '' || !in_array(strtoupper($sheetNameClean), $targetUpper, true)) continue;

                $rows = is_array($sheetData['rows'] ?? null) ? $sheetData['rows'] : [];
                if (empty($rows)) continue;

                $logInsert->execute([':fn' => $oriName, ':sheet' => $sheetNameClean, ':bulan' => $bulan, ':tahun' => $tahun, ':uid' => $userId]);
                $importId   = (int)$pdo->lastInsertId();
                $barisMasuk = 0;

                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $nama = trim((string)($row['nama'] ?? ''));
                    if ($nama === '' || is_numeric($nama)) continue;

                    $memberStmt->execute([':nama' => '%' . $nama . '%']);
                    $mRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$mRow) continue;

                    $memberId  = (int)$mRow['id'];
                    $jenisList = ['wajib' => $row['wajib'] ?? 0, 'pokok' => $row['pokok'] ?? 0, 'sukarela' => $row['sukarela'] ?? 0];

                    foreach ($jenisList as $jenis => $jumlahRaw) {
                        $jumlah = (int)preg_replace('/[^0-9\-]/', '', (string)$jumlahRaw);
                        if ($jumlah <= 0) continue;
                        $insertSimpanan->execute([':mid' => $memberId, ':jenis' => $jenis, ':jumlah' => $jumlah, ':bulan' => $bulan, ':tahun' => $tahun, ':ket' => $sheetNameClean, ':iid' => $importId]);
                        $barisMasuk++;
                    }
                }

                $updateLog->execute([':jml' => $barisMasuk, ':id' => $importId]);
                $totalBaris += $barisMasuk;
                $totalSheet++;
            }

            if ($totalSheet === 0) {
                $pdo->rollBack();
                json_response_sp(['ok' => false, 'message' => 'Sheet tidak ditemukan atau tidak ada data valid.'], 422);
            }

            $pdo->commit();
            catat_aktivitas($pdo, 'import', 'Simpanan', "Import Excel simpanan bulan $bulan/$tahun — $totalBaris baris");
            $msg = "Import berhasil! $totalBaris data simpanan diproses untuk bulan $bulan/$tahun.";
            $_SESSION['flash_success'] = $msg;
            json_response_sp(['ok' => true, 'message' => $msg, 'total_baris' => $totalBaris, 'total_sheet' => $totalSheet]);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            json_response_sp(['ok' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 500);
        }
    }
}

// ── Query params ─────────────────────────────────────────────────────────────
$bulanNama      = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulanAktif     = (int)($_GET['bulan_filter'] ?? date('n'));
$tahunAktif     = (int)($_GET['tahun_filter'] ?? date('Y'));
$searchMember   = trim($_GET['q'] ?? '');
$kelompokFilter = $_GET['kelompok'] ?? '';

// ── Log import ───────────────────────────────────────────────────────────────
try {
    $logStmt = $pdo->query("SELECT si.*, u.nama AS imported_nama FROM simpanan_import si LEFT JOIN users u ON u.id = si.imported_by ORDER BY si.created_at DESC LIMIT 20");
    $importLog = $logStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $importLog = [];
}

// ── Ringkasan bulan aktif ─────────────────────────────────────────────────────
try {
    $sumStmt = $pdo->prepare("SELECT jenis, SUM(jumlah) AS total, COUNT(DISTINCT member_id) AS jumlah_member FROM simpanan WHERE bulan = :b AND tahun = :t GROUP BY jenis");
    $sumStmt->execute([':b' => $bulanAktif, ':t' => $tahunAktif]);
    $ringkasanBulan = [];
    foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $ringkasanBulan[$row['jenis']] = $row;
} catch (Exception $e) {
    $ringkasanBulan = [];
}

// ── Daftar simpanan per member ────────────────────────────────────────────────
try {
    $whereArr   = ['s.bulan = :b', 's.tahun = :t'];
    $bindParams = [':b' => $bulanAktif, ':t' => $tahunAktif];
    if ($searchMember !== '') {
        $whereArr[] = '(m.nama LIKE :q OR m.kode LIKE :q2)';
        $bindParams[':q'] = '%' . $searchMember . '%';
        $bindParams[':q2'] = '%' . $searchMember . '%';
    }
    if ($kelompokFilter !== '') {
        $whereArr[] = 's.keterangan = :ket';
        $bindParams[':ket'] = $kelompokFilter;
    }
    $whereStr   = implode(' AND ', $whereArr);

    $daftarStmt = $pdo->prepare("
        SELECT m.id AS member_id, m.nama AS member_nama, m.kode AS member_kode, m.no_hp, s.keterangan AS kelompok,
               SUM(CASE WHEN s.jenis='pokok'    THEN s.jumlah ELSE 0 END) AS total_pokok,
               SUM(CASE WHEN s.jenis='wajib'    THEN s.jumlah ELSE 0 END) AS total_wajib,
               SUM(CASE WHEN s.jenis='sukarela' THEN s.jumlah ELSE 0 END) AS total_sukarela,
               SUM(s.jumlah) AS grand_total, COUNT(s.id) AS jumlah_jenis
        FROM simpanan s JOIN member m ON m.id = s.member_id
        WHERE $whereStr GROUP BY m.id, m.nama, m.kode, m.no_hp, s.keterangan ORDER BY s.keterangan, m.nama ASC
    ");
    $daftarStmt->execute($bindParams);
    $daftarSimpanan = $daftarStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $daftarSimpanan = [];
}

// ── Statistik agregat ─────────────────────────────────────────────────────────
try {
    $statStmt = $pdo->prepare("SELECT COUNT(DISTINCT s.member_id) AS total_member, SUM(CASE WHEN s.jenis='pokok' THEN s.jumlah ELSE 0 END) AS total_pokok, SUM(CASE WHEN s.jenis='wajib' THEN s.jumlah ELSE 0 END) AS total_wajib, SUM(CASE WHEN s.jenis='sukarela' THEN s.jumlah ELSE 0 END) AS total_sukarela, SUM(s.jumlah) AS grand_total FROM simpanan s WHERE s.bulan = :b AND s.tahun = :t");
    $statStmt->execute([':b' => $bulanAktif, ':t' => $tahunAktif]);
    $statBulan = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $statBulan = [];
}

// ── Kelompok filter list ──────────────────────────────────────────────────────
try {
    $kelompokListStmt = $pdo->prepare("SELECT DISTINCT keterangan FROM simpanan WHERE bulan = :b AND tahun = :t AND keterangan IS NOT NULL AND keterangan <> '' ORDER BY keterangan");
    $kelompokListStmt->execute([':b' => $bulanAktif, ':t' => $tahunAktif]);
    $kelompokList = $kelompokListStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $kelompokList = [];
}

// ── Akumulasi simpanan all time ───────────────────────────────────────────────
try {
    $akumStmt = $pdo->query("SELECT m.id AS member_id, SUM(CASE WHEN s.jenis='pokok' THEN s.jumlah ELSE 0 END) AS akum_pokok, SUM(CASE WHEN s.jenis='wajib' THEN s.jumlah ELSE 0 END) AS akum_wajib, SUM(CASE WHEN s.jenis='sukarela' THEN s.jumlah ELSE 0 END) AS akum_sukarela FROM simpanan s JOIN member m ON m.id = s.member_id GROUP BY m.id");
    $akumMap = [];
    foreach ($akumStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $akumMap[(int)$row['member_id']] = $row;
} catch (Exception $e) {
    $akumMap = [];
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fafafa;
            color: #111;
        }

        /* ── Layout offset (sidebar) ── */
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

        /* ── Scrollbar ── */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* ── Subtle scrollable hint ── */
        .scroll-fade {
            position: relative;
        }

        .scroll-fade::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 40px;
            background: linear-gradient(to right, transparent, #fff);
            pointer-events: none;
        }

        /* ── Tab ── */
        .tab-btn {
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: 1px solid #e5e5e5;
            border-bottom: none;
            background: #fafafa;
            color: #9ca3af;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
            top: 1px;
        }

        .tab-btn:hover {
            color: #111;
            background: #f5f5f5;
        }

        .tab-btn.active {
            background: #fff;
            color: #111;
            border-color: #e5e5e5;
            border-bottom-color: #fff;
        }

        /* ── KPI card ── */
        .kpi-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
        }

        .kpi-card.blue::before {
            background: #3b82f6;
        }

        .kpi-card.green::before {
            background: #22c55e;
        }

        .kpi-card.purple::before {
            background: #a855f7;
        }

        .kpi-card.gray::before {
            background: #6b7280;
        }

        /* ── Table ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 12px 16px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #9ca3af;
            background: #f9f9f9;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }

        .data-table td {
            padding: 14px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f7f7f7;
            vertical-align: middle;
        }

        .data-table tbody tr:hover {
            background: #fafafa;
        }

        .data-table tfoot td {
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 800;
            background: #f9f9f9;
            border-top: 2px solid #111;
        }

        /* ── Badge kelompok ── */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid;
        }

        .badge-pns {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .badge-pppk {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .badge-ppnpn {
            background: #fefce8;
            color: #92400e;
            border-color: #fde68a;
        }

        .badge-nonpns {
            background: #fdf4ff;
            color: #6b21a8;
            border-color: #e9d5ff;
        }

        /* ── Mobile member card ── */
        .member-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 16px;
            margin-bottom: 8px;
            transition: border-color 0.15s;
        }

        .member-card:hover {
            border-color: #e5e5e5;
        }

        .simpanan-pill {
            padding: 8px 12px;
            border-radius: 0;
            font-size: 11px;
        }

        /* ── Grand total bar ── */
        .grand-bar {
            background: #111;
            color: #fff;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        /* ── Form input ── */
        .form-input {
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
            padding: 10px 14px;
            font-size: 13px;
            font-family: inherit;
            font-weight: 600;
            width: 100%;
            transition: all 0.15s;
            appearance: none;
            -webkit-appearance: none;
        }

        .form-input:focus {
            outline: none;
            border-color: #111;
            background: #fff;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        /* ── Upload zone ── */
        .upload-zone {
            border: 2px dashed #e5e5e5;
            padding: 32px 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .upload-zone:hover {
            border-color: #111;
            background: #fafafa;
        }

        .upload-zone.has-file {
            border-color: #22c55e;
            background: #f0fdf4;
            border-style: solid;
        }

        /* ── Alert ── */
        .alert {
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        /* ── Btn ── */
        .btn-primary {
            background: #111;
            color: #fff;
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-primary:hover {
            background: #333;
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .btn-outline {
            background: #fff;
            color: #111;
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: 1px solid #e5e5e5;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-outline:hover {
            background: #f5f5f5;
        }

        .btn-success {
            background: #15803d;
            color: #fff;
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-success:hover {
            background: #166534;
        }

        .btn-success:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .btn-danger-outline {
            background: #fff;
            color: #dc2626;
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: 1px solid #fecaca;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-danger-outline:hover {
            background: #fef2f2;
        }

        /* ── Log item ── */
        .log-item {
            padding: 14px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        /* ── Status pill ── */
        .status-pill {
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 3px 8px;
            border: 1px solid;
        }

        .status-selesai {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .status-proses {
            background: #fefce8;
            color: #92400e;
            border-color: #fde68a;
        }

        .status-gagal {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        /* ── Progress bar ── */
        .prog-track {
            height: 3px;
            background: #f0f0f0;
            overflow: hidden;
        }

        .prog-fill {
            height: 3px;
            background: #d1d5db;
            transition: width 0.3s;
        }

        /* ── Section header ── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .section-title {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #9ca3af;
        }

        /* ── Info box ── */
        .info-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 14px 16px;
            font-size: 11px;
            font-weight: 600;
            color: #78350f;
            line-height: 1.6;
        }

        /* ── Preview modal ── */
        #preview-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 200;
        }

        #preview-modal.open {
            display: flex;
            flex-direction: column;
        }

        .modal-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal-inner {
            position: relative;
            width: 100%;
            height: 100%;
            background: #fff;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .preview-th {
            padding: 10px 14px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #9ca3af;
            background: #f9f9f9;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap;
        }

        .preview-td {
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            border-bottom: 1px solid #f7f7f7;
            vertical-align: middle;
        }

        #preview-tbody tr:hover {
            background: #fafafa;
        }

        /* ── Scroll indicator on table ── */
        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-wrap::-webkit-scrollbar {
            height: 3px;
        }

        .table-wrap::-webkit-scrollbar-track {
            background: #f0f0f0;
        }

        .table-wrap::-webkit-scrollbar-thumb {
            background: #d1d5db;
        }

        /* ── Divider antar kelompok ── */
        .group-divider td {
            padding: 6px 16px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #9ca3af;
            background: #f9f9f9;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .content {
                margin-left: 0 !important;
            }
        }
    </style>
</head>

<body class="antialiased pb-24 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">

        <!-- ── Alert JS ── -->
        <div id="js-alert" style="display:none" class="alert"></div>

        <?php if ($success): ?>
            <div class="alert alert-success"><span>&#10003;</span><?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><span>&#9888;</span><?php echo h($error); ?></div>
        <?php endif; ?>

        <!-- ── Tab navigasi ───────────────────────────────────────────────────── -->
        <div class="flex gap-0 border-b border-gray-200 mb-0 no-print">
            <button class="tab-btn active" id="btn-tab-daftar" onclick="switchTab('tab-daftar')">
                Daftar Simpanan
            </button>
            <button class="tab-btn" id="btn-tab-import" onclick="switchTab('tab-import')">
                Import Excel
            </button>
        </div>


        <!-- ════════════════════════════════════════════════════════════════════
         TAB 1: DAFTAR SIMPANAN
    ════════════════════════════════════════════════════════════════════ -->
        <div id="tab-daftar" class="pt-6">

            <!-- Filter periode ─────────────────────────────────────────────── -->
            <form method="GET" class="bg-white border border-gray-100 p-4 sm:p-5 mb-6 no-print">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">Filter Periode</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Bulan</label>
                        <select name="bulan_filter" class="form-input form-select" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $bulanAktif ? 'selected' : ''; ?>><?php echo $bulanNama[$m]; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tahun</label>
                        <select name="tahun_filter" class="form-input form-select" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y'); $y >= 2023; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $tahunAktif ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if (!empty($kelompokList)): ?>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kelompok</label>
                            <select name="kelompok" class="form-input form-select" onchange="this.form.submit()">
                                <option value="">Semua</option>
                                <?php foreach ($kelompokList as $kel): ?>
                                    <option value="<?php echo h($kel); ?>" <?php echo $kelompokFilter === $kel ? 'selected' : ''; ?>><?php echo h($kel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="<?php echo empty($kelompokList) ? 'col-span-2 sm:col-span-2' : ''; ?>">
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Member</label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input type="text" name="q" value="<?php echo h($searchMember); ?>" placeholder="Nama / kode..." class="form-input pl-9">
                            </div>
                            <button type="submit" class="btn-primary px-4 flex-shrink-0">Cari</button>
                            <?php if ($searchMember || $kelompokFilter): ?>
                                <a href="?bulan_filter=<?php echo $bulanAktif; ?>&tahun_filter=<?php echo $tahunAktif; ?>" class="btn-outline px-4 flex-shrink-0">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <!-- KPI Cards ──────────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="kpi-card gray">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Total Member</p>
                    <p class="text-2xl font-black text-gray-900"><?php echo number_format($statBulan['total_member'] ?? 0); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1 font-medium"><?php echo $bulanNama[$bulanAktif] . ' ' . $tahunAktif; ?></p>
                </div>
                <div class="kpi-card blue">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Simpanan Pokok</p>
                    <p class="text-xl font-black text-blue-600 leading-tight"><?php echo rupiah_sp($statBulan['total_pokok'] ?? 0); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1 font-medium">Bulan ini</p>
                </div>
                <div class="kpi-card green">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Simpanan Wajib</p>
                    <p class="text-xl font-black text-green-600 leading-tight"><?php echo rupiah_sp($statBulan['total_wajib'] ?? 0); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1 font-medium">Bulan ini</p>
                </div>
                <div class="kpi-card purple">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Simpanan Sukarela</p>
                    <p class="text-xl font-black text-purple-600 leading-tight"><?php echo rupiah_sp($statBulan['total_sukarela'] ?? 0); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1 font-medium">Bulan ini</p>
                </div>
            </div>

            <!-- Grand total bar ─────────────────────────────────────────────── -->
            <?php if (!empty($statBulan['grand_total'])): ?>
                <div class="grand-bar mb-6">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-widest opacity-50 mb-1">Grand Total Simpanan</p>
                        <p class="text-3xl font-black tracking-tight"><?php echo rupiah_sp($statBulan['grand_total']); ?></p>
                    </div>
                    <div class="flex gap-8 text-right">
                        <div>
                            <p class="text-2xl font-black"><?php echo number_format($statBulan['total_member'] ?? 0); ?></p>
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-50 mt-0.5">Member</p>
                        </div>
                        <div>
                            <p class="text-xl font-black"><?php echo $bulanNama[$bulanAktif]; ?></p>
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-50 mt-0.5"><?php echo $tahunAktif; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ── DESKTOP: Tabel ─────────────────────────────────────────── -->
            <?php if (empty($daftarSimpanan)): ?>
                <div class="bg-white border border-gray-100 py-24 text-center">
                    <svg class="w-10 h-10 text-gray-200 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-300">Belum ada data simpanan untuk periode ini</p>
                    <p class="text-[11px] text-gray-400 mt-2">Silakan import file Excel atau ubah filter periode</p>
                    <button onclick="switchTab('tab-import')" class="btn-primary mt-6 mx-auto">Import Sekarang</button>
                </div>
            <?php else: ?>

                <!-- Desktop table -->
                <div class="hidden lg:block bg-white border border-gray-100 overflow-hidden mb-8">
                    <div class="section-header">
                        <span class="section-title"><?php echo count($daftarSimpanan); ?> data ditemukan</span>
                        <span class="text-[10px] font-bold text-gray-400"><?php echo $bulanNama[$bulanAktif] . ' ' . $tahunAktif; ?></span>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table" style="min-width: 860px">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:40px">#</th>
                                    <th>Member</th>
                                    <th>Kelompok</th>
                                    <th class="text-right">Pokok</th>
                                    <th class="text-right">Wajib</th>
                                    <th class="text-right">Sukarela</th>
                                    <th class="text-right">Total Bulan</th>
                                    <th class="text-right">Akumulasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $gPokok = $gWajib = $gSukarela = $gTotal = 0;
                                $no = 0;
                                $kelompokSebelum = null;
                                foreach ($daftarSimpanan as $row):
                                    $no++;
                                    $gPokok    += (int)$row['total_pokok'];
                                    $gWajib    += (int)$row['total_wajib'];
                                    $gSukarela += (int)$row['total_sukarela'];
                                    $gTotal    += (int)$row['grand_total'];
                                    $akum      = $akumMap[(int)$row['member_id']] ?? [];
                                    $akumTotal = (int)($akum['akum_pokok'] ?? 0) + (int)($akum['akum_wajib'] ?? 0) + (int)($akum['akum_sukarela'] ?? 0);
                                    $kel       = strtoupper(trim($row['kelompok'] ?? ''));
                                    $bdg       = str_contains($kel, 'PPPK') ? 'badge-pppk' : (str_contains($kel, 'NON') ? 'badge-nonpns' : (str_contains($kel, 'PPNPN') ? 'badge-ppnpn' : 'badge-pns'));

                                    if ($kelompokFilter === '' && $kelompokSebelum !== null && $kelompokSebelum !== $row['kelompok']):
                                ?>
                                        <tr class="group-divider">
                                            <td colspan="8"><?php echo h($kelompokSebelum); ?></td>
                                        </tr>
                                    <?php
                                    endif;
                                    $kelompokSebelum = $row['kelompok'];
                                    ?>
                                    <tr>
                                        <td class="text-center text-gray-300 text-[11px] font-bold"><?php echo $no; ?></td>
                                        <td>
                                            <p class="font-bold text-sm leading-tight"><?php echo h($row['member_nama']); ?></p>
                                            <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo h($row['member_kode']); ?> · <?php echo h($row['no_hp'] ?? '—'); ?></p>
                                        </td>
                                        <td><span class="badge <?php echo $bdg; ?>"><?php echo h($row['kelompok'] ?? '—'); ?></span></td>
                                        <td class="text-right"><?php echo (int)$row['total_pokok'] > 0 ? '<span class="font-bold text-blue-700">' . rupiah_sp($row['total_pokok']) . '</span>' : '<span class="text-gray-300">—</span>'; ?></td>
                                        <td class="text-right"><?php echo (int)$row['total_wajib'] > 0 ? '<span class="font-bold text-green-700">' . rupiah_sp($row['total_wajib']) . '</span>' : '<span class="text-gray-300">—</span>'; ?></td>
                                        <td class="text-right"><?php echo (int)$row['total_sukarela'] > 0 ? '<span class="font-bold text-purple-700">' . rupiah_sp($row['total_sukarela']) . '</span>' : '<span class="text-gray-300">—</span>'; ?></td>
                                        <td class="text-right"><span class="font-black"><?php echo rupiah_sp($row['grand_total']); ?></span></td>
                                        <td class="text-right">
                                            <span class="font-bold text-gray-500 text-[12px]"><?php echo rupiah_sp($akumTotal); ?></span>
                                            <?php if ($akumTotal > 0): $pct = min(100, round(((int)$row['grand_total'] / $akumTotal) * 100)); ?>
                                                <div class="prog-track mt-1.5 w-16 ml-auto">
                                                    <div class="prog-fill" style="width:<?php echo $pct; ?>%"></div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">Total <?php echo number_format($no); ?> Member</td>
                                    <td class="text-right text-blue-700"><?php echo rupiah_sp($gPokok); ?></td>
                                    <td class="text-right text-green-700"><?php echo rupiah_sp($gWajib); ?></td>
                                    <td class="text-right text-purple-700"><?php echo rupiah_sp($gSukarela); ?></td>
                                    <td class="text-right text-gray-900"><?php echo rupiah_sp($gTotal); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Mobile & Tablet cards -->
                <div class="lg:hidden mb-8">
                    <!-- Mini summary -->
                    <div class="grid grid-cols-3 gap-2 mb-4">
                        <div class="bg-blue-50 border border-blue-100 p-3 text-center">
                            <p class="text-[9px] font-black uppercase text-blue-500 mb-1">Pokok</p>
                            <p class="text-sm font-black text-blue-700 leading-tight"><?php echo rupiah_sp($statBulan['total_pokok'] ?? 0); ?></p>
                        </div>
                        <div class="bg-green-50 border border-green-100 p-3 text-center">
                            <p class="text-[9px] font-black uppercase text-green-500 mb-1">Wajib</p>
                            <p class="text-sm font-black text-green-700 leading-tight"><?php echo rupiah_sp($statBulan['total_wajib'] ?? 0); ?></p>
                        </div>
                        <div class="bg-purple-50 border border-purple-100 p-3 text-center">
                            <p class="text-[9px] font-black uppercase text-purple-500 mb-1">Sukarela</p>
                            <p class="text-sm font-black text-purple-700 leading-tight"><?php echo rupiah_sp($statBulan['total_sukarela'] ?? 0); ?></p>
                        </div>
                    </div>

                    <?php
                    $kelompokSebelum = null;
                    foreach ($daftarSimpanan as $i => $row):
                        $akum      = $akumMap[(int)$row['member_id']] ?? [];
                        $akumTotal = (int)($akum['akum_pokok'] ?? 0) + (int)($akum['akum_wajib'] ?? 0) + (int)($akum['akum_sukarela'] ?? 0);
                        $kel       = strtoupper(trim($row['kelompok'] ?? ''));
                        $bdg       = str_contains($kel, 'PPPK') ? 'badge-pppk' : (str_contains($kel, 'NON') ? 'badge-nonpns' : (str_contains($kel, 'PPNPN') ? 'badge-ppnpn' : 'badge-pns'));

                        // Kelompok separator mobile
                        if ($kelompokFilter === '' && $kelompokSebelum !== null && $kelompokSebelum !== $row['kelompok']):
                    ?>
                            <div class="px-2 py-2 mb-2 mt-4">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 border-b border-gray-200 pb-2"><?php echo h($kelompokSebelum); ?></p>
                            </div>
                        <?php
                        endif;
                        $kelompokSebelum = $row['kelompok'];
                        ?>
                        <div class="member-card">
                            <!-- Header -->
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-[10px] text-gray-300 font-black w-5 flex-shrink-0"><?php echo $i + 1; ?></span>
                                        <span class="badge <?php echo $bdg; ?>"><?php echo h($row['kelompok'] ?? '—'); ?></span>
                                    </div>
                                    <p class="font-bold text-sm leading-tight"><?php echo h($row['member_nama']); ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo h($row['member_kode']); ?></p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[9px] font-bold uppercase text-gray-400 mb-0.5">Total Bulan</p>
                                    <p class="text-base font-black"><?php echo rupiah_sp($row['grand_total']); ?></p>
                                </div>
                            </div>

                            <!-- Breakdown 3 kolom -->
                            <div class="grid grid-cols-3 gap-2 mb-3">
                                <div class="bg-blue-50 border border-blue-100 p-2.5">
                                    <p class="text-[9px] font-black uppercase text-blue-400 mb-1">Pokok</p>
                                    <p class="text-xs font-black text-blue-700"><?php echo (int)$row['total_pokok'] > 0 ? rupiah_sp($row['total_pokok']) : '<span class="text-blue-300">—</span>'; ?></p>
                                </div>
                                <div class="bg-green-50 border border-green-100 p-2.5">
                                    <p class="text-[9px] font-black uppercase text-green-400 mb-1">Wajib</p>
                                    <p class="text-xs font-black text-green-700"><?php echo (int)$row['total_wajib'] > 0 ? rupiah_sp($row['total_wajib']) : '<span class="text-green-300">—</span>'; ?></p>
                                </div>
                                <div class="bg-purple-50 border border-purple-100 p-2.5">
                                    <p class="text-[9px] font-black uppercase text-purple-400 mb-1">Sukarela</p>
                                    <p class="text-xs font-black text-purple-700"><?php echo (int)$row['total_sukarela'] > 0 ? rupiah_sp($row['total_sukarela']) : '<span class="text-purple-300">—</span>'; ?></p>
                                </div>
                            </div>

                            <!-- Akumulasi -->
                            <?php if ($akumTotal > 0): $pct = min(100, round(((int)$row['grand_total'] / $akumTotal) * 100)); ?>
                                <div class="border-t border-gray-100 pt-3 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-[9px] font-bold uppercase text-gray-400">Akumulasi All Time</p>
                                        <p class="text-xs font-bold text-gray-600 mt-0.5"><?php echo rupiah_sp($akumTotal); ?></p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-[9px] font-bold text-gray-400"><?php echo $pct; ?>% bulan ini</p>
                                        <div class="prog-track mt-1 w-20 ml-auto">
                                            <div class="prog-fill bg-gray-400" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Mobile footer total -->
                    <div class="grand-bar mt-4">
                        <div>
                            <p class="text-[9px] font-bold uppercase opacity-50 mb-1">Total <?php echo count($daftarSimpanan); ?> Member</p>
                            <p class="text-2xl font-black"><?php echo rupiah_sp($statBulan['grand_total'] ?? 0); ?></p>
                        </div>
                        <div class="text-right text-[10px] font-bold opacity-60 space-y-1">
                            <p>Pokok: <?php echo rupiah_sp($statBulan['total_pokok'] ?? 0); ?></p>
                            <p>Wajib: <?php echo rupiah_sp($statBulan['total_wajib'] ?? 0); ?></p>
                            <p>Sukarela: <?php echo rupiah_sp($statBulan['total_sukarela'] ?? 0); ?></p>
                        </div>
                    </div>
                </div>

            <?php endif; // end empty check 
            ?>
        </div><!-- /tab-daftar -->


        <!-- ════════════════════════════════════════════════════════════════════
         TAB 2: IMPORT EXCEL
    ════════════════════════════════════════════════════════════════════ -->
        <div id="tab-import" class="hidden pt-6">

            <!-- KPI ringkasan periode ──────────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                <?php
                $jenisInfo = [
                    'pokok'    => ['label' => 'Simpanan Pokok',    'cls' => 'blue'],
                    'wajib'    => ['label' => 'Simpanan Wajib',    'cls' => 'green'],
                    'sukarela' => ['label' => 'Simpanan Sukarela', 'cls' => 'purple'],
                ];
                foreach ($jenisInfo as $jenis => $info):
                    $data = $ringkasanBulan[$jenis] ?? ['total' => 0, 'jumlah_member' => 0];
                    $colorMap = ['blue' => 'text-blue-600', 'green' => 'text-green-600', 'purple' => 'text-purple-600'];
                ?>
                    <div class="kpi-card <?php echo $info['cls']; ?>">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2"><?php echo $info['label']; ?></p>
                        <p class="text-xl font-black <?php echo $colorMap[$info['cls']]; ?> leading-tight"><?php echo rupiah_sp($data['total']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1 font-medium"><?php echo number_format($data['jumlah_member']); ?> member · <?php echo $bulanNama[(int)date('n')] . ' ' . date('Y'); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Form upload ─────────────────────────────────────────────── -->
                <div class="bg-white border border-gray-100">
                    <div class="section-header">
                        <span class="section-title">Import File Excel</span>
                    </div>
                    <div class="p-5 sm:p-6">

                        <div class="info-box mb-5">
                            <p class="font-black mb-2 text-[10px] uppercase tracking-widest">Format yang didukung:</p>
                            <ul class="space-y-1 list-disc list-inside text-[11px]">
                                <li>Sheet: <strong>PNS, PPPK, PPNPN, NON PPNPN</strong></li>
                                <li>Kolom wajib: <strong>NAMA PEGAWAI, WAJIB, POKOK, SUKARELA</strong></li>
                                <li>Nama member di Excel harus sesuai data member sistem</li>
                                <li>File rekap koperasi BSDK format .xlsx langsung bisa digunakan</li>
                            </ul>
                        </div>

                        <form id="excel-form" onsubmit="return false;">
                            <!-- Bulan & Tahun -->
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Bulan *</label>
                                    <select id="bulan" class="form-input form-select" required>
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo $m; ?>" <?php echo $m === (int)date('n') ? 'selected' : ''; ?>><?php echo $bulanNama[$m]; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Tahun *</label>
                                    <select id="tahun" class="form-input form-select" required>
                                        <?php for ($y = (int)date('Y'); $y >= 2023; $y--): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $y === (int)date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Upload zone -->
                            <div class="mb-5">
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">File Excel (.xlsx) *</label>
                                <input type="file" accept=".xlsx,.xls" class="hidden" id="file-input" onchange="handleExcelFile(this)">
                                <label for="file-input" class="upload-zone block" id="upload-label">
                                    <svg class="w-8 h-8 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p id="file-label" class="text-[11px] font-black uppercase tracking-widest text-gray-400">Klik untuk pilih file Excel</p>
                                    <p class="text-[10px] text-gray-300 mt-1">Format rekap BSDK · .xlsx</p>
                                </label>
                            </div>

                            <button type="button" onclick="parseSelectedExcel()" class="btn-primary w-full py-3">
                                Upload &amp; Preview Data
                            </button>
                        </form>

                        <!-- Ready state -->
                        <div id="import-ready" class="hidden mt-5 border border-green-200 bg-green-50 p-4">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-green-700 mb-1">File siap diimport</p>
                                    <p id="ready-file-name" class="text-sm font-bold text-green-900"></p>
                                </div>
                                <span class="text-green-500 text-lg">&#10003;</span>
                            </div>
                            <p id="ready-period" class="text-[11px] text-green-700 font-semibold"></p>
                            <p id="ready-summary" class="text-[11px] text-green-600 mt-0.5"></p>
                            <div class="grid grid-cols-2 gap-2 mt-4">
                                <button type="button" onclick="batalImport()" class="btn-outline py-2.5">Batal</button>
                                <button type="button" id="btn-import" onclick="confirmImport()" class="btn-success py-2.5">Konfirmasi &amp; Import</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Log import ──────────────────────────────────────────────── -->
                <div class="bg-white border border-gray-100">
                    <div class="section-header">
                        <span class="section-title">Riwayat Import</span>
                        <span class="text-[10px] font-bold text-gray-400"><?php echo count($importLog); ?> entri</span>
                    </div>
                    <div class="p-5 sm:p-6">
                        <?php if (empty($importLog)): ?>
                            <div class="py-12 text-center">
                                <svg class="w-8 h-8 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p class="text-[11px] font-bold uppercase tracking-widest text-gray-300">Belum ada riwayat import</p>
                            </div>
                        <?php else: ?>
                            <div style="max-height: 420px; overflow-y: auto;" class="no-scrollbar">
                                <?php foreach ($importLog as $log):
                                    $bln = (int)$log['bulan'];
                                    $statusCls = $log['status'] === 'selesai' ? 'status-selesai' : ($log['status'] === 'gagal' ? 'status-gagal' : 'status-proses');
                                ?>
                                    <div class="log-item">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-bold text-gray-900 truncate"><?php echo h($log['filename']); ?></p>
                                                <p class="text-[10px] text-gray-400 mt-0.5 font-medium">
                                                    Sheet: <strong><?php echo h($log['sheet_name']); ?></strong> ·
                                                    <?php echo $bulanNama[$bln] . ' ' . $log['tahun']; ?>
                                                </p>
                                                <div class="flex items-center gap-3 mt-1">
                                                    <p class="text-[10px] text-gray-400"><?php echo number_format($log['jumlah_baris']); ?> baris</p>
                                                    <p class="text-[10px] text-gray-400"><?php echo h($log['imported_nama'] ?? 'Sistem'); ?></p>
                                                    <p class="text-[10px] text-gray-400"><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></p>
                                                </div>
                                            </div>
                                            <span class="status-pill <?php echo $statusCls; ?> flex-shrink-0"><?php echo ucfirst(h($log['status'])); ?></span>
                                        </div>
                                        <?php if (!empty($log['catatan'])): ?>
                                            <p class="text-[10px] text-gray-400 mt-2 pl-0"><?php echo h($log['catatan']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div><!-- /tab-import -->

    </main>


    <!-- ══════════════════════════════════════════════════════════════════════════
     MODAL PREVIEW
══════════════════════════════════════════════════════════════════════════ -->
    <div id="preview-modal">
        <div class="modal-overlay" onclick="closePreviewModal()"></div>
        <div class="modal-inner">

            <!-- Header modal -->
            <div class="flex items-start justify-between gap-4 px-4 sm:px-6 py-4 border-b border-gray-100 flex-shrink-0">
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-900 mb-0.5">Preview Import Simpanan</p>
                    <p id="preview-meta" class="text-[10px] text-gray-400 font-semibold truncate">Memuat...</p>
                </div>
                <button onclick="closePreviewModal()" class="w-9 h-9 border border-gray-200 text-gray-500 hover:bg-gray-50 text-lg leading-none flex-shrink-0 flex items-center justify-center">&times;</button>
            </div>

            <!-- Info banner -->
            <div class="px-4 sm:px-6 py-3 bg-amber-50 border-b border-amber-100 flex-shrink-0">
                <p class="text-[10px] font-semibold text-amber-700">Preview hanya menampilkan data dari Excel. Saat konfirmasi, nama akan dicocokkan dengan database member.</p>
            </div>

            <!-- Toolbar -->
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100 flex-shrink-0 bg-white">
                <div class="flex flex-col sm:flex-row gap-3">
                    <input type="text" id="preview-search" oninput="previewSearchChanged()" placeholder="Cari nama / sheet..." class="form-input flex-1" style="padding: 8px 14px; font-size:12px;">
                    <select id="preview-page-size" onchange="changePreviewPageSize(this.value)" class="form-input form-select" style="width:auto; padding: 8px 36px 8px 14px; font-size:12px;">
                        <option value="50">50 / hal</option>
                        <option value="100" selected>100 / hal</option>
                        <option value="250">250 / hal</option>
                    </select>
                </div>
                <div class="flex items-center justify-between gap-3 mt-3">
                    <label class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500 cursor-pointer">
                        <input type="checkbox" id="preview-select-all" onchange="toggleSelectAllVisible(this.checked)" class="w-4 h-4 accent-black">
                        Pilih semua
                    </label>
                    <div class="flex items-center gap-3">
                        <span id="preview-page-info" class="text-[10px] font-bold text-gray-400"></span>
                        <span id="preview-selected-count" class="text-[10px] font-bold text-gray-400">0 dipilih</span>
                        <button type="button" onclick="deleteSelectedPreviewRows()" id="btn-delete-selected" class="btn-danger-outline py-1.5 px-3 text-[9px]" disabled>Hapus</button>
                    </div>
                </div>
            </div>

            <!-- Table preview -->
            <div class="flex-1 overflow-auto">
                <div class="table-wrap h-full">
                    <table style="width:100%; border-collapse:collapse; min-width:820px;">
                        <thead style="position:sticky; top:0; z-index:10; background:#f9f9f9;">
                            <tr>
                                <th class="preview-th" style="width:40px"></th>
                                <th class="preview-th" style="width:48px">No</th>
                                <th class="preview-th" style="width:110px">Sheet</th>
                                <th class="preview-th">Nama Pegawai</th>
                                <th class="preview-th text-right" style="width:130px">Wajib</th>
                                <th class="preview-th text-right" style="width:130px">Pokok</th>
                                <th class="preview-th text-right" style="width:130px">Sukarela</th>
                                <th class="preview-th text-center" style="width:80px">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="preview-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Footer modal -->
            <div class="px-4 sm:px-6 py-4 border-t border-gray-100 bg-gray-50 flex-shrink-0">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
                    <!-- Pagination -->
                    <div class="flex items-center gap-2">
                        <button onclick="changePreviewPage(-1)" id="btn-preview-prev" class="btn-outline py-2 px-4 text-[9px]" disabled>← Prev</button>
                        <span id="preview-pagination-label" class="text-[10px] font-bold text-gray-500 min-w-[100px] text-center">Hal 1 / 1</span>
                        <button onclick="changePreviewPage(1)" id="btn-preview-next" class="btn-outline py-2 px-4 text-[9px]" disabled>Next →</button>
                    </div>
                    <!-- Actions -->
                    <div class="flex gap-2 w-full sm:w-auto">
                        <button onclick="closePreviewModal()" class="btn-outline py-2.5 flex-1 sm:flex-none sm:px-5 text-[9px]">Cek Lagi</button>
                        <button onclick="batalImport(); closePreviewModal();" class="btn-danger-outline py-2.5 flex-1 sm:flex-none sm:px-5 text-[9px]">Batal</button>
                        <button id="btn-import-modal" onclick="confirmImport()" class="btn-success py-2.5 flex-1 sm:flex-none sm:px-5 text-[9px]">Konfirmasi &amp; Import</button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script>
        /* ── Tab switcher ─────────────────────────────────────────────────────────── */
        function switchTab(tabId) {
            ['tab-daftar', 'tab-import'].forEach(function(id) {
                var el = document.getElementById(id);
                var btn = document.getElementById('btn-' + id);
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
            if (new URLSearchParams(window.location.search).get('tab') === 'import') switchTab('tab-import');
        }());

        /* ── Alert helper ─────────────────────────────────────────────────────────── */
        function showAlert(type, message) {
            var box = document.getElementById('js-alert');
            box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
            box.innerHTML = '<span>' + (type === 'success' ? '&#10003;' : '&#9888;') + '</span><span>' + escapeHtml(message) + '</span>';
            box.style.display = 'flex';
            box.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function escapeHtml(v) {
            return String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        /* ── Excel import logic ───────────────────────────────────────────────────── */
        var selectedExcelFile = null;
        var importPayload = null;
        var selectedPreviewRows = {};
        var previewPage = 1;
        var previewPageSize = 100;
        var previewCurrentKeys = [];
        var bulanNama = <?php echo json_encode($bulanNama); ?>;

        function handleExcelFile(input) {
            selectedExcelFile = (input.files && input.files[0]) ? input.files[0] : null;
            importPayload = null;
            selectedPreviewRows = {};
            previewPage = 1;
            var lbl = document.getElementById('file-label');
            var zone = document.getElementById('upload-label');
            if (selectedExcelFile) {
                lbl.textContent = selectedExcelFile.name;
                zone.classList.add('has-file');
            } else {
                lbl.textContent = 'Klik untuk pilih file Excel';
                zone.classList.remove('has-file');
            }
            document.getElementById('import-ready').classList.add('hidden');
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

        function parseSelectedExcel() {
            if (!selectedExcelFile) {
                showAlert('error', 'Silakan pilih file Excel terlebih dahulu.');
                return;
            }
            var ext = selectedExcelFile.name.split('.').pop().toLowerCase();
            if (['xlsx', 'xls'].indexOf(ext) === -1) {
                showAlert('error', 'File harus berformat .xlsx atau .xls');
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = new Uint8Array(e.target.result);
                    var wb = XLSX.read(data, {
                        type: 'array'
                    });
                    var targetUpper = ['PNS', 'PPPK', 'PPNPN', 'NON PPNPN'];
                    var sheets = [];
                    var totalRows = 0;

                    wb.SheetNames.forEach(function(sheetName) {
                        var sn = String(sheetName || '').trim();
                        if (targetUpper.indexOf(sn.toUpperCase()) === -1) return;
                        var ws = wb.Sheets[sheetName];
                        var rows = XLSX.utils.sheet_to_json(ws, {
                            header: 1,
                            defval: ''
                        });
                        if (!rows.length) return;

                        var headerRow = -1;
                        for (var r = 0; r < Math.min(10, rows.length); r++) {
                            for (var c = 0; c < Math.min(5, (rows[r] || []).length); c++) {
                                if (normalizeCell(rows[r][c]).toUpperCase().indexOf('NAMA PEGAWAI') !== -1) {
                                    headerRow = r;
                                    break;
                                }
                            }
                            if (headerRow !== -1) break;
                        }
                        if (headerRow === -1) return;

                        var subRow = headerRow + 1;
                        var maxCols = 0;
                        rows.forEach(function(r) {
                            if (r.length > maxCols) maxCols = r.length;
                        });

                        var colNama = -1,
                            colWajib = -1,
                            colPokok = -1,
                            colSukarela = -1;
                        for (var col = 0; col < maxCols; col++) {
                            var h = normalizeCell((rows[headerRow] || [])[col]).toUpperCase();
                            var s = normalizeCell((rows[subRow] || [])[col]).toUpperCase();
                            if (h.indexOf('NAMA') !== -1 || s.indexOf('NAMA') !== -1) colNama = col;
                            if (colWajib === -1 && s.indexOf('WAJIB') !== -1) colWajib = col;
                            if (colPokok === -1 && s.indexOf('POKOK') !== -1) colPokok = col;
                            if (colSukarela === -1 && s.indexOf('SUKARELA') !== -1) colSukarela = col;
                        }
                        if (colNama === -1) return;

                        var dataRows = [];
                        for (var i = subRow + 1; i < rows.length; i++) {
                            var row = rows[i] || [];
                            var nama = normalizeCell(row[colNama]);
                            if (!nama || !isNaN(nama)) continue;
                            var item = {
                                nama: nama,
                                wajib: colWajib > -1 ? toNumber(row[colWajib]) : 0,
                                pokok: colPokok > -1 ? toNumber(row[colPokok]) : 0,
                                sukarela: colSukarela > -1 ? toNumber(row[colSukarela]) : 0
                            };
                            if (item.wajib > 0 || item.pokok > 0 || item.sukarela > 0) dataRows.push(item);
                        }
                        if (dataRows.length > 0) {
                            sheets.push({
                                sheet_name: sn,
                                rows: dataRows
                            });
                            totalRows += dataRows.length;
                        }
                    });

                    if (!sheets.length) {
                        showAlert('error', 'Data tidak ditemukan. Pastikan sheet dan header sesuai format.');
                        return;
                    }

                    var bulan = parseInt(document.getElementById('bulan').value, 10);
                    var tahun = parseInt(document.getElementById('tahun').value, 10);
                    importPayload = {
                        action: 'import_json',
                        filename: selectedExcelFile.name,
                        bulan: bulan,
                        tahun: tahun,
                        sheets: sheets
                    };

                    document.getElementById('ready-file-name').textContent = selectedExcelFile.name;
                    document.getElementById('ready-period').textContent = 'Periode: ' + bulanNama[bulan] + ' ' + tahun;
                    document.getElementById('ready-summary').textContent = sheets.length + ' sheet · ' + totalRows + ' baris siap dicek ke member';
                    document.getElementById('import-ready').classList.remove('hidden');

                    selectedPreviewRows = {};
                    previewPage = 1;
                    if (document.getElementById('preview-search')) document.getElementById('preview-search').value = '';
                    renderPreviewModal(importPayload);
                    openPreviewModal();
                    showAlert('success', 'File berhasil dibaca. Silakan periksa data sebelum konfirmasi import.');
                } catch (err) {
                    showAlert('error', 'Gagal membaca file: ' + err.message);
                }
            };
            reader.readAsArrayBuffer(selectedExcelFile);
        }

        function formatNumber(v) {
            return parseInt(v || 0, 10).toLocaleString('id-ID');
        }

        function openPreviewModal() {
            document.getElementById('preview-modal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closePreviewModal() {
            document.getElementById('preview-modal').classList.remove('open');
            document.body.style.overflow = '';
        }

        function getPreviewRows(payload) {
            var rows = [];
            if (!payload || !payload.sheets) return rows;
            payload.sheets.forEach(function(sheet, si) {
                sheet.rows.forEach(function(row, ri) {
                    rows.push({
                        key: si + ':' + ri,
                        sheetIndex: si,
                        rowIndex: ri,
                        sheetName: sheet.sheet_name,
                        row: row
                    });
                });
            });
            return rows;
        }

        function getFilteredPreviewRows(payload) {
            var kw = (document.getElementById('preview-search') || {
                value: ''
            }).value.toLowerCase().trim();
            var rows = getPreviewRows(payload);
            if (!kw) return rows;
            return rows.filter(function(item) {
                return (String(item.sheetName || '') + ' ' + String(item.row.nama || '')).toLowerCase().indexOf(kw) !== -1;
            });
        }

        function previewSearchChanged() {
            previewPage = 1;
            renderPreviewModal(importPayload);
        }

        function changePreviewPageSize(v) {
            previewPageSize = parseInt(v || 100, 10);
            previewPage = 1;
            renderPreviewModal(importPayload);
        }

        function changePreviewPage(d) {
            var total = Math.max(1, Math.ceil(getFilteredPreviewRows(importPayload).length / previewPageSize));
            previewPage = Math.min(total, Math.max(1, previewPage + d));
            renderPreviewModal(importPayload);
        }

        function renderPreviewModal(payload) {
            var tbody = document.getElementById('preview-tbody');
            var meta = document.getElementById('preview-meta');
            var allRows = getPreviewRows(payload);
            var filteredRows = getFilteredPreviewRows(payload);

            if (!payload || !payload.sheets) {
                tbody.innerHTML = '<tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:12px;">Tidak ada data preview</td></tr>';
                meta.textContent = 'Belum ada data';
                updatePaginationControls(0, 1, 1, []);
                return;
            }

            var totalPages = Math.max(1, Math.ceil(filteredRows.length / previewPageSize));
            if (previewPage > totalPages) previewPage = totalPages;
            if (previewPage < 1) previewPage = 1;

            var start = (previewPage - 1) * previewPageSize;
            var pageRows = filteredRows.slice(start, start + previewPageSize);
            previewCurrentKeys = pageRows.map(function(item) {
                return item.key;
            });

            var totalW = 0,
                totalP = 0,
                totalS = 0;
            allRows.forEach(function(item) {
                totalW += parseInt(item.row.wajib || 0, 10);
                totalP += parseInt(item.row.pokok || 0, 10);
                totalS += parseInt(item.row.sukarela || 0, 10);
            });

            var html = '';
            pageRows.forEach(function(item, idx) {
                var row = item.row,
                    si = item.sheetIndex,
                    ri = item.rowIndex,
                    key = item.key,
                    no = start + idx + 1;
                html += '<tr style="border-bottom:1px solid #f7f7f7;">' +
                    '<td class="preview-td" style="text-align:center;"><input type="checkbox" class="w-4 h-4 accent-black" data-key="' + key + '" onchange="togglePreviewRowSelection(\'' + key + '\',this.checked)" ' + (selectedPreviewRows[key] ? 'checked' : '') + '></td>' +
                    '<td class="preview-td" style="color:#d1d5db;">' + no + '</td>' +
                    '<td class="preview-td" style="color:#6b7280;white-space:nowrap;">' + escapeHtml(item.sheetName) + '</td>' +
                    '<td class="preview-td"><input type="text" value="' + escapeHtml(row.nama) + '" onchange="updatePreviewValue(' + si + ',' + ri + ',\'nama\',this.value)" style="width:100%;background:#f9f9f9;border:1px solid #f0f0f0;padding:6px 10px;font-size:12px;font-weight:700;font-family:inherit;" class="focus:outline-none focus:border-black"></td>' +
                    '<td class="preview-td"><input type="number" min="0" value="' + parseInt(row.wajib || 0, 10) + '" onchange="updatePreviewValue(' + si + ',' + ri + ',\'wajib\',this.value)" style="width:100%;background:#f9f9f9;border:1px solid #f0f0f0;padding:6px 10px;font-size:12px;font-weight:600;font-family:inherit;text-align:right;" class="focus:outline-none focus:border-black"></td>' +
                    '<td class="preview-td"><input type="number" min="0" value="' + parseInt(row.pokok || 0, 10) + '" onchange="updatePreviewValue(' + si + ',' + ri + ',\'pokok\',this.value)" style="width:100%;background:#f9f9f9;border:1px solid #f0f0f0;padding:6px 10px;font-size:12px;font-weight:600;font-family:inherit;text-align:right;" class="focus:outline-none focus:border-black"></td>' +
                    '<td class="preview-td"><input type="number" min="0" value="' + parseInt(row.sukarela || 0, 10) + '" onchange="updatePreviewValue(' + si + ',' + ri + ',\'sukarela\',this.value)" style="width:100%;background:#f9f9f9;border:1px solid #f0f0f0;padding:6px 10px;font-size:12px;font-weight:600;font-family:inherit;text-align:right;" class="focus:outline-none focus:border-black"></td>' +
                    '<td class="preview-td" style="text-align:center;"><button onclick="deletePreviewRow(' + si + ',' + ri + ')" style="padding:4px 10px;border:1px solid #fecaca;background:#fff;color:#dc2626;font-size:9px;font-weight:800;text-transform:uppercase;cursor:pointer;">Hapus</button></td>' +
                    '</tr>';
            });
            tbody.innerHTML = html || '<tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:12px;">Tidak ada data yang cocok</td></tr>';

            var kw = (document.getElementById('preview-search') || {
                value: ''
            }).value.trim();
            meta.textContent = (payload.filename || '') + ' · ' + bulanNama[payload.bulan] + ' ' + payload.tahun + ' · ' + payload.sheets.length + ' sheet · ' + allRows.length + ' baris' + (kw ? ' · ' + filteredRows.length + ' hasil cari' : '') + ' · Total: Rp ' + formatNumber(totalW + totalP + totalS);
            updatePaginationControls(filteredRows.length, previewPage, totalPages, previewCurrentKeys);
        }

        function updatePaginationControls(count, page, total, visibleKeys) {
            var pi = document.getElementById('preview-page-info');
            var pl = document.getElementById('preview-pagination-label');
            var pb = document.getElementById('btn-preview-prev');
            var nb = document.getElementById('btn-preview-next');
            if (pi) pi.textContent = count + ' data';
            if (pl) pl.textContent = 'Hal ' + page + ' / ' + total;
            if (pb) pb.disabled = page <= 1;
            if (nb) nb.disabled = page >= total;
            updateSelectionControls(visibleKeys || []);
        }

        function updateSelectionControls(visibleKeys) {
            var selCount = Object.keys(selectedPreviewRows).filter(function(k) {
                return selectedPreviewRows[k];
            }).length;
            var cl = document.getElementById('preview-selected-count');
            var db = document.getElementById('btn-delete-selected');
            var sa = document.getElementById('preview-select-all');
            if (cl) cl.textContent = selCount + ' dipilih';
            if (db) db.disabled = selCount === 0;
            if (sa) {
                var cv = (visibleKeys || []).filter(function(k) {
                    return selectedPreviewRows[k];
                }).length;
                sa.checked = visibleKeys.length > 0 && cv === visibleKeys.length;
                sa.indeterminate = cv > 0 && cv < visibleKeys.length;
            }
        }

        function togglePreviewRowSelection(key, checked) {
            if (checked) selectedPreviewRows[key] = true;
            else delete selectedPreviewRows[key];
            updateSelectionControls(previewCurrentKeys || []);
        }

        function toggleSelectAllVisible(checked) {
            (previewCurrentKeys || []).forEach(function(k) {
                if (checked) selectedPreviewRows[k] = true;
                else delete selectedPreviewRows[k];
            });
            renderPreviewModal(importPayload);
        }

        function deleteSelectedPreviewRows() {
            if (!importPayload || !importPayload.sheets) return;
            var keys = Object.keys(selectedPreviewRows).filter(function(k) {
                return selectedPreviewRows[k];
            });
            if (!keys.length) return;
            if (!confirm('Hapus ' + keys.length + ' baris terpilih?')) return;
            var grouped = {};
            keys.forEach(function(k) {
                var p = k.split(':');
                var si = parseInt(p[0], 10),
                    ri = parseInt(p[1], 10);
                if (!grouped[si]) grouped[si] = [];
                grouped[si].push(ri);
            });
            Object.keys(grouped).forEach(function(si) {
                grouped[si].sort(function(a, b) {
                    return b - a;
                }).forEach(function(ri) {
                    if (importPayload.sheets[parseInt(si, 10)]) importPayload.sheets[parseInt(si, 10)].rows.splice(ri, 1);
                });
            });
            importPayload.sheets = importPayload.sheets.filter(function(s) {
                return s.rows.length > 0;
            });
            selectedPreviewRows = {};
            renderPreviewModal(importPayload);
        }

        function updatePreviewValue(si, ri, field, value) {
            if (!importPayload || !importPayload.sheets[si] || !importPayload.sheets[si].rows[ri]) return;
            if (field === 'nama') importPayload.sheets[si].rows[ri][field] = String(value || '').trim();
            else importPayload.sheets[si].rows[ri][field] = Math.max(0, toNumber(value));
        }

        function deletePreviewRow(si, ri) {
            if (!importPayload || !importPayload.sheets[si]) return;
            if (!confirm('Hapus baris ini?')) return;
            importPayload.sheets[si].rows.splice(ri, 1);
            importPayload.sheets = importPayload.sheets.filter(function(s) {
                return s.rows.length > 0;
            });
            selectedPreviewRows = {};
            renderPreviewModal(importPayload);
        }

        function confirmImport() {
            if (!importPayload) {
                showAlert('error', 'Silakan upload dan preview file terlebih dahulu.');
                return;
            }
            var totalRows = 0;
            importPayload.sheets.forEach(function(s) {
                totalRows += s.rows.length;
            });
            if (!totalRows) {
                showAlert('error', 'Tidak ada data yang bisa diimport.');
                return;
            }
            if (!confirm('Lanjutkan import data simpanan? Data yang sudah ada untuk periode ini akan ditimpa.')) return;

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
                    showAlert('success', data.message || 'Import berhasil.');
                    closePreviewModal();
                    selectedExcelFile = null;
                    importPayload = null;
                    selectedPreviewRows = {};
                    previewPage = 1;
                    document.getElementById('file-input').value = '';
                    document.getElementById('file-label').textContent = 'Klik untuk pilih file Excel';
                    document.getElementById('upload-label').classList.remove('has-file');
                    document.getElementById('import-ready').classList.add('hidden');
                    btns.forEach(function(b) {
                        b.disabled = false;
                        b.textContent = 'Konfirmasi & Import';
                    });
                    setTimeout(function() {
                        window.location.href = '?bulan_filter=<?php echo $bulanAktif; ?>&tahun_filter=<?php echo $tahunAktif; ?>';
                    }, 1500);
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
            if (confirm('Batalkan upload file ini?')) {
                selectedExcelFile = null;
                importPayload = null;
                document.getElementById('file-input').value = '';
                document.getElementById('file-label').textContent = 'Klik untuk pilih file Excel';
                document.getElementById('upload-label').classList.remove('has-file');
                document.getElementById('import-ready').classList.add('hidden');
            }
        }
    </script>
</body>

</html>