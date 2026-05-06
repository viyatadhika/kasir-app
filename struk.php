<?php

/**
 * struk.php — Versi Final 3.1
 *
 * Fitur:
 *  - Tampilan format struk termal klasik (32 karakter Courier)
 *  - Bluetooth langsung cetak + ingat device terakhir
 *  - Baris "Point Dipakai" jika member pakai point untuk bayar
 *  - Auto-print via Bluetooth saat ?print=1
 *  - Mode iframe member (?member=1) tanpa toolbar
 *  - Zero PHP warnings (PHP 7.4+ compatible)
 *
 * URL:
 *   struk.php?invoice=INV-xxxx
 *   struk.php?invoice=INV-xxxx&print=1    → auto trigger Bluetooth saat load
 *   struk.php?invoice=INV-xxxx&member=1   → tanpa toolbar (iframe member)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

/* ─────────────────────────────────────────────
 * HELPER FUNCTIONS
 * ───────────────────────────────────────────── */

/**
 * @param int|float|string|null $v
 */
function uang_struk($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

function line_lr(string $left, string $right, int $width = 32): string
{
    $left  = trim($left);
    $right = trim($right);
    $space = $width - strlen($left) - strlen($right);
    if ($space < 1) $space = 1;
    return $left . str_repeat(' ', $space) . $right;
}

function cut_text(string $text, int $max): string
{
    $text = trim($text);
    if (strlen($text) <= $max) return $text;
    return substr($text, 0, max(0, $max - 1)) . '.';
}

function safe_upper(string $text): string
{
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($text, 'UTF-8');
    }
    return strtoupper($text);
}

/**
 * @param  array<string,mixed> $item
 * @return array{qty:int,harga_normal:int,harga_final:int,subtotal_item:int,normal_item:int,diskon_item:int,diskon_satuan:int}
 */
function hitung_item(array $item): array
{
    $qty         = (int)($item['qty'] ?? 0);
    $hargaNormal = ($item['harga_normal'] !== null && $item['harga_normal'] !== '')
        ? (int)$item['harga_normal']
        : (int)($item['harga'] ?? 0);
    $hargaFinal   = (int)($item['harga'] ?? $hargaNormal);
    $subtotalItem = (int)($item['subtotal'] ?? ($hargaFinal * $qty));
    $normalItem   = $hargaNormal * $qty;
    $diskonItem   = (int)($item['diskon'] ?? 0);

    if ($diskonItem <= 0 && $normalItem > $subtotalItem) {
        $diskonItem = $normalItem - $subtotalItem;
    }

    $diskonSatuan = ($qty > 0) ? (int)round($diskonItem / $qty) : $diskonItem;

    return [
        'qty'           => $qty,
        'harga_normal'  => $hargaNormal,
        'harga_final'   => $hargaFinal,
        'subtotal_item' => $subtotalItem,
        'normal_item'   => $normalItem,
        'diskon_item'   => $diskonItem,
        'diskon_satuan' => $diskonSatuan,
    ];
}

/* ─────────────────────────────────────────────
 * VALIDASI INPUT
 * ───────────────────────────────────────────── */

$invoice   = trim((string)($_GET['invoice'] ?? ''));
if ($invoice === '') die('Invoice tidak ditemukan.');

$autoPrint = isset($_GET['print'])  && (string)$_GET['print']  === '1';
$isMember  = isset($_GET['member']) && (string)$_GET['member'] === '1';

/* ─────────────────────────────────────────────
 * QUERY DATABASE
 * ───────────────────────────────────────────── */

