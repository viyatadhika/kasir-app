<?php
session_start();
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| kendaraan.php
|--------------------------------------------------------------------------
| Admin manajemen armada kendaraan untuk transport bandara.
|
| Fitur:
| - Tambah kendaraan
| - Edit kendaraan
| - Ubah status: tersedia, digunakan, nonaktif
| - Filter pencarian/status
| - Ringkasan armada
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
function kendaraan_status_label($status): string
{
    $status = strtolower(trim((string)$status));

    if ($status === 'digunakan') {
        return 'Digunakan';
    }

    if ($status === 'nonaktif') {
        return 'Nonaktif';
    }

    return 'Tersedia';
}

/** @param mixed $status */
function kendaraan_status_class($status): string
{
    $status = strtolower(trim((string)$status));

    if ($status === 'digunakan') {
        return 'bg-blue-50 text-blue-700 border-blue-200';
    }

    if ($status === 'nonaktif') {
        return 'bg-red-50 text-red-700 border-red-200';
    }

    return 'bg-green-50 text-green-700 border-green-200';
}

function ensure_kendaraan_table(PDO $pdo): void
{
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
}

function generate_kode_kendaraan(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT kode FROM kendaraan WHERE kode LIKE 'KDR%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/KDR(\d+)/', (string)$last, $m)) {
        $next = ((int)$m[1]) + 1;
    } else {
        $next = 1;
    }

    return 'KDR' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

ensure_kendaraan_table($pdo);

