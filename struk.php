<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| STRUK FINAL FIX
|--------------------------------------------------------------------------
| File ini wajib disimpan sebagai: struk.php
|
| Fitur:
| - Menampilkan diskon barang dari transaksi_detail.diskon
| - Menampilkan Disc/pcs x qty
| - Menampilkan subtotal setelah diskon per item
| - Menampilkan total diskon
| - Menampilkan point member jika transaksi punya member dan point_dapat > 0
|
| Pastikan POS membuka:
| struk.php?invoice=INV-xxxx
*/

$invoice = trim($_GET['invoice'] ?? '');

if ($invoice === '') {
    die('Invoice tidak ditemukan.');
}

$autoPrint = isset($_GET['print']) && $_GET['print'] == '1';
$isMember  = isset($_GET['member']) && $_GET['member'] == '1';

/**
 * @param mixed $v
 */
function uang_struk($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

function line_lr(string $left, string $right, int $width = 32): string
{
    $left = trim($left);
    $right = trim($right);
    $space = $width - strlen($left) - strlen($right);
    if ($space < 1) {
        $space = 1;
    }
    return $left . str_repeat(' ', $space) . $right;
}

function cut_text(string $text, int $max): string
{
    $text = trim($text);
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, max(0, $max - 1)) . '.';
}

