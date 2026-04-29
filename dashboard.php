<?php
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php'); // balik ke login
    exit;
}


// ── Data: Ringkasan Shift Hari Ini ──────────────────────────────────────────
$today = date('Y-m-d');

// Total Sales & Jumlah Struk hari ini
$stmtSales = $pdo->prepare("
    SELECT 
        COALESCE(SUM(total), 0)  AS total_sales,
        COUNT(*)                 AS jumlah_struk
    FROM transaksi
    WHERE DATE(created_at) = :today
");
$stmtSales->execute([':today' => $today]);
$ringkasan = $stmtSales->fetch();

// Avg per struk
$avgStruk = $ringkasan['jumlah_struk'] > 0
    ? (int)($ringkasan['total_sales'] / $ringkasan['jumlah_struk'])
    : 0;

// Produk stok limit (stok <= stok_minimum)
$stmtStok = $pdo->query("
    SELECT id, nama, stok, stok_minimum, kategori
    FROM produk
    WHERE stok <= stok_minimum AND status = 'aktif'
    ORDER BY (stok / stok_minimum) ASC
");
$produkStokLimit = $stmtStok->fetchAll();

// Struk Terakhir (5 terbaru)
$stmtStruk = $pdo->query("
    SELECT t.id, t.invoice, t.total, t.bayar, t.kembalian, t.created_at,
           u.nama AS kasir
    FROM transaksi t
    LEFT JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
    LIMIT 5
");
$strukTerakhir = $stmtStruk->fetchAll();

// Top Fast Moving hari ini
$stmtFast = $pdo->prepare("
    SELECT td.nama, SUM(td.qty) AS total_qty
    FROM transaksi_detail td
    JOIN transaksi t ON td.transaksi_id = t.id
    WHERE DATE(t.created_at) = :today
    GROUP BY td.produk_id, td.nama
    ORDER BY total_qty DESC
    LIMIT 5
");
$stmtFast->execute([':today' => $today]);
$fastMoving = $stmtFast->fetchAll();

// Sales per jam (untuk chart)
$stmtJam = $pdo->prepare("
    SELECT HOUR(created_at) AS jam, COALESCE(SUM(total), 0) AS sales
    FROM transaksi
    WHERE DATE(created_at) = :today
    GROUP BY HOUR(created_at)
    ORDER BY jam ASC
");
$stmtJam->execute([':today' => $today]);
$salesPerJam = $stmtJam->fetchAll();

// Format data chart
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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Retail – <?= htmlspecialchars($_SESSION['nama']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #ffffff;
            color: #1a1a1a;
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
            .sidebar {
                width: 220px;
            }

            .content {
                margin-left: 220px;
            }

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

        #mobileMenuOverlay {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        #mobileMenuContent {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style>
</head>

<body class="antialiased pb-20 lg:pb-0">

    <!-- Mobile Menu Overlay -->
    <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[100] opacity-0 invisible flex justify-end lg:hidden">
        <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
            <div class="flex justify-between items-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
                <button onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100 rounded-sm transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="space-y-8 flex-1">
                <a href="index.php" class="block text-sm font-bold text-black uppercase tracking-widest">Dashboard</a>
                <a href="pos.php" class="block text-sm font-medium text-blue-600 uppercase tracking-widest">Mesin Kasir (POS)</a>
                <!-- <a href="#" class="block text-sm font-medium text-gray-400 uppercase tracking-widest">Laporan Shift</a> -->
                <a href="produk.php" class="block text-sm font-medium text-gray-400 uppercase tracking-widest">Kelola Produk</a>
                <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">
                    Kelola Diskon
                </a>

                <!-- LOGOUT -->
                <a href="logout.php"
                    onclick="return confirm('Yakin mau logout?')"
                    class="block text-sm font-bold text-red-500 uppercase tracking-widest">
                    Logout
                </a>
            </nav>
            <div class="pt-8 border-t border-subtle">
                <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
                <p class="text-[10px] text-gray-400 font-medium">Login: <?= htmlspecialchars($_SESSION['nama']) ?></p>
            </div>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12">
            <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">BSDK SEJAHTERA</span>
        </div>
        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-semibold text-black uppercase tracking-widest">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-blue-600 hover:font-bold uppercase tracking-widest transition-all flex items-center gap-2">
                <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                Mesin Kasir (POS)
            </a>
            <!-- <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Shift</a> -->
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
            <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">
                Kelola Diskon
            </a>
            <!-- <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Stok Opname</a>
            <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Pengaturan Toko</a> -->
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.4.0</p>

            <!-- LOGOUT -->
            <a href="logout.php"
                onclick="return confirm('Yakin mau logout?')"
                class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content p-5 md:p-8 lg:p-12">

        <!-- Header -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-end mb-8 md:mb-12 gap-4">
            <div>
                <h1 class="text-xl md:text-2xl font-light tracking-tight">
                    Shift 01 – <span class="font-semibold"><?= htmlspecialchars($_SESSION['nama']) ?></span>
                </h1>
                <p class="text-xs text-gray-400 mt-1">
                    <?= date('l, d F Y', strtotime($today)) ?> | Buka: <?= date('H:i') ?> WIB
                </p>
            </div>
            <div class="w-full md:w-auto">
                <a href="pos.php" class="inline-flex w-full md:w-auto justify-center text-xs font-bold bg-black text-white px-6 py-3 hover:bg-gray-800 transition-all items-center gap-2 rounded-sm shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    BUKA KASIR (POS)
                </a>
            </div>
        </header>

        <!-- Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 md:gap-8 mb-8 md:mb-12 border-b border-subtle pb-8 md:pb-12">
            <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Sales (Nett)</p>
                <p class="text-2xl md:text-3xl font-medium text-blue-600">
                    <?= formatRp($ringkasan['total_sales']) ?>
                </p>
                <span class="text-[10px] font-bold text-gray-400 bg-gray-50 px-2 py-0.5 mt-2 inline-block italic">
                    Target: Rp 12Jt
                </span>
            </div>
            <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jumlah Struk</p>
                <p class="text-2xl md:text-3xl font-medium">
                    <?= number_format($ringkasan['jumlah_struk']) ?>
                    <span class="text-sm text-gray-300 font-bold">INV</span>
                </p>
                <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 mt-2 inline-block">
                    Avg: <?= formatRp($avgStruk) ?>
                </span>
            </div>
            <div class="py-2 sm:col-span-2 md:col-span-1">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                <p class="text-2xl md:text-3xl font-medium text-red-600">
                    <?= count($produkStokLimit) ?>
                    <span class="text-sm text-red-200 uppercase font-bold">SKU</span>
                </p>
                <?php if (count($produkStokLimit) > 0): ?>
                    <a href="#stok-limit" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block underline cursor-pointer">
                        Cek Detail
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Charts Row -->
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

            <!-- Stok Limit -->
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
                            <div class="p-3 border border-red-100 bg-red-50/30 rounded-sm">
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-sm font-bold block leading-tight"><?= htmlspecialchars($p['nama']) ?></span>
                                    <span class="text-[10px] font-black text-red-600 uppercase">Sisa <?= $p['stok'] ?></span>
                                </div>
                                <p class="text-[9px] text-gray-400 uppercase mb-2">
                                    <?= htmlspecialchars($p['kategori']) ?> | Min: <?= $p['stok_minimum'] ?>
                                </p>
                                <div class="w-full bg-gray-100 h-1">
                                    <div class="bg-red-500 h-1" style="width:<?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="p-4 bg-green-50 border border-green-100 rounded-sm text-center">
                        <p class="text-xs font-bold text-green-600">Semua stok dalam kondisi aman ✓</p>
                    </div>
                <?php endif; ?>
                <form action="buat_po.php" method="GET">
                    <button type="submit" class="mt-4 w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                        BUAT PO BARANG
                    </button>
                </form>
            </div>
        </div>

        <!-- Table Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mt-12 md:mt-20">
            <div class="lg:col-span-2 overflow-hidden">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Struk Terakhir</h3>
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full text-left min-w-[500px]">
                        <thead>
                            <tr class="border-b border-black">
                                <th class="py-3 text-[10px] font-bold uppercase tracking-widest">ID Struk</th>
                                <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Kasir</th>
                                <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Total</th>
                                <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Opsi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-subtle">
                            <?php if (empty($strukTerakhir)): ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-xs text-gray-400">
                                        Belum ada transaksi hari ini
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($strukTerakhir as $t): ?>
                                    <tr>
                                        <td class="py-4 text-sm font-medium">
                                            #<?= htmlspecialchars(substr($t['invoice'], -6)) ?>
                                            <span class="text-[10px] text-gray-400 ml-2">
                                                <?= date('H:i', strtotime($t['created_at'])) ?>
                                            </span>
                                        </td>
                                        <td class="py-4 text-sm font-medium text-gray-600">
                                            <?= htmlspecialchars($t['kasir'] ?? 'N/A') ?>
                                        </td>
                                        <td class="py-4 text-sm font-medium"><?= formatRp($t['total']) ?></td>
                                        <td class="py-4 text-sm text-right">
                                            <a href="struk.php?id=<?= $t['id'] ?>" target="_blank"
                                                class="text-[10px] font-bold underline hover:text-blue-600">REPRINT</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="lg:col-span-1">
                <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Top Fast Moving</h3>
                <div class="space-y-4">
                    <?php if (empty($fastMoving)): ?>
                        <p class="text-xs text-gray-400">Belum ada data penjualan hari ini</p>
                    <?php else: ?>
                        <?php foreach ($fastMoving as $i => $fm): ?>
                            <div class="flex justify-between items-center pb-3 border-b border-subtle">
                                <div class="flex items-center gap-3">
                                    <span class="text-[10px] font-black text-gray-300 w-4"><?= $i + 1 ?></span>
                                    <span class="text-sm font-medium"><?= htmlspecialchars($fm['nama']) ?></span>
                                </div>
                                <span class="text-sm font-bold text-gray-400"><?= number_format($fm['total_qty']) ?>x</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18M3 6h18M3 18h18" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>
        <a href="pos.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M8 12h8" />
            </svg>
        </a>
        <a href="produk.php" class="flex flex-col items-center p-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-gray-400">Produk</span>
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
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) toggleMobileMenu();
                });
            }
        });

        const ctx = document.getElementById('salesChart').getContext('2d');
        const isMobile = window.innerWidth < 768;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Sales (Jt)',
                    data: <?= json_encode($chartData) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.05)',
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
                            callback: value => 'Rp' + value + 'M'
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
    </script>
</body>

</html>