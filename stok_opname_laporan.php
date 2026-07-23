<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
requireAccess();

if (!function_exists('lap_h')) {
    function lap_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('lap_angka')) {
    function lap_angka($value): string
    {
        return number_format((float)($value ?? 0), 0, ',', '.');
    }
}
if (!function_exists('lap_rupiah')) {
    function lap_rupiah($value): string
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}
if (!function_exists('lap_tanggal')) {
    function lap_tanggal($value, bool $withTime = true): string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '' || $raw === '0000-00-00') return '-';
        $ts = strtotime($raw);
        if ($ts === false) return '-';
        return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
    }
}
if (!function_exists('lap_current_user_id')) {
    function lap_current_user_id(): int
    {
        foreach (['user_id', 'id_user', 'id', 'admin_id'] as $key) {
            if (!empty($_SESSION[$key])) return (int)$_SESSION[$key];
        }
        if (!empty($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
        return 0;
    }
}

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id < 1) {
    http_response_code(400);
    exit('ID stok opname tidak valid.');
}

$stmt = $pdo->prepare('SELECT * FROM stok_opname WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$header = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    http_response_code(404);
    exit('Laporan stok opname tidak ditemukan.');
}

$isAdmin = function_exists('has_role') ? has_role('admin') : false;
$currentUserId = lap_current_user_id();
if (!$isAdmin && $currentUserId > 0 && (int)($header['user_id'] ?? 0) !== $currentUserId) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke laporan stok opname ini.');
}

$stmtDetail = $pdo->prepare('SELECT * FROM stok_opname_detail WHERE stok_opname_id = :id ORDER BY nama_produk ASC, id ASC');
$stmtDetail->execute([':id' => $id]);
$details = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

$totalSistem = 0;
$totalFisik = 0;
$totalSelisih = 0;
$nilaiSelisih = 0;
$totalExpired = 0;
$today = date('Y-m-d');
foreach ($details as $row) {
    $sistem = (int)($row['stok_sistem'] ?? 0);
    $fisik = (int)($row['stok_fisik'] ?? 0);
    $selisih = (int)($row['selisih'] ?? ($fisik - $sistem));
    $totalSistem += $sistem;
    $totalFisik += $fisik;
    $totalSelisih += $selisih;
    $nilaiSelisih += $selisih * (int)($row['harga_beli'] ?? 0);
    $exp = trim((string)($row['expired_date'] ?? ''));
    if ($exp !== '' && $exp !== '0000-00-00' && $exp < $today) $totalExpired++;
}

