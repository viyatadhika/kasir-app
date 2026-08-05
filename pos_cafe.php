<?php
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'config.php';
if (function_exists('requireAccess')) {
    requireAccess();
}

$activeMenu = 'pos_cafe';
$pageTitle  = 'Kasir Cafe';
$backUrl    = 'dashboard.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('cafe_e')) {
    /** @param mixed $value */
    function cafe_e($value): string
    {
        return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cafe_current_user_id')) {
    function cafe_current_user_id(): int
    {
        foreach (array('user_id', 'id_user', 'id', 'admin_id') as $key) {
            if (!empty($_SESSION[$key])) {
                return (int)$_SESSION[$key];
            }
        }
        if (!empty($_SESSION['user']['id'])) {
            return (int)$_SESSION['user']['id'];
        }
        return 0;
    }
}

if (!function_exists('cafe_current_user_name')) {
    function cafe_current_user_name(): string
    {
        foreach (array('nama', 'name', 'username', 'user_name') as $key) {
            if (!empty($_SESSION[$key])) {
                return (string)$_SESSION[$key];
            }
        }
        if (!empty($_SESSION['user']['nama'])) {
            return (string)$_SESSION['user']['nama'];
        }
        if (!empty($_SESSION['user']['name'])) {
            return (string)$_SESSION['user']['name'];
        }
        if (!empty($_SESSION['user']['username'])) {
            return (string)$_SESSION['user']['username'];
        }
        return 'Kasir Cafe';
    }
}

/**
 * @param string $table
 * @param string $column
 */
