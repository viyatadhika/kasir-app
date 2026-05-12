<?php
session_start();
require_once 'config.php';

$activeMenu = 'log';
$pageTitle = 'Log Aktivitas';
$backUrl = 'dashboard.php';


/*
|--------------------------------------------------------------------------
| log_aktivitas.php
|--------------------------------------------------------------------------
| Halaman admin untuk melihat aktivitas user/admin.
|
| Supaya aktivitas dari halaman lain ikut tercatat, panggil:
| catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah data produk');
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

if (!function_exists('angka')) {
    /** @param mixed $v */
    function angka($v): string
    {
        return number_format((float)($v ?? 0), 0, ',', '.');
    }
}

/** @param mixed $v */
function waktu_log($v): string
{
    return $v ? date('d/m/Y H:i:s', strtotime((string)$v)) : '-';
}

function ensure_log_aktivitas_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS log_aktivitas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            nama_user VARCHAR(150) DEFAULT NULL,
            role_user VARCHAR(50) DEFAULT NULL,
            aksi VARCHAR(80) NOT NULL,
            modul VARCHAR(120) DEFAULT NULL,
            keterangan TEXT DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY aksi (aksi),
            KEY modul (modul),
            KEY role_user (role_user),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * @param mixed $aksi
 * @param mixed $modul
 * @param mixed $keterangan
 */
function catat_aktivitas(PDO $pdo, $aksi, $modul = null, $keterangan = null): void
{
    try {
        ensure_log_aktivitas_table($pdo);

        $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
        $namaUser = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? ($_SESSION['username'] ?? 'User'));
        $roleUser = $_SESSION['role'] ?? ($_SESSION['user']['role'] ?? 'admin');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO log_aktivitas
            (user_id, nama_user, role_user, aksi, modul, keterangan, ip_address, user_agent, created_at)
            VALUES
            (:user_id, :nama_user, :role_user, :aksi, :modul, :keterangan, :ip_address, :user_agent, NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId ? (int)$userId : null,
            ':nama_user' => $namaUser,
            ':role_user' => $roleUser,
            ':aksi' => strtolower(trim((string)$aksi)),
            ':modul' => $modul !== null ? trim((string)$modul) : null,
            ':keterangan' => $keterangan !== null ? trim((string)$keterangan) : null,
            ':ip_address' => $ip,
            ':user_agent' => $agent,
        ]);
    } catch (Throwable $e) {
        // Jangan hentikan aplikasi hanya karena gagal mencatat log.
    }
}

/** @param mixed $aksi */
function label_aksi($aksi): string
{
    $aksi = strtolower(trim((string)$aksi));

    if ($aksi === 'login') return 'Login';
    if ($aksi === 'logout') return 'Logout';
    if ($aksi === 'create' || $aksi === 'tambah') return 'Tambah';
    if ($aksi === 'update' || $aksi === 'edit') return 'Ubah';
    if ($aksi === 'delete' || $aksi === 'hapus') return 'Hapus';
    if ($aksi === 'view' || $aksi === 'lihat') return 'Lihat';
    if ($aksi === 'booking') return 'Booking';
    if ($aksi === 'status') return 'Status';

    return ucwords(str_replace('_', ' ', $aksi));
}

/** @param mixed $aksi */
function class_aksi($aksi): string
{
    $aksi = strtolower(trim((string)$aksi));

    if (in_array($aksi, ['login', 'create', 'tambah', 'booking'], true)) {
        return 'bg-green-50 text-green-700 border-green-200';
    }

    if (in_array($aksi, ['update', 'edit', 'status'], true)) {
        return 'bg-blue-50 text-blue-700 border-blue-200';
    }

    if (in_array($aksi, ['delete', 'hapus', 'logout'], true)) {
        return 'bg-red-50 text-red-700 border-red-200';
    }

    if (in_array($aksi, ['view', 'lihat'], true)) {
        return 'bg-gray-50 text-gray-700 border-gray-200';
    }

    return 'bg-orange-50 text-orange-700 border-orange-200';
}

