<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu = 'produk';
$pageTitle  = 'Kelola Produk';
$backUrl    = 'dashboard.php';

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('e')) {
    /**
     * @param  mixed  $v
     * @return string
     */
    function e($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah')) {
    /**
     * @param  mixed  $n
     * @return string
     */
    function rupiah($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}


if (!function_exists('produk_ensure_gambar_column')) {
    function produk_ensure_gambar_column(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM produk")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('gambar', $cols, true)) {
                $pdo->exec("ALTER TABLE produk ADD COLUMN gambar VARCHAR(255) NULL AFTER satuan");
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('produk_upload_gambar')) {
    function produk_upload_gambar(string $fieldName, string $oldPath = ''): string
    {
        if (empty($_FILES[$fieldName]['tmp_name']) || !is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
            return $oldPath;
        }

        $file = $_FILES[$fieldName];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Upload gambar gagal. Kode error: ' . (int)($file['error'] ?? 0));
        }

        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new Exception('Ukuran gambar maksimal 2MB.');
        }

        $info = @getimagesize((string)$file['tmp_name']);
        if (!$info) {
            throw new Exception('File harus berupa gambar.');
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        $mime = (string)($info['mime'] ?? '');
        if (!isset($extMap[$mime])) {
            throw new Exception('Format gambar harus JPG, PNG, WEBP, atau GIF.');
        }

        $uploadOptions = [
            ['dir' => __DIR__ . '/uploads/produk', 'url' => 'uploads/produk/'],
            ['dir' => __DIR__ . '/assets/produk',  'url' => 'assets/produk/'],
        ];

        $targetDir = '';
        $targetUrl = '';

        foreach ($uploadOptions as $opt) {
            if (!is_dir($opt['dir'])) {
                @mkdir($opt['dir'], 0775, true);
            }

            if (is_dir($opt['dir']) && is_writable($opt['dir'])) {
                $targetDir = $opt['dir'];
                $targetUrl = $opt['url'];
                break;
            }
        }

        if ($targetDir === '') {
            throw new Exception(
                'Folder upload gambar belum bisa ditulis. Buat dan beri izin tulis pada folder uploads/produk atau assets/produk.'
            );
        }

        $filename = 'produk_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
        $dest = $targetDir . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            throw new Exception('Gagal menyimpan gambar produk ke folder upload.');
        }

        if ($oldPath !== '' && (strpos($oldPath, 'uploads/produk/') === 0 || strpos($oldPath, 'assets/produk/') === 0)) {
            $oldFile = __DIR__ . '/' . $oldPath;
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        return $targetUrl . $filename;
    }
}


if (!function_exists('produk_hapus_file_gambar')) {
    function produk_hapus_file_gambar(string $path): void
    {
        if ($path !== '' && strpos($path, 'uploads/produk/') === 0) {
            $file = __DIR__ . '/' . $path;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

if (!function_exists('produk_ensure_expired_column')) {
    function produk_ensure_expired_column(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM produk")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('expired_date', $cols, true)) {
                $pdo->exec("ALTER TABLE produk ADD COLUMN expired_date DATE NULL AFTER stok_minimum");
            }
        } catch (Throwable $e) {
            error_log('Gagal memastikan kolom expired_date: ' . $e->getMessage());
        }
    }
}

produk_ensure_gambar_column($pdo);
produk_ensure_expired_column($pdo);

// ── Print / Export Produk (satu file, tanpa file tambahan) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && in_array($_GET['action'], ['print', 'excel'], true)) {
    $exportSearch        = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $exportKatFilter     = isset($_GET['kat']) ? $_GET['kat'] : '';
    $exportStatusFilter  = isset($_GET['status']) ? $_GET['status'] : 'aktif';
    $exportStokFilter    = isset($_GET['stok']) ? $_GET['stok'] : '';
    $exportExpiredFilter = isset($_GET['expired']) ? $_GET['expired'] : '';

    $exportWhere  = ['1=1'];
    $exportParams = [];

    if ($exportSearch !== '') {
        $exportWhere[] = '(nama LIKE :q OR kode LIKE :q2 OR kategori LIKE :q3)';
        $exportParams[':q']  = "%{$exportSearch}%";
        $exportParams[':q2'] = "%{$exportSearch}%";
        $exportParams[':q3'] = "%{$exportSearch}%";
    }
    if ($exportKatFilter !== '') {
        $exportWhere[] = 'kategori = :kat';
        $exportParams[':kat'] = $exportKatFilter;
    }
    if ($exportStatusFilter !== 'semua') {
        $exportWhere[] = 'status = :status';
        $exportParams[':status'] = $exportStatusFilter;
    }
    if ($exportStokFilter === 'limit') {
        $exportWhere[] = 'stok > 0 AND stok <= stok_minimum';
    } elseif ($exportStokFilter === 'habis') {
        $exportWhere[] = 'stok <= 0';
    }
    if ($exportExpiredFilter === 'expired') {
        $exportWhere[] = "expired_date IS NOT NULL AND expired_date <> '0000-00-00' AND expired_date < CURDATE()";
    } elseif ($exportExpiredFilter === '30_hari') {
        $exportWhere[] = "expired_date IS NOT NULL AND expired_date <> '0000-00-00' AND expired_date >= CURDATE() AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($exportExpiredFilter === '90_hari') {
        $exportWhere[] = "expired_date IS NOT NULL AND expired_date <> '0000-00-00' AND expired_date >= CURDATE() AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
    } elseif ($exportExpiredFilter === 'tanpa_tanggal') {
        $exportWhere[] = "(expired_date IS NULL OR expired_date = '0000-00-00')";
    }

    $exportWhereStr = implode(' AND ', $exportWhere);
    $exportStmt = $pdo->prepare("SELECT * FROM produk WHERE {$exportWhereStr} ORDER BY kategori ASC, nama ASC");
    $exportStmt->execute($exportParams);
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStok = 0;
    $totalNilaiBeli = 0;
    $totalNilaiJual = 0;
    foreach ($exportRows as $row) {
        $qty = (int)($row['stok'] ?? 0);
        $totalStok += $qty;
        $totalNilaiBeli += $qty * (float)($row['harga_beli'] ?? 0);
        $totalNilaiJual += $qty * (float)($row['harga_jual'] ?? 0);
    }

    if ($_GET['action'] === 'excel') {
        $filename = 'laporan_produk_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['No', 'Kode Produk', 'Nama Produk', 'Kategori', 'Harga Beli', 'Harga Jual', 'Margin (%)', 'Stok', 'Stok Minimum', 'Satuan', 'Tanggal Kedaluwarsa', 'Status'], ';');
        foreach ($exportRows as $i => $row) {
            $hargaBeli = (float)($row['harga_beli'] ?? 0);
            $hargaJual = (float)($row['harga_jual'] ?? 0);
            $margin = $hargaJual > 0 ? round((($hargaJual - $hargaBeli) / $hargaJual) * 100, 2) : 0;
            $expired = !empty($row['expired_date']) && $row['expired_date'] !== '0000-00-00'
                ? date('d/m/Y', strtotime($row['expired_date']))
                : '';
            fputcsv($out, [
                $i + 1,
                $row['kode'] ?? '',
                $row['nama'] ?? '',
                $row['kategori'] ?? '',
                $hargaBeli,
                $hargaJual,
                $margin,
                (int)($row['stok'] ?? 0),
                (int)($row['stok_minimum'] ?? 0),
                $row['satuan'] ?? '',
                $expired,
                $row['status'] ?? '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    $filterLabels = [];
    if ($exportSearch !== '') $filterLabels[] = 'Pencarian: ' . $exportSearch;
    if ($exportKatFilter !== '') $filterLabels[] = 'Kategori: ' . $exportKatFilter;
    if ($exportStatusFilter !== 'semua') $filterLabels[] = 'Status: ' . ucfirst($exportStatusFilter);
    if ($exportStokFilter === 'limit') $filterLabels[] = 'Stok limit';
    if ($exportStokFilter === 'habis') $filterLabels[] = 'Stok habis';
    if ($exportExpiredFilter !== '') $filterLabels[] = 'Filter kedaluwarsa: ' . str_replace('_', ' ', $exportExpiredFilter);
    $filterText = $filterLabels ? implode(' • ', $filterLabels) : 'Seluruh data produk';
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Laporan Produk</title>
        <style>
            * {
                box-sizing: border-box
            }

            body {
                font-family: Arial, sans-serif;
                color: #111;
                margin: 0;
                background: #f3f4f6
            }

            .toolbar {
                position: sticky;
                top: 0;
                z-index: 10;
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                padding: 12px 18px;
                background: #fff;
                border-bottom: 1px solid #ddd
            }

            .toolbar button,
            .toolbar a {
                border: 1px solid #111;
                background: #fff;
                color: #111;
                padding: 9px 14px;
                text-decoration: none;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer
            }

            .toolbar .primary {
                background: #111;
                color: #fff
            }

            .sheet {
                width: 297mm;
                min-height: 210mm;
                margin: 18px auto;
                background: #fff;
                padding: 12mm;
                box-shadow: 0 3px 18px rgba(0, 0, 0, .12)
            }

            .header {
                text-align: center;
                border-bottom: 2px solid #111;
                padding-bottom: 10px;
                margin-bottom: 12px
            }

            .header h1 {
                font-size: 20px;
                margin: 0 0 4px;
                text-transform: uppercase;
                letter-spacing: .8px
            }

            .header p {
                font-size: 11px;
                margin: 2px 0;
                color: #555
            }

            .summary {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin: 12px 0
            }

            .summary div {
                border: 1px solid #bbb;
                padding: 8px
            }

            .summary span {
                display: block;
                font-size: 9px;
                text-transform: uppercase;
                color: #666;
                font-weight: bold
            }

            .summary strong {
                display: block;
                font-size: 15px;
                margin-top: 4px
            }

            .meta {
                font-size: 10px;
                margin-bottom: 10px;
                display: flex;
                justify-content: space-between;
                gap: 20px
            }

            .meta div:last-child {
                text-align: right
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5px
            }

            th,
            td {
                border: 1px solid #999;
                padding: 5px 4px;
                vertical-align: middle
            }

            th {
                background: #e5e7eb;
                text-transform: uppercase;
                font-size: 8px;
                letter-spacing: .2px
            }

            .right {
                text-align: right
            }

            .center {
                text-align: center
            }

            .expired {
                color: #b91c1c;
                font-weight: bold
            }

            .soon {
                color: #b45309;
                font-weight: bold
            }

            .signature {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 80mm;
                margin-top: 18px;
                text-align: center;
                font-size: 10px
            }

            .signature .space {
                height: 48px
            }

            .footer {
                margin-top: 12px;
                font-size: 8px;
                color: #666;
                text-align: right
            }

            @media print {
                body {
                    background: #fff
                }

                .toolbar {
                    display: none
                }

                .sheet {
                    width: auto;
                    min-height: auto;
                    margin: 0;
                    padding: 8mm;
                    box-shadow: none
                }

                @page {
                    size: A4 landscape;
                    margin: 8mm
                }
            }

            @media(max-width:1000px) {
                .sheet {
                    width: 100%;
                    margin: 0;
                    box-shadow: none;
                    padding: 12px
                }

                .summary {
                    grid-template-columns: repeat(2, 1fr)
                }

                .table-wrap {
                    overflow-x: auto
                }
            }
        </style>
    </head>

    <body>
        <div class="toolbar">
            <a href="produk.php">Kembali</a>
            <button class="primary" onclick="window.print()">Print / Simpan PDF</button>
        </div>
        <section class="sheet">
            <div class="header">
                <h1>Laporan Daftar Produk</h1>
                <p>SEJAHUB — Sistem Informasi Koperasi dan Penjualan</p>
                <p><?php echo e($filterText); ?></p>
            </div>
            <div class="meta">
                <div>Tanggal cetak: <strong><?php echo date('d/m/Y H:i'); ?></strong></div>
                <div>Jumlah data: <strong><?php echo number_format(count($exportRows)); ?> produk</strong></div>
            </div>
            <div class="summary">
                <div><span>Total SKU</span><strong><?php echo number_format(count($exportRows)); ?></strong></div>
                <div><span>Total Stok</span><strong><?php echo number_format($totalStok); ?></strong></div>
                <div><span>Nilai Persediaan (Beli)</span><strong><?php echo rupiah($totalNilaiBeli); ?></strong></div>
                <div><span>Nilai Potensi Jual</span><strong><?php echo rupiah($totalNilaiJual); ?></strong></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Kode</th>
                            <th>Nama Produk</th>
                            <th>Kategori</th>
                            <th>Harga Beli</th>
                            <th>Harga Jual</th>
                            <th>Stok</th>
                            <th>Min.</th>
                            <th>Satuan</th>
                            <th>Kedaluwarsa</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$exportRows): ?>
                            <tr>
                                <td colspan="11" class="center">Tidak ada data produk.</td>
                            </tr>
                            <?php else: foreach ($exportRows as $i => $row):
                                $expired = !empty($row['expired_date']) && $row['expired_date'] !== '0000-00-00' ? $row['expired_date'] : '';
                                $expiredClass = '';
                                if ($expired !== '' && $expired < date('Y-m-d')) $expiredClass = 'expired';
                                elseif ($expired !== '' && $expired <= date('Y-m-d', strtotime('+30 days'))) $expiredClass = 'soon';
                            ?>
                                <tr>
                                    <td class="center"><?php echo $i + 1; ?></td>
                                    <td><?php echo e($row['kode'] ?? ''); ?></td>
                                    <td><?php echo e($row['nama'] ?? ''); ?></td>
                                    <td><?php echo e($row['kategori'] ?? '-'); ?></td>
                                    <td class="right"><?php echo rupiah($row['harga_beli'] ?? 0); ?></td>
                                    <td class="right"><?php echo rupiah($row['harga_jual'] ?? 0); ?></td>
                                    <td class="center"><?php echo number_format((int)($row['stok'] ?? 0)); ?></td>
                                    <td class="center"><?php echo number_format((int)($row['stok_minimum'] ?? 0)); ?></td>
                                    <td class="center"><?php echo e($row['satuan'] ?? ''); ?></td>
                                    <td class="center <?php echo $expiredClass; ?>"><?php echo $expired !== '' ? date('d/m/Y', strtotime($expired)) : '-'; ?></td>
                                    <td class="center"><?php echo e(ucfirst($row['status'] ?? '')); ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="signature">
                <div>
                    <div>Petugas</div>
                    <div class="space"></div>
                    <div>( ____________________ )</div>
                </div>
                <div>
                    <div>Pemeriksa / Penanggung Jawab</div>
                    <div class="space"></div>
                    <div>( ____________________ )</div>
                </div>
            </div>
            <div class="footer">Dicetak otomatis dari SEJAHUB pada <?php echo date('d/m/Y H:i:s'); ?></div>
        </section>
    </body>

    </html>
<?php
    exit;
}

// ── API Handler (AJAX) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    ini_set('display_errors', '0');
    header('Content-Type: application/json');

    set_exception_handler(function (Throwable $e): void {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });

    switch ($_GET['action']) {

        case 'tambah':
            $input    = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
            $required = ['kode', 'nama', 'harga_jual'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    echo json_encode(['success' => false, 'message' => "Field '$field' wajib diisi."]);
                    exit;
                }
            }
            $cek = $pdo->prepare("SELECT id FROM produk WHERE kode = :kode");
            $cek->execute([':kode' => $input['kode']]);
            if ($cek->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Kode produk sudah digunakan.']);
                exit;
            }
            $gambar = produk_upload_gambar('gambar_produk');

            $stmt = $pdo->prepare("
                INSERT INTO produk (kode, nama, kategori, harga_beli, harga_jual, stok, stok_minimum, expired_date, satuan, gambar, status)
                VALUES (:kode, :nama, :kategori, :harga_beli, :harga_jual, :stok, :stok_minimum, :expired_date, :satuan, :gambar, :status)
            ");
            $stmt->execute([
                ':kode'         => trim($input['kode']),
                ':nama'         => trim($input['nama']),
                ':kategori'     => trim(isset($input['kategori']) ? $input['kategori'] : '-'),
                ':harga_beli'   => (int)(isset($input['harga_beli']) ? $input['harga_beli'] : 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)(isset($input['stok']) ? $input['stok'] : 0),
                ':stok_minimum' => (int)(isset($input['stok_minimum']) ? $input['stok_minimum'] : 5),
                ':expired_date' => !empty($input['expired_date']) ? $input['expired_date'] : null,
                ':satuan'       => trim(isset($input['satuan']) ? $input['satuan'] : 'pcs'),
                ':gambar'       => $gambar,
                ':status'       => in_array(isset($input['status']) ? $input['status'] : 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            catat_aktivitas($pdo, 'create', 'Produk', 'Menambah produk: ' . trim($input['nama']));
            echo json_encode(['success' => true, 'message' => 'Produk berhasil ditambahkan.', 'id' => $pdo->lastInsertId()]);
            exit;

        case 'edit':
            $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $cek = $pdo->prepare("SELECT id FROM produk WHERE kode = :kode AND id != :id");
            $cek->execute([':kode' => $input['kode'], ':id' => $input['id']]);
            if ($cek->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Kode produk sudah digunakan produk lain.']);
                exit;
            }
            $oldStmt = $pdo->prepare("SELECT gambar FROM produk WHERE id = :id LIMIT 1");
            $oldStmt->execute([':id' => (int)$input['id']]);
            $oldGambar = (string)($oldStmt->fetchColumn() ?: '');

            $hapusGambar = !empty($input['hapus_gambar']) && (string)$input['hapus_gambar'] === '1';
            if ($hapusGambar) {
                produk_hapus_file_gambar($oldGambar);
                $oldGambar = '';
            }

            $gambar = produk_upload_gambar('gambar_produk', $oldGambar);

            $stmt = $pdo->prepare("
                UPDATE produk SET
                    kode = :kode, nama = :nama, kategori = :kategori,
                    harga_beli = :harga_beli, harga_jual = :harga_jual,
                    stok = :stok, stok_minimum = :stok_minimum,
                    expired_date = :expired_date, satuan = :satuan, gambar = :gambar, status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'           => (int)$input['id'],
                ':kode'         => trim($input['kode']),
                ':nama'         => trim($input['nama']),
                ':kategori'     => trim(isset($input['kategori']) ? $input['kategori'] : '-'),
                ':harga_beli'   => (int)(isset($input['harga_beli']) ? $input['harga_beli'] : 0),
                ':harga_jual'   => (int)$input['harga_jual'],
                ':stok'         => (int)(isset($input['stok']) ? $input['stok'] : 0),
                ':stok_minimum' => (int)(isset($input['stok_minimum']) ? $input['stok_minimum'] : 5),
                ':expired_date' => !empty($input['expired_date']) ? $input['expired_date'] : null,
                ':satuan'       => trim(isset($input['satuan']) ? $input['satuan'] : 'pcs'),
                ':gambar'       => $gambar,
                ':status'       => in_array(isset($input['status']) ? $input['status'] : 'aktif', ['aktif', 'nonaktif']) ? $input['status'] : 'aktif',
            ]);
            catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah produk ID: ' . (int)$input['id']);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil diperbarui.']);
            exit;

        case 'hapus':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $cek = $pdo->prepare("SELECT id FROM transaksi_detail WHERE produk_id = :id LIMIT 1");
            $cek->execute([':id' => $input['id']]);
            if ($cek->fetch()) {
                $pdo->prepare("UPDATE produk SET status = 'nonaktif', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $input['id']]);
                catat_aktivitas($pdo, 'status', 'Produk', 'Menonaktifkan produk ID: ' . (int)$input['id']);
                echo json_encode(['success' => true, 'message' => 'Produk dinonaktifkan (ada di riwayat transaksi).', 'type' => 'soft']);
            } else {
                $oldStmt = $pdo->prepare("SELECT gambar FROM produk WHERE id = :id LIMIT 1");
                $oldStmt->execute([':id' => (int)$input['id']]);
                produk_hapus_file_gambar((string)($oldStmt->fetchColumn() ?: ''));

                $pdo->prepare("DELETE FROM produk WHERE id = :id")->execute([':id' => $input['id']]);
                catat_aktivitas($pdo, 'delete', 'Produk', 'Menghapus produk ID: ' . (int)$input['id']);
                echo json_encode(['success' => true, 'message' => 'Produk berhasil dihapus.', 'type' => 'hard']);
            }
            exit;

        case 'get':
            $id   = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
            $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if ($row) echo json_encode(['success' => true, 'data' => $row]);
            else      echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan.']);
            exit;

        case 'update_stok':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id'])) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $pdo->prepare("UPDATE produk SET stok = :stok, updated_at = NOW() WHERE id = :id")
                ->execute([':stok' => (int)$input['stok'], ':id' => (int)$input['id']]);
            catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah stok produk ID: ' . (int)$input['id']);
            echo json_encode(['success' => true, 'message' => 'Stok berhasil diperbarui.']);
            exit;
    }
    echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
    exit;
}

// ── Fetch Data ────────────────────────────────────────────────────────────────
$search       = trim(isset($_GET['q'])      ? $_GET['q']      : '');
$katFilter    = isset($_GET['kat'])          ? $_GET['kat']    : '';
$statusFilter = isset($_GET['status'])       ? $_GET['status'] : 'aktif';
$stokFilter   = isset($_GET['stok'])         ? $_GET['stok']   : '';
$expiredFilter = isset($_GET['expired'])      ? $_GET['expired'] : '';
$page         = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$allowedLimit = [10, 15, 25, 50, 100];
$perPage      = (int)(isset($_GET['limit']) ? $_GET['limit'] : 15);
if (!in_array($perPage, $allowedLimit, true)) {
    $perPage = 15;
}
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]       = '(nama LIKE :q OR kode LIKE :q2 OR kategori LIKE :q3)';
    $params[':q']  = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
if ($katFilter) {
    $where[]        = 'kategori = :kat';
    $params[':kat'] = $katFilter;
}
if ($statusFilter !== 'semua') {
    $where[]           = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($stokFilter === 'limit') {
    $where[] = 'stok > 0 AND stok <= stok_minimum';
} elseif ($stokFilter === 'habis') {
    $where[] = 'stok <= 0';
}

if ($expiredFilter === 'expired') {
    $where[] = "expired_date IS NOT NULL AND expired_date < CURDATE()";
} elseif ($expiredFilter === '30_hari') {
    $where[] = "expired_date IS NOT NULL AND expired_date >= CURDATE() AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiredFilter === '90_hari') {
    $where[] = "expired_date IS NOT NULL AND expired_date >= CURDATE() AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
} elseif ($expiredFilter === 'tanpa_tanggal') {
    $where[] = "expired_date IS NULL";
}

$whereStr = implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM produk WHERE $whereStr");
$stmtCount->execute($params);
$totalRows  = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

if (!function_exists('produk_page_url')) {
    function produk_page_url(int $targetPage, string $search, string $katFilter, string $statusFilter, string $stokFilter, string $expiredFilter, int $perPage): string
    {
        return '?' . http_build_query([
            'page' => max(1, $targetPage),
            'q' => $search,
            'kat' => $katFilter,
            'status' => $statusFilter,
            'stok' => $stokFilter,
            'expired' => $expiredFilter,
            'limit' => $perPage,
        ]);
    }
}

$stmtProd = $pdo->prepare("SELECT * FROM produk WHERE $whereStr ORDER BY kategori, nama LIMIT $perPage OFFSET $offset");
$stmtProd->execute($params);
$produkList = $stmtProd->fetchAll();

$kategoriList = $pdo->query("
    SELECT DISTINCT TRIM(kategori) AS kategori
    FROM produk
    WHERE kategori IS NOT NULL
      AND TRIM(kategori) <> ''
      AND TRIM(kategori) <> '-'
    ORDER BY kategori ASC
")->fetchAll(PDO::FETCH_COLUMN);

$summaryWhere = $whereStr;
$summaryParams = $params;

$stmtSummary = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN stok <= stok_minimum THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN stok = 0 THEN 1 ELSE 0 END) AS habis,
        SUM(CASE WHEN expired_date IS NOT NULL AND expired_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN expired_date IS NOT NULL AND expired_date >= CURDATE() AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expired_soon,
        SUM(harga_jual * stok) AS nilai_stok
    FROM produk
    WHERE $summaryWhere
");
$stmtSummary->execute($summaryParams);
$summary = $stmtSummary->fetch();

catat_view_once($pdo, 'Produk', 'Membuka halaman Produk');

// ── Tombol di Navbar ─────────────────────────────────────────────────────────
$reportQuery = http_build_query([
    'q' => $search,
    'kat' => $katFilter,
    'status' => $statusFilter,
    'stok' => $stokFilter,
    'expired' => $expiredFilter,
]);
$rightActionHtml = '
<div class="flex items-center gap-1 sm:gap-2">
    <a href="produk.php?action=print&amp;' . e($reportQuery) . '" target="_blank"
       class="inline-flex items-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-all"
       title="Cetak daftar produk">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18h12v4H6v-4zm-2 0H2V9h20v9h-4"/></svg>
        <span class="hidden md:inline">Print</span>
    </a>
    <a href="produk.php?action=excel&amp;' . e($reportQuery) . '"
       class="inline-flex items-center gap-2 px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-all"
       title="Download Excel CSV">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
        <span class="hidden md:inline">Excel</span>
    </a>
    <button
        onclick="openModal(\'tambah\')"
        class="inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-[10px] font-black uppercase tracking-widest bg-black text-white hover:bg-gray-800 transition-all">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-width="2.5" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Tambah Produk</span>
        <span class="sm:hidden">+</span>
    </button>
</div>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Produk</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        .badge-aktif {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-nonaktif {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .badge-low {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .badge-habis {
            background: #fef2f2;
            color: #dc2626;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.06);
            border-color: #1a1a1a !important;
        }

        .spinner {
            border: 2px solid #f0f0f0;
            border-top-color: #1a1a1a;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 0.7s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        #toast {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: translateY(20px);
            opacity: 0;
        }

        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .stok-bar {
            height: 3px;
            border-radius: 2px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .stok-bar-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s;
        }

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

        @media (max-width: 1023px) {
            body {
                padding-bottom: 76px;
            }

            .produk-main {
                padding-bottom: 5.5rem !important;
            }
        }

        @media (max-width: 640px) {
            #toast {
                left: 1rem;
                right: 1rem;
                bottom: 5rem;
                justify-content: center;
            }
        }

        /* Clean box style */
        .produk-main .summary-card,
        .produk-main .filter-card,
        .produk-main .table-card,
        .produk-main .produk-mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .produk-main input,
        .produk-main select,
        .produk-main textarea,
        .produk-main button {
            border-radius: 0 !important;
        }

        .produk-mobile-card {
            transition: border-color 0.15s ease;
        }

        .produk-mobile-card:hover {
            border-color: #e5e7eb;
        }

        .produk-thumb {
            width: 44px;
            height: 44px;
            border: 1px solid #f0f0f0;
            background: #fafafa;
            object-fit: cover;
            flex-shrink: 0;
        }

        .produk-thumb-empty {
            width: 44px;
            height: 44px;
            border: 1px solid #f0f0f0;
            background: #fafafa;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #d1d5db;
            flex-shrink: 0;
        }

        .gambar-preview-box {
            width: 96px;
            height: 96px;
            border: 1px dashed #d1d5db;
            background: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .gambar-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }


        .preview-image-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px
        }

        .preview-image-modal.show {
            display: flex
        }

        .preview-image-modal img {
            max-width: 95vw;
            max-height: 90vh;
            object-fit: contain;
            background: #fff
        }

        .preview-close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 9999px;
            background: #fff;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .2)
        }

        .preview-close-btn:hover {
            background: #f3f4f6
        }

        .scanner-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #111827;
            color: #fff;
            border: 1px solid #111827;
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .10em;
        }

        .scanner-btn:hover {
            background: #000;
        }


        /* ============================================================
           RESPONSIVE POLISH - TABLET & MOBILE
           Tampilan dibuat lebih rapat, seimbang, dan mudah disentuh.
           ============================================================ */
        @media (max-width: 1023px) {
            .produk-main {
                padding: 1rem !important;
                padding-bottom: 6.25rem !important;
            }

            .produk-main>.grid:first-child {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                gap: .75rem !important;
                margin-bottom: 1rem !important;
            }

            .summary-card {
                min-height: 104px;
                padding: .9rem !important;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                overflow: hidden;
            }

            .summary-card p:first-child {
                font-size: 9px !important;
                line-height: 1.25;
            }

            .summary-card p:nth-child(2) {
                font-size: 1.25rem !important;
                line-height: 1.2;
                word-break: break-word;
            }

            .summary-card p:last-child {
                font-size: 9px !important;
                line-height: 1.3;
            }

            .filter-card {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .75rem !important;
                padding: .875rem !important;
                margin-bottom: .875rem !important;
            }

            .filter-card>.relative:first-child {
                grid-column: 1 / -1;
                min-width: 0 !important;
            }

            .filter-card select,
            .filter-card button,
            .filter-card input {
                width: 100% !important;
                min-width: 0 !important;
                min-height: 44px;
                font-size: 12px !important;
            }

            .filter-card .scanner-btn {
                grid-column: 1 / -1;
            }

            .table-card {
                border-color: #e5e7eb !important;
                background: transparent !important;
            }

            .table-card>.lg\\:hidden {
                background: transparent;
            }

            .table-card .grid.grid-cols-1.md\\:grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .875rem !important;
                padding: .875rem !important;
            }

            .produk-mobile-card {
                min-width: 0;
                padding: 1rem !important;
                border-color: #e5e7eb !important;
                background: #fff !important;
            }

            .produk-mobile-card .produk-thumb,
            .produk-mobile-card .produk-thumb-empty {
                width: 56px;
                height: 56px;
            }

            .produk-mobile-card h3 {
                white-space: normal !important;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                min-height: 2.4em;
            }

            .produk-mobile-card .grid.grid-cols-2 {
                gap: .625rem !important;
            }

            .produk-mobile-card .grid.grid-cols-2>div {
                padding: .75rem !important;
                min-width: 0;
            }

            .produk-mobile-card .grid.grid-cols-2 p:last-child {
                font-size: 12px !important;
                word-break: break-word;
            }

            .produk-mobile-card .flex.items-center.gap-2.pt-3 {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: .5rem !important;
            }

            .produk-mobile-card .flex.items-center.gap-2.pt-3 button {
                width: 100%;
                min-width: 0;
                min-height: 40px;
                padding-left: .4rem !important;
                padding-right: .4rem !important;
            }

            .table-card>.px-4.md\\:px-5.py-4 {
                padding: .875rem !important;
                align-items: stretch !important;
            }

            .table-card>.px-4.md\\:px-5.py-4>.flex {
                overflow-x: auto;
                flex-wrap: nowrap !important;
                padding-bottom: .2rem;
                -webkit-overflow-scrolling: touch;
            }

            .table-card>.px-4.md\\:px-5.py-4 a {
                flex: 0 0 auto;
                min-height: 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            #modal-produk,
            #modal-stok {
                padding: .75rem !important;
                align-items: flex-end !important;
            }

            #modal-produk>div,
            #modal-stok>div {
                width: 100% !important;
                max-width: 100% !important;
                max-height: 94vh;
            }

            #modal-produk>div>div:nth-child(2) {
                max-height: calc(94vh - 142px) !important;
                padding: 1rem !important;
            }

            #modal-produk>div>div:first-child,
            #modal-produk>div>div:last-child,
            #modal-stok>div>div:first-child,
            #modal-stok>div>div:last-child {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            #modal-produk input,
            #modal-produk select,
            #modal-produk button,
            #modal-stok input,
            #modal-stok button {
                min-height: 44px;
                font-size: 16px;
            }

            .gambar-preview-box {
                width: 110px;
                height: 110px;
            }
        }

        @media (min-width: 641px) and (max-width: 1023px) {
            .produk-main {
                padding: 1rem !important;
            }

            .produk-main>.grid:first-child {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            .filter-card {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            .filter-card>.relative:first-child,
            .filter-card .scanner-btn {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 640px) {
            body {
                background: #f8fafc !important;
            }

            .produk-main {
                padding: .625rem !important;
                padding-bottom: 6.5rem !important;
            }

            .produk-main>.grid:first-child {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .5rem !important;
            }

            .summary-card {
                min-height: 92px;
                padding: .7rem !important;
            }

            .summary-card p:nth-child(2) {
                font-size: 1.05rem !important;
            }

            .filter-card {
                grid-template-columns: 1fr !important;
                gap: .625rem !important;
                padding: .75rem !important;
            }

            .filter-card>* {
                grid-column: auto !important;
            }

            .filter-card .scanner-btn {
                min-height: 46px;
            }

            .table-card .grid.grid-cols-1.md\\:grid-cols-2 {
                grid-template-columns: 1fr !important;
                padding: .625rem !important;
                gap: .625rem !important;
            }

            .produk-mobile-card {
                padding: .875rem !important;
            }

            .produk-mobile-card .flex.items-start.justify-between {
                gap: .625rem !important;
            }

            .produk-mobile-card .produk-thumb,
            .produk-mobile-card .produk-thumb-empty {
                width: 52px;
                height: 52px;
            }

            .produk-mobile-card .grid.grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .produk-mobile-card .mb-4.border.border-subtle.bg-gray-50.p-3 .flex {
                align-items: flex-start !important;
                flex-direction: column;
                gap: .35rem !important;
            }

            .produk-mobile-card .mb-4.border.border-subtle.bg-gray-50.p-3 span {
                text-align: left;
            }

            .table-card>.px-4.md\\:px-5.py-4 {
                flex-direction: column !important;
            }

            .table-card>.px-4.md\\:px-5.py-4>span {
                text-align: center;
                width: 100%;
            }

            #modal-produk,
            #modal-stok {
                padding: 0 !important;
            }

            #modal-produk>div,
            #modal-stok>div {
                max-height: 100vh;
                height: 100vh;
            }

            #modal-produk>div>div:nth-child(2) {
                max-height: calc(100vh - 142px) !important;
            }

            #modal-produk .grid.sm\\:grid-cols-2 {
                grid-template-columns: 1fr !important;
            }

            #modal-produk>div>div:last-child,
            #modal-stok>div>div:last-child {
                position: sticky;
                bottom: 0;
                z-index: 5;
            }

            .gambar-preview-box {
                width: 100%;
                height: 180px;
            }

            #toast {
                left: .75rem !important;
                right: .75rem !important;
                bottom: 5.25rem !important;
                max-width: none !important;
            }
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="produk-main p-4 sm:p-5 md:p-8 lg:p-10">

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total SKU</p>
                <p class="text-2xl font-bold"><?php echo number_format($summary['total']); ?></p>
                <p class="text-[10px] text-gray-400 mt-1"><?php echo $statusFilter === 'aktif' ? 'Produk aktif' : number_format($summary['aktif']) . ' aktif'; ?></p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Stok</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo rupiah($summary['nilai_stok'] ?? 0); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Estimasi HPP</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo $summary['low_stock'] > 0 ? 'border-yellow-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                <p class="text-2xl font-bold <?php echo $summary['low_stock'] > 0 ? 'text-yellow-600' : 'text-gray-800'; ?>">
                    <?php echo number_format($summary['low_stock']); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">di bawah minimum</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo $summary['habis'] > 0 ? 'border-red-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Habis</p>
                <p class="text-2xl font-bold <?php echo $summary['habis'] > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
                    <?php echo number_format($summary['habis']); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">perlu restock</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo ($summary['expired_soon'] ?? 0) > 0 ? 'border-yellow-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Akan Kedaluwarsa</p>
                <p class="text-2xl font-bold <?php echo ($summary['expired_soon'] ?? 0) > 0 ? 'text-yellow-600' : 'text-gray-800'; ?>">
                    <?php echo number_format((int)($summary['expired_soon'] ?? 0)); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">30 hari ke depan</p>
            </div>
            <div class="summary-card p-4 md:p-5 border <?php echo ($summary['expired'] ?? 0) > 0 ? 'border-red-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kedaluwarsa</p>
                <p class="text-2xl font-bold <?php echo ($summary['expired'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
                    <?php echo number_format((int)($summary['expired'] ?? 0)); ?>
                </p>
                <p class="text-[10px] text-gray-400 mt-1">tidak boleh dijual</p>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="filter-card p-4 mb-4 flex flex-col md:flex-row md:flex-wrap gap-3 items-stretch md:items-center">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="search-input" value="<?php echo e($search); ?>"
                    placeholder="Cari nama, kode, kategori, atau scan barcode..."
                    class="w-full bg-gray-50 border border-gray-100 rounded-sm pl-10 pr-4 py-2.5 text-sm focus:bg-white transition-all">
            </div>
            <select id="filter-kat" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="">Semua Kategori</option>
                <?php foreach ($kategoriList as $k): ?>
                    <option value="<?php echo e($k); ?>" <?php echo $katFilter === $k ? 'selected' : ''; ?>><?php echo e($k); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-status" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="aktif" <?php echo $statusFilter === 'aktif'    ? 'selected' : ''; ?>>Aktif</option>
                <option value="nonaktif" <?php echo $statusFilter === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                <option value="semua" <?php echo $statusFilter === 'semua'    ? 'selected' : ''; ?>>Semua Status</option>
            </select>
            <select id="filter-stok" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="" <?php echo $stokFilter === '' ? 'selected' : ''; ?>>Semua Stok</option>
                <option value="limit" <?php echo $stokFilter === 'limit' ? 'selected' : ''; ?>>Stok Limit</option>
                <option value="habis" <?php echo $stokFilter === 'habis' ? 'selected' : ''; ?>>Stok Habis</option>
            </select>
            <select id="filter-expired" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <option value="" <?php echo $expiredFilter === '' ? 'selected' : ''; ?>>Semua Kedaluwarsa</option>
                <option value="expired" <?php echo $expiredFilter === 'expired' ? 'selected' : ''; ?>>Sudah Kedaluwarsa</option>
                <option value="30_hari" <?php echo $expiredFilter === '30_hari' ? 'selected' : ''; ?>>30 Hari Lagi</option>
                <option value="90_hari" <?php echo $expiredFilter === '90_hari' ? 'selected' : ''; ?>>90 Hari Lagi</option>
                <option value="tanpa_tanggal" <?php echo $expiredFilter === 'tanpa_tanggal' ? 'selected' : ''; ?>>Tanpa Tanggal</option>
            </select>
            <select id="filter-limit" class="bg-gray-50 border border-gray-100 rounded-sm px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:bg-white transition-all">
                <?php foreach ([10, 15, 25, 50, 100] as $limitOption): ?>
                    <option value="<?php echo $limitOption; ?>" <?php echo $perPage === $limitOption ? 'selected' : ''; ?>><?php echo $limitOption; ?> / Hal</option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="openModal('tambah')" class="scanner-btn">
                Scan / Tambah Produk
            </button>
            <span class="text-xs text-gray-400 font-medium ml-auto hidden sm:block">
                <?php echo number_format($totalRows); ?> produk ditemukan
            </span>
        </div>

        <!-- Tabel & Card -->
        <div class="table-card overflow-hidden">

            <!-- ── DESKTOP: Tabel ──────────────────────────────────────────── -->
            <div class="hidden lg:block overflow-x-auto no-scrollbar">
                <table class="w-full text-left min-w-[780px]">
                    <thead class="border-b border-subtle bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 w-8">#</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kategori</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga Beli</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Harga Jual</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Stok</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Kedaluwarsa</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f5f5f5]">
                        <?php if (empty($produkList)): ?>
                            <tr>
                                <td colspan="9" class="py-20 text-center">
                                    <div class="inline-flex flex-col items-center gap-3 opacity-30">
                                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                        </svg>
                                        <p class="text-xs font-bold uppercase tracking-widest">Produk tidak ditemukan</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($produkList as $i => $p):
                                $isLow     = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                                $isHabis   = $p['stok'] <= 0;
                                $stokPct   = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                                $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                                $margin    = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                                $expiredDate = !empty($p['expired_date']) ? (string)$p['expired_date'] : '';
                                $isExpired = $expiredDate !== '' && $expiredDate < date('Y-m-d');
                                $isExpiredSoon = $expiredDate !== '' && !$isExpired && $expiredDate <= date('Y-m-d', strtotime('+30 days'));
                            ?>
                                <tr class="group">
                                    <td class="px-5 py-4 text-[11px] text-gray-300 font-medium"><?php echo $offset + $i + 1; ?></td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($p['gambar'])): ?>
                                                <img src="<?php echo e($p['gambar']); ?>" alt="<?php echo e($p['nama']); ?>" class="produk-thumb cursor-pointer" onclick="previewProdukImage(this.src)">
                                            <?php else: ?>
                                                <div class="produk-thumb-empty">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4-4a2 2 0 012.8 0L16 17m-2-2l1.5-1.5a2 2 0 012.8 0L20 15M4 6h16v12H4z" />
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <div class="font-semibold text-sm leading-tight"><?php echo e($p['nama']); ?></div>
                                                <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo e($p['kode']); ?> &middot; <?php echo e($p['satuan']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-1">
                                            <?php echo e($p['kategori']); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm text-gray-500"><?php echo rupiah($p['harga_beli']); ?></td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="text-sm font-bold"><?php echo rupiah($p['harga_jual']); ?></span>
                                        <?php if ($margin > 0): ?>
                                            <div class="text-[9px] text-green-500 font-bold text-right">+<?php echo $margin; ?>% margin</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <div class="inline-flex flex-col items-center gap-1 min-w-[60px]">
                                            <span class="text-sm font-bold <?php echo $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : ''); ?>">
                                                <?php echo number_format($p['stok']); ?>
                                                <span class="text-[9px] text-gray-400 font-normal"><?php echo e($p['satuan']); ?></span>
                                            </span>
                                            <div class="stok-bar w-14">
                                                <div class="stok-bar-fill <?php echo $stokColor; ?>" style="width:<?php echo $stokPct; ?>%"></div>
                                            </div>
                                            <span class="text-[9px] text-gray-400">min <?php echo $p['stok_minimum']; ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($expiredDate === ''): ?>
                                            <span class="text-[9px] font-bold uppercase px-2 py-1 bg-gray-100 text-gray-500">Tidak diisi</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="text-[9px] font-bold uppercase px-2 py-1 bg-red-50 text-red-700 border border-red-200">Expired<br><?php echo date('d/m/Y', strtotime($expiredDate)); ?></span>
                                        <?php elseif ($isExpiredSoon): ?>
                                            <span class="text-[9px] font-bold uppercase px-2 py-1 bg-yellow-50 text-yellow-700 border border-yellow-200"><?php echo date('d/m/Y', strtotime($expiredDate)); ?></span>
                                        <?php else: ?>
                                            <span class="text-[9px] font-bold uppercase px-2 py-1 bg-green-50 text-green-700 border border-green-200"><?php echo date('d/m/Y', strtotime($expiredDate)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="openStokModal(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>', <?php echo $p['stok']; ?>)"
                                                class="p-2 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 transition-all" title="Update Stok">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 4v8m0 0l4-4m-4 4l-4-4" />
                                                </svg>
                                            </button>
                                            <button onclick="editProduk(<?php echo $p['id']; ?>)"
                                                class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button onclick="hapusProduk(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>')"
                                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Hapus">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ── MOBILE & TABLET: Card ───────────────────────────────────── -->
            <div class="lg:hidden">
                <?php if (empty($produkList)): ?>
                    <div class="py-16 text-center">
                        <div class="inline-flex flex-col items-center gap-3 opacity-30">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <p class="text-xs font-bold uppercase tracking-widest">Produk tidak ditemukan</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($produkList as $i => $p):
                            $isLow     = $p['stok'] <= $p['stok_minimum'] && $p['stok'] > 0;
                            $isHabis   = $p['stok'] <= 0;
                            $stokPct   = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / max($p['stok_minimum'] * 2, 1) * 100)) : 100;
                            $stokColor = $isHabis ? 'bg-red-400' : ($isLow ? 'bg-yellow-400' : 'bg-green-400');
                            $margin    = $p['harga_beli'] > 0 ? round(($p['harga_jual'] - $p['harga_beli']) / $p['harga_jual'] * 100) : 0;
                            $expiredDate = !empty($p['expired_date']) ? (string)$p['expired_date'] : '';
                            $isExpired = $expiredDate !== '' && $expiredDate < date('Y-m-d');
                            $isExpiredSoon = $expiredDate !== '' && !$isExpired && $expiredDate <= date('Y-m-d', strtotime('+30 days'));
                        ?>
                            <div class="produk-mobile-card bg-white border border-subtle p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="min-w-0 flex items-start gap-3">
                                        <?php if (!empty($p['gambar'])): ?>
                                            <img src="<?php echo e($p['gambar']); ?>" alt="<?php echo e($p['nama']); ?>" class="produk-thumb cursor-pointer" onclick="previewProdukImage(this.src)">
                                        <?php else: ?>
                                            <div class="produk-thumb-empty">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4-4a2 2 0 012.8 0L16 17m-2-2l1.5-1.5a2 2 0 012.8 0L20 15M4 6h16v12H4z" />
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-[10px] text-gray-300 font-bold">#<?php echo $offset + $i + 1; ?></span>
                                                <span class="text-[10px] font-bold uppercase tracking-wide text-gray-500 bg-gray-100 px-2 py-0.5">
                                                    <?php echo e($p['kategori']); ?>
                                                </span>
                                            </div>
                                            <h3 class="font-bold text-sm leading-tight text-gray-900 truncate"><?php echo e($p['nama']); ?></h3>
                                            <p class="text-[10px] text-gray-400 font-mono mt-1 truncate"><?php echo e($p['kode']); ?> &middot; <?php echo e($p['satuan']); ?></p>
                                        </div>
                                    </div>
                                    <div class="shrink-0">
                                        <?php if ($p['status'] === 'aktif'): ?>
                                            <span class="badge-aktif text-[9px] font-bold uppercase px-2 py-1">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-nonaktif text-[9px] font-bold uppercase px-2 py-1">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Jual</p>
                                        <p class="text-sm font-black"><?php echo rupiah($p['harga_jual']); ?></p>
                                        <?php if ($margin > 0): ?>
                                            <p class="text-[9px] text-green-500 font-bold mt-1">+<?php echo $margin; ?>% margin</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="border border-subtle bg-gray-50 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Harga Beli</p>
                                        <p class="text-sm font-bold text-gray-600"><?php echo rupiah($p['harga_beli']); ?></p>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Stok</p>
                                        <span class="text-sm font-black <?php echo $isHabis ? 'text-red-600' : ($isLow ? 'text-yellow-600' : 'text-gray-900'); ?>">
                                            <?php echo number_format($p['stok']); ?>
                                            <span class="text-[9px] text-gray-400 font-normal"><?php echo e($p['satuan']); ?></span>
                                        </span>
                                    </div>
                                    <div class="stok-bar w-full">
                                        <div class="stok-bar-fill <?php echo $stokColor; ?>" style="width:<?php echo $stokPct; ?>%"></div>
                                    </div>
                                    <p class="text-[9px] text-gray-400 mt-1">Minimum <?php echo $p['stok_minimum']; ?></p>
                                </div>
                                <div class="mb-4 border border-subtle bg-gray-50 p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Tanggal Kedaluwarsa</p>
                                        <?php if ($expiredDate === ''): ?>
                                            <span class="text-[10px] font-bold text-gray-500">Tidak diisi</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="text-[10px] font-black text-red-600"><?php echo date('d/m/Y', strtotime($expiredDate)); ?> · EXPIRED</span>
                                        <?php elseif ($isExpiredSoon): ?>
                                            <span class="text-[10px] font-black text-yellow-600"><?php echo date('d/m/Y', strtotime($expiredDate)); ?> · SEGERA</span>
                                        <?php else: ?>
                                            <span class="text-[10px] font-black text-green-600"><?php echo date('d/m/Y', strtotime($expiredDate)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 pt-3 border-t border-subtle">
                                    <button onclick="openStokModal(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>', <?php echo $p['stok']; ?>)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-yellow-100 text-yellow-700 hover:bg-yellow-50 transition-all">
                                        Stok
                                    </button>
                                    <button onclick="editProduk(<?php echo $p['id']; ?>)"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-blue-100 text-blue-700 hover:bg-blue-50 transition-all">
                                        Edit
                                    </button>
                                    <button onclick="hapusProduk(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['nama'])); ?>')"
                                        class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest border border-red-100 text-red-700 hover:bg-red-50 transition-all">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 md:px-5 py-4 border-t border-subtle flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between bg-gray-50">
                    <span class="text-xs text-gray-400">
                        Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?> total · <?php echo $perPage; ?>/hal)
                    </span>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo e(produk_page_url($page - 1, $search, $katFilter, $statusFilter, $stokFilter, $expiredFilter, $perPage)); ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle hover:bg-white transition-all">&larr; Prev</a>
                        <?php endif; ?>
                        <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                            <a href="<?php echo e(produk_page_url($pg, $search, $katFilter, $statusFilter, $stokFilter, $expiredFilter, $perPage)); ?>"
                                class="px-3 py-1.5 text-xs font-bold transition-all <?php echo $pg === $page ? 'bg-black text-white' : 'border border-subtle hover:bg-white'; ?>">
                                <?php echo $pg; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo e(produk_page_url($page + 1, $search, $katFilter, $statusFilter, $stokFilter, $expiredFilter, $perPage)); ?>"
                                class="px-3 py-1.5 text-xs font-bold border border-subtle hover:bg-white transition-all">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ── MODAL: Tambah / Edit Produk ─────────────────────────────────────── -->
    <div id="modal-produk" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-lg shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 md:px-7 py-5 border-b border-subtle">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-widest" id="modal-title">Tambah Produk</h2>
                    <p class="text-[10px] text-gray-400 mt-0.5" id="modal-subtitle">Isi form di bawah dengan benar</p>
                </div>
                <button onclick="closeModal()" class="p-2 hover:bg-gray-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="px-5 md:px-7 py-6 space-y-5 max-h-[75vh] overflow-y-auto no-scrollbar">
                <input type="hidden" id="form-id">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kode Produk *</label>
                        <input type="text" id="form-kode" placeholder="Scan barcode / ketik kode produk"
                            inputmode="numeric" autocomplete="off"
                            onkeydown="handleKodeProdukKeydown(event)"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm font-mono transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Satuan</label>
                        <select id="form-satuan" class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                            <option value="pcs">pcs</option>
                            <option value="botol">botol</option>
                            <option value="kotak">kotak</option>
                            <option value="kg">kg</option>
                            <option value="liter">liter</option>
                            <option value="lusin">lusin</option>
                            <option value="pack">pack</option>
                            <option value="karton">karton</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Nama Produk *</label>
                    <input type="text" id="form-nama" placeholder="Nama lengkap produk"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Gambar Produk</label>
                    <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                        <div class="gambar-preview-box" id="gambar-preview-box">
                            <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Preview</span>
                        </div>
                        <div class="flex-1">
                            <input type="file" id="form-gambar" accept="image/jpeg,image/png,image/webp,image/gif"
                                class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                            <input type="hidden" id="form-gambar-current">
                            <label id="hapus-gambar-wrap" class="hidden mt-2 items-center gap-2 text-xs font-bold text-red-600 cursor-pointer">
                                <input type="checkbox" id="form-hapus-gambar" value="1" class="accent-red-600">
                                Hapus foto produk saat disimpan
                            </label>
                            <p class="text-[9px] text-gray-400 mt-1">Foto opsional. Format JPG, PNG, WEBP, atau GIF. Maksimal 2MB. Jika gagal tampil, cek permission folder uploads/produk.</p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kategori</label>

                    <select id="form-kategori-select"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all mb-2">
                        <option value="">Pilih kategori yang sudah ada</option>
                        <?php foreach ($kategoriList as $k): ?>
                            <option value="<?php echo e($k); ?>"><?php echo e($k); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" id="form-kategori-baru" placeholder="Atau ketik kategori baru..."
                        list="kat-list"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">

                    <datalist id="kat-list">
                        <?php foreach ($kategoriList as $k): ?>
                            <option value="<?php echo e($k); ?>">
                            <?php endforeach; ?>
                    </datalist>

                    <p class="text-[9px] text-gray-400 mt-1">
                        Kalau kategori baru diisi, sistem akan otomatis menyimpannya ke produk dan muncul di dropdown berikutnya.
                    </p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Beli</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-beli" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Harga Jual *</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 font-bold">Rp</span>
                            <input type="number" id="form-harga-jual" placeholder="0" min="0"
                                class="w-full bg-gray-50 border border-gray-200 pl-9 pr-3 py-2.5 text-sm transition-all">
                        </div>
                        <p class="text-[9px] text-green-500 font-bold mt-1" id="margin-info"></p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Awal</label>
                        <input type="number" id="form-stok" placeholder="0" min="0"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Stok Minimum</label>
                        <input type="number" id="form-stok-min" placeholder="5" min="0"
                            class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                        <p class="text-[9px] text-gray-400 mt-1">Batas peringatan stok limit</p>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Tanggal Kedaluwarsa</label>
                    <input type="date" id="form-expired-date"
                        class="w-full bg-gray-50 border border-gray-200 px-3 py-2.5 text-sm transition-all">
                    <p class="text-[9px] text-gray-400 mt-1">Opsional. Kosongkan untuk produk yang tidak memiliki masa kedaluwarsa.</p>
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Status</label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="form-status" value="aktif" checked class="accent-black">
                            <span class="text-sm font-medium">Aktif</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="form-status" value="nonaktif" class="accent-black">
                            <span class="text-sm font-medium">Nonaktif</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="px-5 md:px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3">
                <button onclick="closeModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle hover:bg-white transition-all">Batal</button>
                <button onclick="simpanProduk()" id="btn-simpan"
                    class="flex-1 py-3 text-xs font-bold uppercase bg-black text-white hover:bg-gray-800 transition-all">Simpan</button>
            </div>
        </div>
    </div>

    <!-- ── MODAL: Update Stok ───────────────────────────────────────────────── -->
    <div id="modal-stok" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-sm shadow-2xl overflow-hidden">
            <div class="px-7 py-5 border-b border-subtle">
                <h2 class="text-sm font-black uppercase tracking-widest">Update Stok</h2>
                <p class="text-xs text-gray-500 mt-0.5 font-medium" id="stok-nama-label">&mdash;</p>
            </div>
            <div class="px-7 py-6 space-y-4">
                <input type="hidden" id="stok-id">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Jumlah Stok Baru</label>
                    <input type="number" id="stok-baru" min="0"
                        class="w-full bg-gray-50 border border-gray-200 px-4 py-3 text-lg font-bold text-center transition-all">
                </div>
                <div class="flex gap-2">
                    <?php foreach ([5, 10, 20, 50] as $jml): ?>
                        <button onclick="document.getElementById('stok-baru').value = <?php echo $jml; ?>"
                            class="flex-1 py-2 text-xs font-bold border border-subtle hover:bg-gray-100 transition-all">
                            +<?php echo $jml; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="px-7 py-5 border-t border-subtle bg-gray-50 flex gap-3">
                <button onclick="closeStokModal()" class="flex-1 py-3 text-xs font-bold uppercase border border-subtle hover:bg-white transition-all">Batal</button>
                <button onclick="simpanStok()" class="flex-1 py-3 text-xs font-bold uppercase bg-yellow-500 text-white hover:bg-yellow-600 transition-all">Update Stok</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 shadow-2xl pointer-events-none">
        <span id="toast-icon"></span>
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

    <script>
        var editMode = false;

        // ── Live Filter ─────────────────────────────────────────────────────────────
        var searchTimer;
        document.getElementById('search-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('filter-kat').addEventListener('change', applyFilter);
        document.addEventListener('DOMContentLoaded', function() {
            var kategoriSelect = document.getElementById('form-kategori-select');
            if (kategoriSelect) {
                kategoriSelect.addEventListener('change', function() {
                    if (this.value !== '') {
                        document.getElementById('form-kategori-baru').value = '';
                    }
                });
            }
        });
        document.getElementById('filter-status').addEventListener('change', applyFilter);
        document.getElementById('filter-stok').addEventListener('change', applyFilter);
        document.getElementById('filter-expired').addEventListener('change', applyFilter);
        document.getElementById('filter-limit').addEventListener('change', applyFilter);

        document.addEventListener('DOMContentLoaded', function() {
            var kodeInput = document.getElementById('form-kode');
            if (kodeInput) {
                kodeInput.addEventListener('input', function() {
                    var clean = normalizeBarcodeValue(this.value);
                    if (this.value !== clean) {
                        this.value = clean;
                    }
                });
            }

            var searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.value = normalizeBarcodeValue(this.value);
                        applyFilter();
                    }
                });
            }
        });


        function applyFilter() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('search-input').value);
            url.searchParams.set('kat', document.getElementById('filter-kat').value);
            url.searchParams.set('status', document.getElementById('filter-status').value);
            url.searchParams.set('stok', document.getElementById('filter-stok').value);
            url.searchParams.set('expired', document.getElementById('filter-expired').value);
            url.searchParams.set('limit', document.getElementById('filter-limit').value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        // ── Modal Produk ─────────────────────────────────────────────────────────────
        function openModal(mode) {
            mode = mode || 'tambah';
            editMode = mode === 'edit';
            document.getElementById('modal-title').innerText = editMode ? 'Edit Produk' : 'Tambah Produk';
            document.getElementById('modal-subtitle').innerText = editMode ? 'Ubah data produk di bawah' : 'Isi form di bawah dengan benar';
            document.getElementById('modal-produk').style.display = 'flex';

            if (!editMode) {
                focusKodeProduk();
                startProdukKodeAutoFocus();
            }
        }

        function closeModal() {
            document.getElementById('modal-produk').style.display = 'none';
            resetForm();
        }

        function getKategoriProduk() {
            var baru = document.getElementById('form-kategori-baru').value.trim();
            var pilih = document.getElementById('form-kategori-select').value.trim();

            if (baru !== '') {
                return baru;
            }

            if (pilih !== '') {
                return pilih;
            }

            return '-';
        }

        function setKategoriProduk(kategori) {
            kategori = String(kategori || '').trim();

            var select = document.getElementById('form-kategori-select');
            var inputBaru = document.getElementById('form-kategori-baru');
            var found = false;

            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value === kategori) {
                    found = true;
                    break;
                }
            }

            if (found) {
                select.value = kategori;
                inputBaru.value = '';
            } else {
                select.value = '';
                inputBaru.value = kategori === '-' ? '' : kategori;
            }
        }

        function resetForm() {
            ['form-id', 'form-kode', 'form-nama', 'form-kategori-baru', 'form-harga-beli', 'form-harga-jual', 'form-stok', 'form-stok-min', 'form-expired-date', 'form-gambar-current'].forEach(function(id) {
                document.getElementById(id).value = '';
            });
            var gambarInput = document.getElementById('form-gambar');
            if (gambarInput) gambarInput.value = '';
            var hapusGambar = document.getElementById('form-hapus-gambar');
            if (hapusGambar) hapusGambar.checked = false;
            var hapusWrap = document.getElementById('hapus-gambar-wrap');
            if (hapusWrap) {
                hapusWrap.classList.add('hidden');
                hapusWrap.classList.remove('flex');
            }
            setGambarPreview('');
            document.getElementById('form-satuan').value = 'pcs';
            document.getElementById('form-kategori-select').value = '';
            document.querySelector('input[name="form-status"][value="aktif"]').checked = true;
            document.getElementById('margin-info').innerText = '';
        }

        async function editProduk(id) {
            try {
                var res = await fetch('produk.php?action=get&id=' + id, {
                    method: 'POST'
                });
                var data = await res.json();
                if (!data.success) {
                    showToast(data.message, 'error');
                    return;
                }
                var p = data.data;
                document.getElementById('form-id').value = p.id;
                document.getElementById('form-kode').value = p.kode;
                document.getElementById('form-nama').value = p.nama;
                setKategoriProduk(p.kategori);
                document.getElementById('form-harga-beli').value = p.harga_beli;
                document.getElementById('form-harga-jual').value = p.harga_jual;
                document.getElementById('form-stok').value = p.stok;
                document.getElementById('form-stok-min').value = p.stok_minimum;
                document.getElementById('form-expired-date').value = p.expired_date || '';
                document.getElementById('form-satuan').value = p.satuan;
                document.getElementById('form-gambar-current').value = p.gambar || '';
                setGambarPreview(p.gambar || '');
                var hapusWrap = document.getElementById('hapus-gambar-wrap');
                var hapusGambar = document.getElementById('form-hapus-gambar');
                if (hapusGambar) hapusGambar.checked = false;
                if (hapusWrap) {
                    if (p.gambar) {
                        hapusWrap.classList.remove('hidden');
                        hapusWrap.classList.add('flex');
                    } else {
                        hapusWrap.classList.add('hidden');
                        hapusWrap.classList.remove('flex');
                    }
                }
                document.querySelector('input[name="form-status"][value="' + p.status + '"]').checked = true;
                hitungMargin();
                openModal('edit');
            } catch (e) {
                showToast('Gagal memuat data produk.', 'error');
            }
        }

        async function simpanProduk() {
            var id = document.getElementById('form-id').value;
            var action = id ? 'edit' : 'tambah';
            var btn = document.getElementById('btn-simpan');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span>';

            var payload = new FormData();
            if (id) payload.append('id', id);
            payload.append('kode', normalizeBarcodeValue(document.getElementById('form-kode').value));
            payload.append('nama', document.getElementById('form-nama').value.trim());
            payload.append('kategori', getKategoriProduk());
            payload.append('harga_beli', document.getElementById('form-harga-beli').value || 0);
            payload.append('harga_jual', document.getElementById('form-harga-jual').value);
            payload.append('stok', document.getElementById('form-stok').value || 0);
            payload.append('stok_minimum', document.getElementById('form-stok-min').value || 5);
            payload.append('expired_date', document.getElementById('form-expired-date').value || '');
            payload.append('satuan', document.getElementById('form-satuan').value);
            payload.append('status', document.querySelector('input[name="form-status"]:checked').value);
            var gambarInput = document.getElementById('form-gambar');
            if (gambarInput && gambarInput.files && gambarInput.files[0]) {
                payload.append('gambar_produk', gambarInput.files[0]);
            }
            var hapusGambar = document.getElementById('form-hapus-gambar');
            if (hapusGambar && hapusGambar.checked) {
                payload.append('hapus_gambar', '1');
            }

            try {
                var res = await fetch('produk.php?action=' + action, {
                    method: 'POST',
                    body: payload
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Simpan';
            }
        }

        async function hapusProduk(id, nama) {
            if (!confirm('Hapus produk "' + nama + '"?\n\nJika pernah ada di transaksi, produk akan dinonaktifkan.')) return;
            try {
                var res = await fetch('produk.php?action=hapus', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Modal Stok ───────────────────────────────────────────────────────────────
        function openStokModal(id, nama, stokSekarang) {
            document.getElementById('stok-id').value = id;
            document.getElementById('stok-nama-label').innerText = nama;
            document.getElementById('stok-baru').value = stokSekarang;
            document.getElementById('modal-stok').style.display = 'flex';
            setTimeout(function() {
                document.getElementById('stok-baru').select();
            }, 100);
        }

        function closeStokModal() {
            document.getElementById('modal-stok').style.display = 'none';
        }

        async function simpanStok() {
            var id = document.getElementById('stok-id').value;
            var stok = document.getElementById('stok-baru').value;
            if (stok === '' || stok < 0) {
                showToast('Masukkan jumlah stok yang valid.', 'error');
                return;
            }
            try {
                var res = await fetch('produk.php?action=update_stok', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        stok: parseInt(stok)
                    })
                });
                var data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeStokModal();
                    setTimeout(function() {
                        location.reload();
                    }, 800);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (e) {
                showToast('Terjadi kesalahan.', 'error');
            }
        }

        // ── Hitung Margin ────────────────────────────────────────────────────────────
        function hitungMargin() {
            var beli = parseInt(document.getElementById('form-harga-beli').value || 0);
            var jual = parseInt(document.getElementById('form-harga-jual').value || 0);
            var info = document.getElementById('margin-info');
            if (beli > 0 && jual > 0) {
                var margin = Math.round((jual - beli) / jual * 100);
                info.innerText = margin >= 0 ? 'Margin: +' + margin + '%' : 'Margin: ' + margin + '% (rugi)';
                info.className = 'text-[9px] font-bold mt-1 ' + (margin >= 0 ? 'text-green-500' : 'text-red-500');
            } else {
                info.innerText = '';
            }
        }
        document.getElementById('form-harga-beli').addEventListener('input', hitungMargin);
        document.getElementById('form-harga-jual').addEventListener('input', hitungMargin);


        function setGambarPreview(src) {
            var box = document.getElementById('gambar-preview-box');
            if (!box) return;
            if (src) {
                box.innerHTML = '<img src="' + src + '" alt="Preview gambar produk">';
            } else {
                box.innerHTML = '<span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Preview</span>';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var inputGambar = document.getElementById('form-gambar');
            if (inputGambar) {
                inputGambar.addEventListener('change', function() {
                    var file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file) {
                        setGambarPreview(document.getElementById('form-gambar-current').value || '');
                        return;
                    }
                    if (file.size > 2 * 1024 * 1024) {
                        showToast('Ukuran gambar maksimal 2MB.', 'error');
                        this.value = '';
                        return;
                    }
                    var hapusGambar = document.getElementById('form-hapus-gambar');
                    if (hapusGambar) hapusGambar.checked = false;
                    setGambarPreview(URL.createObjectURL(file));
                });
            }
        });




        // ── Barcode Scanner USB HID Keyboard (Kassen) ───────────────────────────────
        // Scanner Kassen bekerja seperti keyboard: mengirim kode lalu Enter.
        // Fungsi ini sengaja dibuat global karena dipanggil langsung dari atribut HTML.
        var produkKodeFocusTimer = null;

        function normalizeBarcodeValue(value) {
            value = String(value || '');

            // Bersihkan karakter bawaan scanner: Enter, Tab, spasi, invisible char.
            value = value.replace(/[\r\n\t]/g, '');
            value = value.replace(/[\u200B-\u200D\uFEFF]/g, '');
            value = value.trim();

            // Jika scanner mengirim format aneh seperti ]C18991002100017,
            // ambil angka utamanya agar tetap cocok dengan kode produk.
            var numericOnly = value.replace(/[^0-9]/g, '');
            if (numericOnly.length >= 6 && numericOnly.length <= 32) {
                return numericOnly;
            }

            return value;
        }

        function focusKodeProduk() {
            var kode = document.getElementById('form-kode');
            if (!kode) return;
            kode.focus();
            kode.select();
        }

        function handleKodeProdukKeydown(e) {
            e = e || window.event;
            var input = document.getElementById('form-kode');
            if (!input) return true;

            // Scanner Kassen mengirim Enter setelah kode terbaca.
            if (e.key === 'Enter' || e.keyCode === 13) {
                if (e.preventDefault) e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();

                input.value = normalizeBarcodeValue(input.value);

                if (input.value === '') {
                    showToast('Barcode belum terbaca. Silakan scan ulang.', 'error');
                    focusKodeProduk();
                    return false;
                }

                // Setelah barcode masuk, lanjut ke Nama Produk agar input manual berikutnya cepat.
                var nama = document.getElementById('form-nama');
                if (nama) {
                    setTimeout(function() {
                        nama.focus();
                        if (nama.value === '') nama.select();
                    }, 50);
                }

                showToast('Barcode terbaca: ' + input.value, 'success');
                return false;
            }

            return true;
        }

        function startProdukKodeAutoFocus() {
            clearInterval(produkKodeFocusTimer);

            produkKodeFocusTimer = setInterval(function() {
                var modal = document.getElementById('modal-produk');
                var kode = document.getElementById('form-kode');
                var active = document.activeElement;

                if (!modal || modal.style.display === 'none' || editMode) {
                    clearInterval(produkKodeFocusTimer);
                    produkKodeFocusTimer = null;
                    return;
                }

                if (!kode) return;

                // Jangan rebut fokus saat user sedang isi field lain.
                if (active && active !== document.body && active !== kode) {
                    var tag = (active.tagName || '').toLowerCase();
                    if (['input', 'textarea', 'select', 'button'].indexOf(tag) !== -1) {
                        return;
                    }
                }

                kode.focus();
            }, 700);

            setTimeout(focusKodeProduk, 120);
            setTimeout(focusKodeProduk, 350);
        }

        document.addEventListener('click', function(e) {
            var modal = document.getElementById('modal-produk');
            if (modal && modal.style.display !== 'none' && !editMode) {
                var tag = (e.target && e.target.tagName || '').toLowerCase();
                if (!['input', 'textarea', 'select', 'button'].includes(tag)) {
                    startProdukKodeAutoFocus();
                }
            }
        });

        // ── Toast ────────────────────────────────────────────────────────────────────
        var toastTimer;

        function showToast(msg, type) {
            type = type || 'success';
            var toast = document.getElementById('toast');
            var icon = document.getElementById('toast-icon');
            document.getElementById('toast-msg').innerText = msg;
            icon.innerHTML = type === 'success' ?
                '<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' :
                '<svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function() {
                toast.classList.remove('show');
            }, 3000);
        }

        // ── Close on overlay click ───────────────────────────────────────────────────
        document.getElementById('modal-produk').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('modal-stok').addEventListener('click', function(e) {
            if (e.target === this) closeStokModal();
        });
    </script>

    <div id="preview-image-modal" class="preview-image-modal" onclick="closePreviewProdukImage()">
        <button type="button" class="preview-close-btn" onclick="event.stopPropagation();closePreviewProdukImage();">✕</button>
        <img id="preview-image-target" src="" alt="Preview Produk">
    </div>

    <script>
        function previewProdukImage(src) {
            document.getElementById('preview-image-target').src = src;
            document.getElementById('preview-image-modal').classList.add('show');
        }

        function closePreviewProdukImage() {
            document.getElementById('preview-image-modal').classList.remove('show');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePreviewProdukImage();
            }
        });
    </script>
</body>

</html>