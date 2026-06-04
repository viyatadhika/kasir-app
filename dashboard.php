<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
require_once 'activity_helper.php';
requireAccess();

if (!function_exists('formatRp')) {
    /** @param int|float $angka */
    function formatRp($angka): string
    {
        return 'Rp ' . number_format((float)$angka, 0, ',', '.');
    }
}

if (!function_exists('dashboard_pinjaman_status_label')) {
    function dashboard_pinjaman_status_label(string $status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === '') {
            return 'Batal Pengajuan';
        }

        $map = [
            'pending' => 'Pending',
            'diseleksi' => 'Diseleksi',
            'diproses' => 'Diproses',
            'disetujui' => 'Disetujui',
            'ditolak' => 'Ditolak',
            'dibatalkan' => 'Batal Pengajuan',
            'dicairkan' => 'Dicairkan',
            'aktif' => 'Aktif',
            'berjalan' => 'Aktif',
            'lunas' => 'Lunas',
        ];

        return $map[$status] ?? ucfirst($status);
    }
}

if (!function_exists('dashboard_pinjaman_status_class')) {
    function dashboard_pinjaman_status_class(string $status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'pending') {
            return 'bg-amber-50 text-amber-700 border-amber-200';
        }

        if (in_array($status, ['diseleksi', 'diproses'], true)) {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }

        if (in_array($status, ['disetujui', 'dicairkan', 'aktif', 'berjalan', 'lunas'], true)) {
            return 'bg-green-50 text-green-700 border-green-200';
        }

        if (in_array($status, ['ditolak', 'dibatalkan', ''], true)) {
            return 'bg-red-50 text-red-700 border-red-200';
        }

        return 'bg-gray-50 text-gray-700 border-gray-200';
    }
}


$activeMenu  = 'dashboard';
$pageTitle   = 'Dashboard';
$backUrl     = '';
$currentRole = getCurrentRole(); // 'admin' | 'kasir' | 'rental'
$today       = date('Y-m-d');

// ── Inisialisasi variabel (mencegah undefined variable warning) ───────────────
$ringkasan          = ['total_sales' => 0, 'jumlah_struk' => 0];
$avgStruk           = 0;
$produkStokLimit    = [];
$strukTerakhir      = [];
$fastMoving         = [];
$chartLabels        = [];
$chartData          = [];
$ringkasanRental    = ['total_order' => 0, 'total_pendapatan' => 0];
$orderRentalTerbaru = [];
$totalDriver        = 0;

// ── Inisialisasi variabel KSP / Simpan Pinjam ───────────────────────────────
$kspPengajuanSummary = [
    'pending' => 0,
    'diseleksi' => 0,
    'disetujui' => 0,
    'ditolak' => 0,
    'dibatalkan' => 0,
    'dicairkan' => 0,
];
$kspTotalPengajuanNilai = 0;
$kspPinjamanAktif = ['total' => 0, 'pokok' => 0, 'angsuran_bulanan' => 0];
$kspAngsuranJatuhTempo = ['total' => 0, 'nilai' => 0];
$kspPengajuanTerbaru = [];
$kspKonfig = ['bunga_uang' => 0, 'bunga_barang' => 0, 'tenor_maks_uang' => 0, 'tenor_maks_barang' => 0];