try {
    /*
     * Ambil transaksi + member + nama promo transaksi.
     * LEFT JOIN aman walaupun diskon_id NULL.
     */
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            m.kode AS member_kode,
            m.nama AS member_nama,
            m.point AS member_point_total,
            d.nama AS nama_diskon
        FROM transaksi t
        LEFT JOIN member m ON m.id = t.member_id
        LEFT JOIN diskon d ON d.id = t.diskon_id
        WHERE t.invoice = :invoice
        LIMIT 1
    ");
    $stmt->execute([':invoice' => $invoice]);
    $trx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trx) {
        die('Transaksi tidak ditemukan.');
    }

    /*
     * Ambil detail transaksi.
     * Ini khusus setelah tabel sudah di-ALTER:
     * harga_normal, diskon, diskon_id
     */
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
    $items = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        die('Detail transaksi kosong.');
    }

    $subtotalNormal = 0;
    $totalDiskonBarang = 0;
    $subtotalSetelahDiskonBarang = 0;
    $rincianDiskonBarang = [];

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? 0);

        $hargaNormal = $item['harga_normal'] !== null && $item['harga_normal'] !== ''
            ? (int)$item['harga_normal']
            : (int)($item['harga'] ?? 0);

        $hargaFinal = (int)($item['harga'] ?? $hargaNormal);
        $subtotalItem = (int)($item['subtotal'] ?? ($hargaFinal * $qty));
        $normalItem = $hargaNormal * $qty;

        $diskonItem = (int)($item['diskon'] ?? 0);

        /*
         * Fallback: kalau diskon tidak terisi tapi harga_normal > harga final.
         */
        if ($diskonItem <= 0 && $normalItem > $subtotalItem) {
            $diskonItem = $normalItem - $subtotalItem;
        }

        $subtotalNormal += $normalItem;
        $subtotalSetelahDiskonBarang += $subtotalItem;
        $totalDiskonBarang += $diskonItem;

        if ($diskonItem > 0) {
            $rincianDiskonBarang[] = [
                'nama' => (string)($item['nama'] ?? 'ITEM'),
                'qty' => $qty,
                'diskon' => $diskonItem,
                'diskon_satuan' => $qty > 0 ? ($diskonItem / $qty) : $diskonItem,
                'nama_diskon_item' => $item['nama_diskon_item'] ?? null,
            ];
        }
    }

    $totalBayar = (int)($trx['total'] ?? $subtotalSetelahDiskonBarang);
    $bayar = (int)($trx['bayar'] ?? 0);
    $kembalian = (int)($trx['kembalian'] ?? max(0, $bayar - $totalBayar));

    /*
     * transaksi.diskon berisi total diskon yang disimpan POS.
     * Kalau kosong, fallback dari subtotal normal - total bayar.
     */
    $totalDiskon = (int)($trx['diskon'] ?? 0);
    if ($totalDiskon <= 0) {
        $totalDiskon = max(0, $subtotalNormal - $totalBayar);
    }

    $diskonTransaksi = max(0, $totalDiskon - $totalDiskonBarang);

    $pointDapat = (int)($trx['point_dapat'] ?? 0);
    $memberNama = $trx['member_nama'] ?? null;
    $memberKode = $trx['member_kode'] ?? null;
    $memberPointTotal = $trx['member_point_total'] ?? null;

    $tanggalRaw = $trx['created_at'] ?? date('Y-m-d H:i:s');
    $tanggal = date('d/m/Y H:i:s', strtotime((string)$tanggalRaw));
    $operator = $_SESSION['nama'] ?? 'Administrator';
} catch (Throwable $e) {
    die('Gagal memuat struk: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Struk <?= htmlspecialchars($invoice) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f4f6;
            font-family: "Courier New", Courier, monospace;
            color: #111;
        }

        .page {
            min-height: 100vh;
            padding: 12px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .wrap {
            width: 100%;
            max-width: 56mm;
            margin: 0 auto;
        }

        .toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            font-family: Arial, sans-serif;
        }

        .toolbar a,
        .toolbar button {
            flex: 1;
            border: 1px solid #111;
            padding: 9px 10px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
        }

        .toolbar a {
            background: #fff;
            color: #111;
        }

        .toolbar button {
            background: #111;
            color: #fff;
        }

        .receipt {
            width: 100%;
            max-width: 56mm;
            background: #fff;
            padding: 2mm;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .12);
            font-size: 10px;
            line-height: 1.3;
            overflow: visible;
        }

        .center {
            text-align: center;
        }

        .store {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .small {
            font-size: 10px;
        }

        .bold {
            font-weight: 800;
        }

        .dash {
            border-top: 1px dashed #111;
            margin: 7px 0;
        }

        .solid {
            border-top: 1px solid #111;
            margin: 8px 0;
        }

        .line {
            white-space: pre;
            overflow: hidden;
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

        .promo {
            padding-left: 8px;
        }

        .thanks {
            margin-top: 10px;
            text-align: center;
            font-size: 10px;
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="wrap">
            <?php if (!$isMember): ?>
                <div class="toolbar">
                    <a href="pos.php">Kembali</a>
                    <button type="button" onclick="window.print()">Cetak</button>
                </div>
            <?php endif; ?>

            <div class="receipt">
                <div class="center">
                    <div class="store">KOPERASI BSDK</div>
                    <div class="small">MESIN KASIR / POS</div>
                    <div class="small">Terima Kasih Atas Kunjungan Anda</div>
                </div>

                <div class="dash"></div>

                <div class="line"><?= htmlspecialchars(line_lr('No', $invoice)) ?></div>
                <div class="line"><?= htmlspecialchars(line_lr('Tgl', $tanggal)) ?></div>
                <div class="line"><?= htmlspecialchars(line_lr('Kasir', (string)$operator)) ?></div>

                <?php if (!empty($memberNama)): ?>
                    <div class="line"><?= htmlspecialchars(line_lr('Member', cut_text((string)$memberNama, 18))) ?></div>
                    <?php if (!empty($memberKode)): ?>
                        <div class="line"><?= htmlspecialchars(line_lr('Kode', (string)$memberKode)) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="dash"></div>

                <?php foreach ($items as $item): ?>
                    <?php
                    $nama = (string)($item['nama'] ?? 'ITEM');
                    $qty = (int)($item['qty'] ?? 0);

                    $hargaNormal = $item['harga_normal'] !== null && $item['harga_normal'] !== ''
                        ? (int)$item['harga_normal']
                        : (int)($item['harga'] ?? 0);

                    $hargaFinal = (int)($item['harga'] ?? $hargaNormal);
                    $subtotalItem = (int)($item['subtotal'] ?? ($hargaFinal * $qty));
                    $normalItem = $hargaNormal * $qty;

                    $diskonItem = (int)($item['diskon'] ?? 0);
                    if ($diskonItem <= 0 && $normalItem > $subtotalItem) {
                        $diskonItem = $normalItem - $subtotalItem;
                    }

                    $diskonSatuan = $qty > 0 ? ($diskonItem / $qty) : $diskonItem;
                    ?>

                    <div class="item-name"><?= htmlspecialchars(cut_text($nama, 30)) ?></div>
                    <div class="line"><?= htmlspecialchars(line_lr($qty . ' x ' . uang_struk($hargaNormal), uang_struk($normalItem))) ?></div>

                    <?php if ($diskonItem > 0): ?>
                        <?php if (!empty($item['nama_diskon_item'])): ?>
                            <div class="small muted promo"><?= htmlspecialchars('Promo: ' . cut_text((string)$item['nama_diskon_item'], 24)) ?></div>
                        <?php endif; ?>

                        <div class="line"><?= htmlspecialchars(line_lr('Disc/pcs ' . uang_struk($diskonSatuan) . ' x ' . $qty, '-' . uang_struk($diskonItem))) ?></div>
                        <div class="line"><?= htmlspecialchars(line_lr('Subtotal', uang_struk($subtotalItem))) ?></div>
                    <?php endif; ?>

                <?php endforeach; ?>

                <div class="dash"></div>

                <div class="line"><?= htmlspecialchars(line_lr('SUBTOTAL', uang_struk($subtotalNormal))) ?></div>

                <?php if ($totalDiskonBarang > 0): ?>
                    <div class="line"><?= htmlspecialchars(line_lr('DISKON BARANG', '-' . uang_struk($totalDiskonBarang))) ?></div>
                <?php endif; ?>

                <?php if ($diskonTransaksi > 0): ?>
                    <div class="line"><?= htmlspecialchars(line_lr('DISKON PROMO', '-' . uang_struk($diskonTransaksi))) ?></div>
                    <?php if (!empty($trx['nama_diskon'])): ?>
                        <div class="small muted"><?= htmlspecialchars('Promo: ' . cut_text((string)$trx['nama_diskon'], 26)) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($totalDiskon > 0): ?>
                    <div class="line bold"><?= htmlspecialchars(line_lr('TOTAL DISKON', '-' . uang_struk($totalDiskon))) ?></div>
                <?php endif; ?>

                <div class="solid"></div>

                <div class="line bold"><?= htmlspecialchars(line_lr('TOTAL BAYAR', uang_struk($totalBayar))) ?></div>
                <div class="line"><?= htmlspecialchars(line_lr('TUNAI/QRIS', uang_struk($bayar))) ?></div>
                <div class="line"><?= htmlspecialchars(line_lr('KEMBALI', uang_struk($kembalian))) ?></div>

                <?php if (!empty($memberNama) && $pointDapat > 0): ?>
                    <div class="dash"></div>
                    <div class="bold">POINT MEMBER</div>
                    <div class="line"><?= htmlspecialchars(line_lr('Point Didapat', '+' . uang_struk($pointDapat) . ' pt')) ?></div>

                    <?php if ($memberPointTotal !== null && $memberPointTotal !== ''): ?>
                        <div class="line"><?= htmlspecialchars(line_lr('Total Point', uang_struk($memberPointTotal) . ' pt')) ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="dash"></div>

                <div class="thanks">
                    BARANG YANG SUDAH DIBELI<br>
                    TIDAK DAPAT DITUKAR/DIKEMBALIKAN<br><br>
                    *** TERIMA KASIH ***
                </div>
            </div>
        </div>
    </div>

    <?php if ($autoPrint && !$isMember): ?>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        </script>
    <?php endif; ?>

</body>

</html>