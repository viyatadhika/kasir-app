<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
requireAccess();

$activeMenu = 'kas_harian';
$pageTitle  = 'Buka & Tutup Kas';
$backUrl    = 'dashboard.php';

if (!function_exists('e')) {
    /**
     * @param mixed $v
     * @return string
     */
    function e($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('rupiah')) {
    /**
     * @param mixed $n
     * @return string
     */
    function rupiah($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}
if (!function_exists('tanggal_id')) {
    /**
     * @param string|null $dateTime
     * @return string
     */
    function tanggal_id($dateTime)
    {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $ts = strtotime($dateTime ?: date('Y-m-d H:i:s'));
        return $hari[(int)date('w', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y H:i', $ts);
    }
}
if (!function_exists('current_user_id_safe')) {
    function current_user_id_safe()
    {
        foreach (['user_id', 'id_user', 'id', 'admin_id'] as $k) {
            if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
        }
        if (!empty($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
        return 0;
    }
}
if (!function_exists('current_user_name_safe')) {
    function current_user_name_safe()
    {
        foreach (['nama', 'name', 'username', 'user_name'] as $k) {
            if (!empty($_SESSION[$k])) return (string)$_SESSION[$k];
        }
        if (!empty($_SESSION['user']['nama'])) return (string)$_SESSION['user']['nama'];
        if (!empty($_SESSION['user']['name'])) return (string)$_SESSION['user']['name'];
        if (!empty($_SESSION['user']['username'])) return (string)$_SESSION['user']['username'];
        return 'Operator';
    }
}
if (!function_exists('current_user_role_safe')) {
    function current_user_role_safe()
    {
        foreach (['role', 'level', 'user_role', 'tipe_user'] as $k) {
            if (!empty($_SESSION[$k])) return strtolower((string)$_SESSION[$k]);
        }
        if (!empty($_SESSION['user']['role'])) return strtolower((string)$_SESSION['user']['role']);
        if (!empty($_SESSION['user']['level'])) return strtolower((string)$_SESSION['user']['level']);
        return '';
    }
}
if (!function_exists('is_admin_kas_safe')) {
    function is_admin_kas_safe()
    {
        $role = current_user_role_safe();
        return in_array($role, ['admin', 'administrator', 'superadmin', 'owner'], true);
    }
}
if (!function_exists('has_table')) {
    /**
     * @param PDO $pdo
     * @param string $table
     * @return bool
     */
    function has_table(PDO $pdo, $table)
    {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE :t");
            $st->execute([':t' => $table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('has_column')) {
    /**
     * @param PDO $pdo
     * @param string $table
     * @param string $col
     * @return bool
     */
    function has_column(PDO $pdo, $table, $col)
    {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
            $st->execute([':c' => $col]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('first_existing_column')) {
    /**
     * @param PDO $pdo
     * @param string $table
     * @param string[] $cols
     * @param string $default
     * @return string
     */
    function first_existing_column(PDO $pdo, $table, array $cols, $default = '')
    {
        foreach ($cols as $c) if (has_column($pdo, $table, $c)) return $c;
        return $default;
    }
}
if (!function_exists('ensure_kas_harian_table')) {
    function ensure_kas_harian_table(PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kas_harian (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            user_id INT NULL,
            operator VARCHAR(150) NULL,
            kas_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
            kas_akhir_sistem DECIMAL(15,2) NOT NULL DEFAULT 0,
            kas_aktual DECIMAL(15,2) NULL,
            total_sales DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_tunai DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_nontunai DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_struk INT NOT NULL DEFAULT 0,
            margin DECIMAL(15,2) NOT NULL DEFAULT 0,
            fee_promosi DECIMAL(15,2) NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            status ENUM('buka','tutup') NOT NULL DEFAULT 'buka',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_tanggal (tanggal),
            INDEX idx_user_tanggal (user_id, tanggal),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
ensure_kas_harian_table($pdo);

// Tambahan struktur audit dan indeks untuk menjaga satu sesi kas aktif per operator.
try {
    if (!has_column($pdo, 'kas_harian', 'closed_by_user_id')) {
        $pdo->exec("ALTER TABLE kas_harian ADD COLUMN closed_by_user_id INT NULL AFTER closed_at");
    }
    if (!has_column($pdo, 'kas_harian', 'closed_by_name')) {
        $pdo->exec("ALTER TABLE kas_harian ADD COLUMN closed_by_name VARCHAR(150) NULL AFTER closed_by_user_id");
    }
    if (!has_column($pdo, 'kas_harian', 'close_type')) {
        $pdo->exec("ALTER TABLE kas_harian ADD COLUMN close_type ENUM('normal','admin') NOT NULL DEFAULT 'normal' AFTER closed_by_name");
    }
    if (!has_column($pdo, 'kas_harian', 'close_reason')) {
        $pdo->exec("ALTER TABLE kas_harian ADD COLUMN close_reason VARCHAR(255) NULL AFTER close_type");
    }
    $indexes = $pdo->query("SHOW INDEX FROM kas_harian")->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_map(function ($row) {
        return (string)($row['Key_name'] ?? $row['key_name'] ?? '');
    }, $indexes);
    if (!in_array('idx_user_status', $indexNames, true)) {
        $pdo->exec("ALTER TABLE kas_harian ADD INDEX idx_user_status (user_id, status)");
    }
} catch (Throwable $e) {
    // Struktur lama tetap dapat digunakan jika akun database tidak memiliki izin ALTER.
}

$userId        = current_user_id_safe();
$operatorName  = current_user_name_safe();
$today         = date('Y-m-d');
$isAdminKas   = is_admin_kas_safe();

/**
 * @param PDO $pdo
 * @param string $tanggal
 * @param int $userId
 * @return array|null
 */
function get_shift_buka(PDO $pdo, $tanggal, $userId)
{
    // Satu operator hanya boleh memiliki satu sesi berstatus buka, tanpa membatasi tanggal.
    // Parameter $tanggal dipertahankan agar kompatibel dengan pemanggilan lama.
    $st = $pdo->prepare("SELECT * FROM kas_harian WHERE user_id=:user_id AND status='buka' ORDER BY opened_at ASC, id ASC LIMIT 1");
    $st->execute([':user_id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param PDO $pdo
 * @param string $tanggal
 * @param int $userId
 * @return array|null
 */
function get_shift_terakhir(PDO $pdo, $tanggal, $userId)
{
    // Ambil sesi terakhir operator tanpa membatasi tanggal agar sesi lama tetap terlihat.
    $st = $pdo->prepare("SELECT * FROM kas_harian WHERE user_id=:user_id ORDER BY opened_at DESC, id DESC LIMIT 1");
    $st->execute([':user_id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param PDO $pdo
 * @param string $openedAt
 * @param string|null $closedAt
 * @return array
 */
function hitung_penjualan_shift(PDO $pdo, $openedAt, $closedAt = null)
{
    $data = [
        'total_sales'    => 0,
        'total_tunai'    => 0,
        'total_nontunai' => 0,
        'total_struk'    => 0,
        'margin'         => 0,
        'fee_promosi'    => 0
    ];
    if (!has_table($pdo, 'transaksi')) return $data;

    $dateCol   = first_existing_column($pdo, 'transaksi', ['created_at', 'tanggal', 'waktu', 'tgl_transaksi', 'tanggal_transaksi'], 'created_at');
    $totalCol  = first_existing_column($pdo, 'transaksi', ['grand_total', 'total_bayar', 'total', 'subtotal', 'total_harga'], '');
    $payCol    = first_existing_column($pdo, 'transaksi', ['metode_pembayaran', 'metode_bayar', 'payment_method', 'jenis_bayar', 'pembayaran'], '');
    $statusCol = first_existing_column($pdo, 'transaksi', ['status', 'status_transaksi'], '');
    $promoCol  = first_existing_column($pdo, 'transaksi', ['fee_promosi', 'biaya_promosi', 'promo_fee'], '');

    if ($totalCol === '') return $data;

    $where  = "`$dateCol` >= :start";
    $params = [':start' => $openedAt];
    if ($closedAt) {
        $where .= " AND `$dateCol` <= :end";
        $params[':end'] = $closedAt;
    }
    if ($statusCol !== '') {
        $where .= " AND (`$statusCol` IS NULL OR `$statusCol` NOT IN ('batal','cancel','cancelled','void'))";
    }

    $tunaiExpr    = "0";
    $nontunaiExpr = "0";
    if ($payCol !== '') {
        $tunaiExpr    = "SUM(CASE WHEN LOWER(COALESCE(`$payCol`,'')) IN ('tunai','cash') THEN `$totalCol` ELSE 0 END)";
        $nontunaiExpr = "SUM(CASE WHEN LOWER(COALESCE(`$payCol`,'')) NOT IN ('tunai','cash') THEN `$totalCol` ELSE 0 END)";
    }
    $promoExpr = $promoCol !== '' ? "SUM(COALESCE(`$promoCol`,0))" : "0";

    $sql = "SELECT COALESCE(SUM(`$totalCol`),0) total_sales, COALESCE($tunaiExpr,0) total_tunai, COALESCE($nontunaiExpr,0) total_nontunai, COUNT(*) total_struk, COALESCE($promoExpr,0) fee_promosi FROM transaksi WHERE $where";
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($data as $k => $v) if (isset($r[$k])) $data[$k] = (float)$r[$k];

    if (has_table($pdo, 'transaksi_detail')) {
        $detailTransCol = first_existing_column($pdo, 'transaksi_detail', ['transaksi_id', 'id_transaksi'], 'transaksi_id');
        $detailQtyCol   = first_existing_column($pdo, 'transaksi_detail', ['qty', 'jumlah', 'kuantitas'], '');
        $detailHargaCol = first_existing_column($pdo, 'transaksi_detail', ['harga', 'harga_jual', 'harga_satuan'], '');
        $detailBeliCol  = first_existing_column($pdo, 'transaksi_detail', ['harga_beli', 'modal', 'hpp'], '');
        if ($detailQtyCol !== '' && $detailHargaCol !== '' && $detailBeliCol !== '') {
            $idCol = first_existing_column($pdo, 'transaksi', ['id', 'transaksi_id'], 'id');
            $sqlM  = "SELECT COALESCE(SUM((COALESCE(d.`$detailHargaCol`,0)-COALESCE(d.`$detailBeliCol`,0))*COALESCE(d.`$detailQtyCol`,0)),0) AS margin
                     FROM transaksi_detail d JOIN transaksi t ON t.`$idCol` = d.`$detailTransCol` WHERE t.`$dateCol` >= :start";
            if ($closedAt) $sqlM .= " AND t.`$dateCol` <= :end";
            if ($statusCol !== '') $sqlM .= " AND (t.`$statusCol` IS NULL OR t.`$statusCol` NOT IN ('batal','cancel','cancelled','void'))";
            $stm = $pdo->prepare($sqlM);
            $stm->execute($params);
            $data['margin'] = (float)$stm->fetchColumn();
        }
    }
    return $data;
}

if (!function_exists('durasi_sesi_kas')) {
    function durasi_sesi_kas($openedAt, $closedAt = null)
    {
        if (!$openedAt) return '-';
        $start = strtotime((string)$openedAt);
        $end = $closedAt ? strtotime((string)$closedAt) : time();
        if (!$start || !$end || $end < $start) return '-';
        $seconds = $end - $start;
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days . ' hari';
        if ($hours > 0 || $days > 0) $parts[] = $hours . ' jam';
        $parts[] = $minutes . ' menit';
        return implode(' ', $parts);
    }
}

/**
 * @param array $arr
 * @return void
 */
function json_response($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    ini_set('display_errors', '0');
    try {
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

        if ($_GET['action'] === 'buka') {
            $kasAwal = (float)preg_replace('/[^0-9]/', '', (string)($input['kas_awal'] ?? 0));
            if ($kasAwal < 0) json_response(['success' => false, 'message' => 'Kas awal tidak valid.']);
            if ($userId <= 0) json_response(['success' => false, 'message' => 'Session pengguna tidak valid. Silakan login ulang.']);

            $lockName = 'kas_harian_user_' . $userId;
            $lockStmt = $pdo->prepare("SELECT GET_LOCK(:lock_name, 5)");
            $lockStmt->execute([':lock_name' => $lockName]);
            if ((int)$lockStmt->fetchColumn() !== 1) {
                json_response(['success' => false, 'message' => 'Sistem sedang memproses kas operator ini. Silakan coba kembali.']);
            }

            try {
                $aktif = get_shift_buka($pdo, $today, $userId);
                if ($aktif) {
                    $pesan = 'Masih ada sesi kas yang belum ditutup sejak ' . tanggal_id($aktif['opened_at']) . ' (' . durasi_sesi_kas($aktif['opened_at']) . '). Tutup sesi tersebut terlebih dahulu.';
                    $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)")->execute([':lock_name' => $lockName]);
                    json_response(['success' => false, 'message' => $pesan, 'open_shift_id' => (int)$aktif['id']]);
                }
                $st = $pdo->prepare("INSERT INTO kas_harian (tanggal,user_id,operator,kas_awal,status,opened_at) VALUES (:tanggal,:user_id,:operator,:kas_awal,'buka',NOW())");
                $st->execute([':tanggal' => $today, ':user_id' => $userId, ':operator' => $operatorName, ':kas_awal' => $kasAwal]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)")->execute([':lock_name' => $lockName]);
                json_response(['success' => true, 'message' => 'Kas berhasil dibuka.', 'id' => $newId]);
            } catch (Throwable $e) {
                try {
                    $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)")->execute([':lock_name' => $lockName]);
                } catch (Throwable $ignore) {
                }
                throw $e;
            }
        }

        if ($_GET['action'] === 'tutup') {
            $aktif = get_shift_buka($pdo, $today, $userId);
            if (!$aktif) json_response(['success' => false, 'message' => 'Tidak ada sesi kas yang sedang terbuka.']);
            $kasAktual = (float)preg_replace('/[^0-9]/', '', (string)($input['kas_aktual'] ?? 0));
            $catatan   = trim((string)($input['catatan'] ?? ''));
            $sales     = hitung_penjualan_shift($pdo, $aktif['opened_at'], date('Y-m-d H:i:s'));
            $kasAkhir  = (float)$aktif['kas_awal'] + (float)$sales['total_tunai'];
            $auditSet = '';
            if (has_column($pdo, 'kas_harian', 'closed_by_user_id')) $auditSet .= ', closed_by_user_id=:closed_by_user_id';
            if (has_column($pdo, 'kas_harian', 'closed_by_name')) $auditSet .= ', closed_by_name=:closed_by_name';
            if (has_column($pdo, 'kas_harian', 'close_type')) $auditSet .= ", close_type='normal'";
            $st = $pdo->prepare("UPDATE kas_harian SET
                kas_akhir_sistem=:kas_akhir_sistem, kas_aktual=:kas_aktual, total_sales=:total_sales,
                total_tunai=:total_tunai, total_nontunai=:total_nontunai, total_struk=:total_struk,
                margin=:margin, fee_promosi=:fee_promosi, catatan=:catatan, status='tutup', closed_at=NOW(), updated_at=NOW()
                $auditSet
                WHERE id=:id AND status='buka'");
            $closeParams = [
                ':kas_akhir_sistem' => $kasAkhir,
                ':kas_aktual'       => $kasAktual,
                ':total_sales'      => $sales['total_sales'],
                ':total_tunai'      => $sales['total_tunai'],
                ':total_nontunai'   => $sales['total_nontunai'],
                ':total_struk'      => $sales['total_struk'],
                ':margin'           => $sales['margin'],
                ':fee_promosi'      => $sales['fee_promosi'],
                ':catatan'          => $catatan,
                ':id'               => $aktif['id']
            ];
            if (has_column($pdo, 'kas_harian', 'closed_by_user_id')) $closeParams[':closed_by_user_id'] = $userId;
            if (has_column($pdo, 'kas_harian', 'closed_by_name')) $closeParams[':closed_by_name'] = $operatorName;
            $st->execute($closeParams);
            json_response(['success' => true, 'message' => 'Kas berhasil ditutup.', 'id' => $aktif['id']]);
        }

        if ($_GET['action'] === 'ringkasan') {
            $aktif = get_shift_buka($pdo, $today, $userId);
            $last  = $aktif ?: get_shift_terakhir($pdo, $today, $userId);
            if (!$last) json_response(['success' => true, 'data' => null]);
            $sales = ($last['status'] === 'buka') ? hitung_penjualan_shift($pdo, $last['opened_at']) : [
                'total_sales'    => $last['total_sales'],
                'total_tunai'    => $last['total_tunai'],
                'total_nontunai' => $last['total_nontunai'],
                'total_struk'    => $last['total_struk'],
                'margin'         => $last['margin'],
                'fee_promosi'    => $last['fee_promosi']
            ];
            $last['kas_akhir_sistem'] = ($last['status'] === 'buka') ? ((float)$last['kas_awal'] + (float)$sales['total_tunai']) : (float)$last['kas_akhir_sistem'];
            json_response(['success' => true, 'data' => $last, 'sales' => $sales]);
        }

        if ($_GET['action'] === 'detail') {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) json_response(['success' => false, 'message' => 'ID tidak valid.']);
            if ($isAdminKas) {
                $st = $pdo->prepare("SELECT * FROM kas_harian WHERE id=:id LIMIT 1");
                $st->execute([':id' => $id]);
            } else {
                $st = $pdo->prepare("SELECT * FROM kas_harian WHERE id=:id AND user_id=:user_id LIMIT 1");
                $st->execute([':id' => $id, ':user_id' => $userId]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_response(['success' => false, 'message' => 'Data tidak ditemukan.']);
            $sales = ($row['status'] === 'buka') ? hitung_penjualan_shift($pdo, $row['opened_at']) : [
                'total_sales'    => $row['total_sales'],
                'total_tunai'    => $row['total_tunai'],
                'total_nontunai' => $row['total_nontunai'],
                'total_struk'    => $row['total_struk'],
                'margin'         => $row['margin'],
                'fee_promosi'    => $row['fee_promosi']
            ];
            $kasAkhir = ($row['status'] === 'buka') ? ((float)$row['kas_awal'] + (float)$sales['total_tunai']) : (float)$row['kas_akhir_sistem'];
            $kasAktual = $row['kas_aktual'] !== null ? (float)$row['kas_aktual'] : $kasAkhir;
            $row['kas_akhir_sistem'] = $kasAkhir;
            $row['kas_aktual_display'] = $kasAktual;
            $row['selisih'] = $kasAktual - $kasAkhir;
            json_response(['success' => true, 'data' => $row, 'sales' => $sales]);
        }
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

$shiftAktif = get_shift_buka($pdo, $today, $userId);
$shiftLast  = $shiftAktif ?: get_shift_terakhir($pdo, $today, $userId);
$salesNow   = $shiftLast ? (($shiftLast['status'] === 'buka') ? hitung_penjualan_shift($pdo, $shiftLast['opened_at']) : [
    'total_sales'    => $shiftLast['total_sales'],
    'total_tunai'    => $shiftLast['total_tunai'],
    'total_nontunai' => $shiftLast['total_nontunai'],
    'total_struk'    => $shiftLast['total_struk'],
    'margin'         => $shiftLast['margin'],
    'fee_promosi'    => $shiftLast['fee_promosi']
]) : ['total_sales' => 0, 'total_tunai' => 0, 'total_nontunai' => 0, 'total_struk' => 0, 'margin' => 0, 'fee_promosi' => 0];

$kasAkhirNow  = $shiftLast ? (($shiftLast['status'] === 'buka') ? ((float)$shiftLast['kas_awal'] + (float)$salesNow['total_tunai']) : (float)$shiftLast['kas_akhir_sistem']) : 0;
$kasAktualNow = $shiftLast && $shiftLast['kas_aktual'] !== null ? (float)$shiftLast['kas_aktual'] : $kasAkhirNow;
$selisihNow   = $kasAktualNow - $kasAkhirNow;
$durasiShiftNow = $shiftAktif ? durasi_sesi_kas($shiftAktif['opened_at']) : '-';
$shiftOverdue = $shiftAktif && date('Y-m-d', strtotime((string)$shiftAktif['opened_at'])) < $today;

// ── Filter + pagination riwayat kas ─────────────────────────────────────────
$filterStart   = trim((string)($_GET['start'] ?? ''));
$filterEnd     = trim((string)($_GET['end'] ?? ''));
$filterStatus  = trim((string)($_GET['status'] ?? ''));
$filterUserId  = $isAdminKas ? (int)($_GET['operator'] ?? 0) : 0;
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = (int)($_GET['per_page'] ?? 10);
$allowedPerPage = [10, 20, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 10;
$offset        = ($page - 1) * $perPage;

if ($filterStart !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterStart)) $filterStart = '';
if ($filterEnd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterEnd)) $filterEnd = '';
if (!in_array($filterStatus, ['buka', 'tutup'], true)) $filterStatus = '';

$operatorFilterList = [];
if ($isAdminKas) {
    try {
        $operatorFilterList = $pdo->query("SELECT user_id, COALESCE(NULLIF(operator,''), CONCAT('User #', user_id)) AS operator FROM kas_harian GROUP BY user_id, operator ORDER BY operator ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $operatorFilterList = [];
    }
}

$history = [];
$totalHistory = 0;
$totalPages = 1;
try {
    $where = [];
    $params = [];

    if ($isAdminKas) {
        // Admin melihat semua riwayat buka/tutup kas dari seluruh operator.
        if ($filterUserId > 0) {
            $where[] = "user_id = :filter_user_id";
            $params[':filter_user_id'] = $filterUserId;
        }
    } else {
        // Kasir/operator hanya melihat riwayat kas miliknya sendiri.
        $where[] = "user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    if ($filterStart !== '') {
        $where[] = "tanggal >= :start";
        $params[':start'] = $filterStart;
    }
    if ($filterEnd !== '') {
        $where[] = "tanggal <= :end";
        $params[':end'] = $filterEnd;
    }
    if ($filterStatus !== '') {
        $where[] = "status = :status";
        $params[':status'] = $filterStatus;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stCount = $pdo->prepare("SELECT COUNT(*) FROM kas_harian $whereSql");
    foreach ($params as $k => $v) $stCount->bindValue($k, $v);
    $stCount->execute();
    $totalHistory = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalHistory / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $st = $pdo->prepare("SELECT * FROM kas_harian $whereSql ORDER BY tanggal DESC, id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $history = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $history = [];
    $totalHistory = 0;
    $totalPages = 1;
}

$paginationBaseParams = $_GET;
unset($paginationBaseParams['page']);
$paginationBaseUrl = 'kas_harian.php?' . http_build_query($paginationBaseParams);
$paginationBaseUrl .= $paginationBaseParams ? '&' : '';
$historyStartNo = $totalHistory > 0 ? ($offset + 1) : 0;
$historyEndNo = min($offset + count($history), $totalHistory);

// ── Data untuk cetak Bluetooth (format sama dengan struk.php) ──────────────
$kasReceiptData = [
    'operator'       => strtoupper((string)($shiftLast['operator'] ?? $operatorName)),
    'tanggal'        => tanggal_id(($shiftLast['closed_at'] ?? '') ?: date('Y-m-d H:i:s')),
    'status'         => $shiftAktif ? 'KAS BUKA' : strtoupper((string)($shiftLast['status'] ?? '-')),
    'kas_awal'       => (int)($shiftLast['kas_awal'] ?? 0),
    'total_sales'    => (int)$salesNow['total_sales'],
    'kas_akhir'      => (int)$kasAkhirNow,
    'kas_aktual'     => (int)$kasAktualNow,
    'total_tunai'    => (int)$salesNow['total_tunai'],
    'total_nontunai' => (int)$salesNow['total_nontunai'],
    'selisih'        => (int)$selisihNow,
    'margin'         => (int)$salesNow['margin'],
    'fee_promosi'    => (int)$salesNow['fee_promosi'],
    'total_struk'    => (int)$salesNow['total_struk'],
];

$rightActionHtml = '
<div class="flex items-center gap-2">
    <button onclick="cetakBluetooth()" id="btnBluetoothKas" class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest bg-black text-white hover:bg-gray-800 transition-all">
        <span>Print</span>
    </button>
</div>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buka & Tutup Kas</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #1a1a1a
        }

        .border-subtle {
            border-color: #f0f0f0
        }

        input:focus,
        textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #111 !important
        }

        .box {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0;
            box-shadow: none
        }

        .btn {
            border-radius: 0 !important
        }

        .row-clickable {
            cursor: pointer;
            transition: background-color .15s ease;
        }

        .row-clickable:hover {
            background-color: #fafafa;
        }

        .receipt {
            font-family: 'Courier New', monospace;
            width: 80mm;
            max-width: 100%;
            background: #fff;
            color: #111;
            padding: 10px 12px;
            font-size: 12px;
            line-height: 1.2
        }

        .receipt .center {
            text-align: center
        }

        .receipt .row {
            display: flex;
            justify-content: space-between;
            gap: 8px
        }

        .receipt .label {
            white-space: nowrap
        }

        .receipt .value {
            text-align: right;
            white-space: nowrap
        }

        .receipt .line {
            border-top: 1px dashed #111;
            margin: 8px 0
        }

        .receipt-title {
            font-weight: bold;
            letter-spacing: .04em
        }

        /* ── Status bar Bluetooth ── */
        #kasStatusBar {
            display: none;
            padding: 8px 14px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            font-family: Arial, sans-serif;
        }

        #kasStatusBar.info {
            background: #dbeafe;
            color: #1e40af;
            display: block;
        }

        #kasStatusBar.success {
            background: #dcfce7;
            color: #166534;
            display: block;
        }

        #kasStatusBar.error {
            background: #fee2e2;
            color: #991b1b;
            display: block;
        }

        #kasHttpsNotice {
            display: none;
            margin: 10px 0 0;
            padding: 9px 11px;
            background: #fffbeb;
            border: 1px dashed #d97706;
            border-radius: 3px;
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #92400e;
            line-height: 1.6;
        }

        #kasHttpsNotice strong {
            display: block;
            margin-bottom: 3px;
            font-size: 10.5px;
        }

        #kasHttpsNotice code {
            background: #fef3c7;
            padding: 1px 3px;
            border-radius: 2px;
            font-size: 9.5px;
        }

        /* ── Modal Detail Riwayat Kas ── */
        #kasDetailOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 60;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        #kasDetailOverlay.open {
            display: flex;
        }

        #kasDetailModal {
            background: #fff;
            width: 100%;
            max-width: 420px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid #eee;
        }

        #kasDetailModal .modal-row {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12.5px;
        }

        #kasDetailModal .modal-row span:first-child {
            color: #888;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .04em;
        }

        #kasDetailModal .modal-row span:last-child {
            font-weight: 800;
            text-align: right;
        }



        /* ── Responsive Kas Harian ───────────────────────────── */
        .kas-history-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0;
            padding: 14px;
        }

        .kas-history-card:active {
            background: #fafafa;
        }

        .kas-history-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }

        .kas-history-row:last-child {
            border-bottom: 0;
        }

        .kas-history-label {
            color: #9ca3af;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
        }

        .kas-history-value {
            color: #111827;
            font-weight: 800;
            text-align: right;
            word-break: break-word;
        }

        .kas-history-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        @media (max-width: 1279px) {
            .receipt-wrap {
                order: -1;
            }

            .receipt {
                width: 100%;
                max-width: 80mm;
            }
        }

        @media (max-width: 640px) {
            .kas-main {
                padding-left: 14px !important;
                padding-right: 14px !important;
            }

            .box {
                border-left: 1px solid #f0f0f0;
                border-right: 1px solid #f0f0f0;
            }

            .receipt-wrap {
                padding: 12px !important;
            }

            .receipt {
                font-size: 11px;
                padding: 8px 10px;
            }

            #kasDetailModal {
                max-width: 100%;
                max-height: 88vh;
            }

            #kasDetailModal .modal-row {
                align-items: flex-start;
                gap: 12px;
            }
        }

        @media (min-width:1024px) {

            .kas-main,
            .app-header,
            .page-header {
                margin-left: 220px
            }
        }

        @media (max-width:1023px) {
            body {
                padding-bottom: 76px
            }

            .kas-main {
                padding-bottom: 5.5rem !important
            }
        }


        .kas-filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
        }

        .kas-filter-label {
            display: block;
            margin-bottom: 6px;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #9ca3af;
        }

        .kas-filter-input {
            width: 100%;
            background: #fafafa;
            border: 1px solid #eee;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 0;
        }

        .kas-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-top: 1px solid #f0f0f0;
            background: #fff;
        }

        .kas-page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #eee;
            background: #fff;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .kas-page-link.active {
            background: #000;
            color: #fff;
            border-color: #000;
        }

        .kas-page-link.disabled {
            opacity: .35;
            pointer-events: none;
        }

        @media (max-width:1023px) {
            .kas-filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width:640px) {
            .kas-filter-grid {
                grid-template-columns: 1fr;
            }

            .kas-pagination {
                align-items: stretch;
                flex-direction: column;
            }

            .kas-page-link {
                height: 38px;
            }
        }



        /* ── Tema konsisten seperti Produk / Diskon / Stok Opname ───────────── */
        .kas-main .summary-card,
        .kas-main .filter-card,
        .kas-main .table-card,
        .kas-main .kas-action-card,
        .kas-main .kas-history-card,
        .kas-main .receipt-wrap {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .kas-main input,
        .kas-main select,
        .kas-main textarea,
        .kas-main button,
        .kas-main a {
            border-radius: 0 !important;
        }

        .kas-main .filter-card {
            padding: 1rem;
        }

        .kas-main .table-card {
            overflow: hidden;
        }

        .kas-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }

        @media (min-width: 768px) {
            .kas-summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }
        }

        .kas-mobile-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
            padding: .75rem;
        }

        @media (min-width: 768px) and (max-width: 1023px) {
            .kas-mobile-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                padding: 1rem;
            }
        }

        .kas-history-card {
            transition: border-color .15s ease, background .15s ease;
        }

        .kas-history-card:hover {
            border-color: #e5e7eb;
            background: #fff;
        }

        .kas-filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: .75rem;
        }

        @media (min-width: 640px) {
            .kas-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .kas-filter-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
        }

        .kas-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .10em;
        }

        @media (max-width: 640px) {
            .kas-main {
                padding: .75rem !important;
                padding-bottom: 6rem !important;
            }

            .kas-action-btn {
                width: 100%;
            }

            .kas-main .summary-card {
                padding: .9rem !important;
            }

            .kas-main .summary-card p:nth-child(2) {
                font-size: 1rem !important;
                line-height: 1.35;
                word-break: break-word;
            }
        }



        /* === Product-page exact spacing/theme for Kas Harian === */
        .kas-main {
            background-color: #fcfcfc;
        }

        .kas-main .summary-card {
            min-height: 112px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .kas-main .summary-card p:first-child {
            margin-bottom: 4px;
        }

        .kas-action-card {
            padding: 16px !important;
            margin-bottom: 16px;
        }

        .kas-action-card h2 {
            font-size: 10px !important;
            color: #9ca3af;
            margin-bottom: 14px !important;
        }

        .kas-action-card input,
        .kas-action-card textarea {
            background: #f9fafb !important;
            border: 1px solid #f3f4f6 !important;
            font-size: 14px !important;
            padding: 10px 12px !important;
        }

        .kas-action-card button,
        .kas-action-btn {
            height: 40px;
            padding: 0 14px !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px !important;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .kas-main .filter-card {
            margin-bottom: 16px;
            padding: 16px !important;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .kas-main .filter-card {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
            }
        }

        .kas-filter-grid {
            flex: 1 1 auto;
            display: grid !important;
            grid-template-columns: minmax(180px, 1.2fr) minmax(180px, 1.2fr) minmax(140px, .85fr) minmax(150px, .9fr) minmax(130px, .75fr);
            gap: 12px;
            align-items: end;
        }

        .kas-filter-label {
            display: none !important;
        }

        .kas-filter-input {
            height: 40px;
            width: 100%;
            background: #f9fafb !important;
            border: 1px solid #f3f4f6 !important;
            padding: 0 12px !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            color: #64748b;
            text-transform: uppercase;
        }

        .kas-filter-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-top: 0 !important;
        }

        .kas-result-count {
            font-size: 12px;
            color: #9ca3af;
            font-weight: 500;
            white-space: nowrap;
            margin-left: auto;
        }

        .kas-main .table-card {
            margin-top: 0;
        }

        .kas-table-titlebar {
            display: none !important;
        }

        .kas-main table thead th {
            padding: 18px 20px !important;
            font-size: 10px !important;
            color: #9ca3af !important;
            letter-spacing: .08em;
        }

        .kas-main table tbody td {
            padding: 18px 20px !important;
        }

        .kas-main table {
            min-width: 900px !important;
        }

        .kas-pagination {
            background: #fff !important;
            padding: 16px !important;
        }

        @media (max-width: 1023px) {
            .kas-filter-grid {
                grid-template-columns: 1fr 1fr !important;
            }

            .kas-filter-actions {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .kas-result-count {
                width: 100%;
                margin-left: 0;
            }
        }

        @media (max-width: 640px) {
            .kas-filter-grid {
                grid-template-columns: 1fr !important;
            }

            .kas-filter-actions {
                grid-template-columns: 1fr;
            }

            .kas-main .summary-card {
                min-height: 104px;
            }
        }

        @media print {
            body {
                background: #fff !important;
                padding: 0 !important
            }

            .kas-main {
                margin: 0 !important;
                padding: 0 !important
            }

            .no-print,
            aside,
            nav,
            .app-header,
            .page-header,
            .sidebar,
            .navbar {
                display: none !important
            }

            .print-area {
                display: block !important
            }

            .receipt {
                width: 80mm !important;
                margin: 0 !important;
                padding: 0 4mm !important;
                font-size: 12px !important;
                box-shadow: none !important;
                border: 0 !important
            }

            .receipt-wrap {
                border: 0 !important;
                padding: 0 !important;
                margin: 0 !important
            }

            .screen-only {
                display: none !important
            }

            @page {
                size: 80mm auto;
                margin: 4mm
            }
        }
    </style>
</head>

<body class="antialiased bg-[#fcfcfc] min-h-screen pb-20 lg:pb-0">
    <?php if (is_file(__DIR__ . '/sidebar.php')) require_once 'sidebar.php'; ?>
    <?php if (is_file(__DIR__ . '/navbar.php')) require_once 'navbar.php'; ?>

    <div id="kasStatusBar" class="no-print"></div>

    <main class="kas-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if (!empty($_SESSION['kas_warning'])): ?>
            <div class="no-print mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold uppercase tracking-widest">
                <?php echo e($_SESSION['kas_warning']); ?>
            </div>
            <?php unset($_SESSION['kas_warning']); ?>
        <?php endif; ?>


        <div id="kasHttpsNotice" class="no-print">
            <strong>&#9888; Web Bluetooth butuh HTTPS / localhost</strong>
            Aktifkan di Chrome Android:<br>
            Buka <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>
            Isi: <code>http://<?php echo e($_SERVER['HTTP_HOST'] ?? '192.168.x.x'); ?></code> &#8594; Enable &#8594; Relaunch
        </div>

        <?php if ($shiftAktif && date('Y-m-d', strtotime((string)$shiftAktif['opened_at'])) !== $today): ?>
            <div class="no-print mb-4 border <?php echo $shiftOverdue ? 'border-red-200 bg-red-50 text-red-700' : 'border-yellow-200 bg-yellow-50 text-yellow-700'; ?> px-4 py-3">
                <p class="text-[10px] font-black uppercase tracking-widest"><?php echo $shiftOverdue ? 'Terlambat Tutup Kas' : 'Sesi Kas Belum Ditutup'; ?></p>
                <p class="text-xs font-bold mt-1">Dibuka <?php echo e(tanggal_id($shiftAktif['opened_at'])); ?> · berjalan <?php echo e($durasiShiftNow); ?>.</p>
                <p class="text-[10px] mt-1">Selesaikan tutup kas lama sebelum membuka sesi baru.</p>
            </div>
        <?php endif; ?>

        <div class="no-print grid grid-cols-2 md:grid-cols-5 gap-3 md:gap-4 mb-6 md:mb-8">
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Status</p>
                <p class="text-2xl font-bold <?php echo $shiftOverdue ? 'text-red-600' : ($shiftAktif ? 'text-green-600' : 'text-gray-900'); ?>"><?php echo $shiftOverdue ? 'Terlambat Tutup' : ($shiftAktif ? 'Kas Buka' : 'Kas Tutup'); ?></p>
            </div>
            <div class="summary-card p-4 md:p-5 <?php echo $shiftOverdue ? 'border-red-200' : ''; ?>">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Lama Sesi</p>
                <p class="text-lg font-bold <?php echo $shiftOverdue ? 'text-red-600' : 'text-gray-900'; ?>"><?php echo e($durasiShiftNow); ?></p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Kas Awal</p>
                <p class="text-2xl font-bold"><?php echo rupiah($shiftLast['kas_awal'] ?? 0); ?></p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Total Sales</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo rupiah($salesNow['total_sales']); ?></p>
            </div>
            <div class="summary-card p-4 md:p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Kas Akhir</p>
                <p class="text-2xl font-bold"><?php echo rupiah($kasAkhirNow); ?></p>
            </div>
        </div>

        <div class="no-print space-y-6">
            <?php if (!$shiftAktif): ?>
                <div class="kas-action-card p-5 md:p-7">
                    <h2 class="text-sm font-black uppercase tracking-widest mb-5">Buka Kas Hari Ini</h2>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kas Awal</label>
                    <input type="text" id="kas-awal" placeholder="Contoh: 431000" inputmode="numeric" class="w-full bg-gray-50 border border-gray-200 px-4 py-3 text-lg font-black">
                    <button onclick="bukaKas()" class="btn mt-4 w-full py-3 bg-black text-white text-xs font-black uppercase tracking-widest">Buka Kas</button>
                </div>
            <?php else: ?>
                <div class="kas-action-card p-5 md:p-7">
                    <h2 class="text-sm font-black uppercase tracking-widest mb-5">Tutup Kas Hari Ini</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="bg-gray-50 border border-subtle p-4">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Kas Akhir Sistem</p>
                            <p class="text-2xl font-black mt-1"><?php echo rupiah($kasAkhirNow); ?></p>
                        </div>
                        <div class="bg-gray-50 border border-subtle p-4">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Tunai</p>
                            <p class="text-2xl font-black mt-1"><?php echo rupiah($salesNow['total_tunai']); ?></p>
                        </div>
                    </div>
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5">Kas Aktual di Laci</label>
                    <input type="text" id="kas-aktual" value="<?php echo number_format($kasAkhirNow, 0, ',', '.'); ?>" inputmode="numeric" class="w-full bg-gray-50 border border-gray-200 px-4 py-3 text-lg font-black">
                    <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1.5 mt-4">Catatan</label>
                    <textarea id="catatan" rows="3" class="w-full bg-gray-50 border border-gray-200 px-4 py-3 text-sm" placeholder="Opsional"></textarea>
                    <button onclick="tutupKas()" class="btn mt-4 w-full py-3 bg-black text-white text-xs font-black uppercase tracking-widest">Tutup Kas & Print</button>
                </div>
            <?php endif; ?>

            <div class="table-card overflow-hidden">
                <div class="kas-table-titlebar p-4 sm:p-5 border-b border-subtle flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-black uppercase tracking-widest"><?php echo $isAdminKas ? 'Riwayat Kas Semua User' : 'Riwayat Kas'; ?></h2>
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">
                            <?php echo $totalHistory > 0 ? 'Menampilkan ' . number_format($historyStartNo) . '-' . number_format($historyEndNo) . ' dari ' . number_format($totalHistory) . ' data' : 'Belum ada data sesuai filter'; ?>
                        </p>
                    </div>
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Klik data untuk detail / print</span>
                </div>

                <form method="get" class="filter-card p-4 sm:p-5 border-b border-subtle bg-white no-print">
                    <div class="kas-filter-grid">
                        <div>
                            <label class="kas-filter-label">Tanggal Awal</label>
                            <input type="date" name="start" value="<?php echo e($filterStart); ?>" class="kas-filter-input">
                        </div>
                        <div>
                            <label class="kas-filter-label">Tanggal Akhir</label>
                            <input type="date" name="end" value="<?php echo e($filterEnd); ?>" class="kas-filter-input">
                        </div>
                        <div>
                            <label class="kas-filter-label">Status</label>
                            <select name="status" class="kas-filter-input">
                                <option value="">Semua Status</option>
                                <option value="buka" <?php echo $filterStatus === 'buka' ? 'selected' : ''; ?>>Buka</option>
                                <option value="tutup" <?php echo $filterStatus === 'tutup' ? 'selected' : ''; ?>>Tutup</option>
                            </select>
                        </div>
                        <?php if ($isAdminKas): ?>
                            <div>
                                <label class="kas-filter-label">Operator</label>
                                <select name="operator" class="kas-filter-input">
                                    <option value="0">Semua Operator</option>
                                    <?php foreach ($operatorFilterList as $op): ?>
                                        <?php $opUserId = (int)($op['user_id'] ?? 0); ?>
                                        <option value="<?php echo $opUserId; ?>" <?php echo $filterUserId === $opUserId ? 'selected' : ''; ?>><?php echo e($op['operator'] ?? ('User #' . $opUserId)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div>
                            <label class="kas-filter-label">Per Halaman</label>
                            <select name="per_page" class="kas-filter-input">
                                <?php foreach ([10, 20, 50, 100] as $pp): ?>
                                    <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?> Data</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="kas-filter-actions">
                        <button type="submit" class="kas-action-btn bg-black text-white hover:bg-gray-800 transition-all">Terapkan</button>
                        <a href="kas_harian.php" class="kas-action-btn border border-subtle bg-white text-gray-600 hover:bg-gray-50 transition-all">Reset</a>
                        <span class="kas-result-count hidden sm:block"><?php echo number_format($totalHistory); ?> data ditemukan</span>
                    </div>
                </form>

                <!-- Desktop: tabel lengkap -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-left min-w-[760px]">
                        <thead class="bg-gray-50 border-b border-subtle">
                            <tr>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Tanggal</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Operator</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Kas Awal</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Sales</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Kas Akhir</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                                <th class="px-4 py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f5f5f5]">
                            <?php foreach ($history as $h): ?>
                                <tr class="row-clickable" onclick="bukaDetailKas(<?php echo (int)$h['id']; ?>)">
                                    <td class="px-4 py-3 text-xs font-bold"><?php echo e(date('d/m/Y', strtotime($h['tanggal']))); ?></td>
                                    <td class="px-4 py-3 text-xs"><?php echo e($h['operator']); ?></td>
                                    <td class="px-4 py-3 text-xs text-right"><?php echo rupiah($h['kas_awal']); ?></td>
                                    <td class="px-4 py-3 text-xs text-right"><?php echo rupiah($h['total_sales']); ?></td>
                                    <td class="px-4 py-3 text-xs text-right"><?php echo rupiah($h['kas_akhir_sistem']); ?></td>
                                    <td class="px-4 py-3 text-center"><?php $rowOverdue = (($h['status'] ?? '') === 'buka' && !empty($h['opened_at']) && date('Y-m-d', strtotime((string)$h['opened_at'])) < $today); ?>
                                        <span class="text-[9px] font-black uppercase px-2 py-1 <?php echo $rowOverdue ? 'bg-red-50 text-red-700' : (($h['status'] ?? '') === 'buka' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700'); ?>"><?php echo $rowOverdue ? 'Terlambat Tutup' : e($h['status']); ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button type="button" onclick="event.stopPropagation(); printKasRiwayat(<?php echo (int)$h['id']; ?>)" class="btn px-3 py-2 bg-black text-white text-[9px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">
                                            <?php echo $h['status'] === 'buka' ? 'Print Sementara' : 'Print'; ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach;
                            if (empty($history)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-xs text-gray-400 font-bold uppercase tracking-widest">Belum ada riwayat</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Tablet & Mobile: card list agar tidak melebar -->
                <div class="lg:hidden kas-mobile-list">
                    <?php foreach ($history as $h): ?>
                        <div class="kas-history-card" onclick="bukaDetailKas(<?php echo (int)$h['id']; ?>)">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-gray-900"><?php echo e(date('d/m/Y', strtotime($h['tanggal']))); ?></p>
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5 truncate"><?php echo e($h['operator']); ?></p>
                                </div>
                                <span class="shrink-0 text-[9px] font-black uppercase px-2 py-1 <?php echo $h['status'] === 'buka' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700'; ?>"><?php echo e($h['status']); ?></span>
                            </div>

                            <div class="kas-history-row">
                                <span class="kas-history-label">Kas Awal</span>
                                <span class="kas-history-value"><?php echo rupiah($h['kas_awal']); ?></span>
                            </div>
                            <div class="kas-history-row">
                                <span class="kas-history-label">Sales</span>
                                <span class="kas-history-value text-blue-600"><?php echo rupiah($h['total_sales']); ?></span>
                            </div>
                            <div class="kas-history-row">
                                <span class="kas-history-label">Kas Akhir</span>
                                <span class="kas-history-value"><?php echo rupiah($h['kas_akhir_sistem']); ?></span>
                            </div>

                            <div class="kas-history-actions">
                                <button type="button" onclick="event.stopPropagation(); bukaDetailKas(<?php echo (int)$h['id']; ?>)" class="btn py-2 border border-subtle bg-white text-gray-700 text-[9px] font-black uppercase tracking-widest">
                                    Detail
                                </button>
                                <button type="button" onclick="event.stopPropagation(); printKasRiwayat(<?php echo (int)$h['id']; ?>)" class="btn py-2 bg-black text-white text-[9px] font-black uppercase tracking-widest">
                                    <?php echo $h['status'] === 'buka' ? 'Print Sementara' : 'Print'; ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach;
                    if (empty($history)): ?>
                        <div class="px-4 py-10 text-center text-xs text-gray-400 font-bold uppercase tracking-widest">Belum ada riwayat</div>
                    <?php endif; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="kas-pagination no-print">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            Halaman <?php echo number_format($page); ?> dari <?php echo number_format($totalPages); ?>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <a class="kas-page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($paginationBaseUrl . 'page=' . max(1, $page - 1)); ?>">Prev</a>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            if ($startPage > 1): ?>
                                <a class="kas-page-link" href="<?php echo e($paginationBaseUrl . 'page=1'); ?>">1</a>
                                <?php if ($startPage > 2): ?><span class="kas-page-link disabled">...</span><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a class="kas-page-link <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo e($paginationBaseUrl . 'page=' . $i); ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><span class="kas-page-link disabled">...</span><?php endif; ?>
                                <a class="kas-page-link" href="<?php echo e($paginationBaseUrl . 'page=' . $totalPages); ?>"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                            <a class="kas-page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo e($paginationBaseUrl . 'page=' . min($totalPages, $page + 1)); ?>">Next</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="receipt-wrap hidden print-area">
            <div class="receipt mx-auto" id="receipt">
                <div class="center receipt-title">[COPY]</div>
                <div>Laporan Shift</div>
                <div>Tanggal</div>
                <div><?php echo tanggal_id(($shiftLast['closed_at'] ?? '') ?: date('Y-m-d H:i:s')); ?></div>
                <br>
                <div class="row"><span class="label">Operator</span><span class="value">: <?php echo e(strtoupper($shiftLast['operator'] ?? $operatorName)); ?></span></div>
                <div class="row"><span class="label">Kas Awal</span><span class="value">: <?php echo rupiah($shiftLast['kas_awal'] ?? 0); ?></span></div>
                <div class="row"><span class="label">Total Sales</span><span class="value">: <?php echo rupiah($salesNow['total_sales']); ?></span></div>
                <div class="row"><span class="label">Kas Akhir</span><span class="value">: <?php echo rupiah($kasAkhirNow); ?></span></div>
                <div class="row"><span class="label">Kas Aktual</span><span class="value">: <?php echo rupiah($kasAktualNow); ?></span></div>
                <div class="row"><span class="label">TUNAI</span><span class="value">: <?php echo rupiah($salesNow['total_tunai']); ?></span></div>
                <div class="row"><span class="label">NON-TUNAI</span><span class="value">: <?php echo rupiah($salesNow['total_nontunai']); ?></span></div>
                <div class="row"><span class="label">Selisih</span><span class="value">: <?php echo ($selisihNow >= 0 ? '+' : '-') . rupiah(abs($selisihNow)); ?></span></div>
                <div class="row"><span class="label">Margin</span><span class="value">: <?php echo rupiah($salesNow['margin']); ?></span></div>
                <div class="row"><span class="label">Fee Promosi</span><span class="value">: <?php echo rupiah($salesNow['fee_promosi']); ?></span></div>
                <div class="row"><span class="label">Total Struk</span><span class="value">: <?php echo number_format((int)$salesNow['total_struk'], 0, ',', '.'); ?></span></div>
                <br><br>
                <div><?php echo e(strtoupper('TMI KOPERASI KONSUMEN PRIMER BSDK SEJAHTERA')); ?></div>
            </div>
        </div>
        </div>
    </main>

    <!-- ── Modal Detail Riwayat Kas ── -->
    <div id="kasDetailOverlay" class="no-print" onclick="if(event.target===this) tutupDetailKas()">
        <div id="kasDetailModal">
            <div class="p-5 border-b border-subtle flex items-center justify-between">
                <h3 class="text-sm font-black uppercase tracking-widest">Detail Kas</h3>
                <button onclick="tutupDetailKas()" class="text-gray-400 hover:text-black text-xl leading-none font-black">&times;</button>
            </div>
            <div class="p-5" id="kasDetailBody">
                <p class="text-xs text-gray-400 text-center py-6">Memuat...</p>
            </div>
        </div>
    </div>

    <script>
        function angka(v) {
            return String(v || '').replace(/[^0-9]/g, '');
        }

        function formatInput(el) {
            el.value = angka(el.value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        ['kas-awal', 'kas-aktual'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function() {
                    formatInput(this);
                });
            }
        });

        function postAction(action, body) {
            return fetch('kas_harian.php?action=' + action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body || {})
            }).then(function(r) {
                return r.json();
            });
        }

        function bukaKas() {
            var el = document.getElementById('kas-awal');
            var val = angka(el ? el.value : '');
            if (val === '') {
                alert('Kas awal wajib diisi.');
                return;
            }
            postAction('buka', {
                kas_awal: val
            }).then(function(d) {
                alert(d.message || 'Selesai');
                if (d.success) location.reload();
            }).catch(function() {
                alert('Gagal membuka kas.');
            });
        }

        function tutupKas() {
            var el = document.getElementById('kas-aktual');
            var val = angka(el ? el.value : '');
            if (val === '') {
                alert('Kas aktual wajib diisi.');
                return;
            }
            if (!confirm('Tutup kas hari ini?')) return;
            postAction('tutup', {
                kas_aktual: val,
                catatan: (document.getElementById('catatan') || {}).value || ''
            }).then(function(d) {
                alert(d.message || 'Selesai');
                if (!d.success) return;

                // Ambil data terbaru hasil tutup kas (kas_aktual, total sales final, dll)
                // supaya struk yang dicetak bukan data lama dari sebelum kas ditutup.
                return postAction('detail', {
                    id: d.id
                }).then(function(detail) {
                    setKasReceiptFromDetail(detail);
                    // Print dulu via Bluetooth, baru reload setelah proses cetak selesai/gagal.
                    return cetakBluetooth().finally(function() {
                        location.reload();
                    });
                });
            }).catch(function() {
                alert('Gagal menutup kas.');
            });
        }

        function rupiahJs(n) {
            n = Number(n || 0);
            return 'Rp' + n.toLocaleString('id-ID', {
                maximumFractionDigits: 0
            });
        }

        function tglIdJs(str) {
            if (!str) return '-';
            var d = new Date(str.replace(' ', 'T'));
            if (isNaN(d.getTime())) return str;
            return d.toLocaleDateString('id-ID', {
                weekday: 'long',
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            }) + ' ' + d.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function durasiKasJs(openedAt, closedAt) {
            if (!openedAt) return '-';
            var start = new Date(String(openedAt).replace(' ', 'T'));
            var end = closedAt ? new Date(String(closedAt).replace(' ', 'T')) : new Date();
            if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return '-';
            var totalMinutes = Math.floor((end - start) / 60000);
            var days = Math.floor(totalMinutes / 1440);
            var hours = Math.floor((totalMinutes % 1440) / 60);
            var minutes = totalMinutes % 60;
            var parts = [];
            if (days > 0) parts.push(days + ' hari');
            if (hours > 0 || days > 0) parts.push(hours + ' jam');
            parts.push(minutes + ' menit');
            return parts.join(' ');
        }

        function setKasReceiptFromDetail(detail) {
            if (!detail || !detail.success || !detail.data) return false;
            var r = detail.data,
                s = detail.sales || {};
            KAS_RECEIPT = {
                operator: String(r.operator || '').toUpperCase(),
                tanggal: tglIdJs(r.closed_at || r.opened_at),
                status: String(r.status || '').toUpperCase(),
                kas_awal: Math.round(Number(r.kas_awal || 0)),
                total_sales: Math.round(Number(s.total_sales || 0)),
                kas_akhir: Math.round(Number(r.kas_akhir_sistem || 0)),
                kas_aktual: Math.round(Number(r.kas_aktual_display || 0)),
                total_tunai: Math.round(Number(s.total_tunai || 0)),
                total_nontunai: Math.round(Number(s.total_nontunai || 0)),
                selisih: Math.round(Number(r.selisih || 0)),
                margin: Math.round(Number(s.margin || 0)),
                fee_promosi: Math.round(Number(s.fee_promosi || 0)),
                total_struk: Math.round(Number(s.total_struk || 0))
            };
            return true;
        }

        function printKasRiwayat(id) {
            postAction('detail', {
                id: id
            }).then(function(detail) {
                if (!setKasReceiptFromDetail(detail)) {
                    alert((detail && detail.message) || 'Data kas tidak ditemukan.');
                    return;
                }
                return cetakBluetooth();
            }).catch(function() {
                alert('Gagal memuat data kas untuk print.');
            });
        }

        function bukaDetailKas(id) {
            var overlay = document.getElementById('kasDetailOverlay');
            var body = document.getElementById('kasDetailBody');
            body.innerHTML = '<p class="text-xs text-gray-400 text-center py-6">Memuat...</p>';
            overlay.classList.add('open');

            postAction('detail', {
                id: id
            }).then(function(d) {
                if (!d.success || !d.data) {
                    body.innerHTML = '<p class="text-xs text-red-500 text-center py-6">' + (d.message || 'Data tidak ditemukan.') + '</p>';
                    return;
                }
                var r = d.data,
                    s = d.sales;
                var selisih = Number(r.selisih || 0);
                var rows = [
                    ['Tanggal', tglIdJs(r.tanggal + ' 00:00:00').split(' ').slice(0, 4).join(' ')],
                    ['Operator', r.operator || '-'],
                    ['Status', (r.status || '-').toUpperCase()],
                    ['Dibuka', tglIdJs(r.opened_at)],
                    ['Ditutup', r.closed_at ? tglIdJs(r.closed_at) : '-'],
                    ['Durasi Sesi', durasiKasJs(r.opened_at, r.closed_at)],
                    ['Kas Awal', rupiahJs(r.kas_awal)],
                    ['Total Sales', rupiahJs(s.total_sales)],
                    ['Total Tunai', rupiahJs(s.total_tunai)],
                    ['Total Non-Tunai', rupiahJs(s.total_nontunai)],
                    ['Kas Akhir Sistem', rupiahJs(r.kas_akhir_sistem)],
                    ['Kas Aktual', rupiahJs(r.kas_aktual_display)],
                    ['Selisih', (selisih >= 0 ? '+' : '-') + rupiahJs(Math.abs(selisih))],
                    ['Margin', rupiahJs(s.margin)],
                    ['Fee Promosi', rupiahJs(s.fee_promosi)],
                    ['Total Struk', Number(s.total_struk || 0).toLocaleString('id-ID')],
                    ['Catatan', r.catatan ? r.catatan : '-']
                ];
                var html = rows.map(function(row) {
                    return '<div class="modal-row"><span>' + row[0] + '</span><span>' + row[1] + '</span></div>';
                }).join('');
                body.innerHTML = html;
            }).catch(function() {
                body.innerHTML = '<p class="text-xs text-red-500 text-center py-6">Gagal memuat detail.</p>';
            });
        }

        function tutupDetailKas() {
            document.getElementById('kasDetailOverlay').classList.remove('open');
        }
    </script>

    <!-- ════════════════════════════════════════════
         WEB BLUETOOTH + ESC/POS ENGINE — Laporan Kas
         Mengikuti pola yang sama dengan struk.php
         ════════════════════════════════════════════ -->
    <script>
        'use strict';

        var KAS_RECEIPT = <?php echo json_encode($kasReceiptData, JSON_UNESCAPED_UNICODE); ?>;

        var KAS_BT_CONFIG = [{
                service: '000018f0-0000-1000-8000-00805f9b34fb',
                characteristic: '00002af1-0000-1000-8000-00805f9b34fb'
            },
            {
                service: '49535343-fe7d-4ae5-8fa9-9fafd205e455',
                characteristic: '49535343-8841-43f4-a8d4-ecbe34729bb3'
            },
            {
                service: '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
                characteristic: '6e400002-b5a3-f393-e0a9-e50e24dcca9e'
            }
        ];

        var KAS_STORAGE_KEY = 'bt_printer_id';
        var KAS_ESC = 0x1B,
            KAS_GS = 0x1D;
        var KAS_CMD = {
            init: [KAS_ESC, 0x40],
            alignLeft: [KAS_ESC, 0x61, 0x00],
            alignCenter: [KAS_ESC, 0x61, 0x01],
            boldOn: [KAS_ESC, 0x45, 0x01],
            boldOff: [KAS_ESC, 0x45, 0x00],
            fontBig: [KAS_GS, 0x21, 0x11],
            fontNormal: [KAS_GS, 0x21, 0x00],
            feed: function(n) {
                return [KAS_ESC, 0x64, n];
            },
            cut: [KAS_GS, 0x56, 0x41, 0x03]
        };
        var KAS_W = 32;

        function kasFmt(n) {
            return Number(n || 0).toLocaleString('id-ID');
        }

        function kasLr(l, r, w) {
            var width = w || KAS_W,
                ls = String(l),
                rs = String(r);
            var sp = Math.max(1, width - ls.length - rs.length);
            return ls + ' '.repeat(sp) + rs;
        }

        function kasDash() {
            return '-'.repeat(KAS_W);
        }

        function kasSolid() {
            return '='.repeat(KAS_W);
        }

        function kasEnc(str) {
            var out = [];
            for (var i = 0; i < str.length; i++) {
                var c = str.charCodeAt(i);
                out.push(c < 256 ? c : 0x3F);
            }
            return out;
        }

        function buildKasEscPos() {
            var buf = [];

            function push(a) {
                for (var i = 0; i < a.length; i++) buf.push(a[i]);
            }

            function text(s) {
                push(kasEnc(s + '\n'));
            }

            function line(s) {
                push(kasEnc(s));
            }

            var d = KAS_RECEIPT;

            push(KAS_CMD.init);

            /* HEADER */
            push(KAS_CMD.alignCenter);
            push(KAS_CMD.boldOn);
            push(KAS_CMD.fontBig);
            text('KOPERASI BSDK');
            push(KAS_CMD.fontNormal);
            push(KAS_CMD.boldOff);
            text('LAPORAN SHIFT KAS');
            push(KAS_CMD.alignLeft);

            /* INFO */
            line(kasDash() + '\n');
            line(kasLr('Tanggal', d.tanggal) + '\n');
            line(kasLr('Operator', String(d.operator).substring(0, 18)) + '\n');
            line(kasLr('Status', d.status) + '\n');
            line(kasDash() + '\n');

            /* RINGKASAN KAS */
            line(kasLr('Kas Awal', kasFmt(d.kas_awal)) + '\n');
            line(kasLr('Total Sales', kasFmt(d.total_sales)) + '\n');
            line(kasLr('  Tunai', kasFmt(d.total_tunai)) + '\n');
            line(kasLr('  Non-Tunai', kasFmt(d.total_nontunai)) + '\n');
            line(kasDash() + '\n');

            push(KAS_CMD.boldOn);
            line(kasLr('Kas Akhir (Sistem)', kasFmt(d.kas_akhir)) + '\n');
            push(KAS_CMD.boldOff);
            line(kasLr('Kas Aktual', kasFmt(d.kas_aktual)) + '\n');

            var selisihLabel = (d.selisih >= 0 ? '+' : '-') + kasFmt(Math.abs(d.selisih));
            push(KAS_CMD.boldOn);
            line(kasLr('Selisih', selisihLabel) + '\n');
            push(KAS_CMD.boldOff);

            line(kasSolid() + '\n');
            line(kasLr('Margin', kasFmt(d.margin)) + '\n');
            line(kasLr('Fee Promosi', kasFmt(d.fee_promosi)) + '\n');
            line(kasLr('Total Struk', kasFmt(d.total_struk)) + '\n');

            /* FOOTER */
            line(kasDash() + '\n');
            push(KAS_CMD.alignCenter);
            text('TMI KOPERASI KONSUMEN PRIMER');
            text('BSDK SEJAHTERA');
            push(KAS_CMD.alignLeft);
            push(KAS_CMD.feed(5));
            push(KAS_CMD.cut);

            return new Uint8Array(buf);
        }

        function kasSetStatus(msg, type) {
            var el = document.getElementById('kasStatusBar');
            if (!el) return;
            el.textContent = msg;
            el.className = 'no-print ' + (type || 'info');
            el.style.display = 'block';
            if (type === 'success') {
                setTimeout(function() {
                    el.style.display = 'none';
                    el.className = 'no-print';
                }, 3500);
            }
        }

        function kasSendData(characteristic, data) {
            var CHUNK = 100;
            var chain = Promise.resolve();
            for (var pos = 0; pos < data.length; pos += CHUNK) {
                (function(slice) {
                    chain = chain
                        .then(function() {
                            return characteristic.writeValueWithoutResponse(slice);
                        })
                        .then(function() {
                            return new Promise(function(r) {
                                setTimeout(r, 60);
                            });
                        });
                })(data.slice(pos, pos + CHUNK));
            }
            return chain;
        }

        function kasConnectAndPrint(device) {
            kasSetStatus('Menghubungkan ke ' + device.name + '...', 'info');
            return device.gatt.connect().then(function(server) {
                var tryUUID = function(idx) {
                    if (idx >= KAS_BT_CONFIG.length) return Promise.reject(new Error('UUID printer tidak cocok. Cek dengan nRF Connect.'));
                    return server.getPrimaryService(KAS_BT_CONFIG[idx].service)
                        .then(function(svc) {
                            return svc.getCharacteristic(KAS_BT_CONFIG[idx].characteristic);
                        })
                        .catch(function() {
                            return tryUUID(idx + 1);
                        });
                };
                return tryUUID(0).then(function(characteristic) {
                    kasSetStatus('Mengirim laporan...', 'info');
                    return kasSendData(characteristic, buildKasEscPos()).then(function() {
                        kasSetStatus('Laporan terkirim ke ' + device.name + '!', 'success');
                        try {
                            server.disconnect();
                        } catch (e) {}
                    });
                });
            });
        }

        function cetakBluetooth() {
            if (!navigator.bluetooth) {
                var notice = document.getElementById('kasHttpsNotice');
                if (!window.isSecureContext) {
                    if (notice) notice.style.display = 'block';
                    kasSetStatus('Web Bluetooth butuh HTTPS. Lihat panduan di bawah.', 'error');
                } else {
                    kasSetStatus('Browser tidak mendukung Bluetooth. Gunakan Chrome/Edge.', 'error');
                }
                return Promise.resolve();
            }

            var btns = [document.getElementById('btnBluetoothKas'), document.getElementById('btnBluetoothKas2')];
            btns.forEach(function(b) {
                if (b) b.disabled = true;
            });
            kasSetStatus('Mencari printer...', 'info');

            return navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: KAS_BT_CONFIG.map(function(c) {
                        return c.service;
                    })
                })
                .then(function(device) {
                    try {
                        localStorage.setItem(KAS_STORAGE_KEY, device.name || '');
                    } catch (e) {}
                    return kasConnectAndPrint(device);
                })
                .catch(function(err) {
                    if (err.name === 'NotFoundError') {
                        kasSetStatus('Tidak ada printer dipilih.', 'info');
                    } else if (err.name === 'SecurityError') {
                        var n = document.getElementById('kasHttpsNotice');
                        if (n) n.style.display = 'block';
                        kasSetStatus('Bluetooth diblokir. Lihat panduan di bawah.', 'error');
                    } else {
                        kasSetStatus('Gagal: ' + err.message, 'error');
                    }
                })
                .finally(function() {
                    btns.forEach(function(b) {
                        if (b) b.disabled = false;
                    });
                });
        }
    </script>
</body>

</html>