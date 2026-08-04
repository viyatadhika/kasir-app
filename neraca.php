<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/** @var PDO $pdo */
global $pdo;

if (!function_exists('requireAccess')) {
    function requireAccess() {}
}
requireAccess();

if (file_exists(__DIR__ . '/activity_helper.php')) {
    require_once __DIR__ . '/activity_helper.php';
}

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$userRole = (string)($_SESSION['user']['role'] ?? 'kasir');
if (!in_array($userRole, ['admin', 'ksp'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!function_exists('h')) {
    /** @param mixed $v */
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_lk')) {
    /** @param mixed $n */
    function rupiah_lk($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_lk')) {
    /** @param mixed $n */
    function angka_lk($n)
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_lk')) {
    /** @param mixed $v */
    function tanggal_lk($v)
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('periode_filter_lk')) {
    /**
     * @return array{awal:string,akhir:string,where:string,params:array<string,string>}
     */
    function periode_filter_lk()
    {
        $awal = trim((string)($_GET['awal'] ?? date('Y-m-01')));
        $akhir = trim((string)($_GET['akhir'] ?? date('Y-m-d')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
            $awal = date('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) {
            $akhir = date('Y-m-d');
        }

        return [
            'awal' => $awal,
            'akhir' => $akhir,
            'where' => 'ju.tanggal BETWEEN :awal AND :akhir',
            'params' => [
                ':awal' => $awal,
                ':akhir' => $akhir,
            ],
        ];
    }
}

if (!function_exists('saldo_normal_lk')) {
    function saldo_normal_lk($kategori, $debit, $kredit)
    {
        $kategori = strtolower(trim($kategori));

        if (in_array($kategori, ['aktiva', 'beban'], true)) {
            return $debit - $kredit;
        }

        return $kredit - $debit;
    }
}

if (!function_exists('ambil_saldo_coa_lk')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function ambil_saldo_coa_lk($pdo, $where = '1=1', $params = array())
    {
        $sql = "
            SELECT
                c.id,
                c.kode,
                c.nama,
                c.kategori,
                c.subkategori,
                COALESCE(SUM(jd.debit),0) AS total_debit,
                COALESCE(SUM(jd.kredit),0) AS total_kredit
            FROM coa c
            LEFT JOIN jurnal_detail jd ON jd.coa_id = c.id
            LEFT JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
            WHERE c.is_active = 1
              AND ($where OR ju.id IS NULL)
            GROUP BY c.id, c.kode, c.nama, c.kategori, c.subkategori
            ORDER BY c.kode ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['total_debit'] = (float)($row['total_debit'] ?? 0);
            $row['total_kredit'] = (float)($row['total_kredit'] ?? 0);
            $row['saldo'] = saldo_normal_lk(
                (string)($row['kategori'] ?? ''),
                (float)$row['total_debit'],
                (float)$row['total_kredit']
            );
        }

        return $rows;
    }
}



if (!function_exists('table_columns_lk')) {
    /** @return array<int,string> */
    function table_columns_lk($pdo, $table)
    {
        static $cache = array();
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $safeTable = str_replace('`', '', (string)$table);
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}`");
            $cache[$table] = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : array();
        } catch (Throwable $e) {
            $cache[$table] = array();
        }

        return $cache[$table];
    }
}

if (!function_exists('rekonsiliasi_kas_bank_lk')) {
    /**
     * Hitung penerimaan POS berdasarkan metode pembayaran.
     * Tunai masuk akun 101 Kas, sedangkan QRIS/transfer/EDC/non-tunai masuk 102 Bank.
     *
     * @return array{kas:float,bank:float,rincian:array<string,float>}
     */
    function rekonsiliasi_kas_bank_lk($pdo, $awal, $akhir)
    {
        $result = array('kas' => 0.0, 'bank' => 0.0, 'rincian' => array());
        $cols = table_columns_lk($pdo, 'transaksi');

        if (!$cols || !in_array('created_at', $cols, true) || !in_array('total', $cols, true)) {
            return $result;
        }

        $methodCandidates = array('metode_pembayaran', 'payment_method', 'metode', 'jenis_pembayaran', 'tipe_pembayaran');
        $methodParts = array();
        foreach ($methodCandidates as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $methodParts[] = "NULLIF(TRIM(CAST(`{$candidate}` AS CHAR)), '')";
            }
        }

        $methodExpr = $methodParts
            ? 'COALESCE(' . implode(', ', $methodParts) . ", 'tunai')"
            : "'tunai'";

        $sql = "
            SELECT
                LOWER(REPLACE(REPLACE(REPLACE({$methodExpr}, '-', '_'), ' ', '_'), '/', '_')) AS metode,
                COALESCE(SUM(total), 0) AS jumlah
            FROM transaksi
            WHERE DATE(created_at) BETWEEN :awal AND :akhir
            GROUP BY LOWER(REPLACE(REPLACE(REPLACE({$methodExpr}, '-', '_'), ' ', '_'), '/', '_'))
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':awal' => $awal, ':akhir' => $akhir));

        $cashMethods = array('tunai', 'cash', 'uang_tunai');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $method = strtolower(trim((string)($row['metode'] ?? 'tunai')));
            if ($method === '') {
                $method = 'tunai';
            }

            $amount = (float)($row['jumlah'] ?? 0);
            $result['rincian'][$method] = ($result['rincian'][$method] ?? 0) + $amount;

            if (in_array($method, $cashMethods, true)) {
                $result['kas'] += $amount;
            } else {
                $result['bank'] += $amount;
            }
        }

        return $result;
    }
}

if (!function_exists('saldo_non_pos_akun_lk')) {
    /** @return array{debit:float,kredit:float} */
    function saldo_non_pos_akun_lk($pdo, $kodeAkun, $awal, $akhir)
    {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(jd.debit), 0) AS debit,
                COALESCE(SUM(jd.kredit), 0) AS kredit
            FROM coa c
            LEFT JOIN jurnal_detail jd ON jd.coa_id = c.id
            LEFT JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
            WHERE c.kode = :kode
              AND ju.tanggal BETWEEN :awal AND :akhir
              AND (
                    ju.ref_tabel IS NULL
                    OR TRIM(ju.ref_tabel) = ''
                    OR LOWER(ju.ref_tabel) NOT IN ('transaksi', 'transaksi_pos', 'pos', 'penjualan_pos')
                  )
        ");
        $stmt->execute(array(':kode' => $kodeAkun, ':awal' => $awal, ':akhir' => $akhir));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();

        return array(
            'debit' => (float)($row['debit'] ?? 0),
            'kredit' => (float)($row['kredit'] ?? 0),
        );
    }
}

if (!function_exists('terapkan_rekonsiliasi_kas_bank_lk')) {
    /** @return array<int,array<string,mixed>> */
    function terapkan_rekonsiliasi_kas_bank_lk($pdo, $rows, $awal, $akhir, $pos)
    {
        foreach ($rows as &$row) {
            $kode = trim((string)($row['kode'] ?? ''));
            if (!in_array($kode, array('101', '102'), true)) {
                continue;
            }

            $nonPos = saldo_non_pos_akun_lk($pdo, $kode, $awal, $akhir);
            $penerimaanPos = $kode === '101' ? (float)$pos['kas'] : (float)$pos['bank'];

            $row['total_debit'] = $nonPos['debit'] + $penerimaanPos;
            $row['total_kredit'] = $nonPos['kredit'];
            $row['saldo'] = saldo_normal_lk(
                (string)($row['kategori'] ?? 'aktiva'),
                (float)$row['total_debit'],
                (float)$row['total_kredit']
            );
            $row['rekonsiliasi_pos'] = $penerimaanPos;
            $row['sumber_saldo'] = $kode === '101'
                ? 'Tunai POS + jurnal non-POS'
                : 'QRIS/non-tunai + jurnal non-POS';
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('sum_kategori_lk')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function sum_kategori_lk($rows, $kategori)
    {
        $total = 0;
        foreach ($rows as $row) {
            if (strtolower((string)($row['kategori'] ?? '')) === strtolower($kategori)) {
                $total += (float)($row['saldo'] ?? 0);
            }
        }
        return $total;
    }
}


$activeMenu = 'neraca';
$pageTitle = 'Neraca';

$periode = periode_filter_lk();
$awal = $periode['awal'];
$akhir = $periode['akhir'];

$rows = [];
$error = '';
$rekonsiliasiPembayaran = array('kas' => 0.0, 'bank' => 0.0, 'rincian' => array());

try {
    $rows = ambil_saldo_coa_lk($pdo, $periode['where'], $periode['params']);

    // Samakan saldo akun 101/102 dengan metode pembayaran transaksi POS.
    $rekonsiliasiPembayaran = rekonsiliasi_kas_bank_lk($pdo, $awal, $akhir);
    $rows = terapkan_rekonsiliasi_kas_bank_lk(
        $pdo,
        $rows,
        $awal,
        $akhir,
        $rekonsiliasiPembayaran
    );
} catch (Throwable $e) {
    $error = 'Gagal memuat neraca: ' . $e->getMessage();
}

$aktiva = array_values(array_filter($rows, function ($r) {
    return strtolower((string)$r['kategori']) === 'aktiva';
}));
$kewajiban = array_values(array_filter($rows, function ($r) {
    return strtolower((string)$r['kategori']) === 'kewajiban';
}));
$modal = array_values(array_filter($rows, function ($r) {
    return strtolower((string)$r['kategori']) === 'modal';
}));
$pendapatan = sum_kategori_lk($rows, 'pendapatan');
$beban = sum_kategori_lk($rows, 'beban');
$shuBerjalan = $pendapatan - $beban;

$totalAktiva = sum_kategori_lk($rows, 'aktiva');
$totalKewajiban = sum_kategori_lk($rows, 'kewajiban');
$totalModal = sum_kategori_lk($rows, 'modal');
$totalPasiva = $totalKewajiban + $totalModal + $shuBerjalan;
$selisih = $totalAktiva - $totalPasiva;

if (function_exists('catat_view_once')) {
    catat_view_once($pdo, 'Neraca', 'Membuka halaman Neraca');
}

function render_rows_neraca($items)
{
    foreach ($items as $r): ?>
        <tr>
            <td class="px-5 py-3 text-xs font-mono text-gray-500"><?= h($r['kode']) ?></td>
            <td class="px-5 py-3">
                <div class="text-sm font-bold"><?= h($r['nama']) ?></div>
                <div class="text-[10px] text-gray-400"><?= h(!empty($r['sumber_saldo']) ? $r['sumber_saldo'] : ($r['subkategori'] ?: '-')) ?></div>
            </td>
            <td class="px-5 py-3 text-right text-sm font-black"><?= rupiah_lk($r['saldo']) ?></td>
        </tr>
<?php endforeach;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Neraca — Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111;
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

        input:focus,
        select:focus {
            outline: none;
            border-color: #111 !important;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .05);
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        @media print {

            .no-print,
            .sidebar,
            .app-header,
            nav {
                display: none !important;
            }

            body {
                background: #fff;
                padding: 0 !important;
            }

            .main-wrap,
            .content,
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .print-card {
                border: none !important;
            }
        }

        @media (min-width: 1024px) {
            .sidebar {
                width: 220px;
            }

            .app-header,
            .page-header,
            .main-wrap,
            .content,
            .produk-header,
            .produk-main,
            .diskon-header,
            .diskon-main,
            .stok-header,
            .stok-main-wrap,
            .laporan-header,
            .laporan-main-wrap {
                margin-left: 220px;
            }
        }
    </style>

</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <?php require_once __DIR__ . '/navbar.php'; ?>

    <div class="main-wrap">
        <main class="main-content p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">
            <section class="bg-white border border-subtle p-5 md:p-6 print-card">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Laporan Koperasi</p>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight">Neraca Aktiva & Pasiva</h1>
                        <p class="text-xs text-gray-400 mt-2">Periode <?= h(tanggal_lk($awal)) ?> sampai <?= h(tanggal_lk($akhir)) ?></p>
                    </div>

                    <form method="GET" class="no-print grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto_auto] gap-2 md:w-auto">
                        <input type="date" name="awal" value="<?= h($awal) ?>" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs">
                        <input type="date" name="akhir" value="<?= h($akhir) ?>" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs">
                        <button type="submit" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest">Tampilkan</button>
                        <button type="button" onclick="window.print()" class="px-4 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500">Cetak</button>
                    </form>
                </div>
            </section>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold"><?= h($error) ?></div>
            <?php endif; ?>

            <section class="bg-white border border-subtle p-4 md:p-5 print-card">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Rekonsiliasi Kas & Bank POS</h2>
                        <p class="text-xs text-gray-400 mt-1">Tunai masuk Kas 101, QRIS/transfer/EDC/non-tunai masuk Bank 102.</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="border border-subtle bg-gray-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Kas POS (Tunai)</p>
                        <p class="text-xl font-black mt-1"><?= rupiah_lk($rekonsiliasiPembayaran['kas'] ?? 0) ?></p>
                    </div>
                    <div class="border border-blue-100 bg-blue-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-blue-500">Bank POS (Non-Tunai)</p>
                        <p class="text-xl font-black text-blue-700 mt-1"><?= rupiah_lk($rekonsiliasiPembayaran['bank'] ?? 0) ?></p>
                    </div>
                </div>
                <?php if (!empty($rekonsiliasiPembayaran['rincian'])): ?>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ($rekonsiliasiPembayaran['rincian'] as $metode => $nilai): ?>
                            <span class="px-3 py-2 border border-subtle bg-white text-[10px] font-bold uppercase tracking-wide">
                                <?= h(str_replace('_', ' ', $metode)) ?>: <?= rupiah_lk($nilai) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 md:gap-4">
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Aktiva</p>
                    <p class="text-xl font-black text-blue-600"><?= rupiah_lk($totalAktiva) ?></p>
                </div>
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kewajiban</p>
                    <p class="text-xl font-black text-amber-600"><?= rupiah_lk($totalKewajiban) ?></p>
                </div>
                <div class="bg-white border border-subtle p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Modal + SHU</p>
                    <p class="text-xl font-black"><?= rupiah_lk($totalModal + $shuBerjalan) ?></p>
                </div>
                <div class="bg-white border <?= abs($selisih) < 1 ? 'border-green-200' : 'border-red-200' ?> p-4 md:p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Selisih</p>
                    <p class="text-xl font-black <?= abs($selisih) < 1 ? 'text-green-600' : 'text-red-600' ?>"><?= rupiah_lk($selisih) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?= abs($selisih) < 1 ? 'Balance' : 'Perlu dicek' ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                <section class="bg-white border border-subtle overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Aktiva</h2>
                        <p class="text-xs text-gray-400 mt-1">Kas, bank, piutang, dan aset koperasi</p>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-subtle">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Akun</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php if (!$aktiva): ?><tr>
                                        <td colspan="3" class="py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada aktiva</td>
                                    </tr><?php endif; ?>
                                <?php render_rows_neraca($aktiva); ?>
                            </tbody>
                            <tfoot class="bg-gray-50 border-t border-subtle">
                                <tr>
                                    <td colspan="2" class="px-5 py-4 text-xs font-black uppercase tracking-widest">Total Aktiva</td>
                                    <td class="px-5 py-4 text-right text-base font-black"><?= rupiah_lk($totalAktiva) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="bg-white border border-subtle overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pasiva</h2>
                        <p class="text-xs text-gray-400 mt-1">Kewajiban, modal, dan SHU berjalan</p>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-subtle">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Akun</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <tr class="bg-gray-50">
                                    <td colspan="3" class="px-5 py-2 text-[10px] font-black uppercase tracking-widest text-gray-400">Kewajiban</td>
                                </tr>
                                <?php render_rows_neraca($kewajiban); ?>
                                <tr class="bg-gray-50">
                                    <td colspan="3" class="px-5 py-2 text-[10px] font-black uppercase tracking-widest text-gray-400">Modal</td>
                                </tr>
                                <?php render_rows_neraca($modal); ?>
                                <tr>
                                    <td class="px-5 py-3 text-xs font-mono text-gray-500">399</td>
                                    <td class="px-5 py-3">
                                        <div class="text-sm font-bold">SHU Berjalan</div>
                                        <div class="text-[10px] text-gray-400">Pendapatan - beban periode ini</div>
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm font-black"><?= rupiah_lk($shuBerjalan) ?></td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 border-t border-subtle">
                                <tr>
                                    <td colspan="2" class="px-5 py-4 text-xs font-black uppercase tracking-widest">Total Pasiva</td>
                                    <td class="px-5 py-4 text-right text-base font-black"><?= rupiah_lk($totalPasiva) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>

</html>