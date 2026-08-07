<?php
/*
|--------------------------------------------------------------------------
| air_rekap_vendor.php — Rekap Pemesanan ke Vendor Air Mineral
|--------------------------------------------------------------------------
| Menggabungkan kebutuhan pelanggan berdasarkan tanggal pengiriman,
| membuat pesanan vendor, menghasilkan pesan WhatsApp, dan menyimpan jejak
| jumlah diminta/dipesan/dikonfirmasi/diterima.
|
| Compatible PHP 7 & PHP 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';

/*
 * Sementara memakai izin air_pesanan.php agar halaman langsung dapat dibuka
 * oleh role air_mineral. Setelah config_roles.php diperbarui, dapat diganti
 * menjadi requireAccess();
 */
requireAccess('air_pesanan.php');

$activeMenu = 'air_rekap_vendor';
$pageTitle  = 'Rekap Vendor Air Mineral';
$backUrl    = 'air_pesanan.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('arv_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function arv_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('arv_user_id')) {
    function arv_user_id(): int
    {
        if (!empty($_SESSION['user']['id'])) {
            return (int)$_SESSION['user']['id'];
        }
        if (!empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        if (!empty($_SESSION['id'])) {
            return (int)$_SESSION['id'];
        }
        return 0;
    }
}

if (!function_exists('arv_column_exists')) {
    function arv_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = :table_name\n                  AND COLUMN_NAME = :column_name\n            ");
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

if (!function_exists('arv_generate_number')) {
    function arv_generate_number(): string
    {
        return 'V-AIR-' . date('Ymd-His') . '-' . random_int(10, 99);
    }
}

if (!function_exists('arv_normalize_wa')) {
    function arv_normalize_wa(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        if ($number === '') {
            return '';
        }
        if (strpos($number, '0') === 0) {
            return '62' . substr($number, 1);
        }
        if (strpos($number, '62') !== 0) {
            return '62' . $number;
        }
        return $number;
    }
}

if (!function_exists('arv_status_label')) {
    function arv_status_label(string $status): string
    {
        $map = [
            'draft'        => 'Draft',
            'dikirim'      => 'Dikirim ke Vendor',
            'dikonfirmasi' => 'Dikonfirmasi',
            'diterima'     => 'Barang Diterima',
            'selesai'      => 'Selesai',
            'batal'        => 'Batal',
        ];
        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('arv_status_class')) {
    function arv_status_class(string $status): string
    {
        $map = [
            'draft'        => 'bg-gray-50 text-gray-600 border-gray-200',
            'dikirim'      => 'bg-blue-50 text-blue-700 border-blue-200',
            'dikonfirmasi' => 'bg-amber-50 text-amber-700 border-amber-200',
            'diterima'     => 'bg-purple-50 text-purple-700 border-purple-200',
            'selesai'      => 'bg-green-50 text-green-700 border-green-200',
            'batal'        => 'bg-red-50 text-red-700 border-red-200',
        ];
        return $map[$status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
    }
}

if (!function_exists('arv_ensure_schema')) {
    function arv_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_order (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                nomor_vendor_order VARCHAR(60) NOT NULL,\n                tanggal_rekap DATE NOT NULL,\n                tanggal_kebutuhan DATE NOT NULL,\n                vendor_nama VARCHAR(150) NOT NULL,\n                vendor_wa VARCHAR(30) NULL,\n                status VARCHAR(30) NOT NULL DEFAULT 'draft',\n                total_unit INT NOT NULL DEFAULT 0,\n                catatan TEXT NULL,\n                created_by INT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                UNIQUE KEY uq_air_vendor_nomor (nomor_vendor_order),\n                INDEX idx_air_vendor_tanggal (tanggal_kebutuhan),\n                INDEX idx_air_vendor_status (status)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_order_detail (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                vendor_order_id INT NOT NULL,\n                produk_id INT NULL,\n                kode_produk VARCHAR(30) NULL,\n                nama_produk VARCHAR(180) NOT NULL,\n                satuan VARCHAR(30) NOT NULL DEFAULT 'unit',\n                jumlah_diminta INT NOT NULL DEFAULT 0,\n                jumlah_dipesan INT NOT NULL DEFAULT 0,\n                jumlah_dikonfirmasi INT NOT NULL DEFAULT 0,\n                jumlah_diterima INT NOT NULL DEFAULT 0,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                INDEX idx_air_vendor_detail_order (vendor_order_id),\n                INDEX idx_air_vendor_detail_produk (produk_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_order_source (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                vendor_order_id INT NOT NULL,\n                pesanan_id INT NOT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                UNIQUE KEY uq_air_vendor_source (vendor_order_id, pesanan_id),\n                INDEX idx_air_vendor_source_pesanan (pesanan_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        // Pastikan status pesanan pelanggan fleksibel dan menggunakan status sederhana.
        try {
            $statusColumn = $pdo->query("SHOW COLUMNS FROM air_pesanan LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            $statusType = strtolower((string)($statusColumn['Type'] ?? ''));
            if ($statusType !== '' && strpos($statusType, 'varchar') !== 0) {
                $pdo->exec("ALTER TABLE air_pesanan MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'baru'");
            }
        } catch (Throwable $e) {
            error_log('AIR VENDOR MIGRASI STATUS ERROR: ' . $e->getMessage());
        }
    }
}

$allowedVendorStatuses = ['draft', 'dikirim', 'dikonfirmasi', 'diterima', 'selesai', 'batal'];
$flash = '';
$flashType = 'success';

try {
    arv_ensure_schema($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan database rekap vendor: ' . $e->getMessage();
    $flashType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'error') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_vendor_order') {
            $tanggalKebutuhan = trim((string)($_POST['tanggal_kebutuhan'] ?? ''));
            $vendorNama = trim((string)($_POST['vendor_nama'] ?? ''));
            $vendorWa = trim((string)($_POST['vendor_wa'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $sourceIds = isset($_POST['source_ids']) && is_array($_POST['source_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['source_ids']))))
                : [];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalKebutuhan)) {
                throw new RuntimeException('Tanggal kebutuhan tidak valid.');
            }
            if ($vendorNama === '') {
                throw new RuntimeException('Nama vendor wajib diisi.');
            }
            if (!$sourceIds) {
                throw new RuntimeException('Pilih minimal satu pesanan pelanggan untuk direkap.');
            }

            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $stmtEligible = $pdo->prepare("\n                SELECT p.id\n                FROM air_pesanan p\n                WHERE p.id IN ($placeholders)\n                  AND p.status NOT IN ('batal', 'selesai')\n                  AND COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) = ?\n                  AND NOT EXISTS (\n                      SELECT 1\n                      FROM air_vendor_order_source s\n                      JOIN air_vendor_order vo ON vo.id = s.vendor_order_id\n                      WHERE s.pesanan_id = p.id\n                        AND vo.status <> 'batal'\n                  )\n                FOR UPDATE\n            ");

            $pdo->beginTransaction();
            $eligibleArgs = array_merge($sourceIds, [$tanggalKebutuhan]);
            $stmtEligible->execute($eligibleArgs);
            $eligibleIds = array_map('intval', $stmtEligible->fetchAll(PDO::FETCH_COLUMN));

            if (!$eligibleIds) {
                throw new RuntimeException('Pesanan yang dipilih sudah direkap atau tidak lagi tersedia.');
            }

            $eligiblePlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $stmtRecap = $pdo->prepare("\n                SELECT\n                    d.produk_id,\n                    d.kode_produk,\n                    d.nama_produk,\n                    COALESCE(pr.satuan, 'unit') AS satuan,\n                    SUM(d.qty) AS jumlah_diminta\n                FROM air_pesanan_detail d\n                LEFT JOIN air_produk pr ON pr.id = d.produk_id\n                WHERE d.pesanan_id IN ($eligiblePlaceholders)\n                GROUP BY d.produk_id, d.kode_produk, d.nama_produk, pr.satuan\n                HAVING SUM(d.qty) > 0\n                ORDER BY d.nama_produk ASC\n            ");
            $stmtRecap->execute($eligibleIds);
            $recapRows = $stmtRecap->fetchAll(PDO::FETCH_ASSOC);

            if (!$recapRows) {
                throw new RuntimeException('Rincian produk pada pesanan yang dipilih tidak ditemukan.');
            }

            $nomor = arv_generate_number();
            $totalUnit = array_sum(array_map(function ($row) {
                return (int)($row['jumlah_diminta'] ?? 0);
            }, $recapRows));

            $stmtOrder = $pdo->prepare("\n                INSERT INTO air_vendor_order (\n                    nomor_vendor_order, tanggal_rekap, tanggal_kebutuhan,\n                    vendor_nama, vendor_wa, status, total_unit, catatan,\n                    created_by, created_at\n                ) VALUES (\n                    :nomor, CURDATE(), :tanggal_kebutuhan, :vendor_nama,\n                    :vendor_wa, 'draft', :total_unit, :catatan, :created_by, NOW()\n                )\n            ");
            $stmtOrder->execute([
                ':nomor'             => $nomor,
                ':tanggal_kebutuhan' => $tanggalKebutuhan,
                ':vendor_nama'       => $vendorNama,
                ':vendor_wa'         => $vendorWa,
                ':total_unit'        => $totalUnit,
                ':catatan'           => $catatan,
                ':created_by'        => arv_user_id() ?: null,
            ]);
            $vendorOrderId = (int)$pdo->lastInsertId();

            $postedOrdered = isset($_POST['jumlah_dipesan']) && is_array($_POST['jumlah_dipesan'])
                ? $_POST['jumlah_dipesan']
                : [];

            $stmtDetail = $pdo->prepare("\n                INSERT INTO air_vendor_order_detail (\n                    vendor_order_id, produk_id, kode_produk, nama_produk, satuan,\n                    jumlah_diminta, jumlah_dipesan, jumlah_dikonfirmasi, jumlah_diterima, created_at\n                ) VALUES (\n                    :vendor_order_id, :produk_id, :kode_produk, :nama_produk, :satuan,\n                    :jumlah_diminta, :jumlah_dipesan, 0, 0, NOW()\n                )\n            ");

            foreach ($recapRows as $row) {
                $key = (string)($row['produk_id'] ?? 0) . '|' . (string)($row['kode_produk'] ?? '');
                $requested = max(0, (int)$row['jumlah_diminta']);
                $ordered = isset($postedOrdered[$key])
                    ? max(0, (int)$postedOrdered[$key])
                    : $requested;

                $stmtDetail->execute([
                    ':vendor_order_id' => $vendorOrderId,
                    ':produk_id'       => !empty($row['produk_id']) ? (int)$row['produk_id'] : null,
                    ':kode_produk'     => (string)($row['kode_produk'] ?? ''),
                    ':nama_produk'     => (string)$row['nama_produk'],
                    ':satuan'          => (string)($row['satuan'] ?: 'unit'),
                    ':jumlah_diminta'  => $requested,
                    ':jumlah_dipesan'  => $ordered,
                ]);
            }

            $stmtSource = $pdo->prepare("\n                INSERT IGNORE INTO air_vendor_order_source\n                    (vendor_order_id, pesanan_id, created_at)\n                VALUES (:vendor_order_id, :pesanan_id, NOW())\n            ");
            foreach ($eligibleIds as $pesananId) {
                $stmtSource->execute([
                    ':vendor_order_id' => $vendorOrderId,
                    ':pesanan_id'      => $pesananId,
                ]);
            }

            $updatePlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $stmtUpdateOrders = $pdo->prepare("\n                UPDATE air_pesanan\n                SET status = 'diproses', updated_at = NOW()\n                WHERE id IN ($updatePlaceholders)\n            ");
            $stmtUpdateOrders->execute($eligibleIds);

            $pdo->commit();
            $flash = 'Rekap vendor ' . $nomor . ' berhasil dibuat dari ' . count($eligibleIds) . ' pesanan pelanggan.';
        }

        if ($action === 'update_vendor_status') {
            $vendorOrderId = (int)($_POST['vendor_order_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));

            if ($vendorOrderId <= 0 || !in_array($status, $allowedVendorStatuses, true)) {
                throw new RuntimeException('Status rekap vendor tidak valid.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                UPDATE air_vendor_order\n                SET status = :status, updated_at = NOW()\n                WHERE id = :id\n            ");
            $stmt->execute([
                ':status' => $status,
                ':id'     => $vendorOrderId,
            ]);

            if ($status === 'diterima') {
                $stmtCustomer = $pdo->prepare("\n                    UPDATE air_pesanan p\n                    JOIN air_vendor_order_source s ON s.pesanan_id = p.id\n                    SET p.status = 'siap_dikirim', p.updated_at = NOW()\n                    WHERE s.vendor_order_id = :vendor_order_id\n                      AND p.status NOT IN ('batal', 'selesai')\n                ");
                $stmtCustomer->execute([':vendor_order_id' => $vendorOrderId]);
            } elseif ($status === 'batal') {
                $stmtCustomer = $pdo->prepare("\n                    UPDATE air_pesanan p\n                    JOIN air_vendor_order_source s ON s.pesanan_id = p.id\n                    SET p.status = 'baru', p.updated_at = NOW()\n                    WHERE s.vendor_order_id = :vendor_order_id\n                      AND p.status = 'diproses'\n                ");
                $stmtCustomer->execute([':vendor_order_id' => $vendorOrderId]);
            }

            $pdo->commit();
            $flash = 'Status rekap vendor berhasil diperbarui.';
        }

        if ($action === 'save_quantities') {
            $vendorOrderId = (int)($_POST['vendor_order_id'] ?? 0);
            $detailIds = isset($_POST['detail_id']) && is_array($_POST['detail_id'])
                ? $_POST['detail_id']
                : [];
            $confirmed = isset($_POST['jumlah_dikonfirmasi']) && is_array($_POST['jumlah_dikonfirmasi'])
                ? $_POST['jumlah_dikonfirmasi']
                : [];
            $received = isset($_POST['jumlah_diterima']) && is_array($_POST['jumlah_diterima'])
                ? $_POST['jumlah_diterima']
                : [];

            if ($vendorOrderId <= 0 || !$detailIds) {
                throw new RuntimeException('Rincian penerimaan tidak valid.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("\n                UPDATE air_vendor_order_detail\n                SET jumlah_dikonfirmasi = :confirmed,\n                    jumlah_diterima = :received,\n                    updated_at = NOW()\n                WHERE id = :id\n                  AND vendor_order_id = :vendor_order_id\n            ");

            $hasReceived = false;
            foreach ($detailIds as $index => $detailIdRaw) {
                $detailId = (int)$detailIdRaw;
                $confirmedQty = max(0, (int)($confirmed[$index] ?? 0));
                $receivedQty = max(0, (int)($received[$index] ?? 0));
                if ($receivedQty > 0) {
                    $hasReceived = true;
                }
                $stmt->execute([
                    ':confirmed'       => $confirmedQty,
                    ':received'        => $receivedQty,
                    ':id'              => $detailId,
                    ':vendor_order_id' => $vendorOrderId,
                ]);
            }

            if ($hasReceived) {
                $stmtOrder = $pdo->prepare("\n                    UPDATE air_vendor_order\n                    SET status = 'diterima', updated_at = NOW()\n                    WHERE id = :id\n                      AND status <> 'batal'\n                ");
                $stmtOrder->execute([':id' => $vendorOrderId]);

                $stmtCustomer = $pdo->prepare("\n                    UPDATE air_pesanan p\n                    JOIN air_vendor_order_source s ON s.pesanan_id = p.id\n                    SET p.status = 'siap_dikirim', p.updated_at = NOW()\n                    WHERE s.vendor_order_id = :vendor_order_id\n                      AND p.status NOT IN ('batal', 'selesai')\n                ");
                $stmtCustomer->execute([':vendor_order_id' => $vendorOrderId]);
            }

            $pdo->commit();
            $flash = 'Jumlah konfirmasi dan penerimaan berhasil disimpan.';
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
$needDate = trim((string)($_GET['need_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $needDate)) {
    $needDate = $today;
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($statusFilter, $allowedVendorStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$allowedLimits = [10, 15, 25, 50];
$perPage = (int)($_GET['limit'] ?? 15);
if (!in_array($perPage, $allowedLimits, true)) {
    $perPage = 15;
}

// Pesanan pelanggan yang belum masuk rekap vendor aktif.
$availableOrders = [];
$availableRecap = [];
$availableOrderProducts = [];
$availableTotalUnit = 0;
try {
    $stmtAvailableOrders = $pdo->prepare("\n        SELECT\n            p.id, p.nomor_pesanan, p.tanggal_kirim, p.tanggal_pemesanan, p.created_at,\n            c.nama AS nama_pemesan, c.no_hp,\n            COALESCE((SELECT SUM(d.qty) FROM air_pesanan_detail d WHERE d.pesanan_id = p.id), 0) AS total_unit,\n            COALESCE((SELECT COUNT(*) FROM air_pesanan_lokasi l WHERE l.pesanan_id = p.id), 0) AS total_lokasi\n        FROM air_pesanan p\n        JOIN air_pelanggan c ON c.id = p.pelanggan_id\n        WHERE COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) = :need_date\n          AND p.status NOT IN ('batal', 'selesai')\n          AND NOT EXISTS (\n              SELECT 1\n              FROM air_vendor_order_source s\n              JOIN air_vendor_order vo ON vo.id = s.vendor_order_id\n              WHERE s.pesanan_id = p.id\n                AND vo.status <> 'batal'\n          )\n        ORDER BY p.created_at ASC, p.id ASC\n    ");
    $stmtAvailableOrders->execute([':need_date' => $needDate]);
    $availableOrders = $stmtAvailableOrders->fetchAll(PDO::FETCH_ASSOC);

    // Tambahkan rincian lokasi + produk per pesanan untuk ditampilkan di modal rekap.
    $stmtSourceLocations = $pdo->prepare("
        SELECT
            l.id,
            l.pesanan_id,
            l.lokasi,
            l.urutan,
            l.catatan
        FROM air_pesanan_lokasi l
        WHERE l.pesanan_id = :pesanan_id
        ORDER BY l.urutan ASC, l.id ASC
    ");

    $stmtSourceItems = $pdo->prepare("
        SELECT
            d.lokasi_id,
            d.nama_produk,
            d.qty,
            COALESCE(pr.satuan, 'unit') AS satuan
        FROM air_pesanan_detail d
        LEFT JOIN air_produk pr ON pr.id = d.produk_id
        WHERE d.pesanan_id = :pesanan_id
        ORDER BY d.lokasi_id ASC, d.id ASC
    ");

    foreach ($availableOrders as &$availableOrder) {
        $pesananId = (int)$availableOrder['id'];

        $stmtSourceLocations->execute([':pesanan_id' => $pesananId]);
        $sourceLocations = $stmtSourceLocations->fetchAll(PDO::FETCH_ASSOC);

        $stmtSourceItems->execute([':pesanan_id' => $pesananId]);
        $sourceItems = $stmtSourceItems->fetchAll(PDO::FETCH_ASSOC);

        $itemsByLocation = [];
        foreach ($sourceItems as $sourceItem) {
            $lokasiId = (int)($sourceItem['lokasi_id'] ?? 0);
            if (!isset($itemsByLocation[$lokasiId])) {
                $itemsByLocation[$lokasiId] = [];
            }
            $itemsByLocation[$lokasiId][] = $sourceItem;
        }

        foreach ($sourceLocations as &$sourceLocation) {
            $lokasiId = (int)$sourceLocation['id'];
            $sourceLocation['items'] = $itemsByLocation[$lokasiId] ?? [];
        }
        unset($sourceLocation);

        // Dukungan data lama yang belum memiliki lokasi terpisah.
        if (!$sourceLocations && !empty($itemsByLocation[0])) {
            $sourceLocations[] = [
                'id' => 0,
                'pesanan_id' => $pesananId,
                'lokasi' => 'Lokasi belum ditentukan',
                'urutan' => 1,
                'catatan' => '',
                'items' => $itemsByLocation[0],
            ];
        }

        $availableOrder['locations'] = $sourceLocations;
    }
    unset($availableOrder);

    $availableIds = array_map(function ($row) {
        return (int)$row['id'];
    }, $availableOrders);

    if ($availableIds) {
        $placeholders = implode(',', array_fill(0, count($availableIds), '?'));
        $stmtAvailableRecap = $pdo->prepare("\n            SELECT\n                d.produk_id, d.kode_produk, d.nama_produk,\n                COALESCE(pr.satuan, 'unit') AS satuan,\n                SUM(d.qty) AS jumlah_diminta\n            FROM air_pesanan_detail d\n            LEFT JOIN air_produk pr ON pr.id = d.produk_id\n            WHERE d.pesanan_id IN ($placeholders)\n            GROUP BY d.produk_id, d.kode_produk, d.nama_produk, pr.satuan\n            ORDER BY d.nama_produk ASC\n        ");
        $stmtAvailableRecap->execute($availableIds);
        $availableRecap = $stmtAvailableRecap->fetchAll(PDO::FETCH_ASSOC);
        foreach ($availableRecap as $row) {
            $availableTotalUnit += (int)$row['jumlah_diminta'];
        }

        // Detail per pesanan untuk ringkasan modal yang mengikuti checkbox terpilih.
        $stmtOrderProducts = $pdo->prepare("
            SELECT
                d.pesanan_id,
                d.produk_id,
                d.kode_produk,
                d.nama_produk,
                COALESCE(pr.satuan, 'unit') AS satuan,
                SUM(d.qty) AS jumlah_diminta
            FROM air_pesanan_detail d
            LEFT JOIN air_produk pr ON pr.id = d.produk_id
            WHERE d.pesanan_id IN ($placeholders)
            GROUP BY d.pesanan_id, d.produk_id, d.kode_produk, d.nama_produk, pr.satuan
            HAVING SUM(d.qty) > 0
            ORDER BY d.pesanan_id ASC, d.nama_produk ASC
        ");
        $stmtOrderProducts->execute($availableIds);
        foreach ($stmtOrderProducts->fetchAll(PDO::FETCH_ASSOC) as $productRow) {
            $pesananId = (int)$productRow['pesanan_id'];
            if (!isset($availableOrderProducts[$pesananId])) {
                $availableOrderProducts[$pesananId] = [];
            }
            $availableOrderProducts[$pesananId][] = [
                'produk_id' => (int)($productRow['produk_id'] ?? 0),
                'kode_produk' => (string)($productRow['kode_produk'] ?? ''),
                'nama_produk' => (string)($productRow['nama_produk'] ?? ''),
                'satuan' => (string)($productRow['satuan'] ?? 'unit'),
                'jumlah_diminta' => (int)($productRow['jumlah_diminta'] ?? 0),
            ];
        }
    }
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat kebutuhan pelanggan: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$whereVendor = ['1=1'];
$paramsVendor = [];
if ($q !== '') {
    $whereVendor[] = '(vo.nomor_vendor_order LIKE :q OR vo.vendor_nama LIKE :q OR vo.vendor_wa LIKE :q)';
    $paramsVendor[':q'] = '%' . $q . '%';
}
if ($statusFilter !== '') {
    $whereVendor[] = 'vo.status = :status';
    $paramsVendor[':status'] = $statusFilter;
}
$whereVendorSql = implode(' AND ', $whereVendor);

$totalRows = 0;
$totalPages = 1;
$offset = 0;
$vendorOrders = [];

try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM air_vendor_order vo WHERE $whereVendorSql");
    $stmtCount->execute($paramsVendor);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmtOrders = $pdo->prepare("\n        SELECT\n            vo.*,\n            COALESCE((SELECT COUNT(*) FROM air_vendor_order_source s WHERE s.vendor_order_id = vo.id), 0) AS source_count,\n            COALESCE((SELECT SUM(d.jumlah_diminta) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diminta,\n            COALESCE((SELECT SUM(d.jumlah_dipesan) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dipesan,\n            COALESCE((SELECT SUM(d.jumlah_dikonfirmasi) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dikonfirmasi,\n            COALESCE((SELECT SUM(d.jumlah_diterima) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diterima\n        FROM air_vendor_order vo\n        WHERE $whereVendorSql\n        ORDER BY vo.created_at DESC, vo.id DESC\n        LIMIT :limit OFFSET :offset\n    ");
    foreach ($paramsVendor as $key => $value) {
        $stmtOrders->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtOrders->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtOrders->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtOrders->execute();
    $vendorOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    $stmtDetails = $pdo->prepare("\n        SELECT *\n        FROM air_vendor_order_detail\n        WHERE vendor_order_id = :id\n        ORDER BY nama_produk ASC, id ASC\n    ");
    $stmtSources = $pdo->prepare("\n        SELECT p.nomor_pesanan, c.nama AS nama_pemesan\n        FROM air_vendor_order_source s\n        JOIN air_pesanan p ON p.id = s.pesanan_id\n        JOIN air_pelanggan c ON c.id = p.pelanggan_id\n        WHERE s.vendor_order_id = :id\n        ORDER BY p.created_at ASC, p.id ASC\n    ");

    foreach ($vendorOrders as &$vendorOrder) {
        $stmtDetails->execute([':id' => (int)$vendorOrder['id']]);
        $vendorOrder['details'] = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        $stmtSources->execute([':id' => (int)$vendorOrder['id']]);
        $vendorOrder['sources'] = $stmtSources->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($vendorOrder);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat riwayat rekap vendor: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$summary = [
    'total_rekap'   => 0,
    'draft'         => 0,
    'dikirim'       => 0,
    'dikonfirmasi'  => 0,
    'diterima'      => 0,
    'total_diminta' => 0,
    'total_diterima' => 0,
];

try {
    $summaryRow = $pdo->query("\n        SELECT\n            COUNT(*) AS total_rekap,\n            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft,\n            SUM(CASE WHEN status = 'dikirim' THEN 1 ELSE 0 END) AS dikirim,\n            SUM(CASE WHEN status = 'dikonfirmasi' THEN 1 ELSE 0 END) AS dikonfirmasi,\n            SUM(CASE WHEN status = 'diterima' THEN 1 ELSE 0 END) AS diterima,\n            COALESCE(SUM((SELECT SUM(d.jumlah_diminta) FROM air_vendor_order_detail d WHERE d.vendor_order_id = air_vendor_order.id)), 0) AS total_diminta,\n            COALESCE(SUM((SELECT SUM(d.jumlah_diterima) FROM air_vendor_order_detail d WHERE d.vendor_order_id = air_vendor_order.id)), 0) AS total_diterima\n        FROM air_vendor_order\n        WHERE DATE(created_at) = CURDATE()\n          AND status <> 'batal'\n    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        if (array_key_exists($key, $summaryRow)) {
            $summary[$key] = (int)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
    // Ringkasan tetap nol.
}

if (!function_exists('arv_page_url')) {
    function arv_page_url(array $query, int $targetPage): string
    {
        $query['page'] = max(1, $targetPage);
        return 'air_rekap_vendor.php?' . http_build_query($query);
    }
}

$baseQuery = $_GET;
unset($baseQuery['page']);

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Vendor Air Mineral</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
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

        .vendor-main .summary-card,
        .vendor-main .filter-card,
        .vendor-main .table-card,
        .vendor-main .mobile-card,
        .vendor-main .recap-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important
        }

        .vendor-main input,
        .vendor-main select,
        .vendor-main textarea,
        .vendor-main button {
            border-radius: 0 !important
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important
        }

        tbody tr {
            transition: background .15s
        }

        tbody tr:hover {
            background: #f9f9f9
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid;
            padding: 5px 8px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap
        }

        .status-badge:before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: currentColor
        }

        .field {
            width: 100%;
            min-height: 44px;
            border: 1px solid #f0f0f0;
            background: #f9fafb;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700
        }

        textarea.field {
            min-height: 92px;
            padding-top: 11px;
            padding-bottom: 11px
        }

        .btn {
            min-height: 42px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em
        }

        .modal-panel {
            max-height: calc(100vh - 32px)
        }

        @media(min-width:1024px) {
            .vendor-main {
                margin-left: 220px
            }
        }

        @media(max-width:1023px) {
            body {
                padding-bottom: 76px
            }

            .vendor-main {
                padding: 1rem !important;
                padding-bottom: 6.25rem !important
            }

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important
            }

            .filter-card {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important
            }

            .filter-card .wide {
                grid-column: 1/-1
            }

            .mobile-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important
            }
        }

        @media(max-width:640px) {
            body {
                background: #f8fafc !important
            }

            .vendor-main {
                padding: .625rem !important;
                padding-bottom: 6.5rem !important
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important
            }

            .summary-card {
                padding: .75rem !important;
                min-height: 92px
            }

            .filter-card {
                grid-template-columns: 1fr !important;
                padding: .75rem !important
            }

            .filter-card>* {
                grid-column: auto !important
            }

            .mobile-grid {
                grid-template-columns: 1fr !important;
                padding: .625rem !important
            }

            .modal-wrap {
                padding: 0 !important;
                align-items: flex-end !important
            }

            .modal-panel {
                max-height: 100vh;
                height: 100vh;
                width: 100% !important;
                max-width: none !important
            }

            .modal-body {
                max-height: calc(100vh - 132px) !important
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen">
    <main class="vendor-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold <?php echo $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                <?php echo arv_h($flash); ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Air Mineral / Vendor</p>
                <h1 class="text-2xl font-semibold mt-1">Rekap Pemesanan Vendor</h1>
                <p class="text-xs text-gray-400 mt-1">Gabungkan kebutuhan pelanggan, kirim rekap WhatsApp, dan catat penerimaan vendor.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="air_pesanan.php" class="btn border border-gray-200 bg-white text-gray-700">
                    <i data-lucide="clipboard-list" class="w-4 h-4"></i> Pesanan Pelanggan
                </a>
                <button type="button" onclick="openCreateModal()" class="btn bg-black text-white" <?php echo !$availableOrders ? 'disabled' : ''; ?>>
                    <i data-lucide="plus" class="w-4 h-4"></i> Buat Rekap Vendor
                </button>
            </div>
        </div>

        <div class="summary-grid grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 md:gap-4 mb-6">
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Rekap Hari Ini</p>
                <p class="text-2xl font-bold mt-2"><?php echo number_format($summary['total_rekap']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Draft</p>
                <p class="text-2xl font-bold mt-2"><?php echo number_format($summary['draft']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-blue-600">Dikirim</p>
                <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo number_format($summary['dikirim']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-amber-600">Dikonfirmasi</p>
                <p class="text-2xl font-bold text-amber-600 mt-2"><?php echo number_format($summary['dikonfirmasi']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-purple-600">Diterima</p>
                <p class="text-2xl font-bold text-purple-600 mt-2"><?php echo number_format($summary['diterima']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Diminta Hari Ini</p>
                <p class="text-2xl font-bold mt-2"><?php echo number_format($summary['total_diminta']); ?></p>
                <p class="text-[9px] text-gray-400 mt-1">unit</p>
            </div>
            <div class="summary-card p-4 col-span-2 md:col-span-1">
                <p class="text-[9px] font-bold uppercase tracking-widest text-green-600">Diterima Hari Ini</p>
                <p class="text-2xl font-bold text-green-600 mt-2"><?php echo number_format($summary['total_diterima']); ?></p>
                <p class="text-[9px] text-gray-400 mt-1">unit</p>
            </div>
        </div>

        <section class="recap-card p-4 md:p-5 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Kebutuhan Belum Direkap</p>
                    <h2 class="text-base font-bold mt-1"><?php echo arv_h(date('d/m/Y', strtotime($needDate))); ?></h2>
                </div>
                <form method="get" class="flex gap-2">
                    <input type="date" name="need_date" value="<?php echo arv_h($needDate); ?>" class="field">
                    <button class="btn bg-black text-white">Tampilkan</button>
                </form>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <div class="border border-subtle bg-gray-50 p-3">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Pesanan</p>
                    <p class="text-xl font-bold mt-1"><?php echo number_format(count($availableOrders)); ?></p>
                </div>
                <div class="border border-subtle bg-gray-50 p-3">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Jenis Produk</p>
                    <p class="text-xl font-bold mt-1"><?php echo number_format(count($availableRecap)); ?></p>
                </div>
                <div class="border border-subtle bg-gray-50 p-3 col-span-2 md:col-span-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Total Kebutuhan</p>
                    <p class="text-xl font-bold text-blue-600 mt-1"><?php echo number_format($availableTotalUnit); ?> unit</p>
                </div>
                <div class="border border-subtle bg-gray-50 p-3 col-span-2 md:col-span-1">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Status</p>
                    <p class="text-xs font-bold mt-2 <?php echo $availableOrders ? 'text-amber-600' : 'text-green-600'; ?>"><?php echo $availableOrders ? 'Perlu direkap vendor' : 'Semua sudah direkap'; ?></p>
                </div>
            </div>

            <?php if ($availableRecap): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-2">
                    <?php foreach ($availableRecap as $item): ?>
                        <div class="border border-subtle p-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-bold leading-5"><?php echo arv_h($item['nama_produk']); ?></p>
                                <p class="text-[9px] text-gray-400 mt-1"><?php echo arv_h($item['satuan']); ?></p>
                            </div>
                            <p class="text-xl font-bold shrink-0"><?php echo number_format((int)$item['jumlah_diminta']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="border border-dashed border-gray-200 p-8 text-center text-xs text-gray-400">Tidak ada kebutuhan pelanggan yang belum direkap pada tanggal ini.</div>
            <?php endif; ?>
        </section>

        <form method="get" class="filter-card p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <input type="hidden" name="need_date" value="<?php echo arv_h($needDate); ?>">
            <div class="relative flex-1 min-w-[220px] wide">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="search" name="q" value="<?php echo arv_h($q); ?>" class="field pl-10" placeholder="Cari nomor rekap, vendor, atau WA">
            </div>
            <select name="status" class="field md:w-auto md:min-w-[180px]">
                <option value="">Semua Status</option>
                <?php foreach ($allowedVendorStatuses as $status): ?>
                    <option value="<?php echo arv_h($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo arv_h(arv_status_label($status)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="limit" class="field md:w-auto md:min-w-[125px]">
                <?php foreach ($allowedLimits as $limit): ?>
                    <option value="<?php echo $limit; ?>" <?php echo $perPage === $limit ? 'selected' : ''; ?>><?php echo $limit; ?> / Hal</option>
                <?php endforeach; ?>
            </select>
            <button class="btn bg-black text-white"><i data-lucide="filter" class="w-4 h-4"></i> Terapkan</button>
            <a href="air_rekap_vendor.php?need_date=<?php echo urlencode($needDate); ?>" class="btn border border-gray-200 bg-white text-gray-700">Reset</a>
            <span class="text-xs text-gray-400 font-medium md:ml-auto"><?php echo number_format($totalRows); ?> rekap ditemukan</span>
        </form>

        <div class="table-card overflow-hidden">
            <div class="hidden lg:block overflow-x-auto no-scrollbar">
                <table class="w-full text-left min-w-[1050px]">
                    <thead class="border-b border-subtle bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Rekap Vendor</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Vendor</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Kebutuhan</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Diminta</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Dipesan</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Diterima</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f5f5f5]">
                        <?php if (!$vendorOrders): ?>
                            <tr>
                                <td colspan="8" class="py-20 text-center text-xs font-bold uppercase tracking-widest text-gray-300">Belum ada rekap vendor</td>
                            </tr>
                            <?php else: foreach ($vendorOrders as $order): ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold"><?php echo arv_h($order['nomor_vendor_order']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format((int)$order['source_count']); ?> pesanan pelanggan</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold"><?php echo arv_h($order['vendor_nama']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-1"><?php echo arv_h($order['vendor_wa'] ?: '-'); ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-center text-xs font-bold"><?php echo arv_h(date('d/m/Y', strtotime($order['tanggal_kebutuhan']))); ?></td>
                                    <td class="px-5 py-4 text-center text-sm font-bold"><?php echo number_format((int)$order['total_diminta']); ?></td>
                                    <td class="px-5 py-4 text-center text-sm font-bold text-blue-600"><?php echo number_format((int)$order['total_dipesan']); ?></td>
                                    <td class="px-5 py-4 text-center">
                                        <p class="text-sm font-bold text-green-600"><?php echo number_format((int)$order['total_diterima']); ?></p><?php $shortage = (int)$order['total_diminta'] - (int)$order['total_diterima'];
                                                                                                                                                    if ($shortage > 0): ?><p class="text-[9px] font-bold text-red-500 mt-1">Kurang <?php echo number_format($shortage); ?></p><?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center"><span class="status-badge <?php echo arv_status_class((string)$order['status']); ?>"><?php echo arv_h(arv_status_label((string)$order['status'])); ?></span></td>
                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-1"><button type="button" onclick='openDetail(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50" title="Detail"><i data-lucide="eye" class="w-4 h-4"></i></button><button type="button" onclick='openQuantity(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="p-2 text-gray-400 hover:text-purple-600 hover:bg-purple-50" title="Catat penerimaan"><i data-lucide="package-check" class="w-4 h-4"></i></button><button type="button" onclick='sendWhatsApp(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="p-2 text-gray-400 hover:text-green-600 hover:bg-green-50" title="WhatsApp"><i data-lucide="message-circle" class="w-4 h-4"></i></button></div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="lg:hidden">
                <?php if (!$vendorOrders): ?>
                    <div class="py-16 text-center text-xs font-bold uppercase tracking-widest text-gray-300">Belum ada rekap vendor</div>
                <?php else: ?>
                    <div class="mobile-grid grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($vendorOrders as $order): ?>
                            <article class="mobile-card p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold break-all"><?php echo arv_h($order['nomor_vendor_order']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo arv_h($order['vendor_nama']); ?></p>
                                    </div><span class="status-badge <?php echo arv_status_class((string)$order['status']); ?>"><?php echo arv_h(arv_status_label((string)$order['status'])); ?></span>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mt-4">
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[8px] font-bold uppercase text-gray-400">Diminta</p>
                                        <p class="text-lg font-bold mt-1"><?php echo number_format((int)$order['total_diminta']); ?></p>
                                    </div>
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[8px] font-bold uppercase text-gray-400">Dipesan</p>
                                        <p class="text-lg font-bold text-blue-600 mt-1"><?php echo number_format((int)$order['total_dipesan']); ?></p>
                                    </div>
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[8px] font-bold uppercase text-gray-400">Diterima</p>
                                        <p class="text-lg font-bold text-green-600 mt-1"><?php echo number_format((int)$order['total_diterima']); ?></p>
                                    </div>
                                </div>
                                <div class="mt-3 border border-subtle p-3">
                                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Tanggal Kebutuhan</p>
                                    <p class="text-xs font-bold mt-1"><?php echo arv_h(date('d/m/Y', strtotime($order['tanggal_kebutuhan']))); ?> · <?php echo number_format((int)$order['source_count']); ?> pesanan</p>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-subtle"><button type="button" onclick='openDetail(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-blue-100 text-blue-700">Detail</button><button type="button" onclick='openQuantity(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-purple-100 text-purple-700">Terima</button><button type="button" onclick='sendWhatsApp(<?php echo json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-green-100 text-green-700">WA</button></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="px-4 md:px-5 py-4 border-t border-subtle flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50">
                    <span class="text-xs text-gray-400">Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?> total · <?php echo $perPage; ?>/hal)</span>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($page > 1): ?><a href="<?php echo arv_h(arv_page_url($baseQuery, $page - 1)); ?>" class="px-3 py-2 text-xs font-bold border border-subtle bg-white">&larr; Prev</a><?php endif; ?>
                        <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?><a href="<?php echo arv_h(arv_page_url($baseQuery, $pg)); ?>" class="px-3 py-2 text-xs font-bold <?php echo $pg === $page ? 'bg-black text-white' : 'border border-subtle bg-white'; ?>"><?php echo $pg; ?></a><?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="<?php echo arv_h(arv_page_url($baseQuery, $page + 1)); ?>" class="px-3 py-2 text-xs font-bold border border-subtle bg-white">Next &rarr;</a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal buat rekap -->
    <div id="createModal" class="modal-wrap fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel w-full max-w-4xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest">Buat Rekap Vendor</h2>
                    <p class="text-[10px] text-gray-400 mt-1">Pilih pesanan pelanggan dan periksa jumlah yang akan dipesan.</p>
                </div><button type="button" onclick="closeModal('createModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="create_vendor_order">
                <input type="hidden" name="tanggal_kebutuhan" value="<?php echo arv_h($needDate); ?>">
                <div class="modal-body max-h-[72vh] overflow-y-auto px-5 md:px-7 py-6 space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Nama Vendor *</label><input type="text" name="vendor_nama" required class="field" placeholder="Nama pemasok air mineral"></div>
                        <div><label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Nomor WhatsApp Vendor</label><input type="text" name="vendor_wa" class="field" placeholder="08xxxxxxxxxx"></div>
                    </div>
                    <div><label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2">Catatan</label><textarea name="catatan" class="field" placeholder="Catatan untuk vendor atau petugas"></textarea></div>
                    <div>
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Pesanan Pelanggan</label>
                            <label class="text-xs font-bold cursor-pointer">
                                <input type="checkbox" id="checkAllSources" checked onchange="toggleAllSources(this.checked)" class="accent-black">
                                Pilih Semua
                            </label>
                        </div>

                        <div class="max-h-72 overflow-y-auto space-y-2">
                            <?php foreach ($availableOrders as $source): ?>
                                <label class="block border border-subtle bg-white p-3 hover:bg-gray-50 cursor-pointer">
                                    <div class="flex items-start gap-3">
                                        <input type="checkbox"
                                            name="source_ids[]"
                                            value="<?php echo (int)$source['id']; ?>"
                                            checked
                                            class="source-check accent-black mt-1"
                                            onchange="refreshCreateRecapSummary()">

                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-1">
                                                <div>
                                                    <p class="text-xs font-black">
                                                        <?php echo arv_h($source['nomor_pesanan']); ?>
                                                    </p>
                                                    <p class="text-[10px] text-gray-500 mt-1">
                                                        <?php echo arv_h($source['nama_pemesan']); ?>
                                                        <?php if (!empty($source['no_hp'])): ?>
                                                            · <?php echo arv_h($source['no_hp']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>

                                                <p class="text-[9px] font-bold text-blue-600 whitespace-nowrap">
                                                    <?php echo number_format((int)$source['total_lokasi']); ?> lokasi ·
                                                    <?php echo number_format((int)$source['total_unit']); ?> unit
                                                </p>
                                            </div>

                                            <?php if (!empty($source['locations'])): ?>
                                                <div class="mt-3 space-y-2">
                                                    <?php foreach ($source['locations'] as $sourceLocation): ?>
                                                        <div class="border border-gray-100 bg-gray-50 p-3">
                                                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                                                <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">
                                                                    Lokasi Pengantaran
                                                                </p>
                                                                <p class="text-[10px] font-black text-gray-700">
                                                                    <?php echo arv_h($sourceLocation['lokasi'] ?? '-'); ?>
                                                                </p>
                                                            </div>

                                                            <?php if (!empty($sourceLocation['items'])): ?>
                                                                <div class="mt-2 space-y-1">
                                                                    <?php foreach ($sourceLocation['items'] as $sourceItem): ?>
                                                                        <div class="flex items-start justify-between gap-3 text-[10px]">
                                                                            <span class="text-gray-500">
                                                                                <?php echo arv_h($sourceItem['nama_produk'] ?? '-'); ?>
                                                                            </span>
                                                                            <strong class="text-gray-800 whitespace-nowrap">
                                                                                <?php echo number_format((int)($sourceItem['qty'] ?? 0)); ?>
                                                                                <?php echo arv_h($sourceItem['satuan'] ?? 'unit'); ?>
                                                                            </strong>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-3 mb-2"><label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500">Ringkasan Produk Terpilih</label><span id="selectedSourceSummary" class="text-[9px] font-bold text-blue-600">0 pesanan · 0 unit</span></div>
                        <div class="border border-subtle overflow-x-auto">
                            <table class="w-full min-w-[620px]">
                                <thead class="bg-gray-50 border-b border-subtle">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-[9px] uppercase tracking-widest text-gray-400">Produk</th>
                                        <th class="px-4 py-3 text-center text-[9px] uppercase tracking-widest text-gray-400">Diminta</th>
                                        <th class="px-4 py-3 text-center text-[9px] uppercase tracking-widest text-gray-400">Dipesan</th>
                                    </tr>
                                </thead>
                                <tbody id="createRecapBody" class="divide-y divide-[#f5f5f5]"></tbody>
                            </table>
                        </div>
                        <p class="text-[9px] text-gray-400 mt-2">Ringkasan otomatis dihitung hanya dari pesanan pelanggan yang dicentang di atas.</p>
                    </div>
                </div>
                <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3"><button type="button" onclick="closeModal('createModal')" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle bg-white">Batal</button><button type="submit" class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white">Simpan Rekap</button></div>
            </form>
        </div>
    </div>

    <!-- Modal detail -->
    <div id="detailModal" class="modal-wrap fixed inset-0 z-[110] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel w-full max-w-4xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Detail Rekap Vendor</p>
                    <h2 id="detailTitle" class="text-base font-black mt-1">-</h2>
                </div><button onclick="closeModal('detailModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div id="detailBody" class="modal-body max-h-[72vh] overflow-y-auto px-5 md:px-7 py-6"></div>
        </div>
    </div>

    <!-- Modal kuantitas -->
    <div id="quantityModal" class="modal-wrap fixed inset-0 z-[120] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel w-full max-w-3xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Konfirmasi dan Penerimaan</p>
                    <h2 id="quantityTitle" class="text-base font-black mt-1">-</h2>
                </div><button onclick="closeModal('quantityModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form method="post"><input type="hidden" name="action" value="save_quantities"><input type="hidden" name="vendor_order_id" id="quantityOrderId">
                <div id="quantityBody" class="modal-body max-h-[72vh] overflow-y-auto px-5 md:px-7 py-6"></div>
                <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3"><button type="button" onclick="closeModal('quantityModal')" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle bg-white">Batal</button><button type="submit" class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white">Simpan Jumlah</button></div>
            </form>
        </div>
    </div>

    <script>
        function escapeHtml(v) {
            return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')
        }

        function openModal(id) {
            var m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
            document.body.style.overflow = 'hidden'
        }

        function closeModal(id) {
            var m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
            document.body.style.overflow = ''
        }
        var AVAILABLE_ORDER_PRODUCTS = <?php echo json_encode($availableOrderProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function openCreateModal() {
            refreshCreateRecapSummary();
            openModal('createModal');
        }

        function toggleAllSources(checked) {
            document.querySelectorAll('.source-check').forEach(function(el) {
                el.checked = checked
            });
            refreshCreateRecapSummary();
        }

        function refreshCreateRecapSummary() {
            var checked = Array.prototype.slice.call(document.querySelectorAll('.source-check:checked'));
            var grouped = {};
            var totalUnit = 0;

            checked.forEach(function(input) {
                var orderId = String(input.value || '');
                var products = AVAILABLE_ORDER_PRODUCTS[orderId] || AVAILABLE_ORDER_PRODUCTS[Number(orderId)] || [];
                products.forEach(function(item) {
                    var key = String(item.produk_id || 0) + '|' + String(item.kode_produk || '');
                    if (!grouped[key]) {
                        grouped[key] = {
                            produk_id: Number(item.produk_id || 0),
                            kode_produk: String(item.kode_produk || ''),
                            nama_produk: String(item.nama_produk || '-'),
                            satuan: String(item.satuan || 'unit'),
                            jumlah_diminta: 0
                        };
                    }
                    grouped[key].jumlah_diminta += Number(item.jumlah_diminta || 0);
                    totalUnit += Number(item.jumlah_diminta || 0);
                });
            });

            var body = document.getElementById('createRecapBody');
            var summary = document.getElementById('selectedSourceSummary');
            if (!body) return;

            var oldValues = {};
            body.querySelectorAll('input[name^="jumlah_dipesan["]').forEach(function(input) {
                oldValues[input.name] = input.value;
            });

            var rows = Object.keys(grouped).sort(function(a, b) {
                return grouped[a].nama_produk.localeCompare(grouped[b].nama_produk, 'id');
            });

            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="3" class="px-4 py-8 text-center text-xs text-gray-400">Pilih minimal satu pesanan pelanggan.</td></tr>';
            } else {
                body.innerHTML = rows.map(function(key) {
                    var item = grouped[key];
                    var inputName = 'jumlah_dipesan[' + key + ']';
                    var orderedValue = Object.prototype.hasOwnProperty.call(oldValues, inputName) ?
                        oldValues[inputName] :
                        item.jumlah_diminta;
                    return '<tr>' +
                        '<td class="px-4 py-3"><p class="text-xs font-bold">' + escapeHtml(item.nama_produk) + '</p><p class="text-[9px] text-gray-400">' + escapeHtml(item.satuan) + '</p></td>' +
                        '<td class="px-4 py-3 text-center text-sm font-bold">' + Number(item.jumlah_diminta).toLocaleString('id-ID') + '</td>' +
                        '<td class="px-4 py-3"><input type="number" name="' + escapeHtml(inputName) + '" value="' + escapeHtml(orderedValue) + '" min="0" class="field text-center max-w-[120px] mx-auto"></td>' +
                        '</tr>';
                }).join('');
            }

            if (summary) {
                summary.textContent = checked.length.toLocaleString('id-ID') + ' pesanan · ' + totalUnit.toLocaleString('id-ID') + ' unit';
            }

            var checkAll = document.getElementById('checkAllSources');
            var all = document.querySelectorAll('.source-check');
            if (checkAll) {
                checkAll.checked = all.length > 0 && checked.length === all.length;
                checkAll.indeterminate = checked.length > 0 && checked.length < all.length;
            }
        }

        function formatDate(v) {
            if (!v) return '-';
            var p = String(v).substring(0, 10).split('-');
            return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : v
        }

        function statusLabel(v) {
            var m = {
                draft: 'Draft',
                dikirim: 'Dikirim ke Vendor',
                dikonfirmasi: 'Dikonfirmasi',
                diterima: 'Barang Diterima',
                selesai: 'Selesai',
                batal: 'Batal'
            };
            return m[v] || v
        }

        function openDetail(order) {
            document.getElementById('detailTitle').textContent = order.nomor_vendor_order || '-';
            var details = Array.isArray(order.details) ? order.details : [];
            var sources = Array.isArray(order.sources) ? order.sources : [];
            var rows = details.map(function(d) {
                var shortage = Number(d.jumlah_diminta || 0) - Number(d.jumlah_diterima || 0);
                return '<tr><td class="px-4 py-3"><p class="text-xs font-bold">' + escapeHtml(d.nama_produk) + '</p><p class="text-[9px] text-gray-400">' + escapeHtml(d.satuan) + '</p></td><td class="px-4 py-3 text-center font-bold">' + Number(d.jumlah_diminta || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3 text-center font-bold text-blue-600">' + Number(d.jumlah_dipesan || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3 text-center font-bold text-amber-600">' + Number(d.jumlah_dikonfirmasi || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3 text-center font-bold text-green-600">' + Number(d.jumlah_diterima || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3 text-center font-bold ' + (shortage > 0 ? 'text-red-600' : 'text-green-600') + '">' + shortage.toLocaleString('id-ID') + '</td></tr>'
            }).join('');
            var sourceHtml = sources.map(function(s) {
                return '<span class="inline-flex border border-gray-200 px-2 py-1 text-[9px] font-bold">' + escapeHtml(s.nomor_pesanan) + ' · ' + escapeHtml(s.nama_pemesan) + '</span>'
            }).join(' ');
            document.getElementById('detailBody').innerHTML = '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5"><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Vendor</p><p class="text-sm font-bold mt-1">' + escapeHtml(order.vendor_nama) + '</p><p class="text-xs text-gray-400 mt-1">' + escapeHtml(order.vendor_wa || '-') + '</p></div><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Tanggal Kebutuhan</p><p class="text-sm font-bold mt-1">' + formatDate(order.tanggal_kebutuhan) + '</p></div><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Status</p><p class="text-sm font-bold mt-1">' + escapeHtml(statusLabel(order.status)) + '</p></div></div><div class="border border-subtle overflow-x-auto"><table class="w-full min-w-[760px]"><thead class="bg-gray-50 border-b border-subtle"><tr><th class="px-4 py-3 text-left text-[9px] uppercase text-gray-400">Produk</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diminta</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dipesan</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Konfirmasi</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diterima</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Kekurangan</th></tr></thead><tbody class="divide-y divide-[#f5f5f5]">' + rows + '</tbody></table></div><div class="mt-5"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 mb-2">Sumber Pesanan Pelanggan</p><div class="flex flex-wrap gap-2">' + (sourceHtml || '<span class="text-xs text-gray-400">Tidak ada data.</span>') + '</div></div>' + (order.catatan ? '<div class="mt-5 border border-amber-100 bg-amber-50 p-4 text-xs text-amber-700">' + escapeHtml(order.catatan) + '</div>' : '') + '<form method="post" class="mt-5 grid grid-cols-[1fr_auto] gap-2"><input type="hidden" name="action" value="update_vendor_status"><input type="hidden" name="vendor_order_id" value="' + Number(order.id || 0) + '"><select name="status" class="field"><option value="draft">Draft</option><option value="dikirim">Dikirim ke Vendor</option><option value="dikonfirmasi">Dikonfirmasi</option><option value="diterima">Barang Diterima</option><option value="selesai">Selesai</option><option value="batal">Batal</option></select><button class="btn bg-black text-white">Update Status</button></form>';
            var sel = document.querySelector('#detailBody select[name="status"]');
            if (sel) sel.value = order.status || 'draft';
            openModal('detailModal');
        }

        function openQuantity(order) {
            document.getElementById('quantityTitle').textContent = order.nomor_vendor_order || '-';
            document.getElementById('quantityOrderId').value = Number(order.id || 0);
            var details = Array.isArray(order.details) ? order.details : [];
            var html = '<div class="border border-subtle overflow-x-auto"><table class="w-full min-w-[680px]"><thead class="bg-gray-50 border-b border-subtle"><tr><th class="px-4 py-3 text-left text-[9px] uppercase text-gray-400">Produk</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dipesan</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dikonfirmasi</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diterima</th></tr></thead><tbody class="divide-y divide-[#f5f5f5]">';
            details.forEach(function(d, i) {
                html += '<tr><td class="px-4 py-3"><input type="hidden" name="detail_id[]" value="' + Number(d.id || 0) + '"><p class="text-xs font-bold">' + escapeHtml(d.nama_produk) + '</p><p class="text-[9px] text-gray-400">Diminta ' + Number(d.jumlah_diminta || 0).toLocaleString('id-ID') + ' ' + escapeHtml(d.satuan) + '</p></td><td class="px-4 py-3 text-center font-bold text-blue-600">' + Number(d.jumlah_dipesan || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3"><input type="number" name="jumlah_dikonfirmasi[]" min="0" value="' + Number(d.jumlah_dikonfirmasi || 0) + '" class="field text-center"></td><td class="px-4 py-3"><input type="number" name="jumlah_diterima[]" min="0" value="' + Number(d.jumlah_diterima || 0) + '" class="field text-center"></td></tr>'
            });
            html += '</tbody></table></div><div class="mt-4 border border-blue-100 bg-blue-50 p-4 text-xs text-blue-700">Isi jumlah yang benar-benar dikonfirmasi dan diterima. Jika ada penerimaan, pesanan pelanggan otomatis menjadi <strong>Siap Dikirim</strong>.</div>';
            document.getElementById('quantityBody').innerHTML = html;
            openModal('quantityModal')
        }

        function normalizeWa(v) {
            v = String(v || '').replace(/[^0-9]/g, '');
            if (v.indexOf('0') === 0) v = '62' + v.substring(1);
            else if (v.indexOf('62') !== 0) v = '62' + v;
            return v
        }

        function sendWhatsApp(order) {
            var wa = normalizeWa(order.vendor_wa || '');
            if (!wa) {
                alert('Nomor WhatsApp vendor belum diisi.');
                return
            }
            var details = Array.isArray(order.details) ? order.details : [];
            var lines = ['Permintaan Air Mineral', 'Nomor Rekap: ' + (order.nomor_vendor_order || '-'), 'Tanggal Kebutuhan: ' + formatDate(order.tanggal_kebutuhan), ''];
            details.forEach(function(d) {
                lines.push('- ' + d.nama_produk + ': ' + Number(d.jumlah_dipesan || 0).toLocaleString('id-ID') + ' ' + (d.satuan || 'unit'))
            });
            lines.push('', 'Mohon konfirmasi jumlah yang tersedia dan jadwal pengiriman.');
            window.open('https://wa.me/' + wa + '?text=' + encodeURIComponent(lines.join('\n')), '_blank');
            // Tandai status dikirim tanpa menghalangi pembukaan WA.
            var form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            form.innerHTML = '<input name="action" value="update_vendor_status"><input name="vendor_order_id" value="' + Number(order.id || 0) + '"><input name="status" value="dikirim">';
            document.body.appendChild(form);
            setTimeout(function() {
                form.submit()
            }, 500)
        }
        refreshCreateRecapSummary();
        document.querySelectorAll('.modal-wrap').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal(modal.id)
            })
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                ['createModal', 'detailModal', 'quantityModal'].forEach(closeModal)
            }
        });
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>