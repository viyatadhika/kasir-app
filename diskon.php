<?php
require_once 'config.php';

/*
SQL untuk fitur promo diskon aman:
- Promo tidak digabung di POS.
- Member tidak memakai diskon, member hanya mendapatkan point.

CREATE TABLE IF NOT EXISTS diskon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    cakupan ENUM('transaksi','produk','kategori') DEFAULT 'transaksi',
    target ENUM('semua','member') DEFAULT 'semua',
    produk_id INT NULL,
    kategori VARCHAR(100) NULL,
    jenis ENUM('persen','nominal') NOT NULL,
    nilai INT NOT NULL,
    minimal_belanja INT DEFAULT 0,
    tanggal_mulai DATE NULL,
    tanggal_selesai DATE NULL,
    status ENUM('aktif','nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);
*/

$success = '';
$error = '';

/**
 * @param mixed $v
 */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * @param int|float|string|null $n
 */
function rupiah_diskon($n): string
{
    return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
}

/**
 * @param string|null $tgl
 */
function tanggal_diskon($tgl): string
{
    return $tgl ? date('d/m/Y', strtotime($tgl)) : '-';
}

try {
    $produkList = $pdo->query("SELECT id, kode, nama, kategori FROM produk WHERE status='aktif' ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
    $kategoriList = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE status='aktif' AND kategori IS NOT NULL AND kategori<>'' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $produkList = [];
    $kategoriList = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $nama = trim($_POST['nama'] ?? '');
            $cakupan = $_POST['cakupan'] ?? 'transaksi';
            $target = 'semua';
            $produkId = !empty($_POST['produk_id']) ? (int)$_POST['produk_id'] : null;
            $kategori = trim($_POST['kategori'] ?? '');
            $jenis = $_POST['jenis'] ?? 'persen';
            $nilai = (int)($_POST['nilai'] ?? 0);
            $minimal = (int)($_POST['minimal_belanja'] ?? 0);
            $mulai = !empty($_POST['tanggal_mulai']) ? $_POST['tanggal_mulai'] : null;
            $selesai = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : null;
            $status = $_POST['status'] ?? 'aktif';

            if ($nama === '') throw new Exception('Nama diskon wajib diisi.');
            if (!in_array($cakupan, ['transaksi', 'produk', 'kategori'], true)) throw new Exception('Cakupan diskon tidak valid.');
            if (!in_array($jenis, ['persen', 'nominal'], true)) throw new Exception('Jenis diskon tidak valid.');
            if (!in_array($status, ['aktif', 'nonaktif'], true)) throw new Exception('Status tidak valid.');
            if ($nilai <= 0) throw new Exception('Nilai diskon harus lebih dari 0.');
            if ($jenis === 'persen' && $nilai > 100) throw new Exception('Diskon persen maksimal 100%.');
            if ($minimal < 0) throw new Exception('Minimal belanja tidak boleh minus.');
            if ($mulai && $selesai && strtotime($selesai) < strtotime($mulai)) throw new Exception('Tanggal selesai tidak boleh lebih kecil dari tanggal mulai.');
            if ($cakupan === 'produk' && !$produkId) throw new Exception('Pilih produk untuk diskon produk.');
            if ($cakupan === 'kategori' && $kategori === '') throw new Exception('Pilih kategori untuk diskon kategori.');

            if ($cakupan !== 'produk') $produkId = null;
            if ($cakupan !== 'kategori') $kategori = null;

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE diskon
                    SET nama=:nama, cakupan=:cakupan, target=:target, produk_id=:produk_id, kategori=:kategori,
                        jenis=:jenis, nilai=:nilai, minimal_belanja=:minimal, tanggal_mulai=:mulai,
                        tanggal_selesai=:selesai, status=:status, updated_at=NOW()
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':nama' => $nama,
                    ':cakupan' => $cakupan,
                    ':target' => $target,
                    ':produk_id' => $produkId,
                    ':kategori' => $kategori,
                    ':jenis' => $jenis,
                    ':nilai' => $nilai,
                    ':minimal' => $minimal,
                    ':mulai' => $mulai,
                    ':selesai' => $selesai,
                    ':status' => $status,
                    ':id' => $id
                ]);
                $success = 'Diskon berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO diskon (nama, cakupan, target, produk_id, kategori, jenis, nilai, minimal_belanja, tanggal_mulai, tanggal_selesai, status)
                    VALUES (:nama, :cakupan, :target, :produk_id, :kategori, :jenis, :nilai, :minimal, :mulai, :selesai, :status)
                ");
                $stmt->execute([
                    ':nama' => $nama,
                    ':cakupan' => $cakupan,
                    ':target' => $target,
                    ':produk_id' => $produkId,
                    ':kategori' => $kategori,
                    ':jenis' => $jenis,
                    ':nilai' => $nilai,
                    ':minimal' => $minimal,
                    ':mulai' => $mulai,
                    ':selesai' => $selesai,
                    ':status' => $status
                ]);
                $success = 'Diskon berhasil ditambahkan.';
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE diskon SET status=IF(status='aktif','nonaktif','aktif'), updated_at=NOW() WHERE id=:id")->execute([':id' => $id]);
            $success = 'Status diskon berhasil diubah.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM diskon WHERE id=:id")->execute([':id' => $id]);
            $success = 'Diskon berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM diskon WHERE id=:id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$q = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterCakupan = $_GET['cakupan'] ?? '';
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(d.nama LIKE :q OR d.kategori LIKE :q OR p.nama LIKE :q OR p.kode LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($filterStatus, ['aktif', 'nonaktif'], true)) {
    $where[] = "d.status=:status";
    $params[':status'] = $filterStatus;
}
if (in_array($filterCakupan, ['transaksi', 'produk', 'kategori'], true)) {
    $where[] = "d.cakupan=:cakupan";
    $params[':cakupan'] = $filterCakupan;
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("
    SELECT d.*, p.kode AS produk_kode, p.nama AS produk_nama
    FROM diskon d
    LEFT JOIN produk p ON p.id = d.produk_id
    $sqlWhere
    ORDER BY d.status='aktif' DESC, d.id DESC
");
$stmt->execute($params);
$diskonList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
try {
    $summary = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='aktif' THEN 1 ELSE 0 END) AS aktif,
            SUM(CASE WHEN status='aktif' AND cakupan='transaksi' THEN 1 ELSE 0 END) AS transaksi,
            SUM(CASE WHEN status='aktif' AND cakupan='produk' THEN 1 ELSE 0 END) AS produk,
            SUM(CASE WHEN status='aktif' AND cakupan='kategori' THEN 1 ELSE 0 END) AS kategori
        FROM diskon
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $summary = ['total' => 0, 'aktif' => 0, 'transaksi' => 0, 'produk' => 0, 'kategori' => 0];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Diskon</title>
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

        .badge-aktif {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-nonaktif {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .modal-overlay {
            transition: opacity 0.2s ease;
        }

        .modal-box {
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.06);
            border-color: #1a1a1a !important;
        }

        .spinner {
            border: 2px solid #f0f0f0;
            border-top-color: #1a1a1a;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 0.7s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        #toast {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: translateY(20px);
            opacity: 0;
        }

        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        #mobileMenuOverlay {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        #mobileMenuContent {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .diskon-mobile-card {
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .diskon-mobile-card:hover {
            transform: translateY(-1px);
            border-color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .diskon-header,
            .diskon-main {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .diskon-header {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .diskon-main {
                padding: 1rem;
            }
        }

        @media (max-width: 640px) {
            .diskon-header-title {
                max-width: 150px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .diskon-add-text {
                display: none;
            }

            #toast {
                left: 1rem;
                right: 1rem;
                bottom: 5rem;
                justify-content: center;
            }
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">

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
                <a href="produk.php" class="block text-sm font-medium text-gray-400 uppercase tracking-widest">Kelola Produk</a>
                <a href="diskon.php" class="block text-sm font-semibold text-black uppercase tracking-widest">Kelola Diskon</a>
                <a href="laporan.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">
                    Laporan Keuangan
                </a>
                <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block text-sm font-bold text-red-500 uppercase tracking-widest">Logout</a>
            </nav>
            <div class="pt-8 border-t border-subtle">
                <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
                <p class="text-[10px] text-gray-400 font-medium">Login: <?= htmlspecialchars($_SESSION['nama'] ?? '') ?></p>
            </div>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12">
            <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">BSDK SEJAHTERA</span>
        </div>
        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-blue-600 hover:font-bold uppercase tracking-widest transition-all flex items-center gap-2">
                <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                Mesin Kasir (POS)
            </a>
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
            <a href="diskon.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2">
                <span class="w-2 h-2 bg-black rounded-full"></span>
                Kelola Diskon
            </a>
            <a href="laporan.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">
                Laporan Keuangan
            </a>
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.4.0</p>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </aside>

    <!-- Header -->
    <header class="diskon-header sticky top-0 bg-white border-b border-subtle px-4 sm:px-6 py-4 flex justify-between items-center z-40 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors group">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <h1 class="diskon-header-title text-sm font-bold tracking-[0.2em] uppercase">Kelola Diskon</h1>
        </div>
        <div class="flex items-center gap-4">
            <a href="pos.php" class="hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-3 border border-subtle rounded-sm bg-white hover:bg-gray-50 transition-all">
                Mesin Kasir
            </a>
            <button onclick="openModal()"
                class="inline-flex items-center gap-2 bg-black text-white text-[10px] font-black uppercase tracking-widest px-5 py-3 rounded-sm hover:bg-gray-800 transition-all shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                <span class="diskon-add-text">Tambah Diskon</span>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="diskon-main p-4 sm:p-5 md:p-8 lg:p-10">

        <!-- Alert dari POST redirect -->
        <?php if ($success): ?>
            <div id="alert-success" class="mb-5 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-sm text-xs font-bold">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
                <?= h($success) ?>
                <button onclick="this.parentElement.remove()" class="ml-auto text-green-500 hover:text-green-700">✕</button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div id="alert-error" class="mb-5 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-sm text-xs font-bold">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <?= h($error) ?>
                <button onclick="this.parentElement.remove()" class="ml-auto text-red-500 hover:text-red-700">✕</button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Diskon</p>
                <p class="text-2xl font-bold"><?= number_format($summary['total']) ?></p>
                <p class="text-[10px] text-gray-400 mt-1"><?= number_format($summary['aktif']) ?> aktif</p>
            </div>
            <div class="bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Transaksi</p>
                <p class="text-2xl font-bold text-blue-600"><?= number_format($summary['transaksi']) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
            <div class="bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Produk</p>
                <p class="text-2xl font-bold text-purple-600"><?= number_format($summary['produk']) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
            <div class="bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Kategori</p>
                <p class="text-2xl font-bold text-orange-500"><?= number_format($summary['kategori']) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="bg-white border border-subtle rounded-sm p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="search-input" value="<?= h($q) ?>"
                    placeholder="Cari nama diskon / produk / kategori..."
                    class="w-full bg-gray-50 border border-gray-100 rounded-sm pl-10 pr-4 py-2.5 text-sm focus:bg-white transition-all">
            </div>
            <select id="filter-cakupan" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Cakupan</option>
                <option value="transaksi" <?= $filterCakupan === 'transaksi' ? 'selected' : '' ?>>Transaksi</option>
                <option value="produk" <?= $filterCakupan === 'produk' ? 'selected' : '' ?>>Produk</option>
                <option value="kategori" <?= $filterCakupan === 'kategori' ? 'selected' : '' ?>>Kategori</option>
            </select>
            <select id="filter-status" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Status</option>
                <option value="aktif" <?= $filterStatus === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $filterStatus === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
            <span class="text-xs text-gray-400 font-medium ml-auto hidden sm:block">
                <?= number_format(count($diskonList)) ?> diskon ditemukan
            </span>
        </div>

        <!-- Tabel & Card List -->
        <div class="bg-white border border-subtle rounded-sm overflow-hidden">

            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-x-auto no-scrollbar">
                <table class="w-full text-left min-w-[900px]">
                    <thead class="border-b border-subtle bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 w-8">#</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nama Diskon</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Cakupan</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Nilai</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Minimal</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Periode</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f5f5f5]">
                        <?php if (!$diskonList): ?>
                            <tr>
                                <td colspan="8" class="py-20 text-center">
                                    <div class="inline-flex flex-col items-center gap-3 opacity-30">
                                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                        </svg>
                                        <p class="text-xs font-bold uppercase tracking-widest">Belum ada diskon</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($diskonList as $i => $d):
                                $cakupan = $d['cakupan'] ?? 'transaksi';
                                if ($cakupan === 'produk') {
                                    $cakupanLabel = ($d['produk_kode'] ?? '') ? $d['produk_kode'] . ' - ' . $d['produk_nama'] : '-';
                                    $cakupanBadge = 'bg-purple-50 text-purple-700';
                                    $cakupanTag = 'Produk';
                                } elseif ($cakupan === 'kategori') {
                                    $cakupanLabel = $d['kategori'] ?? '-';
                                    $cakupanBadge = 'bg-orange-50 text-orange-700';
                                    $cakupanTag = 'Kategori';
                                } else {
                                    $cakupanLabel = 'Total Transaksi';
                                    $cakupanBadge = 'bg-blue-50 text-blue-700';
                                    $cakupanTag = 'Transaksi';
                                }
                                $nilaiLabel = $d['jenis'] === 'persen' ? ((int)$d['nilai']) . '%' : rupiah_diskon($d['nilai']);
                            ?>
                                <tr class="group">
                                    <td class="px-5 py-4 text-[11px] text-gray-300 font-medium"><?= $i + 1 ?></td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm leading-tight"><?= h($d['nama']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5">ID #<?= (int)$d['id'] ?> · Promo Umum</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-1 rounded-md <?= $cakupanBadge ?>">
                                            <?= $cakupanTag ?>
                                        </span>
                                        <div class="text-[10px] text-gray-400 mt-1 max-w-[160px] truncate"><?= h($cakupanLabel) ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="text-sm font-black text-blue-600"><?= h($nilaiLabel) ?></span>
                                        <div class="text-[9px] text-gray-400 mt-0.5 uppercase"><?= $d['jenis'] === 'persen' ? 'Persen' : 'Nominal' ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-right text-xs text-gray-500"><?= rupiah_diskon($d['minimal_belanja']) ?></td>
                                    <td class="px-5 py-4 text-xs text-gray-500">
                                        <div><?= tanggal_diskon($d['tanggal_mulai']) ?></div>
                                        <div class="text-gray-300">↓</div>
                                        <div><?= tanggal_diskon($d['tanggal_selesai']) ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($d['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="editDiskon(<?= (int)$d['id'] ?>)"
                                                class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-sm transition-all" title="Edit Diskon">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button onclick="toggleDiskon(<?= (int)$d['id'] ?>, '<?= $d['status'] ?>')"
                                                class="p-2 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded-sm transition-all"
                                                title="<?= $d['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <?php if ($d['status'] === 'aktif'): ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                <?php else: ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                            <button onclick="hapusDiskon(<?= (int)$d['id'] ?>, '<?= h(addslashes($d['nama'])) ?>')"
                                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-sm transition-all" title="Hapus Diskon">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile & Tablet Card List -->
            <div class="lg:hidden divide-y divide-[#f5f5f5]">
                <?php if (!$diskonList): ?>
                    <div class="py-16 text-center">
                        <div class="inline-flex flex-col items-center gap-3 opacity-30">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                            <p class="text-xs font-bold uppercase tracking-widest">Belum ada diskon</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($diskonList as $i => $d):
                            $cakupan = $d['cakupan'] ?? 'transaksi';
                            if ($cakupan === 'produk') {
                                $cakupanLabel = ($d['produk_kode'] ?? '') ? $d['produk_kode'] . ' - ' . $d['produk_nama'] : '-';
                                $cakupanBadge = 'bg-purple-50 text-purple-700';
                                $cakupanTag = 'Produk';
                            } elseif ($cakupan === 'kategori') {
                                $cakupanLabel = $d['kategori'] ?? '-';
                                $cakupanBadge = 'bg-orange-50 text-orange-700';
                                $cakupanTag = 'Kategori';
                            } else {
                                $cakupanLabel = 'Total Transaksi';
                                $cakupanBadge = 'bg-blue-50 text-blue-700';
                                $cakupanTag = 'Transaksi';
                            }
                            $nilaiLabel = $d['jenis'] === 'persen' ? ((int)$d['nilai']) . '%' : rupiah_diskon($d['nilai']);
                        ?>
                            <div class="diskon-mobile-card bg-white border border-subtle rounded-sm p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[10px] text-gray-300 font-bold">#<?= $i + 1 ?></span>
                                            <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-1 rounded-sm <?= $cakupanBadge ?>">
                                                <?= $cakupanTag ?>
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-sm leading-tight text-gray-900 truncate"><?= h($d['nama']) ?></h3>
                                        <p class="text-[10px] text-gray-400 font-mono mt-1 truncate">ID #<?= (int)$d['id'] ?> · <?= h($cakupanLabel) ?></p>
                                    </div>
                                    <div class="shrink-0">
                                        <?php if ($d['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50/60 rounded-sm p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Diskon</p>
                                        <p class="text-lg font-black text-blue-600"><?= h($nilaiLabel) ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1"><?= $d['jenis'] === 'persen' ? 'Persentase' : 'Nominal Rp' ?></p>
                                    </div>
                                    <div class="border border-subtle bg-gray-50/60 rounded-sm p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Min. Belanja</p>
                                        <p class="text-sm font-bold text-gray-700"><?= rupiah_diskon($d['minimal_belanja']) ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1">
                                            <?= tanggal_diskon($d['tanggal_mulai']) ?> - <?= tanggal_diskon($d['tanggal_selesai']) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between gap-2 pt-3 border-t border-subtle">
                                    <button onclick="editDiskon(<?= (int)$d['id'] ?>)"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-blue-100 text-blue-700 hover:bg-blue-50 rounded-sm transition-all">
                                        Edit
                                    </button>
                                    <button onclick="toggleDiskon(<?= (int)$d['id'] ?>, '<?= $d['status'] ?>')"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-yellow-100 text-yellow-700 hover:bg-yellow-50 rounded-sm transition-all">
                                        <?= $d['status'] === 'aktif' ? 'Nonaktif' : 'Aktifkan' ?>
                                    </button>
                                    <button onclick="hapusDiskon(<?= (int)$d['id'] ?>, '<?= h(addslashes($d['nama'])) ?>')"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-red-100 text-red-700 hover:bg-red-50 rounded-sm transition-all">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL: Tambah / Edit Diskon
    ══════════════════════════════════════════════════════════════ -->
    <div id="modal-diskon" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm hidden items-center justify-center p-4" style="display:none!important">
        <div class="modal-box bg-white w-full max-w-lg rounded-sm md:rounded-2xl shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest" id="modal-title">Tambah Diskon</h2>
                    <p class="text-[10px] text-gray-400 mt-0.5" id="modal-subtitle">Isi form di bawah dengan benar</p>
                </div>
                <button onclick="closeModal()" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="px-5 md:px-7 py-6 space-y-4 max-h-[75vh] overflow-y-auto no-scrollbar">
                <input type="hidden" id="form-id">

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Nama Diskon *</label>
                    <input type="text" id="form-nama" placeholder="Contoh: Diskon Akhir Tahun 10%"
                        class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Cakupan *</label>
                        <select id="form-cakupan" onchange="toggleCakupanForm()" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                            <option value="transaksi">Total Transaksi</option>
                            <option value="produk">Produk Tertentu</option>
                            <option value="kategori">Kategori Produk</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Target</label>
                        <div class="w-full bg-gray-100 border border-gray-200 rounded-lg px-3 py-2.5 text-sm text-gray-500 font-medium">Semua Pelanggan</div>
                        <p class="text-[9px] text-gray-400 mt-1">Member hanya mendapat point</p>
                    </div>
                </div>

                <div id="produk-wrap" class="hidden">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Pilih Produk *</label>
                    <select id="form-produk-id" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                        <option value="">Pilih produk...</option>
                        <?php foreach ($produkList as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= h($p['kode'] . ' - ' . $p['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="kategori-wrap" class="hidden">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Pilih Kategori *</label>
                    <select id="form-kategori" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                        <option value="">Pilih kategori...</option>
                        <?php foreach ($kategoriList as $kat): ?>
                            <option value="<?= h($kat) ?>"><?= h($kat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Jenis Diskon *</label>
                        <select id="form-jenis" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                            <option value="persen">Persen (%)</option>
                            <option value="nominal">Nominal (Rp)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Nilai *</label>
                        <input type="number" id="form-nilai" placeholder="10 atau 5000" min="1"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Minimal Belanja</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                        <input type="number" id="form-minimal" placeholder="0" min="0"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg pl-9 pr-3 py-2.5 text-sm transition-all">
                    </div>
                    <p class="text-[9px] text-gray-400 mt-1">Isi 0 jika tidak ada syarat minimal belanja</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Tanggal Mulai</label>
                        <input type="date" id="form-mulai" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Tanggal Selesai</label>
                        <input type="date" id="form-selesai" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Status</label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="form-status" value="aktif" checked class="accent-black">
                            <span class="text-sm font-medium">Aktif</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="form-status" value="nonaktif" class="accent-black">
                            <span class="text-sm font-medium">Nonaktif</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50/50 flex gap-3">
                <button onclick="closeModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle rounded-sm hover:bg-white transition-all">
                    Batal
                </button>
                <button onclick="simpanDiskon()" id="btn-simpan"
                    class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white rounded-sm hover:bg-gray-800 transition-all">
                    Simpan
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 rounded-xl shadow-2xl pointer-events-none">
        <span id="toast-icon"></span>
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

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
        <a href="diskon.php" class="flex flex-col items-center p-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Diskon</span>
        </a>
    </nav>

    <!-- Data untuk JS (edit mode) -->
    <script>
        const DISKON_DATA = <?= json_encode(array_column($diskonList, null, 'id')) ?>;
        const PRODUK_LIST = <?= json_encode($produkList) ?>;
        const KATEGORI_LIST = <?= json_encode($kategoriList) ?>;
    </script>

    <script>
        // ── Mobile Menu ────────────────────────────────────────────────────────────
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
            if (overlay) overlay.addEventListener('click', function(e) {
                if (e.target === this) toggleMobileMenu();
            });
        });

        // ── Live Filter ────────────────────────────────────────────────────────────
        let searchTimer;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('filter-cakupan').addEventListener('change', applyFilter);
        document.getElementById('filter-status').addEventListener('change', applyFilter);

        function applyFilter() {
            const url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('search-input').value);
            url.searchParams.set('cakupan', document.getElementById('filter-cakupan').value);
            url.searchParams.set('status', document.getElementById('filter-status').value);
            window.location.href = url.toString();
        }

        // ── Cakupan Toggle ─────────────────────────────────────────────────────────
        function toggleCakupanForm() {
            const c = document.getElementById('form-cakupan').value;
            document.getElementById('produk-wrap').classList.toggle('hidden', c !== 'produk');
            document.getElementById('kategori-wrap').classList.toggle('hidden', c !== 'kategori');
        }

        // ── Modal ──────────────────────────────────────────────────────────────────
        function openModal(mode = 'tambah') {
            document.getElementById('modal-title').innerText = mode === 'edit' ? 'Edit Diskon' : 'Tambah Diskon';
            document.getElementById('modal-subtitle').innerText = mode === 'edit' ? 'Ubah data diskon di bawah' : 'Isi form di bawah dengan benar';
            document.getElementById('modal-diskon').style.display = 'flex';
            toggleCakupanForm();
        }

        function closeModal() {
            document.getElementById('modal-diskon').style.display = 'none';
            resetForm();
        }

        function resetForm() {
            document.getElementById('form-id').value = '';
            document.getElementById('form-nama').value = '';
            document.getElementById('form-cakupan').value = 'transaksi';
            document.getElementById('form-produk-id').value = '';
            document.getElementById('form-kategori').value = '';
            document.getElementById('form-jenis').value = 'persen';
            document.getElementById('form-nilai').value = '';
            document.getElementById('form-minimal').value = '0';
            document.getElementById('form-mulai').value = '';
            document.getElementById('form-selesai').value = '';
            document.querySelector('input[name="form-status"][value="aktif"]').checked = true;
            document.getElementById('produk-wrap').classList.add('hidden');
            document.getElementById('kategori-wrap').classList.add('hidden');
        }

        function editDiskon(id) {
            const d = DISKON_DATA[id];
            if (!d) {
                showToast('Data tidak ditemukan.', 'error');
                return;
            }

            document.getElementById('form-id').value = d.id;
            document.getElementById('form-nama').value = d.nama;
            document.getElementById('form-cakupan').value = d.cakupan || 'transaksi';
            document.getElementById('form-jenis').value = d.jenis;
            document.getElementById('form-nilai').value = d.nilai;
            document.getElementById('form-minimal').value = d.minimal_belanja || 0;
            document.getElementById('form-mulai').value = d.tanggal_mulai || '';
            document.getElementById('form-selesai').value = d.tanggal_selesai || '';
            document.querySelector(`input[name="form-status"][value="${d.status}"]`).checked = true;

            toggleCakupanForm();

            if (d.cakupan === 'produk' && d.produk_id) {
                document.getElementById('form-produk-id').value = d.produk_id;
            }
            if (d.cakupan === 'kategori' && d.kategori) {
                document.getElementById('form-kategori').value = d.kategori;
            }

            openModal('edit');
        }

        async function simpanDiskon() {
            const id = document.getElementById('form-id').value;
            const btn = document.getElementById('btn-simpan');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            const cakupan = document.getElementById('form-cakupan').value;
            const formData = new FormData();
            formData.append('action', 'save');
            if (id) formData.append('id', id);
            formData.append('nama', document.getElementById('form-nama').value.trim());
            formData.append('cakupan', cakupan);
            formData.append('target', 'semua');
            formData.append('jenis', document.getElementById('form-jenis').value);
            formData.append('nilai', document.getElementById('form-nilai').value);
            formData.append('minimal_belanja', document.getElementById('form-minimal').value || 0);
            formData.append('tanggal_mulai', document.getElementById('form-mulai').value);
            formData.append('tanggal_selesai', document.getElementById('form-selesai').value);
            formData.append('status', document.querySelector('input[name="form-status"]:checked').value);
            if (cakupan === 'produk') formData.append('produk_id', document.getElementById('form-produk-id').value);
            if (cakupan === 'kategori') formData.append('kategori', document.getElementById('form-kategori').value);

            try {
                const res = await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                const text = await res.text();
                // Cek apakah ada error di response HTML
                if (text.includes('alert-error') || text.includes('Diskon berhasil')) {
                    showToast(id ? 'Diskon berhasil diperbarui.' : 'Diskon berhasil ditambahkan.', 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    // Extract error message
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errEl = doc.getElementById('alert-error');
                    showToast(errEl ? errEl.innerText.trim() : 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan koneksi.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Simpan';
            }
        }

        async function toggleDiskon(id, statusSekarang) {
            const formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('id', id);
            try {
                await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                showToast(statusSekarang === 'aktif' ? 'Diskon dinonaktifkan.' : 'Diskon diaktifkan.', 'success');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        async function hapusDiskon(id, nama) {
            if (!confirm(`Hapus diskon "${nama}"?\n\nTindakan ini tidak dapat dibatalkan.`)) return;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            try {
                await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                showToast('Diskon berhasil dihapus.', 'success');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Toast ──────────────────────────────────────────────────────────────────
        let toastTimer;

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toast-icon');
            document.getElementById('toast-msg').innerText = msg;
            icon.innerHTML = type === 'success' ?
                '<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' :
                '<svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // ── Close modal on overlay click ───────────────────────────────────────────
        document.getElementById('modal-diskon').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Edit jika ada ?edit= di URL (dari link lama)
        <?php if ($edit): ?>
            document.addEventListener('DOMContentLoaded', function() {
                editDiskon(<?= (int)$edit['id'] ?>);
            });
        <?php endif; ?>
    </script>
</body>

</html>