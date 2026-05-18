<?php
session_start();
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'driver';
$pageTitle = 'Driver Mitra';
$backUrl = 'dashboard.php';


/*
|--------------------------------------------------------------------------
| driver.php
|--------------------------------------------------------------------------
| Admin manajemen mitra driver transport bandara.
|
| Model:
| - Driver adalah mitra/pemilik kendaraan
| - Data kendaraan melekat pada driver
| - Cocok untuk model seperti Gojek / Grab
|
| Fitur:
| - Tambah driver
| - Edit driver
| - Status online/offline
| - Status aktif/nonaktif
| - Data kendaraan driver
| - Tarif bandara driver
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

/** @param mixed $status */
function driver_status_online_label($status): string
{
    return strtolower((string)$status) === 'online' ? 'Online' : 'Offline';
}

/** @param mixed $status */
function driver_status_aktif_label($status): string
{
    return strtolower((string)$status) === 'nonaktif' ? 'Nonaktif' : 'Aktif';
}

/** @param mixed $status */
function driver_status_online_class($status): string
{
    return strtolower((string)$status) === 'online'
        ? 'bg-green-50 text-green-700 border-green-200'
        : 'bg-gray-50 text-gray-600 border-gray-200';
}

/** @param mixed $status */
function driver_status_aktif_class($status): string
{
    return strtolower((string)$status) === 'nonaktif'
        ? 'bg-red-50 text-red-700 border-red-200'
        : 'bg-blue-50 text-blue-700 border-blue-200';
}


/**
 * @param mixed $v
 */
function normalize_tipe_driver($v): string
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

    return $v !== '' ? $v : 'avanza';
}


/**
 * @param mixed $v
 */
function label_tipe_driver($v): string
{
    $v = normalize_tipe_driver($v);

    if ($v === 'innova') {
        return 'Innova';
    }

    if ($v === 'hiace') {
        return 'Hiace';
    }

    return 'Avanza';
}

function ensure_driver_table(PDO $pdo): void
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
}

function generate_kode_driver(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT kode FROM driver WHERE kode LIKE 'DRV%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/DRV(\d+)/', (string)$last, $m)) {
        $next = ((int)$m[1]) + 1;
    } else {
        $next = 1;
    }

    return 'DRV' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

ensure_driver_table($pdo);

$error = '';
$success = '';

