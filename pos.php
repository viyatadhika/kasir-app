<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

if (!function_exists('e')) {
    function e(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

define('POINT_RUPIAH', 1000);

function diskonColumns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) return $cols;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM diskon")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function diskonHasColumn(PDO $pdo, string $column): bool
{
    return in_array($column, diskonColumns($pdo), true);
}

function hitungPotonganDiskon(int $base, string $jenis, int $nilai): int
{
    if ($base <= 0 || $nilai <= 0) return 0;
    $potongan = $jenis === 'persen' ? (int)floor($base * $nilai / 100) : $nilai;
    return max(0, min($base, $potongan));
}

function getActiveDiscountRows(PDO $pdo, int $subtotal): array
{
    if ($subtotal <= 0) return [];
    try {
        $select = "id, nama, target, jenis, nilai, minimal_belanja, tanggal_mulai, tanggal_selesai";
        $select .= diskonHasColumn($pdo, 'cakupan')   ? ", cakupan"           : ", 'transaksi' AS cakupan";
        $select .= diskonHasColumn($pdo, 'produk_id') ? ", produk_id"         : ", NULL AS produk_id";
        $select .= diskonHasColumn($pdo, 'kategori')  ? ", kategori"          : ", NULL AS kategori";
        $stmt = $pdo->prepare("SELECT $select FROM diskon WHERE status='aktif' AND target='semua' AND minimal_belanja <= :subtotal AND (tanggal_mulai IS NULL OR tanggal_mulai <= CURDATE()) AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE()) ORDER BY minimal_belanja DESC, id DESC");
        $stmt->execute([':subtotal' => $subtotal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function calculateCartDiscounts(PDO $pdo, array $items, array $produkMap, $memberId = null): array
{
    $subtotal = 0;
    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        if (isset($produkMap[$pid])) $subtotal += (int)$produkMap[$pid]['harga_jual'] * $qty;
    }
    $rows = getActiveDiscountRows($pdo, $subtotal);
    $candidates = [];
    foreach ($rows as $d) {
        $cakupan = $d['cakupan'] ?? 'transaksi';
        $discountAmount = 0;
        $affectedLines = [];
        if ($cakupan === 'transaksi') {
            $discountAmount = hitungPotonganDiskon($subtotal, (string)$d['jenis'], (int)$d['nilai']);
        } elseif ($cakupan === 'produk' || $cakupan === 'kategori') {
            foreach ($items as $item) {
                $pid = (int)($item['id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if (!isset($produkMap[$pid]) || $qty <= 0) continue;
                $p = $produkMap[$pid];
                if ($cakupan === 'produk' && (int)($d['produk_id'] ?? 0) !== $pid) continue;
                if ($cakupan === 'kategori' && (string)($d['kategori'] ?? '') !== (string)($p['kategori'] ?? '')) continue;
                $lineBase = (int)$p['harga_jual'] * $qty;
                if ((string)$d['jenis'] === 'persen') {
                    $lineDiscount = hitungPotonganDiskon($lineBase, 'persen', (int)$d['nilai']);
                } else {
                    $diskonSatuan = hitungPotonganDiskon((int)$p['harga_jual'], 'nominal', (int)$d['nilai']);
                    $lineDiscount = $diskonSatuan * $qty;
                }
                if ($lineDiscount > 0) {
                    $discountAmount += $lineDiscount;
                    $affectedLines[$pid] = $lineDiscount;
                }
            }
        }
        if ($discountAmount > 0) $candidates[] = ['id' => (int)$d['id'], 'nama' => $d['nama'], 'cakupan' => $cakupan, 'jenis' => $d['jenis'], 'nilai' => (int)$d['nilai'], 'diskon' => (int)$discountAmount, 'affected_lines' => $affectedLines];
    }
    $selected = null;
    foreach ($candidates as $candidate) {
        if ($selected === null || $candidate['diskon'] < $selected['diskon']) $selected = $candidate;
    }
    $lines = [];
    foreach ($items as $item) {
        $pid = (int)$item['id'];
        $qty = (int)$item['qty'];
        $p = $produkMap[$pid];
        $lineBase = (int)$p['harga_jual'] * $qty;
        $diskonItem = 0;
        $diskonItemId = null;
        $diskonItemNama = null;
        if ($selected && ($selected['cakupan'] === 'produk' || $selected['cakupan'] === 'kategori')) {
            $diskonItem = (int)($selected['affected_lines'][$pid] ?? 0);
            if ($diskonItem > 0) {
                $diskonItemId = $selected['id'];
                $diskonItemNama = $selected['nama'];
            }
        }
        $lineNet = max(0, $lineBase - $diskonItem);
        $hargaFinal = $qty > 0 ? (int)floor($lineNet / $qty) : (int)$p['harga_jual'];
        $lines[] = ['produk_id' => $pid, 'kode' => $p['kode'], 'nama' => $p['nama'], 'harga_normal' => (int)$p['harga_jual'], 'qty' => $qty, 'subtotal_normal' => $lineBase, 'diskon_item' => $diskonItem, 'diskon_item_id' => $diskonItemId, 'diskon_item_nama' => $diskonItemNama, 'harga_final' => $hargaFinal, 'subtotal_final' => $lineNet];
    }
    $itemDiskonTotal = ($selected && ($selected['cakupan'] === 'produk' || $selected['cakupan'] === 'kategori')) ? (int)$selected['diskon'] : 0;
    $transaksiDiskon = ($selected && $selected['cakupan'] === 'transaksi') ? (int)$selected['diskon'] : 0;
    $totalDiskon = $itemDiskonTotal + $transaksiDiskon;
    return ['subtotal' => $subtotal, 'item_diskon' => $itemDiskonTotal, 'subtotal_setelah_diskon_item' => max(0, $subtotal - $itemDiskonTotal), 'transaksi_diskon' => $transaksiDiskon, 'transaksi_diskon_id' => $selected ? $selected['id'] : null, 'transaksi_diskon_nama' => $selected ? $selected['nama'] : null, 'diskon_terpilih_id' => $selected ? $selected['id'] : null, 'diskon_terpilih_nama' => $selected ? $selected['nama'] : null, 'diskon_terpilih_cakupan' => $selected ? $selected['cakupan'] : null, 'diskon_total' => $totalDiskon, 'total' => max(0, $subtotal - $totalDiskon), 'lines' => $lines, 'aturan_diskon' => 'Promo tidak digabung.'];
}

function transaksiHasDiscountColumns(PDO $pdo): bool
{
    static $hasColumns = null;
    if ($hasColumns !== null) return $hasColumns;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM transaksi")->fetchAll(PDO::FETCH_COLUMN);
        $hasColumns = in_array('diskon', $cols, true) && in_array('diskon_id', $cols, true);
    } catch (Throwable $e) {
        $hasColumns = false;
    }
    return $hasColumns;
}
function transaksiHasPointRedeemColumns(PDO $pdo): bool
{
    static $hasColumns = null;
    if ($hasColumns !== null) return $hasColumns;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM transaksi")->fetchAll(PDO::FETCH_COLUMN);
        $hasColumns = in_array('point_pakai', $cols, true) && in_array('nilai_point_pakai', $cols, true);
    } catch (Throwable $e) {
        $hasColumns = false;
    }
    return $hasColumns;
}
function transaksiDetailHasDiscountColumns(PDO $pdo): bool
{
    static $hasColumns = null;
    if ($hasColumns !== null) return $hasColumns;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM transaksi_detail")->fetchAll(PDO::FETCH_COLUMN);
        $hasColumns = in_array('harga_normal', $cols, true) && in_array('diskon', $cols, true) && in_array('diskon_id', $cols, true);
    } catch (Throwable $e) {
        $hasColumns = false;
    }
    return $hasColumns;
}

// ── API Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['action']) {
        case 'get_products':
            $stmt = $pdo->query("SELECT id, kode, nama, kategori, harga_jual, stok, satuan FROM produk WHERE status = 'aktif' ORDER BY kategori, nama");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;

        case 'suggest_member':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $q = trim((string)($input['q'] ?? ''));
            if ($q === '') {
                echo json_encode([], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $qDigits = preg_replace('/\D+/', '', $q);
            $hpExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp, ''), ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";
            $stmt = $pdo->prepare("SELECT id, kode, nama, no_hp, point FROM member WHERE status = 'aktif' AND (kode LIKE :kode_like OR nama LIKE :nama_like OR COALESCE(no_hp, '') LIKE :hp_like OR $hpExpr LIKE :hp_digits_like) ORDER BY CASE WHEN kode = :kode_exact THEN 1 WHEN COALESCE(no_hp, '') = :hp_exact THEN 2 WHEN $hpExpr = :hp_digits_exact THEN 3 WHEN nama LIKE :nama_prefix THEN 4 WHEN kode LIKE :kode_prefix THEN 5 WHEN COALESCE(no_hp, '') LIKE :hp_prefix THEN 6 ELSE 7 END, nama ASC LIMIT 8");
            $stmt->execute([':kode_like' => '%' . $q . '%', ':nama_like' => '%' . $q . '%', ':hp_like' => '%' . $q . '%', ':hp_digits_like' => $qDigits !== '' ? '%' . $qDigits . '%' : '%' . $q . '%', ':kode_exact' => $q, ':hp_exact' => $q, ':hp_digits_exact' => $qDigits, ':nama_prefix' => $q . '%', ':kode_prefix' => $q . '%', ':hp_prefix' => $q . '%']);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            exit;

        case 'cari_member':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $keyword = trim((string)($input['keyword'] ?? ($input['kode'] ?? '')));
            if ($keyword === '') {
                echo json_encode(['success' => false, 'message' => 'Input member kosong.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $keywordDigits = preg_replace('/\D+/', '', $keyword);
            $hpExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(no_hp, ''), ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";
            $stmt = $pdo->prepare("SELECT id, kode, nama, no_hp, point FROM member WHERE status = 'aktif' AND (kode = :kode_exact OR nama = :nama_exact OR COALESCE(no_hp, '') = :hp_exact OR $hpExpr = :hp_digits_exact OR kode LIKE :kode_like OR nama LIKE :nama_like OR COALESCE(no_hp, '') LIKE :hp_like OR $hpExpr LIKE :hp_digits_like) ORDER BY CASE WHEN kode = :kode_exact_order THEN 1 WHEN COALESCE(no_hp, '') = :hp_exact_order THEN 2 WHEN $hpExpr = :hp_digits_exact_order THEN 3 WHEN nama = :nama_exact_order THEN 4 WHEN kode LIKE :kode_prefix THEN 5 WHEN nama LIKE :nama_prefix THEN 6 WHEN COALESCE(no_hp, '') LIKE :hp_prefix THEN 7 ELSE 8 END, nama ASC LIMIT 1");
            $stmt->execute([':kode_exact' => $keyword, ':nama_exact' => $keyword, ':hp_exact' => $keyword, ':hp_digits_exact' => $keywordDigits, ':kode_like' => '%' . $keyword . '%', ':nama_like' => '%' . $keyword . '%', ':hp_like' => '%' . $keyword . '%', ':hp_digits_like' => $keywordDigits !== '' ? '%' . $keywordDigits . '%' : '%' . $keyword . '%', ':kode_exact_order' => $keyword, ':hp_exact_order' => $keyword, ':hp_digits_exact_order' => $keywordDigits, ':nama_exact_order' => $keyword, ':kode_prefix' => $keyword . '%', ':nama_prefix' => $keyword . '%', ':hp_prefix' => $keyword . '%']);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $member ? json_encode(['success' => true, 'data' => $member], JSON_UNESCAPED_UNICODE) : json_encode(['success' => false, 'message' => 'Member tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
            exit;

        case 'hitung_diskon':
            $input = json_decode(file_get_contents('php://input'), true);
            $items = !empty($input['items']) && is_array($input['items']) ? $input['items'] : [];
            $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
            if (empty($items)) {
                echo json_encode(['success' => true, 'data' => ['subtotal' => 0, 'item_diskon' => 0, 'transaksi_diskon' => 0, 'diskon_total' => 0, 'total' => 0, 'lines' => []], 'total' => 0], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $produkIds = array_values(array_unique(array_map(function ($x) {
                return (int)($x['id'] ?? 0);
            }, $items)));
            $produkIds = array_filter($produkIds);
            if (!$produkIds) {
                echo json_encode(['success' => true, 'data' => ['subtotal' => 0, 'item_diskon' => 0, 'transaksi_diskon' => 0, 'diskon_total' => 0, 'total' => 0, 'lines' => []], 'total' => 0], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
            $stmtCheck = $pdo->prepare("SELECT id, kode, nama, kategori, harga_jual, stok FROM produk WHERE id IN ($placeholders) AND status='aktif'");
            $stmtCheck->execute($produkIds);
            $produkMap = [];
            foreach ($stmtCheck->fetchAll(PDO::FETCH_ASSOC) as $p) $produkMap[(int)$p['id']] = $p;
            $diskonData = calculateCartDiscounts($pdo, $items, $produkMap, $memberId);
            echo json_encode(['success' => true, 'data' => $diskonData, 'total' => $diskonData['total']], JSON_UNESCAPED_UNICODE);
            exit;

        case 'simpan_transaksi':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['items']) || !is_array($input['items'])) {
                echo json_encode(['success' => false, 'message' => 'Keranjang kosong.']);
                exit;
            }
            $userId = $_SESSION['user_id'] ?? null;
            $invoice = generateInvoice();
            $items = $input['items'];
            $bayar = (int)($input['bayar'] ?? 0);
            $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
            $produkIds = array_values(array_unique(array_column($items, 'id')));
            $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
            $stmtCheck = $pdo->prepare("SELECT id, kode, nama, kategori, harga_jual, stok FROM produk WHERE id IN ($placeholders) AND status = 'aktif'");
            $stmtCheck->execute($produkIds);
            $produkMap = [];
            foreach ($stmtCheck->fetchAll(PDO::FETCH_ASSOC) as $p) $produkMap[(int)$p['id']] = $p;
            foreach ($items as $item) {
                $pid = (int)$item['id'];
                $qty = (int)$item['qty'];
                if (!isset($produkMap[$pid])) {
                    echo json_encode(['success' => false, 'message' => "Produk ID $pid tidak ditemukan."]);
                    exit;
                }
                if ($qty <= 0) {
                    echo json_encode(['success' => false, 'message' => "Qty tidak valid untuk {$produkMap[$pid]['nama']}."]);
                    exit;
                }
                if ((int)$produkMap[$pid]['stok'] < $qty) {
                    echo json_encode(['success' => false, 'message' => "Stok {$produkMap[$pid]['nama']} tidak cukup (sisa: {$produkMap[$pid]['stok']})."]);
                    exit;
                }
            }
            $diskonData = calculateCartDiscounts($pdo, $items, $produkMap, $memberId);
            $subtotal = (int)$diskonData['subtotal'];
            $diskon = (int)$diskonData['diskon_total'];
            $diskonId = !empty($diskonData['diskon_terpilih_id']) ? (int)$diskonData['diskon_terpilih_id'] : null;
            $totalSebelumPoint = (int)$diskonData['total'];
            $pointPakaiInput = !empty($input['point_pakai']) ? max(0, (int)$input['point_pakai']) : 0;
            $pointPakai = 0;
            $nilaiPointPakai = 0;
            $total = $totalSebelumPoint;
            $kembalian = 0;
            $pointDapat = 0;
            try {
                $pdo->beginTransaction();
                if ($memberId && $pointPakaiInput > 0) {
                    $stmtPoint = $pdo->prepare("SELECT point FROM member WHERE id=:id AND status='aktif' FOR UPDATE");
                    $stmtPoint->execute([':id' => $memberId]);
                    $saldoPoint = (int)$stmtPoint->fetchColumn();
                    if ($saldoPoint <= 0) throw new Exception('Saldo point member kosong.');
                    $maxPointByTotal = (int)floor($totalSebelumPoint / POINT_RUPIAH);
                    $pointPakai = min($pointPakaiInput, $saldoPoint, $maxPointByTotal);
                    $nilaiPointPakai = $pointPakai * POINT_RUPIAH;
                    $total = max(0, (int)$totalSebelumPoint - (int)$nilaiPointPakai);
                } elseif (!$memberId && $pointPakaiInput > 0) throw new Exception('Pilih member terlebih dahulu untuk tukar point.');
                $kembalian = max(0, $bayar - $total);
                if ($bayar > 0 && $bayar < $total) throw new Exception('Uang bayar kurang dari total tagihan.');
                $pointDapat = $memberId ? (int)floor($total / 10000) : 0;
                $hasTrxDiscount = transaksiHasDiscountColumns($pdo);
                $hasTrxPointRedeem = transaksiHasPointRedeemColumns($pdo);
                if ($hasTrxDiscount && $hasTrxPointRedeem) {
                    $stmtTrans = $pdo->prepare("INSERT INTO transaksi (invoice,user_id,member_id,total,diskon,diskon_id,bayar,kembalian,point_dapat,point_pakai,nilai_point_pakai,catatan) VALUES (:invoice,:user_id,:member_id,:total,:diskon,:diskon_id,:bayar,:kembalian,:point_dapat,:point_pakai,:nilai_point_pakai,:catatan)");
                    $stmtTrans->execute([':invoice' => $invoice, ':user_id' => $userId, ':member_id' => $memberId, ':total' => $total, ':diskon' => $diskon, ':diskon_id' => $diskonId, ':bayar' => $bayar, ':kembalian' => $kembalian, ':point_dapat' => $pointDapat, ':point_pakai' => $pointPakai, ':nilai_point_pakai' => $nilaiPointPakai, ':catatan' => $input['catatan'] ?? null]);
                } elseif ($hasTrxDiscount) {
                    $stmtTrans = $pdo->prepare("INSERT INTO transaksi (invoice,user_id,member_id,total,diskon,diskon_id,bayar,kembalian,point_dapat,catatan) VALUES (:invoice,:user_id,:member_id,:total,:diskon,:diskon_id,:bayar,:kembalian,:point_dapat,:catatan)");
                    $stmtTrans->execute([':invoice' => $invoice, ':user_id' => $userId, ':member_id' => $memberId, ':total' => $total, ':diskon' => $diskon, ':diskon_id' => $diskonId, ':bayar' => $bayar, ':kembalian' => $kembalian, ':point_dapat' => $pointDapat, ':catatan' => $input['catatan'] ?? null]);
                } elseif ($hasTrxPointRedeem) {
                    $stmtTrans = $pdo->prepare("INSERT INTO transaksi (invoice,user_id,member_id,total,bayar,kembalian,point_dapat,point_pakai,nilai_point_pakai,catatan) VALUES (:invoice,:user_id,:member_id,:total,:bayar,:kembalian,:point_dapat,:point_pakai,:nilai_point_pakai,:catatan)");
                    $stmtTrans->execute([':invoice' => $invoice, ':user_id' => $userId, ':member_id' => $memberId, ':total' => $total, ':bayar' => $bayar, ':kembalian' => $kembalian, ':point_dapat' => $pointDapat, ':point_pakai' => $pointPakai, ':nilai_point_pakai' => $nilaiPointPakai, ':catatan' => $input['catatan'] ?? null]);
                } else {
                    $stmtTrans = $pdo->prepare("INSERT INTO transaksi (invoice,user_id,member_id,total,bayar,kembalian,point_dapat,catatan) VALUES (:invoice,:user_id,:member_id,:total,:bayar,:kembalian,:point_dapat,:catatan)");
                    $stmtTrans->execute([':invoice' => $invoice, ':user_id' => $userId, ':member_id' => $memberId, ':total' => $total, ':bayar' => $bayar, ':kembalian' => $kembalian, ':point_dapat' => $pointDapat, ':catatan' => $input['catatan'] ?? null]);
                }
                $transaksiId = $pdo->lastInsertId();
                if (transaksiDetailHasDiscountColumns($pdo)) {
                    $stmtDetail = $pdo->prepare("INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga_normal,harga,diskon,diskon_id,qty,subtotal) VALUES (:tid,:pid,:kode,:nama,:harga_normal,:harga,:diskon,:diskon_id,:qty,:sub)");
                } else {
                    $stmtDetail = $pdo->prepare("INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga,qty,subtotal) VALUES (:tid,:pid,:kode,:nama,:harga,:qty,:sub)");
                }
                $stmtStok = $pdo->prepare("UPDATE produk SET stok=stok-:qty, updated_at=NOW() WHERE id=:id");
                foreach ($diskonData['lines'] as $line) {
                    $pid = (int)$line['produk_id'];
                    $qty = (int)$line['qty'];
                    if (transaksiDetailHasDiscountColumns($pdo)) {
                        $stmtDetail->execute([':tid' => $transaksiId, ':pid' => $pid, ':kode' => $line['kode'], ':nama' => $line['nama'], ':harga_normal' => $line['harga_normal'], ':harga' => $line['harga_final'], ':diskon' => $line['diskon_item'], ':diskon_id' => $line['diskon_item_id'], ':qty' => $qty, ':sub' => $line['subtotal_final']]);
                    } else {
                        $stmtDetail->execute([':tid' => $transaksiId, ':pid' => $pid, ':kode' => $line['kode'], ':nama' => $line['nama'], ':harga' => $line['harga_final'], ':qty' => $qty, ':sub' => $line['subtotal_final']]);
                    }
                    $stmtStok->execute([':qty' => $qty, ':id' => $pid]);
                }
                if ($memberId) $pdo->prepare("UPDATE member SET point=point-:pakai+:pt, total_belanja=total_belanja+:total, updated_at=NOW() WHERE id=:id")->execute([':pakai' => $pointPakai, ':pt' => $pointDapat, ':total' => $total, ':id' => $memberId]);
                $pdo->commit();
                $pointTotal = 0;
                if ($memberId) {
                    $r = $pdo->prepare("SELECT point FROM member WHERE id=:id");
                    $r->execute([':id' => $memberId]);
                    $pointTotal = (int)$r->fetchColumn();
                }
                echo json_encode(['success' => true, 'invoice' => $invoice, 'subtotal' => $subtotal, 'diskon' => $diskon, 'diskon_id' => $diskonId, 'diskon_nama' => $diskonData['diskon_terpilih_nama'], 'diskon_item' => $diskonData['item_diskon'], 'diskon_transaksi' => $diskonData['transaksi_diskon'], 'total_sebelum_point' => $totalSebelumPoint, 'point_pakai' => $pointPakai, 'nilai_point_pakai' => $nilaiPointPakai, 'total' => $total, 'bayar' => $bayar, 'kembalian' => $kembalian, 'point_dapat' => $pointDapat, 'point_total' => $pointTotal, 'lines' => $diskonData['lines'], 'message' => 'Transaksi berhasil disimpan.']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
            exit;
    }
}

$stmtKat = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE status='aktif' ORDER BY kategori");
$kategoriList = $stmtKat->fetchAll(PDO::FETCH_COLUMN);
$qrisImage = 'assets/qr_code_kasir.png';
$operatorName = addslashes(e($_SESSION['nama'] ?? 'Kasir'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS – Mesin Kasir</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
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

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
        }

        .cart-item-anim {
            animation: slideIn .2s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .spinner {
            border: 3px solid #f0f0f0;
            border-top-color: #2563eb;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin .8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        button,
        .category-btn,
        header a {
            border-radius: 2px !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: .08em !important;
            transition: all .15s ease !important;
        }

        button.bg-black {
            background: #000 !important;
            color: #fff !important;
            border-color: #000 !important;
        }

        button.bg-black:hover {
            background: #1f1f1f !important;
        }

        .category-btn.active-category {
            background: #000 !important;
            color: #fff !important;
            border-color: #000 !important;
        }

        button:hover,
        header a:hover {
            transform: translateY(-1px);
        }

        button:active {
            transform: translateY(0) scale(.98);
        }

        #mobileMenuOverlay {
            transition: opacity .3s ease, visibility .3s ease;
        }

        #mobileMenuContent {
            transition: transform .3s cubic-bezier(.4, 0, .2, 1);
        }

        @media(min-width:1024px) {
            .sidebar {
                width: 220px
            }

            .content {
                margin-left: 220px
            }
        }

        /* ── Mobile Cart Bottom Sheet ─────────────────────── */
        #mobile-cart-sheet {
            transform: translateY(calc(100% - 72px));
            transition: transform .38s cubic-bezier(.4, 0, .2, 1);
            border-radius: 20px 20px 0 0;
            max-height: 92vh;
        }

        #mobile-cart-sheet.expanded {
            transform: translateY(0);
        }

        #cart-overlay {
            background: rgba(0, 0, 0, .4);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            transition: opacity .3s;
        }

        .cart-badge {
            min-width: 22px;
            height: 22px;
            font-size: 11px;
            font-weight: 900;
            border-radius: 99px;
            background: #2563eb;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        /* ── Cart item card (mobile & desktop) ─────────────── */
        .cart-card {
            background: #fff;
            border: 1.5px solid #efefef;
            border-radius: 14px;
            padding: 14px 14px 12px;
            animation: slideIn .2s ease-out;
            transition: border-color .15s, box-shadow .15s;
        }

        .cart-card:active {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        .cart-card .item-name {
            font-size: 14px;
            font-weight: 800;
            line-height: 1.35;
            color: #111;
            letter-spacing: -.01em;
            margin-bottom: 4px;
        }

        .cart-card .item-meta {
            font-size: 11px;
            color: #9ca3af;
            font-weight: 600;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .cart-card .item-price-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .cart-card .price-strike {
            text-decoration: line-through;
            color: #d1d5db;
        }

        .cart-card .price-disc {
            color: #dc2626;
            font-weight: 900;
        }

        .cart-card .item-disc-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: #dc2626;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 3px 8px;
            margin-bottom: 10px;
        }

        .cart-card .item-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        /* Qty control — big touch targets */
        .qty-control {
            display: flex;
            align-items: center;
            background: #f4f4f5;
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            overflow: hidden;
        }

        .qty-btn {
            width: 40px !important;
            height: 40px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 22px !important;
            font-weight: 300 !important;
            color: #374151 !important;
            background: transparent !important;
            border: none !important;
            cursor: pointer !important;
            transition: background .1s !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            border-radius: 0 !important;
            padding: 0 !important;
        }

        .qty-btn:hover {
            background: #e5e7eb !important;
        }

        .qty-btn:active {
            background: #d1d5db !important;
            transform: scale(.88) !important;
        }

        .qty-num {
            min-width: 40px;
            text-align: center;
            font-size: 16px !important;
            font-weight: 900 !important;
            color: #111 !important;
            padding: 0 6px !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            pointer-events: none;
        }

        .item-subtotal {
            font-size: 16px;
            font-weight: 900;
            color: #111;
            white-space: nowrap;
        }

        .item-subtotal.has-disc {
            color: #2563eb;
        }

        /* Cart empty state */
        .cart-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 52px 24px;
            opacity: .3;
            text-align: center;
        }

        /* Summary bar */
        .summary-bar {
            background: #fff;
        }

        #member-found {
            display: none;
        }

        #member-notfound {
            display: none;
        }

        #member-suggest .suggest-item:hover,
        #member-suggest .suggest-item.active {
            background: #eff6ff;
        }

        @keyframes pulse-border {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, .25)
            }

            50% {
                box-shadow: 0 0 0 8px rgba(37, 99, 235, 0)
            }
        }

        #qris-image-wrap {
            animation: pulse-border 2s infinite;
            border-radius: 4px;
        }

        #bt-status-bar {
            display: none;
            padding: 6px 12px;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            margin-top: 8px;
        }

        #bt-status-bar.info {
            background: #dbeafe;
            color: #1e40af;
            display: block;
        }

        #bt-status-bar.success {
            background: #dcfce7;
            color: #166534;
            display: block;
        }

        #bt-status-bar.error {
            background: #fee2e2;
            color: #991b1b;
            display: block;
        }
    </style>
