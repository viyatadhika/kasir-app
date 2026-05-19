<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'auth.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu  = 'laporan';
$pageTitle   = 'Laporan Keuangan';
$backUrl     = 'dashboard.php';
$isAdmin       = has_role('admin');
$isKasirOnly   = has_role('kasir') && !$isAdmin;
$isMurniRental = has_role('rental') && !$isAdmin;
$showTabs      = $isAdmin; // admin lihat semua via tab
$isKasir       = has_role('admin', 'kasir');   // admin + kasir lihat laporan POS
$isRental      = has_role('admin', 'rental');  // admin + rental lihat laporan rental

// ── Helper functions ─────────────────────────────────────────────────────────
if (!function_exists('rupiah')) {
    /** @param mixed $v */
    function rupiah($v): string
    {
        return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
    }
}
if (!function_exists('angka')) {
    /** @param mixed $v */
    function angka($v): string
    {
        return number_format((float)($v ?? 0), 0, ',', '.');
    }
}
if (!function_exists('tgl')) {
    /** @param mixed $v */
    function tgl($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}
if (!function_exists('waktu')) {
    /** @param mixed $v */
    function waktu($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}
if (!function_exists('e')) {
    /** @param mixed $v */
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
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

// ── Rentang tanggal ──────────────────────────────────────────────────────────
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

// ── Inisialisasi variabel ────────────────────────────────────────────────────
$summary              = [];
$transaksi            = [];
$produk               = [];
$diskonTransaksi      = [];
$diskonBarang         = [];
$memberPoin           = [];
$totalTransaksi       = 0;
$omzet                = 0;
$diskonTrxSum         = 0;
$diskonBarangTotal    = 0;
$totalDiskon          = 0;
$totalBayar           = 0;
$totalKembalian       = 0;
$totalPoint           = 0;
$totalPointPakai      = 0;
$totalNilaiPointPakai = 0;
$rata                 = 0;

// Rental
$ringkasanRental    = ['total_order' => 0, 'total_pendapatan' => 0, 'total_driver' => 0];
$orderRental        = [];
$totalOrderRental   = 0;
$pendapatanRental   = 0;

// ════════════════════════════════════════════════════════════════════════════
// DATA: ADMIN & KASIR — Transaksi POS
// ════════════════════════════════════════════════════════════════════════════
if ($isKasir) {

    $hasPointPakai      = transaksi_col_exists($pdo, 'point_pakai');
    $hasNilaiPointPakai = transaksi_col_exists($pdo, 'nilai_point_pakai');
    $whereStatus        = "AND (t.metode_pembayaran = 'tunai' OR t.payment_status = 'paid')";

    try {
        $diskonSum          = "COALESCE(SUM(t.diskon), 0)";
        $pointSum           = "COALESCE(SUM(t.point_dapat), 0)";
        $pointPakaiSum      = $hasPointPakai      ? "COALESCE(SUM(t.point_pakai), 0)"        : "0";
        $nilaiPointPakaiSum = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai), 0)"  : "0";

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

        // Diskon barang
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total_diskon_barang
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarangTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total_diskon_barang'] ?? 0);

        $pointPakaiSelect = $hasPointPakai      ? "t.point_pakai"        : "0 AS point_pakai";
        $nilaiPPSelect    = $hasNilaiPointPakai ? "t.nilai_point_pakai"  : "0 AS nilai_point_pakai";
        $diskonBarangPerTrx = "(SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td2.harga_normal,td2.harga)*td2.qty)-td2.subtotal)),0) FROM transaksi_detail td2 WHERE td2.transaksi_id=t.id)";

        $stmt = $pdo->prepare("
            SELECT t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
                   t.diskon, ($diskonBarangPerTrx) AS diskon_barang,
                   t.point_dapat, $pointPakaiSelect, $nilaiPPSelect,
                   m.nama AS member_nama, m.kode AS member_kode,
                   t.metode_pembayaran, t.payment_status
            FROM transaksi t
            LEFT JOIN member m ON m.id = t.member_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
            ORDER BY t.created_at DESC, t.id DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Produk terlaris
        $stmt = $pdo->prepare("
            SELECT td.produk_id, td.nama, MAX(td.kode) AS kode, SUM(td.qty) AS qty,
                   SUM(COALESCE(td.harga_normal,td.harga)*td.qty) AS subtotal_normal,
                   SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)) AS diskon,
                   SUM(td.subtotal) AS penjualan
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
            GROUP BY td.produk_id, td.nama ORDER BY qty DESC, penjualan DESC LIMIT 20
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Diskon transaksi
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Transaksi') AS nama, COUNT(t.id) AS jumlah,
                   COALESCE(SUM(t.diskon),0) AS total
            FROM transaksi t LEFT JOIN diskon d ON d.id=t.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus AND COALESCE(t.diskon,0)>0
            GROUP BY t.diskon_id, d.nama ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Diskon barang
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Barang') AS nama, td.diskon_id,
                   COUNT(td.id) AS jumlah,
                   COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total
            FROM transaksi_detail td
            JOIN transaksi t ON t.id=td.transaksi_id
            LEFT JOIN diskon d ON d.id=td.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir $whereStatus
              AND GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)>0
            GROUP BY td.diskon_id, d.nama ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Member poin
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
    } catch (Throwable $e) {
        die('Gagal memuat laporan: ' . htmlspecialchars($e->getMessage()));
    }

    $totalTransaksi       = (int)($summary['total_transaksi']   ?? 0);
    $omzet                = (int)($summary['omzet']             ?? 0);
    $diskonTrxSum         = (int)($summary['diskon_transaksi']  ?? 0);
    $totalDiskon          = $diskonTrxSum + $diskonBarangTotal;
    $totalBayar           = (int)($summary['bayar']             ?? 0);
    $totalKembalian       = (int)($summary['kembalian']         ?? 0);
    $totalPoint           = (int)($summary['point']             ?? 0);
    $totalPointPakai      = (int)($summary['point_pakai']       ?? 0);
    $totalNilaiPointPakai = (int)($summary['nilai_point_pakai'] ?? 0);
    $rata                 = $totalTransaksi > 0 ? (int)floor($omzet / $totalTransaksi) : 0;
}

