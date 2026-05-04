<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

if (!function_exists('rupiah')) {
    /**
     * @param mixed $v
     */
    function rupiah($v): string
    {
        return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
    }
}

/**
 * @param mixed $v
 */
function angka($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * @param mixed $v
 */
function tgl($v): string
{
    return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
}

/**
 * @param mixed $v
 */
function waktu($v): string
{
    return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
}


function transaksi_col_exists(PDO $pdo, string $column): bool
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM transaksi")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return in_array($column, $cols, true);
}

/* ── Rentang tanggal ─────────────────────────────────────────── */
$today  = date('Y-m-d');
$preset = $_GET['preset'] ?? 'hari_ini';

if ($preset === 'minggu_ini') {
    $awal  = date('Y-m-d', strtotime('monday this week'));
    $akhir = date('Y-m-d', strtotime('sunday this week'));
} elseif ($preset === 'bulan_ini') {
    $awal  = date('Y-m-01');
    $akhir = date('Y-m-t');
} elseif ($preset === 'tahun_ini') {
    $awal  = date('Y-01-01');
    $akhir = date('Y-12-31');
} elseif ($preset === 'custom') {
    $awal  = $_GET['awal']  ?? $today;
    $akhir = $_GET['akhir'] ?? $today;
} else {
    $preset = 'hari_ini';
    $awal   = $today;
    $akhir  = $today;
}

$hasDiskon       = true;
$hasDiskonId     = true;
$hasPoint        = true;
$hasPointPakai   = transaksi_col_exists($pdo, 'point_pakai');
$hasNilaiPointPakai = transaksi_col_exists($pdo, 'nilai_point_pakai');
$hasMember       = true;
$hasMetode       = true;
$hasPayStatus    = true;
$hasHargaNormal  = true;
$hasDetailDiskon = true;
$hasDetailDiskId = true;

$whereStatus = "AND (t.metode_pembayaran = 'tunai' OR t.payment_status = 'paid')";

