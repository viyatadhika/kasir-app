<?php
session_start();
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'stok';
$pageTitle = 'Stok Opname';
$backUrl = 'dashboard.php';


/*
|--------------------------------------------------------------------------
| stok_opname.php
|--------------------------------------------------------------------------
| Halaman stok opname sesuai struktur database kasir_app.
|
| Tabel utama yang dipakai:
| - produk:
|   id, kode, nama, kategori, harga_beli, harga_jual, stok,
|   stok_minimum, satuan, status, created_at, updated_at
|
| Tabel stok opname yang otomatis dibuat jika belum ada:
| - stok_opname
| - stok_opname_detail
*/

if (!isset($_SESSION['user_id']) && !isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('h')) {
    /**
     * @param mixed $v
     */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('angka')) {
    /**
     * @param mixed $v
     */
    function angka($v): string
    {
        return number_format((float)($v ?? 0), 0, ',', '.');
    }
}

if (!function_exists('rupiah')) {
    /**
     * @param mixed $v
     */
    function rupiah($v): string
    {
        return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
    }
}

if (!function_exists('waktu')) {
    /**
     * @param mixed $v
     */
    function waktu($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}


if (!function_exists('stok_build_url')) {
    /** @param array<string,mixed> $override */
    function stok_build_url(array $override = []): string
    {
        $params = $_GET;
        foreach ($override as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        $query = http_build_query($params);
        return 'stok_opname.php' . ($query ? '?' . $query : '');
    }
}

if (!function_exists('stok_limit_safe')) {
    /**
     * @param mixed $value
     * @param int $default
     * @return int
     */
    function stok_limit_safe($value, int $default = 25): int
    {
        $allowed = [10, 25, 50, 100, 200];
        $value = (int)$value;
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists('stok_page_safe')) {
    /**
     * @param mixed $value
     * @return int
     */
    function stok_page_safe($value): int
    {
        return max(1, (int)$value);
    }
}

if (!function_exists('stok_render_pagination')) {
    /** @param string $pageKey @param string $limitKey */
    function stok_render_pagination(int $page, int $totalPages, int $totalRows, int $limit, string $pageKey, string $limitKey): string
    {
        if ($totalPages <= 1 && $totalRows <= $limit) {
            return '';
        }

        $page = max(1, min($page, max(1, $totalPages)));
        $start = $totalRows > 0 ? (($page - 1) * $limit) + 1 : 0;
        $end = min($totalRows, $page * $limit);
        $html = '<div class="pagination-wrap px-4 py-3 border-t border-subtle bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">';
        $html .= '<p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Menampilkan ' . angka($start) . ' - ' . angka($end) . ' dari ' . angka($totalRows) . ' data</p>';
        $html .= '<div class="pagination-buttons flex items-center gap-2 overflow-x-auto no-scrollbar">';

        $prevDisabled = $page <= 1;
        $nextDisabled = $page >= $totalPages;
        $baseCls = 'inline-flex items-center justify-center min-w-[34px] h-9 px-3 border text-[10px] font-black uppercase tracking-widest';

        if ($prevDisabled) {
            $html .= '<span class="' . $baseCls . ' border-gray-100 text-gray-300 bg-gray-50">&lt;</span>';
        } else {
            $html .= '<a class="' . $baseCls . ' border-gray-200 text-gray-600 hover:bg-gray-50" href="' . h(stok_build_url([$pageKey => $page - 1])) . '">&lt;</a>';
        }

        $from = max(1, $page - 2);
        $to = min($totalPages, $page + 2);
        if ($from > 1) {
            $html .= '<a class="' . $baseCls . ' border-gray-200 text-gray-600 hover:bg-gray-50" href="' . h(stok_build_url([$pageKey => 1])) . '">1</a>';
            if ($from > 2) $html .= '<span class="' . $baseCls . ' border-transparent text-gray-300">...</span>';
        }
        for ($i = $from; $i <= $to; $i++) {
            if ($i === $page) {
                $html .= '<span class="' . $baseCls . ' border-black bg-black text-white">' . $i . '</span>';
            } else {
                $html .= '<a class="' . $baseCls . ' border-gray-200 text-gray-600 hover:bg-gray-50" href="' . h(stok_build_url([$pageKey => $i])) . '">' . $i . '</a>';
            }
        }
        if ($to < $totalPages) {
            if ($to < $totalPages - 1) $html .= '<span class="' . $baseCls . ' border-transparent text-gray-300">...</span>';
            $html .= '<a class="' . $baseCls . ' border-gray-200 text-gray-600 hover:bg-gray-50" href="' . h(stok_build_url([$pageKey => $totalPages])) . '">' . $totalPages . '</a>';
        }

        if ($nextDisabled) {
            $html .= '<span class="' . $baseCls . ' border-gray-100 text-gray-300 bg-gray-50">&gt;</span>';
        } else {
            $html .= '<a class="' . $baseCls . ' border-gray-200 text-gray-600 hover:bg-gray-50" href="' . h(stok_build_url([$pageKey => $page + 1])) . '">&gt;</a>';
        }

        $html .= '</div></div>';
        return $html;
    }
}

function ensure_stok_opname_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stok_opname (
            id INT(11) NOT NULL AUTO_INCREMENT,
            kode VARCHAR(80) NOT NULL,
            tanggal TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            total_item INT(11) NOT NULL DEFAULT 0,
            total_selisih INT(11) NOT NULL DEFAULT 0,
            catatan VARCHAR(255) DEFAULT NULL,
            user_id INT(11) DEFAULT NULL,
            user_nama VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY kode (kode),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stok_opname_detail (
            id INT(11) NOT NULL AUTO_INCREMENT,
            stok_opname_id INT(11) NOT NULL,
            produk_id INT(11) NOT NULL,
            kode_produk VARCHAR(80) NOT NULL,
            nama_produk VARCHAR(160) NOT NULL,
            kategori VARCHAR(100) DEFAULT '-',
            satuan VARCHAR(30) DEFAULT 'pcs',
            harga_beli INT(11) NOT NULL DEFAULT 0,
            harga_jual INT(11) NOT NULL DEFAULT 0,
            stok_sistem INT(11) NOT NULL DEFAULT 0,
            stok_fisik INT(11) NOT NULL DEFAULT 0,
            selisih INT(11) NOT NULL DEFAULT 0,
            catatan VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY stok_opname_id (stok_opname_id),
            KEY produk_id (produk_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

ensure_stok_opname_tables($pdo);

$error = '';
$success = '';

$q = trim($_GET['q'] ?? '');
$kategoriFilter = trim($_GET['kategori'] ?? '');
$statusFilter = $_GET['status'] ?? 'aktif';

// Pagination server-side
$produkPage  = stok_page_safe($_GET['produk_page'] ?? 1);
$produkLimit = stok_limit_safe($_GET['produk_limit'] ?? 25, 25);
$produkOffset = ($produkPage - 1) * $produkLimit;
$produkTotalRows = 0;
$produkTotalPages = 1;

$riwayatPage  = stok_page_safe($_GET['riwayat_page'] ?? 1);
$riwayatLimit = stok_limit_safe($_GET['riwayat_limit'] ?? 10, 10);
$riwayatOffset = ($riwayatPage - 1) * $riwayatLimit;
$riwayatTotalRows = 0;
$riwayatTotalPages = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $produkIds = $_POST['produk_id'] ?? [];
    $stokFisik = $_POST['stok_fisik'] ?? [];
    $catatanDetail = $_POST['catatan_detail'] ?? [];
    $catatan = trim($_POST['catatan'] ?? '');

    if (!$produkIds || !is_array($produkIds)) {
        $error = 'Tidak ada produk untuk stok opname.';
    } else {
        try {
            $pdo->beginTransaction();

            $kodeOpname = 'OPN-' . date('Ymd-His') . '-' . random_int(100, 999);
            $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
            $userNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? null);

            $stmtHeader = $pdo->prepare("
                INSERT INTO stok_opname
                    (kode, tanggal, total_item, total_selisih, catatan, user_id, user_nama, created_at, updated_at)
                VALUES
                    (:kode, NOW(), 0, 0, :catatan, :user_id, :user_nama, NOW(), NOW())
            ");
            $stmtHeader->execute([
                ':kode' => $kodeOpname,
                ':catatan' => $catatan !== '' ? $catatan : null,
                ':user_id' => $userId,
                ':user_nama' => $userNama,
            ]);

            $opnameId = (int)$pdo->lastInsertId();

            $stmtProduk = $pdo->prepare("
                SELECT id, kode, nama, kategori, harga_beli, harga_jual, stok, stok_minimum, satuan, status
                FROM produk
                WHERE id = :id
                LIMIT 1
            ");

            $stmtDetail = $pdo->prepare("
                INSERT INTO stok_opname_detail
                    (stok_opname_id, produk_id, kode_produk, nama_produk, kategori, satuan, harga_beli, harga_jual, stok_sistem, stok_fisik, selisih, catatan, created_at)
                VALUES
                    (:stok_opname_id, :produk_id, :kode_produk, :nama_produk, :kategori, :satuan, :harga_beli, :harga_jual, :stok_sistem, :stok_fisik, :selisih, :catatan, NOW())
            ");

            $stmtUpdateProduk = $pdo->prepare("
                UPDATE produk
                SET stok = :stok,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $totalItem = 0;
            $totalSelisih = 0;

            foreach ($produkIds as $i => $produkId) {
                $produkId = (int)$produkId;
                $fisikRaw = $stokFisik[$i] ?? '';

                if ($produkId <= 0 || $fisikRaw === '') {
                    continue;
                }

                $stokFisikFinal = (int)$fisikRaw;

                if ($stokFisikFinal < 0) {
                    $stokFisikFinal = 0;
                }

                $stmtProduk->execute([
                    ':id' => $produkId
                ]);

                $p = $stmtProduk->fetch(PDO::FETCH_ASSOC);

                if (!$p) {
                    continue;
                }

                $stokSistem = (int)$p['stok'];
                $selisih = $stokFisikFinal - $stokSistem;
                $note = trim($catatanDetail[$i] ?? '');

                $stmtDetail->execute([
                    ':stok_opname_id' => $opnameId,
                    ':produk_id' => (int)$p['id'],
                    ':kode_produk' => $p['kode'],
                    ':nama_produk' => $p['nama'],
                    ':kategori' => $p['kategori'] ?? '-',
                    ':satuan' => $p['satuan'] ?? 'pcs',
                    ':harga_beli' => (int)$p['harga_beli'],
                    ':harga_jual' => (int)$p['harga_jual'],
                    ':stok_sistem' => $stokSistem,
                    ':stok_fisik' => $stokFisikFinal,
                    ':selisih' => $selisih,
                    ':catatan' => $note !== '' ? $note : null,
                ]);

                $stmtUpdateProduk->execute([
                    ':stok' => $stokFisikFinal,
                    ':id' => (int)$p['id'],
                ]);

                $totalItem++;
                $totalSelisih += $selisih;
            }

            if ($totalItem <= 0) {
                throw new Exception('Isi minimal satu stok fisik produk.');
            }

            $stmtUpdateHeader = $pdo->prepare("
                UPDATE stok_opname
                SET total_item = :total_item,
                    total_selisih = :total_selisih,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdateHeader->execute([
                ':total_item' => $totalItem,
                ':total_selisih' => $totalSelisih,
                ':id' => $opnameId,
            ]);

            $pdo->commit();

            catat_aktivitas($pdo, 'create', 'Stok Opname', 'Menyimpan stok opname: ' . $kodeOpname);

            header('Location: stok_opname.php?success=' . urlencode('Stok opname berhasil disimpan. Kode: ' . $kodeOpname));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Gagal menyimpan stok opname: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = (string)$_GET['success'];
}

$summary = [
    'total_produk' => 0,
    'produk_aktif' => 0,
    'total_stok' => 0,
    'stok_minimum' => 0,
    'stok_kosong' => 0,
    'nilai_persediaan' => 0,
    'total_opname' => 0,
];

$produk = [];
$kategoriList = [];
$riwayat = [];

try {
    $stmtSummary = $pdo->query("
        SELECT
            COUNT(*) AS total_produk,
            COALESCE(SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END), 0) AS produk_aktif,
            COALESCE(SUM(stok), 0) AS total_stok,
            COALESCE(SUM(CASE WHEN stok <= stok_minimum THEN 1 ELSE 0 END), 0) AS stok_minimum,
            COALESCE(SUM(CASE WHEN stok <= 0 THEN 1 ELSE 0 END), 0) AS stok_kosong,
            COALESCE(SUM(stok * harga_beli), 0) AS nilai_persediaan
        FROM produk
    ");

    $summaryDb = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summaryDb as $k => $v) {
        if (array_key_exists($k, $summary)) {
            $summary[$k] = (int)$v;
        }
    }

    $summary['total_opname'] = (int)$pdo->query("SELECT COUNT(*) FROM stok_opname")->fetchColumn();

    $kategoriList = $pdo->query("
        SELECT DISTINCT kategori
        FROM produk
        WHERE kategori IS NOT NULL AND kategori <> ''
        ORDER BY kategori ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(kode LIKE :q_kode OR nama LIKE :q_nama OR kategori LIKE :q_kategori)";
        $params[':q_kode'] = '%' . $q . '%';
        $params[':q_nama'] = '%' . $q . '%';
        $params[':q_kategori'] = '%' . $q . '%';
    }

    if ($kategoriFilter !== '') {
        $where[] = "kategori = :kategori";
        $params[':kategori'] = $kategoriFilter;
    }

    if ($statusFilter === 'aktif' || $statusFilter === 'nonaktif') {
        $where[] = "status = :status";
        $params[':status'] = $statusFilter;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtProdukCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM produk
        $whereSql
    ");
    $stmtProdukCount->execute($params);
    $produkTotalRows = (int)$stmtProdukCount->fetchColumn();
    $produkTotalPages = max(1, (int)ceil($produkTotalRows / $produkLimit));
    if ($produkPage > $produkTotalPages) {
        $produkPage = $produkTotalPages;
        $produkOffset = ($produkPage - 1) * $produkLimit;
    }

    $stmtProduk = $pdo->prepare("
        SELECT id, kode, nama, kategori, harga_beli, harga_jual, stok, stok_minimum, satuan, status
        FROM produk
        $whereSql
        ORDER BY
            CASE WHEN stok <= stok_minimum THEN 0 ELSE 1 END,
            nama ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmtProduk->bindValue($key, $value);
    }
    $stmtProduk->bindValue(':limit', $produkLimit, PDO::PARAM_INT);
    $stmtProduk->bindValue(':offset', $produkOffset, PDO::PARAM_INT);
    $stmtProduk->execute();
    $produk = $stmtProduk->fetchAll(PDO::FETCH_ASSOC);

    $stmtRiwayatCount = $pdo->query("SELECT COUNT(*) FROM stok_opname");
    $riwayatTotalRows = (int)$stmtRiwayatCount->fetchColumn();
    $riwayatTotalPages = max(1, (int)ceil($riwayatTotalRows / $riwayatLimit));
    if ($riwayatPage > $riwayatTotalPages) {
        $riwayatPage = $riwayatTotalPages;
        $riwayatOffset = ($riwayatPage - 1) * $riwayatLimit;
    }

    $stmtRiwayat = $pdo->prepare("
        SELECT
            id,
            kode,
            tanggal,
            total_item,
            total_selisih,
            user_nama,
            catatan
        FROM stok_opname
        ORDER BY tanggal DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmtRiwayat->bindValue(':limit', $riwayatLimit, PDO::PARAM_INT);
    $stmtRiwayat->bindValue(':offset', $riwayatOffset, PDO::PARAM_INT);
    $stmtRiwayat->execute();
    $riwayat = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data stok opname: ' . $e->getMessage();
}

catat_view_once($pdo, 'Stok Opname', 'Membuka halaman Stok Opname');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Stok Opname</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <link rel="shortcut icon" type="image/png" href="assets/sejahub_icon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

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
            transition: background .15s;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important;
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

        .card-item {
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .card-item:hover {
            transform: translateY(-1px);
            border-color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        }

        .badge-aktif {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-nonaktif {
            background: #f5f5f5;
            color: #525252;
            border: 1px solid #e5e5e5;
        }

        .badge-low {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .badge-empty {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .stok-header,
            .stok-main-wrap {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .stok-header,
            .stok-main-wrap {
                margin-left: 0 !important;
            }

            .stok-header {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            .stok-main {
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


        @media (max-width: 1023px) {
            .stok-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .75rem;
            }

            .stok-filter-form>div:first-child,
            .stok-filter-actions {
                grid-column: 1 / -1;
            }

            .stok-form-header>div,
            .stok-form-header button {
                width: 100%;
            }

            .stok-form-header button {
                justify-content: center;
            }

            .pagination-wrap {
                align-items: stretch;
            }

            .pagination-buttons a,
            .pagination-buttons span {
                flex: 0 0 auto;
            }
        }

        @media (max-width: 640px) {
            .stok-filter-form {
                grid-template-columns: 1fr;
            }

            .card-list {
                grid-template-columns: 1fr;
                padding: .625rem;
                gap: .625rem;
            }

            .stok-main {
                padding: .75rem !important;
                padding-bottom: 6rem !important;
            }

            .stok-header-title {
                max-width: 170px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }
        }


        .stok-main input,
        .stok-main select,
        .stok-main textarea,
        .stok-main button,
        .stok-main a {
            border-radius: 0 !important;
        }

        .stok-filter-form {
            display: grid;
            grid-template-columns: minmax(220px, 2fr) minmax(160px, 1fr) minmax(140px, 1fr) minmax(140px, 1fr) auto auto;
            gap: .75rem;
            align-items: end;
        }

        .stok-form-header {
            gap: .75rem;
        }

        .stok-save-mobile-bar {
            display: none;
        }

        .stok-section-card {
            background: #fff;
            border: 1px solid #f0f0f0;
        }

        .stok-mobile-title {
            line-height: 1.25;
        }

        .pagination-wrap {
            overflow: hidden;
        }

        .pagination-buttons {
            max-width: 100%;
            padding-bottom: 2px;
        }

        .card-item input,
        .card-item textarea {
            font-size: 16px;
        }

        @media (max-width: 1279px) and (min-width: 1024px) {
            .stok-filter-form {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .stok-filter-actions {
                grid-column: span 2 / span 2;
            }
        }


        @media (max-width: 640px) {
            .stok-riwayat-limit-form {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr auto;
            }

            .stok-riwayat-limit-form select {
                width: 100%;
            }
        }


        /* ── Responsive polish: tablet and mobile ───────────────────── */
        @media (max-width: 1023px) {
            .stok-main {
                gap: .875rem !important;
            }

            .stok-main>.grid:first-of-type {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: .625rem !important;
            }

            .stok-main>.grid:first-of-type>div {
                padding: .875rem !important;
                min-height: 92px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }

            .stok-main>.grid:first-of-type p:first-child {
                font-size: 9px !important;
                line-height: 1.2;
            }

            .stok-main>.grid:first-of-type p:nth-child(2) {
                font-size: 20px !important;
                line-height: 1.15;
            }

            .stok-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .stok-filter-form>div:first-child {
                grid-column: 1 / -1;
            }

            .stok-filter-form button,
            .stok-filter-form a {
                width: 100%;
                min-height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .stok-form-header {
                padding: 1rem !important;
                align-items: stretch !important;
            }

            .stok-form-header button {
                min-height: 44px;
            }

            .card-list {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .875rem !important;
                padding: .875rem !important;
                background: #f8fafc !important;
            }

            .card-item {
                border-color: #e5e7eb !important;
                padding: 1rem !important;
                min-width: 0;
            }

            .card-item p,
            .card-item div {
                min-width: 0;
            }

            .card-item input,
            .card-item textarea,
            .stok-main input,
            .stok-main select,
            .stok-main textarea {
                min-height: 44px;
            }

            .pagination-wrap {
                padding: .875rem !important;
                background: #fff !important;
            }

            .pagination-buttons {
                display: flex;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                gap: .4rem;
                width: 100%;
            }

            .pagination-buttons::-webkit-scrollbar {
                display: none;
            }

            .pagination-buttons a,
            .pagination-buttons span {
                min-width: 38px;
                min-height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                white-space: nowrap;
            }

            .stok-riwayat-limit-form {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
                gap: .5rem;
            }
        }

        @media (min-width: 641px) and (max-width: 1023px) {
            .stok-main {
                padding: 1rem !important;
                padding-bottom: 6rem !important;
            }

            .stok-main>section,
            #form-opname,
            .stok-main>section.bg-white {
                border-color: #e5e7eb !important;
            }

            .card-item .grid.grid-cols-2 {
                gap: .75rem !important;
            }
        }

        @media (max-width: 640px) {
            body {
                background: #f8fafc !important;
            }

            .stok-main {
                padding: .625rem !important;
                padding-bottom: 6.75rem !important;
                gap: .75rem !important;
            }

            .stok-main>.grid:first-of-type {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important;
            }

            .stok-main>.grid:first-of-type>div {
                padding: .75rem !important;
                min-height: 86px;
            }

            .stok-main>.grid:first-of-type p:nth-child(2) {
                font-size: 18px !important;
                word-break: break-word;
            }

            .stok-filter-form {
                grid-template-columns: 1fr !important;
                gap: .625rem !important;
            }

            .stok-filter-form>* {
                grid-column: auto !important;
            }

            .card-list {
                grid-template-columns: 1fr !important;
                padding: .625rem !important;
                gap: .625rem !important;
                background: #f8fafc !important;
            }

            .card-item {
                padding: .875rem !important;
            }

            .card-item .flex.items-start.justify-between {
                gap: .75rem !important;
            }

            .card-item .grid.grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .card-item .grid.grid-cols-2>div {
                background: #f9fafb;
                border: 1px solid #f0f0f0;
                padding: .625rem;
            }

            .stok-save-mobile-bar {
                display: block !important;
                position: sticky;
                bottom: 0;
                z-index: 30;
                box-shadow: 0 -8px 20px rgba(15, 23, 42, .08);
            }

            .stok-form-header button.hidden.sm\:inline-flex {
                display: none !important;
            }

            .pagination-wrap {
                flex-direction: column !important;
                align-items: stretch !important;
            }

            .pagination-wrap>span,
            .pagination-wrap>div:first-child {
                text-align: center;
            }

            .stok-riwayat-limit-form {
                grid-template-columns: 1fr !important;
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

    <div class="stok-main-wrap">
        <main class="stok-main p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

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
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Produk</p>
                    <p class="text-2xl font-bold text-blue-600"><?= angka($summary['total_produk']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?= angka($summary['produk_aktif']) ?> aktif</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Stok</p>
                    <p class="text-2xl font-bold"><?= angka($summary['total_stok']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Semua satuan</p>
                </div>

                <div class="bg-white border <?= $summary['stok_minimum'] > 0 ? 'border-orange-200' : 'border-subtle' ?> p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Minimum</p>
                    <p class="text-2xl font-bold <?= $summary['stok_minimum'] > 0 ? 'text-orange-600' : '' ?>"><?= angka($summary['stok_minimum']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">≤ stok minimum</p>
                </div>

                <div class="bg-white border <?= $summary['stok_kosong'] > 0 ? 'border-red-200' : 'border-subtle' ?> p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Kosong</p>
                    <p class="text-2xl font-bold <?= $summary['stok_kosong'] > 0 ? 'text-red-600' : '' ?>"><?= angka($summary['stok_kosong']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Stok 0 / minus</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Stok</p>
                    <p class="text-xl font-bold"><?= rupiah($summary['nilai_persediaan']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Stok × harga beli</p>
                </div>

                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Opname</p>
                    <p class="text-2xl font-bold text-green-600"><?= angka($summary['total_opname']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Riwayat tersimpan</p>
                </div>
            </div>

            <section class="bg-white border border-subtle p-4">
                <form method="GET" class="stok-filter-form">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Cari Produk</label>
                        <input type="text" name="q" value="<?= h($q) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all" placeholder="Nama / kode / kategori">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Kategori</label>
                        <select name="kategori" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($kategoriList as $kat): ?>
                                <option value="<?= h($kat) ?>" <?= $kategoriFilter === $kat ? 'selected' : '' ?>>
                                    <?= h($kat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Status</label>
                        <select name="status" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all">
                            <option value="">Semua Status</option>
                            <option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                            <option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tampil Produk</label>
                        <select name="produk_limit" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all">
                            <?php foreach ([10, 25, 50, 100, 200] as $limitOpt): ?>
                                <option value="<?= $limitOpt ?>" <?= $produkLimit === $limitOpt ? 'selected' : '' ?>><?= $limitOpt ?> Data</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="produk_page" value="1">
                    <input type="hidden" name="riwayat_page" value="<?= (int)$riwayatPage ?>">
                    <input type="hidden" name="riwayat_limit" value="<?= (int)$riwayatLimit ?>">

                    <button type="submit" class="px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">
                        Tampilkan
                    </button>

                    <a href="stok_opname.php" class="px-5 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 transition-all text-center">
                        Reset
                    </a>
                </form>
            </section>

            <form method="POST" id="form-opname" class="bg-white border border-subtle overflow-hidden">
                <div class="stok-form-header px-5 py-4 border-b border-subtle flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Form Stok Opname</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($produk)) ?> dari <?= angka($produkTotalRows) ?> produk ditampilkan. Isi stok fisik hanya untuk produk yang dicek pada halaman ini.</p>
                    </div>

                    <button type="submit" onclick="return confirm('Simpan stok opname dan update stok produk?')" class="hidden sm:inline-flex px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Simpan
                    </button>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:980px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kategori</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga Beli</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Stok Sistem</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Min</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Stok Fisik</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Catatan</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$produk): ?>
                                <tr>
                                    <td colspan="8" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Produk tidak ditemukan
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($produk as $p):
                                $stok = (int)$p['stok'];
                                $min = (int)$p['stok_minimum'];
                                $stokClass = $stok <= 0 ? 'badge-empty' : ($stok <= $min ? 'badge-low' : 'badge-aktif');
                            ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <input type="hidden" name="produk_id[]" value="<?= h($p['id']) ?>">
                                        <div class="font-semibold text-sm leading-tight"><?= h($p['nama']) ?></div>
                                        <div class="text-[10px] text-gray-400 font-mono"><?= h($p['kode']) ?></div>
                                    </td>

                                    <td class="px-5 py-4 text-sm"><?= h($p['kategori']) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($p['harga_beli']) ?></td>

                                    <td class="px-5 py-4 text-right">
                                        <span class="<?= $stokClass ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                            <?= angka($stok) ?> <?= h($p['satuan']) ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4 text-right text-sm text-gray-500"><?= angka($min) ?></td>

                                    <td class="px-5 py-4">
                                        <input
                                            type="number"
                                            name="stok_fisik[]"
                                            min="0"
                                            inputmode="numeric"
                                            class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm text-right transition-all"
                                            placeholder="<?= h($stok) ?>">
                                    </td>

                                    <td class="px-5 py-4">
                                        <input
                                            type="text"
                                            name="catatan_detail[]"
                                            class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all"
                                            placeholder="Opsional">
                                    </td>

                                    <td class="px-5 py-4">
                                        <span class="<?= $p['status'] === 'aktif' ? 'badge-aktif' : 'badge-nonaktif' ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full">
                                            <?= h($p['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-list">
                    <?php if (!$produk): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Produk tidak ditemukan
                        </div>
                    <?php endif; ?>

                    <?php foreach ($produk as $p):
                        $stok = (int)$p['stok'];
                        $min = (int)$p['stok_minimum'];
                    ?>
                        <div class="card-item bg-white border border-subtle p-4 flex flex-col gap-3">
                            <input type="hidden" name="produk_id[]" value="<?= h($p['id']) ?>">

                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-bold text-sm leading-tight truncate"><?= h($p['nama']) ?></p>
                                    <p class="text-[10px] text-gray-400 font-mono truncate"><?= h($p['kode']) ?></p>
                                    <p class="text-[10px] text-gray-400 mt-1"><?= h($p['kategori']) ?> • <?= h($p['satuan']) ?></p>
                                </div>

                                <div class="text-right shrink-0">
                                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Sistem</p>
                                    <p class="text-sm font-black <?= $stok <= 0 ? 'text-red-600' : ($stok <= $min ? 'text-orange-600' : '') ?>">
                                        <?= angka($stok) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Min</span>
                                    <p class="font-bold"><?= angka($min) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">Harga Beli</span>
                                    <p class="font-bold"><?= rupiah($p['harga_beli']) ?></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 pt-2 border-t border-subtle">
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Stok Fisik</label>
                                    <input
                                        type="number"
                                        name="stok_fisik[]"
                                        min="0"
                                        inputmode="numeric"
                                        class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all"
                                        placeholder="<?= h($stok) ?>">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Catatan</label>
                                    <input
                                        type="text"
                                        name="catatan_detail[]"
                                        class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all"
                                        placeholder="Opsional">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?= stok_render_pagination($produkPage, $produkTotalPages, $produkTotalRows, $produkLimit, 'produk_page', 'produk_limit') ?>

                <div class="p-4 border-t border-subtle bg-gray-50">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Catatan Opname</label>
                    <textarea name="catatan" rows="3" class="w-full bg-white border border-gray-100 px-3 py-2.5 text-sm transition-all" placeholder="Opsional, contoh: stok opname akhir bulan"></textarea>

                    <button type="submit" onclick="return confirm('Simpan stok opname dan update stok produk?')" class="stok-save-mobile-bar mt-3 w-full sm:hidden px-5 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Simpan Opname
                    </button>
                </div>
            </form>

            <section class="bg-white border border-subtle overflow-hidden">
                <div class="px-5 py-4 border-b border-subtle flex flex-col md:flex-row md:items-end md:justify-between gap-3">
                    <div>
                        <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Riwayat Stok Opname</h2>
                        <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($riwayat)) ?> dari <?= angka($riwayatTotalRows) ?> riwayat ditampilkan</p>
                    </div>
                    <form method="GET" class="stok-riwayat-limit-form flex items-end gap-2">
                        <input type="hidden" name="q" value="<?= h($q) ?>">
                        <input type="hidden" name="kategori" value="<?= h($kategoriFilter) ?>">
                        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                        <input type="hidden" name="produk_page" value="<?= (int)$produkPage ?>">
                        <input type="hidden" name="produk_limit" value="<?= (int)$produkLimit ?>">
                        <input type="hidden" name="riwayat_page" value="1">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tampil</label>
                            <select name="riwayat_limit" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm transition-all">
                                <?php foreach ([10, 25, 50, 100, 200] as $limitOpt): ?>
                                    <option value="<?= $limitOpt ?>" <?= $riwayatLimit === $limitOpt ? 'selected' : '' ?>><?= $limitOpt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">OK</button>
                    </form>
                </div>

                <div class="tbl-desktop overflow-x-auto no-scrollbar">
                    <table class="w-full text-left" style="min-width:720px">
                        <thead class="border-b border-subtle bg-gray-50">
                            <tr>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Item</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Selisih</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">User</th>
                                <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Catatan</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php if (!$riwayat): ?>
                                <tr>
                                    <td colspan="6" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                                        Belum ada riwayat opname
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($riwayat as $r): ?>
                                <tr>
                                    <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= h(waktu($r['tanggal'])) ?></td>
                                    <td class="px-5 py-4 text-sm font-bold"><?= h($r['kode']) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($r['total_item']) ?></td>
                                    <td class="px-5 py-4 text-right text-sm font-bold <?= ((int)$r['total_selisih'] < 0) ? 'text-red-600' : 'text-green-600' ?>">
                                        <?= ((int)$r['total_selisih'] > 0 ? '+' : '') . angka($r['total_selisih']) ?>
                                    </td>
                                    <td class="px-5 py-4 text-sm"><?= h($r['user_nama'] ?: '-') ?></td>
                                    <td class="px-5 py-4 text-xs text-gray-500"><?= h($r['catatan'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-list">
                    <?php if (!$riwayat): ?>
                        <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Belum ada riwayat opname
                        </div>
                    <?php endif; ?>

                    <?php foreach ($riwayat as $r): ?>
                        <div class="card-item bg-white border border-subtle p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-bold text-sm"><?= h($r['kode']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= h(waktu($r['tanggal'])) ?></p>
                                </div>

                                <div class="text-right">
                                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Selisih</p>
                                    <p class="text-sm font-black <?= ((int)$r['total_selisih'] < 0) ? 'text-red-600' : 'text-green-600' ?>">
                                        <?= ((int)$r['total_selisih'] > 0 ? '+' : '') . angka($r['total_selisih']) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                <div>
                                    <span class="text-gray-400 font-medium">Item</span>
                                    <p class="font-bold"><?= angka($r['total_item']) ?></p>
                                </div>
                                <div>
                                    <span class="text-gray-400 font-medium">User</span>
                                    <p class="font-bold truncate"><?= h($r['user_nama'] ?: '-') ?></p>
                                </div>
                            </div>

                            <?php if (!empty($r['catatan'])): ?>
                                <p class="text-xs text-gray-500 pt-2 border-t border-subtle"><?= h($r['catatan']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?= stok_render_pagination($riwayatPage, $riwayatTotalPages, $riwayatTotalRows, $riwayatLimit, 'riwayat_page', 'riwayat_limit') ?>
            </section>

        </main>
    </div>

    <!-- <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-50 shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18M3 6h18M3 18h18" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>

        <a href="pos.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M8 12h8" />
            </svg>
        </a>

        <a href="produk.php" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-black">Produk</span>
        </a>
    </nav> -->

    <script>
        function stokSyncResponsiveInputs() {
            var form = document.getElementById('form-opname');
            if (!form) return;
            var isDesktop = window.matchMedia('(min-width: 1024px)').matches;
            var desktopInputs = form.querySelectorAll('.tbl-desktop input, .tbl-desktop textarea, .tbl-desktop select');
            var mobileInputs = form.querySelectorAll('.card-list input, .card-list textarea, .card-list select');

            desktopInputs.forEach(function(el) {
                el.disabled = !isDesktop;
            });
            mobileInputs.forEach(function(el) {
                el.disabled = isDesktop;
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            stokSyncResponsiveInputs();
            window.addEventListener('resize', stokSyncResponsiveInputs);
            var form = document.getElementById('form-opname');
            if (form) {
                form.addEventListener('submit', function() {
                    stokSyncResponsiveInputs();
                });
            }
        });

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