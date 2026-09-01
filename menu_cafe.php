<?php
/*
|--------------------------------------------------------------------------
| menu_cafe.php — Kelola Menu Cafe
|--------------------------------------------------------------------------
| - Compatible PHP 7 & 8
| - Menggunakan tabel produk yang sama dengan POS toko
| - Memisahkan menu cafe melalui kolom tipe_produk
| - CRUD tambah, edit, aktif/nonaktif, dan hapus
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'menu_cafe';
$pageTitle  = 'Menu Cafe';
$backUrl    = 'dashboard.php';

if (!function_exists('mc_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function mc_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mc_rupiah')) {
    /**
     * @param mixed $value
     * @return string
     */
    function mc_rupiah($value)
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}


if (!function_exists('mc_normalize_category_name')) {
    /**
     * @param mixed $value
     * @return string
     */
    function mc_normalize_category_name($value)
    {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/', ' ', $value);

        if ($value === '') {
            return '';
        }

        $lower = mb_strtolower($value, 'UTF-8');

        $fixed = [
            'makanan' => 'Makanan',
            'minuman' => 'Minuman',
            'snack'   => 'Snack',
            'es krim' => 'Es Krim',
        ];

        if (isset($fixed[$lower])) {
            return $fixed[$lower];
        }

        return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
    }
}

if (!function_exists('mc_has_column')) {
    /**
     * @param PDO $pdo
     * @param string $table
     * @param string $column
     * @return bool
     */
    function mc_has_column(PDO $pdo, $table, $column)
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
                ':table_name'  => (string)$table,
                ':column_name' => (string)$column,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mc_first_column')) {
    /**
     * @param PDO $pdo
     * @param string $table
     * @param array<int,string> $candidates
     * @param string $fallback
     * @return string
     */
    function mc_first_column(PDO $pdo, $table, array $candidates, $fallback = '')
    {
        foreach ($candidates as $candidate) {
            if (mc_has_column($pdo, $table, $candidate)) {
                return $candidate;
            }
        }
        return $fallback;
    }
}


