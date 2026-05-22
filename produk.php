<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'produk';
$pageTitle  = 'Kelola Produk';
$backUrl    = 'dashboard.php';

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('e')) {
    /**
     * @param  mixed  $v
     * @return string
     */
    function e($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah')) {
    /**
     * @param  mixed  $n
     * @return string
     */
    function rupiah($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

// ── API Handler (AJAX) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {

        case 'tambah':
            $input    = json_decode(file_get_contents('php://input'), true);
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
                ':kategori'     => trim(isset($input['kategori']) ? $input['kategori'] : '-'),
                ':harga_beli'   => (int)(isset($input['harga_beli']) ? $input['harga_beli'] : 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)(isset($input['stok']) ? $input['stok'] : 0),
                ':stok_minimum' => (int)(isset($input['stok_minimum']) ? $input['stok_minimum'] : 5),
                ':satuan'       => trim(isset($input['satuan']) ? $input['satuan'] : 'pcs'),
                ':status'       => in_array(isset($input['status']) ? $input['status'] : 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            catat_aktivitas($pdo, 'create', 'Produk', 'Menambah produk: ' . trim($input['nama']));
            echo json_encode(['success' => true, 'message' => 'Produk berhasil ditambahkan.', 'id' => $pdo->lastInsertId()]);
            exit;

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
                ':kategori'     => trim(isset($input['kategori']) ? $input['kategori'] : '-'),
                ':harga_beli'   => (int)(isset($input['harga_beli']) ? $input['harga_beli'] : 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)(isset($input['stok']) ? $input['stok'] : 0),
                ':stok_minimum' => (int)(isset($input['stok_minimum']) ? $input['stok_minimum'] : 5),
                ':satuan'       => trim(isset($input['satuan']) ? $input['satuan'] : 'pcs'),
                ':status'       => in_array(isset($input['status']) ? $input['status'] : 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah produk ID: ' . (int)$input['id']);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil diperbarui.']);
            exit;

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
                catat_aktivitas($pdo, 'status', 'Produk', 'Menonaktifkan produk ID: ' . (int)$input['id']);
                echo json_encode(['success' => true, 'message' => 'Produk dinonaktifkan (ada di riwayat transaksi).', 'type' => 'soft']);
            } else {
                $pdo->prepare("DELETE FROM produk WHERE id = :id")->execute([':id' => $input['id']]);
                catat_aktivitas($pdo, 'delete', 'Produk', 'Menghapus produk ID: ' . (int)$input['id']);
                echo json_encode(['success' => true, 'message' => 'Produk berhasil dihapus.', 'type' => 'hard']);
            }
            exit;

        case 'get':
            $id   = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
            $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) echo json_encode(['success' => true, 'data' => $row]);
            else      echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan.']);
            exit;

        case 'update_stok':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $pdo->prepare("UPDATE produk SET stok = :stok, updated_at = NOW() WHERE id = :id")
                ->execute([':stok' => (int)$input['stok'], ':id' => (int)$input['id']]);
            catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah stok produk ID: ' . (int)$input['id']);
            echo json_encode(['success' => true, 'message' => 'Stok berhasil diperbarui.']);
            exit;
    }
    echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
    exit;
}

// ── Fetch Data ────────────────────────────────────────────────────────────────
$search       = trim(isset($_GET['q'])      ? $_GET['q']      : '');
$katFilter    = isset($_GET['kat'])          ? $_GET['kat']    : '';
$statusFilter = isset($_GET['status'])       ? $_GET['status'] : 'aktif';
$page         = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
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

$kategoriList = $pdo->query("
    SELECT DISTINCT TRIM(kategori) AS kategori
    FROM produk
    WHERE kategori IS NOT NULL
      AND TRIM(kategori) <> ''
      AND TRIM(kategori) <> '-'
    ORDER BY kategori ASC
")->fetchAll(PDO::FETCH_COLUMN);

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN stok <= stok_minimum THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN stok = 0 THEN 1 ELSE 0 END) AS habis,
        SUM(harga_jual * stok) AS nilai_stok
    FROM produk
")->fetch();

catat_view_once($pdo, 'Produk', 'Membuka halaman Produk');

// ── Tombol di Navbar ─────────────────────────────────────────────────────────
$rightActionHtml = '
<button
    onclick="openModal(\'tambah\')"
    class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest bg-black text-white hover:bg-gray-800 transition-all">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-width="2.5" d="M12 4v16m8-8H4" />
    </svg>
    <span class="hidden sm:inline">Tambah Produk</span>
    <span class="sm:hidden">+</span>
