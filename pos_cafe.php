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
            'member_id' => "ADD COLUMN member_id INT NULL AFTER nomor_pesanan",
            'promo_id' => "ADD COLUMN promo_id INT NULL AFTER member_id",
            'promo_nama' => "ADD COLUMN promo_nama VARCHAR(150) NULL AFTER promo_id",
            'promo_diskon' => "ADD COLUMN promo_diskon DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER promo_nama",
            'promo_boleh_pakai_point' => "ADD COLUMN promo_boleh_pakai_point TINYINT(1) NOT NULL DEFAULT 1 AFTER promo_diskon",
            'status_pembayaran' => "ADD COLUMN status_pembayaran VARCHAR(20) NOT NULL DEFAULT 'belum_bayar' AFTER status",
            'total_tagihan' => "ADD COLUMN total_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER status_pembayaran",
            'paid_at' => "ADD COLUMN paid_at DATETIME NULL AFTER total_tagihan",
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

    // Catatan batch tambahan pesanan. Tidak mengubah struktur transaksi lama.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cafe_pesanan_batch (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cafe_pesanan_id INT NOT NULL,
            transaksi_id INT NOT NULL,
            batch_no INT NOT NULL DEFAULT 1,
            jenis ENUM('awal','tambahan') NOT NULL DEFAULT 'tambahan',
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_batch_pesanan (cafe_pesanan_id),
            INDEX idx_batch_transaksi (transaksi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cafe_pesanan_batch_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            transaksi_detail_id INT NOT NULL,
            produk_id INT NOT NULL,
            nama VARCHAR(180) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            catatan VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_batch_item_batch (batch_id),
            INDEX idx_batch_item_detail (transaksi_detail_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // Fitur utama tetap berjalan walau akun DB tidak boleh CREATE TABLE.
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
if (!defined('POINT_BELANJA_PER_POIN')) define('POINT_BELANJA_PER_POIN', 15000);
if (!defined('POINT_RUPIAH')) define('POINT_RUPIAH', 1000);

function cafe_columns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return array();
    }
}

function cafe_active_discount(PDO $pdo, float $grossTotal, int $memberId, array $items = array(), int $preferredPromoId = 0): array
{
    $empty = array(
        'id' => 0,
        'nama' => '',
        'target' => 'semua',
        'cakupan' => 'transaksi',
        'jenis' => '',
        'nilai' => 0,
        'diskon' => 0,
        'mode_penerapan' => 'otomatis',
        'boleh_pakai_point' => 1,
        'prioritas' => 999999,
    );

    try {
        $promoCols = cafe_columns($pdo, 'cafe_promo');
        if (!$promoCols) {
            return array('selected' => $empty, 'available' => array());
        }

        $sql = "SELECT * FROM cafe_promo
                WHERE status = 'aktif'
                  AND (tanggal_mulai IS NULL OR tanggal_mulai <= CURDATE())
                  AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())
                  AND COALESCE(minimal_belanja,0) <= :gross_total";

        if ($memberId > 0) {
            $sql .= " AND target IN ('semua','member')";
        } else {
            $sql .= " AND target = 'semua'";
        }

        $sql .= " ORDER BY prioritas ASC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':gross_total' => $grossTotal));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $eligible = array();
        foreach ($rows as $d) {
            $scope = strtolower(trim((string)($d['cakupan'] ?? 'transaksi')));
            $scopeBase = 0.0;

            if ($scope === 'transaksi') {
                $scopeBase = $grossTotal;
            } elseif ($scope === 'menu') {
                $targetProductId = (int)($d['produk_id'] ?? 0);
                foreach ($items as $item) {
                    if ((int)($item['id'] ?? 0) === $targetProductId) {
                        $scopeBase += (float)($item['subtotal'] ?? 0);
                    }
                }
            } elseif ($scope === 'kategori') {
                $targetCategory = strtolower(trim((string)($d['kategori'] ?? '')));
                foreach ($items as $item) {
                    if (strtolower(trim((string)($item['kategori'] ?? ''))) === $targetCategory) {
                        $scopeBase += (float)($item['subtotal'] ?? 0);
                    }
                }
            }

            if ($scopeBase <= 0) {
                continue;
            }

            $value = max(0, (float)($d['nilai'] ?? 0));
            $kind = strtolower(trim((string)($d['jenis'] ?? 'nominal')));
            $discount = $kind === 'persen' ? ($scopeBase * $value / 100) : $value;

            $maxDiscount = isset($d['maksimal_diskon']) && $d['maksimal_diskon'] !== null
                ? (float)$d['maksimal_diskon']
                : 0.0;
            if ($maxDiscount > 0) {
                $discount = min($discount, $maxDiscount);
            }

            $discount = min($grossTotal, $scopeBase, max(0, $discount));
            if ($discount <= 0) {
                continue;
            }

            $eligible[] = array(
                'id' => (int)($d['id'] ?? 0),
                'nama' => (string)($d['nama'] ?? 'Promo'),
                'target' => (string)($d['target'] ?? 'semua'),
                'cakupan' => $scope,
                'jenis' => $kind,
                'nilai' => $value,
                'diskon' => $discount,
                'mode_penerapan' => (string)($d['mode_penerapan'] ?? 'otomatis'),
                'boleh_pakai_point' => (int)($d['boleh_pakai_point'] ?? 1),
                'prioritas' => (int)($d['prioritas'] ?? 100),
            );
        }

        $selected = $empty;

        // Jika kasir memilih promo manual, gunakan promo tersebut bila masih memenuhi syarat.
        if ($preferredPromoId > 0) {
            foreach ($eligible as $promo) {
                if ((int)$promo['id'] === $preferredPromoId) {
                    $selected = $promo;
                    break;
                }
            }
        } else {
            // Tanpa pilihan manual, hanya promo mode otomatis yang boleh diterapkan sendiri.
            foreach ($eligible as $promo) {
                if (strtolower((string)$promo['mode_penerapan']) !== 'otomatis') {
                    continue;
                }

                if (
                    (float)$promo['diskon'] > (float)$selected['diskon'] ||
                    ((float)$promo['diskon'] === (float)$selected['diskon'] && (int)$promo['prioritas'] < (int)$selected['prioritas'])
                ) {
                    $selected = $promo;
                }
            }
        }

        return array('selected' => $selected, 'available' => $eligible);
    } catch (Throwable $e) {
        return array('selected' => $empty, 'available' => array());
    }
}

