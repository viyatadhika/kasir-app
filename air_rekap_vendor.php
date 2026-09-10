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


if (!function_exists('arv_save_vendor_delivery_note')) {
    /**
     * Simpan surat jalan vendor dari form penerimaan.
     * Mendukung foto hasil kamera/WhatsApp maupun PDF hasil scan.
     */
    function arv_save_vendor_delivery_note(string $field = 'surat_jalan_vendor'): ?string
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return null;
        }

        $file = $_FILES[$field];
        $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload surat jalan vendor gagal. Kode upload: ' . $error);
        }

        $size = isset($file['size']) ? (int)$file['size'] : 0;
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new RuntimeException('Ukuran surat jalan vendor maksimal 8 MB.');
        }

        $originalName = (string)($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Surat jalan vendor harus berformat JPG, JPEG, PNG, WebP, atau PDF.');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('File surat jalan vendor tidak valid.');
        }

        // Validasi MIME bila Fileinfo tersedia.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
                if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
                    throw new RuntimeException('Isi file surat jalan vendor tidak sesuai dengan format yang diizinkan.');
                }
            }
        }

        $directory = __DIR__ . '/uploads/surat_jalan_vendor';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder uploads/surat_jalan_vendor tidak dapat dibuat.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Folder uploads/surat_jalan_vendor belum memiliki izin tulis.');
        }

        $random = function_exists('random_bytes') ? bin2hex(random_bytes(4)) : substr(md5(uniqid('', true)), 0, 8);
        $filename = 'surat_jalan_' . date('Ymd_His') . '_' . $random . '.' . $extension;
        $destination = $directory . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Surat jalan vendor gagal disimpan.');
        }

        return 'uploads/surat_jalan_vendor/' . $filename;
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


