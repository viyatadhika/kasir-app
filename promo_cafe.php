<?php
session_start();

require_once 'config.php';
requireAccess();

if (file_exists(__DIR__ . '/activity_helper.php')) {
    require_once __DIR__ . '/activity_helper.php';
}

$activeMenu = 'promo_cafe';
$pageTitle = 'Promo & Diskon Cafe';
$backUrl = 'dashboard.php';

if (!function_exists('pc_h')) {
    function pc_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pc_columns')) {
    function pc_columns(PDO $pdo, $table)
    {
        try {
            return $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', (string)$table) . "`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return array();
        }
    }
}

if (!function_exists('pc_ensure_schema')) {
    function pc_ensure_schema(PDO $pdo)
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cafe_promo (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(150) NOT NULL,
                target ENUM('semua','member') NOT NULL DEFAULT 'semua',
                cakupan ENUM('transaksi','menu','kategori') NOT NULL DEFAULT 'transaksi',
                produk_id INT NULL,
                kategori VARCHAR(100) NULL,
                jenis ENUM('persen','nominal') NOT NULL DEFAULT 'persen',
                nilai DECIMAL(15,2) NOT NULL DEFAULT 0,
                minimal_belanja DECIMAL(15,2) NOT NULL DEFAULT 0,
                maksimal_diskon DECIMAL(15,2) NULL,
                tanggal_mulai DATE NULL,
                tanggal_selesai DATE NULL,
                mode_penerapan ENUM('otomatis','manual') NOT NULL DEFAULT 'otomatis',
                boleh_pakai_point TINYINT(1) NOT NULL DEFAULT 1,
                prioritas INT NOT NULL DEFAULT 100,
                status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
                catatan VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_cafe_promo_status (status),
                INDEX idx_cafe_promo_period (tanggal_mulai, tanggal_selesai),
                INDEX idx_cafe_promo_target (target),
                INDEX idx_cafe_promo_scope (cakupan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $cols = pc_columns($pdo, 'cafe_promo');
        $alter = array(
            'maksimal_diskon' => "ADD COLUMN maksimal_diskon DECIMAL(15,2) NULL AFTER minimal_belanja",
            'mode_penerapan' => "ADD COLUMN mode_penerapan ENUM('otomatis','manual') NOT NULL DEFAULT 'otomatis' AFTER tanggal_selesai",
            'boleh_pakai_point' => "ADD COLUMN boleh_pakai_point TINYINT(1) NOT NULL DEFAULT 1 AFTER mode_penerapan",
            'prioritas' => "ADD COLUMN prioritas INT NOT NULL DEFAULT 100 AFTER boleh_pakai_point",
            'catatan' => "ADD COLUMN catatan VARCHAR(255) NULL AFTER status",
            'updated_at' => "ADD COLUMN updated_at DATETIME NULL AFTER created_at",
        );

        foreach ($alter as $column => $sql) {
            if (!in_array($column, $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE cafe_promo " . $sql);
                } catch (Throwable $e) {
                    // Halaman tetap bisa dibuka jika ALTER tidak diizinkan.
                }
            }
        }
    }
}

pc_ensure_schema($pdo);

$flash = '';
$flashType = 'success';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama'] ?? ''));
            $target = trim((string)($_POST['target'] ?? 'semua'));
            $cakupan = trim((string)($_POST['cakupan'] ?? 'transaksi'));
            $produkId = (int)($_POST['produk_id'] ?? 0);
            $kategori = trim((string)($_POST['kategori'] ?? ''));
            $jenis = trim((string)($_POST['jenis'] ?? 'persen'));
            $nilai = (float)($_POST['nilai'] ?? 0);
            $minimal = max(0, (float)($_POST['minimal_belanja'] ?? 0));
            $maksimalRaw = trim((string)($_POST['maksimal_diskon'] ?? ''));
            $maksimal = $maksimalRaw !== '' ? max(0, (float)$maksimalRaw) : null;
            $mulai = trim((string)($_POST['tanggal_mulai'] ?? ''));
            $selesai = trim((string)($_POST['tanggal_selesai'] ?? ''));
            $mode = trim((string)($_POST['mode_penerapan'] ?? 'otomatis'));
            $bolehPoint = !empty($_POST['boleh_pakai_point']) ? 1 : 0;
            $prioritas = max(1, (int)($_POST['prioritas'] ?? 100));
            $status = trim((string)($_POST['status'] ?? 'aktif'));
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            $mulai = $mulai !== '' ? $mulai : null;
            $selesai = $selesai !== '' ? $selesai : null;
            $produkId = $produkId > 0 ? $produkId : null;
            $kategori = $kategori !== '' ? $kategori : null;
            $catatan = $catatan !== '' ? $catatan : null;

            if ($nama === '') {
                throw new RuntimeException('Nama promo wajib diisi.');
            }
            if (!in_array($target, array('semua', 'member'), true)) {
                throw new RuntimeException('Target promo tidak valid.');
            }
            if (!in_array($cakupan, array('transaksi', 'menu', 'kategori'), true)) {
                throw new RuntimeException('Cakupan promo tidak valid.');
            }
            if (!in_array($jenis, array('persen', 'nominal'), true)) {
                throw new RuntimeException('Jenis diskon tidak valid.');
            }
            if ($nilai <= 0) {
                throw new RuntimeException('Nilai diskon harus lebih dari 0.');
            }
            if ($jenis === 'persen' && $nilai > 100) {
                throw new RuntimeException('Diskon persen maksimal 100%.');
            }
            if ($mulai && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
                throw new RuntimeException('Tanggal mulai tidak valid.');
            }
            if ($selesai && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
                throw new RuntimeException('Tanggal selesai tidak valid.');
            }
            if ($mulai && $selesai && $selesai < $mulai) {
                throw new RuntimeException('Tanggal selesai tidak boleh lebih awal dari tanggal mulai.');
            }
            if (!in_array($mode, array('otomatis', 'manual'), true)) {
                throw new RuntimeException('Mode penerapan tidak valid.');
            }
            if (!in_array($status, array('aktif', 'nonaktif'), true)) {
                throw new RuntimeException('Status tidak valid.');
            }
            if ($cakupan === 'menu' && !$produkId) {
                throw new RuntimeException('Pilih menu untuk promo per menu.');
            }
            if ($cakupan === 'kategori' && !$kategori) {
                throw new RuntimeException('Pilih kategori untuk promo per kategori.');
            }

            if ($cakupan !== 'menu') {
                $produkId = null;
            }
            if ($cakupan !== 'kategori') {
                $kategori = null;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE cafe_promo
                    SET nama = :nama,
                        target = :target,
                        cakupan = :cakupan,
                        produk_id = :produk_id,
                        kategori = :kategori,
                        jenis = :jenis,
                        nilai = :nilai,
                        minimal_belanja = :minimal_belanja,
                        maksimal_diskon = :maksimal_diskon,
                        tanggal_mulai = :tanggal_mulai,
                        tanggal_selesai = :tanggal_selesai,
                        mode_penerapan = :mode_penerapan,
                        boleh_pakai_point = :boleh_pakai_point,
                        prioritas = :prioritas,
                        status = :status,
                        catatan = :catatan,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute(array(
                    ':id' => $id,
                    ':nama' => $nama,
                    ':target' => $target,
                    ':cakupan' => $cakupan,
                    ':produk_id' => $produkId,
                    ':kategori' => $kategori,
                    ':jenis' => $jenis,
                    ':nilai' => $nilai,
                    ':minimal_belanja' => $minimal,
                    ':maksimal_diskon' => $maksimal,
                    ':tanggal_mulai' => $mulai,
                    ':tanggal_selesai' => $selesai,
                    ':mode_penerapan' => $mode,
                    ':boleh_pakai_point' => $bolehPoint,
                    ':prioritas' => $prioritas,
                    ':status' => $status,
                    ':catatan' => $catatan,
                ));
                $flash = 'Promo cafe berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cafe_promo
                    (
                        nama, target, cakupan, produk_id, kategori, jenis, nilai,
                        minimal_belanja, maksimal_diskon, tanggal_mulai, tanggal_selesai,
                        mode_penerapan, boleh_pakai_point, prioritas, status, catatan
                    )
                    VALUES
                    (
                        :nama, :target, :cakupan, :produk_id, :kategori, :jenis, :nilai,
                        :minimal_belanja, :maksimal_diskon, :tanggal_mulai, :tanggal_selesai,
                        :mode_penerapan, :boleh_pakai_point, :prioritas, :status, :catatan
                    )
                ");
                $stmt->execute(array(
                    ':nama' => $nama,
                    ':target' => $target,
                    ':cakupan' => $cakupan,
                    ':produk_id' => $produkId,
                    ':kategori' => $kategori,
                    ':jenis' => $jenis,
                    ':nilai' => $nilai,
                    ':minimal_belanja' => $minimal,
                    ':maksimal_diskon' => $maksimal,
                    ':tanggal_mulai' => $mulai,
                    ':tanggal_selesai' => $selesai,
                    ':mode_penerapan' => $mode,
                    ':boleh_pakai_point' => $bolehPoint,
                    ':prioritas' => $prioritas,
                    ':status' => $status,
                    ':catatan' => $catatan,
                ));
                $flash = 'Promo cafe berhasil ditambahkan.';
            }

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, $id > 0 ? 'update' : 'create', 'Promo Cafe', $flash . ' ' . $nama);
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Promo tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE cafe_promo
                SET status = CASE WHEN status = 'aktif' THEN 'nonaktif' ELSE 'aktif' END,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(array(':id' => $id));
            $flash = 'Status promo berhasil diubah.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Promo tidak valid.');
            }

            $stmt = $pdo->prepare("DELETE FROM cafe_promo WHERE id = :id LIMIT 1");
            $stmt->execute(array(':id' => $id));
            $flash = 'Promo cafe berhasil dihapus.';
        }
    }
} catch (Throwable $e) {
    $flash = $e->getMessage();
    $flashType = 'error';
}

