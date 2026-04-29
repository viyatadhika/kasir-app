<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

// ── Helper Promo Diskon Aman ──────────────────────────────────────────
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
        $select .= diskonHasColumn($pdo, 'cakupan') ? ", cakupan" : ", 'transaksi' AS cakupan";
        $select .= diskonHasColumn($pdo, 'produk_id') ? ", produk_id" : ", NULL AS produk_id";
        $select .= diskonHasColumn($pdo, 'kategori') ? ", kategori" : ", NULL AS kategori";

        // Member tidak mendapatkan diskon. Member hanya mendapatkan point.
        // Jadi promo yang dipakai POS hanya target='semua'.
        $stmt = $pdo->prepare("\n            SELECT $select\n            FROM diskon\n            WHERE status='aktif'\n              AND target='semua'\n              AND minimal_belanja <= :subtotal\n              AND (tanggal_mulai IS NULL OR tanggal_mulai <= CURDATE())\n              AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())\n            ORDER BY minimal_belanja DESC, id DESC\n        ");
        $stmt->execute([':subtotal' => $subtotal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function calculateCartDiscounts(PDO $pdo, array $items, array $produkMap, ?int $memberId): array
{
    $subtotal = 0;
    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        if (isset($produkMap[$pid])) {
            $subtotal += (int)$produkMap[$pid]['harga_jual'] * $qty;
        }
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
                    // Diskon nominal produk/kategori adalah potongan per pcs, lalu dikalikan qty.
                    $diskonSatuan = hitungPotonganDiskon((int)$p['harga_jual'], 'nominal', (int)$d['nilai']);
                    $lineDiscount = $diskonSatuan * $qty;
                }

                if ($lineDiscount > 0) {
                    $discountAmount += $lineDiscount;
                    $affectedLines[$pid] = $lineDiscount;
                }
            }
        }

        if ($discountAmount > 0) {
            $candidates[] = [
                'id' => (int)$d['id'],
                'nama' => $d['nama'],
                'cakupan' => $cakupan,
                'jenis' => $d['jenis'],
                'nilai' => (int)$d['nilai'],
                'diskon' => (int)$discountAmount,
                'affected_lines' => $affectedLines,
            ];
        }
    }

    // Aturan aman: promo/diskon TIDAK bisa digabung.
    // Jika ada beberapa promo aktif, POS memilih potongan PALING KECIL agar paling aman untuk toko.
    $selected = null;
    foreach ($candidates as $candidate) {
        if ($selected === null || $candidate['diskon'] < $selected['diskon']) {
            $selected = $candidate;
        }
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

        $lines[] = [
            'produk_id' => $pid,
            'kode' => $p['kode'],
            'nama' => $p['nama'],
            'harga_normal' => (int)$p['harga_jual'],
            'qty' => $qty,
            'subtotal_normal' => $lineBase,
            'diskon_item' => $diskonItem,
            'diskon_item_id' => $diskonItemId,
            'diskon_item_nama' => $diskonItemNama,
            'harga_final' => $hargaFinal,
            'subtotal_final' => $lineNet,
        ];
    }

    $itemDiskonTotal = ($selected && ($selected['cakupan'] === 'produk' || $selected['cakupan'] === 'kategori')) ? (int)$selected['diskon'] : 0;
    $transaksiDiskon = ($selected && $selected['cakupan'] === 'transaksi') ? (int)$selected['diskon'] : 0;
    $totalDiskon = $itemDiskonTotal + $transaksiDiskon;

    return [
        'subtotal' => $subtotal,
        'item_diskon' => $itemDiskonTotal,
        'subtotal_setelah_diskon_item' => max(0, $subtotal - $itemDiskonTotal),
        'transaksi_diskon' => $transaksiDiskon,
        'transaksi_diskon_id' => $selected ? $selected['id'] : null,
        'transaksi_diskon_nama' => $selected ? $selected['nama'] : null,
        'diskon_terpilih_id' => $selected ? $selected['id'] : null,
        'diskon_terpilih_nama' => $selected ? $selected['nama'] : null,
        'diskon_terpilih_cakupan' => $selected ? $selected['cakupan'] : null,
        'diskon_total' => $totalDiskon,
        'total' => max(0, $subtotal - $totalDiskon),
        'lines' => $lines,
        'aturan_diskon' => 'Promo tidak digabung. Member hanya mendapatkan point. Jika beberapa promo aktif, POS memilih potongan paling kecil agar aman untuk toko.',
    ];
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


/*
SQL tambahan yang disarankan agar rincian transaksi menyimpan harga normal dan diskon barang:

ALTER TABLE transaksi
ADD COLUMN diskon INT DEFAULT 0 AFTER total,
ADD COLUMN diskon_id INT NULL AFTER diskon;

ALTER TABLE transaksi_detail
ADD COLUMN harga_normal INT NULL AFTER nama,
ADD COLUMN diskon INT DEFAULT 0 AFTER harga,
ADD COLUMN diskon_id INT NULL AFTER diskon;

Jika kolom transaksi_detail di atas belum ditambahkan, POS tetap jalan,
tetapi harga detail akan disimpan sebagai harga setelah diskon.
*/

