<?php
session_start();
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'rental';
$pageTitle = 'Rental Bandara';
$backUrl = 'dashboard.php';


/*
|--------------------------------------------------------------------------
| rental_bandara.php
|--------------------------------------------------------------------------
| Admin booking transport bandara.
|
| Fitur:
| - Lihat booking masuk
| - Filter status / tanggal / pencarian
| - Assign driver mitra
| - Ubah status: pending, diproses, driver_menuju_lokasi,
|   dalam_perjalanan, selesai, batal
| - Menampilkan data member, kendaraan, driver, plat, dan kontak
|
| Catatan:
| - Tampilan tetap mengikuti tema admin sebelumnya.
| - Helper dibungkus function_exists agar tidak bentrok dengan config.php.
*/

if (!isset($_SESSION['user_id']) && !isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('h')) {
    /** @param mixed $v */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

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

if (!function_exists('waktu')) {
    /** @param mixed $v */
    function waktu($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('tanggal_id')) {
    /** @param mixed $v */
    function tanggal_id($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('jam_id')) {
    /** @param mixed $v */
    function jam_id($v): string
    {
        return $v ? date('H:i', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('label_layanan')) {
    /** @param mixed $v */
    function label_layanan($v): string
    {
        return ((string)$v === 'jemput_bandara') ? 'Jemput Bandara' : 'Antar Bandara';
    }
}

if (!function_exists('status_label')) {
    /** @param mixed $status */
    function status_label($status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'diproses') {
            return 'Diproses';
        }

        if ($status === 'driver_menuju_lokasi') {
            return 'Driver Menuju Lokasi';
        }

        if ($status === 'dalam_perjalanan') {
            return 'Dalam Perjalanan';
        }

        if ($status === 'selesai') {
            return 'Selesai';
        }

        if ($status === 'batal') {
            return 'Batal';
        }

        return 'Pending';
    }
}

if (!function_exists('status_class')) {
    /** @param mixed $status */
    function status_class($status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'diproses') {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }

        if ($status === 'driver_menuju_lokasi') {
            return 'bg-indigo-50 text-indigo-700 border-indigo-200';
        }

        if ($status === 'dalam_perjalanan') {
            return 'bg-purple-50 text-purple-700 border-purple-200';
        }

        if ($status === 'selesai') {
            return 'bg-green-50 text-green-700 border-green-200';
        }

        if ($status === 'batal') {
            return 'bg-red-50 text-red-700 border-red-200';
        }

        return 'bg-orange-50 text-orange-700 border-orange-200';
    }
}


/**
 * @param mixed $v
 */
function normalize_tipe_kendaraan($v): string
{
    $v = strtolower(trim((string)$v));

    if (strpos($v, 'hiace') !== false) {
        return 'hiace';
    }

    if (strpos($v, 'innova') !== false || strpos($v, 'inova') !== false) {
        return 'innova';
    }

    if (strpos($v, 'avanza') !== false) {
        return 'avanza';
    }

    return $v;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$index]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_rental_bandara_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(50) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            no_hp VARCHAR(30) NOT NULL,
            alamat TEXT DEFAULT NULL,
            kendaraan_nama VARCHAR(120) NOT NULL,
            kendaraan_tipe VARCHAR(80) DEFAULT NULL,
            plat_nomor VARCHAR(30) NOT NULL,
            kapasitas INT NOT NULL DEFAULT 4,
            harga_bandara INT NOT NULL DEFAULT 0,
            status_online VARCHAR(30) NOT NULL DEFAULT 'offline',
            status_aktif VARCHAR(30) NOT NULL DEFAULT 'aktif',
            rating DECIMAL(3,2) NOT NULL DEFAULT 5.00,
            saldo INT NOT NULL DEFAULT 0,
            catatan VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY kode (kode),
            KEY no_hp (no_hp),
            KEY status_online (status_online),
            KEY status_aktif (status_aktif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kendaraan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(50) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            tipe VARCHAR(80) DEFAULT NULL,
            plat_nomor VARCHAR(30) DEFAULT NULL,
            kapasitas INT NOT NULL DEFAULT 4,
            harga_bandara INT NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'tersedia',
            catatan VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY kode (kode),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rental_bandara (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_booking VARCHAR(80) NOT NULL,
            member_id INT DEFAULT NULL,
            kendaraan_id INT DEFAULT NULL,
            driver_id INT DEFAULT NULL,
            nama_pemesan VARCHAR(120) NOT NULL,
            no_hp VARCHAR(30) NOT NULL,
            layanan VARCHAR(50) NOT NULL,
            lokasi_jemput TEXT NOT NULL,
            tujuan TEXT NOT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            jumlah_penumpang INT DEFAULT 1,
            kendaraan VARCHAR(120) DEFAULT NULL,
            total_harga INT DEFAULT 0,
            status VARCHAR(30) DEFAULT 'pending',
            catatan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY kode_booking (kode_booking),
            KEY member_id (member_id),
            KEY kendaraan_id (kendaraan_id),
            KEY driver_id (driver_id),
            KEY status (status),
            KEY tanggal (tanggal),
            KEY tipe_kendaraan (tipe_kendaraan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    try {
        if (!column_exists($pdo, 'rental_bandara', 'kendaraan_id')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD COLUMN kendaraan_id INT DEFAULT NULL AFTER member_id");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!index_exists($pdo, 'rental_bandara', 'kendaraan_id')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD KEY kendaraan_id (kendaraan_id)");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!column_exists($pdo, 'rental_bandara', 'driver_id')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD COLUMN driver_id INT DEFAULT NULL AFTER kendaraan_id");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!index_exists($pdo, 'rental_bandara', 'driver_id')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD KEY driver_id (driver_id)");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!column_exists($pdo, 'rental_bandara', 'tipe_kendaraan')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD COLUMN tipe_kendaraan VARCHAR(80) DEFAULT NULL AFTER kendaraan");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!index_exists($pdo, 'rental_bandara', 'tipe_kendaraan')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD KEY tipe_kendaraan (tipe_kendaraan)");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!column_exists($pdo, 'rental_bandara', 'kapasitas_kendaraan')) {
            $pdo->exec("ALTER TABLE rental_bandara ADD COLUMN kapasitas_kendaraan INT DEFAULT NULL AFTER tipe_kendaraan");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }
}

ensure_rental_bandara_table($pdo);

$error = '';
$success = '';

$allowedStatus = [
    'pending',
    'diproses',
    'driver_menuju_lokasi',
    'dalam_perjalanan',
    'selesai',
    'batal'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $driverId = (int)($_POST['driver_id'] ?? 0);

    if ($id <= 0) {
        $error = 'Booking tidak valid.';
    } elseif (!in_array($status, $allowedStatus, true)) {
        $error = 'Status tidak valid.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtOld = $pdo->prepare("
                SELECT id, kendaraan_id, driver_id, kendaraan, tipe_kendaraan, kapasitas_kendaraan, total_harga
                FROM rental_bandara
                WHERE id = :id
                LIMIT 1
            ");
            $stmtOld->execute([':id' => $id]);
            $oldBooking = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldBooking) {
                throw new Exception('Booking tidak ditemukan.');
            }

            $driverData = null;

            if ($driverId > 0) {
                $stmtDriver = $pdo->prepare("
                    SELECT id, nama, no_hp, kendaraan_nama, kendaraan_tipe, plat_nomor, kapasitas, harga_bandara, status_aktif
                    FROM driver
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtDriver->execute([':id' => $driverId]);
                $driverData = $stmtDriver->fetch(PDO::FETCH_ASSOC);

                if (!$driverData || ($driverData['status_aktif'] ?? '') !== 'aktif') {
                    throw new Exception('Driver tidak ditemukan atau tidak aktif.');
                }

                $bookingTipe = normalize_tipe_kendaraan($oldBooking['tipe_kendaraan'] ?? $oldBooking['kendaraan'] ?? '');
                $driverTipe = normalize_tipe_kendaraan($driverData['kendaraan_tipe'] ?? $driverData['kendaraan_nama'] ?? '');

                if ($bookingTipe !== '' && $driverTipe !== '' && $bookingTipe !== $driverTipe) {
                    throw new Exception('Jenis kendaraan driver tidak sesuai dengan pilihan member.');
                }
            }

            $kendaraanUpdate = $driverData['kendaraan_nama'] ?? ($oldBooking['kendaraan'] ?? 'Menunggu Driver');
            $hargaUpdate = isset($driverData['harga_bandara'])
                ? (int)$driverData['harga_bandara']
                : (int)($oldBooking['total_harga'] ?? 0);

            $stmtUpdate = $pdo->prepare("
                UPDATE rental_bandara
                SET status = :status,
                    driver_id = :driver_id,
                    kendaraan = :kendaraan,
                    total_harga = :total_harga
                WHERE id = :id
                LIMIT 1
            ");
            $stmtUpdate->execute([
                ':status' => $status,
                ':driver_id' => $driverId > 0 ? $driverId : null,
                ':kendaraan' => $kendaraanUpdate,
                ':total_harga' => $hargaUpdate,
                ':id' => $id,
            ]);

            if (!empty($oldBooking['driver_id']) && (int)$oldBooking['driver_id'] !== $driverId) {
                $stmtDriverOld = $pdo->prepare("
                    UPDATE driver
                    SET status_online = 'online',
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtDriverOld->execute([
                    ':id' => (int)$oldBooking['driver_id']
                ]);
            }

            if ($driverId > 0) {
                $driverOnline = in_array($status, ['diproses', 'driver_menuju_lokasi', 'dalam_perjalanan'], true)
                    ? 'offline'
                    : 'online';

                $stmtDriverStatus = $pdo->prepare("
                    UPDATE driver
                    SET status_online = :status_online,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtDriverStatus->execute([
                    ':status_online' => $driverOnline,
                    ':id' => $driverId,
                ]);
            }

            if (!empty($oldBooking['kendaraan_id'])) {
                $kendaraanStatus = null;

                if (in_array($status, ['diproses', 'driver_menuju_lokasi', 'dalam_perjalanan'], true)) {
                    $kendaraanStatus = 'digunakan';
                } elseif ($status === 'selesai' || $status === 'batal') {
                    $kendaraanStatus = 'tersedia';
                }

                if ($kendaraanStatus !== null) {
                    $stmtKendaraan = $pdo->prepare("
                        UPDATE kendaraan
                        SET status = :status,
                            updated_at = NOW()
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $stmtKendaraan->execute([
                        ':status' => $kendaraanStatus,
                        ':id' => (int)$oldBooking['kendaraan_id'],
                    ]);
                }
            }

            $pdo->commit();

            $success = 'Status booking berhasil diperbarui.';
            catat_aktivitas($pdo, 'status', 'Rental Bandara', 'Mengubah status booking ID: ' . $id . ' menjadi ' . $status);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Gagal memperbarui status: ' . $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$awal = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));

$summary = [
    'total' => 0,
    'pending' => 0,
    'diproses' => 0,
    'driver_menuju_lokasi' => 0,
    'dalam_perjalanan' => 0,
    'selesai' => 0,
    'batal' => 0,
    'omzet' => 0
];

$bookings = [];
$driversReady = [];

try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) AS pending,
            COALESCE(SUM(CASE WHEN status='diproses' THEN 1 ELSE 0 END),0) AS diproses,
            COALESCE(SUM(CASE WHEN status='driver_menuju_lokasi' THEN 1 ELSE 0 END),0) AS driver_menuju_lokasi,
            COALESCE(SUM(CASE WHEN status='dalam_perjalanan' THEN 1 ELSE 0 END),0) AS dalam_perjalanan,
            COALESCE(SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END),0) AS selesai,
            COALESCE(SUM(CASE WHEN status='batal' THEN 1 ELSE 0 END),0) AS batal,
            COALESCE(SUM(CASE WHEN status='selesai' THEN total_harga ELSE 0 END),0) AS omzet
        FROM rental_bandara
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $k => $v) {
        $summary[$k] = (int)($row[$k] ?? 0);
    }

    $stmtDrivers = $pdo->query("
        SELECT id, kode, nama, no_hp, kendaraan_nama, kendaraan_tipe, plat_nomor, kapasitas, harga_bandara, rating, status_online, status_aktif
        FROM driver
        WHERE status_aktif = 'aktif'
        ORDER BY
            CASE status_online WHEN 'online' THEN 0 ELSE 1 END,
            rating DESC,
            nama ASC
    ");
    $driversReady = $stmtDrivers->fetchAll(PDO::FETCH_ASSOC);

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            rb.kode_booking LIKE :q1
            OR rb.nama_pemesan LIKE :q2
            OR rb.no_hp LIKE :q3
            OR rb.lokasi_jemput LIKE :q4
            OR rb.tujuan LIKE :q5
            OR rb.kendaraan LIKE :q6
            OR d.nama LIKE :q7
            OR d.no_hp LIKE :q8
            OR d.plat_nomor LIKE :q9
        )";
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
        $params[':q5'] = '%' . $q . '%';
        $params[':q6'] = '%' . $q . '%';
        $params[':q7'] = '%' . $q . '%';
        $params[':q8'] = '%' . $q . '%';
        $params[':q9'] = '%' . $q . '%';
    }

    if (in_array($statusFilter, $allowedStatus, true)) {
        $where[] = 'rb.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($awal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
        $where[] = 'rb.tanggal >= :awal';
        $params[':awal'] = $awal;
    }

    if ($akhir !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) {
        $where[] = 'rb.tanggal <= :akhir';
        $params[':akhir'] = $akhir;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT
            rb.*,
            m.kode AS kode_member,
            k.nama AS kendaraan_nama,
            k.kode AS kendaraan_kode,
            k.plat_nomor AS kendaraan_plat,
            k.kapasitas AS kendaraan_kapasitas,
            k.harga_bandara AS kendaraan_harga,
            d.nama AS driver_nama,
            d.no_hp AS driver_no_hp,
            d.kendaraan_nama AS driver_kendaraan,
            d.plat_nomor AS driver_plat,
            d.rating AS driver_rating
        FROM rental_bandara rb
        LEFT JOIN member m ON m.id = rb.member_id
        LEFT JOIN kendaraan k ON k.id = rb.kendaraan_id
        LEFT JOIN driver d ON d.id = rb.driver_id
        $whereSql
        ORDER BY
            CASE rb.status
                WHEN 'pending' THEN 0
                WHEN 'diproses' THEN 1
                WHEN 'driver_menuju_lokasi' THEN 2
                WHEN 'dalam_perjalanan' THEN 3
                WHEN 'selesai' THEN 4
                ELSE 5
            END,
            rb.tanggal ASC,
            rb.jam ASC,
            rb.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data booking: ' . $e->getMessage();
}

$loginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');

catat_view_once($pdo, 'Rental Bandara', 'Membuka halaman Rental Bandara');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Rental Bandara</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <link rel="shortcut icon" type="image/png" href="assets/sejahub_icon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
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

        tbody tr:hover {
            background: #f9f9f9;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #111 !important;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .05);
        }

        #mobileMenuOverlay {
            transition: opacity .3s ease, visibility .3s ease;
        }

        #mobileMenuContent {
            transition: transform .3s cubic-bezier(.4, 0, .2, 1);
        }

        .card-list {
            display: none;
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .app-header,
            .main-wrap {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .app-header,
            .main-wrap {
                margin-left: 0 !important;
            }

            .main-content {
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
        }

        @media (max-width: 640px) {
            .card-list {
                grid-template-columns: 1fr;
            }

            .header-title {
                max-width: 170px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }
        }

        /* Shared layout aliases for sidebar.php/navbar.php */
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
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <div class="main-wrap">
        <main class="main-content p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-xs font-bold">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Booking</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Semua data</p>
                </div>

                <div class="bg-white border <?= $summary['pending'] > 0 ? 'border-orange-200' : 'border-subtle' ?> p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pending</p>
                    <p class="text-2xl font-bold <?= $summary['pending'] > 0 ? 'text-orange-600' : '' ?>"><?= angka($summary['pending']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Butuh konfirmasi</p>
                </div>

                <div class="bg-white border <?= ($summary['diproses'] + $summary['driver_menuju_lokasi'] + $summary['dalam_perjalanan']) > 0 ? 'border-blue-200' : 'border-subtle' ?> p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Berjalan</p>
                    <p class="text-2xl font-bold <?= ($summary['diproses'] + $summary['driver_menuju_lokasi'] + $summary['dalam_perjalanan']) > 0 ? 'text-blue-600' : '' ?>">
                        <?= angka($summary['diproses'] + $summary['driver_menuju_lokasi'] + $summary['dalam_perjalanan']) ?>
                    </p>
                    <p class="text-[10px] text-gray-400 mt-1">Diproses/perjalanan</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Selesai</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($summary['selesai']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Layanan selesai</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Batal</p>
                    <p class="text-2xl font-bold text-red-600"><?= angka($summary['batal']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Booking batal</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Omzet Selesai</p>
                    <p class="text-xl font-bold"><?= rupiah($summary['omzet']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Status selesai</p>
                </div>
            </div>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto_auto] gap-3 items-end">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Booking</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Kode / nama / no HP / lokasi / driver / plat">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <?php foreach ($allowedStatus as $st): ?>
                                <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                                    <?= h(status_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Awal</label>
                        <input type="date" name="awal" value="<?= h($awal) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Akhir</label>
                        <input type="date" name="akhir" value="<?= h($akhir) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tampilkan
                    </button>

                    <a href="rental_bandara.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section id="booking-list" class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Daftar Booking</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($bookings)) ?> booking ditampilkan</p>
                    </div>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:1350px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Booking</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Pemesan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Layanan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Jadwal</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Rute</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Armada</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Driver</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$bookings): ?>
                                <tr>
                                    <td colspan="10" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada booking transport
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <div class="font-semibold text-sm leading-tight"><?= h($b['kode_booking']) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h(waktu($b['created_at'])) ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="font-semibold text-sm"><?= h($b['nama_pemesan']) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h($b['no_hp']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono"><?= h($b['kode_member'] ?: '-') ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-bold"><?= h(label_layanan($b['layanan'])) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= angka($b['jumlah_penumpang']) ?> penumpang</div>
                                    </td>

                                    <td class="px-5 py-4 align-top whitespace-nowrap">
                                        <div class="text-sm font-bold"><?= h(tanggal_id($b['tanggal'])) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h(jam_id($b['jam'])) ?> WIB</div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-xs text-gray-500 max-w-[240px]">
                                            <strong>Jemput:</strong> <?= h($b['lokasi_jemput']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500 max-w-[240px] mt-1">
                                            <strong>Tujuan:</strong> <?= h($b['tujuan']) ?>
                                        </div>
                                        <?php if (!empty($b['catatan'])): ?>
                                            <div class="text-[10px] text-gray-400 max-w-[240px] mt-1">
                                                Catatan: <?= h($b['catatan']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-bold"><?= h($b['kendaraan'] ?: '-') ?></div>
                                        <div class="text-[10px] text-gray-400">
                                            Permintaan: <?= h($b['tipe_kendaraan'] ?: '-') ?><?= !empty($b['kapasitas_kendaraan']) ? ' · ' . angka($b['kapasitas_kendaraan']) . ' seat' : '' ?>
                                        </div>
                                        <?php if (!empty($b['driver_kendaraan'])): ?>
                                            <div class="text-[10px] text-gray-400">Unit: <?= h($b['driver_kendaraan']) ?> · <?= h($b['driver_plat'] ?: '-') ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-bold"><?= h($b['driver_nama'] ?: '-') ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h($b['driver_no_hp'] ?: '-') ?></div>
                                        <div class="text-[10px] text-gray-400"><?= $b['driver_rating'] !== null ? '⭐ ' . h(number_format((float)$b['driver_rating'], 2)) : '-' ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top text-right">
                                        <div class="text-sm font-black"><?= rupiah($b['total_harga']) ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <span class="<?= h(status_class($b['status'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                            <?= h(status_label($b['status'])) ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <form method="POST" class="flex items-center gap-2">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?= h($b['id']) ?>">

                                            <select name="driver_id" class="bg-gray-50 border border-gray-100 px-2 py-2 text-xs max-w-[210px]">
                                                <option value="">Pilih Driver</option>
                                                <?php foreach ($driversReady as $drv): ?>
                                                    <?php
                                                    $bookingTipeOption = normalize_tipe_kendaraan($b['tipe_kendaraan'] ?? $b['kendaraan'] ?? '');
                                                    $driverTipeOption = normalize_tipe_kendaraan($drv['kendaraan_tipe'] ?? $drv['kendaraan_nama'] ?? '');
                                                    if ($bookingTipeOption !== '' && $driverTipeOption !== '' && $bookingTipeOption !== $driverTipeOption) {
                                                        continue;
                                                    }
                                                    ?>
                                                    <option value="<?= h($drv['id']) ?>" <?= ((int)($b['driver_id'] ?? 0) === (int)$drv['id']) ? 'selected' : '' ?>>
                                                        <?= h($drv['nama']) ?> · <?= h($drv['kendaraan_nama']) ?> · <?= h($drv['plat_nomor']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <select name="status" class="bg-gray-50 border border-gray-100 px-2 py-2 text-xs">
                                                <?php foreach ($allowedStatus as $st): ?>
                                                    <option value="<?= h($st) ?>" <?= ($b['status'] === $st) ? 'selected' : '' ?>>
                                                        <?= h(status_label($st)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" onclick="return confirm('Ubah driver/status booking ini?')" class="px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                                                Simpan
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-list">
                    <?php if (!$bookings): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Belum ada booking transport
                        </div>
                    <?php endif; ?>

                    <?php foreach ($bookings as $b): ?>
                        <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-bold text-sm"><?= h($b['kode_booking']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= h(waktu($b['created_at'])) ?></p>
                                </div>

                                <span class="<?= h(status_class($b['status'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                    <?= h(status_label($b['status'])) ?>
                                </span>
                            </div>

                            <div class="pt-2 border-t border-subtle">
                                <p class="text-sm font-bold"><?= h($b['nama_pemesan']) ?></p>
                                <p class="text-[10px] text-gray-400"><?= h($b['no_hp']) ?> · <?= h($b['kode_member'] ?: '-') ?></p>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Layanan</span>
                                    <p class="font-bold"><?= h(label_layanan($b['layanan'])) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Jadwal</span>
                                    <p class="font-bold"><?= h(tanggal_id($b['tanggal'])) ?> <?= h(jam_id($b['jam'])) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Armada</span>
                                    <p class="font-bold"><?= h($b['driver_kendaraan'] ?: $b['kendaraan_nama'] ?: $b['kendaraan'] ?: '-') ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Plat</span>
                                    <p class="font-bold"><?= h($b['driver_plat'] ?: $b['kendaraan_plat'] ?: '-') ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Driver</span>
                                    <p class="font-bold"><?= h($b['driver_nama'] ?: '-') ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Harga</span>
                                    <p class="font-bold"><?= rupiah($b['total_harga']) ?></p>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-subtle text-xs text-gray-500 leading-relaxed">
                                <p><strong>Jemput:</strong> <?= h($b['lokasi_jemput']) ?></p>
                                <p class="mt-1"><strong>Tujuan:</strong> <?= h($b['tujuan']) ?></p>
                                <?php if (!empty($b['catatan'])): ?>
                                    <p class="mt-1"><strong>Catatan:</strong> <?= h($b['catatan']) ?></p>
                                <?php endif; ?>
                            </div>

                            <form method="POST" class="pt-2 border-t border-subtle grid grid-cols-1 gap-2">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= h($b['id']) ?>">

                                <select name="driver_id" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs">
                                    <option value="">Pilih Driver</option>
                                    <?php foreach ($driversReady as $drv): ?>
                                        <?php
                                        $bookingTipeOption = normalize_tipe_kendaraan($b['tipe_kendaraan'] ?? $b['kendaraan'] ?? '');
                                        $driverTipeOption = normalize_tipe_kendaraan($drv['kendaraan_tipe'] ?? $drv['kendaraan_nama'] ?? '');
                                        if ($bookingTipeOption !== '' && $driverTipeOption !== '' && $bookingTipeOption !== $driverTipeOption) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?= h($drv['id']) ?>" <?= ((int)($b['driver_id'] ?? 0) === (int)$drv['id']) ? 'selected' : '' ?>>
                                            <?= h($drv['nama']) ?> · <?= h($drv['kendaraan_nama']) ?> · <?= h($drv['plat_nomor']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="status" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs">
                                    <?php foreach ($allowedStatus as $st): ?>
                                        <option value="<?= h($st) ?>" <?= ($b['status'] === $st) ? 'selected' : '' ?>>
                                            <?= h(status_label($st)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" onclick="return confirm('Ubah driver/status booking ini?')" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                    Simpan
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    <!-- 
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            ☰
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>

        <a href="driver.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            🚗
        </a>

        <a href="rental_bandara.php" class="flex flex-col items-center p-2">
            ✈️
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Bandara</span>
        </a>
    </nav> -->

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
    </script>
</body>

</html>