</button>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Produk</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
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

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .produk-main {
                padding-bottom: 5.5rem !important;
            }
        }

        @media (max-width: 640px) {
            #toast {
                left: 1rem;
                right: 1rem;
                bottom: 5rem;
                justify-content: center;
            }
        }

        /* Clean box style */
        .produk-main .summary-card,
        .produk-main .filter-card,
        .produk-main .table-card,
        .produk-main .produk-mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .produk-main input,
        .produk-main select,
        .produk-main textarea,
        .produk-main button {
            border-radius: 0 !important;
        }

        .produk-mobile-card {
            transition: border-color 0.15s ease;
        }

        .produk-mobile-card:hover {
            border-color: #e5e7eb;
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="produk-main p-4 sm:p-5 md:p-8 lg:p-10">

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total SKU</p>
                <p class="text-2xl font-bold"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format($summary['aktif']); ?> aktif</p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Stok</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo rupiah($summary['nilai_stok'] ?? 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Estimasi HPP</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo $summary['low_stock'] > 0 ? 'border-yellow-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                <p class="text-2xl font-bold <?php echo $summary['low_stock'] > 0 ? 'text-yellow-600' : 'text-gray-800'; ?>">
                    <?php echo number_format($summary['low_stock']); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">di bawah minimum</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo $summary['habis'] > 0 ? 'border-red-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Habis</p>
                <p class="text-2xl font-bold <?php echo $summary['habis'] > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
                    <?php echo number_format($summary['habis']); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">perlu restock</p>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="filter-card p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="search-input" value="<?php echo e($search); ?>"
                    placeholder="Cari nama, kode, atau kategori..."
                    class="w-full bg-gray-50 border border-gray-100 rounded-sm pl-10 pr-4 py-2.5 text-sm focus:bg-white transition-all">
            </div>
            <select id="filter-kat" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Kategori</option>
                <?php foreach ($kategoriList as $k): ?>
                    <option value="<?php echo e($k); ?>" <?php echo $katFilter === $k ? 'selected' : ''; ?>><?php echo e($k); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-status" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="aktif" <?php echo $statusFilter === 'aktif'    ? 'selected' : ''; ?>>Aktif</option>
                <option value="nonaktif" <?php echo $statusFilter === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                <option value="semua" <?php echo $statusFilter === 'semua'    ? 'selected' : ''; ?>>Semua Status</option>
            </select>
            <span class="text-xs text-gray-400 font-medium ml-auto hidden sm:block">
                <?php echo number_format($totalRows); ?> produk ditemukan
            </span>
        </div>

        <!-- Tabel & Card -->
        <div class="table-card overflow-hidden">

            <!-- ── DESKTOP: Tabel ──────────────────────────────────────────── -->
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
                    <tbody class="divide-y divide-[#f5f5f5]">
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
                                $isLow     = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                                $isHabis   = $p['stok'] <= 0;
                                $stokPct   = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                                $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                                $margin    = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                            ?>
                                <tr class="group">
                                    <td class="px-5 py-4 text-[11px] text-gray-300 font-medium"><?php echo $offset + $i + 1; ?></td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm leading-tight"><?php echo e($p['nama']); ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo e($p['kode']); ?> &middot; <?php echo e($p['satuan']); ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-1">
                                            <?php echo e($p['kategori']); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm text-gray-500"><?php echo rupiah($p['harga_beli']); ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="text-sm font-bold"><?php echo rupiah($p['harga_jual']); ?></span>
                                        <?php if ($margin > 0): ?>
                                            <div class="text-[9px] text-green-500 font-bold text-right">+<?php echo $margin; ?>% margin</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <div class="inline-flex flex-col items-center gap-1 min-w-[60px]">
                                            <span class="text-sm font-bold <?php echo $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : ''); ?>">
                                                <?php echo number_format($p['stok']); ?>
                                                <span class="text-[9px] text-gray-400 font-normal"><?php echo e($p['satuan']); ?></span>
                                            </span>
                                            <div class="stok-bar w-14">
                                                <div class="stok-bar-fill <?php echo $stokColor; ?>" style="width:<?php echo $stokPct; ?>%"></div>
                                            </div>
                                            <span class="text-[9px] text-gray-400">min <?php echo $p['stok_minimum']; ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="openStokModal(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>', <?php echo $p['stok']; ?>)"
                                                class="p-2 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 transition-all" title="Update Stok">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v8m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </button>
                                            <button onclick="editProduk(<?php echo $p['id']; ?>)"
                                                class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button onclick="hapusProduk(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>')"
                                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Hapus">
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

            <!-- ── MOBILE & TABLET: Card ───────────────────────────────────── -->
            <div class="lg:hidden">
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
                            $isLow     = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                            $isHabis   = $p['stok'] <= 0;
                            $stokPct   = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                            $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                            $margin    = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                        ?>
                            <div class="produk-mobile-card bg-white border border-subtle p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[10px] text-gray-300 font-bold">#<?php echo $offset + $i + 1; ?></span>
                                            <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-0.5">
                                                <?php echo e($p['kategori']); ?>
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-sm leading-tight text-gray-900 truncate"><?php echo e($p['nama']); ?></h3>
                                        <p class="text-[10px] text-gray-400 font-mono mt-1 truncate"><?php echo e($p['kode']); ?> &middot; <?php echo e($p['satuan']); ?></p>
                                    </div>
                                    <div class="shrink-0">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Jual</p>
                                        <p class="text-sm font-black"><?php echo rupiah($p['harga_jual']); ?></p>
                                        <?php if ($margin > 0): ?>
                                            <p class="text-[9px] text-green-500 font-bold mt-1">+<?php echo $margin; ?>% margin</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Beli</p>
                                        <p class="text-sm font-bold text-gray-600"><?php echo rupiah($p['harga_beli']); ?></p>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Stok</p>
                                        <span class="text-sm font-black <?php echo $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : 'text-gray-900'); ?>">
                                            <?php echo number_format($p['stok']); ?>
                                            <span class="text-[9px] text-gray-400 font-normal"><?php echo e($p['satuan']); ?></span>
                                        </span>
                                    </div>
                                    <div class="stok-bar w-full">
                                        <div class="stok-bar-fill <?php echo $stokColor; ?>" style="width:<?php echo $stokPct; ?>%"></div>
                                    </div>
                                    <p class="text-[9px] text-gray-400 mt-1">Minimum <?php echo $p['stok_minimum']; ?></p>
                                </div>
                                <div class="flex items-center gap-2 pt-3 border-t border-subtle">
                                    <button onclick="openStokModal(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>', <?php echo $p['stok']; ?>)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-yellow-100 text-yellow-700 hover:bg-yellow-50 transition-all">
                                        Stok
                                    </button>
                                    <button onclick="editProduk(<?php echo $p['id']; ?>)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-blue-100 text-blue-700 hover:bg-blue-50 transition-all">
                                        Edit
                                    </button>
                                    <button onclick="hapusProduk(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>')"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-red-100 text-red-700 hover:bg-red-50 transition-all">
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
                <div class="px-4 md:px-5 py-4 border-t border-subtle flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50">
                    <span class="text-xs text-gray-400">
                        Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?> total)
                    </span>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&q=<?php echo urlencode($search); ?>&kat=<?php echo urlencode($katFilter); ?>&status=<?php echo urlencode($statusFilter); ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle hover:bg-white transition-all">&larr; Prev</a>
                        <?php endif; ?>
                        <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                            <a href="?page=<?php echo $pg; ?>&q=<?php echo urlencode($search); ?>&kat=<?php echo urlencode($katFilter); ?>&status=<?php echo urlencode($statusFilter); ?>"
                                class="px-3 py-1.5 text-xs font-bold transition-all <?php echo $pg === $page ? 'bg-black text-white' : 'border border-subtle hover:bg-white'; ?>">
                                <?php echo $pg; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&q=<?php echo urlencode($search); ?>&kat=<?php echo urlencode($katFilter); ?>&status=<?php echo urlencode($statusFilter); ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle hover:bg-white transition-all">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ── MODAL: Tambah / Edit Produk ─────────────────────────────────────── -->
    <div id="modal-produk" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-lg shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest" id="modal-title">Tambah Produk</h2>
                    <p class="text-[10px] text-gray-400 mt-0.5" id="modal-subtitle">Isi form di bawah dengan benar</p>
                </div>
                <button onclick="closeModal()" class="p-2 hover:bg-gray-100 transition-colors">
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
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm font-mono transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Satuan</label>
                        <select id="form-satuan" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
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
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kategori</label>

                    <select id="form-kategori-select"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all mb-2">
                        <option value="">Pilih kategori yang sudah ada</option>
                        <?php foreach ($kategoriList as $k): ?>
                            <option value="<?php echo e($k); ?>"><?php echo e($k); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" id="form-kategori-baru" placeholder="Atau ketik kategori baru..."
                        list="kat-list"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">

                    <datalist id="kat-list">
                        <?php foreach ($kategoriList as $k): ?>
                            <option value="<?php echo e($k); ?>">
                            <?php endforeach; ?>
                    </datalist>

                    <p class="text-[9px] text-gray-400 mt-1">
                        Kalau kategori baru diisi, sistem akan otomatis menyimpannya ke produk dan muncul di dropdown berikutnya.
                    </p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Beli</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-beli" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Jual *</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-jual" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                        <p class="text-[9px] text-green-500 font-bold mt-1" id="margin-info"></p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Awal</label>
                        <input type="number" id="form-stok" placeholder="0" min="0"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Minimum</label>
                        <input type="number" id="form-stok-min" placeholder="5" min="0"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
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
            <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3">
                <button onclick="closeModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle hover:bg-white transition-all">Batal</button>
                <button onclick="simpanProduk()" id="btn-simpan"
                    class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white hover:bg-gray-800 transition-all">Simpan</button>
            </div>
        </div>
    </div>

    <!-- ── MODAL: Update Stok ───────────────────────────────────────────────── -->
    <div id="modal-stok" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-sm shadow-2xl overflow-hidden">
            <div class="px-7 py-5 border-b border-subtle">
                <h2 class="text-sm font-black uppercase tracking-widest">Update Stok</h2>
                <p class="text-xs text-gray-500 mt-0.5 font-medium" id="stok-nama-label">&mdash;</p>
            </div>
            <div class="px-7 py-6 space-y-4">
                <input type="hidden" id="stok-id">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Jumlah Stok Baru</label>
                    <input type="number" id="stok-baru" min="0"
                        class="w-full bg-gray-50 border border-gray-200 px-4 py-3 text-lg font-bold text-center transition-all">
                </div>
                <div class="flex gap-2">
                    <?php foreach ([5, 10, 20, 50] as $jml): ?>
                        <button onclick="document.getElementById('stok-baru').value = <?php echo $jml; ?>"
                            class="flex-1 py-2 text-xs font-bold border border-subtle hover:bg-gray-100 transition-all">
                            +<?php echo $jml; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3">
                <button onclick="closeStokModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle hover:bg-white transition-all">Batal</button>
                <button onclick="simpanStok()" class="flex-1 py-3 text-xs font-bold uppercase bg-yellow-500 text-white hover:bg-yellow-600 transition-all">Update Stok</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 shadow-2xl pointer-events-none">
        <span id="toast-icon"></span>
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

    <script>
        var editMode = false;

        // ── Live Filter ─────────────────────────────────────────────────────────────
        var searchTimer;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('filter-kat').addEventListener('change', applyFilter);
        document.addEventListener('DOMContentLoaded', function() {
            var kategoriSelect = document.getElementById('form-kategori-select');
            if (kategoriSelect) {
                kategoriSelect.addEventListener('change', function() {
                    if (this.value !== '') {
                        document.getElementById('form-kategori-baru').value = '';
                    }
                });
            }
        });
        document.getElementById('filter-status').addEventListener('change', applyFilter);

        function applyFilter() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('search-input').value);
            url.searchParams.set('kat', document.getElementById('filter-kat').value);
            url.searchParams.set('status', document.getElementById('filter-status').value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // ── Modal Produk ─────────────────────────────────────────────────────────────
        function openModal(mode) {
            mode = mode || 'tambah';
            editMode = mode === 'edit';
            document.getElementById('modal-title').innerText = editMode ? 'Edit Produk' : 'Tambah Produk';
            document.getElementById('modal-subtitle').innerText = editMode ? 'Ubah data produk di bawah' : 'Isi form di bawah dengan benar';
            document.getElementById('modal-produk').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modal-produk').style.display = 'none';
            resetForm();
        }

        function getKategoriProduk() {
            var baru = document.getElementById('form-kategori-baru').value.trim();
            var pilih = document.getElementById('form-kategori-select').value.trim();

            if (baru !== '') {
                return baru;
            }

            if (pilih !== '') {
                return pilih;
            }

            return '-';
        }

        function setKategoriProduk(kategori) {
            kategori = String(kategori || '').trim();

            var select = document.getElementById('form-kategori-select');
            var inputBaru = document.getElementById('form-kategori-baru');
            var found = false;

            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value === kategori) {
                    found = true;
                    break;
                }
            }

            if (found) {
                select.value = kategori;
                inputBaru.value = '';
            } else {
                select.value = '';
                inputBaru.value = kategori === '-' ? '' : kategori;
            }
        }

        function resetForm() {
            ['form-id', 'form-kode', 'form-nama', 'form-kategori-baru', 'form-harga-beli', 'form-harga-jual', 'form-stok', 'form-stok-min'].forEach(function(id) {
                document.getElementById(id).value = '';
            });
            document.getElementById('form-satuan').value = 'pcs';
            document.getElementById('form-kategori-select').value = '';
            document.querySelector('input[name="form-status"][value="aktif"]').checked = true;
            document.getElementById('margin-info').innerText = '';
        }

        async function editProduk(id) {
            try {
                var res = await fetch('produk.php?action=get&id=' + id, {
                    method: 'POST'
                });
                var data = await res.json();
                if (!data.success) {
                    showToast(data.message, 'error');
                    return;
                }
                var p = data.data;
                document.getElementById('form-id').value = p.id;
                document.getElementById('form-kode').value = p.kode;
                document.getElementById('form-nama').value = p.nama;
                setKategoriProduk(p.kategori);
                document.getElementById('form-harga-beli').value = p.harga_beli;
                document.getElementById('form-harga-jual').value = p.harga_jual;
                document.getElementById('form-stok').value = p.stok;
                document.getElementById('form-stok-min').value = p.stok_minimum;
                document.getElementById('form-satuan').value = p.satuan;
                document.querySelector('input[name="form-status"][value="' + p.status + '"]').checked = true;
                hitungMargin();
                openModal('edit');
            } catch (e) {
                showToast('Gagal memuat data produk.', 'error');
            }
        }

        async function simpanProduk() {
            var id = document.getElementById('form-id').value;
            var action = id ? 'edit' : 'tambah';
            var btn = document.getElementById('btn-simpan');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            var payload = {
                id: id || undefined,
                kode: document.getElementById('form-kode').value.trim(),
                nama: document.getElementById('form-nama').value.trim(),
                kategori: getKategoriProduk(),
                harga_beli: document.getElementById('form-harga-beli').value || 0,
                harga_jual: document.getElementById('form-harga-jual').value,
                stok: document.getElementById('form-stok').value || 0,
                stok_minimum: document.getElementById('form-stok-min').value || 5,
                satuan: document.getElementById('form-satuan').value,
                status: document.querySelector('input[name="form-status"]:checked').value,
            };

            try {
                var res = await fetch('produk.php?action=' + action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(function() {
                        location.reload();
                    }, 800);
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
            if (!confirm('Hapus produk "' + nama + '"?\n\nJika pernah ada di transaksi, produk akan dinonaktifkan.')) return;
            try {
                var res = await fetch('produk.php?action=hapus', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Modal Stok ───────────────────────────────────────────────────────────────
        function openStokModal(id, nama, stokSekarang) {
            document.getElementById('stok-id').value = id;
            document.getElementById('stok-nama-label').innerText = nama;
            document.getElementById('stok-baru').value = stokSekarang;
            document.getElementById('modal-stok').style.display = 'flex';
            setTimeout(function() {
                document.getElementById('stok-baru').select();
            }, 100);
        }

        function closeStokModal() {
            document.getElementById('modal-stok').style.display = 'none';
        }

        async function simpanStok() {
            var id = document.getElementById('stok-id').value;
            var stok = document.getElementById('stok-baru').value;
            if (stok === '' || stok < 0) {
                showToast('Masukkan jumlah stok yang valid.', 'error');
                return;
            }
            try {
                var res = await fetch('produk.php?action=update_stok', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        stok: parseInt(stok)
                    })
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeStokModal();
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Hitung Margin ────────────────────────────────────────────────────────────
        function hitungMargin() {
            var beli = parseInt(document.getElementById('form-harga-beli').value || 0);
            var jual = parseInt(document.getElementById('form-harga-jual').value || 0);
            var info = document.getElementById('margin-info');
            if (beli > 0 && jual > 0) {
                var margin = Math.round((jual - beli) / jual * 100);
                info.innerText = margin >= 0 ? 'Margin: +' + margin + '%' : 'Margin: ' + margin + '% (rugi)';
                info.className = 'text-[9px] font-bold mt-1 ' + (margin >= 0 ? 'text-green-500' : 'text-red-500');
            } else {
                info.innerText = '';
            }
        }
        document.getElementById('form-harga-beli').addEventListener('input', hitungMargin);
        document.getElementById('form-harga-jual').addEventListener('input', hitungMargin);

        // ── Toast ────────────────────────────────────────────────────────────────────
        var toastTimer;

        function showToast(msg, type) {
            type = type || 'success';
            var toast = document.getElementById('toast');
            var icon = document.getElementById('toast-icon');
            document.getElementById('toast-msg').innerText = msg;
            icon.innerHTML = type === 'success' ?
                '<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' :
                '<svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function() {
                toast.classList.remove('show');
            }, 3000);
        }

        // ── Close on overlay click ───────────────────────────────────────────────────
        document.getElementById('modal-produk').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('modal-stok').addEventListener('click', function(e) {
            if (e.target === this) closeStokModal();
        });
    </script>
</body>

</html>