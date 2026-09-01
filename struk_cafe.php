<?php
/*
|--------------------------------------------------------------------------
| struk_cafe.php — Thermal Bluetooth + Auto Reconnect
|--------------------------------------------------------------------------
| - Preview struk cafe
| - ESC/POS Bluetooth
| - Mengingat printer terakhir
| - ?print=1 mencoba reconnect otomatis ke printer yang sudah pernah diizinkan
| - Fallback tombol Bluetooth jika browser tetap membutuhkan interaksi pengguna
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

if (function_exists('requireAccess')) {
    requireAccess();
}

date_default_timezone_set('Asia/Jakarta');

function sc_e($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function sc_money($value)
{
    return number_format((float)($value === null ? 0 : $value), 0, ',', '.');
}

function sc_upper($text)
{
    $text = (string)$text;
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($text, 'UTF-8')
        : strtoupper($text);
}

function sc_cut($text, $max)
{
    $text = trim((string)$text);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') <= $max
            ? $text
            : mb_substr($text, 0, max(0, $max - 1), 'UTF-8') . '.';
    }

    return strlen($text) <= $max
        ? $text
        : substr($text, 0, max(0, $max - 1)) . '.';
}

function sc_line_lr($left, $right, $width = 32)
{
    $left = trim((string)$left);
    $right = trim((string)$right);
    $space = $width - strlen($left) - strlen($right);
    if ($space < 1) {
        $space = 1;
    }
    return $left . str_repeat(' ', $space) . $right;
}

function sc_columns(PDO $pdo, $table)
{
    try {
        return $pdo->query(
            "SHOW COLUMNS FROM `" . str_replace('`', '', (string)$table) . "`"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return array();
    }
}

$invoice = trim((string)($_GET['invoice'] ?? ''));
if ($invoice === '') {
    http_response_code(400);
    exit('Invoice tidak ditemukan.');
}

$autoPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';

try {
    $trxCols = sc_columns($pdo, 'transaksi');

    $select = array('t.*');

    if (in_array('member_id', $trxCols, true)) {
        $select[] = 'm.kode AS member_kode';
        $select[] = 'm.nama AS member_nama';
        $select[] = 'COALESCE(m.point,0) AS member_point_total';
    } else {
        $select[] = "'' AS member_kode";
        $select[] = "'' AS member_nama";
        $select[] = '0 AS member_point_total';
    }

    $sql = "SELECT " . implode(', ', $select) . " FROM transaksi t";
    if (in_array('member_id', $trxCols, true)) {
        $sql .= " LEFT JOIN member m ON m.id = t.member_id";
    }
    $sql .= " WHERE t.invoice = :invoice LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':invoice' => $invoice));
    $trx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trx) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }

    $detailCols = sc_columns($pdo, 'transaksi_detail');
    $noteSelect = in_array('catatan_item', $detailCols, true)
        ? ', td.catatan_item'
        : ", '' AS catatan_item";

    $stmtDetail = $pdo->prepare("
        SELECT
            td.kode,
            td.nama,
            td.harga,
            td.qty,
            td.subtotal
            $noteSelect
        FROM transaksi_detail td
        WHERE td.transaksi_id = :id
        ORDER BY td.id ASC
    ");
    $stmtDetail->execute(array(':id' => (int)$trx['id']));
    $items = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        exit('Detail transaksi kosong.');
    }

    $order = null;
    try {
        $stmtOrder = $pdo->prepare("
            SELECT
                cp.*,
                cm.nomor_meja,
                cm.nama_meja
            FROM cafe_pesanan cp
            LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id
            WHERE cp.transaksi_id = :id
            LIMIT 1
        ");
        $stmtOrder->execute(array(':id' => (int)$trx['id']));
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $order = null;
        }
    } catch (Throwable $e) {
        $order = null;
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float)($item['subtotal'] ?? 0);
    }

    $promoDiscount = (float)($order['promo_diskon'] ?? 0);
    $promoName = trim((string)($order['promo_nama'] ?? ''));

    $pointUsed = (int)($trx['point_pakai'] ?? ($trx['point_dipakai'] ?? 0));
    $pointValue = (float)($trx['nilai_point_pakai'] ?? ($trx['nilai_point'] ?? 0));
    $pointEarned = (int)($trx['point_dapat'] ?? 0);

    $total = (float)($trx['total'] ?? 0);
    $bayar = (float)($trx['bayar'] ?? 0);
    $kembalian = (float)($trx['kembalian'] ?? 0);
    $method = strtolower(trim((string)($trx['metode_pembayaran'] ?? 'tunai')));

    $created = (string)($trx['created_at'] ?? ($order['created_at'] ?? date('Y-m-d H:i:s')));
    $tanggal = date('d/m/Y H:i:s', strtotime($created));

    $operator = 'Kasir Cafe';
    foreach (array('nama', 'name', 'username', 'user_name') as $key) {
        if (!empty($_SESSION[$key])) {
            $operator = (string)$_SESSION[$key];
            break;
        }
    }
    if (!empty($_SESSION['user']['nama'])) {
        $operator = (string)$_SESSION['user']['nama'];
    } elseif (!empty($_SESSION['user']['name'])) {
        $operator = (string)$_SESSION['user']['name'];
    } elseif (!empty($_SESSION['user']['username'])) {
        $operator = (string)$_SESSION['user']['username'];
    }

    $memberName = trim((string)($trx['member_nama'] ?? ''));
    $memberCode = trim((string)($trx['member_kode'] ?? ''));
    $memberPointTotal = (int)($trx['member_point_total'] ?? 0);

    $orderNumber = trim((string)($order['nomor_pesanan'] ?? ''));
    $orderType = strtolower(trim((string)($order['tipe_pesanan'] ?? 'takeaway')));
    $tableNumber = trim((string)($order['nomor_meja'] ?? ''));
    $tableName = trim((string)($order['nama_meja'] ?? ''));

    $jsItems = array();
    foreach ($items as $item) {
        $jsItems[] = array(
            'nama' => sc_upper((string)($item['nama'] ?? 'ITEM')),
            'qty' => (int)($item['qty'] ?? 0),
            'harga' => (float)($item['harga'] ?? 0),
            'subtotal' => (float)($item['subtotal'] ?? 0),
            'catatan' => trim((string)($item['catatan_item'] ?? '')),
        );
    }

    $jsData = json_encode(array(
        'invoice' => $invoice,
        'nomor_pesanan' => $orderNumber,
        'tanggal' => $tanggal,
        'operator' => $operator,
        'tipe_pesanan' => $orderType,
        'meja' => $tableNumber,
        'nama_meja' => $tableName,
        'member_nama' => $memberName,
        'member_kode' => $memberCode,
        'member_point_total' => $memberPointTotal,
        'items' => $jsItems,
        'subtotal' => $subtotal,
        'promo_nama' => $promoName,
        'promo_diskon' => $promoDiscount,
        'point_dipakai' => $pointUsed,
        'nilai_point' => $pointValue,
        'point_dapat' => $pointEarned,
        'total' => $total,
        'metode' => strtoupper($method),
        'bayar' => $bayar,
        'kembalian' => $kembalian,
    ), JSON_UNESCAPED_UNICODE);

    if ($jsData === false) {
        $jsData = '{}';
    }
} catch (Throwable $e) {
    exit('Gagal memuat struk cafe: ' . sc_e($e->getMessage()));
}

$httpHost = sc_e((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Struk Cafe <?php echo sc_e($invoice); ?></title>
    <link rel="icon" href="data:,">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            background: #f3f4f6;
            font-family: "Courier New", Courier, monospace;
            color: #111
        }

        .toolbar {
            display: flex;
            font-family: Arial, sans-serif;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .12)
        }

        .toolbar-btn {
            flex: 1;
            border: 0;
            padding: 12px 8px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px
        }

        .btn-back {
            background: #fff;
            color: #111;
            border-right: 1px solid #ddd
        }

        .btn-bluetooth {
            background: #1a1a2e;
            color: #00d4ff
        }

        .btn-bluetooth:disabled {
            background: #555;
            color: #999;
            cursor: not-allowed
        }

        .btn-print {
            background: #111;
            color: #fff;
            border-left: 1px solid #333
        }

        #statusBar {
            display: none;
            padding: 7px 14px;
            font-family: Arial, sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-align: center
        }

        #statusBar.info {
            background: #dbeafe;
            color: #1e40af;
            display: block
        }

        #statusBar.success {
            background: #dcfce7;
            color: #166534;
            display: block
        }

        #statusBar.error {
            background: #fee2e2;
            color: #991b1b;
            display: block
        }

        #httpsNotice {
            display: none;
            margin: 6px 10px;
            padding: 9px 11px;
            background: #fffbeb;
            border: 1px dashed #d97706;
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #92400e;
            line-height: 1.6
        }

        .page {
            min-height: 100vh;
            padding: 10px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            overflow-x: auto
        }

        .wrap,
        .receipt {
            width: 270px !important;
            min-width: 270px !important;
            max-width: 270px !important
        }

        .receipt {
            background: #fff;
            padding: 8px 10px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .12);
            font-family: "Courier New", Courier, monospace;
            font-size: 13px;
            line-height: 1.4
        }

        .center {
            text-align: center
        }

        .store {
            font-size: 1.2em;
            font-weight: 900;
            letter-spacing: 1px
        }

        .small {
            font-size: .85em
        }

        .bold {
            font-weight: 800
        }

        .dash {
            border-top: 1px dashed #111;
            margin: 5px 0
        }

        .solid {
            border-top: 1px solid #111;
            margin: 6px 0
        }

        .line {
            display: block;
            white-space: pre;
            overflow: visible;
            font-size: inherit;
            line-height: inherit
        }

        .item-name {
            margin-top: 4px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: pre-wrap;
            word-break: break-word
        }

        .muted {
            color: #444
        }

        .promo-label {
            padding-left: 6px;
            font-size: .85em;
            color: #555
        }

        .disc-line {
            color: #b00
        }

        .thanks {
            margin-top: 8px;
            text-align: center;
            font-size: .9em
        }

        @media screen and (max-width:480px) {
            .page {
                padding: 10px;
                justify-content: center;
                overflow-x: auto
            }

            .wrap,
            .receipt {
                width: 270px !important;
                min-width: 270px !important;
                max-width: 270px !important
            }
        }

        @page {
            size: 80mm auto;
            margin: 0
        }

        @media print {
            body {
                background: #fff
            }

            .toolbar,
            #statusBar,
            #httpsNotice {
                display: none !important
            }

            .page {
                padding: 0
            }

            .wrap,
            .receipt {
                width: 80mm !important;
                min-width: 80mm !important;
                max-width: 80mm !important
            }

            .receipt {
                box-shadow: none;
                font-size: 9px;
                padding: 0
            }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <a class="toolbar-btn btn-back" href="pos_cafe.php">&#8592; Kembali</a>
        <button class="toolbar-btn btn-bluetooth" id="btnBluetooth" type="button" onclick="printBluetooth()">
            &#128424; Bluetooth
        </button>
        <button class="toolbar-btn btn-print" type="button" onclick="window.print()">
            &#128424; Print
        </button>
    </div>

    <div id="statusBar"></div>

    <div id="httpsNotice">
        <strong>Web Bluetooth membutuhkan HTTPS atau localhost.</strong>
        Jika POS dibuka dari IP LAN biasa, Chrome dapat memblokir Bluetooth.
        Host saat ini: <code><?php echo $httpHost; ?></code>
    </div>

    <div class="page">
        <div class="wrap">
            <div class="receipt">
                <div class="center">
                    <div class="store">SEJAHUB CAFE</div>
                    <div class="small">MESIN KASIR CAFE</div>
                    <div class="small">Terima Kasih Atas Kunjungan Anda</div>
                </div>

                <div class="dash"></div>

                <span class="line"><?php echo sc_e(sc_line_lr('No', $invoice)); ?></span>
                <?php if ($orderNumber !== ''): ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('Pesanan', sc_cut($orderNumber, 18))); ?></span>
                <?php endif; ?>
                <span class="line"><?php echo sc_e(sc_line_lr('Tgl', $tanggal)); ?></span>
                <span class="line"><?php echo sc_e(sc_line_lr('Kasir', sc_cut($operator, 18))); ?></span>
                <span class="line"><?php echo sc_e(sc_line_lr('Tipe', $orderType === 'dine_in' ? 'DINE IN' : 'TAKEAWAY')); ?></span>

                <?php if ($orderType === 'dine_in' && $tableNumber !== ''): ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('Meja', sc_cut($tableNumber . ($tableName !== '' ? ' ' . $tableName : ''), 20))); ?></span>
                <?php endif; ?>

                <?php if ($memberName !== ''): ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('Member', sc_cut($memberName, 18))); ?></span>
                    <?php if ($memberCode !== ''): ?>
                        <span class="line"><?php echo sc_e(sc_line_lr('Kode', sc_cut($memberCode, 20))); ?></span>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="dash"></div>

                <?php foreach ($items as $item): ?>
                    <div class="item-name"><?php echo sc_e(sc_cut((string)($item['nama'] ?? 'ITEM'), 32)); ?></div>
                    <span class="line"><?php
                                        echo sc_e(sc_line_lr(
                                            (int)($item['qty'] ?? 0) . ' x ' . sc_money($item['harga'] ?? 0),
                                            sc_money($item['subtotal'] ?? 0)
                                        ));
                                        ?></span>
                    <?php if (trim((string)($item['catatan_item'] ?? '')) !== ''): ?>
                        <div class="promo-label muted"><?php echo sc_e('Catatan: ' . sc_cut((string)$item['catatan_item'], 24)); ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="dash"></div>

                <span class="line"><?php echo sc_e(sc_line_lr('SUBTOTAL', sc_money($subtotal))); ?></span>

                <?php if ($promoDiscount > 0): ?>
                    <span class="line disc-line"><?php echo sc_e(sc_line_lr('DISKON PROMO', '-' . sc_money($promoDiscount))); ?></span>
                    <?php if ($promoName !== ''): ?>
                        <div class="promo-label muted"><?php echo sc_e('Promo: ' . sc_cut($promoName, 26)); ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($pointUsed > 0): ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('POINT DIPAKAI', '-' . $pointUsed . ' pt')); ?></span>
                    <?php if ($pointValue > 0): ?>
                        <span class="line"><?php echo sc_e(sc_line_lr('NILAI POINT', '-' . sc_money($pointValue))); ?></span>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="solid"></div>

                <span class="line bold"><?php echo sc_e(sc_line_lr('TOTAL BAYAR', sc_money($total))); ?></span>
                <span class="line"><?php echo sc_e(sc_line_lr('METODE', strtoupper($method))); ?></span>
                <span class="line"><?php echo sc_e(sc_line_lr('BAYAR', sc_money($bayar))); ?></span>

                <?php if ($method === 'tunai'): ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('KEMBALI', sc_money($kembalian))); ?></span>
                <?php endif; ?>

                <?php if ($memberName !== '' && ($pointEarned > 0 || $memberPointTotal > 0)): ?>
                    <div class="dash"></div>
                    <div class="bold small">POINT MEMBER</div>
                    <?php if ($pointEarned > 0): ?>
                        <span class="line"><?php echo sc_e(sc_line_lr('Point Didapat', '+' . $pointEarned . ' pt')); ?></span>
                    <?php endif; ?>
                    <span class="line"><?php echo sc_e(sc_line_lr('Total Point', $memberPointTotal . ' pt')); ?></span>
                <?php endif; ?>

                <div class="dash"></div>

                <div class="thanks">
                    TERIMA KASIH ATAS KUNJUNGAN ANDA<br>
                    SIMPAN STRUK SEBAGAI BUKTI PEMBAYARAN<br><br>
                    *** SEJAHUB CAFE ***
                </div>
            </div>
        </div>
    </div>

    <script>
        'use strict';

        var RECEIPT = <?php echo $jsData; ?>;
        var AUTO_PRINT = <?php echo $autoPrint ? 'true' : 'false'; ?>;

        var BT_CONFIG = [{
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

        var STORAGE_KEY_NAME = 'sejahub_cafe_bt_printer_name';
        var ESC = 0x1B;
        var GS = 0x1D;
        var W = 32;

        var CMD = {
            init: [ESC, 0x40],
            alignLeft: [ESC, 0x61, 0x00],
            alignCenter: [ESC, 0x61, 0x01],
            boldOn: [ESC, 0x45, 0x01],
            boldOff: [ESC, 0x45, 0x00],
            fontBig: [GS, 0x21, 0x11],
            fontNormal: [GS, 0x21, 0x00],
            feed: function(n) {
                return [ESC, 0x64, n];
            },
            cut: [GS, 0x56, 0x41, 0x03]
        };

        function fmt(n) {
            return Number(n || 0).toLocaleString('id-ID');
        }

        function lr(left, right, width) {
            var w = width || W;
            var l = String(left == null ? '' : left);
            var r = String(right == null ? '' : right);
            var spaces = Math.max(1, w - l.length - r.length);
            return l + ' '.repeat(spaces) + r;
        }

        function dash() {
            return '-'.repeat(W);
        }

        function solid() {
            return '='.repeat(W);
        }

        function enc(text) {
            var out = [];
            var s = String(text == null ? '' : text);
            for (var i = 0; i < s.length; i++) {
                var code = s.charCodeAt(i);
                out.push(code < 256 ? code : 0x3F);
            }
            return out;
        }

        function buildEscPos() {
            var buffer = [];

            function push(arr) {
                for (var i = 0; i < arr.length; i++) {
                    buffer.push(arr[i]);
                }
            }

            function text(value) {
                push(enc(String(value) + '\n'));
            }

            function raw(value) {
                push(enc(String(value)));
            }

            var d = RECEIPT || {};

            push(CMD.init);

            push(CMD.alignCenter);
            push(CMD.boldOn);
            push(CMD.fontBig);
            text('SEJAHUB CAFE');
            push(CMD.fontNormal);
            push(CMD.boldOff);
            text('MESIN KASIR CAFE');
            text('Terima Kasih Atas Kunjungan Anda');
            push(CMD.alignLeft);

            raw(dash() + '\n');
            raw(lr('No', d.invoice || '') + '\n');

            if (d.nomor_pesanan) {
                raw(lr('Pesanan', String(d.nomor_pesanan).substring(0, 18)) + '\n');
            }

            raw(lr('Tgl', d.tanggal || '') + '\n');
            raw(lr('Kasir', String(d.operator || '').substring(0, 18)) + '\n');
            raw(lr('Tipe', d.tipe_pesanan === 'dine_in' ? 'DINE IN' : 'TAKEAWAY') + '\n');

            if (d.tipe_pesanan === 'dine_in' && d.meja) {
                raw(lr('Meja', String(d.meja + (d.nama_meja ? ' ' + d.nama_meja : '')).substring(0, 20)) + '\n');
            }

            if (d.member_nama) {
                raw(lr('Member', String(d.member_nama).substring(0, 18)) + '\n');
                if (d.member_kode) {
                    raw(lr('Kode', String(d.member_kode).substring(0, 20)) + '\n');
                }
            }

            raw(dash() + '\n');

            (Array.isArray(d.items) ? d.items : []).forEach(function(item) {
                push(CMD.boldOn);
                text(String(item.nama || 'ITEM').substring(0, 32));
                push(CMD.boldOff);
                raw(lr(
                    Number(item.qty || 0) + ' x ' + fmt(item.harga || 0),
                    fmt(item.subtotal || 0)
                ) + '\n');

                if (item.catatan) {
                    text('Catatan: ' + String(item.catatan).substring(0, 24));
                }
            });

            raw(dash() + '\n');
            raw(lr('SUBTOTAL', fmt(d.subtotal || 0)) + '\n');

            if (Number(d.promo_diskon || 0) > 0) {
                raw(lr('DISKON PROMO', '-' + fmt(d.promo_diskon || 0)) + '\n');
                if (d.promo_nama) {
                    text('Promo: ' + String(d.promo_nama).substring(0, 26));
                }
            }

            if (Number(d.point_dipakai || 0) > 0) {
                raw(lr('POINT DIPAKAI', '-' + Number(d.point_dipakai) + ' pt') + '\n');

                if (Number(d.nilai_point || 0) > 0) {
                    raw(lr('NILAI POINT', '-' + fmt(d.nilai_point || 0)) + '\n');
                }
            }

            raw(solid() + '\n');
            push(CMD.boldOn);
            raw(lr('TOTAL BAYAR', fmt(d.total || 0)) + '\n');
            push(CMD.boldOff);
            raw(lr('METODE', String(d.metode || '')) + '\n');
            raw(lr('BAYAR', fmt(d.bayar || 0)) + '\n');

            if (String(d.metode || '').toLowerCase() === 'tunai') {
                raw(lr('KEMBALI', fmt(d.kembalian || 0)) + '\n');
            }

            if (d.member_nama && (Number(d.point_dapat || 0) > 0 || Number(d.member_point_total || 0) > 0)) {
                raw(dash() + '\n');
                push(CMD.boldOn);
                text('POINT MEMBER');
                push(CMD.boldOff);

                if (Number(d.point_dapat || 0) > 0) {
                    raw(lr('Point Didapat', '+' + Number(d.point_dapat) + ' pt') + '\n');
                }

                raw(lr('Total Point', Number(d.member_point_total || 0) + ' pt') + '\n');
            }

            raw(dash() + '\n');
            push(CMD.alignCenter);
            text('TERIMA KASIH ATAS KUNJUNGAN ANDA');
            text('SIMPAN STRUK SEBAGAI BUKTI PEMBAYARAN');
            text('');
            text('*** SEJAHUB CAFE ***');
            push(CMD.alignLeft);
            push(CMD.feed(5));
            push(CMD.cut);

            return new Uint8Array(buffer);
        }

        function setStatus(message, type) {
            var el = document.getElementById('statusBar');
            if (!el) return;

            el.textContent = message;
            el.className = type || 'info';

            if (type === 'success') {
                setTimeout(function() {
                    el.style.display = 'none';
                    el.className = '';
                }, 3500);
            }
        }

        function showBluetoothHelp(message) {
            var notice = document.getElementById('httpsNotice');
            if (notice) {
                notice.style.display = 'block';
            }
            setStatus(message, 'error');
        }

        function sendData(characteristic, data) {
            var CHUNK = 100;
            var chain = Promise.resolve();

            for (var pos = 0; pos < data.length; pos += CHUNK) {
                (function(slice) {
                    chain = chain
                        .then(function() {
                            if (typeof characteristic.writeValueWithoutResponse === 'function') {
                                return characteristic.writeValueWithoutResponse(slice);
                            }
                            return characteristic.writeValue(slice);
                        })
                        .then(function() {
                            return new Promise(function(resolve) {
                                setTimeout(resolve, 60);
                            });
                        });
                })(data.slice(pos, pos + CHUNK));
            }

            return chain;
        }

        function findWritableCharacteristic(server, index) {
            var idx = Number(index || 0);

            if (idx >= BT_CONFIG.length) {
                return Promise.reject(new Error('UUID printer tidak cocok.'));
            }

            return server
                .getPrimaryService(BT_CONFIG[idx].service)
                .then(function(service) {
                    return service.getCharacteristic(BT_CONFIG[idx].characteristic);
                })
                .catch(function() {
                    return findWritableCharacteristic(server, idx + 1);
                });
        }

        function connectAndPrint(device) {
            if (!device) {
                return Promise.reject(new Error('Printer tidak ditemukan.'));
            }

            setStatus('Menghubungkan ke ' + (device.name || 'printer') + '...', 'info');

            return device.gatt.connect()
                .then(function(server) {
                    return findWritableCharacteristic(server, 0)
                        .then(function(characteristic) {
                            setStatus('Mengirim struk...', 'info');

                            return sendData(characteristic, buildEscPos())
                                .then(function() {
                                    try {
                                        localStorage.setItem(STORAGE_KEY_NAME, device.name || '');
                                    } catch (e) {}

                                    setStatus(
                                        'Struk berhasil dicetak ke ' + (device.name || 'printer') + '.',
                                        'success'
                                    );

                                    try {
                                        server.disconnect();
                                    } catch (e) {}
                                });
                        });
                });
        }

        function choosePrinterAndPrint() {
            if (!navigator.bluetooth) {
                if (!window.isSecureContext) {
                    showBluetoothHelp('Web Bluetooth membutuhkan HTTPS atau localhost.');
                } else {
                    setStatus('Browser tidak mendukung Web Bluetooth. Gunakan Chrome/Edge.', 'error');
                }
                return Promise.reject(new Error('Bluetooth tidak tersedia.'));
            }

            setStatus('Pilih printer Bluetooth...', 'info');

            return navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: BT_CONFIG.map(function(config) {
                    return config.service;
                })
            }).then(function(device) {
                return connectAndPrint(device);
            });
        }

        function reconnectSavedPrinter() {
            if (!navigator.bluetooth) {
                return Promise.reject(new Error('Bluetooth tidak tersedia.'));
            }

            /*
             * getDevices() dapat mengakses device yang sebelumnya sudah diizinkan
             * tanpa membuka chooser baru pada browser yang mendukung fitur ini.
             */
            if (typeof navigator.bluetooth.getDevices !== 'function') {
                return Promise.reject(new Error('Auto reconnect tidak didukung browser ini.'));
            }

            return navigator.bluetooth.getDevices()
                .then(function(devices) {
                    if (!devices || !devices.length) {
                        throw new Error('Belum ada printer yang pernah diizinkan.');
                    }

                    var savedName = '';
                    try {
                        savedName = localStorage.getItem(STORAGE_KEY_NAME) || '';
                    } catch (e) {}

                    var device = null;

                    if (savedName) {
                        for (var i = 0; i < devices.length; i++) {
                            if ((devices[i].name || '') === savedName) {
                                device = devices[i];
                                break;
                            }
                        }
                    }

                    if (!device) {
                        device = devices[0];
                    }

                    return connectAndPrint(device);
                });
        }

        function printBluetooth() {
            var btn = document.getElementById('btnBluetooth');
            if (btn) {
                btn.disabled = true;
            }

            choosePrinterAndPrint()
                .catch(function(error) {
                    if (error && error.name === 'NotFoundError') {
                        setStatus('Tidak ada printer dipilih.', 'info');
                    } else if (error && error.name === 'SecurityError') {
                        showBluetoothHelp('Bluetooth diblokir browser. Gunakan HTTPS atau localhost.');
                    } else if (error && error.message !== 'Bluetooth tidak tersedia.') {
                        setStatus('Gagal: ' + (error.message || 'Tidak dapat mencetak.'), 'error');
                    }
                })
                .finally(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                });
        }

        function autoPrintBluetooth() {
            if (!navigator.bluetooth) {
                if (!window.isSecureContext) {
                    showBluetoothHelp('Auto print Bluetooth membutuhkan HTTPS atau localhost.');
                }
                return;
            }

            setStatus('Mencoba printer terakhir...', 'info');

            reconnectSavedPrinter()
                .catch(function(error) {
                    /*
                     * Browser tidak selalu mengizinkan requestDevice() tanpa klik/tap.
                     * Karena itu fallback-nya menunggu tombol Bluetooth ditekan.
                     */
                    setStatus(
                        'Printer belum bisa tersambung otomatis. Tekan Bluetooth sekali untuk memilih printer.',
                        'info'
                    );
                });
        }

        window.addEventListener('load', function() {
            if (AUTO_PRINT) {
                setTimeout(autoPrintBluetooth, 350);
            }
        });
    </script>
</body>

</html>