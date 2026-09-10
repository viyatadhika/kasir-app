<?php
/*
|--------------------------------------------------------------------------
| air_pesanan.php — Pengelolaan Pesanan Air
|--------------------------------------------------------------------------
| Halaman internal petugas untuk memproses pesanan publik dari pesan_air.php.
|
| Mendukung:
| - Satu pesanan dengan banyak lokasi pengantaran
| - Banyak jenis produk pada setiap lokasi
| - Rekap total unit, lokasi, dan produk
| - Filter tanggal, status, lokasi, dan pencarian
| - Update status pesanan
| - Detail pesanan responsif untuk desktop, tablet, dan mobile
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

$activeMenu = 'air_pesanan';
$pageTitle  = 'Pemesanan Air';
$backUrl    = 'dashboard.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('air_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function air_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('air_status_label')) {
    function air_status_label(string $status): string
    {
        $map = [
            'baru'         => 'Baru',
            'diproses'     => 'Diproses',
            'siap_dikirim' => 'Siap Dikirim',
            'selesai'      => 'Selesai',
            'batal'        => 'Batal',
        ];

        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('air_status_class')) {
    function air_status_class(string $status): string
    {
        $map = [
            'baru'         => 'bg-blue-50 text-blue-700 border-blue-200',
            'diproses'     => 'bg-amber-50 text-amber-700 border-amber-200',
            'siap_dikirim' => 'bg-purple-50 text-purple-700 border-purple-200',
            'selesai'      => 'bg-green-50 text-green-700 border-green-200',
            'batal'        => 'bg-red-50 text-red-700 border-red-200',
        ];

        return $map[$status] ?? 'bg-gray-50 text-gray-700 border-gray-200';
    }
}

if (!function_exists('air_table_exists')) {
    function air_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
            ");
            $stmt->execute([':table_name' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('air_column_exists')) {
    function air_column_exists(PDO $pdo, string $table, string $column): bool
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

if (!function_exists('air_ensure_schema')) {
    function air_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_pelanggan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kode_pelanggan VARCHAR(30) NOT NULL,
                nama VARCHAR(120) NOT NULL,
                no_hp VARCHAR(30) NULL,
                alamat TEXT NULL,
                status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_air_pelanggan_kode (kode_pelanggan),
                INDEX idx_air_pelanggan_hp (no_hp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_produk (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kode_produk VARCHAR(30) NOT NULL,
                nama_produk VARCHAR(160) NOT NULL,
                jenis VARCHAR(50) NOT NULL DEFAULT 'lainnya',
                harga_jual DECIMAL(15,2) NOT NULL DEFAULT 0,
                stok INT NOT NULL DEFAULT 0,
                satuan VARCHAR(30) NOT NULL DEFAULT 'unit',
                status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_air_produk_kode (kode_produk)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /*
         * Tabel dari versi lama memakai ENUM untuk kolom jenis. Nilai baru seperti
         * dus_330, dus_600, dus_1500, dan refill_galon akan ditolak oleh ENUM lama
         * dan memunculkan Warning 1265. Ubah kolom menjadi VARCHAR agar fleksibel.
         */
        try {
            $stmtJenis = $pdo->query("SHOW COLUMNS FROM air_produk LIKE 'jenis'");
            $jenisInfo = $stmtJenis ? $stmtJenis->fetch(PDO::FETCH_ASSOC) : false;
            $jenisType = $jenisInfo && isset($jenisInfo['Type'])
                ? strtolower((string)$jenisInfo['Type'])
                : '';

            if ($jenisType !== '' && strpos($jenisType, 'varchar') !== 0) {
                $pdo->exec("ALTER TABLE air_produk MODIFY COLUMN jenis VARCHAR(50) NOT NULL DEFAULT 'lainnya'");
            }
        } catch (Throwable $e) {
            error_log('AIR PRODUK MIGRASI JENIS ERROR: ' . $e->getMessage());
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_pesanan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nomor_pesanan VARCHAR(50) NOT NULL,
                pelanggan_id INT NOT NULL,
                user_id INT NULL,
                tanggal_pemesanan DATE NULL,
                tipe_pengambilan ENUM('ambil_sendiri','antar') NOT NULL DEFAULT 'antar',
                alamat_pengiriman TEXT NULL,
                tanggal_kirim DATE NULL,
                jam_kirim TIME NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'baru',
                metode_pembayaran VARCHAR(30) NOT NULL DEFAULT 'tunai',
                status_pembayaran ENUM('belum_bayar','sebagian','lunas') NOT NULL DEFAULT 'belum_bayar',
                total DECIMAL(15,2) NOT NULL DEFAULT 0,
                dibayar DECIMAL(15,2) NOT NULL DEFAULT 0,
                galon_kosong_diterima INT NOT NULL DEFAULT 0,
                galon_dipinjamkan INT NOT NULL DEFAULT 0,
                deposit_galon DECIMAL(15,2) NOT NULL DEFAULT 0,
                catatan TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_air_nomor_pesanan (nomor_pesanan),
                INDEX idx_air_pesanan_status (status),
                INDEX idx_air_pesanan_tanggal (tanggal_kirim),
                INDEX idx_air_pesanan_pelanggan (pelanggan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!air_column_exists($pdo, 'air_pesanan', 'tanggal_pemesanan')) {
            $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN tanggal_pemesanan DATE NULL AFTER user_id");
        }

        if (!air_column_exists($pdo, 'air_pesanan', 'nama_penerima')) {
            $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN nama_penerima VARCHAR(120) NULL AFTER alamat_pengiriman");
        }

        if (!air_column_exists($pdo, 'air_pesanan', 'no_hp_penerima')) {
            $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN no_hp_penerima VARCHAR(30) NULL AFTER nama_penerima");
        }

        if (!air_column_exists($pdo, 'air_pesanan', 'sumber_data')) {
            $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN sumber_data VARCHAR(30) NOT NULL DEFAULT 'publik' AFTER catatan");
        }

        if (!air_column_exists($pdo, 'air_pesanan', 'is_historical')) {
            $pdo->exec("ALTER TABLE air_pesanan ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0 AFTER sumber_data");
        }

        /* Status dibuat VARCHAR agar alur vendor dapat berkembang tanpa migrasi ENUM berulang. */
        try {
            $stmtStatus = $pdo->query("SHOW COLUMNS FROM air_pesanan LIKE 'status'");
            $statusInfo = $stmtStatus ? $stmtStatus->fetch(PDO::FETCH_ASSOC) : false;
            $statusType = $statusInfo && isset($statusInfo['Type'])
                ? strtolower((string)$statusInfo['Type'])
                : '';

            if ($statusType !== '' && strpos($statusType, 'varchar') !== 0) {
                $pdo->exec("ALTER TABLE air_pesanan MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'baru'");
            }
        } catch (Throwable $e) {
            error_log('AIR PESANAN MIGRASI STATUS ERROR: ' . $e->getMessage());
        }

        // Normalisasi status lama ke alur sederhana.
        try {
            $pdo->exec("
                UPDATE air_pesanan
                SET status = CASE
                    WHEN status IN ('direkap', 'dipesan_vendor', 'dikonfirmasi_vendor') THEN 'diproses'
                    WHEN status IN ('barang_diterima', 'dalam_distribusi') THEN 'siap_dikirim'
                    WHEN status IN ('baru', 'diproses', 'siap_dikirim', 'selesai', 'batal') THEN status
                    ELSE 'baru'
                END
            ");
        } catch (Throwable $e) {
            error_log('AIR PESANAN NORMALISASI STATUS ERROR: ' . $e->getMessage());
        }

        /*
         * CREATE TABLE IF NOT EXISTS tidak menambah kolom pada tabel lama.
         * Pastikan kolom updated_at tersedia pada seluruh tabel lama yang masih
         * dipakai oleh modul ini, agar query UPDATE tidak memunculkan error 1054.
         */
        $updatedAtTables = array('air_pelanggan', 'air_produk', 'air_pesanan');
        foreach ($updatedAtTables as $updatedAtTable) {
            if (!air_column_exists($pdo, $updatedAtTable, 'updated_at')) {
                $pdo->exec(
                    "ALTER TABLE `" . $updatedAtTable . "` " .
                        "ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`"
                );
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_pesanan_lokasi (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pesanan_id INT NOT NULL,
                lokasi VARCHAR(150) NOT NULL,
                urutan INT NOT NULL DEFAULT 1,
                catatan VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_air_lokasi_pesanan (pesanan_id),
                INDEX idx_air_lokasi_nama (lokasi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_pesanan_detail (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pesanan_id INT NOT NULL,
                lokasi_id INT NULL,
                produk_id INT NOT NULL,
                kode_produk VARCHAR(30) NOT NULL,
                nama_produk VARCHAR(160) NOT NULL,
                harga DECIMAL(15,2) NOT NULL DEFAULT 0,
                qty INT NOT NULL DEFAULT 1,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                catatan_item VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_air_detail_pesanan (pesanan_id),
                INDEX idx_air_detail_lokasi (lokasi_id),
                INDEX idx_air_detail_produk (produk_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!air_column_exists($pdo, 'air_pesanan_detail', 'lokasi_id')) {
            $pdo->exec("ALTER TABLE air_pesanan_detail ADD COLUMN lokasi_id INT NULL AFTER pesanan_id");
            $pdo->exec("ALTER TABLE air_pesanan_detail ADD INDEX idx_air_detail_lokasi (lokasi_id)");
        }

        // Master produk tidak dibuat, diaktifkan, atau diubah otomatis dari halaman ini.
        // Seluruh pengelolaan master produk dilakukan hanya melalui air_produk.php.

    }
}

$allowedStatuses = [
    'baru',
    'diproses',
    'siap_dikirim',
    'selesai',
    'batal',
];

$locations = [
    'Lobby Gedung Kantor',
    'Candra 1',
    'Candra 2',
    'Sari',
    'Kartika',
    'Auditorium',
    'Serba Guna',
    'Cakra 1',
    'Cakra 2',
    'Cakra 3',
    'Cakra 4',
    'Cakra 5',
    'Samping BSI Gedung Ahmad Yani',
    'Mini Market Koperasi',
];

if (!function_exists('air_sync_status_otomatis')) {
    /**
     * Menyelaraskan status pesanan berdasarkan proses rekap vendor.
     *
     * @return void
     */
    function air_sync_status_otomatis(PDO $pdo): void
    {
        if (
            !air_table_exists($pdo, 'air_vendor_order')
            || !air_table_exists($pdo, 'air_vendor_order_source')
        ) {
            return;
        }

        $pdo->exec("
            UPDATE air_pesanan p
            JOIN air_vendor_order_source s ON s.pesanan_id = p.id
            JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
            SET p.status = 'diproses', p.updated_at = NOW()
            WHERE p.status = 'baru' AND vo.status = 'draft'
        ");

        $pdo->exec("
            UPDATE air_pesanan p
            JOIN air_vendor_order_source s ON s.pesanan_id = p.id
            JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
            SET p.status = 'siap_dikirim', p.updated_at = NOW()
            WHERE p.status NOT IN ('batal', 'selesai')
              AND vo.status IN ('dikirim', 'dikonfirmasi', 'diterima')
        ");

        $pdo->exec("
            UPDATE air_pesanan p
            JOIN air_vendor_order_source s ON s.pesanan_id = p.id
            JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
            SET p.status = 'baru', p.updated_at = NOW()
            WHERE p.status NOT IN ('batal', 'selesai')
              AND vo.status = 'batal'
              AND NOT EXISTS (
                  SELECT 1
                  FROM air_vendor_order_source s2
                  JOIN air_vendor_order vo2 ON vo2.id = s2.vendor_order_id
                  WHERE s2.pesanan_id = p.id AND vo2.status <> 'batal'
              )
        ");

        if (air_table_exists($pdo, 'air_surat_jalan')) {
            $pdo->exec("
                UPDATE air_pesanan p
                JOIN air_vendor_order_source s ON s.pesanan_id = p.id
                JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                JOIN air_surat_jalan sj ON sj.vendor_order_id = vo.id
                SET p.status = 'selesai', p.updated_at = NOW()
                WHERE p.status NOT IN ('batal', 'selesai')
                  AND vo.status = 'diterima'
                  AND TRIM(COALESCE(sj.nomor_surat_jalan, '')) <> ''
            ");
        }
    }
}

$flash = '';
$flashType = 'success';

try {
    air_ensure_schema($pdo);
    air_sync_status_otomatis($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan database pemesanan air: ' . $e->getMessage();
    $flashType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'cancel_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new RuntimeException('Pesanan tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE air_pesanan
                SET status = 'batal', updated_at = NOW()
                WHERE id = :id AND status <> 'selesai'
            ");
            $stmt->execute([':id' => $orderId]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Pesanan selesai tidak dapat dibatalkan.');
            }

            $flash = 'Pesanan berhasil dibatalkan.';
        }

        if ($action === 'delete_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);

            $stmtCheck = $pdo->prepare("
                SELECT status
                FROM air_pesanan
                WHERE id = :id
                LIMIT 1
            ");
            $stmtCheck->execute([':id' => $orderId]);
            $currentStatus = (string)$stmtCheck->fetchColumn();

            if ($currentStatus === '') {
                throw new RuntimeException('Pesanan tidak ditemukan.');
            }

            if (!in_array($currentStatus, ['baru', 'batal'], true)) {
                throw new RuntimeException('Hanya pesanan baru atau batal yang dapat dihapus.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM air_pesanan_detail WHERE pesanan_id = :id");
            $stmt->execute([':id' => $orderId]);

            $stmt = $pdo->prepare("DELETE FROM air_pesanan_lokasi WHERE pesanan_id = :id");
            $stmt->execute([':id' => $orderId]);

            $stmt = $pdo->prepare("DELETE FROM air_pesanan WHERE id = :id");
            $stmt->execute([':id' => $orderId]);

            $pdo->commit();
            $flash = 'Pesanan berhasil dihapus.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$today = date('Y-m-d');
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$locationFilter = trim((string)($_GET['location'] ?? ''));
$dateStart = trim((string)($_GET['start'] ?? ''));
$dateEnd = trim((string)($_GET['end'] ?? ''));

$allowedStatusFilters = array_merge(['', 'semua'], $allowedStatuses);
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

if (!in_array($locationFilter, $locations, true)) {
    $locationFilter = '';
}

if ($dateStart !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) {
    $dateStart = '';
}

if ($dateEnd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
    $dateEnd = '';
}

if ($dateStart !== '' && $dateEnd !== '' && $dateStart > $dateEnd) {
    $tmp = $dateStart;
    $dateStart = $dateEnd;
    $dateEnd = $tmp;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['limit'] ?? 12);

if (!in_array($perPage, [12, 24, 48, 96], true)) {
    $perPage = 12;
}

$where = [];
$params = [];

/*
|--------------------------------------------------------------------------
| Tampilan awal
|--------------------------------------------------------------------------
| Tanpa filter status, tampilkan seluruh pesanan yang masih aktif:
| Baru, Diproses, dan Siap Dikirim. Tidak dibatasi hanya hari ini.
*/
if ($statusFilter === '') {
    $where[] = "p.status NOT IN ('selesai', 'batal')";
} elseif ($statusFilter !== 'semua') {
    $where[] = "p.status = :status";
    $params[':status'] = $statusFilter;
}

/*
 * Tanggal operasional air diseragamkan dengan Rekap Vendor:
 * tanggal_kirim -> tanggal_pemesanan -> created_at.
 * Dengan aturan tunggal ini, filter Pesanan Air dan Rekap Vendor membaca
 * pesanan pada tanggal kebutuhan yang sama.
 */
$operationalDateExpr = "COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at))";

if ($dateStart !== '') {
    $where[] = "$operationalDateExpr >= :start_date";
    $params[':start_date'] = $dateStart;
}

if ($dateEnd !== '') {
    $where[] = "$operationalDateExpr <= :end_date";
    $params[':end_date'] = $dateEnd;
}

if ($q !== '') {
    // Gunakan placeholder unik. PDO MySQL native prepare tidak aman
    // menggunakan named placeholder yang sama berulang kali dalam satu query
    // dan dapat memicu SQLSTATE[HY093]: Invalid parameter number.
    $where[] = "(
        p.nomor_pesanan LIKE :q_nomor
        OR c.nama LIKE :q_nama
        OR c.no_hp LIKE :q_hp
        OR COALESCE(p.nama_penerima, '') LIKE :q_penerima
        OR COALESCE(p.no_hp_penerima, '') LIKE :q_hp_penerima
    )";
    $searchLike = '%' . $q . '%';
    $params[':q_nomor'] = $searchLike;
    $params[':q_nama'] = $searchLike;
    $params[':q_hp'] = $searchLike;
    $params[':q_penerima'] = $searchLike;
    $params[':q_hp_penerima'] = $searchLike;
}


if ($locationFilter !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM air_pesanan_lokasi fl
        WHERE fl.pesanan_id = p.id
          AND fl.lokasi = :location_filter
    )";
    $params[':location_filter'] = $locationFilter;
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

$totalRows = 0;
$totalPages = 1;
$offset = 0;
$orders = [];

try {
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM air_pesanan p
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE $whereSql
    ");
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmtOrders = $pdo->prepare("
        SELECT
            p.*,
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) AS tanggal_operasional,
            c.nama AS nama_pemesan,
            c.no_hp,
            c.alamat AS alamat_pelanggan,
            COALESCE((
                SELECT COUNT(*)
                FROM air_pesanan_lokasi l
                WHERE l.pesanan_id = p.id
            ), 0) AS total_lokasi,
            COALESCE((
                SELECT SUM(d.qty)
                FROM air_pesanan_detail d
                WHERE d.pesanan_id = p.id
            ), 0) AS total_unit,
            (
                SELECT GROUP_CONCAT(
                    DISTINCT l.lokasi
                    ORDER BY l.urutan ASC, l.id ASC
                    SEPARATOR '||'
                )
                FROM air_pesanan_lokasi l
                WHERE l.pesanan_id = p.id
            ) AS daftar_lokasi
        FROM air_pesanan p
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE $whereSql
        ORDER BY
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC,
            p.created_at DESC,
            p.id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmtOrders->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtOrders->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtOrders->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtOrders->execute();

    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    $stmtLocations = $pdo->prepare("
        SELECT
            l.id,
            l.pesanan_id,
            l.lokasi,
            l.urutan,
            l.catatan,
            COALESCE(SUM(d.qty), 0) AS total_unit
        FROM air_pesanan_lokasi l
        LEFT JOIN air_pesanan_detail d ON d.lokasi_id = l.id
        WHERE l.pesanan_id = :order_id
        GROUP BY l.id, l.pesanan_id, l.lokasi, l.urutan, l.catatan
        ORDER BY l.urutan ASC, l.id ASC
    ");

    $stmtDetails = $pdo->prepare("
        SELECT
            id,
            lokasi_id,
            produk_id,
            kode_produk,
            nama_produk,
            harga,
            qty,
            subtotal,
            catatan_item
        FROM air_pesanan_detail
        WHERE pesanan_id = :order_id
        ORDER BY lokasi_id ASC, id ASC
    ");

    foreach ($orders as &$order) {
        $orderId = (int)$order['id'];

        $stmtLocations->execute([':order_id' => $orderId]);
        $order['locations'] = $stmtLocations->fetchAll(PDO::FETCH_ASSOC);

        $stmtDetails->execute([':order_id' => $orderId]);
        $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        $detailsByLocation = [];
        foreach ($details as $detail) {
            $locationId = (int)($detail['lokasi_id'] ?? 0);
            if (!isset($detailsByLocation[$locationId])) {
                $detailsByLocation[$locationId] = [];
            }
            $detailsByLocation[$locationId][] = $detail;
        }

        foreach ($order['locations'] as &$location) {
            $locationId = (int)$location['id'];
            $location['items'] = $detailsByLocation[$locationId] ?? [];
        }
        unset($location);

        // Dukungan data lama yang belum memiliki tabel lokasi.
        if (empty($order['locations']) && !empty($detailsByLocation[0])) {
            $order['locations'] = [[
                'id'         => 0,
                'lokasi'     => $order['alamat_pengiriman'] ?: 'Lokasi belum ditentukan',
                'urutan'     => 1,
                'catatan'    => '',
                'total_unit' => array_sum(array_map(function ($item) {
                    return (int)($item['qty'] ?? 0);
                }, $detailsByLocation[0])),
                'items'      => $detailsByLocation[0],
            ]];
        }
    }
    unset($order);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat pesanan: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$summary = [
    'total'        => 0,
    'baru'         => 0,
    'diproses'     => 0,
    'siap_dikirim' => 0,
    'selesai'      => 0,
    'batal'        => 0,
    'total_unit'   => 0,
];

try {
    $stmtSummary = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN p.status = 'baru' THEN 1 ELSE 0 END), 0) AS baru,
            COALESCE(SUM(CASE WHEN p.status = 'diproses' THEN 1 ELSE 0 END), 0) AS diproses,
            COALESCE(SUM(CASE WHEN p.status = 'siap_dikirim' THEN 1 ELSE 0 END), 0) AS siap_dikirim,
            COALESCE(SUM(CASE WHEN p.status = 'selesai' THEN 1 ELSE 0 END), 0) AS selesai,
            COALESCE(SUM(CASE WHEN p.status = 'batal' THEN 1 ELSE 0 END), 0) AS batal,
            COALESCE(SUM((
                SELECT COALESCE(SUM(d.qty), 0)
                FROM air_pesanan_detail d
                WHERE d.pesanan_id = p.id
            )), 0) AS total_unit
        FROM air_pesanan p
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE $whereSql
    ");
    $stmtSummary->execute($params);
    $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        if (array_key_exists($key, $summaryRow)) {
            $summary[$key] = (int)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
    // Ringkasan tetap nol.
}

$faviconFile = __DIR__ . '/assets/sejahub_icon.png';
$faviconVersion = is_file($faviconFile) ? (string)filemtime($faviconFile) : (string)time();
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$scriptDir = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
$faviconUrl = $scriptDir . '/assets/sejahub_icon.png?v=' . rawurlencode($faviconVersion);

$baseQuery = $_GET;
unset($baseQuery['page']);

function air_page_url(array $query, int $page): string
{
    $query['page'] = max(1, $page);
    return 'air_pesanan.php?' . http_build_query($query);
}

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengelolaan Pesanan Air</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo air_h($faviconUrl); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo air_h($faviconUrl); ?>">
    <link rel="apple-touch-icon" href="<?php echo air_h($faviconUrl); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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

        .air-main {
            min-height: calc(100vh - 64px);
        }

        .card,
        .summary-card,
        .filter-card,
        .table-card,
        .order-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .field {
            width: 100%;
            min-height: 42px;
            padding: 0 12px;
            border: 1px solid #f0f0f0;
            background: #f9fafb;
            color: #374151;
            font-size: 12px;
            font-weight: 700;
            outline: none;
            border-radius: 0 !important;
            transition: all .15s ease;
        }

        .field:focus {
            background: #fff;
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
        }


        .filter-shell {
            padding: 16px;
        }

        .filter-grid-main {
            display: grid;
            grid-template-columns: minmax(280px, 1.8fr) minmax(180px, .9fr) minmax(180px, .9fr) minmax(170px, .9fr) minmax(170px, .9fr);
            gap: 12px;
            align-items: end;
        }

        .filter-field-group {
            min-width: 0;
        }

        .filter-label {
            display: block;
            margin-bottom: 6px;
            font-size: 8px;
            line-height: 1;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #9ca3af;
        }

        .filter-search-wrap {
            position: relative;
        }

        .filter-search-wrap .field {
            padding-left: 42px;
        }

        .filter-search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            width: 15px;
            height: 15px;
            color: #9ca3af;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .filter-actions-bar {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-actions-bar .btn {
            min-width: 112px;
        }

        .filter-limit {
            min-width: 150px;
            margin-left: auto;
        }

        .filter-result {
            margin-left: auto;
            font-size: 10px;
            font-weight: 700;
            color: #9ca3af;
            white-space: nowrap;
        }

        .btn {
            min-height: 42px;
            padding: 0 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-radius: 0 !important;
            transition: all .15s ease;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid;
            padding: 5px 8px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
            border-radius: 0 !important;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            background: currentColor;
            border-radius: 9999px;
        }

        .location-card {
            border: 1px solid #f0f0f0;
            background: #fafafa;
            border-radius: 0 !important;
        }

        .location-index {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 900;
            border-radius: 0 !important;
        }

        .order-card {
            transition: border-color .15s ease;
        }

        .order-card:hover {
            border-color: #e5e7eb;
        }

        .order-action-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            white-space: nowrap;
        }

        .order-action-group form {
            display: inline-flex;
            margin: 0;
        }

        .order-action-btn {
            min-width: 92px;
            height: 38px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 9px;
            font-weight: 900;
            line-height: 1;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
            transition: background .15s ease, color .15s ease, border-color .15s ease, transform .15s ease;
        }

        .order-action-btn svg {
            width: 15px;
            height: 15px;
            stroke-width: 1.9;
            flex-shrink: 0;
        }

        .order-action-btn:hover {
            transform: translateY(-1px);
        }

        .order-action-detail {
            color: #374151;
            border-color: #e5e7eb;
        }

        .order-action-detail:hover {
            color: #2563eb;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .order-action-cancel {
            color: #dc2626;
            border-color: #fecaca;
        }

        .order-action-cancel:hover {
            color: #b91c1c;
            border-color: #fca5a5;
            background: #fef2f2;
        }

        .order-card-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f3f4f6;
        }

        .order-card-actions form {
            display: flex;
            width: 100%;
            margin: 0;
        }

        .order-card-actions .order-action-btn {
            width: 100%;
            min-width: 0;
            height: 42px;
        }

        tbody tr {
            transition: background .15s ease;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }


        .orders-table {
            width: 100%;
            min-width: 1180px;
            table-layout: fixed;
        }

        .orders-table thead th {
            background: #fafafa;
            border-bottom: 1px solid #eef0f3;
            padding: 12px 16px;
            font-size: 9px;
            font-weight: 900;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #94a3b8;
            vertical-align: middle;
        }

        .orders-table tbody td {
            padding: 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f3f4f6;
        }

        .orders-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .orders-table tbody tr:hover {
            background: #fcfcfd;
        }

        .order-number-cell {
            min-width: 0;
        }

        .order-number-main {
            display: block;
            font-size: 12px;
            line-height: 1.45;
            font-weight: 900;
            color: #111827;
            white-space: nowrap;
        }

        .order-number-meta,
        .order-cell-meta {
            margin-top: 4px;
            font-size: 9px;
            line-height: 1.35;
            color: #94a3b8;
        }

        .order-person-name,
        .order-date-main,
        .order-location-main {
            font-size: 12px;
            line-height: 1.45;
            font-weight: 800;
            color: #111827;
        }

        .order-location-main {
            white-space: nowrap;
        }

        .order-product-value {
            font-size: 16px;
            line-height: 1;
            font-weight: 900;
            color: #2563eb;
        }

        .order-product-label {
            margin-top: 4px;
            font-size: 8px;
            line-height: 1;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #94a3b8;
        }

        .orders-table .status-badge {
            min-width: 72px;
            min-height: 26px;
            padding: 5px 9px;
        }

        .orders-table .order-action-group {
            justify-content: flex-end;
            width: 100%;
            gap: 8px;
        }

        .orders-table .order-action-btn {
            min-width: 92px;
            height: 38px;
        }

        .detail-modal-panel {
            max-height: calc(100vh - 32px);
        }

        @media (min-width: 1280px) and (max-width: 1535px) {

            .orders-table thead th,
            .orders-table tbody td {
                padding-left: 12px;
                padding-right: 12px;
            }

            .orders-table .order-action-btn {
                min-width: 84px;
                padding-left: 10px;
                padding-right: 10px;
            }
        }

        @media (min-width: 1024px) {
            .air-main {
                margin-left: 220px;
            }
        }

        @media (max-width: 1023px) {
            .filter-shell {
                padding: 14px !important;
            }

            .filter-grid-main {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .filter-search-group {
                grid-column: 1 / -1;
            }

            .filter-actions-bar {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .filter-actions-bar .btn,
            .filter-limit {
                width: 100%;
                min-width: 0;
                margin-left: 0;
            }

            .filter-result {
                grid-column: 1 / -1;
                margin-left: 0;
                text-align: right;
            }

            body {
                background: #f8fafc;
                padding-bottom: 76px;
            }

            .air-main {
                margin-left: 0;
                padding: 1rem !important;
                padding-bottom: 6.25rem !important;
            }

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: .75rem !important;
            }

            .summary-card,
            .summary-grid>.card {
                min-height: 104px;
                padding: .9rem !important;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                overflow: hidden;
            }

            .filter-card {
                padding: .875rem !important;
                margin-bottom: .875rem !important;
            }

            .field,
            .btn {
                min-height: 44px;
            }

            .order-card {
                border-color: #e5e7eb;
            }
        }

        @media (min-width: 641px) and (max-width: 1023px) {
            .air-main {
                padding: 1rem !important;
            }
        }

        @media (max-width: 640px) {
            .filter-shell {
                padding: 10px !important;
            }

            .filter-grid-main {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .filter-search-group {
                grid-column: auto;
            }

            .filter-actions-bar {
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }

            .filter-limit,
            .filter-result {
                grid-column: 1 / -1;
            }

            .filter-result {
                text-align: left;
                padding-top: 2px;
            }

            .filter-label {
                margin-bottom: 5px;
            }

            .air-main {
                padding: .625rem !important;
                padding-bottom: 6.5rem !important;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important;
            }

            .summary-card,
            .summary-grid>.card {
                min-height: 92px;
                padding: .7rem !important;
            }

            .filter-actions {
                grid-template-columns: 1fr 1fr;
            }

            .order-actions {
                grid-template-columns: 1fr;
            }

            .order-card-actions {
                gap: 6px;
            }

            .order-card-actions .order-action-btn {
                height: 40px;
                padding: 0 8px;
                font-size: 8px;
                letter-spacing: .05em;
            }

            .detail-modal-panel {
                max-height: calc(100vh - 8px);
            }

            #orderDetailModal,
            #statusModal {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            #orderDetailModal>div,
            #statusModal>div {
                width: 100% !important;
                max-width: 100% !important;
                max-height: 100vh;
            }
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">
    <main class="air-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold <?php echo $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                <?php echo air_h($flash); ?>
            </div>
        <?php endif; ?>

        <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 md:mb-8">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-blue-600">Modul Air Mineral</p>
                <h1 class="text-xl md:text-2xl font-light tracking-tight mt-1">
                    Pengelolaan <span class="font-semibold">Pesanan Air</span>
                </h1>
                <p class="text-xs text-gray-400 mt-1">
                    Kelola permintaan pelanggan, rekap kebutuhan vendor, penerimaan, dan distribusi tanpa stok internal.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
                <a href="air_pesanan_import.php"
                    class="btn border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 w-full sm:w-auto">
                    <i data-lucide="archive-restore" class="w-4 h-4"></i>
                    Input Pesanan Lama
                </a>

                <a href="pesan_air.php"
                    target="_blank"
                    class="btn bg-black text-white hover:bg-gray-800 w-full sm:w-auto">
                    <i data-lucide="external-link" class="w-4 h-4"></i>
                    Buka Form Pelanggan
                </a>
            </div>
        </header>

        <div class="mb-5 border border-gray-100 bg-white px-4 py-3">
            <p class="text-[9px] font-black uppercase tracking-widest text-gray-600">Sistem tanpa stok internal</p>
            <p class="text-xs text-gray-500 mt-1">Jumlah pada halaman ini adalah kebutuhan pemesanan. Barang dipenuhi oleh vendor pihak ketiga. Status berubah otomatis mengikuti rekap vendor, penerimaan barang, dan surat jalan. Pesanan hanya dibatalkan manual bila diperlukan.</p>
        </div>

        <div class="summary-grid grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Pesanan</p>
                <p class="text-2xl font-black mt-2"><?php echo number_format($summary['total']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Baru</p>
                <p class="text-2xl font-black text-blue-700 mt-2"><?php echo number_format($summary['baru']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Diproses</p>
                <p class="text-2xl font-black text-amber-700 mt-2"><?php echo number_format($summary['diproses']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-purple-600">Siap Dikirim</p>
                <p class="text-2xl font-black text-purple-700 mt-2"><?php echo number_format($summary['siap_dikirim']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Selesai</p>
                <p class="text-2xl font-black text-green-700 mt-2"><?php echo number_format($summary['selesai']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5">
                <p class="text-[9px] font-black uppercase tracking-widest text-red-600">Batal</p>
                <p class="text-2xl font-black text-red-700 mt-2"><?php echo number_format($summary['batal']); ?></p>
            </div>

            <div class="summary-card card p-4 md:p-5 col-span-2 md:col-span-1">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Produk</p>
                <p class="text-2xl font-black mt-2"><?php echo number_format($summary['total_unit']); ?></p>
                <p class="text-[9px] text-gray-400 mt-1">Unit seluruh pesanan</p>
            </div>
        </div>

        <div class="mb-3 border border-blue-100 bg-blue-50 px-4 py-3">
            <p class="text-[9px] font-black uppercase tracking-widest text-blue-700">Antrian Pesanan Aktif</p>
            <p class="text-xs text-blue-700 mt-1">
                Secara default halaman menampilkan semua pesanan yang belum selesai atau dibatalkan dari seluruh tanggal.
                Filter tanggal memakai tanggal operasional yang sama dengan Rekap Vendor: tanggal kirim, lalu tanggal pemesanan, lalu tanggal input.
            </p>
        </div>

        <form method="get" class="filter-card card filter-shell mb-4">
            <div class="filter-grid-main">
                <div class="filter-field-group filter-search-group">
                    <label class="filter-label">Pencarian</label>
                    <div class="filter-search-wrap">
                        <i data-lucide="search"></i>
                        <input type="search"
                            name="q"
                            value="<?php echo air_h($q); ?>"
                            class="field"
                            placeholder="Cari nomor pesanan, pemesan, penerima, atau nomor WA">
                    </div>
                </div>

                <div class="filter-field-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="field">
                        <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Belum Selesai</option>
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo air_h($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                <?php echo air_h(air_status_label($status)); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="semua" <?php echo $statusFilter === 'semua' ? 'selected' : ''; ?>>Semua Status</option>
                    </select>
                </div>

                <div class="filter-field-group">
                    <label class="filter-label">Lokasi</label>
                    <select name="location" class="field">
                        <option value="">Semua Lokasi</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo air_h($location); ?>" <?php echo $locationFilter === $location ? 'selected' : ''; ?>>
                                <?php echo air_h($location); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field-group">
                    <label class="filter-label">Dari Tanggal Operasional <span class="font-medium normal-case tracking-normal">(opsional)</span></label>
                    <input type="date" name="start" value="<?php echo air_h($dateStart); ?>" class="field">
                </div>

                <div class="filter-field-group">
                    <label class="filter-label">Sampai Tanggal Operasional <span class="font-medium normal-case tracking-normal">(opsional)</span></label>
                    <input type="date" name="end" value="<?php echo air_h($dateEnd); ?>" class="field">
                </div>
            </div>

            <div class="filter-actions-bar">
                <button type="submit" class="btn bg-black text-white">
                    <i data-lucide="filter" class="w-4 h-4"></i>
                    Terapkan
                </button>

                <a href="air_pesanan.php"
                    class="btn border border-gray-200 bg-white text-gray-700">
                    Reset
                </a>

                <select name="limit"
                    onchange="this.form.submit()"
                    class="field filter-limit">
                    <?php foreach ([12, 24, 48, 96] as $limit): ?>
                        <option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>>
                            <?php echo $limit; ?> data
                        </option>
                    <?php endforeach; ?>
                </select>

                <span class="filter-result">
                    <?php echo number_format($totalRows); ?> pesanan ditemukan
                </span>
            </div>
        </form>

        <!-- Desktop: tabel agar data padat dan mudah dibandingkan -->
        <div class="hidden xl:block card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="orders-table text-left">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="w-[18%]">Pesanan</th>
                            <th class="w-[13%]">Pemesan</th>
                            <th class="w-[11%]">Tanggal Operasional</th>
                            <th class="w-[11%]">Tanggal Kirim</th>
                            <th class="w-[15%]">Lokasi</th>
                            <th class="w-[8%] text-center">Produk</th>
                            <th class="w-[10%] text-center">Status</th>
                            <th class="w-[14%] text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $status = (string)($order['status'] ?? 'baru');
                            $orderDate = $order['tanggal_operasional']
                                ?: ($order['tanggal_pemesanan'] ?: date('Y-m-d', strtotime((string)$order['created_at'])));
                            $locationNames = !empty($order['daftar_lokasi'])
                                ? explode('||', (string)$order['daftar_lokasi'])
                                : [];
                            ?>
                            <tr class="hover:bg-gray-50/70 transition-colors">
                                <td class="order-number-cell">
                                    <span class="order-number-main"><?php echo air_h($order['nomor_pesanan']); ?></span>
                                    <p class="order-number-meta">
                                        <?php if (!empty($order['created_at'])): ?>
                                            Input <?php echo air_h(date('d/m/Y H:i', strtotime((string)$order['created_at']))); ?> WIB
                                        <?php else: ?>
                                            Waktu input -
                                        <?php endif; ?>
                                        <?php if (!empty($order['is_historical'])): ?>
                                            <span class="ml-1 text-amber-600 font-black">· DATA LAMA</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td>
                                    <p class="order-person-name truncate"><?php echo air_h($order['nama_pemesan']); ?></p>
                                    <p class="order-cell-meta whitespace-nowrap"><?php echo air_h($order['no_hp'] ?: '-'); ?></p>
                                </td>
                                <td>
                                    <p class="order-date-main whitespace-nowrap"><?php echo air_h(date('d/m/Y', strtotime($orderDate))); ?></p>
                                </td>
                                <td>
                                    <p class="order-date-main whitespace-nowrap"><?php echo !empty($order['tanggal_kirim']) ? air_h(date('d/m/Y', strtotime((string)$order['tanggal_kirim']))) : '-'; ?></p>
                                    <p class="order-cell-meta"><?php echo !empty($order['jam_kirim']) ? air_h(substr((string)$order['jam_kirim'], 0, 5)) . ' WIB' : 'Jam fleksibel'; ?></p>
                                </td>
                                <td>
                                    <p class="order-location-main"><?php echo number_format((int)$order['total_lokasi']); ?> lokasi</p>
                                    <p class="order-cell-meta max-w-[230px] line-clamp-2">
                                        <?php echo $locationNames ? air_h(implode(', ', array_slice($locationNames, 0, 3))) : 'Lokasi belum tersedia'; ?>
                                        <?php echo count($locationNames) > 3 ? ' +' . number_format(count($locationNames) - 3) : ''; ?>
                                    </p>
                                </td>
                                <td class="text-center">
                                    <p class="order-product-value"><?php echo number_format((int)$order['total_unit']); ?></p>
                                    <p class="order-product-label">Unit</p>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?php echo air_status_class($status); ?>">
                                        <?php echo air_h(air_status_label($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="order-action-group">
                                        <button type="button"
                                            onclick='openOrderDetail(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                            class="order-action-btn order-action-detail"
                                            aria-label="Lihat detail pesanan">
                                            <i data-lucide="eye"></i>
                                            <span>Detail</span>
                                        </button>

                                        <?php if (!in_array($status, ['selesai', 'batal'], true)): ?>
                                            <form method="post" onsubmit="return confirm('Batalkan pesanan ini?')">
                                                <input type="hidden" name="action" value="cancel_order">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                <button type="submit"
                                                    class="order-action-btn order-action-cancel"
                                                    aria-label="Batalkan pesanan">
                                                    <span>Batal</span>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="px-3 py-2 text-[9px] font-black uppercase tracking-widest text-gray-400">Otomatis</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orders): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-14 text-center">
                                    <i data-lucide="inbox" class="w-7 h-7 text-gray-300 mx-auto"></i>
                                    <p class="text-sm font-black mt-3">Belum ada pesanan</p>
                                    <p class="text-xs text-gray-400 mt-1">Pesanan pelanggan akan muncul pada halaman ini.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tablet dan mobile: card lebih nyaman disentuh dan dibaca -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:hidden gap-3 md:gap-4">
            <?php foreach ($orders as $order): ?>
                <?php
                $status = (string)($order['status'] ?? 'baru');
                $orderDate = $order['tanggal_operasional']
                    ?: ($order['tanggal_pemesanan'] ?: date('Y-m-d', strtotime((string)$order['created_at'])));
                $locationNames = !empty($order['daftar_lokasi'])
                    ? explode('||', (string)$order['daftar_lokasi'])
                    : [];
                ?>
                <article class="card order-card p-4 sm:p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <p class="text-sm sm:text-base font-black truncate"><?php echo air_h($order['nomor_pesanan']); ?></p>
                                <?php if (!empty($order['is_historical'])): ?>
                                    <span class="shrink-0 border border-amber-200 bg-amber-50 px-2 py-1 text-[8px] font-black uppercase tracking-widest text-amber-700">Data Lama</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs font-bold mt-1 truncate"><?php echo air_h($order['nama_pemesan']); ?></p>
                            <p class="text-[9px] text-gray-400 mt-1"><?php echo air_h($order['no_hp'] ?: '-'); ?></p>
                            <p class="text-[9px] text-gray-400 mt-1">
                                Input sistem:
                                <?php echo !empty($order['created_at'])
                                    ? air_h(date('d/m/Y H:i', strtotime((string)$order['created_at']))) . ' WIB'
                                    : '-'; ?>
                            </p>
                        </div>
                        <span class="status-badge <?php echo air_status_class($status); ?>">
                            <?php echo air_h(air_status_label($status)); ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <div class="bg-gray-50 border border-gray-100 p-3">
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Tanggal Operasional</p>
                            <p class="text-[11px] font-black mt-1"><?php echo air_h(date('d/m/Y', strtotime($orderDate))); ?></p>
                        </div>
                        <div class="bg-gray-50 border border-gray-100 p-3">
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Tanggal Kirim</p>
                            <p class="text-[11px] font-black mt-1"><?php echo !empty($order['tanggal_kirim']) ? air_h(date('d/m/Y', strtotime((string)$order['tanggal_kirim']))) : '-'; ?></p>
                        </div>
                        <div class="bg-blue-50 border border-blue-100 p-3">
                            <p class="text-[8px] font-black uppercase tracking-widest text-blue-500">Produk</p>
                            <p class="text-[11px] font-black text-blue-700 mt-1"><?php echo number_format((int)$order['total_unit']); ?> unit</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Lokasi Pengantaran</p>
                            <span class="text-[9px] font-black text-blue-600"><?php echo number_format((int)$order['total_lokasi']); ?> lokasi</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (array_slice($locationNames, 0, 3) as $locationName): ?>
                                <span class="inline-flex border border-gray-200 bg-white px-2 py-1 text-[9px] font-bold text-gray-600"><?php echo air_h($locationName); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($locationNames) > 3): ?>
                                <span class="inline-flex border border-blue-200 bg-blue-50 px-2 py-1 text-[9px] font-black text-blue-700">+<?php echo number_format(count($locationNames) - 3); ?></span>
                            <?php endif; ?>
                            <?php if (!$locationNames): ?><span class="text-xs text-gray-400">Lokasi belum tersedia.</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="order-card-actions">
                        <button type="button"
                            onclick='openOrderDetail(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                            class="order-action-btn order-action-detail"
                            aria-label="Lihat detail pesanan">
                            <i data-lucide="eye"></i>
                            <span>Detail</span>
                        </button>

                        <?php if (!in_array($status, ['selesai', 'batal'], true)): ?>
                            <form method="post" onsubmit="return confirm('Batalkan pesanan ini?')">
                                <input type="hidden" name="action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                <button type="submit"
                                    class="order-action-btn order-action-cancel"
                                    aria-label="Batalkan pesanan">
                                    <span>Batal</span>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="order-action-btn text-gray-400 border-gray-200 bg-gray-50 cursor-default">
                                Otomatis
                            </span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (!$orders): ?>
                <div class="card p-12 text-center md:col-span-2">
                    <i data-lucide="inbox" class="w-7 h-7 text-gray-300 mx-auto"></i>
                    <p class="text-sm font-black mt-3">Belum ada pesanan</p>
                    <p class="text-xs text-gray-400 mt-1">Pesanan pelanggan akan muncul pada halaman ini.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="mt-4 table-card card px-4 md:px-5 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-gray-50">
                <p class="text-xs text-gray-500">
                    Menampilkan
                    <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $perPage, $totalRows)); ?>
                    dari <?php echo number_format($totalRows); ?> pesanan
                </p>

                <div class="flex items-center gap-1 overflow-x-auto">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo air_h(air_page_url($baseQuery, $page - 1)); ?>"
                            class="btn border border-gray-200 bg-white px-3">
                            &larr;
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="<?php echo air_h(air_page_url($baseQuery, $i)); ?>"
                            class="btn px-3 <?php echo $i === $page ? 'bg-black text-white' : 'border border-gray-200 bg-white'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo air_h(air_page_url($baseQuery, $page + 1)); ?>"
                            class="btn border border-gray-200 bg-white px-3">
                            &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <div id="orderDetailModal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 p-2 sm:p-4">
        <div class="detail-modal-panel w-full max-w-4xl overflow-y-auto bg-white border border-gray-200 rounded-sm">
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-4 py-4 sm:px-6">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">
                        Detail Pesanan Air
                    </p>
                    <h2 id="detailOrderNumber" class="text-lg font-black mt-1">-</h2>
                </div>

                <button type="button"
                    onclick="closeOrderDetail()"
                    class="w-10 h-10 border border-gray-200 bg-white text-xl font-bold">
                    &times;
                </button>
            </div>

            <div id="detailContent" class="p-4 sm:p-6"></div>
        </div>
    </div>

    <script>
        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(value) {
            if (!value) return '-';
            var parts = String(value).substring(0, 10).split('-');
            if (parts.length !== 3) return value;
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }

        function openOrderDetail(order) {
            document.getElementById('detailOrderNumber').textContent =
                order.nomor_pesanan || '-';

            var locations = Array.isArray(order.locations) ? order.locations : [];

            var locationHtml = locations.map(function(location, index) {
                var items = Array.isArray(location.items) ? location.items : [];

                var itemHtml = items.length ?
                    items.map(function(item) {
                        var price = Number(item.harga || 0);
                        var subtotal = Number(item.subtotal || 0);

                        return '' +
                            '<div class="flex items-start justify-between gap-3 py-3 border-b border-gray-100 last:border-b-0">' +
                            '<div class="min-w-0">' +
                            '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">' + escapeHtml(item.kode_produk || '-') + '</p>' +
                            '<p class="text-sm font-bold mt-1">' + escapeHtml(item.nama_produk || '-') + '</p>' +
                            (item.catatan_item ?
                                '<p class="text-[10px] text-amber-700 mt-1">Catatan: ' + escapeHtml(item.catatan_item) + '</p>' :
                                '') +
                            (price > 0 ?
                                '<p class="text-[10px] text-gray-500 mt-1">Harga satuan Rp ' + price.toLocaleString('id-ID') + '</p>' :
                                '') +
                            '</div>' +
                            '<div class="text-right shrink-0">' +
                            '<p class="text-lg font-black">' + Number(item.qty || 0).toLocaleString('id-ID') + '</p>' +
                            '<p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Jumlah</p>' +
                            (subtotal > 0 ?
                                '<p class="text-[10px] font-bold text-gray-600 mt-2">Rp ' + subtotal.toLocaleString('id-ID') + '</p>' :
                                '') +
                            '</div>' +
                            '</div>';
                    }).join('') :
                    '<p class="py-4 text-xs text-gray-400">Belum ada rincian produk.</p>';

                return '' +
                    '<section class="location-card p-4 sm:p-5">' +
                    '<div class="flex items-start gap-3">' +
                    '<span class="location-index">' + (index + 1) + '</span>' +
                    '<div class="min-w-0 flex-1">' +
                    '<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">' +
                    '<div>' +
                    '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Lokasi Pengantaran</p>' +
                    '<p class="text-base font-black mt-1">' + escapeHtml(location.lokasi || '-') + '</p>' +
                    '</div>' +
                    '<div class="sm:text-right">' +
                    '<p class="text-lg font-black text-blue-600">' + Number(location.total_unit || 0).toLocaleString('id-ID') + '</p>' +
                    '<p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Total Unit</p>' +
                    '</div>' +
                    '</div>' +
                    (location.catatan ?
                        '<div class="mt-3 bg-amber-50 border border-amber-100 px-3 py-2 text-xs text-amber-700">' + escapeHtml(location.catatan) + '</div>' :
                        '') +
                    '<div class="mt-3">' + itemHtml + '</div>' +
                    '</div>' +
                    '</div>' +
                    '</section>';
            }).join('');

            var orderDate = order.tanggal_pemesanan || String(order.created_at || '').substring(0, 10);

            var recipientName = order.nama_penerima || '';
            var recipientPhone = order.no_hp_penerima || '';
            var sameRecipient = recipientName && recipientPhone &&
                recipientName === (order.nama_pemesan || '') &&
                recipientPhone === (order.no_hp || '');

            var statusLabel = String(order.status || 'baru')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, function(m) {
                    return m.toUpperCase();
                });

            var html = '' +
                '<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">' +
                '<div class="border border-gray-100 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Pemesan</p>' +
                '<p class="text-sm font-black mt-1">' + escapeHtml(order.nama_pemesan || '-') + '</p>' +
                '<p class="text-xs text-gray-500 mt-1">WA ' + escapeHtml(order.no_hp || '-') + '</p>' +
                '</div>' +
                '<div class="border border-gray-100 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Penerima</p>' +
                '<p class="text-sm font-black mt-1">' + escapeHtml(recipientName || '-') + '</p>' +
                '<p class="text-xs text-gray-500 mt-1">WA ' + escapeHtml(recipientPhone || '-') + '</p>' +
                (sameRecipient ?
                    '<p class="text-[9px] font-black uppercase tracking-widest text-green-600 mt-2">Sama dengan pemesan</p>' :
                    '') +
                '</div>' +
                '<div class="border border-gray-100 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Jadwal Pesanan</p>' +
                '<p class="text-sm font-black mt-1">Tanggal Pemesanan</p>' +
                '<p class="text-xs text-gray-500 mt-1">' + formatDate(orderDate) + '</p>' +
                '<p class="text-sm font-black mt-3">Tanggal Pengiriman</p>' +
                '<p class="text-xs text-gray-500 mt-1">' + formatDate(order.tanggal_kirim) +
                (order.jam_kirim ? ' · ' + String(order.jam_kirim).substring(0, 5) + ' WIB' : ' · Jam fleksibel') +
                '</p>' +
                '</div>' +
                '<div class="border border-gray-100 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Status Pesanan</p>' +
                '<p class="text-sm font-black mt-1">' + escapeHtml(statusLabel) + '</p>' +
                (Number(order.is_historical || 0) === 1 ?
                    '<p class="text-[9px] font-black uppercase tracking-widest text-amber-600 mt-2">Data Lama</p>' :
                    '') +
                '</div>' +
                '</div>' +

                '<div class="mt-4 border border-gray-100 bg-gray-50 p-4">' +
                '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">' +
                '<div>' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Nomor Pesanan</p>' +
                '<p class="text-sm font-black mt-1">' + escapeHtml(order.nomor_pesanan || '-') + '</p>' +
                '</div>' +
                '<div>' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">ID Data</p>' +
                '<p class="text-sm font-black mt-1">#' + escapeHtml(order.id || '-') + '</p>' +
                '</div>' +
                '</div>' +
                '</div>' +

                '<div class="grid grid-cols-2 gap-3 mt-4">' +
                '<div class="border border-gray-100 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Lokasi</p>' +
                '<p class="text-2xl font-black mt-1">' + Number(order.total_lokasi || locations.length).toLocaleString('id-ID') + '</p>' +
                '</div>' +
                '<div class="border border-gray-100 p-4">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Produk</p>' +
                '<p class="text-2xl font-black text-blue-600 mt-1">' + Number(order.total_unit || 0).toLocaleString('id-ID') + ' unit</p>' +
                '</div>' +
                '</div>' +

                '<div class="mt-5">' +
                '<p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-3">Rincian Distribusi</p>' +
                '<div class="space-y-3">' +
                (locationHtml || '<div class="border border-gray-100 p-6 text-center text-xs text-gray-400">Belum ada rincian lokasi.</div>') +
                '</div>' +
                '</div>' +

                (order.catatan ?
                    '<div class="mt-5 border border-amber-100 bg-amber-50 p-4">' +
                    '<p class="text-[9px] font-black uppercase tracking-widest text-amber-700">Catatan</p>' +
                    '<p class="text-xs text-amber-700 mt-1">' + escapeHtml(order.catatan) + '</p>' +
                    '</div>' :
                    '');

            document.getElementById('detailContent').innerHTML = html;

            var modal = document.getElementById('orderDetailModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            document.body.style.overflow = 'hidden';
        }

        function closeOrderDetail() {
            var modal = document.getElementById('orderDetailModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        document.getElementById('orderDetailModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeOrderDetail();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeOrderDetail();
            }
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>

</html>