if (!function_exists('mc_generate_next_cafe_code')) {
    /**
     * @param PDO $pdo
     * @param string $kodeCol
     * @param string $idCol
     * @return string
     */
    function mc_generate_next_cafe_code(PDO $pdo, $kodeCol, $idCol)
    {
        $stmt = $pdo->query("
            SELECT `$kodeCol`
            FROM produk
            WHERE tipe_produk IN ('makanan','minuman','topping','cafe')
              AND `$kodeCol` REGEXP '^CDS[0-9]+$'
            ORDER BY CAST(SUBSTRING(`$kodeCol`, 4) AS UNSIGNED) DESC, `$idCol` DESC
            LIMIT 1
        ");

        $lastCode = $stmt ? (string)$stmt->fetchColumn() : '';
        $nextNumber = 1;

        if (preg_match('/^CDS(\d+)$/i', $lastCode, $m)) {
            $nextNumber = ((int)$m[1]) + 1;
        }

        return 'CDS' . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('mc_normalize_existing_cafe_codes_once')) {
    /**
     * Nomori ulang SEMUA data menu cafe lama berdasarkan ID:
     * CDS001, CDS002, CDS003, dan seterusnya.
     *
     * @param PDO $pdo
     * @param string $kodeCol
     * @param string $idCol
     * @return void
     */
    function mc_normalize_existing_cafe_codes_once(PDO $pdo, $kodeCol, $idCol)
    {
        $stmt = $pdo->query("
            SELECT `$idCol` AS id
            FROM produk
            WHERE tipe_produk IN ('makanan','minuman','topping','cafe')
            ORDER BY `$idCol` ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        if (!$rows) {
            return;
        }

        /*
         * Pakai kode sementara terlebih dahulu agar tidak bentrok
         * bila kolom kode memiliki UNIQUE index.
         */
        $temp = $pdo->prepare("
            UPDATE produk
            SET `$kodeCol` = :kode
            WHERE `$idCol` = :id
        ");

        foreach ($rows as $index => $row) {
            $temp->execute([
                ':kode' => '__CAFE_TMP_' . (int)$row['id'] . '__',
                ':id'   => (int)$row['id'],
            ]);
        }

        $update = $pdo->prepare("
            UPDATE produk
            SET `$kodeCol` = :kode
            WHERE `$idCol` = :id
        ");

        $number = 1;
        foreach ($rows as $row) {
            $update->execute([
                ':kode' => 'CDS' . str_pad((string)$number, 3, '0', STR_PAD_LEFT),
                ':id'   => (int)$row['id'],
            ]);
            $number++;
        }
    }
}

try {
    if (!mc_has_column($pdo, 'produk', 'tipe_produk')) {
        $pdo->exec("
            ALTER TABLE produk
            ADD COLUMN tipe_produk VARCHAR(30) NOT NULL DEFAULT 'retail'
        ");
    }
} catch (Throwable $e) {
    // Halaman tetap berjalan; pesan struktur akan ditampilkan jika query gagal.
}

$idCol       = mc_first_column($pdo, 'produk', ['id', 'produk_id'], 'id');
$kodeCol     = mc_first_column($pdo, 'produk', ['kode', 'kode_produk', 'barcode'], 'kode');
$namaCol     = mc_first_column($pdo, 'produk', ['nama', 'nama_produk'], 'nama');
$kategoriCol = mc_first_column($pdo, 'produk', ['kategori', 'nama_kategori'], 'kategori');
$jualCol     = mc_first_column($pdo, 'produk', ['harga_jual', 'harga', 'harga_satuan'], 'harga_jual');
$beliCol     = mc_first_column($pdo, 'produk', ['harga_beli', 'harga_modal', 'harga_pokok', 'hpp'], '');
$stokCol     = mc_first_column($pdo, 'produk', ['stok', 'stock'], 'stok');
$satuanCol   = mc_first_column($pdo, 'produk', ['satuan', 'unit'], '');
$statusCol   = mc_first_column($pdo, 'produk', ['status', 'aktif'], 'status');
$gambarCol   = mc_first_column($pdo, 'produk', ['gambar', 'foto', 'image'], '');

try {
    mc_normalize_existing_cafe_codes_once($pdo, $kodeCol, $idCol);
} catch (Throwable $e) {
    // Data cafe lama dinomori ulang CDS001 dst berdasarkan ID. Halaman tetap berjalan bila proses gagal.
}


try {
    $stmtNormalizeCategories = $pdo->query("
        SELECT `$idCol` AS id, `$kategoriCol` AS kategori
        FROM produk
        WHERE tipe_produk IN ('makanan','minuman','topping','cafe')
          AND `$kategoriCol` IS NOT NULL
          AND TRIM(`$kategoriCol`) <> ''
    ");

    $normalizeCategoryUpdate = $pdo->prepare("
        UPDATE produk
        SET `$kategoriCol` = :kategori
        WHERE `$idCol` = :id
    ");

    foreach (($stmtNormalizeCategories ? $stmtNormalizeCategories->fetchAll(PDO::FETCH_ASSOC) : []) as $categoryRow) {
        $oldCategory = trim((string)($categoryRow['kategori'] ?? ''));
        $newCategory = mc_normalize_category_name($oldCategory);

        if ($newCategory !== '' && $newCategory !== $oldCategory) {
            $normalizeCategoryUpdate->execute([
                ':kategori' => $newCategory,
                ':id'       => (int)$categoryRow['id'],
            ]);
        }
    }
} catch (Throwable $e) {
    // Halaman tetap berjalan bila normalisasi kategori lama gagal.
}

$allowedTypes = ['cafe'];

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $kode      = trim((string)($_POST['kode'] ?? ''));
            $nama      = trim((string)($_POST['nama'] ?? ''));

            if ($id <= 0) {
                $kode = mc_generate_next_cafe_code($pdo, $kodeCol, $idCol);
            } elseif ($kode === '') {
                $stmtExistingCode = $pdo->prepare("SELECT `$kodeCol` FROM produk WHERE `$idCol` = :id LIMIT 1");
                $stmtExistingCode->execute([':id' => $id]);
                $kode = trim((string)$stmtExistingCode->fetchColumn());
            }
            $kategori  = trim((string)($_POST['kategori'] ?? ''));
            $kategoriBaru = trim((string)($_POST['kategori_baru'] ?? ''));

            if ($kategori === '__baru__') {
                $kategori = $kategoriBaru;
            }

            $kategori = mc_normalize_category_name($kategori);

            if ($kategori === '') {
                throw new RuntimeException('Kategori menu wajib dipilih atau diisi.');
            }

            if (mb_strlen($kategori) > 80) {
                throw new RuntimeException('Nama kategori terlalu panjang.');
            }

            $tipe      = 'cafe';
            $hargaJual = (float)preg_replace('/[^0-9]/', '', (string)($_POST['harga_jual'] ?? 0));
            $hargaBeli = (float)preg_replace('/[^0-9]/', '', (string)($_POST['harga_beli'] ?? 0));

            // Menu Cafe dibuat saat dipesan. Stok menu jadi tidak digunakan.
            // Nilai stok tetap disimpan 0 agar kompatibel dengan tabel produk.
            $stok      = 0;
            $satuan    = trim((string)($_POST['satuan'] ?? 'porsi'));
            $status    = trim((string)($_POST['status'] ?? 'aktif'));

            if ($nama === '') {
                throw new RuntimeException('Nama menu wajib diisi.');
            }
            if ($kode === '') {
                throw new RuntimeException('Kode menu otomatis gagal dibuat.');
            }

            if (!in_array($tipe, $allowedTypes, true)) {
                $tipe = 'cafe';
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                $status = 'aktif';
            }

            $fields = [
                "`$kodeCol`"     => ':kode',
                "`$namaCol`"     => ':nama',
                "`$kategoriCol`" => ':kategori',
                "`$jualCol`"     => ':harga_jual',
                "`$stokCol`"     => ':stok',
                "`$statusCol`"   => ':status',
                "`tipe_produk`"  => ':tipe_produk',
            ];

            $params = [
                ':kode'         => $kode,
                ':nama'         => $nama,
                ':kategori'     => $kategori,
                ':harga_jual'   => $hargaJual,
                ':stok'         => $stok,
                ':status'       => $status,
                ':tipe_produk'  => $tipe,
            ];

            if ($beliCol !== '') {
                $fields["`$beliCol`"] = ':harga_beli';
                $params[':harga_beli'] = $hargaBeli;
            }

            if ($satuanCol !== '') {
                $fields["`$satuanCol`"] = ':satuan';
                $params[':satuan'] = $satuan;
            }

            if ($id > 0) {
                $set = [];
                foreach ($fields as $field => $placeholder) {
                    $set[] = $field . '=' . $placeholder;
                }
                $params[':id'] = $id;

                $stmt = $pdo->prepare("
                    UPDATE produk
                    SET " . implode(', ', $set) . "
                    WHERE `$idCol` = :id
                ");
                $stmt->execute($params);
                $flash = 'Menu cafe berhasil diperbarui.';
            } else {
                $columns = array_keys($fields);
                $values  = array_values($fields);

                $stmt = $pdo->prepare("
                    INSERT INTO produk (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $values) . ")
                ");
                $stmt->execute($params);
                $flash = 'Menu cafe berhasil ditambahkan.';
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $current = trim((string)($_POST['current_status'] ?? 'aktif'));
            $next = $current === 'aktif' ? 'nonaktif' : 'aktif';

            $stmt = $pdo->prepare("
                UPDATE produk
                SET `$statusCol` = :status
                WHERE `$idCol` = :id
            ");
            $stmt->execute([
                ':status' => $next,
                ':id'     => $id,
            ]);
            $flash = 'Status menu berhasil diperbarui.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM produk
                WHERE `$idCol` = :id
                  AND tipe_produk IN ('makanan','minuman','topping','cafe')
            ");
            $stmt->execute([':id' => $id]);
            $flash = 'Menu cafe berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$filterType = strtolower(trim((string)($_GET['tipe'] ?? '')));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));
$filterCategory = mc_normalize_category_name($_GET['kategori_filter'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = ["tipe_produk IN ('makanan','minuman','topping','cafe')"];
$params = [];

if ($q !== '') {
    /*
     * Gunakan placeholder berbeda untuk setiap LIKE.
     * Beberapa konfigurasi PDO/MySQL tidak mengizinkan satu named
     * placeholder dipakai berulang dalam prepared statement dan
     * akan memunculkan SQLSTATE[HY093].
     */
    $where[] = "(
        `$kodeCol` LIKE :q_kode
        OR `$namaCol` LIKE :q_nama
        OR `$kategoriCol` LIKE :q_kategori
    )";

    $searchValue = '%' . $q . '%';
    $params[':q_kode'] = $searchValue;
    $params[':q_nama'] = $searchValue;
    $params[':q_kategori'] = $searchValue;
}

if ($filterCategory !== '') {
    $where[] = "LOWER(TRIM(`$kategoriCol`)) = :kategori_filter";
    $params[':kategori_filter'] = mb_strtolower($filterCategory, 'UTF-8');
}

if (in_array($filterStatus, ['aktif', 'nonaktif'], true)) {
    $where[] = "`$statusCol` = :status";
    $params[':status'] = $filterStatus;
}

$select = [
    "`$idCol` AS id",
    "`$kodeCol` AS kode",
    "`$namaCol` AS nama",
    "`$kategoriCol` AS kategori",
    "`$jualCol` AS harga_jual",
    "`$stokCol` AS stok",
    "`$statusCol` AS status",
    "tipe_produk",
];

if ($beliCol !== '') {
    $select[] = "`$beliCol` AS harga_beli";
} else {
    $select[] = "0 AS harga_beli";
}

if ($satuanCol !== '') {
    $select[] = "`$satuanCol` AS satuan";
} else {
    $select[] = "'porsi' AS satuan";
}

if ($gambarCol !== '') {
    $select[] = "`$gambarCol` AS gambar";
} else {
    $select[] = "'' AS gambar";
}

$menus = [];
$totalRows = 0;
$totalPages = 1;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM produk WHERE " . implode(' AND ', $where));
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM produk WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN `$kodeCol` REGEXP '^CDS[0-9]+$' THEN 0 ELSE 1 END, CAST(SUBSTRING(`$kodeCol`, 4) AS UNSIGNED) ASC, `$idCol` ASC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
    $stmt->execute($params);
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat menu cafe: ' . $e->getMessage();
        $flashType = 'error';
    }
}
$paginationQuery = $_GET;
unset($paginationQuery['page']);
if (!function_exists('mc_page_url')) {
    /**
     * @param int $pageNumber
     * @param array<string,mixed> $query
     * @return string
     */
    function mc_page_url($pageNumber, array $query)
    {
        $query['page'] = max(1, (int)$pageNumber);
        return 'menu_cafe.php?' . http_build_query($query);
    }
}

$defaultCategories = ['Makanan', 'Minuman', 'Snack', 'Es Krim'];
$existingCategoriesMap = [];

foreach ($defaultCategories as $categoryName) {
    $normalized = mc_normalize_category_name($categoryName);
    $existingCategoriesMap[mb_strtolower($normalized, 'UTF-8')] = $normalized;
}

try {
    $stmtCategories = $pdo->query("
        SELECT DISTINCT TRIM(`$kategoriCol`) AS kategori
        FROM produk
        WHERE tipe_produk IN ('makanan','minuman','topping','cafe')
          AND `$kategoriCol` IS NOT NULL
          AND TRIM(`$kategoriCol`) <> ''
        ORDER BY kategori ASC
    ");

    if ($stmtCategories) {
        foreach ($stmtCategories->fetchAll(PDO::FETCH_COLUMN) as $categoryName) {
            $normalized = mc_normalize_category_name($categoryName);
            if ($normalized !== '') {
                $existingCategoriesMap[mb_strtolower($normalized, 'UTF-8')] = $normalized;
            }
        }
    }
} catch (Throwable $e) {
    // Tetap pakai kategori bawaan.
}

$existingCategories = array_values($existingCategoriesMap);
sort($existingCategories, SORT_NATURAL | SORT_FLAG_CASE);

$nextCafeCode = 'CDS001';
try {
    $nextCafeCode = mc_generate_next_cafe_code($pdo, $kodeCol, $idCol);
} catch (Throwable $e) {
    $nextCafeCode = 'CDS001';
}

$summaryMenu = [
    'aktif'   => 0,
    'makanan' => 0,
    'minuman' => 0,
    'snack'   => 0,
    'es_krim' => 0,
];

try {
    $stmtSummary = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN `$statusCol` = 'aktif' THEN 1 ELSE 0 END), 0) AS aktif,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(`$kategoriCol`)) = 'makanan' THEN 1 ELSE 0 END), 0) AS makanan,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(`$kategoriCol`)) = 'minuman' THEN 1 ELSE 0 END), 0) AS minuman,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(`$kategoriCol`)) = 'snack' THEN 1 ELSE 0 END), 0) AS snack,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(`$kategoriCol`)) = 'es krim' THEN 1 ELSE 0 END), 0) AS es_krim
        FROM produk
        WHERE tipe_produk = 'cafe'
    ");
    $summaryRow = $stmtSummary ? $stmtSummary->fetch(PDO::FETCH_ASSOC) : [];

    foreach ($summaryMenu as $key => $value) {
        if (isset($summaryRow[$key])) {
            $summaryMenu[$key] = (int)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
    // Ringkasan tetap nol bila query gagal.
}

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111827;
        }

        .menu-main {
            min-height: calc(100vh - 64px);
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0;
            box-shadow: none;
        }

        .field {
            width: 100%;
            height: 42px;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 0;
        }

        textarea.field {
            height: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .btn {
            height: 42px;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            vertical-align: middle;
            box-sizing: border-box;
        }

        .table-action-form {
            margin: 0;
            padding: 0;
            display: inline-flex;
            align-items: center;
        }

        .table-action-btn {
            height: 38px;
            min-height: 38px;
            padding: 0 12px;
            min-width: 72px;
        }

        .table-action-btn.toggle-btn {
            min-width: 108px;
        }

        .pagination-btn {
            width: 38px;
            height: 38px;
            min-height: 38px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            box-sizing: border-box;
        }

        .pagination-btn.pagination-wide {
            width: auto;
            min-width: 62px;
            padding: 0 12px;
        }


        .menu-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }

        .menu-header-copy {
            min-width: 0;
        }

        .menu-add-btn {
            min-width: 132px;
            flex-shrink: 0;
        }

        .menu-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .menu-summary-card {
            min-height: 96px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #fff;
            border: 1px solid #eef0f3;
        }

        .menu-summary-label {
            font-size: 8px;
            line-height: 1.2;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #94a3b8;
        }

        .menu-summary-value {
            margin-top: 10px;
            font-size: 24px;
            line-height: 1;
            font-weight: 900;
            color: #111827;
        }

        .menu-summary-card.active .menu-summary-label,
        .menu-summary-card.active .menu-summary-value {
            color: #059669;
        }

        .menu-summary-card.food .menu-summary-label {
            color: #b45309;
        }

        .menu-summary-card.drink .menu-summary-label {
            color: #2563eb;
        }

        .menu-summary-card.snack .menu-summary-label {
            color: #7c3aed;
        }

        .menu-summary-card.ice .menu-summary-label {
            color: #db2777;
        }

        @media (max-width:1023px) {
            .menu-summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .menu-header {
                align-items: stretch;
            }
        }

        @media (max-width:640px) {
            .menu-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                margin-bottom: 16px;
            }

            .menu-add-btn {
                width: 100%;
                min-width: 0;
            }

            .menu-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 16px;
            }

            .menu-summary-card {
                min-height: 84px;
                padding: 12px;
            }

            .menu-summary-value {
                font-size: 21px;
            }

            .menu-summary-card.active {
                grid-column: 1 / -1;
            }
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border: 1px solid;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        @media (min-width:1024px) {
            .menu-main {
                margin-left: 220px;
            }
        }

        @media (max-width:1023px) {
            .menu-main {
                margin-left: 0;
                padding-bottom: 90px !important;
            }
        }

        @media (min-width:1024px) and (max-width:1279px) {
            .table-action-btn {
                min-width: 64px;
                padding-left: 10px;
                padding-right: 10px;
            }

            .table-action-btn.toggle-btn {
                min-width: 96px;
            }
        }

        @media (max-width:640px) {
            .pagination-btn {
                width: 36px;
                height: 36px;
                min-height: 36px;
            }

            .pagination-btn.pagination-wide {
                width: auto;
                min-width: 58px;
                padding: 0 10px;
            }
        }
    </style>
