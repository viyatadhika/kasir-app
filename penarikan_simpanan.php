<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}
if (file_exists(__DIR__ . '/activity_helper.php')) {
    require_once __DIR__ . '/activity_helper.php';
}

$activeMenu = 'penarikan_simpanan';
$pageTitle  = 'Penarikan Simpanan';
$backUrl    = 'dashboard.php';

if (function_exists('requireAccess')) {
    requireAccess();
}

// Role aman: admin dan ksp. Jika ingin kasir boleh lihat, tambahkan 'kasir'.
$userRole = '';
if (isset($_SESSION['user']['role'])) {
    $userRole = (string)$_SESSION['user']['role'];
} elseif (isset($_SESSION['role'])) {
    $userRole = (string)$_SESSION['role'];
}
if ($userRole !== '' && !in_array($userRole, array('admin', 'ksp'), true)) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    /** @param mixed $v */
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}


if (!function_exists('json_response_ps')) {
    /**
     * @param array<string,mixed> $data
     * @param int $status
     */
    function json_response_ps(array $data, int $status = 200): void
    {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('rupiah_ps')) {
    /** @param mixed $n */
    function rupiah_ps($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_ps')) {
    /** @param mixed $n */
    function angka_ps($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_ps')) {
    /** @param mixed $v */
    function tanggal_ps($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('table_exists_ps')) {
    function table_exists_ps(PDO $pdo, string $table): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute(array($table));
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // Lanjut ke pengecekan lain. Beberapa server membatasi SHOW TABLES.
        }

        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = LOWER(?)');
            $stmt->execute(array($table));
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable $e) {
            // Lanjut ke pengecekan langsung.
        }

        try {
            $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('column_exists_ps')) {
    function column_exists_ps(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
            $stmt->execute(array($column));
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('status_badge_ps')) {
    /** @param mixed $status */
    function status_badge_ps($status): string
    {
        $s = strtolower(trim((string)$status));
        if ($s === 'disetujui' || $s === 'selesai') {
            return 'bg-green-50 text-green-700 border-green-200';
        }
        if ($s === 'ditolak' || $s === 'batal') {
            return 'bg-red-50 text-red-700 border-red-200';
        }
        if ($s === 'diproses') {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }
        return 'bg-amber-50 text-amber-700 border-amber-200';
    }
}

if (!function_exists('jenis_label_ps')) {
    /** @param mixed $jenis */
    function jenis_label_ps($jenis): string
    {
        $j = strtolower(trim((string)$jenis));
        if ($j === 'keluar') return 'Keluar Member';
        if ($j === 'pensiun') return 'Pensiun';
        return 'Sukarela';
    }
}

if (!function_exists('status_normal_ps')) {
    /** @param mixed $status */
    function status_normal_ps($status): string
    {
        $s = strtolower(trim((string)$status));
        $allowed = array('menunggu', 'diproses', 'disetujui', 'ditolak', 'selesai', 'batal');
        return in_array($s, $allowed, true) ? $s : 'menunggu';
    }
}

if (!function_exists('coa_id_by_kode_ps')) {
    function coa_id_by_kode_ps(PDO $pdo, string $kode, string $nama = '', string $kategori = '', string $subkategori = ''): int
    {
        $stmt = $pdo->prepare('SELECT id FROM coa WHERE kode = :kode LIMIT 1');
        $stmt->execute(array(':kode' => $kode));
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) return $id;

        if ($nama === '' || $kategori === '') {
            throw new Exception('Akun COA ' . $kode . ' belum tersedia.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO coa (kode, nama, kategori, subkategori, is_active, created_at)
            VALUES (:kode, :nama, :kategori, :subkategori, 1, NOW())
        ');
        $stmt->execute(array(
            ':kode' => $kode,
            ':nama' => $nama,
            ':kategori' => $kategori,
            ':subkategori' => $subkategori,
        ));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('saldo_simpanan_member_ps')) {
    /** @return array<string,float> */
    function saldo_simpanan_member_ps(PDO $pdo, int $memberId): array
    {
        $saldo = array('pokok' => 0, 'wajib' => 0, 'sukarela' => 0, 'total' => 0, 'sukarela_tersedia' => 0, 'total_penarikan' => 0);
        $stmt = $pdo->prepare('
            SELECT
                COALESCE(SUM(CASE WHEN jenis = "pokok" THEN jumlah ELSE 0 END),0) AS pokok,
                COALESCE(SUM(CASE WHEN jenis = "wajib" THEN jumlah ELSE 0 END),0) AS wajib,
                COALESCE(SUM(CASE WHEN jenis = "sukarela" THEN jumlah ELSE 0 END),0) AS sukarela,
                COALESCE(SUM(jumlah),0) AS total
            FROM simpanan
            WHERE member_id = :mid
        ');
        $stmt->execute(array(':mid' => $memberId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
        $saldo['pokok'] = (float)($row['pokok'] ?? 0);
        $saldo['wajib'] = (float)($row['wajib'] ?? 0);
        $saldo['sukarela'] = (float)($row['sukarela'] ?? 0);
        $saldo['total'] = (float)($row['total'] ?? 0);

        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(jumlah),0)
            FROM penarikan_sukarela
            WHERE member_id = :mid
              AND LOWER(TRIM(COALESCE(status,""))) IN ("disetujui", "selesai")
        ');
        $stmt->execute(array(':mid' => $memberId));
        $saldo['total_penarikan'] = (float)$stmt->fetchColumn();
        $saldo['sukarela_tersedia'] = max(0, $saldo['sukarela'] - $saldo['total_penarikan']);
        return $saldo;
    }
}

if (!function_exists('sisa_pinjaman_member_ps')) {
    function sisa_pinjaman_member_ps(PDO $pdo, int $memberId): float
    {
        if (!table_exists_ps($pdo, 'angsuran_pinjaman')) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('
                SELECT COALESCE(SUM(jumlah_total),0)
                FROM angsuran_pinjaman
                WHERE member_id = :mid
                  AND LOWER(TRIM(COALESCE(status,""))) NOT IN ("lunas", "dibayar", "bayar", "selesai")
            ');
            $stmt->execute(array(':mid' => $memberId));
            return (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('buat_jurnal_penarikan_ps')) {
    function buat_jurnal_penarikan_ps(PDO $pdo, int $penarikanId, int $memberId, string $jenis, float $jumlah, string $keterangan): void
    {
        if ($jumlah <= 0 || !table_exists_ps($pdo, 'coa') || !table_exists_ps($pdo, 'jurnal_umum') || !table_exists_ps($pdo, 'jurnal_detail')) {
            return;
        }

        $jenis = strtolower(trim((string)$jenis));
        $akunKas = coa_id_by_kode_ps($pdo, '101', 'Kas', 'aktiva', 'Aktiva Lancar');
        $akunSukarela = coa_id_by_kode_ps($pdo, '203', 'Simpanan Sukarela Member', 'kewajiban', 'Kewajiban');
        $akunPokok = coa_id_by_kode_ps($pdo, '201', 'Simpanan Pokok Member', 'kewajiban', 'Kewajiban');
        $akunWajib = coa_id_by_kode_ps($pdo, '202', 'Simpanan Wajib Member', 'kewajiban', 'Kewajiban');

        $akunDebit = $akunSukarela;
        if ($jenis === 'keluar' || $jenis === 'pensiun') {
            // Default tetap debit simpanan sukarela sesuai nominal penarikan.
            // Jika nanti ingin pencairan semua saldo, bisa dipecah ke akun 201/202/203.
            $akunDebit = $akunSukarela;
        }

        $cek = $pdo->prepare("SELECT id FROM jurnal_umum WHERE ref_tabel = 'penarikan_sukarela' AND ref_id = :rid LIMIT 1");
        $cek->execute(array(':rid' => $penarikanId));
        $jurnalId = (int)$cek->fetchColumn();

        $tanggal = date('Y-m-d');
        $ket = 'Penarikan simpanan ' . jenis_label_ps($jenis) . ' member #' . $memberId . ' - ' . $keterangan;

        if ($jurnalId > 0) {
            $pdo->prepare('DELETE FROM jurnal_detail WHERE jurnal_id = :jid')->execute(array(':jid' => $jurnalId));
            $stmt = $pdo->prepare('UPDATE jurnal_umum SET tanggal = :tanggal, keterangan = :ket WHERE id = :id LIMIT 1');
            $stmt->execute(array(':tanggal' => $tanggal, ':ket' => $ket, ':id' => $jurnalId));
        } else {
            $kode = 'JP-' . date('YmdHis') . '-' . $penarikanId;
            $stmt = $pdo->prepare('
                INSERT INTO jurnal_umum (tanggal, kode_jurnal, keterangan, ref_tabel, ref_id)
                VALUES (:tanggal, :kode, :ket, "penarikan_sukarela", :rid)
            ');
            $stmt->execute(array(':tanggal' => $tanggal, ':kode' => $kode, ':ket' => $ket, ':rid' => $penarikanId));
            $jurnalId = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO jurnal_detail (jurnal_id, coa_id, debit, kredit) VALUES (:jid, :coa, :debit, :kredit)');
        // Debit kewajiban simpanan sukarela, karena kewajiban koperasi kepada member berkurang.
        $ins->execute(array(':jid' => $jurnalId, ':coa' => $akunDebit, ':debit' => $jumlah, ':kredit' => 0));
        // Kredit kas, karena kas keluar.
        $ins->execute(array(':jid' => $jurnalId, ':coa' => $akunKas, ':debit' => 0, ':kredit' => $jumlah));
    }
}


// Endpoint AJAX untuk pencarian member dan ambil saldo langsung dari database.
if (isset($_GET['ajax'])) {
    $ajax = (string)$_GET['ajax'];

    try {
        if ($ajax === 'member_search') {
            $qAjax = trim((string)($_GET['q'] ?? ''));
            $paramsAjax = array();
            $whereAjax = '1=1';

            if ($qAjax !== '') {
                $whereAjax = '(nama LIKE :q1 OR kode LIKE :q2 OR no_hp LIKE :q3)';
                $paramsAjax[':q1'] = '%' . $qAjax . '%';
                $paramsAjax[':q2'] = '%' . $qAjax . '%';
                $paramsAjax[':q3'] = '%' . $qAjax . '%';
            }

            $stmtAjax = $pdo->prepare('SELECT id, kode, nama, no_hp, status FROM member WHERE ' . $whereAjax . ' ORDER BY nama ASC LIMIT 25');
            $stmtAjax->execute($paramsAjax);
            $items = $stmtAjax->fetchAll(PDO::FETCH_ASSOC);

            json_response_ps(array('ok' => true, 'items' => $items));
        }

        if ($ajax === 'member_saldo') {
            $memberIdAjax = (int)($_GET['member_id'] ?? 0);
            if ($memberIdAjax <= 0) {
                json_response_ps(array('ok' => false, 'message' => 'Member tidak valid.'), 422);
            }

            $stmtMemberAjax = $pdo->prepare('SELECT id, kode, nama, no_hp, status FROM member WHERE id = :id LIMIT 1');
            $stmtMemberAjax->execute(array(':id' => $memberIdAjax));
            $memberAjax = $stmtMemberAjax->fetch(PDO::FETCH_ASSOC);
            if (!$memberAjax) {
                json_response_ps(array('ok' => false, 'message' => 'Member tidak ditemukan.'), 404);
            }

            $saldoAjax = saldo_simpanan_member_ps($pdo, $memberIdAjax);
            $pinjamanAjax = sisa_pinjaman_member_ps($pdo, $memberIdAjax);

            json_response_ps(array(
                'ok' => true,
                'member' => $memberAjax,
                'saldo' => $saldoAjax,
                'pinjaman' => $pinjamanAjax,
            ));
        }

        json_response_ps(array('ok' => false, 'message' => 'Endpoint tidak dikenali.'), 404);
    } catch (Throwable $e) {
        json_response_ps(array('ok' => false, 'message' => $e->getMessage()), 500);
    }
}

$error = '';
$success = '';
if (isset($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$tablePenarikanReady = table_exists_ps($pdo, 'penarikan_sukarela');
if (!$tablePenarikanReady) {
    $error = 'Tabel penarikan_sukarela tidak terbaca dari koneksi database saat ini. Pastikan config.php mengarah ke database yang benar.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $jenis = strtolower(trim((string)($_POST['jenis'] ?? 'sukarela')));
            $jumlah = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['jumlah'] ?? '0'));
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            if ($memberId <= 0) throw new Exception('Pilih member terlebih dahulu.');
            if (!in_array($jenis, array('sukarela', 'keluar', 'pensiun'), true)) $jenis = 'sukarela';
            if ($jumlah <= 0) throw new Exception('Jumlah penarikan wajib lebih dari 0.');

            $saldo = saldo_simpanan_member_ps($pdo, $memberId);
            if ($jumlah > $saldo['sukarela_tersedia']) {
                throw new Exception('Jumlah penarikan melebihi saldo sukarela tersedia. Saldo tersedia: ' . rupiah_ps($saldo['sukarela_tersedia']));
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                INSERT INTO penarikan_sukarela (member_id, jenis, jumlah, status, catatan, created_at)
                VALUES (:mid, :jenis, :jumlah, "menunggu", :catatan, NOW())
            ');
            $stmt->execute(array(
                ':mid' => $memberId,
                ':jenis' => $jenis,
                ':jumlah' => $jumlah,
                ':catatan' => $catatan,
            ));
            $pdo->commit();

            if (function_exists('catat_aktivitas')) {
                catat_aktivitas($pdo, 'create', 'Penarikan Simpanan', 'Membuat pengajuan penarikan member #' . $memberId . ' sebesar ' . $jumlah);
            }
            $_SESSION['flash_success'] = 'Pengajuan penarikan berhasil dibuat.';
            header('Location: penarikan_simpanan.php');
            exit;
        }

        if ($action === 'approve' || $action === 'reject' || $action === 'cancel') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID penarikan tidak valid.');

            $stmt = $pdo->prepare('
                SELECT ps.*, m.nama AS member_nama
                FROM penarikan_sukarela ps
                JOIN member m ON m.id = ps.member_id
                WHERE ps.id = :id
                LIMIT 1
            ');
            $stmt->execute(array(':id' => $id));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Data penarikan tidak ditemukan.');

            $statusNow = status_normal_ps($row['status'] ?? 'menunggu');
            if (in_array($statusNow, array('disetujui', 'selesai', 'ditolak', 'batal'), true)) {
                throw new Exception('Data ini sudah diproses.');
            }

            $pdo->beginTransaction();
            if ($action === 'approve') {
                $memberId = (int)$row['member_id'];
                $jenis = (string)($row['jenis'] ?? 'sukarela');
                $jumlah = (float)$row['jumlah'];
                $saldo = saldo_simpanan_member_ps($pdo, $memberId);
                if ($jumlah > $saldo['sukarela_tersedia']) {
                    throw new Exception('Saldo sukarela tersedia tidak cukup. Saldo: ' . rupiah_ps($saldo['sukarela_tersedia']));
                }

                $stmtUp = $pdo->prepare('
                    UPDATE penarikan_sukarela
                    SET status = "disetujui", diproses_oleh = :uid, diproses_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ');
                $stmtUp->execute(array(':uid' => $userId ?: null, ':id' => $id));

                buat_jurnal_penarikan_ps($pdo, $id, $memberId, $jenis, $jumlah, (string)($row['catatan'] ?? ''));

                if ($jenis === 'keluar' || $jenis === 'pensiun') {
                    $pdo->prepare('UPDATE member SET status = "nonaktif", updated_at = NOW() WHERE id = :mid LIMIT 1')
                        ->execute(array(':mid' => $memberId));
                }

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'approve', 'Penarikan Simpanan', 'Menyetujui penarikan #' . $id . ' sebesar ' . $jumlah);
                }
                $_SESSION['flash_success'] = 'Penarikan berhasil disetujui dan jurnal dibuat.';
            } elseif ($action === 'reject') {
                $stmtUp = $pdo->prepare('
                    UPDATE penarikan_sukarela
                    SET status = "ditolak", diproses_oleh = :uid, diproses_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ');
                $stmtUp->execute(array(':uid' => $userId ?: null, ':id' => $id));
                $_SESSION['flash_success'] = 'Pengajuan penarikan ditolak.';
            } else {
                $stmtUp = $pdo->prepare('
                    UPDATE penarikan_sukarela
                    SET status = "batal", diproses_oleh = :uid, diproses_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ');
                $stmtUp->execute(array(':uid' => $userId ?: null, ':id' => $id));
                $_SESSION['flash_success'] = 'Pengajuan penarikan dibatalkan.';
            }
            $pdo->commit();
            header('Location: penarikan_simpanan.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: penarikan_simpanan.php');
        exit;
    }
}

$memberList = array();
try {
    $memberList = $pdo->query('SELECT id, kode, nama, no_hp, status FROM member ORDER BY nama ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $memberList = array();
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$jenisFilter = trim((string)($_GET['jenis'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, array(10, 25, 50, 100), true)) $perPage = 25;
$offset = ($page - 1) * $perPage;

$where = array('1=1');
$params = array();
if ($q !== '') {
    $where[] = '(m.nama LIKE :q1 OR m.kode LIKE :q2 OR m.no_hp LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($statusFilter !== '') {
    $where[] = 'LOWER(TRIM(COALESCE(ps.status,""))) = :status';
    $params[':status'] = strtolower($statusFilter);
}
if ($jenisFilter !== '') {
    $where[] = 'ps.jenis = :jenis';
    $params[':jenis'] = $jenisFilter;
}
$whereSql = implode(' AND ', $where);

$totalRows = 0;
$totalPages = 1;
$penarikanList = array();
$summary = array('total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0, 'nominal' => 0);
try {
    if ($error === '') {
        $summary = $pdo->query('
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,""))) IN ("menunggu", "diproses", "") THEN 1 ELSE 0 END) AS menunggu,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,""))) IN ("disetujui", "selesai") THEN 1 ELSE 0 END) AS disetujui,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(status,""))) IN ("ditolak", "batal") THEN 1 ELSE 0 END) AS ditolak,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status,""))) IN ("disetujui", "selesai") THEN jumlah ELSE 0 END),0) AS nominal
            FROM penarikan_sukarela
        ')->fetch(PDO::FETCH_ASSOC) ?: $summary;

        $count = $pdo->prepare('
            SELECT COUNT(*)
            FROM penarikan_sukarela ps
            JOIN member m ON m.id = ps.member_id
            WHERE ' . $whereSql . '
        ');
        $count->execute($params);
        $totalRows = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $pdo->prepare('
            SELECT ps.*, m.kode AS member_kode, m.nama AS member_nama, m.no_hp, u.nama AS diproses_nama
            FROM penarikan_sukarela ps
            JOIN member m ON m.id = ps.member_id
            LEFT JOIN users u ON u.id = ps.diproses_oleh
            WHERE ' . $whereSql . '
            ORDER BY ps.created_at DESC, ps.id DESC
            LIMIT :limit OFFSET :offset
        ');
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $penarikanList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $error = $error ?: 'Gagal memuat data penarikan: ' . $e->getMessage();
}

if (function_exists('catat_view_once')) {
    catat_view_once($pdo, 'Penarikan Simpanan', 'Membuka halaman Penarikan Simpanan');
}

$baseQuery = 'q=' . urlencode($q) . '&status=' . urlencode($statusFilter) . '&jenis=' . urlencode($jenisFilter) . '&per_page=' . urlencode((string)$perPage);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penarikan Simpanan — SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fafafa;
            color: #111
        }

        .border-subtle {
            border-color: #f0f0f0
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #111 !important;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .05)
        }

        .form-input {
            width: 100%;
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            min-height: 42px
        }

        .form-input:focus {
            background: #fff
        }

        .btn-primary,
        .btn-outline,
        .btn-danger,
        .btn-success {
            min-height: 38px;
            padding: 9px 13px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 6px;
            white-space: nowrap
        }

        .btn-primary {
            background: #111;
            color: #fff
        }

        .btn-outline {
            background: #fff;
            color: #111;
            border: 1px solid #e5e5e5
        }

        .btn-danger {
            background: #dc2626;
            color: #fff
        }

        .btn-success {
            background: #15803d;
            color: #fff
        }

        .alert {
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 18px;
            border: 1px solid
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca
        }

        .kpi {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 16px;
            border-left: 3px solid #111;
            min-width: 0
        }

        .small-muted {
            font-size: 10px;
            color: #9ca3af
        }

        .money {
            font-weight: 900;
            white-space: nowrap
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px
        }

        .data-table th {
            background: #f9f9f9;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            white-space: nowrap
        }

        .data-table td {
            padding: 12px;
            font-size: 12px;
            border-bottom: 1px solid #f7f7f7;
            vertical-align: top
        }

        .data-table tbody tr:hover {
            background: #fafafa
        }

        .mobile-list {
            display: none
        }

        .mobile-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            padding: 14px
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(0, 0, 0, .5);
            padding: 12px
        }

        .modal.open {
            display: flex
        }

        .modal-inner {
            margin: auto;
            width: min(720px, 100%);
            max-height: 92vh;
            background: #fff;
            overflow-y: auto
        }


        .member-result-box {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 240;
            max-height: 280px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e5e5e5;
            box-shadow: 0 18px 40px rgba(0, 0, 0, .12);
        }

        .member-result-item {
            width: 100%;
            display: block;
            text-align: left;
            padding: 11px 12px;
            border-bottom: 1px solid #f3f4f6;
            background: #fff;
            cursor: pointer;
        }

        .member-result-item:hover {
            background: #f9fafb;
        }

        .member-result-name {
            font-size: 13px;
            font-weight: 900;
            color: #111;
        }

        .member-result-meta {
            margin-top: 2px;
            font-size: 10px;
            font-weight: 700;
            color: #9ca3af;
        }

        @media (min-width:1024px) {

            .content,
            .main-wrap,
            .app-header,
            .page-header {
                margin-left: 220px
            }
        }

        @media (max-width:1023px) {
            body {
                padding-bottom: 86px
            }

            .content {
                padding: 16px !important
            }

            .desktop-table {
                display: none
            }

            .mobile-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px
            }

            .filter-grid {
                grid-template-columns: 1fr !important
            }
        }

        @media (max-width:640px) {
            .content {
                padding: 12px !important
            }

            .mobile-list {
                grid-template-columns: 1fr
            }

            .kpi {
                padding: 13px
            }

            .modal {
                padding: 0
            }

            .modal-inner {
                width: 100%;
                height: 100vh;
                max-height: 100vh
            }
        }
    </style>
</head>

<body class="antialiased pb-24 lg:pb-0">
    <?php if (file_exists(__DIR__ . '/sidebar.php')) require_once __DIR__ . '/sidebar.php'; ?>
    <?php if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">
        <?php if ($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo h($error); ?></div><?php endif; ?>

        <section class="card p-5 md:p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">KSP</p>
                    <h1 class="text-2xl md:text-3xl font-black tracking-tight mt-1">Penarikan Simpanan</h1>
                    <p class="text-xs text-gray-400 mt-2">Kelola penarikan simpanan sukarela, keluar member, dan pensiun.</p>
                </div>
                <button type="button" onclick="openCreateModal()" class="btn-primary">Tambah Pengajuan</button>
            </div>
        </section>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Total</p>
                <p class="text-2xl font-black mt-1"><?php echo angka_ps($summary['total'] ?? 0); ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Menunggu</p>
                <p class="text-2xl font-black mt-1 text-amber-600"><?php echo angka_ps($summary['menunggu'] ?? 0); ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Disetujui</p>
                <p class="text-2xl font-black mt-1 text-green-600"><?php echo angka_ps($summary['disetujui'] ?? 0); ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Ditolak/Batal</p>
                <p class="text-2xl font-black mt-1 text-red-600"><?php echo angka_ps($summary['ditolak'] ?? 0); ?></p>
            </div>
            <div class="kpi">
                <p class="small-muted font-black uppercase tracking-widest">Nominal Cair</p>
                <p class="text-xl font-black mt-1"><?php echo rupiah_ps($summary['nominal'] ?? 0); ?></p>
            </div>
        </div>

        <section class="card p-4 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-[2fr_1fr_1fr_1fr_auto_auto] gap-3 items-end filter-grid">
                <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Cari Member</label><input type="text" name="q" value="<?php echo h($q); ?>" class="form-input" placeholder="Nama / kode / no HP"></div>
                <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Jenis</label><select name="jenis" class="form-input">
                        <option value="">Semua</option>
                        <option value="sukarela" <?php echo $jenisFilter === 'sukarela' ? 'selected' : ''; ?>>Sukarela</option>
                        <option value="keluar" <?php echo $jenisFilter === 'keluar' ? 'selected' : ''; ?>>Keluar</option>
                        <option value="pensiun" <?php echo $jenisFilter === 'pensiun' ? 'selected' : ''; ?>>Pensiun</option>
                    </select></div>
                <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Status</label><select name="status" class="form-input">
                        <option value="">Semua</option>
                        <option value="menunggu" <?php echo $statusFilter === 'menunggu' ? 'selected' : ''; ?>>Menunggu</option>
                        <option value="disetujui" <?php echo $statusFilter === 'disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                        <option value="ditolak" <?php echo $statusFilter === 'ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                        <option value="batal" <?php echo $statusFilter === 'batal' ? 'selected' : ''; ?>>Batal</option>
                    </select></div>
                <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Tampil</label><select name="per_page" class="form-input"><?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?>/halaman</option><?php endforeach; ?></select></div>
                <button type="submit" class="btn-primary">Tampilkan</button><a href="penarikan_simpanan.php" class="btn-outline">Reset</a>
            </form>
        </section>

        <section class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Daftar Penarikan</h2>
                    <p class="text-xs text-gray-400 mt-1"><?php echo angka_ps(count($penarikanList)); ?> dari <?php echo angka_ps($totalRows); ?> data ditampilkan</p>
                </div>
            </div>
            <div class="table-wrap desktop-table">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member</th>
                            <th>Jenis</th>
                            <th class="text-right">Jumlah</th>
                            <th>Status</th>
                            <th>Catatan</th>
                            <th>Diproses</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$penarikanList): ?><tr>
                                <td colspan="8" class="text-center py-16 text-gray-300 font-black uppercase tracking-widest">Belum ada data penarikan</td>
                            </tr><?php endif; ?>
                        <?php foreach ($penarikanList as $i => $p): $st = status_normal_ps($p['status'] ?? 'menunggu'); ?>
                            <tr>
                                <td class="text-gray-300 font-black"><?php echo (($page - 1) * $perPage) + $i + 1; ?></td>
                                <td>
                                    <div class="font-black text-sm"><?php echo h($p['member_nama']); ?></div>
                                    <div class="small-muted font-mono"><?php echo h($p['member_kode']); ?> · <?php echo h($p['no_hp'] ?: '-'); ?></div>
                                    <div class="small-muted">Diajukan: <?php echo h(tanggal_ps($p['created_at'] ?? null)); ?></div>
                                </td>
                                <td><span class="border border-gray-200 bg-gray-50 px-2 py-1 text-[9px] font-black uppercase"><?php echo h(jenis_label_ps($p['jenis'] ?? 'sukarela')); ?></span></td>
                                <td class="text-right money"><?php echo rupiah_ps($p['jumlah']); ?></td>
                                <td><span class="<?php echo h(status_badge_ps($st)); ?> border px-2 py-1 text-[9px] font-black uppercase"><?php echo h($st); ?></span></td>
                                <td class="text-xs text-gray-500 max-w-[220px]"><?php echo h($p['catatan'] ?: '-'); ?></td>
                                <td class="text-xs text-gray-500"><?php echo h($p['diproses_nama'] ?: '-'); ?><br><span class="small-muted"><?php echo h(tanggal_ps($p['diproses_at'] ?? null)); ?></span></td>
                                <td class="text-right">
                                    <?php if (in_array($st, array('menunggu', 'diproses'), true)): ?>
                                        <div class="flex justify-end gap-2 flex-wrap">
                                            <form method="POST" onsubmit="return confirm('Setujui penarikan ini?');"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn-success" type="submit">Setujui</button></form>
                                            <form method="POST" onsubmit="return confirm('Tolak penarikan ini?');"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn-danger" type="submit">Tolak</button></form>
                                        </div>
                                    <?php else: ?><span class="small-muted font-black uppercase">Selesai</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mobile-list p-4">
                <?php if (!$penarikanList): ?><div class="mobile-card text-center text-gray-300 font-black uppercase tracking-widest text-[10px]">Belum ada data penarikan</div><?php endif; ?>
                <?php foreach ($penarikanList as $i => $p): $st = status_normal_ps($p['status'] ?? 'menunggu'); ?>
                    <div class="mobile-card">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div>
                                <p class="text-[10px] text-gray-300 font-black">#<?php echo (($page - 1) * $perPage) + $i + 1; ?></p>
                                <p class="font-black text-sm"><?php echo h($p['member_nama']); ?></p>
                                <p class="small-muted font-mono"><?php echo h($p['member_kode']); ?> · <?php echo h($p['no_hp'] ?: '-'); ?></p>
                            </div><span class="<?php echo h(status_badge_ps($st)); ?> border px-2 py-1 text-[9px] font-black uppercase"><?php echo h($st); ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs border-t border-gray-100 pt-3">
                            <div><span class="small-muted font-black uppercase">Jenis</span>
                                <p class="font-black"><?php echo h(jenis_label_ps($p['jenis'] ?? 'sukarela')); ?></p>
                            </div>
                            <div><span class="small-muted font-black uppercase">Jumlah</span>
                                <p class="font-black"><?php echo rupiah_ps($p['jumlah']); ?></p>
                            </div>
                            <div class="col-span-2"><span class="small-muted font-black uppercase">Catatan</span>
                                <p class="text-gray-600"><?php echo h($p['catatan'] ?: '-'); ?></p>
                            </div>
                        </div>
                        <?php if (in_array($st, array('menunggu', 'diproses'), true)): ?><div class="grid grid-cols-2 gap-2 mt-3">
                                <form method="POST" onsubmit="return confirm('Setujui penarikan ini?');"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn-success w-full" type="submit">Setujui</button></form>
                                <form method="POST" onsubmit="return confirm('Tolak penarikan ini?');"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn-danger w-full" type="submit">Tolak</button></form>
                            </div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="px-5 py-4 border-t border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Halaman <?php echo angka_ps($page); ?>/<?php echo angka_ps($totalPages); ?> · Total <?php echo angka_ps($totalRows); ?> data</p>
                <div class="flex gap-2"><a class="btn-outline <?php echo $page <= 1 ? 'opacity-40 pointer-events-none' : ''; ?>" href="?<?php echo h($baseQuery); ?>&page=<?php echo max(1, $page - 1); ?>">Prev</a><a class="btn-outline <?php echo $page >= $totalPages ? 'opacity-40 pointer-events-none' : ''; ?>" href="?<?php echo h($baseQuery); ?>&page=<?php echo min($totalPages, $page + 1); ?>">Next</a></div>
            </div>
        </section>
    </main>

    <div id="modal-create" class="modal">
        <div class="modal-inner">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pengajuan Baru</p>
                    <h2 class="text-lg font-black">Penarikan Simpanan</h2>
                </div><button type="button" onclick="closeCreateModal()" class="w-9 h-9 border border-gray-200 text-xl">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="create">
                <div class="relative">
                    <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Member</label>
                    <input type="hidden" name="member_id" id="member_id" value="">
                    <input type="text" id="member-search" class="form-input" placeholder="Ketik nama / kode / no HP member..." autocomplete="off" oninput="searchMemberAjax()" onfocus="searchMemberAjax()" required>
                    <div id="member-result" class="member-result-box hidden"></div>
                    <p id="member-selected-label" class="small-muted mt-1">Belum ada member dipilih.</p>
                </div>
                <div id="saldo-box" class="grid grid-cols-2 md:grid-cols-4 gap-2 hidden">
                    <div class="card p-3">
                        <p class="small-muted font-black uppercase">Pokok</p>
                        <p id="saldo-pokok" class="font-black">Rp 0</p>
                    </div>
                    <div class="card p-3">
                        <p class="small-muted font-black uppercase">Wajib</p>
                        <p id="saldo-wajib" class="font-black">Rp 0</p>
                    </div>
                    <div class="card p-3">
                        <p class="small-muted font-black uppercase">Sukarela Tersedia</p>
                        <p id="saldo-sukarela" class="font-black text-purple-700">Rp 0</p>
                    </div>
                    <div class="card p-3">
                        <p class="small-muted font-black uppercase">Sisa Pinjaman</p>
                        <p id="saldo-pinjaman" class="font-black text-red-600">Rp 0</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Jenis</label><select name="jenis" class="form-input">
                            <option value="sukarela">Penarikan Sukarela</option>
                            <option value="keluar">Keluar Member</option>
                            <option value="pensiun">Pensiun</option>
                        </select></div>
                    <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Jumlah</label><input type="number" min="1" name="jumlah" class="form-input" placeholder="0" required></div>
                </div>
                <div><label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5">Catatan</label><textarea name="catatan" class="form-input" rows="3" placeholder="Alasan penarikan / nomor rekening / keterangan tambahan"></textarea></div>
                <div class="bg-amber-50 border border-amber-100 p-3 text-[11px] text-amber-800 font-semibold leading-relaxed">Saat disetujui, sistem membuat jurnal otomatis: Debit Simpanan Sukarela, Kredit Kas. Untuk jenis keluar/pensiun, status member otomatis menjadi nonaktif.</div>
                <div class="flex gap-3 pt-4 border-t border-gray-100"><button type="button" onclick="closeCreateModal()" class="btn-outline flex-1">Batal</button><button type="submit" class="btn-primary flex-1">Simpan Pengajuan</button></div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modal-create').classList.add('open');
            document.body.style.overflow = 'hidden'
        }

        function closeCreateModal() {
            document.getElementById('modal-create').classList.remove('open');
            document.body.style.overflow = ''
        }

        function rupiahJS(n) {
            return 'Rp ' + parseInt(n || 0, 10).toLocaleString('id-ID')
        }

        var memberSearchTimer = null;

        function escapeHtmlPS(v) {
            return String(v || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function searchMemberAjax() {
            var input = document.getElementById('member-search');
            var result = document.getElementById('member-result');
            var q = input ? input.value.trim() : '';

            document.getElementById('member_id').value = '';
            document.getElementById('member-selected-label').textContent = 'Ketik minimal 1 huruf lalu pilih member dari hasil pencarian.';
            document.getElementById('saldo-box').classList.add('hidden');

            clearTimeout(memberSearchTimer);
            memberSearchTimer = setTimeout(function() {
                fetch(window.location.pathname + '?ajax=member_search&q=' + encodeURIComponent(q), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(res) {
                        return res.json();
                    })
                    .then(function(data) {
                        if (!data.ok || !data.items || !data.items.length) {
                            result.innerHTML = '<div class="p-3 text-xs text-gray-400 font-bold">Member tidak ditemukan.</div>';
                            result.classList.remove('hidden');
                            return;
                        }

                        var html = '';
                        data.items.forEach(function(m) {
                            var label = (m.nama || '') + ' - ' + (m.kode || '');
                            html += '<button type="button" class="member-result-item" data-id="' + parseInt(m.id, 10) + '" data-label="' + escapeHtmlPS(label) + '">' +
                                '<div class="member-result-name">' + escapeHtmlPS(m.nama) + '</div>' +
                                '<div class="member-result-meta">' + escapeHtmlPS((m.kode || '-') + ' · ' + (m.no_hp || '-') + ' · ' + (m.status || '-')) + '</div>' +
                                '</button>';
                        });

                        result.innerHTML = html;
                        result.querySelectorAll('.member-result-item').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                selectMemberAjax(this.getAttribute('data-id'), this.getAttribute('data-label'));
                            });
                        });
                        result.classList.remove('hidden');
                    })
                    .catch(function() {
                        result.innerHTML = '<div class="p-3 text-xs text-red-500 font-bold">Gagal mengambil data member.</div>';
                        result.classList.remove('hidden');
                    });
            }, 250);
        }

        function selectMemberAjax(id, label) {
            document.getElementById('member_id').value = id;
            document.getElementById('member-search').value = label;
            document.getElementById('member-selected-label').textContent = 'Dipilih: ' + label;
            document.getElementById('member-result').classList.add('hidden');
            updateSaldoPreview(id);
        }

        function updateSaldoPreview(memberId) {
            memberId = memberId || document.getElementById('member_id').value;
            var box = document.getElementById('saldo-box');
            if (!memberId) {
                box.classList.add('hidden');
                return;
            }

            fetch(window.location.pathname + '?ajax=member_saldo&member_id=' + encodeURIComponent(memberId), {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (!data.ok) {
                        box.classList.add('hidden');
                        alert(data.message || 'Saldo member tidak bisa dibaca.');
                        return;
                    }

                    box.classList.remove('hidden');
                    document.getElementById('saldo-pokok').textContent = rupiahJS(data.saldo.pokok);
                    document.getElementById('saldo-wajib').textContent = rupiahJS(data.saldo.wajib);
                    document.getElementById('saldo-sukarela').textContent = rupiahJS(data.saldo.sukarela_tersedia);
                    document.getElementById('saldo-pinjaman').textContent = rupiahJS(data.pinjaman);
                })
                .catch(function() {
                    box.classList.add('hidden');
                    alert('Gagal mengambil saldo member.');
                });
        }

        document.addEventListener('click', function(e) {
            var result = document.getElementById('member-result');
            var input = document.getElementById('member-search');
            if (result && input && e.target !== input && !result.contains(e.target)) {
                result.classList.add('hidden');
            }
        });

        document.getElementById('modal-create').addEventListener('click', function(e) {
            if (e.target === this) closeCreateModal()
        });
    </script>
</body>

</html>