function cafe_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
        $stmt->execute(array(':table_name' => $table, ':column_name' => $column));
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cafe_ensure_database(PDO $pdo): void
{
    // Struktur disamakan dengan meja_cafe.php dan dapur.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cafe_meja (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nomor_meja VARCHAR(30) NOT NULL,
        nama_meja VARCHAR(100) NULL,
        kapasitas INT NOT NULL DEFAULT 2,
        lokasi VARCHAR(100) NULL,
        status ENUM('kosong','terisi','reservasi','nonaktif') NOT NULL DEFAULT 'kosong',
        catatan VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_cafe_meja_nomor (nomor_meja),
        INDEX idx_cafe_meja_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cafe_pesanan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaksi_id INT NULL,
        nomor_pesanan VARCHAR(50) NULL,
        meja_id INT NULL,
        tipe_pesanan ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
        status ENUM('baru','diproses','siap','selesai','batal') NOT NULL DEFAULT 'baru',
        catatan TEXT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_cafe_nomor_pesanan (nomor_pesanan),
        INDEX idx_cafe_pesanan_status (status),
        INDEX idx_cafe_pesanan_transaksi (transaksi_id),
        INDEX idx_cafe_pesanan_meja (meja_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Lengkapi struktur lama tanpa merusak data yang sudah ada.
    $alterMap = array(
        'cafe_meja' => array(
            'nama_meja' => "ADD COLUMN nama_meja VARCHAR(100) NULL AFTER nomor_meja",
            'lokasi' => "ADD COLUMN lokasi VARCHAR(100) NULL AFTER kapasitas",
            'catatan' => "ADD COLUMN catatan VARCHAR(255) NULL AFTER status",
            'updated_at' => "ADD COLUMN updated_at DATETIME NULL AFTER created_at"
        ),
        'cafe_pesanan' => array(
            'nomor_pesanan' => "ADD COLUMN nomor_pesanan VARCHAR(50) NULL AFTER transaksi_id",
            'user_id' => "ADD COLUMN user_id INT NULL AFTER catatan",
            'updated_at' => "ADD COLUMN updated_at DATETIME NULL AFTER created_at"
        )
    );
    foreach ($alterMap as $tableName => $columns) {
        foreach ($columns as $columnName => $definition) {
            if (!cafe_has_column($pdo, $tableName, $columnName)) {
                try {
                    $pdo->exec("ALTER TABLE `" . $tableName . "` " . $definition);
                } catch (Throwable $e) {
                    // Tetap lanjut agar kompatibel dengan akun DB tanpa izin ALTER.
                }
            }
        }
    }

    if (!cafe_has_column($pdo, 'produk', 'tipe_produk')) {
        try {
            $pdo->exec("ALTER TABLE produk ADD COLUMN tipe_produk VARCHAR(20) NOT NULL DEFAULT 'retail' AFTER kategori");
        } catch (Throwable $e) {
            // Tetap lanjut; fallback menggunakan kategori.
        }
    }

    if (!cafe_has_column($pdo, 'transaksi', 'sumber_transaksi')) {
        try {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN sumber_transaksi VARCHAR(20) NOT NULL DEFAULT 'toko' AFTER invoice");
        } catch (Throwable $e) {
            // Tetap lanjut untuk database lama.
        }
    }

    if (!cafe_has_column($pdo, 'transaksi', 'metode_pembayaran')) {
        try {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN metode_pembayaran VARCHAR(20) NOT NULL DEFAULT 'tunai' AFTER kembalian");
        } catch (Throwable $e) {
        }
    }

    if (!cafe_has_column($pdo, 'transaksi_detail', 'catatan_item')) {
        try {
            $pdo->exec("ALTER TABLE transaksi_detail ADD COLUMN catatan_item VARCHAR(255) NULL AFTER subtotal");
        } catch (Throwable $e) {
        }
    }
}

cafe_ensure_database($pdo);

$userId       = cafe_current_user_id();
$operatorName = cafe_current_user_name();

/** @return array<string,mixed>|null */
function cafe_get_open_cash(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM kas_harian WHERE user_id = :user_id AND status = 'buka' ORDER BY opened_at ASC, id ASC LIMIT 1");
    $stmt->execute(array(':user_id' => (int)$userId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

/** @param array<string,mixed> $payload */
function cafe_json(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function cafe_generate_invoice(): string
{
    return 'CF-' . date('Ymd-His') . '-' . random_int(100, 999);
}

function cafe_generate_order_number(): string
{
    return 'ORD-' . date('ymd-His') . '-' . random_int(10, 99);
}

/** @return array<int,string> */
function cafe_columns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return array();
    }
}

function cafe_update_open_cash(PDO $pdo, int $cashId, float $total, string $method, float $margin): void
{
    $isCash = in_array(strtolower($method), array('tunai', 'cash'), true);
    $stmt = $pdo->prepare("UPDATE kas_harian SET
        total_sales = COALESCE(total_sales,0) + :total_sales,
        total_tunai = COALESCE(total_tunai,0) + :total_tunai,
        total_nontunai = COALESCE(total_nontunai,0) + :total_nontunai,
        total_struk = COALESCE(total_struk,0) + 1,
        margin = COALESCE(margin,0) + :margin,
        kas_akhir_sistem = COALESCE(kas_awal,0) + COALESCE(total_sales,0) + :total_sales,
        updated_at = NOW()
        WHERE id = :id AND status = 'buka'");
    $stmt->execute(array(
        ':total_sales' => $total,
        ':total_tunai' => $isCash ? $total : 0,
        ':total_nontunai' => $isCash ? 0 : $total,
        ':margin' => $margin,
        ':id' => (int)$cashId,
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = (string)$_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    try {
        if ($action === 'status') {
            cafe_json(array(
                'success' => true,
                'cash' => cafe_get_open_cash($pdo, $userId),
                'operator' => $operatorName,
            ));
        }

        if ($action === 'menu') {
            $produkCols = cafe_columns($pdo, 'produk');
            $hasType = in_array('tipe_produk', $produkCols, true);
            // POS Cafe hanya menampilkan produk yang memang ditandai sebagai menu cafe.
            // Produk toko/retail tidak ditampilkan meskipun nama kategorinya mengandung
            // kata makanan, minuman, atau cafe.
            $whereType = $hasType
                ? "AND LOWER(TRIM(tipe_produk)) = 'cafe'"
                : "AND 1=0";

            $hargaBeliSelect = in_array('harga_beli', $produkCols, true) ? ', harga_beli' : ', 0 AS harga_beli';
            $satuanSelect = in_array('satuan', $produkCols, true) ? ', satuan' : ", 'pcs' AS satuan";
            $gambarSelect = in_array('gambar', $produkCols, true) ? ', gambar' : ', NULL AS gambar';
            $typeSelect = $hasType ? ', tipe_produk' : ", 'cafe' AS tipe_produk";
            $sql = "SELECT id, kode, nama, kategori, harga_jual, stok" .
                $hargaBeliSelect . $satuanSelect . $gambarSelect . $typeSelect .
                " FROM produk WHERE status='aktif' $whereType ORDER BY kategori, nama";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            cafe_json(array('success' => true, 'data' => $rows));
        }

        if ($action === 'meja') {
            $mejaCols = cafe_columns($pdo, 'cafe_meja');
            $selectNama = in_array('nama_meja', $mejaCols, true) ? ', nama_meja' : ", '' AS nama_meja";
            $selectLokasi = in_array('lokasi', $mejaCols, true) ? ', lokasi' : ", '' AS lokasi";
            $whereMeja = in_array('aktif', $mejaCols, true)
                ? "aktif=1 AND status<>'nonaktif'"
                : "status<>'nonaktif'";
            $rows = $pdo->query("SELECT id, nomor_meja, kapasitas, status $selectNama $selectLokasi FROM cafe_meja WHERE $whereMeja ORDER BY CAST(nomor_meja AS UNSIGNED), nomor_meja")->fetchAll(PDO::FETCH_ASSOC);
            cafe_json(array('success' => true, 'data' => $rows));
        }

        if ($action === 'simpan') {
            if ($userId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Sesi pengguna tidak valid. Silakan login ulang.'), 422);
            }

            $cash = cafe_get_open_cash($pdo, $userId);
            if (!$cash) {
                cafe_json(array('success' => false, 'need_open_cash' => true, 'message' => 'Kas belum dibuka. Buka kas terlebih dahulu.'), 422);
            }

            $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();
            if (!$items) {
                cafe_json(array('success' => false, 'message' => 'Daftar pesanan masih kosong.'), 422);
            }

            $orderType = isset($input['tipe_pesanan']) ? strtolower(trim((string)$input['tipe_pesanan'])) : 'dine_in';
            if (!in_array($orderType, array('dine_in', 'takeaway'), true)) {
                $orderType = 'dine_in';
            }
            $tableId = $orderType === 'dine_in' ? (int)($input['meja_id'] ?? 0) : 0;
            if ($orderType === 'dine_in' && $tableId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Pilih meja untuk pesanan dine in.'), 422);
            }

            $paymentMethod = strtolower(trim((string)($input['metode_pembayaran'] ?? 'tunai')));
            if (!in_array($paymentMethod, array('tunai', 'qris', 'edc', 'transfer', 'debit', 'kredit'), true)) {
                $paymentMethod = 'tunai';
            }

            $ids = array();
            foreach ($items as $item) {
                $id = (int)($item['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            if (!$ids) {
                cafe_json(array('success' => false, 'message' => 'Produk pesanan tidak valid.'), 422);
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $produkColsSave = cafe_columns($pdo, 'produk');
            $hargaBeliSave = in_array('harga_beli', $produkColsSave, true) ? 'harga_beli' : '0 AS harga_beli';
            $whereSaveType = in_array('tipe_produk', $produkColsSave, true)
                ? " AND LOWER(TRIM(tipe_produk)) = 'cafe'"
                : " AND 1=0";
            $stmtProducts = $pdo->prepare("SELECT id, kode, nama, harga_jual, $hargaBeliSave, stok FROM produk WHERE id IN ($placeholders) AND status='aktif' $whereSaveType FOR UPDATE");

            $pdo->beginTransaction();

            // Kunci dan validasi meja agar dua pesanan dine-in tidak memakai meja yang sama.
            if ($orderType === 'dine_in') {
                $stmtMejaCheck = $pdo->prepare("SELECT id, nomor_meja, status FROM cafe_meja WHERE id=:id FOR UPDATE");
                $stmtMejaCheck->execute(array(':id' => $tableId));
                $mejaDipilih = $stmtMejaCheck->fetch(PDO::FETCH_ASSOC);
                if (!$mejaDipilih) {
                    throw new Exception('Meja yang dipilih tidak ditemukan.');
                }
                if (in_array(strtolower((string)$mejaDipilih['status']), array('terisi', 'nonaktif'), true)) {
                    throw new Exception('Meja ' . $mejaDipilih['nomor_meja'] . ' sedang tidak tersedia.');
                }
            }

            $stmtProducts->execute(array_values($ids));
            $productMap = array();
            foreach ($stmtProducts->fetchAll(PDO::FETCH_ASSOC) as $product) {
                $productMap[(int)$product['id']] = $product;
            }

            $total = 0;
            $margin = 0;
            $normalizedItems = array();
            foreach ($items as $item) {
                $id = (int)($item['id'] ?? 0);
                $qty = max(1, (int)($item['qty'] ?? 1));
                if (!isset($productMap[$id])) {
                    throw new Exception('Produk tidak ditemukan atau sudah tidak aktif.');
                }
                $product = $productMap[$id];
                if ((int)$product['stok'] < $qty) {
                    throw new Exception('Stok ' . $product['nama'] . ' tidak cukup.');
                }
                $price = (float)$product['harga_jual'];
                $buyPrice = (float)$product['harga_beli'];
                $subtotal = $price * $qty;
                $total += $subtotal;
                $margin += ($price - $buyPrice) * $qty;
                $normalizedItems[] = array(
                    'id' => $id,
                    'qty' => $qty,
                    'kode' => $product['kode'],
                    'nama' => $product['nama'],
                    'harga' => $price,
                    'subtotal' => $subtotal,
                    'catatan' => trim((string)($item['catatan'] ?? '')),
                );
            }

            $paid = (float)($input['bayar'] ?? 0);
            if ($paymentMethod !== 'tunai') {
                $paid = $total;
            }
            if ($paid < $total) {
                throw new Exception('Nominal pembayaran kurang.');
            }
            $change = max(0, $paid - $total);

            $invoice = cafe_generate_invoice();
            $orderNumber = cafe_generate_order_number();
            $trxCols = cafe_columns($pdo, 'transaksi');
            $fields = array('invoice', 'user_id', 'total', 'bayar', 'kembalian', 'metode_pembayaran', 'catatan');
            $values = array(':invoice', ':user_id', ':total', ':bayar', ':kembalian', ':metode_pembayaran', ':catatan');
            $params = array(
                ':invoice' => $invoice,
                ':user_id' => $userId,
                ':total' => $total,
                ':bayar' => $paid,
                ':kembalian' => $change,
                ':metode_pembayaran' => $paymentMethod,
                ':catatan' => trim((string)($input['catatan'] ?? '')),
            );
            if (in_array('sumber_transaksi', $trxCols, true)) {
                array_splice($fields, 1, 0, 'sumber_transaksi');
                array_splice($values, 1, 0, ':sumber_transaksi');
                $params[':sumber_transaksi'] = 'cafe';
            }
            if (in_array('member_id', $trxCols, true)) {
                $fields[] = 'member_id';
                $values[] = 'NULL';
            }
            if (in_array('point_dapat', $trxCols, true)) {
                $fields[] = 'point_dapat';
                $values[] = '0';
            }
            $sqlTransaction = "INSERT INTO transaksi (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $values) . ")";
            $stmtTransaction = $pdo->prepare($sqlTransaction);
            $stmtTransaction->execute($params);
            $transactionId = (int)$pdo->lastInsertId();

            $detailCols = cafe_columns($pdo, 'transaksi_detail');
            $hasItemNote = in_array('catatan_item', $detailCols, true);
            $detailSql = "INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga,qty,subtotal" . ($hasItemNote ? ",catatan_item" : "") . ") VALUES (:transaksi_id,:produk_id,:kode,:nama,:harga,:qty,:subtotal" . ($hasItemNote ? ",:catatan_item" : "") . ")";
            $stmtDetail = $pdo->prepare($detailSql);
            $stmtStock = $pdo->prepare("UPDATE produk SET stok = stok - :qty, updated_at = NOW() WHERE id = :id");

            foreach ($normalizedItems as $item) {
                $detailParams = array(
                    ':transaksi_id' => $transactionId,
                    ':produk_id' => $item['id'],
                    ':kode' => $item['kode'],
                    ':nama' => $item['nama'],
                    ':harga' => $item['harga'],
                    ':qty' => $item['qty'],
                    ':subtotal' => $item['subtotal'],
                );
                if ($hasItemNote) {
                    $detailParams[':catatan_item'] = $item['catatan'];
                }
                $stmtDetail->execute($detailParams);
                $stmtStock->execute(array(':qty' => $item['qty'], ':id' => $item['id']));
            }

            $stmtOrder = $pdo->prepare("INSERT INTO cafe_pesanan (transaksi_id,meja_id,tipe_pesanan,nomor_pesanan,status,catatan,user_id) VALUES (:transaksi_id,:meja_id,:tipe_pesanan,:nomor_pesanan,'baru',:catatan,:user_id)");
            $stmtOrder->execute(array(
                ':transaksi_id' => $transactionId,
                ':meja_id' => $tableId > 0 ? $tableId : null,
                ':tipe_pesanan' => $orderType,
                ':nomor_pesanan' => $orderNumber,
                ':catatan' => trim((string)($input['catatan'] ?? '')),
                ':user_id' => $userId,
            ));

            if ($tableId > 0) {
                $stmtTable = $pdo->prepare("UPDATE cafe_meja SET status='terisi', updated_at=NOW() WHERE id=:id");
                $stmtTable->execute(array(':id' => $tableId));
            }

            cafe_update_open_cash($pdo, (int)$cash['id'], $total, $paymentMethod, $margin);

            $pdo->commit();
            cafe_json(array(
                'success' => true,
                'message' => 'Pesanan cafe berhasil disimpan.',
                'invoice' => $invoice,
                'nomor_pesanan' => $orderNumber,
                'transaksi_id' => $transactionId,
                'total' => $total,
                'bayar' => $paid,
                'kembalian' => $change,
                'metode_pembayaran' => $paymentMethod,
                'pesanan_dapur' => true,
                'dapur_url' => 'dapur.php',
            ));
        }

        cafe_json(array('success' => false, 'message' => 'Action tidak dikenali.'), 404);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cafe_json(array('success' => false, 'message' => $e->getMessage()), 500);
    }
}

$cashOpen = cafe_get_open_cash($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasir Cafe</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #111827
        }

        * {
            border-radius: 0 !important
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none
        }

        .no-scrollbar {
            scrollbar-width: none
        }

        .cafe-shell {
            min-height: calc(100vh - 60px)
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
            gap: 12px
        }

        .menu-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 12px;
            cursor: pointer;
            transition: .15s
        }

        .menu-card:hover {
            border-color: #111827;
            transform: translateY(-1px)
        }

        .menu-image {
            height: 100px;
            background: #f1f5f9;
            border: 1px solid #edf2f7;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 10px
        }

        .menu-image img {
            width: 100%;
            height: 100%;
            object-fit: cover
        }

        .cart-panel {
            background: #fff;
            border-left: 1px solid #e5e7eb
        }

        .cart-item {
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 12px
        }

        .qty-btn {
            width: 34px;
            height: 34px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 18px;
            font-weight: 800
        }

        .category-btn {
            height: 36px;
            padding: 0 16px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap
        }

        .category-btn.active {
            background: #111827;
            color: #fff;
            border-color: #111827
        }

        #mobile-cart {
            display: none
        }

        @media(min-width:1024px) {

            .content,
            .app-header,
            .page-header {
                margin-left: 220px
            }

            .cafe-shell {
                height: calc(100vh - 60px);
                overflow: hidden
            }

            .menu-side {
                height: 100%;
                overflow: hidden
            }

            .menu-scroll {
                height: 100%;
                overflow-y: auto
            }

            .cart-panel {
                width: 400px;
                height: 100%;
                display: flex;
                flex-direction: column
            }

            .cart-list {
                flex: 1;
                overflow-y: auto
            }
        }

        @media(max-width:1023px) {
            body {
                padding-bottom: 330px
            }

            .content {
                margin-left: 0
            }

            .desktop-cart {
                display: none !important
            }

            #mobile-cart {
                display: flex;
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 320px;
                z-index: 80;
                background: #fff;
                border-top: 2px solid #111827;
                flex-direction: column
            }

            .menu-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr))
            }

            .menu-scroll {
                padding-bottom: 24px
            }
        }

        @media(max-width:640px) {
            body {
                padding-bottom: 360px
            }

            #mobile-cart {
                height: 350px
            }

            .menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px
            }

            .menu-card {
                padding: 9px
            }

            .menu-image {
                height: 90px
            }

            .cafe-header {
                padding: 12px !important
            }
        }
    </style>
