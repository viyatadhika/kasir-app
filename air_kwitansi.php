<?php
/*
|--------------------------------------------------------------------------
| air_kwitansi.php — Kwitansi Penagihan Air Mineral
|--------------------------------------------------------------------------
| Fungsi:
| - Membuat kwitansi/tagihan berdasarkan pesanan yang sudah selesai
| - Mengambil harga tagihan dari master produk air_produk
| - Menyimpan rincian produk, sumber pesanan, dan status pembayaran
| - Menampilkan tabel desktop, card tablet/mobile, pagination, dan print A4
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

$activeMenu = 'air_kwitansi';
$pageTitle  = 'Kwitansi Penagihan Air Mineral';
$backUrl    = 'dashboard.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('akw_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function akw_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('akw_rupiah')) {
    /**
     * @param mixed $value
     * @return string
     */
    function akw_rupiah($value)
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}

if (!function_exists('akw_user_id')) {
    function akw_user_id(): int
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

if (!function_exists('akw_user_name')) {
    function akw_user_name(): string
    {
        if (!empty($_SESSION['user']['nama'])) {
            return (string)$_SESSION['user']['nama'];
        }
        if (!empty($_SESSION['nama'])) {
            return (string)$_SESSION['nama'];
        }
        return 'Petugas';
    }
}

if (!function_exists('akw_generate_number')) {
    function akw_generate_number(): string
    {
        return 'KW-AIR-' . date('Ym') . '-' . date('His') . '-' . random_int(10, 99);
    }
}

if (!function_exists('akw_status_label')) {
    function akw_status_label(string $status): string
    {
        $map = [
            'belum_bayar' => 'Belum Dibayar',
            'lunas'       => 'Lunas',
            'batal'       => 'Batal',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('akw_status_class')) {
    function akw_status_class(string $status): string
    {
        $map = [
            'belum_bayar' => 'border-amber-200 bg-amber-50 text-amber-700',
            'lunas'       => 'border-green-200 bg-green-50 text-green-700',
            'batal'       => 'border-red-200 bg-red-50 text-red-700',
        ];

        return $map[$status] ?? 'border-gray-200 bg-gray-50 text-gray-600';
    }
}

if (!function_exists('akw_terbilang')) {
    /**
     * @param int|float|string $number
     * @return string
     */
    function akw_terbilang($number): string
    {
        $number = (int)round((float)$number);
        $words = [
            '',
            'satu',
            'dua',
            'tiga',
            'empat',
            'lima',
            'enam',
            'tujuh',
            'delapan',
            'sembilan',
            'sepuluh',
            'sebelas'
        ];

        if ($number < 12) {
            return $words[$number];
        }
        if ($number < 20) {
            return akw_terbilang($number - 10) . ' belas';
        }
        if ($number < 100) {
            return trim(akw_terbilang((int)floor($number / 10)) . ' puluh ' . akw_terbilang($number % 10));
        }
        if ($number < 200) {
            return trim('seratus ' . akw_terbilang($number - 100));
        }
        if ($number < 1000) {
            return trim(akw_terbilang((int)floor($number / 100)) . ' ratus ' . akw_terbilang($number % 100));
        }
        if ($number < 2000) {
            return trim('seribu ' . akw_terbilang($number - 1000));
        }
        if ($number < 1000000) {
            return trim(akw_terbilang((int)floor($number / 1000)) . ' ribu ' . akw_terbilang($number % 1000));
        }
        if ($number < 1000000000) {
            return trim(akw_terbilang((int)floor($number / 1000000)) . ' juta ' . akw_terbilang($number % 1000000));
        }
        if ($number < 1000000000000) {
            return trim(akw_terbilang((int)floor($number / 1000000000)) . ' miliar ' . akw_terbilang($number % 1000000000));
        }
        return (string)$number;
    }
}

if (!function_exists('akw_column_exists')) {
    function akw_column_exists(PDO $pdo, string $table, string $column): bool
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

if (!function_exists('akw_ensure_schema')) {
    function akw_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_kwitansi (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nomor_kwitansi VARCHAR(60) NOT NULL,
                tanggal_kwitansi DATE NOT NULL,
                tanggal_awal DATE NULL,
                tanggal_akhir DATE NULL,
                nama_instansi VARCHAR(180) NOT NULL,
                alamat_instansi TEXT NULL,
                penerima_tagihan VARCHAR(150) NULL,
                status_pembayaran VARCHAR(30) NOT NULL DEFAULT 'belum_bayar',
                tanggal_bayar DATE NULL,
                total_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0,
                terbilang VARCHAR(255) NULL,
                catatan TEXT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_air_kwitansi_nomor (nomor_kwitansi),
                INDEX idx_air_kwitansi_tanggal (tanggal_kwitansi),
                INDEX idx_air_kwitansi_status (status_pembayaran)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_kwitansi_detail (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kwitansi_id INT NOT NULL,
                produk_id INT NULL,
                kode_produk VARCHAR(30) NULL,
                nama_produk VARCHAR(180) NOT NULL,
                qty INT NOT NULL DEFAULT 0,
                satuan VARCHAR(30) NOT NULL DEFAULT 'unit',
                harga_satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_air_kwitansi_detail_kwitansi (kwitansi_id),
                INDEX idx_air_kwitansi_detail_produk (produk_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS air_kwitansi_source (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kwitansi_id INT NOT NULL,
                pesanan_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_air_kwitansi_source (kwitansi_id, pesanan_id),
                INDEX idx_air_kwitansi_source_pesanan (pesanan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!akw_column_exists($pdo, 'air_produk', 'harga_tagihan')) {
            $pdo->exec("
                ALTER TABLE air_produk
                ADD COLUMN harga_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0
            ");
        }
    }
}

$flash = '';
$flashType = 'success';

try {
    akw_ensure_schema($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan database kwitansi: ' . $e->getMessage();
    $flashType = 'error';
}

$allowedPaymentStatuses = ['belum_bayar', 'lunas', 'batal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'error') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create') {
            $tanggalKwitansi = trim((string)($_POST['tanggal_kwitansi'] ?? date('Y-m-d')));
            $tanggalAwal = trim((string)($_POST['tanggal_awal'] ?? ''));
            $tanggalAkhir = trim((string)($_POST['tanggal_akhir'] ?? ''));
            $namaInstansi = trim((string)($_POST['nama_instansi'] ?? ''));
            $alamatInstansi = trim((string)($_POST['alamat_instansi'] ?? ''));
            $penerimaTagihan = trim((string)($_POST['penerima_tagihan'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $sourceIds = isset($_POST['source_ids']) && is_array($_POST['source_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['source_ids']))))
                : [];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalKwitansi)) {
                throw new RuntimeException('Tanggal kwitansi tidak valid.');
            }
            if ($tanggalAwal !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAwal)) {
                throw new RuntimeException('Tanggal awal periode tidak valid.');
            }
            if ($tanggalAkhir !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAkhir)) {
                throw new RuntimeException('Tanggal akhir periode tidak valid.');
            }
            if ($namaInstansi === '') {
                throw new RuntimeException('Nama instansi wajib diisi.');
            }
            if (!$sourceIds) {
                throw new RuntimeException('Pilih minimal satu pesanan yang sudah selesai.');
            }

            $pdo->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));

            $stmtEligible = $pdo->prepare("
                SELECT p.id
                FROM air_pesanan p
                WHERE p.id IN ($placeholders)
                  AND LOWER(TRIM(p.status)) = 'selesai'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM air_kwitansi_source s
                      JOIN air_kwitansi k ON k.id = s.kwitansi_id
                      WHERE s.pesanan_id = p.id
                        AND k.status_pembayaran <> 'batal'
                  )
                FOR UPDATE
            ");
            $stmtEligible->execute($sourceIds);
            $eligibleIds = array_map('intval', $stmtEligible->fetchAll(PDO::FETCH_COLUMN));

            if (!$eligibleIds) {
                throw new RuntimeException('Pesanan yang dipilih sudah ditagihkan atau belum berstatus selesai.');
            }

            $eligiblePlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));

            $stmtDetail = $pdo->prepare("
                SELECT
                    d.produk_id,
                    d.kode_produk,
                    d.nama_produk,
                    COALESCE(pr.satuan, 'unit') AS satuan,
                    COALESCE(pr.harga_tagihan, 0) AS harga_satuan,
                    SUM(d.qty) AS qty
                FROM air_pesanan_detail d
                LEFT JOIN air_produk pr ON pr.id = d.produk_id
                WHERE d.pesanan_id IN ($eligiblePlaceholders)
                GROUP BY
                    d.produk_id,
                    d.kode_produk,
                    d.nama_produk,
                    pr.satuan,
                    pr.harga_tagihan
                HAVING SUM(d.qty) > 0
                ORDER BY d.nama_produk ASC
            ");
            $stmtDetail->execute($eligibleIds);
            $detailRows = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

            if (!$detailRows) {
                throw new RuntimeException('Rincian produk tidak ditemukan.');
            }

            $postedPrices = isset($_POST['harga_satuan']) && is_array($_POST['harga_satuan'])
                ? $_POST['harga_satuan']
                : [];

            $totalTagihan = 0;
            foreach ($detailRows as &$detailRow) {
                $key = (string)($detailRow['produk_id'] ?? 0) . '|' . (string)($detailRow['kode_produk'] ?? '');
                $harga = isset($postedPrices[$key])
                    ? max(0, (float)$postedPrices[$key])
                    : max(0, (float)$detailRow['harga_satuan']);

                $qty = max(0, (int)$detailRow['qty']);
                $subtotal = $qty * $harga;

                $detailRow['harga_satuan'] = $harga;
                $detailRow['subtotal'] = $subtotal;
                $totalTagihan += $subtotal;
            }
            unset($detailRow);

            if ($totalTagihan <= 0) {
                throw new RuntimeException('Total tagihan masih nol. Lengkapi harga tagihan pada Master Produk.');
            }

            $nomorKwitansi = akw_generate_number();
            $terbilang = ucfirst(trim(akw_terbilang($totalTagihan))) . ' rupiah';

            $stmtKwitansi = $pdo->prepare("
                INSERT INTO air_kwitansi (
                    nomor_kwitansi,
                    tanggal_kwitansi,
                    tanggal_awal,
                    tanggal_akhir,
                    nama_instansi,
                    alamat_instansi,
                    penerima_tagihan,
                    status_pembayaran,
                    total_tagihan,
                    terbilang,
                    catatan,
                    created_by,
                    created_at
                ) VALUES (
                    :nomor_kwitansi,
                    :tanggal_kwitansi,
                    :tanggal_awal,
                    :tanggal_akhir,
                    :nama_instansi,
                    :alamat_instansi,
                    :penerima_tagihan,
                    'belum_bayar',
                    :total_tagihan,
                    :terbilang,
                    :catatan,
                    :created_by,
                    NOW()
                )
            ");
            $stmtKwitansi->execute([
                ':nomor_kwitansi'   => $nomorKwitansi,
                ':tanggal_kwitansi' => $tanggalKwitansi,
                ':tanggal_awal'     => $tanggalAwal !== '' ? $tanggalAwal : null,
                ':tanggal_akhir'    => $tanggalAkhir !== '' ? $tanggalAkhir : null,
                ':nama_instansi'    => $namaInstansi,
                ':alamat_instansi'  => $alamatInstansi,
                ':penerima_tagihan' => $penerimaTagihan,
                ':total_tagihan'    => $totalTagihan,
                ':terbilang'        => $terbilang,
                ':catatan'          => $catatan,
                ':created_by'       => akw_user_id() ?: null,
            ]);

            $kwitansiId = (int)$pdo->lastInsertId();

            $stmtInsertDetail = $pdo->prepare("
                INSERT INTO air_kwitansi_detail (
                    kwitansi_id,
                    produk_id,
                    kode_produk,
                    nama_produk,
                    qty,
                    satuan,
                    harga_satuan,
                    subtotal,
                    created_at
                ) VALUES (
                    :kwitansi_id,
                    :produk_id,
                    :kode_produk,
                    :nama_produk,
                    :qty,
                    :satuan,
                    :harga_satuan,
                    :subtotal,
                    NOW()
                )
            ");

            foreach ($detailRows as $detailRow) {
                $stmtInsertDetail->execute([
                    ':kwitansi_id'  => $kwitansiId,
                    ':produk_id'    => !empty($detailRow['produk_id']) ? (int)$detailRow['produk_id'] : null,
                    ':kode_produk'  => (string)($detailRow['kode_produk'] ?? ''),
                    ':nama_produk'  => (string)$detailRow['nama_produk'],
                    ':qty'          => (int)$detailRow['qty'],
                    ':satuan'       => (string)($detailRow['satuan'] ?: 'unit'),
                    ':harga_satuan' => (float)$detailRow['harga_satuan'],
                    ':subtotal'     => (float)$detailRow['subtotal'],
                ]);
            }

            $stmtSource = $pdo->prepare("
                INSERT IGNORE INTO air_kwitansi_source
                    (kwitansi_id, pesanan_id, created_at)
                VALUES
                    (:kwitansi_id, :pesanan_id, NOW())
            ");

            foreach ($eligibleIds as $pesananId) {
                $stmtSource->execute([
                    ':kwitansi_id' => $kwitansiId,
                    ':pesanan_id'  => $pesananId,
                ]);
            }

            $pdo->commit();

            $flash = 'Kwitansi ' . $nomorKwitansi . ' berhasil dibuat.';
        }

        if ($action === 'update_payment_status') {
            $kwitansiId = (int)($_POST['kwitansi_id'] ?? 0);
            $status = trim((string)($_POST['status_pembayaran'] ?? ''));
            $tanggalBayar = trim((string)($_POST['tanggal_bayar'] ?? ''));

            if ($kwitansiId <= 0 || !in_array($status, $allowedPaymentStatuses, true)) {
                throw new RuntimeException('Status pembayaran tidak valid.');
            }

            if ($status === 'lunas') {
                if ($tanggalBayar === '') {
                    $tanggalBayar = date('Y-m-d');
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalBayar)) {
                    throw new RuntimeException('Tanggal pembayaran tidak valid.');
                }
            } else {
                $tanggalBayar = '';
            }

            $stmt = $pdo->prepare("
                UPDATE air_kwitansi
                SET status_pembayaran = :status,
                    tanggal_bayar = :tanggal_bayar,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status'        => $status,
                ':tanggal_bayar' => $tanggalBayar !== '' ? $tanggalBayar : null,
                ':id'            => $kwitansiId,
            ]);

            $flash = 'Status pembayaran berhasil diperbarui.';
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
$periodStart = trim((string)($_GET['period_start'] ?? date('Y-m-01')));
$periodEnd = trim((string)($_GET['period_end'] ?? date('Y-m-t')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
    $periodStart = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    $periodEnd = date('Y-m-t');
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($statusFilter, $allowedPaymentStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$allowedLimits = [10, 15, 25, 50];
$perPage = (int)($_GET['limit'] ?? 15);

if (!in_array($perPage, $allowedLimits, true)) {
    $perPage = 15;
}

/*
|--------------------------------------------------------------------------
| Pesanan selesai yang belum ditagihkan
|--------------------------------------------------------------------------
*/

$availableOrders = [];
$billableOrders = [];
$availableRecap = [];
$availableOrderItems = [];
$availableTotal = 0;

try {
    /*
     * Tampilkan SEMUA pesanan yang benar-benar berstatus selesai.
     * LEFT JOIN dipakai agar data lama yang pelanggan_id-nya tidak lagi cocok
     * tetap terlihat dan tidak hilang diam-diam dari halaman kwitansi.
     *
     * Pesanan yang sudah pernah masuk kwitansi aktif tetap ditampilkan,
     * tetapi ditandai "Sudah Ditagihkan" dan tidak bisa dipilih lagi.
     */
    $stmtAvailable = $pdo->prepare("
        SELECT
            p.id,
            p.nomor_pesanan,
            p.tanggal_pemesanan,
            p.tanggal_kirim,
            p.created_at,
            COALESCE(NULLIF(TRIM(c.nama), ''), 'Pemesan #' , p.pelanggan_id) AS nama_pemesan,
            COALESCE(c.no_hp, '') AS no_hp,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT l.lokasi
                    ORDER BY l.urutan ASC, l.id ASC
                    SEPARATOR ', '
                )
                FROM air_pesanan_lokasi l
                WHERE l.pesanan_id = p.id
            ), '-') AS lokasi,
            COALESCE((
                SELECT SUM(d.qty)
                FROM air_pesanan_detail d
                WHERE d.pesanan_id = p.id
            ), 0) AS total_unit,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM air_kwitansi_source s
                    JOIN air_kwitansi k ON k.id = s.kwitansi_id
                    WHERE s.pesanan_id = p.id
                      AND COALESCE(k.status_pembayaran, 'belum_bayar') <> 'batal'
                ) THEN 1
                ELSE 0
            END AS sudah_ditagihkan
        FROM air_pesanan p
        LEFT JOIN air_pelanggan c ON c.id = p.pelanggan_id
        WHERE LOWER(TRIM(COALESCE(p.status, ''))) = 'selesai'
        ORDER BY COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, DATE(p.created_at)) DESC, p.id DESC
    ");
    $stmtAvailable->execute();
    $availableOrders = $stmtAvailable->fetchAll(PDO::FETCH_ASSOC);

    // Hanya yang belum ditagihkan yang dipakai untuk ringkasan dan pembuatan kwitansi baru.
    $billableOrders = array_values(array_filter($availableOrders, function ($row) {
        return (int)($row['sudah_ditagihkan'] ?? 0) === 0;
    }));

    $availableIds = array_map(function ($row) {
        return (int)$row['id'];
    }, $billableOrders);

    if ($availableIds) {
        $placeholders = implode(',', array_fill(0, count($availableIds), '?'));

        $stmtRecap = $pdo->prepare("
            SELECT
                d.produk_id,
                d.kode_produk,
                d.nama_produk,
                COALESCE(pr.satuan, 'unit') AS satuan,
                COALESCE(pr.harga_tagihan, 0) AS harga_satuan,
                SUM(d.qty) AS qty
            FROM air_pesanan_detail d
            LEFT JOIN air_produk pr ON pr.id = d.produk_id
            WHERE d.pesanan_id IN ($placeholders)
            GROUP BY
                d.produk_id,
                d.kode_produk,
                d.nama_produk,
                pr.satuan,
                pr.harga_tagihan
            ORDER BY d.nama_produk ASC
        ");
        $stmtRecap->execute($availableIds);
        $availableRecap = $stmtRecap->fetchAll(PDO::FETCH_ASSOC);

        foreach ($availableRecap as &$row) {
            $row['subtotal'] = (int)$row['qty'] * (float)$row['harga_satuan'];
            $availableTotal += (float)$row['subtotal'];
        }
        unset($row);

        $stmtItems = $pdo->prepare("
            SELECT
                d.pesanan_id,
                d.produk_id,
                d.kode_produk,
                d.nama_produk,
                COALESCE(pr.satuan, 'unit') AS satuan,
                COALESCE(pr.harga_tagihan, 0) AS harga_satuan,
                SUM(d.qty) AS qty
            FROM air_pesanan_detail d
            LEFT JOIN air_produk pr ON pr.id = d.produk_id
            WHERE d.pesanan_id IN ($placeholders)
            GROUP BY
                d.pesanan_id,
                d.produk_id,
                d.kode_produk,
                d.nama_produk,
                pr.satuan,
                pr.harga_tagihan
            ORDER BY d.pesanan_id ASC, d.nama_produk ASC
        ");
        $stmtItems->execute($availableIds);

        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $orderId = (int)$item['pesanan_id'];

            if (!isset($availableOrderItems[$orderId])) {
                $availableOrderItems[$orderId] = [];
            }

            $availableOrderItems[$orderId][] = [
                'key'          => (string)($item['produk_id'] ?? 0) . '|' . (string)($item['kode_produk'] ?? ''),
                'produk_id'    => (int)($item['produk_id'] ?? 0),
                'kode_produk'  => (string)($item['kode_produk'] ?? ''),
                'nama_produk'  => (string)$item['nama_produk'],
                'satuan'       => (string)($item['satuan'] ?: 'unit'),
                'harga_satuan' => (float)$item['harga_satuan'],
                'qty'          => (int)$item['qty'],
            ];
        }
    }
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat pesanan yang siap ditagihkan: ' . $e->getMessage();
        $flashType = 'error';
    }
}

if (!isset($billableOrders) || !is_array($billableOrders)) {
    $billableOrders = [];
}

/*
|--------------------------------------------------------------------------
| Daftar kwitansi
|--------------------------------------------------------------------------
*/

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = "(
        k.nomor_kwitansi LIKE :q
        OR k.nama_instansi LIKE :q
        OR k.penerima_tagihan LIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
}

if ($statusFilter !== '') {
    $where[] = 'k.status_pembayaran = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM air_kwitansi k WHERE $whereSql");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmtKwitansi = $pdo->prepare("
    SELECT
        k.*,
        COALESCE((
            SELECT COUNT(*)
            FROM air_kwitansi_source s
            WHERE s.kwitansi_id = k.id
        ), 0) AS source_count,
        COALESCE((
            SELECT COUNT(*)
            FROM air_kwitansi_detail d
            WHERE d.kwitansi_id = k.id
        ), 0) AS product_count
    FROM air_kwitansi k
    WHERE $whereSql
    ORDER BY k.created_at DESC, k.id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmtKwitansi->bindValue($key, $value, PDO::PARAM_STR);
}
$stmtKwitansi->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtKwitansi->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtKwitansi->execute();
$kwitansiRows = $stmtKwitansi->fetchAll(PDO::FETCH_ASSOC);

$stmtDetailView = $pdo->prepare("
    SELECT *
    FROM air_kwitansi_detail
    WHERE kwitansi_id = :id
    ORDER BY nama_produk ASC, id ASC
");

$stmtSourceView = $pdo->prepare("
    SELECT
        p.nomor_pesanan,
        c.nama AS nama_pemesan,
        c.no_hp,
        COALESCE((
            SELECT GROUP_CONCAT(
                DISTINCT l.lokasi
                ORDER BY l.urutan ASC, l.id ASC
                SEPARATOR ', '
            )
            FROM air_pesanan_lokasi l
            WHERE l.pesanan_id = p.id
        ), '-') AS lokasi
    FROM air_kwitansi_source s
    JOIN air_pesanan p ON p.id = s.pesanan_id
    JOIN air_pelanggan c ON c.id = p.pelanggan_id
    WHERE s.kwitansi_id = :id
    ORDER BY p.id ASC
");

foreach ($kwitansiRows as &$kwitansi) {
    $stmtDetailView->execute([':id' => (int)$kwitansi['id']]);
    $kwitansi['details'] = $stmtDetailView->fetchAll(PDO::FETCH_ASSOC);

    $stmtSourceView->execute([':id' => (int)$kwitansi['id']]);
    $kwitansi['sources'] = $stmtSourceView->fetchAll(PDO::FETCH_ASSOC);
}
unset($kwitansi);

/*
|--------------------------------------------------------------------------
| Ringkasan
|--------------------------------------------------------------------------
*/

$summary = [
    'total'        => 0,
    'belum_bayar' => 0,
    'lunas'        => 0,
    'batal'        => 0,
    'nilai_tagihan' => 0,
    'nilai_lunas'  => 0,
];

try {
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status_pembayaran = 'belum_bayar' THEN 1 ELSE 0 END) AS belum_bayar,
            SUM(CASE WHEN status_pembayaran = 'lunas' THEN 1 ELSE 0 END) AS lunas,
            SUM(CASE WHEN status_pembayaran = 'batal' THEN 1 ELSE 0 END) AS batal,
            COALESCE(SUM(CASE WHEN status_pembayaran <> 'batal' THEN total_tagihan ELSE 0 END), 0) AS nilai_tagihan,
            COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_tagihan ELSE 0 END), 0) AS nilai_lunas
        FROM air_kwitansi
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($summary as $key => $value) {
        if (array_key_exists($key, $summaryRow)) {
            $summary[$key] = (float)$summaryRow[$key];
        }
    }
} catch (Throwable $e) {
}

if (!function_exists('akw_page_url')) {
    function akw_page_url(array $query, int $targetPage): string
    {
        $query['page'] = max(1, $targetPage);
        return 'air_kwitansi.php?' . http_build_query($query);
    }
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
    <title>Kwitansi Penagihan Air Mineral</title>

    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
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
            border: 1px solid #f0f0f0;
            background: #f9fafb;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            outline: none;
            border-radius: 0 !important;
        }

        textarea.field {
            min-height: 94px;
            padding-top: 11px;
            padding-bottom: 11px;
        }

        .field:focus {
            background: #fff;
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
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
            border: 1px solid;
            padding: 5px 8px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
        }

        .status-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: currentColor;
        }

        .filter-search {
            position: relative;
            min-width: 0;
        }

        .filter-search>svg,
        .filter-search>i {
            position: absolute;
            left: 14px;
            top: 50%;
            z-index: 2;
            width: 16px;
            height: 16px;
            color: #9ca3af;
            pointer-events: none;
            transform: translateY(-50%);
        }

        .filter-search .field {
            padding-left: 44px !important;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #9ca3af;
        }

        .modal-wrap {
            padding: 20px;
            backdrop-filter: blur(2px);
        }

        .modal-panel {
            width: min(100%, 1120px);
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
            border: 1px solid #e5e7eb;
            background: #fff;
            overflow: hidden;
        }

        .modal-panel-sm {
            width: min(100%, 520px);
        }

        .modal-header {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 24px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }

        .modal-close {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #6b7280;
        }

        .modal-close:hover {
            background: #f9fafb;
            color: #111827;
        }

        .modal-body {
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 24px;
        }

        .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 10;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid #f0f0f0;
            background: #f9fafb;
        }

        .modal-section {
            border: 1px solid #eef0f3;
            background: #fff;
            padding: 16px;
        }

        .modal-section-title {
            margin-bottom: 12px;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #6b7280;
        }

        .modal-table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #f0f0f0;
            background: #fff;
        }

        .mobile-action-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .print-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            color: #111;
            padding: 18mm;
            font-family: Arial, sans-serif;
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

            .modal-wrap {
                padding: 14px;
            }

            .modal-panel {
                max-height: calc(100vh - 28px);
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding-left: 18px;
                padding-right: 18px;
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

            .filter-grid,
            .mobile-grid {
                grid-template-columns: 1fr !important;
            }

            .filter-search {
                grid-column: auto;
            }

            .modal-wrap {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            .modal-panel,
            .modal-panel-sm {
                width: 100% !important;
                max-width: none !important;
                height: 100dvh;
                max-height: 100dvh;
                border-left: 0;
                border-right: 0;
                border-bottom: 0;
            }

            .modal-header {
                padding: 14px 16px;
            }

            .modal-header h2 {
                font-size: 14px;
                line-height: 1.35;
                word-break: break-word;
            }

            .modal-body {
                padding: 16px !important;
                max-height: none !important;
                flex: 1 1 auto;
            }

            .modal-footer {
                grid-template-columns: 1fr;
                padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
            }

            .modal-close {
                width: 38px;
                height: 38px;
            }

            .mobile-action-grid {
                grid-template-columns: 1fr;
            }

            .print-controls {
                flex-direction: column;
                align-items: stretch !important;
            }

            .print-controls>div {
                display: grid !important;
                grid-template-columns: 1fr;
            }

            .print-sheet {
                width: 100% !important;
                min-height: auto !important;
                padding: 16px !important;
            }
        }

        @media print {
            body * {
                visibility: hidden !important;
            }

            #printModal,
            #printModal * {
                visibility: visible !important;
            }

            #printModal {
                position: absolute !important;
                inset: 0 !important;
                display: block !important;
                background: #fff !important;
                padding: 0 !important;
            }

            #printModal .print-controls {
                display: none !important;
            }

            #printModal .modal-panel {
                width: auto !important;
                max-width: none !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                box-shadow: none !important;
            }

            .print-sheet {
                width: 210mm !important;
                min-height: 297mm !important;
                padding: 16mm !important;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen">
    <main class="air-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold <?php echo $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                <?php echo akw_h($flash); ?>
            </div>
        <?php endif; ?>

        <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6 md:mb-8">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-blue-600">Modul Air Mineral</p>
                <h1 class="text-xl md:text-2xl font-light tracking-tight mt-1">
                    Kwitansi <span class="font-semibold">Penagihan Kantor</span>
                </h1>
                <p class="text-xs text-gray-400 mt-1">
                    Buat tagihan berdasarkan pesanan selesai dan harga dari Master Produk.
                </p>
            </div>

            <button type="button"
                onclick="openCreateModal()"
                class="btn bg-black text-white">
                <i data-lucide="plus" class="w-4 h-4"></i>
                Buat Kwitansi
            </button>
        </header>

        <div class="summary-grid grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4 mb-6">
            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Kwitansi</p>
                <p class="text-2xl font-black mt-2"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[9px] text-gray-400">Semua dokumen</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Belum Dibayar</p>
                <p class="text-2xl font-black text-amber-600 mt-2"><?php echo number_format($summary['belum_bayar']); ?></p>
                <p class="text-[9px] text-gray-400">Masih tertagih</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Lunas</p>
                <p class="text-2xl font-black text-green-600 mt-2"><?php echo number_format($summary['lunas']); ?></p>
                <p class="text-[9px] text-gray-400">Sudah dibayar</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-red-600">Batal</p>
                <p class="text-2xl font-black text-red-600 mt-2"><?php echo number_format($summary['batal']); ?></p>
                <p class="text-[9px] text-gray-400">Dokumen dibatalkan</p>
            </div>

            <div class="summary-card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Nilai Tagihan</p>
                <p class="text-lg font-black text-blue-600 mt-2"><?php echo akw_rupiah($summary['nilai_tagihan']); ?></p>
                <p class="text-[9px] text-gray-400">Tidak termasuk batal</p>
            </div>

            <div class="summary-card p-4 col-span-2 md:col-span-1">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Nilai Lunas</p>
                <p class="text-lg font-black text-green-600 mt-2"><?php echo akw_rupiah($summary['nilai_lunas']); ?></p>
                <p class="text-[9px] text-gray-400">Sudah diterima</p>
            </div>
        </div>

        <section class="card p-4 md:p-5 mb-6">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Pesanan Siap Ditagihkan</p>
                    <p class="text-xl font-black mt-1"><?php echo number_format(count($billableOrders)); ?></p>
                    <p class="text-xs text-gray-400 mt-1">
                        <?php echo number_format(count($availableOrders)); ?> pesanan berstatus selesai ditemukan ·
                        <?php echo number_format(count($billableOrders)); ?> belum ditagihkan.
                    </p>
                </div>

                <div class="border border-gray-100 bg-gray-50 px-4 py-3 text-[10px] font-bold text-gray-500">
                    Syarat: status pesanan <strong>Selesai</strong> dan belum ditagihkan.
                </div>
            </div>

            <?php if ($availableRecap): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-2 mt-4">
                    <?php foreach ($availableRecap as $item): ?>
                        <div class="border border-[#f0f0f0] bg-gray-50 p-3">
                            <p class="text-xs font-black"><?php echo akw_h($item['nama_produk']); ?></p>
                            <p class="text-[9px] text-gray-400 mt-1">
                                <?php echo number_format((int)$item['qty']); ?> <?php echo akw_h($item['satuan']); ?>
                                × <?php echo akw_rupiah($item['harga_satuan']); ?>
                            </p>
                            <p class="text-sm font-black text-blue-600 mt-2"><?php echo akw_rupiah($item['subtotal']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4 border border-blue-100 bg-blue-50 p-4 flex items-center justify-between gap-3">
                    <p class="text-[9px] font-black uppercase tracking-widest text-blue-700">Estimasi Total</p>
                    <p class="text-xl font-black text-blue-700"><?php echo akw_rupiah($availableTotal); ?></p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($availableOrders && !$billableOrders): ?>
            <div class="mb-4 border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-xs font-black text-amber-800">Pesanan selesai ditemukan, tetapi semuanya sudah terhubung ke kwitansi aktif.</p>
                <p class="text-[10px] text-amber-700 mt-1">
                    Buka daftar kwitansi di bawah. Jika kwitansi lama seharusnya dibatalkan, ubah statusnya menjadi Batal agar pesanan dapat ditagihkan kembali.
                </p>
            </div>
        <?php endif; ?>

        <form method="get" class="filter-card p-4 mb-4">
            <input type="hidden" name="period_start" value="<?php echo akw_h($periodStart); ?>">
            <input type="hidden" name="period_end" value="<?php echo akw_h($periodEnd); ?>">

            <div class="filter-grid grid grid-cols-1 md:grid-cols-2 xl:grid-cols-[minmax(280px,1fr)_190px_130px_auto_auto] gap-3 items-center">
                <div class="filter-search">
                    <i data-lucide="search"></i>
                    <input type="search"
                        name="q"
                        value="<?php echo akw_h($q); ?>"
                        class="field"
                        placeholder="Cari nomor kwitansi atau instansi">
                </div>

                <select name="status" class="field">
                    <option value="">Semua Status</option>
                    <?php foreach ($allowedPaymentStatuses as $status): ?>
                        <option value="<?php echo akw_h($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                            <?php echo akw_h(akw_status_label($status)); ?>
                        </option>
                    <?php endforeach; ?>
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

                <a href="air_kwitansi.php" class="btn border border-gray-200 bg-white text-gray-700">Reset</a>
            </div>

            <p class="text-xs text-gray-400 mt-3 text-right">
                <?php echo number_format($totalRows); ?> kwitansi ditemukan
            </p>
        </form>

        <div class="table-card overflow-hidden">
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full text-left min-w-[1040px]">
                    <thead class="border-b border-[#f0f0f0] bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400">Kwitansi</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400">Instansi</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Periode</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Pesanan</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Total</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-[#f5f5f5]">
                        <?php if (!$kwitansiRows): ?>
                            <tr>
                                <td colspan="7" class="py-20 text-center text-xs font-black uppercase tracking-widest text-gray-300">
                                    Belum ada kwitansi
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($kwitansiRows as $row): ?>
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-black"><?php echo akw_h($row['nomor_kwitansi']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-1">
                                            <?php echo akw_h(date('d/m/Y', strtotime($row['tanggal_kwitansi']))); ?>
                                        </p>
                                    </td>

                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold"><?php echo akw_h($row['nama_instansi']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-1"><?php echo akw_h($row['penerima_tagihan'] ?: '-'); ?></p>
                                    </td>

                                    <td class="px-5 py-4 text-center text-xs font-bold">
                                        <?php echo $row['tanggal_awal'] ? akw_h(date('d/m/Y', strtotime($row['tanggal_awal']))) : '-'; ?>
                                        <br>
                                        <span class="text-gray-400">s.d.</span>
                                        <br>
                                        <?php echo $row['tanggal_akhir'] ? akw_h(date('d/m/Y', strtotime($row['tanggal_akhir']))) : '-'; ?>
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <p class="text-sm font-black"><?php echo number_format((int)$row['source_count']); ?></p>
                                        <p class="text-[9px] text-gray-400"><?php echo number_format((int)$row['product_count']); ?> produk</p>
                                    </td>

                                    <td class="px-5 py-4 text-right text-sm font-black text-blue-600">
                                        <?php echo akw_rupiah($row['total_tagihan']); ?>
                                    </td>

                                    <td class="px-5 py-4 text-center">
                                        <span class="status-badge <?php echo akw_status_class((string)$row['status_pembayaran']); ?>">
                                            <?php echo akw_h(akw_status_label((string)$row['status_pembayaran'])); ?>
                                        </span>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <button type="button"
                                                onclick='openDetail(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="action-btn hover:text-blue-600 hover:bg-blue-50"
                                                title="Detail">
                                                <i data-lucide="eye" class="w-4 h-4"></i>
                                            </button>

                                            <button type="button"
                                                onclick='openPrint(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="action-btn hover:text-purple-600 hover:bg-purple-50"
                                                title="Cetak">
                                                <i data-lucide="printer" class="w-4 h-4"></i>
                                            </button>

                                            <button type="button"
                                                onclick='openPayment(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                class="action-btn hover:text-green-600 hover:bg-green-50"
                                                title="Pembayaran">
                                                <i data-lucide="badge-check" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="lg:hidden">
                <?php if (!$kwitansiRows): ?>
                    <div class="py-16 text-center text-xs font-black uppercase tracking-widest text-gray-300">
                        Belum ada kwitansi
                    </div>
                <?php else: ?>
                    <div class="mobile-grid grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($kwitansiRows as $row): ?>
                            <article class="mobile-card p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-black break-all"><?php echo akw_h($row['nomor_kwitansi']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo akw_h($row['nama_instansi']); ?></p>
                                    </div>

                                    <span class="status-badge <?php echo akw_status_class((string)$row['status_pembayaran']); ?>">
                                        <?php echo akw_h(akw_status_label((string)$row['status_pembayaran'])); ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-2 mt-4">
                                    <div class="border border-[#f0f0f0] bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Total Tagihan</p>
                                        <p class="text-sm font-black text-blue-600 mt-1"><?php echo akw_rupiah($row['total_tagihan']); ?></p>
                                    </div>

                                    <div class="border border-[#f0f0f0] bg-gray-50 p-3">
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Pesanan</p>
                                        <p class="text-lg font-black mt-1"><?php echo number_format((int)$row['source_count']); ?></p>
                                    </div>
                                </div>

                                <div class="mt-2 border border-[#f0f0f0] p-3">
                                    <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Periode</p>
                                    <p class="text-xs font-bold mt-1">
                                        <?php echo $row['tanggal_awal'] ? akw_h(date('d/m/Y', strtotime($row['tanggal_awal']))) : '-'; ?>
                                        —
                                        <?php echo $row['tanggal_akhir'] ? akw_h(date('d/m/Y', strtotime($row['tanggal_akhir']))) : '-'; ?>
                                    </p>
                                </div>

                                <div class="mobile-action-grid mt-3 pt-3 border-t border-[#f0f0f0]">
                                    <button type="button"
                                        onclick='openDetail(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="btn border border-blue-100 text-blue-700">
                                        Detail
                                    </button>

                                    <button type="button"
                                        onclick='openPrint(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="btn border border-purple-100 text-purple-700">
                                        Cetak
                                    </button>

                                    <button type="button"
                                        onclick='openPayment(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="btn border border-green-100 text-green-700">
                                        Bayar
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="px-4 md:px-5 py-4 border-t border-[#f0f0f0] flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50">
                <span class="text-xs text-gray-400">
                    Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
                    (<?php echo number_format($totalRows); ?> total · <?php echo $perPage; ?>/hal)
                </span>

                <div class="flex flex-wrap gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo akw_h(akw_page_url($baseQuery, $page - 1)); ?>"
                            class="px-3 py-2 text-xs font-bold border border-[#f0f0f0] bg-white">
                            &larr; Prev
                        </a>
                    <?php endif; ?>

                    <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                        <a href="<?php echo akw_h(akw_page_url($baseQuery, $pg)); ?>"
                            class="px-3 py-2 text-xs font-bold <?php echo $pg === $page ? 'bg-black text-white' : 'border border-[#f0f0f0] bg-white'; ?>">
                            <?php echo $pg; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo akw_h(akw_page_url($baseQuery, $page + 1)); ?>"
                            class="px-3 py-2 text-xs font-bold border border-[#f0f0f0] bg-white">
                            Next &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal buat kwitansi -->
    <div id="createModal" class="modal-wrap fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel">
            <div class="modal-header">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Kwitansi Air Mineral</p>
                    <h2 class="text-base font-black mt-1">Buat Kwitansi Penagihan</h2>
                </div>

                <button type="button" onclick="closeModal('createModal')" class="modal-close">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form method="post" onsubmit="return validateCreateForm()">
                <input type="hidden" name="action" value="create">

                <div class="modal-body space-y-5">
                    <div class="modal-section">
                        <p class="modal-section-title">Informasi Kwitansi</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Tanggal Kwitansi *</label>
                                <input type="date" name="tanggal_kwitansi" value="<?php echo akw_h($today); ?>" required class="field">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Periode Awal</label>
                                <input type="date" name="tanggal_awal" value="<?php echo akw_h($periodStart); ?>" class="field">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Periode Akhir</label>
                                <input type="date" name="tanggal_akhir" value="<?php echo akw_h($periodEnd); ?>" class="field">
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <p class="modal-section-title">Data Instansi</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Nama Instansi *</label>
                                <input type="text" name="nama_instansi" required class="field" placeholder="Nama kantor atau instansi">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Penerima Tagihan</label>
                                <input type="text" name="penerima_tagihan" class="field" placeholder="Nama bagian atau pejabat penerima">
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Alamat Instansi</label>
                            <textarea name="alamat_instansi" class="field" placeholder="Alamat lengkap instansi"></textarea>
                        </div>
                    </div>

                    <div class="modal-section">
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-gray-500">Pesanan Selesai</label>
                            <label class="text-xs font-bold cursor-pointer">
                                <input type="checkbox" id="checkAllSources" checked onchange="toggleAllSources(this.checked)" class="accent-black">
                                Pilih Semua
                            </label>
                        </div>

                        <div class="border border-[#f0f0f0] divide-y divide-[#f5f5f5] max-h-60 overflow-y-auto">
                            <?php if (!$availableOrders): ?>
                                <div class="p-5 text-center bg-gray-50">
                                    <p class="text-xs font-black text-gray-600">Belum ada pesanan selesai yang bisa ditagihkan.</p>
                                    <p class="text-[10px] text-gray-400 mt-2">
                                        Pesanan harus berstatus Selesai dan belum pernah masuk kwitansi yang tidak dibatalkan.
                                    </p>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($availableOrders as $source): ?>
                                <?php $alreadyBilled = (int)($source['sudah_ditagihkan'] ?? 0) === 1; ?>
                                <label class="flex items-center gap-3 p-3 <?php echo $alreadyBilled ? 'bg-gray-50 opacity-70 cursor-not-allowed' : 'hover:bg-gray-50 cursor-pointer'; ?>">
                                    <input type="checkbox"
                                        name="source_ids[]"
                                        value="<?php echo (int)$source['id']; ?>"
                                        <?php echo $alreadyBilled ? 'disabled' : 'checked'; ?>
                                        class="source-check accent-black"
                                        data-order-id="<?php echo (int)$source['id']; ?>"
                                        onchange="updateCreatePreview()">

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                            <p class="text-xs font-black">
                                                <?php echo akw_h($source['nomor_pesanan']); ?> · <?php echo akw_h($source['nama_pemesan']); ?>
                                            </p>
                                            <?php if ($alreadyBilled): ?>
                                                <span class="inline-flex self-start border border-gray-200 bg-white px-2 py-1 text-[8px] font-black uppercase tracking-widest text-gray-500">
                                                    Sudah Ditagihkan
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex self-start border border-green-200 bg-green-50 px-2 py-1 text-[8px] font-black uppercase tracking-widest text-green-700">
                                                    Siap Ditagihkan
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[9px] text-gray-400 mt-1">
                                            <?php echo akw_h($source['no_hp'] ?: '-'); ?>
                                            · <?php echo akw_h($source['lokasi'] ?: '-'); ?>
                                            · <?php echo number_format((int)$source['total_unit']); ?> unit
                                        </p>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="modal-section border-blue-100 bg-blue-50">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div class="border border-blue-100 bg-white p-3">
                                <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Pesanan</p>
                                <p id="previewOrderCount" class="text-xl font-black mt-1">0</p>
                            </div>
                            <div class="border border-blue-100 bg-white p-3">
                                <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Jenis Produk</p>
                                <p id="previewProductCount" class="text-xl font-black mt-1">0</p>
                            </div>
                            <div class="border border-blue-100 bg-white p-3">
                                <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Total Unit</p>
                                <p id="previewUnitCount" class="text-xl font-black mt-1">0</p>
                            </div>
                            <div class="border border-blue-100 bg-white p-3">
                                <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Total Tagihan</p>
                                <p id="previewGrandTotal" class="text-lg font-black text-blue-700 mt-1">Rp 0</p>
                            </div>
                        </div>

                        <div id="previewProductList" class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-3"></div>
                    </div>

                    <div class="modal-section">
                        <p class="modal-section-title">Rincian Harga Tagihan</p>

                        <div class="modal-table-wrap">
                            <table class="w-full min-w-[720px]">
                                <thead class="bg-gray-50 border-b border-[#f0f0f0]">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-[9px] font-black uppercase text-gray-400">Produk</th>
                                        <th class="px-4 py-3 text-center text-[9px] font-black uppercase text-gray-400">Jumlah</th>
                                        <th class="px-4 py-3 text-center text-[9px] font-black uppercase text-gray-400">Harga Satuan</th>
                                        <th class="px-4 py-3 text-right text-[9px] font-black uppercase text-gray-400">Subtotal</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php foreach ($availableRecap as $item): ?>
                                        <?php $key = (string)($item['produk_id'] ?? 0) . '|' . (string)($item['kode_produk'] ?? ''); ?>
                                        <tr>
                                            <td class="px-4 py-3">
                                                <p class="text-xs font-black"><?php echo akw_h($item['nama_produk']); ?></p>
                                                <p class="text-[9px] text-gray-400"><?php echo akw_h($item['satuan']); ?></p>
                                            </td>
                                            <td class="px-4 py-3 text-center text-sm font-black">
                                                <?php echo number_format((int)$item['qty']); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <input type="number"
                                                    name="harga_satuan[<?php echo akw_h($key); ?>]"
                                                    min="0"
                                                    value="<?php echo (float)$item['harga_satuan']; ?>"
                                                    class="field text-center price-input"
                                                    data-product-key="<?php echo akw_h($key); ?>"
                                                    oninput="updateCreatePreview()">
                                            </td>
                                            <td class="px-4 py-3 text-right text-sm font-black text-blue-600">
                                                <?php echo akw_rupiah($item['subtotal']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="modal-section">
                        <p class="modal-section-title">Catatan</p>
                        <textarea name="catatan" class="field" placeholder="Catatan tambahan pada kwitansi"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                        onclick="closeModal('createModal')"
                        class="flex-1 py-3 text-xs font-bold uppercase border border-[#f0f0f0] bg-white">
                        Batal
                    </button>

                    <button type="submit"
                        id="btnSaveKwitansi"
                        class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white disabled:opacity-40 disabled:cursor-not-allowed"
                        <?php echo !$billableOrders ? 'disabled' : ''; ?>>
                        Simpan Kwitansi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal detail -->
    <div id="detailModal" class="modal-wrap fixed inset-0 z-[110] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel">
            <div class="modal-header">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Detail Kwitansi</p>
                    <h2 id="detailTitle" class="text-base font-black mt-1">-</h2>
                </div>

                <button type="button" onclick="closeModal('detailModal')" class="modal-close">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <div id="detailBody" class="modal-body"></div>
        </div>
    </div>

    <!-- Modal pembayaran -->
    <div id="paymentModal" class="modal-wrap fixed inset-0 z-[120] hidden items-center justify-center bg-black/40 p-4">
        <div class="modal-panel modal-panel-sm">
            <div class="modal-header">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Status Pembayaran</p>
                    <h2 id="paymentTitle" class="text-base font-black mt-1">-</h2>
                </div>

                <button type="button" onclick="closeModal('paymentModal')" class="modal-close">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="update_payment_status">
                <input type="hidden" name="kwitansi_id" id="paymentId">

                <div class="modal-body space-y-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Status</label>
                        <select name="status_pembayaran" id="paymentStatus" class="field" onchange="togglePaymentDate()">
                            <option value="belum_bayar">Belum Dibayar</option>
                            <option value="lunas">Lunas</option>
                            <option value="batal">Batal</option>
                        </select>
                    </div>

                    <div id="paymentDateWrap">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-gray-500 mb-2">Tanggal Bayar</label>
                        <input type="date" name="tanggal_bayar" id="paymentDate" class="field">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                        onclick="closeModal('paymentModal')"
                        class="flex-1 py-3 text-xs font-bold uppercase border border-[#f0f0f0] bg-white">
                        Batal
                    </button>

                    <button type="submit" class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white">
                        Simpan Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal print -->
    <div id="printModal" class="modal-wrap fixed inset-0 z-[130] hidden items-start justify-center bg-black/50 p-4 overflow-y-auto">
        <div class="modal-panel">
            <div class="print-controls modal-header">
                <p class="text-xs font-black uppercase tracking-widest">Preview Kwitansi</p>

                <div class="flex gap-2">
                    <button type="button" onclick="window.print()" class="btn bg-black text-white">
                        <i data-lucide="printer" class="w-4 h-4"></i>
                        Cetak / Simpan PDF
                    </button>

                    <button type="button" onclick="closeModal('printModal')" class="btn border border-gray-200 bg-white">
                        Tutup
                    </button>
                </div>
            </div>

            <div id="printBody"></div>
        </div>
    </div>

    <script>
        var availableOrderItems = <?php echo json_encode($availableOrderItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var currentUserName = <?php echo json_encode(akw_user_name(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatRupiah(value) {
            return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
        }

        function formatDate(value) {
            if (!value) return '-';
            var parts = String(value).substring(0, 10).split('-');
            return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
        }

        function openModal(id) {
            var modal = document.getElementById(id);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            var modal = document.getElementById(id);
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        function openCreateModal() {
            updateCreatePreview();
            openModal('createModal');
        }

        function toggleAllSources(checked) {
            document.querySelectorAll('.source-check:not(:disabled)').forEach(function(input) {
                input.checked = checked;
            });
            updateCreatePreview();
        }

        function getCurrentPrices() {
            var prices = {};

            document.querySelectorAll('.price-input').forEach(function(input) {
                prices[String(input.getAttribute('data-product-key') || '')] = Number(input.value || 0);
            });

            return prices;
        }

        function updateCreatePreview() {
            var checked = Array.prototype.slice.call(
                document.querySelectorAll('.source-check:checked')
            );

            var products = {};
            var totalUnits = 0;
            var grandTotal = 0;
            var prices = getCurrentPrices();

            checked.forEach(function(input) {
                var orderId = String(input.getAttribute('data-order-id') || input.value || '');
                var items = Array.isArray(availableOrderItems[orderId]) ?
                    availableOrderItems[orderId] : [];

                items.forEach(function(item) {
                    var key = String(item.key || '');

                    if (!products[key]) {
                        products[key] = {
                            nama_produk: item.nama_produk || '-',
                            satuan: item.satuan || 'unit',
                            qty: 0,
                            harga: prices.hasOwnProperty(key) ?
                                Number(prices[key] || 0) : Number(item.harga_satuan || 0)
                        };
                    }

                    products[key].qty += Number(item.qty || 0);
                    totalUnits += Number(item.qty || 0);
                });
            });

            Object.keys(products).forEach(function(key) {
                grandTotal += products[key].qty * products[key].harga;
            });

            document.getElementById('previewOrderCount').textContent =
                checked.length.toLocaleString('id-ID');
            document.getElementById('previewProductCount').textContent =
                Object.keys(products).length.toLocaleString('id-ID');
            document.getElementById('previewUnitCount').textContent =
                totalUnits.toLocaleString('id-ID');
            document.getElementById('previewGrandTotal').textContent =
                formatRupiah(grandTotal);

            var list = document.getElementById('previewProductList');

            if (!Object.keys(products).length) {
                list.innerHTML =
                    '<div class="md:col-span-2 border border-dashed border-blue-200 bg-white p-4 text-center text-xs text-gray-400">' +
                    'Pilih minimal satu pesanan selesai.' +
                    '</div>';
            } else {
                list.innerHTML = Object.keys(products).map(function(key) {
                    var product = products[key];
                    var subtotal = product.qty * product.harga;

                    return '' +
                        '<div class="border border-blue-100 bg-white p-3">' +
                        '<div class="flex items-start justify-between gap-3">' +
                        '<div class="min-w-0">' +
                        '<p class="text-xs font-black">' + escapeHtml(product.nama_produk) + '</p>' +
                        '<p class="text-[9px] text-gray-400 mt-1">' +
                        Number(product.qty).toLocaleString('id-ID') + ' ' +
                        escapeHtml(product.satuan) + ' × ' +
                        formatRupiah(product.harga) +
                        '</p>' +
                        '</div>' +
                        '<p class="text-sm font-black text-blue-700 shrink-0">' +
                        formatRupiah(subtotal) +
                        '</p>' +
                        '</div>' +
                        '</div>';
                }).join('');
            }

            var saveButton = document.getElementById('btnSaveKwitansi');
            saveButton.disabled = checked.length === 0 || grandTotal <= 0;
        }

        function validateCreateForm() {
            var selected = document.querySelectorAll('.source-check:checked').length;
            var totalText = document.getElementById('previewGrandTotal').textContent || '';
            var totalValue = Number(totalText.replace(/[^0-9]/g, '') || 0);

            if (selected === 0) {
                alert('Pilih minimal satu pesanan selesai.');
                return false;
            }

            if (totalValue <= 0) {
                alert('Total tagihan masih nol. Lengkapi harga tagihan.');
                return false;
            }

            return confirm('Simpan kwitansi penagihan ini?');
        }

        function openDetail(row) {
            document.getElementById('detailTitle').textContent = row.nomor_kwitansi || '-';

            var details = Array.isArray(row.details) ? row.details : [];
            var sources = Array.isArray(row.sources) ? row.sources : [];

            var detailRows = details.map(function(item) {
                return '' +
                    '<tr>' +
                    '<td class="px-4 py-3">' +
                    '<p class="text-xs font-black">' + escapeHtml(item.nama_produk) + '</p>' +
                    '<p class="text-[9px] text-gray-400">' + escapeHtml(item.satuan) + '</p>' +
                    '</td>' +
                    '<td class="px-4 py-3 text-center text-sm font-black">' +
                    Number(item.qty || 0).toLocaleString('id-ID') +
                    '</td>' +
                    '<td class="px-4 py-3 text-right text-sm font-bold">' +
                    formatRupiah(item.harga_satuan) +
                    '</td>' +
                    '<td class="px-4 py-3 text-right text-sm font-black text-blue-600">' +
                    formatRupiah(item.subtotal) +
                    '</td>' +
                    '</tr>';
            }).join('');

            var sourceCards = sources.map(function(source) {
                return '' +
                    '<div class="border border-gray-200 bg-white p-3">' +
                    '<p class="text-xs font-black">' + escapeHtml(source.nomor_pesanan) + '</p>' +
                    '<p class="text-[10px] text-gray-500 mt-1">' +
                    escapeHtml(source.nama_pemesan) + ' · ' + escapeHtml(source.no_hp || '-') +
                    '</p>' +
                    '<p class="text-[10px] font-bold text-gray-700 mt-2">' +
                    escapeHtml(source.lokasi || '-') +
                    '</p>' +
                    '</div>';
            }).join('');

            document.getElementById('detailBody').innerHTML =
                '<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 mb-5">' +
                '<div class="border border-gray-200 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase text-gray-400">Instansi</p>' +
                '<p class="text-sm font-black mt-1">' + escapeHtml(row.nama_instansi) + '</p>' +
                '<p class="text-xs text-gray-400 mt-1">' + escapeHtml(row.penerima_tagihan || '-') + '</p>' +
                '</div>' +
                '<div class="border border-gray-200 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase text-gray-400">Periode</p>' +
                '<p class="text-sm font-black mt-1">' +
                formatDate(row.tanggal_awal) + ' — ' + formatDate(row.tanggal_akhir) +
                '</p>' +
                '</div>' +
                '<div class="border border-gray-200 bg-gray-50 p-4">' +
                '<p class="text-[9px] font-black uppercase text-gray-400">Total Tagihan</p>' +
                '<p class="text-lg font-black text-blue-600 mt-1">' + formatRupiah(row.total_tagihan) + '</p>' +
                '<p class="text-xs text-gray-400 mt-1">' + escapeHtml(row.terbilang || '-') + '</p>' +
                '</div>' +
                '</div>' +

                '<div class="modal-table-wrap">' +
                '<table class="w-full min-w-[680px]">' +
                '<thead class="bg-gray-50 border-b border-gray-200">' +
                '<tr>' +
                '<th class="px-4 py-3 text-left text-[9px] font-black uppercase text-gray-400">Produk</th>' +
                '<th class="px-4 py-3 text-center text-[9px] font-black uppercase text-gray-400">Jumlah</th>' +
                '<th class="px-4 py-3 text-right text-[9px] font-black uppercase text-gray-400">Harga</th>' +
                '<th class="px-4 py-3 text-right text-[9px] font-black uppercase text-gray-400">Subtotal</th>' +
                '</tr>' +
                '</thead>' +
                '<tbody class="divide-y divide-gray-100">' + detailRows + '</tbody>' +
                '</table>' +
                '</div>' +

                '<div class="mt-5">' +
                '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Sumber Pesanan</p>' +
                '<div class="grid grid-cols-1 lg:grid-cols-2 gap-2">' +
                (sourceCards || '<p class="text-xs text-gray-400">Tidak ada sumber pesanan.</p>') +
                '</div>' +
                '</div>' +

                (row.catatan ?
                    '<div class="mt-5 border border-amber-100 bg-amber-50 p-4 text-xs text-amber-700">' +
                    escapeHtml(row.catatan) +
                    '</div>' :
                    '');

            openModal('detailModal');
        }

        function openPayment(row) {
            document.getElementById('paymentTitle').textContent = row.nomor_kwitansi || '-';
            document.getElementById('paymentId').value = Number(row.id || 0);
            document.getElementById('paymentStatus').value = row.status_pembayaran || 'belum_bayar';
            document.getElementById('paymentDate').value = row.tanggal_bayar || '';
            togglePaymentDate();
            openModal('paymentModal');
        }

        function togglePaymentDate() {
            var status = document.getElementById('paymentStatus').value;
            var wrap = document.getElementById('paymentDateWrap');

            if (status === 'lunas') {
                wrap.classList.remove('hidden');
                if (!document.getElementById('paymentDate').value) {
                    document.getElementById('paymentDate').value =
                        new Date().toISOString().substring(0, 10);
                }
            } else {
                wrap.classList.add('hidden');
            }
        }

        function openPrint(row) {
            var details = Array.isArray(row.details) ? row.details : [];
            var sources = Array.isArray(row.sources) ? row.sources : [];

            var detailRows = details.map(function(item, index) {
                return '' +
                    '<tr>' +
                    '<td style="border:1px solid #bbb;padding:8px;text-align:center;">' + (index + 1) + '</td>' +
                    '<td style="border:1px solid #bbb;padding:8px;">' + escapeHtml(item.nama_produk) + '</td>' +
                    '<td style="border:1px solid #bbb;padding:8px;text-align:center;">' +
                    Number(item.qty || 0).toLocaleString('id-ID') + ' ' + escapeHtml(item.satuan) +
                    '</td>' +
                    '<td style="border:1px solid #bbb;padding:8px;text-align:right;">' + formatRupiah(item.harga_satuan) + '</td>' +
                    '<td style="border:1px solid #bbb;padding:8px;text-align:right;font-weight:700;">' + formatRupiah(item.subtotal) + '</td>' +
                    '</tr>';
            }).join('');

            var sourceRows = sources.map(function(source, index) {
                return '' +
                    '<tr>' +
                    '<td style="border:1px solid #ddd;padding:7px;text-align:center;">' + (index + 1) + '</td>' +
                    '<td style="border:1px solid #ddd;padding:7px;">' + escapeHtml(source.nomor_pesanan) + '</td>' +
                    '<td style="border:1px solid #ddd;padding:7px;">' + escapeHtml(source.nama_pemesan) + '</td>' +
                    '<td style="border:1px solid #ddd;padding:7px;">' + escapeHtml(source.lokasi || '-') + '</td>' +
                    '</tr>';
            }).join('');

            document.getElementById('printBody').innerHTML =
                '<div class="print-sheet">' +
                '<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #111;padding-bottom:14px;">' +
                '<div>' +
                '<p style="font-size:22px;font-weight:800;margin:0;">SEJAHUB</p>' +
                '<p style="font-size:11px;margin:4px 0 0;">Sistem Layanan Terpadu</p>' +
                '</div>' +
                '<div style="text-align:right;">' +
                '<p style="font-size:18px;font-weight:800;margin:0;">KWITANSI PENAGIHAN</p>' +
                '<p style="font-size:11px;margin:4px 0 0;">AIR MINERAL</p>' +
                '</div>' +
                '</div>' +

                '<div style="margin-top:22px;display:grid;grid-template-columns:160px 1fr;gap:7px;font-size:12px;">' +
                '<div>Nomor Kwitansi</div><div>: <strong>' + escapeHtml(row.nomor_kwitansi) + '</strong></div>' +
                '<div>Tanggal</div><div>: ' + formatDate(row.tanggal_kwitansi) + '</div>' +
                '<div>Periode Tagihan</div><div>: ' + formatDate(row.tanggal_awal) + ' s.d. ' + formatDate(row.tanggal_akhir) + '</div>' +
                '<div>Ditagihkan kepada</div><div>: <strong>' + escapeHtml(row.nama_instansi) + '</strong></div>' +
                '<div>Penerima Tagihan</div><div>: ' + escapeHtml(row.penerima_tagihan || '-') + '</div>' +
                '<div>Alamat</div><div>: ' + escapeHtml(row.alamat_instansi || '-') + '</div>' +
                '</div>' +

                '<table style="width:100%;border-collapse:collapse;margin-top:22px;font-size:11px;">' +
                '<thead>' +
                '<tr style="background:#f3f4f6;">' +
                '<th style="border:1px solid #bbb;padding:8px;width:40px;">No</th>' +
                '<th style="border:1px solid #bbb;padding:8px;text-align:left;">Uraian</th>' +
                '<th style="border:1px solid #bbb;padding:8px;width:110px;">Jumlah</th>' +
                '<th style="border:1px solid #bbb;padding:8px;width:130px;text-align:right;">Harga Satuan</th>' +
                '<th style="border:1px solid #bbb;padding:8px;width:140px;text-align:right;">Subtotal</th>' +
                '</tr>' +
                '</thead>' +
                '<tbody>' + detailRows + '</tbody>' +
                '<tfoot>' +
                '<tr>' +
                '<td colspan="4" style="border:1px solid #bbb;padding:10px;text-align:right;font-weight:800;">TOTAL TAGIHAN</td>' +
                '<td style="border:1px solid #bbb;padding:10px;text-align:right;font-weight:800;">' + formatRupiah(row.total_tagihan) + '</td>' +
                '</tr>' +
                '</tfoot>' +
                '</table>' +

                '<div style="margin-top:14px;border:1px solid #bbb;padding:10px;font-size:11px;">' +
                '<strong>Terbilang:</strong> ' + escapeHtml(row.terbilang || '-') +
                '</div>' +

                '<p style="font-size:10px;margin-top:18px;font-weight:700;">Rincian Sumber Pesanan</p>' +
                '<table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:9px;">' +
                '<thead>' +
                '<tr style="background:#f9fafb;">' +
                '<th style="border:1px solid #ddd;padding:7px;width:35px;">No</th>' +
                '<th style="border:1px solid #ddd;padding:7px;text-align:left;">Nomor Pesanan</th>' +
                '<th style="border:1px solid #ddd;padding:7px;text-align:left;">Pemesan</th>' +
                '<th style="border:1px solid #ddd;padding:7px;text-align:left;">Lokasi</th>' +
                '</tr>' +
                '</thead>' +
                '<tbody>' + sourceRows + '</tbody>' +
                '</table>' +

                (row.catatan ?
                    '<div style="margin-top:14px;font-size:10px;"><strong>Catatan:</strong> ' + escapeHtml(row.catatan) + '</div>' :
                    '') +

                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:80px;margin-top:48px;text-align:center;font-size:11px;">' +
                '<div>' +
                '<p>Penerima Tagihan</p>' +
                '<div style="height:70px;"></div>' +
                '<p style="border-top:1px solid #111;padding-top:6px;">' + escapeHtml(row.penerima_tagihan || '________________') + '</p>' +
                '</div>' +
                '<div>' +
                '<p>Petugas SEJAHUB</p>' +
                '<div style="height:70px;"></div>' +
                '<p style="border-top:1px solid #111;padding-top:6px;">' + escapeHtml(currentUserName) + '</p>' +
                '</div>' +
                '</div>' +
                '</div>';

            openModal('printModal');
        }

        document.querySelectorAll('.modal-wrap').forEach(function(modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                ['createModal', 'detailModal', 'paymentModal', 'printModal'].forEach(closeModal);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            updateCreatePreview();
            togglePaymentDate();
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>

</html>