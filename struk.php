<?php
require_once 'config.php';

$invoice = $_GET['invoice'] ?? null;
$id      = $_GET['id']      ?? null;

if (!$invoice && !$id) die('<p>Parameter tidak valid.</p>');

if ($invoice) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nama AS kasir, m.nama AS member_nama, m.kode AS member_kode, m.point AS member_point_sekarang
        FROM transaksi t
        LEFT JOIN users  u ON t.user_id   = u.id
        LEFT JOIN member m ON t.member_id = m.id
        WHERE t.invoice = :invoice
    ");
    $stmt->execute([':invoice' => $invoice]);
} else {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nama AS kasir, m.nama AS member_nama, m.kode AS member_kode, m.point AS member_point_sekarang
        FROM transaksi t
        LEFT JOIN users  u ON t.user_id   = u.id
        LEFT JOIN member m ON t.member_id = m.id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $id]);
}

$trans = $stmt->fetch();
if (!$trans) die('<p>Transaksi tidak ditemukan.</p>');

$stmtDetail = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = :id ORDER BY id");
$stmtDetail->execute([':id' => $trans['id']]);
$items = $stmtDetail->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Struk <?= e($trans['invoice']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: #fff;
            color: #000;
        }

        .struk {
            width: 300px;
            margin: 20px auto;
            padding: 16px 0;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .divider-solid {
            border-top: 1px solid #000;
            margin: 8px 0;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }

        .total-row {
            font-weight: bold;
            font-size: 13px;
            margin: 4px 0;
        }

        .point-box {
            border: 1px dashed #000;
            padding: 8px;
            margin: 8px 0;
            text-align: center;
        }

        @media print {
            body {
                background: #fff;
            }

            .no-print {
                display: none;
            }

            @page {
                margin: 0;
                size: 80mm auto;
            }
        }
    </style>
</head>

<body>
    <div class="struk">

        <!-- Header -->
        <div class="center bold" style="font-size:15px; letter-spacing:1px">KOPERASI BSDK</div>
        <div class="center" style="font-size:10px; margin-top:2px">ID Toko: T042 – BOGOR</div>
        <div class="divider-solid"></div>

        <!-- Info Transaksi -->
        <div class="row"><span>No. Struk</span><span class="bold"><?= e($trans['invoice']) ?></span></div>
        <div class="row"><span>Tanggal</span><span><?= date('d/m/Y H:i', strtotime($trans['created_at'])) ?></span></div>
        <div class="row"><span>Kasir</span><span><?= e($trans['kasir'] ?? 'N/A') ?></span></div>

        <?php if ($trans['member_nama']): ?>
            <div class="row"><span>Member</span><span class="bold"><?= e($trans['member_nama']) ?></span></div>
            <div class="row"><span>Kode</span><span><?= e($trans['member_kode']) ?></span></div>
        <?php endif; ?>

        <div class="divider"></div>

        <!-- Item Belanja -->
        <?php foreach ($items as $item): ?>
            <div style="margin:4px 0">
                <div class="bold"><?= e($item['nama']) ?></div>
                <div class="row">
                    <span><?= $item['qty'] ?> x <?= number_format($item['harga'], 0, ',', '.') ?></span>
                    <span><?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="divider"></div>

        <!-- Total — tanpa pajak -->
        <div class="row total-row">
            <span>TOTAL</span>
            <span>Rp <?= number_format($trans['total'], 0, ',', '.') ?></span>
        </div>

        <?php if ($trans['bayar'] > 0): ?>
            <div class="row"><span>Bayar</span><span>Rp <?= number_format($trans['bayar'], 0, ',', '.') ?></span></div>
            <div class="row bold"><span>Kembalian</span><span>Rp <?= number_format($trans['kembalian'], 0, ',', '.') ?></span></div>
        <?php endif; ?>

        <!-- Point Member -->
        <?php if ($trans['member_nama'] && $trans['point_dapat'] > 0): ?>
            <div class="divider"></div>
            <div class="point-box">
                <div class="bold" style="font-size:11px; letter-spacing:1px">★ POINT MEMBER ★</div>
                <div style="margin-top:4px">
                    <span>Diperoleh transaksi ini</span>:
                    <span class="bold">+<?= number_format($trans['point_dapat'], 0, ',', '.') ?> pt</span>
                </div>
                <div style="margin-top:2px">
                    <span>Total point</span>:
                    <span class="bold"><?= number_format($trans['member_point_sekarang'] ?? 0, 0, ',', '.') ?> pt</span>
                </div>
                <div style="font-size:10px; margin-top:4px; font-style:italic">
                    1 point setiap kelipatan Rp 10.000
                </div>
            </div>
        <?php elseif ($trans['member_nama']): ?>
            <div class="divider"></div>
            <div class="point-box">
                <div style="font-size:10px">Member: <?= e($trans['member_nama']) ?></div>
                <div style="font-size:10px">Total point: <span class="bold"><?= number_format($trans['member_point_sekarang'] ?? 0, 0, ',', '.') ?> pt</span></div>
            </div>
        <?php endif; ?>

        <div class="divider"></div>
        <div class="center" style="margin-top:8px">Terima kasih telah berbelanja!</div>
        <div class="center" style="font-size:10px; margin-top:4px">Barang yang sudah dibeli<br>tidak dapat dikembalikan</div>
        <div class="center" style="font-size:10px; margin-top:4px">No. Rek BSI: 7298988474</div>

        <div class="no-print" style="text-align:center; margin-top:20px; display:flex; gap:8px; justify-content:center">
            <button onclick="window.print()" style="padding:8px 24px; background:#000; color:#fff; border:none; cursor:pointer; font-size:12px">CETAK STRUK</button>
            <button onclick="window.close()" style="padding:8px 24px; background:#eee; border:none; cursor:pointer; font-size:12px">TUTUP</button>
        </div>
    </div>
    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 500));
    </script>
</body>

</html>