</head>

<body>
    <?php if (is_file(__DIR__ . '/sidebar.php')) require_once 'sidebar.php'; ?>
    <?php if (is_file(__DIR__ . '/navbar.php')) require_once 'navbar.php'; ?>

    <div class="content cafe-shell flex">
        <section class="menu-side flex-1 min-w-0 flex flex-col">
            <div class="cafe-header bg-white border-b border-gray-200 p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.18em] text-gray-400">Mesin Kasir</p>
                        <h1 class="text-2xl font-black">Cafe</h1>
                    </div>
                    <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest">
                        <span class="px-3 py-2 border <?php echo $cashOpen ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                            <?php echo $cashOpen ? 'Kas Buka' : 'Kas Belum Dibuka'; ?>
                        </span>
                        <span class="px-3 py-2 border border-gray-200 bg-white"><?php echo cafe_e($operatorName); ?></span>
                    </div>
                </div>
                <input id="search" type="search" placeholder="Cari menu makanan atau minuman..." class="w-full border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-bold outline-none focus:border-black">
                <div id="categories" class="flex gap-2 overflow-x-auto no-scrollbar"></div>
            </div>
            <div class="menu-scroll flex-1 p-4 md:p-5 no-scrollbar">
                <div id="menu-grid" class="menu-grid"></div>
            </div>
        </section>

        <aside class="desktop-cart cart-panel">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-sm font-black uppercase tracking-widest">Daftar Pesanan</h2>
                <p class="text-[10px] text-gray-400 mt-1">Draft otomatis tersimpan per operator</p>
            </div>
            <div id="cart-list-desktop" class="cart-list p-4 space-y-3 no-scrollbar"></div>
            <div id="cart-footer-desktop" class="border-t border-gray-200 p-4"></div>
        </aside>
    </div>

    <div id="mobile-cart">
        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Daftar Pesanan</p>
                <p id="mobile-cart-summary" class="text-sm font-black">0 item · Rp 0</p>
            </div>
            <button type="button" onclick="clearCart(true)" class="border border-red-200 bg-red-50 text-red-600 px-3 py-2 text-[9px] font-black uppercase">Reset</button>
        </div>
        <div id="cart-list-mobile" class="flex-1 overflow-y-auto p-3 space-y-2 no-scrollbar"></div>
        <div id="cart-footer-mobile" class="border-t border-gray-200 p-3"></div>
    </div>

    <div id="payment-modal" class="fixed inset-0 z-[200] bg-black/50 hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg border border-gray-200 max-h-[94vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pembayaran Cafe</p>
                    <h3 id="modal-total" class="text-3xl font-black mt-1">Rp 0</h3>
                </div>
                <button onclick="closePayment()" class="text-2xl font-black">&times;</button>
            </div>
            <div class="p-5 space-y-5">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Tipe Pesanan</label>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <button id="type-dine" onclick="setOrderType('dine_in')" class="py-3 border border-black bg-black text-white text-[10px] font-black uppercase">Dine In</button>
                        <button id="type-takeaway" onclick="setOrderType('takeaway')" class="py-3 border border-gray-200 bg-white text-[10px] font-black uppercase">Takeaway</button>
                    </div>
                </div>
                <div id="table-wrap">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Pilih Meja</label>
                    <select id="table-select" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-bold"></select>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Catatan Pesanan</label>
                    <textarea id="order-note" rows="2" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-sm" placeholder="Contoh: tanpa gula, es sedikit"></textarea>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Metode Pembayaran</label>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <button id="pay-cash" onclick="setPaymentMethod('tunai')" class="py-3 border border-black bg-black text-white text-[10px] font-black uppercase">Tunai</button>
                        <button id="pay-qris" onclick="setPaymentMethod('qris')" class="py-3 border border-gray-200 bg-white text-[10px] font-black uppercase">QRIS / Non Tunai</button>
                    </div>
                </div>
                <div id="cash-wrap">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Uang Diterima</label>
                    <input id="paid-input" type="number" min="0" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-lg font-black" oninput="updateChange()">
                    <p class="text-xs text-gray-500 mt-2">Kembalian: <strong id="change-label">Rp 0</strong></p>
                </div>
                <button id="save-order-button" onclick="saveOrder()" class="w-full bg-black text-white py-4 text-xs font-black uppercase tracking-[.18em]">Simpan Pesanan & Bayar</button>
            </div>
        </div>
    </div>

    <script>
        'use strict';
        const ENDPOINT = <?php echo json_encode(basename($_SERVER['PHP_SELF'])); ?>;
        const USER_ID = <?php echo (int)$userId; ?>;
        const STORAGE_KEY = 'sejahub_cafe_cart_' + USER_ID;
        let MENU = [];
        let TABLES = [];
        let cart = [];
        let activeCategory = 'Semua';
        let orderType = 'dine_in';
        let paymentMethod = 'tunai';

        function rupiah(n) {
            return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID')
        }

        function escapeHtml(v) {
            const d = document.createElement('div');
            d.textContent = String(v == null ? '' : v);
            return d.innerHTML
        }

        function api(action, body) {
            return fetch(ENDPOINT + '?action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body || {}),
                cache: 'no-store'
            }).then(async r => {
                const t = await r.text();
                let d;
                try {
                    d = JSON.parse(t)
                } catch (e) {
                    throw new Error('Respons server bukan JSON: ' + t.slice(0, 80))
                }
                if (!r.ok && !d.success) throw new Error(d.message || ('HTTP ' + r.status));
                return d
            })
        }

        function saveDraft() {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                cart: cart,
                updated_at: Date.now()
            }))
        }

        function restoreDraft() {
            try {
                const d = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
                if (d && Array.isArray(d.cart)) cart = d.cart
            } catch (e) {
                cart = []
            }
        }

        function clearDraft() {
            localStorage.removeItem(STORAGE_KEY)
        }

        function totalCart() {
            return cart.reduce((s, i) => s + (Number(i.harga_jual || 0) * Number(i.qty || 0)), 0)
        }

        function countCart() {
            return cart.reduce((s, i) => s + Number(i.qty || 0), 0)
        }

        function productById(id) {
            return MENU.find(p => Number(p.id) === Number(id))
        }

        function addToCart(id) {
            const p = productById(id);
            if (!p) return;
            if (Number(p.stok) <= 0) {
                alert('Stok menu habis.');
                return
            }
            const found = cart.find(i => Number(i.id) === Number(id));
            if (found) {
                if (found.qty >= Number(p.stok)) {
                    alert('Qty melebihi stok.');
                    return
                }
                found.qty++
            } else {
                cart.push({
                    id: Number(p.id),
                    nama: p.nama,
                    kode: p.kode,
                    harga_jual: Number(p.harga_jual),
                    qty: 1,
                    catatan: ''
                })
            }
            saveDraft();
            renderCart()
        }

        function changeQty(id, delta) {
            const i = cart.find(x => Number(x.id) === Number(id));
            if (!i) return;
            const p = productById(id);
            i.qty += delta;
            if (i.qty <= 0) cart = cart.filter(x => Number(x.id) !== Number(id));
            else if (p && i.qty > Number(p.stok)) i.qty = Number(p.stok);
            saveDraft();
            renderCart()
        }

        function removeItem(id) {
            cart = cart.filter(x => Number(x.id) !== Number(id));
            saveDraft();
            renderCart()
        }

        function changeItemNote(id, value) {
            const i = cart.find(x => Number(x.id) === Number(id));
            if (i) {
                i.catatan = value;
                saveDraft()
            }
        }

        function clearCart(confirmFirst) {
            if (confirmFirst && !confirm('Kosongkan semua daftar pesanan?')) return;
            cart = [];
            clearDraft();
            renderCart()
        }

        function renderMenu() {
            const q = (document.getElementById('search').value || '').toLowerCase();
            const rows = MENU.filter(p => (activeCategory === 'Semua' || p.kategori === activeCategory) && ((p.nama || '').toLowerCase().includes(q) || (p.kode || '').toLowerCase().includes(q)));
            const el = document.getElementById('menu-grid');
            el.innerHTML = rows.length ? rows.map(p => `<article class="menu-card" onclick="addToCart(${Number(p.id)})"><div class="menu-image">${p.gambar?`<img src="${escapeHtml(p.gambar)}" alt="${escapeHtml(p.nama)}">`:'<span class="text-3xl">☕</span>'}</div><h3 class="text-xs font-black leading-snug min-h-[34px]">${escapeHtml(p.nama)}</h3><p class="text-sm font-black mt-2">${rupiah(p.harga_jual)}</p><p class="text-[9px] font-bold uppercase tracking-widest mt-1 ${Number(p.stok)>0?'text-green-600':'text-red-600'}">Stok ${Number(p.stok||0)} ${escapeHtml(p.satuan||'')}</p></article>`).join('') : '<div class="col-span-full py-20 text-center text-xs font-bold text-gray-400 uppercase">Belum ada menu cafe. Atur tipe_produk menjadi cafe pada halaman Menu Cafe.</div>'
        }

        function renderCategories() {
            const cats = ['Semua', ...new Set(MENU.map(p => p.kategori || 'Lainnya'))];
            document.getElementById('categories').innerHTML = cats.map(c => `<button class="category-btn ${c===activeCategory?'active':''}" onclick="setCategory(${JSON.stringify(c)})">${escapeHtml(c)}</button>`).join('')
        }

        function setCategory(c) {
            activeCategory = c;
            renderCategories();
            renderMenu()
        }

        function cartItemsHtml() {
            if (!cart.length) return '<div class="py-10 text-center text-[10px] font-black uppercase tracking-widest text-gray-300">Belum ada pesanan</div>';
            return cart.map(i => `<div class="cart-item"><div class="flex justify-between gap-3"><div class="min-w-0"><p class="text-xs font-black">${escapeHtml(i.nama)}</p><p class="text-[10px] text-gray-400 mt-1">${rupiah(i.harga_jual)} / item</p></div><button onclick="removeItem(${i.id})" class="text-red-500 font-black">×</button></div><div class="flex items-center justify-between mt-3"><div class="flex items-center"><button class="qty-btn" onclick="changeQty(${i.id},-1)">−</button><span class="w-10 text-center text-sm font-black">${i.qty}</span><button class="qty-btn" onclick="changeQty(${i.id},1)">+</button></div><strong class="text-sm">${rupiah(i.harga_jual*i.qty)}</strong></div><input value="${escapeHtml(i.catatan||'')}" oninput="changeItemNote(${i.id},this.value)" placeholder="Catatan item" class="w-full mt-3 border border-gray-200 bg-gray-50 px-3 py-2 text-[11px]"></div>`).join('')
        }

        function footerHtml() {
            const total = totalCart();
            return `<div class="flex justify-between text-sm font-black mb-3"><span>Total</span><span class="text-blue-600">${rupiah(total)}</span></div><button onclick="openPayment()" ${cart.length?'':'disabled'} class="w-full py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest disabled:opacity-30">Bayar Pesanan</button>`
        }

        function renderCart() {
            const html = cartItemsHtml();
            document.getElementById('cart-list-desktop').innerHTML = html;
            document.getElementById('cart-list-mobile').innerHTML = html;
            const footer = footerHtml();
            document.getElementById('cart-footer-desktop').innerHTML = footer;
            document.getElementById('cart-footer-mobile').innerHTML = footer;
            document.getElementById('mobile-cart-summary').textContent = countCart() + ' item · ' + rupiah(totalCart())
        }

        function openPayment() {
            if (!cart.length) return;
            document.getElementById('modal-total').textContent = rupiah(totalCart());
            document.getElementById('paid-input').value = paymentMethod === 'tunai' ? '' : Math.round(totalCart());
            updateChange();
            document.getElementById('payment-modal').classList.remove('hidden');
            document.getElementById('payment-modal').classList.add('flex')
        }

        function closePayment() {
            const m = document.getElementById('payment-modal');
            m.classList.add('hidden');
            m.classList.remove('flex')
        }

        function setOrderType(type) {
            orderType = type;
            document.getElementById('type-dine').className = 'py-3 border text-[10px] font-black uppercase ' + (type === 'dine_in' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('type-takeaway').className = 'py-3 border text-[10px] font-black uppercase ' + (type === 'takeaway' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('table-wrap').style.display = type === 'dine_in' ? 'block' : 'none'
        }

        function setPaymentMethod(method) {
            paymentMethod = method;
            document.getElementById('pay-cash').className = 'py-3 border text-[10px] font-black uppercase ' + (method === 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('pay-qris').className = 'py-3 border text-[10px] font-black uppercase ' + (method !== 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('cash-wrap').style.display = method === 'tunai' ? 'block' : 'none';
            document.getElementById('paid-input').value = method === 'tunai' ? '' : Math.round(totalCart());
            updateChange()
        }

        function updateChange() {
            const paid = Number(document.getElementById('paid-input').value || 0);
            document.getElementById('change-label').textContent = rupiah(Math.max(0, paid - totalCart()))
        }
        async function saveOrder() {
            const btn = document.getElementById('save-order-button');
            btn.disabled = true;
            btn.textContent = 'Menyimpan...';
            try {
                const payload = {
                    items: cart.map(i => ({
                        id: i.id,
                        qty: i.qty,
                        catatan: i.catatan || ''
                    })),
                    tipe_pesanan: orderType,
                    meja_id: orderType === 'dine_in' ? Number(document.getElementById('table-select').value || 0) : 0,
                    metode_pembayaran: paymentMethod,
                    bayar: paymentMethod === 'tunai' ? Number(document.getElementById('paid-input').value || 0) : totalCart(),
                    catatan: document.getElementById('order-note').value || ''
                };
                const d = await api('simpan', payload);
                if (!d.success) throw new Error(d.message || 'Gagal menyimpan pesanan.');
                alert('Pesanan berhasil.\n' + d.nomor_pesanan + '\n' + d.invoice + '\nTotal ' + rupiah(d.total));
                cart = [];
                clearDraft();
                closePayment();
                document.getElementById('order-note').value = '';
                await loadData();
                renderCart()
            } catch (e) {
                alert(e.message)
            } finally {
                btn.disabled = false;
                btn.textContent = 'Simpan Pesanan & Bayar'
            }
        }
        async function loadData() {
            const [menu, meja] = await Promise.all([api('menu'), api('meja')]);
            MENU = menu.data || [];
            TABLES = meja.data || [];
            const validIds = new Set(MENU.map(p => Number(p.id)));
            cart = cart.filter(i => validIds.has(Number(i.id)));
            document.getElementById('table-select').innerHTML = '<option value="">Pilih meja...</option>' + TABLES.map(t => `<option value="${Number(t.id)}" ${t.status==='terisi'?'disabled':''}>Meja ${escapeHtml(t.nomor_meja)} · ${escapeHtml(t.status)} · ${Number(t.kapasitas)} orang</option>`).join('');
            renderCategories();
            renderMenu();
            renderCart();
            saveDraft()
        }
        document.getElementById('search').addEventListener('input', renderMenu);
        restoreDraft();
        loadData().catch(e => alert(e.message));
    </script>
</body>

</html>