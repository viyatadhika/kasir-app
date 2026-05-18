<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'diskon';
$pageTitle  = 'Kelola Diskon';
$backUrl    = 'dashboard.php';

$success = '';
$error   = '';

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    /**
     * @param  mixed  $v
     * @return string
     */
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_diskon')) {
    /**
     * @param  mixed  $n
     * @return string
     */
    function rupiah_diskon($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_diskon')) {
    /**
     * @param  mixed  $tgl
     * @return string
     */
    function tanggal_diskon($tgl)
    {
        return $tgl ? date('d/m/Y', strtotime($tgl)) : '-';
    }
}

try {
    $produkList   = $pdo->query("SELECT id, kode, nama, kategori FROM produk WHERE status='aktif' ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
    $kategoriList = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE status='aktif' AND kategori IS NOT NULL AND kategori<>'' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $produkList   = [];
    $kategoriList = [];
}

// ── POST Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'save') {
            $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $nama    = trim(isset($_POST['nama'])    ? $_POST['nama']    : '');
            $cakupan = isset($_POST['cakupan'])       ? $_POST['cakupan'] : 'transaksi';
            $target  = 'semua';
            $produkId = !empty($_POST['produk_id'])  ? (int)$_POST['produk_id'] : null;
            $kategori = trim(isset($_POST['kategori']) ? $_POST['kategori'] : '');
            $jenis   = isset($_POST['jenis'])         ? $_POST['jenis']   : 'persen';
            $nilai   = (int)(isset($_POST['nilai'])   ? $_POST['nilai']   : 0);
            $minimal = (int)(isset($_POST['minimal_belanja']) ? $_POST['minimal_belanja'] : 0);
            $mulai   = !empty($_POST['tanggal_mulai'])   ? $_POST['tanggal_mulai']   : null;
            $selesai = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : null;
            $status  = isset($_POST['status'])        ? $_POST['status']  : 'aktif';

            if ($nama === '') throw new Exception('Nama diskon wajib diisi.');
            if (!in_array($cakupan, ['transaksi', 'produk', 'kategori'], true)) throw new Exception('Cakupan tidak valid.');
            if (!in_array($jenis,   ['persen', 'nominal'], true))              throw new Exception('Jenis diskon tidak valid.');
            if (!in_array($status,  ['aktif', 'nonaktif'], true))              throw new Exception('Status tidak valid.');
            if ($nilai <= 0) throw new Exception('Nilai diskon harus lebih dari 0.');
            if ($jenis === 'persen' && $nilai > 100) throw new Exception('Diskon persen maksimal 100%.');
            if ($minimal < 0) throw new Exception('Minimal belanja tidak boleh minus.');
            if ($mulai && $selesai && strtotime($selesai) < strtotime($mulai)) throw new Exception('Tanggal selesai tidak boleh lebih kecil dari tanggal mulai.');
            if ($cakupan === 'produk'   && !$produkId)        throw new Exception('Pilih produk untuk diskon produk.');
            if ($cakupan === 'kategori' && $kategori === '')  throw new Exception('Pilih kategori untuk diskon kategori.');

            if ($cakupan !== 'produk')   $produkId = null;
            if ($cakupan !== 'kategori') $kategori = null;

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE diskon
                    SET nama=:nama, cakupan=:cakupan, target=:target, produk_id=:produk_id, kategori=:kategori,
                        jenis=:jenis, nilai=:nilai, minimal_belanja=:minimal, tanggal_mulai=:mulai,
                        tanggal_selesai=:selesai, status=:status, updated_at=NOW()
                    WHERE id=:id
                ");
                $stmt->execute([':nama' => $nama, ':cakupan' => $cakupan, ':target' => $target, ':produk_id' => $produkId, ':kategori' => $kategori, ':jenis' => $jenis, ':nilai' => $nilai, ':minimal' => $minimal, ':mulai' => $mulai, ':selesai' => $selesai, ':status' => $status, ':id' => $id]);
                $success = 'Diskon berhasil diperbarui.';
                catat_aktivitas($pdo, 'update', 'Diskon', 'Mengubah diskon: ' . $nama);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO diskon (nama, cakupan, target, produk_id, kategori, jenis, nilai, minimal_belanja, tanggal_mulai, tanggal_selesai, status)
                    VALUES (:nama, :cakupan, :target, :produk_id, :kategori, :jenis, :nilai, :minimal, :mulai, :selesai, :status)
                ");
                $stmt->execute([':nama' => $nama, ':cakupan' => $cakupan, ':target' => $target, ':produk_id' => $produkId, ':kategori' => $kategori, ':jenis' => $jenis, ':nilai' => $nilai, ':minimal' => $minimal, ':mulai' => $mulai, ':selesai' => $selesai, ':status' => $status]);
                $success = 'Diskon berhasil ditambahkan.';
                catat_aktivitas($pdo, 'create', 'Diskon', 'Menambah diskon: ' . $nama);
            }
        } elseif ($action === 'toggle') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $pdo->prepare("UPDATE diskon SET status=IF(status='aktif','nonaktif','aktif'), updated_at=NOW() WHERE id=:id")->execute([':id' => $id]);
            $success = 'Status diskon berhasil diubah.';
            catat_aktivitas($pdo, 'status', 'Diskon', 'Mengubah status diskon ID: ' . $id);
        } elseif ($action === 'delete') {
            $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $pdo->prepare("DELETE FROM diskon WHERE id=:id")->execute([':id' => $id]);
            $success = 'Diskon berhasil dihapus.';
            catat_aktivitas($pdo, 'delete', 'Diskon', 'Menghapus diskon ID: ' . $id);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Fetch List ────────────────────────────────────────────────────────────────
$q             = trim(isset($_GET['q'])       ? $_GET['q']       : '');
$filterStatus  = isset($_GET['status'])        ? $_GET['status']  : '';
$filterCakupan = isset($_GET['cakupan'])       ? $_GET['cakupan'] : '';
$where         = [];
$params        = [];

if ($q !== '') {
    $where[]        = "(d.nama LIKE :q OR d.kategori LIKE :q OR p.nama LIKE :q OR p.kode LIKE :q)";
    $params[':q']   = '%' . $q . '%';
}
if (in_array($filterStatus, ['aktif', 'nonaktif'], true)) {
    $where[]           = "d.status=:status";
    $params[':status'] = $filterStatus;
}
if (in_array($filterCakupan, ['transaksi', 'produk', 'kategori'], true)) {
    $where[]            = "d.cakupan=:cakupan";
    $params[':cakupan'] = $filterCakupan;
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt     = $pdo->prepare("
    SELECT d.*, p.kode AS produk_kode, p.nama AS produk_nama
    FROM diskon d
    LEFT JOIN produk p ON p.id = d.produk_id
    $sqlWhere
    ORDER BY d.status='aktif' DESC, d.id DESC
");
$stmt->execute($params);
$diskonList = $stmt->fetchAll(PDO::FETCH_ASSOC);

try {
    $summary = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='aktif' THEN 1 ELSE 0 END) AS aktif,
            SUM(CASE WHEN status='aktif' AND cakupan='transaksi' THEN 1 ELSE 0 END) AS transaksi,
            SUM(CASE WHEN status='aktif' AND cakupan='produk'    THEN 1 ELSE 0 END) AS produk,
            SUM(CASE WHEN status='aktif' AND cakupan='kategori'  THEN 1 ELSE 0 END) AS kategori
        FROM diskon
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $summary = ['total' => 0, 'aktif' => 0, 'transaksi' => 0, 'produk' => 0, 'kategori' => 0];
}

catat_view_once($pdo, 'Diskon', 'Membuka halaman Diskon');

// ── Tombol di Navbar ─────────────────────────────────────────────────────────
$rightActionHtml = '
<button
    onclick="openModal(\'tambah\')"
    class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest bg-black text-white hover:bg-gray-800 transition-all">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-width="2.5" d="M12 4v16m8-8H4" />
    </svg>
    <span class="hidden sm:inline">Tambah Diskon</span>
    <span class="sm:hidden">+</span>
</button>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Diskon</title>
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

        .diskon-mobile-card {
            transition: border-color 0.15s ease;
        }

        .diskon-mobile-card:hover {
            border-color: #e5e7eb;
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

            .diskon-main {
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

        .diskon-main .summary-card,
        .diskon-main .filter-card,
        .diskon-main .table-card,
        .diskon-main .diskon-mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .diskon-main input,
        .diskon-main select,
        .diskon-main textarea,
        .diskon-main button {
            border-radius: 0 !important;
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="diskon-main p-4 sm:p-5 md:p-8 lg:p-10">

        <!-- Alert -->
        <?php if ($success): ?>
            <div class="mb-5 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-xs font-bold">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
                <?php echo h($success); ?>
                <button onclick="this.parentElement.remove()" class="ml-auto text-green-500 hover:text-green-700">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div id="alert-error" class="mb-5 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <?php echo h($error); ?>
                <button onclick="this.parentElement.remove()" class="ml-auto text-red-500 hover:text-red-700">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Diskon</p>
                <p class="text-2xl font-bold"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format($summary['aktif']); ?> aktif</p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Transaksi</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($summary['transaksi']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Produk</p>
                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($summary['produk']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Per Kategori</p>
                <p class="text-2xl font-bold text-orange-500"><?php echo number_format($summary['kategori']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">promo aktif</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="filter-card p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="search-input" value="<?php echo h($q); ?>"
                    placeholder="Cari nama diskon / produk / kategori..."
                    class="w-full bg-gray-50 border border-gray-100 pl-10 pr-4 py-2.5 text-sm focus:bg-white transition-all">
            </div>
            <select id="filter-cakupan" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Cakupan</option>
                <option value="transaksi" <?php echo $filterCakupan === 'transaksi' ? 'selected' : ''; ?>>Transaksi</option>
                <option value="produk" <?php echo $filterCakupan === 'produk'    ? 'selected' : ''; ?>>Produk</option>
                <option value="kategori" <?php echo $filterCakupan === 'kategori'  ? 'selected' : ''; ?>>Kategori</option>
            </select>
            <select id="filter-status" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Status</option>
                <option value="aktif" <?php echo $filterStatus === 'aktif'    ? 'selected' : ''; ?>>Aktif</option>
                <option value="nonaktif" <?php echo $filterStatus === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
            </select>
            <span class="text-xs text-gray-400 font-medium ml-auto hidden sm:block">
                <?php echo number_format(count($diskonList)); ?> diskon ditemukan
            </span>
        </div>

        <!-- Tabel & Card -->
        <div class="table-card overflow-hidden">

            <!-- ── DESKTOP: Tabel ──────────────────────────────────────────── -->
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
                                $cakupan = isset($d['cakupan']) ? $d['cakupan'] : 'transaksi';
                                if ($cakupan === 'produk') {
                                    $cakupanLabel = (isset($d['produk_kode']) && $d['produk_kode']) ? $d['produk_kode'] . ' - ' . $d['produk_nama'] : '-';
                                    $cakupanBadge = 'bg-purple-50 text-purple-700';
                                    $cakupanTag   = 'Produk';
                                } elseif ($cakupan === 'kategori') {
                                    $cakupanLabel = isset($d['kategori']) ? $d['kategori'] : '-';
                                    $cakupanBadge = 'bg-orange-50 text-orange-700';
                                    $cakupanTag   = 'Kategori';
                                } else {
                                    $cakupanLabel = 'Total Transaksi';
                                    $cakupanBadge = 'bg-blue-50 text-blue-700';
                                    $cakupanTag   = 'Transaksi';
                                }
                                $nilaiLabel = $d['jenis'] === 'persen' ? ((int)$d['nilai']) . '%' : rupiah_diskon($d['nilai']);
                            ?>
                                <tr class="group">
                                    <td class="px-5 py-4 text-[11px] text-gray-300 font-medium"><?php echo $i + 1; ?></td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-sm leading-tight"><?php echo h($d['nama']); ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono mt-0.5">ID #<?php echo (int)$d['id']; ?> &middot; Promo Umum</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-1 <?php echo $cakupanBadge; ?>">
                                            <?php echo $cakupanTag; ?>
                                        </span>
                                        <div class="text-[10px] text-gray-400 mt-1 max-w-[160px] truncate"><?php echo h($cakupanLabel); ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="text-sm font-black text-blue-600"><?php echo h($nilaiLabel); ?></span>
                                        <div class="text-[9px] text-gray-400 mt-0.5 uppercase"><?php echo $d['jenis'] === 'persen' ? 'Persen' : 'Nominal'; ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-right text-xs text-gray-500"><?php echo rupiah_diskon($d['minimal_belanja']); ?></td>
                                    <td class="px-5 py-4 text-xs text-gray-500">
                                        <div><?php echo tanggal_diskon($d['tanggal_mulai']); ?></div>
                                        <div class="text-gray-300">&darr;</div>
                                        <div><?php echo tanggal_diskon($d['tanggal_selesai']); ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($d['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="editDiskon(<?php echo (int)$d['id']; ?>)"
                                                class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button onclick="toggleDiskon(<?php echo (int)$d['id']; ?>, '<?php echo $d['status']; ?>')"
                                                class="p-2 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 transition-all"
                                                title="<?php echo $d['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?>">
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
                                            <button onclick="hapusDiskon(<?php echo (int)$d['id']; ?>, '<?php echo h(addslashes($d['nama'])); ?>')"
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
                            $cakupan = isset($d['cakupan']) ? $d['cakupan'] : 'transaksi';
                            if ($cakupan === 'produk') {
                                $cakupanLabel = (isset($d['produk_kode']) && $d['produk_kode']) ? $d['produk_kode'] . ' - ' . $d['produk_nama'] : '-';
                                $cakupanBadge = 'bg-purple-50 text-purple-700';
                                $cakupanTag = 'Produk';
                            } elseif ($cakupan === 'kategori') {
                                $cakupanLabel = isset($d['kategori']) ? $d['kategori'] : '-';
                                $cakupanBadge = 'bg-orange-50 text-orange-700';
                                $cakupanTag = 'Kategori';
                            } else {
                                $cakupanLabel = 'Total Transaksi';
                                $cakupanBadge = 'bg-blue-50 text-blue-700';
                                $cakupanTag = 'Transaksi';
                            }
                            $nilaiLabel = $d['jenis'] === 'persen' ? ((int)$d['nilai']) . '%' : rupiah_diskon($d['nilai']);
                        ?>
                            <div class="diskon-mobile-card bg-white border border-subtle p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[10px] text-gray-300 font-bold">#<?php echo $i + 1; ?></span>
                                            <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 <?php echo $cakupanBadge; ?>">
                                                <?php echo $cakupanTag; ?>
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-sm leading-tight text-gray-900 truncate"><?php echo h($d['nama']); ?></h3>
                                        <p class="text-[10px] text-gray-400 font-mono mt-1 truncate">ID #<?php echo (int)$d['id']; ?> &middot; <?php echo h($cakupanLabel); ?></p>
                                    </div>
                                    <div class="shrink-0">
                                        <?php if ($d['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Diskon</p>
                                        <p class="text-lg font-black text-blue-600"><?php echo h($nilaiLabel); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1"><?php echo $d['jenis'] === 'persen' ? 'Persentase' : 'Nominal Rp'; ?></p>
                                    </div>
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Min. Belanja</p>
                                        <p class="text-sm font-bold text-gray-700"><?php echo rupiah_diskon($d['minimal_belanja']); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1">
                                            <?php echo tanggal_diskon($d['tanggal_mulai']); ?> &ndash; <?php echo tanggal_diskon($d['tanggal_selesai']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 pt-3 border-t border-subtle">
                                    <button onclick="editDiskon(<?php echo (int)$d['id']; ?>)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-blue-100 text-blue-700 hover:bg-blue-50 transition-all">
                                        Edit
                                    </button>
                                    <button onclick="toggleDiskon(<?php echo (int)$d['id']; ?>, '<?php echo $d['status']; ?>')"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-yellow-100 text-yellow-700 hover:bg-yellow-50 transition-all">
                                        <?php echo $d['status'] === 'aktif' ? 'Nonaktif' : 'Aktifkan'; ?>
                                    </button>
                                    <button onclick="hapusDiskon(<?php echo (int)$d['id']; ?>, '<?php echo h(addslashes($d['nama'])); ?>')"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-red-100 text-red-700 hover:bg-red-50 transition-all">
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

    <!-- ── MODAL: Tambah / Edit Diskon ─────────────────────────────────────── -->
    <div id="modal-diskon" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-lg shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest" id="modal-title">Tambah Diskon</h2>
                    <p class="text-[10px] text-gray-400 mt-0.5" id="modal-subtitle">Isi form di bawah dengan benar</p>
                </div>
                <button onclick="closeModal()" class="p-2 hover:bg-gray-100 transition-colors">
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
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Cakupan *</label>
                        <select id="form-cakupan" onchange="toggleCakupanForm()" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                            <option value="transaksi">Total Transaksi</option>
                            <option value="produk">Produk Tertentu</option>
                            <option value="kategori">Kategori Produk</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Target</label>
                        <div class="w-full bg-gray-100 border border-gray-200 px-3 py-2.5 text-sm text-gray-500 font-medium">Semua Pelanggan</div>
                        <p class="text-[9px] text-gray-400 mt-1">Member hanya mendapat point</p>
                    </div>
                </div>
                <div id="produk-wrap" class="hidden">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Pilih Produk *</label>
                    <select id="form-produk-id" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                        <option value="">Pilih produk...</option>
                        <?php foreach ($produkList as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['kode'] . ' - ' . $p['nama']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="kategori-wrap" class="hidden">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Pilih Kategori *</label>
                    <select id="form-kategori" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                        <option value="">Pilih kategori...</option>
                        <?php foreach ($kategoriList as $kat): ?>
                            <option value="<?php echo h($kat); ?>"><?php echo h($kat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Jenis Diskon *</label>
                        <select id="form-jenis" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                            <option value="persen">Persen (%)</option>
                            <option value="nominal">Nominal (Rp)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Nilai *</label>
                        <input type="number" id="form-nilai" placeholder="10 atau 5000" min="1"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Minimal Belanja</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                        <input type="number" id="form-minimal" placeholder="0" min="0"
                            class="w-full bg-gray-50 border border-gray-200 pl-9 pr-3 py-2.5 text-sm transition-all">
                    </div>
                    <p class="text-[9px] text-gray-400 mt-1">Isi 0 jika tidak ada syarat minimal belanja</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Tanggal Mulai</label>
                        <input type="date" id="form-mulai" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Tanggal Selesai</label>
                        <input type="date" id="form-selesai" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
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
                <button onclick="simpanDiskon()" id="btn-simpan"
                    class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white hover:bg-gray-800 transition-all">Simpan</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 shadow-2xl pointer-events-none">
        <span id="toast-icon"></span>
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

    <script>
        var DISKON_DATA = <?php echo json_encode(array_column($diskonList, null, 'id')); ?>;

        // ── Live Filter ──────────────────────────────────────────────────────────────
        var searchTimer;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('filter-cakupan').addEventListener('change', applyFilter);
        document.getElementById('filter-status').addEventListener('change', applyFilter);

        function applyFilter() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('search-input').value);
            url.searchParams.set('cakupan', document.getElementById('filter-cakupan').value);
            url.searchParams.set('status', document.getElementById('filter-status').value);
            window.location.href = url.toString();
        }

        // ── Cakupan Toggle ───────────────────────────────────────────────────────────
        function toggleCakupanForm() {
            var c = document.getElementById('form-cakupan').value;
            document.getElementById('produk-wrap').classList.toggle('hidden', c !== 'produk');
            document.getElementById('kategori-wrap').classList.toggle('hidden', c !== 'kategori');
        }

        // ── Modal ────────────────────────────────────────────────────────────────────
        function openModal(mode) {
            mode = mode || 'tambah';
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
            var d = DISKON_DATA[id];
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
            document.querySelector('input[name="form-status"][value="' + d.status + '"]').checked = true;
            toggleCakupanForm();
            if (d.cakupan === 'produk' && d.produk_id) document.getElementById('form-produk-id').value = d.produk_id;
            if (d.cakupan === 'kategori' && d.kategori) document.getElementById('form-kategori').value = d.kategori;
            openModal('edit');
        }

        async function simpanDiskon() {
            var id = document.getElementById('form-id').value;
            var btn = document.getElementById('btn-simpan');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            var cakupan = document.getElementById('form-cakupan').value;
            var formData = new FormData();
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
                var res = await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                var text = await res.text();
                if (text.includes('Diskon berhasil')) {
                    showToast(id ? 'Diskon berhasil diperbarui.' : 'Diskon berhasil ditambahkan.', 'success');
                    closeModal();
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(text, 'text/html');
                    var errEl = doc.getElementById('alert-error');
                    showToast(errEl ? errEl.innerText.replace('×', '').trim() : 'Terjadi kesalahan.', 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan koneksi.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Simpan';
            }
        }

        async function toggleDiskon(id, statusSekarang) {
            var formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('id', id);
            try {
                await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                showToast(statusSekarang === 'aktif' ? 'Diskon dinonaktifkan.' : 'Diskon diaktifkan.', 'success');
                setTimeout(function() {
                    location.reload();
                }, 800);
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        async function hapusDiskon(id, nama) {
            if (!confirm('Hapus diskon "' + nama + '"?\n\nTindakan ini tidak dapat dibatalkan.')) return;
            var formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            try {
                await fetch('diskon.php', {
                    method: 'POST',
                    body: formData
                });
                showToast('Diskon berhasil dihapus.', 'success');
                setTimeout(function() {
                    location.reload();
                }, 800);
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

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

        document.getElementById('modal-diskon').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>

</html>