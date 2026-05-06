<?php

/**
 * debug_struk.php — Cek struktur database untuk diagnosa struk terpotong
 * HAPUS FILE INI SETELAH SELESAI DEBUG!
 */
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

function cek_kolom(PDO $pdo, string $tabel): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `$tabel`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [['error' => $e->getMessage()]];
    }
}

function cek_transaksi_detail(PDO $pdo, string $invoice): array
{
    try {
        // Cari transaksi_id dulu
        $s = $pdo->prepare("SELECT id, invoice, total, member_id FROM transaksi WHERE invoice = :inv LIMIT 1");
        $s->execute([':inv' => $invoice]);
        $trx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$trx) return ['error' => "Transaksi '$invoice' tidak ditemukan"];

        // Cek detail tanpa JOIN dulu
        $s2 = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = :tid");
        $s2->execute([':tid' => $trx['id']]);
        $detail = $s2->fetchAll(PDO::FETCH_ASSOC);

        return [
            'transaksi' => $trx,
            'jumlah_detail' => count($detail),
            'detail_sample' => array_slice($detail, 0, 3),
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// Ambil invoice terbaru untuk test
$invoiceTest = '';
try {
    $r = $pdo->query("SELECT invoice FROM transaksi ORDER BY id DESC LIMIT 1");
    $invoiceTest = (string)($r->fetchColumn() ?? '');
} catch (Throwable $e) {
}

$invoice = trim((string)($_GET['invoice'] ?? $invoiceTest));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Debug Struk</title>
    <style>
        body {
            font-family: monospace;
            background: #f5f5f5;
            padding: 20px;
            font-size: 13px;
        }

        h2 {
            background: #222;
            color: #fff;
            padding: 10px 16px;
            margin: 20px 0 8px;
        }

        h3 {
            color: #333;
            border-bottom: 2px solid #ddd;
            padding-bottom: 4px;
            margin: 16px 0 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-bottom: 16px;
        }

        th {
            background: #333;
            color: #fff;
            padding: 6px 10px;
            text-align: left;
            font-size: 11px;
        }

        td {
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
        }

        tr:nth-child(even) {
            background: #fafafa;
        }

        .ok {
            color: #15803d;
            font-weight: bold;
        }

        .err {
            color: #dc2626;
            font-weight: bold;
        }

        .warn {
            color: #d97706;
            font-weight: bold;
        }

        .box {
            background: #fff;
            border: 1px solid #ddd;
            padding: 16px;
            margin-bottom: 16px;
        }

        pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 12px;
            overflow-x: auto;
            border-radius: 4px;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }

        .badge-ok {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-err {
            background: #fee2e2;
            color: #dc2626;
        }
    </style>
</head>

<body>

    <h2>🔍 DEBUG STRUK — Koperasi BSDK</h2>

    <div class="box">
        <strong>⚠️ HAPUS FILE INI SETELAH SELESAI DEBUG!</strong><br>
        File: <code>debug_struk.php</code>
    </div>

    <!-- Form test invoice -->
    <div class="box">
        <form method="get">
            <label><strong>Test Invoice:</strong></label>
            <input type="text" name="invoice" value="<?= htmlspecialchars($invoice) ?>" style="width:300px;padding:6px;margin:0 8px;border:1px solid #ccc;">
            <button type="submit" style="padding:6px 16px;background:#222;color:#fff;border:none;cursor:pointer;">Cek</button>
        </form>
        <?php if ($invoiceTest): ?>
            <div style="margin-top:8px;font-size:11px;color:#666;">Invoice terbaru di DB: <strong><?= htmlspecialchars($invoiceTest) ?></strong></div>
        <?php endif; ?>
    </div>

    <!-- ─── 1. Struktur tabel transaksi_detail ─────────────────────── -->
    <h3>1. Kolom Tabel <code>transaksi_detail</code></h3>
    <?php
    $kolomDetail = cek_kolom($pdo, 'transaksi_detail');
    $namaKolom = array_column($kolomDetail, 'Field');
    $kolom_penting = ['id', 'transaksi_id', 'produk_id', 'nama', 'kode', 'harga', 'harga_normal', 'diskon', 'diskon_id', 'qty', 'subtotal'];
    ?>
    <table>
        <tr>
            <th>Field</th>
            <th>Type</th>
            <th>Null</th>
            <th>Default</th>
            <th>Status</th>
        </tr>
        <?php foreach ($kolomDetail as $k): ?>
            <tr>
                <td><?= htmlspecialchars($k['Field'] ?? $k['error'] ?? '?') ?></td>
                <td><?= htmlspecialchars($k['Type'] ?? '') ?></td>
                <td><?= htmlspecialchars($k['Null'] ?? '') ?></td>
                <td><?= htmlspecialchars($k['Default'] ?? '') ?></td>
                <td>
                    <?php if (isset($k['error'])): ?>
                        <span class="badge badge-err">ERROR: <?= htmlspecialchars($k['error']) ?></span>
                    <?php elseif (in_array($k['Field'], ['harga_normal', 'diskon', 'diskon_id'])): ?>
                        <span class="badge badge-ok">✓ Ada (opsional)</span>
                    <?php else: ?>
                        <span class="badge badge-ok">✓</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php
    // Cek kolom kritis
    $kritisAda = [];
    $kritisTidakAda = [];
    foreach (['id', 'transaksi_id', 'nama', 'harga', 'qty'] as $kol) {
        if (in_array($kol, $namaKolom)) $kritisAda[] = $kol;
        else $kritisTidakAda[] = $kol;
    }
    $opsionalAda = [];
    $opsionalTidakAda = [];
    foreach (['harga_normal', 'diskon', 'diskon_id', 'subtotal'] as $kol) {
        if (in_array($kol, $namaKolom)) $opsionalAda[] = $kol;
        else $opsionalTidakAda[] = $kol;
    }
    ?>
    <div class="box">
        <strong>Kolom Kritis:</strong>
        <?php foreach ($kritisAda as $k): ?><span class="badge badge-ok">✓ <?= $k ?></span> <?php endforeach; ?>
        <?php foreach ($kritisTidakAda as $k): ?><span class="badge badge-err">✗ <?= $k ?> — MISSING!</span> <?php endforeach; ?>
        <br><br>
        <strong>Kolom Opsional:</strong>
        <?php foreach ($opsionalAda as $k): ?><span class="badge badge-ok">✓ <?= $k ?></span> <?php endforeach; ?>
        <?php foreach ($opsionalTidakAda as $k): ?><span class="badge" style="background:#fef9c3;color:#854d0e;">– <?= $k ?> (tidak ada, akan di-fallback)</span> <?php endforeach; ?>
    </div>

    <!-- ─── 2. Struktur tabel transaksi ───────────────────────────── -->
    <h3>2. Kolom Tabel <code>transaksi</code></h3>
    <?php $kolomTrx = cek_kolom($pdo, 'transaksi'); ?>
    <table>
        <tr>
            <th>Field</th>
            <th>Type</th>
            <th>Null</th>
            <th>Default</th>
        </tr>
        <?php foreach ($kolomTrx as $k): ?>
            <tr>
                <td><?= htmlspecialchars($k['Field'] ?? $k['error'] ?? '?') ?></td>
                <td><?= htmlspecialchars($k['Type'] ?? '') ?></td>
                <td><?= htmlspecialchars($k['Null'] ?? '') ?></td>
                <td><?= htmlspecialchars($k['Default'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <!-- ─── 3. Cek tabel diskon ───────────────────────────────────── -->
    <h3>3. Cek Tabel <code>diskon</code></h3>
    <?php
    try {
        $r = $pdo->query("SELECT COUNT(*) FROM diskon");
        $jml = $r->fetchColumn();
        echo '<div class="box"><span class="ok">✓ Tabel diskon ada</span>, berisi ' . $jml . ' baris.</div>';
    } catch (Throwable $e) {
        echo '<div class="box"><span class="err">✗ Tabel diskon ERROR: ' . htmlspecialchars($e->getMessage()) . '</span><br>';
        echo '<strong>→ Ini penyebab utama struk terpotong!</strong> Kolom diskon_id di transaksi_detail tidak bisa di-JOIN.</div>';
    }
    ?>

    <!-- ─── 4. Test query detail untuk invoice spesifik ───────────── -->
    <h3>4. Test Data <code>transaksi_detail</code> untuk Invoice: <em><?= htmlspecialchars($invoice) ?></em></h3>
    <?php
    if ($invoice) {
        $hasil = cek_transaksi_detail($pdo, $invoice);
        if (isset($hasil['error'])) {
            echo '<div class="box"><span class="err">ERROR: ' . htmlspecialchars($hasil['error']) . '</span></div>';
        } else {
            $trxInfo = $hasil['transaksi'];
            $jmlDetail = $hasil['jumlah_detail'];
            echo '<div class="box">';
            echo '<strong>Transaksi ID:</strong> ' . $trxInfo['id'] . '<br>';
            echo '<strong>Invoice:</strong> ' . htmlspecialchars($trxInfo['invoice']) . '<br>';
            echo '<strong>Total:</strong> Rp ' . number_format($trxInfo['total'], 0, ',', '.') . '<br>';
            echo '<strong>Jumlah baris detail:</strong> ';
            if ($jmlDetail > 0) {
                echo '<span class="ok">' . $jmlDetail . ' baris — OK ✓</span>';
            } else {
                echo '<span class="err">0 baris — KOSONG! Ini penyebab struk terpotong.</span>';
            }
            echo '</div>';

            if ($jmlDetail > 0) {
                echo '<table><tr>';
                foreach (array_keys($hasil['detail_sample'][0]) as $h) {
                    echo '<th>' . htmlspecialchars($h) . '</th>';
                }
                echo '</tr>';
                foreach ($hasil['detail_sample'] as $row) {
                    echo '<tr>';
                    foreach ($row as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
    }
    ?>

    <!-- ─── 5. Test JOIN dengan diskon ───────────────────────────── -->
    <h3>5. Test Query JOIN (persis seperti di struk.php)</h3>
    <?php
    if ($invoice) {
        // Cek apakah diskon_id ada di transaksi_detail
        $adaDiskonId = in_array('diskon_id', $namaKolom);
        echo '<div class="box">';
        echo 'Kolom <code>diskon_id</code> di <code>transaksi_detail</code>: ';
        echo $adaDiskonId
            ? '<span class="ok">✓ Ada</span>'
            : '<span class="warn">✗ Tidak ada — JOIN akan dilewati (sudah di-handle oleh struk.php v3.2)</span>';
        echo '</div>';

        // Test query yang dipakai struk.php lama (v3.1)
        echo '<strong>Query lama (v3.1) — pakai JOIN tanpa cek kolom:</strong>';
        try {
            $s = $pdo->prepare("SELECT id FROM transaksi WHERE invoice = :inv LIMIT 1");
            $s->execute([':inv' => $invoice]);
            $tid = $s->fetchColumn();

            $queryLama = "
            SELECT td.*, di.nama AS nama_diskon_item
            FROM transaksi_detail td
            LEFT JOIN diskon di ON di.id = td.diskon_id
            WHERE td.transaksi_id = :tid
        ";
            $s2 = $pdo->prepare($queryLama);
            $s2->execute([':tid' => $tid]);
            $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
            echo '<pre style="color:#86efac">✓ Query lama BERHASIL — ' . count($rows) . ' baris</pre>';
        } catch (Throwable $e) {
            echo '<pre style="color:#fca5a5">✗ Query lama GAGAL: ' . htmlspecialchars($e->getMessage()) . '

→ INILAH PENYEBAB UTAMA! struk.php v3.1 tidak punya fallback untuk error ini.
→ Solusi: Gunakan struk.php v3.2 yang sudah diperbaiki.</pre>';
        }
    }
    ?>

    <!-- ─── 6. Ringkasan & Solusi ─────────────────────────────────── -->
    <h3>6. Ringkasan Diagnosa</h3>
    <div class="box">
        <?php
        $masalah = [];
        // Cek tabel diskon
        try {
            $pdo->query("SELECT 1 FROM diskon LIMIT 1");
        } catch (Throwable $e) {
            $masalah[] = 'Tabel <code>diskon</code> tidak ada → JOIN gagal → detail kosong';
        }

        // Cek kolom diskon_id
        if (!in_array('diskon_id', $namaKolom)) {
            $masalah[] = 'Kolom <code>diskon_id</code> tidak ada di <code>transaksi_detail</code> → JOIN gagal di struk.php v3.1';
        }

        // Cek kolom harga_normal
        if (!in_array('harga_normal', $namaKolom)) {
            $masalah[] = 'Kolom <code>harga_normal</code> tidak ada → harga asli tidak bisa ditampilkan (sudah di-fallback di v3.2)';
        }

        if (empty($masalah)) {
            echo '<span class="ok">✓ Tidak ada masalah struktur yang terdeteksi.</span><br>';
            echo 'Jika struk masih terpotong, pastikan sudah menggunakan <strong>struk.php v3.2</strong>.';
        } else {
            echo '<strong>Masalah yang ditemukan:</strong><ul>';
            foreach ($masalah as $m) echo '<li class="err" style="margin:4px 0">✗ ' . $m . '</li>';
            echo '</ul>';
            echo '<br><strong>Solusi:</strong> Gunakan file <strong>struk.php v3.2</strong> yang sudah di-generate sebelumnya. File tersebut sudah menangani semua kasus di atas.';
        }
        ?>
    </div>

    <div class="box" style="background:#fef2f2;border-color:#fca5a5;">
        <strong style="color:#dc2626;">⚠️ PENTING: Hapus file debug_struk.php setelah selesai!</strong>
    </div>

</body>

</html>