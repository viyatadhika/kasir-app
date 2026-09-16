<?php
session_start();
require_once 'config.php';
require_once 'activity_helper.php';

// Nilai tukar point mengikuti POS: 1 point = Rp 1.000
if (!defined('MEMBER_POINT_RUPIAH')) {
    define('MEMBER_POINT_RUPIAH', 1000);
}

if (isset($_GET['logout'])) {
    unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_kode']);
    header('Location: member_login.php');
    exit;
}

if (empty($_SESSION['member_id'])) {
    header('Location: member_login.php');
    exit;
}

/**
 * @param mixed $v
 */
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * @param mixed $v
 */
function rupiah_member($v): string
{
    return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * @param mixed $v
 */
function angka_member($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * @param mixed $v
 */
function tanggal_member($v): string
{
    return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
}

/**
 * @param mixed $v
 */
function tanggal_short($v): string
{
    return $v ? date('d M Y', strtotime((string)$v)) : '-';
}


if (!function_exists('member_ensure_notifikasi_admin_schema')) {
    function member_ensure_notifikasi_admin_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS notifikasi_admin (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                event_key VARCHAR(190) NOT NULL,\n                judul VARCHAR(180) NOT NULL,\n                pesan TEXT NULL,\n                tipe VARCHAR(50) NOT NULL DEFAULT 'info',\n                ref_tipe VARCHAR(50) NULL,\n                ref_id INT NULL,\n                target_url VARCHAR(255) NULL,\n                is_read TINYINT(1) NOT NULL DEFAULT 0,\n                read_at DATETIME NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL,\n                UNIQUE KEY uq_notifikasi_admin_event (event_key),\n                INDEX idx_notifikasi_admin_read (is_read),\n                INDEX idx_notifikasi_admin_created (created_at),\n                INDEX idx_notifikasi_admin_ref (ref_tipe, ref_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");
    }
}

if (!function_exists('member_push_notifikasi_admin')) {
    function member_push_notifikasi_admin(PDO $pdo, string $eventKey, string $judul, string $pesan, string $tipe, string $refTipe, int $refId, string $targetUrl): void
    {
        member_ensure_notifikasi_admin_schema($pdo);
        $stmt = $pdo->prepare("\n            INSERT INTO notifikasi_admin\n                (event_key, judul, pesan, tipe, ref_tipe, ref_id, target_url, is_read, read_at, created_at, updated_at)\n            VALUES\n                (:event_key, :judul, :pesan, :tipe, :ref_tipe, :ref_id, :target_url, 0, NULL, NOW(), NOW())\n            ON DUPLICATE KEY UPDATE\n                judul = VALUES(judul),\n                pesan = VALUES(pesan),\n                tipe = VALUES(tipe),\n                target_url = VALUES(target_url),\n                is_read = 0,\n                read_at = NULL,\n                updated_at = NOW()\n        ");
        $stmt->execute([
            ':event_key' => $eventKey,
            ':judul' => $judul,
            ':pesan' => $pesan,
            ':tipe' => $tipe,
            ':ref_tipe' => $refTipe,
            ':ref_id' => $refId,
            ':target_url' => $targetUrl,
        ]);
    }
}

/**
 * @param mixed $name
 */
function member_initial($name): string
{
    $name = trim((string)($name ?? ''));
    if ($name === '') return 'MB';
    if (function_exists('mb_substr')) return strtoupper(mb_substr($name, 0, 2));
    return strtoupper(substr($name, 0, 2));
}


/**
 * Mengambil tarif kendaraan dari tabel driver.
 * Harga memakai MIN(harga_bandara) agar yang tampil adalah tarif termurah untuk tipe tersebut.
 *
 * @return array<string, array{label:string, kapasitas:int, harga:int}>
 */
function get_transport_tarif_from_driver(PDO $pdo): array
{
    $default = [
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

    try {
        $stmt = $pdo->query("
            SELECT
                CASE
                    WHEN LOWER(COALESCE(kendaraan_tipe, kendaraan_nama, '')) LIKE '%hiace%' THEN 'hiace'
                    WHEN LOWER(COALESCE(kendaraan_tipe, kendaraan_nama, '')) LIKE '%innova%' THEN 'innova'
                    WHEN LOWER(COALESCE(kendaraan_tipe, kendaraan_nama, '')) LIKE '%inova%' THEN 'innova'
                    WHEN LOWER(COALESCE(kendaraan_tipe, kendaraan_nama, '')) LIKE '%avanza%' THEN 'avanza'
                    ELSE LOWER(COALESCE(kendaraan_tipe, kendaraan_nama, ''))
                END AS tipe,
                MAX(COALESCE(kapasitas, 0)) AS kapasitas,
                MIN(NULLIF(COALESCE(harga_bandara, 0), 0)) AS harga
            FROM driver
            WHERE status_aktif = 'aktif'
            GROUP BY tipe
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tipe = strtolower(trim((string)($row['tipe'] ?? '')));

            if (!isset($default[$tipe])) {
                continue;
            }

            if ((int)($row['kapasitas'] ?? 0) > 0) {
                $default[$tipe]['kapasitas'] = (int)$row['kapasitas'];
            }

            if ((int)($row['harga'] ?? 0) > 0) {
                $default[$tipe]['harga'] = (int)$row['harga'];
            }
        }
    } catch (Throwable $e) {
        // Jika tabel driver belum siap, tetap tampilkan pilihan kendaraan dengan harga 0.
    }

    return $default;
}

function has_column_member(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function has_table_member(PDO $pdo, string $table): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetch(PDO::FETCH_NUM)) {
            return true;
        }
    } catch (Throwable $e) {
        // Lanjut ke information_schema.
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = LOWER(?)');
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    } catch (Throwable $e) {
        // Lanjut ke direct select.
    }

    try {
        $safeTable = str_replace('`', '', $table);
        $pdo->query('SELECT 1 FROM `' . $safeTable . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param mixed $status
 */
function pinjaman_status_label_member($status): string
{
    $status = strtolower(trim((string)$status));
    $map = [
        'pending' => 'Pending',
        'diseleksi' => 'Diseleksi',
        'diproses' => 'Diproses',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
        'dibatalkan' => 'Dibatalkan',
        'dicairkan' => 'Dicairkan',
        'aktif' => 'Aktif',
        'lunas' => 'Lunas',
        'selesai' => 'Selesai',
    ];
    return $map[$status] ?? ucfirst($status ?: '-');
}

/**
 * @param mixed $status
 */
function pinjaman_status_class_member($status): string
{
    $status = strtolower(trim((string)$status));
    if (in_array($status, ['disetujui', 'dicairkan', 'aktif', 'lunas', 'selesai'], true)) return 'transport-status-green';
    if (in_array($status, ['diseleksi', 'diproses'], true)) return 'transport-status-blue';
    if (in_array($status, ['ditolak', 'dibatalkan'], true)) return 'transport-status-red';
    return 'transport-status-orange';
}

$memberId = (int)$_SESSION['member_id'];
$transportTarif = get_transport_tarif_from_driver($pdo);
$transaksiMinimarket = [];
$transaksiCafe = [];

try {
    $stmt = $pdo->prepare("SELECT id,kode,nama,no_hp,point,total_belanja,status,created_at,updated_at FROM member WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        unset($_SESSION['member_id']);
        header('Location: member_login.php');
        exit;
    }

    $hasDiskon          = has_column_member($pdo, 'transaksi', 'diskon');
    $hasPoint           = has_column_member($pdo, 'transaksi', 'point_dapat');
    $hasPointPakai      = has_column_member($pdo, 'transaksi', 'point_pakai');
    $hasNilaiPointPakai = has_column_member($pdo, 'transaksi', 'nilai_point_pakai');

    $diskonSelect          = $hasDiskon ? "COALESCE(t.diskon,0) AS diskon_transaksi" : "0 AS diskon_transaksi";
    $pointSelect           = $hasPoint  ? "COALESCE(t.point_dapat,0) AS point_transaksi" : "0 AS point_transaksi";
    $pointPakaiSelect      = $hasPointPakai ? "COALESCE(t.point_pakai,0) AS point_pakai" : "0 AS point_pakai";
    $nilaiPointPakaiSelect = $hasNilaiPointPakai ? "COALESCE(t.nilai_point_pakai,0) AS nilai_point_pakai" : "0 AS nilai_point_pakai";

    $sumPoint = $hasPoint
        ? "COALESCE(SUM(CASE WHEN COALESCE(t.point_dapat,0)>0 THEN t.point_dapat ELSE FLOOR(COALESCE(t.total,0)/10000) END),0)"
        : "COALESCE(SUM(FLOOR(COALESCE(t.total,0)/10000)),0)";
    $sumPointPakai      = $hasPointPakai      ? "COALESCE(SUM(t.point_pakai),0)"       : "0";
    $sumNilaiPointPakai = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai),0)" : "0";

    $stmtSum = $pdo->prepare("
        SELECT
            COUNT(t.id) AS jumlah_transaksi,
            COALESCE(SUM(t.total),0) AS total_belanja_transaksi,
            COALESCE(SUM(
                GREATEST(
                    COALESCE(t.diskon,0),
                    GREATEST(
                        0,
                        COALESCE((
                            SELECT SUM(COALESCE(td.harga_normal,td.harga) * td.qty)
                            FROM transaksi_detail td
                            WHERE td.transaksi_id = t.id
                        ), t.total) - (COALESCE(t.total,0) + COALESCE(t.nilai_point_pakai,0))
                    )
                )
            ),0) AS total_diskon_transaksi,
            $sumPoint AS total_point_dari_transaksi,
            $sumPointPakai AS total_point_pakai,
            $sumNilaiPointPakai AS total_nilai_point_pakai
        FROM transaksi t
        WHERE t.member_id=:member_id");
    $stmtSum->execute([':member_id' => $memberId]);
    $summary = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];

    $hasSumberTransaksi = has_column_member($pdo, 'transaksi', 'sumber_transaksi');
    $hasCafePesanan = has_table_member($pdo, 'cafe_pesanan');
    $sourceExpr = $hasSumberTransaksi
        ? "CASE WHEN LOWER(TRIM(COALESCE(t.sumber_transaksi,''))) IN ('cafe','kafe','pos_cafe','kasir_cafe') THEN 'cafe' ELSE 'minimarket' END"
        : ($hasCafePesanan
            ? "CASE WHEN EXISTS (SELECT 1 FROM cafe_pesanan cp_src WHERE cp_src.transaksi_id=t.id) THEN 'cafe' ELSE 'minimarket' END"
            : "'minimarket'");

    $stmtTrx = $pdo->prepare("
        SELECT
            t.id AS transaksi_id,
            t.invoice,
            t.created_at AS tanggal_transaksi,
            $sourceExpr AS sumber_member,
            COALESCE(t.total,0) AS total_transaksi,
            COALESCE(t.bayar,0) AS bayar_transaksi,
            COALESCE(t.kembalian,0) AS kembalian_transaksi,
            COALESCE((
                SELECT SUM(COALESCE(td.harga_normal,td.harga) * td.qty)
                FROM transaksi_detail td
                WHERE td.transaksi_id = t.id
            ), t.total) AS total_sebelum_diskon,
            $diskonSelect,
            $pointSelect,
            $pointPakaiSelect,
            $nilaiPointPakaiSelect
        FROM transaksi t
        WHERE t.member_id=:member_id
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 50");
    $stmtTrx->execute([':member_id' => $memberId]);
    $transaksi = $stmtTrx->fetchAll(PDO::FETCH_ASSOC);
    $transaksiMinimarket = array_values(array_filter($transaksi, function ($row) {
        return strtolower((string)($row['sumber_member'] ?? 'minimarket')) !== 'cafe';
    }));
    $transaksiCafe = array_values(array_filter($transaksi, function ($row) {
        return strtolower((string)($row['sumber_member'] ?? '')) === 'cafe';
    }));
} catch (Throwable $e) {
    die('Gagal memuat dashboard: ' . h($e->getMessage()));
}

// ==================== NOTIFIKASI MEMBER ====================
$memberNotifRows = [];
$memberNotifUnread = 0;
$memberNotifTotal = 0;
try {
    // Tabel ini sudah dipakai oleh halaman admin pinjaman. Struktur dilengkapi agar badge baca bisa disimpan.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifikasi_member (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            judul VARCHAR(180) NOT NULL,
            pesan TEXT NULL,
            tipe VARCHAR(50) NULL,
            ref_id INT NULL,
            ref_tipe VARCHAR(50) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifikasi_member_member (member_id),
            INDEX idx_notifikasi_member_read (member_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $notifCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM notifikasi_member")->fetchAll(PDO::FETCH_ASSOC) as $notifCol) {
        $notifField = (string)($notifCol['Field'] ?? '');
        if ($notifField !== '') $notifCols[$notifField] = true;
    }
    if (!isset($notifCols['is_read'])) $pdo->exec("ALTER TABLE notifikasi_member ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    if (!isset($notifCols['read_at'])) $pdo->exec("ALTER TABLE notifikasi_member ADD COLUMN read_at DATETIME NULL");
    if (!isset($notifCols['created_at'])) $pdo->exec("ALTER TABLE notifikasi_member ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    if (!isset($notifCols['event_key'])) {
        $pdo->exec("ALTER TABLE notifikasi_member ADD COLUMN event_key VARCHAR(190) NULL");
        try {
            $pdo->exec("ALTER TABLE notifikasi_member ADD UNIQUE KEY uq_notifikasi_member_event (event_key)");
        } catch (Throwable $e) {
        }
    }

    // Sinkron otomatis notifikasi Minimarket/Cafe dari transaksi member.
    try {
        $hasSumberNotif = has_column_member($pdo, 'transaksi', 'sumber_transaksi');
        $hasCafeNotif = has_table_member($pdo, 'cafe_pesanan');
        $sourceNotifExpr = $hasSumberNotif
            ? "CASE WHEN LOWER(TRIM(COALESCE(t.sumber_transaksi,''))) IN ('cafe','kafe','pos_cafe','kasir_cafe') THEN 'cafe' ELSE 'minimarket' END"
            : ($hasCafeNotif ? "CASE WHEN EXISTS (SELECT 1 FROM cafe_pesanan cp WHERE cp.transaksi_id=t.id) THEN 'cafe' ELSE 'minimarket' END" : "'minimarket'");
        $stmtNotifTrx = $pdo->prepare("SELECT t.id,t.invoice,t.total,t.created_at,$sourceNotifExpr AS sumber FROM transaksi t WHERE t.member_id=:mid ORDER BY t.id DESC LIMIT 100");
        $stmtNotifTrx->execute([':mid' => $memberId]);
        $insNotif = $pdo->prepare("INSERT IGNORE INTO notifikasi_member (member_id,judul,pesan,tipe,ref_id,ref_tipe,is_read,read_at,created_at,event_key) VALUES (:mid,:judul,:pesan,:tipe,:rid,:ref_tipe,:is_read,:read_at,:created_at,:event_key)");
        foreach ($stmtNotifTrx->fetchAll(PDO::FETCH_ASSOC) as $nt) {
            $isCafe = strtolower((string)($nt['sumber'] ?? '')) === 'cafe';
            $eventAt = (string)($nt['created_at'] ?? date('Y-m-d H:i:s'));
            $isOld = date('Y-m-d', strtotime($eventAt)) < date('Y-m-d');
            $insNotif->execute([
                ':mid' => $memberId,
                ':judul' => $isCafe ? 'Transaksi Cafe' : 'Belanja Minimarket',
                ':pesan' => ($isCafe ? 'Pesanan Cafe' : 'Transaksi belanja') . ' ' . (string)($nt['invoice'] ?? ('#' . $nt['id'])) . ' sebesar ' . rupiah_member($nt['total'] ?? 0) . ' berhasil tercatat.',
                ':tipe' => $isCafe ? 'cafe' : 'minimarket',
                ':rid' => (int)$nt['id'],
                ':ref_tipe' => $isCafe ? 'cafe' : 'transaksi',
                ':is_read' => $isOld ? 1 : 0,
                ':read_at' => $isOld ? $eventAt : null,
                ':created_at' => $eventAt,
                ':event_key' => 'trx_' . $nt['id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('SYNC NOTIF TRANSAKSI: ' . $e->getMessage());
    }

    // Sinkron otomatis perubahan status Rental Bandara.
    try {
        if (has_table_member($pdo, 'rental_bandara')) {
            $stmtNotifRental = $pdo->prepare("SELECT id,kode_booking,status,total_harga,created_at FROM rental_bandara WHERE member_id=:mid ORDER BY id DESC LIMIT 50");
            $stmtNotifRental->execute([':mid' => $memberId]);
            $insRentalNotif = $pdo->prepare("INSERT IGNORE INTO notifikasi_member (member_id,judul,pesan,tipe,ref_id,ref_tipe,is_read,read_at,created_at,event_key) VALUES (:mid,'Rental Bandara',:pesan,'rental',:rid,'rental',:is_read,:read_at,:created_at,:event_key)");
            foreach ($stmtNotifRental->fetchAll(PDO::FETCH_ASSOC) as $nr) {
                $st = strtolower(trim((string)($nr['status'] ?? 'pending')));
                $label = ucwords(str_replace('_', ' ', $st));
                $created = (string)($nr['created_at'] ?? date('Y-m-d H:i:s'));
                $isOld = date('Y-m-d', strtotime($created)) < date('Y-m-d');
                $insRentalNotif->execute([
                    ':mid' => $memberId,
                    ':pesan' => 'Booking ' . (string)($nr['kode_booking'] ?? ('#' . $nr['id'])) . ' berstatus ' . $label . '.',
                    ':rid' => (int)$nr['id'],
                    ':is_read' => $isOld ? 1 : 0,
                    ':read_at' => $isOld ? $created : null,
                    ':created_at' => date('Y-m-d H:i:s'),
                    ':event_key' => 'rental_' . $nr['id'] . '_' . $st,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('SYNC NOTIF RENTAL: ' . $e->getMessage());
    }

    // Saat item notifikasi diklik, tandai hanya notifikasi milik member login sebagai sudah dibaca.
    $memberNotifReadId = (int)($_GET['member_notif_read'] ?? 0);
    if ($memberNotifReadId > 0) {
        $stmtMemberNotifRead = $pdo->prepare("UPDATE notifikasi_member SET is_read=1, read_at=NOW() WHERE id=:id AND member_id=:member_id");
        $stmtMemberNotifRead->execute([':id' => $memberNotifReadId, ':member_id' => $memberId]);
    }

    if ((string)($_GET['member_notif_read_all'] ?? '') === '1') {
        $stmtReadAll = $pdo->prepare("UPDATE notifikasi_member SET is_read=1, read_at=NOW() WHERE member_id=:member_id AND COALESCE(is_read,0)=0");
        $stmtReadAll->execute([':member_id' => $memberId]);
    }

    $stmtMemberNotifTotal = $pdo->prepare("SELECT COUNT(*) FROM notifikasi_member WHERE member_id=:member_id");
    $stmtMemberNotifTotal->execute([':member_id' => $memberId]);
    $memberNotifTotal = (int)$stmtMemberNotifTotal->fetchColumn();

    $stmtMemberNotifUnread = $pdo->prepare("SELECT COUNT(*) FROM notifikasi_member WHERE member_id=:member_id AND COALESCE(is_read,0)=0");
    $stmtMemberNotifUnread->execute([':member_id' => $memberId]);
    $memberNotifUnread = (int)$stmtMemberNotifUnread->fetchColumn();

    $stmtMemberNotif = $pdo->prepare("
        SELECT id, judul, pesan, tipe, ref_id, ref_tipe, COALESCE(is_read,0) AS is_read, created_at
        FROM notifikasi_member
        WHERE member_id=:member_id
        ORDER BY COALESCE(is_read,0) ASC, created_at DESC, id DESC
        LIMIT 10
    ");
    $stmtMemberNotif->execute([':member_id' => $memberId]);
    $memberNotifRows = $stmtMemberNotif->fetchAll(PDO::FETCH_ASSOC);
    if ((string)($_GET['member_notif_poll'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'unread' => $memberNotifUnread, 'total' => $memberNotifTotal, 'latest' => $memberNotifRows[0] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $notifError) {
    error_log('NOTIFIKASI MEMBER ERROR: ' . $notifError->getMessage());
    $memberNotifRows = [];
    $memberNotifUnread = 0;
}


if (!function_exists('member_ensure_konfirmasi_pinjaman_schema')) {
    function member_ensure_konfirmasi_pinjaman_schema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS konfirmasi_pinjaman_member (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pengajuan_id INT NOT NULL,
                member_id INT NOT NULL,
                keputusan VARCHAR(20) NOT NULL DEFAULT 'pending',
                nama_ktp VARCHAR(180) NULL,
                alamat_ktp TEXT NULL,
                no_hp VARCHAR(40) NULL,
                nama_bank VARCHAR(100) NULL,
                no_rekening VARCHAR(100) NULL,
                nama_keluarga VARCHAR(180) NULL,
                alamat_keluarga TEXT NULL,
                no_hp_keluarga VARCHAR(40) NULL,
                alamat_keluarga_sama TINYINT(1) NOT NULL DEFAULT 0,
                dikonfirmasi_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_konfirmasi_pinjaman_pengajuan (pengajuan_id),
                INDEX idx_konfirmasi_pinjaman_member (member_id),
                INDEX idx_konfirmasi_pinjaman_keputusan (keputusan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Jika tabel pernah dibuat oleh versi lama, CREATE TABLE IF NOT EXISTS tidak menambah kolom baru.
        // Karena itu cek struktur lalu tambahkan hanya kolom yang belum ada.
        $existingCols = [];
        $stmtCols = $pdo->query("SHOW COLUMNS FROM konfirmasi_pinjaman_member");
        foreach ($stmtCols->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $field = (string)($col['Field'] ?? '');
            if ($field !== '') $existingCols[$field] = true;
        }

        $requiredCols = [
            'pengajuan_id' => "INT NOT NULL DEFAULT 0",
            'member_id' => "INT NOT NULL DEFAULT 0",
            'keputusan' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'nama_ktp' => "VARCHAR(180) NULL",
            'alamat_ktp' => "TEXT NULL",
            'no_hp' => "VARCHAR(40) NULL",
            'nama_bank' => "VARCHAR(100) NULL",
            'no_rekening' => "VARCHAR(100) NULL",
            'nama_keluarga' => "VARCHAR(180) NULL",
            'alamat_keluarga' => "TEXT NULL",
            'no_hp_keluarga' => "VARCHAR(40) NULL",
            'alamat_keluarga_sama' => "TINYINT(1) NOT NULL DEFAULT 0",
            'dikonfirmasi_at' => "DATETIME NULL",
            'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "DATETIME NULL",
        ];
        foreach ($requiredCols as $col => $definition) {
            if (!isset($existingCols[$col])) {
                $safeCol = str_replace('`', '', $col);
                $pdo->exec("ALTER TABLE konfirmasi_pinjaman_member ADD COLUMN `{$safeCol}` {$definition}");
            }
        }

        // Pastikan pengajuan_id unik agar ON DUPLICATE KEY UPDATE bekerja.
        try {
            $stmtIdx = $pdo->query("SHOW INDEX FROM konfirmasi_pinjaman_member WHERE Key_name='uq_konfirmasi_pinjaman_pengajuan'");
            if (!$stmtIdx->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE konfirmasi_pinjaman_member ADD UNIQUE KEY uq_konfirmasi_pinjaman_pengajuan (pengajuan_id)");
            }
        } catch (Throwable $e) {
            error_log('KONFIRMASI PINJAMAN INDEX ERROR: ' . $e->getMessage());
        }
    }
}

try {
    member_ensure_konfirmasi_pinjaman_schema($pdo);
} catch (Throwable $e) {
    error_log('MEMBER KONFIRMASI PINJAMAN SCHEMA ERROR: ' . $e->getMessage());
}

try {
    $colsPengajuan = [];
    foreach ($pdo->query("SHOW COLUMNS FROM pengajuan_pinjaman")->fetchAll(PDO::FETCH_ASSOC) as $colPengajuan) {
        $fieldPengajuan = (string)($colPengajuan['Field'] ?? '');
        if ($fieldPengajuan !== '') $colsPengajuan[$fieldPengajuan] = true;
    }
    if (!isset($colsPengajuan['jumlah_disetujui'])) {
        $pdo->exec("ALTER TABLE pengajuan_pinjaman ADD COLUMN jumlah_disetujui DECIMAL(15,2) NULL AFTER jumlah");
    }
} catch (Throwable $e) {
    error_log('MEMBER JUMLAH DISETUJUI SCHEMA ERROR: ' . $e->getMessage());
}

$pinjamanMsg = '';
$pinjamanErr = '';
$konfigSp = [
    'bunga_uang' => 1.00,
    'bunga_barang' => 1.50,
    'tenor_maks_uang' => 24,
    'tenor_maks_barang' => 36,
];
$tenorUang = [];
$tenorBarang = [];
$pengajuanPinjaman = [];
$pinjamanAktif = [];

try {
    // Ambil konfigurasi bunga langsung dari tabel konfigurasi_sp.
    // Tidak bergantung ke has_table_member agar nilai bunga selalu mengikuti database.
    $rowKonfig = $pdo->query("
        SELECT bunga_uang, bunga_barang, tenor_maks_uang, tenor_maks_barang
        FROM konfigurasi_sp
        ORDER BY id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($rowKonfig) {
        $konfigSp['bunga_uang'] = (float)($rowKonfig['bunga_uang'] ?? $konfigSp['bunga_uang']);
        $konfigSp['bunga_barang'] = (float)($rowKonfig['bunga_barang'] ?? $konfigSp['bunga_barang']);
        $konfigSp['tenor_maks_uang'] = (int)($rowKonfig['tenor_maks_uang'] ?? $konfigSp['tenor_maks_uang']);
        $konfigSp['tenor_maks_barang'] = (int)($rowKonfig['tenor_maks_barang'] ?? $konfigSp['tenor_maks_barang']);
    }

    // Ambil pilihan tenor langsung dari tabel konfigurasi_tenor.
    // Tidak memakai fallback hardcode agar pilihan di member selalu sama dengan database.
    $stmtTenor = $pdo->query("
        SELECT jenis, tenor
        FROM konfigurasi_tenor
        WHERE jenis IN ('uang', 'barang')
          AND tenor IS NOT NULL
          AND tenor > 0
        ORDER BY jenis ASC, tenor ASC
    ");

    foreach ($stmtTenor->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jenisTenor = strtolower(trim((string)($row['jenis'] ?? '')));
        $nilaiTenor = (int)($row['tenor'] ?? 0);

        if ($nilaiTenor < 1) {
            continue;
        }

        if ($jenisTenor === 'uang') {
            $tenorUang[] = $nilaiTenor;
        } elseif ($jenisTenor === 'barang') {
            $tenorBarang[] = $nilaiTenor;
        }
    }

    $tenorUang = array_values(array_unique($tenorUang));
    $tenorBarang = array_values(array_unique($tenorBarang));
    sort($tenorUang, SORT_NUMERIC);
    sort($tenorBarang, SORT_NUMERIC);
} catch (Throwable $e) {
    $pinjamanErr = 'Konfigurasi pinjaman belum siap: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajukan_pinjaman') {
    $jenis       = strtolower(trim((string)($_POST['jenis'] ?? '')));
    $tenor       = (int)($_POST['tenor'] ?? 0);
    $keperluan   = trim((string)($_POST['keperluan'] ?? ''));
    $namaBarang  = trim((string)($_POST['nama_barang'] ?? ''));
    $jumlahInput = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['jumlah'] ?? '0'));

    if (!in_array($jenis, ['uang', 'barang'], true)) {
        $pinjamanErr = 'Jenis pinjaman tidak valid.';
    } elseif ($jumlahInput <= 0) {
        $pinjamanErr = $jenis === 'barang' ? 'Harga barang wajib diisi.' : 'Nominal pinjaman wajib diisi.';
    } elseif ($jenis === 'barang' && $namaBarang === '') {
        $pinjamanErr = 'Nama barang wajib diisi untuk pinjaman barang.';
    } elseif (!in_array($tenor, $jenis === 'barang' ? $tenorBarang : $tenorUang, true)) {
        $pinjamanErr = 'Tenor tidak tersedia dalam konfigurasi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO pengajuan_pinjaman
                    (member_id, jenis, jumlah, tenor, keperluan, nama_barang, harga_barang, status, created_at, updated_at)
                VALUES
                    (:member_id, :jenis, :jumlah, :tenor, :keperluan, :nama_barang, :harga_barang, 'pending', NOW(), NOW())
            ");
            $stmt->execute([
                ':member_id' => $memberId,
                ':jenis' => $jenis,
                ':jumlah' => $jumlahInput,
                ':tenor' => $tenor,
                ':keperluan' => $keperluan,
                ':nama_barang' => $jenis === 'barang' ? $namaBarang : null,
                ':harga_barang' => $jenis === 'barang' ? $jumlahInput : null,
            ]);

            $pengajuanBaruId = (int)$pdo->lastInsertId();

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'create', 'Pengajuan Pinjaman', 'Member mengajukan pinjaman ' . $jenis . ' sebesar ' . rupiah_member($jumlahInput));
            }

            header('Location: member_dashboard.php#pinjaman');
            exit;
        } catch (Throwable $e) {
            $pinjamanErr = 'Gagal mengirim pengajuan pinjaman: ' . $e->getMessage();
        }
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'konfirmasi_pinjaman') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pengajuanId = (int)($_POST['id'] ?? 0);
        $keputusan = strtolower(trim((string)($_POST['keputusan'] ?? '')));
        if ($pengajuanId < 1 || !in_array($keputusan, ['ambil', 'tidak_ambil'], true)) {
            throw new Exception('Konfirmasi tidak valid.');
        }

        $stmtCek = $pdo->prepare("SELECT id, status FROM pengajuan_pinjaman WHERE id=:id AND member_id=:mid LIMIT 1");
        $stmtCek->execute([':id' => $pengajuanId, ':mid' => $memberId]);
        $pengajuanKonfirmasi = $stmtCek->fetch(PDO::FETCH_ASSOC);
        if (!$pengajuanKonfirmasi || (string)$pengajuanKonfirmasi['status'] !== 'disetujui') {
            throw new Exception('Pengajuan belum disetujui atau tidak ditemukan.');
        }

        $namaKtp = trim((string)($_POST['nama_ktp'] ?? ''));
        $alamatKtp = trim((string)($_POST['alamat_ktp'] ?? ''));
        $noHp = trim((string)($_POST['no_hp'] ?? ''));
        $namaBank = trim((string)($_POST['nama_bank'] ?? ''));
        $noRekening = trim((string)($_POST['no_rekening'] ?? ''));
        $namaKeluarga = trim((string)($_POST['nama_keluarga'] ?? ''));
        $alamatKeluargaSama = !empty($_POST['alamat_keluarga_sama']) ? 1 : 0;
        $alamatKeluarga = $alamatKeluargaSama ? $alamatKtp : trim((string)($_POST['alamat_keluarga'] ?? ''));
        $noHpKeluarga = trim((string)($_POST['no_hp_keluarga'] ?? ''));

        if ($keputusan === 'ambil') {
            $required = [
                'Nama sesuai KTP' => $namaKtp,
                'Alamat sesuai KTP' => $alamatKtp,
                'No. Handphone' => $noHp,
                'Nama Bank' => $namaBank,
                'No. Rekening' => $noRekening,
                'Nama anggota keluarga' => $namaKeluarga,
                'Alamat anggota keluarga' => $alamatKeluarga,
                'No. Handphone keluarga' => $noHpKeluarga,
            ];
            foreach ($required as $label => $value) {
                if ($value === '') throw new Exception($label . ' wajib diisi.');
            }
        } else {
            $namaKtp = $alamatKtp = $noHp = $namaBank = $noRekening = $namaKeluarga = $alamatKeluarga = $noHpKeluarga = '';
            $alamatKeluargaSama = 0;
        }

        $stmtUp = $pdo->prepare("
            INSERT INTO konfirmasi_pinjaman_member
                (pengajuan_id, member_id, keputusan, nama_ktp, alamat_ktp, no_hp, nama_bank, no_rekening,
                 nama_keluarga, alamat_keluarga, no_hp_keluarga, alamat_keluarga_sama, dikonfirmasi_at, created_at, updated_at)
            VALUES
                (:pid,:mid,:keputusan,:nama_ktp,:alamat_ktp,:no_hp,:nama_bank,:no_rekening,
                 :nama_keluarga,:alamat_keluarga,:no_hp_keluarga,:alamat_sama,NOW(),NOW(),NOW())
            ON DUPLICATE KEY UPDATE
                keputusan=VALUES(keputusan), nama_ktp=VALUES(nama_ktp), alamat_ktp=VALUES(alamat_ktp),
                no_hp=VALUES(no_hp), nama_bank=VALUES(nama_bank), no_rekening=VALUES(no_rekening),
                nama_keluarga=VALUES(nama_keluarga), alamat_keluarga=VALUES(alamat_keluarga),
                no_hp_keluarga=VALUES(no_hp_keluarga), alamat_keluarga_sama=VALUES(alamat_keluarga_sama),
                dikonfirmasi_at=NOW(), updated_at=NOW()
        ");
        $stmtUp->execute([
            ':pid' => $pengajuanId,
            ':mid' => $memberId,
            ':keputusan' => $keputusan,
            ':nama_ktp' => $namaKtp,
            ':alamat_ktp' => $alamatKtp,
            ':no_hp' => $noHp,
            ':nama_bank' => $namaBank,
            ':no_rekening' => $noRekening,
            ':nama_keluarga' => $namaKeluarga,
            ':alamat_keluarga' => $alamatKeluarga,
            ':no_hp_keluarga' => $noHpKeluarga,
            ':alamat_sama' => $alamatKeluargaSama
        ]);

        // Konfirmasi utama sudah tersimpan. Pencatatan aktivitas tidak boleh menggagalkan simpan konfirmasi.
        if (function_exists('catat_aktivitas')) {
            try {
                catat_aktivitas($pdo, 'update', 'Pengajuan Pinjaman', 'Member mengonfirmasi pengajuan #' . $pengajuanId . ': ' . $keputusan);
            } catch (Throwable $logError) {
                error_log('LOG KONFIRMASI PINJAMAN MEMBER: ' . $logError->getMessage());
            }
        }
        echo json_encode(['success' => true, 'message' => $keputusan === 'ambil' ? 'Konfirmasi berhasil disimpan. Data pengambilan pinjaman sudah dikirim ke admin.' : 'Konfirmasi berhasil disimpan. Anda memilih tidak mengambil pinjaman ini.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batal_pengajuan') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pengajuanId = (int)($_POST['id'] ?? 0);

        if ($pengajuanId < 1) {
            echo json_encode(['success' => false, 'message' => 'ID pengajuan tidak valid.']);
            exit;
        }

        $stmtCek = $pdo->prepare("
            SELECT id, status
            FROM pengajuan_pinjaman
            WHERE id = :id
              AND member_id = :member_id
            LIMIT 1
        ");
        $stmtCek->execute([
            ':id' => $pengajuanId,
            ':member_id' => $memberId
        ]);
        $pengajuanBatal = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$pengajuanBatal) {
            echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan.']);
            exit;
        }

        if (($pengajuanBatal['status'] ?? '') !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Pengajuan hanya bisa dibatalkan saat status masih pending.']);
            exit;
        }

        $stmtBatal = $pdo->prepare("
            UPDATE pengajuan_pinjaman
            SET status = 'dibatalkan',
                updated_at = NOW()
            WHERE id = :id
              AND member_id = :member_id
              AND status = 'pending'
        ");
        $stmtBatal->execute([
            ':id' => $pengajuanId,
            ':member_id' => $memberId
        ]);

        if (function_exists('catat_aktivitas')) {
            catat_aktivitas($pdo, 'update', 'Pengajuan Pinjaman', 'Member membatalkan pengajuan pinjaman #' . $pengajuanId);
        }

        echo json_encode(['success' => true, 'message' => 'Pengajuan berhasil dibatalkan.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal membatalkan pengajuan: ' . $e->getMessage()]);
    }

    exit;
}


try {
    $stmtPengajuan = $pdo->prepare("
        SELECT pp.*,
               COALESCE(kpm.keputusan, 'pending') AS konfirmasi_keputusan,
               kpm.dikonfirmasi_at AS konfirmasi_at,
               kpm.nama_ktp AS konfirmasi_nama_ktp,
               kpm.alamat_ktp AS konfirmasi_alamat_ktp,
               kpm.no_hp AS konfirmasi_no_hp,
               kpm.nama_bank AS konfirmasi_nama_bank,
               kpm.no_rekening AS konfirmasi_no_rekening,
               kpm.nama_keluarga AS konfirmasi_nama_keluarga,
               kpm.alamat_keluarga AS konfirmasi_alamat_keluarga,
               kpm.no_hp_keluarga AS konfirmasi_no_hp_keluarga
        FROM pengajuan_pinjaman pp
        LEFT JOIN konfirmasi_pinjaman_member kpm ON kpm.pengajuan_id = pp.id
        WHERE pp.member_id = :member_id
        ORDER BY pp.created_at DESC, pp.id DESC
        LIMIT 20
    ");
    $stmtPengajuan->execute([
        ':member_id' => $memberId
    ]);
    $pengajuanPinjaman = $stmtPengajuan->fetchAll(PDO::FETCH_ASSOC);

    $stmtPinjaman = $pdo->prepare("
        SELECT *
        FROM pinjaman
        WHERE member_id = :member_id
          AND status IN ('aktif', 'berjalan')
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");
    $stmtPinjaman->execute([
        ':member_id' => $memberId
    ]);
    $pinjamanAktif = $stmtPinjaman->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (!$pinjamanErr) {
        $pinjamanErr = 'Gagal memuat data pinjaman: ' . $e->getMessage();
    }
}

$jumlahTransaksi         = (int)($summary['jumlah_transaksi']           ?? 0);
$totalBelanjaTransaksi   = (int)($summary['total_belanja_transaksi']    ?? 0);
$totalDiskonTransaksi    = (int)($summary['total_diskon_transaksi']     ?? 0);
$totalPointDariTransaksi = (int)($summary['total_point_dari_transaksi'] ?? 0);
$totalPointPakai         = (int)($summary['total_point_pakai']          ?? 0);
$totalNilaiPointPakai    = (int)($summary['total_nilai_point_pakai']    ?? 0);
$saldoPoint              = (int)($member['point']                       ?? 0);
$totalBelanjaProfil      = (int)($member['total_belanja']               ?? 0);
$trxBeranda              = array_slice($transaksi, 0, 3);

$transportBookings = [];

try {
    $stmtTransport = $pdo->prepare("
        SELECT
            rb.id,
            rb.kode_booking,
            rb.member_id,
            rb.driver_id,
            rb.nama_pemesan,
            rb.no_hp,
            rb.layanan,
            rb.lokasi_jemput,
            rb.tujuan,
            rb.tanggal,
            rb.jam,
            rb.jumlah_penumpang,
            rb.kendaraan,
            rb.total_harga,
            rb.status,
            rb.catatan,
            rb.created_at,
            d.nama AS driver_nama,
            d.no_hp AS driver_no_hp,
            d.kendaraan_nama AS driver_kendaraan,
            d.plat_nomor AS driver_plat,
            d.rating AS driver_rating
        FROM rental_bandara rb
        LEFT JOIN driver d ON d.id = rb.driver_id
        WHERE rb.member_id = :member_id
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");

    $stmtTransport->execute([
        ':member_id' => $memberId
    ]);

    $transportBookings = $stmtTransport->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $transportBookings = [];
}

$jumlahRiwayatPesanan = $jumlahTransaksi + count($transportBookings);

if (!function_exists('transport_status_class_member')) {
    /**
     * @param mixed $status
     */
    function transport_status_class_member($status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'diproses') {
            return 'transport-status-blue';
        }

        if ($status === 'driver_menuju_lokasi') {
            return 'transport-status-blue';
        }

        if ($status === 'dalam_perjalanan') {
            return 'transport-status-purple';
        }

        if ($status === 'selesai') {
            return 'transport-status-green';
        }

        if ($status === 'batal') {
            return 'transport-status-red';
        }

        return 'transport-status-orange';
    }
}

if (!function_exists('transport_status_label_member')) {
    /**
     * @param mixed $status
     */
    function transport_status_label_member($status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'diproses') {
            return 'Diproses';
        }

        if ($status === 'driver_menuju_lokasi') {
            return 'Driver Menuju Lokasi';
        }

        if ($status === 'dalam_perjalanan') {
            return 'Dalam Perjalanan';
        }

        if ($status === 'selesai') {
            return 'Selesai';
        }

        if ($status === 'batal') {
            return 'Batal';
        }

        return 'Pending';
    }
}

if (!function_exists('transport_layanan_label_member')) {
    /**
     * @param mixed $layanan
     */
    function transport_layanan_label_member($layanan): string
    {
        return ((string)$layanan === 'jemput_bandara') ? 'Jemput Bandara' : 'Antar Bandara';
    }
}

if (!function_exists('transport_kendaraan_label_member')) {
    /**
     * @param mixed $kendaraan
     */
    function transport_kendaraan_label_member($kendaraan): string
    {
        $kendaraan = strtolower(trim((string)$kendaraan));

        if ($kendaraan === 'innova') {
            return 'Toyota Innova';
        }

        if ($kendaraan === 'hiace') {
            return 'Hiace Premio';
        }

        return 'Toyota Avanza';
    }
}

if (!function_exists('transport_tanggal_member')) {
    /**
     * @param mixed $v
     */
    function transport_tanggal_member($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('transport_jam_member')) {
    /**
     * @param mixed $v
     */
    function transport_jam_member($v): string
    {
        return $v ? date('H:i', strtotime((string)$v)) : '-';
    }
}




// ── Simpanan member dari database ───────────────────────────────────────────
$simpananError = '';
$simpananTahunOptions = [];
$simpananTahunAktif = 0;
$simpananBulanAktif = (int)($_GET['simpanan_bulan'] ?? date('n'));
if ($simpananBulanAktif < 1 || $simpananBulanAktif > 12) {
    $simpananBulanAktif = (int)date('n');
}

$simpananSummary = [
    'pokok' => 0,
    'wajib' => 0,
    'sukarela' => 0,
    'total' => 0,
];
$simpananBulanIni = [
    'pokok' => 0,
    'wajib' => 0,
    'sukarela' => 0,
    'total' => 0,
];
$simpananBulanan = [];
$simpananRiwayat = [];
$bulanNamaMember = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'Mei',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Agu',
    9 => 'Sep',
    10 => 'Okt',
    11 => 'Nov',
    12 => 'Des',
];

for ($i = 1; $i <= 12; $i++) {
    $simpananBulanan[$i] = [
        'pokok' => 0,
        'wajib' => 0,
        'sukarela' => 0,
        'total' => 0,
    ];
}

try {
    if (has_table_member($pdo, 'simpanan')) {
        $stmtTahunSimpanan = $pdo->prepare("\n            SELECT DISTINCT tahun\n            FROM simpanan\n            WHERE member_id = :member_id\n              AND tahun IS NOT NULL\n              AND tahun > 0\n            ORDER BY tahun DESC\n        ");
        $stmtTahunSimpanan->execute([':member_id' => $memberId]);
        $simpananTahunOptions = array_map('intval', $stmtTahunSimpanan->fetchAll(PDO::FETCH_COLUMN));

        $tahunReq = (int)($_GET['simpanan_tahun'] ?? 0);
        $tahunBerjalan = (int)date('Y');

        // Default tampilan mengikuti tahun berjalan. Tahun lama tetap bisa dipilih dari dropdown.
        if ($tahunReq > 0 && in_array($tahunReq, $simpananTahunOptions, true)) {
            $simpananTahunAktif = $tahunReq;
        } else {
            $simpananTahunAktif = $tahunBerjalan;
        }

        if (!in_array($simpananTahunAktif, $simpananTahunOptions, true)) {
            $simpananTahunOptions[] = $simpananTahunAktif;
            rsort($simpananTahunOptions, SORT_NUMERIC);
        }

        $stmtSimpanan = $pdo->prepare("\n            SELECT\n                bulan,\n                jenis,\n                COALESCE(SUM(jumlah), 0) AS total\n            FROM simpanan\n            WHERE member_id = :member_id\n              AND tahun = :tahun\n            GROUP BY bulan, jenis\n            ORDER BY bulan ASC\n        ");
        $stmtSimpanan->execute([
            ':member_id' => $memberId,
            ':tahun' => $simpananTahunAktif,
        ]);

        foreach ($stmtSimpanan->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bulan = (int)($row['bulan'] ?? 0);
            $jenis = strtolower(trim((string)($row['jenis'] ?? '')));
            $jumlah = (float)($row['total'] ?? 0);

            if ($bulan < 1 || $bulan > 12 || !isset($simpananBulanan[$bulan][$jenis])) {
                continue;
            }

            $simpananBulanan[$bulan][$jenis] += $jumlah;
            $simpananBulanan[$bulan]['total'] += $jumlah;
            $simpananSummary[$jenis] += $jumlah;
            $simpananSummary['total'] += $jumlah;
        }

        // Nominal bulan aktif tetap murni transaksi bulan tersebut.
        $simpananBulanIni = $simpananBulanan[$simpananBulanAktif] ?? $simpananBulanIni;

        // Saldo/total utama adalah akumulasi dari semua data sebelumnya sampai bulan & tahun aktif.
        $simpananSummary = [
            'pokok' => 0,
            'wajib' => 0,
            'sukarela' => 0,
            'total' => 0,
        ];
        $stmtAkumulasiSimpanan = $pdo->prepare("
            SELECT
                jenis,
                COALESCE(SUM(jumlah), 0) AS total
            FROM simpanan
            WHERE member_id = :member_id_akumulasi
              AND (
                    tahun < :tahun_sebelum
                    OR (tahun = :tahun_sama AND bulan <= :bulan_sampai)
                  )
            GROUP BY jenis
        ");
        $stmtAkumulasiSimpanan->execute([
            ':member_id_akumulasi' => $memberId,
            ':tahun_sebelum' => $simpananTahunAktif,
            ':tahun_sama' => $simpananTahunAktif,
            ':bulan_sampai' => $simpananBulanAktif,
        ]);
        foreach ($stmtAkumulasiSimpanan->fetchAll(PDO::FETCH_ASSOC) as $rowAkumulasi) {
            $jenisAkumulasi = strtolower(trim((string)($rowAkumulasi['jenis'] ?? '')));
            $jumlahAkumulasi = (float)($rowAkumulasi['total'] ?? 0);
            if (!array_key_exists($jenisAkumulasi, $simpananSummary)) {
                continue;
            }
            $simpananSummary[$jenisAkumulasi] += $jumlahAkumulasi;
            $simpananSummary['total'] += $jumlahAkumulasi;
        }

        $stmtRiwayatSimpanan = $pdo->prepare("\n            SELECT id, jenis, jumlah, bulan, tahun, keterangan, created_at\n            FROM simpanan\n            WHERE member_id = :member_id\n            ORDER BY tahun DESC, bulan DESC, id DESC\n            LIMIT 30\n        ");
        $stmtRiwayatSimpanan->execute([':member_id' => $memberId]);
        $simpananRiwayat = $stmtRiwayatSimpanan->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $simpananError = 'Tabel simpanan belum terbaca dari koneksi database member_dashboard.php. Pastikan tabel simpanan ada di database yang dipakai config.php.';
        $simpananTahunAktif = (int)date('Y');
    }
} catch (Throwable $e) {
    $simpananError = 'Gagal memuat data simpanan: ' . $e->getMessage();
    if ($simpananTahunAktif < 1) {
        $simpananTahunAktif = (int)date('Y');
    }
}

catat_view_once($pdo, 'Member Dashboard', 'Membuka halaman Member Dashboard');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Dashboard Member — SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ── Reset ── */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --black: #0a0a0a;
            --g1: #111827;
            --g2: #374151;
            --g3: #6b7280;
            --g4: #9ca3af;
            --g5: #d1d5db;
            --g6: #e5e7eb;
            --g7: #f3f4f6;
            --g8: #f9fafb;
            --white: #fff;
            --r: 2px;
            --nav-h: 64px;
            --hdr-h: 56px;
            --sidebar-w: 260px;
        }

        html,
        body {
            height: 100%;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 14px;
            color: var(--g1);
            -webkit-font-smoothing: antialiased;
            background: #f0f0f0;
            width: 100%;
            min-width: 0;
            overflow-x: hidden;
        }

        /* ── App Shell — always block layout (no grid) ── */
        .desktop-topbar,
        .desktop-sidebar {
            display: none !important;
        }

        .app-shell {
            display: block !important;
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            min-height: 100dvh !important;
            background: var(--white) !important;
            box-shadow: none !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        .desktop-content {
            display: block !important;
            width: 100% !important;
            min-height: 100dvh !important;
            height: auto !important;
            overflow: visible !important;
        }

        /* ── Pages ── */
        .page {
            display: none;
            flex-direction: column;
            width: 100% !important;
            min-height: 100dvh !important;
            padding-bottom: calc(var(--nav-h) + 20px) !important;
        }

        .page.active {
            display: flex;
        }

        .page-top {
            display: none !important;
        }

        /* ── Bottom Nav ── */
        .bottom-nav {
            display: flex !important;
            position: fixed !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            transform: none !important;
            width: 100% !important;
            max-width: none !important;
            height: var(--nav-h);
            background: var(--white);
            border-top: 0.5px solid var(--g6);
            align-items: stretch;
            z-index: 200;
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }

        .nav-btn {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            border: none;
            background: none;
            cursor: pointer;
            position: relative;
            padding: 0;
            transition: background .15s;
        }

        .nav-btn:hover {
            background: var(--g8);
        }

        .nav-btn .nav-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .nav-btn .nav-icon svg {
            width: 20px;
            height: 20px;
            stroke: var(--g4);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: stroke .15s;
        }

        .nav-btn .nav-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            transition: color .15s;
        }

        .nav-btn.active .nav-icon svg {
            stroke: var(--black);
        }

        .nav-btn.active .nav-label {
            color: var(--black);
        }

        .nav-btn.active::after {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 24px;
            height: 2px;
            background: var(--black);
        }

        .nav-badge {
            position: absolute;
            top: -4px;
            right: -6px;
            min-width: 16px;
            height: 16px;
            background: var(--black);
            color: var(--white);
            font-size: 9px;
            font-weight: 900;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        /* ── Shared ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            border-radius: 0;
            border: 0.5px solid var(--g6);
            background: var(--white);
            color: var(--g2);
            text-decoration: none;
            transition: background .12s, border-color .12s;
            white-space: nowrap;
        }

        .btn:hover {
            background: var(--g8);
        }

        .btn-black {
            background: var(--black);
            color: var(--white);
            border-color: var(--black);
        }

        .btn-black:hover {
            background: var(--g2);
            border-color: var(--g2);
        }

        .btn-danger {
            border-color: #fca5a5;
            color: #dc2626;
        }

        .btn-danger:hover {
            background: #fef2f2;
        }

        .section-label {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g4);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-radius: 0;
            border: 0.5px solid var(--g6);
            color: var(--g2);
            background: var(--g7);
        }

        .badge-selesai {
            background: var(--g7);
            color: var(--g2);
            border-color: var(--g5);
        }

        .gap-section {
            height: 8px;
            background: var(--g8);
            flex-shrink: 0;
        }

        .no-scrollbar {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .divider {
            height: 0.5px;
            background: var(--g6);
        }

        /* ── Hero Beranda ── */
        .beranda-hero {
            background: var(--black);
            color: var(--white);
            padding: 24px 16px 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .beranda-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255, 255, 255, .025) 20px, rgba(255, 255, 255, .025) 21px);
            pointer-events: none;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
        }

        .hero-greeting {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .15em;
            opacity: .5;
        }

        .hero-name {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.02em;
            margin-top: 4px;
            line-height: 1.1;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
            position: relative;
            z-index: 1;
        }

        .hero-tag {
            padding: 4px 10px;
            border: 0.5px solid rgba(255, 255, 255, .2);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border-radius: 0;
            color: rgba(255, 255, 255, .7);
            background: rgba(255, 255, 255, .08);
        }

        .hero-tag-active {
            border-color: rgba(255, 255, 255, .5);
            color: var(--white);
            background: rgba(255, 255, 255, .15);
        }

        /* ── Point Strip ── */
        .point-strip {
            margin: 12px;
            border: none;
            border-radius: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            overflow: visible;
            flex-shrink: 0;
        }

        .point-strip-left {
            padding: 14px 16px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            min-width: 0;
        }

        .point-strip-right {
            padding: 14px 16px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            min-width: 0;
        }

        .point-number {
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -.04em;
            color: var(--black);
            line-height: 1;
            margin-top: 4px;
        }

        .point-unit {
            font-size: 12px;
            font-weight: 700;
            color: var(--g4);
            margin-left: 3px;
        }

        /* ── Stats Grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            padding: 12px;
        }

        .stat-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 14px;
            transition: border-color .15s;
            position: relative;
            overflow: hidden;
            min-height: 88px;
            background: var(--white);
        }

        .stat-card:hover {
            border-color: var(--g4);
        }

        .stat-card::after {
            content: attr(data-icon);
            position: absolute;
            right: 10px;
            bottom: 6px;
            font-size: 42px;
            line-height: 1;
            opacity: .06;
            pointer-events: none;
            filter: grayscale(100%);
        }

        .stat-card-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
            position: relative;
            z-index: 2;
        }

        .stat-card-value {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -.03em;
            margin-top: 6px;
            line-height: 1;
            color: var(--black);
            position: relative;
            z-index: 2;
            padding-right: 30px;
        }

        .stat-card-sub {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 4px;
            position: relative;
            z-index: 2;
            padding-right: 30px;
        }

        /* ── Quick Menu ── */
        .quick-menu-wrap {
            padding: 20px 16px 16px;
            flex-shrink: 0;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            margin-top: 12px;
        }

        .quick-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 12px 4px;
            border: none;
            background: none;
            cursor: pointer;
            font-family: inherit;
            border-radius: 4px;
            transition: background .12s;
        }

        .quick-item:hover {
            background: var(--g8);
        }

        .quick-ico {
            width: 44px;
            height: 44px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
        }

        .quick-ico svg {
            width: 18px;
            height: 18px;
            stroke: var(--black);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .quick-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--g2);
            text-align: center;
            line-height: 1.3;
        }

        /* ── Transaksi List ── */
        .trx-list {
            padding: 0 16px;
            flex-shrink: 0;
        }

        .trx-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--g7);
        }

        .trx-row:last-child {
            border-bottom: none;
        }

        .trx-icon-box {
            width: 38px;
            height: 38px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--g8);
        }

        .trx-icon-box svg {
            width: 16px;
            height: 16px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .trx-info {
            flex: 1;
            min-width: 0;
        }

        .trx-inv {
            font-size: 12px;
            font-weight: 800;
            color: var(--black);
        }

        .trx-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .trx-right {
            text-align: right;
            flex-shrink: 0;
        }

        .trx-total {
            font-size: 13px;
            font-weight: 900;
            color: var(--black);
        }

        .trx-pt {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            margin-top: 2px;
        }

        /* ── Hero Promo & Pesanan (sama) ── */
        .promo-hero-bar {
            background: var(--black);
            color: var(--white);
            padding: 20px 16px;
            flex-shrink: 0;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .promo-hero-bar::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(-45deg, transparent, transparent 20px, rgba(255, 255, 255, .03) 20px, rgba(255, 255, 255, .03) 21px);
            pointer-events: none;
        }

        .promo-hero-bar h2 {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -.03em;
            position: relative;
            z-index: 1;
        }

        .promo-hero-bar p {
            font-size: 11px;
            font-weight: 600;
            opacity: .5;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .1em;
            position: relative;
            z-index: 1;
        }

        /* ── Tab Bar ── */
        .tab-bar {
            display: flex;
            border-bottom: 0.5px solid var(--g6);
            background: var(--white);
            overflow-x: auto;
            flex-shrink: 0;
        }

        .tab-btn {
            padding: 12px 16px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            border: none;
            border-bottom: 2px solid transparent;
            background: none;
            cursor: pointer;
            white-space: nowrap;
            transition: color .12s;
            flex-shrink: 0;
        }

        .tab-btn:hover {
            color: var(--g2);
        }

        .tab-btn.active {
            color: var(--black);
            border-bottom-color: var(--black);
        }

        /* ── Order Summary Bar ── */
        .order-summary-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            border-bottom: 0.5px solid var(--g6);
        }

        .osb-item {
            padding: 14px 10px;
            text-align: center;
            border-right: 0.5px solid var(--g6);
        }

        .osb-item:last-child {
            border-right: none;
        }

        .osb-val {
            font-size: 16px;
            font-weight: 900;
            color: var(--black);
        }

        .osb-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            margin-top: 4px;
        }

        /* ── Order Cards ── */
        .order-list {
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .order-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            overflow: hidden;
            background: var(--white);
            transition: border-color .12s, box-shadow .12s;
        }

        .order-card:hover {
            border-color: var(--g5);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        .order-card-head {
            padding: 12px 14px;
            border-bottom: 0.5px solid var(--g7);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--g8);
        }

        .order-store {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--black);
        }

        .order-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .order-items-wrap {
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .order-item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .oi-name {
            font-size: 12px;
            color: var(--g2);
            font-weight: 600;
        }

        .oi-price {
            font-size: 12px;
            font-weight: 800;
            color: var(--black);
        }

        .order-card-foot {
            padding: 10px 14px;
            border-top: 0.5px solid var(--g7);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-total-wrap .ot-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
        }

        .order-total-wrap .ot-val {
            font-size: 15px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.02em;
            margin-top: 1px;
        }

        .order-total-wrap .ot-pt {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            margin-top: 2px;
        }

        .order-actions {
            display: flex;
            gap: 6px;
        }

        /* ── Promo ── */
        .promo-list {
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        /* ── Belanja ── */
        .search-wrap {
            padding: 12px 16px;
            background: var(--white);
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .search-inner {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 0 12px;
            height: 38px;
            background: var(--g8);
        }

        .search-inner svg {
            width: 15px;
            height: 15px;
            stroke: var(--g4);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .search-inner input {
            flex: 1;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            color: var(--black);
            outline: none;
        }

        .search-inner input::placeholder {
            color: var(--g4);
            font-weight: 500;
        }

        .cat-scroll-wrap {
            padding: 10px 16px;
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .cat-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
        }

        .cat-chip {
            padding: 6px 14px;
            border: 0.5px solid var(--g6);
            border-radius: 20px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--g4);
            background: var(--white);
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all .12s;
        }

        .cat-chip:hover {
            border-color: var(--g4);
            color: var(--g2);
        }

        .cat-chip.active {
            background: var(--black);
            color: var(--white);
            border-color: var(--black);
        }

        /* ── Akun ── */
        .akun-hero {
            background: var(--black);
            color: var(--white);
            padding: 24px 16px 20px;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .akun-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255, 255, 255, .025) 20px, rgba(255, 255, 255, .025) 21px);
            pointer-events: none;
        }

        .akun-avatar {
            width: 56px;
            height: 56px;
            border-radius: 0;
            border: 0.5px solid rgba(255, 255, 255, .3);
            background: rgba(255, 255, 255, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.04em;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }

        .akun-nama {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -.02em;
            position: relative;
            z-index: 1;
        }

        .akun-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
            position: relative;
            z-index: 1;
        }

        .akun-tag {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 3px 8px;
            border: 0.5px solid rgba(255, 255, 255, .2);
            color: rgba(255, 255, 255, .65);
            border-radius: 0;
        }

        .akun-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 0.5px solid var(--g6);
            flex-shrink: 0;
        }

        .akun-stat {
            padding: 16px 14px;
            border-right: 0.5px solid var(--g6);
            text-align: center;
        }

        .akun-stat:last-child {
            border-right: none;
        }

        .akun-stat-val {
            font-size: 18px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.03em;
            line-height: 1;
        }

        .akun-stat-lbl {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            margin-top: 4px;
        }

        .menu-section {
            padding: 16px 16px 0;
            flex-shrink: 0;
        }

        .menu-section-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g4);
            margin-bottom: 6px;
        }

        .menu-group-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            overflow: hidden;
            background: var(--white);
            margin-bottom: 16px;
        }

        .menu-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            border-bottom: 0.5px solid var(--g7);
            cursor: pointer;
            width: 100%;
            border-left: none;
            border-right: none;
            border-top: none;
            background: none;
            font-family: inherit;
            text-align: left;
            transition: background .12s;
        }

        .menu-row:last-child {
            border-bottom: none;
        }

        .menu-row:hover {
            background: var(--g8);
        }

        .menu-ico {
            width: 34px;
            height: 34px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            flex-shrink: 0;
        }

        .menu-ico svg {
            width: 15px;
            height: 15px;
            stroke: var(--black);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .menu-text-wrap {
            flex: 1;
            min-width: 0;
        }

        .menu-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--black);
        }

        .menu-sub {
            font-size: 11px;
            color: var(--g4);
            font-weight: 500;
            margin-top: 1px;
        }

        .menu-chevron {
            color: var(--g5);
            font-size: 18px;
            flex-shrink: 0;
            font-weight: 300;
        }

        .menu-badge-pill {
            font-size: 9px;
            font-weight: 900;
            padding: 3px 8px;
            background: var(--black);
            color: var(--white);
            border-radius: 0;
            letter-spacing: .04em;
        }

        .akun-footer {
            padding: 0 16px 16px;
            flex-shrink: 0;
        }

        /* ── Modal ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 500;
            display: none;
            align-items: flex-end;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-sheet {
            background: var(--white);
            width: 100%;
            max-width: 480px;
            border-radius: 12px 12px 0 0;
            max-height: 90dvh;
            overflow-y: auto;
            animation: slideUp .25s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        .modal-header {
            position: sticky;
            top: 0;
            background: var(--white);
            border-bottom: 0.5px solid var(--g6);
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .modal-title {
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: none;
            transition: background .12s;
        }

        .modal-close:hover {
            background: var(--g8);
        }

        .modal-close svg {
            width: 14px;
            height: 14px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal-body {
            padding: 20px 16px;
        }

        /* ── Form ── */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--black);
            background: var(--white);
            outline: none;
            transition: border-color .12s;
        }

        .form-input:focus {
            border-color: var(--black);
        }

        .form-input:disabled {
            background: var(--g8);
            color: var(--g4);
        }

        .form-hint {
            font-size: 10px;
            font-weight: 600;
            color: var(--g4);
            margin-top: 4px;
        }

        /* ── Point History ── */
        .pt-history-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--g7);
        }

        .pt-history-item:last-child {
            border-bottom: none;
        }

        .pt-hist-ico {
            width: 36px;
            height: 36px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--g8);
        }

        .pt-hist-ico svg {
            width: 14px;
            height: 14px;
            stroke: var(--g2);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .pt-hist-info {
            flex: 1;
            min-width: 0;
        }

        .pt-hist-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--black);
        }

        .pt-hist-date {
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
            margin-top: 2px;
        }

        .pt-hist-val {
            font-size: 14px;
            font-weight: 900;
            color: var(--black);
        }

        .pt-hist-val.plus {
            color: #15803d;
        }

        .pt-hist-val.minus {
            color: #dc2626;
        }

        /* ── Voucher ── */
        .voucher-card {
            border: 0.5px dashed var(--g5);
            border-radius: 0;
            padding: 14px;
            position: relative;
            overflow: hidden;
            margin-bottom: 10px;
            cursor: pointer;
            transition: border-color .12s;
        }

        .voucher-card:hover {
            border-color: var(--black);
        }

        .voucher-card::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--g8);
            border: 0.5px solid var(--g6);
        }

        .voucher-card::after {
            content: '';
            position: absolute;
            right: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--g8);
            border: 0.5px solid var(--g6);
        }

        .vc-code {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: .1em;
            color: var(--black);
        }

        .vc-desc {
            font-size: 11px;
            font-weight: 600;
            color: var(--g3);
            margin-top: 4px;
        }

        .vc-exp {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: 8px;
        }

        .vc-val {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.03em;
            color: var(--black);
        }

        /* ── Tukar Point ── */
        .tukar-point-hero {
            background: var(--black);
            color: var(--white);
            padding: 20px;
            border-radius: 0;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .tukar-point-hero::after {
            content: '⭐';
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 80px;
            opacity: .08;
        }

        .tp-balance {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .tukar-option {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 14px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all .12s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tukar-option:hover {
            border-color: var(--black);
            background: var(--g8);
        }

        .tukar-option.selected {
            border-color: var(--black);
            background: var(--black);
            color: var(--white);
        }

        .to-info .to-name {
            font-size: 13px;
            font-weight: 800;
        }

        .to-info .to-req {
            font-size: 11px;
            font-weight: 600;
            opacity: .5;
            margin-top: 2px;
        }

        .to-val {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        /* ── FAQ ── */
        .faq-item {
            border-bottom: 0.5px solid var(--g7);
        }

        .faq-q {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: var(--black);
        }

        .faq-a {
            font-size: 12px;
            color: var(--g3);
            font-weight: 500;
            padding-bottom: 14px;
            line-height: 1.6;
            display: none;
        }

        .faq-a.open {
            display: block;
        }

        .faq-chevron {
            transition: transform .2s;
            font-size: 16px;
            color: var(--g4);
        }

        .faq-chevron.open {
            transform: rotate(180deg);
        }

        /* ── Kontak ── */
        .kontak-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all .12s;
            text-decoration: none;
        }

        .kontak-card:hover {
            border-color: var(--black);
            background: var(--g8);
        }

        .kontak-ico {
            width: 44px;
            height: 44px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
        }

        .kontak-info .kc-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--black);
        }

        .kontak-info .kc-sub {
            font-size: 11px;
            font-weight: 600;
            color: var(--g4);
            margin-top: 2px;
        }

        /* ── About ── */
        .about-logo {
            font-size: 36px;
            font-weight: 900;
            letter-spacing: -.04em;
            border-bottom: 3px solid var(--black);
            display: inline-block;
            padding-bottom: 4px;
            margin-bottom: 16px;
        }

        .about-version {
            font-size: 11px;
            font-weight: 700;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .1em;
        }

        /* ── Utility ── */
        .info-box {
            padding: 10px 14px;
            border: 0.5px solid var(--g5);
            border-radius: 0;
            background: var(--g8);
            font-size: 11px;
            color: var(--g3);
            font-weight: 600;
            margin: 12px 16px 0;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            gap: 12px;
        }

        .empty-state svg {
            width: 40px;
            height: 40px;
            stroke: var(--g5);
            fill: none;
            stroke-width: 1.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .empty-state p {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .15em;
            color: var(--g5);
            text-align: center;
        }

        .success-toast {
            position: fixed;
            bottom: calc(var(--nav-h) + 16px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--black);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 0;
            font-size: 12px;
            font-weight: 800;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
            white-space: nowrap;
        }

        .success-toast.show {
            opacity: 1;
        }



        /* ── Pesanan Gabungan ── */
        .pesanan-content {
            display: none;
        }

        .pesanan-content.active {
            display: block;
        }

        .pesanan-content .order-list {
            padding-top: 12px;
        }



        .transport-alert {
            margin: 12px 16px 0;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 12px 14px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.5;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .transport-alert-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .transport-alert-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .transport-alert-icon {
            width: 18px;
            height: 18px;
            min-width: 18px;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 900;
            background: rgba(255, 255, 255, .7);
        }


        /* ── Transport Bandara ── */
        .transport-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .transport-card-box {
            margin: 12px 16px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            background: var(--white);
            padding: 16px;
        }

        .transport-section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .transport-section-head h3 {
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--black);
        }

        .transport-section-head span {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--g4);
            border: 0.5px solid var(--g6);
            padding: 4px 8px;
            border-radius: 0;
            background: var(--g8);
        }

        .transport-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .transport-form-group label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--g4);
        }

        .transport-input {
            width: 100%;
            padding: 11px 12px;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--black);
            background: var(--white);
            outline: none;
            transition: border-color .12s, background .12s;
        }

        .transport-input:focus {
            border-color: var(--black);
            background: var(--white);
        }

        .transport-textarea {
            min-height: 88px;
            resize: vertical;
        }

        .transport-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .transport-submit {
            width: 100%;
            border: 0.5px solid var(--black);
            background: var(--black);
            color: var(--white);
            border-radius: 0;
            padding: 13px 16px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            cursor: pointer;
            transition: background .12s, border-color .12s;
        }

        .transport-submit:hover {
            background: var(--g2);
            border-color: var(--g2);
        }

        .transport-service-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            padding: 12px 16px 0;
        }

        .transport-service-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            background: var(--white);
            padding: 12px 8px;
            text-align: center;
            min-height: 78px;
        }

        .transport-service-ico {
            font-size: 22px;
            line-height: 1;
            margin-bottom: 8px;
            filter: grayscale(100%);
        }

        .transport-service-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g2);
            line-height: 1.3;
        }

        .transport-cars {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .transport-car-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            background: var(--white);
            padding: 14px;
        }

        .transport-car-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .transport-car-top h4 {
            font-size: 13px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.01em;
        }

        .transport-car-top p {
            margin-top: 3px;
            font-size: 10px;
            font-weight: 600;
            color: var(--g4);
        }

        .transport-badge-ready {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 4px 8px;
            border-radius: 0;
            background: #f0fdf4;
            color: #15803d;
            border: 0.5px solid #bbf7d0;
            flex-shrink: 0;
        }

        .transport-car-price {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 0.5px solid var(--g7);
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -.03em;
            color: var(--black);
        }

        .transport-note-box {
            margin: 12px 16px 0;
            border: 0.5px solid var(--g6);
            border-radius: 0;
            padding: 12px;
            background: var(--g8);
            font-size: 11px;
            font-weight: 600;
            color: var(--g3);
            line-height: 1.6;
        }



        .transport-history-wrap {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 12px;
        }

        .transport-history-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            background: var(--white);
            padding: 14px;
        }

        .transport-history-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .transport-booking-code {
            font-size: 12px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.02em;
        }

        .transport-booking-date {
            margin-top: 3px;
            font-size: 10px;
            color: var(--g4);
            font-weight: 600;
        }

        .transport-status {
            padding: 4px 8px;
            border-radius: 0;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            border: 0.5px solid transparent;
            flex-shrink: 0;
        }

        .transport-status-orange {
            background: #fff7ed;
            color: #c2410c;
            border-color: #fed7aa;
        }

        .transport-status-blue {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        .transport-status-green {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }


        .transport-status-purple {
            background: #faf5ff;
            color: #7e22ce;
            border-color: #e9d5ff;
        }

        .transport-status-red {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        .transport-history-grid {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }

        .transport-history-item {
            border: 0.5px solid var(--g7);
            border-radius: 0;
            padding: 9px;
            min-width: 0;
        }

        .transport-history-item span {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            margin-bottom: 5px;
        }

        .transport-history-item strong {
            display: block;
            font-size: 11px;
            font-weight: 800;
            color: var(--black);
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .transport-route-box {
            margin-top: 12px;
            border: 0.5px solid var(--g7);
            border-radius: 0;
            padding: 12px;
            background: var(--g8);
        }

        .transport-route-item small {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            margin-bottom: 5px;
        }

        .transport-route-item div {
            font-size: 11px;
            font-weight: 700;
            color: var(--g2);
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .transport-route-divider {
            height: 0.5px;
            background: var(--g6);
            margin: 10px 0;
        }

        .transport-note {
            margin-top: 10px;
            border: 0.5px solid var(--g7);
            border-radius: 0;
            background: var(--white);
            padding: 10px;
            font-size: 11px;
            color: var(--g3);
            font-weight: 600;
            line-height: 1.6;
        }

        .transport-price-row {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 0.5px solid var(--g7);
        }

        .transport-price-row small {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            margin-bottom: 4px;
        }

        .transport-price {
            font-size: 18px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.03em;
        }

        .transport-empty {
            border: 0.5px dashed var(--g6);
            border-radius: 0;
            background: var(--g8);
            padding: 24px 14px;
            text-align: center;
            font-size: 10px;
            font-weight: 900;
            color: var(--g4);
            text-transform: uppercase;
            letter-spacing: .12em;
        }



        /* ── Riwayat Pengajuan Pinjaman Modern ── */
        .pinjaman-history-card {
            border: 0.5px solid var(--g6);
            border-radius: 0;
            background: var(--white);
            padding: 16px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .pinjaman-history-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .pinjaman-history-code {
            font-size: 13px;
            font-weight: 900;
            color: var(--black);
            letter-spacing: -.02em;
        }

        .pinjaman-history-date {
            margin-top: 4px;
            font-size: 10px;
            color: var(--g4);
            font-weight: 700;
        }

        .pinjaman-info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
        }

        .pinjaman-info-item {
            border: 0.5px solid var(--g7);
            border-radius: 0;
            padding: 11px 12px;
            background: #fff;
            min-width: 0;
        }

        .pinjaman-info-item span {
            display: block;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--g4);
            margin-bottom: 6px;
        }

        .pinjaman-info-item strong {
            display: block;
            font-size: 12px;
            font-weight: 900;
            color: var(--black);
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .pinjaman-action-row {
            margin-top: 12px;
        }

        .btn-batal-pengajuan {
            width: 100%;
            border: 0.5px solid #fecaca;
            background: #fff5f5;
            color: #dc2626;
            border-radius: 0;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            cursor: pointer;
            transition: background .12s, border-color .12s, color .12s;
        }

        .btn-batal-pengajuan:hover {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #b91c1c;
        }

        @media(max-width:480px) {
            .pinjaman-info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }


        /* ── Pinjaman Member ── */
        .loan-type-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 8px;
        }

        .loan-type-option {
            position: relative;
        }

        .loan-type-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .loan-type-card {
            border: 0.5px solid var(--g6);
            background: var(--white);
            padding: 14px;
            min-height: 86px;
            cursor: pointer;
            transition: .15s ease;
        }

        .loan-type-option input:checked+.loan-type-card {
            border-color: var(--black);
            background: var(--g8);
            box-shadow: inset 0 0 0 1px var(--black);
        }

        .loan-type-name {
            font-size: 12px;
            font-weight: 900;
            color: var(--black);
            margin-bottom: 5px;
        }

        .loan-type-meta {
            font-size: 10px;
            font-weight: 700;
            color: var(--g4);
            line-height: 1.5;
        }

        .loan-summary-box {
            border: 0.5px solid var(--g6);
            background: var(--g8);
            padding: 14px;
            margin-top: 4px;
        }

        .loan-summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 0;
            border-bottom: 0.5px solid var(--g6);
            font-size: 11px;
            font-weight: 700;
            color: var(--g3);
        }

        .loan-summary-row:last-child {
            border-bottom: none;
        }

        .loan-summary-row strong {
            color: var(--black);
            font-weight: 900;
        }

        /* ── Responsive ── */
        @media(min-width:640px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }

            .quick-grid {
                grid-template-columns: repeat(8, 1fr) !important;
            }

            .order-list {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 12px;
            }

            .promo-list {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
            }

            .modal-sheet {
                max-width: 640px;
                border-radius: 0;
            }
        }

        @media(max-width:480px) {

            .transport-history-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }



            .transport-service-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }

            .transport-grid-2 {
                grid-template-columns: 1fr !important;
            }


            .order-summary-bar {
                grid-template-columns: repeat(2, 1fr) !important;
            }

            .osb-item {
                border-bottom: 0.5px solid var(--g6);
            }

            .akun-stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }

            .order-card-foot {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .order-actions,
            .order-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .success-toast {
                max-width: calc(100vw - 24px);
                text-align: center;
                white-space: normal;
            }
        }

        @media(max-width:380px) {

            .hero-name,
            .akun-nama {
                font-size: 18px !important;
            }

            .point-number {
                font-size: 22px !important;
            }

            .stats-grid {
                grid-template-columns: 1fr !important;
            }

            .quick-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
        }

        .vehicle-type-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 8px;
        }

        .vehicle-type-option {
            position: relative;
        }

        .vehicle-type-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .vehicle-type-card {
            border: 1px solid var(--g6);
            background: #fff;
            padding: 13px 14px;
            min-height: 96px;
            cursor: pointer;
            transition: .15s ease;
        }

        .vehicle-type-option input:checked+.vehicle-type-card {
            border-color: #111;
            background: #fafafa;
            box-shadow: inset 0 0 0 1px #111;
        }

        .vehicle-type-name {
            font-size: 12px;
            font-weight: 900;
            color: var(--ink);
            margin-bottom: 8px;
        }

        .vehicle-type-meta {
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            line-height: 1.6;
        }

        .vehicle-type-price {
            margin-top: 8px;
            font-size: 13px;
            font-weight: 900;
            color: #111;
        }

        @media (max-width: 720px) {
            .vehicle-type-grid {
                grid-template-columns: 1fr;
            }
        }

        .transport-status-red {
            background: #fef2f2 !important;
            color: #dc2626 !important;
            border: 1px solid #fecaca !important;
        }

        .transport-status-orange {
            background: #fff7ed !important;
            color: #ea580c !important;
            border: 1px solid #fed7aa !important;
        }

        .transport-status {
            min-width: 110px;
            text-align: center;
            border-radius: 8px !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            letter-spacing: .08em;
            padding: 7px 12px !important;
        }

        .transport-route-box {
            border-radius: 8px !important;
        }

        .transport-route-item {
            border-radius: 8px !important;
        }


        /* === Tema Kotak Full === */
        .pinjaman-history-card,
        .pinjaman-info-item,
        .transport-route-box,
        .transport-route-item,
        .transport-status,
        .btn-batal-pengajuan,
        .transport-card,
        .transport-box,
        .transport-item,
        .transport-submit,
        .transport-status-red,
        .transport-status-orange,
        .transport-status-blue,
        .transport-status-green {
            border-radius: 0 !important;
        }


        .bottom-nav .nav-btn {
            min-width: 0;
        }

        .bottom-nav .nav-label {
            font-size: 8.5px;
            letter-spacing: .06em;
        }


        .page {
            display: none;
        }

        .page.active {
            display: block;
        }

        .bottom-nav .nav-btn.active {
            color: var(--black);
            font-weight: 900;
        }

        .bottom-nav .nav-btn.active .nav-icon svg {
            stroke: var(--black);
            stroke-width: 2.5;
        }

        .bottom-nav .nav-btn.active .nav-label {
            color: var(--black);
            font-weight: 900;
        }

        .member-notif-bell {
            width: 36px;
            height: 36px;
            border-radius: var(--r);
            border: 0.5px solid rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            flex: 0 0 auto;
        }

        .member-notif-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9px;
            background: #dc2626;
            color: #fff;
            border: 2px solid #111827;
            font-size: 9px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        .member-notif-list {
            display: flex;
            flex-direction: column;
            max-height: 56vh;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .member-notif-item {
            display: block;
            padding: 15px 18px;
            border-bottom: 1px solid #f1f1f1;
            text-decoration: none;
            color: #111;
            background: #fff;
            transition: background .15s ease;
        }

        .member-notif-item:hover {
            background: #fafafa;
        }

        .member-notif-item:last-child {
            border-bottom: 0;
        }

        .member-notif-item.unread {
            background: #f8fbff;
            box-shadow: inset 3px 0 0 #2563eb;
        }

        .member-notif-title {
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 5px;
            line-height: 1.35;
        }

        .member-notif-message {
            font-size: 11px;
            line-height: 1.55;
            color: #6b7280;
            word-break: break-word;
        }

        .member-notif-time {
            font-size: 9px;
            color: #9ca3af;
            margin-top: 7px;
            font-weight: 700;
        }

        .member-notif-empty {
            min-height: 150px;
            padding: 34px 22px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Modal notifikasi dibuat khusus agar tidak ikut model bottom-sheet generik di desktop. */
        #modal-notif {
            align-items: center;
            padding: 24px;
        }

        #modal-notif .modal-sheet {
            width: min(560px, 92vw);
            max-width: 560px !important;
            max-height: min(72vh, 620px);
            border-radius: 0;
            overflow: hidden;
            box-shadow: 0 18px 60px rgba(0, 0, 0, .22);
            animation: memberNotifPop .18s ease;
        }

        #modal-notif .modal-header {
            padding: 18px 20px;
        }

        #modal-notif .modal-title {
            font-size: 12px;
        }

        @keyframes memberNotifPop {
            from {
                opacity: 0;
                transform: translateY(10px) scale(.985);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            #modal-notif {
                padding: 20px;
            }

            #modal-notif .modal-sheet {
                width: min(620px, 88vw);
                max-width: 620px !important;
                max-height: 76vh;
            }

            #modal-notif .modal-header {
                padding: 17px 18px;
            }

            .member-notif-list {
                max-height: 58vh;
            }
        }

        @media (max-width: 768px) {
            #modal-notif {
                align-items: flex-end;
                padding: 0;
            }

            #modal-notif .modal-sheet {
                width: 100%;
                max-width: none !important;
                max-height: 78dvh;
                border-radius: 14px 14px 0 0;
                box-shadow: 0 -12px 38px rgba(0, 0, 0, .18);
                animation: slideUp .22s ease;
            }

            #modal-notif .modal-header {
                padding: 15px 16px;
            }

            #modal-notif .modal-title {
                font-size: 11px;
            }

            .member-notif-list {
                max-height: calc(78dvh - 70px);
            }

            .member-notif-item {
                padding: 14px 16px;
            }

            .member-notif-title {
                font-size: 11px;
            }

            .member-notif-message {
                font-size: 10px;
            }

            .member-notif-empty {
                min-height: 130px;
                padding: 28px 18px;
            }
        }

        @media (max-width: 420px) {
            #modal-notif .modal-sheet {
                max-height: 82dvh;
            }

            .member-notif-list {
                max-height: calc(82dvh - 68px);
            }

            #modal-notif .modal-header {
                padding: 14px;
            }

            .member-notif-item {
                padding: 13px 14px;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell" id="app">

        <!-- ═══ DESKTOP TOPBAR (hidden) ═══ -->
        <div class="desktop-topbar"></div>
        <!-- ═══ DESKTOP SIDEBAR (hidden) ═══ -->
        <div class="desktop-sidebar"></div>

        <div class="desktop-content">

            <!-- ══════════════════════════════
             PAGE: BERANDA
        ══════════════════════════════ -->
            <div class="page active" id="page-beranda">
                <!-- Hero -->
                <div class="beranda-hero">
                    <div class="hero-top">
                        <div>
                            <div class="hero-greeting">Selamat Datang Kembali</div>
                            <div class="hero-name"><?= h($member['nama']) ?></div>
                        </div>
                        <button class="member-notif-bell" type="button" aria-label="Notifikasi" onclick="openModal('modal-notif')">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            </svg>
                            <?php if ($memberNotifUnread > 0): ?>
                                <span class="member-notif-badge"><?= $memberNotifUnread > 9 ? '9+' : (int)$memberNotifUnread ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    <div class="hero-meta">
                        <span class="hero-tag"><?= h($member['kode']) ?></span>
                        <span class="hero-tag"><?= h($member['no_hp']) ?></span>
                        <span class="hero-tag hero-tag-active"><?= h($member['status']) ?></span>
                    </div>
                </div>

                <!-- Point Strip -->
                <div class="point-strip">
                    <div class="point-strip-left">
                        <div class="section-label">Saldo Point</div>
                        <div class="point-number"><?= angka_member($saldoPoint) ?><span class="point-unit">pt</span></div>
                        <div style="font-size:10px;color:var(--g4);font-weight:600;margin-top:4px;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?> nilai tukar</div>
                    </div>
                    <div class="point-strip-right">
                        <div class="section-label">Transaksi</div>
                        <div style="font-size:28px;font-weight:900;letter-spacing:-.04em;color:var(--black);margin-top:4px;"><?= angka_member($jumlahTransaksi) ?></div>
                        <div style="font-size:10px;color:var(--g4);font-weight:600;margin-top:4px;">Total</div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card" data-icon="💰">
                        <div class="stat-card-label">Total Belanja</div>
                        <div class="stat-card-value" style="font-size:16px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <?php if ($totalBelanjaProfil !== $totalBelanjaTransaksi): ?><div class="stat-card-sub">Profil: <?= rupiah_member($totalBelanjaProfil) ?></div><?php endif; ?>
                    </div>
                    <div class="stat-card" data-icon="🏷️">
                        <div class="stat-card-label">Total Diskon</div>
                        <div class="stat-card-value" style="font-size:16px;"><?= rupiah_member($totalDiskonTransaksi) ?></div>
                        <div class="stat-card-sub">Hemat dari transaksi</div>
                    </div>
                    <div class="stat-card" data-icon="⭐">
                        <div class="stat-card-label">Point Transaksi</div>
                        <div class="stat-card-value"><?= angka_member($totalPointDariTransaksi) ?><span style="font-size:13px;font-weight:700;color:var(--g4);"> pt</span></div>
                    </div>
                    <div class="stat-card" data-icon="🎁">
                        <div class="stat-card-label">Point Ditukar</div>
                        <div class="stat-card-value"><?= angka_member($totalPointPakai) ?><span style="font-size:13px;font-weight:700;color:var(--g4);"> pt</span></div>
                        <div class="stat-card-sub">Nilai <?= rupiah_member($totalNilaiPointPakai) ?></div>
                    </div>
                    <div class="stat-card" data-icon="👤">
                        <div class="stat-card-label">Bergabung</div>
                        <div class="stat-card-value" style="font-size:14px;"><?= $member['created_at'] ? date('M Y', strtotime($member['created_at'])) : '-' ?></div>
                    </div>
                </div>

                <div class="gap-section"></div>

                <!-- Quick Menu -->
                <div class="quick-menu-wrap">
                    <div class="section-label">Menu Cepat</div>
                    <div class="quick-grid">
                        <button class="quick-item" onclick="goTo('belanja')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="9" cy="21" r="1" />
                                    <circle cx="20" cy="21" r="1" />
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                </svg></div>
                            <span class="quick-label">Belanja</span>
                        </button>
                        <button class="quick-item" onclick="goTo('promo')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <polyline points="20 12 20 22 4 22 4 12" />
                                    <rect x="2" y="7" width="20" height="5" />
                                    <line x1="12" y1="22" x2="12" y2="7" />
                                    <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
                                    <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
                                </svg></div>
                            <span class="quick-label">Promo</span>
                        </button>
                        <button class="quick-item" onclick="goTo('pesanan')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                    <line x1="12" y1="22.08" x2="12" y2="12" />
                                </svg></div>
                            <span class="quick-label">Pesanan</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-tukar')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <span class="quick-label">Tukar Point</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-voucher')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                    <line x1="7" y1="7" x2="7.01" y2="7" />
                                </svg></div>
                            <span class="quick-label">Voucher</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-point')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <line x1="18" y1="20" x2="18" y2="10" />
                                    <line x1="12" y1="20" x2="12" y2="4" />
                                    <line x1="6" y1="20" x2="6" y2="14" />
                                </svg></div>
                            <span class="quick-label">Riwayat Point</span>
                        </button>

                        <button class="quick-item" onclick="goTo('pinjaman')">
                            <div class="quick-ico">
                                <svg viewBox="0 0 24 24">
                                    <rect x="3" y="4" width="18" height="16" rx="2" />
                                    <path d="M7 8h10" />
                                    <path d="M7 12h7" />
                                    <path d="M7 16h4" />
                                </svg>
                            </div>
                            <span class="quick-label">Pinjaman</span>
                        </button>

                        <button class="quick-item" onclick="goTo('transport')">
                            <div class="quick-ico">
                                <svg viewBox="0 0 24 24">
                                    <path d="M2 16l20-8-20-8 4 8-4 8z" />
                                    <path d="M6 8h16" />
                                </svg>
                            </div>
                            <span class="quick-label">Bandara</span>
                        </button>
                        <button class="quick-item" onclick="openModal('modal-kontak')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg></div>
                            <span class="quick-label">Kontak</span>
                        </button>

                        <button class="quick-item" onclick="openModal('modal-bantuan')">
                            <div class="quick-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg></div>
                            <span class="quick-label">Bantuan</span>
                        </button>
                    </div>
                </div>

                <div class="gap-section"></div>

                <!-- Recent Transactions -->
                <div style="padding:16px 16px 8px;display:flex;justify-content:space-between;align-items:center;">
                    <div class="section-label">Transaksi Terakhir</div>
                    <button onclick="goTo('pesanan')" style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--g4);border:none;background:none;cursor:pointer;font-family:inherit;">Semua →</button>
                </div>
                <div class="trx-list">
                    <?php if (!$trxBeranda): ?>
                        <div style="padding:32px 0;text-align:center;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:var(--g5);">Belum ada transaksi</div>
                        <?php else: foreach ($trxBeranda as $t):
                            $ptDb    = (int)($t['point_transaksi'] ?? 0);
                            $ptTotal = (int)($t['total_transaksi'] ?? 0);
                            $ptTampil = $ptDb > 0 ? $ptDb : (int)floor($ptTotal / 10000); ?>
                            <div class="trx-row">
                                <div class="trx-icon-box"><svg viewBox="0 0 24 24">
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                    </svg></div>
                                <div class="trx-info">
                                    <div class="trx-inv"><?= h($t['invoice']) ?></div>
                                    <div class="trx-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                </div>
                                <div class="trx-right">
                                    <div class="trx-total"><?= rupiah_member($t['total_transaksi']) ?></div>
                                    <div class="trx-pt">+<?= angka_member($ptTampil) ?> pt</div>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div><!-- /page-beranda -->


            <!-- ══════════════════════════════
             PAGE: BELANJA
        ══════════════════════════════ -->
            <div class="page" id="page-belanja">
                <div class="search-wrap">
                    <div class="search-inner">
                        <svg viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        <input type="text" placeholder="Cari produk, kategori...">
                    </div>
                </div>
                <div class="cat-scroll-wrap">
                    <div class="cat-scroll no-scrollbar">
                        <button class="cat-chip active" onclick="setCat(this)">Semua</button>
                        <button class="cat-chip" onclick="setCat(this)">Sembako</button>
                        <button class="cat-chip" onclick="setCat(this)">Minuman</button>
                        <button class="cat-chip" onclick="setCat(this)">Snack</button>
                        <button class="cat-chip" onclick="setCat(this)">Kebersihan</button>
                        <button class="cat-chip" onclick="setCat(this)">Frozen</button>
                        <button class="cat-chip" onclick="setCat(this)">Susu &amp; Bayi</button>
                    </div>
                </div>
                <div class="empty-state" style="padding:48px 24px;">
                    <svg viewBox="0 0 24 24">
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                    </svg>
                    <p>Fitur katalog belanja member belum terhubung ke data produk kasir.</p>
                    <div style="font-size:11px;font-weight:600;color:var(--g4);text-align:center;line-height:1.6;max-width:320px;">Data transaksi member tetap valid dari database. Untuk belanja, silakan transaksi melalui kasir/POS.</div>
                </div>
            </div><!-- /page-belanja -->


            <!-- ══════════════════════════════
             PAGE: PROMO
        ══════════════════════════════ -->
            <div class="page" id="page-promo">
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Penawaran Eksklusif Member</div>
                    <h2>Hemat Lebih,<br>Belanja Lebih!</h2>
                    <p>Khusus Member Aktif Koperasi BSDK</p>
                </div>
                <div class="tab-bar no-scrollbar">
                    <button class="tab-btn active" onclick="setPromoTab(this)">Semua</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Diskon</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Double Point</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Gratis</button>
                    <button class="tab-btn" onclick="setPromoTab(this)">Voucher</button>
                </div>
                <div class="promo-list">
                    <div class="empty-state" style="grid-column:1/-1;">
                        <svg viewBox="0 0 24 24">
                            <polyline points="20 12 20 22 4 22 4 12" />
                            <rect x="2" y="7" width="20" height="5" />
                            <line x1="12" y1="22" x2="12" y2="7" />
                        </svg>
                        <p>Promo member belum ditampilkan dari database.</p>
                        <div style="font-size:11px;font-weight:600;color:var(--g4);text-align:center;line-height:1.6;max-width:320px;">Promo/diskon yang valid tetap dihitung dari transaksi kasir dan muncul di riwayat pesanan.</div>
                    </div>
                </div>
            </div><!-- /page-promo -->


            <!-- ══════════════════════════════
             PAGE: PESANAN
        ══════════════════════════════ -->
            <div class="page" id="page-pesanan">

                <!-- ── Hero Hitam (sama seperti Promo) ── -->
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Riwayat Belanja Member</div>
                    <h2>Pesanan &amp;<br>Transaksi</h2>
                    <p><?= angka_member($jumlahRiwayatPesanan) ?> riwayat · Minimarket/Cafe <?= angka_member($jumlahTransaksi) ?> · Rental <?= angka_member(count($transportBookings)) ?></p>
                </div>

                <!-- ── Summary Bar ── -->
                <div class="order-summary-bar">
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($jumlahTransaksi) ?></div>
                        <div class="osb-label">Transaksi</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val" style="font-size:13px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <div class="osb-label">Belanja</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val" style="font-size:13px;"><?= rupiah_member($totalDiskonTransaksi) ?></div>
                        <div class="osb-label">Diskon</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($totalPointDariTransaksi) ?></div>
                        <div class="osb-label">Point</div>
                    </div>
                    <div class="osb-item">
                        <div class="osb-val"><?= angka_member($totalPointPakai) ?></div>
                        <div class="osb-label">Ditukar</div>
                    </div>
                </div>

                <!-- ── Tab ── -->
                <div class="tab-bar no-scrollbar">
                    <button class="tab-btn active" data-target="pesanan-semua" onclick="setOrderTab(this)">Semua</button>
                    <button class="tab-btn" data-target="pesanan-minimarket" onclick="setOrderTab(this)">Minimarket (<?= angka_member(count($transaksiMinimarket)) ?>)</button>
                    <button class="tab-btn" data-target="pesanan-cafe" onclick="setOrderTab(this)">Cafe (<?= angka_member(count($transaksiCafe)) ?>)</button>
                    <button class="tab-btn" data-target="pesanan-bandara" onclick="setOrderTab(this)">Rental Bandara (<?= angka_member(count($transportBookings)) ?>)</button>
                </div>


                <!-- ── Order Cards Gabungan ── -->
                <div class="pesanan-content active" id="pesanan-semua">
                    <?php if (!$transaksi): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                <line x1="12" y1="22.08" x2="12" y2="12" />
                            </svg>
                            <p>Belum ada riwayat transaksi</p>
                        </div>
                    <?php else: ?>
                        <div class="order-list">
                            <?php foreach ($transaksi as $t):
                                $sebelumDiskon   = (int)($t['total_sebelum_diskon'] ?? 0);
                                $setelahPoint    = (int)($t['total_transaksi']      ?? 0);
                                $pointPakai      = (int)($t['point_pakai']          ?? 0);
                                $nilaiPointPakai = (int)($t['nilai_point_pakai']    ?? 0);
                                $setelahDiskon   = $setelahPoint + $nilaiPointPakai;
                                $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                                $diskonDb        = (int)($t['diskon_transaksi'] ?? 0);
                                $diskonTampil    = max($diskonDb, $diskonDariSelisih);
                                $ptDb            = (int)($t['point_transaksi']  ?? 0);
                                $ptTampil        = $ptDb > 0 ? $ptDb : (int)floor($setelahPoint / 10000);
                            ?>
                                <div class="order-card">
                                    <div class="order-card-head">
                                        <div>
                                            <div class="order-store"><?= strtolower((string)($t['sumber_member'] ?? 'minimarket')) === 'cafe' ? 'Cafe SEJAHUB' : 'Minimarket SEJAHUB' ?></div>
                                            <div class="order-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                        </div>
                                        <span class="badge badge-selesai">Selesai</span>
                                    </div>
                                    <div class="order-items-wrap">
                                        <div class="order-item-row">
                                            <span class="oi-name" style="font-weight:800;color:var(--black);"><?= h($t['invoice']) ?></span>
                                            <span class="oi-price"><?= rupiah_member($setelahPoint) ?></span>
                                        </div>
                                        <?php if ($sebelumDiskon > $setelahDiskon): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Harga Asli</span>
                                                <span style="font-size:11px;font-weight:600;color:var(--g4);text-decoration:line-through;"><?= rupiah_member($sebelumDiskon) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($diskonTampil > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Diskon</span>
                                                <span style="font-size:11px;font-weight:700;color:#15803d;">- <?= rupiah_member($diskonTampil) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($nilaiPointPakai > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Tukar Point</span>
                                                <span style="font-size:11px;font-weight:700;color:#7c3aed;">- <?= rupiah_member($nilaiPointPakai) ?> (<?= angka_member($pointPakai) ?> pt)</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="order-item-row" style="margin-top:4px;padding-top:8px;border-top:0.5px solid var(--g7);">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Bayar</span>
                                            <span style="font-size:11px;font-weight:700;color:var(--black);"><?= rupiah_member($t['bayar_transaksi'] ?? 0) ?></span>
                                        </div>
                                        <?php if (($t['kembalian_transaksi'] ?? 0) > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Kembali</span>
                                                <span style="font-size:11px;font-weight:700;color:var(--g3);"><?= rupiah_member($t['kembalian_transaksi']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="order-card-foot">
                                        <div class="order-total-wrap">
                                            <div class="ot-label">Total Dibayar</div>
                                            <div class="ot-val"><?= rupiah_member($setelahPoint) ?></div>
                                            <div class="ot-pt">+<?= angka_member($ptTampil) ?> point diperoleh</div>
                                        </div>
                                        <div class="order-actions">
                                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:7px 14px;">Struk</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="transport-card-box" style="margin-top:12px;">
                        <div class="transport-section-head">
                            <div>
                                <h3>Booking Bandara</h3>
                                <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Riwayat transport bandara Anda</div>
                            </div>
                            <span><?= angka_member(count($transportBookings)) ?></span>
                        </div>

                        <div class="transport-history-wrap">
                            <?php if (!$transportBookings): ?>
                                <div class="transport-empty">
                                    Belum ada booking transport
                                </div>
                            <?php endif; ?>

                            <?php foreach ($transportBookings as $tb): ?>
                                <div class="transport-history-card">
                                    <div class="transport-history-top">
                                        <div>
                                            <div class="transport-booking-code"><?= h($tb['kode_booking']) ?></div>
                                            <div class="transport-booking-date"><?= h(tanggal_member($tb['created_at'])) ?></div>
                                        </div>

                                        <div class="transport-status <?= h(transport_status_class_member($tb['status'])) ?>">
                                            <?= h(transport_status_label_member($tb['status'])) ?>
                                        </div>
                                    </div>

                                    <div class="transport-history-grid">
                                        <div class="transport-history-item">
                                            <span>Layanan</span>
                                            <strong><?= h(transport_layanan_label_member($tb['layanan'])) ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Kendaraan</span>
                                            <strong><?= h($tb['driver_kendaraan'] ?? $tb['kendaraan'] ?? 'Menunggu Driver') ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Tanggal</span>
                                            <strong><?= h(transport_tanggal_member($tb['tanggal'])) ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Jam</span>
                                            <strong><?= h(transport_jam_member($tb['jam'])) ?> WIB</strong>
                                        </div>
                                    </div>

                                    <div class="transport-route-box">
                                        <div class="transport-route-item">
                                            <small>Jemput</small>
                                            <div><?= h($tb['lokasi_jemput']) ?></div>
                                        </div>

                                        <div class="transport-route-divider"></div>

                                        <div class="transport-route-item">
                                            <small>Tujuan</small>
                                            <div><?= h($tb['tujuan']) ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($tb['catatan'])): ?>
                                        <div class="transport-note">
                                            <?= h($tb['catatan']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="transport-price-row">
                                        <small>Total Harga</small>
                                        <div class="transport-price">
                                            <?= rupiah_member($tb['total_harga']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>


                    <?php if ($transportBookings): ?>
                        <div class="transport-card-box" style="margin-top:12px;">
                            <div class="transport-section-head">
                                <div>
                                    <h3>Booking Rental Bandara</h3>
                                    <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Riwayat transport member</div>
                                </div><span><?= angka_member(count($transportBookings)) ?></span>
                            </div>
                            <div class="transport-history-wrap">
                                <?php foreach ($transportBookings as $tb): ?>
                                    <div class="transport-history-card">
                                        <div class="transport-history-top">
                                            <div>
                                                <div class="transport-booking-code"><?= h($tb['kode_booking']) ?></div>
                                                <div class="transport-booking-date"><?= h(tanggal_member($tb['created_at'])) ?></div>
                                            </div>
                                            <div class="transport-status <?= h(transport_status_class_member($tb['status'])) ?>"><?= h(transport_status_label_member($tb['status'])) ?></div>
                                        </div>
                                        <div class="transport-price-row"><small><?= h(transport_layanan_label_member($tb['layanan'])) ?></small>
                                            <div class="transport-price"><?= rupiah_member($tb['total_harga']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pesanan-content" id="pesanan-minimarket">
                    <?php if (!$transaksiMinimarket): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                <line x1="12" y1="22.08" x2="12" y2="12" />
                            </svg>
                            <p>Belum ada riwayat transaksi</p>
                        </div>
                    <?php else: ?>
                        <div class="order-list">
                            <?php foreach ($transaksiMinimarket as $t):
                                $sebelumDiskon   = (int)($t['total_sebelum_diskon'] ?? 0);
                                $setelahPoint    = (int)($t['total_transaksi']      ?? 0);
                                $pointPakai      = (int)($t['point_pakai']          ?? 0);
                                $nilaiPointPakai = (int)($t['nilai_point_pakai']    ?? 0);
                                $setelahDiskon   = $setelahPoint + $nilaiPointPakai;
                                $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                                $diskonDb        = (int)($t['diskon_transaksi'] ?? 0);
                                $diskonTampil    = max($diskonDb, $diskonDariSelisih);
                                $ptDb            = (int)($t['point_transaksi']  ?? 0);
                                $ptTampil        = $ptDb > 0 ? $ptDb : (int)floor($setelahPoint / 10000);
                            ?>
                                <div class="order-card">
                                    <div class="order-card-head">
                                        <div>
                                            <div class="order-store"><?= strtolower((string)($t['sumber_member'] ?? 'minimarket')) === 'cafe' ? 'Cafe SEJAHUB' : 'Minimarket SEJAHUB' ?></div>
                                            <div class="order-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                        </div>
                                        <span class="badge badge-selesai">Selesai</span>
                                    </div>
                                    <div class="order-items-wrap">
                                        <div class="order-item-row">
                                            <span class="oi-name" style="font-weight:800;color:var(--black);"><?= h($t['invoice']) ?></span>
                                            <span class="oi-price"><?= rupiah_member($setelahPoint) ?></span>
                                        </div>
                                        <?php if ($sebelumDiskon > $setelahDiskon): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Harga Asli</span>
                                                <span style="font-size:11px;font-weight:600;color:var(--g4);text-decoration:line-through;"><?= rupiah_member($sebelumDiskon) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($diskonTampil > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Diskon</span>
                                                <span style="font-size:11px;font-weight:700;color:#15803d;">- <?= rupiah_member($diskonTampil) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($nilaiPointPakai > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Tukar Point</span>
                                                <span style="font-size:11px;font-weight:700;color:#7c3aed;">- <?= rupiah_member($nilaiPointPakai) ?> (<?= angka_member($pointPakai) ?> pt)</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="order-item-row" style="margin-top:4px;padding-top:8px;border-top:0.5px solid var(--g7);">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Bayar</span>
                                            <span style="font-size:11px;font-weight:700;color:var(--black);"><?= rupiah_member($t['bayar_transaksi'] ?? 0) ?></span>
                                        </div>
                                        <?php if (($t['kembalian_transaksi'] ?? 0) > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Kembali</span>
                                                <span style="font-size:11px;font-weight:700;color:var(--g3);"><?= rupiah_member($t['kembalian_transaksi']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="order-card-foot">
                                        <div class="order-total-wrap">
                                            <div class="ot-label">Total Dibayar</div>
                                            <div class="ot-val"><?= rupiah_member($setelahPoint) ?></div>
                                            <div class="ot-pt">+<?= angka_member($ptTampil) ?> point diperoleh</div>
                                        </div>
                                        <div class="order-actions">
                                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:7px 14px;">Struk</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pesanan-content" id="pesanan-cafe">
                    <?php if (!$transaksiCafe): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                <line x1="12" y1="22.08" x2="12" y2="12" />
                            </svg>
                            <p>Belum ada transaksi Cafe</p>
                        </div>
                    <?php else: ?>
                        <div class="order-list">
                            <?php foreach ($transaksiCafe as $t):
                                $sebelumDiskon   = (int)($t['total_sebelum_diskon'] ?? 0);
                                $setelahPoint    = (int)($t['total_transaksi']      ?? 0);
                                $pointPakai      = (int)($t['point_pakai']          ?? 0);
                                $nilaiPointPakai = (int)($t['nilai_point_pakai']    ?? 0);
                                $setelahDiskon   = $setelahPoint + $nilaiPointPakai;
                                $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                                $diskonDb        = (int)($t['diskon_transaksi'] ?? 0);
                                $diskonTampil    = max($diskonDb, $diskonDariSelisih);
                                $ptDb            = (int)($t['point_transaksi']  ?? 0);
                                $ptTampil        = $ptDb > 0 ? $ptDb : (int)floor($setelahPoint / 10000);
                            ?>
                                <div class="order-card">
                                    <div class="order-card-head">
                                        <div>
                                            <div class="order-store"><?= strtolower((string)($t['sumber_member'] ?? 'minimarket')) === 'cafe' ? 'Cafe SEJAHUB' : 'Minimarket SEJAHUB' ?></div>
                                            <div class="order-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                                        </div>
                                        <span class="badge badge-selesai">Selesai</span>
                                    </div>
                                    <div class="order-items-wrap">
                                        <div class="order-item-row">
                                            <span class="oi-name" style="font-weight:800;color:var(--black);"><?= h($t['invoice']) ?></span>
                                            <span class="oi-price"><?= rupiah_member($setelahPoint) ?></span>
                                        </div>
                                        <?php if ($sebelumDiskon > $setelahDiskon): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Harga Asli</span>
                                                <span style="font-size:11px;font-weight:600;color:var(--g4);text-decoration:line-through;"><?= rupiah_member($sebelumDiskon) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($diskonTampil > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Diskon</span>
                                                <span style="font-size:11px;font-weight:700;color:#15803d;">- <?= rupiah_member($diskonTampil) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($nilaiPointPakai > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Tukar Point</span>
                                                <span style="font-size:11px;font-weight:700;color:#7c3aed;">- <?= rupiah_member($nilaiPointPakai) ?> (<?= angka_member($pointPakai) ?> pt)</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="order-item-row" style="margin-top:4px;padding-top:8px;border-top:0.5px solid var(--g7);">
                                            <span style="font-size:11px;color:var(--g4);font-weight:600;">Bayar</span>
                                            <span style="font-size:11px;font-weight:700;color:var(--black);"><?= rupiah_member($t['bayar_transaksi'] ?? 0) ?></span>
                                        </div>
                                        <?php if (($t['kembalian_transaksi'] ?? 0) > 0): ?>
                                            <div class="order-item-row">
                                                <span style="font-size:11px;color:var(--g4);font-weight:600;">Kembali</span>
                                                <span style="font-size:11px;font-weight:700;color:var(--g3);"><?= rupiah_member($t['kembalian_transaksi']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="order-card-foot">
                                        <div class="order-total-wrap">
                                            <div class="ot-label">Total Dibayar</div>
                                            <div class="ot-val"><?= rupiah_member($setelahPoint) ?></div>
                                            <div class="ot-pt">+<?= angka_member($ptTampil) ?> point diperoleh</div>
                                        </div>
                                        <div class="order-actions">
                                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:7px 14px;">Struk</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>


                <div class="pesanan-content" id="pesanan-bandara">

                    <div class="transport-card-box" style="margin-top:12px;">
                        <div class="transport-section-head">
                            <div>
                                <h3>Booking Bandara</h3>
                                <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Riwayat transport bandara Anda</div>
                            </div>
                            <span><?= angka_member(count($transportBookings)) ?></span>
                        </div>

                        <div class="transport-history-wrap">
                            <?php if (!$transportBookings): ?>
                                <div class="transport-empty">
                                    Belum ada booking transport
                                </div>
                            <?php endif; ?>

                            <?php foreach ($transportBookings as $tb): ?>
                                <div class="transport-history-card">
                                    <div class="transport-history-top">
                                        <div>
                                            <div class="transport-booking-code"><?= h($tb['kode_booking']) ?></div>
                                            <div class="transport-booking-date"><?= h(tanggal_member($tb['created_at'])) ?></div>
                                        </div>

                                        <div class="transport-status <?= h(transport_status_class_member($tb['status'])) ?>">
                                            <?= h(transport_status_label_member($tb['status'])) ?>
                                        </div>
                                    </div>

                                    <div class="transport-history-grid">
                                        <div class="transport-history-item">
                                            <span>Layanan</span>
                                            <strong><?= h(transport_layanan_label_member($tb['layanan'])) ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Kendaraan</span>
                                            <strong><?= h($tb['driver_kendaraan'] ?? $tb['kendaraan'] ?? 'Menunggu Driver') ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Tanggal</span>
                                            <strong><?= h(transport_tanggal_member($tb['tanggal'])) ?></strong>
                                        </div>

                                        <div class="transport-history-item">
                                            <span>Jam</span>
                                            <strong><?= h(transport_jam_member($tb['jam'])) ?> WIB</strong>
                                        </div>
                                    </div>

                                    <div class="transport-route-box">
                                        <div class="transport-route-item">
                                            <small>Jemput</small>
                                            <div><?= h($tb['lokasi_jemput']) ?></div>
                                        </div>

                                        <div class="transport-route-divider"></div>

                                        <div class="transport-route-item">
                                            <small>Tujuan</small>
                                            <div><?= h($tb['tujuan']) ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($tb['catatan'])): ?>
                                        <div class="transport-note">
                                            <?= h($tb['catatan']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="transport-price-row">
                                        <small>Total Harga</small>
                                        <div class="transport-price">
                                            <?= rupiah_member($tb['total_harga']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

            </div><!-- /page-pesanan -->





            <!-- ══════════════════════════════
             PAGE: SIMPANAN
        ══════════════════════════════ -->
            <div class="page" id="page-simpanan">
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Layanan Simpanan</div>
                    <h2>Simpanan<br>Member</h2>
                    <p>Saldo ditampilkan akumulasi sampai bulan berjalan dari seluruh data sebelumnya.</p>
                </div>

                <?php if ($simpananError): ?>
                    <div class="transport-alert transport-alert-error">
                        <span class="transport-alert-icon">!</span>
                        <span><?= h($simpananError) ?></span>
                    </div>
                <?php endif; ?>

                <form method="GET" class="transport-card-box" style="position:sticky;top:0;z-index:30;background:#fff;box-shadow:0 6px 18px rgba(15,23,42,.04);">
                    <input type="hidden" name="tab" value="simpanan">
                    <div class="transport-section-head" style="margin-bottom:12px;">
                        <div>
                            <h3>Filter Simpanan</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Default mengikuti bulan dan tahun berjalan. Tahun/bulan lama tetap bisa dipilih.</div>
                        </div>
                        <span><?= h((string)$simpananTahunAktif) ?></span>
                    </div>

                    <div class="transport-grid-2">
                        <div class="transport-form-group">
                            <label>Tahun</label>
                            <select name="simpanan_tahun" class="transport-input" onchange="this.form.submit()">
                                <?php if (!$simpananTahunOptions): ?>
                                    <option value="<?= (int)$simpananTahunAktif ?>"><?= (int)$simpananTahunAktif ?></option>
                                <?php endif; ?>
                                <?php foreach ($simpananTahunOptions as $tahunOpt): ?>
                                    <option value="<?= (int)$tahunOpt ?>" <?= (int)$tahunOpt === (int)$simpananTahunAktif ? 'selected' : '' ?>><?= (int)$tahunOpt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="transport-form-group">
                            <label>Bulan</label>
                            <select name="simpanan_bulan" class="transport-input" onchange="this.form.submit()">
                                <?php foreach ($bulanNamaMember as $numBulan => $namaBulan): ?>
                                    <option value="<?= (int)$numBulan ?>" <?= (int)$numBulan === (int)$simpananBulanAktif ? 'selected' : '' ?>><?= h($namaBulan) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>

                <div class="transport-service-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                    <div class="transport-service-card">
                        <div class="transport-service-label">Pokok Akumulasi</div>
                        <div style="font-size:13px;font-weight:900;color:#111;margin-top:8px;line-height:1.3;"><?= rupiah_member($simpananSummary['pokok']) ?></div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-label">Wajib Akumulasi</div>
                        <div style="font-size:13px;font-weight:900;color:#111;margin-top:8px;line-height:1.3;"><?= rupiah_member($simpananSummary['wajib']) ?></div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-label">Sukarela Akumulasi</div>
                        <div style="font-size:13px;font-weight:900;color:#111;margin-top:8px;line-height:1.3;"><?= rupiah_member($simpananSummary['sukarela']) ?></div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-label">Total Akumulasi</div>
                        <div style="font-size:13px;font-weight:900;color:#111;margin-top:8px;line-height:1.3;"><?= rupiah_member($simpananSummary['total']) ?></div>
                    </div>
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Simpanan Bulan <?= h($bulanNamaMember[$simpananBulanAktif] ?? '-') ?> <?= h((string)$simpananTahunAktif) ?></h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Transaksi khusus bulan yang dipilih</div>
                        </div>
                        <span><?= rupiah_member($simpananBulanIni['total']) ?></span>
                    </div>

                    <?php if ((float)$simpananBulanIni['total'] <= 0): ?>
                        <div class="transport-empty">Belum ada simpanan pada bulan ini</div>
                    <?php else: ?>
                        <div class="transport-history-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                            <div class="transport-history-item">
                                <span>Pokok</span>
                                <strong><?= rupiah_member($simpananBulanIni['pokok']) ?></strong>
                            </div>
                            <div class="transport-history-item">
                                <span>Wajib</span>
                                <strong><?= rupiah_member($simpananBulanIni['wajib']) ?></strong>
                            </div>
                            <div class="transport-history-item">
                                <span>Sukarela</span>
                                <strong><?= rupiah_member($simpananBulanIni['sukarela']) ?></strong>
                            </div>
                            <div class="transport-history-item">
                                <span>Total</span>
                                <strong><?= rupiah_member($simpananBulanIni['total']) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Rekap 12 Bulan</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Klik bulan untuk mengganti ringkasan bulan di atas.</div>
                        </div>
                        <span><?= h((string)$simpananTahunAktif) ?></span>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;">
                        <?php foreach ($bulanNamaMember as $numBulan => $namaBulan): ?>
                            <?php $dataBulan = $simpananBulanan[$numBulan] ?? ['total' => 0]; ?>
                            <a href="?simpanan_tahun=<?= (int)$simpananTahunAktif ?>&simpanan_bulan=<?= (int)$numBulan ?>#simpanan"
                                style="display:block;text-decoration:none;border:0.5px solid <?= (int)$numBulan === (int)$simpananBulanAktif ? '#111' : 'var(--g7)' ?>;background:<?= (int)$numBulan === (int)$simpananBulanAktif ? '#f9fafb' : '#fff' ?>;padding:12px;min-width:0;">
                                <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:var(--g4);"><?= h($namaBulan) ?></div>
                                <div style="font-size:13px;font-weight:900;color:#111;margin-top:7px;line-height:1.25;overflow-wrap:anywhere;"><?= rupiah_member($dataBulan['total'] ?? 0) ?></div>
                                <div style="font-size:10px;font-weight:700;color:var(--g4);margin-top:5px;line-height:1.5;">
                                    W: <?= rupiah_member($dataBulan['wajib'] ?? 0) ?><br>
                                    S: <?= rupiah_member($dataBulan['sukarela'] ?? 0) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Riwayat Detail</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Riwayat tidak ditampilkan langsung agar halaman tetap ringkas.</div>
                        </div>
                        <span><?= angka_member(count($simpananRiwayat)) ?></span>
                    </div>

                    <button type="button" class="transport-submit" onclick="openModal('modal-riwayat-simpanan')">
                        Lihat Riwayat Detail
                    </button>
                    <div style="font-size:10px;font-weight:700;color:var(--g4);margin-top:10px;line-height:1.6;">
                        Menampilkan maksimal 30 data terbaru saat tombol dibuka. Saldo utama di atas tetap memakai akumulasi sampai bulan yang dipilih.
                    </div>
                </div>
            </div><!-- /page-simpanan -->


            <!-- ══════════════════════════════
             PAGE: PINJAMAN
        ══════════════════════════════ -->
            <div class="page" id="page-pinjaman">
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Layanan Simpan Pinjam</div>
                    <h2>Pengajuan<br>Pinjaman</h2>
                    <p>Ajukan pinjaman uang atau barang dan pantau statusnya di sini.</p>
                </div>

                <?php if ($pinjamanMsg): ?>
                    <div class="transport-alert transport-alert-success">
                        <span class="transport-alert-icon">✓</span>
                        <span><?= h($pinjamanMsg) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($pinjamanErr): ?>
                    <div class="transport-alert transport-alert-error">
                        <span class="transport-alert-icon">!</span>
                        <span><?= h($pinjamanErr) ?></span>
                    </div>
                <?php endif; ?>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Form Pengajuan</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Pilih jenis, nominal/harga barang, dan tenor</div>
                            <div style="font-size:10px;font-weight:700;color:var(--g4);margin-top:6px;line-height:1.6;">
                                Tenor uang: <?= $tenorUang ? h(implode(', ', $tenorUang)) . ' bulan' : 'belum dikonfigurasi' ?><br>
                                Tenor barang: <?= $tenorBarang ? h(implode(', ', $tenorBarang)) . ' bulan' : 'belum dikonfigurasi' ?>
                            </div>
                        </div>
                        <span>SP</span>
                    </div>

                    <form method="POST" class="transport-form" id="form-pinjaman-member">
                        <input type="hidden" name="action" value="ajukan_pinjaman">

                        <div class="transport-form-group">
                            <label>Jenis Pinjaman</label>
                            <div class="loan-type-grid">
                                <label class="loan-type-option">
                                    <input type="radio" name="jenis" value="uang" checked onchange="updateLoanForm()">
                                    <div class="loan-type-card">
                                        <div class="loan-type-name">Pinjaman Uang</div>
                                        <div class="loan-type-meta">Bunga <?= h($konfigSp['bunga_uang']) ?>% total</div>
                                    </div>
                                </label>
                                <label class="loan-type-option">
                                    <input type="radio" name="jenis" value="barang" onchange="updateLoanForm()">
                                    <div class="loan-type-card">
                                        <div class="loan-type-name">Pinjaman Barang</div>
                                        <div class="loan-type-meta">Bunga <?= h($konfigSp['bunga_barang']) ?>% total</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="transport-form-group" id="nama-barang-wrap" style="display:none;">
                            <label>Nama Barang</label>
                            <input type="text" name="nama_barang" id="loan-nama-barang" class="transport-input" placeholder="Contoh: Laptop, HP, kulkas">
                        </div>

                        <div class="transport-form-group">
                            <label id="loan-jumlah-label">Nominal Pinjaman</label>
                            <input type="text" name="jumlah" id="loan-jumlah" class="transport-input" placeholder="Contoh: 5000000" inputmode="numeric" oninput="updateLoanEstimate()" required>
                        </div>

                        <div class="transport-form-group">
                            <label>Tenor</label>
                            <select name="tenor" id="loan-tenor" class="transport-input" onchange="updateLoanEstimate()" required></select>
                        </div>

                        <div class="loan-summary-box">
                            <div class="loan-summary-row">
                                <span>Estimasi Pokok / Bulan</span>
                                <strong id="loan-est-pokok">Rp 0</strong>
                            </div>
                            <div class="loan-summary-row">
                                <span>Estimasi Bunga / Bulan</span>
                                <strong id="loan-est-bunga">Rp 0</strong>
                            </div>
                            <div class="loan-summary-row">
                                <span>Total Angsuran / Bulan</span>
                                <strong id="loan-est-total">Rp 0</strong>
                            </div>
                        </div>

                        <div class="transport-form-group">
                            <label>Keperluan / Catatan</label>
                            <textarea name="keperluan" class="transport-input transport-textarea" placeholder="Tuliskan keperluan pengajuan pinjaman"></textarea>
                        </div>

                        <button type="submit" class="transport-submit">
                            Kirim Pengajuan
                        </button>
                    </form>
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Riwayat Pengajuan</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Status pengajuan pinjaman Anda</div>
                        </div>
                        <span><?= angka_member(count($pengajuanPinjaman)) ?></span>
                        <!-- member_id aktif: <?= (int)$memberId ?> -->
                    </div>

                    <div class="transport-history-wrap">
                        <?php if (!$pengajuanPinjaman): ?>
                            <div class="transport-empty">Belum ada pengajuan pinjaman</div>
                        <?php endif; ?>

                        <?php foreach ($pengajuanPinjaman as $p):
                            $bungaPct = $p['jenis'] === 'barang' ? (float)$konfigSp['bunga_barang'] : (float)$konfigSp['bunga_uang'];
                            $pokok = (float)($p['jumlah'] ?? 0);
                            $tenor = max(1, (int)($p['tenor'] ?? 1));
                            $angPok = round($pokok / $tenor);
                            $totalBunga = round($pokok * $bungaPct / 100);
                            $angBng = round($totalBunga / $tenor);
                            $angTot = $angPok + $angBng;
                            $statusPengajuan = strtolower(trim((string)($p['status'] ?? '')));
                        ?>
                            <div class="pinjaman-history-card" id="pengajuan-<?= (int)$p['id'] ?>">
                                <div class="pinjaman-history-top">
                                    <div>
                                        <div class="pinjaman-history-code">Pengajuan #<?= (int)$p['id'] ?></div>
                                        <div class="pinjaman-history-date"><?= h(tanggal_member($p['created_at'])) ?></div>
                                    </div>

                                    <?php
                                    $statusBadgeText = pinjaman_status_label_member($statusPengajuan);
                                    $statusBadgeClass = pinjaman_status_class_member($statusPengajuan);

                                    if ($statusPengajuan === '' || $statusPengajuan === 'dibatalkan') {
                                        $statusBadgeText = 'BATAL PENGAJUAN';
                                        $statusBadgeClass = 'transport-status-red';
                                    }
                                    ?>
                                    <div class="transport-status <?= h($statusBadgeClass) ?>">
                                        <?= h(strtoupper($statusBadgeText)) ?>
                                    </div>
                                </div>

                                <div class="pinjaman-info-grid">
                                    <div class="pinjaman-info-item">
                                        <span>Jenis</span>
                                        <strong><?= h(ucfirst((string)$p['jenis'])) ?></strong>
                                    </div>
                                    <div class="pinjaman-info-item">
                                        <span>Jumlah</span>
                                        <strong><?= rupiah_member($pokok) ?></strong>
                                    </div>
                                    <div class="pinjaman-info-item">
                                        <span>Tenor</span>
                                        <strong><?= angka_member($tenor) ?> Bulan</strong>
                                    </div>
                                    <div class="pinjaman-info-item">
                                        <span>Angsuran</span>
                                        <strong><?= rupiah_member($angTot) ?></strong>
                                    </div>
                                </div>

                                <?php if (!empty($p['nama_barang']) || !empty($p['keperluan']) || !empty($p['catatan_petugas'])): ?>
                                    <div class="transport-route-box">
                                        <?php if (!empty($p['nama_barang'])): ?>
                                            <div class="transport-route-item">
                                                <small>Barang</small>
                                                <div><?= h($p['nama_barang']) ?></div>
                                            </div>
                                            <?php if (!empty($p['keperluan']) || !empty($p['catatan_petugas'])): ?>
                                                <div class="transport-route-divider"></div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($p['keperluan'])): ?>
                                            <div class="transport-route-item">
                                                <small>Keperluan</small>
                                                <div><?= h($p['keperluan']) ?></div>
                                            </div>
                                            <?php if (!empty($p['catatan_petugas'])): ?>
                                                <div class="transport-route-divider"></div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($p['catatan_petugas'])): ?>
                                            <div class="transport-route-item">
                                                <small>Catatan Petugas</small>
                                                <div><?= h($p['catatan_petugas']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($statusPengajuan === 'disetujui'): ?>
                                    <?php $konf = (string)($p['konfirmasi_keputusan'] ?? 'pending'); ?>
                                    <?php if ($konf === 'ambil'): ?>
                                        <div style="margin-top:12px;padding:12px;border:1px solid #bbf7d0;background:#f0fdf4;font-size:11px;font-weight:800;color:#166534;">
                                            Sudah dikonfirmasi: pinjaman akan diambil<?= !empty($p['konfirmasi_at']) ? ' · ' . h(tanggal_member($p['konfirmasi_at'])) : '' ?>
                                        </div>
                                    <?php elseif ($konf === 'tidak_ambil'): ?>
                                        <div style="margin-top:12px;padding:12px;border:1px solid #fecaca;background:#fef2f2;font-size:11px;font-weight:800;color:#b91c1c;">
                                            Sudah dikonfirmasi: pinjaman tidak diambil
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top:12px;padding:12px;border:1px solid #fde68a;background:#fffbeb;">
                                            <div style="font-size:11px;font-weight:900;color:#92400e;">Pengajuan Anda telah disetujui</div>
                                            <div style="font-size:10px;color:#a16207;margin-top:4px;">Konfirmasi apakah pinjaman akan Anda ambil.</div>
                                            <button type="button" class="btn-konfirmasi-pinjaman" data-id="<?= (int)$p['id'] ?>" style="margin-top:10px;width:100%;padding:10px;background:#111;color:#fff;border:0;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;">Konfirmasi Pengambilan</button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($statusPengajuan === 'pending'): ?>
                                    <div class="pinjaman-action-row">
                                        <button type="button"
                                            class="btn-batal-pengajuan"
                                            data-id="<?= (int)$p['id'] ?>">
                                            Batalkan Pengajuan
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Pinjaman Aktif</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Pinjaman yang sudah dicairkan</div>
                        </div>
                        <span><?= angka_member(count($pinjamanAktif)) ?></span>
                    </div>

                    <div class="transport-history-wrap">
                        <?php if (!$pinjamanAktif): ?>
                            <div class="transport-empty">Belum ada pinjaman aktif</div>
                        <?php endif; ?>

                        <?php foreach ($pinjamanAktif as $p): ?>
                            <div class="transport-history-card">
                                <div class="transport-history-top">
                                    <div>
                                        <div class="transport-booking-code">Pinjaman #<?= (int)$p['id'] ?></div>
                                        <div class="transport-booking-date"><?= h(tanggal_short($p['tanggal_mulai'] ?? $p['created_at'])) ?> - <?= h(tanggal_short($p['tanggal_selesai'] ?? null)) ?></div>
                                    </div>
                                    <div class="transport-status <?= h(pinjaman_status_class_member($p['status'] ?? 'aktif')) ?>">
                                        <?= h(pinjaman_status_label_member($p['status'] ?? 'aktif')) ?>
                                    </div>
                                </div>

                                <div class="transport-history-grid">
                                    <div class="transport-history-item">
                                        <span>Jenis</span>
                                        <strong><?= h(ucfirst((string)$p['jenis'])) ?></strong>
                                    </div>
                                    <div class="transport-history-item">
                                        <span>Pokok</span>
                                        <strong><?= rupiah_member($p['pokok'] ?? 0) ?></strong>
                                    </div>
                                    <div class="transport-history-item">
                                        <span>Tenor</span>
                                        <strong><?= angka_member($p['tenor'] ?? 0) ?> Bulan</strong>
                                    </div>
                                    <div class="transport-history-item">
                                        <span>Angsuran</span>
                                        <strong><?= rupiah_member($p['angsuran_total'] ?? 0) ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div><!-- /page-pinjaman -->


            <!-- ══════════════════════════════
             PAGE: TRANSPORT BANDARA
        ══════════════════════════════ -->
            <div class="page" id="page-transport">
                <div class="promo-hero-bar">
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;opacity:.4;margin-bottom:8px;">Transport Service</div>
                    <h2>Airport<br>Transfer</h2>
                    <p>Booking kendaraan bandara cepat, aman, dan nyaman.</p>
                </div>

                <div class="transport-service-grid">
                    <div class="transport-service-card">
                        <div class="transport-service-ico">🛫</div>
                        <div class="transport-service-label">Antar Bandara</div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-ico">🛬</div>
                        <div class="transport-service-label">Jemput Bandara</div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-ico">📍</div>
                        <div class="transport-service-label">Lokasi Jemput</div>
                    </div>
                    <div class="transport-service-card">
                        <div class="transport-service-ico">📋</div>
                        <div class="transport-service-label">Riwayat</div>
                    </div>
                </div>

                <div class="transport-note-box">
                    Pilih jenis kendaraan sesuai kebutuhan. Admin/operator akan menugaskan driver dan unit kendaraan yang sesuai.
                </div>

                <div class="transport-card-box">
                    <div class="transport-section-head">
                        <div>
                            <h3>Booking Kendaraan</h3>
                            <div style="font-size:11px;font-weight:600;color:var(--g4);margin-top:4px;">Airport transfer member</div>
                        </div>
                        <span>Form</span>
                    </div>

                    <form method="POST" action="transport_booking.php" class="transport-form">
                        <input type="hidden" name="member_id" value="<?= h($member['id']) ?>">

                        <div class="transport-form-group">
                            <label>Jenis Layanan</label>
                            <select name="layanan" class="transport-input" required>
                                <option value="antar_bandara">Antar Bandara</option>
                                <option value="jemput_bandara">Jemput Bandara</option>
                            </select>
                        </div>

                        <div class="transport-form-group">
                            <label>Jenis Kendaraan</label>

                            <div class="vehicle-type-grid">
                                <label class="vehicle-type-option">
                                    <input type="radio" name="tipe_kendaraan" value="avanza" checked>
                                    <div class="vehicle-type-card">
                                        <div class="vehicle-type-name"><?= h($transportTarif['avanza']['label']) ?></div>
                                        <div class="vehicle-type-meta">Kapasitas <?= angka_member($transportTarif['avanza']['kapasitas']) ?> orang</div>
                                        <div class="vehicle-type-price"><?= rupiah_member($transportTarif['avanza']['harga']) ?></div>
                                    </div>
                                </label>

                                <label class="vehicle-type-option">
                                    <input type="radio" name="tipe_kendaraan" value="innova">
                                    <div class="vehicle-type-card">
                                        <div class="vehicle-type-name"><?= h($transportTarif['innova']['label']) ?></div>
                                        <div class="vehicle-type-meta">Kapasitas <?= angka_member($transportTarif['innova']['kapasitas']) ?> orang</div>
                                        <div class="vehicle-type-price"><?= rupiah_member($transportTarif['innova']['harga']) ?></div>
                                    </div>
                                </label>

                                <label class="vehicle-type-option">
                                    <input type="radio" name="tipe_kendaraan" value="hiace">
                                    <div class="vehicle-type-card">
                                        <div class="vehicle-type-name"><?= h($transportTarif['hiace']['label']) ?></div>
                                        <div class="vehicle-type-meta">Kapasitas <?= angka_member($transportTarif['hiace']['kapasitas']) ?> orang</div>
                                        <div class="vehicle-type-price"><?= rupiah_member($transportTarif['hiace']['harga']) ?></div>
                                    </div>
                                </label>
                            </div>
                        </div>



                        <div class="transport-form-group">
                            <label>Lokasi Jemput</label>
                            <input type="text" name="lokasi_jemput" class="transport-input" placeholder="Contoh: Bogor Selatan" required>
                        </div>

                        <div class="transport-form-group">
                            <label>Tujuan</label>
                            <input type="text" name="tujuan" class="transport-input" placeholder="Contoh: Bandara Soekarno Hatta Terminal 3" required>
                        </div>

                        <div class="transport-grid-2">
                            <div class="transport-form-group">
                                <label>Tanggal</label>
                                <input type="date" name="tanggal" class="transport-input" required>
                            </div>

                            <div class="transport-form-group">
                                <label>Jam</label>
                                <input type="time" name="jam" class="transport-input" required>
                            </div>
                        </div>

                        <div class="transport-form-group">
                            <label>Penumpang</label>
                            <input type="number" name="jumlah_penumpang" class="transport-input" value="1" min="1" required>
                        </div>

                        <div class="transport-form-group">
                            <label>Catatan</label>
                            <textarea name="catatan" class="transport-input transport-textarea" placeholder="Contoh: bawa koper besar, jemput di lobby, butuh kursi bayi"></textarea>
                        </div>

                        <button type="submit" class="transport-submit">
                            Booking Sekarang
                        </button>
                    </form>
                </div>

            </div><!-- /page-transport -->


            <!-- ══════════════════════════════
             PAGE: AKUN
        ══════════════════════════════ -->
            <div class="page" id="page-akun">
                <div class="akun-hero">
                    <div class="akun-avatar"><?= member_initial($member['nama']) ?></div>
                    <div class="akun-nama"><?= h($member['nama']) ?></div>
                    <div class="akun-meta">
                        <span class="akun-tag"><?= h($member['kode']) ?></span>
                        <span class="akun-tag"><?= h($member['no_hp']) ?></span>
                        <span class="akun-tag" style="border-color:rgba(255,255,255,.5);color:rgba(255,255,255,.9);"><?= h($member['status']) ?></span>
                    </div>
                </div>
                <div class="akun-stats-grid">
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($saldoPoint) ?></div>
                        <div class="akun-stat-lbl">Point</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($jumlahTransaksi) ?></div>
                        <div class="akun-stat-lbl">Transaksi</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val"><?= angka_member($totalPointPakai) ?></div>
                        <div class="akun-stat-lbl">Ditukar</div>
                    </div>
                    <div class="akun-stat">
                        <div class="akun-stat-val" style="font-size:14px;"><?= rupiah_member($totalBelanjaTransaksi) ?></div>
                        <div class="akun-stat-lbl">Total Belanja</div>
                    </div>
                </div>
                <?php if ($totalBelanjaProfil !== $totalBelanjaTransaksi): ?>
                    <div class="info-box">⚠ Profil mencatat <strong><?= rupiah_member($totalBelanjaProfil) ?></strong>. Acuan terpercaya adalah total dari riwayat transaksi.</div>
                <?php endif; ?>

                <div class="menu-section">
                    <div class="menu-section-label">Profil &amp; Akun</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="openModal('modal-profil')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Edit Profil</div>
                                <div class="menu-sub">Nama, nomor HP, alamat</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-point')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Saldo &amp; Point</div>
                                <div class="menu-sub">Riwayat point dan penukaran</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-voucher')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                    <line x1="7" y1="7" x2="7.01" y2="7" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Voucher Saya</div>
                                <div class="menu-sub">Kode voucher aktif</div>
                            </div>
                            <span class="menu-badge-pill">3</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-tukar')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="14" rx="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Tukar Point</div>
                                <div class="menu-sub">Tukar point jadi diskon/cashback</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="menu-section">
                    <div class="menu-section-label">Simpan Pinjam</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="goTo('pinjaman')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <rect x="3" y="4" width="18" height="16" rx="2" />
                                    <path d="M7 8h10" />
                                    <path d="M7 12h7" />
                                    <path d="M7 16h4" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Pengajuan Pinjaman</div>
                                <div class="menu-sub">Ajukan dan pantau status pinjaman</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>

                <div class="menu-section">
                    <div class="menu-section-label">Transaksi</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="goTo('pesanan')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                    <line x1="12" y1="22.08" x2="12" y2="12" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Riwayat Pesanan</div>
                                <div class="menu-sub"><?= angka_member($jumlahTransaksi) ?> transaksi total</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-struk')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <polyline points="14 2 14 8 20 8" />
                                    <line x1="16" y1="13" x2="8" y2="13" />
                                    <line x1="16" y1="17" x2="8" y2="17" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Struk Digital</div>
                                <div class="menu-sub">Lihat struk transaksi</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="menu-section">
                    <div class="menu-section-label">Bantuan</div>
                    <div class="menu-group-card">
                        <button class="menu-row" onclick="openModal('modal-bantuan')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Bantuan &amp; FAQ</div>
                                <div class="menu-sub">Cara penggunaan aplikasi</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-kontak')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Hubungi Koperasi</div>
                                <div class="menu-sub">WhatsApp &amp; Telepon</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                        <button class="menu-row" onclick="openModal('modal-about')">
                            <div class="menu-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg></div>
                            <div class="menu-text-wrap">
                                <div class="menu-title">Tentang Aplikasi</div>
                                <div class="menu-sub">Versi 2.1 — Koperasi BSDK</div>
                            </div>
                            <span class="menu-chevron">›</span>
                        </button>
                    </div>
                </div>
                <div class="akun-footer">
                    <button class="btn btn-danger" style="width:100%;padding:13px;font-size:11px;" onclick="confirmLogout()">Keluar dari Akun</button>
                    <div style="margin-top:12px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--g5);">Member sejak <?= $member['created_at'] ? date('F Y', strtotime($member['created_at'])) : '-' ?></div>
                </div>
            </div><!-- /page-akun -->

        </div><!-- /desktop-content -->


        <!-- ══════════════════════════════
         BOTTOM NAV
    ══════════════════════════════ -->
        <nav class="bottom-nav">
            <button class="nav-btn active" onclick="goTo('beranda')" data-page="beranda">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 11l9-8 9 8" />
                        <path d="M5 10v10h14V10" />
                        <path d="M9 20v-6h6v6" />
                    </svg>
                </div>
                <span class="nav-label">Beranda</span>
            </button>

            <button class="nav-btn" onclick="goTo('simpanan')" data-page="simpanan">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="6" width="18" height="14" rx="2" />
                        <path d="M7 10h10" />
                        <path d="M7 14h6" />
                        <path d="M17 17h.01" />
                    </svg>
                </div>
                <span class="nav-label">Simpanan</span>
            </button>

            <button class="nav-btn" onclick="goTo('pinjaman')" data-page="pinjaman">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="16" rx="2" />
                        <path d="M7 8h10" />
                        <path d="M7 12h7" />
                        <path d="M7 16h4" />
                    </svg>
                </div>
                <span class="nav-label">Pinjaman</span>
            </button>

            <button class="nav-btn" onclick="goTo('pesanan')" data-page="pesanan">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                        <line x1="12" y1="22.08" x2="12" y2="12" />
                    </svg>
                </div>
                <span class="nav-label">Pesanan</span>
            </button>

            <button class="nav-btn" onclick="goTo('akun')" data-page="akun">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                </div>
                <span class="nav-label">Akun</span>
            </button>
        </nav>

    </div><!-- /app-shell -->


    <!-- ════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════ -->

    <!-- MODAL: Edit Profil -->
    <div class="modal-overlay" id="modal-profil" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Edit Profil</div>
                <button class="modal-close" onclick="closeModal('modal-profil')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:72px;height:72px;border-radius:var(--r);background:var(--black);color:var(--white);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:900;margin:0 auto 8px;"><?= member_initial($member['nama']) ?></div>
                    <div style="font-size:11px;font-weight:700;color:var(--g4);">Foto profil tidak tersedia</div>
                </div>
                <div class="form-group"><label class="form-label">Kode Member</label><input class="form-input" value="<?= h($member['kode']) ?>" disabled>
                    <div class="form-hint">Kode tidak dapat diubah</div>
                </div>
                <div class="form-group"><label class="form-label">Nama Lengkap</label><input class="form-input" id="edit-nama" value="<?= h($member['nama']) ?>" placeholder="Nama lengkap"></div>
                <div class="form-group"><label class="form-label">Nomor HP</label><input class="form-input" id="edit-hp" value="<?= h($member['no_hp']) ?>" placeholder="08xxxxxxxxxx"></div>
                <div class="form-group"><label class="form-label">Status</label><input class="form-input" value="<?= h($member['status']) ?>" disabled></div>
                <div style="display:flex;gap:8px;margin-top:20px;">
                    <button class="btn" style="flex:1;" onclick="closeModal('modal-profil')">Batal</button>
                    <button class="btn btn-black" style="flex:2;" onclick="saveProfil()">Simpan Perubahan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Saldo & Point -->
    <div class="modal-overlay" id="modal-point" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Saldo &amp; Point</div>
                <button class="modal-close" onclick="closeModal('modal-point')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="background:var(--black);color:var(--white);padding:20px;border-radius:var(--r);margin-bottom:20px;position:relative;overflow:hidden;">
                    <div style="position:absolute;right:-10px;top:-10px;font-size:80px;opacity:.06;">⭐</div>
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;opacity:.5;">Saldo Point Saat Ini</div>
                    <div style="font-size:40px;font-weight:900;letter-spacing:-.04em;margin-top:6px;"><?= angka_member($saldoPoint) ?><span style="font-size:16px;font-weight:700;opacity:.4;margin-left:4px;">pt</span></div>
                    <div style="font-size:11px;font-weight:600;opacity:.4;margin-top:4px;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?> nilai tukar</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;">
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Total Point Diperoleh</div>
                        <div style="font-size:20px;font-weight:900;color:var(--black);margin-top:6px;"><?= angka_member($totalPointDariTransaksi) ?><span style="font-size:11px;color:var(--g4);"> pt</span></div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Nilai Tukar</div>
                        <div style="font-size:16px;font-weight:900;color:var(--black);margin-top:6px;"><?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?></div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:14px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Point Ditukar</div>
                        <div style="font-size:20px;font-weight:900;color:var(--black);margin-top:6px;"><?= angka_member($totalPointPakai) ?><span style="font-size:11px;color:var(--g4);"> pt</span></div>
                        <div style="font-size:10px;font-weight:700;color:var(--g4);margin-top:3px;">Nilai <?= rupiah_member($totalNilaiPointPakai) ?></div>
                    </div>
                </div>
                <div class="section-label" style="margin-bottom:10px;">Riwayat Point</div>
                <?php if (!$transaksi): ?>
                    <div style="text-align:center;padding:24px;font-size:11px;font-weight:700;color:var(--g4);">Belum ada riwayat point</div>
                    <?php else: foreach (array_slice($transaksi, 0, 8) as $t):
                        $ptDb    = (int)($t['point_transaksi'] ?? 0);
                        $ptTotal = (int)($t['total_transaksi'] ?? 0);
                        $ptPakai = (int)($t['point_pakai']     ?? 0);
                        $ptTampil = $ptDb > 0 ? $ptDb : (int)floor($ptTotal / 10000); ?>
                        <div class="pt-history-item">
                            <div class="pt-hist-ico"><svg viewBox="0 0 24 24">
                                    <circle cx="9" cy="21" r="1" />
                                    <circle cx="20" cy="21" r="1" />
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                </svg></div>
                            <div class="pt-hist-info">
                                <div class="pt-hist-title"><?= h($t['invoice']) ?></div>
                                <div class="pt-hist-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?> · <?= rupiah_member($t['total_transaksi']) ?></div>
                            </div>
                            <div class="pt-hist-val <?= $ptPakai > 0 ? 'minus' : 'plus' ?>"><?= $ptPakai > 0 ? '-' . angka_member($ptPakai) . ' pt' : '+' . angka_member($ptTampil) . ' pt' ?></div>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: Voucher -->
    <div class="modal-overlay" id="modal-voucher" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Voucher Saya</div>
                <button class="modal-close" onclick="closeModal('modal-voucher')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Masukkan Kode Voucher</label>
                    <div style="display:flex;gap:8px;"><input class="form-input" placeholder="KODE-VOUCHER" style="flex:1;"><button class="btn btn-black" onclick="showToast('Kode voucher tidak ditemukan')">Cek</button></div>
                </div>
                <div class="section-label" style="margin-bottom:12px;">Voucher Aktif (3)</div>
                <div class="voucher-card" onclick="copyVoucher('BSDK20OFF')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">BSDK20OFF</div>
                            <div class="vc-desc">Diskon 20% untuk pembelian sembako</div>
                            <div class="vc-exp">Berlaku s.d 30 Mei 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">20%</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Diskon</div>
                        </div>
                    </div>
                </div>
                <div class="voucher-card" onclick="copyVoucher('BSDK10RB')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">BSDK10RB</div>
                            <div class="vc-desc">Voucher Rp 10.000 min. belanja Rp 75.000</div>
                            <div class="vc-exp">Berlaku s.d 20 Jun 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">10K</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Cashback</div>
                        </div>
                    </div>
                </div>
                <div class="voucher-card" onclick="copyVoucher('GRATISONGKIR')">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div class="vc-code">GRATISONGKIR</div>
                            <div class="vc-desc">Gratis ongkos kirim min. belanja Rp 50.000</div>
                            <div class="vc-exp">Berlaku s.d 31 Mei 2025</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="vc-val">0</div>
                            <div style="font-size:9px;font-weight:700;color:var(--g4);text-transform:uppercase;letter-spacing:.06em;">Ongkir</div>
                        </div>
                    </div>
                </div>
                <div style="font-size:10px;font-weight:600;color:var(--g4);text-align:center;margin-top:8px;">Klik voucher untuk menyalin kode</div>
            </div>
        </div>
    </div>

    <!-- MODAL: Tukar Point -->
    <div class="modal-overlay" id="modal-tukar" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Tukar Point</div>
                <button class="modal-close" onclick="closeModal('modal-tukar')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div class="tukar-point-hero">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;opacity:.4;position:relative;z-index:1;">Saldo Anda</div>
                    <div class="tp-balance" style="position:relative;z-index:1;"><?= angka_member($saldoPoint) ?><span style="font-size:16px;font-weight:700;opacity:.4;margin-left:4px;">pt</span></div>
                    <div style="font-size:11px;font-weight:600;opacity:.4;margin-top:4px;position:relative;z-index:1;">≈ <?= rupiah_member($saldoPoint * MEMBER_POINT_RUPIAH) ?></div>
                </div>
                <div class="section-label" style="margin-bottom:12px;">Pilih Penukaran</div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Cashback Rp 10.000</div>
                        <div class="to-req">10 point diperlukan</div>
                    </div>
                    <div class="to-val">10 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Cashback Rp 50.000</div>
                        <div class="to-req">50 point diperlukan</div>
                    </div>
                    <div class="to-val">50 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Diskon Rp 100.000</div>
                        <div class="to-req">100 point diperlukan</div>
                    </div>
                    <div class="to-val">100 pt</div>
                </div>
                <div class="tukar-option" onclick="selectTukar(this)">
                    <div class="to-info">
                        <div class="to-name">Voucher Rp 200.000</div>
                        <div class="to-req">200 point diperlukan</div>
                    </div>
                    <div class="to-val">200 pt</div>
                </div>
                <div style="margin-top:16px;padding:12px;background:var(--g8);border-radius:var(--r);font-size:11px;font-weight:600;color:var(--g3);">💡 Pilih opsi penukaran di atas, lalu konfirmasi ke kasir saat transaksi berlangsung.</div>
                <div style="display:flex;gap:8px;margin-top:16px;">
                    <button class="btn" style="flex:1;" onclick="closeModal('modal-tukar')">Batal</button>
                    <button class="btn btn-black" style="flex:2;" onclick="showToast('Silakan tunjukkan ke kasir')">Konfirmasi Penukaran</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Struk Digital -->
    <div class="modal-overlay" id="modal-struk" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Struk Digital</div>
                <button class="modal-close" onclick="closeModal('modal-struk')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;font-size:12px;font-weight:600;color:var(--g3);">Cari struk berdasarkan nomor invoice atau pilih dari daftar transaksi di bawah.</div>
                <div class="form-group"><label class="form-label">Nomor Invoice</label>
                    <div style="display:flex;gap:8px;"><input class="form-input" placeholder="Contoh: INV-20250501-001" id="struk-invoice" style="flex:1;"><button class="btn btn-black" onclick="cariStruk()">Cari</button></div>
                </div>
                <div class="section-label" style="margin-bottom:10px;">Transaksi Terakhir</div>
                <?php if (!$transaksi): ?>
                    <div style="text-align:center;padding:24px;font-size:11px;font-weight:700;color:var(--g4);">Belum ada transaksi</div>
                    <?php else: foreach (array_slice($transaksi, 0, 10) as $t): ?>
                        <div class="trx-row">
                            <div class="trx-icon-box"><svg viewBox="0 0 24 24">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <polyline points="14 2 14 8 20 8" />
                                </svg></div>
                            <div class="trx-info">
                                <div class="trx-inv"><?= h($t['invoice']) ?></div>
                                <div class="trx-date"><?= h(tanggal_member($t['tanggal_transaksi'])) ?></div>
                            </div>
                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank" class="btn btn-black" style="padding:6px 12px;font-size:10px;">Buka</a>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: Bantuan & FAQ -->
    <div class="modal-overlay" id="modal-bantuan" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Bantuan &amp; FAQ</div>
                <button class="modal-close" onclick="closeModal('modal-bantuan')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;"><input class="form-input" placeholder="Cari pertanyaan..." oninput="filterFAQ(this.value)"></div>
                <div id="faq-list">
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mendapatkan point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Setiap transaksi senilai Rp 10.000 mendapatkan 1 point. Point akan otomatis dikreditkan setelah transaksi selesai.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara menukar point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Tukar Point", pilih opsi penukaran yang tersedia, kemudian tunjukkan konfirmasi ke kasir saat transaksi. Point akan langsung dipotong dari saldo Anda.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Berapa nilai 1 point?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">1 point setara dengan Rp 1.000. Jadi jika Anda memiliki 100 point, nilainya adalah Rp 100.000 yang dapat ditukar menjadi cashback atau diskon belanja.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara menggunakan voucher?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Voucher Saya", salin kode voucher, kemudian berikan ke kasir saat transaksi. Pastikan nilai belanja Anda sudah memenuhi minimum yang disyaratkan.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Apakah point memiliki masa berlaku?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Point berlaku selama akun member Anda aktif. Point tidak akan kadaluarsa selama Anda melakukan minimal 1 transaksi per tahun.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mencetak struk?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Struk Digital" atau "Riwayat Pesanan", cari transaksi yang diinginkan, kemudian klik tombol "Struk" untuk membuka struk digital.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Bagaimana cara mengubah data profil?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Buka menu "Edit Profil" di halaman Akun. Anda dapat mengubah nama dan nomor HP. Kode member tidak dapat diubah.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-q" onclick="toggleFAQ(this)"><span>Apakah promo berlaku untuk semua produk?</span><span class="faq-chevron">▼</span></div>
                        <div class="faq-a">Promo berlaku sesuai syarat dan ketentuan yang tertera di masing-masing promo. Beberapa promo hanya berlaku untuk kategori produk tertentu atau dengan minimum pembelian.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Hubungi Koperasi -->
    <div class="modal-overlay" id="modal-kontak" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Hubungi Koperasi</div>
                <button class="modal-close" onclick="closeModal('modal-kontak')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:20px;font-size:12px;font-weight:600;color:var(--g3);line-height:1.6;">Tim Koperasi BSDK siap membantu Anda. Hubungi kami melalui salah satu saluran di bawah ini.</div>
                <a class="kontak-card" href="https://wa.me/6281234567890" target="_blank">
                    <div class="kontak-ico">📱</div>
                    <div class="kontak-info">
                        <div class="kc-title">WhatsApp</div>
                        <div class="kc-sub">+62 812-3456-7890 · Senin–Sabtu 08.00–17.00</div>
                    </div>
                </a>
                <a class="kontak-card" href="tel:+6281234567890">
                    <div class="kontak-ico">📞</div>
                    <div class="kontak-info">
                        <div class="kc-title">Telepon</div>
                        <div class="kc-sub">+62 812-3456-7890 · Jam kerja</div>
                    </div>
                </a>
                <a class="kontak-card" href="mailto:koperasi@bsdk.co.id">
                    <div class="kontak-ico">✉️</div>
                    <div class="kontak-info">
                        <div class="kc-title">Email</div>
                        <div class="kc-sub">koperasi@bsdk.co.id</div>
                    </div>
                </a>
                <a class="kontak-card" href="#">
                    <div class="kontak-ico">📍</div>
                    <div class="kontak-info">
                        <div class="kc-title">Kunjungi Langsung</div>
                        <div class="kc-sub">Jl. Contoh No. 123, Kota · Senin–Sabtu 08.00–16.00</div>
                    </div>
                </a>
                <div style="margin-top:16px;padding:12px;background:var(--g8);border-radius:var(--r);font-size:11px;font-weight:600;color:var(--g3);">⏰ Jam operasional: Senin–Jumat 08.00–16.00 WIB, Sabtu 08.00–13.00 WIB</div>
            </div>
        </div>
    </div>

    <!-- MODAL: Tentang Aplikasi -->
    <div class="modal-overlay" id="modal-about" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Tentang Aplikasi</div>
                <button class="modal-close" onclick="closeModal('modal-about')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <div class="about-logo">BSDK</div>
                <div style="font-size:20px;font-weight:900;letter-spacing:-.02em;margin-bottom:4px;">Koperasi BSDK</div>
                <div class="about-version">Member Dashboard v2.1</div>
                <div style="margin:20px 0;height:0.5px;background:var(--g6);"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;text-align:left;">
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Versi</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">2.1.0</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Update</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">Mei 2025</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Platform</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">Web App</div>
                    </div>
                    <div style="border:0.5px solid var(--g6);border-radius:var(--r);padding:12px;">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--g4);">Developer</div>
                        <div style="font-size:14px;font-weight:800;margin-top:4px;">IT BSDK</div>
                    </div>
                </div>
                <div style="font-size:11px;font-weight:600;color:var(--g4);line-height:1.7;">Aplikasi dashboard member Koperasi BSDK memungkinkan anggota untuk memantau transaksi, mengelola point, dan memanfaatkan promo eksklusif secara mudah dan efisien.</div>
                <div style="margin-top:16px;font-size:10px;font-weight:700;color:var(--g5);text-transform:uppercase;letter-spacing:.1em;">© 2025 Koperasi BSDK · All rights reserved</div>
            </div>
        </div>
    </div>

    <!-- Modal Riwayat Simpanan -->
    <div class="modal-overlay" id="modal-riwayat-simpanan" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div class="modal-title">Riwayat Simpanan</div>
                <button class="modal-close" onclick="closeModal('modal-riwayat-simpanan')"><svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div style="font-size:11px;font-weight:700;color:var(--g4);line-height:1.7;margin-bottom:14px;">
                    30 data simpanan terbaru dari database. Untuk melihat saldo, gunakan ringkasan akumulasi pada halaman Simpanan.
                </div>

                <div class="transport-history-wrap" style="margin-top:0;">
                    <?php if (!$simpananRiwayat): ?>
                        <div class="transport-empty">Belum ada riwayat simpanan</div>
                    <?php endif; ?>

                    <?php foreach ($simpananRiwayat as $rowSimpanan): ?>
                        <div class="transport-history-card">
                            <div class="transport-history-top">
                                <div>
                                    <div class="transport-booking-code">Simpanan <?= h(ucfirst((string)$rowSimpanan['jenis'])) ?></div>
                                    <div class="transport-booking-date"><?= h($bulanNamaMember[(int)$rowSimpanan['bulan']] ?? '-') ?> <?= h((string)$rowSimpanan['tahun']) ?><?= !empty($rowSimpanan['keterangan']) ? ' · ' . h($rowSimpanan['keterangan']) : '' ?></div>
                                </div>
                                <div class="transport-status transport-status-green">
                                    <?= rupiah_member($rowSimpanan['jumlah']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Notifikasi Member -->
    <div class="modal-overlay" id="modal-notif" onclick="overlayClose(event,this)">
        <div class="modal-sheet">
            <div class="modal-header">
                <div>
                    <div class="modal-title">Notifikasi</div>
                    <div style="font-size:10px;color:var(--g4);font-weight:700;margin-top:3px;"><?= (int)$memberNotifUnread ?> belum dibaca · <?= (int)$memberNotifTotal ?> total</div>
                </div>
                <button class="modal-close" type="button" onclick="closeModal('modal-notif')">
                    <svg viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="member-notif-list">
                <?php if (!$memberNotifRows): ?>
                    <div class="member-notif-empty">Belum ada notifikasi.</div>
                <?php else: ?>
                    <?php foreach ($memberNotifRows as $memberNotif): ?>
                        <?php
                        $memberNotifId = (int)($memberNotif['id'] ?? 0);
                        $memberNotifRef = (int)($memberNotif['ref_id'] ?? 0);
                        $memberNotifType = strtolower(trim((string)($memberNotif['ref_tipe'] ?? '')));
                        if ($memberNotifType === 'pengajuan' || $memberNotifType === 'pinjaman') {
                            $memberNotifUrl = 'member_dashboard.php?member_notif_read=' . $memberNotifId . '&tab=pinjaman';
                            if ($memberNotifType === 'pengajuan' && $memberNotifRef > 0) $memberNotifUrl .= '&pengajuan_id=' . $memberNotifRef;
                        } elseif ($memberNotifType === 'cafe') {
                            $memberNotifUrl = 'member_dashboard.php?member_notif_read=' . $memberNotifId . '&tab=pesanan&order_tab=cafe';
                        } elseif ($memberNotifType === 'rental') {
                            $memberNotifUrl = 'member_dashboard.php?member_notif_read=' . $memberNotifId . '&tab=pesanan&order_tab=bandara';
                        } else {
                            $memberNotifUrl = 'member_dashboard.php?member_notif_read=' . $memberNotifId . '&tab=pesanan&order_tab=minimarket';
                        }
                        $memberNotifIsUnread = (int)($memberNotif['is_read'] ?? 0) === 0;
                        ?>
                        <a class="member-notif-item <?= $memberNotifIsUnread ? 'unread' : '' ?>" href="<?= h($memberNotifUrl) ?>">
                            <div class="member-notif-title"><?= h($memberNotif['judul'] ?? 'Notifikasi') ?></div>
                            <div class="member-notif-message"><?= h($memberNotif['pesan'] ?? '') ?></div>
                            <div class="member-notif-time"><?= h(tanggal_member($memberNotif['created_at'] ?? null)) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="padding:12px 16px;border-top:1px solid var(--g7);display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap;background:#fff;">
                <button type="button" class="btn" style="font-size:9px;padding:8px 10px;" onclick="enableMemberBrowserNotif()">Aktifkan Notifikasi HP</button>
                <?php if ($memberNotifUnread > 0): ?><a class="btn" style="font-size:9px;padding:8px 10px;text-decoration:none;" href="member_dashboard.php?member_notif_read_all=1">Tandai Semua Dibaca</a><?php endif; ?>
                <span style="font-size:9px;color:var(--g4);font-weight:700;">10 terbaru dari <?= (int)$memberNotifTotal ?> notifikasi</span>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="success-toast" id="toast"></div>

    <div id="modal-konfirmasi-pinjaman" class="modal-overlay" style="display:none;z-index:9999;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;width:100%;max-width:620px;max-height:92vh;overflow-y:auto;border:1px solid #e5e7eb;box-shadow:0 24px 80px rgba(0,0,0,.22);">
            <div style="padding:18px 20px;border-bottom:1px solid #eee;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;position:sticky;top:0;background:#fff;z-index:2;">
                <div>
                    <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;color:#9ca3af;">Pengajuan Disetujui</div>
                    <h3 style="font-size:18px;font-weight:900;margin-top:4px;">Konfirmasi Pengambilan Pinjaman</h3>
                    <p style="font-size:11px;color:#6b7280;margin-top:5px;">Silakan konfirmasi apakah pinjaman akan diambil.</p>
                </div>
                <button type="button" onclick="closeKonfirmasiPinjaman()" style="border:0;background:#f3f4f6;width:34px;height:34px;font-size:20px;">&times;</button>
            </div>
            <form id="form-konfirmasi-pinjaman" style="padding:20px;">
                <input type="hidden" name="action" value="konfirmasi_pinjaman">
                <input type="hidden" name="id" id="konfirmasi-pinjaman-id" value="">
                <input type="hidden" name="keputusan" id="konfirmasi-keputusan" value="">
                <div id="konfirmasi-step-choice">
                    <div id="konfirmasi-ringkasan-nominal" style="border:1px solid #e5e7eb;background:#f9fafb;padding:14px;margin-bottom:14px;">
                        <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:10px;">Rincian Persetujuan</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <div style="font-size:9px;color:#9ca3af;font-weight:800;text-transform:uppercase;">Diajukan</div>
                                <div id="konf-jumlah-diajukan" style="font-size:16px;font-weight:900;margin-top:3px;">Rp 0</div>
                            </div>
                            <div>
                                <div style="font-size:9px;color:#9ca3af;font-weight:800;text-transform:uppercase;">Disetujui</div>
                                <div id="konf-jumlah-disetujui" style="font-size:18px;font-weight:900;color:#15803d;margin-top:3px;">Rp 0</div>
                            </div>
                        </div>
                        <div id="konf-nominal-berbeda" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:10px;color:#b45309;font-weight:700;">Nominal yang disetujui berbeda dari nominal pengajuan awal.</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #e5e7eb;">
                            <div>
                                <div style="font-size:9px;color:#9ca3af;font-weight:800;text-transform:uppercase;">Tenor</div>
                                <div id="konf-tenor" style="font-size:12px;font-weight:900;margin-top:3px;">-</div>
                            </div>
                            <div>
                                <div style="font-size:9px;color:#9ca3af;font-weight:800;text-transform:uppercase;">Estimasi Angsuran</div>
                                <div id="konf-angsuran" style="font-size:12px;font-weight:900;margin-top:3px;">Rp 0 / bln</div>
                            </div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <button type="button" onclick="pilihKeputusanPinjaman('ambil')" style="padding:16px 12px;border:1px solid #111;background:#111;color:#fff;font-size:11px;font-weight:900;text-transform:uppercase;">Ya, Saya Akan Mengambil</button>
                        <button type="button" onclick="pilihKeputusanPinjaman('tidak_ambil')" style="padding:16px 12px;border:1px solid #fecaca;background:#fff;color:#b91c1c;font-size:11px;font-weight:900;text-transform:uppercase;">Tidak Mengambil</button>
                    </div>
                </div>
                <div id="konfirmasi-data-wrap" style="display:none;margin-top:18px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div style="grid-column:1/-1"><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">Nama Sesuai KTP</label><input name="nama_ktp" id="konf-nama-ktp" value="<?= h($member['nama'] ?? '') ?>" class="transport-input" required></div>
                        <div style="grid-column:1/-1"><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">Alamat Sesuai KTP</label><textarea name="alamat_ktp" id="konf-alamat-ktp" class="transport-input transport-textarea" required></textarea></div>
                        <div><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">No. Handphone</label><input name="no_hp" value="<?= h($member['no_hp'] ?? '') ?>" class="transport-input" required></div>
                        <div><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">Nama Bank</label><input name="nama_bank" class="transport-input" placeholder="Contoh: BRI / BNI / BCA" required></div>
                        <div style="grid-column:1/-1"><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">No. Rekening</label><input name="no_rekening" class="transport-input" inputmode="numeric" required></div>
                        <div style="grid-column:1/-1;border-top:1px solid #eee;padding-top:16px;margin-top:4px;">
                            <div style="font-size:11px;font-weight:900;">Kontak Keluarga yang Dapat Dihubungi</div>
                        </div>
                        <div style="grid-column:1/-1"><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">Nama Anggota Keluarga</label><input name="nama_keluarga" class="transport-input" required></div>
                        <div style="grid-column:1/-1"><label style="display:flex;align-items:center;gap:8px;font-size:10px;font-weight:800;margin-bottom:8px;"><input type="checkbox" name="alamat_keluarga_sama" id="alamat-keluarga-sama" value="1"> Alamat sama dengan yang bersangkutan</label><textarea name="alamat_keluarga" id="konf-alamat-keluarga" class="transport-input transport-textarea" required></textarea></div>
                        <div style="grid-column:1/-1"><label style="display:block;font-size:10px;font-weight:800;margin-bottom:6px;">No. Handphone Keluarga</label><input name="no_hp_keluarga" class="transport-input" required></div>
                    </div>
                    <button type="submit" style="margin-top:18px;width:100%;padding:13px;background:#111;color:#fff;border:0;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;">Simpan Konfirmasi</button>
                </div>
            </form>
        </div>
    </div>


    <script>
        'use strict';

        /* ── Navigation ───────────────────────────────────────────────────────────── */
        function goTo(page) {
            // Nonaktifkan semua halaman
            document.querySelectorAll('.page').forEach(function(p) {
                p.classList.remove('active');
            });

            // Nonaktifkan semua tombol nav
            document.querySelectorAll('.nav-btn').forEach(function(b) {
                b.classList.remove('active');
            });

            // Aktifkan halaman tujuan
            var pg = document.getElementById('page-' + page);
            if (pg) {
                pg.classList.add('active');
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }

            // Aktifkan tombol nav sesuai data-page
            var btn = document.querySelector('.nav-btn[data-page="' + page + '"]');
            if (btn) {
                btn.classList.add('active');
            }

            // Simpan halaman terakhir
            try {
                localStorage.setItem('member_dashboard_active_page', page);
            } catch (e) {}
        }

        /* ── Category chips ── */
        function setCat(el) {
            document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
        }

        function setPromoTab(el) {
            document.querySelectorAll('#page-promo .tab-btn').forEach(t => t.classList.remove('active'));
            el.classList.add('active');
        }

        function setOrderTab(el) {
            document.querySelectorAll('#page-pesanan .tab-btn').forEach(t => t.classList.remove('active'));
            el.classList.add('active');

            var target = el.getAttribute('data-target') || 'pesanan-semua';

            document.querySelectorAll('#page-pesanan .pesanan-content').forEach(function(box) {
                box.classList.remove('active');
            });

            var activeBox = document.getElementById(target);
            if (activeBox) activeBox.classList.add('active');
        }

        function applyOrderTabFromQuery() {
            var wanted = new URLSearchParams(window.location.search).get('order_tab') || '';
            var map = {
                minimarket: 'pesanan-minimarket',
                cafe: 'pesanan-cafe',
                bandara: 'pesanan-bandara',
                semua: 'pesanan-semua'
            };
            if (!map[wanted]) return;
            var btn = document.querySelector('#page-pesanan .tab-btn[data-target="' + map[wanted] + '"]');
            if (btn) setOrderTab(btn);
        }

        var memberNotifLastUnread = <?= (int)$memberNotifUnread ?>;
        var memberNotifLastId = <?= (int)(($memberNotifRows[0]['id'] ?? 0)) ?>;

        function enableMemberBrowserNotif() {
            if (!('Notification' in window)) {
                showToast('Browser ini belum mendukung notifikasi perangkat.');
                return;
            }
            Notification.requestPermission().then(function(permission) {
                showToast(permission === 'granted' ? 'Notifikasi HP diaktifkan.' : 'Izin notifikasi belum diberikan.');
            });
        }

        function pollMemberNotifications() {
            fetch('member_dashboard.php?member_notif_poll=1', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store'
                })
                .then(function(r) {
                    return r.json();
                }).then(function(data) {
                    if (!data || !data.success) return;
                    var latest = data.latest || null,
                        latestId = latest ? Number(latest.id || 0) : 0;
                    if (Number(data.unread || 0) > memberNotifLastUnread && latest && latestId !== memberNotifLastId) {
                        if ('Notification' in window && Notification.permission === 'granted') {
                            var n = new Notification(latest.judul || 'SEJAHUB', {
                                body: latest.pesan || 'Ada notifikasi baru.'
                            });
                            n.onclick = function() {
                                window.focus();
                                window.location.href = 'member_dashboard.php';
                            };
                        }
                        memberNotifLastId = latestId;
                    }
                    memberNotifLastUnread = Number(data.unread || 0);
                    var badge = document.querySelector('.member-notif-badge');
                    if (memberNotifLastUnread > 0) {
                        if (!badge) {
                            var bell = document.querySelector('.member-notif-bell');
                            if (bell) {
                                badge = document.createElement('span');
                                badge.className = 'member-notif-badge';
                                bell.appendChild(badge);
                            }
                        }
                        if (badge) badge.textContent = memberNotifLastUnread > 9 ? '9+' : String(memberNotifLastUnread);
                    } else if (badge) badge.remove();
                }).catch(function() {});
        }

        /* ── Modal ── */
        function openModal(id) {
            var m = document.getElementById(id);
            if (m) m.classList.add('open');
        }

        function closeModal(id) {
            var m = document.getElementById(id);
            if (m) m.classList.remove('open');
        }

        function overlayClose(e, overlay) {
            if (e.target === overlay) overlay.classList.remove('open');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
            }
        });

        /* ── Voucher copy ── */
        function copyVoucher(code) {
            if (navigator.clipboard) navigator.clipboard.writeText(code);
            showToast('Kode "' + code + '" disalin!');
        }

        /* ── Tukar point ── */
        function selectTukar(el) {
            document.querySelectorAll('.tukar-option').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
        }

        /* ── Struk ── */
        function cariStruk() {
            var v = document.getElementById('struk-invoice').value.trim();
            if (!v) {
                showToast('Masukkan nomor invoice');
                return;
            }
            window.open('struk.php?invoice=' + encodeURIComponent(v) + '&member=1', '_blank');
        }

        /* ── FAQ ── */
        function toggleFAQ(el) {
            var a = el.nextElementSibling;
            var ch = el.querySelector('.faq-chevron');
            a.classList.toggle('open');
            ch.classList.toggle('open');
        }

        function filterFAQ(q) {
            q = q.toLowerCase();
            document.querySelectorAll('.faq-item').forEach(function(item) {
                item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        /* ── Save profil (demo) ── */
        function saveProfil() {
            var nama = document.getElementById('edit-nama').value.trim();
            if (!nama) {
                showToast('Nama tidak boleh kosong');
                return;
            }
            showToast('Profil berhasil diperbarui!');
            closeModal('modal-profil');
        }

        /* ── Toast ── */
        function showToast(msg) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(t._to);
            t._to = setTimeout(function() {
                t.classList.remove('show');
            }, 2500);
        }

        /* ── Logout ── */
        function confirmLogout() {
            if (confirm('Yakin ingin keluar dari akun member?')) {
                window.location.href = 'member_dashboard.php?logout=1';
            }
        }

        /* ── Pinjaman Member ── */
        var TENOR_UANG = <?= json_encode(array_values($tenorUang)) ?>;
        var TENOR_BARANG = <?= json_encode(array_values($tenorBarang)) ?>;
        var BUNGA_UANG = Number(<?= json_encode((float)$konfigSp['bunga_uang']) ?>) || 0;
        var BUNGA_BARANG = Number(<?= json_encode((float)$konfigSp['bunga_barang']) ?>) || 0;

        function getLoanJenis() {
            var checked = document.querySelector('input[name="jenis"]:checked');
            return checked ? checked.value : 'uang';
        }

        function normalizeNumberInput(v) {
            return Number(String(v || '').replace(/\./g, '').replace(/,/g, '.')) || 0;
        }

        function formatRupiahSimple(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function updateLoanForm() {
            var jenis = getLoanJenis();
            var tenorSelect = document.getElementById('loan-tenor');
            var jumlahLabel = document.getElementById('loan-jumlah-label');
            var namaBarangWrap = document.getElementById('nama-barang-wrap');
            var namaBarang = document.getElementById('loan-nama-barang');

            if (!tenorSelect) return;

            var tenors = jenis === 'barang' ? TENOR_BARANG : TENOR_UANG;
            tenorSelect.innerHTML = '';
            if (!tenors || tenors.length === 0) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Tenor belum dikonfigurasi';
                tenorSelect.appendChild(opt);
            } else {
                tenors.forEach(function(t) {
                    var opt = document.createElement('option');
                    opt.value = t;
                    opt.textContent = t + ' Bulan';
                    tenorSelect.appendChild(opt);
                });
            }

            if (jumlahLabel) jumlahLabel.textContent = jenis === 'barang' ? 'Harga Barang' : 'Nominal Pinjaman';
            if (namaBarangWrap) namaBarangWrap.style.display = jenis === 'barang' ? '' : 'none';
            if (namaBarang && jenis !== 'barang') namaBarang.value = '';

            updateLoanEstimate();
        }

        var formPinjamanMember = document.getElementById('form-pinjaman-member');
        if (formPinjamanMember) {
            formPinjamanMember.addEventListener('submit', function(e) {
                var tenorSelect = document.getElementById('loan-tenor');
                if (!tenorSelect || !tenorSelect.value) {
                    e.preventDefault();
                    showToast('Tenor belum dikonfigurasi untuk jenis pinjaman ini');
                }
            });
        }

        function updateLoanEstimate() {
            var jumlah = normalizeNumberInput(document.getElementById('loan-jumlah') ? document.getElementById('loan-jumlah').value : 0);
            var tenor = Number(document.getElementById('loan-tenor') ? document.getElementById('loan-tenor').value : 0) || 0;
            var jenis = getLoanJenis();
            var bunga = jenis === 'barang' ? BUNGA_BARANG : BUNGA_UANG;

            var pokok = tenor > 0 ? Math.round(jumlah / tenor) : 0;
            // Bunga diambil dari database konfigurasi_sp sebagai total persen bunga.
            // Contoh: nominal 5.000.000, bunga 10%, tenor 10 bulan
            // total bunga = 500.000, bunga per bulan = 50.000
            var totalBunga = Math.round(jumlah * bunga / 100);
            var bungaBulanan = tenor > 0 ? Math.round(totalBunga / tenor) : 0;
            var total = pokok + bungaBulanan;

            var elPokok = document.getElementById('loan-est-pokok');
            var elBunga = document.getElementById('loan-est-bunga');
            var elTotal = document.getElementById('loan-est-total');

            if (elPokok) elPokok.textContent = formatRupiahSimple(pokok);
            if (elBunga) elBunga.textContent = formatRupiahSimple(bungaBulanan);
            if (elTotal) elTotal.textContent = formatRupiahSimple(total);
        }


        var KONFIRMASI_PINJAMAN_DATA = <?= json_encode(array_column($pengajuanPinjaman, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        var TARGET_PENGAJUAN_ID = Number(new URLSearchParams(window.location.search).get('pengajuan_id') || 0);

        function openKonfirmasiPinjaman(id) {
            document.getElementById('konfirmasi-pinjaman-id').value = id || '';
            document.getElementById('konfirmasi-keputusan').value = '';
            document.getElementById('konfirmasi-step-choice').style.display = '';
            document.getElementById('konfirmasi-data-wrap').style.display = 'none';

            var item = KONFIRMASI_PINJAMAN_DATA[id] || {};
            var diajukan = Number(item.jumlah || 0);
            var disetujui = Number(item.jumlah_disetujui || 0) > 0 ? Number(item.jumlah_disetujui) : diajukan;
            var tenor = Number(item.tenor || 0);
            var bunga = String(item.jenis || '') === 'barang' ? BUNGA_BARANG : BUNGA_UANG;
            var totalBunga = Math.round(disetujui * bunga / 100);
            var angsuran = tenor > 0 ? Math.round(disetujui / tenor) + Math.round(totalBunga / tenor) : 0;

            var elDiajukan = document.getElementById('konf-jumlah-diajukan');
            var elDisetujui = document.getElementById('konf-jumlah-disetujui');
            var elBeda = document.getElementById('konf-nominal-berbeda');
            var elTenor = document.getElementById('konf-tenor');
            var elAngsuran = document.getElementById('konf-angsuran');
            if (elDiajukan) elDiajukan.textContent = formatRupiahSimple(diajukan);
            if (elDisetujui) elDisetujui.textContent = formatRupiahSimple(disetujui);
            if (elBeda) elBeda.style.display = Math.round(diajukan) !== Math.round(disetujui) ? '' : 'none';
            if (elTenor) elTenor.textContent = tenor > 0 ? tenor + ' Bulan' : '-';
            if (elAngsuran) elAngsuran.textContent = formatRupiahSimple(angsuran) + ' / bln';

            var modal = document.getElementById('modal-konfirmasi-pinjaman');
            if (modal) modal.style.display = 'flex';
        }

        function closeKonfirmasiPinjaman() {
            var modal = document.getElementById('modal-konfirmasi-pinjaman');
            if (modal) modal.style.display = 'none';
        }

        function pilihKeputusanPinjaman(keputusan) {
            document.getElementById('konfirmasi-keputusan').value = keputusan;
            if (keputusan === 'ambil') {
                document.getElementById('konfirmasi-step-choice').style.display = 'none';
                document.getElementById('konfirmasi-data-wrap').style.display = '';
                return;
            }
            if (!confirm('Anda memilih tidak mengambil pinjaman yang telah disetujui. Lanjutkan?')) return;
            simpanKonfirmasiPinjaman();
        }

        async function simpanKonfirmasiPinjaman() {
            var formEl = document.getElementById('form-konfirmasi-pinjaman');
            if (!formEl) return;
            var keputusan = document.getElementById('konfirmasi-keputusan').value;
            if (!keputusan) {
                showToast('Pilih konfirmasi pengambilan terlebih dahulu.');
                return;
            }

            // Validasi field hanya saat member memilih akan mengambil.
            if (keputusan === 'ambil' && !formEl.reportValidity()) {
                return;
            }

            var submitBtn = formEl.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.textContent;
                submitBtn.textContent = 'Menyimpan...';
            }

            var form = new FormData(formEl);
            try {
                var res = await fetch('member_dashboard.php', {
                    method: 'POST',
                    body: form,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                var raw = await res.text();
                var data;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    throw new Error(raw ? raw.substring(0, 300) : 'Respons server kosong.');
                }

                showToast(data.message || 'Konfirmasi diproses');
                if (data.success) {
                    setTimeout(function() {
                        window.location.href = 'member_dashboard.php#pinjaman';
                    }, 900);
                    return;
                }
            } catch (e) {
                console.error('Simpan konfirmasi pinjaman:', e);
                showToast('Gagal menyimpan konfirmasi: ' + (e.message || 'kesalahan server'));
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Simpan Konfirmasi';
                }
            }
        }

        function initKonfirmasiPinjaman() {
            document.querySelectorAll('.btn-konfirmasi-pinjaman').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openKonfirmasiPinjaman(this.getAttribute('data-id'));
                });
            });
            var cb = document.getElementById('alamat-keluarga-sama');
            if (cb) cb.addEventListener('change', function() {
                var target = document.getElementById('konf-alamat-keluarga');
                var source = document.getElementById('konf-alamat-ktp');
                if (!target || !source) return;
                target.value = this.checked ? source.value : '';
                target.readOnly = this.checked;
            });
            var formEl = document.getElementById('form-konfirmasi-pinjaman');
            if (formEl) formEl.addEventListener('submit', function(e) {
                e.preventDefault();
                simpanKonfirmasiPinjaman();
            });
            if (TARGET_PENGAJUAN_ID > 0) {
                setTimeout(function() {
                    var card = document.getElementById('pengajuan-' + TARGET_PENGAJUAN_ID);
                    if (card) {
                        card.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        card.style.boxShadow = '0 0 0 2px #2563eb';
                        setTimeout(function() {
                            card.style.boxShadow = '';
                        }, 2200);
                    }
                }, 500);
            }
        }


        function initBatalPengajuan() {
            document.querySelectorAll('.btn-batal-pengajuan').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    var id = this.getAttribute('data-id');
                    if (!id) return;

                    if (!confirm('Batalkan pengajuan pinjaman ini?')) {
                        return;
                    }

                    try {
                        var form = new FormData();
                        form.append('action', 'batal_pengajuan');
                        form.append('id', id);

                        var res = await fetch('member_dashboard.php', {
                            method: 'POST',
                            body: form
                        });

                        var data = await res.json();
                        showToast(data.message || 'Pengajuan diproses');

                        if (data.success) {
                            setTimeout(function() {
                                window.location.href = 'member_dashboard.php#pinjaman';
                            }, 900);
                        }
                    } catch (e) {
                        showToast('Gagal membatalkan pengajuan.');
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof updateLoanForm === 'function') {
                updateLoanForm();
            }
            if (typeof initBatalPengajuan === 'function') {
                initBatalPengajuan();
            }
            if (typeof initKonfirmasiPinjaman === 'function') {
                initKonfirmasiPinjaman();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            var params = new URLSearchParams(window.location.search);
            var targetTab = params.get('tab') || '';
            // Buka Beranda secara default. Halaman lain hanya dibuka jika datang dari klik notifikasi/menu dengan parameter tab.
            if (typeof goTo === 'function') {
                goTo(targetTab && document.getElementById('page-' + targetTab) ? targetTab : 'beranda');
            }
            if (targetTab === 'pesanan') applyOrderTabFromQuery();
            try {
                localStorage.setItem('member_dashboard_active_page', 'beranda');
            } catch (e) {}
            if (window.location.hash) history.replaceState(null, document.title, window.location.pathname + window.location.search);
            setTimeout(pollMemberNotifications, 5000);
            setInterval(pollMemberNotifications, 30000);
        });
    </script>
</body>

</html>