try {
    /** @var \PDO $pdo */
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            m.kode  AS member_kode,
            m.nama  AS member_nama,
            m.point AS member_point_total,
            d.nama  AS nama_diskon
        FROM transaksi t
        LEFT JOIN member m ON m.id = t.member_id
        LEFT JOIN diskon d ON d.id = t.diskon_id
        WHERE t.invoice = :invoice
        LIMIT 1
    ");
    $stmt->execute([':invoice' => $invoice]);

    /** @var array<string,mixed>|false $trx */
    $trx = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($trx === false) die('Transaksi tidak ditemukan.');

    $stmtDetail = $pdo->prepare("
        SELECT
            td.*,
            di.nama AS nama_diskon_item
        FROM transaksi_detail td
        LEFT JOIN diskon di ON di.id = td.diskon_id
        WHERE td.transaksi_id = :tid
        ORDER BY td.id ASC
    ");
    $stmtDetail->execute([':tid' => $trx['id']]);

    /** @var array<int,array<string,mixed>> $items */
    $items = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) die('Detail transaksi kosong.');

    /* Agregat */
    $subtotalNormal              = 0;
    $totalDiskonBarang           = 0;
    $subtotalSetelahDiskonBarang = 0;

    foreach ($items as $item) {
        $h = hitung_item($item);
        $subtotalNormal              += $h['normal_item'];
        $subtotalSetelahDiskonBarang += $h['subtotal_item'];
        $totalDiskonBarang           += $h['diskon_item'];
    }

    $totalBayar  = (int)($trx['total'] ?? $subtotalSetelahDiskonBarang);
    $bayar       = (int)($trx['bayar'] ?? 0);
    $kembalian   = (int)($trx['kembalian'] ?? max(0, $bayar - $totalBayar));
    $totalDiskon = (int)($trx['diskon'] ?? 0);

    if ($totalDiskon <= 0) $totalDiskon = max(0, $subtotalNormal - $totalBayar);

    $diskonTransaksi  = max(0, $totalDiskon - $totalDiskonBarang);
    $pointDapat       = (int)($trx['point_dapat']   ?? 0);
    $pointDipakai     = (int)($trx['point_dipakai'] ?? 0);
    $nilaiPoint       = (int)($trx['nilai_point']   ?? 0);

    // Fallback kolom point_pakai / nilai_point_pakai
    if ($pointDipakai <= 0 && isset($trx['point_pakai']))       $pointDipakai = (int)$trx['point_pakai'];
    if ($nilaiPoint   <= 0 && isset($trx['nilai_point_pakai'])) $nilaiPoint   = (int)$trx['nilai_point_pakai'];

    $memberNama       = isset($trx['member_nama'])        ? (string)$trx['member_nama']     : null;
    $memberKode       = isset($trx['member_kode'])        ? (string)$trx['member_kode']     : null;
    $memberPointTotal = isset($trx['member_point_total']) ? (int)$trx['member_point_total'] : null;
    $namaDiskonTrx    = (string)($trx['nama_diskon'] ?? '');

    $tanggalRaw = isset($trx['created_at']) ? (string)$trx['created_at'] : date('Y-m-d H:i:s');
    $tanggal    = date('d/m/Y H:i:s', (int)strtotime($tanggalRaw));
    $operator   = isset($_SESSION['nama']) ? (string)$_SESSION['nama'] : 'Administrator';
} catch (Throwable $e) {
    die('Gagal memuat struk: ' . htmlspecialchars($e->getMessage()));
}

/* ─────────────────────────────────────────────
 * PHP → JS DATA
 * ───────────────────────────────────────────── */

/** @var array<int,array<string,mixed>> $jsItems */
$jsItems = [];
foreach ($items as $item) {
    $h = hitung_item($item);
    $jsItems[] = [
        'nama'          => safe_upper((string)($item['nama'] ?? 'ITEM')),
        'qty'           => $h['qty'],
        'harga_normal'  => $h['harga_normal'],
        'normal_item'   => $h['normal_item'],
        'subtotal_item' => $h['subtotal_item'],
        'diskon_item'   => $h['diskon_item'],
        'diskon_satuan' => $h['diskon_satuan'],
        'nama_diskon'   => (string)($item['nama_diskon_item'] ?? ''),
    ];
}

