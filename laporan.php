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

if (!function_exists('angka')) {
    /**
     * @param mixed $v
     */
    function angka($v): string
    {
        return number_format((float)($v ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tgl')) {
    /**
     * @param mixed $v
     */
    function tgl($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('waktu')) {
    /**
     * @param mixed $v
     */
    function waktu($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
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

$hasDiskon          = true;
$hasDiskonId        = true;
$hasPoint           = true;
$hasPointPakai      = transaksi_col_exists($pdo, 'point_pakai');
$hasNilaiPointPakai = transaksi_col_exists($pdo, 'nilai_point_pakai');
$hasMember          = true;
$hasMetode          = true;
$hasPayStatus       = true;
$hasHargaNormal     = true;
$hasDetailDiskon    = true;
$hasDetailDiskId    = true;

$whereStatus = "AND (t.metode_pembayaran = 'tunai' OR t.payment_status = 'paid')";

try {
    $diskonSum          = $hasDiskon       ? "COALESCE(SUM(t.diskon), 0)"            : "0";
    $pointSum           = $hasPoint        ? "COALESCE(SUM(t.point_dapat), 0)"       : "0";
    $pointPakaiSum      = $hasPointPakai   ? "COALESCE(SUM(t.point_pakai), 0)"       : "0";
    $nilaiPointPakaiSum = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai), 0)" : "0";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(t.total),0) AS omzet,
               $diskonSum AS diskon_transaksi, COALESCE(SUM(t.bayar),0) AS bayar,
               COALESCE(SUM(t.kembalian),0) AS kembalian, $pointSum AS point,
               $pointPakaiSum AS point_pakai, $nilaiPointPakaiSum AS nilai_point_pakai
        FROM transaksi t
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $diskonBarangTotal = 0;
    if ($hasHargaNormal) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total_diskon_barang
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarangTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total_diskon_barang'] ?? 0);
    }

    $memberJoin        = $hasMember ? "LEFT JOIN member m ON m.id = t.member_id" : "";
    $memberSelect      = $hasMember ? "m.nama AS member_nama, m.kode AS member_kode" : "NULL AS member_nama, NULL AS member_kode";
    $diskonSelect      = $hasDiskon ? "t.diskon" : "0 AS diskon";
    $pointSelect       = $hasPoint  ? "t.point_dapat" : "0 AS point_dapat";
    $pointPakaiSelect  = $hasPointPakai ? "t.point_pakai" : "0 AS point_pakai";
    $nilaiPPSelect     = $hasNilaiPointPakai ? "t.nilai_point_pakai" : "0 AS nilai_point_pakai";
    $metodeSelect      = $hasMetode ? "t.metode_pembayaran" : "'tunai' AS metode_pembayaran";
    $statusSelect      = $hasPayStatus ? "t.payment_status" : "'selesai' AS payment_status";
    $diskonBarangPerTrx = $hasHargaNormal
        ? "(SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td2.harga_normal,td2.harga)*td2.qty)-td2.subtotal)),0) FROM transaksi_detail td2 WHERE td2.transaksi_id=t.id)"
        : "0";

    $stmt = $pdo->prepare("
        SELECT t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
               $diskonSelect, ($diskonBarangPerTrx) AS diskon_barang,
               $pointSelect, $pointPakaiSelect, $nilaiPPSelect,
               $memberSelect, $metodeSelect, $statusSelect
        FROM transaksi t $memberJoin
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
        ORDER BY t.created_at DESC, t.id DESC
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hargaNormal  = $hasHargaNormal ? "COALESCE(td.harga_normal, td.harga)" : "td.harga";
    $diskonProduk = "GREATEST(0, (($hargaNormal * td.qty) - td.subtotal))";

    $stmt = $pdo->prepare("
        SELECT td.produk_id, td.nama, MAX(td.kode) AS kode, SUM(td.qty) AS qty,
               SUM($hargaNormal * td.qty) AS subtotal_normal,
               SUM($diskonProduk) AS diskon, SUM(td.subtotal) AS penjualan
        FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
        GROUP BY td.produk_id, td.nama ORDER BY qty DESC, penjualan DESC LIMIT 20
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diskonTransaksi = [];
    if ($hasDiskon && $hasDiskonId) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Transaksi') AS nama, COUNT(t.id) AS jumlah, COALESCE(SUM(t.diskon),0) AS total
            FROM transaksi t LEFT JOIN diskon d ON d.id=t.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus AND COALESCE(t.diskon,0)>0
            GROUP BY t.diskon_id, d.nama ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $diskonBarang = [];
    if ($hasHargaNormal) {
        $diskonIdJoin   = $hasDetailDiskId ? "LEFT JOIN diskon d ON d.id=td.diskon_id" : "";
        $diskonIdSelect = $hasDetailDiskId ? "COALESCE(d.nama,'Diskon Barang') AS nama, td.diskon_id" : "NULL AS diskon_id, 'Diskon Barang' AS nama";
        $stmt = $pdo->prepare("
            SELECT $diskonIdSelect, COUNT(td.id) AS jumlah,
                   COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id $diskonIdJoin
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
              AND GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)>0
            GROUP BY " . ($hasDetailDiskId ? "td.diskon_id, d.nama" : "1") . " ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $memberPoin = [];
    if ($hasMember && $hasPoint) {
        $ppMemberSum = $hasPointPakai ? "COALESCE(SUM(t.point_pakai),0)" : "0";
        $stmt = $pdo->prepare("
            SELECT m.kode, m.nama, m.no_hp, COUNT(t.id) AS jumlah_trx,
                   COALESCE(SUM(t.total),0) AS total_belanja,
                   COALESCE(SUM(t.point_dapat),0) AS point_periode,
                   $ppMemberSum AS point_pakai_periode, m.point AS point_total_lifetime
            FROM transaksi t JOIN member m ON m.id=t.member_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
            GROUP BY t.member_id, m.kode, m.nama, m.no_hp, m.point ORDER BY total_belanja DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $memberPoin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    die('Gagal memuat laporan: ' . htmlspecialchars($e->getMessage()));
}

$totalTransaksi       = (int)($summary['total_transaksi']  ?? 0);
$omzet                = (int)($summary['omzet']            ?? 0);
$diskonTrxSum         = (int)($summary['diskon_transaksi'] ?? 0);
$totalDiskon          = $diskonTrxSum + $diskonBarangTotal;
$totalBayar           = (int)($summary['bayar']            ?? 0);
$totalKembalian       = (int)($summary['kembalian']        ?? 0);
$totalPoint           = (int)($summary['point']            ?? 0);
$totalPointPakai      = (int)($summary['point_pakai']      ?? 0);
$totalNilaiPointPakai = (int)($summary['nilai_point_pakai'] ?? 0);
$rata                 = $totalTransaksi > 0 ? (int)floor($omzet / $totalTransaksi) : 0;

if (!function_exists('e')) {
    /**
     * @param mixed $v
     */
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan — Koperasi BSDK</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #fcfcfc;
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

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        .stok-bar {
            height: 3px;
            border-radius: 2px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .stok-bar-fill {
            height: 100%;
            border-radius: 2px;
        }

        input:focus,
        select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important;
        }

        #mobileMenuOverlay {
            transition: opacity .3s ease, visibility .3s ease;
        }

        #mobileMenuContent {
            transition: transform .3s cubic-bezier(.4, 0, .2, 1);
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .laporan-header,
            .laporan-main-wrap {
                margin-left: 220px;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .laporan-header,
            .laporan-main-wrap {
                margin-left: 0 !important;
            }

            body {
                background: #fff;
            }
        }

        /* Mobile card list — hidden on desktop */
        .card-list {
            display: none;
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .laporan-header {
                margin-left: 0 !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            .laporan-main-wrap {
                margin-left: 0 !important;
            }

            .laporan-main {
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

            .col2 {
                grid-template-columns: 1fr !important;
            }

            .table-footer-mobile {
                grid-column: 1 / -1;
                background: #fafafa;
                border: 1px solid #f0f0f0;
                border-radius: 2px;
                padding: .85rem 1rem;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .07em;
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                color: #737373;
            }
        }

        @media (max-width: 640px) {
            .card-list {
                grid-template-columns: 1fr;
            }

            .laporan-header-title {
                max-width: 155px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .laporan-main {
                padding: .75rem !important;
                padding-bottom: 6rem !important;
            }

            .header-export-btn {
                display: none !important;
            }

            .export-mobile {
                display: flex !important;
            }
        }

        .export-mobile {
            display: none;
            gap: .5rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .export-mobile::-webkit-scrollbar {
            display: none;
        }

        /* Badge styles matching produk.php */
        .badge-aktif {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-blue {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .badge-gray {
            background: #f5f5f5;
            color: #404040;
            border: 1px solid #e5e5e5;
        }

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
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #111;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .invoice-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .invoice-link:hover {
            text-decoration: underline;
        }

        /* Card item hover — identical to produk mobile card */
        .card-item {
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .card-item:hover {
            transform: translateY(-1px);
            border-color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        }


        /* Clean square layout refinements */
        .laporan-main .rounded-sm,
        .laporan-main .rounded-xl,
        .laporan-main .rounded-full,
        .laporan-header .rounded-sm,
        .laporan-header .rounded-full {
            border-radius: 0 !important;
        }

        .laporan-main .bg-white.border {
            box-shadow: none;
        }

        .card-item {
            border-radius: 0 !important;
            padding: 1rem !important;
            gap: .75rem !important;
        }

        .card-item:hover {
            transform: none;
            box-shadow: none;
        }

        @media (max-width: 1023px) {
            .laporan-main {
                gap: .85rem !important;
            }

            .laporan-main>.grid.grid-cols-2 {
                gap: .6rem !important;
            }

            .laporan-main>.grid.grid-cols-2>div {
                padding: .9rem !important;
                min-height: 86px;
            }

            .laporan-main>.grid.grid-cols-2>div p.text-2xl {
                font-size: 1.15rem !important;
                line-height: 1.35rem !important;
            }

            .card-list {
                gap: .6rem !important;
                padding: .75rem !important;
            }

            .table-footer-mobile {
                border-radius: 0 !important;
                padding: .75rem !important;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <!-- ══ Mobile Menu Overlay ══════════════════════════════════════ -->
    <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[100] opacity-0 invisible flex justify-end lg:hidden">
        <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
            <div class="flex justify-between items-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
                <button onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100 rounded-sm transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="space-y-8 flex-1">
                <a href="index.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
                <a href="pos.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Mesin Kasir (POS)</a>
                <a href="produk.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
                <a href="diskon.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Diskon</a>
                <a href="laporan.php" class="block text-sm font-bold text-black uppercase tracking-widest">Laporan Keuangan</a>
                <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block text-sm font-bold text-red-500 uppercase tracking-widest">Logout</a>
            </nav>
            <div class="pt-8 border-t border-subtle">
                <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
                <p class="text-[10px] text-gray-400 font-medium">Login: <?= e($_SESSION['nama']) ?></p>
            </div>
        </div>
    </div>

    <!-- ══ Desktop Sidebar ══════════════════════════════════════════ -->
    <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12">
            <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span>
        </div>
        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Mesin Kasir (POS)</a>
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
            <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Diskon</a>
            <a href="laporan.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2">
                <span class="w-2 h-2 bg-black rounded-full"></span>Laporan Keuangan
            </a>
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.5.1</p>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </aside>

    <!-- ══ Header ═══════════════════════════════════════════════════ -->
    <header class="laporan-header no-print sticky top-0 bg-white border-b border-subtle px-4 sm:px-6 py-4 flex justify-between items-center z-40 shadow-sm">
        <div class="flex items-center gap-3 sm:gap-4">
            <!-- Back -->
            <a href="index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors group">
                <svg class="h-5 w-5 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <h1 class="laporan-header-title text-sm font-bold tracking-[0.2em] uppercase">Laporan Keuangan</h1>
        </div>
        <!-- Export buttons — desktop -->
        <div class="flex items-center gap-2 sm:gap-3">
            <a href="pos.php" class="header-export-btn hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 border border-subtle rounded-sm bg-white hover:bg-gray-50 transition-all">POS</a>
            <a href="export_laporan_pdf.php?jenis=ringkasan&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="header-export-btn hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 bg-black text-white rounded-none hover:bg-gray-800 transition-all">Ringkasan</a>
            <a href="export_laporan_pdf.php?jenis=transaksi&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="header-export-btn hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 bg-black text-white rounded-sm hover:bg-gray-800 transition-all">Transaksi</a>
            <a href="export_laporan_pdf.php?jenis=produk&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="header-export-btn hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 bg-black text-white rounded-sm hover:bg-gray-800 transition-all">Produk</a>
            <a href="export_laporan_pdf.php?jenis=diskon&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="header-export-btn hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 bg-black text-white rounded-sm hover:bg-gray-800 transition-all">Diskon</a>
        </div>
    </header>

    <!-- ══ Main ═════════════════════════════════════════════════════ -->
    <div class="laporan-main-wrap">
        <main class="laporan-main p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

            <!-- ── Filter ──────────────────────────────────────────────── -->
            <section class="no-print bg-white border border-subtle rounded-none p-4">
                <form method="GET">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Preset Periode</label>
                            <select name="preset" class="w-full bg-gray-50 border border-gray-100 rounded-none px-3 py-2.5 text-sm transition-all">
                                <option value="hari_ini" <?= $preset === 'hari_ini'  ? 'selected' : '' ?>>Hari Ini</option>
                                <option value="minggu_ini" <?= $preset === 'minggu_ini' ? 'selected' : '' ?>>Minggu Ini</option>
                                <option value="bulan_ini" <?= $preset === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                                <option value="tahun_ini" <?= $preset === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                                <option value="custom" <?= $preset === 'custom'    ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Awal</label>
                            <input type="date" name="awal" value="<?= e($awal) ?>" class="w-full bg-gray-50 border border-gray-100 rounded-none px-3 py-2.5 text-sm transition-all">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Akhir</label>
                            <input type="date" name="akhir" value="<?= e($akhir) ?>" class="w-full bg-gray-50 border border-gray-100 rounded-none px-3 py-2.5 text-sm transition-all">
                        </div>
                        <div class="">
                            <button type="submit" class="w-full px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-sm hover:bg-gray-800 transition-all">Tampilkan</button>
                        </div>
                        <div class="">
                            <a href="laporan.php" class="w-full flex justify-center px-4 py-2.5 border border-subtle rounded-none text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 transition-all">Reset</a>
                        </div>
                    </div>
                </form>
            </section>

            <!-- ── Metric Cards (identik gaya summary produk.php) ──────── -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Omzet Bersih</p>
                    <p class="text-2xl font-bold text-blue-600"><?= rupiah($omzet) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Setelah diskon transaksi</p>
                </div>
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Transaksi</p>
                    <p class="text-2xl font-bold"><?= angka($totalTransaksi) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Rata-rata <?= rupiah($rata) ?></p>
                </div>
                <div class="bg-white border <?= $totalDiskon > 0 ? 'border-red-200' : 'border-subtle' ?> rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Diskon</p>
                    <p class="text-2xl font-bold <?= $totalDiskon > 0 ? 'text-red-600' : '' ?>"><?= rupiah($totalDiskon) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Trx <?= rupiah($diskonTrxSum) ?> + Barang <?= rupiah($diskonBarangTotal) ?></p>
                </div>
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Member</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($totalPoint) ?> pt</p>
                    <p class="text-[10px] text-gray-400 mt-1">Diberikan periode ini</p>
                </div>
                <div class="bg-white border <?= $totalPointPakai > 0 ? 'border-yellow-200' : 'border-subtle' ?> rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Ditukar</p>
                    <p class="text-2xl font-bold <?= $totalPointPakai > 0 ? 'text-yellow-600' : '' ?>"><?= angka($totalPointPakai) ?> pt</p>
                    <p class="text-[10px] text-gray-400 mt-1">Potongan <?= rupiah($totalNilaiPointPakai) ?></p>
                </div>
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bayar Diterima</p>
                    <p class="text-2xl font-bold"><?= rupiah($totalBayar) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                </div>
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Kembalian</p>
                    <p class="text-2xl font-bold"><?= rupiah($totalKembalian) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                </div>
                <div class="bg-white border border-subtle rounded-none p-4 md:p-5 md:col-span-2">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                    <p class="text-lg font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?= angka($totalTransaksi) ?> transaksi</p>
                </div>
            </div>

            <!-- ── Detail Transaksi ─────────────────────────────────────── -->
            <section class="bg-white border border-subtle rounded-none overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle flex items-center justify-between">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Transaksi</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($transaksi)) ?> transaksi ditemukan</p>
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:860px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Invoice</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Metode</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon Trx</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon Barang</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Bayar</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Kembali</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Point Pakai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$transaksi): ?>
                                <tr>
                                    <td colspan="11" class="py-20 text-center">
                                        <div class="inline-flex flex-col items-center gap-3 opacity-30">
                                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            <p class="text-xs font-bold uppercase tracking-widest">Belum ada transaksi</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($transaksi as $t):
                                $metode   = $t['metode_pembayaran'] ?? 'tunai';
                                $badgeCls = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                            ?>
                                <tr>
                                    <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($t['created_at'] ?? null)) ?></td>
                                    <td class="px-5 py-4"><a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm"><?= e($t['invoice']) ?></a></td>
                                    <td class="px-5 py-4">
                                        <?php if (!empty($t['member_nama'])): ?>
                                            <div class="font-semibold text-sm leading-tight"><?= e($t['member_nama']) ?></div>
                                            <div class="text-[10px] text-gray-400 font-mono"><?= e($t['member_kode'] ?? '') ?></div>
                                        <?php else: ?><span class="text-gray-400 text-sm">Umum</span><?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4"><span class="<?= $badgeCls ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(strtoupper($metode)) ?></span></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= ($t['diskon'] ?? 0) > 0 ? rupiah($t['diskon']) : '<span class="text-gray-300">—</span>' ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold text-orange-600"><?= ($t['diskon_barang'] ?? 0) > 0 ? rupiah($t['diskon_barang']) : '<span class="text-gray-300">—</span>' ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></td>
                                    <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['bayar'] ?? 0) ?></td>
                                    <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['kembalian'] ?? 0) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= ($t['point_dapat'] ?? 0) > 0 ? angka($t['point_dapat']) . ' pt' : '<span class="text-gray-300">—</span>' ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold text-yellow-600"><?= ($t['point_pakai'] ?? 0) > 0 ? angka($t['point_pakai']) . ' pt<br><span class="text-[10px] text-gray-400 font-normal">-' . rupiah($t['nilai_point_pakai'] ?? 0) . '</span>' : '<span class="text-gray-300">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($transaksi): ?>
                            <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                <tr>
                                    <td colspan="4" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total</td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold text-red-600"><?= rupiah($diskonTrxSum) ?></td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold text-orange-600"><?= rupiah($diskonBarangTotal) ?></td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold text-blue-600"><?= rupiah($omzet) ?></td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold"><?= rupiah($totalBayar) ?></td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold"><?= rupiah($totalKembalian) ?></td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold text-green-600"><?= angka($totalPoint) ?> pt</td>
                                    <td class="px-5 py-3 text-right text-[11px] font-bold text-yellow-600"><?= angka($totalPointPakai) ?> pt</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Mobile card list -->
                <div class="card-list">
                    <?php if (!$transaksi): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada transaksi</div>
                    <?php endif; ?>
                    <?php foreach ($transaksi as $t):
                        $metode   = $t['metode_pembayaran'] ?? 'tunai';
                        $badgeCls = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                    ?>
                        <div class="card-item bg-white border border-subtle rounded-none p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm block truncate"><?= e($t['invoice']) ?></a>
                                    <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($t['created_at'] ?? null)) ?></p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></p>
                                    <span class="<?= $badgeCls ?> text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block"><?= e(strtoupper($metode)) ?></span>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400 font-medium">Member</span>
                                    <span class="font-semibold"><?= !empty($t['member_nama']) ? e($t['member_nama']) : '<span class="text-gray-400">Umum</span>' ?></span>
                                </div>
                                <?php if (($t['diskon'] ?? 0) > 0): ?>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Diskon Trx</span>
                                        <span class="font-bold text-red-600"><?= rupiah($t['diskon']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (($t['diskon_barang'] ?? 0) > 0): ?>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Diskon Barang</span>
                                        <span class="font-bold text-orange-600"><?= rupiah($t['diskon_barang']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400 font-medium">Bayar</span>
                                    <span class="font-semibold"><?= rupiah($t['bayar'] ?? 0) ?></span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-400 font-medium">Kembalian</span>
                                    <span class="font-semibold"><?= rupiah($t['kembalian'] ?? 0) ?></span>
                                </div>
                                <?php if (($t['point_dapat'] ?? 0) > 0): ?>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Poin Didapat</span>
                                        <span class="font-bold text-green-600"><?= angka($t['point_dapat']) ?> pt</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (($t['point_pakai'] ?? 0) > 0): ?>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Point Ditukar</span>
                                        <span class="font-bold text-yellow-600"><?= angka($t['point_pakai']) ?> pt / -<?= rupiah($t['nilai_point_pakai'] ?? 0) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($transaksi): ?>
                        <div class="table-footer-mobile">
                            <span>Total: <strong class="text-blue-600"><?= rupiah($omzet) ?></strong></span>
                            <span>Diskon: <strong class="text-red-600"><?= rupiah($totalDiskon) ?></strong></span>
                            <span>Bayar: <strong><?= rupiah($totalBayar) ?></strong></span>
                            <span>Poin: <strong class="text-green-600"><?= angka($totalPoint) ?> pt</strong></span>
                            <?php if ($totalPointPakai > 0): ?><span>Tukar: <strong class="text-yellow-600"><?= angka($totalPointPakai) ?> pt</strong></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ── Produk Terlaris + Diskon Terpakai ────────────────────── -->
            <div class="col2 grid grid-cols-1 lg:grid-cols-2 gap-5 md:gap-6">

                <!-- Produk Terlaris -->
                <section class="bg-white border border-subtle rounded-none overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk Terlaris</h2>
                        <p class="text-xs text-gray-400 mt-0.5">Top 20 berdasarkan qty terjual</p>
                    </div>
                    <!-- Desktop -->
                    <div class="tbl-desktop overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:400px">
                            <thead class="border-b border-subtle bg-gray-50">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Qty</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Penjualan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php if (!$produk): ?>
                                    <tr>
                                        <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada produk</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($produk as $i => $p): ?>
                                    <tr>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-2">
                                                <?php if ($i < 3): ?><span class="rank-circle"><?= $i + 1 ?></span><?php endif; ?>
                                                <div>
                                                    <div class="font-semibold text-sm leading-tight"><?= e($p['nama']) ?></div>
                                                    <div class="text-[10px] text-gray-400 font-mono"><?= e($p['kode'] ?? '') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($p['qty']) ?></td>
                                        <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= $p['diskon'] > 0 ? rupiah($p['diskon']) : '<span class="text-gray-300">—</span>' ?></td>
                                        <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($p['penjualan']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php if (!$produk): ?>
                            <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada produk</div>
                        <?php endif; ?>
                        <?php foreach ($produk as $i => $p): ?>
                            <div class="card-item bg-white border border-subtle rounded-none p-4 flex flex-col gap-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <?php if ($i < 3): ?><span class="rank-circle shrink-0"><?= $i + 1 ?></span><?php endif; ?>
                                        <div class="min-w-0">
                                            <p class="font-bold text-sm leading-tight truncate"><?= e($p['nama']) ?></p>
                                            <p class="text-[10px] text-gray-400 font-mono truncate"><?= e($p['kode'] ?? '') ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-bold"><?= rupiah($p['penjualan']) ?></p>
                                        <p class="text-[10px] text-gray-400 mt-0.5"><?= angka($p['qty']) ?> pcs</p>
                                    </div>
                                </div>
                                <?php if ($p['diskon'] > 0): ?>
                                    <div class="flex justify-between text-xs pt-2 border-t border-subtle">
                                        <span class="text-gray-400 font-medium">Diskon</span>
                                        <span class="font-bold text-red-600"><?= rupiah($p['diskon']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- Diskon Terpakai -->
                <section class="bg-white border border-subtle rounded-none overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Diskon Terpakai</h2>
                        <p class="text-xs text-gray-400 mt-0.5">Promo transaksi dan barang</p>
                    </div>
                    <!-- Desktop -->
                    <div class="tbl-desktop overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:360px">
                            <thead class="border-b border-subtle bg-gray-50">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nama</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tipe</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Pakai</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php if (!$diskonTransaksi && !$diskonBarang): ?>
                                    <tr>
                                        <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada diskon</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($diskonTransaksi as $d): ?>
                                    <tr>
                                        <td class="px-5 py-4 text-sm font-semibold"><?= e($d['nama']) ?></td>
                                        <td class="px-5 py-4"><span class="badge-trx text-[9px] font-bold uppercase px-2 py-0.5 rounded-full">Transaksi</span></td>
                                        <td class="px-5 py-4 text-right text-sm"><?= angka($d['jumlah']) ?>×</td>
                                        <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($diskonBarang as $d): ?>
                                    <tr>
                                        <td class="px-5 py-4 text-sm font-semibold"><?= e($d['nama']) ?></td>
                                        <td class="px-5 py-4"><span class="badge-barang text-[9px] font-bold uppercase px-2 py-0.5 rounded-full">Barang</span></td>
                                        <td class="px-5 py-4 text-right text-sm"><?= angka($d['jumlah']) ?>×</td>
                                        <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($diskonTransaksi || $diskonBarang): ?>
                                <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                    <tr>
                                        <td colspan="3" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total Diskon</td>
                                        <td class="px-5 py-3 text-right text-[11px] font-bold text-red-600"><?= rupiah($totalDiskon) ?></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php if (!$diskonTransaksi && !$diskonBarang): ?>
                            <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada diskon</div>
                        <?php endif; ?>
                        <?php foreach ($diskonTransaksi as $d): ?>
                            <div class="card-item bg-white border border-subtle rounded-none p-4 flex flex-col gap-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-bold text-sm"><?= e($d['nama']) ?></p>
                                        <span class="badge-trx text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block">Transaksi</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></p>
                                        <p class="text-[10px] text-gray-400 mt-0.5"><?= angka($d['jumlah']) ?>× dipakai</p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($diskonBarang as $d): ?>
                            <div class="card-item bg-white border border-subtle rounded-none p-4 flex flex-col gap-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-bold text-sm"><?= e($d['nama']) ?></p>
                                        <span class="badge-barang text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block">Barang</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></p>
                                        <p class="text-[10px] text-gray-400 mt-0.5"><?= angka($d['jumlah']) ?>× dipakai</p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($diskonTransaksi || $diskonBarang): ?>
                            <div class="table-footer-mobile">
                                <span>Total Diskon: <strong class="text-red-600"><?= rupiah($totalDiskon) ?></strong></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- ── Poin Member ──────────────────────────────────────────── -->
            <?php if ($memberPoin): ?>
                <section class="bg-white border border-subtle rounded-none overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Poin Member — Periode Ini</h2>
                        <p class="text-xs text-gray-400 mt-0.5">Poin dari transaksi dalam periode ini (bukan kumulatif lifetime)</p>
                    </div>
                    <!-- Desktop -->
                    <div class="tbl-desktop overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:600px">
                            <thead class="border-b border-subtle bg-gray-50">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Trx</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total Belanja</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin Periode</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin Total (DB)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php foreach ($memberPoin as $mp): ?>
                                    <tr>
                                        <td class="px-5 py-4 text-sm font-semibold"><?= e($mp['nama']) ?></td>
                                        <td class="px-5 py-4 text-[11px] text-gray-400 font-mono"><?= e($mp['kode']) ?></td>
                                        <td class="px-5 py-4 text-right text-sm"><?= angka($mp['jumlah_trx']) ?></td>
                                        <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($mp['total_belanja']) ?></td>
                                        <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= angka($mp['point_periode']) ?> pt</td>
                                        <td class="px-5 py-4 text-right text-sm text-gray-400"><?= angka($mp['point_total_lifetime']) ?> pt</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Mobile card list -->
                    <div class="card-list">
                        <?php foreach ($memberPoin as $mp): ?>
                            <div class="card-item bg-white border border-subtle rounded-none p-4 flex flex-col gap-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-bold text-sm truncate"><?= e($mp['nama']) ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono"><?= e($mp['kode']) ?></p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-bold text-green-600"><?= angka($mp['point_periode']) ?> pt</p>
                                        <p class="text-[10px] text-gray-400 mt-0.5">periode ini</p>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Total Belanja</span>
                                        <span class="font-semibold"><?= rupiah($mp['total_belanja']) ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Jumlah Trx</span>
                                        <span class="font-semibold"><?= angka($mp['jumlah_trx']) ?>×</span>
                                    </div>
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-400 font-medium">Poin Total (DB)</span>
                                        <span class="font-semibold text-gray-400"><?= angka($mp['point_total_lifetime']) ?> pt</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        </main>
    </div>

    <!-- ══ Mobile Bottom Navigation (identik produk.php) ═══════════ -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg no-print">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18M3 6h18M3 18h18" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>
        <a href="pos.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M8 12h8" />
            </svg>
        </a>
        <a href="laporan.php" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Laporan</span>
        </a>
    </nav>

    <script>
        function toggleMobileMenu() {
            const overlay = document.getElementById('mobileMenuOverlay');
            const content = document.getElementById('mobileMenuContent');
            if (!overlay || !content) return;
            if (overlay.classList.contains('invisible')) {
                overlay.classList.remove('invisible');
                overlay.classList.add('opacity-100');
                content.classList.remove('translate-x-full');
            } else {
                overlay.classList.add('invisible');
                overlay.classList.remove('opacity-100');
                content.classList.add('translate-x-full');
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('mobileMenuOverlay');
            if (overlay) overlay.addEventListener('click', function(e) {
                if (e.target === this) toggleMobileMenu();
            });
            // Auto-switch preset to custom when dates changed manually
            document.querySelectorAll('input[name="awal"], input[name="akhir"]').forEach(el => {
                el.addEventListener('change', () => {
                    document.querySelector('select[name="preset"]').value = 'custom';
                });
            });
        });
    </script>
</body>

</html>