/*
 * Daftar menu mengikuti sumber yang sudah digunakan POS Cafe:
 * produk aktif, tetapi hanya menu yang berkategori Cafe jika penamaan tersebut tersedia.
 * Agar halaman tetap kompatibel dengan database lama, semua produk aktif tetap bisa dipilih.
 */
$menuRows = array();
$kategoriRows = array();

try {
    $menuRows = $pdo->query("
        SELECT id, kode, nama, kategori, harga_jual
        FROM produk
        WHERE status = 'aktif'
        ORDER BY kategori ASC, nama ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($menuRows as $m) {
        $kat = trim((string)($m['kategori'] ?? ''));
        if ($kat !== '') {
            $kategoriRows[$kat] = $kat;
        }
    }
    ksort($kategoriRows);
} catch (Throwable $e) {
    $menuRows = array();
    $kategoriRows = array();
}

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterTarget = trim((string)($_GET['target'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$where = array('1=1');
$params = array();

if (in_array($filterStatus, array('aktif', 'nonaktif'), true)) {
    $where[] = 'cp.status = :status';
    $params[':status'] = $filterStatus;
}
if (in_array($filterTarget, array('semua', 'member'), true)) {
    $where[] = 'cp.target = :target';
    $params[':target'] = $filterTarget;
}
if ($search !== '') {
    $where[] = '(cp.nama LIKE :search OR cp.kategori LIKE :search OR cp.catatan LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

$stmtList = $pdo->prepare("
    SELECT
        cp.*,
        p.nama AS nama_menu,
        p.kode AS kode_menu
    FROM cafe_promo cp
    LEFT JOIN produk p ON p.id = cp.produk_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE WHEN cp.status = 'aktif' THEN 0 ELSE 1 END,
        cp.prioritas ASC,
        cp.id DESC
");
$stmtList->execute($params);
$rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$stats = array(
    'total' => 0,
    'aktif' => 0,
    'berjalan' => 0,
    'member' => 0,
);

try {
    $allPromo = $pdo->query("SELECT target,status,tanggal_mulai,tanggal_selesai FROM cafe_promo")->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($allPromo);

    foreach ($allPromo as $p) {
        if ((string)$p['status'] === 'aktif') {
            $stats['aktif']++;
        }
        if ((string)$p['target'] === 'member') {
            $stats['member']++;
        }

        $periodOk =
            ((empty($p['tanggal_mulai']) || $p['tanggal_mulai'] <= $today) &&
                (empty($p['tanggal_selesai']) || $p['tanggal_selesai'] >= $today));

        if ((string)$p['status'] === 'aktif' && $periodOk) {
            $stats['berjalan']++;
        }
    }
} catch (Throwable $e) {
}

function pc_period_label($row)
{
    $start = !empty($row['tanggal_mulai']) ? date('d/m/Y', strtotime((string)$row['tanggal_mulai'])) : 'Tanpa batas';
    $end = !empty($row['tanggal_selesai']) ? date('d/m/Y', strtotime((string)$row['tanggal_selesai'])) : 'Tanpa batas';
    return $start . ' - ' . $end;
}

function pc_discount_label($row)
{
    if ((string)$row['jenis'] === 'persen') {
        return rtrim(rtrim(number_format((float)$row['nilai'], 2, ',', '.'), '0'), ',') . '%';
    }
    return 'Rp ' . number_format((float)$row['nilai'], 0, ',', '.');
}


$activeMenu = 'promo_cafe';
$pageTitle = 'Promo & Diskon Cafe';
$backUrl = 'dashboard.php';
$rightActionHtml = '<button type="button" onclick="openCreateModal()" class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest bg-black text-white hover:bg-gray-800 transition-all"><svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-width="2.5" d="M12 4v16m8-8H4" /></svg><span class="hidden sm:inline">Tambah Promo</span><span class="sm:hidden">+</span></button>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Promo & Diskon Cafe</title>
    <?php if (file_exists(__DIR__ . '/header.php')) include 'header.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #fcfcfc;
            color: #1a1a1a
        }

        .border-subtle {
            border-color: #f0f0f0
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none
        }

        tbody tr {
            transition: background .15s
        }

        tbody tr:hover {
            background: #f9f9f9
        }

        .field {
            width: 100%;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: .625rem .75rem;
            font-size: .875rem;
            outline: none;
            border-radius: 0 !important
        }

        .field:focus,
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important;
            background: #fff
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            min-height: 36px;
            padding: .5rem .75rem;
            border: 1px solid #f0f0f0;
            font-size: .625rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-radius: 0 !important;
            transition: all .15s
        }

        .card,
        .summary-card,
        .filter-card,
        .table-card,
        .promo-mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: .25rem .5rem;
            font-size: .56rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important
        }

        .modal-bg {
            background: rgba(0, 0, 0, .4);
            backdrop-filter: blur(4px)
        }

        button,
        input,
        select,
        textarea {
            border-radius: 0 !important
        }

        @media(min-width:1024px) {
            .page-wrap {
                margin-left: 220px
            }

            .desktop-table {
                display: block
            }

            .mobile-cards {
                display: none
            }
        }

        @media(max-width:1023px) {
            body {
                padding-bottom: 76px
            }

            .page-wrap {
                padding-bottom: 5.5rem !important
            }

            .desktop-table {
                display: none
            }

            .mobile-cards {
                display: block
            }
        }

        @media(min-width:641px) and (max-width:1023px) {
            .page-wrap {
                padding-left: 1.25rem !important;
                padding-right: 1.25rem !important
            }

            .mobile-cards {
                padding: 1rem !important
            }

            .mobile-cards>div,
            .mobile-cards>article {
                min-height: 100%
            }
        }

        @media(max-width:640px) {
            .page-wrap {
                padding-left: .75rem !important;
                padding-right: .75rem !important
            }

            .filter-card select,
            .filter-card input,
            .filter-card button,
            .filter-card a {
                width: 100%
            }
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">
    <?php
    if (file_exists(__DIR__ . '/sidebar.php')) require_once 'sidebar.php';
    if (file_exists(__DIR__ . '/navbar.php')) require_once 'navbar.php';
    ?>

    <main class="page-wrap p-4 sm:p-5 md:p-8 lg:p-10">
        <div>
            <?php if ($flash !== ''): ?>
                <div class="mb-4 px-4 py-3 border text-xs font-bold <?php echo $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                    <?php echo pc_h($flash); ?>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                <div class="summary-card p-4 md:p-5">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Promo</p>
                    <p class="text-2xl font-black mt-2"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="summary-card p-4 md:p-5">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Status Aktif</p>
                    <p class="text-2xl font-black mt-2"><?php echo number_format($stats['aktif']); ?></p>
                </div>
                <div class="summary-card p-4 md:p-5">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Berlaku Hari Ini</p>
                    <p class="text-2xl font-black mt-2"><?php echo number_format($stats['berjalan']); ?></p>
                </div>
                <div class="summary-card p-4 md:p-5">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Khusus Member</p>
                    <p class="text-2xl font-black mt-2"><?php echo number_format($stats['member']); ?></p>
                </div>
            </section>

            <section class="filter-card p-4 mb-4">
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1fr_170px_170px_auto] gap-2">
                    <input type="search" name="q" value="<?php echo pc_h($search); ?>" placeholder="Cari nama promo..." class="field">
                    <select name="target" class="field">
                        <option value="">Semua Target</option>
                        <option value="semua" <?php echo $filterTarget === 'semua' ? 'selected' : ''; ?>>Semua Pelanggan</option>
                        <option value="member" <?php echo $filterTarget === 'member' ? 'selected' : ''; ?>>Khusus Member</option>
                    </select>
                    <select name="status" class="field">
                        <option value="">Semua Status</option>
                        <option value="aktif" <?php echo $filterStatus === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="nonaktif" <?php echo $filterStatus === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                    </select>
                    <div class="flex gap-2">
                        <button class="btn bg-black text-white border-black flex-1">Terapkan</button>
                        <a href="promo_cafe.php" class="btn bg-white flex-1">Reset</a>
                    </div>
                </form>
            </section>

            <section class="table-card overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-black">Daftar Promo</h2>
                        <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format(count($rows)); ?> data ditampilkan</p>
                    </div>
                    <span class="badge bg-gray-50 text-gray-600">1 Promo / Transaksi</span>
                </div>

                <div class="desktop-table overflow-x-auto">
                    <table class="w-full min-w-[1050px]">
                        <thead class="bg-gray-50 border-b border-gray-100">
                            <tr class="text-left text-[9px] font-black uppercase tracking-widest text-gray-400">
                                <th class="px-4 py-3">Promo</th>
                                <th class="px-4 py-3">Target</th>
                                <th class="px-4 py-3">Cakupan</th>
                                <th class="px-4 py-3">Diskon</th>
                                <th class="px-4 py-3">Periode</th>
                                <th class="px-4 py-3">Penerapan</th>
                                <th class="px-4 py-3">Point</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="9" class="px-5 py-14 text-center">
                                        <p class="text-xs font-black text-gray-400 uppercase tracking-widest">Belum ada promo cafe</p>
                                        <p class="text-[10px] text-gray-400 mt-2">Klik Tambah Promo untuk membuat promo pertama.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($rows as $row): ?>
                                <?php
                                $scopeLabel = 'Seluruh transaksi';
                                if ($row['cakupan'] === 'menu') $scopeLabel = $row['nama_menu'] ?: 'Menu #' . (int)$row['produk_id'];
                                if ($row['cakupan'] === 'kategori') $scopeLabel = 'Kategori: ' . ($row['kategori'] ?: '-');
                                ?>
                                <tr class="text-xs">
                                    <td class="px-4 py-4">
                                        <p class="font-black"><?php echo pc_h($row['nama']); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1">Prioritas <?php echo (int)$row['prioritas']; ?><?php echo $row['catatan'] ? ' · ' . pc_h($row['catatan']) : ''; ?></p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="badge <?php echo $row['target'] === 'member' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-gray-50 text-gray-600'; ?>">
                                            <?php echo $row['target'] === 'member' ? 'Member' : 'Semua'; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 font-bold text-gray-600"><?php echo pc_h($scopeLabel); ?></td>
                                    <td class="px-4 py-4">
                                        <p class="font-black text-base"><?php echo pc_h(pc_discount_label($row)); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1">
                                            Min. Rp <?php echo number_format((float)$row['minimal_belanja'], 0, ',', '.'); ?>
                                            <?php if ($row['maksimal_diskon'] !== null): ?>
                                                · Maks. Rp <?php echo number_format((float)$row['maksimal_diskon'], 0, ',', '.'); ?>
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                    <td class="px-4 py-4 text-[10px] font-bold text-gray-500"><?php echo pc_h(pc_period_label($row)); ?></td>
                                    <td class="px-4 py-4">
                                        <span class="badge bg-gray-50 text-gray-600"><?php echo pc_h($row['mode_penerapan']); ?></span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="badge <?php echo (int)$row['boleh_pakai_point'] === 1 ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                                            <?php echo (int)$row['boleh_pakai_point'] === 1 ? 'Boleh' : 'Tidak'; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="badge <?php echo $row['status'] === 'aktif' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500'; ?>">
                                            <?php echo pc_h($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex justify-end gap-2">
                                            <button type="button" onclick='editPromo(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn px-3">Edit</button>
                                            <form method="post">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn px-3"><?php echo $row['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Hapus promo ini?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn px-3 border-red-200 text-red-600">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-cards p-3 md:p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php if (!$rows): ?>
                            <div class="p-8 text-center">
                                <p class="text-xs font-black text-gray-400 uppercase">Belum ada promo cafe</p>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                            <?php
                            $scopeLabel = 'Seluruh transaksi';
                            if ($row['cakupan'] === 'menu') $scopeLabel = $row['nama_menu'] ?: 'Menu #' . (int)$row['produk_id'];
                            if ($row['cakupan'] === 'kategori') $scopeLabel = 'Kategori: ' . ($row['kategori'] ?: '-');
                            ?>
                            <article class="promo-mobile-card p-4 bg-white">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-black"><?php echo pc_h($row['nama']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-1"><?php echo pc_h($scopeLabel); ?></p>
                                    </div>
                                    <span class="badge <?php echo $row['status'] === 'aktif' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500'; ?>">
                                        <?php echo pc_h($row['status']); ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-2 mt-4">
                                    <div class="bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase text-gray-400">Diskon</p>
                                        <p class="text-sm font-black mt-1"><?php echo pc_h(pc_discount_label($row)); ?></p>
                                    </div>
                                    <div class="bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase text-gray-400">Target</p>
                                        <p class="text-sm font-black mt-1"><?php echo $row['target'] === 'member' ? 'Member' : 'Semua'; ?></p>
                                    </div>
                                </div>

                                <p class="text-[10px] text-gray-500 mt-3"><?php echo pc_h(pc_period_label($row)); ?></p>

                                <div class="grid grid-cols-3 gap-2 mt-4">
                                    <button type="button" onclick='editPromo(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn">Edit</button>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button class="btn w-full"><?php echo $row['status'] === 'aktif' ? 'Off' : 'On'; ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Hapus promo ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button class="btn w-full border-red-200 text-red-600">Hapus</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="mt-4 border border-blue-100 bg-blue-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-blue-700">Contoh Pengaturan</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3 text-xs">
                    <div class="bg-white/80 border border-blue-100 p-3">
                        <strong>Diskon Member 5%</strong>
                        <p class="text-gray-500 mt-1">Target: Member · Cakupan: Transaksi · Jenis: Persen · Nilai: 5 · Periode: tanpa batas.</p>
                    </div>
                    <div class="bg-white/80 border border-blue-100 p-3">
                        <strong>Promo Kemerdekaan 17%</strong>
                        <p class="text-gray-500 mt-1">Target: Semua · Cakupan: Transaksi · Nilai: 17% · Tanggal mulai dan selesai sesuai periode promo.</p>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <div id="promoModal" class="fixed inset-0 z-[300] hidden items-center justify-center p-3 sm:p-5 modal-bg">
        <div class="bg-white w-full max-w-3xl max-h-[92vh] overflow-hidden shadow-2xl flex flex-col">
            <div class="shrink-0 bg-white px-5 md:px-7 py-5 border-b border-subtle flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Promo Cafe</p>
                    <h3 id="modalTitle" class="text-lg font-black mt-1">Tambah Promo</h3>
                </div>
                <button type="button" onclick="closeModal()" class="p-2 hover:bg-gray-100 transition-colors text-xl">&times;</button>
            </div>

            <form method="post" id="promoForm" class="flex-1 min-h-0 flex flex-col">
                <div class="px-5 md:px-7 py-6 overflow-y-auto no-scrollbar">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="promo-id" value="0">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Nama Promo *</label>
                            <input name="nama" id="promo-nama" required class="field mt-2" placeholder="Contoh: Diskon Member 5%">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Target *</label>
                            <select name="target" id="promo-target" class="field mt-2">
                                <option value="semua">Semua Pelanggan</option>
                                <option value="member">Khusus Member</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Cakupan *</label>
                            <select name="cakupan" id="promo-cakupan" onchange="syncScopeFields()" class="field mt-2">
                                <option value="transaksi">Seluruh Transaksi</option>
                                <option value="menu">Menu Tertentu</option>
                                <option value="kategori">Kategori Menu</option>
                            </select>
                        </div>

                        <div id="menu-field" class="hidden md:col-span-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Pilih Menu *</label>
                            <select name="produk_id" id="promo-produk" class="field mt-2">
                                <option value="">Pilih menu</option>
                                <?php foreach ($menuRows as $menu): ?>
                                    <option value="<?php echo (int)$menu['id']; ?>">
                                        <?php echo pc_h(($menu['kode'] ?: '-') . ' - ' . $menu['nama'] . ' · Rp ' . number_format((float)$menu['harga_jual'], 0, ',', '.')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="kategori-field" class="hidden md:col-span-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Kategori *</label>
                            <select name="kategori" id="promo-kategori" class="field mt-2">
                                <option value="">Pilih kategori</option>
                                <?php foreach ($kategoriRows as $kat): ?>
                                    <option value="<?php echo pc_h($kat); ?>"><?php echo pc_h($kat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Jenis Diskon *</label>
                            <select name="jenis" id="promo-jenis" class="field mt-2">
                                <option value="persen">Persen (%)</option>
                                <option value="nominal">Nominal (Rp)</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Nilai Diskon *</label>
                            <input type="number" min="0" step="0.01" name="nilai" id="promo-nilai" required class="field mt-2" placeholder="5">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Minimal Belanja</label>
                            <input type="number" min="0" name="minimal_belanja" id="promo-minimal" class="field mt-2" value="0">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Maksimal Diskon</label>
                            <input type="number" min="0" name="maksimal_diskon" id="promo-maksimal" class="field mt-2" placeholder="Kosong = tanpa batas">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Tanggal Mulai</label>
                            <input type="date" name="tanggal_mulai" id="promo-mulai" class="field mt-2">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" id="promo-selesai" class="field mt-2">
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Penerapan</label>
                            <select name="mode_penerapan" id="promo-mode" class="field mt-2">
                                <option value="otomatis">Otomatis</option>
                                <option value="manual">Dipilih Kasir</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Prioritas</label>
                            <input type="number" min="1" name="prioritas" id="promo-prioritas" class="field mt-2" value="100">
                            <p class="text-[9px] text-gray-400 mt-1">Angka lebih kecil = diprioritaskan lebih dulu.</p>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Status</label>
                            <select name="status" id="promo-status" class="field mt-2">
                                <option value="aktif">Aktif</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Point Member</label>
                            <label class="mt-2 min-h-[46px] border border-gray-200 flex items-center gap-3 px-4 cursor-pointer">
                                <input type="checkbox" name="boleh_pakai_point" id="promo-point" value="1" checked class="accent-black">
                                <span class="text-xs font-bold">Boleh digabung dengan penggunaan point</span>
                            </label>
                        </div>

                        <div class="md:col-span-2">
                            <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Catatan</label>
                            <textarea name="catatan" id="promo-catatan" rows="2" class="field mt-2" placeholder="Keterangan internal (opsional)"></textarea>
                        </div>
                    </div>

                    <div class="border border-amber-100 bg-amber-50 p-3 mt-5 text-[10px] text-amber-800 leading-5">
                        POS Cafe disarankan memakai satu promo terbaik/terpilih per transaksi. Penggunaan point mengikuti pengaturan "Boleh digabung dengan point".
                    </div>

                </div>
                <div class="shrink-0 px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 grid grid-cols-2 gap-3">
                    <button type="button" onclick="closeModal()" class="btn bg-white">Batal</button>
                    <button type="submit" class="btn bg-black text-white border-black">Simpan Promo</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('promoForm').reset();
            document.getElementById('promo-id').value = '0';
            document.getElementById('promo-target').value = 'semua';
            document.getElementById('promo-cakupan').value = 'transaksi';
            document.getElementById('promo-jenis').value = 'persen';
            document.getElementById('promo-mode').value = 'otomatis';
            document.getElementById('promo-prioritas').value = '100';
            document.getElementById('promo-status').value = 'aktif';
            document.getElementById('promo-point').checked = true;
            document.getElementById('modalTitle').textContent = 'Tambah Promo';
            syncScopeFields();
            openModal();
        }

        function editPromo(row) {
            document.getElementById('promo-id').value = Number(row.id || 0);
            document.getElementById('promo-nama').value = row.nama || '';
            document.getElementById('promo-target').value = row.target || 'semua';
            document.getElementById('promo-cakupan').value = row.cakupan || 'transaksi';
            document.getElementById('promo-produk').value = row.produk_id || '';
            document.getElementById('promo-kategori').value = row.kategori || '';
            document.getElementById('promo-jenis').value = row.jenis || 'persen';
            document.getElementById('promo-nilai').value = row.nilai || '';
            document.getElementById('promo-minimal').value = row.minimal_belanja || 0;
            document.getElementById('promo-maksimal').value = row.maksimal_diskon === null ? '' : row.maksimal_diskon;
            document.getElementById('promo-mulai').value = row.tanggal_mulai || '';
            document.getElementById('promo-selesai').value = row.tanggal_selesai || '';
            document.getElementById('promo-mode').value = row.mode_penerapan || 'otomatis';
            document.getElementById('promo-prioritas').value = row.prioritas || 100;
            document.getElementById('promo-status').value = row.status || 'aktif';
            document.getElementById('promo-point').checked = Number(row.boleh_pakai_point || 0) === 1;
            document.getElementById('promo-catatan').value = row.catatan || '';
            document.getElementById('modalTitle').textContent = 'Edit Promo';
            syncScopeFields();
            openModal();
        }

        function syncScopeFields() {
            const scope = document.getElementById('promo-cakupan').value;
            document.getElementById('menu-field').classList.toggle('hidden', scope !== 'menu');
            document.getElementById('kategori-field').classList.toggle('hidden', scope !== 'kategori');
        }

        function openModal() {
            const modal = document.getElementById('promoModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            setTimeout(function() {
                document.getElementById('promo-nama').focus();
            }, 80);
        }

        function closeModal() {
            const modal = document.getElementById('promoModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        document.getElementById('promoModal').addEventListener('click', function(event) {
            if (event.target === this) closeModal();
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>

</html>