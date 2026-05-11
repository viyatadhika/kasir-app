<?php
session_start();
require_once 'config.php';
require_once 'activity_helper.php';

/*
|--------------------------------------------------------------------------
| transport_booking.php
|--------------------------------------------------------------------------
| FINAL VERSION
| Booking transport bandara berbasis DRIVER MITRA + PILIH JENIS KENDARAAN.
|
| FLOW:
| Member pilih jenis kendaraan
| ↓
| Harga dan kapasitas tersimpan di booking
| ↓
| Status pending
| ↓
| Admin assign driver di rental_bandara.php
| ↓
| Driver yang ditugaskan membawa kendaraan sesuai kebutuhan operasional
|
| CATATAN:
| - Tidak memakai kendaraan.php
| - Member memilih tipe kendaraan, bukan unit kendaraan fisik
| - Driver tetap dipilih admin/operator
*/

if (empty($_SESSION['member_id'])) {
    header('Location: member_login.php');
    exit;
}

if (!function_exists('h')) {
    /**
     * @param mixed $v
     */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function tb_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SHOW COLUMNS
            FROM `$table`
            LIKE ?
        ");
        $stmt->execute([$column]);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function tb_index_exists(PDO $pdo, string $table, string $index): bool
{
    try {
        $stmt = $pdo->prepare("
            SHOW INDEX
            FROM `$table`
            WHERE Key_name = ?
        ");
        $stmt->execute([$index]);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_transport_tables(PDO $pdo): void
{
    /*
    |--------------------------------------------------------------------------
    | DRIVER
    |--------------------------------------------------------------------------
    */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver (
            id INT AUTO_INCREMENT PRIMARY KEY,

            kode VARCHAR(50) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            no_hp VARCHAR(30) NOT NULL,

            alamat TEXT DEFAULT NULL,

            kendaraan_nama VARCHAR(120) NOT NULL,
            kendaraan_tipe VARCHAR(80) DEFAULT NULL,
            plat_nomor VARCHAR(30) NOT NULL,

            kapasitas INT NOT NULL DEFAULT 4,

            harga_bandara INT NOT NULL DEFAULT 0,

            status_online VARCHAR(30) NOT NULL DEFAULT 'offline',
            status_aktif VARCHAR(30) NOT NULL DEFAULT 'aktif',

            rating DECIMAL(3,2) NOT NULL DEFAULT 5.00,

            saldo INT NOT NULL DEFAULT 0,

            catatan VARCHAR(255) DEFAULT NULL,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY kode (kode),

            KEY no_hp (no_hp),
            KEY status_online (status_online),
            KEY status_aktif (status_aktif)

        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_general_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | RENTAL BANDARA
    |--------------------------------------------------------------------------
    */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rental_bandara (
            id INT AUTO_INCREMENT PRIMARY KEY,

            kode_booking VARCHAR(80) NOT NULL,

            member_id INT DEFAULT NULL,
            driver_id INT DEFAULT NULL,

            nama_pemesan VARCHAR(120) NOT NULL,
            no_hp VARCHAR(30) NOT NULL,

            layanan VARCHAR(50) NOT NULL,

            lokasi_jemput TEXT NOT NULL,
            tujuan TEXT NOT NULL,

            tanggal DATE NOT NULL,
            jam TIME NOT NULL,

            jumlah_penumpang INT DEFAULT 1,

            kendaraan VARCHAR(120) DEFAULT NULL,
            tipe_kendaraan VARCHAR(80) DEFAULT NULL,
            kapasitas_kendaraan INT DEFAULT NULL,

            total_harga INT DEFAULT 0,

            status VARCHAR(30) DEFAULT 'pending',

            catatan TEXT DEFAULT NULL,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY kode_booking (kode_booking),

            KEY member_id (member_id),
            KEY driver_id (driver_id),
            KEY status (status),
            KEY tanggal (tanggal),
            KEY tipe_kendaraan (tipe_kendaraan)

        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_general_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | SAFE ALTER driver_id
    |--------------------------------------------------------------------------
    */
    try {
        if (!tb_column_exists($pdo, 'rental_bandara', 'driver_id')) {
            $pdo->exec("
                ALTER TABLE rental_bandara
                ADD COLUMN driver_id INT DEFAULT NULL
                AFTER member_id
            ");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!tb_index_exists($pdo, 'rental_bandara', 'driver_id')) {
            $pdo->exec("
                ALTER TABLE rental_bandara
                ADD KEY driver_id (driver_id)
            ");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE ALTER tipe_kendaraan
    |--------------------------------------------------------------------------
    */
    try {
        if (!tb_column_exists($pdo, 'rental_bandara', 'tipe_kendaraan')) {
            $pdo->exec("
                ALTER TABLE rental_bandara
                ADD COLUMN tipe_kendaraan VARCHAR(80) DEFAULT NULL
                AFTER kendaraan
            ");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    try {
        if (!tb_index_exists($pdo, 'rental_bandara', 'tipe_kendaraan')) {
            $pdo->exec("
                ALTER TABLE rental_bandara
                ADD KEY tipe_kendaraan (tipe_kendaraan)
            ");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE ALTER kapasitas_kendaraan
    |--------------------------------------------------------------------------
    */
    try {
        if (!tb_column_exists($pdo, 'rental_bandara', 'kapasitas_kendaraan')) {
            $pdo->exec("
                ALTER TABLE rental_bandara
                ADD COLUMN kapasitas_kendaraan INT DEFAULT NULL
                AFTER tipe_kendaraan
            ");
        }
    } catch (Throwable $e) {
        // Abaikan agar halaman tetap berjalan.
    }
}

/**
 * @param mixed $layanan
 */
function tb_label_layanan($layanan): string
{
    $layanan = strtolower(trim((string)$layanan));

    if ($layanan === 'jemput_bandara') {
        return 'jemput_bandara';
    }

    return 'antar_bandara';
}

/**
 * @return array<string, array{label:string, kapasitas:int, harga:int}>
 */
function tb_daftar_tipe_kendaraan(): array
{
    return [
        'avanza' => [
            'label' => 'Toyota Avanza',
            'kapasitas' => 4,
            'harga' => 0,
        ],
        'innova' => [
            'label' => 'Toyota Innova',
            'kapasitas' => 6,
            'harga' => 0,
        ],
        'hiace' => [
            'label' => 'Toyota Hiace',
            'kapasitas' => 12,
            'harga' => 0,
        ],
    ];
}


/**
 * Mengambil harga dan kapasitas berdasarkan tipe kendaraan dari tabel driver.
 *
 * @return array{kode:string,label:string,kapasitas:int,harga:int}
 */
function tb_get_tipe_kendaraan_from_driver(PDO $pdo, string $kode): array
{
    $base = tb_get_tipe_kendaraan($kode);

    try {
        $stmt = $pdo->prepare("
            SELECT
                MAX(COALESCE(kapasitas, 0)) AS kapasitas,
                MIN(NULLIF(COALESCE(harga_bandara, 0), 0)) AS harga,
                MIN(kendaraan_nama) AS kendaraan_nama
            FROM driver
            WHERE status_aktif = 'aktif'
              AND (
                    LOWER(COALESCE(kendaraan_tipe, '')) = :tipe
                    OR LOWER(COALESCE(kendaraan_nama, '')) LIKE :like_tipe
                    OR (:tipe = 'innova' AND LOWER(COALESCE(kendaraan_nama, '')) LIKE '%inova%')
              )
        ");

        $stmt->execute([
            ':tipe' => $base['kode'],
            ':like_tipe' => '%' . $base['kode'] . '%',
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int)($row['kapasitas'] ?? 0) > 0) {
            $base['kapasitas'] = (int)$row['kapasitas'];
        }

        if ((int)($row['harga'] ?? 0) > 0) {
            $base['harga'] = (int)$row['harga'];
        }

        if (!empty($row['kendaraan_nama'])) {
            $base['label'] = (string)$row['kendaraan_nama'];
        }
    } catch (Throwable $e) {
        // Jika data driver belum tersedia, gunakan default.
    }

    return $base;
}

/**
 * @param mixed $kode
 * @return array{kode:string,label:string,kapasitas:int,harga:int}
 */
function tb_get_tipe_kendaraan($kode): array
{
    $kode = strtolower(trim((string)$kode));
    $daftar = tb_daftar_tipe_kendaraan();

    if (!isset($daftar[$kode])) {
        $kode = 'avanza';
    }

    return [
        'kode' => $kode,
        'label' => $daftar[$kode]['label'],
        'kapasitas' => (int)$daftar[$kode]['kapasitas'],
        'harga' => (int)$daftar[$kode]['harga'],
    ];
}

ensure_transport_tables($pdo);

/*
|--------------------------------------------------------------------------
| HANYA POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: member_dashboard.php');
    exit;
}

$memberId = (int)$_SESSION['member_id'];

try {
    /*
    |--------------------------------------------------------------------------
    | VALIDASI MEMBER
    |--------------------------------------------------------------------------
    */
    $stmtMember = $pdo->prepare("
        SELECT
            id,
            kode,
            nama,
            no_hp,
            status
        FROM member
        WHERE id = :id
        LIMIT 1
    ");

    $stmtMember->execute([
        ':id' => $memberId
    ]);

    $member = $stmtMember->fetch(PDO::FETCH_ASSOC);

    if (!$member || ($member['status'] ?? '') !== 'aktif') {
        unset(
            $_SESSION['member_id'],
            $_SESSION['member_nama'],
            $_SESSION['member_kode']
        );

        header('Location: member_login.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | INPUT
    |--------------------------------------------------------------------------
    */
    $layanan = tb_label_layanan($_POST['layanan'] ?? '');

    $lokasiJemput = trim((string)($_POST['lokasi_jemput'] ?? ''));
    $tujuan = trim((string)($_POST['tujuan'] ?? ''));

    $tanggal = trim((string)($_POST['tanggal'] ?? ''));
    $jam = trim((string)($_POST['jam'] ?? ''));

    $jumlahPenumpang = (int)($_POST['jumlah_penumpang'] ?? 1);

    /*
    |--------------------------------------------------------------------------
    | Input tipe kendaraan
    |--------------------------------------------------------------------------
    | Form boleh mengirim:
    | - tipe_kendaraan
    | - kendaraan_tipe
    | - kendaraan
    |
    | Supaya kompatibel dengan form lama.
    |--------------------------------------------------------------------------
    */
    $tipeInput = $_POST['tipe_kendaraan']
        ?? ($_POST['kendaraan_tipe']
            ?? ($_POST['kendaraan'] ?? 'avanza'));

    $tipeKendaraan = tb_get_tipe_kendaraan_from_driver($pdo, (string)$tipeInput);

    $catatan = trim((string)($_POST['catatan'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */
    if ($lokasiJemput === '') {
        throw new Exception('Lokasi jemput wajib diisi.');
    }

    if ($tujuan === '') {
        throw new Exception('Tujuan wajib diisi.');
    }

    if ($tanggal === '') {
        throw new Exception('Tanggal wajib diisi.');
    }

    if ($jam === '') {
        throw new Exception('Jam wajib diisi.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        throw new Exception('Format tanggal tidak valid.');
    }

    if (!preg_match('/^\d{2}:\d{2}/', $jam)) {
        throw new Exception('Format jam tidak valid.');
    }

    $timestampBooking = strtotime($tanggal . ' ' . $jam);

    if (!$timestampBooking) {
        throw new Exception('Tanggal atau jam tidak valid.');
    }

    if ($timestampBooking < time()) {
        throw new Exception('Tanggal dan jam tidak boleh kurang dari waktu sekarang.');
    }

    if ($jumlahPenumpang < 1) {
        $jumlahPenumpang = 1;
    }

    if ((int)$tipeKendaraan['harga'] <= 0) {
        throw new Exception('Tarif kendaraan belum tersedia. Silakan hubungi admin.');
    }

    if ($jumlahPenumpang > $tipeKendaraan['kapasitas']) {
        throw new Exception(
            'Jumlah penumpang melebihi kapasitas ' .
                $tipeKendaraan['label'] .
                ' (' .
                $tipeKendaraan['kapasitas'] .
                ' orang).'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BOOKING CODE
    |--------------------------------------------------------------------------
    */
    $kodeBooking =
        'TRP-' .
        date('Ymd-His') .
        '-' .
        random_int(100, 999);

    /*
    |--------------------------------------------------------------------------
    | INSERT BOOKING
    |--------------------------------------------------------------------------
    | kendaraan:
    | jenis kendaraan yang dipilih member
    |
    | driver_id:
    | NULL, nanti admin/operator assign driver
    |
    | status:
    | pending
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        INSERT INTO rental_bandara
        (
            kode_booking,

            member_id,
            driver_id,

            nama_pemesan,
            no_hp,

            layanan,

            lokasi_jemput,
            tujuan,

            tanggal,
            jam,

            jumlah_penumpang,

            kendaraan,
            tipe_kendaraan,
            kapasitas_kendaraan,

            total_harga,

            status,

            catatan,

            created_at
        )
        VALUES
        (
            :kode_booking,

            :member_id,
            NULL,

            :nama_pemesan,
            :no_hp,

            :layanan,

            :lokasi_jemput,
            :tujuan,

            :tanggal,
            :jam,

            :jumlah_penumpang,

            :kendaraan,
            :tipe_kendaraan,
            :kapasitas_kendaraan,

            :total_harga,

            'pending',

            :catatan,

            NOW()
        )
    ");

    $stmt->execute([
        ':kode_booking' => $kodeBooking,

        ':member_id' => (int)$member['id'],

        ':nama_pemesan' => $member['nama'],
        ':no_hp' => $member['no_hp'],

        ':layanan' => $layanan,

        ':lokasi_jemput' => $lokasiJemput,
        ':tujuan' => $tujuan,

        ':tanggal' => $tanggal,
        ':jam' => $jam,

        ':jumlah_penumpang' => $jumlahPenumpang,

        ':kendaraan' => $tipeKendaraan['label'],
        ':tipe_kendaraan' => $tipeKendaraan['kode'],
        ':kapasitas_kendaraan' => $tipeKendaraan['kapasitas'],

        ':total_harga' => $tipeKendaraan['harga'],

        ':catatan' => $catatan !== ''
            ? $catatan
            : null,
    ]);

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */
    $_SESSION['transport_success'] =
        'Booking transport berhasil dikirim. '
        . 'Kode booking: '
        . $kodeBooking;

    catat_aktivitas($pdo, 'booking', 'Transport Bandara', 'Member membuat booking transport: ' . $kodeBooking . ' - ' . $tipeKendaraan['label']);

    header('Location: member_dashboard.php#pesanan');
    exit;
} catch (Throwable $e) {
    /*
    |--------------------------------------------------------------------------
    | ERROR
    |--------------------------------------------------------------------------
    */
    $_SESSION['transport_error'] =
        'Gagal booking transport: '
        . $e->getMessage();

    header('Location: member_dashboard.php#transport');
    exit;
}
