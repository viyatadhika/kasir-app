<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'air_tracking';
$pageTitle = 'Tracking Pemesanan Air';
$backUrl = 'dashboard.php';

if (!function_exists('atr_h')) {
    /** @param mixed $v */
    function atr_h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('atr_table_exists')) {
    function atr_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = array();
        if (isset($cache[$table])) return $cache[$table];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
            $st->execute(array(':t' => $table));
            return $cache[$table] = ((int)$st->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    }
}
if (!function_exists('atr_col_exists')) {
    function atr_col_exists(PDO $pdo, string $table, string $col): bool
    {
        static $cache = array();
        $k = $table . '.' . $col;
        if (isset($cache[$k])) return $cache[$k];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
            $st->execute(array(':t' => $table, ':c' => $col));
            return $cache[$k] = ((int)$st->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}
if (!function_exists('atr_tgl')) {
    /** @param mixed $v */
    function atr_tgl($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}
if (!function_exists('atr_status_label')) {
    function atr_status_label(string $s): string
    {
        $s = strtolower(trim((string)$s));
        $map = array(
            'baru' => 'Baru',
            'diproses' => 'Diproses',
            'siap_dikirim' => 'Siap Dikirim',
            'dalam_pengiriman' => 'Dalam Pengiriman',
            'selesai' => 'Selesai',
            'batal' => 'Batal'
        );
        return isset($map[$s]) ? $map[$s] : ucwords(str_replace('_', ' ', $s));
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$periode = trim((string)($_GET['periode'] ?? 'semua'));
$allowedStatus = array('', 'baru', 'diproses', 'siap_dikirim', 'dalam_pengiriman', 'selesai', 'batal');
if (!in_array($status, $allowedStatus, true)) $status = '';
if (!in_array($periode, array('semua', 'bulan_ini', '3_bulan', 'tahun_ini'), true)) $periode = 'semua';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = array('1=1');
$params = array();
/*
 * Pencarian dan filter status dilakukan langsung di browser agar hasil muncul
 * seketika saat mengetik/menghapus huruf tanpa reload halaman. Query server
 * hanya membatasi periode supaya seluruh data dalam periode tersedia untuk
 * live search dan pagination client-side.
 */
if ($periode === 'bulan_ini') {
    $where[] = "DATE(COALESCE(p.tanggal_kirim,p.tanggal_pemesanan,p.created_at)) BETWEEN DATE_FORMAT(CURDATE(),'%Y-%m-01') AND LAST_DAY(CURDATE())";
} elseif ($periode === '3_bulan') {
    $where[] = "DATE(COALESCE(p.tanggal_kirim,p.tanggal_pemesanan,p.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($periode === 'tahun_ini') {
    $where[] = "YEAR(COALESCE(p.tanggal_kirim,p.tanggal_pemesanan,p.created_at)) = YEAR(CURDATE())";
}
$whereSql = implode(' AND ', $where);

$hasCustomer = atr_table_exists($pdo, 'air_pelanggan');
$hasLocation = atr_table_exists($pdo, 'air_pesanan_lokasi');
$hasDetail = atr_table_exists($pdo, 'air_pesanan_detail');
$hasVendorSource = atr_table_exists($pdo, 'air_vendor_order_source');
$hasVendor = atr_table_exists($pdo, 'air_vendor_order');
$hasVendorDetail = atr_table_exists($pdo, 'air_vendor_order_detail');
$hasKwitansiSource = atr_table_exists($pdo, 'air_kwitansi_source');
$hasKwitansi = atr_table_exists($pdo, 'air_kwitansi');
$hasHist = atr_col_exists($pdo, 'air_pesanan', 'is_historical');
$hasSumber = atr_col_exists($pdo, 'air_pesanan', 'sumber_data');

$joinCustomer = $hasCustomer ? 'LEFT JOIN air_pelanggan c ON c.id=p.pelanggan_id' : '';
$customerName = $hasCustomer ? "COALESCE(NULLIF(TRIM(c.nama),''), CONCAT('Pemesan #',p.pelanggan_id))" : "CONCAT('Pemesan #',COALESCE(p.pelanggan_id,0))";
$customerPhone = $hasCustomer ? "COALESCE(c.no_hp,'')" : "''";
$locationExpr = $hasLocation ? "COALESCE((SELECT GROUP_CONCAT(DISTINCT l.lokasi ORDER BY l.urutan,l.id SEPARATOR ', ') FROM air_pesanan_lokasi l WHERE l.pesanan_id=p.id),'-')" : "'-'";
$unitExpr = $hasDetail ? "COALESCE((SELECT SUM(d.qty) FROM air_pesanan_detail d WHERE d.pesanan_id=p.id),0)" : '0';
$histExprParts = array("p.nomor_pesanan LIKE 'AIR-LAMA-%'");
if ($hasHist) $histExprParts[] = 'COALESCE(p.is_historical,0)=1';
if ($hasSumber) $histExprParts[] = "LOWER(COALESCE(p.sumber_data,''))='migrasi'";
$histExpr = '(' . implode(' OR ', $histExprParts) . ')';

$vendorExistsExpr = ($hasVendorSource && $hasVendor)
    ? "EXISTS(SELECT 1 FROM air_vendor_order_source s JOIN air_vendor_order vo ON vo.id=s.vendor_order_id WHERE s.pesanan_id=p.id AND LOWER(COALESCE(vo.status,''))<>'batal')"
    : '0';
$vendorNameExpr = ($hasVendorSource && $hasVendor)
    ? "COALESCE((SELECT GROUP_CONCAT(DISTINCT vo.vendor_nama ORDER BY vo.id SEPARATOR ', ') FROM air_vendor_order_source s JOIN air_vendor_order vo ON vo.id=s.vendor_order_id WHERE s.pesanan_id=p.id AND LOWER(COALESCE(vo.status,''))<>'batal'),'-')"
    : "'-'";
$vendorConfirmedExpr = ($hasVendorSource && $hasVendor)
    ? "EXISTS(SELECT 1 FROM air_vendor_order_source s JOIN air_vendor_order vo ON vo.id=s.vendor_order_id WHERE s.pesanan_id=p.id AND LOWER(COALESCE(vo.status,'')) IN ('dikonfirmasi','dikirim','diterima','selesai'))"
    : '0';
$receivedExpr = ($hasVendorSource && $hasVendor && $hasVendorDetail)
    ? "EXISTS(SELECT 1 FROM air_vendor_order_source s JOIN air_vendor_order vo ON vo.id=s.vendor_order_id JOIN air_vendor_order_detail vd ON vd.vendor_order_id=vo.id WHERE s.pesanan_id=p.id AND COALESCE(vd.jumlah_diterima,0)>0)"
    : "(LOWER(COALESCE(p.status,''))='selesai')";
$receivedQtyExpr = ($hasVendorSource && $hasVendorDetail)
    ? "COALESCE((SELECT SUM(vd.jumlah_diterima) FROM air_vendor_order_source s JOIN air_vendor_order_detail vd ON vd.vendor_order_id=s.vendor_order_id WHERE s.pesanan_id=p.id),0)"
    : '0';
$kwitansiExistsExpr = ($hasKwitansiSource && $hasKwitansi)
    ? "EXISTS(SELECT 1 FROM air_kwitansi_source ks JOIN air_kwitansi k ON k.id=ks.kwitansi_id WHERE ks.pesanan_id=p.id AND LOWER(COALESCE(k.status_pembayaran,''))<>'batal')"
    : '0';
$kwitansiPaidExpr = ($hasKwitansiSource && $hasKwitansi)
    ? "EXISTS(SELECT 1 FROM air_kwitansi_source ks JOIN air_kwitansi k ON k.id=ks.kwitansi_id WHERE ks.pesanan_id=p.id AND LOWER(COALESCE(k.status_pembayaran,''))='lunas')"
    : '0';
$kwitansiNoExpr = ($hasKwitansiSource && $hasKwitansi)
    ? "COALESCE((SELECT GROUP_CONCAT(DISTINCT k.nomor_kwitansi ORDER BY k.id SEPARATOR ', ') FROM air_kwitansi_source ks JOIN air_kwitansi k ON k.id=ks.kwitansi_id WHERE ks.pesanan_id=p.id AND LOWER(COALESCE(k.status_pembayaran,''))<>'batal'),'-')"
    : "'-'";

try {
    $sql = "SELECT p.id,p.nomor_pesanan,p.tanggal_pemesanan,p.tanggal_kirim,p.created_at,p.updated_at,p.status,
                   {$customerName} AS nama_pemesan, {$customerPhone} AS no_hp,
                   {$locationExpr} AS lokasi, {$unitExpr} AS total_unit,
                   {$histExpr} AS is_historical,
                   {$vendorExistsExpr} AS has_vendor,
                   {$vendorNameExpr} AS vendor_nama,
                   {$vendorConfirmedExpr} AS vendor_confirmed,
                   {$receivedExpr} AS received,
                   {$receivedQtyExpr} AS received_qty,
                   {$kwitansiExistsExpr} AS has_kwitansi,
                   {$kwitansiPaidExpr} AS kwitansi_paid,
                   {$kwitansiNoExpr} AS nomor_kwitansi
            FROM air_pesanan p {$joinCustomer}
            WHERE {$whereSql}
            ORDER BY COALESCE(p.tanggal_kirim,p.tanggal_pemesanan,p.created_at) DESC,p.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $totalRows = count($rows);
} catch (Throwable $e) {
    $rows = array();
    $totalRows = 0;
    $loadError = $e->getMessage();
}
$totalPages = 1; // pagination ditangani di browser

$summary = array('total' => 0, 'proses' => 0, 'selesai' => 0, 'belum_tagih' => 0, 'belum_lunas' => 0);
try {
    $summarySql = "SELECT
        COUNT(*) total,
        SUM(CASE WHEN LOWER(COALESCE(p.status,''))='selesai' THEN 1 ELSE 0 END) selesai,
        SUM(CASE WHEN LOWER(COALESCE(p.status,'')) NOT IN ('selesai','batal') THEN 1 ELSE 0 END) proses,
        SUM(CASE WHEN LOWER(COALESCE(p.status,''))='selesai' AND NOT ({$kwitansiExistsExpr}) THEN 1 ELSE 0 END) belum_tagih,
        SUM(CASE WHEN ({$kwitansiExistsExpr}) AND NOT ({$kwitansiPaidExpr}) THEN 1 ELSE 0 END) belum_lunas
        FROM air_pesanan p";
    $summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: $summary;
} catch (Throwable $e) {
}


require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tracking Pemesanan Air</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111827
        }

        .air-track-main {
            margin-left: 0
        }

        .step-line {
            height: 2px;
            background: #e5e7eb;
            flex: 1;
            min-width: 12px
        }

        .step-line.done {
            background: #111827
        }

        .step-dot {
            width: 26px;
            height: 26px;
            border: 1px solid #d1d5db;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            flex: none
        }

        .step-dot.done {
            background: #111827;
            border-color: #111827;
            color: #fff
        }

        .step-dot.skip {
            background: #f3f4f6;
            color: #9ca3af
        }

        .timeline-label {
            font-size: 8px;
            line-height: 1.25;
            text-align: center;
            color: #6b7280;
            max-width: 76px
        }

        .timeline-label.done {
            color: #111827;
            font-weight: 700
        }

        .desktop-only {
            display: none
        }

        .mobile-only {
            display: grid
        }

        .track-card {
            border: 1px solid #e5e7eb;
            background: #fff
        }

        .badge {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 4px 7px;
            border: 1px solid #e5e7eb
        }

        .badge-ok {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #15803d
        }

        .badge-warn {
            background: #fffbeb;
            border-color: #fde68a;
            color: #a16207
        }

        .badge-gray {
            background: #f9fafb;
            color: #6b7280
        }

        .badge-red {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626
        }

        @media(min-width:1024px) {
            .air-track-main {
                margin-left: 220px
            }

            .desktop-only {
                display: block
            }

            .mobile-only {
                display: none
            }
        }

        @media(max-width:1399px) and (min-width:1024px) {
            .air-track-main {
                margin-left: 0 !important
            }
        }
    </style>
</head>

<body class="min-h-screen">
    <main class="air-track-main p-4 sm:p-5 md:p-8 lg:p-10">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Air Mineral / Admin</p>
                <h1 class="text-2xl font-semibold mt-1">Tracking Pemesanan</h1>
                <p class="text-xs text-gray-400 mt-1">Pantau perjalanan setiap pesanan dari dibuat sampai pembayaran kantor.</p>
            </div>
        </div>

        <?php if (!empty($loadError)): ?><div class="mb-4 border border-red-200 bg-red-50 p-3 text-xs text-red-700"><?php echo atr_h($loadError); ?></div><?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            <div class="track-card p-4">
                <p class="text-[9px] uppercase font-bold text-gray-400">Semua Pesanan</p>
                <p class="text-2xl font-bold mt-1"><?php echo number_format((int)$summary['total']); ?></p>
            </div>
            <div class="track-card p-4">
                <p class="text-[9px] uppercase font-bold text-gray-400">Masih Proses</p>
                <p class="text-2xl font-bold mt-1 text-amber-600"><?php echo number_format((int)$summary['proses']); ?></p>
            </div>
            <div class="track-card p-4">
                <p class="text-[9px] uppercase font-bold text-gray-400">Selesai</p>
                <p class="text-2xl font-bold mt-1 text-green-600"><?php echo number_format((int)$summary['selesai']); ?></p>
            </div>
            <div class="track-card p-4">
                <p class="text-[9px] uppercase font-bold text-gray-400">Belum Ditagih</p>
                <p class="text-2xl font-bold mt-1 text-orange-600"><?php echo number_format((int)$summary['belum_tagih']); ?></p>
            </div>
            <div class="track-card p-4 col-span-2 md:col-span-1">
                <p class="text-[9px] uppercase font-bold text-gray-400">Tagihan Belum Lunas</p>
                <p class="text-2xl font-bold mt-1 text-red-600"><?php echo number_format((int)$summary['belum_lunas']); ?></p>
            </div>
        </div>

        <form id="trackingFilterForm" method="get" class="track-card p-4 mb-4 grid grid-cols-1 md:grid-cols-[minmax(280px,1fr)_180px_180px] gap-3 items-end" onsubmit="return false;">
            <div>
                <label class="block text-[9px] uppercase font-bold text-gray-400 mb-1">Cari</label>
                <div class="relative">
                    <span class="absolute left-0 top-0 bottom-0 w-11 flex items-center justify-center text-gray-400 border-r border-gray-100 pointer-events-none"><i data-lucide="search" class="w-4 h-4"></i></span>
                    <input id="trackingSearch" type="search" value="" class="w-full border border-gray-200 pl-14 pr-9 py-2.5 text-sm bg-white" placeholder="Cari nomor pesanan, pemesan, lokasi, vendor..." autocomplete="off">
                </div>
            </div>
            <div><label class="block text-[9px] uppercase font-bold text-gray-400 mb-1">Status</label><select id="trackingStatus" class="w-full border border-gray-200 px-3 py-2.5 text-sm">
                    <option value="">Semua Status</option><?php foreach (array('baru', 'diproses', 'siap_dikirim', 'dalam_pengiriman', 'selesai', 'batal') as $s): ?><option value="<?php echo $s; ?>"><?php echo atr_h(atr_status_label($s)); ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="block text-[9px] uppercase font-bold text-gray-400 mb-1">Periode</label><select id="trackingPeriode" name="periode" class="w-full border border-gray-200 px-3 py-2.5 text-sm">
                    <option value="semua" <?php echo $periode === 'semua' ? 'selected' : ''; ?>>Semua Data</option>
                    <option value="bulan_ini" <?php echo $periode === 'bulan_ini' ? 'selected' : ''; ?>>Bulan Ini</option>
                    <option value="3_bulan" <?php echo $periode === '3_bulan' ? 'selected' : ''; ?>>3 Bulan Terakhir</option>
                    <option value="tahun_ini" <?php echo $periode === 'tahun_ini' ? 'selected' : ''; ?>>Tahun Ini</option>
                </select></div>
        </form>

        <section class="track-card overflow-hidden">
            <div class="px-4 md:px-5 py-4 border-b border-gray-100 flex justify-between items-center gap-3">
                <div>
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Daftar Tracking</h2>
                    <p id="trackingResultInfo" class="text-xs text-gray-400 mt-1"><?php echo number_format($totalRows); ?> pesanan</p>
                </div>
            </div>
            <div class="desktop-only overflow-x-auto">
                <table class="w-full text-left min-w-[1160px]">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Pesanan</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Pemesan / Lokasi</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Vendor</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-center">Unit</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Tracking</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!$rows): ?><tr>
                                <td colspan="6" class="py-16 text-center text-xs text-gray-400">Belum ada data.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($rows as $r):
                            $hist = !empty($r['is_historical']);
                            $hasVendorStep = !empty($r['has_vendor']);
                            $confirmed = !empty($r['vendor_confirmed']);
                            $received = !empty($r['received']) || strtolower((string)$r['status']) === 'selesai';
                            $billed = !empty($r['has_kwitansi']);
                            $paid = !empty($r['kwitansi_paid']);
                            $steps = array(
                                array('Dibuat', true, false),
                                array('Rekap Vendor', $hasVendorStep, $hist && !$hasVendorStep),
                                array('Konfirmasi', $confirmed, $hist && !$hasVendorStep),
                                array('Diterima', $received, false),
                                array('Ditagihkan', $billed, false),
                                array('Dibayar', $paid, false),
                            );
                            $s = strtolower((string)$r['status']);
                            $bc = $s === 'selesai' ? 'badge-ok' : ($s === 'batal' ? 'badge-red' : 'badge-warn');
                        ?>
                            <?php $searchText = strtolower((string)$r['nomor_pesanan'] . ' ' . (string)$r['nama_pemesan'] . ' ' . (string)$r['lokasi'] . ' ' . (string)$r['vendor_nama'] . ' ' . (string)$r['nomor_kwitansi'] . ' ' . (string)$r['status']); ?>
                            <tr class="tracking-row" data-status="<?php echo atr_h($s); ?>" data-search="<?php echo atr_h($searchText); ?>">
                                <td class="px-4 py-4 align-top">
                                    <div class="font-bold text-sm"><?php echo atr_h($r['nomor_pesanan']); ?></div>
                                    <div class="text-[10px] text-gray-400 mt-1"><?php echo atr_h(atr_tgl($r['tanggal_kirim'] ?: $r['tanggal_pemesanan'] ?: $r['created_at'])); ?></div><?php if ($hist): ?><span class="badge badge-gray inline-block mt-2">Data Historis</span><?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="font-semibold text-sm"><?php echo atr_h($r['nama_pemesan']); ?></div>
                                    <div class="text-[10px] text-gray-400 mt-1 max-w-[260px]"><?php echo atr_h($r['lokasi']); ?></div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm"><?php echo atr_h($r['vendor_nama']); ?></td>
                                <td class="px-4 py-4 align-top text-center">
                                    <div class="font-bold"><?php echo number_format((int)$r['total_unit']); ?></div><?php if ((int)$r['received_qty'] > 0): ?><div class="text-[9px] text-green-600 mt-1">diterima <?php echo number_format((int)$r['received_qty']); ?></div><?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top">
                                    <div class="flex items-start min-w-[470px]">
                                        <?php foreach ($steps as $i => $st): ?><div class="flex flex-col items-center">
                                                <div class="step-dot <?php echo $st[1] ? 'done' : ($st[2] ? 'skip' : ''); ?>"><?php echo $st[1] ? '✓' : ($st[2] ? '—' : ($i + 1)); ?></div>
                                                <div class="timeline-label <?php echo $st[1] ? 'done' : ''; ?> mt-1"><?php echo atr_h($st[0]); ?></div>
                                            </div><?php if ($i < count($steps) - 1): ?><div class="step-line <?php echo ($st[1] && $steps[$i + 1][1]) ? 'done' : ''; ?> mt-[12px]"></div><?php endif; ?><?php endforeach; ?>
                                    </div><?php if ($billed): ?><div class="text-[9px] text-gray-400 mt-2">Kwitansi: <?php echo atr_h($r['nomor_kwitansi']); ?></div><?php endif; ?>
                                </td>
                                <td class="px-4 py-4 align-top text-center"><span class="badge <?php echo $bc; ?>"><?php echo atr_h(atr_status_label($s)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-only gap-3 p-3">
                <?php foreach ($rows as $r): $hist = !empty($r['is_historical']);
                    $hasVendorStep = !empty($r['has_vendor']);
                    $confirmed = !empty($r['vendor_confirmed']);
                    $received = !empty($r['received']) || strtolower((string)$r['status']) === 'selesai';
                    $billed = !empty($r['has_kwitansi']);
                    $paid = !empty($r['kwitansi_paid']);
                    $s = strtolower((string)$r['status']); ?>
                    <?php $searchText = strtolower((string)$r['nomor_pesanan'] . ' ' . (string)$r['nama_pemesan'] . ' ' . (string)$r['lokasi'] . ' ' . (string)$r['vendor_nama'] . ' ' . (string)$r['nomor_kwitansi'] . ' ' . (string)$r['status']); ?>
                    <div class="tracking-mobile-card border border-gray-200 bg-white p-4" data-status="<?php echo atr_h($s); ?>" data-search="<?php echo atr_h($searchText); ?>">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-bold text-sm break-all"><?php echo atr_h($r['nomor_pesanan']); ?></p>
                                <p class="text-[10px] text-gray-400 mt-1"><?php echo atr_h($r['nama_pemesan']); ?> · <?php echo atr_h(atr_tgl($r['tanggal_kirim'] ?: $r['tanggal_pemesanan'] ?: $r['created_at'])); ?></p>
                            </div><span class="badge <?php echo $s === 'selesai' ? 'badge-ok' : ($s === 'batal' ? 'badge-red' : 'badge-warn'); ?> shrink-0"><?php echo atr_h(atr_status_label($s)); ?></span>
                        </div>
                        <div class="text-xs text-gray-500 mt-3"><?php echo atr_h($r['lokasi']); ?></div>
                        <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
                            <div class="border border-gray-100 p-2"><span class="text-gray-400">Vendor</span>
                                <div class="font-semibold mt-1"><?php echo atr_h($r['vendor_nama']); ?></div>
                            </div>
                            <div class="border border-gray-100 p-2"><span class="text-gray-400">Jumlah</span>
                                <div class="font-semibold mt-1"><?php echo number_format((int)$r['total_unit']); ?> unit</div>
                            </div>
                        </div>
                        <div class="mt-4 space-y-2 text-xs">
                            <?php $mobileSteps = array(array('Pesanan dibuat', true), array('Direkap ke vendor', $hasVendorStep), array('Dikonfirmasi vendor', $confirmed), array('Barang diterima', $received), array('Ditagihkan ke kantor', $billed), array('Dibayar kantor', $paid));
                            foreach ($mobileSteps as $ms): ?><div class="flex items-center gap-2"><span class="w-5 h-5 border flex items-center justify-center text-[9px] font-bold <?php echo $ms[1] ? 'bg-black text-white border-black' : 'border-gray-200 text-gray-300'; ?>"><?php echo $ms[1] ? '✓' : '•'; ?></span><span class="<?php echo $ms[1] ? 'font-semibold' : 'text-gray-400'; ?>"><?php echo atr_h($ms[0]); ?></span></div><?php endforeach; ?>
                        </div>
                        <?php if ($hist): ?><div class="mt-3 text-[9px] text-orange-600 font-bold uppercase">Data historis: tahap vendor lama dapat tidak tercatat.</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="trackingEmpty" class="hidden px-4 py-12 text-center text-xs font-bold uppercase tracking-widest text-gray-400 border-t border-gray-100">Pesanan tidak ditemukan</div>
            <div id="trackingPagination" class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3 text-[10px] font-bold text-gray-500">
                    <span id="trackingPageInfo">Menampilkan 0 data</span>
                    <label class="flex items-center gap-2">Per halaman
                        <select id="trackingPageSize" class="border border-gray-200 bg-white px-2 py-1.5 text-[10px] font-bold">
                            <option value="10">10</option>
                            <option value="15" selected>15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>
                <div id="trackingPageButtons" class="flex items-center justify-center sm:justify-end gap-1 flex-wrap"></div>
            </div>
        </section>
    </main>
    <script>
        if (window.lucide) {
            lucide.createIcons();
        }
        (function() {
            var input = document.getElementById('trackingSearch');
            var status = document.getElementById('trackingStatus');
            var periode = document.getElementById('trackingPeriode');
            var pageSizeSelect = document.getElementById('trackingPageSize');
            var currentPage = 1;
            var pageSize = pageSizeSelect ? Number(pageSizeSelect.value || 15) : 15;

            function filtered(selector) {
                var keyword = input ? input.value.toLowerCase().trim() : '';
                var statusValue = status ? status.value : '';
                return Array.prototype.filter.call(document.querySelectorAll(selector), function(el) {
                    var haystack = String(el.getAttribute('data-search') || '').toLowerCase();
                    var rowStatus = String(el.getAttribute('data-status') || '');
                    return (keyword === '' || haystack.indexOf(keyword) !== -1) &&
                        (statusValue === '' || rowStatus === statusValue);
                });
            }

            function pageButton(label, page, disabled, active) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = label;
                btn.disabled = !!disabled;
                btn.className = 'min-w-[32px] h-8 px-2 border text-[10px] font-bold ' +
                    (active ? 'bg-black text-white border-black' : 'bg-white text-gray-700 border-gray-200') +
                    (disabled ? ' opacity-30 cursor-not-allowed' : '');
                btn.addEventListener('click', function() {
                    currentPage = page;
                    render();
                });
                return btn;
            }

            function buildPages(totalPages) {
                var wrap = document.getElementById('trackingPageButtons');
                if (!wrap) return;
                wrap.innerHTML = '';
                if (totalPages <= 1) return;
                wrap.appendChild(pageButton('‹', Math.max(1, currentPage - 1), currentPage === 1, false));
                var pages = [];
                if (totalPages <= 7) {
                    for (var i = 1; i <= totalPages; i++) pages.push(i);
                } else {
                    pages = [1];
                    var a = Math.max(2, currentPage - 1),
                        b = Math.min(totalPages - 1, currentPage + 1);
                    if (a > 2) pages.push('...');
                    for (var j = a; j <= b; j++) pages.push(j);
                    if (b < totalPages - 1) pages.push('...');
                    pages.push(totalPages);
                }
                pages.forEach(function(pg) {
                    if (pg === '...') {
                        var sp = document.createElement('span');
                        sp.textContent = '...';
                        sp.className = 'px-1 text-xs text-gray-400';
                        wrap.appendChild(sp);
                    } else wrap.appendChild(pageButton(String(pg), pg, false, pg === currentPage));
                });
                wrap.appendChild(pageButton('›', Math.min(totalPages, currentPage + 1), currentPage === totalPages, false));
            }

            function render() {
                var desktopAll = document.querySelectorAll('.tracking-row');
                var mobileAll = document.querySelectorAll('.tracking-mobile-card');
                var desktopFiltered = filtered('.tracking-row');
                var mobileFiltered = filtered('.tracking-mobile-card');
                var total = desktopFiltered.length || mobileFiltered.length;
                var totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                var start = (currentPage - 1) * pageSize;
                var end = Math.min(start + pageSize, total);
                desktopAll.forEach(function(el) {
                    el.style.display = 'none';
                });
                mobileAll.forEach(function(el) {
                    el.style.display = 'none';
                });
                desktopFiltered.slice(start, end).forEach(function(el) {
                    el.style.display = '';
                });
                mobileFiltered.slice(start, end).forEach(function(el) {
                    el.style.display = '';
                });
                var empty = document.getElementById('trackingEmpty');
                if (empty) empty.classList.toggle('hidden', total > 0);
                var info = document.getElementById('trackingResultInfo');
                if (info) info.textContent = total.toLocaleString('id-ID') + ' pesanan';
                var pageInfo = document.getElementById('trackingPageInfo');
                if (pageInfo) pageInfo.textContent = total > 0 ? ('Menampilkan ' + (start + 1) + '–' + end + ' dari ' + total + ' data') : 'Menampilkan 0 data';
                var pagination = document.getElementById('trackingPagination');
                if (pagination) pagination.style.display = total > 0 ? 'flex' : 'none';
                buildPages(totalPages);
            }

            function resetAndRender() {
                currentPage = 1;
                render();
            }
            if (input) {
                input.addEventListener('input', resetAndRender);
                input.addEventListener('search', resetAndRender);
            }
            if (status) status.addEventListener('change', resetAndRender);
            if (pageSizeSelect) pageSizeSelect.addEventListener('change', function() {
                pageSize = Number(this.value || 15);
                resetAndRender();
            });
            if (periode) periode.addEventListener('change', function() {
                var url = new URL(window.location.href);
                url.search = '';
                if (this.value && this.value !== 'semua') url.searchParams.set('periode', this.value);
                window.location.href = url.toString();
            });
            render();
        })();
    </script>
</body>

</html>