// ════════════════════════════════════════════════════════════════════════════
// DATA: RENTAL — Data rental_bandara saja
// ════════════════════════════════════════════════════════════════════════════
if ($isRental) {
    try {
        // Ringkasan
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_order,
                   COALESCE(SUM(total_bayar), 0) AS total_pendapatan
            FROM rental_bandara
            WHERE DATE(created_at) BETWEEN :awal AND :akhir
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $ringkasanRental = $stmt->fetch(PDO::FETCH_ASSOC) ?: $ringkasanRental;

        // Detail order
        $stmt = $pdo->prepare("
            SELECT r.*, d.nama AS nama_driver
            FROM rental_bandara r
            LEFT JOIN driver d ON r.driver_id = d.id
            WHERE DATE(r.created_at) BETWEEN :awal AND :akhir
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $orderRental = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Tabel belum ada atau kolom berbeda — tampil kosong, tidak crash
        $orderRental = [];
    }

    $totalOrderRental = (int)($ringkasanRental['total_order']      ?? 0);
    $pendapatanRental = (int)($ringkasanRental['total_pendapatan'] ?? 0);
}

catat_view_once($pdo, 'Laporan Keuangan', 'Membuka halaman Laporan Keuangan');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($isRental ? 'Laporan Rental' : 'Laporan Keuangan') ?> — SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
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

        input:focus,
        select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important;
        }

        .invoice-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .invoice-link:hover {
            text-decoration: underline;
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

        .badge-selesai {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-proses {
            background: #fefce8;
            color: #a16207;
            border: 1px solid #fde68a;
        }

        .badge-batal {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .card-list {
            display: none;
        }

        @media (min-width:1024px) {

            .laporan-header,
            .laporan-main-wrap {
                margin-left: 220px;
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

        @media (max-width:1023px) {
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

            .table-footer-mobile {
                grid-column: 1/-1;
                background: #fafafa;
                border: 1px solid #f0f0f0;
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

        @media (max-width:640px) {
            .card-list {
                grid-template-columns: 1fr;
            }

            .laporan-main {
                padding: .75rem !important;
                padding-bottom: 6rem !important;
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
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <div class="laporan-main-wrap">
        <main class="laporan-main p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

            <!-- ── Judul + Tab (admin lihat semua) ─────────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold tracking-tight">Laporan Keuangan</h2>
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-0.5">
                        <?php
                        if ($isAdmin) echo 'Lihat semua laporan — kasir, rental, dan lainnya';
                        elseif ($isMurniRental) echo 'Data order &amp; pendapatan rental';
                        else echo 'Data transaksi POS &amp; diskon';
                        ?>
                    </p>
                </div>
            </div>

            <!-- Tab navigasi — hanya muncul untuk admin -->
            <?php if ($showTabs): ?>
                <div class="flex gap-0 border-b border-subtle no-print">
                    <button id="tab-kasir" onclick="switchTab('kasir')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Kasir / POS
                    </button>
                    <button id="tab-rental" onclick="switchTab('rental')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Rental Bandara
                    </button>
                    <!-- ke depan: tambah tab baru di sini -->
                </div>
            <?php endif; ?>

            <!-- ── Filter Periode ────────────────────────────────────────────────── -->
            <section class="no-print bg-white border border-subtle p-4">
                <form method="GET">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Preset Periode</label>
                            <select name="preset" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                                <option value="hari_ini" <?= $preset === 'hari_ini'  ? 'selected' : '' ?>>Hari Ini</option>
                                <option value="minggu_ini" <?= $preset === 'minggu_ini' ? 'selected' : '' ?>>Minggu Ini</option>
                                <option value="bulan_ini" <?= $preset === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                                <option value="tahun_ini" <?= $preset === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                                <option value="custom" <?= $preset === 'custom'    ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Awal</label>
                            <input type="date" name="awal" value="<?= e($awal) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Akhir</label>
                            <input type="date" name="akhir" value="<?= e($akhir) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <button type="submit" class="w-full px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">Tampilkan</button>
                        </div>
                        <div>
                            <a href="laporan.php" class="w-full flex justify-center px-4 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 transition-all">Reset</a>
                        </div>
                    </div>
                </form>
            </section>


            <?php if ($isKasir): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN KASIR / POS                                                -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-kasir">

                    <!-- KPI Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Omzet Bersih</p>
                            <p class="text-2xl font-bold text-blue-600"><?= rupiah($omzet) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Setelah diskon transaksi</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Transaksi</p>
                            <p class="text-2xl font-bold"><?= angka($totalTransaksi) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Rata-rata <?= rupiah($rata) ?></p>
                        </div>
                        <div class="bg-white border <?= $totalDiskon > 0 ? 'border-red-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Diskon</p>
                            <p class="text-2xl font-bold <?= $totalDiskon > 0 ? 'text-red-600' : '' ?>"><?= rupiah($totalDiskon) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Trx <?= rupiah($diskonTrxSum) ?> + Barang <?= rupiah($diskonBarangTotal) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Member</p>
                            <p class="text-2xl font-bold text-green-600"><?= angka($totalPoint) ?> pt</p>
                            <p class="text-[10px] text-gray-400 mt-1">Diberikan periode ini</p>
                        </div>
                        <div class="bg-white border <?= $totalPointPakai > 0 ? 'border-yellow-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Ditukar</p>
                            <p class="text-2xl font-bold <?= $totalPointPakai > 0 ? 'text-yellow-600' : '' ?>"><?= angka($totalPointPakai) ?> pt</p>
                            <p class="text-[10px] text-gray-400 mt-1">Potongan <?= rupiah($totalNilaiPointPakai) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bayar Diterima</p>
                            <p class="text-2xl font-bold"><?= rupiah($totalBayar) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Kembalian</p>
                            <p class="text-2xl font-bold"><?= rupiah($totalKembalian) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5 md:col-span-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                            <p class="text-lg font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($totalTransaksi) ?> transaksi</p>
                        </div>
                    </div>

                    <!-- Detail Transaksi -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Transaksi</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($transaksi)) ?> transaksi ditemukan</p>
                            </div>
                        </div>
                        <!-- Desktop -->
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
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$transaksi): ?>
                                        <tr>
                                            <td colspan="10" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada transaksi</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($transaksi as $t):
                                        $metode = $t['metode_pembayaran'] ?? 'tunai';
                                        $bCls   = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                                    ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($t['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4"><a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm"><?= e($t['invoice']) ?></a></td>
                                            <td class="px-5 py-4">
                                                <?php if (!empty($t['member_nama'])): ?>
                                                    <div class="font-semibold text-sm"><?= e($t['member_nama']) ?></div>
                                                    <div class="text-[10px] text-gray-400 font-mono"><?= e($t['member_kode'] ?? '') ?></div>
                                                <?php else: ?><span class="text-gray-400 text-sm">Umum</span><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4"><span class="<?= $bCls ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(strtoupper($metode)) ?></span></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= ($t['diskon'] ?? 0) > 0 ? rupiah($t['diskon']) : '<span class="text-gray-300">—</span>' ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-orange-600"><?= ($t['diskon_barang'] ?? 0) > 0 ? rupiah($t['diskon_barang']) : '<span class="text-gray-300">—</span>' ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['bayar'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['kembalian'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= ($t['point_dapat'] ?? 0) > 0 ? angka($t['point_dapat']) . ' pt' : '<span class="text-gray-300">—</span>' ?></td>
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
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                        <!-- Mobile -->
                        <div class="card-list">
                            <?php if (!$transaksi): ?>
                                <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada transaksi</div>
                            <?php endif; ?>
                            <?php foreach ($transaksi as $t):
                                $metode = $t['metode_pembayaran'] ?? 'tunai';
                                $bCls   = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                            ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm block truncate"><?= e($t['invoice']) ?></a>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($t['created_at'] ?? null)) ?></p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></p>
                                            <span class="<?= $bCls ?> text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block"><?= e(strtoupper($metode)) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-400">Member</span>
                                            <span class="font-semibold"><?= !empty($t['member_nama']) ? e($t['member_nama']) : '<span class="text-gray-400">Umum</span>' ?></span>
                                        </div>
                                        <?php if (($t['diskon'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Diskon Trx</span><span class="font-bold text-red-600"><?= rupiah($t['diskon']) ?></span></div><?php endif; ?>
                                        <?php if (($t['diskon_barang'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Diskon Barang</span><span class="font-bold text-orange-600"><?= rupiah($t['diskon_barang']) ?></span></div><?php endif; ?>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Bayar</span><span class="font-semibold"><?= rupiah($t['bayar'] ?? 0) ?></span></div>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Kembalian</span><span class="font-semibold"><?= rupiah($t['kembalian'] ?? 0) ?></span></div>
                                        <?php if (($t['point_dapat'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Poin</span><span class="font-bold text-green-600"><?= angka($t['point_dapat']) ?> pt</span></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($transaksi): ?>
                                <div class="table-footer-mobile">
                                    <span>Total: <strong class="text-blue-600"><?= rupiah($omzet) ?></strong></span>
                                    <span>Diskon: <strong class="text-red-600"><?= rupiah($totalDiskon) ?></strong></span>
                                    <span>Bayar: <strong><?= rupiah($totalBayar) ?></strong></span>
                                    <span>Poin: <strong class="text-green-600"><?= angka($totalPoint) ?> pt</strong></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- Produk Terlaris + Diskon -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 md:gap-6">

                        <!-- Produk Terlaris -->
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk Terlaris</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Top 20 berdasarkan qty terjual</p>
                            </div>
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
                                        <?php if (!$produk): ?><tr>
                                                <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada produk</td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($produk as $i => $p): ?>
                                            <tr>
                                                <td class="px-5 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <?php if ($i < 3): ?><span class="rank-circle"><?= $i + 1 ?></span><?php endif; ?>
                                                        <div>
                                                            <div class="font-semibold text-sm"><?= e($p['nama']) ?></div>
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
                            <div class="card-list">
                                <?php if (!$produk): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada produk</div><?php endif; ?>
                                <?php foreach ($produk as $i => $p): ?>
                                    <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <?php if ($i < 3): ?><span class="rank-circle shrink-0"><?= $i + 1 ?></span><?php endif; ?>
                                                <div class="min-w-0">
                                                    <p class="font-bold text-sm truncate"><?= e($p['nama']) ?></p>
                                                </div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-bold"><?= rupiah($p['penjualan']) ?></p>
                                                <p class="text-[10px] text-gray-400"><?= angka($p['qty']) ?> pcs</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <!-- Diskon Terpakai -->
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Diskon Terpakai</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Promo transaksi dan barang</p>
                            </div>
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
                                        <?php if (!$diskonTransaksi && !$diskonBarang): ?><tr>
                                                <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada diskon</td>
                                            </tr><?php endif; ?>
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
                        </section>
                    </div>

                    <!-- Poin Member — hanya admin & kasir, rental tidak perlu lihat ini -->
                    <?php if ($memberPoin): ?>
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Poin Member — Periode Ini</h2>
                            </div>
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
                        </section>
                    <?php endif; ?>

                </div><!-- end panel-kasir -->
            <?php endif; // end isKasir 
            ?>


            <?php if ($isRental): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN RENTAL                                                      -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-rental">

                    <!-- KPI Cards Rental -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Order</p>
                            <p class="text-2xl font-bold text-blue-600"><?= angka($totalOrderRental) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Dalam periode ini</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pendapatan</p>
                            <p class="text-2xl font-bold text-green-600"><?= rupiah($pendapatanRental) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Total bayar diterima</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5 sm:col-span-1">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                            <p class="text-lg font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($totalOrderRental) ?> order</p>
                        </div>
                    </div>

                    <!-- Detail Order Rental -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Order Rental</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($orderRental)) ?> order ditemukan</p>
                        </div>
                        <!-- Desktop -->
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:700px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Penumpang</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Driver</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tujuan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total Bayar</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$orderRental): ?>
                                        <tr>
                                            <td colspan="6" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada order rental</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($orderRental as $r):
                                        $status   = strtolower($r['status'] ?? 'proses');
                                        $badgeSts = $status === 'selesai' ? 'badge-selesai' : ($status === 'batal' ? 'badge-batal' : 'badge-proses');
                                    ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($r['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4 text-sm font-semibold"><?= e($r['nama_penumpang'] ?? $r['nama'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-600"><?= e($r['nama_driver'] ?? 'Belum assign') ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-600"><?= e($r['tujuan'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($r['total_bayar'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center"><span class="<?= $badgeSts ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(strtoupper($status)) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($orderRental): ?>
                                    <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                        <tr>
                                            <td colspan="4" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total</td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-green-600"><?= rupiah($pendapatanRental) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                        <!-- Mobile -->
                        <div class="card-list">
                            <?php if (!$orderRental): ?>
                                <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada order rental</div>
                            <?php endif; ?>
                            <?php foreach ($orderRental as $r):
                                $status   = strtolower($r['status'] ?? 'proses');
                                $badgeSts = $status === 'selesai' ? 'badge-selesai' : ($status === 'batal' ? 'badge-batal' : 'badge-proses');
                            ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($r['nama_penumpang'] ?? $r['nama'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($r['created_at'] ?? null)) ?></p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold"><?= rupiah($r['total_bayar'] ?? 0) ?></p>
                                            <span class="<?= $badgeSts ?> text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block"><?= e(strtoupper($status)) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Driver</span><span class="font-semibold"><?= e($r['nama_driver'] ?? 'Belum assign') ?></span></div>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Tujuan</span><span class="font-semibold"><?= e($r['tujuan'] ?? '-') ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($orderRental): ?>
                                <div class="table-footer-mobile">
                                    <span>Total Order: <strong><?= angka($totalOrderRental) ?></strong></span>
                                    <span>Pendapatan: <strong class="text-green-600"><?= rupiah($pendapatanRental) ?></strong></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                </div><!-- end panel-rental -->
            <?php endif; // end isRental 
            ?>

        </main>
    </div>

    <script>
        // ── Tab switching (admin only) ────────────────────────────────────────────
        var activeTab = '<?php echo $isAdmin ? "kasir" : ($isMurniRental ? "rental" : "kasir"); ?>';

        function switchTab(tab) {
            activeTab = tab;

            // Panel visibility
            var panelKasir = document.getElementById('panel-kasir');
            var panelRental = document.getElementById('panel-rental');
            if (panelKasir) panelKasir.style.display = tab === 'kasir' ? '' : 'none';
            if (panelRental) panelRental.style.display = tab === 'rental' ? '' : 'none';

            // Tab button style
            document.querySelectorAll('.tab-btn').forEach(function(btn) {
                btn.classList.remove('border-black', 'text-black');
                btn.classList.add('border-transparent', 'text-gray-400');
            });
            var active = document.getElementById('tab-' + tab);
            if (active) {
                active.classList.remove('border-transparent', 'text-gray-400');
                active.classList.add('border-black', 'text-black');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Init tab state
            <?php if ($showTabs): ?>
                switchTab(activeTab);
            <?php else: ?>
                // Non-admin: pastikan panel yang relevan tampil, sisanya hidden
                var panelKasir = document.getElementById('panel-kasir');
                var panelRental = document.getElementById('panel-rental');
                <?php if ($isMurniRental): ?>
                    if (panelKasir) panelKasir.style.display = 'none';
                    if (panelRental) panelRental.style.display = '';
                <?php else: ?>
                    if (panelKasir) panelKasir.style.display = '';
                    if (panelRental) panelRental.style.display = 'none';
                <?php endif; ?>
            <?php endif; ?>

            // Auto-switch preset ke custom saat ubah tanggal manual
            document.querySelectorAll('input[name="awal"], input[name="akhir"]').forEach(function(el) {
                el.addEventListener('change', function() {
                    document.querySelector('select[name="preset"]').value = 'custom';
                });
            });
        });
    </script>
</body>

</html>