</head>

<body class="antialiased min-h-screen flex flex-col overflow-x-hidden pb-0">

    <!-- Mobile Menu Overlay -->
    <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[300] opacity-0 invisible flex justify-end lg:hidden">
        <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
            <div class="flex justify-between items-center mb-10">
                <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
                <button onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="space-y-8 flex-1">
                <a href="index.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
                <a href="pos.php" class="block text-sm font-bold text-black uppercase tracking-widest">Mesin Kasir (POS)</a>
                <a href="produk.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
                <a href="diskon.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Diskon</a>
                <a href="laporan.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Keuangan</a>
                <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block text-sm font-bold text-red-500 uppercase tracking-widest">Logout</a>
            </nav>
            <div class="pt-8 border-t border-subtle">
                <p class="text-[10px] text-gray-400 font-medium uppercase">KOPERASI BSDK</p>
                <p class="text-[10px] text-gray-400 font-medium">Login: <?= e($_SESSION['nama']) ?></p>
            </div>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
        <div class="mb-12"><span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span></div>
        <nav class="flex-1 space-y-6">
            <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
            <a href="pos.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2"><span class="w-2 h-2 bg-black rounded-full"></span>Mesin Kasir (POS)</a>
            <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
            <a href="diskon.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Diskon</a>
            <a href="laporan.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Keuangan</a>
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.5.1</p>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </aside>

    <!-- Header -->
    <header class="content bg-white border-b border-subtle px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center z-10 shadow-sm sticky top-0 lg:relative">
        <div class="flex items-center gap-3 sm:gap-4">
            <a href="index.php" class="p-2 hover:bg-gray-100 group">
                <svg class="h-5 w-5 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <h1 class="text-sm font-bold tracking-[0.2em] uppercase">Mesin Kasir</h1>
        </div>
        <div class="flex items-center gap-3 sm:gap-6">
            <div class="text-right hidden md:block border-r border-subtle pr-6">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Operator</p>
                <p class="text-xs font-semibold uppercase"><?= e($_SESSION['nama']) ?></p>
            </div>
            <div id="clock" class="text-sm font-bold tabular-nums">00:00:00</div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════════════════════════
         MAIN LAYOUT
         Desktop: side by side (produk kiri, cart kanan)
         Mobile/Tablet: produk full width + cart sebagai bottom sheet
         ══════════════════════════════════════════════════════════════ -->
    <div class="content flex-1 flex flex-col lg:flex-row overflow-visible lg:overflow-hidden" style="min-height:calc(100vh - 60px)">

        <!-- ── Kiri: Produk ─────────────────────────────────────────── -->
        <div class="flex-1 flex flex-col bg-gray-50/50 border-b lg:border-b-0 lg:border-r border-subtle min-h-0"
            style="padding-bottom: 80px" id="product-panel">
            <div class="p-4 sm:p-6 bg-white border-b border-subtle space-y-4">
                <div class="relative">
                    <span class="absolute inset-y-0 left-4 flex items-center text-gray-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </span>
                    <input type="text" id="search-input" oninput="filterProducts()"
                        placeholder="Cari nama produk atau scan barcode..."
                        class="w-full bg-gray-50 border border-gray-100 text-sm py-3 sm:py-4 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-black/5 transition-all">
                </div>
                <div class="flex gap-3 overflow-x-auto no-scrollbar" id="category-filters">
                    <button onclick="setCategory('Semua')" class="category-btn active-category px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px] whitespace-nowrap">Semua</button>
                    <?php foreach ($kategoriList as $kat): ?>
                        <button onclick="setCategory('<?= e($kat) ?>')" class="category-btn px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px] hover:border-black hover:text-black transition-colors whitespace-nowrap"><?= e($kat) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-4 sm:p-6 no-scrollbar">
                <div class="product-grid" id="product-list">
                    <div class="col-span-full flex justify-center py-20">
                        <div class="spinner"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Kanan: Cart (Desktop only, always visible) ────────────── -->
        <div class="hidden lg:flex w-[420px] bg-white flex-col shadow-2xl z-20 h-[calc(100vh-60px)] sticky top-0 min-h-0">
            <!-- Member Input -->
            <div class="p-4 sm:p-6 border-b border-subtle flex justify-between items-center bg-gray-50/30">
                <h2 class="text-xs font-black uppercase tracking-[0.2em]">Daftar Belanja</h2>
                <button onclick="clearCart(true)" class="text-[10px] text-red-500 font-bold uppercase tracking-widest hover:underline">Reset</button>
            </div>
            <div class="px-4 sm:px-6 pt-4 pb-3 border-b border-subtle bg-white">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Member (Kode / Nama / No HP)</p>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none z-10">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </span>
                        <input type="text" id="member-input" placeholder="Ketik kode, nama, atau no HP member..."
                            oninput="onMemberInput()" onkeydown="onMemberKeydown(event)" autocomplete="off"
                            class="w-full bg-gray-50 border border-gray-100 text-sm py-2.5 pl-10 pr-3 focus:outline-none focus:ring-2 focus:ring-black/5 transition-all">
                        <div id="member-suggest" class="hidden absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl z-50 max-h-52 overflow-y-auto no-scrollbar"></div>
                    </div>
                    <button onclick="cariMember()" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase hover:bg-gray-800 transition-all">Cari</button>
                    <button onclick="clearMember()" class="px-3 py-2.5 border border-subtle text-gray-400 text-[10px] font-black uppercase hover:bg-gray-50 transition-all">✕</button>
                </div>
                <div id="member-found" class="mt-2 bg-green-50 border border-green-200 px-3 py-2 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black text-green-700" id="member-nama">—</p>
                        <p class="text-[9px] text-green-500 font-bold uppercase tracking-wide mt-0.5">Point saat ini: <span id="member-point" class="font-black">0</span> pt</p>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-green-600 bg-green-100 px-2 py-1">Member Aktif</span>
                </div>
                <div id="point-redeem-box" class="hidden mt-2 bg-blue-50 border border-blue-200 px-3 py-3">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <div>
                            <p class="text-[10px] font-black text-blue-700 uppercase tracking-widest">Tukar Point</p>
                            <p class="text-[9px] text-blue-500 font-bold" id="point-redeem-info">1 point = Rp 1.000</p>
                        </div>
                        <button type="button" onclick="setPointRedeemMax()" class="px-2 py-1 bg-blue-600 text-white text-[9px] font-black uppercase">Max</button>
                    </div>
                    <div class="flex gap-2">
                        <input type="number" id="point-redeem-input" min="0" value="0" oninput="onPointRedeemInput()"
                            class="w-full bg-white border border-blue-100 text-sm py-2 px-3 focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all" placeholder="Jumlah point yang ditukar">
                        <button type="button" onclick="clearPointRedeem()" class="px-3 py-2 border border-blue-100 text-blue-600 text-[10px] font-black uppercase bg-white">Reset</button>
                    </div>
                </div>
                <div id="member-notfound" class="mt-2 bg-red-50 border border-red-200 px-3 py-2">
                    <p class="text-[10px] font-black text-red-600">Member tidak ditemukan. Transaksi tanpa point.</p>
                </div>
            </div>

            <!-- Desktop Cart Items -->
            <div class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3 no-scrollbar" id="cart-container">
                <div class="cart-empty-state">
                    <svg class="h-16 w-16 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    <p class="text-xs font-bold uppercase tracking-widest">Keranjang Kosong</p>
                    <p class="text-xs text-gray-400 mt-1">Tap produk untuk menambahkan</p>
                </div>
            </div>

            <!-- Desktop Summary -->
            <div class="shrink-0 p-4 sm:p-6 border-t border-subtle bg-white space-y-3 shadow-[0_-8px_24px_rgba(0,0,0,0.04)]">
                <div class="flex justify-between text-[11px] font-medium text-gray-400 uppercase tracking-widest">
                    <span>Subtotal</span><span id="subtotal">Rp 0</span>
                </div>
                <div id="diskon-preview" class="hidden flex justify-between text-[11px] font-bold text-red-600 uppercase tracking-widest">
                    <span id="diskon-preview-label">Diskon</span><span id="diskon-preview-val">-Rp 0</span>
                </div>
                <div id="point-redeem-preview" class="hidden flex justify-between text-[11px] font-bold text-purple-600 uppercase tracking-widest">
                    <span id="point-redeem-preview-label">Tukar Point</span><span id="point-redeem-preview-val">-Rp 0</span>
                </div>
                <div id="point-preview" class="hidden flex justify-between text-[11px] font-bold text-blue-600 uppercase tracking-widest">
                    <span>Point Didapat</span><span id="point-preview-val">+0 pt</span>
                </div>
                <div class="flex justify-between items-end pt-1">
                    <span class="text-xs font-black uppercase tracking-[0.2em]">Total</span>
                    <span class="text-2xl font-bold text-blue-600" id="total-price">Rp 0</span>
                </div>
                <button onclick="openPayment()" class="w-full bg-black text-white py-4 text-xs font-black uppercase tracking-[0.3em] hover:bg-gray-800 transition-all active:scale-[0.98] shadow-xl shadow-black/10">
                    Bayar Sekarang
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MOBILE / TABLET: Cart Bottom Sheet
         Tersembunyi di bawah, muncul saat diklik / swipe up
         ══════════════════════════════════════════════════════════════ -->

    <!-- Overlay untuk tutup sheet -->
    <div id="cart-overlay" onclick="closeMobileCart()" class="hidden lg:hidden fixed inset-0 z-[80]" style="background:rgba(0,0,0,.4)"></div>

    <!-- Bottom Sheet -->
    <div id="mobile-cart-sheet" class="lg:hidden fixed bottom-0 left-0 right-0 z-[90] bg-white flex flex-col overflow-hidden shadow-2xl" style="max-height:92vh">

        <!-- Handle / Trigger -->
        <div id="cart-handle" onclick="toggleMobileCart()" class="px-4 py-3 border-b border-subtle bg-gray-50 flex items-center justify-between cursor-pointer select-none">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-black rounded-xl flex items-center justify-center relative">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    <span id="cart-badge-mobile" class="cart-badge absolute -top-1.5 -right-1.5 hidden">0</span>
                </div>
                <div>
                    <p class="text-xs font-black uppercase tracking-wider">Daftar Belanja</p>
                    <p class="text-[10px] text-gray-400 font-semibold" id="cart-summary-mobile">Kosong · Rp 0</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" onclick="event.stopPropagation(); clearCart(true)" class="px-2 py-1 border border-red-100 bg-white text-red-500 text-[9px] font-black uppercase tracking-widest hover:bg-red-50 transition-all">Reset</button>
                <span class="text-lg font-black text-blue-600" id="total-price-mobile">Rp 0</span>
                <svg id="cart-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" />
                </svg>
            </div>
        </div>


        <!-- Mobile Member Input -->
        <div class="px-4 py-3 bg-white border-b border-subtle" id="mobile-member-input-section">
            <div class="flex items-center justify-between gap-3 mb-2">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Member (Kode / Nama / No HP)</p>
                <div class="flex items-center gap-2 shrink-0">

                </div>
            </div>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none z-10">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </span>
                    <input type="text" id="mobile-member-input" placeholder="Kode, nama, atau no HP member..."
                        oninput="onMobileMemberInput()" onkeydown="onMobileMemberKeydown(event)" autocomplete="off"
                        class="w-full bg-gray-50 border border-gray-100 text-sm py-2.5 pl-10 pr-3 focus:outline-none focus:ring-2 focus:ring-black/5 transition-all">
                    <div id="mobile-member-suggest" class="hidden absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl z-[120] max-h-52 overflow-y-auto no-scrollbar"></div>
                </div>
                <button onclick="cariMemberMobile()" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase hover:bg-gray-800 transition-all">Cari</button>
                <button onclick="clearMember()" class="px-3 py-2.5 border border-subtle text-gray-400 text-[10px] font-black uppercase hover:bg-gray-50 transition-all">✕</button>
            </div>
            <div id="mobile-member-notfound" class="hidden mt-2 bg-red-50 border border-red-200 px-3 py-2">
                <p class="text-[10px] font-black text-red-600">Member tidak ditemukan. Transaksi tanpa point.</p>
            </div>
            <div id="mobile-point-redeem-box" class="hidden mt-2 bg-blue-50 border border-blue-200 px-3 py-3">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <div>
                        <p class="text-[10px] font-black text-blue-700 uppercase tracking-widest">Tukar Point</p>
                        <p class="text-[9px] text-blue-500 font-bold" id="mobile-point-redeem-info">1 point = Rp 1.000</p>
                    </div>
                    <button type="button" onclick="setPointRedeemMax()" class="px-2 py-1 bg-blue-600 text-white text-[9px] font-black uppercase">Max</button>
                </div>
                <div class="flex gap-2">
                    <input type="number" id="mobile-point-redeem-input" min="0" value="0" oninput="onMobilePointRedeemInput()"
                        class="w-full bg-white border border-blue-100 text-sm py-2 px-3 focus:outline-none focus:ring-2 focus:ring-blue-100 transition-all" placeholder="Jumlah point yang ditukar">
                    <button type="button" onclick="clearPointRedeem()" class="px-3 py-2 border border-blue-100 text-blue-600 text-[10px] font-black uppercase bg-white">Reset</button>
                </div>
            </div>
        </div>

        <!-- Scrollable cart list -->
        <div class="flex-1 overflow-y-auto p-4 space-y-3 no-scrollbar" id="cart-container-mobile" style="max-height:50vh">
            <div class="cart-empty-state">
                <svg class="h-14 w-14 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
                <p class="text-sm font-bold uppercase tracking-widest">Keranjang Kosong</p>
                <p class="text-xs text-gray-400 mt-1">Tap produk di atas untuk menambahkan</p>
            </div>
        </div>

        <!-- Mobile Member + Diskon info -->
        <div class="px-4 py-3 bg-blue-50 border-t border-blue-100" id="mobile-member-bar" style="display:none">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 bg-blue-600 rounded-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-blue-800" id="mobile-member-nama">—</p>
                        <p class="text-[9px] text-blue-500 font-bold" id="mobile-member-point">0 pt</p>
                    </div>
                </div>
                <div id="mobile-diskon-info" class="text-right hidden">
                    <p class="text-[9px] font-black text-red-600 uppercase" id="mobile-diskon-label"></p>
                    <p class="text-xs font-black text-red-600" id="mobile-diskon-val"></p>
                </div>
            </div>
        </div>

        <!-- Mobile Summary + Bayar Button -->
        <div class="px-4 pt-3 pb-4 bg-white border-t border-subtle space-y-2">
            <div class="flex justify-between items-center">
                <div class="space-y-1">
                    <div class="flex items-center gap-3 text-[11px]">
                        <span class="text-gray-400 font-medium uppercase tracking-widest">Subtotal</span>
                        <span class="font-bold text-gray-600" id="subtotal-mobile">Rp 0</span>
                    </div>
                    <div id="diskon-row-mobile" class="hidden flex items-center gap-3 text-[11px]">
                        <span class="text-red-500 font-bold uppercase tracking-widest" id="diskon-label-mobile">Diskon</span>
                        <span class="font-black text-red-600" id="diskon-val-mobile">-Rp 0</span>
                    </div>
                    <div id="point-redeem-row-mobile" class="hidden flex items-center gap-3 text-[11px]">
                        <span class="text-purple-500 font-bold uppercase tracking-widest">Tukar Point</span>
                        <span class="font-black text-purple-600" id="point-redeem-val-mobile">-Rp 0 (0 pt)</span>
                    </div>
                    <div id="point-row-mobile" class="hidden flex items-center gap-3 text-[11px]">
                        <span class="text-blue-500 font-bold uppercase tracking-widest">+Point</span>
                        <span class="font-black text-blue-600" id="point-val-mobile">+0 pt</span>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[9px] text-gray-400 uppercase font-bold tracking-widest">Total</p>
                    <p class="text-2xl font-black text-blue-600" id="total-price-mobile-big">Rp 0</p>
                </div>
            </div>
            <button onclick="openPayment()" class="w-full bg-black text-white py-4 text-xs font-black uppercase tracking-[0.3em] hover:bg-gray-800 active:scale-[0.98] transition-all shadow-lg shadow-black/15 rounded-xl">
                💳 Bayar Sekarang
            </button>
        </div>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-2 flex justify-between items-center z-[100] shadow-lg" style="display:none !important">
        <!-- Hidden — diganti oleh cart sheet -->
    </nav>

    <!-- ── Payment Modal ──────────────────────────────────────────────── -->
    <div id="payment-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-md overflow-hidden shadow-2xl max-h-[92vh] overflow-y-auto no-scrollbar rounded-2xl">
            <div class="p-6 sm:p-8 text-center border-b border-subtle">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Total Tagihan</p>
                <h3 class="text-4xl font-black" id="modal-total">Rp 0</h3>
                <div id="modal-member-info" class="hidden mt-2 text-xs text-blue-600 font-bold"></div>
            </div>
            <div class="p-6 sm:p-8 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pilih Metode</p>
                <div class="grid grid-cols-2 gap-4">
                    <button id="btn-tunai" onclick="selectMethod('tunai')" class="p-4 border-2 border-black flex flex-col items-center gap-2 bg-black text-white transition-all rounded-xl">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span>Tunai</span>
                    </button>
                    <button id="btn-qris" onclick="selectMethod('qris')" class="p-4 border border-subtle flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all rounded-xl">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                        <span>QRIS / EDC</span>
                    </button>
                </div>
                <div id="tunai-input" class="hidden space-y-3 mt-4">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block">Uang Diterima</label>
                    <input type="number" id="bayar-input" placeholder="0" oninput="hitungKembalian()"
                        class="w-full border border-gray-200 px-4 py-3 text-lg font-bold focus:outline-none focus:border-black transition-colors rounded-lg">
                    <div class="flex flex-wrap gap-2" id="nominal-cepat"></div>
                    <div class="flex justify-between pt-1">
                        <span class="text-xs text-gray-400 font-medium uppercase tracking-widest">Kembalian</span>
                        <span id="kembalian-display" class="text-sm font-black text-gray-400">Rp 0</span>
                    </div>
                </div>
                <div id="qris-box" class="hidden mt-4">
                    <div class="border border-subtle bg-white p-5 text-center rounded-xl">
                        <div class="flex justify-between items-center mb-4 px-1">
                            <div class="text-left">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Nominal Transfer</p>
                                <p class="text-xl font-black" id="qris-total-label">Rp 0</p>
                            </div>
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1.5 bg-green-50 text-green-700 border border-green-200 rounded">Siap Scan</span>
                        </div>
                        <div id="qris-image-wrap" class="bg-white border-2 border-gray-100 inline-block p-3 shadow-sm rounded-xl">
                            <img src="<?= htmlspecialchars($qrisImage) ?>" alt="QRIS Koperasi BSDK" class="w-[220px] h-[220px] object-contain">
                        </div>
                        <div class="mt-4 space-y-1">
                            <p class="text-xs font-black uppercase tracking-widest">KOPERASI BSDK</p>
                            <p class="text-[10px] text-gray-400">NMID: ID1025377699398</p>
                            <p class="text-[9px] text-gray-400">Scan menggunakan aplikasi e-wallet atau m-banking manapun</p>
                        </div>
                        <button onclick="confirmQrisPayment()" class="mt-5 w-full bg-blue-600 text-white py-3 text-[10px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all rounded-lg">
                            ✓ Pembayaran Sudah Diterima
                        </button>
                    </div>
                </div>
            </div>
            <div class="p-6 sm:p-8 bg-gray-50 flex gap-3 border-t border-subtle">
                <button onclick="closePayment()" class="flex-1 py-4 text-[10px] font-black uppercase border border-subtle hover:bg-white transition-all rounded-lg">Batal</button>
                <button onclick="processPayment()" id="btn-konfirmasi" class="flex-1 py-4 text-[10px] font-black uppercase bg-blue-600 text-white shadow-lg shadow-blue-200 active:scale-95 transition-all rounded-lg">Konfirmasi</button>
            </div>
        </div>
    </div>

    <!-- ── Success Modal ────────────────────────────────────────────── -->
    <div id="success-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-sm overflow-hidden shadow-2xl text-center p-8 sm:p-10 rounded-2xl">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h3 class="text-xl font-black mb-2">Transaksi Berhasil!</h3>
            <p class="text-xs text-gray-400 uppercase tracking-widest mb-1" id="success-invoice"></p>
            <p class="text-2xl font-black text-blue-600 my-2" id="success-total"></p>
            <p class="text-xs text-red-500 font-bold hidden" id="success-diskon"></p>
            <p class="text-sm text-gray-500 font-medium" id="success-kembalian"></p>
            <div id="success-point-wrap" class="hidden mt-3 bg-blue-50 border border-blue-200 px-4 py-3 rounded-xl">
                <p class="text-[10px] font-bold uppercase tracking-widest text-blue-400 mb-1">Point Member</p>
                <p class="text-sm font-black text-blue-700" id="success-point-dapat"></p>
                <p class="text-[10px] text-blue-500 font-bold mt-0.5" id="success-point-total"></p>
            </div>
            <div id="bt-status-bar"></div>
            <div class="flex gap-3 mt-6">
                <button id="btn-struk" onclick="cetakStrukBluetooth()" class="flex-1 py-3 text-[10px] font-black uppercase border border-subtle hover:bg-gray-50 transition-all rounded-lg">🖨 Cetak Struk</button>
                <button onclick="resetTransaksiBaru()" class="flex-1 py-3 text-[10px] font-black uppercase bg-black text-white transition-all rounded-lg">Transaksi Baru</button>
            </div>
            <p class="mt-3 text-[9px] text-gray-400">
                Bluetooth tidak tersedia?
                <a id="link-struk-fallback" href="#" target="_blank" class="underline text-blue-500 font-bold">Buka struk manual</a>
            </p>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════
         JAVASCRIPT
         ════════════════════════════════════════════════════════════════════ -->
    <script>
        'use strict';

        // ── Mobile Menu ─────────────────────────────────────────────────────────────
        function toggleMobileMenu() {
            const o = document.getElementById('mobileMenuOverlay'),
                c = document.getElementById('mobileMenuContent');
            if (!o || !c) return;
            if (o.classList.contains('invisible')) {
                o.classList.remove('invisible');
                o.classList.add('opacity-100');
                c.classList.remove('translate-x-full');
            } else {
                o.classList.add('invisible');
                o.classList.remove('opacity-100');
                c.classList.add('translate-x-full');
            }
        }

        // ── Mobile Cart Sheet ────────────────────────────────────────────────────────
        function toggleMobileCart() {
            const sheet = document.getElementById('mobile-cart-sheet');
            const overlay = document.getElementById('cart-overlay');
            const chevron = document.getElementById('cart-chevron');
            if (!sheet) return;
            const expanded = sheet.classList.toggle('expanded');
            if (overlay) overlay.classList.toggle('hidden', !expanded);
            if (chevron) chevron.style.transform = expanded ? 'rotate(180deg)' : '';
        }

        function closeMobileCart() {
            const sheet = document.getElementById('mobile-cart-sheet');
            const overlay = document.getElementById('cart-overlay');
            const chevron = document.getElementById('cart-chevron');
            if (sheet) sheet.classList.remove('expanded');
            if (overlay) overlay.classList.add('hidden');
            if (chevron) chevron.style.transform = '';
        }

        // ── State ────────────────────────────────────────────────────────────────────
        const POS_ENDPOINT = <?= json_encode(basename($_SERVER['PHP_SELF'])) ?>;
        const OPERATOR_NAME = '<?= $operatorName ?>';
        const POINT_VALUE = 1000;

        let PRODUCTS = [];
        let cart = [];
        let currentCat = 'Semua';
        let selectedMethod = 'tunai';
        let activeMember = null;
        let activeDiskon = null;
        let pointRedeem = 0;
        let diskonPreviewTimeout = null;
        let customerDisplayLockedUntil = 0;
        let memberSuggestTimeout = null;
        let memberSuggestIndex = -1;
        let memberSuggestData = [];

        // ── Init ─────────────────────────────────────────────────────────────────────
        async function init() {
            updateClock();
            setInterval(updateClock, 1000);
            await loadProducts();
        }

        async function loadProducts() {
            try {
                const res = await fetch(POS_ENDPOINT + '?action=get_products', {
                    method: 'POST'
                });
                const d = await res.json();
                if (d.success) {
                    PRODUCTS = d.data;
                    renderProducts(PRODUCTS);
                } else showError('Gagal memuat produk: ' + d.message);
            } catch (e) {
                showError('Koneksi gagal.');
            }
        }

        // ── Member Autocomplete ──────────────────────────────────────────────────────
        async function onMemberInput() {
            const q = document.getElementById('member-input').value.trim();
            clearTimeout(memberSuggestTimeout);
            if (!q) {
                hideMemberSuggest();
                activeMember = null;
                pointRedeem = 0;
                document.getElementById('member-found').style.display = 'none';
                document.getElementById('member-notfound').style.display = 'none';
                updateUI();
                return;
            }
            activeMember = null;
            pointRedeem = 0;
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';
            memberSuggestTimeout = setTimeout(async () => {
                try {
                    const res = await fetch(POS_ENDPOINT + '?action=suggest_member', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            q
                        })
                    });
                    let data = [];
                    try {
                        data = await res.json();
                    } catch (err) {
                        hideMemberSuggest();
                        return;
                    }
                    memberSuggestData = Array.isArray(data) ? data : [];
                    memberSuggestIndex = -1;
                    renderMemberSuggest(memberSuggestData);
                } catch (e) {
                    const box = document.getElementById('member-suggest');
                    if (box) {
                        box.innerHTML = '<div class="px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-red-500">Gagal mengambil data member</div>';
                        box.classList.remove('hidden');
                    }
                }
            }, 200);
        }

        function renderSuggestBox(boxId, data) {
            const box = document.getElementById(boxId);
            if (!box) return;
            if (!Array.isArray(data) || !data.length) {
                box.innerHTML = '<div class="px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member tidak ditemukan</div>';
                box.classList.remove('hidden');
                return;
            }
            box.innerHTML = '';
            data.forEach((m, i) => {
                const item = document.createElement('div');
                item.className = 'suggest-item px-4 py-3 cursor-pointer border-b border-gray-50 last:border-0 flex justify-between items-center transition-colors';
                item.dataset.index = i;
                item.addEventListener('click', () => pilihMember(i));
                const left = document.createElement('div');
                left.className = 'min-w-0';
                const nama = document.createElement('p');
                nama.className = 'text-xs font-black text-gray-800 truncate';
                nama.textContent = m.nama || '-';
                const detail = document.createElement('p');
                detail.className = 'text-[9px] text-gray-400 font-bold uppercase tracking-wide mt-0.5';
                detail.textContent = (m.kode || '') + (m.no_hp ? ' - ' + m.no_hp : '');
                left.appendChild(nama);
                left.appendChild(detail);
                const point = document.createElement('span');
                point.className = 'text-[9px] font-black text-blue-600 bg-blue-50 px-2 py-1 ml-3 shrink-0 whitespace-nowrap';
                point.textContent = Number(m.point || 0).toLocaleString('id-ID') + ' pt';
                item.appendChild(left);
                item.appendChild(point);
                box.appendChild(item);
            });
            box.classList.remove('hidden');
        }

        function renderMemberSuggest(data) {
            renderSuggestBox('member-suggest', data);
        }

        function renderMobileMemberSuggest(data) {
            renderSuggestBox('mobile-member-suggest', data);
        }

        function hideMemberSuggest() {
            const desktopBox = document.getElementById('member-suggest');
            const mobileBox = document.getElementById('mobile-member-suggest');
            if (desktopBox) desktopBox.classList.add('hidden');
            if (mobileBox) mobileBox.classList.add('hidden');
            memberSuggestData = [];
            memberSuggestIndex = -1;
        }

        function pilihMember(index) {
            const m = memberSuggestData[index];
            if (!m) return;
            document.getElementById('member-input').value = m.kode || m.nama || m.no_hp || '';
            const mobileMemberInput = document.getElementById('mobile-member-input');
            if (mobileMemberInput) mobileMemberInput.value = m.kode || m.nama || m.no_hp || '';
            const mobileNotFound = document.getElementById('mobile-member-notfound');
            if (mobileNotFound) mobileNotFound.classList.add('hidden');
            pointRedeem = 0;
            activeMember = {
                id: m.id,
                kode: m.kode,
                nama: m.nama,
                no_hp: m.no_hp,
                point: m.point
            };
            document.getElementById('member-nama').innerText = m.nama;
            document.getElementById('member-point').innerText = Number(m.point).toLocaleString('id-ID');
            document.getElementById('member-found').style.display = 'flex';
            document.getElementById('member-notfound').style.display = 'none';
            hideMemberSuggest();
            updateUI();
        }

        function onMemberKeydown(e) {
            const box = document.getElementById('member-suggest');
            const items = box.querySelectorAll('.suggest-item');
            if (box.classList.contains('hidden') || !items.length) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    cariMember();
                }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                memberSuggestIndex = Math.min(memberSuggestIndex + 1, items.length - 1);
                highlightSuggest(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                memberSuggestIndex = Math.max(memberSuggestIndex - 1, 0);
                highlightSuggest(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (memberSuggestIndex >= 0) pilihMember(memberSuggestIndex);
                else cariMember();
            } else if (e.key === 'Escape') hideMemberSuggest();
        }

        function highlightSuggest(items) {
            items.forEach((el, i) => el.classList.toggle('bg-blue-50', i === memberSuggestIndex));
            if (memberSuggestIndex >= 0) items[memberSuggestIndex].scrollIntoView({
                block: 'nearest'
            });
        }

        async function cariMember() {
            const keyword = document.getElementById('member-input').value.trim();
            if (!keyword) return;
            hideMemberSuggest();
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';
            try {
                const res = await fetch(POS_ENDPOINT + '?action=cari_member', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        keyword
                    })
                });
                const d = await res.json();
                if (d.success) {
                    pointRedeem = 0;
                    activeMember = d.data;
                    document.getElementById('member-nama').innerText = d.data.nama;
                    document.getElementById('member-point').innerText = Number(d.data.point).toLocaleString('id-ID');
                    document.getElementById('member-found').style.display = 'flex';
                    const mobileMemberInput = document.getElementById('mobile-member-input');
                    if (mobileMemberInput) mobileMemberInput.value = d.data.kode || d.data.nama || d.data.no_hp || '';
                    const mobileNotFound = document.getElementById('mobile-member-notfound');
                    if (mobileNotFound) mobileNotFound.classList.add('hidden');
                } else {
                    activeMember = null;
                    document.getElementById('member-notfound').style.display = 'block';
                    const mobileNotFound = document.getElementById('mobile-member-notfound');
                    if (mobileNotFound) mobileNotFound.classList.remove('hidden');
                    setTimeout(() => {
                        document.getElementById('member-notfound').style.display = 'none';
                        if (mobileNotFound) mobileNotFound.classList.add('hidden');
                    }, 3000);
                }
            } catch (e) {
                activeMember = null;
            }
            updateUI();
        }

        function clearMember() {
            activeMember = null;
            activeDiskon = null;
            pointRedeem = 0;
            const desktopInput = document.getElementById('member-input');
            const mobileInput = document.getElementById('mobile-member-input');
            if (desktopInput) desktopInput.value = '';
            if (mobileInput) mobileInput.value = '';
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';
            const mobileNotFound = document.getElementById('mobile-member-notfound');
            if (mobileNotFound) mobileNotFound.classList.add('hidden');
            hideMemberSuggest();
            updateUI();
        }

        async function onMobileMemberInput() {
            const input = document.getElementById('mobile-member-input');
            const q = input ? input.value.trim() : '';
            const desktopInput = document.getElementById('member-input');
            if (desktopInput) desktopInput.value = q;
            clearTimeout(memberSuggestTimeout);
            if (!q) {
                hideMemberSuggest();
                activeMember = null;
                pointRedeem = 0;
                const mobileNotFound = document.getElementById('mobile-member-notfound');
                if (mobileNotFound) mobileNotFound.classList.add('hidden');
                document.getElementById('member-found').style.display = 'none';
                document.getElementById('member-notfound').style.display = 'none';
                updateUI();
                return;
            }
            activeMember = null;
            pointRedeem = 0;
            const mobileNotFound = document.getElementById('mobile-member-notfound');
            if (mobileNotFound) mobileNotFound.classList.add('hidden');
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';
            memberSuggestTimeout = setTimeout(async () => {
                try {
                    const res = await fetch(POS_ENDPOINT + '?action=suggest_member', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            q
                        })
                    });
                    let data = [];
                    try {
                        data = await res.json();
                    } catch (err) {
                        hideMemberSuggest();
                        return;
                    }
                    memberSuggestData = Array.isArray(data) ? data : [];
                    memberSuggestIndex = -1;
                    renderMobileMemberSuggest(memberSuggestData);
                } catch (e) {
                    const box = document.getElementById('mobile-member-suggest');
                    if (box) {
                        box.innerHTML = '<div class="px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-red-500">Gagal mengambil data member</div>';
                        box.classList.remove('hidden');
                    }
                }
            }, 200);
        }

        function onMobileMemberKeydown(e) {
            const box = document.getElementById('mobile-member-suggest');
            const items = box ? box.querySelectorAll('.suggest-item') : [];
            if (!box || box.classList.contains('hidden') || !items.length) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    cariMemberMobile();
                }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                memberSuggestIndex = Math.min(memberSuggestIndex + 1, items.length - 1);
                highlightSuggest(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                memberSuggestIndex = Math.max(memberSuggestIndex - 1, 0);
                highlightSuggest(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (memberSuggestIndex >= 0) pilihMember(memberSuggestIndex);
                else cariMemberMobile();
            } else if (e.key === 'Escape') hideMemberSuggest();
        }

        async function cariMemberMobile() {
            const mobileInput = document.getElementById('mobile-member-input');
            const desktopInput = document.getElementById('member-input');
            if (desktopInput && mobileInput) desktopInput.value = mobileInput.value.trim();
            await cariMember();
            if (mobileInput && activeMember) mobileInput.value = activeMember.kode || activeMember.nama || activeMember.no_hp || '';
            const mobileNotFound = document.getElementById('mobile-member-notfound');
            if (mobileNotFound) mobileNotFound.classList.toggle('hidden', !!activeMember);
        }

        function onMobilePointRedeemInput() {
            const input = document.getElementById('mobile-point-redeem-input');
            pointRedeem = Math.max(0, parseInt(input?.value || 0));
            const maxPoint = getMaxPointRedeem();
            if (pointRedeem > maxPoint) pointRedeem = maxPoint;
            if (input) input.value = pointRedeem;
            const desktopInput = document.getElementById('point-redeem-input');
            if (desktopInput) desktopInput.value = pointRedeem;
            updateUI(false);
        }

        // ── Produk ───────────────────────────────────────────────────────────────────
        function setCategory(cat) {
            currentCat = cat;
            document.querySelectorAll('.category-btn').forEach(b =>
                b.classList.toggle('active-category', b.textContent.trim().toLowerCase() === cat.trim().toLowerCase())
            );
            filterProducts();
        }

        function filterProducts() {
            const q = document.getElementById('search-input').value.toLowerCase();
            renderProducts(PRODUCTS.filter(p =>
                ((p.nama || '').toLowerCase().includes(q) || (p.kode || '').toLowerCase().includes(q) || (p.kategori || '').toLowerCase().includes(q)) &&
                (currentCat === 'Semua' || (p.kategori || '') === currentCat)
            ));
        }

        function renderProducts(data) {
            const list = document.getElementById('product-list');
            if (!data.length) {
                list.innerHTML = `<div class="col-span-full py-20 text-center opacity-20"><p class="text-xs font-bold uppercase tracking-[0.2em]">Produk tidak ditemukan</p></div>`;
                return;
            }
            list.innerHTML = data.map(p => {
                const habis = p.stok <= 0,
                    low = p.stok > 0 && p.stok <= 5;
                return `<div onclick="${habis?'':'addToCart('+p.id+')'}"
            class="bg-white p-3 rounded-2xl border border-subtle transition-all cursor-pointer group active:scale-95 shadow-sm ${habis?'opacity-40 cursor-not-allowed':'hover:border-black hover:shadow-md'}">
            <div class="w-full aspect-square bg-gray-50 rounded-xl mb-3 flex items-center justify-center text-gray-200 ${habis?'':'group-hover:text-blue-500'} transition-colors">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <p class="text-[8px] font-black uppercase text-gray-400 tracking-widest mb-1">${p.kategori||'-'}</p>
            <h4 class="text-[12px] font-bold leading-tight h-8 overflow-hidden text-slate-800">${p.nama}</h4>
            <p class="text-xs font-black text-black mt-1">${formatRp(p.harga_jual)}</p>
            <div class="mt-1">${habis
                ? '<span class="text-[8px] font-black text-red-500 uppercase">Habis</span>'
                : low ? `<span class="text-[8px] font-black text-orange-500 uppercase">Sisa ${p.stok}</span>`
                      : `<span class="text-[8px] text-gray-300 uppercase">Stok ${p.stok}</span>`
            }</div></div>`;
            }).join('');
        }

        // ── Cart ─────────────────────────────────────────────────────────────────────
        function addToCart(id) {
            const pid = parseInt(id),
                p = PRODUCTS.find(x => parseInt(x.id) === pid);
            if (!p || p.stok <= 0) return;
            const inCart = cart.find(x => parseInt(x.id) === pid);
            if (inCart) {
                if (inCart.qty >= parseInt(p.stok)) {
                    alert(`Stok ${p.nama} hanya ${p.stok} pcs.`);
                    return;
                }
                inCart.qty++;
            } else {
                cart.push({
                    id: pid,
                    kode: p.kode,
                    nama: p.nama,
                    kategori: p.kategori,
                    harga_jual: parseInt(p.harga_jual),
                    stok: parseInt(p.stok),
                    satuan: p.satuan,
                    qty: 1
                });
            }
            // Auto-hint the sheet on mobile
            const sheet = document.getElementById('mobile-cart-sheet');
            if (sheet && !sheet.classList.contains('expanded') && window.innerWidth < 1024) {
                sheet.style.transition = 'transform .2s ease';
                sheet.style.transform = 'translateY(calc(100% - 90px))';
                setTimeout(() => {
                    sheet.style.transition = '';
                    sheet.style.transform = '';
                }, 350);
            }
            updateUI();
        }

        function updateQty(id, delta) {
            const pid = parseInt(id),
                item = cart.find(i => parseInt(i.id) === pid);
            if (!item) return;
            const max = (PRODUCTS.find(p => parseInt(p.id) === pid) || {
                stok: item.stok
            }).stok;
            const next = item.qty + parseInt(delta);
            if (next <= 0) cart = cart.filter(i => parseInt(i.id) !== pid);
            else if (next > max) {
                alert(`Stok ${item.nama} hanya ${max} pcs.`);
                return;
            } else item.qty = next;
            updateUI();
        }

        function clearCart(resetMember = false) {
            cart = [];
            activeDiskon = null;
            if (resetMember) {
                activeMember = null;
                pointRedeem = 0;
                const mi = document.getElementById('member-input');
                const mmi = document.getElementById('mobile-member-input');
                if (mi) mi.value = '';
                if (mmi) mmi.value = '';
                document.getElementById('member-found').style.display = 'none';
                document.getElementById('member-notfound').style.display = 'none';
                hideMemberSuggest();
            }
            closeMobileCart();
            updateUI();
        }

        function getSubtotal() {
            return cart.reduce((a, i) => a + i.harga_jual * i.qty, 0);
        }

        function getDiskonAmount() {
            return activeDiskon ? parseInt(activeDiskon.diskon_total || 0) : 0;
        }

        function getTotalBeforePoint() {
            return activeDiskon ? parseInt(activeDiskon.total || 0) : Math.max(0, getSubtotal() - getDiskonAmount());
        }

        function getPointRedeemAmount() {
            if (!activeMember || pointRedeem <= 0) return 0;
            return Math.min(pointRedeem * POINT_VALUE, getTotalBeforePoint());
        }

        function getGrandTotal() {
            return Math.max(0, getTotalBeforePoint() - getPointRedeemAmount());
        }

        function getMaxPointRedeem() {
            if (!activeMember) return 0;
            return Math.max(0, Math.min(parseInt(activeMember.point || 0), Math.floor(getTotalBeforePoint() / POINT_VALUE)));
        }

        function onPointRedeemInput() {
            const input = document.getElementById('point-redeem-input');
            pointRedeem = Math.max(0, parseInt(input?.value || 0));
            const maxPoint = getMaxPointRedeem();
            if (pointRedeem > maxPoint) pointRedeem = maxPoint;
            if (input) input.value = pointRedeem;
            updateUI(false);
        }

        function setPointRedeemMax() {
            pointRedeem = getMaxPointRedeem();
            const i = document.getElementById('point-redeem-input');
            const mi = document.getElementById('mobile-point-redeem-input');
            if (i) i.value = pointRedeem;
            if (mi) mi.value = pointRedeem;
            updateUI(false);
        }

        function clearPointRedeem() {
            pointRedeem = 0;
            const i = document.getElementById('point-redeem-input');
            const mi = document.getElementById('mobile-point-redeem-input');
            if (i) i.value = 0;
            if (mi) mi.value = 0;
            updateUI(false);
        }

        // ── Render Cart Items (unified, used for both desktop & mobile) ──────────────
        function buildCartHTML() {
            if (!cart.length) return `<div class="cart-empty-state">
            <svg class="h-16 w-16 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            <p class="text-sm font-bold uppercase tracking-widest">Keranjang Kosong</p>
            <p class="text-xs text-gray-400 mt-1">Tap produk untuk menambahkan</p></div>`;

            return cart.map(item => `
        <div class="cart-card">
            <p class="item-name">${item.nama}</p>
            <p class="item-meta">${item.kategori||''} ${item.satuan ? '· '+item.satuan : ''}</p>
            <div class="item-price-row">
                ${item.diskon_item > 0
                    ? `<span class="price-strike">${formatRp(item.harga_jual)}</span>
                       <svg class="w-3 h-3 text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                       <span class="price-disc">${formatRp(item.harga_final||item.harga_jual)}</span>`
                    : `<span>${formatRp(item.harga_jual)}</span>`}
            </div>
            ${item.diskon_item > 0 ? `<div class="item-disc-tag">🏷 -${formatRp(item.diskon_item)}${item.diskon_item_nama?' · '+item.diskon_item_nama:''}</div>` : ''}
            <div class="item-bottom">
                <div class="qty-control">
                    <button class="qty-btn" onclick="updateQty(${item.id},-1)">−</button>
                    <span class="qty-num">${item.qty}</span>
                    <button class="qty-btn" onclick="updateQty(${item.id}, 1)">+</button>
                </div>
                <span class="item-subtotal ${item.diskon_item>0?'has-disc':''}">${formatRp((item.harga_final||item.harga_jual)*item.qty)}</span>
            </div>
        </div>`).join('');
        }

        function applyTotalsToUI() {
            const subtotal = getSubtotal(),
                diskon = getDiskonAmount(),
                potonganPoint = getPointRedeemAmount(),
                total = getGrandTotal();

            // Desktop elements
            const sub = document.getElementById('subtotal');
            if (sub) sub.innerText = formatRp(subtotal);
            const tp = document.getElementById('total-price');
            if (tp) tp.innerText = formatRp(total);
            const mt = document.getElementById('modal-total');
            if (mt) mt.innerText = formatRp(total);
            const ql = document.getElementById('qris-total-label');
            if (ql) ql.innerText = formatRp(total);

            const diskonWrap = document.getElementById('diskon-preview');
            if (diskon > 0 && activeDiskon) {
                document.getElementById('diskon-preview-label').innerText = activeDiskon.label || 'Diskon';
                document.getElementById('diskon-preview-val').innerText = '-' + formatRp(diskon);
                diskonWrap.classList.remove('hidden');
                diskonWrap.style.display = 'flex';
            } else {
                if (diskonWrap) {
                    diskonWrap.classList.add('hidden');
                    diskonWrap.style.display = 'none';
                }
            }

            const prWrap = document.getElementById('point-redeem-preview');
            if (potonganPoint > 0) {
                document.getElementById('point-redeem-preview-val').innerText = '-' + formatRp(potonganPoint) + ' (' + pointRedeem + ' pt)';
                prWrap.classList.remove('hidden');
                prWrap.style.display = 'flex';
            } else {
                if (prWrap) {
                    prWrap.classList.add('hidden');
                    prWrap.style.display = 'none';
                }
            }

            // Mobile elements
            const smobile = document.getElementById('subtotal-mobile');
            if (smobile) smobile.innerText = formatRp(subtotal);
            const tpm = document.getElementById('total-price-mobile');
            if (tpm) tpm.innerText = formatRp(total);
            const tpmb = document.getElementById('total-price-mobile-big');
            if (tpmb) tpmb.innerText = formatRp(total);
            const sumMobile = document.getElementById('cart-summary-mobile');
            if (sumMobile) {
                const itemCount = cart.reduce((a, i) => a + i.qty, 0);
                sumMobile.innerText = itemCount > 0 ? itemCount + ' item · ' + formatRp(total) : 'Kosong · Rp 0';
            }
            const diskonRowM = document.getElementById('diskon-row-mobile');
            if (diskon > 0 && activeDiskon) {
                if (document.getElementById('diskon-label-mobile')) document.getElementById('diskon-label-mobile').innerText = activeDiskon.label || 'Diskon';
                if (document.getElementById('diskon-val-mobile')) document.getElementById('diskon-val-mobile').innerText = '-' + formatRp(diskon);
                if (diskonRowM) {
                    diskonRowM.classList.remove('hidden');
                    diskonRowM.style.display = 'flex';
                }
            } else {
                if (diskonRowM) {
                    diskonRowM.classList.add('hidden');
                    diskonRowM.style.display = 'none';
                }
            }

            const pointRedeemRowM = document.getElementById('point-redeem-row-mobile');
            const pointRedeemValM = document.getElementById('point-redeem-val-mobile');
            if (potonganPoint > 0 && pointRedeem > 0) {
                if (pointRedeemValM) pointRedeemValM.innerText = '-' + formatRp(potonganPoint) + ' (' + pointRedeem + ' pt)';
                if (pointRedeemRowM) {
                    pointRedeemRowM.classList.remove('hidden');
                    pointRedeemRowM.style.display = 'flex';
                }
            } else {
                if (pointRedeemRowM) {
                    pointRedeemRowM.classList.add('hidden');
                    pointRedeemRowM.style.display = 'none';
                }
            }

            return {
                subtotal,
                diskon,
                pointRedeem,
                potonganPoint,
                total
            };
        }

        function updateCartBadge() {
            const totalItems = cart.reduce((a, i) => a + i.qty, 0);
            const badge = document.getElementById('cart-badge-mobile');
            if (badge) {
                badge.textContent = totalItems;
                badge.style.display = totalItems > 0 ? 'inline-flex' : 'none';
            }
        }

        function updateMobileMemberBar() {
            const bar = document.getElementById('mobile-member-bar');
            if (!bar) return;
            if (activeMember) {
                bar.style.display = 'block';
                const nn = document.getElementById('mobile-member-nama');
                if (nn) nn.innerText = activeMember.nama;
                const np = document.getElementById('mobile-member-point');
                if (np) np.innerText = Number(activeMember.point || 0).toLocaleString('id-ID') + ' pt';
                const di = document.getElementById('mobile-diskon-info');
                const diskon = getDiskonAmount();
                if (diskon > 0 && activeDiskon) {
                    if (di) di.classList.remove('hidden');
                    const dl = document.getElementById('mobile-diskon-label');
                    if (dl) dl.innerText = activeDiskon.label || 'Diskon';
                    const dv = document.getElementById('mobile-diskon-val');
                    if (dv) dv.innerText = '-' + formatRp(diskon);
                } else {
                    if (di) di.classList.add('hidden');
                }
            } else {
                bar.style.display = 'none';
            }
        }

        async function refreshDiskonPreview() {
            const subtotal = getSubtotal();
            if (subtotal <= 0) {
                activeDiskon = null;
                applyTotalsToUI();
                return;
            }
            try {
                const res = await fetch(POS_ENDPOINT + '?action=hitung_diskon', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items: cart.map(i => ({
                            id: parseInt(i.id),
                            qty: parseInt(i.qty)
                        })),
                        member_id: activeMember ? activeMember.id : null,
                        point_pakai: activeMember ? pointRedeem : 0
                    })
                });
                const d = await res.json();
                if (d.success && d.data && parseInt(d.data.diskon_total || 0) > 0) {
                    activeDiskon = d.data;
                    const parts = [];
                    if (activeDiskon.diskon_terpilih_nama) parts.push(activeDiskon.diskon_terpilih_nama);
                    else if (parseInt(activeDiskon.item_diskon || 0) > 0) parts.push('Diskon Barang');
                    else if (parseInt(activeDiskon.transaksi_diskon || 0) > 0) parts.push('Diskon Transaksi');
                    activeDiskon.label = parts.join('') || 'Diskon';
                    if (Array.isArray(activeDiskon.lines)) {
                        cart = cart.map(item => {
                            const line = activeDiskon.lines.find(l => parseInt(l.produk_id) === parseInt(item.id));
                            return line ? {
                                ...item,
                                diskon_item: parseInt(line.diskon_item || 0),
                                harga_final: parseInt(line.harga_final || item.harga_jual),
                                diskon_item_nama: line.diskon_item_nama || null
                            } : item;
                        });
                    }
                } else {
                    activeDiskon = null;
                    cart = cart.map(item => ({
                        ...item,
                        diskon_item: 0,
                        harga_final: item.harga_jual,
                        diskon_item_nama: null
                    }));
                }
            } catch (e) {
                activeDiskon = null;
            }
            updateUI(false);
        }

        function scheduleDiskonPreview() {
            clearTimeout(diskonPreviewTimeout);
            diskonPreviewTimeout = setTimeout(refreshDiskonPreview, 150);
        }

        function updateUI(refreshDiskon = true) {
            // Render cart in both desktop and mobile containers
            const cartHTML = buildCartHTML();
            const desktopCart = document.getElementById('cart-container');
            const mobileCart = document.getElementById('cart-container-mobile');
            if (desktopCart) desktopCart.innerHTML = cartHTML;
            if (mobileCart) mobileCart.innerHTML = cartHTML;

            const totals = applyTotalsToUI();
            const total = totals.total;

            // Point redeem box (desktop only)
            const redeemBox = document.getElementById('point-redeem-box');
            const redeemInput = document.getElementById('point-redeem-input');
            const redeemInfo = document.getElementById('point-redeem-info');
            const mobileRedeemBox = document.getElementById('mobile-point-redeem-box');
            const mobileRedeemInput = document.getElementById('mobile-point-redeem-input');
            const mobileRedeemInfo = document.getElementById('mobile-point-redeem-info');
            if (activeMember) {
                const maxPoint = getMaxPointRedeem();
                if (pointRedeem > maxPoint) pointRedeem = maxPoint;
                const redeemText = `Saldo ${Number(activeMember.point||0).toLocaleString('id-ID')} pt · Maks ${maxPoint.toLocaleString('id-ID')} pt · 1 pt = ${formatRp(POINT_VALUE)}`;
                if (redeemInput) redeemInput.value = pointRedeem;
                if (mobileRedeemInput) mobileRedeemInput.value = pointRedeem;
                if (redeemInfo) redeemInfo.innerText = redeemText;
                if (mobileRedeemInfo) mobileRedeemInfo.innerText = redeemText;
                if (redeemBox) redeemBox.classList.remove('hidden');
                if (mobileRedeemBox) mobileRedeemBox.classList.remove('hidden');
            } else {
                pointRedeem = 0;
                if (redeemInput) redeemInput.value = 0;
                if (mobileRedeemInput) mobileRedeemInput.value = 0;
                if (redeemBox) redeemBox.classList.add('hidden');
                if (mobileRedeemBox) mobileRedeemBox.classList.add('hidden');
            }

            const pointDapat = activeMember ? Math.floor(total / 10000) : 0;
            const ppWrap = document.getElementById('point-preview');
            if (activeMember && pointDapat > 0) {
                document.getElementById('point-preview-val').innerText = '+' + pointDapat + ' pt';
                if (ppWrap) {
                    ppWrap.classList.remove('hidden');
                    ppWrap.style.display = 'flex';
                }
            } else {
                if (ppWrap) {
                    ppWrap.classList.add('hidden');
                    ppWrap.style.display = 'none';
                }
            }

            // Mobile point row
            const pointRowM = document.getElementById('point-row-mobile');
            if (activeMember && pointDapat > 0) {
                const pvm = document.getElementById('point-val-mobile');
                if (pvm) pvm.innerText = '+' + pointDapat + ' pt';
                if (pointRowM) {
                    pointRowM.classList.remove('hidden');
                    pointRowM.style.display = 'flex';
                }
            } else {
                if (pointRowM) {
                    pointRowM.classList.add('hidden');
                    pointRowM.style.display = 'none';
                }
            }

            const mInfo = document.getElementById('modal-member-info');
            if (activeMember) {
                mInfo.innerText = `Member: ${activeMember.nama} · +${pointDapat} point didapat`;
                mInfo.classList.remove('hidden');
            } else mInfo.classList.add('hidden');

            hitungKembalian();
            renderNominalCepat(total);
            updateCartBadge();
            updateMobileMemberBar();
            if (refreshDiskon) scheduleDiskonPreview();
            updateCustomerDisplay();
        }

        function renderNominalCepat(total) {
            const wrap = document.getElementById('nominal-cepat');
            if (!wrap) return;
            const pecahan = [2000, 5000, 10000, 20000, 50000, 100000];
            const sug = [];
            for (const p of pecahan) {
                const v = Math.ceil(total / p) * p;
                if (v >= total && !sug.includes(v) && sug.length < 4) sug.push(v);
            }
            wrap.innerHTML = sug.map(n => `<button onclick="document.getElementById('bayar-input').value=${n};hitungKembalian()" class="flex-1 py-1.5 text-[9px] font-bold border border-subtle hover:bg-gray-100 transition-all">${formatRp(n)}</button>`).join('');
        }

        // ── Payment ──────────────────────────────────────────────────────────────────
        function openPayment() {
            if (!cart.length) return;
            document.getElementById('payment-modal').style.display = 'flex';
            selectMethod('tunai');
        }

        function closePayment() {
            document.getElementById('payment-modal').style.display = 'none';
        }

        function selectMethod(method) {
            selectedMethod = method;
            const ti = document.getElementById('tunai-input'),
                qb = document.getElementById('qris-box'),
                bk = document.getElementById('btn-konfirmasi');
            document.getElementById('btn-tunai').className = method === 'tunai' ? 'p-4 border-2 border-black flex flex-col items-center gap-2 bg-black text-white transition-all rounded-xl' : 'p-4 border border-subtle flex flex-col items-center gap-2 hover:border-black hover:text-black transition-all rounded-xl';
            document.getElementById('btn-qris').className = method === 'qris' ? 'p-4 border-2 border-blue-600 flex flex-col items-center gap-2 bg-blue-600 text-white transition-all rounded-xl' : 'p-4 border border-subtle flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all rounded-xl';
            if (method === 'tunai') {
                ti.classList.remove('hidden');
                qb.classList.add('hidden');
                bk.innerText = 'Konfirmasi';
                setTimeout(() => document.getElementById('bayar-input').focus(), 50);
            } else {
                ti.classList.add('hidden');
                qb.classList.remove('hidden');
                bk.innerText = 'Konfirmasi QRIS';
            }
        }

        function hitungKembalian() {
            const total = getGrandTotal(),
                bayar = parseInt(document.getElementById('bayar-input')?.value || 0);
            const kem = Math.max(0, bayar - total);
            const el = document.getElementById('kembalian-display');
            if (el) {
                el.innerText = formatRp(kem);
                el.className = `text-sm font-black ${bayar>=total&&bayar>0?'text-green-600':'text-gray-400'}`;
            }
        }

        function confirmQrisPayment() {
            if (confirm('Konfirmasi pembayaran QRIS sudah diterima?')) processPayment();
        }

        async function processPayment() {
            if (!cart.length) return;
            await refreshDiskonPreview();
            const total = getGrandTotal();
            let bayar = 0;
            if (selectedMethod === 'tunai') {
                bayar = parseInt(document.getElementById('bayar-input').value || 0);
                if (bayar < total) {
                    alert('Uang bayar kurang: ' + formatRp(total));
                    return;
                }
            } else bayar = total;

            const btn = document.getElementById('btn-konfirmasi');
            btn.disabled = true;
            btn.innerText = 'Memproses...';
            try {
                const res = await fetch(POS_ENDPOINT + '?action=simpan_transaksi', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items: cart.map(i => ({
                            id: parseInt(i.id),
                            qty: parseInt(i.qty)
                        })),
                        bayar,
                        member_id: activeMember ? activeMember.id : null,
                        point_pakai: activeMember ? pointRedeem : 0
                    })
                });
                const d = await res.json();
                if (d.success) {
                    closePayment();
                    document.getElementById('success-invoice').innerText = d.invoice;
                    document.getElementById('success-total').innerText = formatRp(d.total);
                    const sd = document.getElementById('success-diskon');
                    if (sd && parseInt(d.diskon || 0) > 0) {
                        sd.innerText = `Diskon barang: -${formatRp(d.diskon_item||0)} · Diskon transaksi: -${formatRp(d.diskon_transaksi||0)} · Total diskon: -${formatRp(d.diskon||0)}`;
                        sd.classList.remove('hidden');
                    } else if (sd) {
                        sd.classList.add('hidden');
                        sd.innerText = '';
                    }
                    if (parseInt(d.point_pakai || 0) > 0) {
                        const info = document.getElementById('success-diskon');
                        const current = info.innerText ? info.innerText + ' · ' : '';
                        info.innerText = current + `Tukar point: -${formatRp(d.nilai_point_pakai||0)} (${d.point_pakai} pt)`;
                        info.classList.remove('hidden');
                    }
                    document.getElementById('success-kembalian').innerText = selectedMethod === 'tunai' ? 'Kembalian: ' + formatRp(d.kembalian) : 'QRIS – Lunas ✓';
                    const pw = document.getElementById('success-point-wrap');
                    if (activeMember && d.point_dapat > 0) {
                        document.getElementById('success-point-dapat').innerText = `+${d.point_dapat} point diperoleh transaksi ini`;
                        document.getElementById('success-point-total').innerText = `Total point ${activeMember.nama}: ${d.point_total.toLocaleString('id-ID')} pt`;
                        pw.classList.remove('hidden');
                        document.getElementById('member-point').innerText = d.point_total.toLocaleString('id-ID');
                        activeMember.point = d.point_total;
                    } else pw.classList.add('hidden');
                    const btBar = document.getElementById('bt-status-bar');
                    if (btBar) {
                        btBar.className = '';
                        btBar.style.display = 'none';
                        btBar.innerText = '';
                    }
                    prepareReceiptData(d);
                    document.getElementById('link-struk-fallback').href = 'struk.php?invoice=' + encodeURIComponent(d.invoice) + '&print=1';
                    document.getElementById('success-modal').style.display = 'flex';
                    showThankYouDisplay(d);
                    setTimeout(() => {
                        clearCart(true);
                        resetCustomerDisplay();
                        customerDisplayLockedUntil = 0;
                        const bi = document.getElementById('bayar-input');
                        if (bi) bi.value = '';
                    }, 10000);
                    await loadProducts();
                } else alert('Gagal: ' + d.message);
            } catch (e) {
                alert('Kesalahan jaringan.');
            } finally {
                btn.disabled = false;
                btn.innerText = selectedMethod === 'qris' ? 'Konfirmasi QRIS' : 'Konfirmasi';
            }
        }

        function resetTransaksiBaru() {
            clearCart(true);
            const bi = document.getElementById('bayar-input');
            if (bi) bi.value = '';
            document.getElementById('success-modal').style.display = 'none';
            closeMobileCart();
            setTimeout(() => document.getElementById('search-input')?.focus(), 100);
        }

        // ── Barcode ──────────────────────────────────────────────────────────────────
        function handleBarcodeScan(code) {
            const clean = String(code || '').trim().toLowerCase();
            if (!clean) return;
            const found = PRODUCTS.find(p => String(p.kode || '').trim().toLowerCase() === clean);
            if (found) {
                addToCart(found.id);
                const inp = document.getElementById('search-input');
                if (inp) {
                    inp.value = '';
                    filterProducts();
                    inp.focus();
                }
            } else {
                alert(`Produk "${code}" tidak ditemukan.`);
                document.getElementById('search-input')?.focus();
            }
        }

        // ── Helpers ──────────────────────────────────────────────────────────────────
        function formatRp(n) {
            return 'Rp ' + Math.floor(n).toLocaleString('id-ID');
        }

        function updateClock() {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('id-ID', {
                hour12: false
            });
        }

        function showError(m) {
            document.getElementById('product-list').innerHTML = `<div class="col-span-full py-20 text-center text-red-400"><p class="text-xs font-bold uppercase">${m}</p></div>`;
        }

        // ── Event Listeners ──────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const o = document.getElementById('mobileMenuOverlay');
            if (o) o.addEventListener('click', e => {
                if (e.target === o) toggleMobileMenu();
            });
            const inp = document.getElementById('search-input');
            if (inp) {
                inp.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        handleBarcodeScan(inp.value);
                    }
                });
                inp.focus();
            }
            document.addEventListener('click', e => {
                const mi = document.getElementById('member-input'),
                    ms = document.getElementById('member-suggest'),
                    mmi = document.getElementById('mobile-member-input'),
                    mms = document.getElementById('mobile-member-suggest');
                const outsideDesktop = !mi || !ms || (!mi.contains(e.target) && !ms.contains(e.target));
                const outsideMobile = !mmi || !mms || (!mmi.contains(e.target) && !mms.contains(e.target));
                if (outsideDesktop && outsideMobile) hideMemberSuggest();
            });
        });

        window.onload = () => {
            init();
            setTimeout(() => document.getElementById('search-input')?.focus(), 300);
            updateCustomerDisplay();
            setInterval(updateCustomerDisplay, 2000);
        };

        // ── Customer Display ─────────────────────────────────────────────────────────
        function getCustomerDisplayPayload() {
            return {
                mode: 'cart',
                items: cart.map(i => ({
                    nama: i.nama,
                    qty: i.qty,
                    harga: i.harga_final || i.harga_jual,
                    subtotal: (i.harga_final || i.harga_jual) * i.qty,
                    diskon: i.diskon_item || 0
                })),
                subtotal: getSubtotal(),
                diskon: getDiskonAmount(),
                total: getGrandTotal(),
                member: activeMember ? {
                    nama: activeMember.nama,
                    point: Math.floor(getGrandTotal() / 10000)
                } : null
            };
        }

        function updateCustomerDisplay() {
            if (Date.now() < customerDisplayLockedUntil) return;
            fetch('update_display.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(getCustomerDisplayPayload()),
                keepalive: true
            }).catch(() => {});
        }

        function showThankYouDisplay(dataTransaksi) {
            customerDisplayLockedUntil = Date.now() + 10000;
            const data = {
                mode: 'thank_you',
                items: cart.map(i => ({
                    nama: i.nama,
                    qty: i.qty,
                    harga: i.harga_final || i.harga_jual,
                    subtotal: (i.harga_final || i.harga_jual) * i.qty,
                    diskon: i.diskon_item || 0
                })),
                subtotal: parseInt(dataTransaksi.subtotal || getSubtotal()),
                diskon: parseInt(dataTransaksi.diskon || getDiskonAmount()),
                point_pakai: parseInt(dataTransaksi.point_pakai || 0),
                nilai_point_pakai: parseInt(dataTransaksi.nilai_point_pakai || 0),
                total: parseInt(dataTransaksi.total || getGrandTotal()),
                bayar: parseInt(dataTransaksi.bayar || 0),
                kembalian: parseInt(dataTransaksi.kembalian || 0),
                invoice: dataTransaksi.invoice || '',
                metode: selectedMethod === 'qris' ? 'QRIS / EDC' : 'TUNAI',
                member: activeMember ? {
                    nama: activeMember.nama,
                    point: parseInt(dataTransaksi.point_dapat || 0),
                    point_total: parseInt(dataTransaksi.point_total || activeMember.point || 0)
                } : null
            };
            fetch('update_display.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                keepalive: true
            }).catch(() => {});
        }

        function resetCustomerDisplay() {
            fetch('update_display.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    mode: 'cart',
                    items: [],
                    subtotal: 0,
                    diskon: 0,
                    total: 0,
                    member: null
                }),
                keepalive: true
            }).catch(() => {});
        }
        window.addEventListener('beforeunload', () => {
            navigator.sendBeacon('update_display.php', new Blob([JSON.stringify({
                mode: 'cart',
                items: [],
                subtotal: 0,
                diskon: 0,
                total: 0,
                member: null
            })], {
                type: 'application/json'
            }));
        });

        // ════════════════════════════════════════════════════════════════════════════
        //  BLUETOOTH ESC/POS ENGINE
        // ════════════════════════════════════════════════════════════════════════════
        const BT_PRINTER_CONFIG = [{
            service: '000018f0-0000-1000-8000-00805f9b34fb',
            characteristic: '00002af1-0000-1000-8000-00805f9b34fb'
        }, {
            service: '49535343-fe7d-4ae5-8fa9-9fafd205e455',
            characteristic: '49535343-8841-43f4-a8d4-ecbe34729bb3'
        }, {
            service: '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
            characteristic: '6e400002-b5a3-f393-e0a9-e50e24dcca9e'
        }];
        const ESC_BYTE = 0x1B,
            GS_BYTE = 0x1D;
        const ESCPOS = {
            init: [ESC_BYTE, 0x40],
            alignLeft: [ESC_BYTE, 0x61, 0x00],
            alignCenter: [ESC_BYTE, 0x61, 0x01],
            boldOn: [ESC_BYTE, 0x45, 0x01],
            boldOff: [ESC_BYTE, 0x45, 0x00],
            fontBig: [GS_BYTE, 0x21, 0x11],
            fontNormal: [GS_BYTE, 0x21, 0x00],
            feed: (n) => [ESC_BYTE, 0x64, n],
            cut: [GS_BYTE, 0x56, 0x41, 0x03]
        };
        const PRINT_W = 32;
        let btLastReceipt = null;

        function _fmt(n) {
            return Number(n || 0).toLocaleString('id-ID');
        }

        function _lr(l, r, w) {
            const width = w || PRINT_W,
                ls = String(l),
                rs = String(r),
                sp = Math.max(1, width - ls.length - rs.length);
            return ls + ' '.repeat(sp) + rs;
        }

        function _dash() {
            return '-'.repeat(PRINT_W);
        }

        function _solid() {
            return '='.repeat(PRINT_W);
        }

        function _enc(str) {
            const out = [];
            for (let i = 0; i < str.length; i++) {
                const c = str.charCodeAt(i);
                out.push(c < 256 ? c : 0x3F);
            }
            return out;
        }

        function buildEscPos(d) {
            const buf = [];
            const push = a => {
                for (let i = 0; i < a.length; i++) buf.push(a[i]);
            };
            const text = s => push(_enc(s + '\n'));
            const line = s => push(_enc(s));
            push(ESCPOS.init);
            push(ESCPOS.alignCenter);
            push(ESCPOS.boldOn);
            push(ESCPOS.fontBig);
            text('KOPERASI BSDK');
            push(ESCPOS.fontNormal);
            push(ESCPOS.boldOff);
            text('MESIN KASIR / POS');
            text('Terima Kasih Atas Kunjungan Anda');
            push(ESCPOS.alignLeft);
            line(_dash() + '\n');
            line(_lr('No', d.invoice) + '\n');
            line(_lr('Tgl', d.tanggal) + '\n');
            line(_lr('Kasir', String(d.operator || '').substring(0, 18)) + '\n');
            if (d.member_nama) {
                line(_lr('Member', String(d.member_nama).substring(0, 18)) + '\n');
                if (d.member_kode) line(_lr('Kode', String(d.member_kode)) + '\n');
            }
            line(_dash() + '\n');
            (d.items || []).forEach(item => {
                push(ESCPOS.boldOn);
                text(String(item.nama).substring(0, 32));
                push(ESCPOS.boldOff);
                line(_lr(item.qty + ' x ' + _fmt(item.harga_normal), _fmt(item.normal_item)) + '\n');
                if (item.diskon_item > 0) {
                    if (item.nama_diskon) text('Promo: ' + String(item.nama_diskon).substring(0, 26));
                    line(_lr('Disc/pcs ' + _fmt(item.diskon_satuan) + ' x ' + item.qty, '-' + _fmt(item.diskon_item)) + '\n');
                    push(ESCPOS.boldOn);
                    line(_lr('Subtotal', _fmt(item.subtotal_item)) + '\n');
                    push(ESCPOS.boldOff);
                }
            });
            line(_dash() + '\n');
            line(_lr('SUBTOTAL', _fmt(d.subtotal_normal)) + '\n');
            if (d.diskon_barang > 0) line(_lr('DISKON BARANG', '-' + _fmt(d.diskon_barang)) + '\n');
            if (d.diskon_transaksi > 0) {
                line(_lr('DISKON PROMO', '-' + _fmt(d.diskon_transaksi)) + '\n');
                if (d.nama_diskon_trx) text('Promo: ' + String(d.nama_diskon_trx).substring(0, 26));
            }
            if (d.point_dipakai > 0) {
                line(_lr('POINT DIPAKAI', '-' + d.point_dipakai + ' pt') + '\n');
                if (d.nilai_point > 0) line(_lr('NILAI POINT', '-' + _fmt(d.nilai_point)) + '\n');
            }
            if (d.total_diskon > 0) {
                push(ESCPOS.boldOn);
                line(_lr('TOTAL DISKON', '-' + _fmt(d.total_diskon)) + '\n');
                push(ESCPOS.boldOff);
            }
            line(_solid() + '\n');
            push(ESCPOS.boldOn);
            line(_lr('TOTAL BAYAR', _fmt(d.total_bayar)) + '\n');
            push(ESCPOS.boldOff);
            line(_lr('TUNAI/QRIS', _fmt(d.bayar)) + '\n');
            line(_lr('KEMBALI', _fmt(d.kembalian)) + '\n');
            if (d.member_nama && d.point_dapat > 0) {
                line(_dash() + '\n');
                push(ESCPOS.boldOn);
                text('POINT MEMBER');
                push(ESCPOS.boldOff);
                line(_lr('Point Didapat', '+' + _fmt(d.point_dapat) + ' pt') + '\n');
                if (d.member_point_total) line(_lr('Total Point', _fmt(d.member_point_total) + ' pt') + '\n');
            }
            line(_dash() + '\n');
            push(ESCPOS.alignCenter);
            text('BARANG YANG SUDAH DIBELI');
            text('TIDAK DAPAT DITUKAR/DIKEMBALIKAN');
            text('');
            text('*** TERIMA KASIH ***');
            push(ESCPOS.alignLeft);
            push(ESCPOS.feed(5));
            push(ESCPOS.cut);
            return new Uint8Array(buf);
        }

        function btSendData(characteristic, data) {
            const CHUNK = 100;
            let chain = Promise.resolve();
            for (let pos = 0; pos < data.length; pos += CHUNK) {
                const slice = data.slice(pos, pos + CHUNK);
                chain = chain.then(() => characteristic.writeValueWithoutResponse(slice)).then(() => new Promise(r => setTimeout(r, 60)));
            }
            return chain;
        }

        function btConnectAndPrint(device, data) {
            return device.gatt.connect().then(server => {
                const tryUUID = idx => {
                    if (idx >= BT_PRINTER_CONFIG.length) return Promise.reject(new Error('UUID printer tidak cocok.'));
                    return server.getPrimaryService(BT_PRINTER_CONFIG[idx].service).then(svc => svc.getCharacteristic(BT_PRINTER_CONFIG[idx].characteristic)).catch(() => tryUUID(idx + 1));
                };
                return tryUUID(0).then(characteristic => btSendData(characteristic, data).then(() => {
                    try {
                        server.disconnect();
                    } catch (e) {}
                }));
            });
        }

        function setBtStatus(msg, type) {
            const bar = document.getElementById('bt-status-bar');
            if (!bar) return;
            bar.textContent = msg;
            bar.className = type || 'info';
            if (type === 'success') setTimeout(() => {
                bar.style.display = 'none';
                bar.className = '';
            }, 4000);
        }

        function setBtnStrukLabel(text, disabled) {
            const btn = document.getElementById('btn-struk');
            if (!btn) return;
            btn.textContent = text;
            btn.disabled = !!disabled;
        }

        function cetakStrukBluetooth() {
            if (!btLastReceipt) {
                alert('Data struk belum siap.');
                return;
            }
            if (!navigator.bluetooth) {
                setBtStatus('Web Bluetooth tidak tersedia. Gunakan Chrome/Edge dengan HTTPS.', 'error');
                return;
            }
            setBtnStrukLabel('🔍 Mencari printer...', true);
            setBtStatus('Membuka dialog pilih printer Bluetooth...', 'info');
            const escData = buildEscPos(btLastReceipt);
            navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: BT_PRINTER_CONFIG.map(c => c.service)
                })
                .then(device => {
                    setBtnStrukLabel('📡 Menghubungkan...', true);
                    setBtStatus('Menghubungkan ke ' + device.name + '...', 'info');
                    return btConnectAndPrint(device, escData);
                })
                .then(() => {
                    setBtnStrukLabel('✓ Tercetak!', false);
                    setBtStatus('Struk berhasil dicetak!', 'success');
                    setTimeout(() => setBtnStrukLabel('🖨 Cetak Struk', false), 3500);
                })
                .catch(err => {
                    if (err.name === 'NotFoundError') {
                        setBtStatus('Tidak ada printer yang dipilih.', 'info');
                    } else {
                        setBtStatus('Gagal: ' + err.message, 'error');
                    }
                    setBtnStrukLabel('🖨 Cetak Struk', false);
                });
        }

        function prepareReceiptData(d) {
            const now = new Date();
            const tgl = now.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }) + ' ' + now.toLocaleTimeString('id-ID', {
                hour12: false
            });
            let lines = [];
            if (Array.isArray(d.lines) && d.lines.length) {
                lines = d.lines.map(l => {
                    const hn = parseInt(l.harga_normal || l.harga_final || 0),
                        hf = parseInt(l.harga_final || hn),
                        qty = parseInt(l.qty || 1),
                        sub = parseInt(l.subtotal_final || (hf * qty)),
                        di = parseInt(l.diskon_item || 0);
                    return {
                        nama: String(l.nama || '').toUpperCase(),
                        qty,
                        harga_normal: hn,
                        normal_item: hn * qty,
                        subtotal_item: sub,
                        diskon_item: di,
                        diskon_satuan: qty > 0 ? Math.round(di / qty) : di,
                        nama_diskon: String(l.diskon_item_nama || '')
                    };
                });
            } else {
                lines = cart.map(item => {
                    const hn = parseInt(item.harga_jual),
                        hf = parseInt(item.harga_final || hn),
                        qty = parseInt(item.qty),
                        di = parseInt(item.diskon_item || 0);
                    return {
                        nama: String(item.nama || '').toUpperCase(),
                        qty,
                        harga_normal: hn,
                        normal_item: hn * qty,
                        subtotal_item: Math.max(0, hf * qty),
                        diskon_item: di,
                        diskon_satuan: qty > 0 ? Math.round(di / qty) : di,
                        nama_diskon: String(item.diskon_item_nama || '')
                    };
                });
            }
            const subtotalNormal = lines.reduce((s, l) => s + l.normal_item, 0);
            const diskonBarang = lines.reduce((s, l) => s + l.diskon_item, 0);
            const diskonTransaksi = parseInt(d.diskon_transaksi || 0);
            const totalDiskon = parseInt(d.diskon || 0) || (diskonBarang + diskonTransaksi);
            btLastReceipt = {
                invoice: String(d.invoice || ''),
                tanggal: tgl,
                operator: OPERATOR_NAME,
                member_nama: activeMember ? activeMember.nama : '',
                member_kode: activeMember ? activeMember.kode : '',
                member_point_total: parseInt(d.point_total || (activeMember ? activeMember.point : 0)),
                items: lines,
                subtotal_normal: subtotalNormal,
                diskon_barang: diskonBarang,
                diskon_transaksi: diskonTransaksi,
                total_diskon: totalDiskon,
                total_bayar: parseInt(d.total || 0),
                bayar: parseInt(d.bayar || 0),
                kembalian: parseInt(d.kembalian || 0),
                point_dapat: parseInt(d.point_dapat || 0),
                point_dipakai: parseInt(d.point_pakai || 0),
                nilai_point: parseInt(d.nilai_point_pakai || 0),
                nama_diskon_trx: String(d.diskon_nama || '')
            };
        }
    </script>
</body>

</html>