// ── API Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {

        // Ambil semua produk aktif
        case 'get_products':
            $stmt = $pdo->query("
                SELECT id, kode, nama, kategori, harga_jual, stok, satuan
                FROM produk WHERE status = 'aktif' ORDER BY kategori, nama
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;

            // Suggest member (autocomplete)
        case 'suggest_member':
            $input = json_decode(file_get_contents('php://input'), true);
            $q = trim($input['q'] ?? '');

            if (strlen($q) < 1) {
                echo json_encode([]);
                exit;
            }

            $like = '%' . $q . '%';

            $stmt = $pdo->prepare("
        SELECT id, kode, nama, no_hp, point
        FROM member
        WHERE (kode LIKE :q_kode OR nama LIKE :q_nama)
          AND status = 'aktif'
        LIMIT 5
    ");

            $stmt->execute([
                ':q_kode' => $like,
                ':q_nama' => $like
            ]);

            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;

            // Cari member berdasarkan kode (exact)
        case 'cari_member':
            $input = json_decode(file_get_contents('php://input'), true);
            $kode  = trim($input['kode'] ?? '');
            if (!$kode) {
                echo json_encode(['success' => false, 'message' => 'Kode member kosong.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id, kode, nama, no_hp, point FROM member WHERE kode = :kode AND status = 'aktif'");
            $stmt->execute([':kode' => $kode]);
            $member = $stmt->fetch();
            if ($member) echo json_encode(['success' => true,  'data'    => $member]);
            else         echo json_encode(['success' => false, 'message' => 'Member tidak ditemukan.']);
            exit;


            // Hitung diskon aktif untuk preview POS, termasuk diskon barang
        case 'hitung_diskon':
            $input    = json_decode(file_get_contents('php://input'), true);
            $items    = !empty($input['items']) && is_array($input['items']) ? $input['items'] : [];
            $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;

            if (empty($items)) {
                echo json_encode([
                    'success' => true,
                    'data' => ['subtotal' => 0, 'item_diskon' => 0, 'transaksi_diskon' => 0, 'diskon_total' => 0, 'total' => 0, 'lines' => []],
                    'total' => 0
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $produkIds = array_values(array_unique(array_map(function ($x) {
                return (int)($x['id'] ?? 0);
            }, $items)));
            $produkIds = array_filter($produkIds);

            if (!$produkIds) {
                echo json_encode([
                    'success' => true,
                    'data' => ['subtotal' => 0, 'item_diskon' => 0, 'transaksi_diskon' => 0, 'diskon_total' => 0, 'total' => 0, 'lines' => []],
                    'total' => 0
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
            $stmtCheck = $pdo->prepare("SELECT id, kode, nama, kategori, harga_jual, stok FROM produk WHERE id IN ($placeholders) AND status='aktif'");
            $stmtCheck->execute($produkIds);

            $produkMap = [];
            foreach ($stmtCheck->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $produkMap[(int)$p['id']] = $p;
            }

            $diskonData = calculateCartDiscounts($pdo, $items, $produkMap, $memberId);

            echo json_encode([
                'success' => true,
                'data'    => $diskonData,
                'total'   => $diskonData['total'],
            ], JSON_UNESCAPED_UNICODE);
            exit;


            // Simpan transaksi
        case 'simpan_transaksi':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['items']) || !is_array($input['items'])) {
                echo json_encode(['success' => false, 'message' => 'Keranjang kosong.']);
                exit;
            }

            $userId   = $_SESSION['user_id'] ?? null;
            $invoice  = generateInvoice();
            $items    = $input['items'];
            $bayar    = (int)($input['bayar'] ?? 0);
            $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;

            $produkIds = array_values(array_unique(array_column($items, 'id')));
            $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
            $stmtCheck = $pdo->prepare("SELECT id, kode, nama, kategori, harga_jual, stok FROM produk WHERE id IN ($placeholders) AND status = 'aktif'");
            $stmtCheck->execute($produkIds);

            $produkMap = [];
            foreach ($stmtCheck->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $produkMap[(int)$p['id']] = $p;
            }

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

            // Hitung 1 promo diskon yang dipakai. Promo tidak digabung; member hanya point.
            $diskonData = calculateCartDiscounts($pdo, $items, $produkMap, $memberId);
            $subtotal   = (int)$diskonData['subtotal'];
            $diskon     = (int)$diskonData['diskon_total'];
            $diskonId   = !empty($diskonData['diskon_terpilih_id']) ? (int)$diskonData['diskon_terpilih_id'] : null;
            $total      = (int)$diskonData['total'];
            $kembalian  = max(0, $bayar - $total);

            if ($bayar > 0 && $bayar < $total) {
                echo json_encode(['success' => false, 'message' => 'Uang bayar kurang dari total tagihan.']);
                exit;
            }

            // Hitung point: setiap kelipatan Rp 10.000 = 1 point
            $pointDapat = $memberId ? (int)floor($total / 10000) : 0;

            try {
                $pdo->beginTransaction();

                if (transaksiHasDiscountColumns($pdo)) {
                    $stmtTrans = $pdo->prepare("
                        INSERT INTO transaksi (invoice, user_id, member_id, total, diskon, diskon_id, bayar, kembalian, point_dapat, catatan)
                        VALUES (:invoice,:user_id,:member_id,:total,:diskon,:diskon_id,:bayar,:kembalian,:point_dapat,:catatan)
                    ");
                    $stmtTrans->execute([
                        ':invoice'     => $invoice,
                        ':user_id'     => $userId,
                        ':member_id'   => $memberId,
                        ':total'       => $total,
                        ':diskon'      => $diskon,
                        ':diskon_id'   => $diskonId,
                        ':bayar'       => $bayar,
                        ':kembalian'   => $kembalian,
                        ':point_dapat' => $pointDapat,
                        ':catatan'     => $input['catatan'] ?? null,
                    ]);
                } else {
                    $stmtTrans = $pdo->prepare("
                        INSERT INTO transaksi (invoice, user_id, member_id, total, bayar, kembalian, point_dapat, catatan)
                        VALUES (:invoice,:user_id,:member_id,:total,:bayar,:kembalian,:point_dapat,:catatan)
                    ");
                    $stmtTrans->execute([
                        ':invoice'     => $invoice,
                        ':user_id'     => $userId,
                        ':member_id'   => $memberId,
                        ':total'       => $total,
                        ':bayar'       => $bayar,
                        ':kembalian'   => $kembalian,
                        ':point_dapat' => $pointDapat,
                        ':catatan'     => $input['catatan'] ?? null,
                    ]);
                }
                $transaksiId = $pdo->lastInsertId();

                if (transaksiDetailHasDiscountColumns($pdo)) {
                    $stmtDetail = $pdo->prepare("
                        INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga_normal,harga,diskon,diskon_id,qty,subtotal)
                        VALUES (:tid,:pid,:kode,:nama,:harga_normal,:harga,:diskon,:diskon_id,:qty,:sub)
                    ");
                } else {
                    $stmtDetail = $pdo->prepare("
                        INSERT INTO transaksi_detail (transaksi_id,produk_id,kode,nama,harga,qty,subtotal)
                        VALUES (:tid,:pid,:kode,:nama,:harga,:qty,:sub)
                    ");
                }

                $stmtStok = $pdo->prepare("UPDATE produk SET stok=stok-:qty, updated_at=NOW() WHERE id=:id");

                foreach ($diskonData['lines'] as $line) {
                    $pid = (int)$line['produk_id'];
                    $qty = (int)$line['qty'];

                    if (transaksiDetailHasDiscountColumns($pdo)) {
                        $stmtDetail->execute([
                            ':tid' => $transaksiId,
                            ':pid' => $pid,
                            ':kode' => $line['kode'],
                            ':nama' => $line['nama'],
                            ':harga_normal' => $line['harga_normal'],
                            ':harga' => $line['harga_final'],
                            ':diskon' => $line['diskon_item'],
                            ':diskon_id' => $line['diskon_item_id'],
                            ':qty' => $qty,
                            ':sub' => $line['subtotal_final'],
                        ]);
                    } else {
                        $stmtDetail->execute([
                            ':tid' => $transaksiId,
                            ':pid' => $pid,
                            ':kode' => $line['kode'],
                            ':nama' => $line['nama'],
                            ':harga' => $line['harga_final'],
                            ':qty' => $qty,
                            ':sub' => $line['subtotal_final'],
                        ]);
                    }

                    $stmtStok->execute([':qty' => $qty, ':id' => $pid]);
                }

                // Update point & total belanja member
                if ($memberId && $pointDapat > 0) {
                    $pdo->prepare("UPDATE member SET point=point+:pt, total_belanja=total_belanja+:total, updated_at=NOW() WHERE id=:id")
                        ->execute([':pt' => $pointDapat, ':total' => $total, ':id' => $memberId]);
                } elseif ($memberId) {
                    $pdo->prepare("UPDATE member SET total_belanja=total_belanja+:total, updated_at=NOW() WHERE id=:id")
                        ->execute([':total' => $total, ':id' => $memberId]);
                }

                $pdo->commit();

                // Ambil total point terbaru member
                $pointTotal = 0;
                if ($memberId) {
                    $r = $pdo->prepare("SELECT point FROM member WHERE id=:id");
                    $r->execute([':id' => $memberId]);
                    $pointTotal = (int)$r->fetchColumn();
                }

                echo json_encode([
                    'success'     => true,
                    'invoice'     => $invoice,
                    'subtotal'    => $subtotal,
                    'diskon'      => $diskon,
                    'diskon_id'   => $diskonId,
                    'diskon_nama' => $diskonData['diskon_terpilih_nama'],
                    'diskon_item' => $diskonData['item_diskon'],
                    'diskon_transaksi' => $diskonData['transaksi_diskon'],
                    'total'       => $total,
                    'bayar'       => $bayar,
                    'kembalian'   => $kembalian,
                    'point_dapat' => $pointDapat,
                    'point_total' => $pointTotal,
                    'message'     => 'Transaksi berhasil disimpan.',
                ]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
            exit;
    }
}

$stmtKat      = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE status='aktif' ORDER BY kategori");
$kategoriList = $stmtKat->fetchAll(PDO::FETCH_COLUMN);
$qrisImage    = 'assets/qr_code_kasir.png';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS – Mesin Kasir</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        /* Member */
        #member-found {
            display: none;
        }

        #member-notfound {
            display: none;
        }

        /* Member suggest dropdown */
        #member-suggest .suggest-item:hover,
        #member-suggest .suggest-item.active {
            background: #eff6ff;
        }

        /* QRIS pulse */
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
    </style>
</head>

<body class="antialiased min-h-screen lg:h-screen flex flex-col overflow-x-hidden lg:overflow-hidden pb-20 lg:pb-0">

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
        </nav>
        <div class="mt-auto">
            <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
            <p class="text-[10px] text-gray-400 font-medium">v 2.4.0</p>
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

    <!-- Main Layout -->
    <div class="content flex-1 flex flex-col lg:flex-row overflow-visible lg:overflow-hidden">

        <!-- Kiri: Produk -->
        <div class="flex-1 flex flex-col bg-gray-50/50 border-b lg:border-b-0 lg:border-r border-subtle min-h-0">
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
                    <button onclick="setCategory('Semua')" class="category-btn active-category px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px]">Semua</button>
                    <?php foreach ($kategoriList as $kat): ?>
                        <button onclick="setCategory('<?= e($kat) ?>')" class="category-btn px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px] hover:border-black hover:text-black transition-colors">
                            <?= e($kat) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="flex-1 overflow-y-visible lg:overflow-y-auto p-4 sm:p-6 no-scrollbar">
                <div class="product-grid" id="product-list">
                    <div class="col-span-full flex justify-center py-20">
                        <div class="spinner"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanan: Keranjang -->
        <div class="w-full lg:w-[420px] bg-white flex flex-col shadow-none lg:shadow-2xl z-20">
            <div class="p-4 sm:p-6 border-b border-subtle flex justify-between items-center bg-gray-50/30">
                <h2 class="text-xs font-black uppercase tracking-[0.2em]">Daftar Belanja</h2>
                <button onclick="clearCart(true)" class="text-[10px] text-red-500 font-bold uppercase tracking-widest hover:underline">Reset</button>
            </div>

            <!-- Input Member -->
            <div class="px-4 sm:px-6 pt-4 pb-3 border-b border-subtle bg-white">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Kode Member (Opsional)</p>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none z-10">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </span>
                        <input type="text" id="member-input"
                            placeholder="Ketik kode / nama member..."
                            oninput="onMemberInput()"
                            onkeydown="onMemberKeydown(event)"
                            autocomplete="off"
                            class="w-full bg-gray-50 border border-gray-100 text-sm py-2.5 pl-10 pr-3 focus:outline-none focus:ring-2 focus:ring-black/5 transition-all">
                        <!-- Dropdown autocomplete -->
                        <div id="member-suggest" class="hidden absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl z-50 max-h-52 overflow-y-auto no-scrollbar"></div>
                    </div>
                    <button onclick="cariMember()" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase hover:bg-gray-800 transition-all">Cari</button>
                    <button onclick="clearMember()" class="px-3 py-2.5 border border-subtle text-gray-400 text-[10px] font-black uppercase hover:bg-gray-50 transition-all">✕</button>
                </div>
                <!-- Member ditemukan -->
                <div id="member-found" class="mt-2 bg-green-50 border border-green-200 px-3 py-2 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black text-green-700" id="member-nama">—</p>
                        <p class="text-[9px] text-green-500 font-bold uppercase tracking-wide mt-0.5">
                            Point saat ini: <span id="member-point" class="font-black">0</span> pt
                        </p>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-widest text-green-600 bg-green-100 px-2 py-1">Member Aktif</span>
                </div>
                <!-- Member tidak ditemukan -->
                <div id="member-notfound" class="mt-2 bg-red-50 border border-red-200 px-3 py-2">
                    <p class="text-[10px] font-black text-red-600">Member tidak ditemukan. Transaksi tanpa point.</p>
                </div>
            </div>

            <div class="min-h-[200px] lg:flex-1 overflow-y-visible lg:overflow-y-auto p-4 space-y-4 no-scrollbar" id="cart-container">
                <div class="h-full flex flex-col items-center justify-center text-center opacity-30 py-10">
                    <svg class="h-14 w-14 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    <p class="text-xs font-bold uppercase tracking-widest">Keranjang Kosong</p>
                </div>
            </div>

            <!-- Ringkasan -->
            <div class="p-4 sm:p-6 border-t border-subtle bg-white space-y-3 sticky bottom-20 lg:static shadow-[0_-8px_24px_rgba(0,0,0,0.04)] lg:shadow-none">
                <div class="flex justify-between text-[11px] font-medium text-gray-400 uppercase tracking-widest">
                    <span>Subtotal</span><span id="subtotal">Rp 0</span>
                </div>
                <!-- Preview diskon -->
                <div id="diskon-preview" class="hidden flex justify-between text-[11px] font-bold text-red-600 uppercase tracking-widest">
                    <span id="diskon-preview-label">Diskon</span><span id="diskon-preview-val">-Rp 0</span>
                </div>
                <!-- Preview point -->
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

    <!-- ── Payment Modal ─────────────────────────────────────────────────────────── -->
    <div id="payment-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[100] items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-md overflow-hidden shadow-2xl max-h-[92vh] overflow-y-auto no-scrollbar">
            <div class="p-6 sm:p-8 text-center border-b border-subtle">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Total Tagihan</p>
                <h3 class="text-4xl font-black" id="modal-total">Rp 0</h3>
                <div id="modal-member-info" class="hidden mt-2 text-xs text-blue-600 font-bold"></div>
            </div>
            <div class="p-6 sm:p-8 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pilih Metode</p>
                <div class="grid grid-cols-2 gap-4">
                    <button id="btn-tunai" onclick="selectMethod('tunai')" class="p-4 border-2 border-black flex flex-col items-center gap-2 bg-black text-white transition-all">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span>Tunai</span>
                    </button>
                    <button id="btn-qris" onclick="selectMethod('qris')" class="p-4 border border-subtle flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                        <span>QRIS / EDC</span>
                    </button>
                </div>

                <!-- Tunai -->
                <div id="tunai-input" class="hidden space-y-3 mt-4">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block">Uang Diterima</label>
                    <input type="number" id="bayar-input" placeholder="0" oninput="hitungKembalian()"
                        class="w-full border border-gray-200 px-4 py-3 text-lg font-bold focus:outline-none focus:border-black transition-colors">
                    <div class="flex flex-wrap gap-2" id="nominal-cepat"></div>
                    <div class="flex justify-between pt-1">
                        <span class="text-xs text-gray-400 font-medium uppercase tracking-widest">Kembalian</span>
                        <span id="kembalian-display" class="text-sm font-black text-gray-400">Rp 0</span>
                    </div>
                </div>

                <!-- QRIS -->
                <div id="qris-box" class="hidden mt-4">
                    <div class="border border-subtle bg-white p-5 text-center">
                        <div class="flex justify-between items-center mb-4 px-1">
                            <div class="text-left">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Nominal Transfer</p>
                                <p class="text-xl font-black" id="qris-total-label">Rp 0</p>
                            </div>
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1.5 bg-green-50 text-green-700 border border-green-200">Siap Scan</span>
                        </div>
                        <div id="qris-image-wrap" class="bg-white border-2 border-gray-100 inline-block p-3 shadow-sm">
                            <img src="<?= htmlspecialchars($qrisImage) ?>" alt="QRIS Koperasi BSDK" class="w-[220px] h-[220px] object-contain">
                        </div>
                        <div class="mt-4 space-y-1">
                            <p class="text-xs font-black uppercase tracking-widest">KOPERASI BSDK</p>
                            <p class="text-[10px] text-gray-400">NMID: ID1025377699398</p>
                            <p class="text-[9px] text-gray-400">Scan menggunakan aplikasi e-wallet atau m-banking manapun</p>
                        </div>
                        <button onclick="confirmQrisPayment()" class="mt-5 w-full bg-blue-600 text-white py-3 text-[10px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all">
                            ✓ Pembayaran Sudah Diterima
                        </button>
                    </div>
                </div>
            </div>
            <div class="p-6 sm:p-8 bg-gray-50 flex gap-3 border-t border-subtle">
                <button onclick="closePayment()" class="flex-1 py-4 text-[10px] font-black uppercase border border-subtle hover:bg-white transition-all">Batal</button>
                <button onclick="processPayment()" id="btn-konfirmasi" class="flex-1 py-4 text-[10px] font-black uppercase bg-blue-600 text-white shadow-lg shadow-blue-200 active:scale-95 transition-all">Konfirmasi</button>
            </div>
        </div>
    </div>

    <!-- ── Success Modal ──────────────────────────────────────────────────────────── -->
    <div id="success-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-sm overflow-hidden shadow-2xl text-center p-8 sm:p-10">
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
            <div id="success-point-wrap" class="hidden mt-3 bg-blue-50 border border-blue-200 px-4 py-3">
                <p class="text-[10px] font-bold uppercase tracking-widest text-blue-400 mb-1">Point Member</p>
                <p class="text-sm font-black text-blue-700" id="success-point-dapat"></p>
                <p class="text-[10px] text-blue-500 font-bold mt-0.5" id="success-point-total"></p>
            </div>
            <div class="flex gap-3 mt-6">
                <a id="btn-struk" href="#" target="_blank" class="flex-1 py-3 text-[10px] font-black uppercase border border-subtle hover:bg-gray-50 transition-all">Cetak Struk</a>
                <button onclick="resetTransaksiBaru()" class="flex-1 py-3 text-[10px] font-black uppercase bg-black text-white transition-all">Transaksi Baru</button>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-[250] shadow-lg">
        <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18M3 6h18M3 18h18" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
        </button>
        <a href="pos.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M8 12h8" />
            </svg>
        </a>
        <a href="produk.php" class="flex flex-col items-center p-2">
            <svg class="h-5 w-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <span class="text-[8px] font-bold mt-1 uppercase text-gray-400">Produk</span>
        </a>
    </nav>

    <script>
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

        // ── State ────────────────────────────────────────────────────────────────────
        let PRODUCTS = [];
        let cart = [];
        let currentCat = 'Semua';
        let selectedMethod = 'tunai';
        let activeMember = null;
        let activeDiskon = null; // ringkasan 1 promo diskon yang dipakai
        let diskonPreviewTimeout = null;

        // ── Member Autocomplete State ─────────────────────────────────────────────
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
                const res = await fetch('pos.php?action=get_products', {
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

        // ── Member Autocomplete ───────────────────────────────────────────────────
        async function onMemberInput() {
            const q = document.getElementById('member-input').value.trim();
            clearTimeout(memberSuggestTimeout);

            if (!q) {
                hideMemberSuggest();
                // Reset jika dikosongkan
                activeMember = null;
                document.getElementById('member-found').style.display = 'none';
                document.getElementById('member-notfound').style.display = 'none';
                updateUI();
                return;
            }

            // Reset status member aktif saat mengetik ulang
            activeMember = null;
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';

            memberSuggestTimeout = setTimeout(async () => {
                try {
                    const res = await fetch('pos.php?action=suggest_member', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            q
                        })
                    });
                    const text = await res.text();
                    let data = [];
                    try {
                        data = JSON.parse(text);
                    } catch (err) {
                        console.error('Response suggest_member bukan JSON valid:', text);
                        hideMemberSuggest();
                        return;
                    }
                    memberSuggestData = Array.isArray(data) ? data : [];
                    memberSuggestIndex = -1;
                    renderMemberSuggest(memberSuggestData);
                } catch (e) {
                    hideMemberSuggest();
                }
            }, 200);
        }

        function renderMemberSuggest(data) {
            const box = document.getElementById('member-suggest');
            if (!box) return;

            if (!Array.isArray(data) || !data.length) {
                hideMemberSuggest();
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

        function hideMemberSuggest() {
            document.getElementById('member-suggest').classList.add('hidden');
            memberSuggestData = [];
            memberSuggestIndex = -1;
        }

        function pilihMember(index) {
            const m = memberSuggestData[index];
            if (!m) return;

            document.getElementById('member-input').value = m.kode;
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
            } else if (e.key === 'Escape') {
                hideMemberSuggest();
            }
        }

        function highlightSuggest(items) {
            items.forEach((el, i) => {
                el.classList.toggle('bg-blue-50', i === memberSuggestIndex);
            });
            // Scroll item aktif ke tampilan
            if (memberSuggestIndex >= 0) items[memberSuggestIndex].scrollIntoView({
                block: 'nearest'
            });
        }

        // ── Member (exact search / manual) ────────────────────────────────────────
        async function cariMember() {
            const kode = document.getElementById('member-input').value.trim();
            if (!kode) return;

            hideMemberSuggest();
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';

            try {
                const res = await fetch('pos.php?action=cari_member', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        kode
                    })
                });
                const d = await res.json();
                if (d.success) {
                    activeMember = d.data;
                    document.getElementById('member-nama').innerText = d.data.nama;
                    document.getElementById('member-point').innerText = Number(d.data.point).toLocaleString('id-ID');
                    document.getElementById('member-found').style.display = 'flex';
                } else {
                    activeMember = null;
                    document.getElementById('member-notfound').style.display = 'block';
                    setTimeout(() => {
                        document.getElementById('member-notfound').style.display = 'none';
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
            document.getElementById('member-input').value = '';
            document.getElementById('member-found').style.display = 'none';
            document.getElementById('member-notfound').style.display = 'none';
            hideMemberSuggest();
            updateUI();
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
                (p.nama.toLowerCase().includes(q) || p.kode.toLowerCase().includes(q) || p.kategori.toLowerCase().includes(q)) &&
                (currentCat === 'Semua' || p.kategori === currentCat)
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
                return `<div onclick="${habis ? '' : 'addToCart(' + p.id + ')'}"
                class="bg-white p-3 rounded-2xl border border-subtle transition-all cursor-pointer group active:scale-95 shadow-sm ${habis ? 'opacity-40 cursor-not-allowed' : 'hover:border-black hover:shadow-md'}">
                <div class="w-full aspect-square bg-gray-50 rounded-xl mb-3 flex items-center justify-center text-gray-200 ${habis ? '' : 'group-hover:text-blue-500'} transition-colors">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p class="text-[8px] font-black uppercase text-gray-400 tracking-widest mb-1">${p.kategori}</p>
                <h4 class="text-[12px] font-bold leading-tight h-8 overflow-hidden text-slate-800">${p.nama}</h4>
                <p class="text-xs font-black text-black mt-1">${formatRp(p.harga_jual)}</p>
                <div class="mt-1">${habis
                    ? '<span class="text-[8px] font-black text-red-500 uppercase">Habis</span>'
                    : low
                        ? `<span class="text-[8px] font-black text-orange-500 uppercase">Sisa ${p.stok}</span>`
                        : `<span class="text-[8px] text-gray-300 uppercase">Stok ${p.stok}</span>`
                }</div>
            </div>`;
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
                const memberInput = document.getElementById('member-input');
                if (memberInput) memberInput.value = '';
                document.getElementById('member-found').style.display = 'none';
                document.getElementById('member-notfound').style.display = 'none';
                hideMemberSuggest();
            }
            updateUI();
        }


        function getSubtotal() {
            return cart.reduce((a, i) => a + i.harga_jual * i.qty, 0);
        }

        function getDiskonAmount() {
            return activeDiskon ? parseInt(activeDiskon.diskon_total || 0) : 0;
        }

        function getGrandTotal() {
            return activeDiskon ? parseInt(activeDiskon.total || 0) : Math.max(0, getSubtotal() - getDiskonAmount());
        }

        function applyTotalsToUI() {
            const subtotal = getSubtotal();
            const diskon = getDiskonAmount();
            const total = activeDiskon ? parseInt(activeDiskon.total || 0) : Math.max(0, subtotal - diskon);

            document.getElementById('subtotal').innerText = formatRp(subtotal);
            document.getElementById('total-price').innerText = formatRp(total);
            document.getElementById('modal-total').innerText = formatRp(total);
            document.getElementById('qris-total-label').innerText = formatRp(total);

            const diskonWrap = document.getElementById('diskon-preview');
            const diskonLabel = document.getElementById('diskon-preview-label');
            const diskonVal = document.getElementById('diskon-preview-val');
            if (diskon > 0 && activeDiskon) {
                diskonLabel.innerText = activeDiskon.label || 'Diskon';
                diskonVal.innerText = '-' + formatRp(diskon);
                diskonWrap.classList.remove('hidden');
                diskonWrap.style.display = 'flex';
            } else {
                diskonWrap.classList.add('hidden');
                diskonWrap.style.display = 'none';
            }

            return {
                subtotal,
                diskon,
                total
            };
        }

        async function refreshDiskonPreview() {
            const subtotal = getSubtotal();
            if (subtotal <= 0) {
                activeDiskon = null;
                applyTotalsToUI();
                return;
            }

            try {
                const res = await fetch('pos.php?action=hitung_diskon', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items: cart.map(i => ({
                            id: parseInt(i.id),
                            qty: parseInt(i.qty)
                        })),
                        member_id: activeMember?.id || null
                    })
                });
                const d = await res.json();
                if (d.success && d.data && parseInt(d.data.diskon_total || 0) > 0) {
                    activeDiskon = d.data;
                    const parts = [];
                    if (activeDiskon.diskon_terpilih_nama) {
                        parts.push(activeDiskon.diskon_terpilih_nama);
                    } else if (parseInt(activeDiskon.item_diskon || 0) > 0) {
                        parts.push('Diskon Barang');
                    } else if (parseInt(activeDiskon.transaksi_diskon || 0) > 0) {
                        parts.push('Diskon Transaksi');
                    }
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
            const container = document.getElementById('cart-container');
            if (!container) return;

            if (!cart.length) {
                container.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-center opacity-30 py-10">
                <svg class="h-14 w-14 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <p class="text-xs font-bold uppercase tracking-widest">Keranjang Kosong</p></div>`;
            } else {
                container.innerHTML = cart.map(item => `
                <div class="cart-item-anim flex justify-between items-center bg-gray-50/30 p-4 rounded-2xl border border-subtle">
                    <div class="flex-1 pr-2">
                        <h5 class="text-[10px] font-bold uppercase leading-tight tracking-tight">${item.nama}</h5>
                        <p class="text-[9px] text-gray-400 mt-1">
                            ${item.diskon_item > 0
                                ? `<span class="line-through">${formatRp(item.harga_jual)}</span> <span class="text-red-500 font-black">${formatRp(item.harga_final || item.harga_jual)}</span>`
                                : formatRp(item.harga_jual)}
                        </p>
                        ${item.diskon_item > 0 ? `<p class="text-[8px] text-red-500 font-black uppercase mt-0.5">-${formatRp(item.diskon_item)} ${item.diskon_item_nama ? '· ' + item.diskon_item_nama : ''}</p>` : ''}
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 bg-white rounded-lg border border-subtle p-1">
                            <button onclick="updateQty(${item.id},-1)" class="w-5 h-5 flex items-center justify-center text-[10px] hover:bg-gray-100">−</button>
                            <span class="text-[10px] font-black w-4 text-center">${item.qty}</span>
                            <button onclick="updateQty(${item.id}, 1)" class="w-5 h-5 flex items-center justify-center text-[10px] hover:bg-gray-100">+</button>
                        </div>
                        <p class="text-[11px] font-black w-20 text-right">${formatRp((item.harga_final || item.harga_jual) * item.qty)}</p>
                    </div>
                </div>`).join('');
            }

            const totals = applyTotalsToUI();
            const total = totals.total;

            // Preview point dihitung dari total setelah diskon
            const pointDapat = activeMember ? Math.floor(total / 10000) : 0;
            const ppWrap = document.getElementById('point-preview');
            if (activeMember && pointDapat > 0) {
                document.getElementById('point-preview-val').innerText = '+' + pointDapat + ' pt';
                ppWrap.classList.remove('hidden');
                ppWrap.style.display = 'flex';
            } else {
                ppWrap.classList.add('hidden');
                ppWrap.style.display = 'none';
            }

            // Info member di modal
            const mInfo = document.getElementById('modal-member-info');
            if (activeMember) {
                mInfo.innerText = `Member: ${activeMember.nama} · +${pointDapat} point didapat`;
                mInfo.classList.remove('hidden');
            } else {
                mInfo.classList.add('hidden');
            }

            hitungKembalian();
            renderNominalCepat(total);
            if (refreshDiskon) scheduleDiskonPreview();
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
            wrap.innerHTML = sug.map(n =>
                `<button onclick="document.getElementById('bayar-input').value=${n};hitungKembalian()"
                class="flex-1 py-1.5 text-[9px] font-bold border border-subtle hover:bg-gray-100 transition-all">${formatRp(n)}</button>`
            ).join('');
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
            document.getElementById('btn-tunai').className = method === 'tunai' ?
                'p-4 border-2 border-black flex flex-col items-center gap-2 bg-black text-white transition-all' :
                'p-4 border border-subtle flex flex-col items-center gap-2 hover:border-black hover:text-black transition-all';
            document.getElementById('btn-qris').className = method === 'qris' ?
                'p-4 border-2 border-blue-600 flex flex-col items-center gap-2 bg-blue-600 text-white transition-all' :
                'p-4 border border-subtle flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all';
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
            const total = getGrandTotal();
            const bayar = parseInt(document.getElementById('bayar-input')?.value || 0);
            const kem = Math.max(0, bayar - total);
            const el = document.getElementById('kembalian-display');
            if (el) {
                el.innerText = formatRp(kem);
                el.className = `text-sm font-black ${bayar >= total && bayar > 0 ? 'text-green-600' : 'text-gray-400'}`;
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
            } else {
                bayar = total;
            }

            const btn = document.getElementById('btn-konfirmasi');
            btn.disabled = true;
            btn.innerText = 'Memproses...';

            try {
                const res = await fetch('pos.php?action=simpan_transaksi', {
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
                        member_id: activeMember?.id || null
                    })
                });
                const d = await res.json();
                if (d.success) {
                    closePayment();
                    document.getElementById('success-invoice').innerText = d.invoice;
                    document.getElementById('success-total').innerText = formatRp(d.total);
                    const sd = document.getElementById('success-diskon');
                    if (sd && parseInt(d.diskon || 0) > 0) {
                        sd.innerText = `Diskon barang: -${formatRp(d.diskon_item || 0)} · Diskon transaksi: -${formatRp(d.diskon_transaksi || 0)} · Total diskon: -${formatRp(d.diskon || 0)}`;
                        sd.classList.remove('hidden');
                    } else if (sd) {
                        sd.classList.add('hidden');
                        sd.innerText = '';
                    }
                    document.getElementById('success-kembalian').innerText = selectedMethod === 'tunai' ?
                        'Kembalian: ' + formatRp(d.kembalian) :
                        'QRIS – Lunas ✓';

                    const pw = document.getElementById('success-point-wrap');
                    if (activeMember && d.point_dapat > 0) {
                        document.getElementById('success-point-dapat').innerText = `+${d.point_dapat} point diperoleh transaksi ini`;
                        document.getElementById('success-point-total').innerText = `Total point ${activeMember.nama}: ${d.point_total.toLocaleString('id-ID')} pt`;
                        pw.classList.remove('hidden');
                        document.getElementById('member-point').innerText = d.point_total.toLocaleString('id-ID');
                        activeMember.point = d.point_total;
                    } else {
                        pw.classList.add('hidden');
                    }

                    document.getElementById('btn-struk').href = 'struk.php?invoice=' + encodeURIComponent(d.invoice);
                    document.getElementById('success-modal').style.display = 'flex';
                    clearCart();
                    await loadProducts();
                } else {
                    alert('Gagal: ' + d.message);
                }
            } catch (e) {
                alert('Kesalahan jaringan.');
            } finally {
                btn.disabled = false;
                btn.innerText = selectedMethod === 'qris' ? 'Konfirmasi QRIS' : 'Konfirmasi';
            }
        }

        function closeSuccess() {
            document.getElementById('success-modal').style.display = 'none';
        }

        function resetTransaksiBaru() {
            clearCart(true);
            const bayarInput = document.getElementById('bayar-input');
            if (bayarInput) bayarInput.value = '';
            closeSuccess();
            setTimeout(() => document.getElementById('search-input')?.focus(), 100);
        }

        // ── Barcode Scanner ──────────────────────────────────────────────────────────
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
            document.getElementById('product-list').innerHTML =
                `<div class="col-span-full py-20 text-center text-red-400"><p class="text-xs font-bold uppercase">${m}</p></div>`;
        }

        // ── Event Listeners ──────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            // Tutup mobile menu klik overlay
            const o = document.getElementById('mobileMenuOverlay');
            if (o) o.addEventListener('click', e => {
                if (e.target === o) toggleMobileMenu();
            });

            // Barcode listener di search input
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

            // Tutup member suggest jika klik di luar
            document.addEventListener('click', e => {
                const memberInput = document.getElementById('member-input');
                const memberSuggest = document.getElementById('member-suggest');
                if (memberInput && memberSuggest &&
                    !memberInput.contains(e.target) &&
                    !memberSuggest.contains(e.target)) {
                    hideMemberSuggest();
                }
            });
        });

        window.onload = () => {
            init();
            setTimeout(() => document.getElementById('search-input')?.focus(), 300);
        };
    </script>
</body>

</html>