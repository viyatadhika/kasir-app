<?php
/*
|--------------------------------------------------------------------------
| air_pesanan_import.php — Input / Import Pesanan Lama
|--------------------------------------------------------------------------
| Fitur:
| - Input manual pesanan historis
| - Import CSV banyak pesanan sekaligus
| - Menandai data sebagai sumber_data = migrasi
| - Tidak mengirim WhatsApp / tracking otomatis
| - Mendukung banyak lokasi dan banyak produk per pesanan
| - Compatible PHP 7 & PHP 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'air_pesanan';
$pageTitle  = 'Input Pesanan Lama';
$backUrl    = 'air_pesanan.php';

date_default_timezone_set('Asia/Jakarta');

if (!function_exists('api_h')) {
    /**
     * @param mixed $value
     * @return string
     */
    function api_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('api_table_exists')) {
    function api_table_exists(PDO $pdo, string $table): bool
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

if (!function_exists('api_column_exists')) {
    function api_column_exists(PDO $pdo, string $table, string $column): bool
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

if (!function_exists('api_csrf_token')) {
    function api_csrf_token(): string
    {
        if (empty($_SESSION['air_import_csrf'])) {
            $_SESSION['air_import_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['air_import_csrf'];
    }
}

if (!function_exists('api_normalize_status')) {
    function api_normalize_status(string $status): string
    {
        $status = strtolower(trim($status));
        $map = [
            'baru' => 'baru',
            'diproses' => 'diproses',
            'proses' => 'diproses',
            'siap dikirim' => 'siap_dikirim',
            'siap_dikirim' => 'siap_dikirim',
            'selesai' => 'selesai',
            'batal' => 'batal',
            'dibatalkan' => 'batal',
        ];

        return $map[$status] ?? 'selesai';
    }
}

if (!function_exists('api_parse_date')) {
    function api_parse_date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
                return $dt->format('Y-m-d');
            }
        }

        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : '';
    }
}

if (!function_exists('api_ensure_schema')) {
    function api_ensure_schema(PDO $pdo): void
    {
        if (!api_table_exists($pdo, 'air_pesanan')) {
            throw new RuntimeException('Tabel air_pesanan belum tersedia.');
        }

        if (!api_column_exists($pdo, 'air_pesanan', 'sumber_data')) {
            $pdo->exec("
                ALTER TABLE air_pesanan
                ADD COLUMN sumber_data VARCHAR(30) NOT NULL DEFAULT 'publik'
                AFTER catatan
            ");
        }

        if (!api_column_exists($pdo, 'air_pesanan', 'is_historical')) {
            $pdo->exec("
                ALTER TABLE air_pesanan
                ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0
                AFTER sumber_data
            ");
        }

        if (!api_column_exists($pdo, 'air_pesanan', 'updated_at')) {
            $pdo->exec("
                ALTER TABLE air_pesanan
                ADD COLUMN updated_at DATETIME NULL
                AFTER created_at
            ");
        }
    }
}

if (!function_exists('api_find_or_create_customer')) {
    function api_find_or_create_customer(PDO $pdo, string $nama, string $noHp): int
    {
        $noHp = trim($noHp);

        if ($noHp !== '') {
            $stmt = $pdo->prepare("
                SELECT id
                FROM air_pelanggan
                WHERE no_hp = :no_hp
                LIMIT 1
            ");
            $stmt->execute([':no_hp' => $noHp]);
            $id = (int)$stmt->fetchColumn();

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE air_pelanggan
                    SET nama = :nama,
                        status = 'aktif',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':nama' => $nama,
                    ':id'   => $id,
                ]);
                return $id;
            }
        }

        $kode = 'PLG-M-' . date('ymdHis') . '-' . random_int(10, 99);

        $stmt = $pdo->prepare("
            INSERT INTO air_pelanggan
                (kode_pelanggan, nama, no_hp, alamat, status, created_at)
            VALUES
                (:kode, :nama, :no_hp, '', 'aktif', NOW())
        ");
        $stmt->execute([
            ':kode'  => $kode,
            ':nama'  => $nama,
            ':no_hp' => $noHp,
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('api_find_product')) {
    /**
     * @return array<string,mixed>|null
     */
    function api_find_product(PDO $pdo, string $kode, string $nama = ''): ?array
    {
        $whereDeleted = api_column_exists($pdo, 'air_produk', 'is_deleted')
            ? " AND COALESCE(is_deleted,0)=0"
            : "";

        if ($kode !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM air_produk
                WHERE kode_produk = :kode
                  $whereDeleted
                LIMIT 1
            ");
            $stmt->execute([':kode' => $kode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($nama !== '') {
            $stmt = $pdo->prepare("
                SELECT *
                FROM air_produk
                WHERE nama_produk = :nama
                  $whereDeleted
                LIMIT 1
            ");
            $stmt->execute([':nama' => $nama]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('api_generate_old_order_number')) {
    function api_generate_old_order_number(string $tanggal): string
    {
        $stamp = preg_replace('/[^0-9]/', '', $tanggal);
        if ($stamp === '') {
            $stamp = date('Ymd');
        }

        return 'AIR-LAMA-' . $stamp . '-' . random_int(1000, 9999);
    }
}

if (!function_exists('api_insert_order')) {
    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $items
     */
    function api_insert_order(PDO $pdo, array $data, array $items): int
    {
        $nomor = trim((string)($data['nomor_pesanan'] ?? ''));
        $tanggalPesan = api_parse_date((string)($data['tanggal_pemesanan'] ?? ''));
        $tanggalKirim = api_parse_date((string)($data['tanggal_kirim'] ?? ''));
        $nama = trim((string)($data['nama_pemesan'] ?? ''));
        $noHp = trim((string)($data['no_wa'] ?? ''));
        $status = api_normalize_status((string)($data['status'] ?? 'selesai'));
        $catatan = trim((string)($data['catatan'] ?? ''));

        if ($tanggalPesan === '') {
            throw new RuntimeException('Tanggal pemesanan tidak valid.');
        }

        if ($tanggalKirim === '') {
            $tanggalKirim = $tanggalPesan;
        }

        if ($nama === '') {
            throw new RuntimeException('Nama pemesan wajib diisi.');
        }

        if (!$items) {
            throw new RuntimeException('Minimal satu produk harus diisi.');
        }

        if ($nomor === '') {
            $nomor = api_generate_old_order_number($tanggalPesan);
        }

        $stmtCheck = $pdo->prepare("
            SELECT id
            FROM air_pesanan
            WHERE nomor_pesanan = :nomor
            LIMIT 1
        ");
        $stmtCheck->execute([':nomor' => $nomor]);

        if ($stmtCheck->fetchColumn()) {
            throw new RuntimeException('Nomor pesanan ' . $nomor . ' sudah ada.');
        }

        $pelangganId = api_find_or_create_customer($pdo, $nama, $noHp);

        $stmtOrder = $pdo->prepare("
            INSERT INTO air_pesanan (
                nomor_pesanan,
                pelanggan_id,
                user_id,
                tanggal_pemesanan,
                tipe_pengambilan,
                alamat_pengiriman,
                tanggal_kirim,
                jam_kirim,
                status,
                metode_pembayaran,
                status_pembayaran,
                total,
                dibayar,
                galon_kosong_diterima,
                galon_dipinjamkan,
                deposit_galon,
                catatan,
                sumber_data,
                is_historical,
                created_at,
                updated_at
            ) VALUES (
                :nomor,
                :pelanggan_id,
                NULL,
                :tanggal_pemesanan,
                'antar',
                'Data Historis',
                :tanggal_kirim,
                NULL,
                :status,
                'tunai',
                'belum_bayar',
                0,
                0,
                0,
                0,
                0,
                :catatan,
                'migrasi',
                1,
                :created_at,
                NOW()
            )
        ");

        $stmtOrder->execute([
            ':nomor'             => $nomor,
            ':pelanggan_id'      => $pelangganId,
            ':tanggal_pemesanan' => $tanggalPesan,
            ':tanggal_kirim'     => $tanggalKirim,
            ':status'            => $status,
            ':catatan'           => $catatan,
            ':created_at'        => $tanggalPesan . ' 08:00:00',
        ]);

        $pesananId = (int)$pdo->lastInsertId();

        $locations = [];

        foreach ($items as $item) {
            $lokasi = trim((string)($item['lokasi'] ?? ''));
            $kodeProduk = strtoupper(trim((string)($item['kode_produk'] ?? '')));
            $namaProduk = trim((string)($item['nama_produk'] ?? ''));
            $qty = max(0, (int)($item['qty'] ?? 0));

            if ($lokasi === '' || $qty <= 0) {
                continue;
            }

            global $lokasiPilihan;

            if (!in_array($lokasi, $lokasiPilihan, true)) {
                throw new RuntimeException('Lokasi pengantaran tidak valid: ' . $lokasi);
            }

            $product = api_find_product($pdo, $kodeProduk, $namaProduk);
            if (!$product) {
                throw new RuntimeException(
                    'Produk tidak ditemukan: ' .
                        ($kodeProduk !== '' ? $kodeProduk : $namaProduk)
                );
            }

            if (!isset($locations[$lokasi])) {
                $stmtLoc = $pdo->prepare("
                    INSERT INTO air_pesanan_lokasi
                        (pesanan_id, lokasi, urutan, catatan, created_at)
                    VALUES
                        (:pesanan_id, :lokasi, :urutan, '', :created_at)
                ");
                $stmtLoc->execute([
                    ':pesanan_id' => $pesananId,
                    ':lokasi'      => $lokasi,
                    ':urutan'      => count($locations) + 1,
                    ':created_at'  => $tanggalPesan . ' 08:00:00',
                ]);
                $locations[$lokasi] = (int)$pdo->lastInsertId();
            }

            $stmtDetail = $pdo->prepare("
                INSERT INTO air_pesanan_detail (
                    pesanan_id,
                    lokasi_id,
                    produk_id,
                    kode_produk,
                    nama_produk,
                    harga,
                    qty,
                    subtotal,
                    catatan_item,
                    created_at
                ) VALUES (
                    :pesanan_id,
                    :lokasi_id,
                    :produk_id,
                    :kode_produk,
                    :nama_produk,
                    0,
                    :qty,
                    0,
                    '',
                    :created_at
                )
            ");

            $stmtDetail->execute([
                ':pesanan_id' => $pesananId,
                ':lokasi_id'   => $locations[$lokasi],
                ':produk_id'   => (int)$product['id'],
                ':kode_produk' => (string)$product['kode_produk'],
                ':nama_produk' => (string)$product['nama_produk'],
                ':qty'         => $qty,
                ':created_at'  => $tanggalPesan . ' 08:00:00',
            ]);
        }

        if (!$locations) {
            throw new RuntimeException('Tidak ada lokasi/produk valid yang dapat disimpan.');
        }

        return $pesananId;
    }
}

$flash = '';
$flashType = 'success';
$importResult = [
    'berhasil' => 0,
    'gagal' => 0,
    'errors' => [],
];

try {
    api_ensure_schema($pdo);
} catch (Throwable $e) {
    $flash = 'Gagal menyiapkan halaman migrasi: ' . $e->getMessage();
    $flashType = 'error';
}

$lokasiPilihan = [
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

$produkList = [];
try {
    $whereDeleted = api_column_exists($pdo, 'air_produk', 'is_deleted')
        ? " AND COALESCE(is_deleted,0)=0"
        : "";

    $produkList = $pdo->query("
        SELECT id, kode_produk, nama_produk, satuan
        FROM air_produk
        WHERE status = 'aktif'
        $whereDeleted
        ORDER BY nama_produk ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Master produk belum dapat dimuat.';
        $flashType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType !== 'error') {
    try {
        $token = (string)($_POST['csrf_token'] ?? '');
        if ($token === '' || !hash_equals(api_csrf_token(), $token)) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman.');
        }

        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'manual') {
            $rows = isset($_POST['items']) && is_array($_POST['items'])
                ? $_POST['items']
                : [];

            $pdo->beginTransaction();

            api_insert_order($pdo, [
                'nomor_pesanan'     => $_POST['nomor_pesanan'] ?? '',
                'tanggal_pemesanan' => $_POST['tanggal_pemesanan'] ?? '',
                'tanggal_kirim'     => $_POST['tanggal_kirim'] ?? '',
                'nama_pemesan'      => $_POST['nama_pemesan'] ?? '',
                'no_wa'             => $_POST['no_wa'] ?? '',
                'status'            => $_POST['status'] ?? 'selesai',
                'catatan'           => $_POST['catatan'] ?? '',
            ], $rows);

            $pdo->commit();

            $flash = 'Pesanan lama berhasil disimpan sebagai data migrasi.';
            $flashType = 'success';
            $_SESSION['air_import_csrf'] = bin2hex(random_bytes(32));
        }

        if ($action === 'csv') {
            if (
                !isset($_FILES['csv_file'])
                || !is_array($_FILES['csv_file'])
                || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            ) {
                throw new RuntimeException('Pilih file CSV terlebih dahulu.');
            }

            $tmp = (string)$_FILES['csv_file']['tmp_name'];
            $size = (int)($_FILES['csv_file']['size'] ?? 0);

            if ($size <= 0 || $size > 5 * 1024 * 1024) {
                throw new RuntimeException('Ukuran CSV harus lebih dari 0 dan maksimal 5 MB.');
            }

            $handle = fopen($tmp, 'r');
            if (!$handle) {
                throw new RuntimeException('File CSV tidak dapat dibaca.');
            }

            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                throw new RuntimeException('Header CSV tidak ditemukan.');
            }

            $header = array_map(function ($value) {
                $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
                return strtolower(trim($value));
            }, $header);

            $required = [
                'nomor_pesanan',
                'tanggal_pemesanan',
                'nama_pemesan',
                'no_wa',
                'tanggal_kirim',
                'lokasi',
                'kode_produk',
                'qty',
                'status',
                'catatan',
            ];

            foreach ($required as $requiredColumn) {
                if (!in_array($requiredColumn, $header, true)) {
                    fclose($handle);
                    throw new RuntimeException('Kolom CSV wajib tidak ditemukan: ' . $requiredColumn);
                }
            }

            $orders = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (count($row) < count($header)) {
                    $row = array_pad($row, count($header), '');
                }

                $data = [];
                foreach ($header as $index => $columnName) {
                    $data[$columnName] = isset($row[$index]) ? trim((string)$row[$index]) : '';
                }

                if (
                    $data['nomor_pesanan'] === ''
                    && $data['nama_pemesan'] === ''
                    && $data['kode_produk'] === ''
                ) {
                    continue;
                }

                $orderKey = $data['nomor_pesanan'];
                if ($orderKey === '') {
                    $orderKey = '__ROW_' . $rowNumber;
                }

                if (!isset($orders[$orderKey])) {
                    $orders[$orderKey] = [
                        'data' => [
                            'nomor_pesanan'     => $data['nomor_pesanan'],
                            'tanggal_pemesanan' => $data['tanggal_pemesanan'],
                            'tanggal_kirim'     => $data['tanggal_kirim'],
                            'nama_pemesan'      => $data['nama_pemesan'],
                            'no_wa'             => $data['no_wa'],
                            'status'            => $data['status'],
                            'catatan'           => $data['catatan'],
                        ],
                        'items' => [],
                    ];
                }

                $orders[$orderKey]['items'][] = [
                    'lokasi'       => $data['lokasi'],
                    'kode_produk'  => $data['kode_produk'],
                    'nama_produk'  => '',
                    'qty'          => $data['qty'],
                ];
            }

            fclose($handle);

            foreach ($orders as $orderKey => $payload) {
                try {
                    $pdo->beginTransaction();
                    api_insert_order($pdo, $payload['data'], $payload['items']);
                    $pdo->commit();
                    $importResult['berhasil']++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $importResult['gagal']++;
                    $importResult['errors'][] = $orderKey . ': ' . $e->getMessage();
                }
            }

            if ($importResult['berhasil'] > 0) {
                $flash = $importResult['berhasil'] . ' pesanan berhasil diimport.';
                if ($importResult['gagal'] > 0) {
                    $flash .= ' ' . $importResult['gagal'] . ' pesanan gagal.';
                    $flashType = 'warning';
                } else {
                    $flashType = 'success';
                }
            } else {
                $flash = 'Tidak ada pesanan yang berhasil diimport.';
                $flashType = 'error';
            }

            $_SESSION['air_import_csrf'] = bin2hex(random_bytes(32));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$csrfToken = api_csrf_token();

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Input Pesanan Lama - SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #1a1a1a
        }

        .air-main {
            min-height: calc(100vh - 64px)
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0
        }

        .field {
            width: 100%;
            min-height: 44px;
            padding: 0 12px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            outline: none
        }

        textarea.field {
            padding-top: 10px;
            min-height: 90px;
            resize: vertical
        }

        .field:focus {
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, .06)
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
            letter-spacing: .07em
        }

        .tab-btn {
            min-height: 44px;
            padding: 0 16px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .07em
        }

        .tab-btn.active {
            background: #111827;
            color: #fff;
            border-color: #111827
        }

        .item-row {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(220px, 1.4fr) 100px 42px;
            gap: 8px;
            align-items: end
        }

        .section-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #94a3b8
        }

        @media(min-width:1024px) {
            .air-main {
                margin-left: 220px
            }
        }

        @media(max-width:1023px) {
            .air-main {
                margin-left: 0 !important;
                padding: 1rem !important;
                padding-bottom: 6rem !important
            }
        }

        @media(max-width:767px) {
            .air-main {
                padding: .625rem !important;
                padding-bottom: 6.5rem !important
            }

            .item-row {
                grid-template-columns: 1fr
            }

            .item-remove {
                width: 100%;
                height: 42px
            }

            .mobile-stack {
                grid-template-columns: 1fr !important
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen">
    <main class="air-main p-4 sm:p-5 md:p-8 lg:p-10">
        <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="section-label text-blue-600">Modul Air Mineral</p>
                <h1 class="text-xl md:text-2xl font-light tracking-tight mt-1">
                    Input <span class="font-semibold">Pesanan Lama</span>
                </h1>
                <p class="text-xs text-gray-400 mt-1">
                    Masukkan data historis tanpa mengirim WhatsApp, tanpa tracking publik, dan tanpa mengubah alur pesanan baru.
                </p>
            </div>

            <a href="air_pesanan.php" class="btn border border-gray-200 bg-white text-gray-700">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali
            </a>
        </header>

        <div class="mb-5 border border-blue-100 bg-blue-50 px-4 py-3">
            <p class="section-label text-blue-700">Data Historis / Migrasi</p>
            <p class="text-xs text-blue-700 mt-1">
                Pesanan yang diinput di halaman ini ditandai sebagai <strong>migrasi</strong>.
                Data lama yang sudah tuntas sebaiknya menggunakan status <strong>Selesai</strong>.
            </p>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="mb-5 border px-4 py-3 text-xs font-bold
            <?php
            echo $flashType === 'error'
                ? 'border-red-200 bg-red-50 text-red-700'
                : ($flashType === 'warning'
                    ? 'border-amber-200 bg-amber-50 text-amber-700'
                    : 'border-green-200 bg-green-50 text-green-700');
            ?>">
                <?php echo api_h($flash); ?>
            </div>
        <?php endif; ?>

        <?php if ($importResult['errors']): ?>
            <div class="mb-5 card p-4">
                <p class="section-label text-red-600">Baris / Pesanan Gagal</p>
                <div class="mt-3 max-h-52 overflow-y-auto space-y-2">
                    <?php foreach ($importResult['errors'] as $errorItem): ?>
                        <div class="border border-red-100 bg-red-50 px-3 py-2 text-xs text-red-700">
                            <?php echo api_h($errorItem); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 mb-4">
            <button type="button" class="tab-btn active" id="tabManualBtn" onclick="showTab('manual')">
                <i data-lucide="pencil-line" class="w-4 h-4 inline-block mr-1"></i>
                Input Manual
            </button>
            <button type="button" class="tab-btn" id="tabCsvBtn" onclick="showTab('csv')">
                <i data-lucide="file-spreadsheet" class="w-4 h-4 inline-block mr-1"></i>
                Import CSV
            </button>
        </div>

        <section id="tabManual" class="card p-4 md:p-6">
            <form method="post" id="manualForm">
                <input type="hidden" name="csrf_token" value="<?php echo api_h($csrfToken); ?>">
                <input type="hidden" name="action" value="manual">

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div>
                        <label class="section-label block mb-2">Nomor Pesanan Lama</label>
                        <input type="text" name="nomor_pesanan" class="field" placeholder="Opsional, otomatis jika kosong">
                    </div>
                    <div>
                        <label class="section-label block mb-2">Tanggal Pemesanan *</label>
                        <input type="date" name="tanggal_pemesanan" class="field" required>
                    </div>
                    <div>
                        <label class="section-label block mb-2">Tanggal Pengiriman</label>
                        <input type="date" name="tanggal_kirim" class="field">
                    </div>
                    <div>
                        <label class="section-label block mb-2">Status *</label>
                        <select name="status" class="field">
                            <option value="selesai">Selesai</option>
                            <option value="baru">Baru</option>
                            <option value="diproses">Diproses</option>
                            <option value="siap_dikirim">Siap Dikirim</option>
                            <option value="batal">Batal</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="section-label block mb-2">Nama Pemesan *</label>
                        <input type="text" name="nama_pemesan" class="field" required>
                    </div>
                    <div>
                        <label class="section-label block mb-2">Nomor WhatsApp</label>
                        <input type="text" name="no_wa" class="field" placeholder="Opsional">
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <div>
                        <p class="section-label">Rincian Produk & Lokasi</p>
                        <p class="text-xs text-gray-400 mt-1">Tambahkan satu atau beberapa baris sesuai data lama. Lokasi dipilih langsung dari daftar yang tersedia.</p>
                    </div>
                    <button type="button" onclick="addItemRow()" class="btn border border-gray-200 bg-white text-gray-700">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Tambah Baris
                    </button>
                </div>

                <div id="itemRows" class="mt-4 space-y-3"></div>

                <div class="mt-4">
                    <label class="section-label block mb-2">Catatan</label>
                    <textarea name="catatan" class="field" placeholder="Contoh: data migrasi arsip tahun sebelumnya"></textarea>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row gap-2">
                    <button type="submit" class="btn bg-black text-white flex-1">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        Simpan Pesanan Lama
                    </button>
                    <button type="reset" onclick="resetManualRows()" class="btn border border-gray-200 bg-white text-gray-700">
                        Reset
                    </button>
                </div>
            </form>
        </section>

        <section id="tabCsv" class="card p-4 md:p-6 hidden">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo api_h($csrfToken); ?>">
                <input type="hidden" name="action" value="csv">

                <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-5">
                    <div>
                        <p class="section-label">Upload CSV</p>
                        <h2 class="text-base font-black mt-1">Import Banyak Pesanan Lama</h2>
                        <p class="text-xs text-gray-400 mt-2 leading-5">
                            Satu nomor pesanan boleh memiliki beberapa baris untuk lokasi atau produk yang berbeda.
                            Sistem akan menggabungkan baris dengan nomor pesanan yang sama.
                        </p>

                        <div class="mt-5 border border-dashed border-gray-300 bg-gray-50 p-5">
                            <input type="file" name="csv_file" accept=".csv,text/csv" required class="block w-full text-xs">
                            <p class="text-[10px] text-gray-400 mt-2">Maksimal 5 MB. Gunakan format CSV UTF-8.</p>
                        </div>

                        <button type="submit" class="btn bg-black text-white mt-4 w-full sm:w-auto">
                            <i data-lucide="upload" class="w-4 h-4"></i>
                            Import CSV
                        </button>
                    </div>

                    <div class="border border-gray-100 bg-gray-50 p-4">
                        <p class="section-label">Kolom Wajib CSV</p>
                        <div class="mt-3 space-y-2 text-xs text-gray-600">
                            <p>nomor_pesanan</p>
                            <p>tanggal_pemesanan</p>
                            <p>nama_pemesan</p>
                            <p>no_wa</p>
                            <p>tanggal_kirim</p>
                            <p>lokasi</p>
                            <p>kode_produk</p>
                            <p>qty</p>
                            <p>status</p>
                            <p>catatan</p>
                        </div>

                        <a href="template_import_pesanan_air.csv" class="btn border border-gray-200 bg-white text-gray-700 mt-4 w-full">
                            <i data-lucide="download" class="w-4 h-4"></i>
                            Download Template
                        </a>
                    </div>
                </div>
            </form>
        </section>
    </main>

    <template id="itemTemplate">
        <div class="item-row border border-gray-100 bg-gray-50 p-3" data-item-row>
            <div>
                <label class="section-label block mb-2">Lokasi *</label>
                <select class="field item-location" required>
                    <option value="">Pilih lokasi pengantaran...</option>
                    <?php foreach ($lokasiPilihan as $lokasi): ?>
                        <option value="<?php echo api_h($lokasi); ?>">
                            <?php echo api_h($lokasi); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="section-label block mb-2">Produk *</label>
                <select class="field item-product" required>
                    <option value="">Pilih produk...</option>
                    <?php foreach ($produkList as $produk): ?>
                        <option value="<?php echo api_h($produk['kode_produk']); ?>">
                            <?php echo api_h($produk['nama_produk']); ?> (<?php echo api_h($produk['kode_produk']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="section-label block mb-2">Jumlah *</label>
                <input type="number" min="1" value="1" class="field item-qty" required>
            </div>
            <button type="button" onclick="removeItemRow(this)" class="item-remove h-[44px] border border-red-200 bg-white text-red-600 flex items-center justify-center">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
        </div>
    </template>

    <script>
        function showTab(tab) {
            var manual = document.getElementById('tabManual');
            var csv = document.getElementById('tabCsv');
            var manualBtn = document.getElementById('tabManualBtn');
            var csvBtn = document.getElementById('tabCsvBtn');

            var isManual = tab === 'manual';

            manual.classList.toggle('hidden', !isManual);
            csv.classList.toggle('hidden', isManual);
            manualBtn.classList.toggle('active', isManual);
            csvBtn.classList.toggle('active', !isManual);
        }

        function reindexItemRows() {
            document.querySelectorAll('[data-item-row]').forEach(function(row, index) {
                row.querySelector('.item-location').name = 'items[' + index + '][lokasi]';
                row.querySelector('.item-product').name = 'items[' + index + '][kode_produk]';
                row.querySelector('.item-qty').name = 'items[' + index + '][qty]';
            });
        }

        function addItemRow() {
            var node = document.getElementById('itemTemplate').content.cloneNode(true);
            document.getElementById('itemRows').appendChild(node);
            reindexItemRows();
            if (window.lucide) lucide.createIcons();
        }

        function removeItemRow(button) {
            var rows = document.querySelectorAll('[data-item-row]');
            if (rows.length <= 1) {
                alert('Minimal satu baris produk harus tersedia.');
                return;
            }

            button.closest('[data-item-row]').remove();
            reindexItemRows();
        }

        function resetManualRows() {
            setTimeout(function() {
                document.getElementById('itemRows').innerHTML = '';
                addItemRow();
            }, 0);
        }

        document.getElementById('manualForm').addEventListener('submit', function() {
            reindexItemRows();
        });

        addItemRow();

        if (window.lucide) {
            lucide.createIcons();
        }
    </script>
</body>

</html>