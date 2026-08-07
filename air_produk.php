<?php
/*
|--------------------------------------------------------------------------
| air_produk.php — Master Produk Air Mineral
|--------------------------------------------------------------------------
| Fungsi:
| - Menyimpan master jenis produk air mineral
| - Menyimpan harga vendor dan harga tagihan ke kantor
| - Tidak menggunakan stok internal
| - Menjadi sumber harga untuk kwitansi dan laporan
|
| Compatible PHP 7 & PHP 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'air_produk';
$pageTitle  = 'Master Produk Air Mineral';
$backUrl    = 'dashboard.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('ap_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function ap_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ap_rupiah')) {
    /**
     * @param mixed $value
     * @return string
     */
    function ap_rupiah($value)
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}

if (!function_exists('ap_column_exists')) {
    function ap_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name'  => $table,
                ':column_name' => $column,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ap_ensure_schema')) {
    function ap_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_produk (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kode_produk VARCHAR(30) NOT NULL,
                nama_produk VARCHAR(180) NOT NULL,
                jenis VARCHAR(80) NOT NULL DEFAULT 'lainnya',
                satuan VARCHAR(30) NOT NULL DEFAULT 'unit',
                harga_vendor DECIMAL(15,2) NOT NULL DEFAULT 0,
                harga_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0,
                status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_air_produk_kode (kode_produk),
                INDEX idx_air_produk_status (status),
                INDEX idx_air_produk_jenis (jenis)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $columns = [
            'harga_vendor'  => "ALTER TABLE air_produk ADD COLUMN harga_vendor DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER satuan",
            'harga_tagihan' => "ALTER TABLE air_produk ADD COLUMN harga_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER harga_vendor",
            'is_deleted'    => "ALTER TABLE air_produk ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
            'deleted_at'    => "ALTER TABLE air_produk ADD COLUMN deleted_at DATETIME NULL AFTER is_deleted",
            'updated_at'    => "ALTER TABLE air_produk ADD COLUMN updated_at DATETIME NULL AFTER created_at",
        ];

        foreach ($columns as $column => $sql) {
            if (!ap_column_exists($pdo, 'air_produk', $column)) {
                $pdo->exec($sql);
            }
        }

        // Kompatibilitas database lama.
        if (ap_column_exists($pdo, 'air_produk', 'harga_jual')) {
            $pdo->exec("
                UPDATE air_produk
                SET harga_tagihan = CASE
                    WHEN harga_tagihan = 0 THEN COALESCE(harga_jual, 0)
                    ELSE harga_tagihan
                END
            ");
        }

        try {
            $column = $pdo->query("SHOW COLUMNS FROM air_produk LIKE 'jenis'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string)($column['Type'] ?? ''));
            if ($type !== '' && strpos($type, 'varchar') !== 0) {
                $pdo->exec("ALTER TABLE air_produk MODIFY COLUMN jenis VARCHAR(80) NOT NULL DEFAULT 'lainnya'");
            }
        } catch (Throwable $e) {
            error_log('AIR PRODUK MIGRASI JENIS: ' . $e->getMessage());
        }

        // Bersihkan duplikat lama yang sebelumnya dihapus melalui mekanisme nonaktif.
        // Hanya baris nonaktif yang memiliki pasangan aktif dengan jenis sama yang disembunyikan.
        try {
            $pdo->exec("
                UPDATE air_produk nonaktif
                JOIN air_produk aktif
                  ON aktif.id <> nonaktif.id
                 AND aktif.is_deleted = 0
                 AND aktif.status = 'aktif'
                 AND COALESCE(NULLIF(TRIM(aktif.jenis), ''), aktif.kode_produk)
                     = COALESCE(NULLIF(TRIM(nonaktif.jenis), ''), nonaktif.kode_produk)
                SET nonaktif.is_deleted = 1,
                    nonaktif.deleted_at = COALESCE(nonaktif.deleted_at, NOW()),
                    nonaktif.updated_at = NOW()
                WHERE nonaktif.is_deleted = 0
                  AND nonaktif.status = 'nonaktif'
            ");
        } catch (Throwable $e) {
            error_log('AIR PRODUK CLEANUP DUPLIKAT: ' . $e->getMessage());
        }

        // Produk tidak pernah ditambahkan otomatis.
        // Seluruh master produk harus dibuat melalui tombol Tambah Produk.

    }
}

$flash = '';
$flashType = 'success';

if (empty($_SESSION['air_produk_form_token'])) {
    $_SESSION['air_produk_form_token'] = bin2hex(random_bytes(24));
}
$airProdukFormToken = (string)$_SESSION['air_produk_form_token'];

try {
    ap_ensure_schema($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan database master produk: ' . $e->getMessage();
    $flashType = 'error';
}

if (!empty($_SESSION['air_produk_flash'])) {
    $flash = (string)$_SESSION['air_produk_flash'];
    unset($_SESSION['air_produk_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'error') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $postedToken = (string)($_POST['form_token'] ?? '');

        if ($postedToken === '' || !hash_equals($airProdukFormToken, $postedToken)) {
            throw new RuntimeException('Permintaan tidak valid atau formulir sudah pernah diproses. Muat ulang halaman lalu coba lagi.');
        }

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $formMode = trim((string)($_POST['form_mode'] ?? ($id > 0 ? 'edit' : 'create')));
            $kode = strtoupper(trim((string)($_POST['kode_produk'] ?? '')));
            $nama = trim((string)($_POST['nama_produk'] ?? ''));
            $jenis = trim((string)($_POST['jenis'] ?? 'lainnya'));
            $satuan = trim((string)($_POST['satuan'] ?? 'unit'));
            $hargaVendor = max(0, (float)($_POST['harga_vendor'] ?? 0));
            $hargaTagihan = max(0, (float)($_POST['harga_tagihan'] ?? 0));
            $status = trim((string)($_POST['status'] ?? 'aktif'));

            if ($kode === '' || $nama === '') {
                throw new RuntimeException('Kode dan nama produk wajib diisi.');
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                $status = 'aktif';
            }

            $stmtCheck = $pdo->prepare("\n                SELECT id, is_deleted\n                FROM air_produk\n                WHERE kode_produk = :kode\n                  AND id <> :id\n                LIMIT 1\n            ");
            $stmtCheck->execute([
                ':kode' => $kode,
                ':id'   => $id,
            ]);
            $existingProduct = $stmtCheck->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingProduct && (int)$existingProduct['is_deleted'] === 0) {
                throw new RuntimeException('Kode produk sudah digunakan. Gunakan tombol Edit pada produk yang sudah ada.');
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                $stmt = $pdo->prepare("\n                    UPDATE air_produk\n                    SET kode_produk = :kode,\n                        nama_produk = :nama,\n                        jenis = :jenis,\n                        satuan = :satuan,\n                        harga_vendor = :harga_vendor,\n                        harga_tagihan = :harga_tagihan,\n                        status = :status,\n                        is_deleted = 0,\n                        deleted_at = NULL,\n                        updated_at = NOW()\n                    WHERE id = :id\n                ");
                $stmt->execute([
                    ':kode'          => $kode,
                    ':nama'          => $nama,
                    ':jenis'         => $jenis,
                    ':satuan'        => $satuan,
                    ':harga_vendor'  => $hargaVendor,
                    ':harga_tagihan' => $hargaTagihan,
                    ':status'        => $status,
                    ':id'            => $id,
                ]);

                $flash = 'Produk berhasil diperbarui.';
            } else {
                if ($formMode !== 'create') {
                    throw new RuntimeException('Mode formulir tidak valid.');
                }

                if ($existingProduct && (int)$existingProduct['is_deleted'] === 1) {
                    $stmt = $pdo->prepare("\n                        UPDATE air_produk\n                        SET nama_produk = :nama,\n                            jenis = :jenis,\n                            satuan = :satuan,\n                            harga_vendor = :harga_vendor,\n                            harga_tagihan = :harga_tagihan,\n                            status = :status,\n                            is_deleted = 0,\n                            deleted_at = NULL,\n                            updated_at = NOW()\n                        WHERE id = :id\n                    ");
                    $stmt->execute([
                        ':nama'          => $nama,
                        ':jenis'         => $jenis,
                        ':satuan'        => $satuan,
                        ':harga_vendor'  => $hargaVendor,
                        ':harga_tagihan' => $hargaTagihan,
                        ':status'        => $status,
                        ':id'            => (int)$existingProduct['id'],
                    ]);
                    $flash = 'Produk lama dipulihkan dan diperbarui, bukan dibuat sebagai data baru.';
                } else {
                    $stmt = $pdo->prepare("\n                        INSERT INTO air_produk\n                            (kode_produk, nama_produk, jenis, satuan, harga_vendor, harga_tagihan, status, is_deleted, created_at)\n                        VALUES\n                            (:kode, :nama, :jenis, :satuan, :harga_vendor, :harga_tagihan, :status, 0, NOW())\n                    ");
                    $stmt->execute([
                        ':kode'          => $kode,
                        ':nama'          => $nama,
                        ':jenis'         => $jenis,
                        ':satuan'        => $satuan,
                        ':harga_vendor'  => $hargaVendor,
                        ':harga_tagihan' => $hargaTagihan,
                        ':status'        => $status,
                    ]);
                    $flash = 'Produk berhasil ditambahkan.';
                }
            }

            $pdo->commit();
            $_SESSION['air_produk_flash'] = $flash;
            $_SESSION['air_produk_form_token'] = bin2hex(random_bytes(24));
            header('Location: air_produk.php');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID produk tidak valid.');
            }

            $used = false;

            $usageChecks = [
                ['table' => 'air_pesanan_detail', 'column' => 'produk_id'],
                ['table' => 'air_vendor_order_detail', 'column' => 'produk_id'],
                ['table' => 'air_kwitansi_detail', 'column' => 'produk_id'],
            ];

            foreach ($usageChecks as $check) {
                try {
                    $stmtUsage = $pdo->prepare(
                        "SELECT COUNT(*) FROM {$check['table']} WHERE {$check['column']} = :id"
                    );
                    $stmtUsage->execute([':id' => $id]);

                    if ((int)$stmtUsage->fetchColumn() > 0) {
                        $used = true;
                        break;
                    }
                } catch (Throwable $e) {
                    // Tabel belum tersedia, lanjutkan pemeriksaan berikutnya.
                }
            }

            if ($used) {
                $stmt = $pdo->prepare("
                    UPDATE air_produk
                    SET status = 'nonaktif',
                        is_deleted = 1,
                        deleted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $id]);

                $flash = 'Produk sudah pernah digunakan. Produk disembunyikan dari master, tetapi riwayat transaksi tetap aman.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM air_produk WHERE id = :id");
                $stmt->execute([':id' => $id]);

                $flash = 'Produk berhasil dihapus permanen.';
            }
        }

        if ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));

            if ($id <= 0 || !in_array($status, ['aktif', 'nonaktif'], true)) {
                throw new RuntimeException('Data status tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE air_produk
                SET status = :status,
                    updated_at = NOW()
                WHERE id = :id
                  AND is_deleted = 0
            ");
            $stmt->execute([
                ':status' => $status,
                ':id'     => $id,
            ]);

            $flash = 'Status produk berhasil diperbarui.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'aktif'));
$jenisFilter = trim((string)($_GET['jenis'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedLimits = [10, 15, 25, 50, 100];
$perPage = (int)($_GET['limit'] ?? 15);

if (!in_array($perPage, $allowedLimits, true)) {
    $perPage = 15;
}

if (!in_array($statusFilter, ['aktif', 'nonaktif', 'semua'], true)) {
    $statusFilter = 'aktif';
}

$where = ['is_deleted = 0'];
$params = [];

if ($q !== '') {
    $where[] = "(kode_produk LIKE :q OR nama_produk LIKE :q OR jenis LIKE :q OR satuan LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($statusFilter !== 'semua') {
    $where[] = "status = :status";
    $params[':status'] = $statusFilter;
}

if ($jenisFilter !== '') {
    $where[] = "jenis = :jenis";
    $params[':jenis'] = $jenisFilter;
}

$whereSql = implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM air_produk WHERE $whereSql");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT *
    FROM air_produk
    WHERE $whereSql
    ORDER BY status DESC, nama_produk ASC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'total'                 => 0,
    'aktif'                 => 0,
    'nonaktif'              => 0,
    'harga_vendor_kosong'   => 0,
    'harga_tagihan_kosong'  => 0,
    'harga_lengkap'         => 0,
];

try {
    // Ringkasan mengikuti data master yang benar-benar tampil di halaman.
    // Produk yang sudah dihapus secara soft-delete tidak ikut dihitung.
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) AS aktif,
            SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) AS nonaktif,
            SUM(CASE WHEN COALESCE(harga_vendor, 0) <= 0 THEN 1 ELSE 0 END) AS harga_vendor_kosong,
            SUM(CASE WHEN COALESCE(harga_tagihan, 0) <= 0 THEN 1 ELSE 0 END) AS harga_tagihan_kosong,
            SUM(
                CASE
                    WHEN COALESCE(harga_vendor, 0) > 0
                     AND COALESCE(harga_tagihan, 0) > 0
                    THEN 1 ELSE 0
                END
            ) AS harga_lengkap
        FROM air_produk
        WHERE is_deleted = 0
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        if (array_key_exists($key, $summaryRow)) {
            $summary[$key] = (float)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
}

$jenisList = $pdo->query("
    SELECT DISTINCT jenis
    FROM air_produk
    WHERE is_deleted = 0
      AND jenis IS NOT NULL
      AND TRIM(jenis) <> ''
    ORDER BY jenis ASC
")->fetchAll(PDO::FETCH_COLUMN);


if (!function_exists('ap_tanggal_update')) {
    /**
     * @param mixed $updatedAt
     * @param mixed $createdAt
     * @return string
     */
    function ap_tanggal_update($updatedAt, $createdAt)
    {
        $value = $updatedAt ?: $createdAt;

        if (!$value) {
            return '-';
        }

        $time = strtotime((string)$value);

        return $time ? date('d/m/Y H:i', $time) : '-';
    }
}

function ap_page_url(array $query, int $targetPage): string
{
    $query['page'] = max(1, $targetPage);
    return 'air_produk.php?' . http_build_query($query);
}

$baseQuery = $_GET;
unset($baseQuery['page']);

$faviconFile = __DIR__ . '/assets/sejahub_icon.png';
$faviconVersion = is_file($faviconFile) ? (string)filemtime($faviconFile) : (string)time();
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$scriptDir = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
$faviconUrl = $scriptDir . '/assets/sejahub_icon.png?v=' . rawurlencode($faviconVersion);

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Produk Air Mineral</title>

    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo ap_h($faviconUrl); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo ap_h($faviconUrl); ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #fcfcfc;
            color: #1a1a1a;
        }

        .air-main {
            min-height: calc(100vh - 64px);
        }

        .card,
        .summary-card,
        .filter-card,
        .table-card,
        .mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .field {
            width: 100%;
            min-height: 44px;
            padding: 0 12px;
            border: 1px solid #f0f0f0;
            background: #f9fafb;
            font-size: 12px;
            font-weight: 700;
            outline: none;
            border-radius: 0 !important;
        }

        .field:focus {
            background: #fff;
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
        }

        .filter-search {
            position: relative;
            min-width: 0;
        }

        .filter-search>i,
        .filter-search>svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: #9ca3af;
            pointer-events: none;
            z-index: 3;
        }

        .search-field {
            padding-left: 44px !important;
            padding-right: 12px !important;
        }

        .btn {
            min-height: 42px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-radius: 0 !important;
        }

        .summary-card {
            min-height: 108px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border: 1px solid;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .status-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: currentColor;
        }

        tbody tr {
            transition: background .15s;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        .product-table {
            table-layout: fixed;
            min-width: 1320px;
        }

        .product-table th {
            white-space: nowrap;
        }

        .product-table td {
            vertical-align: middle;
        }

        .product-name {
            font-size: 13px;
            line-height: 1.45;
            font-weight: 800;
            color: #111827;
            overflow-wrap: anywhere;
        }

        .product-code {
            margin-top: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 10px;
            color: #9ca3af;
        }

        .price-value {
            display: block;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 800;
        }

        .action-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            white-space: nowrap;
        }

        .action-group form {
            margin: 0;
            display: inline-flex;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #9ca3af;
            line-height: 1;
            cursor: pointer;
            transition:
                background .15s ease,
                color .15s ease,
                border-color .15s ease,
                transform .15s ease;
        }

        .action-btn svg {
            width: 16px;
            height: 16px;
            stroke-width: 1.8;
            pointer-events: none;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .action-btn-edit:hover {
            color: #2563eb;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .action-btn-toggle:hover {
            color: #d97706;
            background: #fffbeb;
            border-color: #fde68a;
        }

        .action-btn-toggle.is-inactive:hover {
            color: #16a34a;
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .action-btn-delete:hover {
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }

        .action-btn:focus-visible {
            outline: 2px solid #111827;
            outline-offset: 2px;
        }

        .pagination-bar {
            min-height: 64px;
        }

        .card-action-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f0f0f0;
        }

        .card-action-grid form {
            margin: 0;
            min-width: 0;
        }

        .card-action-btn {
            width: 100%;
            min-width: 0;
            min-height: 40px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 9px;
            font-weight: 900;
            line-height: 1;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-action-btn svg {
            width: 14px;
            height: 14px;
            stroke-width: 1.9;
            flex-shrink: 0;
        }

        .card-action-edit {
            color: #2563eb;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .card-action-toggle {
            color: #b45309;
            border-color: #fde68a;
            background: #fffbeb;
        }

        .card-action-toggle.is-inactive {
            color: #15803d;
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .card-action-delete {
            color: #dc2626;
            border-color: #fecaca;
            background: #fef2f2;
        }

        .card-action-btn:active {
            transform: scale(.98);
        }

        .modal-panel {
            max-height: calc(100vh - 32px);
        }

        @media (min-width: 1024px) {
            .air-main {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            body {
                background: #f8fafc;
                padding-bottom: 76px;
            }

            .air-main {
                margin-left: 0 !important;
                padding: 1rem !important;
                padding-bottom: 6.25rem !important;
            }

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .filter-search {
                grid-column: 1 / -1;
            }

            .mobile-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        @media (max-width: 640px) {
            .air-main {
                padding: .625rem !important;
                padding-bottom: 6.5rem !important;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important;
            }

            .summary-card {
                min-height: 92px;
                padding: .75rem !important;
            }

            .filter-grid {
                grid-template-columns: 1fr !important;
            }

            .filter-search {
                grid-column: auto;
            }

            .mobile-grid {
                grid-template-columns: 1fr !important;
                padding: .625rem !important;
            }

            .card-action-grid {
                gap: 6px;
            }

            .card-action-btn {
                min-height: 38px;
                padding: 0 6px;
                font-size: 8px;
                letter-spacing: .04em;
            }

            .modal-wrap {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            .modal-panel {
                width: 100% !important;
                max-width: none !important;
                height: 100vh;
                max-height: 100vh;
            }

            .modal-body {
                max-height: calc(100vh - 132px) !important;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen">
    <main class="air-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold <?php echo $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                <?php echo ap_h($flash); ?>
            </div>
        <?php endif; ?>

        <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 md:mb-8">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-blue-600">Modul Air Mineral</p>
                <h1 class="text-xl md:text-2xl font-light tracking-tight mt-1">
                    Master <span class="font-semibold">Produk dan Harga</span>
                </h1>
                <p class="text-xs text-gray-400 mt-1">
                    Kelola jenis produk, harga vendor, dan harga penagihan ke kantor.
                </p>
            </div>

            <button type="button"
                onclick="openProductModal()"
                class="btn bg-black text-white hover:bg-gray-800">
                <i data-lucide="plus" class="w-4 h-4"></i>
                Tambah Produk
            </button>
        </header>

        <div class="summary-grid grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4 mb-6">
            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Produk</p>
                <p class="text-2xl font-black mt-2"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[9px] text-gray-400">Semua master produk</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Produk Aktif</p>
                <p class="text-2xl font-black text-green-600 mt-2"><?php echo number_format($summary['aktif']); ?></p>
                <p class="text-[9px] text-gray-400">Dapat digunakan</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-red-600">Produk Nonaktif</p>
                <p class="text-2xl font-black text-red-600 mt-2"><?php echo number_format($summary['nonaktif']); ?></p>
                <p class="text-[9px] text-gray-400">Tidak ditampilkan</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Harga Vendor Kosong</p>
                <p class="text-2xl font-black text-amber-600 mt-2"><?php echo number_format($summary['harga_vendor_kosong']); ?></p>
                <p class="text-[9px] text-gray-400">Perlu dilengkapi</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Harga Tagihan Kosong</p>
                <p class="text-2xl font-black text-blue-600 mt-2"><?php echo number_format($summary['harga_tagihan_kosong']); ?></p>
                <p class="text-[9px] text-gray-400">Belum siap kwitansi</p>
            </div>

            <div class="summary-card p-4 col-span-2 md:col-span-1">
                <p class="text-[9px] font-black uppercase tracking-widest text-purple-600">Harga Lengkap</p>
                <p class="text-2xl font-black text-purple-600 mt-2"><?php echo number_format($summary['harga_lengkap']); ?></p>
                <p class="text-[9px] text-gray-400">Vendor dan tagihan terisi</p>
            </div>
        </div>

        <form method="get" class="filter-card p-4 mb-4">
            <div class="filter-grid grid grid-cols-1 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_180px_160px_130px_auto_auto] gap-3 items-center">
                <div class="filter-search">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"></i>
                    <input type="search"
                        name="q"
                        value="<?php echo ap_h($q); ?>"
                        class="field search-field"
                        placeholder="Cari kode, nama, atau jenis produk">
                </div>

                <select name="jenis" class="field">
                    <option value="">Semua Jenis</option>
                    <?php foreach ($jenisList as $jenis): ?>
                        <option value="<?php echo ap_h($jenis); ?>" <?php echo $jenisFilter === $jenis ? 'selected' : ''; ?>>
                            <?php echo ap_h(ucwords(str_replace('_', ' ', $jenis))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="field">
                    <option value="aktif" <?php echo $statusFilter === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="nonaktif" <?php echo $statusFilter === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                    <option value="semua" <?php echo $statusFilter === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                </select>

                <select name="limit" class="field">
                    <?php foreach ($allowedLimits as $limit): ?>
                        <option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>>
                            <?php echo $limit; ?> / Hal
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn bg-black text-white">
                    <i data-lucide="filter" class="w-4 h-4"></i>
                    Terapkan
                </button>

                <a href="air_produk.php" class="btn border border-gray-200 bg-white text-gray-700">
                    Reset
                </a>
            </div>

            <p class="text-xs text-gray-400 mt-3 text-right">
                <?php echo number_format($totalRows); ?> produk ditemukan
            </p>
        </form>

        <div class="table-card overflow-hidden">
            <div class="hidden lg:block overflow-x-auto">
                <table class="product-table w-full text-left">
                    <thead class="border-b border-[#f0f0f0] bg-gray-50">
                        <tr>
                            <th class="w-[300px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400">Produk</th>
                            <th class="w-[140px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400">Jenis</th>
                            <th class="w-[90px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Satuan</th>
                            <th class="w-[140px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Harga Vendor</th>
                            <th class="w-[140px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Harga Tagihan</th>
                            <th class="w-[120px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Selisih</th>
                            <th class="w-[110px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="w-[155px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Terakhir Update</th>
                            <th class="w-[135px] px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-[#f5f5f5]">
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="9" class="py-20 text-center text-xs font-black uppercase tracking-widest text-gray-300">
                                    Produk tidak ditemukan
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $margin = (float)$row['harga_tagihan'] - (float)$row['harga_vendor'];
                                ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="product-name"><?php echo ap_h($row['nama_produk']); ?></p>
                                        <p class="product-code"><?php echo ap_h($row['kode_produk']); ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex bg-gray-100 px-2 py-1 text-[9px] font-black uppercase tracking-wide text-gray-600">
                                            <?php echo ap_h(ucwords(str_replace('_', ' ', $row['jenis']))); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center text-xs font-bold"><?php echo ap_h($row['satuan']); ?></td>
                                    <td class="px-5 py-4 text-right"><span class="price-value text-gray-600"><?php echo ap_rupiah($row['harga_vendor']); ?></span></td>
                                    <td class="px-5 py-4 text-right"><span class="price-value text-blue-600"><?php echo ap_rupiah($row['harga_tagihan']); ?></span></td>
                                    <td class="px-5 py-4 text-right"><span class="price-value <?php echo $margin >= 0 ? 'text-green-600' : 'text-red-600'; ?>"><?php echo ap_rupiah($margin); ?></span></td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($row['status'] === 'aktif'): ?>
                                            <span class="status-badge border-green-200 bg-green-50 text-green-700">Aktif</span>
                                        <?php else: ?>
                                            <span class="status-badge border-red-200 bg-red-50 text-red-700">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <p class="text-[10px] font-bold text-gray-500"><?php echo ap_h(ap_tanggal_update($row['updated_at'] ?? null, $row['created_at'] ?? null)); ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="action-group">
                                            <button type="button"
                                                onclick='editProduct(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="action-btn action-btn-edit"
                                                title="Edit produk" aria-label="Edit produk">
                                                <i data-lucide="pencil" class="w-4 h-4"></i>
                                            </button>

                                            <form method="post">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="form_token" value="<?php echo ap_h($airProdukFormToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $row['status'] === 'aktif' ? 'nonaktif' : 'aktif'; ?>">
                                                <button type="submit"
                                                    class="action-btn action-btn-toggle <?php echo $row['status'] === 'aktif' ? '' : 'is-inactive'; ?>"
                                                    title="<?php echo $row['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?>" aria-label="<?php echo $row['status'] === 'aktif' ? 'Nonaktifkan produk' : 'Aktifkan produk'; ?>">
                                                    <i data-lucide="<?php echo $row['status'] === 'aktif' ? 'circle-off' : 'circle-check'; ?>" class="w-4 h-4"></i>
                                                </button>
                                            </form>

                                            <form method="post" onsubmit="return confirmDeleteProduct('<?php echo ap_h(addslashes($row['nama_produk'])); ?>')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="form_token" value="<?php echo ap_h($airProdukFormToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit"
                                                    class="action-btn action-btn-delete"
                                                    title="Hapus produk" aria-label="Hapus produk">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="lg:hidden">
                <?php if (!$rows): ?>
                    <div class="py-16 text-center text-xs font-black uppercase tracking-widest text-gray-300">
                        Produk tidak ditemukan
                    </div>
                <?php else: ?>
                    <div class="mobile-grid grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($rows as $row): ?>
                            <?php $margin = (float)$row['harga_tagihan'] - (float)$row['harga_vendor']; ?>
                            <article class="mobile-card p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-black leading-5"><?php echo ap_h($row['nama_produk']); ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono mt-1"><?php echo ap_h($row['kode_produk']); ?></p>
                                    </div>

                                    <?php if ($row['status'] === 'aktif'): ?>
                                        <span class="status-badge border-green-200 bg-green-50 text-green-700">Aktif</span>
                                    <?php else: ?>
                                        <span class="status-badge border-red-200 bg-red-50 text-red-700">Nonaktif</span>
                                    <?php endif; ?>
                                </div>

                                <div class="grid grid-cols-2 gap-2 mt-4">
                                    <div class="border border-[#f0f0f0] bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Harga Vendor</p>
                                        <p class="text-sm font-black mt-1"><?php echo ap_rupiah($row['harga_vendor']); ?></p>
                                    </div>

                                    <div class="border border-[#f0f0f0] bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Harga Tagihan</p>
                                        <p class="text-sm font-black text-blue-600 mt-1"><?php echo ap_rupiah($row['harga_tagihan']); ?></p>
                                    </div>
                                </div>

                                <div class="mt-2 border border-[#f0f0f0] p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Jenis / Satuan</p>
                                            <p class="text-xs font-bold mt-1">
                                                <?php echo ap_h(ucwords(str_replace('_', ' ', $row['jenis']))); ?> · <?php echo ap_h($row['satuan']); ?>
                                            </p>
                                        </div>

                                        <div class="text-right">
                                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Selisih</p>
                                            <p class="text-xs font-black mt-1 <?php echo $margin >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                                <?php echo ap_rupiah($margin); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2 border border-[#f0f0f0] bg-gray-50 p-3">
                                    <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Terakhir Update</p>
                                    <p class="text-[10px] font-bold text-gray-600 mt-1">
                                        <?php echo ap_h(ap_tanggal_update($row['updated_at'] ?? null, $row['created_at'] ?? null)); ?>
                                    </p>
                                </div>

                                <div class="card-action-grid">
                                    <button type="button"
                                        onclick='editProduct(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="card-action-btn card-action-edit">
                                        <i data-lucide="pencil" class="w-4 h-4"></i>
                                        <span>Edit</span>
                                    </button>

                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="form_token" value="<?php echo ap_h($airProdukFormToken); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="status" value="<?php echo $row['status'] === 'aktif' ? 'nonaktif' : 'aktif'; ?>">

                                        <button type="submit"
                                            class="card-action-btn card-action-toggle <?php echo $row['status'] === 'aktif' ? '' : 'is-inactive'; ?>">
                                            <i data-lucide="<?php echo $row['status'] === 'aktif' ? 'circle-off' : 'circle-check'; ?>" class="w-4 h-4"></i>
                                            <span><?php echo $row['status'] === 'aktif' ? 'Nonaktif' : 'Aktifkan'; ?></span>
                                        </button>
                                    </form>

                                    <form method="post"
                                        onsubmit="return confirmDeleteProduct('<?php echo ap_h(addslashes($row['nama_produk'])); ?>')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="form_token" value="<?php echo ap_h($airProdukFormToken); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                        <button type="submit"
                                            class="card-action-btn card-action-delete">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            <span>Hapus</span>
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pagination-bar px-4 md:px-5 py-4 border-t border-[#f0f0f0] flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50">
                <span class="text-xs text-gray-400">
                    Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
                    (<?php echo number_format($totalRows); ?> total · <?php echo $perPage; ?>/hal)
                </span>

                <div class="flex flex-wrap gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo ap_h(ap_page_url($baseQuery, $page - 1)); ?>"
                            class="px-3 py-2 text-xs font-bold border border-[#f0f0f0] bg-white">
                            &larr; Prev
                        </a>
                    <?php endif; ?>

                    <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                        <a href="<?php echo ap_h(ap_page_url($baseQuery, $pg)); ?>"
                            class="px-3 py-2 text-xs font-bold <?php echo $pg === $page ? 'bg-black text-white' : 'border border-[#f0f0f0] bg-white'; ?>">
                            <?php echo $pg; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo ap_h(ap_page_url($baseQuery, $page + 1)); ?>"
                            class="px-3 py-2 text-xs font-bold border border-[#f0f0f0] bg-white">
                            Next &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
    </main>

    <div id="productModal" class="modal-wrap fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel w-full max-w-2xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-[#f0f0f0]">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Master Produk Air Mineral</p>
                    <h2 id="modalTitle" class="text-base font-black mt-1">Tambah Produk</h2>
                </div>

                <button type="button" onclick="closeProductModal()" class="p-2 hover:bg-gray-100">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form method="post" onsubmit="return validateProductPrice()">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="form_token" value="<?php echo ap_h($airProdukFormToken); ?>">
                <input type="hidden" name="form_mode" id="formMode" value="create">
                <input type="hidden" name="id" id="formId">

                <div class="modal-body max-h-[72vh] overflow-y-auto px-5 md:px-7 py-6 space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                                Kode Produk *
                            </label>
                            <input type="text" name="kode_produk" id="formKode" required class="field" placeholder="Contoh: REFILL-19L">
                        </div>

                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                                Satuan *
                            </label>
                            <select name="satuan" id="formSatuan" class="field" required>
                                <option value="galon">Galon</option>
                                <option value="dus">Dus</option>
                                <option value="botol">Botol</option>
                                <option value="unit">Unit</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                            Nama Produk *
                        </label>
                        <input type="text" name="nama_produk" id="formNama" required class="field" placeholder="Nama lengkap produk">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                            Jenis Produk
                        </label>
                        <input type="text" name="jenis" id="formJenis" class="field" placeholder="Contoh: refill_galon">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                                Harga Vendor
                            </label>
                            <input type="number" name="harga_vendor" id="formHargaVendor" min="0" class="field" placeholder="0" oninput="updateMarginPreview()">
                            <p class="text-[9px] text-gray-400 mt-1">Harga beli dari vendor per satuan.</p>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                                Harga Tagihan Kantor
                            </label>
                            <input type="number" name="harga_tagihan" id="formHargaTagihan" min="0" class="field" placeholder="0" oninput="updateMarginPreview()">
                            <p class="text-[9px] text-gray-400 mt-1">Harga yang digunakan pada kwitansi.</p>
                        </div>
                    </div>

                    <div class="border border-blue-100 bg-blue-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-blue-700">Selisih Harga</p>
                        <p id="marginPreview" class="text-xl font-black text-blue-700 mt-1">Rp 0</p>
                        <p class="text-[9px] text-blue-600 mt-1">Harga tagihan dikurangi harga vendor.</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">
                            Status
                        </label>
                        <select name="status" id="formStatus" class="field">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="px-5 md:px-7 py-5 border-t border-[#f0f0f0] bg-gray-50 flex gap-3">
                    <button type="button"
                        onclick="closeProductModal()"
                        class="flex-1 py-3 text-xs font-bold uppercase border border-[#f0f0f0] bg-white">
                        Batal
                    </button>

                    <button type="submit"
                        class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white">
                        Simpan Produk
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function formatRupiah(value) {
            return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
        }

        function resetProductForm() {
            document.getElementById('formId').value = '';
            document.getElementById('formMode').value = 'create';
            document.getElementById('formKode').value = '';
            document.getElementById('formNama').value = '';
            document.getElementById('formJenis').value = '';
            document.getElementById('formSatuan').value = 'galon';
            document.getElementById('formHargaVendor').value = 0;
            document.getElementById('formHargaTagihan').value = 0;
            document.getElementById('formStatus').value = 'aktif';
            updateMarginPreview();
        }

        function openProductModal() {
            resetProductForm();
            document.getElementById('modalTitle').textContent = 'Tambah Produk';

            var modal = document.getElementById('productModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function editProduct(product) {
            document.getElementById('formId').value = product.id || '';
            document.getElementById('formMode').value = 'edit';
            document.getElementById('formKode').value = product.kode_produk || '';
            document.getElementById('formNama').value = product.nama_produk || '';
            document.getElementById('formJenis').value = product.jenis || '';
            document.getElementById('formSatuan').value = product.satuan || 'unit';
            document.getElementById('formHargaVendor').value = Number(product.harga_vendor || 0);
            document.getElementById('formHargaTagihan').value = Number(product.harga_tagihan || 0);
            document.getElementById('formStatus').value = product.status || 'aktif';
            document.getElementById('modalTitle').textContent = 'Edit Produk';

            updateMarginPreview();

            var modal = document.getElementById('productModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeProductModal() {
            var modal = document.getElementById('productModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        function confirmDeleteProduct(name) {
            return confirm(
                'Hapus produk "' + name + '"?\n\n' +
                'Jika produk sudah pernah digunakan, sistem akan menonaktifkannya agar riwayat tetap aman.'
            );
        }

        function validateProductPrice() {
            var form = document.querySelector('#productModal form');
            var submitButton = form ? form.querySelector('button[type="submit"]') : null;
            var vendor = Number(document.getElementById('formHargaVendor').value || 0);
            var tagihan = Number(document.getElementById('formHargaTagihan').value || 0);

            if (tagihan > 0 && vendor > 0 && tagihan < vendor) {
                return confirm(
                    'Harga tagihan lebih kecil daripada harga vendor.\n\n' +
                    'Produk ini akan menghasilkan selisih negatif. Tetap simpan?'
                );
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Menyimpan...';
            }

            return true;
        }

        function updateMarginPreview() {
            var vendor = Number(document.getElementById('formHargaVendor').value || 0);
            var tagihan = Number(document.getElementById('formHargaTagihan').value || 0);
            var margin = tagihan - vendor;
            var preview = document.getElementById('marginPreview');

            preview.textContent = formatRupiah(margin);
            preview.className = margin >= 0 ?
                'text-xl font-black text-green-700 mt-1' :
                'text-xl font-black text-red-700 mt-1';
        }

        document.getElementById('productModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeProductModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeProductModal();
            }
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>

</html>