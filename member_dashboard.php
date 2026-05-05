<?php
session_start();
require_once 'config.php';

// Nilai tukar point mengikuti POS: 1 point = Rp 1.000
if (!defined('MEMBER_POINT_RUPIAH')) {
    define('MEMBER_POINT_RUPIAH', 1000);
}

if (isset($_GET['logout'])) {
    unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_kode']);
    header('Location: member_login.php');
    exit;
}

if (empty($_SESSION['member_id'])) {
    header('Location: member_login.php');
    exit;
}

/**
 * @param mixed $v
 */
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * @param mixed $v
 */
function rupiah_member($v): string
{
    return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * @param mixed $v
 */
function angka_member($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * @param mixed $v
 */
function tanggal_member($v): string
{
    return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
}

/**
 * @param mixed $v
 */
function tanggal_short($v): string
{
    return $v ? date('d M Y', strtotime((string)$v)) : '-';
}

/** 
 * @param mixed $name
 */
function member_initial($name): string
{
    $name = trim((string)($name ?? ''));
    if ($name === '') {
        return 'MB';
    }
    if (function_exists('mb_substr')) {
        return strtoupper(mb_substr($name, 0, 2));
    }
    return strtoupper(substr($name, 0, 2));
}

function has_column_member(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$memberId = (int)$_SESSION['member_id'];

try {
    $stmt = $pdo->prepare("SELECT id,kode,nama,no_hp,point,total_belanja,status,created_at,updated_at FROM member WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        unset($_SESSION['member_id']);
        header('Location: member_login.php');
        exit;
    }

    $hasDiskon          = has_column_member($pdo, 'transaksi', 'diskon');
    $hasPoint           = has_column_member($pdo, 'transaksi', 'point_dapat');
    $hasPointPakai      = has_column_member($pdo, 'transaksi', 'point_pakai');
    $hasNilaiPointPakai = has_column_member($pdo, 'transaksi', 'nilai_point_pakai');

    $diskonSelect          = $hasDiskon ? "COALESCE(t.diskon,0) AS diskon_transaksi" : "0 AS diskon_transaksi";
    $pointSelect           = $hasPoint ? "COALESCE(t.point_dapat,0) AS point_transaksi" : "0 AS point_transaksi";
    $pointPakaiSelect      = $hasPointPakai ? "COALESCE(t.point_pakai,0) AS point_pakai" : "0 AS point_pakai";
    $nilaiPointPakaiSelect = $hasNilaiPointPakai ? "COALESCE(t.nilai_point_pakai,0) AS nilai_point_pakai" : "0 AS nilai_point_pakai";

    $sumPoint = $hasPoint
        ? "COALESCE(SUM(CASE WHEN COALESCE(t.point_dapat,0)>0 THEN t.point_dapat ELSE FLOOR(COALESCE(t.total,0)/10000) END),0)"
        : "COALESCE(SUM(FLOOR(COALESCE(t.total,0)/10000)),0)";
    $sumPointPakai      = $hasPointPakai ? "COALESCE(SUM(t.point_pakai),0)" : "0";
    $sumNilaiPointPakai = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai),0)" : "0";

    $stmtSum = $pdo->prepare("
        SELECT
            COUNT(t.id) AS jumlah_transaksi,
            COALESCE(SUM(t.total),0) AS total_belanja_transaksi,
            COALESCE(SUM(
                GREATEST(
                    COALESCE(t.diskon,0),
                    GREATEST(
                        0,
                        COALESCE((
                            SELECT SUM(COALESCE(td.harga_normal,td.harga) * td.qty)
                            FROM transaksi_detail td
                            WHERE td.transaksi_id = t.id
                        ), t.total) - (COALESCE(t.total,0) + COALESCE(t.nilai_point_pakai,0))
                    )
                )
            ),0) AS total_diskon_transaksi,
            $sumPoint AS total_point_dari_transaksi,
            $sumPointPakai AS total_point_pakai,
            $sumNilaiPointPakai AS total_nilai_point_pakai
        FROM transaksi t
        WHERE t.member_id=:member_id");
    $stmtSum->execute([':member_id' => $memberId]);
    $summary = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtTrx = $pdo->prepare("
        SELECT
            t.id AS transaksi_id,
            t.invoice,
            t.created_at AS tanggal_transaksi,
            COALESCE(t.total,0) AS total_transaksi,
            COALESCE(t.bayar,0) AS bayar_transaksi,
            COALESCE(t.kembalian,0) AS kembalian_transaksi,
            COALESCE((
                SELECT SUM(COALESCE(td.harga_normal,td.harga) * td.qty)
                FROM transaksi_detail td
                WHERE td.transaksi_id = t.id
            ), t.total) AS total_sebelum_diskon,
            $diskonSelect,
            $pointSelect,
            $pointPakaiSelect,
            $nilaiPointPakaiSelect
        FROM transaksi t
        WHERE t.member_id=:member_id
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 50");
    $stmtTrx->execute([':member_id' => $memberId]);
    $transaksi = $stmtTrx->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die('Gagal memuat dashboard: ' . h($e->getMessage()));
}

$jumlahTransaksi         = (int)($summary['jumlah_transaksi']           ?? 0);
$totalBelanjaTransaksi   = (int)($summary['total_belanja_transaksi']    ?? 0);
$totalDiskonTransaksi    = (int)($summary['total_diskon_transaksi']     ?? 0);
$totalPointDariTransaksi = (int)($summary['total_point_dari_transaksi'] ?? 0);
$totalPointPakai         = (int)($summary['total_point_pakai']           ?? 0);
$totalNilaiPointPakai    = (int)($summary['total_nilai_point_pakai']     ?? 0);
$saldoPoint              = (int)($member['point']                       ?? 0);
$totalBelanjaProfil      = (int)($member['total_belanja']               ?? 0);
$trxBeranda = array_slice($transaksi, 0, 3);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Dashboard Member — Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ── Reset ── */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --black: #0a0a0a;
            --g1: #111827;
            --g2: #374151;
            --g3: #6b7280;
            --g4: #9ca3af;
            --g5: #d1d5db;
            --g6: #e5e7eb;
            --g7: #f3f4f6;
            --g8: #f9fafb;
            --white: #fff;
            --r: 2px;
            --nav-h: 64px;
            --hdr-h: 56px;
            --sidebar-w: 260px;
        }

        html,
        body {
            height: 100%;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 14px;
            color: var(--g1);
            -webkit-font-smoothing: antialiased;
            background: #f0f0f0;
        }

        /* ══════════════════════════
   DESKTOP LAYOUT (≥1024px)
══════════════════════════ */
        @media(min-width:1024px) {
            body {
                background: #ececec;
            }

            .app-shell {
                display: grid;
                grid-template-columns: var(--sidebar-w) 1fr;
                grid-template-rows: var(--hdr-h) 1fr;
                min-height: 100vh;
                width: 100%;
                max-width: none;
                margin: 0;
                background: var(--white);
                box-shadow: none;
            }

            .desktop-topbar {
                grid-column: 1/-1;
                grid-row: 1;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 24px;
                border-bottom: 0.5px solid var(--g6);
                background: var(--white);
                position: sticky;
                top: 0;
                z-index: 300;
            }

            .desktop-sidebar {
                grid-column: 1;
                grid-row: 2;
                border-right: 0.5px solid var(--g6);
                background: var(--white);
                position: sticky;
                top: var(--hdr-h);
                height: calc(100vh - var(--hdr-h));
                overflow-y: auto;
                display: flex;
                flex-direction: column;
            }

            .desktop-content {
                grid-column: 2;
                grid-row: 2;
                overflow-y: auto;
                min-height: calc(100vh - var(--hdr-h));
                height: auto;
            }

            .bottom-nav {
                display: none;
            }

            .page {
                min-height: unset;
                padding-bottom: 24px;
            }

            .page.active {
                display: flex;
            }
        }

        /* Tablet + Mobile hide sidebar/topbar */
        @media(max-width:1023px) {

            .desktop-topbar,
            .desktop-sidebar,
            .desktop-content {
                display: contents;
            }

            .app-shell {
                width: 100%;
                max-width: none;
                margin: 0;
                background: var(--white);
                min-height: 100dvh;
                position: relative;
                overflow-x: hidden;
                overflow-y: auto;
                box-shadow: 0 0 0 0.5px var(--g6);
            }
        }

        /* ── Sidebar Nav ── */
        .sidebar-profile {
            padding: 20px 16px 16px;
            border-bottom: 0.5px solid var(--g6);
        }

        .sidebar-avatar {
            width: 48px;
            height: 48px;
            border-radius: var(--r);
            background: var(--black);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 10px;
        }

        .sidebar-name {
            font-size: 14px;
            font-weight: 800;
            color: var(--black);
        }

        .sidebar-code {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-top: 2px;
        }

        .sidebar-pt {
            margin-top: 10px;
            padding: 8px 12px;
            background: var(--black);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-pt-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: rgba(255, 255, 255, .5);
        }

        .sidebar-pt-val {
            font-size: 16px;
            font-weight: 900;
            color: var(--white);
            letter-spacing: -.03em;
        }

        .sidebar-pt-unit {
            font-size: 10px;
            font-weight: 700;
            color: rgba(255, 255, 255, .5);
            margin-left: 2px;
        }

        .sidebar-menu {
            padding: 12px 0;
            flex: 1;
        }

        .sidebar-section-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g4);
            padding: 12px 16px 6px;
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            cursor: pointer;
            border: none;
            background: none;
            font-family: inherit;
            width: 100%;
            text-align: left;
            transition: background .12s;
            border-radius: 0;
            position: relative;
        }

        .sidebar-item:hover {
            background: var(--g8);
        }

        .sidebar-item.active {
            background: var(--g7);
        }

        .sidebar-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--black);
        }

        .si-ico {
            width: 32px;
            height: 32px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            flex-shrink: 0;
        }

        .si-ico svg {
            width: 14px;
            height: 14px;
            stroke: var(--black);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .sidebar-item.active .si-ico {
            background: var(--black);
        }

        .sidebar-item.active .si-ico svg {
            stroke: var(--white);
        }

        .si-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--g1);
        }

        .sidebar-item.active .si-label {
            font-weight: 800;
            color: var(--black);
        }

        .si-badge {
            margin-left: auto;
            background: var(--black);
            color: var(--white);
            font-size: 9px;
            font-weight: 900;
            padding: 2px 7px;
            border-radius: 10px;
            letter-spacing: .04em;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 0.5px solid var(--g6);
        }

        /* ── Desktop Topbar ── */
        .dtb-logo {
            font-size: 13px;
            font-weight: 900;
            letter-spacing: -.02em;
            border-bottom: 2px solid var(--black);
            padding-bottom: 2px;
        }

        .dtb-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dtb-user {
            text-align: right;
        }

        .dtb-uname {
            font-size: 12px;
            font-weight: 800;
            color: var(--black);
        }

        .dtb-ukode {
            font-size: 10px;
            font-weight: 600;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .dtb-notif {
            width: 36px;
            height: 36px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .12s;
        }

        .dtb-notif:hover {
            background: var(--g8);
        }

        .dtb-notif svg {
            width: 16px;
            height: 16px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ── Pages ── */
        .page {
            display: none;
            flex-direction: column;
            min-height: 100dvh;
            padding-bottom: calc(var(--nav-h)+16px);
            overflow-y: auto;
        }

        .page.active {
            display: flex;
        }

        @media(min-width:1024px) {
            .page {
                min-height: unset;
                padding-bottom: 24px;
            }

            .page-top {
                display: none;
            }
        }

        /* ── Bottom Nav ── */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            max-width: none;
            height: var(--nav-h);
            background: var(--white);
            border-top: 0.5px solid var(--g6);
            display: flex;
            align-items: stretch;
            z-index: 200;
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }

        .nav-btn {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            border: none;
            background: none;
            cursor: pointer;
            position: relative;
            padding: 0;
            transition: background .15s;
        }

        .nav-btn:hover {
            background: var(--g8);
        }

        .nav-btn .nav-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .nav-btn .nav-icon svg {
            width: 20px;
            height: 20px;
            stroke: var(--g4);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: stroke .15s;
        }

        .nav-btn .nav-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            transition: color .15s;
        }

        .nav-btn.active .nav-icon svg {
            stroke: var(--black);
        }

        .nav-btn.active .nav-label {
            color: var(--black);
        }

        .nav-btn.active::after {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 24px;
            height: 2px;
            background: var(--black);
        }

        .nav-badge {
            position: absolute;
            top: -4px;
            right: -6px;
            min-width: 16px;
            height: 16px;
            background: var(--black);
            color: var(--white);
            font-size: 9px;
            font-weight: 900;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        /* ── Shared Components ── */
        .page-top {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--white);
            border-bottom: 0.5px solid var(--g6);
            padding: 0 16px;
            height: var(--hdr-h);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .page-top-title {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .2em;
        }

        .logo-mark {
            font-size: 11px;
            font-weight: 900;
            letter-spacing: -.02em;
            border-bottom: 2px solid var(--black);
            padding-bottom: 2px;
            line-height: 1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            border-radius: var(--r);
            border: 0.5px solid var(--g6);
            background: var(--white);
            color: var(--g2);
            text-decoration: none;
            transition: background .12s, border-color .12s;
            white-space: nowrap;
        }

        .btn:hover {
            background: var(--g8);
        }

        .btn-black {
            background: var(--black);
            color: var(--white);
            border-color: var(--black);
        }

        .btn-black:hover {
            background: var(--g2);
            border-color: var(--g2);
        }

        .btn-danger {
            border-color: #fca5a5;
            color: #dc2626;
        }

        .btn-danger:hover {
            background: #fef2f2;
        }

        .section-label {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g4);
        }

        .card {
            background: var(--white);
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
        }

        .divider {
            height: 0.5px;
            background: var(--g6);
        }

        .gap-section {
            height: 8px;
            background: var(--g8);
            flex-shrink: 0;
        }

        .no-scrollbar {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-radius: var(--r);
            border: 0.5px solid var(--g6);
            color: var(--g2);
            background: var(--g7);
        }

        .badge-active {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .badge-selesai {
            background: var(--g7);
            color: var(--g2);
            border-color: var(--g5);
        }

        /* ── Desktop Content Wrapper ── */
        .dc-wrap {
            padding: 28px 32px;
        }

        .dc-title {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.03em;
            color: var(--black);
            margin-bottom: 20px;
        }

        .dc-subtitle {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
            margin-bottom: 20px;
        }

        /* ── Stat Cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            padding: 16px;
        }

        @media(min-width:1024px) {
            .stats-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 12px;
                padding: 0 0 20px;
            }
        }

        .stat-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            padding: 14px;
            transition: border-color .15s;
        }

        .stat-card:hover {
            border-color: var(--g4);
        }

        .stat-card-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
        }

        .stat-card-value {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -.03em;
            margin-top: 6px;
            line-height: 1;
            color: var(--black);
        }

        .stat-card-sub {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 4px;
        }

        @media(min-width:1024px) {
            .stat-card-value {
                font-size: 24px;
            }
        }

        /* ── Tabs ── */
        .tab-bar {
            display: flex;
            border-bottom: 0.5px solid var(--g6);
            background: var(--white);
            overflow-x: auto;
            flex-shrink: 0;
        }

        .tab-btn {
            padding: 12px 16px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            border: none;
            border-bottom: 2px solid transparent;
            background: none;
            cursor: pointer;
            white-space: nowrap;
            transition: color .12s;
            flex-shrink: 0;
        }

        .tab-btn:hover {
            color: var(--g2);
        }

        .tab-btn.active {
            color: var(--black);
            border-bottom-color: var(--black);
        }

        /* ══════════════════════════
   PAGE: BERANDA
══════════════════════════ */
        .beranda-hero {
            background: var(--black);
            color: var(--white);
            padding: 24px 16px 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .beranda-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255, 255, 255, .025) 20px, rgba(255, 255, 255, .025) 21px);
            pointer-events: none;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
        }

        .hero-greeting {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .15em;
            opacity: .5;
        }

        .hero-name {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.02em;
            margin-top: 4px;
            line-height: 1.1;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
            position: relative;
            z-index: 1;
        }

        .hero-tag {
            padding: 4px 10px;
            border: 0.5px solid rgba(255, 255, 255, .2);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border-radius: var(--r);
            color: rgba(255, 255, 255, .7);
            background: rgba(255, 255, 255, .08);
        }

        .hero-tag-active {
            border-color: rgba(255, 255, 255, .5);
            color: var(--white);
            background: rgba(255, 255, 255, .15);
        }

        .point-strip {
            margin: 0 16px;
            margin-top: 16px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: grid;
            grid-template-columns: 1fr auto;
            overflow: hidden;
            flex-shrink: 0;
        }

        .point-strip-left {
            padding: 14px 16px;
            border-right: 0.5px solid var(--g6);
        }

        .point-strip-right {
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
        }

        .point-number {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -.04em;
            color: var(--black);
            line-height: 1;
            margin-top: 4px;
        }

        .point-unit {
            font-size: 12px;
            font-weight: 700;
            color: var(--g4);
            margin-left: 3px;
        }

        .quick-menu-wrap {
            padding: 20px 16px 16px;
            flex-shrink: 0;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            margin-top: 12px;
        }

        @media(min-width:640px) {
            .quick-grid {
                grid-template-columns: repeat(8, 1fr);
            }
        }

        .quick-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 12px 4px;
            border: none;
            background: none;
            cursor: pointer;
            font-family: inherit;
            border-radius: 4px;
            transition: background .12s;
        }

        .quick-item:hover {
            background: var(--g8);
        }

        .quick-ico {
            width: 44px;
            height: 44px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
        }

        .quick-ico svg {
            width: 18px;
            height: 18px;
            stroke: var(--black);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .quick-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--g2);
            text-align: center;
            line-height: 1.3;
        }

        .promo-mini-scroll {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 0 16px 4px;
            flex-shrink: 0;
        }

        .promo-mini-card {
            min-width: 180px;
            height: 80px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            padding: 12px;
            flex-shrink: 0;
            background: var(--white);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            transition: border-color .12s;
        }

        .promo-mini-card:hover {
            border-color: var(--g4);
        }

        .promo-mini-card::after {
            content: attr(data-icon);
            position: absolute;
            right: -4px;
            bottom: -8px;
            font-size: 52px;
            opacity: .07;
            line-height: 1;
            pointer-events: none;
        }

        .pmc-tag {
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            border: 0.5px solid var(--g5);
            padding: 2px 6px;
            border-radius: var(--r);
            display: inline-block;
            width: fit-content;
        }

        .pmc-title {
            font-size: 12px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.01em;
            line-height: 1.2;
        }

        .trx-list {
            padding: 0 16px;
            flex-shrink: 0;
        }

        .trx-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--g7);
        }

        .trx-row:last-child {
            border-bottom: none;
        }

        .trx-icon-box {
            width: 38px;
            height: 38px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--g8);
        }

        .trx-icon-box svg {
            width: 16px;
            height: 16px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .trx-info {
            flex: 1;
            min-width: 0;
        }

        .trx-inv {
            font-size: 12px;
            font-weight: 800;
            color: var(--black);
        }

        .trx-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .trx-right {
            text-align: right;
            flex-shrink: 0;
        }

        .trx-total {
            font-size: 13px;
            font-weight: 900;
            color: var(--black);
        }

        .trx-pt {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            margin-top: 2px;
        }

        /* ══════════════════════════
   PAGE: BELANJA
══════════════════════════ */
        .search-wrap {
            padding: 12px 16px;
            background: var(--white);
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .search-inner {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            padding: 0 12px;
            height: 38px;
            background: var(--g8);
        }

        .search-inner svg {
            width: 15px;
            height: 15px;
            stroke: var(--g4);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .search-inner input {
            flex: 1;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            color: var(--black);
            outline: none;
        }

        .search-inner input::placeholder {
            color: var(--g4);
            font-weight: 500;
        }

        .cat-scroll-wrap {
            padding: 10px 16px;
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .cat-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
        }

        .cat-chip {
            padding: 6px 14px;
            border: 0.5px solid var(--g6);
            border-radius: 20px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--g4);
            background: var(--white);
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all .12s;
        }

        .cat-chip:hover {
            border-color: var(--g4);
            color: var(--g2);
        }

        .cat-chip.active {
            background: var(--black);
            color: var(--white);
            border-color: var(--black);
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            padding: 12px 16px;
        }

        @media(min-width:640px) {
            .product-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(min-width:1024px) {
            .product-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .prod-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            overflow: hidden;
            background: var(--white);
            cursor: pointer;
            transition: border-color .12s, box-shadow .12s;
        }

        .prod-card:hover {
            border-color: var(--g4);
            box-shadow: 0 2px 12px rgba(0, 0, 0, .06);
        }

        .prod-img-box {
            height: 96px;
            background: var(--g8);
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 0.5px solid var(--g6);
            font-size: 36px;
        }

        .prod-body {
            padding: 10px;
        }

        .prod-name {
            font-size: 11px;
            font-weight: 700;
            color: var(--black);
            line-height: 1.35;
        }

        .prod-ori {
            font-size: 10px;
            color: var(--g4);
            text-decoration: line-through;
            margin-top: 4px;
            font-weight: 600;
        }

        .prod-price {
            font-size: 14px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.02em;
            margin-top: 2px;
        }

        .prod-discount-badge {
            display: inline-block;
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 2px 5px;
            border-radius: var(--r);
            background: var(--g1);
            color: var(--white);
            margin-bottom: 3px;
        }

        .prod-add-btn {
            margin-top: 8px;
            width: 100%;
            padding: 8px;
            background: var(--black);
            color: var(--white);
            border: none;
            border-radius: var(--r);
            font-family: inherit;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            transition: background .12s;
        }

        .prod-add-btn:hover {
            background: var(--g2);
        }

        /* ══════════════════════════
   PAGE: PROMO
══════════════════════════ */
        .promo-hero-bar {
            background: var(--black);
            color: var(--white);
            padding: 20px 16px;
            flex-shrink: 0;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .promo-hero-bar::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(-45deg, transparent, transparent 20px, rgba(255, 255, 255, .03) 20px, rgba(255, 255, 255, .03) 21px);
            pointer-events: none;
        }

        .promo-hero-bar h2 {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -.03em;
            position: relative;
            z-index: 1;
        }

        .promo-hero-bar p {
            font-size: 11px;
            font-weight: 600;
            opacity: .5;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .1em;
            position: relative;
            z-index: 1;
        }

        .promo-list {
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        @media(min-width:1024px) {
            .promo-list {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .promo-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            overflow: hidden;
            background: var(--white);
            cursor: pointer;
            transition: border-color .12s, box-shadow .12s;
        }

        .promo-card:hover {
            border-color: var(--g4);
            box-shadow: 0 2px 12px rgba(0, 0, 0, .06);
        }

        .promo-card-top {
            padding: 16px;
            background: var(--g1);
            color: var(--white);
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .promo-card-top.invert {
            background: var(--white);
            color: var(--black);
            border-bottom: 0.5px solid var(--g6);
        }

        .promo-card-top::after {
            content: attr(data-icon);
            position: absolute;
            right: -8px;
            top: -8px;
            font-size: 72px;
            opacity: .08;
            line-height: 1;
            pointer-events: none;
        }

        .promo-card-top.invert::after {
            opacity: .12;
        }

        .promo-card-badge {
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            padding: 3px 8px;
            border-radius: var(--r);
            display: inline-block;
            margin-bottom: 8px;
            background: rgba(255, 255, 255, .15);
            color: rgba(255, 255, 255, .9);
            border: 0.5px solid rgba(255, 255, 255, .2);
        }

        .promo-card-top.invert .promo-card-badge {
            background: var(--g7);
            color: var(--g2);
            border-color: var(--g5);
        }

        .promo-card-title {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: -.02em;
            line-height: 1.2;
        }

        .promo-card-desc {
            font-size: 11px;
            opacity: .65;
            margin-top: 4px;
            font-weight: 600;
        }

        .promo-card-top.invert .promo-card-desc {
            opacity: .5;
        }

        .promo-card-foot {
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--white);
        }

        .promo-until {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .promo-klaim-btn {
            padding: 6px 16px;
            background: var(--black);
            color: var(--white);
            border: none;
            border-radius: var(--r);
            font-family: inherit;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            transition: background .12s;
        }

        .promo-klaim-btn:hover {
            background: var(--g2);
        }

        .promo-klaim-btn.claimed {
            background: var(--g7);
            color: var(--g4);
            cursor: default;
        }

        /* ══════════════════════════
   PAGE: PESANAN — Card List
══════════════════════════ */
        .order-summary-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            border-bottom: 0.5px solid var(--g6);
        }

        .osb-item {
            padding: 14px 10px;
            text-align: center;
            border-right: 0.5px solid var(--g6);
        }

        .osb-item:last-child {
            border-right: none;
        }

        .osb-val {
            font-size: 16px;
            font-weight: 900;
            color: var(--black);
        }

        .osb-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            margin-top: 4px;
        }

        .order-list {
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        @media(min-width:1024px) {
            .order-list {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                align-items: start;
            }
        }

        .order-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            overflow: hidden;
            background: var(--white);
            transition: border-color .12s, box-shadow .12s;
        }

        .order-card:hover {
            border-color: var(--g5);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        .order-card-head {
            padding: 12px 14px;
            border-bottom: 0.5px solid var(--g7);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--g8);
        }

        .order-store {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--black);
        }

        .order-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .order-items-wrap {
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .order-item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .oi-name {
            font-size: 12px;
            color: var(--g2);
            font-weight: 600;
        }

        .oi-price {
            font-size: 12px;
            font-weight: 800;
            color: var(--black);
        }

        .order-card-foot {
            padding: 10px 14px;
            border-top: 0.5px solid var(--g7);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-total-wrap .ot-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
        }

        .order-total-wrap .ot-val {
            font-size: 15px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.02em;
            margin-top: 1px;
        }

        .order-total-wrap .ot-pt {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            margin-top: 2px;
        }

        .order-actions {
            display: flex;
            gap: 6px;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            gap: 12px;
        }

        .empty-state svg {
            width: 40px;
            height: 40px;
            stroke: var(--g5);
            fill: none;
            stroke-width: 1.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .empty-state p {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g5);
            text-align: center;
        }

        /* ══════════════════════════
   PAGE: AKUN
══════════════════════════ */
        .akun-hero {
            background: var(--black);
            color: var(--white);
            padding: 24px 16px 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .akun-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255, 255, 255, .025) 20px, rgba(255, 255, 255, .025) 21px);
            pointer-events: none;
        }

        .akun-avatar {
            width: 56px;
            height: 56px;
            border-radius: var(--r);
            border: 0.5px solid rgba(255, 255, 255, .3);
            background: rgba(255, 255, 255, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.04em;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }

        .akun-nama {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -.02em;
            position: relative;
            z-index: 1;
        }

        .akun-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
            position: relative;
            z-index: 1;
        }

        .akun-tag {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 3px 8px;
            border: 0.5px solid rgba(255, 255, 255, .2);
            color: rgba(255, 255, 255, .65);
            border-radius: var(--r);
        }

        .akun-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .akun-stat {
            padding: 16px 14px;
            border-right: 0.5px solid var(--g6);
            text-align: center;
        }

        .akun-stat:last-child {
            border-right: none;
        }

        .akun-stat-val {
            font-size: 18px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.03em;
            line-height: 1;
        }

        .akun-stat-lbl {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            margin-top: 4px;
        }

        .menu-section {
            padding: 16px 16px 0;
            flex-shrink: 0;
        }

        .menu-section-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g4);
            margin-bottom: 6px;
        }

        .menu-group-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            overflow: hidden;
            background: var(--white);
            margin-bottom: 16px;
        }

        .menu-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            border-bottom: 0.5px solid var(--g7);
            cursor: pointer;
            width: 100%;
            border-left: none;
            border-right: none;
            border-top: none;
            background: none;
            font-family: inherit;
            text-align: left;
            transition: background .12s;
        }

        .menu-row:last-child {
            border-bottom: none;
        }

        .menu-row:hover {
            background: var(--g8);
        }

        .menu-ico {
            width: 34px;
            height: 34px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            flex-shrink: 0;
        }

        .menu-ico svg {
            width: 15px;
            height: 15px;
            stroke: var(--black);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .menu-text-wrap {
            flex: 1;
            min-width: 0;
        }

        .menu-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--black);
        }

        .menu-sub {
            font-size: 11px;
            color: var(--g4);
            font-weight: 500;
            margin-top: 1px;
        }

        .menu-chevron {
            color: var(--g5);
            font-size: 18px;
            flex-shrink: 0;
            font-weight: 300;
        }

        .menu-badge-pill {
            font-size: 9px;
            font-weight: 900;
            padding: 3px 8px;
            background: var(--black);
            color: var(--white);
            border-radius: 10px;
            letter-spacing: .04em;
        }

        .akun-footer {
            padding: 0 16px 16px;
            flex-shrink: 0;
        }

        /* ══════════════════════════
   MODAL / OVERLAY PAGES
══════════════════════════ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 500;
            display: none;
            align-items: flex-end;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-overlay.open {
            display: flex;
        }

        @media(min-width:1024px) {
            .modal-overlay {
                align-items: center;
            }
        }

        .modal-sheet {
            background: var(--white);
            width: 100%;
            max-width: 480px;
            border-radius: 12px 12px 0 0;
            max-height: 90dvh;
            overflow-y: auto;
            animation: slideUp .25s ease;
        }

        @media(min-width:1024px) {
            .modal-sheet {
                border-radius: var(--r);
                max-height: 85vh;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        .modal-header {
            position: sticky;
            top: 0;
            background: var(--white);
            border-bottom: 0.5px solid var(--g6);
            padding: 16px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .modal-title {
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: none;
            transition: background .12s;
        }

        .modal-close:hover {
            background: var(--g8);
        }

        .modal-close svg {
            width: 14px;
            height: 14px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal-body {
            padding: 20px 16px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--black);
            background: var(--white);
            outline: none;
            transition: border-color .12s;
        }

        .form-input:focus {
            border-color: var(--black);
        }

        .form-input:disabled {
            background: var(--g8);
            color: var(--g4);
        }

        .form-hint {
            font-size: 10px;
            font-weight: 600;
            color: var(--g4);
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Point History */
        .pt-history-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--g7);
        }

        .pt-history-item:last-child {
            border-bottom: none;
        }

        .pt-hist-ico {
            width: 36px;
            height: 36px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--g8);
        }

        .pt-hist-ico svg {
            width: 14px;
            height: 14px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .pt-hist-info {
            flex: 1;
            min-width: 0;
        }

        .pt-hist-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--black);
        }

        .pt-hist-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .pt-hist-val {
            font-size: 14px;
            font-weight: 900;
            color: var(--black);
        }

        .pt-hist-val.plus {
            color: #15803d;
        }

        .pt-hist-val.minus {
            color: #dc2626;
        }

        /* Voucher */
        .voucher-card {
            border: 0.5px dashed var(--g5);
            border-radius: var(--r);
            padding: 14px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            margin-bottom: 10px;
            cursor: pointer;
            transition: border-color .12s;
        }

        .voucher-card:hover {
            border-color: var(--black);
        }

        .voucher-card::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--g8);
            border: 0.5px solid var(--g6);
        }

        .voucher-card::after {
            content: '';
            position: absolute;
            right: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--g8);
            border: 0.5px solid var(--g6);
        }

        .vc-code {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: .1em;
            color: var(--black);
        }

        .vc-desc {
            font-size: 11px;
            font-weight: 600;
            color: var(--g3);
            margin-top: 4px;
        }

        .vc-exp {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: 8px;
        }

        .vc-val {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.03em;
            color: var(--black);
        }

        /* Point Tukar */
        .tukar-point-hero {
            background: var(--black);
            color: var(--white);
            padding: 20px;
            border-radius: var(--r);
            margin-bottom: 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .tukar-point-hero::after {
            content: '⭐';
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 80px;
            opacity: .08;
        }

        .tp-balance {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .tp-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            opacity: .5;
        }

        .tukar-option {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            padding: 14px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all .12s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tukar-option:hover {
            border-color: var(--black);
            background: var(--g8);
        }

        .tukar-option.selected {
            border-color: var(--black);
            background: var(--black);
            color: var(--white);
        }

        .to-info .to-name {
            font-size: 13px;
            font-weight: 800;
        }

        .to-info .to-req {
            font-size: 11px;
            font-weight: 600;
            opacity: .5;
            margin-top: 2px;
        }

        .to-val {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        /* Bantuan */
        .faq-item {
            border-bottom: 0.5px solid var(--g7);
        }

        .faq-q {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: var(--black);
        }

        .faq-a {
            font-size: 12px;
            color: var(--g3);
            font-weight: 500;
            padding-bottom: 14px;
            line-height: 1.6;
            display: none;
        }

        .faq-a.open {
            display: block;
        }

        .faq-chevron {
            transition: transform .2s;
            font-size: 16px;
            color: var(--g4);
        }

        .faq-chevron.open {
            transform: rotate(180deg);
        }

        /* Kontak */
        .kontak-card {
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            padding: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all .12s;
            text-decoration: none;
        }

        .kontak-card:hover {
            border-color: var(--black);
            background: var(--g8);
        }

        .kontak-ico {
            width: 44px;
            height: 44px;
            border: 0.5px solid var(--g6);
            border-radius: var(--r);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
        }

        .kontak-info .kc-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--black);
        }

        .kontak-info .kc-sub {
            font-size: 11px;
            font-weight: 600;
            color: var(--g4);
            margin-top: 2px;
        }

        /* About */
        .about-logo {
            font-size: 36px;
            font-weight: 900;
            letter-spacing: -.04em;
            border-bottom: 3px solid var(--black);
            display: inline-block;
            padding-bottom: 4px;
            margin-bottom: 16px;
        }

        .about-version {
            font-size: 11px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .1em;
        }

        /* ══════════════════════════
   UTILITY
══════════════════════════ */
        .info-box {
            padding: 10px 14px;
            border: 0.5px solid var(--g5);
            border-radius: var(--r);
            background: var(--g8);
            font-size: 11px;
            color: var(--g3);
            font-weight: 600;
            margin: 12px 16px 0;
        }

        .success-toast {
            position: fixed;
            bottom: calc(var(--nav-h) + 16px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--black);
            color: var(--white);
            padding: 10px 20px;
            border-radius: var(--r);
            font-size: 12px;
            font-weight: 800;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
            white-space: nowrap;
        }

        .success-toast.show {
            opacity: 1;
        }

        @media(min-width:1024px) {
            .success-toast {
                bottom: 24px;
            }
        }

        /* ── FINAL RESPONSIVE FIX: Desktop full layar, tablet/mobile fleksibel ── */
        html,
        body {
            width: 100%;
            min-width: 0;
            overflow-x: hidden;
        }

        .app-shell,
        .desktop-content,
        .page {
            min-width: 0;
        }

        @media (min-width: 1024px) {
            .app-shell {
                width: 100vw;
                max-width: none;
                margin: 0;
            }

            .desktop-content {
                width: 100%;
                min-width: 0;
            }

            .page.active {
                width: 100%;
            }

            .dc-wrap,
            #stats-grid-wrap,
            .quick-menu-wrap,
            .trx-list,
            .promo-mini-scroll,
            .order-list,
            .promo-list,
            .product-grid {
                max-width: none;
            }

            .stats-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            }

            .order-list {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)) !important;
            }

            .promo-list {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)) !important;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) !important;
            }
        }

        @media (min-width: 641px) and (max-width: 1023px) {
            .app-shell {
                width: 100%;
                max-width: none;
            }

            .page {
                min-height: 100dvh;
                padding-bottom: calc(var(--nav-h) + 20px);
            }

            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            .quick-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            }

            .order-list {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 12px;
            }

            .promo-list {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .product-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            .modal-sheet {
                max-width: 640px;
                border-radius: var(--r);
            }
        }

        @media (max-width: 640px) {
            .app-shell {
                width: 100%;
                max-width: none;
            }

            .page {
                min-height: 100dvh;
                padding-bottom: calc(var(--nav-h) + 20px);
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                padding: 12px !important;
            }

            .stat-card {
                padding: 12px !important;
            }

            .stat-card-value {
                font-size: 17px !important;
                line-height: 1.15 !important;
                word-break: break-word;
            }

            .order-summary-bar {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .osb-item {
                border-bottom: 0.5px solid var(--g6);
            }

            .quick-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            }

            .quick-ico {
                width: 40px;
                height: 40px;
            }

            .promo-mini-card {
                min-width: 160px;
            }

            .order-list {
                display: flex !important;
                flex-direction: column !important;
                padding: 12px !important;
            }

            .order-card-head,
            .order-card-foot {
                align-items: flex-start;
                gap: 10px;
            }

            .order-card-foot {
                flex-direction: column;
            }

            .order-actions,
            .order-actions .btn {
                width: 100%;
            }

            .order-actions .btn {
                justify-content: center;
            }

            .order-item-row {
                gap: 12px;
                align-items: flex-start;
            }

            .oi-name {
                min-width: 0;
                overflow-wrap: anywhere;
            }

            .oi-price {
                text-align: right;
                white-space: nowrap;
            }

            .akun-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .bottom-nav {
                left: 0;
                transform: none;
            }

            .success-toast {
                max-width: calc(100vw - 24px);
                text-align: center;
                white-space: normal;
            }
        }

        @media (max-width: 380px) {

            .hero-name,
            .akun-nama {
                font-size: 18px !important;
            }

            .point-number {
                font-size: 22px !important;
            }

            .stats-grid {
                grid-template-columns: 1fr !important;
            }

            .quick-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
        }


        /* ── FINAL NAV CLEANUP: hilangkan topbar/sidebar, pakai navbar bawah saja ── */
        .desktop-topbar,
        .desktop-sidebar {
            display: none !important;
        }

        .app-shell {
            display: block !important;
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            min-height: 100dvh !important;
            background: var(--white) !important;
            box-shadow: none !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        .desktop-content {
            display: block !important;
            width: 100% !important;
            min-height: 100dvh !important;
            height: auto !important;
            overflow: visible !important;
        }

        .page {
            width: 100% !important;
            min-height: 100dvh !important;
            padding-bottom: calc(var(--nav-h) + 20px) !important;
        }

        .page-top {
            display: none !important;
        }

        .bottom-nav {
            display: flex !important;
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            transform: none !important;
            width: 100% !important;
            max-width: none !important;
        }

        @media (min-width: 1024px) {
            body {
                background: var(--white) !important;
            }

            .app-shell {
                display: block !important;
            }

            .desktop-content {
                display: block !important;
            }

            .page {
                padding-bottom: calc(var(--nav-h) + 24px) !important;
            }

            .bottom-nav {
                max-width: none !important;
                height: var(--nav-h) !important;
            }

            .nav-btn {
                max-width: none !important;
            }
        }

        /* ─────────────────────────────────────────────
           FINAL: Info Member dihapus, icon dipindah ke card statistik atas
        ───────────────────────────────────────────── */
        .stat-card-icon {
            position: relative !important;
            overflow: hidden !important;
            min-height: 88px;
        }

        .stat-card-icon::after {
            content: attr(data-icon);
            position: absolute;
            right: 12px;
            bottom: 8px;
            font-size: 42px;
            line-height: 1;
            opacity: .055;
            pointer-events: none;
        }

        .stat-card-icon .stat-card-label,
        .stat-card-icon .stat-card-value,
        .stat-card-icon .stat-card-sub {
            position: relative;
            z-index: 2;
        }

        .stat-card-icon .stat-card-value {
            padding-right: 28px;
        }

        @media (max-width: 640px) {
            .stat-card-icon {
                min-height: 86px;
            }

            .stat-card-icon::after {
                font-size: 34px;
                right: 8px;
                bottom: 8px;
            }

            .stat-card-icon .stat-card-value {
                padding-right: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell" id="app">

        <!-- ═══ DESKTOP TOPBAR ═══ -->
        <div class="desktop-topbar">
            <div class="dtb-logo">KOPERASI BSDK</div>
            <div class="dtb-right">
                <div class="dtb-user">
                    <div class="dtb-uname"><?= h($member['nama']) ?></div>
                    <div class="dtb-ukode"><?= h($member['kode']) ?> · <?= h($member['status']) ?></div>
                </div>
                <button class="dtb-notif" title="Notifikasi">
                    <svg viewBox="0 0 24 24">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                </button>
                <button class="btn btn-danger" onclick="confirmLogout()" style="padding:6px 14px;">Logout</button>
            </div>
        </div>

        <!-- ═══ DESKTOP SIDEBAR ═══ -->
        <div class="desktop-sidebar">
            <div class="sidebar-profile">
                <div class="sidebar-avatar"><?= member_initial($member['nama']) ?></div>
                <div class="sidebar-name"><?= h($member['nama']) ?></div>
                <div class="sidebar-code"><?= h($member['kode']) ?> · <?= h($member['no_hp']) ?></div>
                <div class="sidebar-pt">
                    <div>
                        <div class="sidebar-pt-label">Saldo Point</div>
                        <div class="sidebar-pt-val"><?= angka_member($saldoPoint) ?><span class="sidebar-pt-unit">pt</span></div>
                    </div>
                </div>
            </div>
            <nav class="sidebar-menu">
                <div class="sidebar-section-label">Menu Utama</div>
                <button class="sidebar-item active" id="si-beranda" onclick="goTo('beranda')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg></div>
                    <span class="si-label">Beranda</span>
                </button>
                <button class="sidebar-item" id="si-belanja" onclick="goTo('belanja')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <circle cx="9" cy="21" r="1" />
                            <circle cx="20" cy="21" r="1" />
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                        </svg></div>
                    <span class="si-label">Belanja</span>
                </button>
                <button class="sidebar-item" id="si-promo" onclick="goTo('promo')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <polyline points="20 12 20 22 4 22 4 12" />
                            <rect x="2" y="7" width="20" height="5" />
                            <line x1="12" y1="22" x2="12" y2="7" />
                            <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
                            <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
                        </svg></div>
                    <span class="si-label">Promo</span>
                </button>
                <button class="sidebar-item" id="si-pesanan" onclick="goTo('pesanan')">
                    <div class="si-ico" style="position:relative;"><svg viewBox="0 0 24 24">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                            <line x1="12" y1="22.08" x2="12" y2="12" />
                        </svg></div>
                    <span class="si-label">Pesanan</span>
                    <?php if ($jumlahTransaksi > 0): ?><span class="si-badge"><?= $jumlahTransaksi > 99 ? '99+' : $jumlahTransaksi ?></span><?php endif; ?>
                </button>
                <div class="sidebar-section-label" style="margin-top:8px;">Akun</div>
                <button class="sidebar-item" id="si-akun" onclick="goTo('akun')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg></div>
                    <span class="si-label">Akun Saya</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-profil')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg></div>
                    <span class="si-label">Edit Profil</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-point')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg></div>
                    <span class="si-label">Saldo & Point</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-voucher')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                            <line x1="7" y1="7" x2="7.01" y2="7" />
                        </svg></div>
                    <span class="si-label">Voucher Saya</span>
                    <span class="si-badge">3</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-tukar')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg></div>
                    <span class="si-label">Tukar Point</span>
                </button>
                <div class="sidebar-section-label" style="margin-top:8px;">Bantuan</div>
                <button class="sidebar-item" onclick="openModal('modal-bantuan')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg></div>
                    <span class="si-label">Bantuan & FAQ</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-kontak')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                        </svg></div>
                    <span class="si-label">Hubungi Koperasi</span>
                </button>
                <button class="sidebar-item" onclick="openModal('modal-about')">
                    <div class="si-ico"><svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg></div>
                    <span class="si-label">Tentang Aplikasi</span>
                </button>
            </nav>
            <div class="sidebar-footer">
                <button class="btn btn-danger" style="width:100%;padding:10px;" onclick="confirmLogout()">Keluar dari Akun</button>
                <div style="margin-top:8px;text-align:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--g5);">Member sejak <?= $member['created_at'] ? date('M Y', strtotime($member['created_at'])) : '-' ?></div>
            </div>
        </div>

        <!-- ═══════════════════════════════ DESKTOP CONTENT ═══════════════════════════════ -->
        <div class="desktop-content">

            <!-- ═══ PAGE: BERANDA ═══ -->
            <div class="page active" id="page-beranda">
                <div class="page-top">
                    <div class="logo-mark">KOPERASI BSDK</div>
                    <button class="btn" style="padding:6px 10px;" onclick="confirmLogout()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                    </button>
                </div>

                <!-- Hero (mobile only) -->
                <div class="beranda-hero" id="beranda-hero-mobile">
                    <div class="hero-top">
                        <div>
                            <div class="hero-greeting">Selamat Datang Kembali</div>
                            <div class="hero-name"><?= h($member['nama']) ?></div>
                        </div>
                        <button style="width:36px;height:36px;border-radius:var(--r);border:0.5px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;" onclick="openModal('modal-notif')">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            </svg>
                        </button>
                    </div>
                    <div class="hero-meta">
                        <span class="hero-tag"><?= h($member['kode']) ?></span>
                        <span class="hero-tag"><?= h($member['no_hp']) ?></span>
                        <span class="hero-tag hero-tag-active"><?= h($member['status']) ?></span>
                    </div>
                </div>

                <!-- Desktop Hero -->
                <div style="display:none;" id="beranda-hero-desktop">
                    <div class="dc-wrap" style="padding-bottom:0;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
                            <div>
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:var(--g4);margin-bottom:6px;">Selamat Datang Kembali</div>
                                <div style="font-size:28px;font-weight:900;letter-spacing:-.03em;color:var(--black);"><?= h($member['nama']) ?></div>
                                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <span style="font-size:10px;font-weight:700;padding:4px 10px;border:0.5px solid var(--g6);border-radius:var(--r);color:var(--g3);"><?= h($member['kode']) ?></span>
                                    <span style="font-size:10px;font-weight:700;padding:4px 10px;border:0.5px solid var(--g6);border-radius:var(--r);color:var(--g3);"><?= h($member['no_hp']) ?></span>
                                    <span style="font-size:10px;font-weight:700;padding:4px 10px;border:0.5px solid #bbf7d0;border-radius:var(--r);color:#15803d;background:#f0fdf4;"><?= h($member['status']) ?></span>
                                </div>
                            </div>
                            <div style="background:var(--black);color:var(--white);padding:16px 20px;border-radius:var(--r);min-width:160px;">
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;opacity:.5;">Saldo Point</div>
                                <div style="font-size:32px;font-weight:900;letter-spacing:-.04em;margin-top:4px;"><?= angka_member($saldoPoint) ?><span style="font-size:14px;font-weight:700;opacity:.5;margin-left:3px;">pt</span></div>
                                <div style="font-size:10px;font-weight:600;opacity:.4;margin-top:4px;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Point strip (mobile) -->
                <div class="point-strip" id="point-strip-mobile">
                    <div class="point-strip-left">
                        <div class="section-label">Saldo Point</div>
                        <div class="point-number"><?= angka_member($saldoPoint) ?><span class="point-unit">pt</span></div>
                        <div style="font-size:10px;color:var(--g4);font-weight:600;margin-top:4px;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?> nilai tukar</div>
                    </div>
                    <div class="point-strip-right">
                        <div class="section-label">Transaksi</div>
                        <div style="font-size:28px;font-weight:900;letter-spacing:-.04em;color:var(--black);margin-top:4px;"><?= angka_member($jumlahTransaksi) ?></div>
                        <div style="font-size:10px;color:var(--g4);font-weight:600;margin-top:4px;">Total</div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid" id="stats-grid-wrap">
                    <div class="stat-card stat-card-icon" data-icon="💰">
                        <div class="stat-card-label">Total Belanja</div>
                        <div class="stat-card-value" style="font-size:16px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <?php if ($totalBelanjaProfil !== $totalBelanjaTransaksi): ?><div class="stat-card-sub">Profil: <?= rupiah_member($totalBelanjaProfil) ?></div><?php endif; ?>
                    </div>
                    <div class="stat-card stat-card-icon" data-icon="🏷️">
                        <div class="stat-card-label">Total Diskon</div>
                        <div class="stat-card-value" style="font-size:16px;"><?= rupiah_member($totalDiskonTransaksi) ?></div>
                        <div class="stat-card-sub">Hemat dari transaksi</div>
                    </div>
                    <div class="stat-card stat-card-icon" data-icon="⭐">
                        <div class="stat-card-label">Point Transaksi</div>
                        <div class="stat-card-value"><?= angka_member($totalPointDariTransaksi) ?><span style="font-size:13px;font-weight:700;color:var(--g4);"> pt</span></div>
                    </div>
                    <div class="stat-card stat-card-icon" data-icon="🎁">
                        <div class="stat-card-label">Point Ditukar</div>
                        <div class="stat-card-value"><?= angka_member($totalPointPakai) ?><span style="font-size:13px;font-weight:700;color:var(--g4);"> pt</span></div>
                        <div class="stat-card-sub">Nilai <?= rupiah_member($totalNilaiPointPakai) ?></div>
                    </div>
                    <div class="stat-card stat-card-icon" data-icon="👤">
                        <div class="stat-card-label">Bergabung</div>
                        <div class="stat-card-value" style="font-size:14px;"><?= $member['created_at'] ? date('M Y', strtotime($member['created_at'])) : '-' ?></div>
                    </div>
                </div>

                <div class="gap-section"></div>

                <!-- Quick Menu -->
                <div class="quick-menu-wrap">
                    <div class="section-label">Menu Cepat</div>
                    <div class="quick-grid">
                        <button class="quick-item" onclick="goTo('belanja')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="9" cy="21" r="1" />
                                    <circle cx="20" cy="21" r="1" />
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                </svg></div>
                            <span class="quick-label">Belanja</span>
                        </button>
                        <button class="quick-item" onclick="goTo('promo')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <polyline points="20 12 20 22 4 22 4 12" />
                                    <rect x="2" y="7" width="20" height="5" />
                                    <line x1="12" y1="22" x2="12" y2="7" />
                                    <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
                                    <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
                                </svg></div>
                            <span class="quick-label">Promo</span>
                        </button>
                        <button class="quick-item" onclick="goTo('pesanan')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                    <line x1="12" y1="22.08" x2="12" y2="12" />
                                </svg></div>
                            <span class="quick-label">Pesanan</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-tukar')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <span class="quick-label">Tukar Point</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-voucher')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                    <line x1="7" y1="7" x2="7.01" y2="7" />
                                </svg></div>
                            <span class="quick-label">Voucher</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-point')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <line x1="18" y1="20" x2="18" y2="10" />
                                    <line x1="12" y1="20" x2="12" y2="4" />
                                    <line x1="6" y1="20" x2="6" y2="14" />
                                </svg></div>
                            <span class="quick-label">Riwayat Point</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-bantuan')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg></div>
                            <span class="quick-label">Bantuan</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-kontak')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg></div>
                            <span class="quick-label">Kontak</span>
                        </button>
                    </div>
                </div>

                <div class="gap-section"></div>

                <!-- Info Member -->
                <div style="padding:16px 16px 10px;">
                    <div class="section-label">Info Member</div>
                </div>
                <div class="promo-mini-scroll no-scrollbar">
                    <div class="promo-mini-card" data-icon="⭐" onclick="openModal('modal-point')">
                        <div class="pmc-tag">Saldo Point</div>
                        <div class="pmc-title"><?= angka_member($saldoPoint) ?> pt</div>
                    </div>
                    <div class="promo-mini-card" data-icon="🧾" onclick="goTo('pesanan')">
                        <div class="pmc-tag">Transaksi</div>
                        <div class="pmc-title"><?= angka_member($jumlahTransaksi) ?> Riwayat</div>
                    </div>
                    <div class="promo-mini-card" data-icon="💰" onclick="goTo('pesanan')">
                        <div class="pmc-tag">Total Belanja</div>
                        <div class="pmc-title"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                    </div>
                    <div class="promo-mini-card" data-icon="🎁" onclick="openModal('modal-point')">
                        <div class="pmc-tag">Point Ditukar</div>
                        <div class="pmc-title"><?= angka_member($totalPointPakai) ?> pt</div>
                    </div>
                </div>

                <div class="gap-section" style="margin-top:10px;"></div>

                <!-- Recent Transactions -->
                <div style="padding:16px 16px 8px;display:flex;justify-content:space-between;align-items:center;">
                    <div class="section-label">Transaksi Terakhir</div>
                    <button onclick="goTo('pesanan')" style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--g4);border:none;background:none;cursor:pointer;font-family:inherit;">Semua →</button>
                </div>
                <div class="trx-list">
                    <?php if (!$trxBeranda): ?>
                        <div style="padding:32px 0;text-align:center;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:var(--g5);">Belum ada transaksi</div>
                        <?php else: foreach ($trxBeranda as $t):
                            $ptDb = (int)($t['point_transaksi'] ?? 0);
                            $ptTotal = (int)($t['total_transaksi'] ?? 0);
                            $ptTampil = $ptDb > 0 ? $ptDb : (int)floor($ptTotal / 10000); ?>
                            <div class="trx-row">
                                <div class="trx-icon-box"><svg viewBox="0 0 24 24">
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                    </svg></div>
                                <div class="trx-info">
                                    <div class="trx-inv"><?= h($t['invoice']) ?></div>
                                    <div class="trx-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                </div>
                                <div class="trx-right">
                                    <div class="trx-total"><?= rupiah_member($t['total_transaksi']) ?></div>
                                    <div class="trx-pt">+<?= angka_member($ptTampil) ?> pt</div>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div><!-- /page-beranda -->


            <!-- ═══ PAGE: BELANJA ═══ -->
            <div class="page" id="page-belanja">
                <div class="page-top">
                    <div class="page-top-title">Belanja</div>
                    <button class="btn" style="padding:6px 10px;"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <circle cx="9" cy="21" r="1" />
                            <circle cx="20" cy="21" r="1" />
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                        </svg> 0</button>
                </div>
                <div class="search-wrap">
                    <div class="search-inner"><svg viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg><input type="text" placeholder="Cari produk, kategori..."></div>
                </div>
                <div class="cat-scroll-wrap">
                    <div class="cat-scroll no-scrollbar">
                        <button class="cat-chip active" onclick="setCat(this)">Semua</button>
                        <button class="cat-chip" onclick="setCat(this)">Sembako</button>
                        <button class="cat-chip" onclick="setCat(this)">Minuman</button>
                        <button class="cat-chip" onclick="setCat(this)">Snack</button>
                        <button class="cat-chip" onclick="setCat(this)">Kebersihan</button>
                        <button class="cat-chip" onclick="setCat(this)">Frozen</button>
                        <button class="cat-chip" onclick="setCat(this)">Susu &amp; Bayi</button>
                    </div>
                </div>
                <div style="padding:12px 16px 4px;display:flex;justify-content:space-between;align-items:center;">
                    <div class="section-label">Produk Tersedia</div>
                    <div style="font-size:10px;font-weight:700;color:var(--g4);">Urutkan ↕</div>
                </div>
                <div class="empty-state" style="padding:48px 24px;">
                    <svg viewBox="0 0 24 24">
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                    </svg>
                    <p>Fitur katalog belanja member belum terhubung ke data produk kasir.</p>
                    <div style="font-size:11px;font-weight:600;color:var(--g4);text-align:center;line-height:1.6;max-width:320px;">Data transaksi member tetap valid dari database. Untuk belanja, silakan transaksi melalui kasir/POS.</div>
                </div>
            </div><!-- /page-belanja -->


            <!-- ═══ PAGE: PROMO ═══ -->
            <div class="page" id="page-promo">
                <div class="page-top">
                    <div class="page-top-title">Promo</div>
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);"><?= date('d M Y') ?></div>
                </div>
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Penawaran Eksklusif Member</div>
                    <h2>Hemat Lebih,<br>Belanja Lebih!</h2>
                    <p>Khusus Member Aktif Koperasi BSDK</p>
                </div>
                <div class="tab-bar no-scrollbar">
                    <button class="tab-btn active" onclick="setPromoTab(this)">Semua</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Diskon</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Double Point</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Gratis</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Voucher</button>
                </div>
                <div class="promo-list">
                    <div class="empty-state" style="grid-column:1/-1;">
                        <svg viewBox="0 0 24 24">
                            <polyline points="20 12 20 22 4 22 4 12" />
                            <rect x="2" y="7" width="20" height="5" />
                            <line x1="12" y1="22" x2="12" y2="7" />
                        </svg>
                        <p>Promo member belum ditampilkan dari database.</p>
                        <div style="font-size:11px;font-weight:600;color:var(--g4);text-align:center;line-height:1.6;max-width:320px;">Promo/diskon yang valid tetap dihitung dari transaksi kasir dan muncul di riwayat pesanan.</div>
                    </div>
                </div>
            </div><!-- /page-promo -->


            <!-- ═══ PAGE: PESANAN ═══ -->
            <div class="page" id="page-pesanan">
                <div class="page-top">
                    <div class="page-top-title">Pesanan &amp; Transaksi</div>
                </div>
                <!-- Summary -->
                <div class="order-summary-bar">
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($jumlahTransaksi) ?></div>
                        <div class="osb-label">Transaksi</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val" style="font-size:13px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <div class="osb-label">Belanja</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val" style="font-size:13px;"><?= rupiah_member($totalDiskonTransaksi) ?></div>
                        <div class="osb-label">Diskon</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($totalPointDariTransaksi) ?></div>
                        <div class="osb-label">Point</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($totalPointPakai) ?></div>
                        <div class="osb-label">Ditukar</div>
                    </div>
                </div>
                <div class="tab-bar no-scrollbar">
                    <button class="tab-btn active" onclick="setOrderTab(this)">Semua (<?= angka_member($jumlahTransaksi) ?>)</button>
                    <button class="tab-btn" onclick="setOrderTab(this)">Selesai</button>
                    <button class="tab-btn" onclick="setOrderTab(this)">Diproses</button>
                </div>

                <!-- Card List (all breakpoints) -->
                <?php if (!$transaksi): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                            <line x1="12" y1="22.08" x2="12" y2="12" />
                        </svg>
                        <p>Belum ada riwayat transaksi</p>
                    </div>
                <?php else: ?>
                    <div class="order-list">
                        <?php foreach ($transaksi as $t):
                            $sebelumDiskon = (int)($t['total_sebelum_diskon'] ?? 0);
                            $setelahPoint = (int)($t['total_transaksi'] ?? 0);
                            $pointPakai = (int)($t['point_pakai'] ?? 0);
                            $nilaiPointPakai = (int)($t['nilai_point_pakai'] ?? 0);
                            $setelahDiskon = $setelahPoint + $nilaiPointPakai;
                            $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                            $diskonDb = (int)($t['diskon_transaksi'] ?? 0);
                            $diskonTampil = max($diskonDb, $diskonDariSelisih);
                            $ptDb = (int)($t['point_transaksi'] ?? 0);
                            $ptTampil = $ptDb > 0 ? $ptDb : (int)floor($setelahPoint / 10000);
                        ?>
                            <div class="order-card">
                                <div class="order-card-head">
                                    <div>
                                        <div class="order-store">Koperasi BSDK</div>
                                        <div class="order-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                    </div>
                                    <span class="badge badge-selesai">Selesai</span>
                                </div>
                                <div class="order-items-wrap">
                                    <div class="order-item-row">
                                        <span class="oi-name" style="font-weight:800;color:var(--black);"><?= h($t['invoice']) ?></span>
                                        <span class="oi-price"><?= rupiah_member($setelahPoint) ?></span>
                                    </div>
                                    <?php if ($sebelumDiskon > $setelahDiskon): ?>
                                        <div class="order-item-row">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Harga Asli</span>
                                            <span style="font-size:11px;font-weight:600;color:var(--g4);text-decoration:line-through;"><?= rupiah_member($sebelumDiskon) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($diskonTampil > 0): ?>
                                        <div class="order-item-row">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Diskon</span>
                                            <span style="font-size:11px;font-weight:700;color:#15803d;">- <?= rupiah_member($diskonTampil) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($nilaiPointPakai > 0): ?>
                                        <div class="order-item-row">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Tukar Point</span>
                                            <span style="font-size:11px;font-weight:700;color:#7c3aed;">- <?= rupiah_member($nilaiPointPakai) ?> (<?= angka_member($pointPakai) ?> pt)</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="order-item-row" style="margin-top:4px;padding-top:8px;border-top:0.5px solid var(--g7);">
                                        <span style="font-size:11px;color:var(--g4);font-weight:600;">Bayar</span>
                                        <span style="font-size:11px;font-weight:700;color:var(--black);"><?= rupiah_member($t['bayar_transaksi'] ?? 0) ?></span>
                                    </div>
                                    <?php if (($t['kembalian_transaksi'] ?? 0) > 0): ?>
                                        <div class="order-item-row">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Kembali</span>
                                            <span style="font-size:11px;font-weight:700;color:var(--g3);"><?= rupiah_member($t['kembalian_transaksi']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="order-card-foot">
                                    <div class="order-total-wrap">
                                        <div class="ot-label">Total Dibayar</div>
                                        <div class="ot-val"><?= rupiah_member($setelahPoint) ?></div>
                                        <div class="ot-pt">+<?= angka_member($ptTampil) ?> point diperoleh</div>
                                    </div>
                                    <div class="order-actions">
                                        <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:7px 14px;">Struk</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div><!-- /page-pesanan -->


            <!-- ═══ PAGE: AKUN ═══ -->
            <div class="page" id="page-akun">
                <div class="page-top">
                    <div class="page-top-title">Akun Saya</div><button class="btn btn-danger" onclick="confirmLogout()" style="padding:6px 14px;">Logout</button>
                </div>
                <div class="akun-hero">
                    <div class="akun-avatar"><?= member_initial($member['nama']) ?></div>
                    <div class="akun-nama"><?= h($member['nama']) ?></div>
                    <div class="akun-meta">
                        <span class="akun-tag"><?= h($member['kode']) ?></span>
                        <span class="akun-tag"><?= h($member['no_hp']) ?></span>
                        <span class="akun-tag" style="border-color:rgba(255,255,255,.5);color:rgba(255,255,255,.9);"><?= h($member['status']) ?></span>
                    </div>
                </div>
                <div class="akun-stats-grid">
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($saldoPoint) ?></div>
                        <div class="akun-stat-lbl">Point</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($jumlahTransaksi) ?></div>
                        <div class="akun-stat-lbl">Transaksi</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($totalPointPakai) ?></div>
                        <div class="akun-stat-lbl">Ditukar</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val" style="font-size:14px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <div class="akun-stat-lbl">Total Belanja</div>
                    </div>
                </div>
                <?php if ($totalBelanjaProfil !== $totalBelanjaTransaksi): ?>
                    <div class="info-box">⚠ Profil mencatat <strong><?= rupiah_member($totalBelanjaProfil) ?></strong>. Acuan terpercaya adalah total dari riwayat transaksi.</div>
                <?php endif; ?>
                <div class="menu-section">
                    <div class="menu-section-label">Profil &amp; Akun</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="openModal('modal-profil')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Edit Profil</div>
                                <div class="menu-sub">Nama, nomor HP, alamat</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-point')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Saldo &amp; Point</div>
                                <div class="menu-sub">Riwayat point dan penukaran</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-voucher')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                    <line x1="7" y1="7" x2="7.01" y2="7" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Voucher Saya</div>
                                <div class="menu-sub">Kode voucher aktif</div>
                            </div><span class="menu-badge-pill">3</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-tukar')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Tukar Point</div>
                                <div class="menu-sub">Tukar point jadi diskon/cashback</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="menu-section">
                    <div class="menu-section-label">Transaksi</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="goTo('pesanan')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                    <line x1="12" y1="22.08" x2="12" y2="12" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Riwayat Pesanan</div>
                                <div class="menu-sub"><?= angka_member($jumlahTransaksi) ?> transaksi total</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-struk')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <polyline points="14 2 14 8 20 8" />
                                    <line x1="16" y1="13" x2="8" y2="13" />
                                    <line x1="16" y1="17" x2="8" y2="17" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Struk Digital</div>
                                <div class="menu-sub">Lihat struk transaksi</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="menu-section">
                    <div class="menu-section-label">Bantuan</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="openModal('modal-bantuan')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Bantuan &amp; FAQ</div>
                                <div class="menu-sub">Cara penggunaan aplikasi</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-kontak')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Hubungi Koperasi</div>
                                <div class="menu-sub">WhatsApp &amp; Telepon</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-about')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Tentang Aplikasi</div>
                                <div class="menu-sub">Versi 2.0 — Koperasi BSDK</div>
                            </div><span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="akun-footer">
                    <button class="btn btn-danger" style="width:100%;padding:13px;font-size:11px;" onclick="confirmLogout()">Keluar dari Akun</button>
                    <div style="margin-top:12px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--g5);">Member sejak <?= $member['created_at'] ? date('F Y', strtotime($member['created_at'])) : '-' ?></div>
                </div>
            </div><!-- /page-akun -->

        </div><!-- /desktop-content -->


        <!-- ═══ BOTTOM NAV ═══ -->
        <nav class="bottom-nav">
            <button class="nav-btn active" id="nav-beranda" onclick="goTo('beranda')">
                <div class="nav-icon"><svg viewBox="0 0 24 24">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg></div><span class="nav-label">Beranda</span>
            </button>
            <button class="nav-btn" id="nav-belanja" onclick="goTo('belanja')">
                <div class="nav-icon"><svg viewBox="0 0 24 24">
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                    </svg></div><span class="nav-label">Belanja</span>
            </button>
            <button class="nav-btn" id="nav-promo" onclick="goTo('promo')">
                <div class="nav-icon"><svg viewBox="0 0 24 24">
                        <polyline points="20 12 20 22 4 22 4 12" />
                        <rect x="2" y="7" width="20" height="5" />
                        <line x1="12" y1="22" x2="12" y2="7" />
                        <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
                        <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
                    </svg></div><span class="nav-label">Promo</span>
            </button>
            <button class="nav-btn" id="nav-pesanan" onclick="goTo('pesanan')">
                <div class="nav-icon" style="position:relative;"><svg viewBox="0 0 24 24">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                        <line x1="12" y1="22.08" x2="12" y2="12" />
                    </svg><?php if ($jumlahTransaksi > 0): ?><span class="nav-badge"><?= $jumlahTransaksi > 99 ? '99+' : $jumlahTransaksi ?></span><?php endif; ?></div><span class="nav-label">Pesanan</span>
            </button>
            <button class="nav-btn" id="nav-akun" onclick="goTo('akun')">
                <div class="nav-icon"><svg viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg></div><span class="nav-label">Akun</span>
            </button>
        </nav>

    </div><!-- /app-shell -->


    <!-- ════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════ -->

    <!-- MODAL: Edit Profil -->
    <div class="modal-overlay" id="modal-profil" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Edit Profil</div>
                <button class="modal-close" onclick="closeModal('modal-profil')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:72px;height:72px;border-radius:var(--r);background:var(--black);color:var(--white);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:900;margin:0 auto 8px;"><?= member_initial($member['nama']) ?></div>
                    <div style="font-size:11px;font-weight:700;color:var(--g4);">Foto profil tidak tersedia</div>
                </div>
                <div class="form-group"><label class="form-label">Kode Member</label><input class="form-input" value="<?= h($member['kode']) ?>" disabled>
                    <div class="form-hint">Kode tidak dapat diubah</div>
                </div>
                <div class="form-group"><label class="form-label">Nama Lengkap</label><input class="form-input" id="edit-nama" value="<?= h($member['nama']) ?>" placeholder="Nama lengkap"></div>
                <div class="form-group"><label class="form-label">Nomor HP</label><input class="form-input" id="edit-hp" value="<?= h($member['no_hp']) ?>" placeholder="08xxxxxxxxxx"></div>
                <div class="form-group"><label class="form-label">Status</label><input class="form-input" value="<?= h($member['status']) ?>" disabled></div>
                <div style="display:flex;gap:8px;margin-top:20px;">
                    <button class="btn" style="flex:1;" onclick="closeModal('modal-profil')">Batal</button>
                    <button class="btn btn-black" style="flex:2;" onclick="saveProfil()">Simpan Perubahan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Saldo & Point -->
    <div class="modal-overlay" id="modal-point" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Saldo &amp; Point</div><button class="modal-close" onclick="closeModal('modal-point')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="background:var(--black);color:var(--white);padding:20px;border-radius:var(--r);margin-bottom:20px;position:relative;overflow-x:hidden;overflow-y:auto;">
                    <div style="position:absolute;right:-10px;top:-10px;font-size:80px;opacity:.06;">⭐</div>
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;opacity:.5;">Saldo Point Saat Ini</div>
                    <div style="font-size:40px;font-weight:900;letter-spacing:-.04em;margin-top:6px;"><?= angka_member($saldoPoint) ?><span style="font-size:16px;font-weight:700;opacity:.4;margin-left:4px;">pt</span></div>
                    <div style="font-size:11px;font-weight:600;opacity:.4;margin-top:4px;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?> nilai tukar</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;">
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Total Point Diperoleh</div>
                        <div style="font-size:20px;font-weight:900;color:var(--black);margin-top:6px;"><?= angka_member($totalPointDariTransaksi) ?><span style="font-size:11px;color:var(--g4);"> pt</span></div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Nilai Tukar</div>
                        <div style="font-size:16px;font-weight:900;color:var(--black);margin-top:6px;"><?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?></div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Point Ditukar</div>
                        <div style="font-size:20px;font-weight:900;color:var(--black);margin-top:6px;"><?= angka_member($totalPointPakai) ?><span style="font-size:11px;color:var(--g4);"> pt</span></div>
                        <div style="font-size:10px;font-weight:700;color:var(--g4);margin-top:3px;">Nilai <?= rupiah_member($totalNilaiPointPakai) ?></div>
                    </div>
                </div>
                <div class="section-label" style="margin-bottom:10px;">Riwayat Point</div>
                <?php if (!$transaksi): ?>
                    <div style="text-align:center;padding:24px;font-size:11px;font-weight:700;color:var(--g4);">Belum ada riwayat point</div>
                    <?php else: foreach (array_slice($transaksi, 0, 8) as $t):
                        $ptDb = (int)($t['point_transaksi'] ?? 0);
                        $ptTotal = (int)($t['total_transaksi'] ?? 0);
                        $ptPakai = (int)($t['point_pakai'] ?? 0);
                        $ptTampil = $ptDb > 0 ? $ptDb : (int)floor($ptTotal / 10000); ?>
                        <div class="pt-history-item">
                            <div class="pt-hist-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="9" cy="21" r="1" />
                                    <circle cx="20" cy="21" r="1" />
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                </svg></div>
                            <div class="pt-hist-info">
                                <div class="pt-hist-title"><?= h($t['invoice']) ?></div>
                                <div class="pt-hist-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?> · <?= rupiah_member($t['total_transaksi']) ?></div>
                            </div>
                            <div class="pt-hist-val <?= $ptPakai > 0 ? 'minus' : 'plus' ?>"><?= $ptPakai > 0 ? '-' . angka_member($ptPakai) . ' pt' : '+' . angka_member($ptTampil) . ' pt' ?></div>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: Voucher -->
    <div class="modal-overlay" id="modal-voucher" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Voucher Saya</div><button class="modal-close" onclick="closeModal('modal-voucher')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;">
                    <div class="form-group"><label class="form-label">Masukkan Kode Voucher</label>
                        <div style="display:flex;gap:8px;"><input class="form-input" placeholder="KODE-VOUCHER" style="flex:1;"><button class="btn btn-black" onclick="showToast('Kode voucher tidak ditemukan')">Cek</button></div>
                    </div>
                </div>
                <div class="section-label" style="margin-bottom:12px;">Voucher Aktif (3)</div>
                <div class="voucher-card" onclick="copyVoucher('BSDK20OFF')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">BSDK20OFF</div>
                            <div class="vc-desc">Diskon 20% untuk pembelian sembako</div>
                            <div class="vc-exp">Berlaku s.d 30 Mei 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">20%</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Diskon</div>
                        </div>
                    </div>
                </div>
                <div class="voucher-card" onclick="copyVoucher('BSDK10RB')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">BSDK10RB</div>
                            <div class="vc-desc">Voucher Rp 10.000 min. belanja Rp 75.000</div>
                            <div class="vc-exp">Berlaku s.d 20 Jun 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">10K</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Cashback</div>
                        </div>
                    </div>
                </div>
                <div class="voucher-card" onclick="copyVoucher('GRATIONGKIR')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">GRATIONGKIR</div>
                            <div class="vc-desc">Gratis ongkos kirim min. belanja Rp 50.000</div>
                            <div class="vc-exp">Berlaku s.d 31 Mei 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">0</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Ongkir</div>
                        </div>
                    </div>
                </div>
                <div style="font-size:10px;font-weight:600;color:var(--g4);text-align:center;margin-top:8px;">Klik voucher untuk menyalin kode</div>
            </div>
        </div>
    </div>

    <!-- MODAL: Tukar Point -->
    <div class="modal-overlay" id="modal-tukar" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Tukar Point</div><button class="modal-close" onclick="closeModal('modal-tukar')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div class="tukar-point-hero">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;opacity:.4;position:relative;z-index:1;">Saldo Anda</div>
                    <div class="tp-balance" style="position:relative;z-index:1;"><?= angka_member($saldoPoint) ?><span style="font-size:16px;font-weight:700;opacity:.4;margin-left:4px;">pt</span></div>
                    <div style="font-size:11px;font-weight:600;opacity:.4;margin-top:4px;position:relative;z-index:1;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?></div>
                </div>
                <div class="section-label" style="margin-bottom:12px;">Pilih Penukaran</div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Cashback Rp 10.000</div>
                        <div class="to-req">10 point diperlukan</div>
                    </div>
                    <div class="to-val">10 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Cashback Rp 50.000</div>
                        <div class="to-req">50 point diperlukan</div>
                    </div>
                    <div class="to-val">50 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Diskon Rp 100.000</div>
                        <div class="to-req">100 point diperlukan</div>
                    </div>
                    <div class="to-val">100 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Voucher Rp 200.000</div>
                        <div class="to-req">200 point diperlukan</div>
                    </div>
                    <div class="to-val">200 pt</div>
                </div>
                <div style="margin-top:16px;padding:12px;background:var(--g8);border-radius:var(--r);font-size:11px;font-weight:600;color:var(--g3);">💡 Pilih opsi penukaran di atas, lalu konfirmasi ke kasir saat transaksi berlangsung.</div>
                <div style="display:flex;gap:8px;margin-top:16px;">
                    <button class="btn" style="flex:1;" onclick="closeModal('modal-tukar')">Batal</button>
                    <button class="btn btn-black" style="flex:2;" onclick="showToast('Silakan tunjukkan ke kasir')">Konfirmasi Penukaran</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Struk Digital -->
    <div class="modal-overlay" id="modal-struk" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Struk Digital</div><button class="modal-close" onclick="closeModal('modal-struk')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;font-size:12px;font-weight:600;color:var(--g3);">Cari struk berdasarkan nomor invoice atau pilih dari daftar transaksi di bawah.</div>
                <div class="form-group"><label class="form-label">Nomor Invoice</label>
                    <div style="display:flex;gap:8px;"><input class="form-input" placeholder="Contoh: INV-20250501-001" id="struk-invoice" style="flex:1;"><button class="btn btn-black" onclick="cariStruk()">Cari</button></div>
                </div>
                <div class="section-label" style="margin-bottom:10px;">Transaksi Terakhir</div>
                <?php if (!$transaksi): ?><div style="text-align:center;padding:24px;font-size:11px;font-weight:700;color:var(--g4);">Belum ada transaksi</div>
                    <?php else: foreach (array_slice($transaksi, 0, 10) as $t): ?>
                        <div class="trx-row">
                            <div class="trx-icon-box"><svg viewBox="0 0 24 24">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <polyline points="14 2 14 8 20 8" />
                                </svg></div>
                            <div class="trx-info">
                                <div class="trx-inv"><?= h($t['invoice']) ?></div>
                                <div class="trx-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                            </div>
                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:6px 12px;font-size:10px;">Buka</a>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: Bantuan & FAQ -->
    <div class="modal-overlay" id="modal-bantuan" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Bantuan &amp; FAQ</div><button class="modal-close" onclick="closeModal('modal-bantuan')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;"><input class="form-input" placeholder="Cari pertanyaan..." oninput="filterFAQ(this.value)"></div>
                <div id="faq-list">
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mendapatkan point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Setiap transaksi senilai Rp 10.000 mendapatkan 1 point. Point akan otomatis dikreditkan setelah transaksi selesai. Double point berlaku setiap Sabtu dan Minggu untuk belanja min. Rp 100.000.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara menukar point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Tukar Point", pilih opsi penukaran yang tersedia, kemudian tunjukkan konfirmasi ke kasir saat transaksi. Point akan langsung dipotong dari saldo Anda.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Berapa nilai 1 point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">1 point setara dengan Rp 1.000. Jadi jika Anda memiliki 100 point, nilainya adalah Rp 10.000 yang dapat ditukar menjadi cashback atau diskon belanja.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara menggunakan voucher?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Voucher Saya", salin kode voucher, kemudian berikan ke kasir saat transaksi. Pastikan nilai belanja Anda sudah memenuhi minimum yang disyaratkan.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Apakah point memiliki masa berlaku?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Point berlaku selama akun member Anda aktif. Point tidak akan kadaluarsa selama Anda melakukan minimal 1 transaksi per tahun.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mencetak struk?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Struk Digital" atau "Riwayat Pesanan", cari transaksi yang diinginkan, kemudian klik tombol "Struk" untuk membuka struk digital. Di halaman member, struk hanya mode lihat tanpa tombol cetak dan kembali.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mengubah data profil?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Edit Profil" di halaman Akun. Anda dapat mengubah nama dan nomor HP. Kode member tidak dapat diubah. Perubahan akan langsung tersimpan.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Apakah promo berlaku untuk semua produk?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Promo berlaku sesuai syarat dan ketentuan yang tertera di masing-masing promo. Beberapa promo hanya berlaku untuk kategori produk tertentu atau dengan minimum pembelian.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Hubungi Koperasi -->
    <div class="modal-overlay" id="modal-kontak" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Hubungi Koperasi</div><button class="modal-close" onclick="closeModal('modal-kontak')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:20px;font-size:12px;font-weight:600;color:var(--g3);line-height:1.6;">Tim Koperasi BSDK siap membantu Anda. Hubungi kami melalui salah satu saluran di bawah ini.</div>
                <a class="kontak-card" href="https://wa.me/6281234567890" target="_blank">
                    <div class="kontak-ico">📱</div>
                    <div class="kontak-info">
                        <div class="kc-title">WhatsApp</div>
                        <div class="kc-sub">+62 812-3456-7890 · Senin–Sabtu 08.00–17.00</div>
                    </div>
                </a>
                <a class="kontak-card" href="tel:+6281234567890">
                    <div class="kontak-ico">📞</div>
                    <div class="kontak-info">
                        <div class="kc-title">Telepon</div>
                        <div class="kc-sub">+62 812-3456-7890 · Jam kerja</div>
                    </div>
                </a>
                <a class="kontak-card" href="mailto:koperasi@bsdk.co.id">
                    <div class="kontak-ico">✉️</div>
                    <div class="kontak-info">
                        <div class="kc-title">Email</div>
                        <div class="kc-sub">koperasi@bsdk.co.id</div>
                    </div>
                </a>
                <a class="kontak-card" href="#">
                    <div class="kontak-ico">📍</div>
                    <div class="kontak-info">
                        <div class="kc-title">Kunjungi Langsung</div>
                        <div class="kc-sub">Jl. Contoh No. 123, Kota · Senin–Sabtu 08.00–16.00</div>
                    </div>
                </a>
                <div style="margin-top:16px;padding:12px;background:var(--g8);border-radius:var(--r);font-size:11px;font-weight:600;color:var(--g3);">⏰ Jam operasional: Senin–Jumat 08.00–16.00 WIB, Sabtu 08.00–13.00 WIB</div>
            </div>
        </div>
    </div>

    <!-- MODAL: Tentang Aplikasi -->
    <div class="modal-overlay" id="modal-about" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Tentang Aplikasi</div><button class="modal-close" onclick="closeModal('modal-about')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <div class="about-logo">BSDK</div>
                <div style="font-size:20px;font-weight:900;letter-spacing:-.02em;margin-bottom:4px;">Koperasi BSDK</div>
                <div class="about-version">Member Dashboard v2.0</div>
                <div style="margin:20px 0;height:0.5px;background:var(--g6);"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;text-align:left;">
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Versi</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">2.0.0</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Update</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">Mei 2025</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Platform</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">Web App</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Developer</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">IT BSDK</div>
                    </div>
                </div>
                <div style="font-size:11px;font-weight:600;color:var(--g4);line-height:1.7;">Aplikasi dashboard member Koperasi BSDK memungkinkan anggota untuk memantau transaksi, mengelola point, dan memanfaatkan promo eksklusif secara mudah dan efisien.</div>
                <div style="margin-top:16px;font-size:10px;font-weight:700;color:var(--g5);text-transform:uppercase;letter-spacing:.1em;">© 2025 Koperasi BSDK · All rights reserved</div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="success-toast" id="toast"></div>

    <script>
        /* ── Navigation ── */
        function goTo(page) {
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
            var pg = document.getElementById('page-' + page);
            if (pg) {
                pg.classList.add('active');
                pg.scrollTop = 0;
            }
            var nb = document.getElementById('nav-' + page);
            if (nb) nb.classList.add('active');
            var si = document.getElementById('si-' + page);
            if (si) si.classList.add('active');
            // Desktop hero toggle
            adjustBerandaLayout();
        }

        function adjustBerandaLayout() {
            var isDesktop = window.innerWidth >= 1024;
            var heroMob = document.getElementById('beranda-hero-mobile');
            var hDes = document.getElementById('beranda-hero-desktop');
            var ptStrip = document.getElementById('point-strip-mobile');
            if (heroMob) heroMob.style.display = isDesktop ? 'none' : '';
            if (hDes) hDes.style.display = isDesktop ? '' : 'none';
            if (ptStrip) ptStrip.style.display = isDesktop ? 'none' : '';
            var sg = document.getElementById('stats-grid-wrap');
            if (sg) {
                sg.style.padding = isDesktop ? '0 32px 20px' : '12px';
            }
        }
        window.addEventListener('resize', adjustBerandaLayout);
        adjustBerandaLayout();

        /* ── Category chips ── */
        function setCat(el) {
            document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
        }

        function setPromoTab(el) {
            document.querySelectorAll('#page-promo .tab-btn').forEach(t => t.classList.remove('active'));
            el.classList.add('active');
        }

        function setOrderTab(el) {
            document.querySelectorAll('#page-pesanan .tab-btn').forEach(t => t.classList.remove('active'));
            el.classList.add('active');
        }

        /* ── Promo claim ── */
        function klaimPromo(btn) {
            btn.textContent = 'Diklaim ✓';
            btn.classList.add('claimed');
            btn.disabled = true;
            showToast('Promo berhasil diklaim!');
        }

        /* ── Cart ── */
        var cartCount = 0;

        function addToCart(btn) {
            cartCount++;
            document.querySelectorAll('#page-belanja .page-top button')[0].innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> ' + cartCount;
            btn.textContent = 'Ditambahkan ✓';
            btn.style.background = '#15803d';
            setTimeout(() => {
                btn.textContent = '+ Keranjang';
                btn.style.background = '';
            }, 1500);
            showToast('Produk ditambahkan ke keranjang');
        }

        /* ── Modal ── */
        function openModal(id) {
            var m = document.getElementById(id);
            if (m) m.classList.add('open');
        }

        function closeModal(id) {
            var m = document.getElementById(id);
            if (m) m.classList.remove('open');
        }

        function overlayClose(e, overlay) {
            if (e.target === overlay) overlay.classList.remove('open');
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        });

        /* ── Voucher copy ── */
        function copyVoucher(code) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code);
            }
            showToast('Kode "' + code + '" disalin!');
        }

        /* ── Tukar point ── */
        function selectTukar(el) {
            document.querySelectorAll('.tukar-option').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
        }

        /* ── Struk ── */
        function cariStruk() {
            var v = document.getElementById('struk-invoice').value.trim();
            if (!v) {
                showToast('Masukkan nomor invoice');
                return;
            }
            window.open('struk.php?invoice=' + encodeURIComponent(v) + '&member=1', '_blank');
        }

        /* ── FAQ ── */
        function toggleFAQ(el) {
            var a = el.nextElementSibling,
                ch = el.querySelector('.faq-chevron');
            a.classList.toggle('open');
            ch.classList.toggle('open');
        }

        function filterFAQ(q) {
            q = q.toLowerCase();
            document.querySelectorAll('.faq-item').forEach(item => {
                var txt = item.textContent.toLowerCase();
                item.style.display = txt.includes(q) ? '' : 'none';
            });
        }

        /* ── Save profil (demo) ── */
        function saveProfil() {
            var nama = document.getElementById('edit-nama').value.trim();
            if (!nama) {
                showToast('Nama tidak boleh kosong');
                return;
            }
            showToast('Profil berhasil diperbarui!');
            closeModal('modal-profil');
        }

        /* ── Toast ── */
        function showToast(msg) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(t._to);
            t._to = setTimeout(() => t.classList.remove('show'), 2500);
        }

        /* ── Logout ── */
        function confirmLogout() {
            if (confirm('Yakin ingin keluar dari akun member?')) window.location.href = 'member_dashboard.php?logout=1';
        }
    </script>
</body>

</html>