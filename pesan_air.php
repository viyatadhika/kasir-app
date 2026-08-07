<?php
/*
|--------------------------------------------------------------------------
| pesan_air.php — Form Pemesanan Air Publik Multi Lokasi
|--------------------------------------------------------------------------
| Satu transaksi dapat berisi beberapa lokasi pengantaran dan setiap
| lokasi dapat berisi beberapa jenis produk dengan jumlah berbeda.
| Compatible PHP 7 & 8.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
date_default_timezone_set('Asia/Jakarta');

/**
 * Escape nilai untuk output HTML.
 *
 * @param mixed $value
 * @return string
 */
function pa_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format nilai numerik ke Rupiah.
 *
 * @param int|float|string|null $value
 * @return string
 */
function pa_rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function pa_csrf_token(): string
{
    if (empty($_SESSION['pesan_air_csrf'])) {
        $_SESSION['pesan_air_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['pesan_air_csrf'];
}

function pa_order_number(): string
{
    return 'AIR-' . date('Ymd-His') . '-' . random_int(10, 99);
}


function pa_tracking_token(): string
{
    return bin2hex(random_bytes(24));
}

function pa_normalize_wa(string $number): string
{
    $number = preg_replace('/[^0-9]/', '', $number);

    if ($number === '') {
        return '';
    }

    if (strpos($number, '0') === 0) {
        return '62' . substr($number, 1);
    }

    if (strpos($number, '62') !== 0) {
        return '62' . $number;
    }

    return $number;
}

function pa_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/pesan_air.php'));
    $dir = rtrim(dirname($scriptName), '/.');

    return $scheme . '://' . $host . ($dir !== '' ? $dir : '');
}

function pa_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function pa_ensure_database(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS air_pelanggan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_pelanggan VARCHAR(30) NOT NULL,
        nama VARCHAR(120) NOT NULL,
        no_hp VARCHAR(30) NULL,
        alamat TEXT NULL,
        status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_air_pelanggan_kode (kode_pelanggan),
        INDEX idx_air_pelanggan_hp (no_hp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS air_produk (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_produk VARCHAR(30) NOT NULL,
        nama_produk VARCHAR(120) NOT NULL,
        jenis ENUM('galon_isi_ulang','galon_baru','botol','dus','lainnya') NOT NULL DEFAULT 'galon_isi_ulang',
        harga_jual DECIMAL(15,2) NOT NULL DEFAULT 0,
        stok INT NOT NULL DEFAULT 0,
        satuan VARCHAR(30) NOT NULL DEFAULT 'pcs',
        status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_air_produk_kode (kode_produk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS air_pesanan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nomor_pesanan VARCHAR(50) NOT NULL,
        pelanggan_id INT NOT NULL,
        user_id INT NULL,
        tanggal_pemesanan DATE NULL,
        tipe_pengambilan ENUM('ambil_sendiri','antar') NOT NULL DEFAULT 'antar',
        alamat_pengiriman TEXT NULL,
        tanggal_kirim DATE NULL,
        jam_kirim TIME NULL,
        status ENUM('baru','diproses','siap_dikirim','dalam_pengiriman','selesai','batal') NOT NULL DEFAULT 'baru',
        metode_pembayaran VARCHAR(30) NOT NULL DEFAULT 'tunai',
        status_pembayaran ENUM('belum_bayar','sebagian','lunas') NOT NULL DEFAULT 'belum_bayar',
        total DECIMAL(15,2) NOT NULL DEFAULT 0,
        dibayar DECIMAL(15,2) NOT NULL DEFAULT 0,
        galon_kosong_diterima INT NOT NULL DEFAULT 0,
        galon_dipinjamkan INT NOT NULL DEFAULT 0,
        deposit_galon DECIMAL(15,2) NOT NULL DEFAULT 0,
        catatan TEXT NULL,
        tracking_token VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_air_nomor_pesanan (nomor_pesanan),
        INDEX idx_air_pesanan_status (status),
        INDEX idx_air_pesanan_pelanggan (pelanggan_id),
        UNIQUE KEY uq_air_pesanan_tracking (tracking_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!pa_column_exists($pdo, 'air_pesanan', 'tanggal_pemesanan')) {
        $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN tanggal_pemesanan DATE NULL AFTER user_id");
    }


    if (!pa_column_exists($pdo, 'air_pesanan', 'tracking_token')) {
        $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN tracking_token VARCHAR(64) NULL AFTER catatan");
        $pdo->exec("ALTER TABLE air_pesanan ADD UNIQUE KEY uq_air_pesanan_tracking (tracking_token)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS air_pesanan_lokasi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pesanan_id INT NOT NULL,
        lokasi VARCHAR(150) NOT NULL,
        urutan INT NOT NULL DEFAULT 1,
        catatan VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_air_lokasi_pesanan (pesanan_id),
        INDEX idx_air_lokasi_nama (lokasi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS air_pesanan_detail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pesanan_id INT NOT NULL,
        lokasi_id INT NULL,
        produk_id INT NOT NULL,
        kode_produk VARCHAR(30) NOT NULL,
        nama_produk VARCHAR(120) NOT NULL,
        harga DECIMAL(15,2) NOT NULL DEFAULT 0,
        qty INT NOT NULL DEFAULT 1,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        catatan_item VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_air_detail_pesanan (pesanan_id),
        INDEX idx_air_detail_lokasi (lokasi_id),
        INDEX idx_air_detail_produk (produk_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!pa_column_exists($pdo, 'air_pesanan_detail', 'lokasi_id')) {
        $pdo->exec("ALTER TABLE air_pesanan_detail ADD COLUMN lokasi_id INT NULL AFTER pesanan_id");
        $pdo->exec("ALTER TABLE air_pesanan_detail ADD INDEX idx_air_detail_lokasi (lokasi_id)");
    }

    // Master produk tidak ditambahkan otomatis dari halaman publik.
    // Produk dikelola hanya melalui air_produk.php.

}

$lokasiPilihan = [
    'Lobby Gedung Kantor',
    'Candra 1',
    'Candra 2',
    'Sari',
    'Kartika',
    'Auditorium',
    'Serba Guna',
    'Cakra 1',
    'Cakra 2',
    'Cakra 3',
    'Cakra 4',
    'Cakra 5',
    'Samping BSI Gedung Ahmad Yani',
    'Mini Market Koperasi',
];

$error = '';
$success = false;
$orderNumber = '';
$orderTotal = 0;
$orderTotalQty = 0;
$orderSummary = [];
$trackingToken = '';
$trackingUrl = '';
$whatsappUrl = '';

try {
    pa_ensure_database($pdo);
} catch (Throwable $e) {
    $error = 'Database modul air belum siap: ' . $e->getMessage();
}

$produkList = [];
try {
    $produkList = $pdo->query("SELECT id,kode_produk,nama_produk,jenis,harga_jual,stok,satuan
        FROM air_produk WHERE status='aktif'
        ORDER BY FIELD(jenis,'galon_isi_ulang','galon_baru','botol','dus','lainnya'),nama_produk")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($error === '') $error = 'Produk air belum dapat dimuat.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        $token = (string)($_POST['csrf_token'] ?? '');
        if ($token === '' || !hash_equals(pa_csrf_token(), $token)) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
        }

        $tanggalPemesanan = trim((string)($_POST['tanggal_pemesanan'] ?? ''));
        $tanggalKirim = trim((string)($_POST['tanggal_kirim'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $noHp = trim((string)($_POST['no_hp'] ?? ''));
        // Pembayaran dikelola oleh petugas setelah pesanan masuk.
        $metodePembayaran = 'tunai';
        $catatan = '';
        $lokasiRows = isset($_POST['lokasi']) && is_array($_POST['lokasi']) ? $_POST['lokasi'] : [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalKirim)) {
            throw new RuntimeException('Tanggal pengiriman wajib diisi.');
        }
        if ($tanggalKirim < $tanggalPemesanan) {
            throw new RuntimeException('Tanggal pengiriman tidak boleh sebelum tanggal pemesanan.');
        }
        if ($nama === '') throw new RuntimeException('Nama pemesan wajib diisi.');
        if ($noHp === '') throw new RuntimeException('Nomor WhatsApp wajib diisi.');
        if (!$lokasiRows) throw new RuntimeException('Tambahkan minimal satu lokasi pengantaran.');
        if (!in_array($metodePembayaran, ['tunai', 'qris', 'transfer'], true)) $metodePembayaran = 'tunai';

        $normalizedLocations = [];
        $requiredStock = [];

        foreach ($lokasiRows as $locationIndex => $locationRow) {
            if (!is_array($locationRow)) continue;
            $locationName = trim((string)($locationRow['nama'] ?? ''));
            $locationNote = trim((string)($locationRow['catatan'] ?? ''));
            $itemsRaw = isset($locationRow['items']) && is_array($locationRow['items']) ? $locationRow['items'] : [];

            if ($locationName === '') continue;
            if (!in_array($locationName, $lokasiPilihan, true)) {
                throw new RuntimeException('Lokasi pengantaran tidak valid: ' . $locationName);
            }

            $items = [];
            foreach ($itemsRaw as $itemRaw) {
                if (!is_array($itemRaw)) continue;
                $produkId = (int)($itemRaw['produk_id'] ?? 0);
                $qty = (int)($itemRaw['qty'] ?? 0);
                if ($produkId <= 0 || $qty <= 0) continue;
                $items[] = ['produk_id' => $produkId, 'qty' => $qty];
                if (!isset($requiredStock[$produkId])) $requiredStock[$produkId] = 0;
                $requiredStock[$produkId] += $qty;
            }

            if (!$items) {
                throw new RuntimeException('Lokasi ' . $locationName . ' belum memiliki jenis pemesanan.');
            }

            $normalizedLocations[] = [
                'nama' => $locationName,
                'catatan' => $locationNote,
                'items' => $items,
            ];
        }

        if (!$normalizedLocations) throw new RuntimeException('Tambahkan minimal satu lokasi dan satu jenis pemesanan.');

        $pdo->beginTransaction();

        $ids = array_keys($requiredStock);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtProduk = $pdo->prepare("SELECT id,kode_produk,nama_produk,harga_jual,stok,satuan
            FROM air_produk WHERE id IN ($placeholders) AND status='aktif' FOR UPDATE");
        $stmtProduk->execute($ids);
        $productMap = [];
        foreach ($stmtProduk->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $productMap[(int)$product['id']] = $product;
        }

        foreach ($requiredStock as $produkId => $qtyRequired) {
            if (!isset($productMap[$produkId])) {
                throw new RuntimeException('Salah satu produk tidak tersedia.');
            }
        }

        $stmtCustomer = $pdo->prepare("SELECT id FROM air_pelanggan WHERE no_hp=:no_hp LIMIT 1");
        $stmtCustomer->execute([':no_hp' => $noHp]);
        $pelangganId = (int)$stmtCustomer->fetchColumn();

        if ($pelangganId > 0) {
            $stmt = $pdo->prepare("UPDATE air_pelanggan SET nama=:nama,status='aktif',updated_at=NOW() WHERE id=:id");
            $stmt->execute([':nama' => $nama, ':id' => $pelangganId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO air_pelanggan(kode_pelanggan,nama,no_hp,alamat,status,created_at)
                VALUES(:kode,:nama,:no_hp,'','aktif',NOW())");
            $stmt->execute([
                ':kode' => 'PLG-' . date('ymdHis'),
                ':nama' => $nama,
                ':no_hp' => $noHp,
            ]);
            $pelangganId = (int)$pdo->lastInsertId();
        }

        $orderNumber = pa_order_number();
        $trackingToken = pa_tracking_token();

        $stmtOrder = $pdo->prepare("INSERT INTO air_pesanan(
            nomor_pesanan,pelanggan_id,user_id,tanggal_pemesanan,tipe_pengambilan,
            alamat_pengiriman,tanggal_kirim,jam_kirim,status,metode_pembayaran,
            status_pembayaran,total,dibayar,galon_kosong_diterima,galon_dipinjamkan,
            deposit_galon,catatan,tracking_token,created_at
        ) VALUES(
            :nomor,:pelanggan_id,NULL,:tanggal_pemesanan,'antar','Multi Lokasi',
            :tanggal_kirim,NULL,'baru',:metode,'belum_bayar',0,0,0,0,0,:catatan,:tracking_token,NOW()
        )");
        $stmtOrder->execute([
            ':nomor' => $orderNumber,
            ':pelanggan_id' => $pelangganId,
            ':tanggal_pemesanan' => $tanggalPemesanan,
            ':tanggal_kirim' => $tanggalKirim,
            ':metode' => $metodePembayaran,
            ':catatan' => $catatan,
            ':tracking_token' => $trackingToken,
        ]);
        $pesananId = (int)$pdo->lastInsertId();

        $stmtLocation = $pdo->prepare("INSERT INTO air_pesanan_lokasi(pesanan_id,lokasi,urutan,catatan,created_at)
            VALUES(:pesanan_id,:lokasi,:urutan,:catatan,NOW())");
        $stmtDetail = $pdo->prepare("INSERT INTO air_pesanan_detail(
            pesanan_id,lokasi_id,produk_id,kode_produk,nama_produk,harga,qty,subtotal,catatan_item,created_at
        ) VALUES(:pesanan_id,:lokasi_id,:produk_id,:kode,:nama,:harga,:qty,:subtotal,'',NOW())");

        $total = 0;
        $totalQty = 0;
        $orderSummary = [];
        foreach ($normalizedLocations as $locationNo => $location) {
            $stmtLocation->execute([
                ':pesanan_id' => $pesananId,
                ':lokasi' => $location['nama'],
                ':urutan' => $locationNo + 1,
                ':catatan' => $location['catatan'],
            ]);
            $lokasiId = (int)$pdo->lastInsertId();
            $summaryItems = [];

            foreach ($location['items'] as $item) {
                $product = $productMap[$item['produk_id']];
                $subtotal = 0;
                $totalQty += (int)$item['qty'];
                $stmtDetail->execute([
                    ':pesanan_id' => $pesananId,
                    ':lokasi_id' => $lokasiId,
                    ':produk_id' => $product['id'],
                    ':kode' => $product['kode_produk'],
                    ':nama' => $product['nama_produk'],
                    ':harga' => $product['harga_jual'],
                    ':qty' => $item['qty'],
                    ':subtotal' => $subtotal,
                ]);
                $summaryItems[] = $product['nama_produk'] . ' ' . $item['qty'] . ' ' . $product['satuan'];
            }
            $orderSummary[] = ['lokasi' => $location['nama'], 'items' => $summaryItems];
        }

        $stmtTotal = $pdo->prepare("UPDATE air_pesanan SET total=:total WHERE id=:id");
        $stmtTotal->execute([':total' => $total, ':id' => $pesananId]);
        $pdo->commit();

        $orderTotal = 0;
        $orderTotalQty = $totalQty;

        $trackingUrl = pa_base_url() . '/lacak_air.php?t=' . rawurlencode($trackingToken);
        $waNumber = pa_normalize_wa($noHp);

        $waMessage = "Pesanan Air Mineral SEJAHUB\n\n"
            . "Nomor Pesanan: " . $orderNumber . "\n"
            . "Total: " . $orderTotalQty . " unit\n"
            . "Status: Baru\n\n"
            . "Pantau proses pesanan Anda tanpa login melalui link berikut:\n"
            . $trackingUrl . "\n\n"
            . "Simpan link ini sampai pesanan selesai.";

        if ($waNumber !== '') {
            $whatsappUrl = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($waMessage);
        }

        $success = true;
        $_SESSION['pesan_air_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$csrfToken = pa_csrf_token();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#111827">
    <title>Pemesanan Air - SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #111827;
            --muted: #94a3b8;
            --line: #eef0f3;
            --soft: #f8fafc;
            --brand: #0f172a
        }

        * {
            box-sizing: border-box
        }

        html {
            scroll-behavior: smooth
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f6f7f9;
            color: var(--ink);
            margin: 0
        }

        button,
        input,
        select {
            font: inherit
        }

        .app-shell {
            min-height: 100vh
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 40;
            background: rgba(255, 255, 255, .96);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line)
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            object-fit: contain
        }

        .surface {
            background: #fff;
            border: 1px solid var(--line);
            box-shadow: 0 10px 30px rgba(15, 23, 42, .035)
        }

        .section-eyebrow {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #94a3b8
        }

        .field {
            width: 100%;
            min-height: 48px;
            padding: 0 14px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            transition: .15s
        }

        .field:focus {
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, .06)
        }

        .btn {
            min-height: 46px;
            padding: 0 17px;
            border: 1px solid transparent;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: .15s;
            cursor: pointer
        }

        .btn-dark {
            background: #111827;
            color: #fff
        }

        .btn-dark:hover {
            background: #1f2937
        }

        .btn-light {
            background: #fff;
            border-color: #e5e7eb;
            color: #111827
        }

        .btn-light:hover {
            background: #f8fafc
        }

        .icon-btn {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #dc2626
        }

        .location-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            overflow: hidden
        }

        .location-head {
            background: #fafafa;
            border-bottom: 1px solid #eef0f3
        }

        .item-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 120px 42px;
            gap: 10px;
            align-items: center
        }

        .summary-bar {
            position: sticky;
            bottom: 0;
            z-index: 30;
            background: rgba(255, 255, 255, .97);
            backdrop-filter: blur(10px);
            border-top: 1px solid var(--line);
            box-shadow: 0 -12px 30px rgba(15, 23, 42, .06)
        }

        .step-dot {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: #fff;
            font-size: 11px;
            font-weight: 800
        }

        @media(min-width:1024px) {
            .page-grid {
                display: grid;
                grid-template-columns: 330px minmax(0, 1fr);
                gap: 24px
            }

            .desktop-sticky {
                position: sticky;
                top: 102px;
                align-self: start
            }

            .summary-bar {
                position: static;
                border: 1px solid var(--line);
                box-shadow: none;
                background: #fff
            }
        }

        @media(max-width:767px) {
            .topbar-inner {
                padding: 12px 14px
            }

            .brand-logo {
                width: 36px;
                height: 36px
            }

            .item-row {
                grid-template-columns: minmax(0, 1fr) 86px 40px;
                gap: 7px
            }

            .surface {
                box-shadow: none
            }

            .mobile-tight {
                padding: 16px !important
            }

            .location-card {
                border-left: 0;
                border-right: 0
            }

            .location-wrap {
                margin-left: -16px;
                margin-right: -16px
            }

            .btn {
                min-height: 44px
            }

            .summary-bar {
                padding-bottom: calc(12px + env(safe-area-inset-bottom))
            }
        }
    </style>
</head>

<body>
    <div class="app-shell pb-28 lg:pb-8">
        <header class="topbar">
            <div class="topbar-inner mx-auto max-w-7xl px-4 md:px-8 py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <img src="assets/sejahub_icon.png" alt="Logo SEJAHUB" class="brand-logo" onerror="this.style.display='none';document.getElementById('logoFallback').style.display='flex'">
                    <div id="logoFallback" class="hidden w-10 h-10 bg-black text-white items-center justify-center"><i data-lucide="droplets" class="w-5 h-5"></i></div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-extrabold tracking-[.18em] text-gray-400">SEJAHUB SUPER APP</p>
                        <h1 class="text-base md:text-lg font-extrabold truncate">Pemesanan Air Mineral</h1>
                    </div>
                </div>
                <div class="hidden sm:flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-100 text-[10px] font-bold text-gray-500">
                    <i data-lucide="shield-check" class="w-4 h-4"></i> Form Pemesanan Resmi
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 md:px-8 py-5 md:py-8">
            <div class="mb-6 md:mb-8">
                <p class="section-eyebrow">Layanan Internal SEJAHUB</p>
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mt-1">
                    <div>
                        <h2 class="text-2xl md:text-3xl font-extrabold tracking-tight">Form Pemesanan Air</h2>
                        <p class="text-xs md:text-sm text-gray-400 mt-2">Buat satu pesanan untuk beberapa lokasi dan beberapa jenis produk sekaligus.</p>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <section class="surface max-w-3xl mx-auto p-6 md:p-10">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto rounded-full border border-green-200 bg-green-50 text-green-700 flex items-center justify-center"><i data-lucide="check" class="w-8 h-8"></i></div>
                        <p class="section-eyebrow text-green-600 mt-5">Pesanan Berhasil Dikirim</p>
                        <h2 class="text-2xl font-extrabold mt-2"><?= pa_h($orderNumber) ?></h2>
                        <p class="text-sm text-gray-400 mt-2">Total jumlah pesanan <?= number_format($orderTotalQty) ?> unit</p>
                    </div>
                    <div class="mt-7 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($orderSummary as $row): ?>
                            <div class="border border-gray-100 bg-gray-50 p-4">
                                <p class="section-eyebrow"><?= pa_h($row['lokasi']) ?></p>
                                <p class="text-sm font-bold mt-2 leading-6"><?= pa_h(implode(' · ', $row['items'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-6 border border-blue-100 bg-blue-50 p-4 text-left">
                        <p class="section-eyebrow text-blue-700">Tracking Pesanan</p>
                        <p class="text-xs text-blue-700 mt-2 leading-5">Gunakan link berikut untuk memantau status pesanan tanpa login.</p>
                        <div class="mt-3 flex flex-col sm:flex-row gap-2">
                            <a href="<?= pa_h($trackingUrl) ?>" target="_blank" class="btn btn-light flex-1"><i data-lucide="map-pinned" class="w-4 h-4"></i>Lacak Pesanan</a>
                            <?php if ($whatsappUrl !== ''): ?>
                                <a href="<?= pa_h($whatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-dark flex-1"><i data-lucide="message-circle" class="w-4 h-4"></i>Kirim ke WhatsApp</a>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-blue-600 mt-3 break-all"><?= pa_h($trackingUrl) ?></p>
                    </div>
                    <a href="pesan_air.php" class="btn btn-dark mt-3 w-full"><i data-lucide="plus" class="w-4 h-4"></i>Buat Pesanan Lagi</a>
                </section>
            <?php else: ?>
                <?php if ($error !== ''): ?><div class="mb-5 border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700"><?= pa_h($error) ?></div><?php endif; ?>
                <form method="post" id="orderForm" class="page-grid">
                    <input type="hidden" name="csrf_token" value="<?= pa_h($csrfToken) ?>">

                    <aside class="desktop-sticky space-y-4">
                        <section class="surface mobile-tight p-5 md:p-6">
                            <div class="flex items-center gap-3 mb-5">
                                <span class="step-dot">1</span>
                                <div>
                                    <p class="section-eyebrow">Langkah Pertama</p>
                                    <h3 class="text-base font-extrabold mt-1">Data Pemesan</h3>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4">
                                <div>
                                    <label class="section-eyebrow block mb-2">Tanggal Pengiriman</label>
                                    <input type="date"
                                        name="tanggal_kirim"
                                        required
                                        min="<?= date('Y-m-d') ?>"
                                        value="<?= pa_h($_POST['tanggal_kirim'] ?? date('Y-m-d')) ?>"
                                        class="field">
                                    <p class="text-[9px] text-gray-400 mt-2">Tanggal pemesanan dicatat otomatis oleh sistem saat pesanan dikirim.</p>
                                </div>
                                <div><label class="section-eyebrow block mb-2">Nama Pemesan</label><input type="text" name="nama" required value="<?= pa_h($_POST['nama'] ?? '') ?>" class="field" placeholder="Nama lengkap"></div>
                                <div><label class="section-eyebrow block mb-2">Nomor WhatsApp</label><input type="tel" name="no_hp" required value="<?= pa_h($_POST['no_hp'] ?? '') ?>" class="field" placeholder="08xxxxxxxxxx"></div>
                            </div>
                        </section>

                        <section class="surface hidden lg:block p-5">
                            <p class="section-eyebrow">Cara Pengisian</p>
                            <div class="mt-4 space-y-4">
                                <div class="flex gap-3">
                                    <div class="step-dot">1</div>
                                    <div>
                                        <p class="text-xs font-bold">Isi data pemesan</p>
                                        <p class="text-[10px] text-gray-400 mt-1">Pastikan tanggal dan nomor WhatsApp benar.</p>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <div class="step-dot">2</div>
                                    <div>
                                        <p class="text-xs font-bold">Tambah lokasi</p>
                                        <p class="text-[10px] text-gray-400 mt-1">Satu transaksi dapat mencakup banyak lokasi.</p>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <div class="step-dot">3</div>
                                    <div>
                                        <p class="text-xs font-bold">Tambah produk</p>
                                        <p class="text-[10px] text-gray-400 mt-1">Atur jenis dan jumlah produk per lokasi.</p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </aside>

                    <section class="surface mobile-tight p-5 md:p-6 min-w-0">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="step-dot">2</span>
                                <div>
                                    <p class="section-eyebrow">Langkah Kedua</p>
                                    <h3 class="text-base md:text-lg font-extrabold mt-1">Rincian Pengantaran</h3>
                                    <p class="text-xs text-gray-400 mt-1">Tambahkan lokasi dan produk sesuai kebutuhan.</p>
                                </div>
                            </div>
                            <button type="button" onclick="addLocation()" class="btn btn-dark w-full sm:w-auto"><i data-lucide="map-pin-plus" class="w-4 h-4"></i>Tambah Lokasi</button>
                        </div>
                        <div id="locations" class="location-wrap space-y-4 mt-6"></div>

                        <div class="hidden lg:flex mt-6 border border-gray-100 bg-gray-50 p-4 items-center justify-between gap-4">
                            <div>
                                <p class="section-eyebrow">Total Jumlah Produk</p>
                                <p class="text-xs text-gray-400 mt-1">Jumlah seluruh produk dari semua lokasi.</p>
                            </div>
                            <p id="grandTotalDesktop" class="text-2xl font-extrabold">0 unit</p>
                        </div>
                        <button type="submit" class="hidden lg:flex btn btn-dark mt-4 w-full"><i data-lucide="send" class="w-4 h-4"></i>Kirim Pesanan</button>
                    </section>
                </form>
            <?php endif; ?>
        </main>

        <?php if (!$success): ?>
            <div class="summary-bar lg:hidden px-4 py-3">
                <div class="mx-auto max-w-7xl flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="section-eyebrow">Total Produk</p>
                        <p id="grandTotal" class="text-lg font-extrabold mt-1">0 unit</p>
                    </div>
                    <button type="submit" form="orderForm" class="btn btn-dark px-5"><i data-lucide="send" class="w-4 h-4"></i>Kirim</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <template id="locationTemplate">
        <div class="location-card" data-location>
            <div class="location-head p-4 md:p-5 flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 bg-white border border-gray-200 flex items-center justify-center shrink-0"><i data-lucide="map-pin" class="w-4 h-4"></i></div>
                    <div class="min-w-0">
                        <p class="section-eyebrow">Lokasi Pengantaran</p>
                        <p class="text-sm font-extrabold mt-1 location-title truncate">Lokasi Baru</p>
                    </div>
                </div>
                <button type="button" onclick="removeLocation(this)" class="icon-btn"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
            </div>
            <div class="p-4 md:p-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <select class="field location-select" required onchange="updateLocationTitle(this)"></select>
                    <input type="text" class="field location-note" placeholder="Catatan lokasi (opsional)">
                </div>
                <div class="mt-5">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <p class="section-eyebrow">Jenis Pemesanan</p>
                        <button type="button" onclick="addItem(this)" class="text-[10px] font-extrabold uppercase tracking-widest flex items-center gap-1"><i data-lucide="plus" class="w-3 h-3"></i>Tambah Produk</button>
                    </div>
                    <div class="items space-y-2"></div>
                </div>
            </div>
        </div>
    </template>

    <template id="itemTemplate">
        <div class="item-row" data-item>
            <select class="field product-select" required onchange="calculateTotal()"></select>
            <input type="number" min="1" value="1" class="field qty-input" required oninput="calculateTotal()" aria-label="Jumlah produk">
            <button type="button" onclick="removeItem(this)" class="icon-btn h-12"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
    </template>

    <script>
        var LOCATIONS = <?= json_encode($lokasiPilihan, JSON_UNESCAPED_UNICODE) ?>;
        var PRODUCTS = <?= json_encode($produkList, JSON_UNESCAPED_UNICODE) ?>;

        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                } [c]
            })
        }

        function locationOptions() {
            var h = '<option value="">Pilih lokasi...</option>';
            LOCATIONS.forEach(function(x) {
                h += '<option value="' + esc(x) + '">' + esc(x) + '</option>'
            });
            return h
        }

        function productOptions() {
            var h = '<option value="">Pilih jenis pemesanan...</option>';
            PRODUCTS.forEach(function(p) {
                h += '<option value="' + Number(p.id) + '" data-stock="' + Number(p.stok) + '">' + esc(p.nama_produk) + '</option>'
            });
            return h
        }

        function reindex() {
            document.querySelectorAll('[data-location]').forEach(function(loc, li) {
                var sel = loc.querySelector('.location-select'),
                    note = loc.querySelector('.location-note');
                sel.name = 'lokasi[' + li + '][nama]';
                note.name = 'lokasi[' + li + '][catatan]';
                loc.querySelectorAll('[data-item]').forEach(function(item, ii) {
                    item.querySelector('.product-select').name = 'lokasi[' + li + '][items][' + ii + '][produk_id]';
                    item.querySelector('.qty-input').name = 'lokasi[' + li + '][items][' + ii + '][qty]'
                })
            })
        }

        function addLocation() {
            var node = document.getElementById('locationTemplate').content.cloneNode(true);
            var loc = node.querySelector('[data-location]');
            loc.querySelector('.location-select').innerHTML = locationOptions();
            document.getElementById('locations').appendChild(node);
            var latest = document.querySelector('#locations [data-location]:last-child button[onclick="addItem(this)"]');
            addItem(latest);
            reindex();
            if (window.lucide) lucide.createIcons()
        }

        function removeLocation(btn) {
            var all = document.querySelectorAll('[data-location]');
            if (all.length <= 1) {
                alert('Minimal satu lokasi pengantaran.');
                return
            }
            btn.closest('[data-location]').remove();
            reindex();
            calculateTotal()
        }

        function addItem(btn) {
            var loc = btn.closest('[data-location]');
            var node = document.getElementById('itemTemplate').content.cloneNode(true);
            node.querySelector('.product-select').innerHTML = productOptions();
            loc.querySelector('.items').appendChild(node);
            reindex();
            calculateTotal();
            if (window.lucide) lucide.createIcons()
        }

        function removeItem(btn) {
            var loc = btn.closest('[data-location]');
            if (loc.querySelectorAll('[data-item]').length <= 1) {
                alert('Minimal satu jenis pemesanan pada setiap lokasi.');
                return
            }
            btn.closest('[data-item]').remove();
            reindex();
            calculateTotal()
        }

        function updateLocationTitle(sel) {
            sel.closest('[data-location]').querySelector('.location-title').textContent = sel.value || 'Lokasi Baru'
        }

        function calculateTotal() {
            var totalQty = 0;
            document.querySelectorAll('[data-item]').forEach(function(item) {
                var sel = item.querySelector('.product-select');
                var opt = sel.options[sel.selectedIndex];
                var stock = opt ? Number(opt.getAttribute('data-stock') || 0) : 0;
                var input = item.querySelector('.qty-input');
                var qty = Math.max(1, Number(input.value || 1));
                if (stock > 0 && qty > stock) {
                    qty = stock;
                    input.value = stock
                }
                if (opt && opt.value) {
                    totalQty += qty
                }
            });
            var label = totalQty.toLocaleString('id-ID') + ' unit';
            var mobile = document.getElementById('grandTotal');
            var desktop = document.getElementById('grandTotalDesktop');
            if (mobile) mobile.textContent = label;
            if (desktop) desktop.textContent = label
        }
        var form = document.getElementById('orderForm');
        if (form) {
            form.addEventListener('submit', function() {
                reindex();
                var buttons = document.querySelectorAll('button[type="submit"]');
                buttons.forEach(function(b) {
                    b.disabled = true;
                    b.textContent = 'Mengirim...'
                })
            })
        }
        addLocation();
        calculateTotal();
        if (window.lucide) lucide.createIcons();

        <?php if ($success && $whatsappUrl !== ''): ?>
            setTimeout(function() {
                var key = 'wa_order_sent_<?= pa_h($orderNumber) ?>';
                if (!sessionStorage.getItem(key)) {
                    sessionStorage.setItem(key, '1');
                    window.open(<?= json_encode($whatsappUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, '_blank');
                }
            }, 500);
        <?php endif; ?>
    </script>
</body>

</html>