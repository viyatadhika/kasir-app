<?php
require_once 'config.php';

// ── API Handler (AJAX) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {

        // Tambah produk baru
        case 'tambah':
            $input = json_decode(file_get_contents('php://input'), true);
            $required = ['kode', 'nama', 'harga_jual'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    echo json_encode(['success' => false, 'message' => "Field '$field' wajib diisi."]);
                    exit;
                }
            }
            $cek = $pdo->prepare("SELECT id FROM produk WHERE kode = :kode");
            $cek->execute([':kode' => $input['kode']]);
            if ($cek->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Kode produk sudah digunakan.']);
                exit;
            }
            $stmt = $pdo->prepare("
                INSERT INTO produk (kode, nama, kategori, harga_beli, harga_jual, stok, stok_minimum, satuan, status)
                VALUES (:kode, :nama, :kategori, :harga_beli, :harga_jual, :stok, :stok_minimum, :satuan, :status)
            ");
            $stmt->execute([
                ':kode'         => trim($input['kode']),
                ':nama'         => trim($input['nama']),
                ':kategori'     => trim($input['kategori'] ?? '-'),
                ':harga_beli'   => (int)($input['harga_beli'] ?? 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)($input['stok'] ?? 0),
                ':stok_minimum' => (int)($input['stok_minimum'] ?? 5),
                ':satuan'       => trim($input['satuan'] ?? 'pcs'),
                ':status'       => in_array($input['status'] ?? 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil ditambahkan.', 'id' => $pdo->lastInsertId()]);
            exit;

            // Edit produk
        case 'edit':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $cek = $pdo->prepare("SELECT id FROM produk WHERE kode = :kode AND id != :id");
            $cek->execute([':kode' => $input['kode'], ':id' => $input['id']]);
            if ($cek->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Kode produk sudah digunakan produk lain.']);
                exit;
            }
            $stmt = $pdo->prepare("
                UPDATE produk SET
                    kode = :kode, nama = :nama, kategori = :kategori,
                    harga_beli = :harga_beli, harga_jual = :harga_jual,
                    stok = :stok, stok_minimum = :stok_minimum,
                    satuan = :satuan, status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'           => (int)$input['id'],
                ':kode'         => trim($input['kode']),
                ':nama'         => trim($input['nama']),
                ':kategori'     => trim($input['kategori'] ?? '-'),
                ':harga_beli'   => (int)($input['harga_beli'] ?? 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)($input['stok'] ?? 0),
                ':stok_minimum' => (int)($input['stok_minimum'] ?? 5),
                ':satuan'       => trim($input['satuan'] ?? 'pcs'),
                ':status'       => in_array($input['status'] ?? 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil diperbarui.']);
            exit;

            // Hapus produk
        case 'hapus':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $cek = $pdo->prepare("SELECT id FROM transaksi_detail WHERE produk_id = :id LIMIT 1");
            $cek->execute([':id' => $input['id']]);
            if ($cek->fetch()) {
                $pdo->prepare("UPDATE produk SET status = 'nonaktif', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $input['id']]);
                echo json_encode(['success' => true, 'message' => 'Produk dinonaktifkan (ada di riwayat transaksi).', 'type' => 'soft']);
            } else {
                $pdo->prepare("DELETE FROM produk WHERE id = :id")->execute([':id' => $input['id']]);
                echo json_encode(['success' => true, 'message' => 'Produk berhasil dihapus.', 'type' => 'hard']);
            }
            exit;

            // Ambil satu produk (untuk form edit)
        case 'get':
            $id   = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) echo json_encode(['success' => true, 'data' => $row]);
            else      echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan.']);
            exit;

            // Update stok saja (opname)
        case 'update_stok':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $pdo->prepare("UPDATE produk SET stok = :stok, updated_at = NOW() WHERE id = :id")
                ->execute([':stok' => (int)$input['stok'], ':id' => (int)$input['id']]);
            echo json_encode(['success' => true, 'message' => 'Stok berhasil diperbarui.']);
            exit;
    }
    echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
    exit;
}

// ── Fetch Data ────────────────────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$katFilter    = $_GET['kat'] ?? '';
$statusFilter = $_GET['status'] ?? 'aktif';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]       = '(nama LIKE :q OR kode LIKE :q2 OR kategori LIKE :q3)';
    $params[':q']  = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
if ($katFilter) {
    $where[]        = 'kategori = :kat';
    $params[':kat'] = $katFilter;
}
if ($statusFilter !== 'semua') {
    $where[]           = 'status = :status';
    $params[':status'] = $statusFilter;
}

$whereStr = implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM produk WHERE $whereStr");
$stmtCount->execute($params);
$totalRows  = (int)$stmtCount->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmtProd = $pdo->prepare("SELECT * FROM produk WHERE $whereStr ORDER BY kategori, nama LIMIT $perPage OFFSET $offset");
$stmtProd->execute($params);
$produkList = $stmtProd->fetchAll();

$kategoriList = $pdo->query("SELECT DISTINCT kategori FROM produk ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN stok <= stok_minimum THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN stok = 0 THEN 1 ELSE 0 END) AS habis,
        SUM(harga_jual * stok) AS nilai_stok
    FROM produk
")->fetch();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Produk & Stok</title>
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

        .badge-low {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .badge-habis {
            background: #fef2f2;
            color: #dc2626;
        }

        .modal-overlay {
            transition: opacity 0.2s ease;
        }

        .modal-box {
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
        }

        .modal-overlay.hidden .modal-box {
            transform: scale(0.95);
            opacity: 0;
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

        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable:hover {
            color: #1a1a1a;
        }

        .stok-bar {
            height: 3px;
            border-radius: 2px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .stok-bar-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s;
        }

        #mobileMenuOverlay {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        #mobileMenuContent {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .produk-header,
            .produk-main {
                margin-left: 220px;
            }
        }

        .produk-mobile-card {
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .produk-mobile-card:hover {
            transform: translateY(-1px);
            border-color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .produk-header {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .produk-main {
                padding: 1rem;
            }

            .produk-filter {
                align-items: stretch;
            }

            .produk-filter .filter-control {
                width: 100%;
            }

            .produk-filter select {
                width: 100%;
            }

            .produk-summary {
                gap: 0.75rem;
                margin-bottom: 1rem;
            }

            .produk-summary-card {
                padding: 1rem;
            }

            .produk-summary-card p:nth-child(2) {
                font-size: 1.25rem;
                line-height: 1.75rem;
            }
        }

        @media (max-width: 640px) {
            .produk-header-title {
                max-width: 150px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .produk-add-text,
            .produk-pos-link {
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
                <!-- <a href="#" class="block text-sm font-medium text-gray-400 uppercase tracking-widest">Laporan Shift</a> -->
                <a href="produk.php" class="block text-sm font-medium text-gray-400 uppercase tracking-widest">Kelola Produk</a>

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
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
            <a href="pos.php" class="block text-xs font-medium text-blue-600 hover:font-bold uppercase tracking-widest transition-all flex items-center gap-2">
                <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                Mesin Kasir (POS)
            </a>
            <!-- <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Shift</a> -->
            <a href="produk.php" class="block text-xs font-semibold text-black uppercase tracking-widest">Kelola Produk</a>
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

    <!-- Header -->
    <header class="produk-header sticky top-0 bg-white border-b border-subtle px-4 sm:px-6 py-4 flex justify-between items-center z-40 shadow-sm">
        <div class="flex items-center gap-4">
            <a href="index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors group">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <h1 class="produk-header-title text-sm font-bold tracking-[0.2em] uppercase">Kelola Produk</h1>
        </div>
        <div class="flex items-center gap-4">
            <a href="pos.php" class="produk-pos-link hidden sm:inline-flex text-[10px] font-black uppercase tracking-widest px-4 py-3 border border-subtle rounded-sm bg-white hover:bg-gray-50 transition-all">
                Mesin Kasir
            </a>
            <button onclick="openModal()"
                class="inline-flex items-center gap-2 bg-black text-white text-[10px] font-black uppercase tracking-widest px-5 py-3 rounded-sm hover:bg-gray-800 transition-all shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                <span class="produk-add-text">Tambah Produk</span>
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="produk-main p-4 sm:p-5 md:p-8 lg:p-10">

        <!-- Summary Cards -->
        <div class="produk-summary grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="produk-summary-card bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total SKU</p>
                <p class="text-2xl font-bold"><?= number_format($summary['total']) ?></p>
                <p class="text-[10px] text-gray-400 mt-1"><?= number_format($summary['aktif']) ?> aktif</p>
            </div>
            <div class="produk-summary-card bg-white border border-subtle rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Stok</p>
                <p class="text-2xl font-bold text-blue-600"><?= rupiah($summary['nilai_stok'] ?? 0) ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Estimasi HPP</p>
            </div>
            <div class="produk-summary-card bg-white border <?= $summary['low_stock'] > 0 ? 'border-yellow-200' : 'border-subtle' ?> rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                <p class="text-2xl font-bold <?= $summary['low_stock'] > 0 ? 'text-yellow-600' : 'text-gray-800' ?>">
                    <?= number_format($summary['low_stock']) ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">di bawah minimum</p>
            </div>
            <div class="produk-summary-card bg-white border <?= $summary['habis'] > 0 ? 'border-red-200' : 'border-subtle' ?> rounded-sm md:rounded-xl p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Habis</p>
                <p class="text-2xl font-bold <?= $summary['habis'] > 0 ? 'text-red-600' : 'text-gray-800' ?>">
                    <?= number_format($summary['habis']) ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">perlu restock</p>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="produk-filter bg-white border border-subtle rounded-sm p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <div class="filter-control relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="search-input" value="<?= e($search) ?>"
                    placeholder="Cari nama, kode, atau kategori..."
                    class="w-full bg-gray-50 border border-gray-100 rounded-sm pl-10 pr-4 py-2.5 text-sm focus:bg-white transition-all">
            </div>
            <select id="filter-kat" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Kategori</option>
                <?php foreach ($kategoriList as $k): ?>
                    <option value="<?= e($k) ?>" <?= $katFilter === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-status" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="aktif" <?= $statusFilter === 'aktif'    ? 'selected' : '' ?>>Aktif</option>
                <option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                <option value="semua" <?= $statusFilter === 'semua'    ? 'selected' : '' ?>>Semua Status</option>
            </select>
            <span class="text-xs text-gray-400 font-medium ml-auto hidden sm:block">
                <?= number_format($totalRows) ?> produk ditemukan
            </span>
        </div>

        <!-- Tabel Produk -->
        <div class="bg-white border border-subtle rounded-sm overflow-hidden">

            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-x-auto no-scrollbar">
                <table class="w-full text-left min-w-[780px]">
                    <thead class="border-b border-subtle bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 w-8">#</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kategori</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga Beli</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga Jual</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Stok</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tabel-body" class="divide-y divide-[#f5f5f5]">
                        <?php if (empty($produkList)): ?>
                            <tr>
                                <td colspan="8" class="py-20 text-center">
                                    <div class="inline-flex flex-col items-center gap-3 opacity-30">
                                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                        </svg>
                                        <p class="text-xs font-bold uppercase tracking-widest">Produk tidak ditemukan</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($produkList as $i => $p):
                                $isLow    = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                                $isHabis  = $p['stok'] <= 0;
                                $stokPct  = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                                $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                                $margin   = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                            ?>
                                <tr class="group">
                                    <td class="px-5 py-4 text-[11px] text-gray-300 font-medium"><?= $offset + $i + 1 ?></td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm leading-tight"><?= e($p['nama']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?= e($p['kode']) ?> · <?= e($p['satuan']) ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-1 rounded-md">
                                            <?= e($p['kategori']) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm text-gray-500"><?= rupiah($p['harga_beli']) ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="text-sm font-bold"><?= rupiah($p['harga_jual']) ?></span>
                                        <?php if ($margin > 0): ?>
                                            <div class="text-[9px] text-green-500 font-bold text-right">+<?= $margin ?>% margin</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <div class="inline-flex flex-col items-center gap-1 min-w-[60px]">
                                            <span class="text-sm font-bold <?= $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : '') ?>">
                                                <?= number_format($p['stok']) ?>
                                                <span class="text-[9px] text-gray-400 font-normal"><?= e($p['satuan']) ?></span>
                                            </span>
                                            <div class="stok-bar w-14">
                                                <div class="stok-bar-fill <?= $stokColor ?>" style="width:<?= $stokPct ?>%"></div>
                                            </div>
                                            <span class="text-[9px] text-gray-400">min <?= $p['stok_minimum'] ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="openStokModal(<?= $p['id'] ?>, '<?= e(addslashes($p['nama'])) ?>', <?= $p['stok'] ?>)"
                                                class="p-2 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded-sm transition-all" title="Update Stok">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v8m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </button>
                                            <button onclick="editProduk(<?= $p['id'] ?>)"
                                                class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-sm transition-all" title="Edit Produk">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button onclick="hapusProduk(<?= $p['id'] ?>, '<?= e(addslashes($p['nama'])) ?>')"
                                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-sm transition-all" title="Hapus Produk">
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
                <?php if (empty($produkList)): ?>
                    <div class="py-16 text-center">
                        <div class="inline-flex flex-col items-center gap-3 opacity-30">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <p class="text-xs font-bold uppercase tracking-widest">Produk tidak ditemukan</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($produkList as $i => $p):
                            $isLow    = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                            $isHabis  = $p['stok'] <= 0;
                            $stokPct  = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                            $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                            $margin   = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                        ?>
                            <div class="produk-mobile-card bg-white border border-subtle rounded-sm p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[10px] text-gray-300 font-bold">#<?= $offset + $i + 1 ?></span>
                                            <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-1 rounded-sm">
                                                <?= e($p['kategori']) ?>
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-sm leading-tight text-gray-900 truncate"><?= e($p['nama']) ?></h3>
                                        <p class="text-[10px] text-gray-400 font-mono mt-1 truncate"><?= e($p['kode']) ?> · <?= e($p['satuan']) ?></p>
                                    </div>
                                    <div class="shrink-0">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1 rounded-full">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50/60 rounded-sm p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Jual</p>
                                        <p class="text-sm font-black"><?= rupiah($p['harga_jual']) ?></p>
                                        <?php if ($margin > 0): ?>
                                            <p class="text-[9px] text-green-500 font-bold mt-1">+<?= $margin ?>% margin</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="border border-subtle bg-gray-50/60 rounded-sm p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Beli</p>
                                        <p class="text-sm font-bold text-gray-600"><?= rupiah($p['harga_beli']) ?></p>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Stok</p>
                                        <span class="text-sm font-black <?= $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : 'text-gray-900') ?>">
                                            <?= number_format($p['stok']) ?>
                                            <span class="text-[9px] text-gray-400 font-normal"><?= e($p['satuan']) ?></span>
                                        </span>
                                    </div>
                                    <div class="stok-bar w-full">
                                        <div class="stok-bar-fill <?= $stokColor ?>" style="width:<?= $stokPct ?>%"></div>
                                    </div>
                                    <p class="text-[9px] text-gray-400 mt-1">Minimum <?= $p['stok_minimum'] ?></p>
                                </div>
                                <div class="flex items-center justify-between gap-2 pt-3 border-t border-subtle">
                                    <button onclick="openStokModal(<?= $p['id'] ?>, '<?= e(addslashes($p['nama'])) ?>', <?= $p['stok'] ?>)"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-yellow-100 text-yellow-700 hover:bg-yellow-50 rounded-sm transition-all">
                                        Stok
                                    </button>
                                    <button onclick="editProduk(<?= $p['id'] ?>)"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-blue-100 text-blue-700 hover:bg-blue-50 rounded-sm transition-all">
                                        Edit
                                    </button>
                                    <button onclick="hapusProduk(<?= $p['id'] ?>, '<?= e(addslashes($p['nama'])) ?>')"
                                        class="flex-1 inline-flex items-center justify-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-red-100 text-red-700 hover:bg-red-50 rounded-sm transition-all">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 md:px-5 py-4 border-t border-subtle flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50/50">
                    <span class="text-xs text-gray-400">
                        Halaman <?= $page ?> dari <?= $totalPages ?> (<?= number_format($totalRows) ?> total)
                    </span>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&kat=<?= urlencode($katFilter) ?>&status=<?= urlencode($statusFilter) ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle rounded-sm hover:bg-white transition-all">← Prev</a>
                        <?php endif; ?>
                        <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                            <a href="?page=<?= $pg ?>&q=<?= urlencode($search) ?>&kat=<?= urlencode($katFilter) ?>&status=<?= urlencode($statusFilter) ?>"
                                class="px-3 py-1.5 text-xs font-bold rounded-sm transition-all <?= $pg === $page ? 'bg-black text-white' : 'border border-subtle hover:bg-white' ?>">
                                <?= $pg ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&kat=<?= urlencode($katFilter) ?>&status=<?= urlencode($statusFilter) ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle rounded-sm hover:bg-white transition-all">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ══════════════════════════════════════════════════════════════
     MODAL: Tambah / Edit Produk
    ══════════════════════════════════════════════════════════════ -->
    <div id="modal-produk" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm hidden items-center justify-center p-4" style="display:none!important">
        <div class="modal-box bg-white w-full max-w-lg rounded-sm md:rounded-2xl shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest" id="modal-title">Tambah Produk</h2>
                    <p class="text-[10px] text-gray-400 mt-0.5" id="modal-subtitle">Isi form di bawah dengan benar</p>
                </div>
                <button onclick="closeModal()" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="px-5 md:px-7 py-6 space-y-5 max-h-[75vh] overflow-y-auto no-scrollbar">
                <input type="hidden" id="form-id">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kode Produk *</label>
                        <input type="text" id="form-kode" placeholder="contoh: 899100111001"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm font-mono transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Satuan</label>
                        <select id="form-satuan" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                            <option value="pcs">pcs</option>
                            <option value="botol">botol</option>
                            <option value="kotak">kotak</option>
                            <option value="kg">kg</option>
                            <option value="liter">liter</option>
                            <option value="lusin">lusin</option>
                            <option value="pack">pack</option>
                            <option value="karton">karton</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Nama Produk *</label>
                    <input type="text" id="form-nama" placeholder="Nama lengkap produk"
                        class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kategori</label>
                    <div class="flex gap-2">
                        <input type="text" id="form-kategori" placeholder="Minuman, Snack, Sembako..."
                            list="kat-list"
                            class="flex-1 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                        <datalist id="kat-list">
                            <?php foreach ($kategoriList as $k): ?>
                                <option value="<?= e($k) ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Beli</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-beli" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 rounded-lg pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Jual *</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-jual" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 rounded-lg pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                        <p class="text-[9px] text-green-500 font-bold mt-1" id="margin-info"></p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Awal</label>
                        <input type="number" id="form-stok" placeholder="0" min="0"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Minimum</label>
                        <input type="number" id="form-stok-min" placeholder="5" min="0"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-sm transition-all">
                        <p class="text-[9px] text-gray-400 mt-1">Batas peringatan stok limit</p>
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
                <button onclick="simpanProduk()" id="btn-simpan"
                    class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white rounded-sm hover:bg-gray-800 transition-all">
                    Simpan
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
     MODAL: Update Stok Cepat
    ══════════════════════════════════════════════════════════════ -->
    <div id="modal-stok" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm hidden items-center justify-center p-4" style="display:none!important">
        <div class="modal-box bg-white w-full max-w-sm rounded-sm md:rounded-2xl shadow-2xl overflow-hidden">
            <div class="px-7 py-5 border-b border-subtle">
                <h2 class="text-sm font-black uppercase tracking-widest">Update Stok</h2>
                <p class="text-xs text-gray-500 mt-0.5 font-medium" id="stok-nama-label">—</p>
            </div>
            <div class="px-7 py-6 space-y-4">
                <input type="hidden" id="stok-id">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Jumlah Stok Baru</label>
                    <input type="number" id="stok-baru" min="0"
                        class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 text-lg font-bold text-center transition-all">
                </div>
                <div class="flex gap-2">
                    <?php foreach ([5, 10, 20, 50] as $jml): ?>
                        <button onclick="document.getElementById('stok-baru').value = <?= $jml ?>"
                            class="flex-1 py-2 text-xs font-bold border border-subtle rounded-sm hover:bg-gray-100 transition-all">
                            +<?= $jml ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="px-7 py-5 border-t border-subtle bg-gray-50/50 flex gap-3">
                <button onclick="closeStokModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle rounded-sm hover:bg-white transition-all">
                    Batal
                </button>
                <button onclick="simpanStok()" class="flex-1 py-3 text-xs font-bold uppercase bg-yellow-500 text-white rounded-sm hover:bg-yellow-600 transition-all">
                    Update Stok
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
        <a href="produk.php" class="flex flex-col items-center p-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Produk</span>
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

        // ── State ──────────────────────────────────────────────────────────────────
        let editMode = false;

        // ── Live Search + Filter ───────────────────────────────────────────────────
        let searchTimer;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('filter-kat').addEventListener('change', applyFilter);
        document.getElementById('filter-status').addEventListener('change', applyFilter);

        function applyFilter() {
            const q = document.getElementById('search-input').value;
            const kat = document.getElementById('filter-kat').value;
            const status = document.getElementById('filter-status').value;
            const url = new URL(window.location.href);
            url.searchParams.set('q', q);
            url.searchParams.set('kat', kat);
            url.searchParams.set('status', status);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // ── Modal Produk ───────────────────────────────────────────────────────────
        function openModal(mode = 'tambah') {
            editMode = mode === 'edit';
            document.getElementById('modal-title').innerText = editMode ? 'Edit Produk' : 'Tambah Produk';
            document.getElementById('modal-subtitle').innerText = editMode ? 'Ubah data produk di bawah' : 'Isi form di bawah dengan benar';
            document.getElementById('modal-produk').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modal-produk').style.display = 'none';
            resetForm();
        }

        function resetForm() {
            ['form-id', 'form-kode', 'form-nama', 'form-kategori', 'form-harga-beli', 'form-harga-jual', 'form-stok', 'form-stok-min'].forEach(id => {
                document.getElementById(id).value = '';
            });
            document.getElementById('form-satuan').value = 'pcs';
            document.querySelector('input[name="form-status"][value="aktif"]').checked = true;
            document.getElementById('margin-info').innerText = '';
        }

        async function editProduk(id) {
            try {
                const res = await fetch(`produk.php?action=get&id=${id}`, {
                    method: 'POST'
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.message, 'error');
                    return;
                }
                const p = data.data;
                document.getElementById('form-id').value = p.id;
                document.getElementById('form-kode').value = p.kode;
                document.getElementById('form-nama').value = p.nama;
                document.getElementById('form-kategori').value = p.kategori;
                document.getElementById('form-harga-beli').value = p.harga_beli;
                document.getElementById('form-harga-jual').value = p.harga_jual;
                document.getElementById('form-stok').value = p.stok;
                document.getElementById('form-stok-min').value = p.stok_minimum;
                document.getElementById('form-satuan').value = p.satuan;
                document.querySelector(`input[name="form-status"][value="${p.status}"]`).checked = true;
                hitungMargin();
                openModal('edit');
            } catch (e) {
                showToast('Gagal memuat data produk.', 'error');
            }
        }

        async function simpanProduk() {
            const id = document.getElementById('form-id').value;
            const action = id ? 'edit' : 'tambah';
            const btn = document.getElementById('btn-simpan');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            const payload = {
                id: id || undefined,
                kode: document.getElementById('form-kode').value.trim(),
                nama: document.getElementById('form-nama').value.trim(),
                kategori: document.getElementById('form-kategori').value.trim() || '-',
                harga_beli: document.getElementById('form-harga-beli').value || 0,
                harga_jual: document.getElementById('form-harga-jual').value,
                stok: document.getElementById('form-stok').value || 0,
                stok_minimum: document.getElementById('form-stok-min').value || 5,
                satuan: document.getElementById('form-satuan').value,
                status: document.querySelector('input[name="form-status"]:checked').value,
            };

            try {
                const res = await fetch(`produk.php?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Simpan';
            }
        }

        async function hapusProduk(id, nama) {
            if (!confirm(`Hapus produk "${nama}"?\n\nJika pernah ada di transaksi, produk akan dinonaktifkan.`)) return;
            try {
                const res = await fetch('produk.php?action=hapus', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Modal Stok Cepat ───────────────────────────────────────────────────────
        function openStokModal(id, nama, stokSekarang) {
            document.getElementById('stok-id').value = id;
            document.getElementById('stok-nama-label').innerText = nama;
            document.getElementById('stok-baru').value = stokSekarang;
            document.getElementById('modal-stok').style.display = 'flex';
            setTimeout(() => document.getElementById('stok-baru').select(), 100);
        }

        function closeStokModal() {
            document.getElementById('modal-stok').style.display = 'none';
        }

        async function simpanStok() {
            const id = document.getElementById('stok-id').value;
            const stok = document.getElementById('stok-baru').value;
            if (stok === '' || stok < 0) {
                showToast('Masukkan jumlah stok yang valid.', 'error');
                return;
            }
            try {
                const res = await fetch('produk.php?action=update_stok', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id,
                        stok: parseInt(stok)
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeStokModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Hitung Margin ──────────────────────────────────────────────────────────
        function hitungMargin() {
            const beli = parseInt(document.getElementById('form-harga-beli').value || 0);
            const jual = parseInt(document.getElementById('form-harga-jual').value || 0);
            const info = document.getElementById('margin-info');
            if (beli > 0 && jual > 0) {
                const margin = Math.round((jual - beli) / jual * 100);
                info.innerText = margin >= 0 ? `Margin: +${margin}%` : `Margin: ${margin}% (rugi)`;
                info.className = `text-[9px] font-bold mt-1 ${margin >= 0 ? 'text-green-500' : 'text-red-500'}`;
            } else {
                info.innerText = '';
            }
        }
        document.getElementById('form-harga-beli').addEventListener('input', hitungMargin);
        document.getElementById('form-harga-jual').addEventListener('input', hitungMargin);

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
        document.getElementById('modal-produk').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('modal-stok').addEventListener('click', function(e) {
            if (e.target === this) closeStokModal();
        });
    </script>
</body>

</html>