try {
    $diskonSum = $hasDiskon ? "COALESCE(SUM(t.diskon), 0)" : "0";
    $pointSum  = $hasPoint  ? "COALESCE(SUM(t.point_dapat), 0)" : "0";
    $pointPakaiSum = $hasPointPakai ? "COALESCE(SUM(t.point_pakai), 0)" : "0";
    $nilaiPointPakaiSum = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai), 0)" : "0";

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                      AS total_transaksi,
            COALESCE(SUM(t.total),  0)    AS omzet,
            $diskonSum                    AS diskon_transaksi,
            COALESCE(SUM(t.bayar),  0)    AS bayar,
            COALESCE(SUM(t.kembalian), 0) AS kembalian,
            $pointSum                     AS point,
            $pointPakaiSum                AS point_pakai,
            $nilaiPointPakaiSum           AS nilai_point_pakai
        FROM transaksi t
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
          $whereStatus
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $diskonBarangTotal = 0;
    if ($hasHargaNormal) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                GREATEST(0, (COALESCE(td.harga_normal, td.harga) * td.qty) - td.subtotal)
            ), 0) AS total_diskon_barang
            FROM transaksi_detail td
            JOIN transaksi t ON t.id = td.transaksi_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              $whereStatus
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $diskonBarangTotal = (int)($row['total_diskon_barang'] ?? 0);
    }

    $memberJoin   = $hasMember ? "LEFT JOIN member m ON m.id = t.member_id" : "";
    $memberSelect = $hasMember
        ? "m.nama AS member_nama, m.kode AS member_kode"
        : "NULL AS member_nama, NULL AS member_kode";
    $diskonSelect = $hasDiskon ? "t.diskon" : "0 AS diskon";
    $pointSelect  = $hasPoint  ? "t.point_dapat" : "0 AS point_dapat";
    $pointPakaiSelect = $hasPointPakai ? "t.point_pakai" : "0 AS point_pakai";
    $nilaiPointPakaiSelect = $hasNilaiPointPakai ? "t.nilai_point_pakai" : "0 AS nilai_point_pakai";
    $metodeSelect = $hasMetode ? "t.metode_pembayaran" : "'tunai' AS metode_pembayaran";
    $statusSelect = $hasPayStatus ? "t.payment_status" : "'selesai' AS payment_status";

    $diskonBarangPerTrx = $hasHargaNormal
        ? "(SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td2.harga_normal,td2.harga)*td2.qty)-td2.subtotal)),0)
               FROM transaksi_detail td2 WHERE td2.transaksi_id = t.id)"
        : "0";

    $stmt = $pdo->prepare("
        SELECT
            t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
            $diskonSelect,
            ($diskonBarangPerTrx) AS diskon_barang,
            $pointSelect,
            $pointPakaiSelect,
            $nilaiPointPakaiSelect,
            $memberSelect,
            $metodeSelect,
            $statusSelect
        FROM transaksi t
        $memberJoin
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
          $whereStatus
        ORDER BY t.created_at DESC, t.id DESC
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hargaNormal   = $hasHargaNormal  ? "COALESCE(td.harga_normal, td.harga)" : "td.harga";
    $diskonProduk  = "GREATEST(0, (($hargaNormal * td.qty) - td.subtotal))";

    $stmt = $pdo->prepare("
        SELECT
            td.produk_id,
            td.nama,
            MAX(td.kode)                                  AS kode,
            SUM(td.qty)                                   AS qty,
            SUM($hargaNormal * td.qty)                    AS subtotal_normal,
            SUM($diskonProduk)                            AS diskon,
            SUM(td.subtotal)                              AS penjualan
        FROM transaksi_detail td
        JOIN transaksi t ON t.id = td.transaksi_id
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
          $whereStatus
        GROUP BY td.produk_id, td.nama
        ORDER BY qty DESC, penjualan DESC
        LIMIT 20
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diskonTransaksi = [];
    if ($hasDiskon && $hasDiskonId) {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(d.nama, 'Diskon Transaksi') AS nama,
                COUNT(t.id)                           AS jumlah,
                COALESCE(SUM(t.diskon), 0)            AS total
            FROM transaksi t
            LEFT JOIN diskon d ON d.id = t.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              $whereStatus
              AND COALESCE(t.diskon, 0) > 0
            GROUP BY t.diskon_id, d.nama
            ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $diskonBarang = [];
    if ($hasHargaNormal) {
        $diskonIdJoin   = $hasDetailDiskId
            ? "LEFT JOIN diskon d ON d.id = td.diskon_id"
            : "";
        $diskonIdSelect = $hasDetailDiskId
            ? "COALESCE(d.nama, 'Diskon Barang') AS nama, td.diskon_id"
            : "NULL AS diskon_id, 'Diskon Barang' AS nama";

        $stmt = $pdo->prepare("
            SELECT
                $diskonIdSelect,
                COUNT(td.id)                                         AS jumlah,
                COALESCE(SUM(
                    GREATEST(0, (COALESCE(td.harga_normal, td.harga) * td.qty) - td.subtotal)
                ), 0)                                                AS total
            FROM transaksi_detail td
            JOIN transaksi t ON t.id = td.transaksi_id
            $diskonIdJoin
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              $whereStatus
              AND GREATEST(0, (COALESCE(td.harga_normal, td.harga) * td.qty) - td.subtotal) > 0
            GROUP BY " . ($hasDetailDiskId ? "td.diskon_id, d.nama" : "1") . "
            ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $memberPoin = [];
    if ($hasMember && $hasPoint) {
        $pointPakaiMemberSum = $hasPointPakai ? "COALESCE(SUM(t.point_pakai), 0)" : "0";
        $stmt = $pdo->prepare("
            SELECT
                m.kode, m.nama, m.no_hp,
                COUNT(t.id)                        AS jumlah_trx,
                COALESCE(SUM(t.total), 0)          AS total_belanja,
                COALESCE(SUM(t.point_dapat), 0)    AS point_periode,
                $pointPakaiMemberSum          AS point_pakai_periode,
                m.point                            AS point_total_lifetime
            FROM transaksi t
            JOIN member m ON m.id = t.member_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              $whereStatus
            GROUP BY t.member_id, m.kode, m.nama, m.no_hp, m.point
            ORDER BY total_belanja DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $memberPoin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    die('Gagal memuat laporan: ' . htmlspecialchars($e->getMessage()));
}

$totalTransaksi = (int)($summary['total_transaksi']   ?? 0);
$omzet          = (int)($summary['omzet']             ?? 0);
$diskonTrxSum   = (int)($summary['diskon_transaksi']  ?? 0);
$totalDiskon    = $diskonTrxSum + $diskonBarangTotal;
$totalBayar     = (int)($summary['bayar']             ?? 0);
$totalKembalian = (int)($summary['kembalian']         ?? 0);
$totalPoint     = (int)($summary['point']             ?? 0);
$totalPointPakai = (int)($summary['point_pakai'] ?? 0);
$totalNilaiPointPakai = (int)($summary['nilai_point_pakai'] ?? 0);
$rata           = $totalTransaksi > 0 ? (int)floor($omzet / $totalTransaksi) : 0;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan — Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --sidebar-w: 240px;
            --hdr-h: 60px;
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --blue-50: #eff6ff;
            --blue-100: #dbeafe;
            --blue-600: #2563eb;
            --blue-700: #1d4ed8;
            --green-50: #f0fdf4;
            --green-600: #16a34a;
            --green-700: #15803d;
            --red-50: #fef2f2;
            --red-600: #dc2626;
            --orange-50: #fff7ed;
            --orange-600: #ea580c;
            --amber-600: #d97706;
            --purple-600: #9333ea;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, .07), 0 1px 2px rgba(0, 0, 0, .05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .08);
            --transition: .18s ease;
        }

        html {
            font-size: 15px;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
        }

        /* ── Sidebar ─────────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-w);
            background: var(--white);
            border-right: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            padding: 0;
            z-index: 100;
            transition: transform var(--transition);
        }

        .sidebar-brand {
            padding: 24px 24px 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .sidebar-brand-name {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--gray-900);
        }

        .sidebar-brand-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 3px;
            font-family: 'DM Mono', monospace;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .nav-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--gray-400);
            padding: 12px 8px 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-500);
            text-decoration: none;
            transition: background var(--transition), color var(--transition);
        }

        .nav-link:hover {
            background: var(--gray-50);
            color: var(--gray-900);
        }

        .nav-link.active {
            background: var(--gray-900);
            color: var(--white);
            font-weight: 600;
        }

        .nav-link svg {
            flex-shrink: 0;
            opacity: .7;
        }

        .nav-link.active svg {
            opacity: 1;
        }

        .sidebar-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-200);
        }

        .sidebar-footer p {
            font-size: 11px;
            color: var(--gray-400);
            line-height: 1.6;
        }

        .logout-link {
            display: inline-block;
            margin-top: 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--red-600);
            text-decoration: none;
        }

        .logout-link:hover {
            text-decoration: underline;
        }

        /* ── Header ──────────────────────────────────────────── */
        .header {
            position: fixed;
            top: 0;
            left: var(--sidebar-w);
            right: 0;
            height: var(--hdr-h);
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 90;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: none;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--gray-700);
        }

        .back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            background: none;
            text-decoration: none;
            color: var(--gray-700);
            font-size: 14px;
            transition: background var(--transition);
        }

        .back-btn:hover {
            background: var(--gray-50);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            text-decoration: none;
            transition: opacity var(--transition), transform var(--transition);
        }

        .export-btn:hover {
            opacity: .85;
            transform: translateY(-1px);
        }

        .export-btn:active {
            transform: translateY(0);
        }

        .btn-blue {
            background: var(--blue-600);
            color: #fff;
        }

        .btn-green {
            background: var(--green-600);
            color: #fff;
        }

        .btn-purple {
            background: var(--purple-600);
            color: #fff;
        }

        .btn-red {
            background: var(--red-600);
            color: #fff;
        }

        /* ── Main layout ─────────────────────────────────────── */
        .main-wrap {
            margin-left: var(--sidebar-w);
            padding-top: var(--hdr-h);
        }

        .main-content {
            padding: 28px 28px 40px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            max-width: 1400px;
        }

        /* ── Cards / panels ──────────────────────────────────── */
        .panel {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .panel-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .panel-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        /* ── Metric grid ─────────────────────────────────────── */
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .metric-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            box-shadow: var(--shadow-sm);
        }

        .metric-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--gray-400);
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
        }

        .metric-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 5px;
        }

        .c-blue {
            color: var(--blue-600);
        }

        .c-red {
            color: var(--red-600);
        }

        .c-green {
            color: var(--green-600);
        }

        .c-orange {
            color: var(--orange-600);
        }

        .c-amber {
            color: var(--amber-600);
        }

        /* ── Filter section ──────────────────────────────────── */
        .filter-panel {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            box-shadow: var(--shadow-sm);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--gray-400);
            margin-bottom: 6px;
        }

        .filter-group select,
        .filter-group input[type="date"] {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: inherit;
            color: var(--gray-900);
            background: var(--white);
            outline: none;
            transition: border-color var(--transition);
        }

        .filter-group select:focus,
        .filter-group input[type="date"]:focus {
            border-color: var(--gray-400);
        }

        .btn-submit {
            padding: 9px 18px;
            background: var(--gray-900);
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            white-space: nowrap;
            transition: background var(--transition);
        }

        .btn-submit:hover {
            background: var(--gray-700);
        }

        .btn-reset {
            padding: 9px 14px;
            background: none;
            color: var(--gray-500);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            transition: background var(--transition);
        }

        .btn-reset:hover {
            background: var(--gray-50);
        }

        /* ── Table (desktop) ─────────────────────────────────── */
        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead th {
            padding: 11px 16px;
            background: var(--gray-50);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--gray-400);
            white-space: nowrap;
            border-bottom: 1px solid var(--gray-100);
            text-align: left;
        }

        thead th.r {
            text-align: right;
        }

        tbody tr {
            border-bottom: 1px solid var(--gray-100);
            transition: background var(--transition);
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody tr:hover {
            background: var(--gray-50);
        }

        td {
            padding: 12px 16px;
            vertical-align: middle;
        }

        td.r {
            text-align: right;
        }

        td.fw {
            font-weight: 600;
        }

        tfoot tr {
            background: var(--gray-50);
            border-top: 2px solid var(--gray-200);
        }

        tfoot td {
            padding: 11px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        tfoot td.r {
            text-align: right;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .badge-gray {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .badge-blue {
            background: var(--blue-50);
            color: var(--blue-700);
        }

        .badge-trx {
            background: var(--blue-50);
            color: var(--blue-700);
        }

        .badge-barang {
            background: var(--orange-50);
            color: var(--orange-600);
        }

        .rank-circle {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--gray-900);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .invoice-link {
            color: var(--blue-600);
            font-weight: 600;
            text-decoration: none;
        }

        .invoice-link:hover {
            text-decoration: underline;
        }

        .empty-row td {
            padding: 40px 16px;
            text-align: center;
            color: var(--gray-400);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        /* ── Card list (mobile/tablet) ───────────────────────── */
        .card-list {
            display: none;
            flex-direction: column;
            gap: 0;
        }

        .card-item {
            padding: 16px 18px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .card-item:last-child {
            border-bottom: none;
        }

        .card-item-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }

        .card-item-title {
            font-size: 14px;
            font-weight: 600;
        }

        .card-item-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        .card-item-amount {
            font-size: 15px;
            font-weight: 700;
            text-align: right;
        }

        .card-item-rows {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .card-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
        }

        .card-row-label {
            color: var(--gray-400);
            font-weight: 500;
        }

        .card-row-val {
            font-weight: 600;
        }

        /* ── Two-col panels ──────────────────────────────────── */
        .col2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* ── Sidebar overlay (mobile) ────────────────────────── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 99;
        }

        /* ── Print ───────────────────────────────────────────── */
        @media print {

            .sidebar,
            .header,
            .no-print {
                display: none !important;
            }

            .main-wrap {
                margin-left: 0;
                padding-top: 0;
            }

            .main-content {
                padding: 0;
            }

            .print-title {
                display: block !important;
            }

            body {
                background: #fff;
            }
        }

        .print-title {
            display: none;
        }

        /* ── Tablet (≤1024px) ───────────────────────────────── */
        @media (max-width: 1024px) {
            :root {
                --sidebar-w: 0px;
            }

            .sidebar {
                transform: translateX(-240px);
                width: 240px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.open {
                display: block;
            }

            .header {
                left: 0;
            }

            .menu-btn {
                display: flex;
            }

            .main-wrap {
                margin-left: 0;
            }

            .main-content {
                padding: 20px 16px 40px;
            }

            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-grid .filter-group:first-child {
                grid-column: span 2;
            }

            .col2 {
                grid-template-columns: 1fr;
            }

            /* swap table → card list */
            .tbl-desktop {
                display: none !important;
            }

            .card-list {
                display: flex;
            }

            .table-footer-mobile {
                padding: 14px 18px;
                background: var(--gray-50);
                border-top: 2px solid var(--gray-200);
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .07em;
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                color: var(--gray-500);
            }

            .table-footer-mobile span {
                white-space: nowrap;
            }
        }

        /* ── Mobile (≤640px) ─────────────────────────────────── */
        @media (max-width: 640px) {
            .header-title {
                font-size: 13px;
            }

            .export-btn {
                display: none;
            }

            .export-mobile {
                display: flex !important;
            }

            .metric-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .metric-card {
                padding: 14px 16px;
            }

            .metric-value {
                font-size: 20px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .filter-group:first-child {
                grid-column: span 1;
            }
        }

        .export-mobile {
            display: none;
            gap: 6px;
            flex-wrap: wrap;
            padding: 0 0 4px;
        }


        /* ── Diskon theme responsive override ───────────────────────
           Menyamakan tampilan laporan dengan halaman diskon tanpa
           mengubah query, data, atau struktur utama halaman. */
        :root {
            --sidebar-w: 220px;
            --hdr-h: 68px;
            --white: #ffffff;
            --gray-50: #fafafa;
            --gray-100: #f5f5f5;
            --gray-200: #f0f0f0;
            --gray-300: #d4d4d4;
            --gray-400: #a3a3a3;
            --gray-500: #737373;
            --gray-700: #404040;
            --gray-900: #1a1a1a;
            --blue-50: #eff6ff;
            --blue-600: #2563eb;
            --blue-700: #1d4ed8;
            --green-50: #f0fdf4;
            --green-600: #16a34a;
            --red-50: #fef2f2;
            --red-600: #dc2626;
            --orange-50: #fff7ed;
            --orange-600: #ea580c;
            --amber-600: #d97706;
            --purple-600: #9333ea;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: none;
            --shadow-md: 0 10px 30px rgba(15, 23, 42, .05);
            --transition: .18s ease;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #1a1a1a;
            padding-bottom: 0;
        }

        .sidebar {
            width: 220px;
            background: #fff;
            border-right: 1px solid #f0f0f0;
            padding: 32px;
            z-index: 30;
        }

        .sidebar-brand {
            padding: 0 0 44px;
            border-bottom: none;
        }

        .sidebar-brand-name {
            display: inline-block;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: -.04em;
            color: #111;
            border-bottom: 2px solid #111;
            padding-bottom: 4px;
            text-transform: uppercase;
        }

        .sidebar-brand-sub {
            margin-top: 12px;
            font-size: 10px;
            color: #a3a3a3;
        }

        .sidebar-nav {
            padding: 0;
            gap: 24px;
        }

        .nav-label {
            display: none;
        }

        .nav-link {
            padding: 0;
            border-radius: 0;
            color: #a3a3a3;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .14em;
            text-transform: uppercase;
            background: transparent !important;
            gap: 10px;
        }

        .nav-link:hover {
            color: #111;
            background: transparent !important;
        }

        .nav-link.active {
            color: #111;
            background: transparent !important;
            font-weight: 800;
        }

        .nav-link.active::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #111;
            flex: 0 0 auto;
        }

        .nav-link svg {
            display: none;
        }

        .sidebar-footer {
            padding: 28px 0 0;
            border-top: 1px solid #f0f0f0;
        }

        .sidebar-footer p,
        .logout-link {
            font-size: 10px;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .header {
            left: 220px;
            height: 68px;
            background: rgba(255, 255, 255, .96);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #f0f0f0;
            padding: 0 24px;
            z-index: 40;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }

        .header-title {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .2em;
            color: #111;
        }

        .back-btn,
        .menu-btn {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 999px;
            background: transparent;
        }

        .back-btn:hover,
        .menu-btn:hover {
            background: #f5f5f5;
        }

        .header-actions {
            gap: 10px;
        }

        .export-btn {
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .13em;
            box-shadow: none;
        }

        .header-actions .badge-gray {
            border: 1px solid #f0f0f0;
            background: #fff;
            color: #111 !important;
        }

        .btn-blue,
        .btn-green,
        .btn-purple,
        .btn-red,
        .btn-submit {
            background: #111 !important;
            color: #fff !important;
        }

        .btn-blue:hover,
        .btn-green:hover,
        .btn-purple:hover,
        .btn-red:hover,
        .btn-submit:hover {
            background: #333 !important;
        }

        .main-wrap {
            margin-left: 220px;
            padding-top: 68px;
        }

        .main-content {
            max-width: none;
            padding: 40px;
            gap: 24px;
        }

        .panel,
        .filter-panel,
        .metric-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 4px;
            box-shadow: none;
        }

        .panel {
            overflow: hidden;
        }

        .panel-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }

        .panel-title,
        .metric-label,
        .filter-group label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .16em;
            color: #737373;
        }

        .panel-sub,
        .metric-sub {
            color: #a3a3a3;
        }

        .metric-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .metric-card {
            padding: 20px;
        }

        .metric-value {
            font-size: 26px;
            font-weight: 800;
        }

        .filter-panel {
            padding: 16px;
        }

        .filter-grid {
            grid-template-columns: minmax(220px, 2fr) minmax(150px, 1fr) minmax(150px, 1fr) auto auto;
            gap: 12px;
        }

        .filter-group select,
        .filter-group input[type="date"] {
            background: #fafafa;
            border: 1px solid #f0f0f0;
            border-radius: 4px;
            padding: 11px 12px;
            font-size: 13px;
        }

        .filter-group select:focus,
        .filter-group input[type="date"]:focus {
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
        }

        .btn-submit,
        .btn-reset {
            height: 42px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .14em;
        }

        .btn-reset {
            border: 1px solid #f0f0f0;
            background: #fff;
            color: #737373;
        }

        .table-wrap::-webkit-scrollbar {
            display: none;
        }

        .table-wrap {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        table {
            min-width: 900px;
        }

        thead th {
            background: #fafafa;
            color: #a3a3a3;
            border-bottom: 1px solid #f0f0f0;
            padding: 16px 20px;
        }

        td {
            padding: 16px 20px;
        }

        tbody tr {
            border-bottom: 1px solid #f5f5f5;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .badge {
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 9px;
            font-weight: 800;
        }

        .badge-gray {
            background: #f5f5f5;
            color: #404040;
            border: 1px solid #e5e5e5;
        }

        .badge-blue,
        .badge-trx {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .badge-barang {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .rank-circle {
            background: #111;
        }

        .invoice-link {
            color: #2563eb;
            font-weight: 800;
        }

        .mobile-bottom-nav {
            display: none;
        }

        @media (max-width: 1180px) {
            .metric-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-grid .filter-group:first-child {
                grid-column: span 2;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            :root {
                --sidebar-w: 0px;
            }

            .sidebar {
                width: 288px;
                padding: 32px;
                transform: translateX(-100%);
                z-index: 100;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.open {
                display: block;
                z-index: 99;
            }

            .header {
                left: 0;
                padding: 0 16px;
            }

            .menu-btn {
                display: flex;
            }

            .main-wrap {
                margin-left: 0;
            }

            .main-content {
                padding: 24px 16px 96px;
            }

            .metric-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .metric-card[style*="grid-column"] {
                grid-column: span 2 !important;
            }

            .col2 {
                grid-template-columns: 1fr;
            }

            .tbl-desktop {
                display: none !important;
            }

            .card-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                padding: 12px;
                background: #fff;
            }

            .card-item {
                border: 1px solid #f0f0f0;
                border-radius: 4px;
                padding: 16px;
                background: #fff;
                gap: 12px;
                transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
            }

            .card-item:hover {
                transform: translateY(-1px);
                border-color: #e5e7eb;
                box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
            }

            .card-item:last-child {
                border-bottom: 1px solid #f0f0f0;
            }

            .card-item-title {
                font-size: 14px;
                font-weight: 800;
            }

            .card-item-sub {
                font-size: 10px;
                color: #a3a3a3;
            }

            .card-item-amount {
                font-size: 15px;
                font-weight: 900;
            }

            .card-row {
                padding: 8px 0;
                border-top: 1px solid #f5f5f5;
                gap: 12px;
            }

            .card-row-label {
                font-size: 11px;
                color: #a3a3a3;
                font-weight: 700;
            }

            .card-row-val {
                font-size: 12px;
                font-weight: 800;
                text-align: right;
            }

            .table-footer-mobile {
                grid-column: 1 / -1;
                border: 1px solid #f0f0f0;
                border-radius: 4px;
                padding: 14px 16px;
                background: #fafafa;
                font-size: 11px;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 76px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 24px;
                background: #fff;
                border-top: 1px solid #f0f0f0;
                z-index: 80;
                box-shadow: 0 -10px 30px rgba(15, 23, 42, .06);
            }

            .mobile-bottom-nav a,
            .mobile-bottom-nav button {
                border: 0;
                background: transparent;
                color: #111;
                text-decoration: none;
                display: inline-flex;
                flex-direction: column;
                align-items: center;
                gap: 4px;
                font-size: 8px;
                font-weight: 900;
                text-transform: uppercase;
            }

            .mobile-bottom-nav .pos-fab {
                width: 54px;
                height: 54px;
                border-radius: 999px;
                background: #111;
                color: #fff;
                justify-content: center;
                margin-top: -30px;
                border: 4px solid #fff;
                box-shadow: 0 10px 25px rgba(0, 0, 0, .16);
            }
        }

        @media (max-width: 640px) {
            .header-title {
                max-width: 160px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                font-size: 13px;
            }

            .back-btn {
                display: none;
            }

            .header-actions .export-btn {
                display: none;
            }

            .export-mobile {
                display: flex !important;
                gap: 8px;
                overflow-x: auto;
                padding-bottom: 2px;
                scrollbar-width: none;
            }

            .export-mobile::-webkit-scrollbar {
                display: none;
            }

            .export-mobile .export-btn {
                display: inline-flex !important;
                flex: 0 0 auto;
                padding: 9px 12px !important;
                font-size: 9px !important;
                background: #111 !important;
                color: #fff !important;
            }

            .main-content {
                padding: 16px 12px 96px;
                gap: 16px;
            }

            .metric-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .metric-card {
                padding: 14px;
            }

            .metric-label {
                font-size: 9px;
                letter-spacing: .12em;
            }

            .metric-value {
                font-size: 20px;
                line-height: 1.15;
            }

            .metric-sub {
                font-size: 10px;
            }

            .metric-card[style*="grid-column"] {
                grid-column: 1 / -1 !important;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .filter-group:first-child {
                grid-column: auto;
            }

            .panel-header {
                padding: 16px;
            }

            .card-list {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 10px;
            }

            .card-item-header {
                gap: 12px;
            }

            .card-item-header>div:first-child {
                min-width: 0;
            }

            .card-item-title,
            .invoice-link {
                overflow-wrap: anywhere;
            }
        }
    </style>
</head>

<body>

    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- ── Sidebar ──────────────────────────────────────────────── -->
    <aside class="sidebar no-print" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-name">Koperasi BSDK</div>
            <div class="sidebar-brand-sub">ID: T042 · BOGOR · v3.0.0</div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Menu</div>
            <a href="index.php" class="nav-link">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1" />
                    <rect x="14" y="3" width="7" height="7" rx="1" />
                    <rect x="3" y="14" width="7" height="7" rx="1" />
                    <rect x="14" y="14" width="7" height="7" rx="1" />
                </svg>
                Dashboard
            </a>
            <a href="pos.php" class="nav-link">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" />
                    <path d="M8 21h8M12 17v4" />
                </svg>
                Mesin Kasir (POS)
            </a>
            <a href="produk.php" class="nav-link">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                </svg>
                Kelola Produk
            </a>
            <a href="diskon.php" class="nav-link">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                </svg>
                Kelola Diskon
            </a>
            <a href="laporan.php" class="nav-link active">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14,2 14,8 20,8" />
                    <line x1="16" y1="13" x2="8" y2="13" />
                    <line x1="16" y1="17" x2="8" y2="17" />
                    <polyline points="10,9 9,9 8,9" />
                </svg>
                Laporan
            </a>
        </nav>
        <div class="sidebar-footer">
            <p>Koperasi BSDK<br>Toko ID T042 · Bogor</p>
            <a href="logout.php" class="logout-link" onclick="return confirm('Yakin mau logout?')">Logout →</a>
        </div>
    </aside>

    <!-- ── Header ────────────────────────────────────────────────── -->
    <header class="header no-print">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()" aria-label="Menu">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <line x1="3" y1="6" x2="21" y2="6" />
                    <line x1="3" y1="12" x2="21" y2="12" />
                    <line x1="3" y1="18" x2="21" y2="18" />
                </svg>
            </button>
            <a href="index.php" class="back-btn">←</a>
            <span class="header-title">Laporan Keuangan</span>
        </div>
        <div class="header-actions">
            <a href="pos.php" class="export-btn badge-gray" style="color:var(--gray-700)">POS</a>
            <a href="export_laporan_pdf.php?jenis=ringkasan&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-blue">Ringkasan</a>
            <a href="export_laporan_pdf.php?jenis=transaksi&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-green">Transaksi</a>
            <a href="export_laporan_pdf.php?jenis=produk&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-purple">Produk</a>
            <a href="export_laporan_pdf.php?jenis=diskon&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-red">Diskon</a>
        </div>
    </header>

    <!-- ── Main ──────────────────────────────────────────────────── -->
    <div class="main-wrap">
        <main class="main-content">

            <!-- Print title -->
            <div class="print-title" style="text-align:center;margin-bottom:16px;">
                <h1 style="font-size:20px;font-weight:700;">Laporan Keuangan Koperasi BSDK</h1>
                <p style="font-size:13px;color:var(--gray-500);">Periode <?= htmlspecialchars(tgl($awal)) ?> s/d <?= htmlspecialchars(tgl($akhir)) ?></p>
            </div>

            <!-- Mobile export row -->
            <div class="export-mobile no-print">
                <a href="export_laporan_pdf.php?jenis=ringkasan&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-blue" style="font-size:10px;padding:5px 10px;">Ringkasan</a>
                <a href="export_laporan_pdf.php?jenis=transaksi&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-green" style="font-size:10px;padding:5px 10px;">Transaksi</a>
                <a href="export_laporan_pdf.php?jenis=produk&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-purple" style="font-size:10px;padding:5px 10px;">Produk</a>
                <a href="export_laporan_pdf.php?jenis=diskon&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="export-btn btn-red" style="font-size:10px;padding:5px 10px;">Diskon</a>
            </div>

            <!-- ── Filter ─────────────────────────────────────────────── -->
            <section class="filter-panel no-print">
                <form method="GET">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>Preset Periode</label>
                            <select name="preset">
                                <option value="hari_ini" <?= $preset === 'hari_ini'  ? 'selected' : '' ?>>Hari Ini</option>
                                <option value="minggu_ini" <?= $preset === 'minggu_ini' ? 'selected' : '' ?>>Minggu Ini</option>
                                <option value="bulan_ini" <?= $preset === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                                <option value="tahun_ini" <?= $preset === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                                <option value="custom" <?= $preset === 'custom'    ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Tanggal Awal</label>
                            <input type="date" name="awal" value="<?= htmlspecialchars($awal) ?>">
                        </div>
                        <div class="filter-group">
                            <label>Tanggal Akhir</label>
                            <input type="date" name="akhir" value="<?= htmlspecialchars($akhir) ?>">
                        </div>
                        <div>
                            <button type="submit" class="btn-submit" style="width:100%;">Tampilkan</button>
                        </div>
                        <div>
                            <a href="laporan.php" class="btn-reset" style="width:100%;justify-content:center;">Reset</a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- ── Metric cards ───────────────────────────────────────── -->
            <div class="metric-grid">
                <div class="metric-card">
                    <div class="metric-label">Omzet Bersih</div>
                    <div class="metric-value c-blue"><?= rupiah($omzet) ?></div>
                    <div class="metric-sub">Setelah diskon transaksi</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Total Transaksi</div>
                    <div class="metric-value"><?= angka($totalTransaksi) ?></div>
                    <div class="metric-sub">Rata-rata <?= rupiah($rata) ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Total Diskon</div>
                    <div class="metric-value c-red"><?= rupiah($totalDiskon) ?></div>
                    <div class="metric-sub">Trx <?= rupiah($diskonTrxSum) ?> + Barang <?= rupiah($diskonBarangTotal) ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Point Member</div>
                    <div class="metric-value c-green"><?= angka($totalPoint) ?> pt</div>
                    <div class="metric-sub">Diberikan periode ini</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Point Ditukar</div>
                    <div class="metric-value c-amber"><?= angka($totalPointPakai) ?> pt</div>
                    <div class="metric-sub">Potongan <?= rupiah($totalNilaiPointPakai) ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Bayar Diterima</div>
                    <div class="metric-value"><?= rupiah($totalBayar) ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Total Kembalian</div>
                    <div class="metric-value"><?= rupiah($totalKembalian) ?></div>
                </div>
                <div class="metric-card" style="grid-column: span 2;">
                    <div class="metric-label">Periode</div>
                    <div class="metric-value" style="font-size:18px;"><?= htmlspecialchars(tgl($awal)) ?> – <?= htmlspecialchars(tgl($akhir)) ?></div>
                </div>
            </div>

            <!-- ── Detail Transaksi ───────────────────────────────────── -->
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">Detail Transaksi</div>
                        <div class="panel-sub"><?= angka(count($transaksi)) ?> transaksi ditemukan</div>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="tbl-desktop table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Invoice</th>
                                <th>Member</th>
                                <th>Metode</th>
                                <th class="r">Diskon Trx</th>
                                <th class="r">Diskon Barang</th>
                                <th class="r">Total</th>
                                <th class="r">Bayar</th>
                                <th class="r">Kembali</th>
                                <th class="r">Poin</th>
                                <th class="r">Point Pakai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$transaksi): ?>
                                <tr class="empty-row">
                                    <td colspan="11">Belum ada transaksi</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($transaksi as $t): ?>
                                <?php
                                $metode = $t['metode_pembayaran'] ?? 'tunai';
                                $badge  = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                                ?>
                                <tr>
                                    <td style="white-space:nowrap;font-size:12px;"><?= htmlspecialchars(waktu($t['created_at'] ?? null)) ?></td>
                                    <td><a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link"><?= htmlspecialchars($t['invoice']) ?></a></td>
                                    <td>
                                        <?php if (!empty($t['member_nama'])): ?>
                                            <span class="fw"><?= htmlspecialchars($t['member_nama']) ?></span>
                                            <div style="font-size:10px;color:var(--gray-400);font-family:'DM Mono',monospace;"><?= htmlspecialchars($t['member_kode'] ?? '') ?></div>
                                        <?php else: ?>
                                            <span style="color:var(--gray-400);">Umum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($metode)) ?></span></td>
                                    <td class="r c-red fw"><?= ($t['diskon'] ?? 0) > 0 ? rupiah($t['diskon']) : '<span style="color:var(--gray-300)">—</span>' ?></td>
                                    <td class="r c-orange fw"><?= ($t['diskon_barang'] ?? 0) > 0 ? rupiah($t['diskon_barang']) : '<span style="color:var(--gray-300)">—</span>' ?></td>
                                    <td class="r fw" style="font-weight:700;"><?= rupiah($t['total'] ?? 0) ?></td>
                                    <td class="r"><?= rupiah($t['bayar'] ?? 0) ?></td>
                                    <td class="r"><?= rupiah($t['kembalian'] ?? 0) ?></td>
                                    <td class="r c-green fw"><?= ($t['point_dapat'] ?? 0) > 0 ? angka($t['point_dapat']) . ' pt' : '<span style="color:var(--gray-300)">—</span>' ?></td>
                                    <td class="r c-amber fw"><?= ($t['point_pakai'] ?? 0) > 0 ? angka($t['point_pakai']) . ' pt<br><span style="font-size:10px;color:var(--gray-400);">-' . rupiah($t['nilai_point_pakai'] ?? 0) . '</span>' : '<span style="color:var(--gray-300)">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($transaksi): ?>
                            <tfoot>
                                <tr>
                                    <td colspan="4" style="color:var(--gray-400);">Total</td>
                                    <td class="r c-red"><?= rupiah($diskonTrxSum) ?></td>
                                    <td class="r c-orange"><?= rupiah($diskonBarangTotal) ?></td>
                                    <td class="r c-blue"><?= rupiah($omzet) ?></td>
                                    <td class="r"><?= rupiah($totalBayar) ?></td>
                                    <td class="r"><?= rupiah($totalKembalian) ?></td>
                                    <td class="r c-green"><?= angka($totalPoint) ?> pt</td>
                                    <td class="r c-amber"><?= angka($totalPointPakai) ?> pt</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Mobile / Tablet card list -->
                <div class="card-list">
                    <?php if (!$transaksi): ?>
                        <div class="card-item" style="text-align:center;color:var(--gray-400);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:36px;">
                            Belum ada transaksi
                        </div>
                    <?php endif; ?>
                    <?php foreach ($transaksi as $t):
                        $metode = $t['metode_pembayaran'] ?? 'tunai';
                        $badgeCls = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                    ?>
                        <div class="card-item">
                            <div class="card-item-header">
                                <div>
                                    <div class="card-item-title">
                                        <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link"><?= htmlspecialchars($t['invoice']) ?></a>
                                    </div>
                                    <div class="card-item-sub"><?= htmlspecialchars(waktu($t['created_at'] ?? null)) ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="card-item-amount"><?= rupiah($t['total'] ?? 0) ?></div>
                                    <span class="badge <?= $badgeCls ?>" style="margin-top:4px;"><?= htmlspecialchars(strtoupper($metode)) ?></span>
                                </div>
                            </div>
                            <div class="card-item-rows">
                                <div class="card-row">
                                    <span class="card-row-label">Member</span>
                                    <span class="card-row-val"><?= !empty($t['member_nama']) ? htmlspecialchars($t['member_nama']) : '<span style="color:var(--gray-400)">Umum</span>' ?></span>
                                </div>
                                <?php if (($t['diskon'] ?? 0) > 0): ?>
                                    <div class="card-row">
                                        <span class="card-row-label">Diskon Trx</span>
                                        <span class="card-row-val c-red"><?= rupiah($t['diskon']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (($t['diskon_barang'] ?? 0) > 0): ?>
                                    <div class="card-row">
                                        <span class="card-row-label">Diskon Barang</span>
                                        <span class="card-row-val c-orange"><?= rupiah($t['diskon_barang']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="card-row">
                                    <span class="card-row-label">Bayar</span>
                                    <span class="card-row-val"><?= rupiah($t['bayar'] ?? 0) ?></span>
                                </div>
                                <div class="card-row">
                                    <span class="card-row-label">Kembalian</span>
                                    <span class="card-row-val"><?= rupiah($t['kembalian'] ?? 0) ?></span>
                                </div>
                                <?php if (($t['point_dapat'] ?? 0) > 0): ?>
                                    <div class="card-row">
                                        <span class="card-row-label">Poin Didapat</span>
                                        <span class="card-row-val c-green"><?= angka($t['point_dapat']) ?> pt</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (($t['point_pakai'] ?? 0) > 0): ?>
                                    <div class="card-row">
                                        <span class="card-row-label">Point Ditukar</span>
                                        <span class="card-row-val c-amber"><?= angka($t['point_pakai']) ?> pt / -<?= rupiah($t['nilai_point_pakai'] ?? 0) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($transaksi): ?>
                        <div class="table-footer-mobile">
                            <span>Total: <strong class="c-blue"><?= rupiah($omzet) ?></strong></span>
                            <span>Diskon: <strong class="c-red"><?= rupiah($totalDiskon) ?></strong></span>
                            <span>Bayar: <strong><?= rupiah($totalBayar) ?></strong></span>
                            <span>Poin: <strong class="c-green"><?= angka($totalPoint) ?> pt</strong></span>
                            <span>Point Ditukar: <strong class="c-amber"><?= angka($totalPointPakai) ?> pt</strong></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ── Produk + Diskon ────────────────────────────────────── -->
            <div class="col2">

                <!-- Produk terlaris -->
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="panel-title">Produk Terlaris</div>
                            <div class="panel-sub">Top 20 berdasarkan qty terjual</div>
                        </div>
                    </div>

                    <!-- Desktop -->
                    <div class="tbl-desktop table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th class="r">Qty</th>
                                    <th class="r">Diskon</th>
                                    <th class="r">Penjualan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$produk): ?>
                                    <tr class="empty-row">
                                        <td colspan="4">Belum ada produk</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($produk as $i => $p): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <?php if ($i < 3): ?><span class="rank-circle"><?= $i + 1 ?></span><?php endif; ?>
                                                <div>
                                                    <div class="fw"><?= htmlspecialchars($p['nama']) ?></div>
                                                    <div style="font-size:10px;color:var(--gray-400);font-family:'DM Mono',monospace;"><?= htmlspecialchars($p['kode'] ?? '') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="r fw"><?= angka($p['qty']) ?></td>
                                        <td class="r c-red"><?= $p['diskon'] > 0 ? rupiah($p['diskon']) : '<span style="color:var(--gray-300)">—</span>' ?></td>
                                        <td class="r fw"><?= rupiah($p['penjualan']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php if (!$produk): ?>
                            <div class="card-item" style="text-align:center;color:var(--gray-400);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:36px;">Belum ada produk</div>
                        <?php endif; ?>
                        <?php foreach ($produk as $i => $p): ?>
                            <div class="card-item">
                                <div class="card-item-header">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <?php if ($i < 3): ?><span class="rank-circle" style="flex-shrink:0;"><?= $i + 1 ?></span><?php endif; ?>
                                        <div>
                                            <div class="card-item-title"><?= htmlspecialchars($p['nama']) ?></div>
                                            <div class="card-item-sub" style="font-family:'DM Mono',monospace;"><?= htmlspecialchars($p['kode'] ?? '') ?></div>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="card-item-amount"><?= rupiah($p['penjualan']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-400);margin-top:2px;"><?= angka($p['qty']) ?> pcs</div>
                                    </div>
                                </div>
                                <?php if ($p['diskon'] > 0): ?>
                                    <div class="card-row">
                                        <span class="card-row-label">Diskon</span>
                                        <span class="card-row-val c-red"><?= rupiah($p['diskon']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Diskon terpakai -->
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="panel-title">Diskon Terpakai</div>
                            <div class="panel-sub">Promo transaksi dan barang</div>
                        </div>
                    </div>

                    <!-- Desktop -->
                    <div class="tbl-desktop table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Tipe</th>
                                    <th class="r">Pakai</th>
                                    <th class="r">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$diskonTransaksi && !$diskonBarang): ?>
                                    <tr class="empty-row">
                                        <td colspan="4">Belum ada diskon</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($diskonTransaksi as $d): ?>
                                    <tr>
                                        <td class="fw"><?= htmlspecialchars($d['nama']) ?></td>
                                        <td><span class="badge badge-trx">Transaksi</span></td>
                                        <td class="r"><?= angka($d['jumlah']) ?>×</td>
                                        <td class="r c-red fw"><?= rupiah($d['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($diskonBarang as $d): ?>
                                    <tr>
                                        <td class="fw"><?= htmlspecialchars($d['nama']) ?></td>
                                        <td><span class="badge badge-barang">Barang</span></td>
                                        <td class="r"><?= angka($d['jumlah']) ?>×</td>
                                        <td class="r c-red fw"><?= rupiah($d['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($diskonTransaksi || $diskonBarang): ?>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="color:var(--gray-400);">Total Diskon</td>
                                        <td class="r c-red"><?= rupiah($totalDiskon) ?></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php if (!$diskonTransaksi && !$diskonBarang): ?>
                            <div class="card-item" style="text-align:center;color:var(--gray-400);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:36px;">Belum ada diskon</div>
                        <?php endif; ?>
                        <?php foreach ($diskonTransaksi as $d): ?>
                            <div class="card-item">
                                <div class="card-item-header">
                                    <div>
                                        <div class="card-item-title"><?= htmlspecialchars($d['nama']) ?></div>
                                        <div style="margin-top:4px;"><span class="badge badge-trx">Transaksi</span></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="card-item-amount c-red"><?= rupiah($d['total']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-400);margin-top:2px;"><?= angka($d['jumlah']) ?>× dipakai</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($diskonBarang as $d): ?>
                            <div class="card-item">
                                <div class="card-item-header">
                                    <div>
                                        <div class="card-item-title"><?= htmlspecialchars($d['nama']) ?></div>
                                        <div style="margin-top:4px;"><span class="badge badge-barang">Barang</span></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="card-item-amount c-red"><?= rupiah($d['total']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-400);margin-top:2px;"><?= angka($d['jumlah']) ?>× dipakai</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($diskonTransaksi || $diskonBarang): ?>
                            <div class="table-footer-mobile">
                                <span>Total Diskon: <strong class="c-red"><?= rupiah($totalDiskon) ?></strong></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- ── Poin Member ─────────────────────────────────────────── -->
            <?php if ($memberPoin): ?>
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <div class="panel-title">Poin Member — Periode Ini</div>
                            <div class="panel-sub">Poin dari transaksi dalam periode ini saja (bukan kumulatif lifetime)</div>
                        </div>
                    </div>

                    <!-- Desktop -->
                    <div class="tbl-desktop table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Kode</th>
                                    <th class="r">Trx</th>
                                    <th class="r">Total Belanja</th>
                                    <th class="r">Poin Periode</th>
                                    <th class="r">Poin Total (DB)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($memberPoin as $mp): ?>
                                    <tr>
                                        <td class="fw"><?= htmlspecialchars($mp['nama']) ?></td>
                                        <td style="font-size:11px;color:var(--gray-400);font-family:'DM Mono',monospace;"><?= htmlspecialchars($mp['kode']) ?></td>
                                        <td class="r"><?= angka($mp['jumlah_trx']) ?></td>
                                        <td class="r fw"><?= rupiah($mp['total_belanja']) ?></td>
                                        <td class="r c-green fw" style="font-weight:700;"><?= angka($mp['point_periode']) ?> pt</td>
                                        <td class="r" style="color:var(--gray-400);"><?= angka($mp['point_total_lifetime']) ?> pt</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php foreach ($memberPoin as $mp): ?>
                            <div class="card-item">
                                <div class="card-item-header">
                                    <div>
                                        <div class="card-item-title"><?= htmlspecialchars($mp['nama']) ?></div>
                                        <div class="card-item-sub" style="font-family:'DM Mono',monospace;"><?= htmlspecialchars($mp['kode']) ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="card-item-amount c-green"><?= angka($mp['point_periode']) ?> pt</div>
                                        <div style="font-size:11px;color:var(--gray-400);margin-top:2px;">periode ini</div>
                                    </div>
                                </div>
                                <div class="card-item-rows">
                                    <div class="card-row">
                                        <span class="card-row-label">Total Belanja</span>
                                        <span class="card-row-val"><?= rupiah($mp['total_belanja']) ?></span>
                                    </div>
                                    <div class="card-row">
                                        <span class="card-row-label">Jumlah Trx</span>
                                        <span class="card-row-val"><?= angka($mp['jumlah_trx']) ?>×</span>
                                    </div>
                                    <div class="card-row">
                                        <span class="card-row-label">Poin Total (DB)</span>
                                        <span class="card-row-val" style="color:var(--gray-400);"><?= angka($mp['point_total_lifetime']) ?> pt</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        </main>
    </div>


    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav no-print">
        <button type="button" onclick="toggleSidebar()" aria-label="Menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                <path d="M3 12h18M3 6h18M3 18h18" />
            </svg>
            <span>Menu</span>
        </button>
        <a href="pos.php" class="pos-fab" aria-label="POS">
            <svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M8 12h8" />
            </svg>
        </a>
        <a href="laporan.php" aria-label="Laporan">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" />
            </svg>
            <span>Laporan</span>
        </a>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
        }

        // Auto-switch preset to custom when dates are manually changed
        document.querySelectorAll('input[name="awal"], input[name="akhir"]').forEach(el => {
            el.addEventListener('change', () => {
                document.querySelector('select[name="preset"]').value = 'custom';
            });
        });
    </script>

</body>

</html>