$allowedOnline = ['online', 'offline'];
$allowedAktif = ['aktif', 'nonaktif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $kode = trim((string)($_POST['kode'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $noHp = preg_replace('/[^0-9]/', '', (string)($_POST['no_hp'] ?? ''));
        $alamat = trim((string)($_POST['alamat'] ?? ''));
        $kendaraanNama = trim((string)($_POST['kendaraan_nama'] ?? ''));
        $kendaraanTipe = normalize_tipe_driver($_POST['kendaraan_tipe'] ?? 'avanza');
        $platNomor = strtoupper(trim((string)($_POST['plat_nomor'] ?? '')));
        $kapasitas = (int)($_POST['kapasitas'] ?? 4);
        $hargaBandara = (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga_bandara'] ?? '0'));
        $statusOnline = strtolower(trim((string)($_POST['status_online'] ?? 'offline')));
        $statusAktif = strtolower(trim((string)($_POST['status_aktif'] ?? 'aktif')));
        $rating = (float)($_POST['rating'] ?? 5);
        $saldo = (int)preg_replace('/[^0-9]/', '', (string)($_POST['saldo'] ?? '0'));
        $catatan = trim((string)($_POST['catatan'] ?? ''));

        if ($kode === '') {
            $kode = generate_kode_driver($pdo);
        }

        if ($nama === '') {
            $error = 'Nama driver wajib diisi.';
        } elseif ($noHp === '') {
            $error = 'Nomor HP driver wajib diisi.';
        } elseif (strlen($noHp) < 9) {
            $error = 'Nomor HP driver tidak valid.';
        } elseif ($kendaraanNama === '') {
            $error = 'Nama kendaraan wajib diisi.';
        } elseif (!in_array($kendaraanTipe, ['avanza', 'innova', 'hiace'], true)) {
            $error = 'Jenis kendaraan tidak valid.';
        } elseif ($platNomor === '') {
            $error = 'Plat nomor wajib diisi.';
        } elseif ($kapasitas <= 0) {
            $error = 'Kapasitas kendaraan tidak valid.';
        } elseif ($hargaBandara < 0) {
            $error = 'Harga bandara tidak valid.';
        } elseif (!in_array($statusOnline, $allowedOnline, true)) {
            $error = 'Status online tidak valid.';
        } elseif (!in_array($statusAktif, $allowedAktif, true)) {
            $error = 'Status aktif tidak valid.';
        } else {
            if ($rating < 0) {
                $rating = 0;
            }

            if ($rating > 5) {
                $rating = 5;
            }

            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE driver
                        SET kode = :kode,
                            nama = :nama,
                            no_hp = :no_hp,
                            alamat = :alamat,
                            kendaraan_nama = :kendaraan_nama,
                            kendaraan_tipe = :kendaraan_tipe,
                            plat_nomor = :plat_nomor,
                            kapasitas = :kapasitas,
                            harga_bandara = :harga_bandara,
                            status_online = :status_online,
                            status_aktif = :status_aktif,
                            rating = :rating,
                            saldo = :saldo,
                            catatan = :catatan,
                            updated_at = NOW()
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':kode' => $kode,
                        ':nama' => $nama,
                        ':no_hp' => $noHp,
                        ':alamat' => $alamat !== '' ? $alamat : null,
                        ':kendaraan_nama' => $kendaraanNama,
                        ':kendaraan_tipe' => $kendaraanTipe !== '' ? $kendaraanTipe : null,
                        ':plat_nomor' => $platNomor,
                        ':kapasitas' => $kapasitas,
                        ':harga_bandara' => $hargaBandara,
                        ':status_online' => $statusOnline,
                        ':status_aktif' => $statusAktif,
                        ':rating' => $rating,
                        ':saldo' => $saldo,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                        ':id' => $id,
                    ]);

                    $success = 'Data driver berhasil diperbarui.';
                    catat_aktivitas($pdo, 'update', 'Driver Mitra', 'Mengubah data driver: ' . $nama);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO driver
                        (
                            kode,
                            nama,
                            no_hp,
                            alamat,
                            kendaraan_nama,
                            kendaraan_tipe,
                            plat_nomor,
                            kapasitas,
                            harga_bandara,
                            status_online,
                            status_aktif,
                            rating,
                            saldo,
                            catatan,
                            created_at,
                            updated_at
                        )
                        VALUES
                        (
                            :kode,
                            :nama,
                            :no_hp,
                            :alamat,
                            :kendaraan_nama,
                            :kendaraan_tipe,
                            :plat_nomor,
                            :kapasitas,
                            :harga_bandara,
                            :status_online,
                            :status_aktif,
                            :rating,
                            :saldo,
                            :catatan,
                            NOW(),
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        ':kode' => $kode,
                        ':nama' => $nama,
                        ':no_hp' => $noHp,
                        ':alamat' => $alamat !== '' ? $alamat : null,
                        ':kendaraan_nama' => $kendaraanNama,
                        ':kendaraan_tipe' => $kendaraanTipe !== '' ? $kendaraanTipe : null,
                        ':plat_nomor' => $platNomor,
                        ':kapasitas' => $kapasitas,
                        ':harga_bandara' => $hargaBandara,
                        ':status_online' => $statusOnline,
                        ':status_aktif' => $statusAktif,
                        ':rating' => $rating,
                        ':saldo' => $saldo,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                    ]);

                    $success = 'Driver berhasil ditambahkan.';
                    catat_aktivitas($pdo, 'create', 'Driver Mitra', 'Menambah driver baru: ' . $nama);
                }
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate') !== false) {
                    $error = 'Kode driver sudah digunakan.';
                } else {
                    $error = 'Gagal menyimpan driver: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $statusOnline = strtolower(trim((string)($_POST['status_online'] ?? 'offline')));
        $statusAktif = strtolower(trim((string)($_POST['status_aktif'] ?? 'aktif')));

        if ($id <= 0) {
            $error = 'Driver tidak valid.';
        } elseif (!in_array($statusOnline, $allowedOnline, true)) {
            $error = 'Status online tidak valid.';
        } elseif (!in_array($statusAktif, $allowedAktif, true)) {
            $error = 'Status aktif tidak valid.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE driver
                    SET status_online = :status_online,
                        status_aktif = :status_aktif,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':status_online' => $statusOnline,
                    ':status_aktif' => $statusAktif,
                    ':id' => $id,
                ]);

                $success = 'Status driver berhasil diperbarui.';
                catat_aktivitas($pdo, 'status', 'Driver Mitra', 'Mengubah status driver ID: ' . $id);
            } catch (Throwable $e) {
                $error = 'Gagal memperbarui status driver: ' . $e->getMessage();
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$onlineFilter = strtolower(trim((string)($_GET['online'] ?? '')));
$aktifFilter = strtolower(trim((string)($_GET['aktif'] ?? '')));
$editId = (int)($_GET['edit'] ?? 0);

$summary = [
    'total' => 0,
    'online' => 0,
    'offline' => 0,
    'aktif' => 0,
    'nonaktif' => 0,
    'kapasitas' => 0,
];

$drivers = [];
$editData = null;

try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status_online = 'online' THEN 1 ELSE 0 END), 0) AS online,
            COALESCE(SUM(CASE WHEN status_online = 'offline' THEN 1 ELSE 0 END), 0) AS offline,
            COALESCE(SUM(CASE WHEN status_aktif = 'aktif' THEN 1 ELSE 0 END), 0) AS aktif,
            COALESCE(SUM(CASE WHEN status_aktif = 'nonaktif' THEN 1 ELSE 0 END), 0) AS nonaktif,
            COALESCE(SUM(CASE WHEN status_aktif = 'aktif' THEN kapasitas ELSE 0 END), 0) AS kapasitas
        FROM driver
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $k => $v) {
        $summary[$k] = (int)($row[$k] ?? 0);
    }

    if ($editId > 0) {
        $stmtEdit = $pdo->prepare("SELECT * FROM driver WHERE id = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $editData = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            kode LIKE :q1
            OR nama LIKE :q2
            OR no_hp LIKE :q3
            OR kendaraan_nama LIKE :q4
            OR kendaraan_tipe LIKE :q5
            OR plat_nomor LIKE :q6
        )";
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
        $params[':q5'] = '%' . $q . '%';
        $params[':q6'] = '%' . $q . '%';
    }

    if (in_array($onlineFilter, $allowedOnline, true)) {
        $where[] = "status_online = :online";
        $params[':online'] = $onlineFilter;
    }

    if (in_array($aktifFilter, $allowedAktif, true)) {
        $where[] = "status_aktif = :aktif";
        $params[':aktif'] = $aktifFilter;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtList = $pdo->prepare("
        SELECT *
        FROM driver
        $whereSql
        ORDER BY
            CASE status_aktif
                WHEN 'aktif' THEN 0
                ELSE 1
            END,
            CASE status_online
                WHEN 'online' THEN 0
                ELSE 1
            END,
            nama ASC,
            id DESC
        LIMIT 200
    ");
    $stmtList->execute($params);
    $drivers = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data driver: ' . $e->getMessage();
}

$formKode = $editData['kode'] ?? generate_kode_driver($pdo);
$formNama = $editData['nama'] ?? '';
$formHp = $editData['no_hp'] ?? '';
$formAlamat = $editData['alamat'] ?? '';
$formKendaraan = $editData['kendaraan_nama'] ?? '';
$formTipe = $editData['kendaraan_tipe'] ?? '';
$formPlat = $editData['plat_nomor'] ?? '';
$formKapasitas = $editData['kapasitas'] ?? 4;
$formHarga = $editData['harga_bandara'] ?? 0;
$formOnline = $editData['status_online'] ?? 'offline';
$formAktif = $editData['status_aktif'] ?? 'aktif';
$formRating = $editData['rating'] ?? 5;
$formSaldo = $editData['saldo'] ?? 0;
$formCatatan = $editData['catatan'] ?? '';

$loginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');

catat_view_once($pdo, 'Driver Mitra', 'Membuka halaman Driver Mitra');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Driver Mitra</title>
    <?php include 'header.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

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

        input,
        select,
        textarea,
        button,
        a {
            border-radius: 0 !important;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        input:focus,
        select:focus,
        textarea:focus {
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

        .driver-table {
            table-layout: fixed;
        }

        .driver-table th {
            white-space: nowrap;
        }

        .driver-table td {
            vertical-align: top;
        }

        .driver-col-driver {
            width: 190px;
        }

        .driver-col-kendaraan {
            width: 180px;
        }

        .driver-col-plat {
            width: 110px;
        }

        .driver-col-kapasitas {
            width: 100px;
        }

        .driver-col-tarif {
            width: 130px;
        }

        .driver-col-status {
            width: 120px;
        }

        .driver-col-rating {
            width: 90px;
        }

        .driver-col-saldo {
            width: 130px;
        }

        .driver-col-aksi {
            width: 360px;
        }

        .driver-status-pill {
            min-width: 72px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            border-radius: 999px !important;
        }

        .driver-action-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: .5rem;
            align-items: center;
        }

        .driver-action-form select {
            min-width: 92px;
        }

        .driver-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: .875rem;
            min-height: 100%;
        }

        .driver-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .75rem;
        }

        .driver-card-title {
            min-width: 0;
        }

        .driver-card-title .driver-name {
            font-size: 13px;
            line-height: 1.25;
            font-weight: 900;
            color: #111827;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .driver-card-title .driver-meta {
            font-size: 10px;
            line-height: 1.35;
            color: #9ca3af;
            font-weight: 600;
        }

        .driver-card-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .35rem;
            flex-shrink: 0;
        }

        .driver-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .5rem;
            padding-top: .75rem;
            border-top: 1px solid #f0f0f0;
        }

        .driver-card-field {
            min-width: 0;
            min-height: 58px;
            background: #fafafa;
            border: 1px solid #f3f3f3;
            padding: .65rem .7rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .driver-card-field span {
            display: block;
            color: #9ca3af;
            font-size: 9px;
            line-height: 1;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: .4rem;
        }

        .driver-card-field p {
            font-size: 11px;
            line-height: 1.25;
            font-weight: 900;
            color: #111827;
            overflow-wrap: anywhere;
        }

        .driver-card-note {
            font-size: 11px;
            line-height: 1.5;
            color: #6b7280;
            padding-top: .75rem;
            border-top: 1px solid #f0f0f0;
        }

        .driver-card-action {
            padding-top: .75rem;
            border-top: 1px solid #f0f0f0;
        }

        .driver-card-form {
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }

        .driver-card-top-action {
            display: grid;
            grid-template-columns: 72px 1fr 1fr;
            gap: .5rem;
            align-items: stretch;
        }

        .driver-card-top-action>a {
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #f0f0f0;
            background: #fff;
            color: #111827;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            transition: all .15s ease;
        }

        .driver-card-top-action>a:hover {
            background: #fafafa;
        }

        .driver-card-top-action select {
            width: 100%;
            min-height: 40px;
            background: #fafafa;
            border: 1px solid #f0f0f0;
            padding: 0 .55rem;
            font-size: 10px;
            font-weight: 700;
            color: #111827;
        }

        .driver-save-btn {
            width: 100%;
            min-height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            color: #fff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            transition: all .15s ease;
        }

        .driver-save-btn:hover {
            background: #1f1f1f;
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
                padding-bottom: 0;
            }

            .app-header,
            .main-wrap {
                margin-left: 0 !important;
            }

            .main-content {
                padding: 1rem !important;
                padding-bottom: 1.25rem !important;
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
                padding: .75rem;
            }

            .header-title {
                max-width: 170px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .driver-card {
                padding: .875rem;
            }

            .driver-card-top-action {
                grid-template-columns: 64px 1fr 1fr;
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
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Driver</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Mitra terdaftar</p>
                </div>

                <div class="bg-white border border-green-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Online</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($summary['online']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Siap menerima order</p>
                </div>

                <div class="bg-white border border-gray-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Offline</p>
                    <p class="text-2xl font-bold"><?= angka($summary['offline']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Tidak aktif online</p>
                </div>

                <div class="bg-white border border-blue-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Aktif</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['aktif']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Mitra aktif</p>
                </div>

                <div class="bg-white border border-red-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nonaktif</p>
                    <p class="text-2xl font-bold text-red-600"><?= angka($summary['nonaktif']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Tidak digunakan</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kapasitas</p>
                    <p class="text-2xl font-bold"><?= angka($summary['kapasitas']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Total seat aktif</p>
                </div>
            </div>

            <section id="form-driver" class="bg-white border border-subtle p-4 md:p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            <?= $editData ? 'Edit Driver' : 'Tambah Driver' ?>
                        </h2>
                        <p class="text-xs text-gray-400 mt-0.5">Data mitra driver sekaligus kendaraan miliknya.</p>
                    </div>

                    <?php if ($editData): ?>
                        <a href="driver.php" class="text-[10px] font-black uppercase tracking-widest px-4 py-2 border border-subtle hover:bg-gray-50">
                            Batal Edit
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= h($editData['id'] ?? 0) ?>">

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kode</label>
                        <input type="text" name="kode" value="<?= h($formKode) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Nama Driver</label>
                        <input type="text" name="nama" value="<?= h($formNama) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Contoh: Andi Saputra" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">No HP</label>
                        <input type="text" name="no_hp" value="<?= h($formHp) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="081234567890" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Alamat</label>
                        <input type="text" name="alamat" value="<?= h($formAlamat) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Opsional">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kendaraan</label>
                        <input type="text" name="kendaraan_nama" value="<?= h($formKendaraan) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Toyota Avanza" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tipe</label>
                        <select name="kendaraan_tipe" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" required>
                            <option value="avanza" <?= normalize_tipe_driver($formTipe) === 'avanza' ? 'selected' : '' ?>>Avanza</option>
                            <option value="innova" <?= normalize_tipe_driver($formTipe) === 'innova' ? 'selected' : '' ?>>Innova</option>
                            <option value="hiace" <?= normalize_tipe_driver($formTipe) === 'hiace' ? 'selected' : '' ?>>Hiace</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Plat Nomor</label>
                        <input type="text" name="plat_nomor" value="<?= h($formPlat) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm uppercase" placeholder="F 1234 ABC" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kapasitas</label>
                        <input type="number" name="kapasitas" value="<?= h($formKapasitas) ?>" min="1" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Harga Bandara</label>
                        <input type="number" name="harga_bandara" value="<?= h($formHarga) ?>" min="0" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status Online</label>
                        <select name="status_online" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <?php foreach ($allowedOnline as $st): ?>
                                <option value="<?= h($st) ?>" <?= $formOnline === $st ? 'selected' : '' ?>>
                                    <?= h(driver_status_online_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status Aktif</label>
                        <select name="status_aktif" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <?php foreach ($allowedAktif as $st): ?>
                                <option value="<?= h($st) ?>" <?= $formAktif === $st ? 'selected' : '' ?>>
                                    <?= h(driver_status_aktif_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Rating</label>
                        <input type="number" step="0.01" name="rating" value="<?= h($formRating) ?>" min="0" max="5" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Saldo</label>
                        <input type="number" name="saldo" value="<?= h($formSaldo) ?>" min="0" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                    </div>

                    <div class="md:col-span-2 xl:col-span-4">
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Catatan</label>
                        <input type="text" name="catatan" value="<?= h($formCatatan) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Opsional">
                    </div>

                    <div class="md:col-span-2 xl:col-span-4">
                        <button type="submit" class="w-full bg-black text-white px-5 py-3 text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                            <?= $editData ? 'Simpan Perubahan' : 'Tambah Driver' ?>
                        </button>
                    </div>
                </form>
            </section>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-[1fr_180px_180px_auto_auto] gap-3 items-end">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Driver</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Kode / nama / no HP / kendaraan / plat">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Online</label>
                        <select name="online" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <?php foreach ($allowedOnline as $st): ?>
                                <option value="<?= h($st) ?>" <?= $onlineFilter === $st ? 'selected' : '' ?>>
                                    <?= h(driver_status_online_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Aktif</label>
                        <select name="aktif" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <?php foreach ($allowedAktif as $st): ?>
                                <option value="<?= h($st) ?>" <?= $aktifFilter === $st ? 'selected' : '' ?>>
                                    <?= h(driver_status_aktif_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tampilkan
                    </button>

                    <a href="driver.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle">
                    <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Daftar Driver Mitra</h2>
                    <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($drivers)) ?> driver ditampilkan</p>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="driver-table w-full text-left" style="min-width:1320px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Driver</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kendaraan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Plat</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Kapasitas</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Tarif</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Rating</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Saldo</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$drivers): ?>
                                <tr>
                                    <td colspan="9" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada driver mitra
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($drivers as $d): ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm"><?= h($d['nama']) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h($d['no_hp']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono"><?= h($d['kode']) ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-sm font-bold"><?= h($d['kendaraan_nama']) ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h(label_tipe_driver($d['kendaraan_tipe'])) ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-bold"><?= h($d['plat_nomor']) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($d['kapasitas']) ?> seat</td>
                                    <td class="px-5 py-4 text-right text-sm font-black"><?= rupiah($d['harga_bandara']) ?></td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-col gap-1">
                                            <span class="<?= h(driver_status_online_class($d['status_online'])) ?> driver-status-pill border text-[9px] font-bold uppercase px-2 py-1 w-fit">
                                                <?= h(driver_status_online_label($d['status_online'])) ?>
                                            </span>
                                            <span class="<?= h(driver_status_aktif_class($d['status_aktif'])) ?> driver-status-pill border text-[9px] font-bold uppercase px-2 py-1 w-fit">
                                                <?= h(driver_status_aktif_label($d['status_aktif'])) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm font-bold">⭐ <?= h(number_format((float)$d['rating'], 2)) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-black"><?= rupiah($d['saldo']) ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex justify-end items-center gap-2">
                                            <a href="driver.php?edit=<?= h($d['id']) ?>#form-driver" class="px-3 py-2 border border-subtle text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                                Edit
                                            </a>

                                            <form method="POST" class="driver-action-form">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= h($d['id']) ?>">
                                                <select name="status_online" class="bg-gray-50 border border-gray-100 px-2 py-2 text-xs">
                                                    <?php foreach ($allowedOnline as $st): ?>
                                                        <option value="<?= h($st) ?>" <?= $d['status_online'] === $st ? 'selected' : '' ?>>
                                                            <?= h(driver_status_online_label($st)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="status_aktif" class="bg-gray-50 border border-gray-100 px-2 py-2 text-xs">
                                                    <?php foreach ($allowedAktif as $st): ?>
                                                        <option value="<?= h($st) ?>" <?= $d['status_aktif'] === $st ? 'selected' : '' ?>>
                                                            <?= h(driver_status_aktif_label($st)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                                                    Simpan
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-list">
                    <?php if (!$drivers): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Belum ada driver mitra
                        </div>
                    <?php endif; ?>

                    <?php foreach ($drivers as $d): ?>
                        <div class="driver-card">
                            <div class="driver-card-head">
                                <div class="driver-card-title">
                                    <p class="driver-name"><?= h($d['nama']) ?></p>
                                    <p class="driver-meta"><?= h($d['no_hp']) ?></p>
                                    <p class="driver-meta font-mono"><?= h($d['kode']) ?></p>
                                </div>

                                <div class="driver-card-status">
                                    <span class="<?= h(driver_status_online_class($d['status_online'])) ?> driver-status-pill border text-[9px] font-bold uppercase px-2 py-1">
                                        <?= h(driver_status_online_label($d['status_online'])) ?>
                                    </span>
                                    <span class="<?= h(driver_status_aktif_class($d['status_aktif'])) ?> driver-status-pill border text-[9px] font-bold uppercase px-2 py-1">
                                        <?= h(driver_status_aktif_label($d['status_aktif'])) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="driver-card-grid">
                                <div class="driver-card-field">
                                    <span>Kendaraan</span>
                                    <p><?= h($d['kendaraan_nama']) ?></p>
                                </div>
                                <div class="driver-card-field">
                                    <span>Plat</span>
                                    <p><?= h($d['plat_nomor']) ?></p>
                                </div>
                                <div class="driver-card-field">
                                    <span>Kapasitas</span>
                                    <p><?= angka($d['kapasitas']) ?> seat</p>
                                </div>
                                <div class="driver-card-field">
                                    <span>Tarif</span>
                                    <p><?= rupiah($d['harga_bandara']) ?></p>
                                </div>
                                <div class="driver-card-field">
                                    <span>Rating</span>
                                    <p>⭐ <?= h(number_format((float)$d['rating'], 2)) ?></p>
                                </div>
                                <div class="driver-card-field">
                                    <span>Saldo</span>
                                    <p><?= rupiah($d['saldo']) ?></p>
                                </div>
                            </div>

                            <?php if (!empty($d['catatan'])): ?>
                                <p class="driver-card-note"><?= h($d['catatan']) ?></p>
                            <?php endif; ?>

                            <div class="driver-card-action">
                                <form method="POST" class="driver-card-form">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= h($d['id']) ?>">

                                    <div class="driver-card-top-action">
                                        <a href="driver.php?edit=<?= h($d['id']) ?>#form-driver">
                                            Edit
                                        </a>

                                        <select name="status_online">
                                            <?php foreach ($allowedOnline as $st): ?>
                                                <option value="<?= h($st) ?>" <?= $d['status_online'] === $st ? 'selected' : '' ?>>
                                                    <?= h(driver_status_online_label($st)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <select name="status_aktif">
                                            <?php foreach ($allowedAktif as $st): ?>
                                                <option value="<?= h($st) ?>" <?= $d['status_aktif'] === $st ? 'selected' : '' ?>>
                                                    <?= h(driver_status_aktif_label($st)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <button type="submit" class="driver-save-btn">
                                        Simpan Status
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
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