// ── API: Direct Thermal Reprint ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'reprint_data') {
    header('Content-Type: application/json; charset=utf-8');

    // Hanya admin & kasir boleh reprint
    if (!has_role('admin', 'kasir')) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }

    $id      = isset($_GET['id'])      ? (int)$_GET['id']              : 0;
    $invoice = isset($_GET['invoice']) ? trim((string)$_GET['invoice']) : '';

    if ($id <= 0 && $invoice === '') {
        echo json_encode(['success' => false, 'message' => 'ID / invoice kosong.']);
        exit;
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT t.*, u.nama AS kasir,
                       m.nama  AS member_nama,
                       m.kode  AS member_kode,
                       m.point AS member_point_total
                FROM transaksi t
                LEFT JOIN users  u ON t.user_id  = u.id
                LEFT JOIN member m ON t.member_id = m.id
                WHERE t.id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT t.*, u.nama AS kasir,
                       m.nama  AS member_nama,
                       m.kode  AS member_kode,
                       m.point AS member_point_total
                FROM transaksi t
                LEFT JOIN users  u ON t.user_id  = u.id
                LEFT JOIN member m ON t.member_id = m.id
                WHERE t.invoice = :invoice LIMIT 1
            ");
            $stmt->execute([':invoice' => $invoice]);
        }

        $trx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trx) {
            echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan.']);
            exit;
        }

        $stmtDetail = $pdo->prepare("
            SELECT * FROM transaksi_detail
            WHERE transaksi_id = :tid ORDER BY id ASC
        ");
        $stmtDetail->execute([':tid' => (int)$trx['id']]);
        $details = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

        $items          = [];
        $subtotalNormal = 0;
        $diskonBarang   = 0;

        foreach ($details as $d) {
            $qty          = (int)($d['qty'] ?? 0);
            $hargaNormal  = (int)(($d['harga_normal'] ?? null) !== null ? $d['harga_normal'] : ($d['harga'] ?? 0));
            $hargaFinal   = (int)($d['harga'] ?? $hargaNormal);
            $subtotalItem = (int)($d['subtotal'] ?? ($hargaFinal * $qty));
            $diskonItem   = isset($d['diskon']) ? (int)$d['diskon'] : max(0, ($hargaNormal * $qty) - $subtotalItem);

            $subtotalNormal += $hargaNormal * $qty;
            $diskonBarang   += $diskonItem;

            $items[] = [
                'nama'          => strtoupper((string)($d['nama'] ?? 'PRODUK')),
                'qty'           => $qty,
                'harga_normal'  => $hargaNormal,
                'normal_item'   => $hargaNormal * $qty,
                'subtotal_item' => $subtotalItem,
                'diskon_item'   => $diskonItem,
                'diskon_satuan' => $qty > 0 ? (int)round($diskonItem / $qty) : $diskonItem,
                'nama_diskon'   => '',
            ];
        }

        $totalDiskon     = (int)($trx['diskon'] ?? 0);
        if ($totalDiskon <= 0) $totalDiskon = $diskonBarang;
        $diskonTransaksi = max(0, $totalDiskon - $diskonBarang);

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'                 => (int)$trx['id'],
                'invoice'            => (string)$trx['invoice'],
                'tanggal'            => date('d/m/Y H:i:s', strtotime($trx['created_at'] ?? 'now')),
                'operator'           => (string)($trx['kasir'] ?? ($_SESSION['nama'] ?? 'Kasir')),
                'member_nama'        => (string)($trx['member_nama'] ?? ''),
                'member_kode'        => (string)($trx['member_kode'] ?? ''),
                'member_point_total' => (int)($trx['member_point_total'] ?? 0),
                'items'              => $items,
                'subtotal_normal'    => $subtotalNormal,
                'diskon_barang'      => $diskonBarang,
                'diskon_transaksi'   => $diskonTransaksi,
                'total_diskon'       => $totalDiskon,
                'total_bayar'        => (int)($trx['total'] ?? 0),
                'bayar'              => (int)($trx['bayar'] ?? 0),
                'kembalian'          => (int)($trx['kembalian'] ?? 0),
                'point_dapat'        => (int)($trx['point_dapat'] ?? 0),
                'point_dipakai'      => (int)($trx['point_pakai'] ?? 0),
                'nilai_point'        => (int)($trx['nilai_point_pakai'] ?? 0),
                'nama_diskon_trx'    => '',
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── Data: Admin & Kasir ──────────────────────────────────────────────────────
if (has_role('admin', 'kasir')) {

    $stmtSales = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) AS total_sales, COUNT(*) AS jumlah_struk
        FROM transaksi WHERE DATE(created_at) = :today
    ");
    $stmtSales->execute([':today' => $today]);
    $ringkasan = $stmtSales->fetch();

    $avgStruk = $ringkasan['jumlah_struk'] > 0
        ? (int)($ringkasan['total_sales'] / $ringkasan['jumlah_struk'])
        : 0;

    $stmtStok = $pdo->query("
        SELECT id, nama, stok, stok_minimum, kategori
        FROM produk
        WHERE stok <= stok_minimum AND status = 'aktif'
        ORDER BY (stok / stok_minimum) ASC
    ");
    $produkStokLimit = $stmtStok->fetchAll();

    $stmtStruk = $pdo->query("
        SELECT t.id, t.invoice, t.total, t.bayar, t.kembalian, t.created_at,
               u.nama AS kasir
        FROM transaksi t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC LIMIT 5
    ");
    $strukTerakhir = $stmtStruk->fetchAll();

    $stmtFast = $pdo->prepare("
        SELECT td.nama, SUM(td.qty) AS total_qty
        FROM transaksi_detail td
        JOIN transaksi t ON td.transaksi_id = t.id
        WHERE DATE(t.created_at) = :today
        GROUP BY td.produk_id, td.nama
        ORDER BY total_qty DESC LIMIT 5
    ");
    $stmtFast->execute([':today' => $today]);
    $fastMoving = $stmtFast->fetchAll();

    $stmtJam = $pdo->prepare("
        SELECT HOUR(created_at) AS jam, COALESCE(SUM(total), 0) AS sales
        FROM transaksi WHERE DATE(created_at) = :today
        GROUP BY HOUR(created_at) ORDER BY jam ASC
    ");
    $stmtJam->execute([':today' => $today]);
    $salesPerJam = $stmtJam->fetchAll();

    $chartLabels = [];
    $chartData   = [];
    for ($h = 6; $h <= 22; $h++) {
        $chartLabels[] = str_pad($h, 2, '0', STR_PAD_LEFT);
        $found = false;
        foreach ($salesPerJam as $row) {
            if ((int)$row['jam'] === $h) {
                $chartData[] = round($row['sales'] / 1000000, 2);
                $found = true;
                break;
            }
        }
        if (!$found) $chartData[] = 0;
    }
}

// ── Data: Rental ─────────────────────────────────────────────────────────────
if (has_role('rental', 'admin')) {

    // Jumlah order rental hari ini
    // Sesuaikan nama tabel/kolom dengan skema database Anda
    try {
        $stmtRental = $pdo->prepare("
            SELECT COUNT(*) AS total_order,
                   COALESCE(SUM(total_bayar), 0) AS total_pendapatan
            FROM rental_bandara
            WHERE DATE(created_at) = :today
        ");
        $stmtRental->execute([':today' => $today]);
        $ringkasanRental = $stmtRental->fetch();
    } catch (Exception $e) {
        $ringkasanRental = ['total_order' => 0, 'total_pendapatan' => 0];
    }

    // Order rental terbaru
    try {
        $stmtOrderRental = $pdo->prepare("
            SELECT r.*, d.nama AS nama_driver
            FROM rental_bandara r
            LEFT JOIN driver d ON r.driver_id = d.id
            ORDER BY r.created_at DESC LIMIT 5
        ");
        $stmtOrderRental->execute();
        $orderRentalTerbaru = $stmtOrderRental->fetchAll();
    } catch (Exception $e) {
        $orderRentalTerbaru = [];
    }

    // Jumlah driver aktif
    try {
        $stmtDriver = $pdo->query("SELECT COUNT(*) AS total FROM driver WHERE status = 'aktif'");
        $totalDriver = (int)$stmtDriver->fetchColumn();
    } catch (Exception $e) {
        $totalDriver = 0;
    }
}


// ── Data: KSP / Simpan Pinjam ───────────────────────────────────────────────
if (has_role('ksp', 'admin')) {
    try {
        $stmtKspStatus = $pdo->query("
            SELECT status, COUNT(*) AS total, COALESCE(SUM(jumlah), 0) AS nilai
            FROM pengajuan_pinjaman
            GROUP BY status
        ");
        foreach ($stmtKspStatus->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $st = strtolower(trim((string)($row['status'] ?? '')));
            if ($st === '') {
                $st = 'dibatalkan';
            }

            if (array_key_exists($st, $kspPengajuanSummary)) {
                $kspPengajuanSummary[$st] = (int)$row['total'];
            }

            $kspTotalPengajuanNilai += (float)($row['nilai'] ?? 0);
        }
    } catch (Throwable $e) {
        $kspPengajuanSummary = ['pending' => 0, 'diseleksi' => 0, 'disetujui' => 0, 'ditolak' => 0, 'dibatalkan' => 0, 'dicairkan' => 0];
        $kspTotalPengajuanNilai = 0;
    }

    try {
        $stmtKspAktif = $pdo->query("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(pokok), 0) AS pokok,
                   COALESCE(SUM(angsuran_total), 0) AS angsuran_bulanan
            FROM pinjaman
            WHERE status IN ('aktif', 'berjalan')
        ");
        $kspPinjamanAktif = $stmtKspAktif->fetch(PDO::FETCH_ASSOC) ?: $kspPinjamanAktif;
    } catch (Throwable $e) {
        $kspPinjamanAktif = ['total' => 0, 'pokok' => 0, 'angsuran_bulanan' => 0];
    }

    try {
        $stmtKspJatuh = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(jumlah_total), 0) AS nilai
            FROM angsuran_pinjaman
            WHERE status IN ('belum_bayar', 'pending', 'menunggak')
              AND jatuh_tempo <= DATE_ADD(:today, INTERVAL 7 DAY)
        ");
        $stmtKspJatuh->execute([':today' => $today]);
        $kspAngsuranJatuhTempo = $stmtKspJatuh->fetch(PDO::FETCH_ASSOC) ?: $kspAngsuranJatuhTempo;
    } catch (Throwable $e) {
        $kspAngsuranJatuhTempo = ['total' => 0, 'nilai' => 0];
    }

    try {
        $stmtKspTerbaru = $pdo->query("
            SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode
            FROM pengajuan_pinjaman pp
            LEFT JOIN member m ON m.id = pp.member_id
            ORDER BY FIELD(COALESCE(NULLIF(pp.status, ''), 'dibatalkan'), 'pending', 'diseleksi', 'disetujui', 'ditolak', 'dibatalkan', 'dicairkan'), pp.created_at DESC, pp.id DESC
            LIMIT 6
        ");
        $kspPengajuanTerbaru = $stmtKspTerbaru->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kspPengajuanTerbaru = [];
    }

    try {
        $rowKspKonfig = $pdo->query("
            SELECT bunga_uang, bunga_barang, tenor_maks_uang, tenor_maks_barang
            FROM konfigurasi_sp
            ORDER BY id ASC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($rowKspKonfig) {
            $kspKonfig = array_merge($kspKonfig, $rowKspKonfig);
        }
    } catch (Throwable $e) {
        $kspKonfig = ['bunga_uang' => 0, 'bunga_barang' => 0, 'tenor_maks_uang' => 0, 'tenor_maks_barang' => 0];
    }
}

$title = 'Dashboard - ' . ($_SESSION['nama'] ?? 'SEJAHUB');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff;
            color: #111827;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        @media (min-width: 1024px) {
            .chart-container {
                height: 280px;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .dashboard-role-section {
            margin-top: 56px;
            padding-top: 48px;
            border-top: 1px solid #f0f0f0;
        }

        .dashboard-role-section:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .dashboard-section-title {
            margin-bottom: 24px;
        }

        .dashboard-kpi-block {
            margin-bottom: 56px;
        }

        @media (max-width: 768px) {
            .dashboard-role-section {
                margin-top: 40px;
                padding-top: 32px;
            }

            .dashboard-kpi-block {
                margin-bottom: 40px;
            }
        }


        /* Mobile dashboard action buttons */
        .dashboard-action-wrap {
            display: flex;
            gap: 8px;
            align-items: stretch;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .dashboard-action-btn {
            min-width: 170px;
            height: 56px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
            line-height: 1.15;
            white-space: normal;
        }

        .dashboard-action-btn svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        @media (max-width: 640px) {
            .dashboard-action-wrap {
                display: grid;
                grid-template-columns: 1fr;
                gap: 6px;
                overflow: visible;
                width: 100%;
            }

            .dashboard-action-btn {
                width: 100%;
                min-width: 0;
                height: 42px;
                padding: 0 10px;
                font-size: 9px;
                letter-spacing: .04em;
            }
        }
    </style>
</head>

<body class="antialiased pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-5 md:p-8 lg:p-12">

        <!-- ── Header ──────────────────────────────────────────────────────────── -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 md:mb-12 gap-4">
            <div>
                <?php if (has_role('admin', 'kasir')): ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">
                        Shift 01 &ndash; <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </h1>
                <?php else: ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">
                        Selamat datang, <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </h1>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-1">
                    <?php echo date('l, d F Y', strtotime($today)); ?> | <?php echo date('H:i'); ?> WIB
                </p>
            </div>

            <!-- Tombol aksi sesuai role -->
            <div class="dashboard-action-wrap w-full md:w-auto">
                <?php if (has_role('admin', 'kasir')): ?>
                    <a href="pos.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>MESIN KASIR <span class="opacity-70">(POS)</span></span>
                    </a>
                <?php endif; ?>
                <?php if (has_role('admin', 'rental')): ?>
                    <a href="rental_bandara.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>RENTAL<span class="hidden sm:inline"> </span><br class="sm:hidden">BANDARA</span>
                    </a>
                <?php endif; ?>
                <?php if (has_role('admin', 'ksp')): ?>
                    <a href="pinjaman.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>PENGAJUAN<span class="hidden sm:inline"> </span><br class="sm:hidden">PINJAMAN</span>
                    </a>
                <?php endif; ?>
            </div>
        </header>


        <?php if (has_role('admin', 'kasir')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN ADMIN & KASIR                                                -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <!-- KPI Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 md:gap-8 dashboard-kpi-block border-b border-subtle pb-10 md:pb-14">

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Sales (Nett)</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo formatRp($ringkasan['total_sales']); ?>
                        </p>
                        <span class="text-[10px] font-bold text-gray-400 bg-gray-50 px-2 py-0.5 mt-2 inline-block italic">Target: Rp 12Jt</span>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jumlah Struk</p>
                        <p class="text-2xl md:text-3xl font-medium">
                            <?php echo number_format($ringkasan['jumlah_struk']); ?>
                            <span class="text-sm text-gray-300 font-bold">INV</span>
                        </p>
                        <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 mt-2 inline-block">
                            Avg: <?php echo formatRp($avgStruk); ?>
                        </span>
                    </div>

                    <div class="py-2 sm:col-span-2 md:col-span-1">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo count($produkStokLimit); ?>
                            <span class="text-sm text-red-200 uppercase font-bold">SKU</span>
                        </p>
                        <?php if (count($produkStokLimit) > 0): ?>
                            <a href="#stok-limit" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block underline cursor-pointer">Cek Detail</a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Chart + Stok Limit -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mb-8 md:mb-12">

                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-widest">Realisasi Sales (Per Jam)</h3>
                                <p class="text-[10px] text-gray-400 italic mt-1">*Satuan dalam Juta Rupiah</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <div class="lg:col-span-1" id="stok-limit">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-red-600 mb-6">
                            Peringatan Stok Limit
                            <?php if (count($produkStokLimit) === 0): ?>
                                <span class="text-gray-400 normal-case font-medium">(aman)</span>
                            <?php endif; ?>
                        </h3>

                        <?php if (count($produkStokLimit) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach (array_slice($produkStokLimit, 0, 4) as $p): ?>
                                    <?php $pct = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / $p['stok_minimum'] * 100)) : 0; ?>
                                    <div class="p-3 border border-red-100 bg-red-50 rounded-sm">
                                        <div class="flex justify-between items-start mb-1">
                                            <span class="text-sm font-bold block leading-tight"><?php echo htmlspecialchars($p['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="text-[10px] font-black text-red-600 uppercase">Sisa <?php echo $p['stok']; ?></span>
                                        </div>
                                        <p class="text-[9px] text-gray-400 uppercase mb-2">
                                            <?php echo htmlspecialchars($p['kategori'], ENT_QUOTES, 'UTF-8'); ?> | Min: <?php echo $p['stok_minimum']; ?>
                                        </p>
                                        <div class="w-full bg-gray-100 h-1">
                                            <div class="bg-red-500 h-1" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-4 bg-green-50 border border-green-100 rounded-sm text-center">
                                <p class="text-xs font-bold text-green-600">Semua stok dalam kondisi aman &#10003;</p>
                            </div>
                        <?php endif; ?>

                        <?php if (has_role('admin', 'kasir')): ?>
                            <a href="buat_po.php">
                                <button type="button" class="mt-4 w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                                    BUAT PO BARANG
                                </button>
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Struk Terakhir + Fast Moving -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mt-12 md:mt-20">

                    <div class="lg:col-span-2">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Struk Terakhir</h3>

                        <?php if (empty($strukTerakhir)): ?>
                            <div class="p-6 border border-gray-100 rounded-sm text-center">
                                <p class="text-xs text-gray-400">Belum ada transaksi hari ini</p>
                            </div>
                        <?php else: ?>

                            <!-- Desktop -->
                            <div class="hidden lg:block overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-black">
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">ID Struk</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Kasir</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Total</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Opsi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($strukTerakhir as $t): ?>
                                            <tr>
                                                <td class="py-4 text-sm font-medium">
                                                    #<?php echo htmlspecialchars(substr($t['invoice'], -6), ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="text-[10px] text-gray-400 ml-2"><?php echo date('H:i', strtotime($t['created_at'])); ?></span>
                                                </td>
                                                <td class="py-4 text-sm font-medium text-gray-600">
                                                    <?php echo htmlspecialchars($t['kasir'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td class="py-4 text-sm font-medium"><?php echo formatRp($t['total']); ?></td>
                                                <td class="py-4 text-sm text-right">
                                                    <button type="button"
                                                        onclick="reprintThermal(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars($t['invoice'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                        class="text-[10px] font-bold underline hover:text-blue-600">REPRINT</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile -->
                            <div class="lg:hidden space-y-3">
                                <?php foreach ($strukTerakhir as $t): ?>
                                    <div class="border border-gray-100 rounded-sm p-4 flex items-center justify-between gap-3 hover:border-gray-300 transition-all">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-9 h-9 bg-gray-50 border border-gray-100 rounded-sm flex items-center justify-center flex-shrink-0">
                                                <span class="text-[9px] font-black text-gray-400 uppercase">INV</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold">
                                                    #<?php echo htmlspecialchars(substr($t['invoice'], -6), ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="text-[10px] text-gray-400 font-normal ml-1"><?php echo date('H:i', strtotime($t['created_at'])); ?></span>
                                                </p>
                                                <p class="text-[10px] text-gray-400 mt-0.5 truncate"><?php echo htmlspecialchars($t['kasir'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3 flex-shrink-0">
                                            <div class="text-right">
                                                <p class="text-sm font-bold"><?php echo formatRp($t['total']); ?></p>
                                                <p class="text-[10px] text-gray-400 mt-0.5">Total</p>
                                            </div>
                                            <button type="button"
                                                onclick="reprintThermal(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars($t['invoice'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                class="px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:bg-black hover:text-white hover:border-black transition-all rounded-sm">
                                                Reprint
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-1">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Top Fast Moving</h3>
                        <div class="space-y-4">
                            <?php if (empty($fastMoving)): ?>
                                <p class="text-xs text-gray-400">Belum ada data penjualan hari ini</p>
                            <?php else: ?>
                                <?php foreach ($fastMoving as $i => $fm): ?>
                                    <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                                        <div class="flex items-center gap-3">
                                            <span class="text-[10px] font-black text-gray-300 w-4"><?php echo $i + 1; ?></span>
                                            <span class="text-sm font-medium"><?php echo htmlspecialchars($fm['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <span class="text-sm font-bold text-gray-400"><?php echo number_format($fm['total_qty']); ?>x</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </section>
        <?php endif; // end has_role admin|kasir 
        ?>


        <?php if (has_role('rental', 'admin')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN RENTAL                                                       -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <!-- KPI Cards Rental -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-8 mb-8 md:mb-12 border-b border-subtle pb-8 md:pb-12">

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Order Hari Ini</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo number_format($ringkasanRental['total_order'] ?? 0); ?>
                            <span class="text-sm text-gray-300 font-bold">ORDER</span>
                        </p>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pendapatan Hari Ini</p>
                        <p class="text-2xl md:text-3xl font-medium text-green-600">
                            <?php echo formatRp($ringkasanRental['total_pendapatan'] ?? 0); ?>
                        </p>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Driver Aktif</p>
                        <p class="text-2xl md:text-3xl font-medium">
                            <?php echo number_format($totalDriver ?? 0); ?>
                            <span class="text-sm text-gray-300 font-bold">DRIVER</span>
                        </p>
                        <a href="driver.php" class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 mt-2 inline-block">
                            Kelola Driver
                        </a>
                    </div>

                </div>

                <!-- Order Rental Terbaru -->
                <div class="mt-4">
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Order Rental Terbaru</h3>

                    <?php if (empty($orderRentalTerbaru)): ?>
                        <div class="p-6 border border-gray-100 rounded-sm text-center">
                            <p class="text-xs text-gray-400">Belum ada order rental hari ini</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($orderRentalTerbaru as $r): ?>
                                <div class="border border-gray-100 rounded-sm p-4 flex items-center justify-between gap-3 hover:border-gray-300 transition-all">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold truncate">
                                            <?php echo htmlspecialchars($r['nama_penumpang'] ?? $r['nama'] ?? 'Penumpang', ENT_QUOTES, 'UTF-8'); ?>
                                        </p>
                                        <p class="text-[10px] text-gray-400 mt-0.5">
                                            Driver: <?php echo htmlspecialchars($r['nama_driver'] ?? 'Belum assign', ENT_QUOTES, 'UTF-8'); ?>
                                            &bull; <?php echo date('H:i', strtotime($r['created_at'] ?? 'now')); ?>
                                        </p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-sm font-bold"><?php echo formatRp($r['total_bayar'] ?? 0); ?></p>
                                        <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-sm
                                <?php echo ($r['status'] ?? '') === 'selesai' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                            <?php echo htmlspecialchars($r['status'] ?? 'proses', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <a href="rental_bandara.php">
                        <button type="button" class="mt-6 w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                            LIHAT SEMUA ORDER RENTAL
                        </button>
                    </a>
                </div>

            </section>
        <?php endif; // end has_role rental 
        ?>


        <?php if (has_role('ksp', 'admin')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN KSP / SIMPAN PINJAM                                          -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-6 md:gap-8 mb-8 md:mb-12 border-b border-subtle pb-8 md:pb-12">

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pengajuan Pending</p>
                        <p class="text-2xl md:text-3xl font-medium text-amber-600">
                            <?php echo number_format((int)$kspPengajuanSummary['pending']); ?>
                            <span class="text-sm text-amber-200 font-bold">REQ</span>
                        </p>
                        <a href="pinjaman.php?status=pending" class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 mt-2 inline-block">Proses Pengajuan</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Disetujui Belum Cair</p>
                        <p class="text-2xl md:text-3xl font-medium text-green-600">
                            <?php echo number_format((int)$kspPengajuanSummary['disetujui']); ?>
                            <span class="text-sm text-green-200 font-bold">ACC</span>
                        </p>
                        <a href="pinjaman.php?status=disetujui" class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 mt-2 inline-block">Cairkan</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Batal Pengajuan</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo number_format((int)$kspPengajuanSummary['dibatalkan']); ?>
                            <span class="text-sm text-red-200 font-bold">BTL</span>
                        </p>
                        <a href="pinjaman.php?status=dibatalkan" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block">Lihat Batal</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pinjaman Aktif</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo number_format((int)($kspPinjamanAktif['total'] ?? 0)); ?>
                            <span class="text-sm text-blue-200 font-bold">AKTIF</span>
                        </p>
                        <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 mt-2 inline-block">
                            <?php echo formatRp($kspPinjamanAktif['pokok'] ?? 0); ?>
                        </span>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jatuh Tempo 7 Hari</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo number_format((int)($kspAngsuranJatuhTempo['total'] ?? 0)); ?>
                            <span class="text-sm text-red-200 font-bold">ANGS</span>
                        </p>
                        <span class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block">
                            <?php echo formatRp($kspAngsuranJatuhTempo['nilai'] ?? 0); ?>
                        </span>
                    </div>

                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mb-8 md:mb-12">

                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-widest">Pengajuan Terbaru</h3>
                                <p class="text-[10px] text-gray-400 italic mt-1">Data terbaru dari member yang mengajukan pinjaman</p>
                            </div>
                            <a href="pinjaman.php" class="text-[10px] font-black uppercase tracking-widest underline">Lihat Semua</a>
                        </div>

                        <?php if (empty($kspPengajuanTerbaru)): ?>
                            <div class="p-6 border border-gray-100 rounded-sm text-center">
                                <p class="text-xs text-gray-400">Belum ada pengajuan pinjaman</p>
                            </div>
                        <?php else: ?>
                            <div class="hidden lg:block overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-black">
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Member</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Jenis</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Jumlah</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-center">Tenor</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($kspPengajuanTerbaru as $p): ?>
                                            <tr>
                                                <td class="py-4 text-sm font-medium">
                                                    <?php echo htmlspecialchars($p['member_nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="block text-[10px] text-gray-400 font-mono"><?php echo htmlspecialchars($p['member_kode'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                                </td>
                                                <td class="py-4 text-sm font-bold uppercase"><?php echo htmlspecialchars($p['jenis'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="py-4 text-sm font-bold text-right"><?php echo formatRp($p['jumlah'] ?? 0); ?></td>
                                                <td class="py-4 text-sm text-center"><?php echo number_format((int)($p['tenor'] ?? 0)); ?> bln</td>
                                                <td class="py-4 text-right">
                                                    <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo dashboard_pinjaman_status_class((string)($p['status'] ?? '')); ?>">
                                                        <?php echo htmlspecialchars(strtoupper(dashboard_pinjaman_status_label((string)($p['status'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="lg:hidden space-y-3">
                                <?php foreach ($kspPengajuanTerbaru as $p): ?>
                                    <div class="border border-gray-100 rounded-sm p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-bold"><?php echo htmlspecialchars($p['member_nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo htmlspecialchars($p['member_kode'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo dashboard_pinjaman_status_class((string)($p['status'] ?? '')); ?>">
                                                <?php echo htmlspecialchars(strtoupper(dashboard_pinjaman_status_label((string)($p['status'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 mt-4">
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Jenis</p>
                                                <p class="text-xs font-bold uppercase"><?php echo htmlspecialchars($p['jenis'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Jumlah</p>
                                                <p class="text-xs font-bold"><?php echo formatRp($p['jumlah'] ?? 0); ?></p>
                                            </div>
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Tenor</p>
                                                <p class="text-xs font-bold"><?php echo number_format((int)($p['tenor'] ?? 0)); ?> bln</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-1">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Konfigurasi SP Aktif</h3>
                        <div class="space-y-4">
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Uang</p>
                                <p class="text-2xl font-medium text-green-600"><?php echo number_format((float)($kspKonfig['bunga_uang'] ?? 0), 2, ',', '.'); ?>%</p>
                            </div>
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Barang</p>
                                <p class="text-2xl font-medium text-blue-600"><?php echo number_format((float)($kspKonfig['bunga_barang'] ?? 0), 2, ',', '.'); ?>%</p>
                            </div>
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tenor Maksimal</p>
                                <p class="text-sm font-bold">Uang <?php echo number_format((int)($kspKonfig['tenor_maks_uang'] ?? 0)); ?> bln · Barang <?php echo number_format((int)($kspKonfig['tenor_maks_barang'] ?? 0)); ?> bln</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-2 mt-4">
                            <a href="pinjaman.php" class="w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm text-center">
                                KELOLA PENGAJUAN
                            </a>
                            <a href="sp.php" class="w-full py-3 text-[10px] font-bold border border-gray-200 uppercase tracking-widest hover:bg-gray-50 transition-all rounded-sm text-center">
                                KONFIGURASI SP
                            </a>
                            <a href="laporan.php" class="w-full py-3 text-[10px] font-bold border border-gray-200 uppercase tracking-widest hover:bg-gray-50 transition-all rounded-sm text-center">
                                LAPORAN SP
                            </a>
                        </div>
                    </div>

                </div>

            </section>
        <?php endif; // end has_role ksp 
        ?>

    </main>

    <!-- ── Reprint Toast (hanya untuk admin & kasir) ─────────────────────────── -->
    <?php if (has_role('admin', 'kasir')): ?>
        <div id="reprint-status" class="fixed bottom-24 right-4 left-4 md:left-auto md:w-96 bg-white border border-gray-200 shadow-2xl z-[120] p-4 rounded-sm hidden">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Thermal Reprint</p>
                    <p id="reprint-status-text" class="text-sm font-bold mt-1">Menyiapkan struk...</p>
                </div>
                <button type="button" onclick="hideReprintStatus()" class="text-xs font-black text-gray-400 hover:text-black">&#10005;</button>
            </div>
            <div class="mt-3 flex gap-2">
                <button id="reprint-fallback-btn" type="button" onclick="openLastReceiptFallback()" class="hidden flex-1 py-2 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50">Buka Struk</button>
                <button type="button" onclick="hideReprintStatus()" class="flex-1 py-2 text-[10px] font-black uppercase bg-black text-white hover:bg-gray-800">Tutup</button>
            </div>
        </div>
    <?php endif; ?>

    <script>
        <?php if (has_role('admin', 'kasir')): ?>
                // ── Chart ────────────────────────────────────────────────────────────────────
                (function() {
                    var ctx = document.getElementById('salesChart').getContext('2d');
                    var isMobile = window.innerWidth < 768;
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($chartLabels); ?>,
                            datasets: [{
                                label: 'Sales (Jt)',
                                data: <?php echo json_encode($chartData); ?>,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37,99,235,0.05)',
                                fill: true,
                                tension: 0.4,
                                borderWidth: isMobile ? 1.5 : 2,
                                pointRadius: isMobile ? 2 : 4,
                                pointBackgroundColor: '#2563eb'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        borderDash: [5, 5],
                                        color: '#f0f0f0'
                                    },
                                    ticks: {
                                        font: {
                                            size: 9
                                        },
                                        color: '#999',
                                        callback: v => 'Rp' + v + 'M'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        font: {
                                            size: 9,
                                            weight: '700'
                                        },
                                        color: '#999'
                                    }
                                }
                            }
                        }
                    });
                }());

            // ── Bluetooth Thermal Reprint ─────────────────────────────────────────────────
            var BT_PRINTER_CONFIG = [{
                    service: '000018f0-0000-1000-8000-00805f9b34fb',
                    characteristic: '00002af1-0000-1000-8000-00805f9b34fb'
                },
                {
                    service: '49535343-fe7d-4ae5-8fa9-9fafd205e455',
                    characteristic: '49535343-8841-43f4-a8d4-ecbe34729bb3'
                },
                {
                    service: '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
                    characteristic: '6e400002-b5a3-f393-e0a9-e50e24dcca9e'
                }
            ];
            var ESC_BYTE = 0x1B,
                GS_BYTE = 0x1D;
            var ESCPOS = {
                init: [ESC_BYTE, 0x40],
                alignLeft: [ESC_BYTE, 0x61, 0x00],
                alignCenter: [ESC_BYTE, 0x61, 0x01],
                boldOn: [ESC_BYTE, 0x45, 0x01],
                boldOff: [ESC_BYTE, 0x45, 0x00],
                fontBig: [GS_BYTE, 0x21, 0x11],
                fontNormal: [GS_BYTE, 0x21, 0x00],
                feed: function(n) {
                    return [ESC_BYTE, 0x64, n];
                },
                cut: [GS_BYTE, 0x56, 0x41, 0x03]
            };
            var PRINT_W = 32,
                lastReprintFallbackUrl = '#';

            function showReprintStatus(msg, type) {
                type = type || 'info';
                var box = document.getElementById('reprint-status');
                var txt = document.getElementById('reprint-status-text');
                if (!box || !txt) return;
                txt.textContent = msg;
                txt.className = 'text-sm font-bold mt-1 ' + (type === 'error' ? 'text-red-600' : type === 'success' ? 'text-green-600' : 'text-gray-900');
                box.classList.remove('hidden');
            }

            function hideReprintStatus() {
                var b = document.getElementById('reprint-status');
                if (b) b.classList.add('hidden');
            }

            function setFallbackVisible(show) {
                var b = document.getElementById('reprint-fallback-btn');
                if (b) b.classList.toggle('hidden', !show);
            }

            function openLastReceiptFallback() {
                if (lastReprintFallbackUrl !== '#') window.open(lastReprintFallbackUrl, '_blank');
            }

            function _fmt(n) {
                return Number(n || 0).toLocaleString('id-ID');
            }

            function _lr(l, r, w) {
                var W = w || PRINT_W,
                    ls = String(l || ''),
                    rs = String(r || ''),
                    sp = Math.max(1, W - ls.length - rs.length);
                return ls + ' '.repeat(sp) + rs;
            }

            function _dash() {
                return '-'.repeat(PRINT_W);
            }

            function _solid() {
                return '='.repeat(PRINT_W);
            }

            function _enc(str) {
                var out = [];
                str = String(str || '');
                for (var i = 0; i < str.length; i++) {
                    var c = str.charCodeAt(i);
                    out.push(c < 256 ? c : 0x3F);
                }
                return out;
            }

            function buildEscPos(d) {
                var buf = [];

                function push(a) {
                    for (var i = 0; i < a.length; i++) buf.push(a[i]);
                }

                function text(s) {
                    push(_enc(String(s || '') + '\n'));
                }

                function line(s) {
                    push(_enc(String(s || '')));
                }
                push(ESCPOS.init);
                push(ESCPOS.alignCenter);
                push(ESCPOS.boldOn);
                push(ESCPOS.fontBig);
                text('KOPERASI BSDK');
                push(ESCPOS.fontNormal);
                push(ESCPOS.boldOff);
                text('MESIN KASIR / POS');
                text('REPRINT STRUK');
                push(ESCPOS.alignLeft);
                line(_dash() + '\n');
                line(_lr('No', d.invoice) + '\n');
                line(_lr('Tgl', d.tanggal) + '\n');
                line(_lr('Kasir', String(d.operator || '').substring(0, 18)) + '\n');
                if (d.member_nama) {
                    line(_lr('Member', String(d.member_nama).substring(0, 18)) + '\n');
                    if (d.member_kode) line(_lr('Kode', String(d.member_kode)) + '\n');
                }
                line(_dash() + '\n');
                (d.items || []).forEach(function(item) {
                    push(ESCPOS.boldOn);
                    text(String(item.nama || 'PRODUK').substring(0, 32));
                    push(ESCPOS.boldOff);
                    line(_lr((item.qty || 0) + ' x ' + _fmt(item.harga_normal), _fmt(item.normal_item)) + '\n');
                    if (Number(item.diskon_item || 0) > 0) {
                        if (item.nama_diskon) text('Promo: ' + String(item.nama_diskon).substring(0, 26));
                        line(_lr('Disc/pcs ' + _fmt(item.diskon_satuan) + ' x ' + item.qty, '-' + _fmt(item.diskon_item)) + '\n');
                        push(ESCPOS.boldOn);
                        line(_lr('Subtotal', _fmt(item.subtotal_item)) + '\n');
                        push(ESCPOS.boldOff);
                    }
                });
                line(_dash() + '\n');
                line(_lr('SUBTOTAL', _fmt(d.subtotal_normal)) + '\n');
                if (Number(d.diskon_barang || 0) > 0) line(_lr('DISKON BARANG', '-' + _fmt(d.diskon_barang)) + '\n');
                if (Number(d.diskon_transaksi || 0) > 0) {
                    line(_lr('DISKON PROMO', '-' + _fmt(d.diskon_transaksi)) + '\n');
                    if (d.nama_diskon_trx) text('Promo: ' + String(d.nama_diskon_trx).substring(0, 26));
                }
                if (Number(d.point_dipakai || 0) > 0) {
                    line(_lr('POINT DIPAKAI', '-' + d.point_dipakai + ' pt') + '\n');
                    if (Number(d.nilai_point || 0) > 0) line(_lr('NILAI POINT', '-' + _fmt(d.nilai_point)) + '\n');
                }
                if (Number(d.total_diskon || 0) > 0) {
                    push(ESCPOS.boldOn);
                    line(_lr('TOTAL DISKON', '-' + _fmt(d.total_diskon)) + '\n');
                    push(ESCPOS.boldOff);
                }
                line(_solid() + '\n');
                push(ESCPOS.boldOn);
                line(_lr('TOTAL BAYAR', _fmt(d.total_bayar)) + '\n');
                push(ESCPOS.boldOff);
                line(_lr('TUNAI/QRIS', _fmt(d.bayar)) + '\n');
                line(_lr('KEMBALI', _fmt(d.kembalian)) + '\n');
                if (d.member_nama && Number(d.point_dapat || 0) > 0) {
                    line(_dash() + '\n');
                    push(ESCPOS.boldOn);
                    text('POINT MEMBER');
                    push(ESCPOS.boldOff);
                    line(_lr('Point Didapat', '+' + _fmt(d.point_dapat) + ' pt') + '\n');
                    if (d.member_point_total) line(_lr('Total Point', _fmt(d.member_point_total) + ' pt') + '\n');
                }
                line(_dash() + '\n');
                push(ESCPOS.alignCenter);
                text('BARANG YANG SUDAH DIBELI');
                text('TIDAK DAPAT DITUKAR/DIKEMBALIKAN');
                text('');
                text('*** TERIMA KASIH ***');
                push(ESCPOS.alignLeft);
                push(ESCPOS.feed(5));
                push(ESCPOS.cut);
                return new Uint8Array(buf);
            }

            function btSendData(ch, data) {
                var C = 100,
                    chain = Promise.resolve();
                for (var p = 0; p < data.length; p += C) {
                    (function(s) {
                        chain = chain.then(function() {
                            return ch.writeValueWithoutResponse(s);
                        }).then(function() {
                            return new Promise(function(r) {
                                setTimeout(r, 60);
                            });
                        });
                    }(data.slice(p, p + C)));
                }
                return chain;
            }

            function btConnectAndPrint(device, data) {
                return device.gatt.connect().then(function(server) {
                    function t(i) {
                        if (i >= BT_PRINTER_CONFIG.length) return Promise.reject(new Error('UUID printer tidak cocok.'));
                        return server.getPrimaryService(BT_PRINTER_CONFIG[i].service).then(function(s) {
                            return s.getCharacteristic(BT_PRINTER_CONFIG[i].characteristic);
                        }).catch(function() {
                            return t(i + 1);
                        });
                    }
                    return t(0).then(function(c) {
                        return btSendData(c, data).then(function() {
                            try {
                                server.disconnect();
                            } catch (e) {}
                        });
                    });
                });
            }

            async function reprintThermal(id, invoice) {
                lastReprintFallbackUrl = 'struk.php?invoice=' + encodeURIComponent(invoice || '') + '&print=1';
                setFallbackVisible(false);
                showReprintStatus('Mengambil data struk...', 'info');
                try {
                    var res = await fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>?action=reprint_data&id=' + encodeURIComponent(id), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    var json = await res.json();
                    if (!json.success) throw new Error(json.message || 'Data struk tidak ditemukan.');
                    var data = json.data;
                    if (data.invoice) lastReprintFallbackUrl = 'struk.php?invoice=' + encodeURIComponent(data.invoice) + '&print=1';
                    if (!navigator.bluetooth) {
                        setFallbackVisible(true);
                        throw new Error('Web Bluetooth tidak tersedia.');
                    }
                    showReprintStatus('Pilih printer Bluetooth...', 'info');
                    var escData = buildEscPos(data);
                    var device = await navigator.bluetooth.requestDevice({
                        acceptAllDevices: true,
                        optionalServices: BT_PRINTER_CONFIG.map(function(c) {
                            return c.service;
                        })
                    });
                    showReprintStatus('Menghubungkan ke printer...', 'info');
                    await btConnectAndPrint(device, escData);
                    showReprintStatus('Struk berhasil dicetak ulang.', 'success');
                } catch (err) {
                    var msg = (err && err.name === 'NotFoundError') ? 'Tidak ada printer yang dipilih.' : (err.message || 'Gagal reprint struk.');
                    setFallbackVisible(true);
                    showReprintStatus(msg, (err && err.name === 'NotFoundError') ? 'info' : 'error');
                }
            }
        <?php endif; // end JS untuk admin|kasir 
        ?>
    </script>

</body>

</html>