if (($_GET['format'] ?? '') === 'excel') {
    $filename = 'stok_opname_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$header['kode']) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['LAPORAN STOK OPNAME'], ';');
    fputcsv($out, ['Kode', $header['kode']], ';');
    fputcsv($out, ['Tanggal', lap_tanggal($header['tanggal'])], ';');
    fputcsv($out, ['Operator', $header['user_nama'] ?: '-'], ';');
    fputcsv($out, ['Catatan', $header['catatan'] ?: '-'], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['No', 'Kode Produk', 'Nama Produk', 'Kategori', 'Satuan', 'Harga Beli', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Nilai Selisih', 'Tanggal Kedaluwarsa', 'Catatan'], ';');
    foreach ($details as $index => $row) {
        $selisih = (int)($row['selisih'] ?? 0);
        fputcsv($out, [
            $index + 1,
            $row['kode_produk'] ?? '',
            $row['nama_produk'] ?? '',
            $row['kategori'] ?? '',
            $row['satuan'] ?? '',
            (int)($row['harga_beli'] ?? 0),
            (int)($row['stok_sistem'] ?? 0),
            (int)($row['stok_fisik'] ?? 0),
            $selisih,
            $selisih * (int)($row['harga_beli'] ?? 0),
            lap_tanggal($row['expired_date'] ?? null, false),
            $row['catatan'] ?? '',
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['TOTAL', '', '', '', '', '', $totalSistem, $totalFisik, $totalSelisih, $nilaiSelisih, '', ''], ';');
    fclose($out);
    exit;
}

$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Stok Opname - <?= lap_h($header['kode']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 16px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
        }

        .btn-dark {
            background: #111827;
            color: #fff;
            border-color: #111827;
        }

        .btn-green {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }

        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 18px auto;
            padding: 14mm;
            background: #fff;
            box-shadow: 0 12px 36px rgba(0, 0, 0, .08);
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid #111827;
        }

        .brand {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .16em;
        }

        h1 {
            margin: 5px 0 0;
            font-size: 23px;
        }

        .meta {
            min-width: 250px;
            font-size: 11px;
            line-height: 1.7;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px dotted #d1d5db;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin: 14px 0;
        }

        .summary-card {
            border: 1px solid #e5e7eb;
            padding: 10px;
        }

        .summary-label {
            font-size: 8px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .summary-value {
            margin-top: 5px;
            font-size: 15px;
            font-weight: 800;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        th {
            background: #f3f4f6;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-size: 8px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .positive {
            color: #047857;
            font-weight: 700;
        }

        .negative {
            color: #b91c1c;
            font-weight: 700;
        }

        .notes {
            margin-top: 12px;
            border: 1px solid #e5e7eb;
            padding: 10px;
            font-size: 10px;
            min-height: 48px;
        }

        .signatures {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 80px;
            margin-top: 32px;
            text-align: center;
            font-size: 10px;
        }

        .signature-space {
            height: 62px;
        }

        .line {
            border-top: 1px solid #111827;
            padding-top: 5px;
        }

        @media (max-width: 900px) {
            .sheet {
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 18px;
                box-shadow: none;
            }

            .summary {
                grid-template-columns: repeat(2, 1fr);
            }

            .header {
                flex-direction: column;
            }

            .meta {
                min-width: 0;
            }

            .table-wrap {
                overflow-x: auto;
            }

            table {
                min-width: 850px;
            }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 9mm;
            }

            body {
                background: #fff;
            }

            .toolbar {
                display: none !important;
            }

            .sheet {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .summary {
                break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            tr {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <a href="stok_opname.php" class="btn">Kembali</a>
        <button type="button" class="btn btn-dark" onclick="window.print()">Print / PDF</button>
        <a href="?id=<?= (int)$id ?>&format=excel" class="btn btn-green">Download Excel</a>
    </div>
    <main class="sheet">
        <div class="header">
            <div>
                <div class="brand">SEJAHUB</div>
                <h1>Laporan Stok Opname</h1>
                <div style="margin-top:6px;font-size:10px;color:#6b7280;">Dokumen pemeriksaan dan penyesuaian persediaan</div>
            </div>
            <div class="meta">
                <div class="meta-row"><span>Kode</span><strong><?= lap_h($header['kode']) ?></strong></div>
                <div class="meta-row"><span>Tanggal</span><strong><?= lap_h(lap_tanggal($header['tanggal'])) ?></strong></div>
                <div class="meta-row"><span>Operator</span><strong><?= lap_h($header['user_nama'] ?: '-') ?></strong></div>
                <div class="meta-row"><span>Tanggal Cetak</span><strong><?= date('d/m/Y H:i') ?></strong></div>
            </div>
        </div>

        <section class="summary">
            <div class="summary-card">
                <div class="summary-label">Total Item</div>
                <div class="summary-value"><?= lap_angka(count($details)) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Stok Sistem</div>
                <div class="summary-value"><?= lap_angka($totalSistem) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Stok Fisik</div>
                <div class="summary-value"><?= lap_angka($totalFisik) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Total Selisih</div>
                <div class="summary-value <?= $totalSelisih < 0 ? 'negative' : 'positive' ?>"><?= ($totalSelisih > 0 ? '+' : '') . lap_angka($totalSelisih) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Nilai Selisih</div>
                <div class="summary-value <?= $nilaiSelisih < 0 ? 'negative' : 'positive' ?>"><?= lap_rupiah($nilaiSelisih) ?></div>
            </div>
        </section>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="center">No</th>
                        <th>Kode</th>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th class="right">Harga Beli</th>
                        <th class="right">Sistem</th>
                        <th class="right">Fisik</th>
                        <th class="right">Selisih</th>
                        <th class="right">Nilai Selisih</th>
                        <th>Kedaluwarsa</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$details): ?>
                        <tr>
                            <td colspan="11" class="center">Tidak ada detail stok opname.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($details as $index => $row): ?>
                        <?php $selisih = (int)($row['selisih'] ?? 0);
                        $nilai = $selisih * (int)($row['harga_beli'] ?? 0); ?>
                        <tr>
                            <td class="center"><?= $index + 1 ?></td>
                            <td><?= lap_h($row['kode_produk'] ?? '-') ?></td>
                            <td><strong><?= lap_h($row['nama_produk'] ?? '-') ?></strong><br><span style="color:#6b7280"><?= lap_h($row['satuan'] ?? '') ?></span></td>
                            <td><?= lap_h($row['kategori'] ?? '-') ?></td>
                            <td class="right"><?= lap_rupiah($row['harga_beli'] ?? 0) ?></td>
                            <td class="right"><?= lap_angka($row['stok_sistem'] ?? 0) ?></td>
                            <td class="right"><?= lap_angka($row['stok_fisik'] ?? 0) ?></td>
                            <td class="right <?= $selisih < 0 ? 'negative' : 'positive' ?>"><?= ($selisih > 0 ? '+' : '') . lap_angka($selisih) ?></td>
                            <td class="right <?= $nilai < 0 ? 'negative' : 'positive' ?>"><?= lap_rupiah($nilai) ?></td>
                            <td><?= lap_h(lap_tanggal($row['expired_date'] ?? null, false)) ?></td>
                            <td><?= lap_h($row['catatan'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="right">TOTAL</th>
                        <th class="right"><?= lap_angka($totalSistem) ?></th>
                        <th class="right"><?= lap_angka($totalFisik) ?></th>
                        <th class="right"><?= ($totalSelisih > 0 ? '+' : '') . lap_angka($totalSelisih) ?></th>
                        <th class="right"><?= lap_rupiah($nilaiSelisih) ?></th>
                        <th colspan="2">Expired: <?= lap_angka($totalExpired) ?> produk</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="notes"><strong>Catatan Opname:</strong><br><?= nl2br(lap_h($header['catatan'] ?: '-')) ?></div>
        <div class="signatures">
            <div>
                <div>Petugas Stok Opname</div>
                <div class="signature-space"></div>
                <div class="line"><?= lap_h($header['user_nama'] ?: '________________') ?></div>
            </div>
            <div>
                <div>Pemeriksa / Penanggung Jawab</div>
                <div class="signature-space"></div>
                <div class="line">________________________</div>
            </div>
        </div>
    </main>
    <?php if ($autoPrint): ?><script>
            window.addEventListener('load', function() {
                window.print();
            });
        </script><?php endif; ?>
</body>

</html>