if (!function_exists('arv_customer_status_label')) {
    function arv_customer_status_label(string $status): string
    {
        $map = [
            'baru'             => 'Baru',
            'diproses'         => 'Diproses',
            'siap_dikirim'     => 'Siap Dikirim',
            'dalam_pengiriman' => 'Dalam Pengiriman',
            'selesai'          => 'Selesai',
            'batal'            => 'Batal',
        ];

        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('arv_customer_status_class')) {
    function arv_customer_status_class(string $status): string
    {
        $map = [
            'baru'             => 'bg-blue-50 text-blue-700 border-blue-200',
            'diproses'         => 'bg-amber-50 text-amber-700 border-amber-200',
            'siap_dikirim'     => 'bg-purple-50 text-purple-700 border-purple-200',
            'dalam_pengiriman' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'selesai'          => 'bg-green-50 text-green-700 border-green-200',
            'batal'            => 'bg-red-50 text-red-700 border-red-200',
        ];

        return $map[$status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
    }
}

if (!function_exists('arv_ensure_schema')) {
    function arv_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_order (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                nomor_vendor_order VARCHAR(60) NOT NULL,\n                tanggal_rekap DATE NOT NULL,\n                tanggal_kebutuhan DATE NOT NULL,\n                vendor_nama VARCHAR(150) NOT NULL,\n                vendor_wa VARCHAR(30) NULL,\n                status VARCHAR(30) NOT NULL DEFAULT 'draft',\n                total_unit INT NOT NULL DEFAULT 0,\n                catatan TEXT NULL,\n                created_by INT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                UNIQUE KEY uq_air_vendor_nomor (nomor_vendor_order),\n                INDEX idx_air_vendor_tanggal (tanggal_kebutuhan),\n                INDEX idx_air_vendor_status (status)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        if (!arv_column_exists($pdo, 'air_vendor_order', 'surat_jalan_vendor')) {
            $pdo->exec("ALTER TABLE air_vendor_order ADD COLUMN surat_jalan_vendor VARCHAR(255) NULL AFTER catatan");
        }

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

/*
 * Sinkronisasi status final.
 * Rekap vendor berstatus SELESAI hanya terjadi setelah seluruh pesanan sumber
 * selesai. Untuk data lama yang pernah tidak sinkron, status pelanggan dibuat
 * konsisten menjadi selesai. Status BATAL tetap dilindungi dan tidak diubah.
 */
if ($flashType !== 'error') {
    try {
        $pdo->exec("
            UPDATE air_pesanan p
            INNER JOIN air_vendor_order_source s ON s.pesanan_id = p.id
            INNER JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
            SET p.status = 'selesai', p.updated_at = NOW()
            WHERE vo.status = 'selesai'
              AND p.status NOT IN ('selesai', 'batal')
        ");
    } catch (Throwable $e) {
        error_log('AIR VENDOR STATUS SYNC ERROR: ' . $e->getMessage());
    }
}

/*
 * Bersihkan rekap vendor yatim.
 * Jika seluruh pesanan sumber suatu rekap sudah dihapus dari air_pesanan,
 * detail/source/rekap vendor tersebut tidak boleh tetap muncul di halaman.
 */
if ($flashType !== 'error') {
    try {
        $pdo->beginTransaction();

        $orphanIds = $pdo->query("
            SELECT vo.id
            FROM air_vendor_order vo
            WHERE NOT EXISTS (
                SELECT 1
                FROM air_vendor_order_source s
                INNER JOIN air_pesanan p ON p.id = s.pesanan_id
                WHERE s.vendor_order_id = vo.id
            )
        ")->fetchAll(PDO::FETCH_COLUMN);

        if ($orphanIds) {
            $orphanIds = array_map('intval', $orphanIds);
            $orphanIds = array_values(array_filter($orphanIds, function ($id) {
                return $id > 0;
            }));

            if ($orphanIds) {
                $orphanPlaceholders = implode(',', array_fill(0, count($orphanIds), '?'));

                $stmtCleanupSource = $pdo->prepare("DELETE FROM air_vendor_order_source WHERE vendor_order_id IN ($orphanPlaceholders)");
                $stmtCleanupSource->execute($orphanIds);

                $stmtCleanupDetail = $pdo->prepare("DELETE FROM air_vendor_order_detail WHERE vendor_order_id IN ($orphanPlaceholders)");
                $stmtCleanupDetail->execute($orphanIds);

                $stmtCleanupOrder = $pdo->prepare("DELETE FROM air_vendor_order WHERE id IN ($orphanPlaceholders)");
                $stmtCleanupOrder->execute($orphanIds);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('AIR VENDOR CLEANUP ORPHAN ERROR: ' . $e->getMessage());
    }
}

/*
 * Penanda data historis/migrasi.
 * Pesanan lama yang SUDAH SELESAI diperlakukan sebagai arsip dan tidak wajib
 * dibuatkan rekap vendor. Pesanan baru tetap mengikuti alur rekap vendor.
 * Kode dibuat adaptif agar tetap aman bila salah satu kolom belum tersedia.
 */
$hasIsHistorical = arv_column_exists($pdo, 'air_pesanan', 'is_historical');
$hasSumberData = arv_column_exists($pdo, 'air_pesanan', 'sumber_data');

$historicalParts = [];
if ($hasIsHistorical) {
    $historicalParts[] = "COALESCE(p.is_historical, 0) = 1";
}
if ($hasSumberData) {
    $historicalParts[] = "LOWER(COALESCE(p.sumber_data, '')) IN ('migrasi','historis','historical','import_lama')";
}
$historicalSql = $historicalParts ? '(' . implode(' OR ', $historicalParts) . ')' : '0=1';
$historicalSelectSql = $historicalParts
    ? "CASE WHEN $historicalSql THEN 1 ELSE 0 END AS is_data_historis,"
    : "0 AS is_data_historis,";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'error') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_vendor_order') {
            $tanggalKebutuhan = '';
            $vendorNama = trim((string)($_POST['vendor_nama'] ?? ''));
            $vendorWa = trim((string)($_POST['vendor_wa'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $sourceIds = isset($_POST['source_ids']) && is_array($_POST['source_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['source_ids']))))
                : [];

            if ($vendorNama === '') {
                throw new RuntimeException('Nama vendor wajib diisi.');
            }
            if (!$sourceIds) {
                throw new RuntimeException('Pilih minimal satu pesanan pelanggan untuk direkap.');
            }

            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $stmtEligible = $pdo->prepare("
                SELECT
                    p.id,
                    COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) AS tanggal_kebutuhan
                FROM air_pesanan p
                WHERE p.id IN ($placeholders)
                  AND p.status NOT IN ('batal', 'selesai')
                  AND NOT EXISTS (
                      SELECT 1
                      FROM air_vendor_order_source s
                      JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                      WHERE s.pesanan_id = p.id
                        AND vo.status <> 'batal'
                  )
                FOR UPDATE
            ");

            $pdo->beginTransaction();
            $stmtEligible->execute($sourceIds);
            $eligibleRows = $stmtEligible->fetchAll(PDO::FETCH_ASSOC);
            $eligibleIds = array_map(function ($row) {
                return (int)$row['id'];
            }, $eligibleRows);

            $selectedDates = [];
            foreach ($eligibleRows as $eligibleRow) {
                $dateValue = trim((string)($eligibleRow['tanggal_kebutuhan'] ?? ''));
                if ($dateValue !== '') {
                    $selectedDates[$dateValue] = true;
                }
            }

            if (count($selectedDates) !== 1) {
                throw new RuntimeException('Pilih pesanan dengan tanggal kebutuhan yang sama untuk satu rekap vendor.');
            }

            $tanggalKebutuhan = (string)array_key_first($selectedDates);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalKebutuhan)) {
                throw new RuntimeException('Tanggal kebutuhan pesanan tidak valid.');
            }

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

        if ($action === 'complete_customer_order') {
            $pesananId = (int)($_POST['pesanan_id'] ?? 0);

            if ($pesananId <= 0) {
                throw new RuntimeException('Pesanan pelanggan tidak valid.');
            }

            $stmtCheckCustomer = $pdo->prepare("
                SELECT status
                FROM air_pesanan
                WHERE id = :id
                LIMIT 1
            ");
            $stmtCheckCustomer->execute([':id' => $pesananId]);
            $customerStatus = (string)$stmtCheckCustomer->fetchColumn();

            if ($customerStatus === '') {
                throw new RuntimeException('Pesanan pelanggan tidak ditemukan.');
            }

            if ($customerStatus === 'selesai') {
                $flash = 'Pesanan pelanggan sudah berstatus selesai.';
            } elseif ($customerStatus === 'batal') {
                throw new RuntimeException('Pesanan yang dibatalkan tidak dapat diselesaikan.');
            } elseif ($customerStatus !== 'siap_dikirim' && $customerStatus !== 'dalam_pengiriman') {
                throw new RuntimeException('Pesanan hanya dapat diselesaikan setelah status Siap Dikirim / Dalam Pengiriman.');
            } else {
                $pdo->beginTransaction();

                $stmtCompleteCustomer = $pdo->prepare("
                    UPDATE air_pesanan
                    SET status = 'selesai', updated_at = NOW()
                    WHERE id = :id
                      AND status IN ('siap_dikirim', 'dalam_pengiriman')
                ");
                $stmtCompleteCustomer->execute([':id' => $pesananId]);

                /*
                 * Jika pesanan ini terhubung ke rekap vendor dan seluruh pesanan
                 * sumber pada rekap tersebut sudah selesai/batal, tandai rekap
                 * vendornya sebagai selesai juga.
                 */
                $stmtVendorIds = $pdo->prepare("
                    SELECT DISTINCT s.vendor_order_id
                    FROM air_vendor_order_source s
                    WHERE s.pesanan_id = :pesanan_id
                ");
                $stmtVendorIds->execute([':pesanan_id' => $pesananId]);
                $vendorIds = array_map('intval', $stmtVendorIds->fetchAll(PDO::FETCH_COLUMN));

                if ($vendorIds) {
                    $stmtRemaining = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM air_vendor_order_source s
                        JOIN air_pesanan p ON p.id = s.pesanan_id
                        WHERE s.vendor_order_id = :vendor_order_id
                          AND p.status NOT IN ('selesai', 'batal')
                    ");
                    $stmtFinishVendor = $pdo->prepare("
                        UPDATE air_vendor_order
                        SET status = 'selesai', updated_at = NOW()
                        WHERE id = :vendor_order_id
                          AND status <> 'batal'
                    ");

                    foreach ($vendorIds as $vendorId) {
                        $stmtRemaining->execute([':vendor_order_id' => $vendorId]);
                        if ((int)$stmtRemaining->fetchColumn() === 0) {
                            $stmtFinishVendor->execute([':vendor_order_id' => $vendorId]);
                        }
                    }
                }

                $pdo->commit();
                $flash = 'Pesanan pelanggan berhasil ditandai selesai.';
            }
        }

        // Legacy compatibility only. UI baru menyimpan catatan lewat form Penerimaan.
        if ($action === 'save_vendor_note') {
            $vendorOrderId = (int)($_POST['vendor_order_id'] ?? 0);
            $catatanVendor = trim((string)($_POST['catatan'] ?? ''));

            if ($vendorOrderId <= 0) {
                throw new RuntimeException('Rekap vendor tidak valid.');
            }

            $stmtNote = $pdo->prepare("
                UPDATE air_vendor_order
                SET catatan = :catatan, updated_at = NOW()
                WHERE id = :id
            ");
            $stmtNote->execute([
                ':catatan' => $catatanVendor,
                ':id'      => $vendorOrderId,
            ]);

            $flash = 'Catatan pengiriman vendor berhasil disimpan.';
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
            } elseif ($status === 'selesai') {
                $stmtCustomer = $pdo->prepare("\n                    UPDATE air_pesanan p\n                    JOIN air_vendor_order_source s ON s.pesanan_id = p.id\n                    SET p.status = 'selesai', p.updated_at = NOW()\n                    WHERE s.vendor_order_id = :vendor_order_id\n                      AND p.status <> 'batal'\n                ");
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
            $catatanVendor = trim((string)($_POST['catatan'] ?? ''));
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

            // Ambil surat jalan lama agar tetap dipertahankan jika pengguna tidak mengunggah file baru.
            $stmtOldSuratJalan = $pdo->prepare("SELECT surat_jalan_vendor FROM air_vendor_order WHERE id = :id LIMIT 1");
            $stmtOldSuratJalan->execute([':id' => $vendorOrderId]);
            $oldSuratJalan = trim((string)$stmtOldSuratJalan->fetchColumn());
            $newSuratJalan = arv_save_vendor_delivery_note('surat_jalan_vendor');

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

            // Catatan pengiriman/vendor disimpan bersamaan dengan jumlah penerimaan.
            $stmtCatatan = $pdo->prepare("\n                UPDATE air_vendor_order\n                SET catatan = :catatan,\n                    surat_jalan_vendor = COALESCE(:surat_jalan_vendor, surat_jalan_vendor),\n                    updated_at = NOW()\n                WHERE id = :id\n            ");
            $stmtCatatan->execute([
                ':catatan'            => $catatanVendor,
                ':surat_jalan_vendor' => $newSuratJalan,
                ':id'                 => $vendorOrderId,
            ]);

            if ($hasReceived) {
                /*
                 * Alur operasional Air Mineral:
                 * vendor mengirim langsung ke lokasi tujuan. Karena itu saat
                 * penerimaan dicatat, distribusi dianggap sudah terlaksana.
                 */
                $stmtOrder = $pdo->prepare("\n                    UPDATE air_vendor_order\n                    SET status = 'selesai', updated_at = NOW()\n                    WHERE id = :id\n                      AND status <> 'batal'\n                ");
                $stmtOrder->execute([':id' => $vendorOrderId]);

                $stmtCustomer = $pdo->prepare("\n                    UPDATE air_pesanan p\n                    JOIN air_vendor_order_source s ON s.pesanan_id = p.id\n                    SET p.status = 'selesai', p.updated_at = NOW()\n                    WHERE s.vendor_order_id = :vendor_order_id\n                      AND p.status <> 'batal'\n                ");
                $stmtCustomer->execute([':vendor_order_id' => $vendorOrderId]);
            }

            $pdo->commit();

            // Bila ada upload baru, hapus file lama setelah transaksi database berhasil.
            if ($newSuratJalan && $oldSuratJalan && $oldSuratJalan !== $newSuratJalan) {
                $oldAbsolute = __DIR__ . '/' . ltrim($oldSuratJalan, '/');
                if (is_file($oldAbsolute)) {
                    @unlink($oldAbsolute);
                }
            }

            $flash = $hasReceived
                ? 'Penerimaan berhasil disimpan. Pesanan otomatis selesai karena vendor mengirim langsung ke lokasi.'
                : 'Data konfirmasi dan catatan vendor berhasil disimpan. Status belum diselesaikan karena jumlah diterima masih 0.';
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
if (!in_array($statusFilter, $allowedVendorStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$allowedLimits = [10, 15, 25, 50];
$perPage = (int)($_GET['limit'] ?? 15);
if (!in_array($perPage, $allowedLimits, true)) {
    $perPage = 15;
}

// Seluruh pesanan pelanggan yang belum selesai.
// Bagian ini sengaja TIDAK hanya membaca pesanan yang belum direkap,
// supaya petugas bisa melihat posisi seluruh pesanan aktif dalam satu halaman.
$unfinishedOrders = [];
$unfinishedSummary = [
    'total' => 0,
    'baru' => 0,
    'diproses' => 0,
    'siap_dikirim' => 0,
    'dalam_pengiriman' => 0,
    'selesai' => 0,
    'batal' => 0,
    'belum_rekap' => 0,
];

try {
    $stmtUnfinished = $pdo->query("
        SELECT
            $historicalSelectSql
            p.id,
            p.nomor_pesanan,
            p.status,
            p.tanggal_pemesanan,
            p.tanggal_kirim,
            p.jam_kirim,
            p.catatan,
            p.created_at,
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) AS tanggal_operasional,
            c.nama AS nama_pemesan,
            c.no_hp,
            COALESCE((SELECT COUNT(*) FROM air_pesanan_lokasi lx WHERE lx.pesanan_id = p.id), 0) AS total_lokasi,
            COALESCE((
                SELECT SUM(d.qty)
                FROM air_pesanan_detail d
                WHERE d.pesanan_id = p.id
            ), 0) AS total_unit,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT NULLIF(TRIM(l.lokasi), '')
                    ORDER BY l.urutan ASC, l.id ASC
                    SEPARATOR '||'
                )
                FROM air_pesanan_lokasi l
                WHERE l.pesanan_id = p.id
            ), '') AS daftar_lokasi,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT vo.nomor_vendor_order
                    ORDER BY vo.created_at DESC
                    SEPARATOR '||'
                )
                FROM air_vendor_order_source s
                JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                WHERE s.pesanan_id = p.id
                  AND vo.status <> 'batal'
            ), '') AS nomor_rekap_vendor,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT vo.status
                    ORDER BY vo.created_at DESC
                    SEPARATOR '||'
                )
                FROM air_vendor_order_source s
                JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                WHERE s.pesanan_id = p.id
                  AND vo.status <> 'batal'
            ), '') AS status_rekap_vendor,
            COALESCE((
                SELECT vo.id
                FROM air_vendor_order_source s
                JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                WHERE s.pesanan_id = p.id
                  AND vo.status <> 'batal'
                ORDER BY vo.created_at DESC, vo.id DESC
                LIMIT 1
            ), 0) AS latest_vendor_order_id
        FROM air_pesanan p
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        ORDER BY
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC,
            p.created_at DESC,
            p.id DESC
    ");

    $unfinishedOrders = $stmtUnfinished
        ? $stmtUnfinished->fetchAll(PDO::FETCH_ASSOC)
        : [];

    // Siapkan rincian lengkap setiap pesanan agar tombol Lihat membuka modal langsung
    // tanpa berpindah ke halaman air_pesanan.php.
    $stmtMonitorLocations = $pdo->prepare("
        SELECT id, pesanan_id, lokasi, urutan, catatan
        FROM air_pesanan_lokasi
        WHERE pesanan_id = :pesanan_id
        ORDER BY urutan ASC, id ASC
    ");
    $stmtMonitorItems = $pdo->prepare("
        SELECT d.lokasi_id, d.nama_produk, d.qty, d.catatan_item, COALESCE(pr.satuan, 'unit') AS satuan
        FROM air_pesanan_detail d
        LEFT JOIN air_produk pr ON pr.id = d.produk_id
        WHERE d.pesanan_id = :pesanan_id
        ORDER BY d.lokasi_id ASC, d.id ASC
    ");
    foreach ($unfinishedOrders as &$monitorOrder) {
        $pesananId = (int)$monitorOrder['id'];
        $stmtMonitorLocations->execute([':pesanan_id' => $pesananId]);
        $monitorLocations = $stmtMonitorLocations->fetchAll(PDO::FETCH_ASSOC);
        $stmtMonitorItems->execute([':pesanan_id' => $pesananId]);
        $monitorItems = $stmtMonitorItems->fetchAll(PDO::FETCH_ASSOC);
        $itemsByLocation = [];
        foreach ($monitorItems as $item) {
            $lokasiId = (int)($item['lokasi_id'] ?? 0);
            $itemsByLocation[$lokasiId][] = $item;
        }
        foreach ($monitorLocations as &$location) {
            $location['items'] = $itemsByLocation[(int)$location['id']] ?? [];
        }
        unset($location);
        if (!$monitorLocations && !empty($itemsByLocation[0])) {
            $monitorLocations[] = [
                'id' => 0,
                'pesanan_id' => $pesananId,
                'lokasi' => 'Lokasi belum ditentukan',
                'urutan' => 1,
                'catatan' => '',
                'items' => $itemsByLocation[0],
            ];
        }
        $monitorOrder['locations'] = $monitorLocations;
    }
    unset($monitorOrder);

    foreach ($unfinishedOrders as $unfinishedOrder) {
        $status = (string)($unfinishedOrder['status'] ?? '');

        $unfinishedSummary['total']++;
        if (isset($unfinishedSummary[$status])) {
            $unfinishedSummary[$status]++;
        }

        if (trim((string)($unfinishedOrder['nomor_rekap_vendor'] ?? '')) === '') {
            $unfinishedSummary['belum_rekap']++;
        }
    }
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat monitoring pesanan belum selesai: ' . $e->getMessage();
        $flashType = 'error';
    }
}


// Pesanan pelanggan yang belum masuk rekap vendor aktif.
$availableOrders = [];
$availableRecap = [];
$availableOrderProducts = [];
$availableTotalUnit = 0;
try {
    $stmtAvailableOrders = $pdo->prepare("
        SELECT
            p.id, p.nomor_pesanan, p.tanggal_kirim, p.tanggal_pemesanan, p.created_at,
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) AS tanggal_operasional,
            c.nama AS nama_pemesan, c.no_hp,
            COALESCE((SELECT SUM(d.qty) FROM air_pesanan_detail d WHERE d.pesanan_id = p.id), 0) AS total_unit,
            COALESCE((SELECT COUNT(*) FROM air_pesanan_lokasi l WHERE l.pesanan_id = p.id), 0) AS total_lokasi
        FROM air_pesanan p
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE p.status NOT IN ('batal', 'selesai')
          AND NOT EXISTS (
              SELECT 1
              FROM air_vendor_order_source s
              JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
              WHERE s.pesanan_id = p.id
                AND vo.status <> 'batal'
          )
        ORDER BY
            COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC,
            p.created_at DESC,
            p.id DESC
    ");
    $stmtAvailableOrders->execute();
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

$whereVendor = ["EXISTS (\n    SELECT 1\n    FROM air_vendor_order_source src_live\n    INNER JOIN air_pesanan p_live ON p_live.id = src_live.pesanan_id\n    WHERE src_live.vendor_order_id = vo.id\n)"];
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

    $stmtOrders = $pdo->prepare("\n        SELECT\n            vo.*,\n            COALESCE((SELECT COUNT(*) FROM air_vendor_order_source s WHERE s.vendor_order_id = vo.id), 0) AS source_count,\n            COALESCE((SELECT SUM(d.jumlah_diminta) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diminta,\n            COALESCE((SELECT SUM(d.jumlah_dipesan) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dipesan,\n            COALESCE((SELECT SUM(d.jumlah_dikonfirmasi) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dikonfirmasi,\n            COALESCE((SELECT SUM(d.jumlah_diterima) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diterima\n        FROM air_vendor_order vo\n        WHERE $whereVendorSql\n        ORDER BY vo.tanggal_kebutuhan DESC, vo.created_at DESC, vo.id DESC\n        LIMIT :limit OFFSET :offset\n    ");
    foreach ($paramsVendor as $key => $value) {
        $stmtOrders->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtOrders->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtOrders->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtOrders->execute();
    $vendorOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    $stmtDetails = $pdo->prepare("\n        SELECT *\n        FROM air_vendor_order_detail\n        WHERE vendor_order_id = :id\n        ORDER BY nama_produk ASC, id ASC\n    ");
    $stmtDeliveryLocations = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan') AS lokasi,
            d.nama_produk,
            COALESCE(pr.satuan, 'unit') AS satuan,
            SUM(d.qty) AS qty
        FROM air_vendor_order_source s
        JOIN air_pesanan_detail d ON d.pesanan_id = s.pesanan_id
        LEFT JOIN air_pesanan_lokasi l ON l.id = d.lokasi_id AND l.pesanan_id = d.pesanan_id
        LEFT JOIN air_produk pr ON pr.id = d.produk_id
        WHERE s.vendor_order_id = :id
        GROUP BY COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan'), d.nama_produk, pr.satuan
        HAVING SUM(d.qty) > 0
        ORDER BY COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan') ASC, d.nama_produk ASC
    ");
    $stmtSources = $pdo->prepare("
        SELECT
            p.id AS pesanan_id,
            p.nomor_pesanan,
            c.nama AS nama_pemesan,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT NULLIF(TRIM(l.lokasi), '')
                    ORDER BY l.urutan ASC, l.id ASC
                    SEPARATOR '||'
                )
                FROM air_pesanan_lokasi l
                WHERE l.pesanan_id = p.id
            ), '') AS daftar_lokasi
        FROM air_vendor_order_source s
        JOIN air_pesanan p ON p.id = s.pesanan_id
        JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE s.vendor_order_id = :id
        ORDER BY COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC, p.created_at DESC, p.id DESC
    ");

    foreach ($vendorOrders as &$vendorOrder) {
        $stmtDetails->execute([':id' => (int)$vendorOrder['id']]);
        $vendorOrder['details'] = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        $stmtSources->execute([':id' => (int)$vendorOrder['id']]);
        $vendorOrder['sources'] = $stmtSources->fetchAll(PDO::FETCH_ASSOC);

        $stmtDeliveryLocations->execute([':id' => (int)$vendorOrder['id']]);
        $deliveryRows = $stmtDeliveryLocations->fetchAll(PDO::FETCH_ASSOC);
        $deliveryGroups = [];
        foreach ($deliveryRows as $deliveryRow) {
            $locationName = trim((string)($deliveryRow['lokasi'] ?? ''));
            if ($locationName === '') $locationName = 'Lokasi belum ditentukan';
            if (!isset($deliveryGroups[$locationName])) $deliveryGroups[$locationName] = [];
            $deliveryGroups[$locationName][] = [
                'nama_produk' => (string)($deliveryRow['nama_produk'] ?? '-'),
                'satuan' => (string)($deliveryRow['satuan'] ?? 'unit'),
                'qty' => (int)($deliveryRow['qty'] ?? 0),
            ];
        }
        $vendorOrder['delivery_locations'] = $deliveryGroups;
    }
    unset($vendorOrder);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat riwayat rekap vendor: ' . $e->getMessage();
        $flashType = 'error';
    }
}


/*
 * Data rekap vendor yang terhubung ke pesanan belum selesai.
 * Dipakai oleh satu tabel monitoring utama agar admin tidak perlu membuka
 * tabel rekap vendor terpisah.
 */
$monitorVendorById = [];

try {
    $monitorVendorIds = [];

    foreach ($unfinishedOrders as $unfinishedOrder) {
        $monitorVendorId = (int)($unfinishedOrder['latest_vendor_order_id'] ?? 0);
        if ($monitorVendorId > 0) {
            $monitorVendorIds[$monitorVendorId] = $monitorVendorId;
        }
    }

    $monitorVendorIds = array_values($monitorVendorIds);

    if ($monitorVendorIds) {
        $monitorPlaceholders = implode(',', array_fill(0, count($monitorVendorIds), '?'));

        $stmtMonitorVendors = $pdo->prepare("
            SELECT
                vo.*,
                COALESCE((SELECT COUNT(*) FROM air_vendor_order_source s WHERE s.vendor_order_id = vo.id), 0) AS source_count,
                COALESCE((SELECT SUM(d.jumlah_diminta) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diminta,
                COALESCE((SELECT SUM(d.jumlah_dipesan) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dipesan,
                COALESCE((SELECT SUM(d.jumlah_dikonfirmasi) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_dikonfirmasi,
                COALESCE((SELECT SUM(d.jumlah_diterima) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id), 0) AS total_diterima
            FROM air_vendor_order vo
            WHERE vo.id IN ($monitorPlaceholders)
        ");
        $stmtMonitorVendors->execute($monitorVendorIds);

        $monitorVendorRows = $stmtMonitorVendors->fetchAll(PDO::FETCH_ASSOC);

        $stmtMonitorDetails = $pdo->prepare("
            SELECT *
            FROM air_vendor_order_detail
            WHERE vendor_order_id = :id
            ORDER BY nama_produk ASC, id ASC
        ");

        $stmtMonitorSources = $pdo->prepare("
            SELECT
                p.id AS pesanan_id,
                p.nomor_pesanan,
                c.nama AS nama_pemesan,
                COALESCE((
                    SELECT GROUP_CONCAT(
                        DISTINCT NULLIF(TRIM(l.lokasi), '')
                        ORDER BY l.urutan ASC, l.id ASC
                        SEPARATOR '||'
                    )
                    FROM air_pesanan_lokasi l
                    WHERE l.pesanan_id = p.id
                ), '') AS daftar_lokasi
            FROM air_vendor_order_source s
            JOIN air_pesanan p ON p.id = s.pesanan_id
            JOIN air_pelanggan c ON c.id = p.pelanggan_id
            WHERE s.vendor_order_id = :id
            ORDER BY COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC,
                     p.created_at DESC,
                     p.id DESC
        ");

        $stmtMonitorLocations = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan') AS lokasi,
                d.nama_produk,
                COALESCE(pr.satuan, 'unit') AS satuan,
                SUM(d.qty) AS qty
            FROM air_vendor_order_source s
            JOIN air_pesanan_detail d ON d.pesanan_id = s.pesanan_id
            LEFT JOIN air_pesanan_lokasi l
                   ON l.id = d.lokasi_id
                  AND l.pesanan_id = d.pesanan_id
            LEFT JOIN air_produk pr ON pr.id = d.produk_id
            WHERE s.vendor_order_id = :id
            GROUP BY
                COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan'),
                d.nama_produk,
                pr.satuan
            HAVING SUM(d.qty) > 0
            ORDER BY
                COALESCE(NULLIF(TRIM(l.lokasi), ''), 'Lokasi belum ditentukan') ASC,
                d.nama_produk ASC
        ");

        foreach ($monitorVendorRows as $monitorVendorRow) {
            $monitorVendorId = (int)$monitorVendorRow['id'];

            $stmtMonitorDetails->execute([':id' => $monitorVendorId]);
            $monitorVendorRow['details'] = $stmtMonitorDetails->fetchAll(PDO::FETCH_ASSOC);

            $stmtMonitorSources->execute([':id' => $monitorVendorId]);
            $monitorVendorRow['sources'] = $stmtMonitorSources->fetchAll(PDO::FETCH_ASSOC);

            $stmtMonitorLocations->execute([':id' => $monitorVendorId]);
            $monitorLocationRows = $stmtMonitorLocations->fetchAll(PDO::FETCH_ASSOC);

            $monitorDeliveryGroups = [];
            foreach ($monitorLocationRows as $monitorLocationRow) {
                $monitorLocationName = trim((string)($monitorLocationRow['lokasi'] ?? ''));
                if ($monitorLocationName === '') {
                    $monitorLocationName = 'Lokasi belum ditentukan';
                }

                if (!isset($monitorDeliveryGroups[$monitorLocationName])) {
                    $monitorDeliveryGroups[$monitorLocationName] = [];
                }

                $monitorDeliveryGroups[$monitorLocationName][] = [
                    'nama_produk' => (string)($monitorLocationRow['nama_produk'] ?? '-'),
                    'satuan'      => (string)($monitorLocationRow['satuan'] ?? 'unit'),
                    'qty'         => (int)($monitorLocationRow['qty'] ?? 0),
                ];
            }

            $monitorVendorRow['delivery_locations'] = $monitorDeliveryGroups;
            $monitorVendorById[$monitorVendorId] = $monitorVendorRow;
        }
    }
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat data proses vendor untuk monitoring: ' . $e->getMessage();
        $flashType = 'error';
    }
}

/*
 * Ringkasan utama mengikuti STATUS PESANAN PELANGGAN (air_pesanan),
 * bukan status internal rekap vendor. Dengan begitu angka pada card sama
 * dengan data yang admin lihat pada alur Pemesanan Air.
 */
$summary = [
    'total'             => 0,
    'baru'              => 0,
    'diproses'          => 0,
    'siap_dikirim'      => 0,
    'dalam_pengiriman'  => 0,
    'selesai'           => 0,
    'batal'             => 0,
    'belum_rekap'       => 0,
];

try {
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN p.status = 'baru' THEN 1 ELSE 0 END) AS baru,
            SUM(CASE WHEN p.status = 'diproses' THEN 1 ELSE 0 END) AS diproses,
            SUM(CASE WHEN p.status = 'siap_dikirim' THEN 1 ELSE 0 END) AS siap_dikirim,
            SUM(CASE WHEN p.status = 'dalam_pengiriman' THEN 1 ELSE 0 END) AS dalam_pengiriman,
            SUM(CASE WHEN p.status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
            SUM(CASE WHEN p.status = 'batal' THEN 1 ELSE 0 END) AS batal,
            SUM(CASE
                WHEN p.status <> 'batal'
                 AND NOT (p.status = 'selesai' AND $historicalSql)
                 AND NOT EXISTS (
                    SELECT 1
                    FROM air_vendor_order_source s
                    JOIN air_vendor_order vo ON vo.id = s.vendor_order_id
                    WHERE s.pesanan_id = p.id
                      AND vo.status <> 'batal'
                 )
                THEN 1 ELSE 0 END
            ) AS belum_rekap
        FROM air_pesanan p
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        if (array_key_exists($key, $summaryRow)) {
            $summary[$key] = (int)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat ringkasan status pesanan: ' . $e->getMessage();
        $flashType = 'error';
    }
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


        .unfinished-table td {
            vertical-align: middle;
        }

        .unfinished-location {
            max-width: 240px;
        }

        @media(max-width:1023px) {
            .unfinished-location {
                max-width: none;
            }
        }


        /* Action buttons monitoring: compact, sejajar, dan konsisten */
        .monitor-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 7px;
            flex-wrap: nowrap;
        }

        .monitor-action-btn {
            height: 34px;
            min-height: 34px !important;
            padding: 0 12px !important;
            border-radius: 7px;
            font-size: 9px !important;
            font-weight: 800 !important;
            letter-spacing: .05em;
            white-space: nowrap;
            gap: 6px !important;
            box-shadow: none;
        }

        .monitor-action-view {
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #374151;
        }

        .monitor-action-view:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .monitor-action-done {
            border: 1px solid #16a34a;
            background: #16a34a;
            color: #fff;
        }

        .monitor-action-done:hover {
            background: #15803d;
            border-color: #15803d;
        }

        .monitor-actions form {
            margin: 0;
        }

        @media(max-width:1023px) {
            .monitor-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                width: 100%;
                gap: 8px;
            }

            .monitor-action-btn {
                width: 100%;
                justify-content: center;
                height: 40px;
            }
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

        /* ==========================================================
           SATU TABEL MONITORING UTAMA
           ========================================================== */
        .workflow-filter {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) 190px;
            gap: 10px;
        }

        /* Search monitoring: icon dan teks dibuat benar-benar sejajar.
           Padding memakai !important karena .field mendefinisikan padding sendiri. */
        .workflow-search-wrap {
            position: relative;
            min-width: 0;
        }

        .workflow-search-icon {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            border-right: 1px solid #eef0f3;
            pointer-events: none;
            z-index: 2;
        }

        .workflow-search-icon svg {
            width: 15px !important;
            height: 15px !important;
            stroke-width: 2;
        }

        .workflow-search-input {
            height: 44px;
            padding-left: 56px !important;
            padding-right: 34px !important;
            background: #fff !important;
            line-height: 44px;
        }

        .workflow-search-input::placeholder {
            color: #9ca3af;
            font-weight: 600;
            opacity: 1;
        }

        .workflow-search-input::-webkit-search-cancel-button {
            cursor: pointer;
        }

        .workflow-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            max-width: 430px;
            margin-left: auto;
        }

        .workflow-btn {
            min-height: 34px !important;
            height: 34px !important;
            padding: 0 11px !important;
            border-radius: 0 !important;
            font-size: 8.5px !important;
            font-weight: 800 !important;
            letter-spacing: .055em !important;
            gap: 6px !important;
            white-space: nowrap;
            box-shadow: none !important;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }

        /* Tema aksi tabel dibuat seragam hitam-putih */
        .workflow-btn-view {
            background: #fff !important;
            border: 1px solid #d1d5db !important;
            color: #111827 !important;
        }

        .workflow-btn-view:hover {
            background: #f8fafc !important;
            border-color: #111827 !important;
        }

        .workflow-btn-recap,
        .workflow-btn-vendor,
        .workflow-btn-receive,
        .workflow-btn-done {
            background: #111827 !important;
            border: 1px solid #111827 !important;
            color: #fff !important;
        }

        .workflow-btn-recap:hover,
        .workflow-btn-vendor:hover,
        .workflow-btn-receive:hover,
        .workflow-btn-done:hover {
            background: #000 !important;
            border-color: #000 !important;
        }

        .workflow-actions form {
            margin: 0;
        }

        .workflow-actions .workflow-btn svg {
            width: 13px !important;
            height: 13px !important;
            stroke-width: 2;
        }

        .workflow-stage {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        .workflow-stage::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
        }

        @media(max-width:1023px) {
            .workflow-filter {
                grid-template-columns: 1fr;
            }

            .workflow-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
            }

            .workflow-actions form,
            .workflow-actions .workflow-btn {
                width: 100%;
            }
        }


        .workflow-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 14px;
            border-top: 1px solid #eef0f3;
        }

        .workflow-pagination-info {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
        }

        .workflow-limit-wrap {
            display: flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
        }

        .workflow-limit-select {
            height: 32px;
            min-width: 58px;
            padding: 0 24px 0 9px;
            border: 1px solid #dfe3e8;
            background: #fff;
            color: #111827;
            font-size: 10px;
            font-weight: 800;
            outline: none;
        }

        .workflow-page-buttons {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
            flex-wrap: wrap;
        }

        .workflow-page-btn {
            min-width: 32px;
            height: 32px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dfe3e8;
            background: #fff;
            color: #111827;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
            transition: .15s ease;
        }

        .workflow-page-btn:hover:not(:disabled),
        .workflow-page-btn.is-active {
            background: #111827;
            border-color: #111827;
            color: #fff;
        }

        .workflow-page-btn:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        .workflow-page-dots {
            min-width: 24px;
            text-align: center;
            color: #94a3b8;
            font-size: 10px;
            font-weight: 800;
        }

        @media(max-width:767px) {
            .workflow-pagination {
                align-items: stretch;
                flex-direction: column;
            }

            .workflow-pagination-info {
                justify-content: space-between;
                width: 100%;
            }

            .workflow-page-buttons {
                justify-content: center;
                width: 100%;
            }

            .workflow-page-btn {
                min-width: 34px;
                height: 34px;
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
                <p class="text-xs text-gray-400 mt-1">Kelola seluruh alur pesanan air dari satu tabel: rekap vendor, penerimaan, distribusi, hingga selesai.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="air_pesanan.php" class="btn border border-gray-200 bg-white text-gray-700">
                    <i data-lucide="clipboard-list" class="w-4 h-4"></i> Pesanan Pelanggan
                </a>
                <button type="button"
                    onclick="openCreateModal()"
                    class="btn bg-black text-white">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Buat Rekap Vendor
                </button>
            </div>
        </div>

        <div class="summary-grid grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 md:gap-4 mb-6">
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Total Pesanan</p>
                <p class="text-2xl font-bold mt-2"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[9px] text-gray-400 mt-1">semua status</p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-blue-600">Baru</p>
                <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo number_format($summary['baru']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-amber-600">Diproses</p>
                <p class="text-2xl font-bold text-amber-600 mt-2"><?php echo number_format($summary['diproses']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-purple-600">Siap Dikirim</p>
                <p class="text-2xl font-bold text-purple-600 mt-2"><?php echo number_format($summary['siap_dikirim']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-cyan-600">Dalam Pengiriman</p>
                <p class="text-2xl font-bold text-cyan-600 mt-2"><?php echo number_format($summary['dalam_pengiriman']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-green-600">Selesai</p>
                <p class="text-2xl font-bold text-green-600 mt-2"><?php echo number_format($summary['selesai']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-red-600">Batal</p>
                <p class="text-2xl font-bold text-red-600 mt-2"><?php echo number_format($summary['batal']); ?></p>
            </div>
            <div class="summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-orange-600">Belum Direkap</p>
                <p class="text-2xl font-bold text-orange-600 mt-2"><?php echo number_format($summary['belum_rekap']); ?></p>
                <p class="text-[9px] text-gray-400 mt-1">masih aktif</p>
            </div>
        </div>

        <section class="recap-card p-4 md:p-5 mb-6">
            <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-4 mb-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-blue-600">Monitoring Utama</p>
                    <h2 class="text-lg font-bold mt-1">Monitoring Pesanan Air</h2>
                    <p class="text-[10px] text-gray-400 mt-1">
                        Tabel ini menampilkan seluruh pesanan dari semua status, termasuk Selesai dan Batal. Gunakan filter status untuk mempersempit tampilan.
                    </p>
                </div>

                <div class="workflow-filter w-full xl:w-auto xl:min-w-[520px]">
                    <div class="workflow-search-wrap">
                        <span class="workflow-search-icon" aria-hidden="true">
                            <i data-lucide="search"></i>
                        </span>
                        <input type="search"
                            id="workflowSearch"
                            class="field workflow-search-input"
                            placeholder="Cari nomor pesanan, pemesan, lokasi, atau rekap vendor..."
                            autocomplete="off"
                            aria-label="Cari pesanan air">
                    </div>
                    <select id="workflowStatus" class="field">
                        <option value="">Semua Status</option>
                        <option value="baru">Baru</option>
                        <option value="diproses">Diproses</option>
                        <option value="siap_dikirim">Siap Dikirim</option>
                        <option value="dalam_pengiriman">Dalam Pengiriman</option>
                        <option value="selesai">Selesai</option>
                        <option value="batal">Batal</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2 mb-4">
                <div class="border border-subtle bg-gray-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-gray-400">Total Tampil</p>
                    <p class="text-xl font-bold mt-1"><?php echo number_format($unfinishedSummary['total']); ?></p>
                </div>
                <div class="border border-blue-100 bg-blue-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-blue-600">Baru</p>
                    <p class="text-xl font-bold text-blue-700 mt-1"><?php echo number_format($unfinishedSummary['baru']); ?></p>
                </div>
                <div class="border border-amber-100 bg-amber-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-amber-600">Diproses</p>
                    <p class="text-xl font-bold text-amber-700 mt-1"><?php echo number_format($unfinishedSummary['diproses']); ?></p>
                </div>
                <div class="border border-purple-100 bg-purple-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-purple-600">Siap Dikirim</p>
                    <p class="text-xl font-bold text-purple-700 mt-1"><?php echo number_format($unfinishedSummary['siap_dikirim']); ?></p>
                </div>
                <div class="border border-cyan-100 bg-cyan-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-cyan-600">Dalam Pengiriman</p>
                    <p class="text-xl font-bold text-cyan-700 mt-1"><?php echo number_format($unfinishedSummary['dalam_pengiriman']); ?></p>
                </div>
                <div class="border border-green-100 bg-green-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-green-600">Selesai</p>
                    <p class="text-xl font-bold text-green-700 mt-1"><?php echo number_format($unfinishedSummary['selesai']); ?></p>
                </div>
                <div class="border border-red-100 bg-red-50 p-3">
                    <p class="text-[8px] font-bold uppercase tracking-widest text-red-600">Batal</p>
                    <p class="text-xl font-bold text-red-700 mt-1"><?php echo number_format($unfinishedSummary['batal']); ?></p>
                </div>
            </div>

            <div class="hidden lg:block border border-subtle overflow-x-auto">
                <table class="w-full min-w-[1320px] text-left">
                    <thead class="bg-gray-50 border-b border-subtle">
                        <tr>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400">Pesanan</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400">Pemesan</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400">Lokasi</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400 text-center">Produk</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400">Rekap Vendor</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-4 py-3 text-[9px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi Berikutnya</th>
                        </tr>
                    </thead>

                    <tbody id="workflowTableBody" class="divide-y divide-[#f5f5f5]">
                        <?php if (!$unfinishedOrders): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-14 text-center text-xs font-bold uppercase tracking-widest text-green-600">
                                    Belum ada data pesanan
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($unfinishedOrders as $customerOrder): ?>
                                <?php
                                $locations = trim((string)($customerOrder['daftar_lokasi'] ?? '')) !== ''
                                    ? explode('||', (string)$customerOrder['daftar_lokasi'])
                                    : [];

                                $monitorVendorId = (int)($customerOrder['latest_vendor_order_id'] ?? 0);
                                $monitorVendor = $monitorVendorById[$monitorVendorId] ?? null;

                                $searchText = strtolower(
                                    (string)($customerOrder['nomor_pesanan'] ?? '') . ' ' .
                                        (string)($customerOrder['nama_pemesan'] ?? '') . ' ' .
                                        (string)($customerOrder['no_hp'] ?? '') . ' ' .
                                        implode(' ', $locations) . ' ' .
                                        (string)($monitorVendor['nomor_vendor_order'] ?? '') . ' ' .
                                        (string)($monitorVendor['vendor_nama'] ?? '') . ' ' .
                                        (string)($customerOrder['status'] ?? '')
                                );
                                ?>
                                <tr class="workflow-row"
                                    data-status="<?php echo arv_h((string)$customerOrder['status']); ?>"
                                    data-search="<?php echo arv_h($searchText); ?>">
                                    <td class="px-4 py-3">
                                        <p class="text-xs font-bold"><?php echo arv_h($customerOrder['nomor_pesanan']); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1">
                                            Input <?php echo arv_h(date('d/m/Y H:i', strtotime((string)$customerOrder['created_at']))); ?> WIB
                                        </p>
                                    </td>

                                    <td class="px-4 py-3">
                                        <p class="text-xs font-bold"><?php echo arv_h($customerOrder['nama_pemesan']); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1"><?php echo arv_h($customerOrder['no_hp'] ?: '-'); ?></p>
                                    </td>

                                    <td class="px-4 py-3">
                                        <p class="text-xs font-bold">
                                            <?php echo arv_h(date('d/m/Y', strtotime((string)$customerOrder['tanggal_operasional']))); ?>
                                        </p>
                                        <p class="text-[9px] text-gray-400 mt-1">Tanggal operasional</p>
                                    </td>

                                    <td class="px-4 py-3 max-w-[220px]">
                                        <?php if ($locations): ?>
                                            <p class="text-xs font-bold truncate"><?php echo arv_h($locations[0]); ?></p>
                                            <?php if (count($locations) > 1): ?>
                                                <p class="text-[9px] text-gray-400 mt-1">+<?php echo number_format(count($locations) - 1); ?> lokasi lain</p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="text-xs text-gray-400">Lokasi belum ditentukan</p>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <p class="text-sm font-bold text-blue-600"><?php echo number_format((int)$customerOrder['total_unit']); ?></p>
                                        <p class="text-[8px] text-gray-400 uppercase">unit</p>
                                    </td>

                                    <td class="px-4 py-3">
                                        <?php if ($monitorVendor): ?>
                                            <p class="text-[10px] font-bold"><?php echo arv_h($monitorVendor['nomor_vendor_order']); ?></p>
                                            <p class="text-[9px] text-gray-400 mt-1">
                                                <?php echo arv_h($monitorVendor['vendor_nama'] ?: '-'); ?> ·
                                                <?php echo arv_h(arv_status_label((string)$monitorVendor['status'])); ?>
                                            </p>
                                        <?php else: ?>
                                            <?php if ((string)$customerOrder['status'] === 'selesai' && !empty($customerOrder['is_data_historis'])): ?>
                                                <span class="workflow-stage text-gray-500 border-gray-200 bg-gray-50">Arsip Lama</span>
                                            <?php else: ?>
                                                <span class="workflow-stage text-gray-700 border-gray-300 bg-white">Belum Direkap</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <span class="status-badge <?php echo arv_customer_status_class((string)$customerOrder['status']); ?>">
                                            <?php echo arv_h(arv_customer_status_label((string)$customerOrder['status'])); ?>
                                        </span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="workflow-actions">
                                            <button type="button"
                                                onclick='openCustomerDetail(<?php echo json_encode($customerOrder, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="btn workflow-btn workflow-btn-view"
                                                title="Lihat detail pesanan">
                                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                                Lihat
                                            </button>

                                            <?php if (!in_array((string)$customerOrder['status'], ['selesai', 'batal'], true)): ?>
                                                <?php if (!$monitorVendor): ?>
                                                    <button type="button"
                                                        onclick="openCreateModalForOrder(<?php echo (int)$customerOrder['id']; ?>)"
                                                        class="btn workflow-btn workflow-btn-recap">
                                                        <i data-lucide="clipboard-plus" class="w-3.5 h-3.5"></i>
                                                        Rekap
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                        onclick='openDetail(<?php echo json_encode($monitorVendor, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                        class="btn workflow-btn workflow-btn-vendor">
                                                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                                                        Detail Vendor
                                                    </button>

                                                    <?php if (!in_array((string)$monitorVendor['status'], ['diterima', 'selesai', 'batal'], true)): ?>
                                                        <button type="button"
                                                            onclick='openQuantity(<?php echo json_encode($monitorVendor, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                            class="btn workflow-btn workflow-btn-receive">
                                                            <i data-lucide="package-check" class="w-3.5 h-3.5"></i>
                                                            Terima Barang
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; // status pelanggan masih aktif (bukan selesai/batal) 
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <tr id="workflowEmptyRow" class="hidden">
                            <td colspan="8" class="px-4 py-12 text-center text-xs font-bold uppercase tracking-widest text-gray-400">
                                Pesanan tidak ditemukan
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="workflowMobileList" class="lg:hidden space-y-3">
                <?php if (!$unfinishedOrders): ?>
                    <div class="border border-green-100 bg-green-50 p-6 text-center text-xs font-bold text-green-700">
                        Belum ada data pesanan.
                    </div>
                <?php else: ?>
                    <?php foreach ($unfinishedOrders as $customerOrder): ?>
                        <?php
                        $locations = trim((string)($customerOrder['daftar_lokasi'] ?? '')) !== ''
                            ? explode('||', (string)$customerOrder['daftar_lokasi'])
                            : [];

                        $monitorVendorId = (int)($customerOrder['latest_vendor_order_id'] ?? 0);
                        $monitorVendor = $monitorVendorById[$monitorVendorId] ?? null;

                        $searchText = strtolower(
                            (string)($customerOrder['nomor_pesanan'] ?? '') . ' ' .
                                (string)($customerOrder['nama_pemesan'] ?? '') . ' ' .
                                (string)($customerOrder['no_hp'] ?? '') . ' ' .
                                implode(' ', $locations) . ' ' .
                                (string)($monitorVendor['nomor_vendor_order'] ?? '') . ' ' .
                                (string)($monitorVendor['vendor_nama'] ?? '') . ' ' .
                                (string)($customerOrder['status'] ?? '')
                        );
                        ?>
                        <article class="workflow-mobile-card border border-subtle bg-white p-4"
                            data-status="<?php echo arv_h((string)$customerOrder['status']); ?>"
                            data-search="<?php echo arv_h($searchText); ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold break-all"><?php echo arv_h($customerOrder['nomor_pesanan']); ?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo arv_h($customerOrder['nama_pemesan']); ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">
                                        <?php echo arv_h(date('d/m/Y', strtotime((string)$customerOrder['tanggal_operasional']))); ?>
                                    </p>
                                </div>
                                <span class="status-badge <?php echo arv_customer_status_class((string)$customerOrder['status']); ?>">
                                    <?php echo arv_h(arv_customer_status_label((string)$customerOrder['status'])); ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2 mt-4">
                                <div class="border border-subtle bg-gray-50 p-3">
                                    <p class="text-[8px] font-bold uppercase text-gray-400">Produk</p>
                                    <p class="text-sm font-bold text-blue-600 mt-1"><?php echo number_format((int)$customerOrder['total_unit']); ?> unit</p>
                                </div>
                                <div class="border border-subtle bg-gray-50 p-3">
                                    <p class="text-[8px] font-bold uppercase text-gray-400">Lokasi</p>
                                    <p class="text-xs font-bold mt-1 truncate">
                                        <?php echo $locations ? arv_h($locations[0]) : 'Belum ditentukan'; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3 border border-subtle p-3">
                                <p class="text-[8px] font-bold uppercase tracking-widest text-gray-400">Rekap Vendor</p>
                                <?php if ($monitorVendor): ?>
                                    <p class="text-xs font-bold mt-1"><?php echo arv_h($monitorVendor['nomor_vendor_order']); ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">
                                        <?php echo arv_h($monitorVendor['vendor_nama'] ?: '-'); ?> ·
                                        <?php echo arv_h(arv_status_label((string)$monitorVendor['status'])); ?>
                                    </p>
                                <?php else: ?>
                                    <?php if ((string)$customerOrder['status'] === 'selesai' && !empty($customerOrder['is_data_historis'])): ?>
                                        <p class="text-xs font-bold text-gray-500 mt-1">Arsip lama · tidak wajib rekap vendor</p>
                                    <?php else: ?>
                                        <p class="text-xs font-bold text-red-600 mt-1">Belum direkap vendor</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="workflow-actions mt-3">
                                <button type="button"
                                    onclick='openCustomerDetail(<?php echo json_encode($customerOrder, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                    class="btn workflow-btn workflow-btn-view"
                                    title="Lihat detail pesanan">
                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                    Lihat
                                </button>

                                <?php if (!in_array((string)$customerOrder['status'], ['selesai', 'batal'], true)): ?>
                                    <?php if (!$monitorVendor): ?>
                                        <button type="button"
                                            onclick="openCreateModalForOrder(<?php echo (int)$customerOrder['id']; ?>)"
                                            class="btn workflow-btn workflow-btn-recap">
                                            <i data-lucide="clipboard-plus" class="w-3.5 h-3.5"></i>
                                            Rekap
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            onclick='openDetail(<?php echo json_encode($monitorVendor, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                            class="btn workflow-btn workflow-btn-vendor">
                                            <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                                            Detail Vendor
                                        </button>

                                        <?php if (!in_array((string)$monitorVendor['status'], ['diterima', 'selesai', 'batal'], true)): ?>
                                            <button type="button"
                                                onclick='openQuantity(<?php echo json_encode($monitorVendor, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="btn workflow-btn workflow-btn-receive">
                                                <i data-lucide="package-check" class="w-3.5 h-3.5"></i>
                                                Terima Barang
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; // status pelanggan masih aktif (bukan selesai/batal) 
                                ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div id="workflowMobileEmpty" class="hidden border border-dashed border-gray-200 bg-gray-50 p-6 text-center">
                    <p class="text-xs font-bold text-gray-400">Pesanan tidak ditemukan.</p>
                </div>
            </div>

            <div id="workflowPagination" class="workflow-pagination mt-4">
                <div class="workflow-pagination-info">
                    <span id="workflowPageInfo">Menampilkan 0 data</span>
                    <label class="workflow-limit-wrap">
                        <span>Per halaman</span>
                        <select id="workflowPageSize" class="workflow-limit-select">
                            <option value="10">10</option>
                            <option value="15" selected>15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>
                <div id="workflowPageButtons" class="workflow-page-buttons"></div>
            </div>
        </section>

    </main>

    <!-- Modal buat rekap -->
    <div id="createModal" class="modal-wrap fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel w-full max-w-4xl bg-white overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest">Buat Rekap Vendor</h2>
                    <p class="text-[10px] text-gray-400 mt-1">Semua pesanan belum direkap ditampilkan. Pilih pesanan dengan tanggal kebutuhan yang sama untuk satu rekap.</p>
                </div><button type="button" onclick="closeModal('createModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="create_vendor_order">
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
                                <input type="checkbox" id="checkAllSources" onchange="toggleAllSources(this.checked)" class="accent-black">
                                Pilih Semua
                            </label>
                        </div>

                        <div class="max-h-72 overflow-y-auto space-y-2">
                            <?php if (!$availableOrders): ?>
                                <div class="border border-dashed border-gray-200 bg-gray-50 p-5 text-center">
                                    <p class="text-xs font-bold text-gray-600">Belum ada pesanan yang dapat direkap.</p>
                                    <p class="text-[10px] text-gray-400 mt-1">
                                        Pastikan pesanan belum selesai/batal dan belum masuk rekap vendor aktif.
                                    </p>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($availableOrders as $source): ?>
                                <label class="block border border-subtle bg-white p-3 hover:bg-gray-50 cursor-pointer">
                                    <div class="flex items-start gap-3">
                                        <input type="checkbox"
                                            name="source_ids[]"
                                            value="<?php echo (int)$source['id']; ?>"
                                            data-need-date="<?php echo arv_h((string)($source['tanggal_operasional'] ?? '')); ?>"
                                            class="source-check accent-black mt-1"
                                            onchange="handleSourceDateSelection(this)">

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
                                                    <p class="text-[9px] font-black text-gray-500 mt-1">Kebutuhan: <?php echo !empty($source['tanggal_operasional']) ? arv_h(date('d/m/Y', strtotime((string)$source['tanggal_operasional']))) : '-'; ?></p>
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
                <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3"><button type="button" onclick="closeModal('createModal')" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle bg-white">Batal</button><button type="submit"
                        id="saveCreateRecapButton"
                        class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white disabled:opacity-40 disabled:cursor-not-allowed"
                        <?php echo !$availableOrders ? 'disabled' : ''; ?>>
                        Simpan Rekap
                    </button></div>
            </form>
        </div>
    </div>

    <!-- Modal detail -->

    <div id="customerDetailModal" class="modal-wrap fixed inset-0 z-[115] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-card w-full max-w-4xl bg-white border border-subtle shadow-xl max-h-[92vh] overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle bg-white">
                <div>
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Detail Pesanan Air</p>
                    <h2 id="customerDetailTitle" class="text-base font-black mt-1">-</h2>
                </div>
                <button type="button" onclick="closeModal('customerDetailModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div id="customerDetailBody" class="modal-body max-h-[78vh] overflow-y-auto px-5 md:px-7 py-6"></div>
        </div>
    </div>

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
                    <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Penerimaan Barang &amp; Catatan Vendor</p>
                    <h2 id="quantityTitle" class="text-base font-black mt-1">-</h2>
                </div><button onclick="closeModal('quantityModal')" class="p-2 hover:bg-gray-100"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_quantities"><input type="hidden" name="vendor_order_id" id="quantityOrderId">
                <div id="quantityBody" class="modal-body max-h-[72vh] overflow-y-auto px-5 md:px-7 py-6"></div>
                <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3"><button type="button" onclick="closeModal('quantityModal')" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle bg-white">Batal</button><button type="submit" class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white">Simpan Penerimaan</button></div>
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


        function openCreateModalForOrder(orderId) {
            var targetId = Number(orderId || 0);

            document.querySelectorAll('.source-check').forEach(function(el) {
                el.checked = Number(el.value || 0) === targetId;
            });

            var checkAll = document.getElementById('checkAllSources');
            if (checkAll) {
                checkAll.checked = false;
            }

            refreshCreateRecapSummary();
            openModal('createModal');
        }

        var workflowCurrentPage = 1;
        var workflowPageSize = 15;

        function getWorkflowFilteredElements(selector) {
            var search = document.getElementById('workflowSearch');
            var status = document.getElementById('workflowStatus');
            var keyword = search ? search.value.toLowerCase().trim() : '';
            var statusValue = status ? status.value : '';

            return Array.prototype.filter.call(document.querySelectorAll(selector), function(el) {
                var haystack = String(el.getAttribute('data-search') || '').toLowerCase();
                var rowStatus = String(el.getAttribute('data-status') || '');
                return (keyword === '' || haystack.indexOf(keyword) !== -1) &&
                    (statusValue === '' || rowStatus === statusValue);
            });
        }

        function buildWorkflowPageButtons(totalPages) {
            var wrap = document.getElementById('workflowPageButtons');
            if (!wrap) return;
            wrap.innerHTML = '';
            if (totalPages <= 1) return;

            function addButton(label, page, disabled, active) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'workflow-page-btn' + (active ? ' is-active' : '');
                btn.textContent = label;
                btn.disabled = !!disabled;
                btn.addEventListener('click', function() {
                    workflowCurrentPage = page;
                    renderWorkflowPage();
                    var table = document.getElementById('workflowTableBody');
                    if (table && window.innerWidth >= 1024) table.closest('section').scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                });
                wrap.appendChild(btn);
            }

            addButton('‹', Math.max(1, workflowCurrentPage - 1), workflowCurrentPage === 1, false);
            var pages = [];
            if (totalPages <= 7) {
                for (var i = 1; i <= totalPages; i++) pages.push(i);
            } else {
                pages = [1];
                var a = Math.max(2, workflowCurrentPage - 1),
                    b = Math.min(totalPages - 1, workflowCurrentPage + 1);
                if (a > 2) pages.push('...');
                for (var j = a; j <= b; j++) pages.push(j);
                if (b < totalPages - 1) pages.push('...');
                pages.push(totalPages);
            }
            pages.forEach(function(pg) {
                if (pg === '...') {
                    var dots = document.createElement('span');
                    dots.className = 'workflow-page-dots';
                    dots.textContent = '...';
                    wrap.appendChild(dots);
                } else addButton(String(pg), pg, false, pg === workflowCurrentPage);
            });
            addButton('›', Math.min(totalPages, workflowCurrentPage + 1), workflowCurrentPage === totalPages, false);
        }

        function renderWorkflowPage() {
            var desktopAll = document.querySelectorAll('.workflow-row');
            var mobileAll = document.querySelectorAll('.workflow-mobile-card');
            var desktopFiltered = getWorkflowFilteredElements('.workflow-row');
            var mobileFiltered = getWorkflowFilteredElements('.workflow-mobile-card');
            var total = desktopFiltered.length || mobileFiltered.length;
            var totalPages = Math.max(1, Math.ceil(total / workflowPageSize));
            if (workflowCurrentPage > totalPages) workflowCurrentPage = totalPages;
            var start = (workflowCurrentPage - 1) * workflowPageSize;
            var end = Math.min(start + workflowPageSize, total);

            desktopAll.forEach(function(el) {
                el.style.display = 'none';
            });
            mobileAll.forEach(function(el) {
                el.style.display = 'none';
            });
            desktopFiltered.slice(start, end).forEach(function(el) {
                el.style.display = '';
            });
            mobileFiltered.slice(start, end).forEach(function(el) {
                el.style.display = '';
            });

            var emptyRow = document.getElementById('workflowEmptyRow');
            if (emptyRow) emptyRow.classList.toggle('hidden', total > 0);
            var mobileEmpty = document.getElementById('workflowMobileEmpty');
            if (mobileEmpty) mobileEmpty.classList.toggle('hidden', total > 0);

            var info = document.getElementById('workflowPageInfo');
            if (info) info.textContent = total > 0 ? ('Menampilkan ' + (start + 1) + '–' + end + ' dari ' + total + ' data') : 'Menampilkan 0 data';
            var pagination = document.getElementById('workflowPagination');
            if (pagination) pagination.style.display = total > 0 ? 'flex' : 'none';
            buildWorkflowPageButtons(totalPages);
        }

        function applyWorkflowFilter() {
            workflowCurrentPage = 1;
            renderWorkflowPage();
        }

        var workflowSearch = document.getElementById('workflowSearch');
        if (workflowSearch) {
            workflowSearch.addEventListener('input', applyWorkflowFilter);
            workflowSearch.addEventListener('search', applyWorkflowFilter);
        }
        var workflowStatus = document.getElementById('workflowStatus');
        if (workflowStatus) workflowStatus.addEventListener('change', applyWorkflowFilter);
        var workflowPageSizeSelect = document.getElementById('workflowPageSize');
        if (workflowPageSizeSelect) {
            workflowPageSizeSelect.addEventListener('change', function() {
                workflowPageSize = parseInt(this.value, 10) || 15;
                workflowCurrentPage = 1;
                renderWorkflowPage();
            });
        }
        renderWorkflowPage();

        function openCreateModal() {
            refreshCreateRecapSummary();
            openModal('createModal');

            var firstVendorInput = document.querySelector('#createModal input[name="vendor_nama"]');
            if (firstVendorInput) {
                setTimeout(function() {
                    firstVendorInput.focus();
                }, 80);
            }
        }

        function handleSourceDateSelection(changed) {
            if (changed && changed.checked) {
                var selectedDate = changed.dataset.needDate || '';
                document.querySelectorAll('.source-check').forEach(function(el) {
                    if (el !== changed && el.checked && (el.dataset.needDate || '') !== selectedDate) {
                        el.checked = false;
                    }
                });
            }
            var checkAll = document.getElementById('checkAllSources');
            if (checkAll) checkAll.checked = false;
            refreshCreateRecapSummary();
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

            var saveButton = document.getElementById('saveCreateRecapButton');
            if (saveButton) {
                saveButton.disabled = checked.length === 0;
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


        function formatCustomerDate(value) {
            if (!value) return '-';
            var parts = String(value).substring(0, 10).split('-');
            return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
        }

        function openCustomerDetail(order) {
            if (!order) return;
            document.getElementById('customerDetailTitle').textContent = order.nomor_pesanan || '-';
            var locations = Array.isArray(order.locations) ? order.locations : [];
            var locationHtml = locations.map(function(location, index) {
                var items = Array.isArray(location.items) ? location.items : [];
                var itemHtml = items.length ? items.map(function(item) {
                    return '<div class="flex items-start justify-between gap-3 py-3 border-b border-gray-100 last:border-b-0">' +
                        '<div class="min-w-0"><p class="text-sm font-bold">' + escapeHtml(item.nama_produk || '-') + '</p>' +
                        (item.catatan_item ? '<p class="text-[10px] text-gray-400 mt-1">' + escapeHtml(item.catatan_item) + '</p>' : '') + '</div>' +
                        '<div class="text-right shrink-0"><p class="text-lg font-black">' + Number(item.qty || 0).toLocaleString('id-ID') + '</p><p class="text-[8px] uppercase text-gray-400">' + escapeHtml(item.satuan || 'unit') + '</p></div></div>';
                }).join('') : '<p class="py-3 text-xs text-gray-400">Belum ada rincian produk.</p>';
                return '<div class="border border-subtle p-4 mb-3"><div class="flex justify-between gap-3"><div><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Lokasi ' + (index + 1) + '</p><p class="text-sm font-black mt-1">' + escapeHtml(location.lokasi || '-') + '</p></div></div>' +
                    (location.catatan ? '<div class="mt-3 border border-amber-100 bg-amber-50 p-3 text-xs text-amber-700">' + escapeHtml(location.catatan) + '</div>' : '') +
                    '<div class="mt-2">' + itemHtml + '</div></div>';
            }).join('');
            var orderDate = order.tanggal_pemesanan || String(order.created_at || '').substring(0, 10);
            var html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">' +
                '<div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Pemesan</p><p class="text-sm font-black mt-1">' + escapeHtml(order.nama_pemesan || '-') + '</p><p class="text-xs text-gray-500 mt-1">' + escapeHtml(order.no_hp || '-') + '</p></div>' +
                '<div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Jadwal</p><p class="text-sm font-black mt-1">Pesan ' + formatCustomerDate(orderDate) + '</p><p class="text-xs text-gray-500 mt-1">Kirim ' + formatCustomerDate(order.tanggal_kirim) + (order.jam_kirim ? ' · ' + String(order.jam_kirim).substring(0, 5) + ' WIB' : '') + '</p></div></div>' +
                '<div class="grid grid-cols-2 gap-3 mt-3"><div class="border border-subtle p-4"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Total Lokasi</p><p class="text-xl font-black mt-1">' + Number(order.total_lokasi || locations.length).toLocaleString('id-ID') + '</p></div><div class="border border-subtle p-4"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Total Produk</p><p class="text-xl font-black text-blue-600 mt-1">' + Number(order.total_unit || 0).toLocaleString('id-ID') + ' unit</p></div></div>' +
                '<div class="mt-5"><p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-3">Rincian Distribusi</p>' + (locationHtml || '<div class="border border-subtle p-5 text-center text-xs text-gray-400">Belum ada rincian lokasi.</div>') + '</div>' +
                (order.catatan ? '<div class="mt-4 border border-amber-100 bg-amber-50 p-4"><p class="text-[9px] font-bold uppercase tracking-widest text-amber-700">Catatan Pesanan</p><p class="text-xs text-amber-700 mt-1">' + escapeHtml(order.catatan) + '</p></div>' : '');
            document.getElementById('customerDetailBody').innerHTML = html;
            openModal('customerDetailModal');
            if (window.lucide) lucide.createIcons();
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
            document.getElementById('detailBody').innerHTML = '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5"><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Vendor</p><p class="text-sm font-bold mt-1">' + escapeHtml(order.vendor_nama) + '</p><p class="text-xs text-gray-400 mt-1">' + escapeHtml(order.vendor_wa || '-') + '</p></div><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Tanggal Kebutuhan</p><p class="text-sm font-bold mt-1">' + formatDate(order.tanggal_kebutuhan) + '</p></div><div class="border border-subtle bg-gray-50 p-4"><p class="text-[9px] font-bold uppercase text-gray-400">Status</p><p class="text-sm font-bold mt-1">' + escapeHtml(statusLabel(order.status)) + '</p></div></div><div class="border border-subtle overflow-x-auto"><table class="w-full min-w-[760px]"><thead class="bg-gray-50 border-b border-subtle"><tr><th class="px-4 py-3 text-left text-[9px] uppercase text-gray-400">Produk</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diminta</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dipesan</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Konfirmasi</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diterima</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Kekurangan</th></tr></thead><tbody class="divide-y divide-[#f5f5f5]">' + rows + '</tbody></table></div><div class="mt-5"><p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 mb-2">Sumber Pesanan Pelanggan</p><div class="flex flex-wrap gap-2">' + (sourceHtml || '<span class="text-xs text-gray-400">Tidak ada data.</span>') + '</div></div>' + (order.catatan ? '<div class="mt-5 border border-amber-100 bg-amber-50 p-4 text-xs text-amber-700">' + escapeHtml(order.catatan) + '</div>' : '') + '<div class="mt-5 flex flex-wrap justify-end gap-2"><button type="button" id="detailVendorWaBtn" class="btn bg-black text-white"><span class="inline-flex items-center gap-2"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i>Kirim WA Vendor</span></button></div>';
            var waBtn = document.getElementById('detailVendorWaBtn');
            if (waBtn) waBtn.onclick = function() {
                sendWhatsApp(order);
            };
            openModal('detailModal');
            if (window.lucide) lucide.createIcons();
        }

        function openQuantity(order) {
            document.getElementById('quantityTitle').textContent = order.nomor_vendor_order || '-';
            document.getElementById('quantityOrderId').value = Number(order.id || 0);
            var details = Array.isArray(order.details) ? order.details : [];
            var html = '<div class="border border-subtle overflow-x-auto"><table class="w-full min-w-[680px]"><thead class="bg-gray-50 border-b border-subtle"><tr><th class="px-4 py-3 text-left text-[9px] uppercase text-gray-400">Produk</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dipesan</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Dikonfirmasi</th><th class="px-4 py-3 text-center text-[9px] uppercase text-gray-400">Diterima</th></tr></thead><tbody class="divide-y divide-[#f5f5f5]">';
            details.forEach(function(d, i) {
                html += '<tr><td class="px-4 py-3"><input type="hidden" name="detail_id[]" value="' + Number(d.id || 0) + '"><p class="text-xs font-bold">' + escapeHtml(d.nama_produk) + '</p><p class="text-[9px] text-gray-400">Diminta ' + Number(d.jumlah_diminta || 0).toLocaleString('id-ID') + ' ' + escapeHtml(d.satuan) + '</p></td><td class="px-4 py-3 text-center font-bold text-blue-600">' + Number(d.jumlah_dipesan || 0).toLocaleString('id-ID') + '</td><td class="px-4 py-3"><input type="number" name="jumlah_dikonfirmasi[]" min="0" value="' + Number(d.jumlah_dikonfirmasi || 0) + '" class="field text-center"></td><td class="px-4 py-3"><input type="number" name="jumlah_diterima[]" min="0" value="' + Number(d.jumlah_diterima || 0) + '" class="field text-center"></td></tr>'
            });
            html += '</tbody></table></div>';
            html += '<div class="mt-4">' +
                '<label class="block text-[9px] font-bold uppercase tracking-widest text-gray-500 mb-2">Catatan Pengiriman / Catatan Vendor</label>' +
                '<textarea name="catatan" class="field" rows="4" placeholder="Contoh: barang diterima lengkap, kurang 2 unit, kemasan rusak, atau catatan penerimaan lainnya.">' + escapeHtml(order.catatan || '') + '</textarea>' +
                '<p class="text-[9px] text-gray-400 mt-2">Catatan disimpan bersamaan dengan jumlah barang yang dikonfirmasi dan diterima.</p>' +
                '</div>';
            var existingSuratJalan = String(order.surat_jalan_vendor || '');
            html += '<div class="mt-4">' +
                '<label class="block text-[9px] font-bold uppercase tracking-widest text-gray-500 mb-2">Surat Jalan dari Vendor</label>' +
                '<div class="border border-dashed border-gray-300 bg-gray-50 p-4">' +
                '<input type="file" name="surat_jalan_vendor" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf" class="block w-full text-xs text-gray-600 file:mr-3 file:border-0 file:bg-black file:px-4 file:py-2.5 file:text-[9px] file:font-bold file:uppercase file:text-white">' +
                '<p class="mt-2 text-[9px] leading-relaxed text-gray-400">Upload foto/scan surat jalan vendor. Format JPG, PNG, WebP, atau PDF maksimal 8 MB.</p>' +
                (existingSuratJalan ? '<div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-3"><span class="text-[9px] font-bold uppercase text-green-700">Surat jalan sudah tersimpan</span><a href="' + escapeHtml(existingSuratJalan) + '" target="_blank" rel="noopener" class="text-[9px] font-bold uppercase text-blue-700 underline">Lihat File</a><span class="text-[9px] text-gray-400">Upload baru untuk mengganti file lama.</span></div>' : '') +
                '</div>' +
                '</div>';
            html += '<div class="mt-4 border border-blue-100 bg-blue-50 p-4 text-xs text-blue-700">Isi jumlah yang benar-benar diterima di lokasi. Karena vendor mengirim langsung ke lokasi tujuan, setelah penerimaan disimpan pesanan otomatis menjadi <strong>Selesai</strong>.</div>';
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
                return;
            }

            var details = Array.isArray(order.details) ? order.details : [];
            var deliveryLocations = order.delivery_locations && typeof order.delivery_locations === 'object' ?
                order.delivery_locations : {};

            var lines = [
                'Permintaan Air Mineral',
                '',
                'Tanggal Pengiriman: ' + formatDate(order.tanggal_kebutuhan),
                ''
            ];

            var locationNames = Object.keys(deliveryLocations);
            if (locationNames.length) {
                lines.push('Lokasi Pengantaran:');
                lines.push('');
                locationNames.forEach(function(locationName, index) {
                    lines.push(locationName);
                    var items = Array.isArray(deliveryLocations[locationName]) ? deliveryLocations[locationName] : [];
                    items.forEach(function(item) {
                        lines.push('- ' + String(item.nama_produk || '-') + ': ' + Number(item.qty || 0).toLocaleString('id-ID') + ' ' + String(item.satuan || 'unit'));
                    });
                    if (index < locationNames.length - 1) lines.push('');
                });
            } else {
                lines.push('Pesanan:');
                details.forEach(function(d) {
                    lines.push('- ' + String(d.nama_produk || '-') + ': ' + Number(d.jumlah_dipesan || 0).toLocaleString('id-ID') + ' ' + String(d.satuan || 'unit'));
                });
            }

            var totalOrdered = details.reduce(function(total, d) {
                return total + Number(d.jumlah_dipesan || 0);
            }, 0);

            lines.push('');
            lines.push('Total Pesanan: ' + totalOrdered.toLocaleString('id-ID') + ' unit');
            lines.push('');
            lines.push('Mohon konfirmasi ketersediaan dan jadwal pengiriman.');
            lines.push('Terima kasih.');
            lines.push('');
            lines.push('No. Rekap: ' + (order.nomor_vendor_order || '-'));

            window.open('https://wa.me/' + wa + '?text=' + encodeURIComponent(lines.join('\n')), '_blank');

            var form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            form.innerHTML = '<input name="action" value="update_vendor_status"><input name="vendor_order_id" value="' + Number(order.id || 0) + '"><input name="status" value="dikirim">';
            document.body.appendChild(form);
            setTimeout(function() {
                form.submit();
            }, 500);
        }
        refreshCreateRecapSummary();
        document.querySelectorAll('.modal-wrap').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal(modal.id)
            })
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                ['createModal', 'customerDetailModal', 'detailModal', 'quantityModal'].forEach(closeModal)
            }
        });
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>