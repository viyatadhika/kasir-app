<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| laporan.php - Laporan Keuangan POS
|--------------------------------------------------------------------------
| Fitur:
| - Filter tanggal / preset
| - Ringkasan omzet, transaksi, diskon, bayar, kembalian, point
| - Detail transaksi
| - Produk terlaris
| - Diskon terpakai
| - Tombol cetak
*/

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

function tgl(?string $v): string
{
    return $v ? date('d/m/Y', strtotime($v)) : '-';
}

function waktu(?string $v): string
{
    return $v ? date('d/m/Y H:i', strtotime($v)) : '-';
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$today = date('Y-m-d');
$preset = $_GET['preset'] ?? 'hari_ini';

if ($preset === 'minggu_ini') {
    $awal = date('Y-m-d', strtotime('monday this week'));
    $akhir = date('Y-m-d', strtotime('sunday this week'));
} elseif ($preset === 'bulan_ini') {
    $awal = date('Y-m-01');
    $akhir = date('Y-m-t');
} elseif ($preset === 'tahun_ini') {
    $awal = date('Y-01-01');
    $akhir = date('Y-12-31');
} elseif ($preset === 'custom') {
    $awal = $_GET['awal'] ?? $today;
    $akhir = $_GET['akhir'] ?? $today;
} else {
    $preset = 'hari_ini';
    $awal = $today;
    $akhir = $today;
}

$hasDiskon = hasColumn($pdo, 'transaksi', 'diskon');
$hasDiskonId = hasColumn($pdo, 'transaksi', 'diskon_id');
$hasPoint = hasColumn($pdo, 'transaksi', 'point_dapat');
$hasMember = hasColumn($pdo, 'transaksi', 'member_id');
$hasHargaNormal = hasColumn($pdo, 'transaksi_detail', 'harga_normal');
$hasDetailDiskon = hasColumn($pdo, 'transaksi_detail', 'diskon');
$hasDetailDiskonId = hasColumn($pdo, 'transaksi_detail', 'diskon_id');

try {
    $diskonSum = $hasDiskon ? "COALESCE(SUM(diskon),0)" : "0";
    $pointSum = $hasPoint ? "COALESCE(SUM(point_dapat),0)" : "0";

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_transaksi,
            COALESCE(SUM(total),0) AS omzet,
            $diskonSum AS diskon,
            COALESCE(SUM(bayar),0) AS bayar,
            COALESCE(SUM(kembalian),0) AS kembalian,
            $pointSum AS point
        FROM transaksi
        WHERE DATE(created_at) BETWEEN :awal AND :akhir
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $memberJoin = $hasMember ? "LEFT JOIN member m ON m.id=t.member_id" : "";
    $memberSelect = $hasMember ? "m.nama AS member_nama, m.kode AS member_kode" : "NULL AS member_nama, NULL AS member_kode";
    $diskonSelect = $hasDiskon ? "t.diskon" : "0 AS diskon";
    $pointSelect = $hasPoint ? "t.point_dapat" : "0 AS point_dapat";

    $stmt = $pdo->prepare("
        SELECT
            t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
            $diskonSelect, $pointSelect, $memberSelect
        FROM transaksi t
        $memberJoin
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
        ORDER BY t.created_at DESC, t.id DESC
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hargaNormal = $hasHargaNormal ? "COALESCE(td.harga_normal, td.harga)" : "td.harga";
    $diskonDetail = $hasDetailDiskon ? "COALESCE(td.diskon,0)" : "GREATEST(0, (($hargaNormal * td.qty) - td.subtotal))";

    $stmt = $pdo->prepare("
        SELECT
            td.produk_id, td.kode, td.nama,
            SUM(td.qty) AS qty,
            SUM($hargaNormal * td.qty) AS subtotal_normal,
            SUM($diskonDetail) AS diskon,
            SUM(td.subtotal) AS penjualan
        FROM transaksi_detail td
        JOIN transaksi t ON t.id=td.transaksi_id
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
        GROUP BY td.produk_id, td.kode, td.nama
        ORDER BY qty DESC, penjualan DESC
        LIMIT 20
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diskonTransaksi = [];
    if ($hasDiskon && $hasDiskonId) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Transaksi') AS nama, COUNT(t.id) AS jumlah, COALESCE(SUM(t.diskon),0) AS total
            FROM transaksi t
            LEFT JOIN diskon d ON d.id=t.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              AND COALESCE(t.diskon,0) > 0
            GROUP BY t.diskon_id, d.nama
            ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $diskonBarang = [];
    if ($hasDetailDiskon && $hasDetailDiskonId) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Barang') AS nama, COUNT(td.id) AS jumlah, COALESCE(SUM(td.diskon),0) AS total
            FROM transaksi_detail td
            JOIN transaksi t ON t.id=td.transaksi_id
            LEFT JOIN diskon d ON d.id=td.diskon_id
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
              AND COALESCE(td.diskon,0) > 0
            GROUP BY td.diskon_id, d.nama
            ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    die('Gagal memuat laporan: ' . htmlspecialchars($e->getMessage()));
}

$totalTransaksi = (int)($summary['total_transaksi'] ?? 0);
$omzet = (int)($summary['omzet'] ?? 0);
$totalDiskon = (int)($summary['diskon'] ?? 0);
$totalBayar = (int)($summary['bayar'] ?? 0);
$totalKembalian = (int)($summary['kembalian'] ?? 0);
$totalPoint = (int)($summary['point'] ?? 0);
$rata = $totalTransaksi > 0 ? floor($omzet / $totalTransaksi) : 0;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan - Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .border-subtle {
            border-color: #e5e7eb;
        }

        button,
        .btn,
        nav a,
        header a {
            border-radius: 2px !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: .08em !important;
            transition: all .15s ease !important;
        }

        @media(min-width:1024px) {
            .sidebar {
                width: 220px
            }

            .content {
                margin-left: 220px
            }
        }

        @media print {
            .no-print {
                display: none !important
            }

            body {
                background: #fff
            }

            .content {
                margin-left: 0 !important
            }

            .print-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important
            }

            table {
                font-size: 11px
            }
        }
    </style>
</head>

<body class="min-h-screen">

    <aside class="sidebar no-print hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12"><span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span></div>
        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Mesin Kasir (POS)</a>
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Produk</a>
            <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Diskon</a>
            <a href="laporan.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2"><span class="w-2 h-2 bg-black rounded-full"></span>Laporan</a>
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.4.0</p>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </aside>

    <header class="content no-print bg-white border-b border-subtle px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center z-10 shadow-sm sticky top-0">
        <div class="flex items-center gap-3 sm:gap-4">
            <a href="index.php" class="p-2 hover:bg-gray-100">←</a>
            <h1 class="text-sm font-bold tracking-[0.2em] uppercase">Laporan Keuangan</h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="pos.php" class="btn px-4 py-2 border border-subtle hover:bg-gray-50">POS</a>
            <div class="flex gap-2 flex-wrap">
                <a href="export_laporan_pdf.php?jenis=ringkasan&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="px-3 py-2 bg-blue-600 text-white text-xs">Ringkasan</a>

                <a href="export_laporan_pdf.php?jenis=transaksi&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="px-3 py-2 bg-green-600 text-white text-xs">Transaksi</a>

                <a href="export_laporan_pdf.php?jenis=produk&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="px-3 py-2 bg-purple-600 text-white text-xs">Produk</a>

                <a href="export_laporan_pdf.php?jenis=diskon&awal=<?= $awal ?>&akhir=<?= $akhir ?>" class="px-3 py-2 bg-red-600 text-white text-xs">Diskon</a>
            </div>
        </div>
    </header>

    <main class="content p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="hidden print:block text-center mb-6">
            <h1 class="text-xl font-black uppercase">Laporan Keuangan Koperasi BSDK</h1>
            <p class="text-sm">Periode <?= htmlspecialchars(tgl($awal)) ?> s/d <?= htmlspecialchars(tgl($akhir)) ?></p>
        </div>

        <section class="no-print bg-white border border-subtle print-card p-4 sm:p-6 shadow-sm">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Preset</label>
                    <select name="preset" class="w-full border border-gray-200 px-3 py-2.5 text-sm">
                        <option value="hari_ini" <?= $preset === 'hari_ini' ? 'selected' : '' ?>>Hari Ini</option>
                        <option value="minggu_ini" <?= $preset === 'minggu_ini' ? 'selected' : '' ?>>Minggu Ini</option>
                        <option value="bulan_ini" <?= $preset === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                        <option value="tahun_ini" <?= $preset === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                        <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Tanggal Awal</label>
                    <input type="date" name="awal" value="<?= htmlspecialchars($awal) ?>" class="w-full border border-gray-200 px-3 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Tanggal Akhir</label>
                    <input type="date" name="akhir" value="<?= htmlspecialchars($akhir) ?>" class="w-full border border-gray-200 px-3 py-2.5 text-sm">
                </div>
                <button type="submit" class="bg-black text-white px-4 py-3">Tampilkan</button>
                <a href="laporan.php" class="btn text-center border border-subtle px-4 py-3 hover:bg-gray-50">Reset</a>
            </form>
        </section>

        <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Omzet Bersih</p>
                <p class="text-2xl font-black text-blue-600"><?= rupiah($omzet) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Setelah diskon</p>
            </div>
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Total Transaksi</p>
                <p class="text-2xl font-black"><?= angka($totalTransaksi) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Rata-rata <?= rupiah($rata) ?></p>
            </div>
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Total Diskon</p>
                <p class="text-2xl font-black text-red-600"><?= rupiah($totalDiskon) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Promo terpakai</p>
            </div>
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Point Member</p>
                <p class="text-2xl font-black text-green-600"><?= angka($totalPoint) ?> pt</p>
                <p class="text-[10px] text-gray-400 mt-1">Point diberikan</p>
            </div>
        </section>

        <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Total Bayar Diterima</p>
                <p class="text-xl font-black"><?= rupiah($totalBayar) ?></p>
            </div>
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Total Kembalian</p>
                <p class="text-xl font-black"><?= rupiah($totalKembalian) ?></p>
            </div>
            <div class="bg-white border border-subtle print-card p-5 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Periode</p>
                <p class="text-xl font-black"><?= htmlspecialchars(tgl($awal)) ?> - <?= htmlspecialchars(tgl($akhir)) ?></p>
            </div>
        </section>

        <section class="bg-white border border-subtle print-card shadow-sm overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-subtle">
                <h2 class="text-sm font-black uppercase tracking-widest">Detail Transaksi</h2>
                <p class="text-xs text-gray-400 mt-1"><?= angka(count($transaksi)) ?> transaksi ditemukan</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-subtle">
                        <tr class="text-left text-[10px] uppercase tracking-widest text-gray-400">
                            <th class="px-4 py-3">Tanggal</th>
                            <th class="px-4 py-3">Invoice</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3 text-right">Diskon</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Bayar</th>
                            <th class="px-4 py-3 text-right">Kembali</th>
                            <th class="px-4 py-3 text-right">Point</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!$transaksi): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-gray-400 text-xs uppercase tracking-widest">Belum ada transaksi</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($transaksi as $t): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars(waktu($t['created_at'] ?? null)) ?></td>
                                <td class="px-4 py-3 font-bold whitespace-nowrap">
                                    <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="text-blue-600 hover:underline"><?= htmlspecialchars($t['invoice']) ?></a>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($t['member_nama'])): ?>
                                        <span class="font-bold"><?= htmlspecialchars($t['member_nama']) ?></span>
                                        <div class="text-[10px] text-gray-400"><?= htmlspecialchars($t['member_kode'] ?? '') ?></div>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right text-red-600 font-bold"><?= rupiah($t['diskon'] ?? 0) ?></td>
                                <td class="px-4 py-3 text-right font-black"><?= rupiah($t['total'] ?? 0) ?></td>
                                <td class="px-4 py-3 text-right"><?= rupiah($t['bayar'] ?? 0) ?></td>
                                <td class="px-4 py-3 text-right"><?= rupiah($t['kembalian'] ?? 0) ?></td>
                                <td class="px-4 py-3 text-right text-green-600 font-bold"><?= angka($t['point_dapat'] ?? 0) ?> pt</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="bg-white border border-subtle print-card shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-subtle">
                    <h2 class="text-sm font-black uppercase tracking-widest">Produk Terlaris</h2>
                    <p class="text-xs text-gray-400 mt-1">Berdasarkan qty terjual</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-subtle">
                            <tr class="text-left text-[10px] uppercase tracking-widest text-gray-400">
                                <th class="px-4 py-3">Produk</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Diskon</th>
                                <th class="px-4 py-3 text-right">Penjualan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (!$produk): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-10 text-center text-gray-400 text-xs uppercase tracking-widest">Belum ada produk terjual</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($produk as $p): ?>
                                <tr>
                                    <td class="px-4 py-3">
                                        <span class="font-bold"><?= htmlspecialchars($p['nama']) ?></span>
                                        <div class="text-[10px] text-gray-400"><?= htmlspecialchars($p['kode'] ?? '') ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-black"><?= angka($p['qty']) ?></td>
                                    <td class="px-4 py-3 text-right text-red-600"><?= rupiah($p['diskon']) ?></td>
                                    <td class="px-4 py-3 text-right font-bold"><?= rupiah($p['penjualan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white border border-subtle print-card shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-subtle">
                    <h2 class="text-sm font-black uppercase tracking-widest">Diskon Terpakai</h2>
                    <p class="text-xs text-gray-400 mt-1">Promo transaksi dan barang</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-subtle">
                            <tr class="text-left text-[10px] uppercase tracking-widest text-gray-400">
                                <th class="px-4 py-3">Nama</th>
                                <th class="px-4 py-3">Tipe</th>
                                <th class="px-4 py-3 text-right">Pakai</th>
                                <th class="px-4 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (!$diskonTransaksi && !$diskonBarang): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-10 text-center text-gray-400 text-xs uppercase tracking-widest">Belum ada diskon</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($diskonTransaksi as $d): ?>
                                <tr>
                                    <td class="px-4 py-3 font-bold"><?= htmlspecialchars($d['nama']) ?></td>
                                    <td class="px-4 py-3">Transaksi</td>
                                    <td class="px-4 py-3 text-right"><?= angka($d['jumlah']) ?></td>
                                    <td class="px-4 py-3 text-right text-red-600 font-bold"><?= rupiah($d['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($diskonBarang as $d): ?>
                                <tr>
                                    <td class="px-4 py-3 font-bold"><?= htmlspecialchars($d['nama']) ?></td>
                                    <td class="px-4 py-3">Barang</td>
                                    <td class="px-4 py-3 text-right"><?= angka($d['jumlah']) ?></td>
                                    <td class="px-4 py-3 text-right text-red-600 font-bold"><?= rupiah($d['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>

</html>