function cafe_update_open_cash(PDO $pdo, int $cashId, float $total, string $method, float $margin): void
{
    $isCash = in_array(strtolower($method), array('tunai', 'cash'), true);
    $stmt = $pdo->prepare("UPDATE kas_harian SET
        total_sales = COALESCE(total_sales,0) + :sales_add,
        total_tunai = COALESCE(total_tunai,0) + :cash_add,
        total_nontunai = COALESCE(total_nontunai,0) + :noncash_add,
        total_struk = COALESCE(total_struk,0) + 1,
        margin = COALESCE(margin,0) + :margin_add,
        kas_akhir_sistem = COALESCE(kas_awal,0) + COALESCE(total_sales,0) + :sales_for_cash_end,
        updated_at = NOW()
        WHERE id = :id AND status = 'buka'");
    $stmt->execute(array(
        ':sales_add' => $total,
        ':cash_add' => $isCash ? $total : 0,
        ':noncash_add' => $isCash ? 0 : $total,
        ':margin_add' => $margin,
        ':sales_for_cash_end' => $total,
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
            $satuanSelect = in_array('satuan', $produkCols, true) ? ', satuan' : ", 'porsi' AS satuan";
            $gambarSelect = in_array('gambar', $produkCols, true) ? ', gambar' : ', NULL AS gambar';
            $typeSelect = $hasType ? ', tipe_produk' : ", 'cafe' AS tipe_produk";
            $sql = "SELECT id, kode, nama, kategori, harga_jual, stok" .
                $hargaBeliSelect . $satuanSelect . $gambarSelect . $typeSelect .
                " FROM produk WHERE status='aktif' $whereType ORDER BY kategori, nama";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            cafe_json(array('success' => true, 'data' => $rows));
        }

        if ($action === 'promo') {
            $gross = max(0, (float)($input['total'] ?? 0));
            $memberIdPromo = max(0, (int)($input['member_id'] ?? 0));
            $preferredPromoId = max(0, (int)($input['promo_id'] ?? 0));
            $promoItems = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();
            $promoResult = cafe_active_discount($pdo, $gross, $memberIdPromo, $promoItems, $preferredPromoId);
            $selectedPromo = $promoResult['selected'];
            cafe_json(array(
                'success' => true,
                'data' => $selectedPromo,
                'available' => $promoResult['available'],
                'subtotal' => $gross,
                'total' => max(0, $gross - (float)$selectedPromo['diskon']),
            ));
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

        if ($action === 'members') {
            $rows = $pdo->query("
                SELECT id,kode,nama,no_hp,point
                FROM member
                WHERE status='aktif'
                ORDER BY nama ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            cafe_json(array('success' => true, 'data' => $rows));
        }

        // Autocomplete member - mengikuti pola POS minimarket.
        if ($action === 'suggest_member') {
            $q = trim((string)($input['q'] ?? ''));

            if ($q === '') {
                cafe_json(array('success' => true, 'data' => array()));
            }

            $qDigits = preg_replace('/\D+/', '', $q);
            $hpExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp, ''), ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";

            $stmtMemberSuggest = $pdo->prepare("
                SELECT id, kode, nama, no_hp, point
                FROM member
                WHERE status = 'aktif'
                  AND (
                        kode LIKE :kode_like
                     OR nama LIKE :nama_like
                     OR COALESCE(no_hp, '') LIKE :hp_like
                     OR $hpExpr LIKE :hp_digits_like
                  )
                ORDER BY
                    CASE
                        WHEN kode = :kode_exact THEN 1
                        WHEN COALESCE(no_hp, '') = :hp_exact THEN 2
                        WHEN $hpExpr = :hp_digits_exact THEN 3
                        WHEN nama LIKE :nama_prefix THEN 4
                        WHEN kode LIKE :kode_prefix THEN 5
                        WHEN COALESCE(no_hp, '') LIKE :hp_prefix THEN 6
                        ELSE 7
                    END,
                    nama ASC
                LIMIT 8
            ");

            $stmtMemberSuggest->execute(array(
                ':kode_like' => '%' . $q . '%',
                ':nama_like' => '%' . $q . '%',
                ':hp_like' => '%' . $q . '%',
                ':hp_digits_like' => $qDigits !== '' ? '%' . $qDigits . '%' : '%' . $q . '%',
                ':kode_exact' => $q,
                ':hp_exact' => $q,
                ':hp_digits_exact' => $qDigits,
                ':nama_prefix' => $q . '%',
                ':kode_prefix' => $q . '%',
                ':hp_prefix' => $q . '%',
            ));

            cafe_json(array(
                'success' => true,
                'data' => $stmtMemberSuggest->fetchAll(PDO::FETCH_ASSOC)
            ));
        }

        // Cari member sekali klik / Enter - mengikuti pola POS minimarket.
        if ($action === 'cari_member') {
            $keyword = trim((string)($input['keyword'] ?? ($input['kode'] ?? '')));

            if ($keyword === '') {
                cafe_json(array('success' => false, 'message' => 'Input member kosong.'), 422);
            }

            $keywordDigits = preg_replace('/\D+/', '', $keyword);
            $hpExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp, ''), ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";

            $stmtCariMember = $pdo->prepare("
                SELECT id, kode, nama, no_hp, point
                FROM member
                WHERE status = 'aktif'
                  AND (
                        kode = :kode_exact
                     OR nama = :nama_exact
                     OR COALESCE(no_hp, '') = :hp_exact
                     OR $hpExpr = :hp_digits_exact
                     OR kode LIKE :kode_like
                     OR nama LIKE :nama_like
                     OR COALESCE(no_hp, '') LIKE :hp_like
                     OR $hpExpr LIKE :hp_digits_like
                  )
                ORDER BY
                    CASE
                        WHEN kode = :kode_exact_order THEN 1
                        WHEN COALESCE(no_hp, '') = :hp_exact_order THEN 2
                        WHEN $hpExpr = :hp_digits_exact_order THEN 3
                        WHEN nama = :nama_exact_order THEN 4
                        WHEN kode LIKE :kode_prefix THEN 5
                        WHEN nama LIKE :nama_prefix THEN 6
                        WHEN COALESCE(no_hp, '') LIKE :hp_prefix THEN 7
                        ELSE 8
                    END,
                    nama ASC
                LIMIT 1
            ");

            $stmtCariMember->execute(array(
                ':kode_exact' => $keyword,
                ':nama_exact' => $keyword,
                ':hp_exact' => $keyword,
                ':hp_digits_exact' => $keywordDigits,
                ':kode_like' => '%' . $keyword . '%',
                ':nama_like' => '%' . $keyword . '%',
                ':hp_like' => '%' . $keyword . '%',
                ':hp_digits_like' => $keywordDigits !== '' ? '%' . $keywordDigits . '%' : '%' . $keyword . '%',
                ':kode_exact_order' => $keyword,
                ':hp_exact_order' => $keyword,
                ':hp_digits_exact_order' => $keywordDigits,
                ':nama_exact_order' => $keyword,
                ':kode_prefix' => $keyword . '%',
                ':nama_prefix' => $keyword . '%',
                ':hp_prefix' => $keyword . '%',
            ));

            $member = $stmtCariMember->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                cafe_json(array('success' => false, 'message' => 'Member tidak ditemukan.'), 404);
            }

            cafe_json(array('success' => true, 'data' => $member));
        }

        if ($action === 'open_orders') {
            $rows = $pdo->query("
                SELECT
                    cp.id,
                    cp.nomor_pesanan,
                    cp.meja_id,
                    cp.tipe_pesanan,
                    cp.status,
                    COALESCE(cp.status_pembayaran, 'belum_bayar') AS status_pembayaran,
                    COALESCE(cp.total_tagihan, 0) AS total_tagihan,
                    cp.created_at,
                    cm.nomor_meja,
                    cm.nama_meja,
                    cp.member_id,
                    cp.promo_id,
                    cp.promo_nama,
                    COALESCE(cp.promo_diskon,0) AS promo_diskon,
                    COALESCE(cp.promo_boleh_pakai_point,1) AS promo_boleh_pakai_point,
                    m.kode AS member_kode,
                    m.nama AS member_nama,
                    m.no_hp AS member_no_hp,
                    COALESCE(m.point,0) AS member_point
                FROM cafe_pesanan cp
                LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id
                LEFT JOIN member m ON m.id = cp.member_id
                WHERE COALESCE(cp.status_pembayaran, 'belum_bayar') <> 'lunas'
                  AND cp.status <> 'batal'
                ORDER BY cp.created_at ASC, cp.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            cafe_json(array('success' => true, 'data' => $rows));
        }


        if ($action === 'order_detail') {
            $orderId = max(0, (int)($input['order_id'] ?? 0));
            if ($orderId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Pesanan tidak valid.'), 422);
            }

            $stOrderDetail = $pdo->prepare("\n                SELECT cp.*, cm.nomor_meja, cm.nama_meja,\n                       m.kode AS member_kode, m.nama AS member_nama\n                FROM cafe_pesanan cp\n                LEFT JOIN cafe_meja cm ON cm.id=cp.meja_id\n                LEFT JOIN member m ON m.id=cp.member_id\n                WHERE cp.id=:id LIMIT 1\n            ");
            $stOrderDetail->execute(array(':id' => $orderId));
            $orderDetail = $stOrderDetail->fetch(PDO::FETCH_ASSOC);
            if (!$orderDetail) {
                cafe_json(array('success' => false, 'message' => 'Pesanan tidak ditemukan.'), 404);
            }

            $detailColsView = cafe_columns($pdo, 'transaksi_detail');
            $noteView = in_array('catatan_item', $detailColsView, true) ? 'td.catatan_item' : "'' AS catatan_item";
            $stItemsView = $pdo->prepare("\n                SELECT td.id, td.produk_id, td.nama, td.harga, td.qty, td.subtotal, $noteView,\n                       COALESCE(cb.batch_no,1) AS batch_no,\n                       CASE WHEN cb.id IS NULL THEN 'awal' ELSE cb.jenis END AS batch_jenis,\n                       cb.created_at AS batch_created_at\n                FROM transaksi_detail td\n                LEFT JOIN cafe_pesanan_batch_item cbi ON cbi.transaksi_detail_id=td.id\n                LEFT JOIN cafe_pesanan_batch cb ON cb.id=cbi.batch_id\n                WHERE td.transaksi_id=:transaksi_id\n                ORDER BY COALESCE(cb.batch_no,1), td.id\n            ");
            $stItemsView->execute(array(':transaksi_id' => (int)$orderDetail['transaksi_id']));
            $detailItems = $stItemsView->fetchAll(PDO::FETCH_ASSOC);

            $subtotalView = 0.0;
            foreach ($detailItems as $di) $subtotalView += (float)$di['subtotal'];

            cafe_json(array('success' => true, 'data' => array(
                'order' => $orderDetail,
                'items' => $detailItems,
                'subtotal' => $subtotalView,
                'diskon' => (float)($orderDetail['promo_diskon'] ?? 0),
                'total' => (float)($orderDetail['total_tagihan'] ?? 0),
            )));
        }

        if ($action === 'assign_table') {
            $orderId = max(0, (int)($input['order_id'] ?? 0));
            $newTableId = max(0, (int)($input['meja_id'] ?? 0));

            if ($orderId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Pesanan tidak valid.'), 422);
            }

            $pdo->beginTransaction();

            $stOrder = $pdo->prepare("
                SELECT *
                FROM cafe_pesanan
                WHERE id = :id
                FOR UPDATE
            ");
            $stOrder->execute(array(':id' => $orderId));
            $orderRow = $stOrder->fetch(PDO::FETCH_ASSOC);

            if (!$orderRow) {
                throw new Exception('Pesanan cafe tidak ditemukan.');
            }
            if ((string)($orderRow['status_pembayaran'] ?? 'belum_bayar') === 'lunas') {
                throw new Exception('Pesanan sudah lunas dan meja tidak dapat diubah.');
            }
            if ((string)($orderRow['status'] ?? '') === 'batal') {
                throw new Exception('Pesanan sudah dibatalkan.');
            }

            $oldTableId = (int)($orderRow['meja_id'] ?? 0);

            if ($newTableId > 0 && $newTableId !== $oldTableId) {
                $stTable = $pdo->prepare("
                    SELECT id, nomor_meja, status
                    FROM cafe_meja
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stTable->execute(array(':id' => $newTableId));
                $newTable = $stTable->fetch(PDO::FETCH_ASSOC);

                if (!$newTable) {
                    throw new Exception('Meja tidak ditemukan.');
                }

                if (in_array(strtolower((string)$newTable['status']), array('terisi', 'nonaktif'), true)) {
                    throw new Exception('Meja ' . $newTable['nomor_meja'] . ' sedang tidak tersedia.');
                }
            }

            $stUpdate = $pdo->prepare("
                UPDATE cafe_pesanan
                SET meja_id = :meja_id,
                    tipe_pesanan = 'dine_in',
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stUpdate->execute(array(
                ':meja_id' => $newTableId > 0 ? $newTableId : null,
                ':id' => $orderId,
            ));

            if ($oldTableId > 0 && $oldTableId !== $newTableId) {
                $stOther = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM cafe_pesanan
                    WHERE meja_id = :meja_id
                      AND id <> :id
                      AND COALESCE(status_pembayaran,'belum_bayar') <> 'lunas'
                      AND status <> 'batal'
                ");
                $stOther->execute(array(':meja_id' => $oldTableId, ':id' => $orderId));

                if ((int)$stOther->fetchColumn() === 0) {
                    $stFree = $pdo->prepare("UPDATE cafe_meja SET status='kosong', updated_at=NOW() WHERE id=:id");
                    $stFree->execute(array(':id' => $oldTableId));
                }
            }

            if ($newTableId > 0) {
                $stBusy = $pdo->prepare("UPDATE cafe_meja SET status='terisi', updated_at=NOW() WHERE id=:id");
                $stBusy->execute(array(':id' => $newTableId));
            }

            $pdo->commit();

            cafe_json(array(
                'success' => true,
                'message' => $newTableId > 0 ? 'Meja berhasil ditetapkan.' : 'Pesanan sekarang belum memiliki meja.',
                'order_id' => $orderId,
                'meja_id' => $newTableId,
            ));
        }

        if ($action === 'add_items') {
            if ($userId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Sesi pengguna tidak valid. Silakan login ulang.'), 422);
            }

            $orderId = max(0, (int)($input['order_id'] ?? 0));
            $newItems = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();

            if ($orderId <= 0 || !$newItems) {
                cafe_json(array('success' => false, 'message' => 'Pesanan tambahan belum valid.'), 422);
            }

            $ids = array();
            foreach ($newItems as $row) {
                $pid = (int)($row['id'] ?? 0);
                if ($pid > 0) $ids[$pid] = $pid;
            }
            if (!$ids) {
                cafe_json(array('success' => false, 'message' => 'Menu tambahan belum dipilih.'), 422);
            }

            $pdo->beginTransaction();

            $stOrder = $pdo->prepare("
                SELECT *
                FROM cafe_pesanan
                WHERE id = :id
                FOR UPDATE
            ");
            $stOrder->execute(array(':id' => $orderId));
            $orderRow = $stOrder->fetch(PDO::FETCH_ASSOC);

            if (!$orderRow) {
                throw new Exception('Pesanan cafe tidak ditemukan.');
            }
            if ((string)($orderRow['status_pembayaran'] ?? 'belum_bayar') === 'lunas') {
                throw new Exception('Pesanan sudah lunas. Buat pesanan baru bila ingin menambah menu.');
            }
            if ((string)($orderRow['status'] ?? '') === 'batal') {
                throw new Exception('Pesanan sudah dibatalkan.');
            }

            $transactionId = (int)($orderRow['transaksi_id'] ?? 0);
            if ($transactionId <= 0) {
                throw new Exception('Transaksi pesanan tidak ditemukan.');
            }

            $stTrx = $pdo->prepare("SELECT * FROM transaksi WHERE id=:id FOR UPDATE");
            $stTrx->execute(array(':id' => $transactionId));
            $trxRow = $stTrx->fetch(PDO::FETCH_ASSOC);
            if (!$trxRow) {
                throw new Exception('Transaksi pesanan tidak ditemukan.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $produkColsAdd = cafe_columns($pdo, 'produk');
            $whereAddType = in_array('tipe_produk', $produkColsAdd, true)
                ? " AND LOWER(TRIM(tipe_produk)) = 'cafe'"
                : " AND 1=0";

            $stProducts = $pdo->prepare("
                SELECT id, kode, nama, kategori, harga_jual, stok
                FROM produk
                WHERE id IN ($placeholders)
                  AND status='aktif'
                  $whereAddType
                FOR UPDATE
            ");
            $stProducts->execute(array_values($ids));

            $productMap = array();
            foreach ($stProducts->fetchAll(PDO::FETCH_ASSOC) as $product) {
                $productMap[(int)$product['id']] = $product;
            }

            $normalizedNew = array();
            $additionalSubtotal = 0.0;
            foreach ($newItems as $row) {
                $pid = (int)($row['id'] ?? 0);
                $qty = max(1, (int)($row['qty'] ?? 1));

                if (!isset($productMap[$pid])) {
                    throw new Exception('Menu tambahan tidak ditemukan atau sudah tidak aktif.');
                }

                $product = $productMap[$pid];

                // Menu Cafe dibuat saat dipesan, jadi stok menu jadi tidak dipakai.
                $price = (float)$product['harga_jual'];
                $sub = $price * $qty;
                $additionalSubtotal += $sub;

                $normalizedNew[] = array(
                    'id' => $pid,
                    'qty' => $qty,
                    'kode' => (string)$product['kode'],
                    'nama' => (string)$product['nama'],
                    'kategori' => (string)($product['kategori'] ?? ''),
                    'harga' => $price,
                    'subtotal' => $sub,
                    'catatan' => trim((string)($row['catatan'] ?? '')),
                );
            }

            $detailColsAdd = cafe_columns($pdo, 'transaksi_detail');
            $hasNoteAdd = in_array('catatan_item', $detailColsAdd, true);
            $detailSqlAdd = "INSERT INTO transaksi_detail
                (transaksi_id,produk_id,kode,nama,harga,qty,subtotal" . ($hasNoteAdd ? ",catatan_item" : "") . ")
                VALUES
                (:transaksi_id,:produk_id,:kode,:nama,:harga,:qty,:subtotal" . ($hasNoteAdd ? ",:catatan_item" : "") . ")";
            $stDetailAdd = $pdo->prepare($detailSqlAdd);

            $batchId = 0;
            try {
                $stBatchNo = $pdo->prepare("SELECT COALESCE(MAX(batch_no),0)+1 FROM cafe_pesanan_batch WHERE cafe_pesanan_id=:id");
                $stBatchNo->execute(array(':id' => $orderId));
                $batchNo = max(1, (int)$stBatchNo->fetchColumn());

                $stBatch = $pdo->prepare("
                    INSERT INTO cafe_pesanan_batch
                        (cafe_pesanan_id, transaksi_id, batch_no, jenis, subtotal, user_id)
                    VALUES
                        (:pesanan_id,:transaksi_id,:batch_no,'tambahan',:subtotal,:user_id)
                ");
                $stBatch->execute(array(
                    ':pesanan_id' => $orderId,
                    ':transaksi_id' => $transactionId,
                    ':batch_no' => $batchNo,
                    ':subtotal' => $additionalSubtotal,
                    ':user_id' => $userId,
                ));
                $batchId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                $batchId = 0;
            }

            foreach ($normalizedNew as $item) {
                $note = $item['catatan'];
                if ($hasNoteAdd && $note === '') {
                    $note = 'Tambahan pesanan';
                }

                $paramsDetail = array(
                    ':transaksi_id' => $transactionId,
                    ':produk_id' => $item['id'],
                    ':kode' => $item['kode'],
                    ':nama' => $item['nama'],
                    ':harga' => $item['harga'],
                    ':qty' => $item['qty'],
                    ':subtotal' => $item['subtotal'],
                );
                if ($hasNoteAdd) {
                    $paramsDetail[':catatan_item'] = $note;
                }

                $stDetailAdd->execute($paramsDetail);
                $newDetailId = (int)$pdo->lastInsertId();

                if ($batchId > 0) {
                    try {
                        $stBatchItem = $pdo->prepare("
                            INSERT INTO cafe_pesanan_batch_item
                                (batch_id, transaksi_detail_id, produk_id, nama, qty, catatan)
                            VALUES
                                (:batch_id,:detail_id,:produk_id,:nama,:qty,:catatan)
                        ");
                        $stBatchItem->execute(array(
                            ':batch_id' => $batchId,
                            ':detail_id' => $newDetailId,
                            ':produk_id' => $item['id'],
                            ':nama' => $item['nama'],
                            ':qty' => $item['qty'],
                            ':catatan' => $item['catatan'] !== '' ? $item['catatan'] : null,
                        ));
                    } catch (Throwable $e) {
                    }
                }
            }

            // Hitung ulang seluruh subtotal dari detail transaksi agar tagihan satu order tetap utuh.
            $stAll = $pdo->prepare("
                SELECT td.produk_id AS id, td.qty, td.subtotal, COALESCE(p.kategori,'') AS kategori
                FROM transaksi_detail td
                LEFT JOIN produk p ON p.id=td.produk_id
                WHERE td.transaksi_id=:id
            ");
            $stAll->execute(array(':id' => $transactionId));
            $allItems = $stAll->fetchAll(PDO::FETCH_ASSOC);

            $grossTotal = 0.0;
            foreach ($allItems as $row) {
                $grossTotal += (float)($row['subtotal'] ?? 0);
            }

            $memberId = (int)($orderRow['member_id'] ?? 0);
            $preferredPromoId = (int)($orderRow['promo_id'] ?? 0);
            $promoResult = cafe_active_discount($pdo, $grossTotal, $memberId, $allItems, $preferredPromoId);
            $promo = $promoResult['selected'];
            $promoDiscount = (float)($promo['diskon'] ?? 0);
            $newTotal = max(0, $grossTotal - $promoDiscount);

            $stUpdateTrx = $pdo->prepare("
                UPDATE transaksi
                SET total=:total,
                    bayar=0,
                    kembalian=0
                WHERE id=:id
            ");
            $stUpdateTrx->execute(array(':total' => $newTotal, ':id' => $transactionId));

            $stUpdateOrder = $pdo->prepare("
                UPDATE cafe_pesanan
                SET promo_id=:promo_id,
                    promo_nama=:promo_nama,
                    promo_diskon=:promo_diskon,
                    promo_boleh_pakai_point=:promo_point,
                    total_tagihan=:total_tagihan,
                    updated_at=NOW()
                WHERE id=:id
            ");
            $stUpdateOrder->execute(array(
                ':promo_id' => (int)($promo['id'] ?? 0) > 0 ? (int)$promo['id'] : null,
                ':promo_nama' => trim((string)($promo['nama'] ?? '')) !== '' ? (string)$promo['nama'] : null,
                ':promo_diskon' => $promoDiscount,
                ':promo_point' => (int)($promo['boleh_pakai_point'] ?? 1),
                ':total_tagihan' => $newTotal,
                ':id' => $orderId,
            ));

            $pdo->commit();

            cafe_json(array(
                'success' => true,
                'message' => 'Pesanan tambahan berhasil disimpan.',
                'order_id' => $orderId,
                'nomor_pesanan' => (string)($orderRow['nomor_pesanan'] ?? ''),
                'tambahan' => $additionalSubtotal,
                'subtotal' => $grossTotal,
                'diskon' => $promoDiscount,
                'total' => $newTotal,
                'promo' => $promo,
            ));
        }

        if ($action === 'pay_order') {
            if ($userId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Sesi pengguna tidak valid. Silakan login ulang.'), 422);
            }

            $cash = cafe_get_open_cash($pdo, $userId);
            if (!$cash) {
                cafe_json(array('success' => false, 'need_open_cash' => true, 'message' => 'Kas belum dibuka. Buka kas terlebih dahulu.'), 422);
            }

            $orderId = (int)($input['order_id'] ?? 0);
            $paymentMethod = strtolower(trim((string)($input['metode_pembayaran'] ?? 'tunai')));

            if (!in_array($paymentMethod, array('tunai', 'qris', 'edc', 'transfer', 'debit', 'kredit'), true)) {
                $paymentMethod = 'tunai';
            }

            if ($orderId <= 0) {
                cafe_json(array('success' => false, 'message' => 'Pesanan tidak valid.'), 422);
            }

            $pdo->beginTransaction();

            $stmtOrderPay = $pdo->prepare("
                SELECT cp.*, cm.nomor_meja
                FROM cafe_pesanan cp
                LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id
                WHERE cp.id = :id
                FOR UPDATE
            ");
            $stmtOrderPay->execute(array(':id' => $orderId));
            $orderPay = $stmtOrderPay->fetch(PDO::FETCH_ASSOC);

            if (!$orderPay) {
                throw new Exception('Pesanan cafe tidak ditemukan.');
            }

            if ((string)($orderPay['status_pembayaran'] ?? 'belum_bayar') === 'lunas') {
                throw new Exception('Pesanan ini sudah lunas.');
            }

            $transactionId = (int)($orderPay['transaksi_id'] ?? 0);
            if ($transactionId <= 0) {
                throw new Exception('Transaksi pesanan tidak ditemukan.');
            }

            $stmtTrx = $pdo->prepare("SELECT * FROM transaksi WHERE id = :id FOR UPDATE");
            $stmtTrx->execute(array(':id' => $transactionId));
            $trx = $stmtTrx->fetch(PDO::FETCH_ASSOC);

            if (!$trx) {
                throw new Exception('Data transaksi tidak ditemukan.');
            }

            // total_tagihan sudah menyimpan nilai setelah promo saat pesanan dibuat.
            // Jangan hitung promo ulang di tahap bayar-nanti agar diskon tidak terpotong dua kali.
            $grossTotal = (float)($orderPay['total_tagihan'] ?? $trx['total'] ?? 0);
            $total = $grossTotal;
            $promoDiscount = 0.0;
            $promo = array('id' => 0, 'nama' => '', 'jenis' => '', 'nilai' => 0, 'diskon' => 0);
            $memberIdPay = (int)($orderPay['member_id'] ?? 0);
            $pointPakaiInput = max(0, (int)($input['point_pakai'] ?? 0));
            $pointPakai = 0;
            $nilaiPointPakai = 0;
            $promoAllowsPointPay = (int)($orderPay['promo_boleh_pakai_point'] ?? 1) === 1;
            if ($memberIdPay > 0 && $pointPakaiInput > 0 && $promoAllowsPointPay) {
                $st = $pdo->prepare("SELECT point FROM member WHERE id=:id AND status='aktif' FOR UPDATE");
                $st->execute(array(':id' => $memberIdPay));
                $saldoPoint = (int)$st->fetchColumn();
                $pointPakai = min($pointPakaiInput, $saldoPoint, (int)floor($total / POINT_RUPIAH));
                $nilaiPointPakai = $pointPakai * POINT_RUPIAH;
            }
            $total = max(0, $total - $nilaiPointPakai);
            $pointDapat = $memberIdPay > 0 ? (int)floor($total / POINT_BELANJA_PER_POIN) : 0;
            $paid = (float)($input['bayar'] ?? 0);
            if ($paymentMethod !== 'tunai') $paid = $total;
            if ($paid < $total) throw new Exception('Nominal pembayaran kurang.');
            $change = max(0, $paid - $total);

            $trxColsPay = cafe_columns($pdo, 'transaksi');
            $setParts = array(
                'bayar = :bayar',
                'kembalian = :kembalian',
                'metode_pembayaran = :metode'
            );
            if (in_array('member_id', $trxColsPay, true)) $setParts[] = 'member_id = :member_id';
            if (in_array('point_dapat', $trxColsPay, true)) $setParts[] = 'point_dapat = :point_dapat';
            if (in_array('point_pakai', $trxColsPay, true)) $setParts[] = 'point_pakai = :point_pakai';
            if (in_array('nilai_point_pakai', $trxColsPay, true)) $setParts[] = 'nilai_point_pakai = :nilai_point_pakai';
            if (in_array('updated_at', $trxColsPay, true)) $setParts[] = 'updated_at = NOW()';

            $stmtUpdateTrx = $pdo->prepare("
                UPDATE transaksi
                SET " . implode(', ', $setParts) . "
                WHERE id = :id
            ");
            $payParams = array(':bayar' => $paid, ':kembalian' => $change, ':metode' => $paymentMethod, ':id' => $transactionId);
            if (in_array('member_id', $trxColsPay, true)) $payParams[':member_id'] = $memberIdPay ?: null;
            if (in_array('point_dapat', $trxColsPay, true)) $payParams[':point_dapat'] = $pointDapat;
            if (in_array('point_pakai', $trxColsPay, true)) $payParams[':point_pakai'] = $pointPakai;
            if (in_array('nilai_point_pakai', $trxColsPay, true)) $payParams[':nilai_point_pakai'] = $nilaiPointPakai;
            $stmtUpdateTrx->execute($payParams);

            $stmtMargin = $pdo->prepare("
                SELECT COALESCE(SUM((td.harga - COALESCE(p.harga_beli,0)) * td.qty),0)
                FROM transaksi_detail td
                LEFT JOIN produk p ON p.id = td.produk_id
                WHERE td.transaksi_id = :id
            ");
            $stmtMargin->execute(array(':id' => $transactionId));
            $margin = (float)$stmtMargin->fetchColumn();
            $margin -= (float)($orderPay['promo_diskon'] ?? 0);
            $margin -= $nilaiPointPakai;

            $stmtOrderDone = $pdo->prepare("
                UPDATE cafe_pesanan
                SET status_pembayaran = 'lunas',
                    paid_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtOrderDone->execute(array(':id' => $orderId));

            if (!empty($orderPay['meja_id'])) {
                $stmtTableFree = $pdo->prepare("UPDATE cafe_meja SET status='kosong', updated_at=NOW() WHERE id=:id");
                $stmtTableFree->execute(array(':id' => (int)$orderPay['meja_id']));
            }

            if ($memberIdPay > 0) {
                $st = $pdo->prepare("UPDATE member SET point=point-:pakai+:dapat,total_belanja=total_belanja+:total,updated_at=NOW() WHERE id=:id");
                $st->execute(array(':pakai' => $pointPakai, ':dapat' => $pointDapat, ':total' => $total, ':id' => $memberIdPay));
            }
            cafe_update_open_cash($pdo, (int)$cash['id'], $total, $paymentMethod, $margin);

            $pdo->commit();

            cafe_json(array(
                'success' => true,
                'message' => 'Pembayaran berhasil.',
                'invoice' => (string)($trx['invoice'] ?? ''),
                'transaksi_id' => $transactionId,
                'nomor_pesanan' => (string)($orderPay['nomor_pesanan'] ?? ''),
                'total' => $total,
                'subtotal' => $grossTotal,
                'diskon' => $promoDiscount,
                'promo' => $promo,
                'bayar' => $paid,
                'kembalian' => $change,
                'metode_pembayaran' => $paymentMethod,
                'point_pakai' => $pointPakai,
                'nilai_point_pakai' => $nilaiPointPakai,
                'point_dapat' => $pointDapat,
            ));
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
            // Meja bersifat opsional. Pesanan dine-in boleh dibuat lebih dulu,
            // lalu meja dapat ditentukan / dipindahkan setelah pelanggan duduk.
            $tableId = $orderType === 'dine_in' ? max(0, (int)($input['meja_id'] ?? 0)) : 0;

            $paymentMode = strtolower(trim((string)($input['payment_mode'] ?? 'bayar_sekarang')));
            if (!in_array($paymentMode, array('bayar_sekarang', 'bayar_nanti'), true)) {
                $paymentMode = 'bayar_sekarang';
            }

            $paymentMethod = strtolower(trim((string)($input['metode_pembayaran'] ?? 'tunai')));
            if (!in_array($paymentMethod, array('tunai', 'qris', 'edc', 'transfer', 'debit', 'kredit'), true)) $paymentMethod = 'tunai';
            $memberId = max(0, (int)($input['member_id'] ?? 0));
            $pointPakaiInput = max(0, (int)($input['point_pakai'] ?? 0));
            $memberPoint = 0;
            if ($memberId > 0) {
                $st = $pdo->prepare("SELECT point FROM member WHERE id=:id AND status='aktif' LIMIT 1");
                $st->execute(array(':id' => $memberId));
                $v = $st->fetchColumn();
                if ($v === false) throw new Exception('Member tidak ditemukan atau tidak aktif.');
                $memberPoint = (int)$v;
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
            $stmtProducts = $pdo->prepare("SELECT id, kode, nama, kategori, harga_jual, $hargaBeliSave, stok FROM produk WHERE id IN ($placeholders) AND status='aktif' $whereSaveType FOR UPDATE");

            $pdo->beginTransaction();

            // Kunci dan validasi meja hanya bila kasir sudah memilih meja.
            if ($orderType === 'dine_in' && $tableId > 0) {
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

                // Menu Cafe dibuat setelah dipesan. Stok menu jadi tidak membatasi qty.
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
                    'kategori' => (string)($product['kategori'] ?? ''),
                    'harga' => $price,
                    'subtotal' => $subtotal,
                    'catatan' => trim((string)($item['catatan'] ?? '')),
                );
            }

            $grossTotal = $total;
            $preferredPromoId = max(0, (int)($input['promo_id'] ?? 0));
            $promoResult = cafe_active_discount($pdo, $grossTotal, $memberId, $normalizedItems, $preferredPromoId);
            $promo = $promoResult['selected'];
            $promoDiscount = (float)$promo['diskon'];
            $total = max(0, $grossTotal - $promoDiscount);
            $pointPakai = 0;
            $nilaiPointPakai = 0;
            $pointDapat = 0;
            $paid = 0;
            $change = 0;
            if ($paymentMode === 'bayar_sekarang') {
                if ($memberId > 0 && $pointPakaiInput > 0 && (int)($promo['boleh_pakai_point'] ?? 1) === 1) {
                    // Point dipakai dari total sesudah promo, bukan subtotal sebelum promo.
                    $pointPakai = min($pointPakaiInput, $memberPoint, (int)floor($total / POINT_RUPIAH));
                    $nilaiPointPakai = $pointPakai * POINT_RUPIAH;
                }
                $total = max(0, $total - $nilaiPointPakai);
                $pointDapat = $memberId > 0 ? (int)floor($total / POINT_BELANJA_PER_POIN) : 0;
                $paid = (float)($input['bayar'] ?? 0);
                if ($paymentMethod !== 'tunai') $paid = $total;
                if ($paid < $total) throw new Exception('Nominal pembayaran kurang.');
                $change = max(0, $paid - $total);
            }

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
                $values[] = ':member_id';
                $params[':member_id'] = $memberId ?: null;
            }
            if (in_array('point_dapat', $trxCols, true)) {
                $fields[] = 'point_dapat';
                $values[] = ':point_dapat';
                $params[':point_dapat'] = $pointDapat;
            }
            if (in_array('point_pakai', $trxCols, true)) {
                $fields[] = 'point_pakai';
                $values[] = ':point_pakai';
                $params[':point_pakai'] = $pointPakai;
            }
            if (in_array('nilai_point_pakai', $trxCols, true)) {
                $fields[] = 'nilai_point_pakai';
                $values[] = ':nilai_point_pakai';
                $params[':nilai_point_pakai'] = $nilaiPointPakai;
            }
            $sqlTransaction = "INSERT INTO transaksi (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $values) . ")";
            $stmtTransaction = $pdo->prepare($sqlTransaction);
            $stmtTransaction->execute($params);
            $transactionId = (int)$pdo->lastInsertId();

            $detailCols = cafe_columns($pdo, 'transaksi_detail');
            $hasItemNote = in_array('catatan_item', $detailCols, true);
            $detailSql = "INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga,qty,subtotal" . ($hasItemNote ? ",catatan_item" : "") . ") VALUES (:transaksi_id,:produk_id,:kode,:nama,:harga,:qty,:subtotal" . ($hasItemNote ? ",:catatan_item" : "") . ")";
            $stmtDetail = $pdo->prepare($detailSql);

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
            }

            $paymentStatus = $paymentMode === 'bayar_sekarang' ? 'lunas' : 'belum_bayar';

            $stmtOrder = $pdo->prepare("
                INSERT INTO cafe_pesanan
                    (transaksi_id,meja_id,tipe_pesanan,nomor_pesanan,member_id,promo_id,promo_nama,promo_diskon,promo_boleh_pakai_point,status,status_pembayaran,total_tagihan,paid_at,catatan,user_id)
                VALUES
                    (:transaksi_id,:meja_id,:tipe_pesanan,:nomor_pesanan,:member_id,:promo_id,:promo_nama,:promo_diskon,:promo_boleh_pakai_point,'baru',:status_pembayaran,:total_tagihan,:paid_at,:catatan,:user_id)
            ");
            $stmtOrder->execute(array(
                ':transaksi_id' => $transactionId,
                ':meja_id' => $tableId > 0 ? $tableId : null,
                ':tipe_pesanan' => $orderType,
                ':nomor_pesanan' => $orderNumber,
                ':member_id' => $memberId ?: null,
                ':promo_id' => (int)($promo['id'] ?? 0) > 0 ? (int)$promo['id'] : null,
                ':promo_nama' => (string)($promo['nama'] ?? '') !== '' ? (string)$promo['nama'] : null,
                ':promo_diskon' => $promoDiscount,
                ':promo_boleh_pakai_point' => (int)($promo['boleh_pakai_point'] ?? 1),
                ':status_pembayaran' => $paymentStatus,
                ':total_tagihan' => $total,
                ':paid_at' => $paymentStatus === 'lunas' ? date('Y-m-d H:i:s') : null,
                ':catatan' => trim((string)($input['catatan'] ?? '')),
                ':user_id' => $userId,
            ));

            if ($tableId > 0) {
                $stmtTable = $pdo->prepare("UPDATE cafe_meja SET status='terisi', updated_at=NOW() WHERE id=:id");
                $stmtTable->execute(array(':id' => $tableId));
            }

            if ($paymentMode === 'bayar_sekarang') {
                if ($memberId > 0) {
                    $st = $pdo->prepare("UPDATE member SET point=point-:pakai+:dapat,total_belanja=total_belanja+:total,updated_at=NOW() WHERE id=:id");
                    $st->execute(array(':pakai' => $pointPakai, ':dapat' => $pointDapat, ':total' => $total, ':id' => $memberId));
                }
                $marginNet = $margin - $promoDiscount - $nilaiPointPakai;
                cafe_update_open_cash($pdo, (int)$cash['id'], $total, $paymentMethod, $marginNet);
            }

            $pdo->commit();
            cafe_json(array(
                'success' => true,
                'message' => 'Pesanan cafe berhasil disimpan.',
                'invoice' => $invoice,
                'nomor_pesanan' => $orderNumber,
                'transaksi_id' => $transactionId,
                'subtotal' => $grossTotal,
                'diskon' => $promoDiscount,
                'promo' => $promo,
                'total' => $total,
                'bayar' => $paid,
                'kembalian' => $change,
                'metode_pembayaran' => $paymentMethod,
                'status_pembayaran' => $paymentStatus,
                'payment_mode' => $paymentMode,
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

        /* Mobile/tablet POS ala aplikasi food-ordering:
           menu tetap menjadi area utama; keranjang berubah menjadi bar ringkas
           di bawah dan dapat dibuka sebagai bottom sheet. */
        @media(max-width:1023px) {
            body {
                padding-bottom: 82px !important;
                background: #f8fafc;
            }

            .cafe-shell {
                min-height: calc(100vh - 60px);
                overflow: visible;
            }

            .menu-side {
                min-height: calc(100vh - 60px);
            }

            .cafe-header {
                position: sticky;
                top: 0;
                z-index: 45;
                background: rgba(255, 255, 255, .97);
                box-shadow: 0 2px 12px rgba(15, 23, 42, .06);
            }

            .menu-scroll {
                overflow: visible !important;
                padding-bottom: 110px !important;
            }

            .menu-grid {
                align-items: stretch;
            }

            .menu-card {
                min-height: 100%;
                cursor: pointer;
            }

            #mobile-cart {
                height: 72px !important;
                border-top: 0 !important;
                left: 10px !important;
                right: 10px !important;
                bottom: 8px !important;
                border-radius: 16px;
                box-shadow: 0 10px 35px rgba(15, 23, 42, .24);
                overflow: hidden;
                transition: height .22s ease, border-radius .22s ease;
            }

            #mobile-cart>div:first-child {
                min-height: 72px;
                cursor: pointer;
                border-bottom: 0;
                padding: 10px 14px !important;
            }

            #mobile-cart>div:first-child:after {
                content: "Lihat pesanan";
                margin-left: auto;
                margin-right: 8px;
                font-size: 9px;
                font-weight: 900;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: #475569;
            }

            #mobile-cart #cart-list-mobile,
            #mobile-cart #cart-footer-mobile {
                display: none;
            }

            #mobile-cart.cart-open {
                height: min(72vh, 560px) !important;
                border-radius: 18px 18px 0 0;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
            }

            #mobile-cart.cart-open>div:first-child {
                border-bottom: 1px solid #e5e7eb;
            }

            #mobile-cart.cart-open>div:first-child:after {
                content: "Tutup";
            }

            #mobile-cart.cart-open #cart-list-mobile {
                display: block;
                flex: 1;
                overflow-y: auto;
            }

            #mobile-cart.cart-open #cart-footer-mobile {
                display: block;
            }
        }

        @media(max-width:640px) {
            body {
                padding-bottom: 82px !important;
            }

            .cafe-header {
                padding: 10px !important;
            }

            .cafe-header h1 {
                font-size: 20px !important;
            }

            .menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 9px !important;
            }

            .menu-card {
                padding: 8px !important;
                border-radius: 12px;
                box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
            }

            .menu-image {
                height: 105px !important;
                margin-bottom: 7px !important;
                border-radius: 9px;
            }

            .category-btn {
                height: 34px;
                padding: 0 13px;
                border-radius: 999px;
            }
        }

        @media(min-width:641px) and (max-width:1023px) {
            .menu-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: 12px;
            }

            .menu-image {
                height: 125px !important;
            }
        }

        #order-detail-modal .order-detail-card {
            border-radius: 16px;
            overflow: hidden;
        }

        @media(max-width:640px) {
            #order-detail-modal {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            #order-detail-modal .order-detail-card {
                max-height: 88vh;
                border-radius: 20px 20px 0 0;
                border: 0;
            }
        }

        #add-order-banner {
            border-radius: 12px;
        }

        #assign-table-modal>div {
            border-radius: 16px;
            overflow: hidden;
        }

        @media(max-width:640px) {
            #assign-table-modal {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            #assign-table-modal>div {
                border-radius: 18px 18px 0 0;
                border: 0;
            }
        }

        /* Pembayaran ringkas untuk kasir mobile/tablet */
        #payment-modal .payment-card {
            border-radius: 18px;
        }

        @media(max-width:640px) {
            #payment-modal {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            #payment-modal .payment-card {
                max-height: 92vh !important;
                border: 0 !important;
                border-radius: 20px 20px 0 0;
                overflow-y: auto;
            }

            #payment-modal .payment-head {
                padding: 14px 16px !important;
                position: sticky;
                top: 0;
                z-index: 3;
                background: #fff;
            }

            #payment-modal .payment-body {
                padding: 14px 16px 18px !important;
            }

            #payment-modal .payment-body>div {
                margin: 0 !important;
            }

            #payment-modal .payment-body {
                display: grid;
                gap: 12px !important;
            }

            #payment-modal label {
                font-size: 9px !important;
            }

            #payment-modal button {
                border-radius: 9px;
            }

            #payment-modal input,
            #payment-modal select,
            #payment-modal textarea {
                border-radius: 9px;
            }

            #payment-modal textarea {
                min-height: 44px;
                height: 44px;
                padding-top: 11px !important;
                padding-bottom: 8px !important;
            }

            #payment-modal #member-input {
                padding-top: 10px !important;
                padding-bottom: 10px !important;
            }

            #payment-modal #table-select {
                padding-top: 10px !important;
                padding-bottom: 10px !important;
            }

            #payment-modal #promo-select {
                margin-top: 7px !important;
            }

            #payment-modal #paid-input {
                padding-top: 9px !important;
                padding-bottom: 9px !important;
            }

            #payment-modal #save-order-button {
                position: sticky;
                bottom: 0;
                z-index: 2;
                min-height: 48px;
                border-radius: 12px;
                box-shadow: 0 -5px 18px rgba(255, 255, 255, .9);
            }
        }

        @media(min-width:641px) and (max-width:1023px) {
            #payment-modal .payment-card {
                max-width: 620px;
            }

            #payment-modal .payment-body {
                padding: 18px !important;
            }
        }

        /* Menu cafe model card-list seperti aplikasi food delivery.
           Desktop tetap grid, mobile/tablet memakai list horizontal agar nama
           dan harga lebih cepat dipindai kasir. */
        @media(max-width:1023px) {
            .menu-grid {
                display: flex !important;
                flex-direction: column !important;
                gap: 10px !important;
            }

            .menu-card {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) 104px !important;
                grid-template-areas:
                    "info image"
                    "meta image" !important;
                gap: 6px 12px !important;
                min-height: 112px !important;
                padding: 12px !important;
                border-radius: 14px !important;
                align-items: start !important;
                box-shadow: 0 1px 5px rgba(15, 23, 42, .06);
                background: #fff;
            }

            .menu-card .menu-image {
                grid-area: image !important;
                width: 104px !important;
                height: 88px !important;
                margin: 0 !important;
                border: 0 !important;
                border-radius: 12px !important;
                align-self: center !important;
            }

            .menu-card .menu-info {
                grid-area: info;
                min-width: 0;
            }

            .menu-card .menu-meta {
                grid-area: meta;
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 8px;
                align-self: end;
            }

            .menu-card .menu-name {
                min-height: 0 !important;
                font-size: 13px !important;
                line-height: 1.35 !important;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .menu-card .menu-price {
                margin-top: 5px !important;
                font-size: 13px !important;
            }

            .menu-card .menu-add {
                width: 31px;
                height: 31px;
                border-radius: 999px;
                border: 1px solid #111827;
                background: #fff;
                color: #111827;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 19px;
                font-weight: 900;
                line-height: 1;
                flex: 0 0 auto;
            }
        }

        @media(max-width:640px) {
            .menu-scroll {
                padding: 10px !important;
            }

            .menu-card {
                grid-template-columns: minmax(0, 1fr) 92px !important;
                min-height: 104px !important;
                padding: 10px !important;
                gap: 5px 10px !important;
            }

            .menu-card .menu-image {
                width: 92px !important;
                height: 82px !important;
            }
        }

        /* Penyempurnaan POS Cafe mobile: lebih rapat, bersih, dan fokus ke menu */
        @media(max-width:640px) {
            .cafe-header {
                padding: 8px 10px 10px !important;
                gap: 8px !important;
            }

            .cafe-header h1 {
                font-size: 20px !important;
                line-height: 1.05 !important;
                margin-top: 2px !important;
            }

            .cafe-header .flex.flex-wrap {
                gap: 6px !important;
                margin-top: 8px !important;
            }

            .cafe-header .flex.flex-wrap>* {
                min-height: 32px !important;
                padding: 0 11px !important;
                display: inline-flex;
                align-items: center;
            }

            #search {
                height: 44px !important;
                margin-top: 6px !important;
                padding: 0 14px !important;
                font-size: 13px !important;
                border-radius: 10px !important;
                background: #f8fafc !important;
            }

            #open-order-alert {
                margin-top: 2px !important;
                min-height: 40px !important;
                padding: 0 12px !important;
                border-radius: 10px !important;
            }

            #categories {
                gap: 7px !important;
                padding-top: 2px !important;
                padding-bottom: 2px !important;
            }

            .category-btn {
                height: 34px !important;
                padding: 0 13px !important;
                border-radius: 9px !important;
                font-size: 9px !important;
                letter-spacing: .05em !important;
            }

            .menu-scroll {
                padding: 8px 9px 100px !important;
                background: #f6f7f9 !important;
            }

            .menu-grid {
                gap: 8px !important;
            }

            .menu-card {
                grid-template-columns: minmax(0, 1fr) 88px !important;
                min-height: 102px !important;
                padding: 10px !important;
                gap: 4px 10px !important;
                border: 1px solid #e8ebef !important;
                border-radius: 13px !important;
                box-shadow: 0 2px 7px rgba(15, 23, 42, .045) !important;
            }

            .menu-card .menu-image {
                width: 88px !important;
                height: 82px !important;
                border-radius: 11px !important;
                background: #f1f5f9 !important;
            }

            .menu-card .menu-name {
                font-size: 12px !important;
                line-height: 1.3 !important;
            }

            .menu-card .menu-price {
                font-size: 13px !important;
                margin-top: 3px !important;
            }

            .menu-card .menu-meta {
                min-height: 30px;
            }

            .menu-card .menu-meta>span {
                font-size: 8px !important;
            }

            .menu-card .menu-add {
                width: 30px !important;
                height: 30px !important;
                border-radius: 8px !important;
                font-size: 18px !important;
                background: #111827 !important;
                color: #fff !important;
                border-color: #111827 !important;
            }

            /* Bottom cart dibuat satu bar rapi dan tidak menutup card menu */
            #mobile-cart {
                left: 8px !important;
                right: 8px !important;
                bottom: 7px !important;
                height: 66px !important;
                border-radius: 13px !important;
                box-shadow: 0 7px 24px rgba(15, 23, 42, .18) !important;
            }

            #mobile-cart>div:first-child {
                min-height: 66px !important;
                padding: 8px 10px !important;
            }

            #mobile-cart>div:first-child:after {
                content: "Lihat";
                margin-right: 4px;
                padding: 8px 10px;
                border-radius: 8px;
                background: #f1f5f9;
                color: #334155;
                font-size: 8px;
            }

            #mobile-cart #mobile-cart-summary {
                font-size: 13px !important;
                line-height: 1.2 !important;
            }

            #mobile-cart>div:first-child>div>p:first-child {
                font-size: 8px !important;
            }

            #mobile-cart>div:first-child>button {
                min-width: auto !important;
                padding: 7px 9px !important;
                border-radius: 8px !important;
                font-size: 8px !important;
            }

            #mobile-cart.cart-open {
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                height: min(78vh, 600px) !important;
                border-radius: 18px 18px 0 0 !important;
            }

            #mobile-cart.cart-open>div:first-child:after {
                content: "Tutup";
            }
        }

        /* Card menu mobile versi lebih rapi dan seimbang */
        @media(max-width:640px) {
            .menu-card {
                grid-template-columns: minmax(0, 1fr) 86px !important;
                min-height: 96px !important;
                padding: 9px 10px !important;
                gap: 4px 9px !important;
                border-radius: 12px !important;
                align-items: center !important;
            }

            .menu-card .menu-info {
                align-self: start !important;
                padding-top: 1px;
            }

            .menu-card .menu-name {
                font-size: 12px !important;
                line-height: 1.25 !important;
                font-weight: 800 !important;
                margin: 0 !important;
            }

            .menu-card .menu-price {
                font-size: 13px !important;
                line-height: 1.2 !important;
                margin-top: 4px !important;
                color: #0f172a !important;
            }

            .menu-card .menu-image {
                width: 86px !important;
                height: 78px !important;
                border-radius: 10px !important;
                align-self: center !important;
                background: #f1f5f9 !important;
            }

            .menu-card .menu-image img {
                width: 100% !important;
                height: 100% !important;
                object-fit: cover !important;
            }

            .menu-card .menu-meta {
                min-height: 26px !important;
                margin-top: 1px !important;
                align-items: end !important;
            }

            .menu-card .menu-meta>span {
                font-size: 7.5px !important;
                color: #94a3b8 !important;
                font-weight: 600 !important;
                line-height: 1.2 !important;
            }

            .menu-card .menu-add {
                width: 28px !important;
                height: 28px !important;
                border-radius: 8px !important;
                font-size: 17px !important;
                box-shadow: none !important;
                align-self: end !important;
            }
        }

        /* Final alignment tombol tambah pada card menu mobile */
        @media(max-width:640px) {
            .menu-card {
                position: relative !important;
                padding-bottom: 10px !important;
            }

            .menu-card .menu-meta {
                display: block !important;
                min-height: 28px !important;
                padding-right: 38px !important;
            }

            .menu-card .menu-meta>span {
                display: block !important;
                position: absolute !important;
                left: 10px !important;
                bottom: 12px !important;
                max-width: calc(100% - 150px) !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

            .menu-card .menu-add {
                position: absolute !important;
                right: 106px !important;
                bottom: 9px !important;
                width: 30px !important;
                height: 30px !important;
                min-width: 30px !important;
                min-height: 30px !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 8px !important;
                background: #0f172a !important;
                color: #fff !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 19px !important;
                font-weight: 700 !important;
                line-height: 1 !important;
                box-sizing: border-box !important;
                transform: none !important;
            }

            .menu-card .menu-add:disabled {
                opacity: .35 !important;
            }
        }

        /* Layout card mobile: gambar di kiri, informasi di kanan, tombol tambah di kanan bawah */
        @media(max-width:640px) {
            .menu-card {
                display: grid !important;
                grid-template-columns: 86px minmax(0, 1fr) !important;
                grid-template-areas:
                    "image info"
                    "image meta" !important;
                gap: 4px 10px !important;
                min-height: 96px !important;
                padding: 9px 10px !important;
                position: relative !important;
                align-items: center !important;
            }

            .menu-card .menu-image {
                grid-area: image !important;
                width: 86px !important;
                height: 78px !important;
                margin: 0 !important;
                border-radius: 10px !important;
                align-self: center !important;
            }

            .menu-card .menu-info {
                grid-area: info !important;
                min-width: 0 !important;
                align-self: start !important;
                padding-right: 0 !important;
            }

            .menu-card .menu-meta {
                grid-area: meta !important;
                display: flex !important;
                align-items: flex-end !important;
                justify-content: space-between !important;
                gap: 8px !important;
                min-height: 30px !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .menu-card .menu-meta>span {
                position: static !important;
                display: block !important;
                max-width: calc(100% - 42px) !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                font-size: 7.5px !important;
                color: #94a3b8 !important;
            }

            .menu-card .menu-add {
                position: static !important;
                width: 30px !important;
                height: 30px !important;
                min-width: 30px !important;
                min-height: 30px !important;
                margin: 0 !important;
                padding: 0 !important;
                border-radius: 8px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                line-height: 1 !important;
                font-size: 18px !important;
                flex: 0 0 30px !important;
                align-self: flex-end !important;
            }
        }

        /* Mobile POS Cafe: gaya daftar menu seperti aplikasi food-ordering,
           tetapi tetap mengikuti identitas aplikasi SEJAHUB. */
        @media(max-width:640px) {
            .menu-scroll {
                padding: 0 12px 92px !important;
                background: #fff !important;
            }

            .menu-grid {
                display: flex !important;
                flex-direction: column !important;
                gap: 0 !important;
                background: #fff !important;
            }

            .menu-card {
                display: grid !important;
                grid-template-columns: 96px minmax(0, 1fr) 40px !important;
                grid-template-areas: "image info add" !important;
                align-items: center !important;
                min-height: 124px !important;
                padding: 14px 0 !important;
                margin: 0 !important;
                gap: 12px !important;
                border: 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
                transform: none !important;
            }

            .menu-card:last-child {
                border-bottom: 0 !important;
            }

            .menu-card .menu-image {
                grid-area: image !important;
                width: 96px !important;
                height: 96px !important;
                margin: 0 !important;
                border: 0 !important;
                border-radius: 16px !important;
                overflow: hidden !important;
                background: #f1f5f9 !important;
            }

            .menu-card .menu-image img {
                width: 100% !important;
                height: 100% !important;
                object-fit: cover !important;
            }

            .menu-card .menu-info {
                grid-area: info !important;
                min-width: 0 !important;
                align-self: center !important;
                padding: 0 !important;
            }

            .menu-card .menu-name {
                min-height: 0 !important;
                margin: 0 !important;
                font-size: 14px !important;
                line-height: 1.32 !important;
                font-weight: 800 !important;
                color: #111827 !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
            }

            .menu-card .menu-price {
                margin-top: 8px !important;
                font-size: 15px !important;
                line-height: 1.15 !important;
                font-weight: 900 !important;
                color: #111827 !important;
            }

            .menu-card .menu-meta {
                display: none !important;
            }

            .menu-card .menu-add {
                grid-area: add !important;
                position: static !important;
                width: 38px !important;
                height: 38px !important;
                min-width: 38px !important;
                min-height: 38px !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 999px !important;
                background: #111827 !important;
                color: #fff !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 24px !important;
                font-weight: 500 !important;
                line-height: 1 !important;
                box-shadow: 0 4px 10px rgba(15, 23, 42, .12) !important;
            }

            .menu-card .menu-add:active {
                transform: scale(.96) !important;
            }

            .menu-card .menu-add:disabled {
                opacity: .3 !important;
                box-shadow: none !important;
            }
        }

        /* POS Cafe - menu list compact ala aplikasi food-ordering */
        @media(max-width:1023px) {
            .menu-scroll {
                background: #fff !important;
            }

            .menu-grid {
                display: flex !important;
                flex-direction: column !important;
                gap: 0 !important;
            }

            .menu-card {
                display: grid !important;
                grid-template-columns: 88px minmax(0, 1fr) 38px !important;
                grid-template-areas: "image info add" !important;
                align-items: center !important;
                gap: 10px !important;
                min-height: 106px !important;
                padding: 10px 2px !important;
                margin: 0 !important;
                border: 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #fff !important;
                transform: none !important;
            }

            .menu-card:hover {
                border-color: #e5e7eb !important;
                transform: none !important;
            }

            .menu-card:last-child {
                border-bottom: 0 !important;
            }

            .menu-card .menu-image {
                grid-area: image !important;
                width: 88px !important;
                height: 88px !important;
                margin: 0 !important;
                border: 0 !important;
                border-radius: 14px !important;
                overflow: hidden !important;
                background: #f1f5f9 !important;
            }

            .menu-card .menu-image img {
                width: 100% !important;
                height: 100% !important;
                object-fit: cover !important;
            }

            .menu-card .menu-info {
                grid-area: info !important;
                min-width: 0 !important;
                padding: 0 !important;
                align-self: center !important;
            }

            .menu-card .menu-name {
                min-height: 0 !important;
                margin: 0 !important;
                font-size: 13px !important;
                line-height: 1.3 !important;
                font-weight: 800 !important;
                color: #111827 !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
            }

            .menu-card .menu-price {
                margin-top: 6px !important;
                font-size: 14px !important;
                line-height: 1.15 !important;
                font-weight: 900 !important;
                color: #111827 !important;
            }

            .menu-card .menu-meta {
                display: none !important;
            }

            .menu-card .menu-add {
                grid-area: add !important;
                position: static !important;
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 50% !important;
                background: #0f172a !important;
                color: #fff !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-family: Arial, sans-serif !important;
                font-size: 22px !important;
                font-weight: 400 !important;
                line-height: 1 !important;
                letter-spacing: 0 !important;
                text-align: center !important;
                box-shadow: 0 4px 10px rgba(15, 23, 42, .14) !important;
                transform: none !important;
                overflow: hidden !important;
            }

            .menu-card .menu-add:hover,
            .menu-card .menu-add:focus {
                background: #020617 !important;
                color: #fff !important;
                transform: none !important;
            }

            .menu-card .menu-add:active {
                transform: scale(.95) !important;
            }

            .menu-card .menu-add:disabled {
                opacity: .3 !important;
                box-shadow: none !important;
            }
        }

        @media(max-width:640px) {
            .menu-scroll {
                padding: 0 10px 92px !important;
            }

            .menu-card {
                grid-template-columns: 78px minmax(0, 1fr) 34px !important;
                gap: 9px !important;
                min-height: 94px !important;
                padding: 8px 0 !important;
            }

            .menu-card .menu-image {
                width: 78px !important;
                height: 78px !important;
                border-radius: 12px !important;
            }

            .menu-card .menu-name {
                font-size: 12px !important;
            }

            .menu-card .menu-price {
                font-size: 13px !important;
                margin-top: 5px !important;
            }

            .menu-card .menu-add {
                width: 32px !important;
                height: 32px !important;
                min-width: 32px !important;
                min-height: 32px !important;
                font-size: 20px !important;
            }
        }

        @media(min-width:641px) and (max-width:1023px) {
            .menu-scroll {
                padding: 0 18px 100px !important;
            }

            .menu-card {
                max-width: 760px;
            }
        }

        /* Tombol tambah diposisikan tepat di tengah vertikal sisi kanan card */
        @media(max-width:1023px) {
            .menu-card {
                position: relative !important;
                grid-template-columns: 88px minmax(0, 1fr) 42px !important;
            }

            .menu-card .menu-add {
                grid-area: add !important;
                align-self: center !important;
                justify-self: center !important;
                position: static !important;
                margin: auto !important;
            }
        }

        @media(max-width:640px) {
            .menu-card {
                grid-template-columns: 78px minmax(0, 1fr) 40px !important;
            }

            .menu-card .menu-add {
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                margin: auto !important;
                align-self: center !important;
                justify-self: center !important;
            }
        }

        /* Rapikan bar Daftar Pesanan mobile: beri jarak antara ringkasan dan RESET */
        @media(max-width:640px) {
            #mobile-cart>div:first-child {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) auto auto !important;
                align-items: center !important;
                column-gap: 10px !important;
            }

            #mobile-cart>div:first-child>div {
                min-width: 0 !important;
                padding-right: 4px !important;
            }

            #mobile-cart>div:first-child>button {
                margin-left: 0 !important;
                margin-right: 2px !important;
                padding: 7px 10px !important;
                min-height: 30px !important;
                border-radius: 8px !important;
                white-space: nowrap !important;
            }

            #mobile-cart>div:first-child:after {
                margin: 0 !important;
                justify-self: end !important;
                white-space: nowrap !important;
            }
        }

        /* Tombol tambah POS Cafe - desktop */
        @media (min-width: 1024px) {
            .menu-card {
                position: relative;
                padding-bottom: 52px !important;
            }

            .menu-card .menu-add {
                position: absolute !important;
                left: 50% !important;
                right: auto !important;
                bottom: 12px !important;
                top: auto !important;
                transform: translateX(-50%) !important;
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                border: 1px solid #111827 !important;
                border-radius: 50% !important;
                background: #111827 !important;
                color: #fff !important;
                font-size: 22px !important;
                font-weight: 500 !important;
                line-height: 1 !important;
                box-shadow: 0 4px 10px rgba(17, 24, 39, .14) !important;
                cursor: pointer;
                transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
                z-index: 3;
            }

            .menu-card .menu-add:hover {
                transform: translateX(-50%) translateY(-1px) !important;
                background: #000 !important;
                box-shadow: 0 6px 14px rgba(17, 24, 39, .20) !important;
            }

            .menu-card .menu-add:active {
                transform: translateX(-50%) scale(.94) !important;
            }

            .menu-card .menu-meta {
                padding-bottom: 2px;
            }
        }


        /* Premium desktop add button: compact floating action */
        @media (min-width: 1024px) {
            .menu-card {
                position: relative;
                padding-bottom: 58px !important;
                transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
            }

            .menu-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
                border-color: #d7dce3;
            }

            .menu-card .menu-add {
                position: absolute !important;
                left: auto !important;
                right: 12px !important;
                bottom: 12px !important;
                top: auto !important;
                transform: none !important;
                width: 38px !important;
                height: 38px !important;
                min-width: 38px !important;
                min-height: 38px !important;
                padding: 0 !important;
                display: grid !important;
                place-items: center !important;
                border: 0 !important;
                border-radius: 12px !important;
                background: #0f172a !important;
                color: transparent !important;
                font-size: 0 !important;
                box-shadow: 0 7px 16px rgba(15, 23, 42, .20) !important;
                cursor: pointer;
                overflow: hidden;
                transition: transform .16s ease, box-shadow .16s ease, background .16s ease;
                z-index: 4;
            }

            .menu-card .menu-add::before,
            .menu-card .menu-add::after {
                content: "";
                position: absolute;
                left: 50%;
                top: 50%;
                width: 14px;
                height: 2px;
                border-radius: 999px;
                background: #fff;
                transform: translate(-50%, -50%);
            }

            .menu-card .menu-add::after {
                transform: translate(-50%, -50%) rotate(90deg);
            }

            .menu-card .menu-add:hover {
                transform: translateY(-2px) !important;
                background: #020617 !important;
                box-shadow: 0 10px 20px rgba(15, 23, 42, .28) !important;
            }

            .menu-card .menu-add:active {
                transform: scale(.92) !important;
                box-shadow: 0 4px 10px rgba(15, 23, 42, .18) !important;
            }

            .menu-card .menu-meta {
                position: absolute;
                left: 12px;
                right: 60px;
                bottom: 19px;
                margin: 0 !important;
                padding: 0 !important;
                min-height: 22px;
                display: flex;
                align-items: center;
            }

            .menu-card .menu-meta span {
                display: inline-flex;
                align-items: center;
                min-height: 22px;
                padding: 0 8px;
                border-radius: 999px;
                background: #f4f6f8;
                color: #667085 !important;
                font-size: 8px !important;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
        }


        /* Final adjustment: smaller premium add button on desktop */
        @media (min-width: 1024px) {
            .menu-card {
                padding-bottom: 52px !important;
            }

            .menu-card .menu-add {
                right: 11px !important;
                bottom: 11px !important;
                width: 32px !important;
                height: 32px !important;
                min-width: 32px !important;
                min-height: 32px !important;
                border-radius: 10px !important;
                box-shadow: 0 5px 12px rgba(15, 23, 42, .17) !important;
            }

            .menu-card .menu-add::before,
            .menu-card .menu-add::after {
                width: 12px !important;
                height: 2px !important;
            }

            .menu-card .menu-meta {
                right: 54px !important;
                bottom: 16px !important;
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
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3">
                    <input id="search" type="search" placeholder="Cari menu makanan atau minuman..." class="w-full border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-bold outline-none focus:border-black">
                    <button type="button" onclick="openUnpaidOrders()" class="border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-[10px] font-black uppercase">
                        Tagihan Belum Bayar <span id="unpaid-count">0</span>
                    </button>
                </div>
                <div id="categories" class="flex gap-2 overflow-x-auto no-scrollbar"></div>
            </div>
            <div id="add-order-banner" class="hidden mx-4 md:mx-5 mt-3 border border-amber-200 bg-amber-50 px-4 py-3 items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[9px] font-black uppercase tracking-widest text-amber-700">Mode Tambah Pesanan</p>
                    <p id="add-order-banner-text" class="text-xs font-black text-amber-900 mt-1 truncate">-</p>
                </div>
                <button type="button" onclick="cancelAddOrder()" class="shrink-0 border border-amber-300 bg-white px-3 py-2 text-[9px] font-black uppercase">Batal</button>
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
        <div class="payment-card bg-white w-full max-w-lg border border-gray-200 max-h-[94vh] overflow-y-auto">
            <div class="payment-head p-5 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pembayaran Cafe</p>
                    <h3 id="modal-total" class="text-3xl font-black mt-1">Rp 0</h3>
                </div>
                <button onclick="closePayment()" class="text-2xl font-black">&times;</button>
            </div>
            <div class="payment-body p-5 space-y-5">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Tipe Pesanan</label>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <button id="type-dine" onclick="setOrderType('dine_in')" class="py-3 border border-black bg-black text-white text-[10px] font-black uppercase">Dine In</button>
                        <button id="type-takeaway" onclick="setOrderType('takeaway')" class="py-3 border border-gray-200 bg-white text-[10px] font-black uppercase">Takeaway</button>
                    </div>
                </div>
                <div id="table-wrap">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Meja (Opsional)</label>
                    <select id="table-select" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-bold"></select>
                    <p class="text-[9px] text-gray-400 mt-1">Boleh belum memilih meja. Meja bisa ditentukan setelah pelanggan duduk.</p>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Catatan Pesanan</label>
                    <textarea id="order-note" rows="2" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-sm" placeholder="Contoh: tanpa gula, es sedikit"></textarea>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                        Member (Kode / Nama / No HP)
                    </label>

                    <div class="flex gap-2 mt-2">
                        <div class="relative flex-1 min-w-0">
                            <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none">
                                <i data-lucide="user-search" class="w-4 h-4"></i>
                            </span>

                            <input
                                id="member-input"
                                type="text"
                                autocomplete="off"
                                placeholder="Ketik kode, nama, atau no HP member..."
                                oninput="onMemberInput()"
                                onkeydown="onMemberKeydown(event)"
                                class="w-full border border-gray-200 bg-gray-50 pl-10 pr-3 py-3 text-sm font-bold outline-none focus:border-black">

                            <div
                                id="member-suggest"
                                class="hidden absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl z-[240] max-h-56 overflow-y-auto">
                            </div>
                        </div>

                        <button
                            type="button"
                            onclick="cariMember()"
                            class="px-4 py-3 bg-black text-white text-[10px] font-black uppercase shrink-0">
                            Cari
                        </button>

                        <button
                            type="button"
                            onclick="clearMember()"
                            class="px-3 py-3 border border-gray-200 text-gray-500 text-[10px] font-black uppercase shrink-0">
                            ✕
                        </button>
                    </div>

                    <div id="member-info" class="hidden mt-2 border border-green-200 bg-green-50 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p id="member-name" class="text-xs font-black text-green-800 truncate">-</p>
                                <p id="member-meta" class="text-[9px] font-bold text-green-600 mt-1">-</p>
                            </div>
                            <span class="text-[8px] font-black uppercase tracking-widest text-green-700 bg-green-100 px-2 py-1 shrink-0">
                                Member Aktif
                            </span>
                        </div>
                    </div>

                    <div id="member-notfound" class="hidden mt-2 border border-red-200 bg-red-50 p-3">
                        <p class="text-[10px] font-black text-red-600">Member tidak ditemukan.</p>
                    </div>

                    <div id="point-use-wrap" class="hidden mt-2 border border-blue-200 bg-blue-50 p-3">
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-blue-700">Gunakan Point</p>
                                <p class="text-[9px] text-blue-500 font-bold">1 point = Rp <?php echo number_format((int)POINT_RUPIAH, 0, ',', '.'); ?></p>
                            </div>
                            <button type="button" onclick="setPointMax()" class="px-2 py-1 bg-blue-600 text-white text-[9px] font-black uppercase">
                                Max
                            </button>
                        </div>

                        <input
                            id="point-use-input"
                            type="number"
                            min="0"
                            value="0"
                            oninput="updateMemberPointPreview()"
                            class="w-full border border-blue-100 bg-white px-3 py-2 text-sm font-black">

                        <p id="point-preview" class="text-[10px] text-blue-600 font-bold mt-2"></p>
                    </div>
                </div>
                <div id="promo-payment-wrap" class="border border-emerald-200 bg-emerald-50 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700">Promo Cafe</p>
                            <p id="promo-payment-info" class="text-[10px] text-emerald-600 font-bold mt-1">Promo otomatis akan dicek.</p>
                        </div>
                    </div>
                    <select id="promo-select" onchange="onPromoSelectionChange()" class="w-full mt-3 border border-emerald-200 bg-white px-3 py-2 text-xs font-bold">
                        <option value="0">Otomatis - pilih promo terbaik</option>
                    </select>
                </div>

                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Pembayaran</label>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <button id="mode-now" onclick="setPaymentMode('bayar_sekarang')" class="py-3 border border-black bg-black text-white text-[10px] font-black uppercase">Bayar Sekarang</button>
                        <button id="mode-later" onclick="setPaymentMode('bayar_nanti')" class="py-3 border border-gray-200 bg-white text-[10px] font-black uppercase">Bayar Nanti</button>
                    </div>
                </div>

                <div id="payment-method-wrap">
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

    <div id="order-detail-modal" class="fixed inset-0 z-[218] bg-black/50 hidden items-center justify-center p-4">
        <div class="order-detail-card bg-white w-full max-w-lg border border-gray-200 max-h-[90vh] flex flex-col">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between shrink-0">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Detail Pesanan</p>
                    <h3 id="order-detail-title" class="text-lg font-black mt-1">-</h3>
                    <p id="order-detail-meta" class="text-[10px] text-gray-500 mt-1">-</p>
                </div>
                <button type="button" onclick="closeOrderDetail()" class="text-2xl font-black">&times;</button>
            </div>
            <div id="order-detail-body" class="p-4 overflow-y-auto flex-1"></div>
            <div id="order-detail-footer" class="p-4 border-t border-gray-200 shrink-0"></div>
        </div>
    </div>

    <div id="assign-table-modal" class="fixed inset-0 z-[215] bg-black/50 hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-md border border-gray-200">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Pesanan Aktif</p>
                    <h3 id="assign-table-title" class="text-lg font-black mt-1">Atur Meja</h3>
                </div>
                <button type="button" onclick="closeAssignTable()" class="text-2xl font-black">&times;</button>
            </div>
            <div class="p-4 space-y-3">
                <label class="text-[9px] font-black uppercase tracking-widest text-gray-500">Pilih Meja</label>
                <select id="assign-table-select" class="w-full border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-bold"></select>
                <p class="text-[10px] text-gray-400">Pilih “Belum pilih meja” bila pelanggan masih mencari tempat duduk.</p>
                <button type="button" onclick="saveAssignedTable()" class="w-full bg-black text-white py-3 text-[10px] font-black uppercase tracking-widest">Simpan Meja</button>
            </div>
        </div>
    </div>

    <div id="unpaid-modal" class="fixed inset-0 z-[210] bg-black/50 hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl border border-gray-200 max-h-[92vh] overflow-y-auto">
            <div class="p-5 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Cafe</p>
                    <h3 class="text-xl font-black mt-1">Tagihan Belum Bayar</h3>
                </div>
                <button onclick="closeUnpaidOrders()" class="text-2xl font-black">&times;</button>
            </div>
            <div id="unpaid-list" class="p-4 space-y-3"></div>
        </div>
    </div>

    <div id="pay-existing-modal" class="fixed inset-0 z-[220] bg-black/50 hidden items-center justify-center p-4">
        <div class="bg-white w-full max-w-md border border-gray-200">
            <div class="p-5 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pembayaran</p>
                    <h3 id="existing-order-title" class="text-lg font-black mt-1">-</h3>
                </div>
                <button onclick="closeExistingPayment()" class="text-2xl font-black">&times;</button>
            </div>
            <div class="p-5 space-y-4">
                <input type="hidden" id="existing-order-id">
                <p id="existing-order-total" class="text-3xl font-black">Rp 0</p>
                <div id="existing-member-info" class="hidden border border-gray-100 bg-gray-50 p-3 text-xs"></div>
                <div id="existing-point-wrap" class="hidden"><label class="text-[10px] font-black uppercase">Gunakan Point</label><input id="existing-point-input" type="number" min="0" value="0" oninput="updateExistingPointPreview()" class="w-full mt-2 border border-gray-200 px-3 py-2">
                    <p id="existing-point-preview" class="text-[10px] text-gray-500 mt-1"></p>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Metode Pembayaran</label>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <button id="existing-pay-cash" onclick="setExistingPaymentMethod('tunai')" class="py-3 border border-black bg-black text-white text-[10px] font-black uppercase">Tunai</button>
                        <button id="existing-pay-qris" onclick="setExistingPaymentMethod('qris')" class="py-3 border border-gray-200 bg-white text-[10px] font-black uppercase">QRIS / Non Tunai</button>
                    </div>
                </div>
                <div id="existing-cash-wrap">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Uang Diterima</label>
                    <input id="existing-paid-input" type="number" min="0" class="w-full mt-2 border border-gray-200 bg-gray-50 px-4 py-3 text-lg font-black" oninput="updateExistingChange()">
                    <p class="text-xs text-gray-500 mt-2">Kembalian: <strong id="existing-change-label">Rp 0</strong></p>
                </div>
                <button onclick="payExistingOrder()" class="w-full bg-black text-white py-4 text-xs font-black uppercase tracking-[.18em]">Bayar Tagihan</button>
            </div>
        </div>
    </div>

    <script>
        'use strict';
        const ENDPOINT = <?php echo json_encode(basename($_SERVER['PHP_SELF'])); ?>;
        const USER_ID = <?php echo (int)$userId; ?>;
        const STORAGE_KEY = 'sejahub_cafe_cart_' + USER_ID;
        const POINT_VALUE = <?php echo (int)POINT_RUPIAH; ?>;
        const POINT_EARN_THRESHOLD = <?php echo (int)POINT_BELANJA_PER_POIN; ?>;
        let MENU = [];
        let TABLES = [];
        let MEMBERS = [];
        let activeMember = null;
        let cart = [];
        let activeCategory = 'Semua';
        let orderType = 'dine_in';
        let paymentMethod = 'tunai';
        let paymentMode = 'bayar_sekarang';
        let OPEN_ORDERS = [];
        let existingPaymentMethod = 'tunai';
        let existingPaymentTotal = 0;
        let ACTIVE_PROMO = {
            id: 0,
            nama: '',
            diskon: 0,
            boleh_pakai_point: 1
        };
        let PROMO_OPTIONS = [];
        let MANUAL_PROMO_ID = 0;

        let ADDING_ORDER = null;
        let ASSIGNING_ORDER_ID = 0;

        let memberSuggestTimer = null;
        let memberSuggestIndex = -1;
        let memberSuggestData = [];

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

            const found = cart.find(i => Number(i.id) === Number(id));
            if (found) {
                found.qty++
            } else {
                cart.push({
                    id: Number(p.id),
                    nama: p.nama,
                    kode: p.kode,
                    kategori: p.kategori || '',
                    harga_jual: Number(p.harga_jual),
                    qty: 1,
                    catatan: ''
                })
            }
            saveDraft();
            renderCart();
            renderMenu();
            refreshPromo()
        }

        function changeQty(id, delta) {
            const i = cart.find(x => Number(x.id) === Number(id));
            if (!i) return;
            i.qty += delta;
            if (i.qty <= 0) cart = cart.filter(x => Number(x.id) !== Number(id));
            saveDraft();
            renderCart();
            renderMenu();
            refreshPromo()
        }

        function removeItem(id) {
            cart = cart.filter(x => Number(x.id) !== Number(id));
            saveDraft();
            renderCart();
            renderMenu();
            refreshPromo()
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
            renderCart();
            renderMenu();
            refreshPromo()
        }

        function renderMenu() {
            const q = (document.getElementById('search').value || '').toLowerCase();
            const rows = MENU.filter(p =>
                (activeCategory === 'Semua' || p.kategori === activeCategory) &&
                ((p.nama || '').toLowerCase().includes(q) || (p.kode || '').toLowerCase().includes(q))
            );

            const el = document.getElementById('menu-grid');

            el.innerHTML = rows.length ? rows.map(p => {
                return `<article class="menu-card" onclick="addToCart(${Number(p.id)})">
                    <div class="menu-image">
                        ${p.gambar ? `<img src="${escapeHtml(p.gambar)}" alt="${escapeHtml(p.nama)}">` : '<span class="text-3xl">☕</span>'}
                    </div>
                    <div class="menu-info">
                        <h3 class="menu-name text-xs font-black leading-snug min-h-[34px]">${escapeHtml(p.nama)}</h3>
                        <p class="menu-price text-sm font-black mt-2">${rupiah(p.harga_jual)}</p>
                    </div>
                    <div class="menu-meta">
                        <span class="text-[9px] font-bold text-gray-400 truncate">${escapeHtml(p.kategori || 'Menu Cafe')}</span>
                    </div>
                    <button type="button"
                        class="menu-add"
                        onclick="event.stopPropagation();addToCart(${Number(p.id)})">+</button>
                </article>`;
            }).join('') : '<div class="col-span-full py-20 text-center text-xs font-bold text-gray-400 uppercase">Belum ada menu cafe. Atur tipe_produk menjadi cafe pada halaman Menu Cafe.</div>';
        }

        function renderCategories() {
            const cats = ['Semua', ...new Set(MENU.map(p => p.kategori || 'Lainnya'))];
            const container = document.getElementById('categories');

            container.innerHTML = '';

            cats.forEach(function(c) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'category-btn' + (c === activeCategory ? ' active' : '');
                btn.textContent = c;
                btn.addEventListener('click', function() {
                    setCategory(c);
                });
                container.appendChild(btn);
            });
        }

        function setCategory(c) {
            activeCategory = c;
            renderCategories();
            renderMenu();
        }

        function cartItemsHtml() {
            if (!cart.length) return '<div class="py-10 text-center text-[10px] font-black uppercase tracking-widest text-gray-300">Belum ada pesanan</div>';
            return cart.map(i => `<div class="cart-item"><div class="flex justify-between gap-3"><div class="min-w-0"><p class="text-xs font-black">${escapeHtml(i.nama)}</p><p class="text-[10px] text-gray-400 mt-1">${rupiah(i.harga_jual)} / item</p></div><button onclick="removeItem(${i.id})" class="text-red-500 font-black">×</button></div><div class="flex items-center justify-between mt-3"><div class="flex items-center"><button class="qty-btn" onclick="changeQty(${i.id},-1)">−</button><span class="w-10 text-center text-sm font-black">${i.qty}</span><button class="qty-btn" onclick="changeQty(${i.id},1)">+</button></div><strong class="text-sm">${rupiah(i.harga_jual*i.qty)}</strong></div><input value="${escapeHtml(i.catatan||'')}" oninput="changeItemNote(${i.id},this.value)" placeholder="Catatan item" class="w-full mt-3 border border-gray-200 bg-gray-50 px-3 py-2 text-[11px]"></div>`).join('')
        }

        function footerHtml() {
            const total = totalCart();

            if (ADDING_ORDER) {
                return `<div class="space-y-1 mb-3"><div class="flex justify-between text-xs font-bold"><span>Tambahan</span><span>${rupiah(total)}</span></div><div class="text-[9px] font-bold text-amber-700">Akan ditambahkan ke ${escapeHtml(ADDING_ORDER.nomor_pesanan||'pesanan aktif')}</div></div><button onclick="submitAdditionalOrder()" ${cart.length?'':'disabled'} class="w-full py-3 bg-amber-600 text-white text-[10px] font-black uppercase tracking-widest disabled:opacity-30">Kirim Tambahan ke Pesanan</button>`;
            }

            const disc = Number(ACTIVE_PROMO.diskon || 0);
            const net = Math.max(0, total - disc);
            return `<div class="space-y-1 mb-3"><div class="flex justify-between text-xs font-bold"><span>Subtotal</span><span>${rupiah(total)}</span></div>${disc>0?`<div class="flex justify-between text-xs font-black text-green-600"><span>Promo ${escapeHtml(ACTIVE_PROMO.nama||'')}</span><span>-${rupiah(disc)}</span></div>`:''}<div class="flex justify-between text-sm font-black"><span>Total</span><span class="text-blue-600">${rupiah(net)}</span></div></div><button onclick="openPayment()" ${cart.length?'':'disabled'} class="w-full py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest disabled:opacity-30">Bayar Pesanan</button>`
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

        function hideMemberSuggest() {
            const box = document.getElementById('member-suggest');
            if (!box) return;
            box.classList.add('hidden');
            box.innerHTML = '';
            memberSuggestData = [];
            memberSuggestIndex = -1;
        }

        function renderMemberSuggest(rows) {
            const box = document.getElementById('member-suggest');
            if (!box) return;

            memberSuggestData = Array.isArray(rows) ? rows : [];
            memberSuggestIndex = -1;

            if (!memberSuggestData.length) {
                box.innerHTML = '<div class="p-3 text-[10px] font-bold text-gray-400">Member tidak ditemukan.</div>';
                box.classList.remove('hidden');
                return;
            }

            box.innerHTML = memberSuggestData.map(function(m, index) {
                return '<button type="button" ' +
                    'class="member-suggest-item w-full text-left px-3 py-3 border-b border-gray-100 hover:bg-gray-50" ' +
                    'data-index="' + index + '" onclick="chooseMemberSuggest(' + index + ')">' +
                    '<div class="flex items-start justify-between gap-3">' +
                    '<div class="min-w-0">' +
                    '<p class="text-xs font-black text-gray-800 truncate">' + escapeHtml(m.nama || '-') + '</p>' +
                    '<p class="text-[9px] font-bold text-gray-400 mt-1">' +
                    escapeHtml((m.kode || '-') + ' · ' + (m.no_hp || '-')) +
                    '</p>' +
                    '</div>' +
                    '<span class="text-[9px] font-black text-blue-600 shrink-0">' +
                    Number(m.point || 0).toLocaleString('id-ID') + ' pt' +
                    '</span>' +
                    '</div>' +
                    '</button>';
            }).join('');

            box.classList.remove('hidden');
        }

        async function onMemberInput() {
            const input = document.getElementById('member-input');
            const q = input ? input.value.trim() : '';

            if (activeMember) {
                activeMember = null;
                document.getElementById('member-info').classList.add('hidden');
                document.getElementById('point-use-wrap').classList.add('hidden');
                document.getElementById('point-use-input').value = 0;
                updateMemberPointPreview();
            }

            document.getElementById('member-notfound').classList.add('hidden');

            clearTimeout(memberSuggestTimer);

            if (!q) {
                hideMemberSuggest();
                return;
            }

            memberSuggestTimer = setTimeout(async function() {
                try {
                    const d = await api('suggest_member', {
                        q: q
                    });
                    renderMemberSuggest(d.data || []);
                } catch (e) {
                    hideMemberSuggest();
                }
            }, 220);
        }

        function onMemberKeydown(event) {
            const box = document.getElementById('member-suggest');
            if (!box || box.classList.contains('hidden')) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    cariMember();
                }
                return;
            }

            const rows = box.querySelectorAll('.member-suggest-item');

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                memberSuggestIndex = Math.min(memberSuggestIndex + 1, rows.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                memberSuggestIndex = Math.max(memberSuggestIndex - 1, 0);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (memberSuggestIndex >= 0 && memberSuggestData[memberSuggestIndex]) {
                    chooseMemberSuggest(memberSuggestIndex);
                } else {
                    cariMember();
                }
                return;
            } else if (event.key === 'Escape') {
                hideMemberSuggest();
                return;
            } else {
                return;
            }

            rows.forEach(function(el, index) {
                el.classList.toggle('bg-gray-100', index === memberSuggestIndex);
            });

            if (rows[memberSuggestIndex]) {
                rows[memberSuggestIndex].scrollIntoView({
                    block: 'nearest'
                });
            }
        }

        function chooseMemberSuggest(index) {
            const member = memberSuggestData[index];
            if (!member) return;
            setActiveMember(member);
            refreshPromo();
        }

        async function cariMember() {
            const input = document.getElementById('member-input');
            const keyword = input ? input.value.trim() : '';

            if (!keyword) {
                clearMember();
                return;
            }

            try {
                const d = await api('cari_member', {
                    keyword: keyword
                });
                if (!d.success || !d.data) {
                    throw new Error(d.message || 'Member tidak ditemukan.');
                }
                setActiveMember(d.data);
            } catch (e) {
                activeMember = null;
                hideMemberSuggest();
                document.getElementById('member-info').classList.add('hidden');
                document.getElementById('point-use-wrap').classList.add('hidden');
                document.getElementById('member-notfound').classList.remove('hidden');
                document.getElementById('point-use-input').value = 0;
                updateMemberPointPreview();
            }
        }

        function setActiveMember(member) {
            activeMember = member || null;

            const input = document.getElementById('member-input');
            const info = document.getElementById('member-info');
            const notFound = document.getElementById('member-notfound');
            const pointWrap = document.getElementById('point-use-wrap');

            if (!activeMember) {
                clearMember();
                return;
            }

            if (input) {
                input.value = activeMember.kode || activeMember.nama || activeMember.no_hp || '';
            }

            document.getElementById('member-name').textContent = activeMember.nama || '-';
            document.getElementById('member-meta').textContent =
                (activeMember.kode || '-') +
                ' · ' + (activeMember.no_hp || '-') +
                ' · Saldo ' + Number(activeMember.point || 0).toLocaleString('id-ID') + ' pt';

            info.classList.remove('hidden');
            notFound.classList.add('hidden');
            pointWrap.classList.toggle('hidden', paymentMode !== 'bayar_sekarang');

            document.getElementById('point-use-input').value = 0;

            hideMemberSuggest();
            updateMemberPointPreview();
            refreshPromo();
        }

        function clearMember() {
            activeMember = null;

            const input = document.getElementById('member-input');
            if (input) input.value = '';

            document.getElementById('member-info').classList.add('hidden');
            document.getElementById('member-notfound').classList.add('hidden');
            document.getElementById('point-use-wrap').classList.add('hidden');
            document.getElementById('point-use-input').value = 0;

            hideMemberSuggest();
            updateMemberPointPreview();
            refreshPromo();
        }

        function setPointMax() {
            if (!activeMember || paymentMode !== 'bayar_sekarang') return;

            const maxByTotal = Math.floor(Math.max(0, totalCart() - Number(ACTIVE_PROMO.diskon || 0)) / POINT_VALUE);
            const maxPoint = Math.min(Number(activeMember.point || 0), maxByTotal);

            document.getElementById('point-use-input').value = Math.max(0, maxPoint);
            updateMemberPointPreview();
        }

        function currentPointUse() {
            if (!activeMember || paymentMode !== 'bayar_sekarang') return 0;
            if (Number(ACTIVE_PROMO.id || 0) > 0 && Number(ACTIVE_PROMO.boleh_pakai_point || 0) !== 1) return 0;
            const afterPromo = Math.max(0, totalCart() - Number(ACTIVE_PROMO.diskon || 0));
            return Math.min(
                Math.max(0, Number(document.getElementById('point-use-input').value || 0)),
                Number(activeMember.point || 0),
                Math.floor(afterPromo / POINT_VALUE)
            );
        }

        function payableCartTotal() {
            return Math.max(0, totalCart() - Number(ACTIVE_PROMO.diskon || 0) - currentPointUse() * POINT_VALUE);
        }

        function promoRequestItems() {
            return cart.map(function(i) {
                const p = productById(i.id);
                return {
                    id: Number(i.id),
                    qty: Number(i.qty || 0),
                    subtotal: Number(i.harga_jual || 0) * Number(i.qty || 0),
                    kategori: i.kategori || (p ? (p.kategori || '') : '')
                };
            });
        }

        function renderPromoSelect() {
            const select = document.getElementById('promo-select');
            if (!select) return;

            const manualRows = PROMO_OPTIONS.filter(function(p) {
                return String(p.mode_penerapan || '').toLowerCase() === 'manual';
            });

            select.innerHTML = '<option value="0">Otomatis - pilih promo terbaik</option>' +
                manualRows.map(function(p) {
                    return '<option value="' + Number(p.id) + '">' +
                        escapeHtml(p.nama || 'Promo') + ' · -' + rupiah(p.diskon || 0) +
                        '</option>';
                }).join('');

            select.value = String(MANUAL_PROMO_ID || 0);
        }

        function syncPromoPointRule() {
            const pointInput = document.getElementById('point-use-input');
            const pointWrap = document.getElementById('point-use-wrap');
            const pointAllowed = Number(ACTIVE_PROMO.id || 0) === 0 || Number(ACTIVE_PROMO.boleh_pakai_point || 0) === 1;

            if (!pointAllowed && pointInput) {
                pointInput.value = 0;
            }

            if (pointWrap && activeMember && paymentMode === 'bayar_sekarang') {
                pointWrap.classList.toggle('hidden', !pointAllowed);
            }
        }

        async function refreshPromo() {
            if (ADDING_ORDER) {
                ACTIVE_PROMO = {
                    id: 0,
                    nama: '',
                    diskon: 0,
                    boleh_pakai_point: 1
                };
                PROMO_OPTIONS = [];
                MANUAL_PROMO_ID = 0;
                renderCart();
                return;
            }

            if (!cart.length) {
                ACTIVE_PROMO = {
                    id: 0,
                    nama: '',
                    diskon: 0,
                    boleh_pakai_point: 1
                };
                PROMO_OPTIONS = [];
                MANUAL_PROMO_ID = 0;
                renderCart();
                return;
            }

            try {
                const d = await api('promo', {
                    total: totalCart(),
                    member_id: activeMember ? Number(activeMember.id) : 0,
                    promo_id: MANUAL_PROMO_ID,
                    items: promoRequestItems()
                });

                ACTIVE_PROMO = d.data || {
                    id: 0,
                    nama: '',
                    diskon: 0,
                    boleh_pakai_point: 1
                };
                PROMO_OPTIONS = Array.isArray(d.available) ? d.available : [];

                // Promo manual yang sudah tidak memenuhi syarat dikembalikan ke otomatis.
                if (MANUAL_PROMO_ID > 0 && Number(ACTIVE_PROMO.id || 0) !== MANUAL_PROMO_ID) {
                    MANUAL_PROMO_ID = 0;
                    return refreshPromo();
                }
            } catch (e) {
                ACTIVE_PROMO = {
                    id: 0,
                    nama: '',
                    diskon: 0,
                    boleh_pakai_point: 1
                };
                PROMO_OPTIONS = [];
            }

            renderPromoSelect();
            syncPromoPointRule();

            const info = document.getElementById('promo-payment-info');
            if (info) {
                info.textContent = Number(ACTIVE_PROMO.diskon || 0) > 0 ?
                    (String(ACTIVE_PROMO.nama || 'Promo') + ' · potongan ' + rupiah(ACTIVE_PROMO.diskon || 0)) :
                    'Tidak ada promo yang memenuhi syarat.';
            }

            renderCart();
            updateMemberPointPreview();
        }

        function onPromoSelectionChange() {
            const select = document.getElementById('promo-select');
            MANUAL_PROMO_ID = select ? Math.max(0, Number(select.value || 0)) : 0;
            refreshPromo();
        }

        function updateMemberPointPreview() {
            const e = document.getElementById('point-preview');
            if (!e) return;
            const u = currentPointUse(),
                n = payableCartTotal();
            e.textContent = activeMember ? (u + ' point = potongan ' + rupiah(u * POINT_VALUE) + ' · Bayar ' + rupiah(n) + ' · Dapat ' + Math.floor(n / POINT_EARN_THRESHOLD) + ' point') : '';
            document.getElementById('modal-total').textContent = rupiah(n);
            updateChange();
        }

        function openPayment() {
            if (!cart.length) return;
            document.getElementById('modal-total').textContent = rupiah(totalCart());
            setPaymentMode(orderType === 'dine_in' ? 'bayar_nanti' : 'bayar_sekarang');

            // Pertahankan member yang sudah dipilih selama transaksi masih berjalan.
            if (activeMember) {
                setActiveMember(activeMember);
            } else {
                clearMember();
            }

            refreshPromo();
            updateMemberPointPreview();
            document.getElementById('paid-input').value = paymentMethod === 'tunai' ? '' : Math.round(payableCartTotal());
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

        function setPaymentMode(mode) {
            paymentMode = mode;
            const now = mode === 'bayar_sekarang';

            document.getElementById('mode-now').className =
                'py-3 border text-[10px] font-black uppercase ' +
                (now ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');

            document.getElementById('mode-later').className =
                'py-3 border text-[10px] font-black uppercase ' +
                (!now ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');

            document.getElementById('payment-method-wrap').style.display = now ? 'block' : 'none';
            document.getElementById('cash-wrap').style.display =
                now && paymentMethod === 'tunai' ? 'block' : 'none';

            document.getElementById('save-order-button').textContent =
                now ? 'Simpan Pesanan & Bayar' : 'Kirim Pesanan ke Dapur';
            const pw = document.getElementById('point-use-wrap');
            if (pw) pw.classList.toggle('hidden', !now || !activeMember);
            updateMemberPointPreview();
        }

        function setPaymentMethod(method) {
            paymentMethod = method;
            document.getElementById('pay-cash').className = 'py-3 border text-[10px] font-black uppercase ' + (method === 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('pay-qris').className = 'py-3 border text-[10px] font-black uppercase ' + (method !== 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');
            document.getElementById('cash-wrap').style.display = paymentMode === 'bayar_sekarang' && method === 'tunai' ? 'block' : 'none';
            document.getElementById('paid-input').value = method === 'tunai' ? '' : Math.round(payableCartTotal());
            updateChange()
        }

        function updateChange() {
            const paid = Number(document.getElementById('paid-input').value || 0);
            document.getElementById('change-label').textContent = rupiah(Math.max(0, paid - payableCartTotal()))
        }

        function openCafeReceipt(invoice) {
            if (!invoice) return;
            const url = 'struk_cafe.php?invoice=' + encodeURIComponent(invoice) + '&print=1';
            window.open(url, '_blank', 'noopener');
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
                    payment_mode: paymentMode,
                    member_id: activeMember ? Number(activeMember.id) : 0,
                    promo_id: MANUAL_PROMO_ID > 0 ? MANUAL_PROMO_ID : 0,
                    point_pakai: paymentMode === 'bayar_sekarang' ? currentPointUse() : 0,
                    metode_pembayaran: paymentMethod,
                    bayar: paymentMode === 'bayar_sekarang' ?
                        (paymentMethod === 'tunai' ? Number(document.getElementById('paid-input').value || 0) : payableCartTotal()) : 0,
                    catatan: document.getElementById('order-note').value || ''
                };
                const d = await api('simpan', payload);
                if (!d.success) throw new Error(d.message || 'Gagal menyimpan pesanan.');
                const successMessage =
                    (d.status_pembayaran === 'lunas' ?
                        'Pesanan & pembayaran berhasil.\n' :
                        'Pesanan berhasil dikirim ke dapur.\nBayar nanti setelah selesai makan.\n') +
                    d.nomor_pesanan + '\n' + d.invoice + '\nTotal ' + rupiah(d.total);
                if (d.status_pembayaran === 'lunas') {
                    if (confirm(successMessage + '\n\nCetak struk sekarang?')) {
                        openCafeReceipt(d.invoice);
                    }
                } else {
                    alert(successMessage);
                }
                cart = [];
                ACTIVE_PROMO = {
                    id: 0,
                    nama: '',
                    diskon: 0,
                    boleh_pakai_point: 1
                };
                PROMO_OPTIONS = [];
                MANUAL_PROMO_ID = 0;
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

        function startAddOrder(orderId) {
            const order = OPEN_ORDERS.find(o => Number(o.id) === Number(orderId));
            if (!order) return;

            ADDING_ORDER = order;
            cart = [];
            clearDraft();

            const banner = document.getElementById('add-order-banner');
            const text = document.getElementById('add-order-banner-text');
            if (banner) {
                banner.classList.remove('hidden');
                banner.classList.add('flex');
            }
            if (text) {
                const location = order.tipe_pesanan === 'dine_in' ?
                    (order.meja_id ? 'Meja ' + (order.nomor_meja || '-') : 'Belum pilih meja') :
                    'Takeaway';
                text.textContent = (order.nomor_pesanan || '-') + ' · ' + location;
            }

            closeUnpaidOrders();
            renderCart();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        function cancelAddOrder() {
            ADDING_ORDER = null;
            cart = [];
            clearDraft();

            const banner = document.getElementById('add-order-banner');
            if (banner) {
                banner.classList.add('hidden');
                banner.classList.remove('flex');
            }

            renderCart();
            refreshPromo();
        }

        async function submitAdditionalOrder() {
            if (!ADDING_ORDER || !cart.length) return;

            if (!confirm('Tambahkan menu ini ke ' + (ADDING_ORDER.nomor_pesanan || 'pesanan aktif') + '?')) {
                return;
            }

            try {
                const d = await api('add_items', {
                    order_id: Number(ADDING_ORDER.id),
                    items: cart.map(i => ({
                        id: Number(i.id),
                        qty: Number(i.qty || 1),
                        catatan: i.catatan || ''
                    }))
                });

                if (!d.success) throw new Error(d.message || 'Gagal menambah pesanan.');

                alert(
                    'Pesanan tambahan berhasil.\n' +
                    (d.nomor_pesanan || '') + '\n' +
                    'Tambahan ' + rupiah(d.tambahan || 0) + '\n' +
                    'Total tagihan sekarang ' + rupiah(d.total || 0)
                );

                ADDING_ORDER = null;
                cart = [];
                clearDraft();

                const banner = document.getElementById('add-order-banner');
                if (banner) {
                    banner.classList.add('hidden');
                    banner.classList.remove('flex');
                }

                await loadData();
                renderCart();
            } catch (e) {
                alert(e.message);
            }
        }

        function openAssignTable(orderId) {
            const order = OPEN_ORDERS.find(o => Number(o.id) === Number(orderId));
            if (!order) return;

            ASSIGNING_ORDER_ID = Number(order.id);
            document.getElementById('assign-table-title').textContent =
                'Atur Meja · ' + (order.nomor_pesanan || '-');

            const select = document.getElementById('assign-table-select');
            select.innerHTML =
                '<option value="0">Belum pilih meja</option>' +
                TABLES.map(function(t) {
                    const isCurrent = Number(order.meja_id || 0) === Number(t.id);
                    const unavailable = t.status === 'terisi' && !isCurrent;
                    return '<option value="' + Number(t.id) + '" ' +
                        (unavailable ? 'disabled' : '') + '>' +
                        'Meja ' + escapeHtml(t.nomor_meja || '-') +
                        (t.nama_meja ? ' · ' + escapeHtml(t.nama_meja) : '') +
                        (isCurrent ? ' · Saat ini' : '') +
                        (unavailable ? ' · Terisi' : '') +
                        '</option>';
                }).join('');

            select.value = String(Number(order.meja_id || 0));

            closeUnpaidOrders();
            const modal = document.getElementById('assign-table-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeAssignTable() {
            ASSIGNING_ORDER_ID = 0;
            const modal = document.getElementById('assign-table-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        async function saveAssignedTable() {
            if (!ASSIGNING_ORDER_ID) return;

            try {
                const tableId = Number(document.getElementById('assign-table-select').value || 0);
                const d = await api('assign_table', {
                    order_id: ASSIGNING_ORDER_ID,
                    meja_id: tableId
                });

                if (!d.success) throw new Error(d.message || 'Gagal menyimpan meja.');

                closeAssignTable();
                await loadData();
                alert(d.message || 'Meja berhasil diperbarui.');
            } catch (e) {
                alert(e.message);
            }
        }

        async function refreshOpenOrders() {
            const d = await api('open_orders');
            OPEN_ORDERS = d.data || [];

            const countEl = document.getElementById('unpaid-count');
            if (countEl) countEl.textContent = OPEN_ORDERS.length;
        }

        async function openOrderDetail(orderId) {
            try {
                const d = await api('order_detail', {
                    order_id: Number(orderId)
                });
                if (!d.success) throw new Error(d.message || 'Detail pesanan tidak dapat dimuat.');

                const data = d.data || {},
                    o = data.order || {},
                    items = data.items || [];
                const isDineIn = String(o.tipe_pesanan || '') === 'dine_in';
                const location = isDineIn ?
                    (o.meja_id ? 'Dine In · Meja ' + (o.nomor_meja || '-') : 'Dine In · Belum pilih meja') :
                    'Takeaway';

                document.getElementById('order-detail-title').textContent = o.nomor_pesanan || '-';
                document.getElementById('order-detail-meta').textContent = location + (o.member_nama ? ' · ' + o.member_nama : '');

                const groups = {};
                items.forEach(function(item) {
                    const no = Number(item.batch_no || 1);
                    const key = String(no);
                    if (!groups[key]) groups[key] = [];
                    groups[key].push(item);
                });

                const keys = Object.keys(groups).sort(function(a, b) {
                    return Number(a) - Number(b);
                });
                document.getElementById('order-detail-body').innerHTML = keys.length ? keys.map(function(key, idx) {
                    const group = groups[key];
                    const isAdditional = group.some(function(x) {
                        return String(x.batch_jenis || '') === 'tambahan';
                    });
                    const title = isAdditional ? 'Tambahan #' + key : 'Pesanan Awal';
                    return '<section class="mb-5 last:mb-0">' +
                        '<div class="flex items-center gap-2 mb-2"><span class="text-[9px] font-black uppercase tracking-widest ' + (isAdditional ? 'text-amber-700' : 'text-gray-500') + '">' + title + '</span><span class="h-px bg-gray-100 flex-1"></span></div>' +
                        group.map(function(i) {
                            return '<div class="py-2.5 border-b border-gray-100 last:border-0">' +
                                '<div class="flex justify-between gap-3"><div class="min-w-0"><p class="text-xs font-black">' + escapeHtml(i.nama || '-') + '</p><p class="text-[10px] text-gray-500 mt-1">' + Number(i.qty || 0) + ' × ' + rupiah(i.harga || 0) + '</p>' +
                                (i.catatan_item ? '<p class="text-[9px] text-amber-700 mt-1">Catatan: ' + escapeHtml(i.catatan_item) + '</p>' : '') +
                                '</div><span class="text-xs font-black shrink-0">' + rupiah(i.subtotal || 0) + '</span></div>' +
                                '</div>';
                        }).join('') + '</section>';
                }).join('') : '<p class="py-10 text-center text-xs font-bold text-gray-400">Detail menu kosong.</p>';

                document.getElementById('order-detail-footer').innerHTML =
                    '<div class="space-y-1.5">' +
                    '<div class="flex justify-between text-xs"><span class="text-gray-500">Subtotal</span><strong>' + rupiah(data.subtotal || 0) + '</strong></div>' +
                    (Number(data.diskon || 0) > 0 ? '<div class="flex justify-between text-xs text-green-600"><span>Promo ' + escapeHtml(o.promo_nama || '') + '</span><strong>-' + rupiah(data.diskon || 0) + '</strong></div>' : '') +
                    '<div class="flex justify-between text-base pt-2 border-t border-gray-200"><strong>Total Tagihan</strong><strong>' + rupiah(data.total || 0) + '</strong></div>' +
                    '</div><div class="grid grid-cols-2 gap-2 mt-3"><button onclick="closeOrderDetail();startAddOrder(' + Number(o.id) + ')" class="border border-amber-300 bg-amber-50 text-amber-800 py-3 text-[9px] font-black uppercase">+ Tambah Menu</button><button onclick="closeOrderDetail();openExistingPayment(' + Number(o.id) + ')" class="bg-black text-white py-3 text-[9px] font-black uppercase">Bayar</button></div>';

                closeUnpaidOrders();
                const modal = document.getElementById('order-detail-modal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            } catch (e) {
                alert(e.message);
            }
        }

        function closeOrderDetail() {
            const modal = document.getElementById('order-detail-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function openUnpaidOrders() {
            const list = document.getElementById('unpaid-list');

            if (!OPEN_ORDERS.length) {
                list.innerHTML = '<div class="py-12 text-center text-xs font-bold text-gray-400 uppercase">Tidak ada tagihan belum bayar.</div>';
            } else {
                list.innerHTML = OPEN_ORDERS.map(function(o) {
                    const isDineIn = String(o.tipe_pesanan || '') === 'dine_in';
                    const label = isDineIn ?
                        (o.meja_id ? 'Dine In · Meja ' + escapeHtml(o.nomor_meja || '-') : 'Dine In · Belum pilih meja') :
                        'Takeaway';

                    return '<article class="border border-gray-200 p-4">' +
                        '<div class="flex items-start justify-between gap-3">' +
                        '<div>' +
                        '<p class="text-sm font-black">' + escapeHtml(o.nomor_pesanan || '-') + '</p>' +
                        '<p class="text-[10px] text-gray-500 mt-1">' + label + '</p>' +
                        '</div>' +
                        '<span class="text-sm font-black text-amber-700">' + rupiah(o.total_tagihan || 0) + '</span>' +
                        '</div>' +
                        (Number(o.promo_diskon || 0) > 0 ? '<p class="text-[9px] font-black text-green-600 mt-2">Promo ' + escapeHtml(o.promo_nama || '') + ' · -' + rupiah(o.promo_diskon || 0) + '</p>' : '') +
                        '<div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3">' +
                        '<button onclick="openOrderDetail(' + Number(o.id) + ')" class="border border-blue-200 bg-blue-50 text-blue-700 py-3 text-[9px] font-black uppercase">Detail</button>' +
                        '<button onclick="startAddOrder(' + Number(o.id) + ')" class="border border-amber-300 bg-amber-50 text-amber-800 py-3 text-[9px] font-black uppercase">+ Menu</button>' +
                        (isDineIn ?
                            '<button onclick="openAssignTable(' + Number(o.id) + ')" class="border border-gray-300 bg-white py-3 text-[9px] font-black uppercase">Atur Meja</button>' :
                            '<button disabled class="border border-gray-100 bg-gray-50 text-gray-300 py-3 text-[9px] font-black uppercase">Meja -</button>') +
                        '<button onclick="openExistingPayment(' + Number(o.id) + ')" class="border border-black bg-black text-white py-3 text-[9px] font-black uppercase">Bayar</button>' +
                        '</div>' +
                        '</article>';
                }).join('');
            }

            document.getElementById('unpaid-modal').classList.remove('hidden');
            document.getElementById('unpaid-modal').classList.add('flex');
        }

        function closeUnpaidOrders() {
            const m = document.getElementById('unpaid-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function openExistingPayment(orderId) {
            const order = OPEN_ORDERS.find(function(o) {
                return Number(o.id) === Number(orderId);
            });

            if (!order) return;

            existingPaymentTotal = Number(order.total_tagihan || 0);
            existingPaymentMethod = 'tunai';

            document.getElementById('existing-order-id').value = Number(order.id);
            document.getElementById('existing-order-title').textContent =
                order.meja_id ? 'Meja ' + (order.nomor_meja || '-') : (order.nomor_pesanan || '-');
            document.getElementById('existing-order-total').textContent = rupiah(existingPaymentTotal);
            document.getElementById('existing-paid-input').value = '';
            document.getElementById('existing-point-input').value = 0;
            const mi = document.getElementById('existing-member-info'),
                pw = document.getElementById('existing-point-wrap');
            if (Number(order.member_id || 0) > 0) {
                mi.innerHTML = '<strong>' + escapeHtml(order.member_nama || '-') + '</strong><br>Saldo ' + Number(order.member_point || 0).toLocaleString('id-ID') + ' point' +
                    (Number(order.promo_diskon || 0) > 0 ? '<br><span class="text-green-600">Promo ' + escapeHtml(order.promo_nama || '') + ' · -' + rupiah(order.promo_diskon || 0) + '</span>' : '');
                mi.classList.remove('hidden');
                pw.classList.toggle('hidden', Number(order.promo_boleh_pakai_point || 1) !== 1);
            } else {
                mi.classList.add('hidden');
                pw.classList.add('hidden');
            }
            setExistingPaymentMethod('tunai');
            updateExistingPointPreview();
            updateExistingChange();
            closeUnpaidOrders();

            document.getElementById('pay-existing-modal').classList.remove('hidden');
            document.getElementById('pay-existing-modal').classList.add('flex');
        }

        function closeExistingPayment() {
            const m = document.getElementById('pay-existing-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function setExistingPaymentMethod(method) {
            existingPaymentMethod = method;

            document.getElementById('existing-pay-cash').className =
                'py-3 border text-[10px] font-black uppercase ' +
                (method === 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');

            document.getElementById('existing-pay-qris').className =
                'py-3 border text-[10px] font-black uppercase ' +
                (method !== 'tunai' ? 'border-black bg-black text-white' : 'border-gray-200 bg-white');

            document.getElementById('existing-cash-wrap').style.display =
                method === 'tunai' ? 'block' : 'none';

            if (method !== 'tunai') {
                document.getElementById('existing-paid-input').value = Math.round(existingPayableTotal());
            }

            updateExistingChange();
        }

        function existingPointUse() {
            const id = Number(document.getElementById('existing-order-id').value || 0),
                o = OPEN_ORDERS.find(x => Number(x.id) === id);
            if (!o || !o.member_id) return 0;
            if (Number(o.promo_boleh_pakai_point || 1) !== 1) return 0;
            return Math.min(Math.max(0, Number(document.getElementById('existing-point-input').value || 0)), Number(o.member_point || 0), Math.floor(existingPaymentTotal / POINT_VALUE));
        }

        function existingPayableTotal() {
            return Math.max(0, existingPaymentTotal - existingPointUse() * POINT_VALUE);
        }

        function updateExistingPointPreview() {
            const e = document.getElementById('existing-point-preview'),
                u = existingPointUse(),
                n = existingPayableTotal();
            if (e) e.textContent = u + ' point = potongan ' + rupiah(u * POINT_VALUE) + ' · Bayar ' + rupiah(n);
            document.getElementById('existing-order-total').textContent = rupiah(n);
            updateExistingChange();
        }

        function updateExistingChange() {
            const paid = Number(document.getElementById('existing-paid-input').value || 0);
            document.getElementById('existing-change-label').textContent =
                rupiah(Math.max(0, paid - existingPayableTotal()));
        }

        async function payExistingOrder() {
            try {
                const orderId = Number(document.getElementById('existing-order-id').value || 0);
                const paid = existingPaymentMethod === 'tunai' ?
                    Number(document.getElementById('existing-paid-input').value || 0) :
                    existingPayableTotal();

                const d = await api('pay_order', {
                    order_id: orderId,
                    point_pakai: existingPointUse(),
                    metode_pembayaran: existingPaymentMethod,
                    bayar: paid
                });

                if (!d.success) {
                    throw new Error(d.message || 'Pembayaran gagal.');
                }

                const paidMessage =
                    'Pembayaran berhasil.\n' +
                    'Total ' + rupiah(d.total) + '\n' +
                    'Kembalian ' + rupiah(d.kembalian);
                if (confirm(paidMessage + '\n\nCetak struk sekarang?')) {
                    openCafeReceipt(d.invoice);
                }

                closeExistingPayment();
                await loadData();
            } catch (e) {
                alert(e.message);
            }
        }

        async function loadData() {
            const [menu, meja] = await Promise.all([api('menu'), api('meja')]);
            MENU = menu.data || [];
            TABLES = meja.data || [];
            MEMBERS = [];
            const validIds = new Set(MENU.map(p => Number(p.id)));
            cart = cart.filter(i => validIds.has(Number(i.id)));
            document.getElementById('table-select').innerHTML = '<option value="">Belum pilih meja (boleh lanjut)</option>' + TABLES.map(t => `<option value="${Number(t.id)}" ${t.status==='terisi'?'disabled':''}>Meja ${escapeHtml(t.nomor_meja)} · ${escapeHtml(t.status)} · ${Number(t.kapasitas)} orang</option>`).join('');
            renderCategories();
            renderMenu();
            renderCart();
            await refreshPromo();
            await refreshOpenOrders();
            saveDraft()
        }
        document.getElementById('search').addEventListener('input', renderMenu);
        restoreDraft();
        loadData().catch(e => alert(e.message));
    </script>

    <script>
        (function() {
            var mobileCart = document.getElementById('mobile-cart');
            if (!mobileCart) return;

            var head = mobileCart.firstElementChild;
            if (head) {
                head.addEventListener('click', function(e) {
                    if (e.target.closest('button')) return;
                    mobileCart.classList.toggle('cart-open');
                });
            }

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') mobileCart.classList.remove('cart-open');
            });
        })();
    </script>
</body>

</html>