</head>

<body>
    <main class="menu-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 px-4 py-3 border text-xs font-bold <?php echo $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'; ?>">
                <?php echo mc_h($flash); ?>
            </div>
        <?php endif; ?>

        <div class="menu-header">
            <div class="menu-header-copy">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-gray-400">Operasional Cafe</p>
                <h1 class="text-2xl font-black mt-1">Kelola Menu Cafe</h1>
                <p class="text-xs text-gray-400 mt-1">Menu aktif otomatis tersedia pada POS Cafe. Stok menu jadi tidak digunakan karena makanan/minuman dibuat saat dipesan.</p>
            </div>
            <button type="button" onclick="openMenuModal()" class="btn menu-add-btn bg-black text-white px-5 hover:bg-gray-800">
                + Tambah Menu
            </button>
        </div>

        <div class="menu-summary-grid">
            <div class="menu-summary-card active">
                <p class="menu-summary-label">Menu Aktif</p>
                <p class="menu-summary-value"><?php echo number_format($summaryMenu['aktif']); ?></p>
            </div>
            <div class="menu-summary-card food">
                <p class="menu-summary-label">Makanan</p>
                <p class="menu-summary-value"><?php echo number_format($summaryMenu['makanan']); ?></p>
            </div>
            <div class="menu-summary-card drink">
                <p class="menu-summary-label">Minuman</p>
                <p class="menu-summary-value"><?php echo number_format($summaryMenu['minuman']); ?></p>
            </div>
            <div class="menu-summary-card snack">
                <p class="menu-summary-label">Snack</p>
                <p class="menu-summary-value"><?php echo number_format($summaryMenu['snack']); ?></p>
            </div>
            <div class="menu-summary-card ice">
                <p class="menu-summary-label">Es Krim</p>
                <p class="menu-summary-value"><?php echo number_format($summaryMenu['es_krim']); ?></p>
            </div>
        </div>

        <form method="get" id="menuFilterForm" class="card p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2 relative">
                    <input type="search"
                        id="menuSearch"
                        value=""
                        placeholder="Cari kode, nama, kategori..."
                        class="field pr-10"
                        autocomplete="off">
                    <span id="menuSearchLoader"
                        class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-black uppercase tracking-widest text-gray-400">
                        Cari...
                    </span>
                </div>
                <select name="kategori_filter" class="field">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($existingCategories as $categoryName): ?>
                        <option value="<?php echo mc_h($categoryName); ?>" <?php echo (mb_strtolower((string)($_GET['kategori_filter'] ?? ''), 'UTF-8') === mb_strtolower($categoryName, 'UTF-8')) ? 'selected' : ''; ?>>
                            <?php echo mc_h(ucwords($categoryName)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="field">
                    <option value="">Semua Status</option>
                    <option value="aktif" <?php echo $filterStatus === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="nonaktif" <?php echo $filterStatus === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 mt-3">
                <button type="submit" class="btn bg-black text-white px-5">Terapkan</button>
                <a href="menu_cafe.php" class="btn border border-gray-200 bg-white text-gray-600 px-5 inline-flex items-center justify-center">Reset</a>
            </div>
        </form>

        <div class="hidden lg:block card overflow-x-auto">
            <table class="w-full min-w-[980px] text-left">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Kode</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Menu</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Jenis</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-right">Harga</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody id="menuTableBody" class="divide-y divide-gray-100">
                    <?php foreach ($menus as $menu): ?>
                        <tr class="menu-search-item"
                            data-search="<?php echo mc_h(strtolower(
                                                (string)$menu['kode'] . ' ' .
                                                    (string)$menu['nama'] . ' ' .
                                                    (string)$menu['kategori'] . ' ' .
                                                    (string)$menu['tipe_produk'] . ' ' .
                                                    (string)$menu['status']
                                            )); ?>">
                            <td class="px-5 py-4 text-xs font-mono font-bold"><?php echo mc_h($menu['kode']); ?></td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-black"><?php echo mc_h($menu['nama']); ?></p>
                                <p class="text-[10px] text-gray-400 mt-0.5"><?php echo mc_h($menu['kategori'] ?: '-'); ?></p>
                            </td>
                            <td class="px-5 py-4 text-xs font-bold uppercase"><?php echo mc_h($menu['tipe_produk']); ?></td>
                            <td class="px-5 py-4 text-xs font-black text-right"><?php echo mc_rupiah($menu['harga_jual']); ?></td>
                                <td class="px-5 py-4 text-center">
                                <span class="badge <?php echo $menu['status'] === 'aktif' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-100 border-gray-200 text-gray-600'; ?>">
                                    <?php echo mc_h($menu['status']); ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end items-center gap-1.5 whitespace-nowrap h-[38px]">
                                    <button type="button" onclick='editMenu(<?php echo json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn table-action-btn border border-gray-200 bg-white">Edit</button>
                                    <form method="post" class="table-action-form">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo mc_h($menu['status']); ?>">
                                        <button type="submit" class="btn table-action-btn toggle-btn border border-gray-200 bg-white"><?php echo $menu['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                    </form>
                                    <form method="post" class="table-action-form" onsubmit="return confirm('Hapus menu ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                                        <button type="submit" class="btn table-action-btn bg-red-600 text-white">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($menus)): ?>
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-xs font-bold text-gray-400 uppercase tracking-widest">
                                Belum ada menu cafe
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr id="desktopSearchEmpty" class="hidden">
                        <td colspan="6" class="px-5 py-12 text-center text-xs font-bold text-gray-400 uppercase tracking-widest">
                            Menu tidak ditemukan
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="menuMobileList" class="lg:hidden grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($menus as $menu): ?>
                <div class="card p-4 menu-search-item"
                    data-search="<?php echo mc_h(strtolower(
                                        (string)$menu['kode'] . ' ' .
                                            (string)$menu['nama'] . ' ' .
                                            (string)$menu['kategori'] . ' ' .
                                            (string)$menu['tipe_produk'] . ' ' .
                                            (string)$menu['status']
                                    )); ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-black truncate"><?php echo mc_h($menu['nama']); ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?php echo mc_h($menu['kode']); ?> · <?php echo mc_h($menu['kategori'] ?: '-'); ?></p>
                        </div>
                        <span class="badge <?php echo $menu['status'] === 'aktif' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-100 border-gray-200 text-gray-600'; ?>">
                            <?php echo mc_h($menu['status']); ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-4 pt-4 border-t border-gray-100">
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Jenis</p>
                            <p class="text-xs font-bold uppercase mt-1"><?php echo mc_h($menu['tipe_produk']); ?></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Harga</p>
                            <p class="text-xs font-bold mt-1"><?php echo mc_rupiah($menu['harga_jual']); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-4 items-stretch">
                        <button type="button" onclick='editMenu(<?php echo json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-gray-200 bg-white">Edit</button>
                        <form method="post" class="h-full">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo mc_h($menu['status']); ?>">
                            <button type="submit" class="btn border border-gray-200 bg-white w-full"><?php echo $menu['status'] === 'aktif' ? 'Nonaktif' : 'Aktif'; ?></button>
                        </form>
                        <form method="post" class="h-full" onsubmit="return confirm('Hapus menu ini?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                            <button type="submit" class="btn bg-red-600 text-white w-full">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <div id="mobileSearchEmpty" class="hidden card p-10 text-center md:col-span-2">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Menu tidak ditemukan</p>
            </div>
        </div>
        <?php if ($totalRows > 0): ?>
            <div class="card mt-4 p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider text-center sm:text-left">Menampilkan <?php echo number_format((($page - 1) * $perPage) + 1); ?>-<?php echo number_format(min($page * $perPage, $totalRows)); ?> dari <?php echo number_format($totalRows); ?> menu</p>
                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-center gap-1 flex-wrap min-h-[38px]">
                        <a class="pagination-btn pagination-wide border border-gray-200 bg-white text-[10px] font-black uppercase tracking-wider <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>" href="<?php echo mc_h(mc_page_url($page - 1, $paginationQuery)); ?>">Prev</a>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                            <a class="pagination-btn text-[10px] font-black <?php echo $p === $page ? 'bg-black text-white border border-black' : 'border border-gray-200 bg-white'; ?>" href="<?php echo mc_h(mc_page_url($p, $paginationQuery)); ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <a class="pagination-btn pagination-wide border border-gray-200 bg-white text-[10px] font-black uppercase tracking-wider <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : ''; ?>" href="<?php echo mc_h(mc_page_url($page + 1, $paginationQuery)); ?>">Next</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <div id="menuModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-2xl bg-white border border-gray-200 max-h-[92vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Form Menu</p>
                    <h2 id="menuModalTitle" class="text-lg font-black mt-1">Tambah Menu Cafe</h2>
                </div>
                <button type="button" onclick="closeMenuModal()" class="w-10 h-10 border border-gray-200 text-xl font-bold">&times;</button>
            </div>

            <form method="post" class="p-5">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="menu-id" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Kode Menu</label>
                        <input type="text" name="kode" id="menu-kode" readonly class="field bg-blue-50 text-blue-700 border-blue-200 cursor-not-allowed" value="<?php echo mc_h($nextCafeCode); ?>">
                        <p class="text-[9px] font-bold text-blue-600 mt-1">Otomatis · tidak dapat diedit.</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Nama Menu</label>
                        <input type="text" name="nama" id="menu-nama" required class="field" placeholder="Es Kopi Susu">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Kategori</label>
                        <select name="kategori" id="menu-kategori" class="field" required onchange="toggleNewCategoryField()">
                            <option value="">Pilih kategori...</option>
                            <?php foreach ($existingCategories as $categoryName): ?>
                                <option value="<?php echo mc_h($categoryName); ?>">
                                    <?php echo mc_h(ucwords($categoryName)); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__baru__">+ Tambah kategori baru...</option>
                        </select>
                        <div id="new-category-wrap" class="hidden mt-2">
                            <input type="text"
                                name="kategori_baru"
                                id="menu-kategori-baru"
                                class="field"
                                maxlength="80"
                                placeholder="Contoh: Dessert, Bakery, Kopi">
                            <p class="text-[9px] text-gray-400 mt-1">Kategori baru akan otomatis muncul di pilihan pada input berikutnya.</p>
                        </div>
                        <p class="text-[9px] text-gray-400 mt-1">Pilih kategori yang tersedia atau tambah kategori baru.</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Jenis</label>
                        <input type="hidden" name="tipe_produk" id="menu-tipe" value="cafe">
                        <input type="text" class="field bg-amber-50 text-amber-700 border-amber-200 cursor-not-allowed" value="Cafe" readonly>
                        <p class="text-[9px] font-bold text-amber-600 mt-1">Otomatis · khusus POS Cafe.</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Harga Beli / Modal</label>
                        <input type="text" name="harga_beli" id="menu-harga-beli" inputmode="numeric" class="field" placeholder="10000">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Harga Jual</label>
                        <input type="text" name="harga_jual" id="menu-harga-jual" inputmode="numeric" required class="field" placeholder="15000">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Satuan</label>
                        <input type="text" name="satuan" id="menu-satuan" class="field" value="porsi" placeholder="porsi / gelas / cup">
                        <p class="text-[9px] text-gray-400 mt-1">Stok menu jadi tidak digunakan. Persediaan bahan baku dapat dikelola terpisah.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Status</label>
                        <select name="status" id="menu-status" class="field">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-6">
                    <button type="button" onclick="closeMenuModal()" class="btn border border-gray-200 bg-white">Batal</button>
                    <button type="submit" class="btn bg-black text-white">Simpan Menu</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleNewCategoryField() {
            var select = document.getElementById('menu-kategori');
            var wrap = document.getElementById('new-category-wrap');
            var input = document.getElementById('menu-kategori-baru');

            if (!select || !wrap || !input) return;

            if (select.value === '__baru__') {
                wrap.classList.remove('hidden');
                input.required = true;
                setTimeout(function() {
                    input.focus();
                }, 50);
            } else {
                wrap.classList.add('hidden');
                input.required = false;
                input.value = '';
            }
        }

        function openMenuModal() {
            document.getElementById('menuModalTitle').textContent = 'Tambah Menu Cafe';
            document.getElementById('menu-id').value = '0';
            document.getElementById('menu-kode').value = <?php echo json_encode($nextCafeCode); ?>;
            document.getElementById('menu-nama').value = '';
            document.getElementById('menu-kategori').value = '';
            document.getElementById('menu-kategori-baru').value = '';
            toggleNewCategoryField();
            document.getElementById('menu-tipe').value = 'cafe';
            document.getElementById('menu-harga-beli').value = '';
            document.getElementById('menu-harga-jual').value = '';
            document.getElementById('menu-satuan').value = 'porsi';
            document.getElementById('menu-status').value = 'aktif';

            var modal = document.getElementById('menuModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function editMenu(menu) {
            document.getElementById('menuModalTitle').textContent = 'Edit Menu Cafe';
            document.getElementById('menu-id').value = menu.id || 0;
            document.getElementById('menu-kode').value = menu.kode || '';
            document.getElementById('menu-nama').value = menu.nama || '';
            var categorySelect = document.getElementById('menu-kategori');
            var categoryValue = String(menu.kategori || '').trim();
            var matchedOptionValue = '';

            Array.prototype.some.call(categorySelect.options, function(opt) {
                if (String(opt.value || '').toLowerCase() === categoryValue.toLowerCase()) {
                    matchedOptionValue = opt.value;
                    return true;
                }
                return false;
            });

            if (matchedOptionValue !== '') {
                categorySelect.value = matchedOptionValue;
                document.getElementById('menu-kategori-baru').value = '';
            } else if (categoryValue) {
                categorySelect.value = '__baru__';
                document.getElementById('menu-kategori-baru').value = categoryValue;
            } else {
                categorySelect.value = '';
                document.getElementById('menu-kategori-baru').value = '';
            }
            toggleNewCategoryField();
            document.getElementById('menu-tipe').value = 'cafe';
            document.getElementById('menu-harga-beli').value = Number(menu.harga_beli || 0);
            document.getElementById('menu-harga-jual').value = Number(menu.harga_jual || 0);
            document.getElementById('menu-satuan').value = menu.satuan || 'porsi';
            document.getElementById('menu-status').value = menu.status || 'aktif';

            var modal = document.getElementById('menuModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeMenuModal() {
            var modal = document.getElementById('menuModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('menuModal').addEventListener('click', function(event) {
            if (event.target === this) closeMenuModal();
        });

        // SEARCH AJAX: mencari ke seluruh data database tanpa reload halaman.
        (function() {
            var searchInput = document.getElementById('menuSearch');
            var filterForm = document.getElementById('menuFilterForm');
            var tableBody = document.getElementById('menuTableBody');
            var mobileList = document.getElementById('menuMobileList');
            var timer = null;
            var requestController = null;

            if (!searchInput || !filterForm || !tableBody || !mobileList) return;

            function buildSearchUrl() {
                var params = new URLSearchParams();

                var keyword = searchInput.value.trim();
                if (keyword !== '') {
                    params.set('q', keyword);
                }

                var category = filterForm.querySelector('[name="kategori_filter"]');
                var status = filterForm.querySelector('[name="status"]');

                if (category && category.value) {
                    params.set('kategori_filter', category.value);
                }

                if (status && status.value) {
                    params.set('status', status.value);
                }

                // Selalu mulai dari halaman 1 untuk hasil pencarian.
                params.set('page', '1');

                return 'menu_cafe.php?' + params.toString();
            }

            async function runAjaxSearch() {
                if (requestController) {
                    requestController.abort();
                }

                requestController = new AbortController();

                try {
                    var response = await fetch(buildSearchUrl(), {
                        method: 'GET',
                        cache: 'no-store',
                        signal: requestController.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    var html = await response.text();
                    var doc = new DOMParser().parseFromString(html, 'text/html');

                    var newTableBody = doc.getElementById('menuTableBody');
                    var newMobileList = doc.getElementById('menuMobileList');

                    if (!newTableBody || !newMobileList) {
                        throw new Error('Hasil pencarian tidak valid.');
                    }

                    tableBody.innerHTML = newTableBody.innerHTML;
                    mobileList.innerHTML = newMobileList.innerHTML;

                    // Inisialisasi ulang icon bila tersedia.
                    if (window.lucide) {
                        lucide.createIcons();
                    }
                } catch (error) {
                    if (error && error.name === 'AbortError') return;
                    console.error('Pencarian menu gagal:', error);
                }
            }

            function queueAjaxSearch() {
                clearTimeout(timer);
                timer = setTimeout(runAjaxSearch, 250);
            }

            searchInput.addEventListener('input', queueAjaxSearch);
            searchInput.addEventListener('search', function() {
                clearTimeout(timer);
                runAjaxSearch();
            });

            searchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(timer);
                    runAjaxSearch();
                }
            });

            // Filter kategori/status juga langsung refresh hasil tanpa reload.
            var category = filterForm.querySelector('[name="kategori_filter"]');
            var status = filterForm.querySelector('[name="status"]');

            if (category) {
                category.addEventListener('change', runAjaxSearch);
            }

            if (status) {
                status.addEventListener('change', runAjaxSearch);
            }
        })();
    </script>
</body>

</html>