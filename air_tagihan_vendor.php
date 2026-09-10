<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'air_tagihan_vendor';
$pageTitle  = 'Tagihan Vendor Air Mineral';
$backUrl    = 'air_rekap_vendor.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('atv_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function atv_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('atv_rp')) {
    /**
     * @param mixed $value
     * @return string
     */
    function atv_rp($value): string
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}

if (!function_exists('atv_date')) {
    /**
     * @param mixed $value
     * @return string
     */
    function atv_date($value): string
    {
        if (!$value) return '-';
        $ts = strtotime((string)$value);
        return $ts ? date('d/m/Y', $ts) : '-';
    }
}


if (!function_exists('atv_column_exists')) {
    function atv_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $stmt->execute([':t' => $table, ':c' => $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('atv_save_payment_proof')) {
    function atv_save_payment_proof(string $field = 'bukti_pembayaran'): ?string
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $f = $_FILES[$field];
        $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) return null;
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload bukti pembayaran gagal. Kode: ' . $err);
        $size = isset($f['size']) ? (int)$f['size'] : 0;
        if ($size <= 0 || $size > 8 * 1024 * 1024) throw new RuntimeException('Ukuran bukti pembayaran maksimal 8 MB.');
        $original = (string)($f['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($ext, $allowed, true)) throw new RuntimeException('Bukti pembayaran harus JPG, JPEG, PNG, WebP, atau PDF.');
        $dir = __DIR__ . '/uploads/bukti_pembayaran_vendor';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Folder bukti pembayaran tidak dapat dibuat.');
        if (!is_writable($dir)) throw new RuntimeException('Folder uploads/bukti_pembayaran_vendor belum memiliki izin tulis.');
        $name = 'bukti_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file((string)$f['tmp_name'], $dest)) throw new RuntimeException('Bukti pembayaran gagal disimpan.');
        return 'uploads/bukti_pembayaran_vendor/' . $name;
    }
}

if (!function_exists('atv_save_vendor_invoice')) {
    /** Upload invoice/tagihan asli dari vendor. Opsional. */
    function atv_save_vendor_invoice(string $field = 'invoice_vendor'): ?string
    {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $f = $_FILES[$field];
        $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) return null;
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload invoice vendor gagal. Kode: ' . $err);
        $size = isset($f['size']) ? (int)$f['size'] : 0;
        if ($size <= 0 || $size > 8 * 1024 * 1024) throw new RuntimeException('Ukuran invoice vendor maksimal 8 MB.');
        $original = (string)($f['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($ext, $allowed, true)) throw new RuntimeException('Invoice vendor harus JPG, JPEG, PNG, WebP, atau PDF.');
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('File invoice vendor tidak valid.');
        $dir = __DIR__ . '/uploads/invoice_vendor';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Folder invoice vendor tidak dapat dibuat.');
        if (!is_writable($dir)) throw new RuntimeException('Folder uploads/invoice_vendor belum memiliki izin tulis.');
        $random = function_exists('random_bytes') ? bin2hex(random_bytes(4)) : substr(md5(uniqid('', true)), 0, 8);
        $name = 'invoice_' . date('Ymd_His') . '_' . $random . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Invoice vendor gagal disimpan.');
        return 'uploads/invoice_vendor/' . $name;
    }
}

if (!function_exists('atv_ensure_schema')) {
    function atv_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_tagihan (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                vendor_order_id INT NOT NULL,\n                nomor_tagihan VARCHAR(70) NOT NULL,\n                nomor_invoice_vendor VARCHAR(100) NULL,\n                vendor_nama VARCHAR(150) NOT NULL,\n                tanggal_tagihan DATE NOT NULL,\n                jatuh_tempo DATE NULL,\n                nilai_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0,\n                total_dibayar DECIMAL(15,2) NOT NULL DEFAULT 0,\n                status VARCHAR(30) NOT NULL DEFAULT 'belum_bayar',\n                catatan TEXT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                UNIQUE KEY uq_air_vendor_tagihan_order (vendor_order_id),\n                UNIQUE KEY uq_air_vendor_tagihan_nomor (nomor_tagihan),\n                INDEX idx_air_vendor_tagihan_vendor (vendor_nama),\n                INDEX idx_air_vendor_tagihan_status (status),\n                INDEX idx_air_vendor_tagihan_tanggal (tanggal_tagihan)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_tagihan_detail (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                tagihan_id INT NOT NULL,\n                produk_id INT NULL,\n                nama_produk VARCHAR(180) NOT NULL,\n                satuan VARCHAR(30) NOT NULL DEFAULT 'unit',\n                jumlah_diterima INT NOT NULL DEFAULT 0,\n                harga_vendor DECIMAL(15,2) NOT NULL DEFAULT 0,\n                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_air_vendor_tagihan_detail (tagihan_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS air_vendor_pembayaran (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                tagihan_id INT NOT NULL,\n                tanggal_bayar DATE NOT NULL,\n                nominal DECIMAL(15,2) NOT NULL DEFAULT 0,\n                metode VARCHAR(50) NULL,\n                nomor_referensi VARCHAR(120) NULL,\n                catatan TEXT NULL,\n                created_by INT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_air_vendor_pembayaran_tagihan (tagihan_id),\n                INDEX idx_air_vendor_pembayaran_tanggal (tanggal_bayar)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");

        if (!atv_column_exists($pdo, 'air_vendor_pembayaran', 'bukti_pembayaran')) {
            $pdo->exec("ALTER TABLE air_vendor_pembayaran ADD COLUMN bukti_pembayaran VARCHAR(255) NULL AFTER catatan");
        }
        if (!atv_column_exists($pdo, 'air_vendor_tagihan', 'invoice_vendor_file')) {
            $pdo->exec("ALTER TABLE air_vendor_tagihan ADD COLUMN invoice_vendor_file VARCHAR(255) NULL AFTER nomor_invoice_vendor");
        }
    }
}

if (!function_exists('atv_refresh_status')) {
    function atv_refresh_status(PDO $pdo, int $tagihanId): void
    {
        $stmt = $pdo->prepare("SELECT nilai_tagihan FROM air_vendor_tagihan WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $tagihanId]);
        $nilai = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM air_vendor_pembayaran WHERE tagihan_id = :id");
        $stmt->execute([':id' => $tagihanId]);
        $dibayar = (float)$stmt->fetchColumn();

        if ($dibayar <= 0) {
            $status = 'belum_bayar';
        } elseif ($nilai > 0 && $dibayar >= $nilai) {
            $status = 'lunas';
        } else {
            $status = 'sebagian';
        }

        $stmt = $pdo->prepare("\n            UPDATE air_vendor_tagihan\n            SET total_dibayar = :dibayar, status = :status, updated_at = NOW()\n            WHERE id = :id\n        ");
        $stmt->execute([':dibayar' => $dibayar, ':status' => $status, ':id' => $tagihanId]);
    }
}

if (!function_exists('atv_sync_all_payment_totals')) {
    /**
     * Sinkronkan nilai pembayaran dan status seluruh tagihan langsung dari
     * tabel air_vendor_pembayaran. Ini mencegah card dan tabel membaca nilai
     * cache total_dibayar/status yang sudah tidak sesuai dengan database.
     */
    function atv_sync_all_payment_totals(PDO $pdo): void
    {
        $pdo->exec("
            UPDATE air_vendor_tagihan t
            LEFT JOIN (
                SELECT tagihan_id, COALESCE(SUM(nominal),0) AS dibayar
                FROM air_vendor_pembayaran
                GROUP BY tagihan_id
            ) p ON p.tagihan_id = t.id
            SET
                t.total_dibayar = COALESCE(p.dibayar,0),
                t.status = CASE
                    WHEN COALESCE(p.dibayar,0) <= 0 THEN 'belum_bayar'
                    WHEN t.nilai_tagihan > 0 AND COALESCE(p.dibayar,0) >= t.nilai_tagihan THEN 'lunas'
                    ELSE 'sebagian'
                END
        ");
    }
}

$flash = '';
$flashType = 'success';

try {
    atv_ensure_schema($pdo);
    atv_sync_all_payment_totals($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan database tagihan vendor: ' . $e->getMessage();
    $flashType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash === '') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_bill') {
            $vendorOrderId = (int)($_POST['vendor_order_id'] ?? 0);
            $invoiceVendor = trim((string)($_POST['nomor_invoice_vendor'] ?? ''));
            $tanggalTagihan = trim((string)($_POST['tanggal_tagihan'] ?? date('Y-m-d')));
            $jatuhTempo = trim((string)($_POST['jatuh_tempo'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            if ($vendorOrderId <= 0) {
                throw new RuntimeException('Rekap vendor tidak valid.');
            }

            $check = $pdo->prepare("SELECT id FROM air_vendor_tagihan WHERE vendor_order_id = :id LIMIT 1");
            $check->execute([':id' => $vendorOrderId]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('Rekap vendor ini sudah memiliki tagihan.');
            }

            $stmt = $pdo->prepare("\n                SELECT vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.status,\n                       COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END)),0) AS total_diterima\n                FROM air_vendor_order vo\n                LEFT JOIN air_vendor_order_detail vd ON vd.vendor_order_id = vo.id\n                WHERE vo.id = :id\n                GROUP BY vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.status\n                LIMIT 1\n            ");
            $stmt->execute([':id' => $vendorOrderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('Rekap vendor tidak ditemukan.');
            }
            if ((int)$order['total_diterima'] <= 0) {
                throw new RuntimeException('Tagihan belum dapat dibuat karena jumlah pemesanan masih 0.');
            }
            if ((string)$order['status'] === 'batal') {
                throw new RuntimeException('Rekap vendor yang batal tidak dapat dibuatkan tagihan.');
            }

            $stmt = $pdo->prepare("\n                SELECT vd.produk_id, vd.nama_produk, vd.satuan, (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) AS jumlah_diterima,\n                       COALESCE(ap.harga_vendor,0) AS harga_vendor\n                FROM air_vendor_order_detail vd\n                LEFT JOIN air_produk ap ON ap.id = vd.produk_id\n                WHERE vd.vendor_order_id = :id\n                  AND (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) > 0\n                ORDER BY vd.id ASC\n            ");
            $stmt->execute([':id' => $vendorOrderId]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$details) {
                throw new RuntimeException('Rincian jumlah pemesanan tidak ditemukan.');
            }

            $nilaiTagihan = 0.0;
            foreach ($details as $d) {
                $nilaiTagihan += ((int)$d['jumlah_diterima']) * ((float)$d['harga_vendor']);
            }

            $pdo->beginTransaction();
            $nomorTagihan = 'TV-AIR-' . date('Ymd') . '-' . str_pad((string)$vendorOrderId, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("\n                INSERT INTO air_vendor_tagihan\n                    (vendor_order_id, nomor_tagihan, nomor_invoice_vendor, vendor_nama, tanggal_tagihan, jatuh_tempo, nilai_tagihan, total_dibayar, status, catatan, created_at)\n                VALUES\n                    (:vendor_order_id, :nomor_tagihan, :invoice_vendor, :vendor_nama, :tanggal_tagihan, :jatuh_tempo, :nilai_tagihan, 0, 'belum_bayar', :catatan, NOW())\n            ");
            $stmt->execute([
                ':vendor_order_id' => $vendorOrderId,
                ':nomor_tagihan' => $nomorTagihan,
                ':invoice_vendor' => $invoiceVendor !== '' ? $invoiceVendor : null,
                ':vendor_nama' => (string)$order['vendor_nama'],
                ':tanggal_tagihan' => $tanggalTagihan,
                ':jatuh_tempo' => $jatuhTempo !== '' ? $jatuhTempo : null,
                ':nilai_tagihan' => $nilaiTagihan,
                ':catatan' => $catatan,
            ]);
            $tagihanId = (int)$pdo->lastInsertId();

            $stmtDetail = $pdo->prepare("\n                INSERT INTO air_vendor_tagihan_detail\n                    (tagihan_id, produk_id, nama_produk, satuan, jumlah_diterima, harga_vendor, subtotal, created_at)\n                VALUES\n                    (:tagihan_id, :produk_id, :nama_produk, :satuan, :jumlah_diterima, :harga_vendor, :subtotal, NOW())\n            ");
            foreach ($details as $d) {
                $subtotal = ((int)$d['jumlah_diterima']) * ((float)$d['harga_vendor']);
                $stmtDetail->execute([
                    ':tagihan_id' => $tagihanId,
                    ':produk_id' => $d['produk_id'] !== null ? (int)$d['produk_id'] : null,
                    ':nama_produk' => (string)$d['nama_produk'],
                    ':satuan' => (string)$d['satuan'],
                    ':jumlah_diterima' => (int)$d['jumlah_diterima'],
                    ':harga_vendor' => (float)$d['harga_vendor'],
                    ':subtotal' => $subtotal,
                ]);
            }
            $pdo->commit();
            $flash = 'Tagihan vendor berhasil dibuat berdasarkan barang yang benar-benar diterima.';
        }

        if ($action === 'add_payment') {
            $tagihanId = (int)($_POST['tagihan_id'] ?? 0);
            $tanggalBayar = trim((string)($_POST['tanggal_bayar'] ?? date('Y-m-d')));
            $nominal = (float)($_POST['nominal'] ?? 0);
            $metode = trim((string)($_POST['metode'] ?? 'Transfer'));
            $referensi = trim((string)($_POST['nomor_referensi'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $buktiPembayaran = atv_save_payment_proof();

            if ($tagihanId <= 0 || $nominal <= 0) {
                throw new RuntimeException('Nominal pembayaran harus lebih dari 0.');
            }

            $stmt = $pdo->prepare("SELECT nilai_tagihan, total_dibayar FROM air_vendor_tagihan WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $tagihanId]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bill) throw new RuntimeException('Tagihan tidak ditemukan.');

            $sisa = max(0, (float)$bill['nilai_tagihan'] - (float)$bill['total_dibayar']);
            if ($sisa > 0 && $nominal > $sisa) {
                throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan ' . atv_rp($sisa) . '.');
            }

            $createdBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            $stmt = $pdo->prepare("\n                INSERT INTO air_vendor_pembayaran\n                    (tagihan_id, tanggal_bayar, nominal, metode, nomor_referensi, catatan, bukti_pembayaran, created_by, created_at)\n                VALUES\n                    (:tagihan_id, :tanggal_bayar, :nominal, :metode, :referensi, :catatan, :bukti, :created_by, NOW())\n            ");
            $stmt->execute([
                ':tagihan_id' => $tagihanId,
                ':tanggal_bayar' => $tanggalBayar,
                ':nominal' => $nominal,
                ':metode' => $metode,
                ':referensi' => $referensi !== '' ? $referensi : null,
                ':catatan' => $catatan,
                ':bukti' => $buktiPembayaran,
                ':created_by' => $createdBy,
            ]);
            atv_refresh_status($pdo, $tagihanId);
            $flash = 'Pembayaran vendor berhasil dicatat.';
        }


        if ($action === 'bulk_payment') {
            $vendorOrderIds = isset($_POST['vendor_order_ids']) && is_array($_POST['vendor_order_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['vendor_order_ids']))))
                : [];
            $historicalOrderIds = isset($_POST['historical_order_ids']) && is_array($_POST['historical_order_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['historical_order_ids']))))
                : [];
            $historicalVendorName = trim((string)($_POST['historical_vendor_name'] ?? ''));
            $historicalNominal = isset($_POST['historical_nominal']) && is_array($_POST['historical_nominal'])
                ? $_POST['historical_nominal']
                : [];
            $tanggalBayar = trim((string)($_POST['tanggal_bayar'] ?? date('Y-m-d')));
            $metode = trim((string)($_POST['metode'] ?? 'Transfer'));
            $referensi = trim((string)($_POST['nomor_referensi'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            if (!$vendorOrderIds && !$historicalOrderIds) {
                throw new RuntimeException('Pilih minimal satu pemesanan yang akan dibayar.');
            }
            if ($historicalOrderIds && $historicalVendorName === '') {
                throw new RuntimeException('Pilih atau isi nama vendor untuk data lama yang dipilih.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalBayar)) {
                throw new RuntimeException('Tanggal pembayaran tidak valid.');
            }

            $createdBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            // Invoice vendor bersifat opsional. Jika kosong pembayaran tetap dapat diproses.
            $invoiceVendorFile = atv_save_vendor_invoice('invoice_vendor');
            $pdo->beginTransaction();

            /*
             * DATA LAMA / MIGRASI
             * ------------------
             * Pesanan historis diinput langsung ke air_pesanan dan memang tidak mempunyai
             * air_vendor_order. Agar tetap bisa masuk pembayaran vendor, saat dipilih di sini
             * sistem membuat rekap vendor historis satu-per-pesanan, menyalin detail qty,
             * menghubungkan source, lalu melanjutkan proses tagihan seperti data baru.
             */
            if ($historicalOrderIds) {
                $histPlaceholders = implode(',', array_fill(0, count($historicalOrderIds), '?'));
                $historicalWhere = "p.id IN ($histPlaceholders) AND LOWER(TRIM(COALESCE(p.status,''))) <> 'batal'";
                $historicalMarkers = ["UPPER(TRIM(COALESCE(p.nomor_pesanan,''))) LIKE 'AIR-LAMA-%'"];
                if (atv_column_exists($pdo, 'air_pesanan', 'is_historical')) {
                    $historicalMarkers[] = "COALESCE(p.is_historical,0)=1";
                }
                if (atv_column_exists($pdo, 'air_pesanan', 'sumber_data')) {
                    $historicalMarkers[] = "LOWER(TRIM(COALESCE(p.sumber_data,'')))='migrasi'";
                }
                $historicalWhere .= " AND (" . implode(' OR ', $historicalMarkers) . ")";
                // AIR-LAMA tidak wajib pernah masuk rekap vendor.
                $histTanggalPemesanan = atv_column_exists($pdo, 'air_pesanan', 'tanggal_pemesanan') ? 'p.tanggal_pemesanan' : 'NULL';
                $histTanggalKirim = atv_column_exists($pdo, 'air_pesanan', 'tanggal_kirim') ? 'p.tanggal_kirim' : 'NULL';

                $stmtHist = $pdo->prepare("
                    SELECT p.id, p.nomor_pesanan, {$histTanggalPemesanan} AS tanggal_pemesanan, {$histTanggalKirim} AS tanggal_kirim
                    FROM air_pesanan p
                    WHERE $historicalWhere
                    FOR UPDATE
                ");
                $stmtHist->execute($historicalOrderIds);
                $historicalRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

                if (count($historicalRows) !== count($historicalOrderIds)) {
                    throw new RuntimeException('Sebagian data lama dibatalkan atau tidak lagi tersedia. Muat ulang halaman lalu pilih kembali.');
                }

                $stmtHistDetails = $pdo->prepare("
                    SELECT
                        d.produk_id, d.kode_produk, d.nama_produk,
                        COALESCE(ap.satuan,'unit') AS satuan,
                        SUM(COALESCE(d.qty,0)) AS qty,
                        COALESCE(ap.harga_vendor,0) AS harga_vendor
                    FROM air_pesanan_detail d
                    LEFT JOIN air_produk ap ON ap.id=d.produk_id
                    WHERE d.pesanan_id=:pesanan_id
                    GROUP BY d.produk_id, d.kode_produk, d.nama_produk, ap.satuan, ap.harga_vendor
                    HAVING SUM(COALESCE(d.qty,0)) > 0
                    ORDER BY d.nama_produk ASC
                ");
                $stmtCreateHistOrder = $pdo->prepare("
                    INSERT INTO air_vendor_order
                        (nomor_vendor_order, tanggal_rekap, tanggal_kebutuhan, vendor_nama, vendor_wa, status, total_unit, catatan, created_by, created_at)
                    VALUES
                        (:nomor, :tanggal_rekap, :tanggal_kebutuhan, :vendor_nama, NULL, 'selesai', :total_unit, :catatan, :created_by, NOW())
                ");
                $stmtCreateHistDetail = $pdo->prepare("
                    INSERT INTO air_vendor_order_detail
                        (vendor_order_id, produk_id, kode_produk, nama_produk, satuan, jumlah_diminta, jumlah_dipesan, jumlah_dikonfirmasi, jumlah_diterima, created_at)
                    VALUES
                        (:vendor_order_id, :produk_id, :kode_produk, :nama_produk, :satuan, :qty, :qty, :qty, :qty, NOW())
                ");
                $stmtCreateHistSource = $pdo->prepare("
                    INSERT INTO air_vendor_order_source (vendor_order_id, pesanan_id, created_at)
                    VALUES (:vendor_order_id, :pesanan_id, NOW())
                ");
                $stmtCreateHistBill = $pdo->prepare("
                    INSERT INTO air_vendor_tagihan
                        (vendor_order_id, nomor_tagihan, nomor_invoice_vendor, invoice_vendor_file, vendor_nama, tanggal_tagihan, jatuh_tempo, nilai_tagihan, total_dibayar, status, catatan, created_at)
                    VALUES
                        (:vendor_order_id, :nomor_tagihan, NULL, :invoice_vendor_file, :vendor_nama, :tanggal_tagihan, NULL, :nilai_tagihan, 0, 'belum_bayar', :catatan, NOW())
                ");
                $stmtCreateHistBillDetail = $pdo->prepare("
                    INSERT INTO air_vendor_tagihan_detail
                        (tagihan_id, produk_id, nama_produk, satuan, jumlah_diterima, harga_vendor, subtotal, created_at)
                    VALUES
                        (:tagihan_id, :produk_id, :nama_produk, :satuan, :jumlah_diterima, :harga_vendor, :subtotal, NOW())
                ");

                foreach ($historicalRows as $hist) {
                    $stmtHistDetails->execute([':pesanan_id' => (int)$hist['id']]);
                    $histDetails = $stmtHistDetails->fetchAll(PDO::FETCH_ASSOC);

                    $totalUnitHist = 0;
                    $nilaiHist = 0.0;
                    foreach ($histDetails as $hd) {
                        $qtyHist = max(0, (int)$hd['qty']);
                        $totalUnitHist += $qtyHist;
                        $nilaiHist += $qtyHist * (float)$hd['harga_vendor'];
                    }

                    $manualHistNominal = isset($historicalNominal[(string)$hist['id']])
                        ? (float)$historicalNominal[(string)$hist['id']]
                        : 0.0;
                    $nilaiTagihanHist = $nilaiHist > 0 ? $nilaiHist : max(0, $manualHistNominal);
                    if ($nilaiTagihanHist <= 0) {
                        throw new RuntimeException('Isi nominal tagihan untuk data lama ' . (string)$hist['nomor_pesanan'] . '. Data ini berasal dari sebelum aplikasi sehingga nilai vendor tidak dapat dihitung otomatis.');
                    }

                    $histDate = !empty($hist['tanggal_kirim']) ? (string)$hist['tanggal_kirim'] : (string)$hist['tanggal_pemesanan'];
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $histDate)) {
                        $histDate = $tanggalBayar;
                    }
                    $nomorVendorHist = 'V-AIR-HIST-' . (int)$hist['id'] . '-' . date('His');
                    $stmtCreateHistOrder->execute([
                        ':nomor' => $nomorVendorHist,
                        ':tanggal_rekap' => $histDate,
                        ':tanggal_kebutuhan' => $histDate,
                        ':vendor_nama' => $historicalVendorName,
                        ':total_unit' => $totalUnitHist,
                        ':catatan' => 'Rekap otomatis dari data historis ' . (string)$hist['nomor_pesanan'],
                        ':created_by' => $createdBy,
                    ]);
                    $histVendorOrderId = (int)$pdo->lastInsertId();

                    if ($histDetails) {
                        foreach ($histDetails as $hd) {
                            $stmtCreateHistDetail->execute([
                                ':vendor_order_id' => $histVendorOrderId,
                                ':produk_id' => $hd['produk_id'] !== null ? (int)$hd['produk_id'] : null,
                                ':kode_produk' => (string)($hd['kode_produk'] ?? ''),
                                ':nama_produk' => (string)$hd['nama_produk'],
                                ':satuan' => (string)($hd['satuan'] ?: 'unit'),
                                ':qty' => max(0, (int)$hd['qty']),
                            ]);
                        }
                    } else {
                        $stmtCreateHistDetail->execute([
                            ':vendor_order_id' => $histVendorOrderId,
                            ':produk_id' => null,
                            ':kode_produk' => 'HISTORIS',
                            ':nama_produk' => 'Tagihan historis ' . (string)$hist['nomor_pesanan'],
                            ':satuan' => 'tagihan',
                            ':qty' => 1,
                        ]);
                        $totalUnitHist = 1;
                    }
                    $stmtCreateHistSource->execute([
                        ':vendor_order_id' => $histVendorOrderId,
                        ':pesanan_id' => (int)$hist['id'],
                    ]);

                    $nomorTagihanHist = 'TV-AIR-HIST-' . date('Ymd') . '-' . str_pad((string)$histVendorOrderId, 4, '0', STR_PAD_LEFT);
                    $stmtCreateHistBill->execute([
                        ':vendor_order_id' => $histVendorOrderId,
                        ':nomor_tagihan' => $nomorTagihanHist,
                        ':invoice_vendor_file' => $invoiceVendorFile,
                        ':vendor_nama' => $historicalVendorName,
                        ':tanggal_tagihan' => $tanggalBayar,
                        ':nilai_tagihan' => $nilaiTagihanHist,
                        ':catatan' => 'Tagihan historis otomatis dari ' . (string)$hist['nomor_pesanan'],
                    ]);
                    $histTagihanId = (int)$pdo->lastInsertId();

                    if ($nilaiHist > 0 && $histDetails) {
                        foreach ($histDetails as $hd) {
                            $qtyHist = max(0, (int)$hd['qty']);
                            $hargaHist = (float)$hd['harga_vendor'];
                            $stmtCreateHistBillDetail->execute([
                                ':tagihan_id' => $histTagihanId,
                                ':produk_id' => $hd['produk_id'] !== null ? (int)$hd['produk_id'] : null,
                                ':nama_produk' => (string)$hd['nama_produk'],
                                ':satuan' => (string)($hd['satuan'] ?: 'unit'),
                                ':jumlah_diterima' => $qtyHist,
                                ':harga_vendor' => $hargaHist,
                                ':subtotal' => $qtyHist * $hargaHist,
                            ]);
                        }
                    } else {
                        $stmtCreateHistBillDetail->execute([
                            ':tagihan_id' => $histTagihanId,
                            ':produk_id' => null,
                            ':nama_produk' => 'Tagihan historis ' . (string)$hist['nomor_pesanan'],
                            ':satuan' => 'tagihan',
                            ':jumlah_diterima' => 1,
                            ':harga_vendor' => $nilaiTagihanHist,
                            ':subtotal' => $nilaiTagihanHist,
                        ]);
                    }
                    $vendorOrderIds[] = $histVendorOrderId;
                }
                $vendorOrderIds = array_values(array_unique(array_map('intval', $vendorOrderIds)));
            }

            $placeholders = implode(',', array_fill(0, count($vendorOrderIds), '?'));
            $stmtOrders = $pdo->prepare("\n                SELECT vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.status\n                FROM air_vendor_order vo\n                WHERE vo.id IN ($placeholders)\n                  AND vo.status <> 'batal'\n                FOR UPDATE\n            ");
            $stmtOrders->execute($vendorOrderIds);
            $selectedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            if (count($selectedOrders) !== count($vendorOrderIds)) {
                throw new RuntimeException('Sebagian pemesanan yang dipilih tidak ditemukan atau sudah dibatalkan.');
            }

            $vendors = array_values(array_unique(array_map(function ($o) {
                return trim((string)$o['vendor_nama']);
            }, $selectedOrders)));
            if (count($vendors) > 1) {
                throw new RuntimeException('Satu pembayaran hanya boleh berisi pemesanan dari vendor yang sama.');
            }

            $stmtExistingBill = $pdo->prepare("SELECT * FROM air_vendor_tagihan WHERE vendor_order_id = :order_id LIMIT 1");
            $stmtOrderDetails = $pdo->prepare("\n                SELECT vd.produk_id, vd.nama_produk, vd.satuan, (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) AS jumlah_diterima,\n                       COALESCE(ap.harga_vendor,0) AS harga_vendor\n                FROM air_vendor_order_detail vd\n                LEFT JOIN air_produk ap ON ap.id = vd.produk_id\n                WHERE vd.vendor_order_id = :order_id\n                  AND (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) > 0\n                ORDER BY vd.id ASC\n            ");
            $stmtCreateBill = $pdo->prepare("\n                INSERT INTO air_vendor_tagihan\n                    (vendor_order_id, nomor_tagihan, nomor_invoice_vendor, vendor_nama, tanggal_tagihan, jatuh_tempo, nilai_tagihan, total_dibayar, status, catatan, created_at)\n                VALUES\n                    (:vendor_order_id, :nomor_tagihan, NULL, :vendor_nama, :tanggal_tagihan, NULL, :nilai_tagihan, 0, 'belum_bayar', :catatan, NOW())\n            ");
            $stmtCreateDetail = $pdo->prepare("\n                INSERT INTO air_vendor_tagihan_detail\n                    (tagihan_id, produk_id, nama_produk, satuan, jumlah_diterima, harga_vendor, subtotal, created_at)\n                VALUES\n                    (:tagihan_id, :produk_id, :nama_produk, :satuan, :jumlah_diterima, :harga_vendor, :subtotal, NOW())\n            ");
            $stmtPay = $pdo->prepare("\n                INSERT INTO air_vendor_pembayaran\n                    (tagihan_id, tanggal_bayar, nominal, metode, nomor_referensi, catatan, bukti_pembayaran, created_by, created_at)\n                VALUES\n                    (:tagihan_id, :tanggal_bayar, :nominal, :metode, :referensi, :catatan, :bukti, :created_by, NOW())\n            ");

            // Simpan bukti setelah data yang dipilih lolos validasi dasar.
            $buktiPembayaran = atv_save_payment_proof();
            $processed = 0;
            $totalPaidNow = 0.0;

            foreach ($selectedOrders as $order) {
                $orderId = (int)$order['id'];
                $stmtExistingBill->execute([':order_id' => $orderId]);
                $bill = $stmtExistingBill->fetch(PDO::FETCH_ASSOC);
                if ($bill && $invoiceVendorFile) {
                    $stmtInvoiceExisting = $pdo->prepare("UPDATE air_vendor_tagihan SET invoice_vendor_file = :invoice, updated_at = NOW() WHERE id = :id");
                    $stmtInvoiceExisting->execute([':invoice' => $invoiceVendorFile, ':id' => (int)$bill['id']]);
                    $bill['invoice_vendor_file'] = $invoiceVendorFile;
                }

                // Jika pemesanan belum pernah dibuatkan tagihan, buat tagihan otomatis
                // saat pembayaran agar pengguna tidak perlu melalui langkah terpisah.
                if (!$bill) {
                    $stmtOrderDetails->execute([':order_id' => $orderId]);
                    $details = $stmtOrderDetails->fetchAll(PDO::FETCH_ASSOC);
                    if (!$details) {
                        throw new RuntimeException('Pemesanan ' . (string)$order['nomor_vendor_order'] . ' belum memiliki jumlah pemesanan yang dapat ditagihkan.');
                    }

                    $nilaiTagihan = 0.0;
                    foreach ($details as $d) {
                        $nilaiTagihan += ((int)$d['jumlah_diterima']) * ((float)$d['harga_vendor']);
                    }
                    if ($nilaiTagihan <= 0) {
                        throw new RuntimeException('Nilai tagihan untuk ' . (string)$order['nomor_vendor_order'] . ' masih Rp 0. Lengkapi Harga Vendor pada Master Produk.');
                    }

                    $nomorTagihan = 'TV-AIR-' . date('Ymd') . '-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);
                    $stmtCreateBill->execute([
                        ':vendor_order_id' => $orderId,
                        ':nomor_tagihan' => $nomorTagihan,
                        ':invoice_vendor_file' => $invoiceVendorFile,
                        ':vendor_nama' => (string)$order['vendor_nama'],
                        ':tanggal_tagihan' => $tanggalBayar,
                        ':nilai_tagihan' => $nilaiTagihan,
                        ':catatan' => 'Tagihan dibuat otomatis saat pembayaran vendor',
                    ]);
                    $tagihanId = (int)$pdo->lastInsertId();

                    foreach ($details as $d) {
                        $subtotal = ((int)$d['jumlah_diterima']) * ((float)$d['harga_vendor']);
                        $stmtCreateDetail->execute([
                            ':tagihan_id' => $tagihanId,
                            ':produk_id' => $d['produk_id'] !== null ? (int)$d['produk_id'] : null,
                            ':nama_produk' => (string)$d['nama_produk'],
                            ':satuan' => (string)$d['satuan'],
                            ':jumlah_diterima' => (int)$d['jumlah_diterima'],
                            ':harga_vendor' => (float)$d['harga_vendor'],
                            ':subtotal' => $subtotal,
                        ]);
                    }

                    $bill = [
                        'id' => $tagihanId,
                        'nilai_tagihan' => $nilaiTagihan,
                        'total_dibayar' => 0,
                    ];
                }

                $sisa = max(0, (float)$bill['nilai_tagihan'] - (float)$bill['total_dibayar']);
                if ($sisa <= 0) {
                    continue;
                }

                $stmtPay->execute([
                    ':tagihan_id' => (int)$bill['id'],
                    ':tanggal_bayar' => $tanggalBayar,
                    ':nominal' => $sisa,
                    ':metode' => $metode,
                    ':referensi' => $referensi !== '' ? $referensi : null,
                    ':catatan' => $catatan !== '' ? $catatan : 'Pembayaran pemesanan vendor',
                    ':bukti' => $buktiPembayaran,
                    ':created_by' => $createdBy,
                ]);
                atv_refresh_status($pdo, (int)$bill['id']);
                $processed++;
                $totalPaidNow += $sisa;
            }

            if ($processed <= 0) {
                throw new RuntimeException('Semua pemesanan yang dipilih sudah lunas.');
            }

            $pdo->commit();
            $flash = $processed . ' pemesanan vendor berhasil dibayar dengan total ' . atv_rp($totalPaidNow) . '.';
        }

        if ($action === 'update_bill_info') {
            $tagihanId = (int)($_POST['tagihan_id'] ?? 0);
            $invoiceVendor = trim((string)($_POST['nomor_invoice_vendor'] ?? ''));
            $jatuhTempo = trim((string)($_POST['jatuh_tempo'] ?? ''));
            $catatan = trim((string)($_POST['catatan'] ?? ''));
            $stmt = $pdo->prepare("\n                UPDATE air_vendor_tagihan\n                SET nomor_invoice_vendor = :invoice, jatuh_tempo = :jatuh_tempo, catatan = :catatan, updated_at = NOW()\n                WHERE id = :id\n            ");
            $stmt->execute([
                ':invoice' => $invoiceVendor !== '' ? $invoiceVendor : null,
                ':jatuh_tempo' => $jatuhTempo !== '' ? $jatuhTempo : null,
                ':catatan' => $catatan,
                ':id' => $tagihanId,
            ]);
            $flash = 'Informasi tagihan vendor berhasil diperbarui.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['', 'belum_bayar', 'sebagian', 'lunas'];
if (!in_array($status, $allowedStatuses, true)) $status = '';
$perPage = (int)($_GET['per_page'] ?? 15);
if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$summary = [
    'total_tagihan' => 0,
    'nilai_tagihan' => 0,
    'total_dibayar' => 0,
    'sisa_hutang' => 0,
    'belum_bayar' => 0,
    'sebagian' => 0,
    'lunas' => 0,
    'belum_ditagihkan' => 0,
];
$rows = [];
$billableOrders = [];
$totalRows = 0;
$totalPages = 1;

try {
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*) AS total_tagihan,
            COALESCE(SUM(t.nilai_tagihan),0) AS nilai_tagihan,
            COALESCE(SUM(COALESCE(pay.dibayar,0)),0) AS total_dibayar,
            COALESCE(SUM(GREATEST(t.nilai_tagihan-COALESCE(pay.dibayar,0),0)),0) AS sisa_hutang,
            COALESCE(SUM(CASE WHEN COALESCE(pay.dibayar,0) <= 0 THEN 1 ELSE 0 END),0) AS belum_bayar,
            COALESCE(SUM(CASE WHEN COALESCE(pay.dibayar,0) > 0 AND COALESCE(pay.dibayar,0) < t.nilai_tagihan THEN 1 ELSE 0 END),0) AS sebagian,
            COALESCE(SUM(CASE WHEN t.nilai_tagihan > 0 AND COALESCE(pay.dibayar,0) >= t.nilai_tagihan THEN 1 ELSE 0 END),0) AS lunas
        FROM air_vendor_tagihan t
        LEFT JOIN (
            SELECT tagihan_id, COALESCE(SUM(nominal),0) AS dibayar
            FROM air_vendor_pembayaran
            GROUP BY tagihan_id
        ) pay ON pay.tagihan_id = t.id
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($summaryRow as $k => $v) {
        if (array_key_exists($k, $summary)) $summary[$k] = $v;
    }

    $summary['belum_ditagihkan'] = (int)$pdo->query("\n        SELECT COUNT(*)\n        FROM air_vendor_order vo\n        WHERE vo.status <> 'batal'\n          AND EXISTS (SELECT 1 FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id AND (CASE WHEN COALESCE(d.jumlah_diterima,0) > 0 THEN d.jumlah_diterima WHEN COALESCE(d.jumlah_dipesan,0) > 0 THEN d.jumlah_dipesan WHEN COALESCE(d.jumlah_dikonfirmasi,0) > 0 THEN d.jumlah_dikonfirmasi ELSE COALESCE(d.jumlah_diminta,0) END) > 0)\n          AND NOT EXISTS (SELECT 1 FROM air_vendor_tagihan t WHERE t.vendor_order_id = vo.id)\n    ")->fetchColumn();

    $billableOrders = $pdo->query("\n        SELECT vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.tanggal_kebutuhan, vo.status,\n               COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END)),0) AS total_diterima,\n               COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) * COALESCE(ap.harga_vendor,0)),0) AS estimasi_tagihan\n        FROM air_vendor_order vo\n        JOIN air_vendor_order_detail vd ON vd.vendor_order_id = vo.id\n        LEFT JOIN air_produk ap ON ap.id = vd.produk_id\n        WHERE vo.status <> 'batal'\n          AND (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) > 0\n          AND NOT EXISTS (SELECT 1 FROM air_vendor_tagihan t WHERE t.vendor_order_id = vo.id)\n        GROUP BY vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.tanggal_kebutuhan, vo.status\n        ORDER BY vo.tanggal_kebutuhan DESC, vo.id DESC\n        LIMIT 50\n    ")->fetchAll(PDO::FETCH_ASSOC);

    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(t.nomor_tagihan LIKE :q OR t.nomor_invoice_vendor LIKE :q OR t.vendor_nama LIKE :q OR vo.nomor_vendor_order LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($status !== '') {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM air_vendor_tagihan t JOIN air_vendor_order vo ON vo.id=t.vendor_order_id WHERE $whereSql");
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("\n        SELECT t.*, vo.nomor_vendor_order, vo.tanggal_kebutuhan,\n               COALESCE((SELECT SUM((CASE WHEN COALESCE(d.jumlah_diterima,0) > 0 THEN d.jumlah_diterima WHEN COALESCE(d.jumlah_dipesan,0) > 0 THEN d.jumlah_dipesan WHEN COALESCE(d.jumlah_dikonfirmasi,0) > 0 THEN d.jumlah_dikonfirmasi ELSE COALESCE(d.jumlah_diminta,0) END)) FROM air_vendor_order_detail d WHERE d.vendor_order_id=vo.id),0) AS total_unit,\n               GREATEST(t.nilai_tagihan-t.total_dibayar,0) AS sisa_tagihan\n        FROM air_vendor_tagihan t\n        JOIN air_vendor_order vo ON vo.id=t.vendor_order_id\n        WHERE $whereSql\n        ORDER BY t.tanggal_tagihan DESC, t.id DESC\n        LIMIT :limit OFFSET :offset\n    ");
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $detailStmt = $pdo->prepare("SELECT * FROM air_vendor_tagihan_detail WHERE tagihan_id=:id ORDER BY id ASC");
    $payStmt = $pdo->prepare("SELECT * FROM air_vendor_pembayaran WHERE tagihan_id=:id ORDER BY tanggal_bayar DESC, id DESC");
    foreach ($rows as &$row) {
        $detailStmt->execute([':id' => (int)$row['id']]);
        $row['details'] = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
        $payStmt->execute([':id' => (int)$row['id']]);
        $row['payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($row);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat tagihan vendor: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$payableOrders = [];
try {
    $payableOrders = $pdo->query("\n        SELECT\n            vo.id AS vendor_order_id,\n            vo.nomor_vendor_order,\n            vo.vendor_nama,\n            vo.tanggal_kebutuhan,\n            COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END)),0) AS total_diterima,\n            COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) * COALESCE(ap.harga_vendor,0)),0) AS nilai_order,\n            t.id AS tagihan_id,\n            t.nomor_tagihan,\n            t.tanggal_tagihan,\n            COALESCE(t.nilai_tagihan, COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) * COALESCE(ap.harga_vendor,0)),0)) AS nilai_tagihan,\n            COALESCE(t.total_dibayar,0) AS total_dibayar,\n            GREATEST(\n                COALESCE(t.nilai_tagihan, COALESCE(SUM((CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) * COALESCE(ap.harga_vendor,0)),0)) - COALESCE(t.total_dibayar,0),\n                0\n            ) AS sisa_tagihan,\n            CASE\n                WHEN t.id IS NULL THEN 'belum_bayar'\n                ELSE t.status\n            END AS status_pembayaran,\n            CASE\n                WHEN SUM(CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN 1 ELSE 0 END) > 0 THEN 'penerimaan'\n                ELSE 'data_lama'\n            END AS sumber_jumlah\n        FROM air_vendor_order vo\n        JOIN air_vendor_order_detail vd ON vd.vendor_order_id = vo.id\n        LEFT JOIN air_produk ap ON ap.id = vd.produk_id\n        LEFT JOIN air_vendor_tagihan t ON t.vendor_order_id = vo.id\n        WHERE vo.status <> 'batal'\n          AND (CASE WHEN COALESCE(vd.jumlah_diterima,0) > 0 THEN vd.jumlah_diterima WHEN COALESCE(vd.jumlah_dipesan,0) > 0 THEN vd.jumlah_dipesan WHEN COALESCE(vd.jumlah_dikonfirmasi,0) > 0 THEN vd.jumlah_dikonfirmasi ELSE COALESCE(vd.jumlah_diminta,0) END) > 0\n        GROUP BY\n            vo.id, vo.nomor_vendor_order, vo.vendor_nama, vo.tanggal_kebutuhan,\n            t.id, t.nomor_tagihan, t.tanggal_tagihan, t.nilai_tagihan, t.total_dibayar, t.status\n        HAVING sisa_tagihan > 0\n        ORDER BY vo.vendor_nama ASC, vo.tanggal_kebutuhan ASC, vo.id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $payableOrders = [];
}

$historicalPayableOrders = [];
$knownVendorNames = [];
try {
    try {
        $knownVendorNames = $pdo->query("\n            SELECT DISTINCT TRIM(vendor_nama)\n            FROM air_vendor_order\n            WHERE TRIM(COALESCE(vendor_nama,'')) <> ''\n            ORDER BY vendor_nama ASC\n        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $knownVendorNames = [];
    }

    /*
     * DATA LAMA / SEBELUM APLIKASI
     * ---------------------------------------------
     * Jangan mensyaratkan air_vendor_order, air_vendor_order_source,
     * air_pesanan_detail, is_historical, atau sumber_data untuk MENAMPILKAN data.
     * Nomor AIR-LAMA-* adalah sumber kebenaran utama untuk data historis manual.
     * Detail/nominal hanya diperkaya bila tabel terkait tersedia.
     */
    $tanggalPemesananSelect = atv_column_exists($pdo, 'air_pesanan', 'tanggal_pemesanan')
        ? 'p.tanggal_pemesanan'
        : 'NULL';
    $tanggalKirimSelect = atv_column_exists($pdo, 'air_pesanan', 'tanggal_kirim')
        ? 'p.tanggal_kirim'
        : 'NULL';
    $pelangganIdSelect = atv_column_exists($pdo, 'air_pesanan', 'pelanggan_id')
        ? 'p.pelanggan_id'
        : 'NULL';

    $stmtHistBase = $pdo->query("\n        SELECT\n            p.id AS pesanan_id,\n            p.nomor_pesanan,\n            {$tanggalPemesananSelect} AS tanggal_pemesanan,\n            {$tanggalKirimSelect} AS tanggal_kirim,\n            {$pelangganIdSelect} AS pelanggan_id\n        FROM air_pesanan p\n        WHERE UPPER(TRIM(COALESCE(p.nomor_pesanan,''))) LIKE 'AIR-LAMA-%'\n          AND LOWER(TRIM(COALESCE(p.status,''))) <> 'batal'\n        ORDER BY COALESCE({$tanggalKirimSelect}, {$tanggalPemesananSelect}, DATE(p.created_at)) ASC, p.id ASC\n    ");
    $histBaseRows = $stmtHistBase->fetchAll(PDO::FETCH_ASSOC);

    $stmtCustomerName = null;
    try {
        $stmtCustomerName = $pdo->prepare("SELECT nama FROM air_pelanggan WHERE id=:id LIMIT 1");
    } catch (Throwable $e) {
        $stmtCustomerName = null;
    }

    $stmtHistAmounts = null;
    try {
        $stmtHistAmounts = $pdo->prepare("\n            SELECT\n                COALESCE(SUM(COALESCE(d.qty,0)),0) AS total_unit,\n                COUNT(d.id) AS detail_count,\n                COALESCE(SUM(COALESCE(d.qty,0) * COALESCE(ap.harga_vendor,0)),0) AS nilai_vendor\n            FROM air_pesanan_detail d\n            LEFT JOIN air_produk ap ON ap.id=d.produk_id\n            WHERE d.pesanan_id=:pesanan_id\n        ");
    } catch (Throwable $e) {
        $stmtHistAmounts = null;
    }

    $stmtPaidCheck = null;
    try {
        $stmtPaidCheck = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM air_vendor_order_source vos\n            INNER JOIN air_vendor_order vo ON vo.id=vos.vendor_order_id\n            INNER JOIN air_vendor_tagihan t ON t.vendor_order_id=vo.id\n            WHERE vos.pesanan_id=:pesanan_id\n              AND t.status='lunas'\n              AND COALESCE(t.total_dibayar,0) >= COALESCE(t.nilai_tagihan,0)\n        ");
    } catch (Throwable $e) {
        $stmtPaidCheck = null;
    }

    foreach ($histBaseRows as $hb) {
        $pesananId = (int)$hb['pesanan_id'];

        // Hanya sembunyikan bila memang sudah mempunyai tagihan vendor yang LUNAS.
        if ($stmtPaidCheck) {
            try {
                $stmtPaidCheck->execute([':pesanan_id' => $pesananId]);
                if ((int)$stmtPaidCheck->fetchColumn() > 0) {
                    continue;
                }
            } catch (Throwable $e) {
                // Jika pengecekan relasi lama gagal, jangan hilangkan data AIR-LAMA.
            }
        }

        $namaPemesan = 'Data Lama';
        if ($stmtCustomerName && !empty($hb['pelanggan_id'])) {
            try {
                $stmtCustomerName->execute([':id' => (int)$hb['pelanggan_id']]);
                $cn = trim((string)$stmtCustomerName->fetchColumn());
                if ($cn !== '') $namaPemesan = $cn;
            } catch (Throwable $e) {
            }
        }

        $totalUnit = 0;
        $detailCount = 0;
        $nilaiVendor = 0.0;
        if ($stmtHistAmounts) {
            try {
                $stmtHistAmounts->execute([':pesanan_id' => $pesananId]);
                $ha = $stmtHistAmounts->fetch(PDO::FETCH_ASSOC) ?: [];
                $totalUnit = (int)($ha['total_unit'] ?? 0);
                $detailCount = (int)($ha['detail_count'] ?? 0);
                $nilaiVendor = (float)($ha['nilai_vendor'] ?? 0);
            } catch (Throwable $e) {
            }
        }

        $historicalPayableOrders[] = [
            'pesanan_id' => $pesananId,
            'nomor_pesanan' => (string)$hb['nomor_pesanan'],
            'tanggal_pemesanan' => $hb['tanggal_pemesanan'] ?? null,
            'tanggal_kirim' => $hb['tanggal_kirim'] ?? null,
            'nama_pemesan' => $namaPemesan,
            'total_unit' => $totalUnit,
            'detail_count' => $detailCount,
            'nilai_vendor' => $nilaiVendor,
        ];
    }
} catch (Throwable $e) {
    // Jangan membuat halaman gagal total. Tampilkan pesan diagnostik agar masalah tidak tersembunyi.
    if ($flash === '') {
        $flash = 'Data lama belum dapat dimuat: ' . $e->getMessage();
        $flashType = 'error';
    }
    $historicalPayableOrders = [];
}


/*
 * RINGKASAN KEWAJIBAN VENDOR YANG MASIH BELUM LUNAS
 * --------------------------------------------------
 * Card di bagian atas tidak hanya membaca air_vendor_tagihan yang sudah dibuat.
 * Semua pemesanan vendor yang masih mempunyai sisa kewajiban ikut dihitung,
 * termasuk AIR-LAMA-* yang belum pernah masuk rekap vendor.
 *
 * Makna card setelah perhitungan ini:
 * - Total Tagihan   : jumlah kewajiban/pemesanan yang masih belum lunas.
 * - Nilai Tagihan   : nilai bruto seluruh kewajiban yang masih belum lunas.
 * - Sudah Dibayar   : pembayaran yang sudah masuk untuk kewajiban tersebut.
 * - Sisa Hutang     : nilai yang masih harus dibayar.
 * - Belum Bayar     : kewajiban yang belum mempunyai pembayaran sama sekali.
 * - Sebagian        : kewajiban yang sudah dibayar sebagian.
 * - Belum Ditagihkan: kewajiban yang belum mempunyai row air_vendor_tagihan,
 *                     termasuk data historis AIR-LAMA-* yang belum direkap.
 */
try {
    $derivedSummary = [
        'total_tagihan' => 0,
        'nilai_tagihan' => 0.0,
        'total_dibayar' => 0.0,
        'sisa_hutang' => 0.0,
        'belum_bayar' => 0,
        'sebagian' => 0,
        'lunas' => 0,
        'belum_ditagihkan' => 0,
    ];

    // Pesanan yang sudah mempunyai rekap vendor aktif tidak boleh dihitung lagi
    // pada kelompok AIR-LAMA, karena kewajibannya sudah terwakili di $payableOrders.
    $linkedHistoricalIds = [];
    try {
        $linkedRows = $pdo->query("\n            SELECT DISTINCT vos.pesanan_id\n            FROM air_vendor_order_source vos\n            INNER JOIN air_vendor_order vo ON vo.id = vos.vendor_order_id\n            WHERE vo.status <> 'batal'\n        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($linkedRows as $linkedId) {
            $linkedHistoricalIds[(int)$linkedId] = true;
        }
    } catch (Throwable $e) {
        $linkedHistoricalIds = [];
    }

    foreach ($payableOrders as $po) {
        $nilai = max(0, (float)($po['nilai_tagihan'] ?? 0));
        $dibayar = max(0, (float)($po['total_dibayar'] ?? 0));
        $sisa = max(0, (float)($po['sisa_tagihan'] ?? ($nilai - $dibayar)));

        if ($sisa <= 0) {
            continue;
        }

        $derivedSummary['total_tagihan']++;
        $derivedSummary['nilai_tagihan'] += $nilai;
        $derivedSummary['total_dibayar'] += min($dibayar, $nilai > 0 ? $nilai : $dibayar);
        $derivedSummary['sisa_hutang'] += $sisa;

        if ($dibayar <= 0) {
            $derivedSummary['belum_bayar']++;
        } else {
            $derivedSummary['sebagian']++;
        }

        if (empty($po['tagihan_id'])) {
            $derivedSummary['belum_ditagihkan']++;
        }
    }

    foreach ($historicalPayableOrders as $hp) {
        $pesananIdHist = (int)($hp['pesanan_id'] ?? 0);

        // Hindari double count jika AIR-LAMA sudah pernah masuk rekap vendor aktif.
        if ($pesananIdHist > 0 && isset($linkedHistoricalIds[$pesananIdHist])) {
            continue;
        }

        $nilaiHist = max(0, (float)($hp['nilai_vendor'] ?? 0));

        // Data lama yang belum direkap tetap merupakan kewajiban meskipun nilai
        // historisnya belum dapat dihitung otomatis. Nominalnya nanti dapat diisi
        // saat pembayaran; jumlah kewajibannya tetap harus terlihat pada card.
        $derivedSummary['total_tagihan']++;
        $derivedSummary['nilai_tagihan'] += $nilaiHist;
        $derivedSummary['sisa_hutang'] += $nilaiHist;
        $derivedSummary['belum_bayar']++;
        $derivedSummary['belum_ditagihkan']++;
    }

    // Gunakan ringkasan berbasis kewajiban yang belum lunas sebagai sumber card.
    $summary = $derivedSummary;
} catch (Throwable $e) {
    // Bila kalkulasi tambahan gagal, pertahankan ringkasan database sebelumnya
    // agar halaman tetap dapat digunakan.
    error_log('AIR TAGIHAN VENDOR SUMMARY OUTSTANDING ERROR: ' . $e->getMessage());
}

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tagihan Vendor Air Mineral</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111827;
        }

        .air-tagihan-main {
            max-width: 1680px;
        }

        .card {
            background: #fff;
            border: 1px solid #ececec;
            border-radius: 2px;
            box-shadow: none;
        }

        .summary-card {
            min-height: 98px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .field {
            width: 100%;
            height: 42px;
            border: 1px solid #e5e7eb;
            border-radius: 2px;
            background: #fff;
            padding: 0 12px;
            font-size: 12px;
            color: #111827;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .field::placeholder {
            color: #9ca3af;
        }

        .field-search {
            padding-left: 42px !important;
        }

        textarea.field {
            height: auto;
            min-height: 88px;
            padding-top: 11px;
            padding-bottom: 11px;
            resize: vertical;
        }

        .field:focus {
            outline: none;
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, .06);
        }


        .upload-doc-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .upload-doc-card {
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 14px;
            min-width: 0;
        }

        .upload-doc-card.invoice {
            border-color: #fde68a;
            background: #fffbeb;
        }

        .upload-doc-head {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .upload-doc-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .upload-doc-card.invoice .upload-doc-icon {
            border-color: #fde68a;
            background: #fff;
            color: #b45309;
        }

        .upload-doc-title {
            font-size: 9px;
            line-height: 1.35;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .055em;
            color: #374151;
        }

        .upload-doc-card.invoice .upload-doc-title {
            color: #b45309;
        }

        .upload-doc-subtitle {
            margin-top: 3px;
            font-size: 9px;
            line-height: 1.5;
            color: #9ca3af;
        }

        .upload-doc-card.invoice .upload-doc-subtitle {
            color: #b45309;
        }

        .upload-file-input {
            width: 100%;
            min-height: 44px;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 5px;
            font-size: 10px;
            color: #6b7280;
            cursor: pointer;
        }

        .upload-file-input::file-selector-button {
            min-height: 32px;
            margin-right: 10px;
            padding: 0 12px;
            border: 0;
            background: #111827;
            color: #fff;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            cursor: pointer;
        }

        .upload-file-input::-webkit-file-upload-button {
            min-height: 32px;
            margin-right: 10px;
            padding: 0 12px;
            border: 0;
            background: #111827;
            color: #fff;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            cursor: pointer;
        }

        .upload-doc-help {
            margin-top: 8px;
            font-size: 9px;
            line-height: 1.55;
            color: #9ca3af;
        }

        .upload-doc-card.invoice .upload-doc-help {
            color: #92400e;
        }

        @media (max-width: 767px) {
            .upload-doc-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .upload-doc-card {
                padding: 12px;
            }

            .upload-file-input {
                font-size: 9px;
            }

            .upload-file-input::file-selector-button,
            .upload-file-input::-webkit-file-upload-button {
                margin-right: 7px;
                padding: 0 10px;
            }
        }

        .search-icon-wrap {
            position: absolute;
            inset: 0 auto 0 0;
            width: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            color: #9ca3af;
        }

        .btn {
            min-height: 36px;
            padding: 0 12px;
            border: 1px solid #dedede;
            border-radius: 2px;
            background: #fff;
            color: #111827;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 9px;
            line-height: 1;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .055em;
            white-space: nowrap;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }

        .btn:hover {
            background: #f7f7f7;
            border-color: #cfcfcf;
        }

        .btn-dark {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .btn-dark:hover {
            background: #27272a;
            border-color: #27272a;
        }

        .btn-icon {
            width: 36px;
            padding: 0;
        }

        .table-shell {
            border: 1px solid #ececec;
            background: #fff;
        }

        .data-table th {
            background: #fafafa;
            color: #9ca3af;
            font-size: 9px;
            line-height: 1.2;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .075em;
            white-space: nowrap;
            border-bottom: 1px solid #ececec;
        }

        .data-table td {
            vertical-align: middle;
        }

        .data-table tbody tr {
            transition: background .12s ease;
        }

        .data-table tbody tr:hover {
            background: #fcfcfc;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 4px 9px;
            border: 1px solid;
            border-radius: 999px;
            font-size: 8px;
            line-height: 1;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .055em;
            white-space: nowrap;
        }

        .mobile-card {
            border: 1px solid #ececec;
            background: #fff;
            padding: 15px;
        }

        .modal-wrap {
            background: rgba(17, 24, 39, .46);
            backdrop-filter: blur(2px);
        }

        .modal-panel {
            border-radius: 3px;
            box-shadow: 0 22px 55px rgba(0, 0, 0, .13);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 20px;
            line-height: 1;
        }

        .modal-close:hover {
            background: #f7f7f7;
        }

        .pagination-bar {
            background: #fff;
            border: 1px solid #ececec;
            border-top: 0;
            padding: 12px 14px;
        }

        .page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #e5e7eb;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
        }

        .page-btn:hover {
            background: #f7f7f7;
        }

        .page-btn.active {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .page-btn.disabled {
            color: #d1d5db;
            pointer-events: none;
            background: #fafafa;
        }

        @media(max-width:1023px) {
            .desktop-only {
                display: none;
            }

            .mobile-only {
                display: grid;
            }
        }

        @media(min-width:1024px) {
            .desktop-only {
                display: block;
            }

            .mobile-only {
                display: none;
            }
        }

        @media(max-width:767px) {
            .air-tagihan-main {
                padding: 16px 12px 28px !important;
            }

            .summary-card {
                min-height: 88px;
                padding: 13px !important;
            }

            .summary-money {
                font-size: 14px !important;
                line-height: 1.35;
                word-break: break-word;
            }

            .btn {
                min-height: 38px;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .modal-wrap {
                padding: 0 !important;
                align-items: flex-end !important;
            }

            .modal-panel {
                width: 100%;
                max-height: 92vh;
                border-left: 0 !important;
                border-right: 0 !important;
                border-bottom: 0 !important;
                overflow-y: auto;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen">
    <main class="air-main air-tagihan-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700' ?>"><?= atv_h($flash) ?></div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Air Mineral / Keuangan Vendor</p>
                <h1 class="text-2xl font-semibold mt-1">Tagihan Vendor</h1>
                <p class="text-xs text-gray-400 mt-1">Pantau nilai tagihan, pembayaran koperasi, dan sisa hutang vendor berdasarkan barang yang benar-benar diterima.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-dark" onclick="openBulkPayment()"><i data-lucide="wallet-cards" class="w-4 h-4"></i> Buat Pembayaran Vendor</button>
                <a href="air_rekap_vendor.php" class="btn"><i data-lucide="clipboard-list" class="w-4 h-4"></i> Rekap Vendor</a>
                <a href="air_produk.php" class="btn"><i data-lucide="package" class="w-4 h-4"></i> Harga Vendor</a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400">Total Tagihan</p>
                <p class="text-2xl font-bold mt-2"><?= number_format((int)$summary['total_tagihan']) ?></p>
                <p class="text-[9px] text-gray-400 mt-1">Tagihan tersimpan di database</p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-blue-600">Nilai Tagihan</p>
                <p class="summary-money text-lg font-bold text-blue-700 mt-2"><?= atv_rp($summary['nilai_tagihan']) ?></p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-green-600">Sudah Dibayar</p>
                <p class="summary-money text-lg font-bold text-green-700 mt-2"><?= atv_rp($summary['total_dibayar']) ?></p>
                <p class="text-[9px] text-gray-400 mt-1">Dari riwayat pembayaran</p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-red-600">Sisa Hutang</p>
                <p class="summary-money text-lg font-bold text-red-700 mt-2"><?= atv_rp($summary['sisa_hutang']) ?></p>
                <p class="text-[9px] text-gray-400 mt-1">Tagihan dikurangi pembayaran</p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-orange-600">Belum Bayar</p>
                <p class="text-2xl font-bold text-orange-700 mt-2"><?= number_format((int)$summary['belum_bayar']) ?></p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-purple-600">Sebagian</p>
                <p class="text-2xl font-bold text-purple-700 mt-2"><?= number_format((int)$summary['sebagian']) ?></p>
            </div>
            <div class="card summary-card p-4">
                <p class="text-[9px] font-bold uppercase tracking-widest text-amber-600">Belum Ditagihkan</p>
                <p class="text-2xl font-bold text-amber-700 mt-2"><?= number_format((int)$summary['belum_ditagihkan']) ?></p>
            </div>
        </div>


        <section class="card p-4 md:p-5 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Pencarian & Filter</p>
                    <p class="text-xs text-gray-400 mt-1">Temukan tagihan berdasarkan nomor tagihan, invoice, vendor, atau nomor rekap.</p>
                </div>
                <p class="text-[10px] font-bold text-gray-500"><?= number_format($totalRows) ?> data</p>
            </div>
            <form method="get" class="grid grid-cols-1 md:grid-cols-[minmax(280px,1fr)_190px_120px_auto] gap-2 items-end">
                <div><label class="block text-[9px] font-bold uppercase tracking-widest text-gray-400 mb-2">Pencarian</label>
                    <div class="relative"><span class="search-icon-wrap"><i data-lucide="search" class="w-4 h-4"></i></span><input class="field field-search" name="q" value="<?= atv_h($q) ?>" placeholder="Cari tagihan, invoice, vendor, atau nomor rekap"></div>
                </div>
                <div><label class="block text-[9px] font-bold uppercase tracking-widest text-gray-400 mb-2">Status</label><select name="status" class="field">
                        <option value="">Semua Status</option>
                        <option value="belum_bayar" <?= $status === 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                        <option value="sebagian" <?= $status === 'sebagian' ? 'selected' : '' ?>>Sebagian</option>
                        <option value="lunas" <?= $status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                    </select></div>
                <div><label class="block text-[9px] font-bold uppercase tracking-widest text-gray-400 mb-2">Per Halaman</label><select name="per_page" class="field"><?php foreach ([10, 15, 25, 50] as $pp): ?><option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?></select></div>
                <div class="filter-actions flex gap-2"><button class="btn btn-dark flex-1 md:flex-none"><i data-lucide="search" class="w-3.5 h-3.5"></i> Terapkan</button><a href="air_tagihan_vendor.php" class="btn flex-1 md:flex-none"><i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> Reset</a></div>
            </form>
        </section>

        <section class="table-shell overflow-hidden">
            <div class="px-4 md:px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Daftar Tagihan</p>
                    <h2 class="text-sm font-bold mt-1">Monitoring Pembayaran Vendor</h2>
                </div>
                <span class="text-[9px] text-gray-400">Urut terbaru</span>
            </div>
            <div class="desktop-only overflow-x-auto">
                <table class="data-table w-full min-w-[1180px] text-left">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Tagihan</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Vendor</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Rekap Vendor</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-center">Unit</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-right">Nilai Tagihan</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-right">Dibayar</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-right">Sisa</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400 text-center">Status</th>
                            <th class="px-4 py-3 text-[9px] uppercase text-gray-400">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!$rows): ?><tr>
                                <td colspan="9" class="py-16 text-center text-xs text-gray-400">Belum ada tagihan vendor.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $badge = $r['status'] === 'lunas' ? 'border-green-200 bg-green-50 text-green-700' : ($r['status'] === 'sebagian' ? 'border-purple-200 bg-purple-50 text-purple-700' : 'border-orange-200 bg-orange-50 text-orange-700'); ?>
                            <tr>
                                <td class="px-4 py-4">
                                    <p class="text-xs font-bold"><?= atv_h($r['nomor_tagihan']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1"><?= atv_date($r['tanggal_tagihan']) ?><?= $r['nomor_invoice_vendor'] ? ' · Inv: ' . atv_h($r['nomor_invoice_vendor']) : '' ?></p>
                                </td>
                                <td class="px-4 py-4 text-xs font-semibold"><?= atv_h($r['vendor_nama']) ?></td>
                                <td class="px-4 py-4">
                                    <p class="text-xs font-semibold"><?= atv_h($r['nomor_vendor_order']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Kebutuhan <?= atv_date($r['tanggal_kebutuhan']) ?></p>
                                </td>
                                <td class="px-4 py-4 text-center font-bold"><?= number_format((int)$r['total_unit']) ?></td>
                                <td class="px-4 py-4 text-right font-bold"><?= atv_rp($r['nilai_tagihan']) ?></td>
                                <td class="px-4 py-4 text-right font-bold text-green-700"><?= atv_rp($r['total_dibayar']) ?></td>
                                <td class="px-4 py-4 text-right font-bold text-red-700"><?= atv_rp($r['sisa_tagihan']) ?></td>
                                <td class="px-4 py-4 text-center"><span class="status-badge <?= $badge ?>"><?= atv_h($r['status'] === 'belum_bayar' ? 'Belum Bayar' : ucfirst($r['status'])) ?></span></td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5"><button type="button" class="btn" onclick='openDetail(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="eye" class="w-3.5 h-3.5"></i> Detail</button><?php if ($r['status'] !== 'lunas'): ?><button type="button" class="btn btn-dark" onclick='openPayment(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="wallet-cards" class="w-3.5 h-3.5"></i> Bayar</button><?php endif; ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mobile-only grid-cols-1 md:grid-cols-2 gap-3 p-3">
                <?php if (!$rows): ?>
                    <div class="md:col-span-2 border border-dashed border-gray-200 bg-gray-50 p-8 text-center">
                        <i data-lucide="receipt-text" class="w-6 h-6 text-gray-300 mx-auto"></i>
                        <p class="text-xs font-bold mt-3">Belum ada tagihan vendor</p>
                        <p class="text-[10px] text-gray-400 mt-1">Tagihan yang sudah dibuat akan tampil di sini.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php $badge = $r['status'] === 'lunas' ? 'border-green-200 bg-green-50 text-green-700' : ($r['status'] === 'sebagian' ? 'border-purple-200 bg-purple-50 text-purple-700' : 'border-orange-200 bg-orange-50 text-orange-700'); ?>
                    <div class="mobile-card">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold"><?= atv_h($r['nomor_tagihan']) ?></p>
                                <p class="text-[10px] text-gray-400 mt-1"><?= atv_h($r['vendor_nama']) ?></p>
                            </div><span class="status-badge <?= $badge ?>"><?= atv_h($r['status'] === 'belum_bayar' ? 'Belum Bayar' : ucfirst($r['status'])) ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-4">
                            <div>
                                <p class="text-[8px] uppercase font-bold text-gray-400">Tagihan</p>
                                <p class="text-sm font-bold mt-1"><?= atv_rp($r['nilai_tagihan']) ?></p>
                            </div>
                            <div>
                                <p class="text-[8px] uppercase font-bold text-gray-400">Sisa</p>
                                <p class="text-sm font-bold text-red-700 mt-1"><?= atv_rp($r['sisa_tagihan']) ?></p>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-4"><button class="btn flex-1" onclick='openDetail(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Detail</button><?php if ($r['status'] !== 'lunas'): ?><button class="btn flex-1 bg-black text-white border-black" onclick='openPayment(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Bayar</button><?php endif; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="pagination-bar flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <p class="text-[10px] text-gray-400">
                Menampilkan <strong class="text-gray-700"><?= number_format($totalRows ? (($page - 1) * $perPage + 1) : 0) ?>–<?= number_format(min($page * $perPage, $totalRows)) ?></strong>
                dari <strong class="text-gray-700"><?= number_format($totalRows) ?></strong> tagihan
            </p>
            <?php $base = $_GET; ?>
            <div class="flex items-center gap-1 overflow-x-auto no-scrollbar">
                <?php $base['page'] = max(1, $page - 1); ?>
                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= atv_h(http_build_query($base)) ?>" aria-label="Halaman sebelumnya">
                    <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
                </a>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): $base['page'] = $i; ?>
                    <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="?<?= atv_h(http_build_query($base)) ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php $base['page'] = min($totalPages, $page + 1); ?>
                <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= atv_h(http_build_query($base)) ?>" aria-label="Halaman berikutnya">
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>
        </div>
    </main>

    <div id="createBillModal" class="modal-wrap fixed inset-0 z-[120] hidden items-center justify-center p-3">
        <div class="modal-panel bg-white w-full max-w-xl border border-gray-200">
            <div class="px-5 py-4 border-b flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] uppercase font-bold text-gray-400">Buat Tagihan Vendor</p>
                    <h2 id="createBillTitle" class="text-base font-bold mt-1">-</h2>
                </div><button type="button" class="modal-close" onclick="closeModal('createBillModal')" aria-label="Tutup">&times;</button>
            </div>
            <form method="post"><input type="hidden" name="action" value="create_bill"><input type="hidden" name="vendor_order_id" id="createBillOrderId">
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Tanggal Tagihan</label><input type="date" name="tanggal_tagihan" value="<?= date('Y-m-d') ?>" class="field mt-2" required></div>
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Jatuh Tempo</label><input type="date" name="jatuh_tempo" class="field mt-2"></div>
                    </div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Nomor Invoice Vendor</label><input name="nomor_invoice_vendor" class="field mt-2" placeholder="Opsional"></div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Catatan</label><textarea name="catatan" class="field mt-2" placeholder="Opsional"></textarea></div>
                    <div class="border border-blue-100 bg-blue-50 p-3 text-xs text-blue-700">Nilai tagihan otomatis dihitung dari <strong>jumlah barang diterima × harga vendor</strong> pada master produk.</div>
                </div>
                <div class="p-5 border-t flex gap-2"><button type="button" class="btn flex-1" onclick="closeModal('createBillModal')">Batal</button><button class="btn flex-1 bg-black text-white border-black">Simpan Tagihan</button></div>
            </form>
        </div>
    </div>

    <div id="paymentModal" class="modal-wrap fixed inset-0 z-[120] hidden items-center justify-center p-3">
        <div class="modal-panel bg-white w-full max-w-xl border border-gray-200">
            <div class="px-5 py-4 border-b flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] uppercase font-bold text-gray-400">Pembayaran Vendor</p>
                    <h2 id="paymentTitle" class="text-base font-bold mt-1">-</h2>
                </div><button type="button" class="modal-close" onclick="closeModal('paymentModal')" aria-label="Tutup">&times;</button>
            </div>
            <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="add_payment"><input type="hidden" name="tagihan_id" id="paymentBillId">
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="border bg-gray-50 p-3">
                            <p class="text-[8px] uppercase font-bold text-gray-400">Nilai Tagihan</p>
                            <p id="paymentBillAmount" class="text-base font-bold mt-1">-</p>
                        </div>
                        <div class="border bg-gray-50 p-3">
                            <p class="text-[8px] uppercase font-bold text-gray-400">Sisa</p>
                            <p id="paymentRemaining" class="text-base font-bold text-red-700 mt-1">-</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Tanggal Bayar</label><input type="date" name="tanggal_bayar" value="<?= date('Y-m-d') ?>" class="field mt-2" required></div>
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Nominal</label><input type="number" min="1" step="1" name="nominal" id="paymentNominal" class="field mt-2" required></div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Metode</label><select name="metode" class="field mt-2">
                                <option>Transfer</option>
                                <option>Tunai</option>
                                <option>Virtual Account</option>
                                <option>Lainnya</option>
                            </select></div>
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Nomor Referensi</label><input name="nomor_referensi" class="field mt-2" placeholder="Opsional"></div>
                    </div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Bukti Pembayaran</label><input type="file" name="bukti_pembayaran" accept="image/jpeg,image/png,image/webp,application/pdf" class="field mt-2 py-2">
                        <p class="text-[9px] text-gray-400 mt-1">JPG, PNG, WebP, atau PDF · maksimal 8 MB</p>
                    </div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Catatan</label><textarea name="catatan" class="field mt-2"></textarea></div>
                </div>
                <div class="p-5 border-t flex gap-2"><button type="button" class="btn flex-1" onclick="closeModal('paymentModal')">Batal</button><button class="btn flex-1 bg-black text-white border-black">Simpan Pembayaran</button></div>
            </form>
        </div>
    </div>

    <div id="bulkPaymentModal" class="modal-wrap fixed inset-0 z-[125] hidden items-center justify-center p-3">
        <div class="modal-panel bg-white w-full max-w-4xl border border-gray-200 max-h-[94vh] overflow-y-auto">
            <div class="px-5 py-4 border-b sticky top-0 bg-white z-10 flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] uppercase font-bold text-gray-400">Pembayaran Vendor</p>
                    <h2 class="text-base font-bold mt-1">Buat Pembayaran Vendor</h2>
                    <p class="text-[10px] text-gray-400 mt-1">Pilih satu atau beberapa pemesanan yang belum dibayar dari vendor yang sama.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('bulkPaymentModal')" aria-label="Tutup">&times;</button>
            </div>
            <form method="post" id="bulkPaymentForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_payment">
                <div class="p-5 space-y-5">
                    <div class="border border-gray-200">
                        <div class="p-3 border-b bg-gray-50 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[9px] uppercase font-bold text-gray-400">Pemesanan Belum Dibayar</p>
                                <p id="bulkVendorHint" class="text-[10px] text-gray-500 mt-1">Pilih pemesanan dari satu vendor. Data lama AIR-LAMA yang belum pernah masuk rekap vendor aktif muncul langsung pada daftar ini.</p>
                            </div>
                            <label class="text-[10px] font-bold cursor-pointer"><input type="checkbox" id="modalCheckAll" class="accent-black" onchange="toggleModalAll(this.checked)"> Pilih Semua Vendor Ini</label>
                        </div>
                        <div class="divide-y max-h-72 overflow-y-auto">
                            <?php if (!$payableOrders && !$historicalPayableOrders): ?>
                                <div class="p-8 text-center text-xs text-gray-400">Tidak ada pemesanan vendor yang belum dibayar.</div>
                            <?php endif; ?>
                            <?php foreach ($payableOrders as $pb): ?>
                                <label class="payment-source flex items-center gap-3 p-3 hover:bg-gray-50 cursor-pointer" data-vendor="<?= atv_h($pb['vendor_nama']) ?>">
                                    <input type="checkbox" name="vendor_order_ids[]" value="<?= (int)$pb['vendor_order_id'] ?>" class="modal-bill-check accent-black" data-vendor="<?= atv_h($pb['vendor_nama']) ?>" data-remaining="<?= (float)$pb['sisa_tagihan'] ?>" data-bill="<?= atv_h($pb['nomor_vendor_order']) ?>" onchange="updatePaymentPreview(this)">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                            <p class="text-xs font-bold"><?= atv_h($pb['nomor_vendor_order']) ?></p>
                                            <p class="text-xs font-bold text-red-700"><?= atv_rp($pb['sisa_tagihan']) ?></p>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1"><?= atv_h($pb['vendor_nama']) ?> · Kebutuhan <?= atv_date($pb['tanggal_kebutuhan']) ?><?php if (($pb['sumber_jumlah'] ?? '') === 'data_lama'): ?> · <span class="font-bold text-amber-600">Data Lama</span><?php endif; ?> · <?= $pb['status_pembayaran'] === 'sebagian' ? 'Sudah dibayar sebagian ' . atv_rp($pb['total_dibayar']) : ($pb['tagihan_id'] ? 'Belum dibayar' : 'Belum dibuat tagihan') ?></p>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($historicalPayableOrders as $hp): ?>
                                <label class="payment-source historical-source flex items-center gap-3 p-3 hover:bg-amber-50 cursor-pointer" data-vendor="__HISTORICAL__">
                                    <input type="checkbox" id="histCheck<?= (int)$hp['pesanan_id'] ?>" name="historical_order_ids[]" value="<?= (int)$hp['pesanan_id'] ?>" class="modal-bill-check historical-bill-check accent-black" data-historical="1" data-remaining="<?= (float)$hp['nilai_vendor'] ?>" data-bill="<?= atv_h($hp['nomor_pesanan']) ?>" onchange="updatePaymentPreview(this)">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="text-xs font-bold"><?= atv_h($hp['nomor_pesanan']) ?></p><span class="text-[8px] px-2 py-0.5 border border-amber-200 bg-amber-50 text-amber-700 font-bold uppercase">Data Lama</span>
                                            </div>
                                            <p class="text-xs font-bold text-red-700"><?= (float)$hp['nilai_vendor'] > 0 ? atv_rp($hp['nilai_vendor']) : 'Isi nominal' ?></p>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1"><?= atv_h($hp['nama_pemesan']) ?> · <?= number_format((int)$hp['total_unit']) ?> unit · <?= atv_date($hp['tanggal_kirim'] ?: $hp['tanggal_pemesanan']) ?> · Belum pernah masuk rekap vendor aktif</p>
                                        <?php if ((float)$hp['nilai_vendor'] <= 0): ?>
                                            <div class="mt-2" onclick="event.stopPropagation()">
                                                <label class="text-[8px] uppercase font-bold text-amber-700">Nominal Tagihan Historis</label>
                                                <input type="number" min="1" step="1" name="historical_nominal[<?= (int)$hp['pesanan_id'] ?>]" id="histNominal<?= (int)$hp['pesanan_id'] ?>" class="field mt-1 historical-nominal" placeholder="Contoh: 750000" data-check-id="histCheck<?= (int)$hp['pesanan_id'] ?>" oninput="updateHistoricalAmount(this)">
                                                <p class="text-[8px] text-amber-700 mt-1">Data sebelum aplikasi: isi nominal yang benar-benar menjadi tagihan vendor.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($historicalPayableOrders): ?>
                        <div class="border border-amber-200 bg-amber-50 p-3">
                            <div class="grid grid-cols-1 md:grid-cols-[1fr_300px] gap-3 items-end">
                                <div>
                                    <p class="text-[9px] uppercase font-bold text-amber-700">Vendor untuk Data Lama</p>
                                    <p class="text-[10px] text-amber-700 mt-1">Jika memilih baris berlabel Data Lama, pilih/ketik vendor yang dahulu memasok pesanan tersebut.</p>
                                </div>
                                <div>
                                    <input type="text" name="historical_vendor_name" id="historicalVendorName" list="historicalVendorList" class="field" placeholder="Pilih / ketik nama vendor" oninput="updatePaymentPreview()">
                                    <datalist id="historicalVendorList">
                                        <?php foreach ($knownVendorNames as $vendorName): ?>
                                            <option value="<?= atv_h($vendorName) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="border bg-gray-50 p-3">
                            <p class="text-[8px] uppercase font-bold text-gray-400">Vendor</p>
                            <p id="bulkModalVendor" class="text-xs font-bold mt-1">-</p>
                        </div>
                        <div class="border bg-gray-50 p-3">
                            <p class="text-[8px] uppercase font-bold text-gray-400">Pemesanan Dipilih</p>
                            <p id="bulkModalCount" class="text-base font-bold mt-1">0</p>
                        </div>
                        <div class="border bg-blue-50 border-blue-100 p-3">
                            <p class="text-[8px] uppercase font-bold text-blue-600">Total Akan Dibayar</p>
                            <p id="bulkModalTotal" class="text-base font-bold text-blue-700 mt-1">Rp 0</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Tanggal Bayar</label><input type="date" name="tanggal_bayar" value="<?= date('Y-m-d') ?>" class="field mt-2" required></div>
                        <div><label class="text-[9px] uppercase font-bold text-gray-400">Metode</label><select name="metode" class="field mt-2">
                                <option>Transfer</option>
                                <option>Tunai</option>
                                <option>Virtual Account</option>
                                <option>ATM</option>
                                <option>Mobile Banking</option>
                                <option>Lainnya</option>
                            </select></div>
                    </div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Nomor Referensi</label><input name="nomor_referensi" class="field mt-2" placeholder="Nomor transaksi / referensi bank (opsional)"></div>
                    <div class="upload-doc-grid">
                        <div class="upload-doc-card invoice">
                            <div class="upload-doc-head">
                                <div class="upload-doc-icon"><i data-lucide="file-text" class="w-4 h-4"></i></div>
                                <div class="min-w-0">
                                    <p class="upload-doc-title">Invoice dari Vendor <span class="font-normal normal-case">(opsional)</span></p>
                                    <p class="upload-doc-subtitle">Dokumen tagihan asli dari vendor.</p>
                                </div>
                            </div>
                            <input type="file" name="invoice_vendor" accept="image/jpeg,image/png,image/webp,application/pdf" class="upload-file-input">
                            <p class="upload-doc-help">JPG, PNG, WebP, atau PDF maksimal 8 MB. Jika tidak ada invoice, pembayaran tetap dapat disimpan.</p>
                        </div>

                        <div class="upload-doc-card">
                            <div class="upload-doc-head">
                                <div class="upload-doc-icon"><i data-lucide="receipt" class="w-4 h-4"></i></div>
                                <div class="min-w-0">
                                    <p class="upload-doc-title">Bukti Pembayaran <span class="font-normal normal-case text-gray-400">(opsional)</span></p>
                                    <p class="upload-doc-subtitle">Struk transfer, mobile banking, ATM, atau bukti lainnya.</p>
                                </div>
                            </div>
                            <input type="file" name="bukti_pembayaran" accept="image/jpeg,image/png,image/webp,application/pdf" class="upload-file-input">
                            <p class="upload-doc-help">JPG, PNG, WebP, atau PDF maksimal 8 MB.</p>
                        </div>
                    </div>
                    <div><label class="text-[9px] uppercase font-bold text-gray-400">Catatan</label><textarea name="catatan" class="field mt-2" placeholder="Opsional"></textarea></div>
                </div>
                <div class="p-5 border-t sticky bottom-0 bg-white flex gap-2"><button type="button" class="btn flex-1" onclick="closeModal('bulkPaymentModal')">Batal</button><button id="bulkSubmitBtn" class="btn flex-1 btn-dark opacity-40 pointer-events-none">Simpan Pembayaran</button></div>
            </form>
        </div>
    </div>

    <div id="detailModal" class="modal-wrap fixed inset-0 z-[120] hidden items-center justify-center p-3">
        <div class="modal-panel bg-white w-full max-w-4xl border border-gray-200 max-h-[90vh] overflow-y-auto">
            <div class="px-5 py-4 border-b sticky top-0 z-10 bg-white flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] uppercase font-bold text-gray-400">Detail Tagihan Vendor</p>
                    <h2 id="detailTitle" class="text-base font-bold mt-1">-</h2>
                </div><button type="button" class="modal-close" onclick="closeModal('detailModal')" aria-label="Tutup">&times;</button>
            </div>
            <div id="detailBody" class="p-5"></div>
        </div>
    </div>

    <script>
        function escapeHtml(v) {
            return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')
        }

        function rp(v) {
            return 'Rp ' + Number(v || 0).toLocaleString('id-ID', {
                maximumFractionDigits: 0
            })
        }

        function closeModal(id) {
            var m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
            document.body.style.overflow = ''
        }

        function showModal(id) {
            var m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
            document.body.style.overflow = 'hidden'
        }

        function modalSelectedBills() {
            return Array.prototype.slice.call(document.querySelectorAll('.modal-bill-check:checked'));
        }

        function historicalVendorValue() {
            var el = document.getElementById('historicalVendorName');
            return el ? String(el.value || '').trim() : '';
        }

        function effectiveBillVendor(el) {
            if (!el) return '';
            if (String(el.dataset.historical || '') === '1') return historicalVendorValue();
            return String(el.dataset.vendor || '').trim();
        }

        function historicalAmountForCheck(el) {
            if (!el || String(el.dataset.historical || '') !== '1') return Math.max(0, Number(el && el.dataset ? el.dataset.remaining || 0 : 0));
            var input = document.getElementById('histNominal' + String(el.value || ''));
            if (input) return Math.max(0, Number(input.value || 0));
            return Math.max(0, Number(el.dataset.remaining || 0));
        }

        function updateHistoricalAmount(input) {
            if (!input) return;
            var check = document.getElementById(String(input.dataset.checkId || ''));
            if (check) check.dataset.remaining = String(Math.max(0, Number(input.value || 0)));
            updatePaymentPreview(check || null);
        }

        function resetPaymentModal() {
            document.querySelectorAll('.modal-bill-check').forEach(function(el) {
                el.checked = false;
                el.disabled = false;
            });
            document.querySelectorAll('.payment-source').forEach(function(el) {
                el.classList.remove('opacity-40', 'cursor-not-allowed');
            });
            var hv = document.getElementById('historicalVendorName');
            if (hv) hv.value = '';
            var all = document.getElementById('modalCheckAll');
            if (all) {
                all.checked = false;
                all.disabled = false;
            }
            updatePaymentPreview();
        }

        function updatePaymentPreview(changed) {
            var selected = modalSelectedBills();
            var vendor = '';

            // Bila tagihan vendor biasa dipilih lebih dulu, pakai vendor tersebut sebagai
            // default untuk data lama agar dapat digabung dengan vendor yang sama.
            selected.some(function(el) {
                if (String(el.dataset.historical || '') !== '1') {
                    vendor = effectiveBillVendor(el);
                    return vendor !== '';
                }
                return false;
            });
            if (!vendor) {
                selected.some(function(el) {
                    var v = effectiveBillVendor(el);
                    if (v) {
                        vendor = v;
                        return true;
                    }
                    return false;
                });
            }

            var hv = document.getElementById('historicalVendorName');
            if (changed && String(changed.dataset.historical || '') !== '1' && changed.checked && hv && !String(hv.value || '').trim()) {
                hv.value = String(changed.dataset.vendor || '').trim();
                vendor = String(hv.value || '').trim();
            }

            document.querySelectorAll('.modal-bill-check').forEach(function(el) {
                var ev = effectiveBillVendor(el);
                var isHist = String(el.dataset.historical || '') === '1';
                // Data lama belum diberi vendor tetap boleh dicentang; tombol simpan akan
                // menunggu sampai nama vendor diisi. Tagihan vendor lain tetap dikunci.
                var disable = vendor !== '' && ev !== '' && ev !== vendor;
                el.disabled = disable;
                var row = el.closest('.payment-source');
                if (row) row.classList.toggle('opacity-40', disable);
            });

            selected = modalSelectedBills();
            var total = selected.reduce(function(sum, el) {
                return sum + historicalAmountForCheck(el);
            }, 0);
            var hasHistorical = selected.some(function(el) {
                return String(el.dataset.historical || '') === '1';
            });
            var historicalVendorMissing = hasHistorical && historicalVendorValue() === '';
            var historicalAmountMissing = selected.some(function(el) {
                return String(el.dataset.historical || '') === '1' && historicalAmountForCheck(el) <= 0;
            });

            document.getElementById('bulkModalVendor').textContent = vendor || (hasHistorical ? 'Pilih vendor data lama' : '-');
            document.getElementById('bulkModalCount').textContent = selected.length.toLocaleString('id-ID') + ' pesanan';
            document.getElementById('bulkModalTotal').textContent = rp(total);
            document.getElementById('bulkVendorHint').textContent = historicalVendorMissing ?
                'Data lama dipilih. Isi Vendor untuk Data Lama sebelum menyimpan pembayaran.' :
                (vendor ? 'Vendor dipilih: ' + vendor + '. Pemesanan vendor lain dinonaktifkan.' : 'Pilih pemesanan dari satu vendor. Data lama AIR-LAMA yang belum pernah masuk rekap vendor aktif muncul langsung pada daftar ini.');

            var btn = document.getElementById('bulkSubmitBtn');
            var blocked = selected.length === 0 || historicalVendorMissing || historicalAmountMissing;
            if (btn) {
                btn.classList.toggle('opacity-40', blocked);
                btn.classList.toggle('pointer-events-none', blocked);
            }

            var all = document.getElementById('modalCheckAll');
            if (all) {
                var eligible = Array.prototype.slice.call(document.querySelectorAll('.modal-bill-check:not(.historical-bill-check)')).filter(function(el) {
                    return !el.disabled;
                });
                var checked = eligible.filter(function(el) {
                    return el.checked;
                }).length;
                all.checked = eligible.length > 0 && checked === eligible.length;
                all.indeterminate = checked > 0 && checked < eligible.length;
            }
        }

        function toggleModalAll(checked) {
            var selected = modalSelectedBills();
            var vendor = '';
            selected.some(function(el) {
                if (String(el.dataset.historical || '') !== '1') {
                    vendor = String(el.dataset.vendor || '').trim();
                    return vendor !== '';
                }
                return false;
            });
            var candidates = Array.prototype.slice.call(document.querySelectorAll('.modal-bill-check:not(.historical-bill-check)')).filter(function(el) {
                return !el.disabled;
            });
            if (!vendor && checked && candidates.length) vendor = String(candidates[0].dataset.vendor || '').trim();
            candidates.forEach(function(el) {
                if (!vendor || String(el.dataset.vendor || '').trim() === vendor) el.checked = checked;
            });
            var hv = document.getElementById('historicalVendorName');
            if (checked && vendor && hv && !String(hv.value || '').trim()) hv.value = vendor;
            updatePaymentPreview();
        }

        function openBulkPayment() {
            resetPaymentModal();
            showModal('bulkPaymentModal');
        }

        function openCreateBill(o) {
            document.getElementById('createBillOrderId').value = Number(o.id || 0);
            document.getElementById('createBillTitle').textContent = (o.nomor_vendor_order || '-') + ' · ' + (o.vendor_nama || '-');
            showModal('createBillModal')
        }

        function openPayment(r) {
            document.getElementById('paymentBillId').value = Number(r.id || 0);
            document.getElementById('paymentTitle').textContent = r.nomor_tagihan || '-';
            document.getElementById('paymentBillAmount').textContent = rp(r.nilai_tagihan);
            document.getElementById('paymentRemaining').textContent = rp(r.sisa_tagihan);
            document.getElementById('paymentNominal').value = Math.max(0, Number(r.sisa_tagihan || 0));
            document.getElementById('paymentNominal').max = Math.max(0, Number(r.sisa_tagihan || 0));
            showModal('paymentModal')
        }

        function openDetail(r) {
            document.getElementById('detailTitle').textContent = r.nomor_tagihan || '-';
            var details = Array.isArray(r.details) ? r.details : [];
            var pays = Array.isArray(r.payments) ? r.payments : [];
            var drows = details.map(function(d) {
                return '<tr><td class="px-3 py-3 text-xs font-semibold">' + escapeHtml(d.nama_produk) + '</td><td class="px-3 py-3 text-center text-xs">' + Number(d.jumlah_diterima || 0).toLocaleString('id-ID') + ' ' + escapeHtml(d.satuan) + '</td><td class="px-3 py-3 text-right text-xs">' + rp(d.harga_vendor) + '</td><td class="px-3 py-3 text-right text-xs font-bold">' + rp(d.subtotal) + '</td></tr>'
            }).join('');
            var prows = pays.map(function(p) {
                var proof = p.bukti_pembayaran ? '<a class="text-blue-600 font-bold hover:underline" href="' + escapeHtml(p.bukti_pembayaran) + '" target="_blank">Lihat Bukti</a>' : '-';
                return '<tr><td class="px-3 py-3 text-xs">' + escapeHtml(p.tanggal_bayar || '-') + '</td><td class="px-3 py-3 text-xs">' + escapeHtml(p.metode || '-') + '</td><td class="px-3 py-3 text-xs">' + escapeHtml(p.nomor_referensi || '-') + '</td><td class="px-3 py-3 text-xs">' + proof + '</td><td class="px-3 py-3 text-right text-xs font-bold text-green-700">' + rp(p.nominal) + '</td></tr>'
            }).join('');
            document.getElementById('detailBody').innerHTML = '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5"><div class="border bg-gray-50 p-3"><p class="text-[8px] uppercase font-bold text-gray-400">Vendor</p><p class="text-xs font-bold mt-1">' + escapeHtml(r.vendor_nama) + '</p></div><div class="border bg-gray-50 p-3"><p class="text-[8px] uppercase font-bold text-gray-400">Invoice Vendor</p><p class="text-xs font-bold mt-1">' + escapeHtml(r.nomor_invoice_vendor || '-') + '</p></div><div class="border bg-gray-50 p-3"><p class="text-[8px] uppercase font-bold text-gray-400">Tagihan</p><p class="text-xs font-bold mt-1">' + rp(r.nilai_tagihan) + '</p></div><div class="border bg-gray-50 p-3"><p class="text-[8px] uppercase font-bold text-gray-400">Sisa</p><p class="text-xs font-bold text-red-700 mt-1">' + rp(r.sisa_tagihan) + '</p></div></div><p class="text-[9px] uppercase font-bold text-gray-400 mb-2">Rincian Barang</p><div class="border overflow-x-auto"><table class="w-full min-w-[620px]"><thead class="bg-gray-50"><tr><th class="px-3 py-3 text-left text-[8px] uppercase text-gray-400">Produk</th><th class="px-3 py-3 text-center text-[8px] uppercase text-gray-400">Diterima</th><th class="px-3 py-3 text-right text-[8px] uppercase text-gray-400">Harga Vendor</th><th class="px-3 py-3 text-right text-[8px] uppercase text-gray-400">Subtotal</th></tr></thead><tbody class="divide-y">' + (drows || '<tr><td colspan="4" class="py-8 text-center text-xs text-gray-400">Tidak ada rincian</td></tr>') + '</tbody></table></div><p class="text-[9px] uppercase font-bold text-gray-400 mt-5 mb-2">Riwayat Pembayaran</p><div class="border overflow-x-auto"><table class="w-full min-w-[680px]"><thead class="bg-gray-50"><tr><th class="px-3 py-3 text-left text-[8px] uppercase text-gray-400">Tanggal</th><th class="px-3 py-3 text-left text-[8px] uppercase text-gray-400">Metode</th><th class="px-3 py-3 text-left text-[8px] uppercase text-gray-400">Referensi</th><th class="px-3 py-3 text-left text-[8px] uppercase text-gray-400">Bukti</th><th class="px-3 py-3 text-right text-[8px] uppercase text-gray-400">Nominal</th></tr></thead><tbody class="divide-y">' + (prows || '<tr><td colspan="5" class="py-8 text-center text-xs text-gray-400">Belum ada pembayaran</td></tr>') + '</tbody></table></div>';
            showModal('detailModal');
            if (window.lucide) lucide.createIcons()
        }
        ['createBillModal', 'paymentModal', 'bulkPaymentModal', 'detailModal'].forEach(function(id) {
            document.getElementById(id).addEventListener('click', function(e) {
                if (e.target === this) closeModal(id)
            })
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape')['createBillModal', 'paymentModal', 'bulkPaymentModal', 'detailModal'].forEach(closeModal)
        });
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>