$jsData = (string)json_encode([
    'invoice'            => $invoice,
    'tanggal'            => $tanggal,
    'operator'           => $operator,
    'member_nama'        => $memberNama ?? '',
    'member_kode'        => $memberKode ?? '',
    'member_point_total' => $memberPointTotal ?? 0,
    'items'              => $jsItems,
    'subtotal_normal'    => $subtotalNormal,
    'diskon_barang'      => $totalDiskonBarang,
    'diskon_transaksi'   => $diskonTransaksi,
    'total_diskon'       => $totalDiskon,
    'total_bayar'        => $totalBayar,
    'bayar'              => $bayar,
    'kembalian'          => $kembalian,
    'point_dapat'        => $pointDapat,
    'point_dipakai'      => $pointDipakai,
    'nilai_point'        => $nilaiPoint,
    'nama_diskon_trx'    => $namaDiskonTrx,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$httpHost = htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? '192.168.x.x'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Struk <?= htmlspecialchars($invoice) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #f3f4f6;
            font-family: "Courier New", Courier, monospace;
            color: #111;
        }

        /* ── TOOLBAR ── */
        .toolbar {
            display: flex;
            font-family: Arial, sans-serif;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .12);
        }

        .toolbar-btn {
            flex: 1;
            border: none;
            padding: 12px 8px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .4px;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: opacity .15s;
        }

        .toolbar-btn:active {
            opacity: .75;
        }

        .btn-back {
            background: #fff;
            color: #111;
            border-right: 1px solid #ddd;
        }

        .btn-bluetooth {
            background: #1a1a2e;
            color: #00d4ff;
        }

        .btn-bluetooth:disabled {
            background: #555;
            color: #999;
            cursor: not-allowed;
        }

        .btn-print {
            background: #111;
            color: #fff;
            border-left: 1px solid #333;
        }

        /* ── STATUS BAR ── */
        #statusBar {
            display: none;
            padding: 7px 14px;
            font-family: Arial, sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
        }

        #statusBar.info {
            background: #dbeafe;
            color: #1e40af;
            display: block;
        }

        #statusBar.success {
            background: #dcfce7;
            color: #166534;
            display: block;
        }

        #statusBar.error {
            background: #fee2e2;
            color: #991b1b;
            display: block;
        }

        /* ── HTTPS NOTICE ── */
        #httpsNotice {
            display: none;
            margin: 6px 10px;
            padding: 9px 11px;
            background: #fffbeb;
            border: 1px dashed #d97706;
            border-radius: 3px;
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #92400e;
            line-height: 1.6;
        }

        #httpsNotice strong {
            display: block;
            margin-bottom: 3px;
            font-size: 10.5px;
        }

        #httpsNotice code {
            background: #fef3c7;
            padding: 1px 3px;
            border-radius: 2px;
            font-size: 9.5px;
        }

        /* ── HALAMAN ── */
        .page {
            min-height: 100vh;
            padding: 10px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            overflow-x: auto;
        }

        /*
         * .wrap: lebar tetap mengikuti konten struk.
         * Pakai fit-content agar di mode member (modal/new tab sempit)
         * wrap tidak melebar melebihi receipt itu sendiri.
         */
        .wrap {
            width: 270px !important;
            min-width: 270px !important;
            max-width: 270px !important;
        }

        /*
         * .receipt: KUNCI utama agar teks 32-char tidak terpotong.
         *
         * Lebar dihitung dari: 32 karakter × lebar 1 char Courier 10px
         * Di browser, Courier New 10px → 1 char ≈ 6px → 32ch = ~192px
         * Tapi karena padding dan variasi render, kita pakai 32ch + padding.
         *
         * Gunakan satuan `ch` (lebar karakter "0" pada font aktif) —
         * ini cara paling akurat agar 32 karakter selalu muat 1 baris.
         */
        .receipt {
            background: #fff;
            padding: 8px 10px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .12);

            /* Font harus di-set DI SINI, sebelum ukuran ch dihitung browser */
            font-family: "Courier New", Courier, monospace;
            font-size: 13px;
            /* ← naik dari 10px; 13px lebih terbaca & ch lebih stabil */
            line-height: 1.4;

            /* Lebar preview sama di desktop, tablet, dan mobile */
            width: 270px !important;
            min-width: 270px !important;
            max-width: 270px !important;
        }

        .center {
            text-align: center;
        }

        .store {
            font-size: 1.2em;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .small {
            font-size: 0.85em;
        }

        .bold {
            font-weight: 800;
        }

        .dash {
            border-top: 1px dashed #111;
            margin: 5px 0;
        }

        .solid {
            border-top: 1px solid #111;
            margin: 6px 0;
        }

        /*
         * .line: JANGAN overflow:hidden — itu yang bikin teks terpotong.
         * white-space:pre wajib agar spasi padding lr() tidak collapse.
         * overflow:visible (default) biarkan konten terlihat semua.
         */
        .line {
            display: block;
            white-space: pre;
            overflow: visible;
            font-size: inherit;
            line-height: inherit;
        }

        .item-name {
            margin-top: 4px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .muted {
            color: #444;
        }

        .promo-label {
            padding-left: 6px;
            font-size: 0.85em;
            color: #555;
        }

        .disc-line {
            color: #b00;
        }

        .thanks {
            margin-top: 8px;
            text-align: center;
            font-size: 0.9em;
        }


        /* Fixed preview width seperti screenshot; jangan mengecil di mobile/tablet */
        @media screen and (max-width: 480px) {
            .page {
                padding: 10px;
                justify-content: center;
                overflow-x: auto;
            }

            .wrap {
                width: 270px !important;
                min-width: 270px !important;
                max-width: 270px !important;
            }

            .receipt {
                width: 270px !important;
                min-width: 270px !important;
                max-width: 270px !important;
            }
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }

        /* ── PRINT ── */
        @media print {
            body {
                background: #fff;
            }

            .toolbar,
            #statusBar,
            #httpsNotice {
                display: none !important;
            }

            .page {
                padding: 0;
            }

            /* Saat print, biarkan lebar mengikuti kertas */
            .wrap {
                width: 80mm !important;
                min-width: 80mm !important;
                max-width: 80mm !important;
            }

            .receipt {
                width: 80mm !important;
                min-width: 80mm !important;
                max-width: 80mm !important;
                box-shadow: none;
                font-size: 9px;
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <?php if (!$isMember): ?>
        <div class="toolbar">
            <a class="toolbar-btn btn-back" href="pos.php">&#8592; Kembali</a>
            <button class="toolbar-btn btn-bluetooth" id="btnBluetooth" type="button" onclick="printBluetooth()">
                &#128424; Bluetooth
            </button>
            <button class="toolbar-btn btn-print" type="button" onclick="window.print()">
                &#128424; Print
            </button>
        </div>

        <div id="statusBar"></div>

        <div id="httpsNotice">
            <strong>&#9888; Web Bluetooth butuh HTTPS / localhost</strong>
            Aktifkan di Chrome Android:<br>
            Buka <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>
            Isi: <code>http://<?= $httpHost ?></code> &#8594; Enable &#8594; Relaunch
        </div>
    <?php endif; ?>

    <div class="page">
        <div class="wrap">
            <div class="receipt">

                <div class="center">
                    <div class="store">KOPERASI BSDK</div>
                    <div class="small">MESIN KASIR / POS</div>
                    <div class="small">Terima Kasih Atas Kunjungan Anda</div>
                </div>

                <div class="dash"></div>

                <span class="line"><?= htmlspecialchars(line_lr('No',    $invoice))  ?></span>
                <span class="line"><?= htmlspecialchars(line_lr('Tgl',   $tanggal))  ?></span>
                <span class="line"><?= htmlspecialchars(line_lr('Kasir', $operator)) ?></span>

                <?php if ($memberNama !== null): ?>
                    <span class="line"><?= htmlspecialchars(line_lr('Member', cut_text($memberNama, 18))) ?></span>
                    <?php if ($memberKode !== null): ?>
                        <span class="line"><?= htmlspecialchars(line_lr('Kode', $memberKode)) ?></span>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="dash"></div>

                <?php foreach ($items as $row):
                    $h          = hitung_item($row);
                    $namaItem   = (string)($row['nama'] ?? 'ITEM');
                    $namaDiskon = (string)($row['nama_diskon_item'] ?? '');
                ?>
                    <div class="item-name"><?= htmlspecialchars(cut_text($namaItem, 32)) ?></div>
                    <span class="line"><?= htmlspecialchars(line_lr(
                                            $h['qty'] . ' x ' . uang_struk($h['harga_normal']),
                                            uang_struk($h['normal_item'])
                                        )) ?></span>

                    <?php if ($h['diskon_item'] > 0): ?>
                        <?php if ($namaDiskon !== ''): ?>
                            <div class="promo-label muted"><?= htmlspecialchars('Promo: ' . cut_text($namaDiskon, 26)) ?></div>
                        <?php endif; ?>
                        <span class="line disc-line"><?= htmlspecialchars(line_lr(
                                                            'Disc/pcs ' . uang_struk($h['diskon_satuan']) . ' x ' . $h['qty'],
                                                            '-' . uang_struk($h['diskon_item'])
                                                        )) ?></span>
                        <span class="line bold"><?= htmlspecialchars(line_lr('Subtotal', uang_struk($h['subtotal_item']))) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="dash"></div>

                <span class="line"><?= htmlspecialchars(line_lr('SUBTOTAL', uang_struk($subtotalNormal))) ?></span>

                <?php if ($totalDiskonBarang > 0): ?>
                    <span class="line"><?= htmlspecialchars(line_lr('DISKON BARANG', '-' . uang_struk($totalDiskonBarang))) ?></span>
                <?php endif; ?>

                <?php if ($diskonTransaksi > 0): ?>
                    <span class="line"><?= htmlspecialchars(line_lr('DISKON PROMO', '-' . uang_struk($diskonTransaksi))) ?></span>
                    <?php if ($namaDiskonTrx !== ''): ?>
                        <div class="promo-label muted"><?= htmlspecialchars('Promo: ' . cut_text($namaDiskonTrx, 26)) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($pointDipakai > 0): ?>
                    <span class="line"><?= htmlspecialchars(line_lr('POINT DIPAKAI', '-' . $pointDipakai . ' pt')) ?></span>
                    <?php if ($nilaiPoint > 0): ?>
                        <span class="line"><?= htmlspecialchars(line_lr('NILAI POINT', '-' . uang_struk($nilaiPoint))) ?></span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($totalDiskon > 0): ?>
                    <span class="line bold"><?= htmlspecialchars(line_lr('TOTAL DISKON', '-' . uang_struk($totalDiskon))) ?></span>
                <?php endif; ?>

                <div class="solid"></div>

                <span class="line bold"><?= htmlspecialchars(line_lr('TOTAL BAYAR', uang_struk($totalBayar))) ?></span>
                <span class="line"><?= htmlspecialchars(line_lr('TUNAI/QRIS', uang_struk($bayar))) ?></span>
                <span class="line"><?= htmlspecialchars(line_lr('KEMBALI',    uang_struk($kembalian))) ?></span>

                <?php if ($memberNama !== null && $pointDapat > 0): ?>
                    <div class="dash"></div>
                    <div class="bold small">POINT MEMBER</div>
                    <span class="line"><?= htmlspecialchars(line_lr('Point Didapat', '+' . uang_struk($pointDapat) . ' pt')) ?></span>
                    <?php if ($memberPointTotal !== null): ?>
                        <span class="line"><?= htmlspecialchars(line_lr('Total Point', uang_struk($memberPointTotal) . ' pt')) ?></span>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="dash"></div>

                <div class="thanks">
                    BARANG YANG SUDAH DIBELI<br>
                    TIDAK DAPAT DITUKAR/DIKEMBALIKAN<br><br>
                    *** TERIMA KASIH ***
                </div>

            </div><!-- /.receipt -->
        </div>
    </div>

    <!-- ════════════════════════════════════════════
         WEB BLUETOOTH + ESC/POS ENGINE
         ════════════════════════════════════════════ -->
    <script>
        'use strict';

        var RECEIPT = <?= $jsData ?>;
        var AUTO_PRINT = <?= $autoPrint ? 'true' : 'false' ?>;

        /* ── UUID Printer (dicoba berurutan) ── */
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

        var STORAGE_KEY = 'bt_printer_id';

        /* ── ESC/POS constants ── */
        var ESC = 0x1B,
            GS = 0x1D;
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
        var W = 32;

        function fmt(n) {
            return Number(n).toLocaleString('id-ID');
        }

        function lr(l, r, w) {
            var width = w || W;
            var ls = String(l),
                rs = String(r);
            var sp = Math.max(1, width - ls.length - rs.length);
            return ls + ' '.repeat(sp) + rs;
        }

        function dash() {
            return '-'.repeat(W);
        }

        function solid() {
            return '='.repeat(W);
        }

        function enc(str) {
            var out = [];
            for (var i = 0; i < str.length; i++) {
                var c = str.charCodeAt(i);
                out.push(c < 256 ? c : 0x3F);
            }
            return out;
        }

        function buildEscPos() {
            var buf = [];

            function pushArr(a) {
                for (var i = 0; i < a.length; i++) buf.push(a[i]);
            }

            function text(s) {
                pushArr(enc(s + '\n'));
            }

            function line(s) {
                pushArr(enc(s));
            }

            var d = RECEIPT;

            pushArr(CMD.init);

            /* HEADER */
            pushArr(CMD.alignCenter);
            pushArr(CMD.boldOn);
            pushArr(CMD.fontBig);
            text('KOPERASI BSDK');
            pushArr(CMD.fontNormal);
            pushArr(CMD.boldOff);
            text('MESIN KASIR / POS');
            text('Terima Kasih Atas Kunjungan Anda');
            pushArr(CMD.alignLeft);

            /* INFO */
            line(dash() + '\n');
            line(lr('No', d.invoice) + '\n');
            line(lr('Tgl', d.tanggal) + '\n');
            line(lr('Kasir', String(d.operator).substring(0, 18)) + '\n');
            if (d.member_nama) {
                line(lr('Member', String(d.member_nama).substring(0, 18)) + '\n');
                if (d.member_kode) line(lr('Kode', String(d.member_kode)) + '\n');
            }
            line(dash() + '\n');

            /* ITEM */
            d.items.forEach(function(item) {
                pushArr(CMD.boldOn);
                text(String(item.nama).substring(0, 32));
                pushArr(CMD.boldOff);
                line(lr(item.qty + ' x ' + fmt(item.harga_normal), fmt(item.normal_item)) + '\n');
                if (item.diskon_item > 0) {
                    if (item.nama_diskon) text('Promo: ' + String(item.nama_diskon).substring(0, 26));
                    line(lr('Disc/pcs ' + fmt(item.diskon_satuan) + ' x ' + item.qty, '-' + fmt(item.diskon_item)) + '\n');
                    pushArr(CMD.boldOn);
                    line(lr('Subtotal', fmt(item.subtotal_item)) + '\n');
                    pushArr(CMD.boldOff);
                }
            });

            /* SUMMARY */
            line(dash() + '\n');
            line(lr('SUBTOTAL', fmt(d.subtotal_normal)) + '\n');
            if (d.diskon_barang > 0) line(lr('DISKON BARANG', '-' + fmt(d.diskon_barang)) + '\n');
            if (d.diskon_transaksi > 0) {
                line(lr('DISKON PROMO', '-' + fmt(d.diskon_transaksi)) + '\n');
                if (d.nama_diskon_trx) text('Promo: ' + String(d.nama_diskon_trx).substring(0, 26));
            }
            if (d.point_dipakai > 0) {
                line(lr('POINT DIPAKAI', '-' + d.point_dipakai + ' pt') + '\n');
                if (d.nilai_point > 0) line(lr('NILAI POINT', '-' + fmt(d.nilai_point)) + '\n');
            }
            if (d.total_diskon > 0) {
                pushArr(CMD.boldOn);
                line(lr('TOTAL DISKON', '-' + fmt(d.total_diskon)) + '\n');
                pushArr(CMD.boldOff);
            }

            line(solid() + '\n');
            pushArr(CMD.boldOn);
            line(lr('TOTAL BAYAR', fmt(d.total_bayar)) + '\n');
            pushArr(CMD.boldOff);
            line(lr('TUNAI/QRIS', fmt(d.bayar)) + '\n');
            line(lr('KEMBALI', fmt(d.kembalian)) + '\n');

            /* POINT */
            if (d.member_nama && d.point_dapat > 0) {
                line(dash() + '\n');
                pushArr(CMD.boldOn);
                text('POINT MEMBER');
                pushArr(CMD.boldOff);
                line(lr('Point Didapat', '+' + fmt(d.point_dapat) + ' pt') + '\n');
                if (d.member_point_total) line(lr('Total Point', fmt(d.member_point_total) + ' pt') + '\n');
            }

            /* FOOTER */
            line(dash() + '\n');
            pushArr(CMD.alignCenter);
            text('BARANG YANG SUDAH DIBELI');
            text('TIDAK DAPAT DITUKAR/DIKEMBALIKAN');
            text('');
            text('*** TERIMA KASIH ***');
            pushArr(CMD.alignLeft);
            pushArr(CMD.feed(5));
            pushArr(CMD.cut);

            return new Uint8Array(buf);
        }

        /* ── Status bar ── */
        function setStatus(msg, type) {
            var el = document.getElementById('statusBar');
            if (!el) return;
            el.textContent = msg;
            el.className = type || 'info';
            if (type === 'success') {
                setTimeout(function() {
                    el.style.display = 'none';
                    el.className = '';
                }, 3500);
            }
        }

        /* ── Kirim data ke characteristic ── */
        function sendData(characteristic, data) {
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

        /* ── Coba koneksi ke device dengan semua UUID ── */
        function connectAndPrint(device) {
            setStatus('Menghubungkan ke ' + device.name + '...', 'info');
            return device.gatt.connect().then(function(server) {
                var tryUUID = function(idx) {
                    if (idx >= BT_CONFIG.length) return Promise.reject(new Error('UUID tidak cocok. Cek dengan nRF Connect.'));
                    return server.getPrimaryService(BT_CONFIG[idx].service)
                        .then(function(svc) {
                            return svc.getCharacteristic(BT_CONFIG[idx].characteristic);
                        })
                        .catch(function() {
                            return tryUUID(idx + 1);
                        });
                };
                return tryUUID(0).then(function(characteristic) {
                    setStatus('Mengirim struk...', 'info');
                    return sendData(characteristic, buildEscPos()).then(function() {
                        setStatus('Struk terkirim ke ' + device.name + '!', 'success');
                        try {
                            server.disconnect();
                        } catch (e) {}
                    });
                });
            });
        }

        /* ── Print via Bluetooth ── */
        function printBluetooth() {
            if (!navigator.bluetooth) {
                var notice = document.getElementById('httpsNotice');
                if (!window.isSecureContext) {
                    if (notice) notice.style.display = 'block';
                    setStatus('Web Bluetooth butuh HTTPS. Lihat panduan di bawah.', 'error');
                } else {
                    setStatus('Browser tidak support Bluetooth. Gunakan Chrome/Edge.', 'error');
                }
                return;
            }

            var btn = document.getElementById('btnBluetooth');
            if (btn) btn.disabled = true;
            setStatus('Mencari printer...', 'info');

            navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: BT_CONFIG.map(function(c) {
                        return c.service;
                    })
                })
                .then(function(device) {
                    try {
                        localStorage.setItem(STORAGE_KEY, device.name || '');
                    } catch (e) {}
                    return connectAndPrint(device);
                })
                .catch(function(err) {
                    if (err.name === 'NotFoundError') {
                        setStatus('Tidak ada printer dipilih.', 'info');
                    } else if (err.name === 'SecurityError') {
                        var n = document.getElementById('httpsNotice');
                        if (n) n.style.display = 'block';
                        setStatus('Bluetooth diblokir. Lihat panduan di bawah.', 'error');
                    } else {
                        setStatus('Gagal: ' + err.message, 'error');
                    }
                })
                .finally(function() {
                    var b = document.getElementById('btnBluetooth');
                    if (b) b.disabled = false;
                });
        }

        /* ── Auto print saat load ── */
        window.addEventListener('load', function() {
            if (AUTO_PRINT) setTimeout(printBluetooth, 600);
        });
    </script>

</body>

</html>