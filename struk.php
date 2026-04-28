<?php
require_once 'config.php';

// Ambil transaksi berdasarkan invoice atau id
$invoice = $_GET['invoice'] ?? null;
$id      = $_GET['id'] ?? null;

if (!$invoice && !$id) {
    die('<p>Parameter tidak valid.</p>');
}

if ($invoice) {
    $stmt = $pdo->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.user_id = u.id WHERE t.invoice = :invoice");
    $stmt->execute([':invoice' => $invoice]);
} else {
    $stmt = $pdo->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = :id");
    $stmt->execute([':id' => $id]);
}

$trans = $stmt->fetch();
if (!$trans) die('<p>Transaksi tidak ditemukan.</p>');

// Detail items
$stmtDetail = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = :id ORDER BY id");
$stmtDetail->execute([':id' => $trans['id']]);
$items = $stmtDetail->fetchAll();

$subtotal = array_sum(array_column($items, 'subtotal'));
$pajak    = $trans['total'] - $subtotal;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Struk <?= htmlspecialchars($trans['invoice']) ?></title>
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
            width: 280px;
            margin: 20px auto;
            padding: 16px 0;
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
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

        .item-name {
            flex: 1;
        }

        .item-qty {
            width: 30px;
            text-align: center;
        }

        .item-price {
            width: 80px;
            text-align: right;
        }

        .total-row {
            font-weight: bold;
            font-size: 14px;
            margin: 4px 0;
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
            }
        }
    </style>
</head>

<body>
    <div class="struk">
        <div class="center bold" style="font-size:14px">RETAIL MANAGER</div>
        <div class="center">ID Toko: T042 - BOGOR</div>
        <div class="center">Jl. Contoh No. 1, Bogor</div>
        <div class="divider"></div>

        <div class="row">
            <span>No. Struk</span>
            <span><?= htmlspecialchars($trans['invoice']) ?></span>
        </div>
        <div class="row">
            <span>Tanggal</span>
            <span><?= date('d/m/Y H:i', strtotime($trans['created_at'])) ?></span>
        </div>
        <div class="row">
            <span>Kasir</span>
            <span><?= htmlspecialchars($trans['kasir'] ?? 'N/A') ?></span>
        </div>

        <div class="divider"></div>

        <?php foreach ($items as $item): ?>
            <div style="margin: 4px 0;">
                <div class="bold"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="row">
                    <span><?= $item['qty'] ?>x <?= number_format($item['harga'], 0, ',', '.') ?></span>
                    <span><?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="divider"></div>

        <div class="row">
            <span>Subtotal</span>
            <span>Rp <?= number_format($subtotal, 0, ',', '.') ?></span>
        </div>
        <div class="row">
            <span>PPN 11%</span>
            <span>Rp <?= number_format($pajak, 0, ',', '.') ?></span>
        </div>
        <div class="divider"></div>
        <div class="row total-row">
            <span>TOTAL</span>
            <span>Rp <?= number_format($trans['total'], 0, ',', '.') ?></span>
        </div>
        <?php if ($trans['bayar'] > 0): ?>
            <div class="row">
                <span>Bayar</span>
                <span>Rp <?= number_format($trans['bayar'], 0, ',', '.') ?></span>
            </div>
            <div class="row bold">
                <span>Kembalian</span>
                <span>Rp <?= number_format($trans['kembalian'], 0, ',', '.') ?></span>
            </div>
        <?php endif; ?>

        <div class="divider"></div>
        <div class="center" style="margin-top:8px">Terima kasih atas kunjungan Anda!</div>
        <div class="center" style="font-size:10px;margin-top:4px">Barang yang sudah dibeli<br>tidak dapat dikembalikan</div>

        <div class="no-print" style="text-align:center; margin-top:20px">
            <button onclick="window.print()"
                style="padding:8px 24px; background:#000; color:#fff; border:none; cursor:pointer; font-size:12px">
                CETAK STRUK
            </button>
            <button onclick="window.close()"
                style="padding:8px 24px; background:#eee; border:none; cursor:pointer; font-size:12px; margin-left:8px">
                TUTUP
            </button>
        </div>
    </div>
    <script>
        // Auto print saat halaman dibuka
        window.addEventListener('load', () => {
            setTimeout(() => window.print(), 500);
        });
    </script>
</body>

</html>