ensure_log_aktivitas_table($pdo);

$loginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');
$q = trim((string)($_GET['q'] ?? ''));
$aksiFilter = strtolower(trim((string)($_GET['aksi'] ?? '')));
$roleFilter = strtolower(trim((string)($_GET['role'] ?? '')));
$awal = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));

$summary = [
    'total' => 0,
    'hari_ini' => 0,
    'login' => 0,
    'tambah' => 0,
    'ubah' => 0,
    'hapus' => 0,
];

$logs = [];
$aksiList = [];
$roleList = [];
$error = '';

try {
    $stmtSummary = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS hari_ini,
            COALESCE(SUM(CASE WHEN aksi = 'login' THEN 1 ELSE 0 END), 0) AS login,
            COALESCE(SUM(CASE WHEN aksi IN ('create','tambah') THEN 1 ELSE 0 END), 0) AS tambah,
            COALESCE(SUM(CASE WHEN aksi IN ('update','edit','status') THEN 1 ELSE 0 END), 0) AS ubah,
            COALESCE(SUM(CASE WHEN aksi IN ('delete','hapus') THEN 1 ELSE 0 END), 0) AS hapus
        FROM log_aktivitas
    ");
    $rowSummary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $k => $v) {
        $summary[$k] = (int)($rowSummary[$k] ?? 0);
    }

    $aksiList = $pdo->query("
        SELECT DISTINCT aksi
        FROM log_aktivitas
        WHERE aksi IS NOT NULL AND aksi <> ''
        ORDER BY aksi ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $roleList = $pdo->query("
        SELECT DISTINCT role_user
        FROM log_aktivitas
        WHERE role_user IS NOT NULL AND role_user <> ''
        ORDER BY role_user ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            nama_user LIKE :q1
            OR role_user LIKE :q2
            OR aksi LIKE :q3
            OR modul LIKE :q4
            OR keterangan LIKE :q5
            OR ip_address LIKE :q6
        )";
        $params[':q1'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
        $params[':q5'] = '%' . $q . '%';
        $params[':q6'] = '%' . $q . '%';
    }

    if ($aksiFilter !== '') {
        $where[] = "aksi = :aksi";
        $params[':aksi'] = $aksiFilter;
    }

    if ($roleFilter !== '') {
        $where[] = "LOWER(role_user) = :role";
        $params[':role'] = $roleFilter;
    }

    if ($awal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
        $where[] = "DATE(created_at) >= :awal";
        $params[':awal'] = $awal;
    }

    if ($akhir !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) {
        $where[] = "DATE(created_at) <= :akhir";
        $params[':akhir'] = $akhir;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtLogs = $pdo->prepare("
        SELECT
            id,
            user_id,
            nama_user,
            role_user,
            aksi,
            modul,
            keterangan,
            ip_address,
            user_agent,
            created_at
        FROM log_aktivitas
        $whereSql
        ORDER BY created_at DESC, id DESC
        LIMIT 300
    ");
    $stmtLogs->execute($params);
    $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Gagal memuat log aktivitas: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Log Aktivitas</title>
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

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Log</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['total']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Semua aktivitas</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Hari Ini</p>
                    <p class="text-2xl font-bold"><?= angka($summary['hari_ini']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Aktivitas hari ini</p>
                </div>

                <div class="bg-white border border-green-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Login</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($summary['login']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Akses masuk</p>
                </div>

                <div class="bg-white border border-blue-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tambah</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['tambah']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Data dibuat</p>
                </div>

                <div class="bg-white border border-orange-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Ubah</p>
                    <p class="text-2xl font-bold text-orange-600"><?= angka($summary['ubah']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Data diperbarui</p>
                </div>

                <div class="bg-white border border-red-200 p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Hapus</p>
                    <p class="text-2xl font-bold text-red-600"><?= angka($summary['hapus']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Data dihapus</p>
                </div>
            </div>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto_auto] gap-3 items-end">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Aktivitas</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm" placeholder="Nama / aksi / modul / keterangan / IP">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Aksi</label>
                        <select name="aksi" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <?php foreach ($aksiList as $ak): ?>
                                <option value="<?= h($ak) ?>" <?= $aksiFilter === strtolower((string)$ak) ? 'selected' : '' ?>>
                                    <?= h(label_aksi($ak)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Role</label>
                        <select name="role" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                            <option value="">Semua</option>
                            <?php foreach ($roleList as $rl): ?>
                                <option value="<?= h(strtolower((string)$rl)) ?>" <?= $roleFilter === strtolower((string)$rl) ? 'selected' : '' ?>>
                                    <?= h($rl) ?>
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

                    <a href="log_aktivitas.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 text-center">
                        Reset
                    </a>
                </form>
            </section>

            <section class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Riwayat Aktivitas</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($logs)) ?> log ditampilkan</p>
                    </div>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:1180px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Waktu</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">User</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Aksi</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Modul</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Keterangan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">IP</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="6" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada log aktivitas
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="px-5 py-4 align-top whitespace-nowrap">
                                        <div class="text-sm font-bold"><?= h(waktu_log($log['created_at'])) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono">#<?= h($log['id']) ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-bold"><?= h($log['nama_user'] ?: '-') ?></div>
                                        <div class="text-[10px] text-gray-400"><?= h($log['role_user'] ?: '-') ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono">ID: <?= h($log['user_id'] ?: '-') ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <span class="<?= h(class_aksi($log['aksi'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                            <?= h(label_aksi($log['aksi'])) ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-bold"><?= h($log['modul'] ?: '-') ?></div>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-xs text-gray-600 max-w-[420px] leading-relaxed">
                                            <?= h($log['keterangan'] ?: '-') ?>
                                        </div>
                                        <?php if (!empty($log['user_agent'])): ?>
                                            <div class="text-[10px] text-gray-400 mt-1 max-w-[420px] truncate">
                                                <?= h($log['user_agent']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-xs font-mono text-gray-500"><?= h($log['ip_address'] ?: '-') ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-list">
                    <?php if (!$logs): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Belum ada log aktivitas
                        </div>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): ?>
                        <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-bold text-sm"><?= h($log['nama_user'] ?: '-') ?></p>
                                    <p class="text-[10px] text-gray-400"><?= h(waktu_log($log['created_at'])) ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono">ID: <?= h($log['user_id'] ?: '-') ?></p>
                                </div>

                                <span class="<?= h(class_aksi($log['aksi'])) ?> border text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                    <?= h(label_aksi($log['aksi'])) ?>
                                </span>
                            </div>

                            <div class="pt-2 border-t border-subtle">
                                <p class="text-[10px] text-gray-400 uppercase tracking-widest font-bold">Modul</p>
                                <p class="text-sm font-bold"><?= h($log['modul'] ?: '-') ?></p>
                            </div>

                            <div class="pt-2 border-t border-subtle">
                                <p class="text-[10px] text-gray-400 uppercase tracking-widest font-bold">Keterangan</p>
                                <p class="text-xs text-gray-600 leading-relaxed"><?= h($log['keterangan'] ?: '-') ?></p>
                            </div>

                            <div class="pt-2 border-t border-subtle grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Role</span>
                                    <p class="font-bold"><?= h($log['role_user'] ?: '-') ?></p>
                                </div>

                                <div>
                                    <span class="text-gray-400 font-medium">IP</span>
                                    <p class="font-mono font-bold"><?= h($log['ip_address'] ?: '-') ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            ☰
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>

        <a href="index.php" class="flex flex-col items-center p-2">
            🏠
            <span class="text-[8px] font-bold mt-1 uppercase">Home</span>
        </a>

        <a href="log_aktivitas.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            📋
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