$error = '';
$success = '';
$allowedStatus = ['tersedia', 'digunakan', 'nonaktif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $kode = trim((string)($_POST['kode'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $tipe = trim((string)($_POST['tipe'] ?? ''));
        $platNomor = strtoupper(trim((string)($_POST['plat_nomor'] ?? '')));
        $kapasitas = (int)($_POST['kapasitas'] ?? 4);
        $hargaBandara = (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga_bandara'] ?? '0'));
        $status = strtolower(trim((string)($_POST['status'] ?? 'tersedia')));
        $catatan = trim((string)($_POST['catatan'] ?? ''));

        if ($kode === '') {
            $kode = generate_kode_kendaraan($pdo);
        }

        if ($nama === '') {
            $error = 'Nama kendaraan wajib diisi.';
        } elseif ($kapasitas <= 0) {
            $error = 'Kapasitas kendaraan tidak valid.';
        } elseif ($hargaBandara < 0) {
            $error = 'Harga bandara tidak valid.';
        } elseif (!in_array($status, $allowedStatus, true)) {
            $error = 'Status kendaraan tidak valid.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE kendaraan
                        SET kode = :kode,
                            nama = :nama,
                            tipe = :tipe,
                            plat_nomor = :plat_nomor,
                            kapasitas = :kapasitas,
                            harga_bandara = :harga_bandara,
                            status = :status,
                            catatan = :catatan,
                            updated_at = NOW()
                        WHERE id = :id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':kode' => $kode,
                        ':nama' => $nama,
                        ':tipe' => $tipe !== '' ? $tipe : null,
                        ':plat_nomor' => $platNomor !== '' ? $platNomor : null,
                        ':kapasitas' => $kapasitas,
                        ':harga_bandara' => $hargaBandara,
                        ':status' => $status,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                        ':id' => $id,
                    ]);

                    $success = 'Data kendaraan berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO kendaraan
                        (
                            kode,
                            nama,
                            tipe,
                            plat_nomor,
                            kapasitas,
                            harga_bandara,
                            status,
                            catatan,
                            created_at,
                            updated_at
                        )
                        VALUES
                        (
                            :kode,
                            :nama,
                            :tipe,
                            :plat_nomor,
                            :kapasitas,
                            :harga_bandara,
                            :status,
                            :catatan,
                            NOW(),
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        ':kode' => $kode,
                        ':nama' => $nama,
                        ':tipe' => $tipe !== '' ? $tipe : null,
                        ':plat_nomor' => $platNomor !== '' ? $platNomor : null,
                        ':kapasitas' => $kapasitas,
                        ':harga_bandara' => $hargaBandara,
                        ':status' => $status,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                    ]);

                    $success = 'Kendaraan berhasil ditambahkan.';
                }
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate') !== false) {
                    $error = 'Kode kendaraan sudah digunakan.';
                } else {
                    $error = 'Gagal menyimpan kendaraan: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = strtolower(trim((string)($_POST['status'] ?? '')));

        if ($id <= 0) {
            $error = 'Kendaraan tidak valid.';
        } elseif (!in_array($status, $allowedStatus, true)) {
            $error = 'Status tidak valid.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE kendaraan
                    SET status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':status' => $status,
                    ':id' => $id,
                ]);

                $success = 'Status kendaraan berhasil diperbarui.';
            } catch (Throwable $e) {
                $error = 'Gagal memperbarui status: ' . $e->getMessage();
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$editId = (int)($_GET['edit'] ?? 0);

$summary = [
    'total' => 0,
    'tersedia' => 0,
    'digunakan' => 0,
    'nonaktif' => 0,
    'kapasitas' => 0,
];

$kendaraan = [];
$editData = null;

try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'tersedia' THEN 1 ELSE 0 END), 0) AS tersedia,
            COALESCE(SUM(CASE WHEN status = 'digunakan' THEN 1 ELSE 0 END), 0) AS digunakan,
            COALESCE(SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END), 0) AS nonaktif,
            COALESCE(SUM(CASE WHEN status <> 'nonaktif' THEN kapasitas ELSE 0 END), 0) AS kapasitas
        FROM kendaraan
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $k => $v) {
        $summary[$k] = (int)($row[$k] ?? 0);
    }

    if ($editId > 0) {
        $stmtEdit = $pdo->prepare("SELECT * FROM kendaraan WHERE id = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $editData = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(kode LIKE :q1 OR nama LIKE :q2 OR tipe LIKE :q3 OR plat_nomor LIKE :q4)";
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
    }

    if (in_array($statusFilter, $allowedStatus, true)) {
        $where[] = "status = :status";
        $params[':status'] = $statusFilter;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtList = $pdo->prepare("
        SELECT *
        FROM kendaraan
        $whereSql
        ORDER BY
            CASE status
                WHEN 'tersedia' THEN 0
                WHEN 'digunakan' THEN 1
                ELSE 2
            END,
            nama ASC,
            id DESC
        LIMIT 200
    ");
    $stmtList->execute($params);
    $kendaraan = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data kendaraan: ' . $e->getMessage();
}

$formKode = $editData['kode'] ?? generate_kode_kendaraan($pdo);
$formNama = $editData['nama'] ?? '';
$formTipe = $editData['tipe'] ?? '';
$formPlat = $editData['plat_nomor'] ?? '';
$formKapasitas = $editData['kapasitas'] ?? 4;
$formHarga = $editData['harga_bandara'] ?? 0;
$formStatus = $editData['status'] ?? 'tersedia';
$formCatatan = $editData['catatan'] ?? '';

$loginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Kendaraan - Koperasi BSDK</title>
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
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[100] opacity-0 invisible flex justify-end lg:hidden">
        <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
            <div class="flex justify-between items-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
                <button onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100 transition-colors">✕</button>
            </div>

            <nav class="space-y-8 flex-1">
                <a href="index.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Dashboard</a>
                <a href="pos.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Mesin Kasir (POS)</a>
                <a href="produk.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Produk</a>
                <a href="stok_opname.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Stok Opname</a>
                <a href="rental_bandara.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Rental Bandara</a>
                <a href="kendaraan.php" class="block text-sm font-bold text-black uppercase tracking-widest">Kendaraan</a>
                <a href="diskon.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Diskon</a>
                <a href="laporan.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest">Laporan Keuangan</a>
                <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block text-sm font-bold text-red-500 uppercase tracking-widest">Logout</a>
            </nav>

            <div class="pt-8 border-t border-subtle">
                <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
                <p class="text-[10px] text-gray-400 font-medium">Login: <?= h($loginNama) ?></p>
            </div>
        </div>
    </div>

    <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12">
            <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span>
        </div>

        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Mesin Kasir (POS)</a>
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Produk</a>
            <a href="stok_opname.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Stok Opname</a>
            <a href="rental_bandara.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Rental Bandara</a>
            <a href="kendaraan.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2">
                <span class="w-2 h-2 bg-black rounded-full"></span>Kendaraan
            </a>
            <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Kelola Diskon</a>
            <a href="laporan.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest">Laporan Keuangan</a>
        </nav>

        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.5.1</p>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </aside>

    <header class="app-header sticky top-0 bg-white border-b border-subtle px-4 sm:px-6 py-4 flex justify-between items-center z-40 shadow-sm">
        <div class="flex items-center gap-3 sm:gap-4">
            <button onclick="toggleMobileMenu()" class="lg:hidden p-2 hover:bg-gray-100 transition-colors" aria-label="Menu">☰</button>

            <a href="rental_bandara.php" class="p-2 hover:bg-gray-100 transition-colors">←</a>

            <h1 class="header-title text-sm font-bold tracking-[0.2em] uppercase">Kendaraan</h1>
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
            <a href="rental_bandara.php" class="hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 border border-subtle bg-white hover:bg-gray-50">Rental</a>
            <a href="#form-kendaraan" class="inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-2.5 bg-black text-white hover:bg-gray-800">Tambah</a>
        </div>
    </header>

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

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Armada</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Data kendaraan</p>
                </div>

                <div class="bg-white border border-green-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tersedia</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($summary['tersedia']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Siap jalan</p>
                </div>

                <div class="bg-white border border-blue-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Digunakan</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['digunakan']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Sedang jalan</p>
                </div>

                <div class="bg-white border border-red-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nonaktif</p>
                    <p class="text-2xl font-bold text-red-600"><?= angka($summary['nonaktif']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Tidak dipakai</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kapasitas</p>
                    <p class="text-2xl font-bold"><?= angka($summary['kapasitas']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Total seat aktif</p>
                </div>
            </div>

            <section id="form-kendaraan" class="bg-white border border-subtle p-4 md:p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            <?= $editData ? 'Edit Kendaraan' : 'Tambah Kendaraan' ?>
                        </h2>
                        <p class="text-xs text-gray-400 mt-0.5">Data armada untuk layanan transport bandara.</p>
                    </div>

                    <?php if ($editData): ?>
                        <a href="kendaraan.php" class="text-[10px] font-black uppercase tracking-widest px-4 py-2 border border-subtle hover:bg-gray-50">
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
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Nama Kendaraan</label>
                        <input type="text" name="nama" value="<?= h($formNama) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Contoh: Toyota Avanza" required>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tipe</label>
                        <input type="text" name="tipe" value="<?= h($formTipe) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="MPV / Minibus">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Plat Nomor</label>
                        <input type="text" name="plat_nomor" value="<?= h($formPlat) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm uppercase" placeholder="F 1234 ABC">
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
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <?php foreach ($allowedStatus as $st): ?>
                                <option value="<?= h($st) ?>" <?= $formStatus === $st ? 'selected' : '' ?>>
                                    <?= h(kendaraan_status_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Catatan</label>
                        <input type="text" name="catatan" value="<?= h($formCatatan) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Opsional">
                    </div>

                    <div class="md:col-span-2 xl:col-span-4">
                        <button type="submit" class="w-full bg-black text-white px-5 py-3 text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                            <?= $editData ? 'Simpan Perubahan' : 'Tambah Kendaraan' ?>
                        </button>
                    </div>
                </form>
            </section>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-[1fr_220px_auto_auto] gap-3 items-end">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Kendaraan</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Kode / nama / tipe / plat">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua Status</option>
                            <?php foreach ($allowedStatus as $st): ?>
                                <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                                    <?= h(kendaraan_status_label($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tampilkan
                    </button>

                    <a href="kendaraan.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle">
                    <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Daftar Kendaraan</h2>
                    <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($kendaraan)) ?> kendaraan ditampilkan</p>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:1000px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kendaraan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tipe</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Plat</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Kapasitas</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Catatan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$kendaraan): ?>
                                <tr>
                                    <td colspan="8" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada kendaraan
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($kendaraan as $k): ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm"><?= h($k['nama']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono"><?= h($k['kode']) ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-sm"><?= h($k['tipe'] ?: '-') ?></td>
                                    <td class="px-5 py-4 text-sm font-bold"><?= h($k['plat_nomor'] ?: '-') ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($k['kapasitas']) ?> seat</td>
                                    <td class="px-5 py-4 text-right text-sm font-black"><?= rupiah($k['harga_bandara']) ?></td>
                                    <td class="px-5 py-4">
                                        <span class="<?= h(kendaraan_status_class($k['status'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                            <?= h(kendaraan_status_label($k['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-gray-500"><?= h($k['catatan'] ?: '-') ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex justify-end items-center gap-2">
                                            <a href="kendaraan.php?edit=<?= h($k['id']) ?>#form-kendaraan" class="px-3 py-2 border border-subtle text-[10px] font-black uppercase tracking-widest hover:bg-gray-50">
                                                Edit
                                            </a>

                                            <form method="POST" class="inline-flex items-center gap-2">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= h($k['id']) ?>">
                                                <select name="status" class="bg-gray-50 border border-gray-100 px-2 py-2 text-xs">
                                                    <?php foreach ($allowedStatus as $st): ?>
                                                        <option value="<?= h($st) ?>" <?= $k['status'] === $st ? 'selected' : '' ?>>
                                                            <?= h(kendaraan_status_label($st)) ?>
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
                    <?php if (!$kendaraan): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Belum ada kendaraan
                        </div>
                    <?php endif; ?>

                    <?php foreach ($kendaraan as $k): ?>
                        <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-bold text-sm"><?= h($k['nama']) ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono"><?= h($k['kode']) ?></p>
                                </div>
                                <span class="<?= h(kendaraan_status_class($k['status'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                    <?= h(kendaraan_status_label($k['status'])) ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Tipe</span>
                                    <p class="font-bold"><?= h($k['tipe'] ?: '-') ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Plat</span>
                                    <p class="font-bold"><?= h($k['plat_nomor'] ?: '-') ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Kapasitas</span>
                                    <p class="font-bold"><?= angka($k['kapasitas']) ?> seat</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Harga</span>
                                    <p class="font-bold"><?= rupiah($k['harga_bandara']) ?></p>
                                </div>
                            </div>

                            <?php if (!empty($k['catatan'])): ?>
                                <p class="text-xs text-gray-500 pt-2 border-t border-subtle"><?= h($k['catatan']) ?></p>
                            <?php endif; ?>

                            <div class="pt-2 border-t border-subtle grid grid-cols-[auto_1fr] gap-2">
                                <a href="kendaraan.php?edit=<?= h($k['id']) ?>#form-kendaraan" class="px-4 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-center">
                                    Edit
                                </a>

                                <form method="POST" class="grid grid-cols-[1fr_auto] gap-2">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= h($k['id']) ?>">
                                    <select name="status" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs">
                                        <?php foreach ($allowedStatus as $st): ?>
                                            <option value="<?= h($st) ?>" <?= $k['status'] === $st ? 'selected' : '' ?>>
                                                <?= h(kendaraan_status_label($st)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">
                                        Simpan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>

    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            ☰
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>

        <a href="rental_bandara.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            ✈️
        </a>

        <a href="kendaraan.php" class="flex flex-col items-center p-2">
            🚗
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Armada</span>
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
    </script>
</body>

</html>