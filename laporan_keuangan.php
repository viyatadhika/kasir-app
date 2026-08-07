<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/** @var PDO $pdo */
global $pdo;

if (!function_exists('requireAccess')) {
    function requireAccess(): void {}
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
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_lk')) {
    /** @param mixed $n */
    function rupiah_lk($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('angka_lk')) {
    /** @param mixed $n */
    function angka_lk($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('tanggal_lk')) {
    /** @param mixed $v */
    function tanggal_lk($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}

if (!function_exists('periode_filter_lk')) {
    /**
     * @return array{awal:string,akhir:string,where:string,params:array<string,string>}
     */
    function periode_filter_lk(): array
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
    function saldo_normal_lk(string $kategori, float $debit, float $kredit): float
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
    function ambil_saldo_coa_lk(PDO $pdo, string $where = '1=1', array $params = []): array
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
    function table_columns_lk(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
            $cache[$table] = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e) {
            $cache[$table] = [];
        }

        return $cache[$table];
    }
}


if (!function_exists('table_exists_lk')) {
    function table_exists_lk(PDO $pdo, string $table): bool
    {
        return table_columns_lk($pdo, $table) !== [];
    }
}

if (!function_exists('first_column_lk')) {
    /** @param array<int,string> $candidates */
    function first_column_lk(array $columns, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('ringkasan_unit_usaha_lk')) {
    /**
     * Ringkasan ini bersifat operasional dan tidak mengganti pencatatan jurnal.
     * Nilai laporan resmi tetap berasal dari jurnal_umum dan jurnal_detail.
     *
     * @return array<string,mixed>
     */
    function ringkasan_unit_usaha_lk(PDO $pdo, string $awal, string $akhir): array
    {
        $result = [
            'pos_toko' => 0.0,
            'cafe' => 0.0,
            'air_tagihan' => 0.0,
            'air_lunas' => 0.0,
            'air_piutang' => 0.0,
            'air_batal' => 0.0,
            'air_estimasi_vendor' => 0.0,
            'air_biaya_vendor' => 0.0,
            'air_margin_operasional' => 0.0,
            'cafe_terdeteksi' => false,
            'sumber_cafe' => '',
            'rincian_pos' => [],
        ];

        /*
         * POS dan Cafe.
         * Jika tabel transaksi mempunyai kolom unit/modul/outlet, transaksi Cafe
         * dipisahkan otomatis. Jika tidak, seluruh transaksi dianggap POS Toko.
         */
        $transactionColumns = table_columns_lk($pdo, 'transaksi');
        if ($transactionColumns && in_array('created_at', $transactionColumns, true) && in_array('total', $transactionColumns, true)) {
            $sourceCandidates = [
                'sumber_transaksi',
                'unit_usaha',
                'unit',
                'modul',
                'sumber',
                'source',
                'outlet',
                'channel',
                'jenis_transaksi',
                'tipe_transaksi',
                'kategori'
            ];
            $sourceColumn = first_column_lk($transactionColumns, $sourceCandidates);
            $statusColumn = first_column_lk($transactionColumns, ['status_transaksi', 'status']);
            $whereStatusTransaksi = $statusColumn !== ''
                ? " AND LOWER(COALESCE(`{$statusColumn}`,'')) NOT IN ('batal','cancel','cancelled','void')"
                : '';

            if ($sourceColumn !== '') {
                $sql = "
                    SELECT
                        LOWER(TRIM(CAST(`{$sourceColumn}` AS CHAR))) AS sumber,
                        COALESCE(SUM(total), 0) AS jumlah
                    FROM transaksi
                    WHERE DATE(created_at) BETWEEN :awal AND :akhir
                    {$whereStatusTransaksi}
                    GROUP BY LOWER(TRIM(CAST(`{$sourceColumn}` AS CHAR)))
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $source = strtolower(trim((string)($row['sumber'] ?? '')));
                    $amount = (float)($row['jumlah'] ?? 0);
                    $result['rincian_pos'][$source !== '' ? $source : 'tanpa_kategori'] = $amount;

                    if (strpos($source, 'cafe') !== false || strpos($source, 'kafe') !== false) {
                        $result['cafe'] += $amount;
                        $result['cafe_terdeteksi'] = true;
                        $result['sumber_cafe'] = 'transaksi.' . $sourceColumn;
                    } else {
                        $result['pos_toko'] += $amount;
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(total), 0)
                    FROM transaksi
                    WHERE DATE(created_at) BETWEEN :awal AND :akhir
                    {$whereStatusTransaksi}
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $result['pos_toko'] = (float)$stmt->fetchColumn();
            }
        }

        // Fallback untuk instalasi yang menyimpan transaksi Cafe pada tabel tersendiri.
        if (!$result['cafe_terdeteksi']) {
            foreach (['transaksi_cafe', 'cafe_transaksi', 'pos_cafe_transaksi'] as $table) {
                $cols = table_columns_lk($pdo, $table);
                if (!$cols) {
                    continue;
                }

                $dateColumn = first_column_lk($cols, ['created_at', 'tanggal', 'tanggal_transaksi', 'waktu']);
                $totalColumn = first_column_lk($cols, ['total', 'grand_total', 'total_bayar', 'total_transaksi']);

                if ($dateColumn === '' || $totalColumn === '') {
                    continue;
                }

                $dateExpr = $dateColumn === 'tanggal' ? "`{$dateColumn}`" : "DATE(`{$dateColumn}`)";
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(`{$totalColumn}`), 0)
                    FROM `{$table}`
                    WHERE {$dateExpr} BETWEEN :awal AND :akhir
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $result['cafe'] = (float)$stmt->fetchColumn();
                $result['cafe_terdeteksi'] = true;
                $result['sumber_cafe'] = $table;
                break;
            }
        }

        // Air Mineral berdasarkan kwitansi penagihan.
        $kwitansiColumns = table_columns_lk($pdo, 'air_kwitansi');
        if ($kwitansiColumns && in_array('total_tagihan', $kwitansiColumns, true)) {
            $dateColumn = first_column_lk($kwitansiColumns, ['tanggal_kwitansi', 'created_at', 'tanggal']);
            $statusColumn = first_column_lk($kwitansiColumns, ['status_pembayaran', 'status']);

            if ($dateColumn !== '') {
                $dateExpr = $dateColumn === 'created_at' ? "DATE(`{$dateColumn}`)" : "`{$dateColumn}`";
                $statusExpr = $statusColumn !== ''
                    ? "LOWER(REPLACE(TRIM(CAST(`{$statusColumn}` AS CHAR)), ' ', '_'))"
                    : "'belum_bayar'";

                $stmt = $pdo->prepare("
                    SELECT
                        {$statusExpr} AS status_bayar,
                        COALESCE(SUM(total_tagihan), 0) AS jumlah
                    FROM air_kwitansi
                    WHERE {$dateExpr} BETWEEN :awal AND :akhir
                    GROUP BY {$statusExpr}
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $status = strtolower(trim((string)($row['status_bayar'] ?? 'belum_bayar')));
                    $amount = (float)($row['jumlah'] ?? 0);

                    if ($status === 'batal' || $status === 'dibatalkan') {
                        $result['air_batal'] += $amount;
                        continue;
                    }

                    $result['air_tagihan'] += $amount;
                    if (in_array($status, ['lunas', 'dibayar', 'paid', 'selesai'], true)) {
                        $result['air_lunas'] += $amount;
                    } else {
                        $result['air_piutang'] += $amount;
                    }
                }
            }
        }

        /*
         * Biaya vendor Air Mineral.
         * - estimasi_vendor memakai jumlah_dipesan x harga_vendor;
         * - biaya_vendor memakai jumlah_diterima x harga_vendor sehingga lebih dekat
         *   ke biaya barang yang benar-benar diterima pada periode tersebut.
         * Ringkasan ini tetap bersifat operasional; laporan resmi tetap mengikuti jurnal.
         */
        $vendorOrderCols = table_columns_lk($pdo, 'air_vendor_order');
        $vendorDetailCols = table_columns_lk($pdo, 'air_vendor_order_detail');
        $airProductCols = table_columns_lk($pdo, 'air_produk');

        if (
            $vendorOrderCols
            && $vendorDetailCols
            && $airProductCols
            && in_array('id', $vendorOrderCols, true)
            && in_array('vendor_order_id', $vendorDetailCols, true)
            && in_array('produk_id', $vendorDetailCols, true)
            && in_array('harga_vendor', $airProductCols, true)
        ) {
            $vendorDateColumn = first_column_lk($vendorOrderCols, ['tanggal_kebutuhan', 'tanggal_rekap', 'created_at']);
            $vendorStatusColumn = first_column_lk($vendorOrderCols, ['status']);
            $orderedColumn = first_column_lk($vendorDetailCols, ['jumlah_dipesan', 'jumlah_diminta']);
            $receivedColumn = first_column_lk($vendorDetailCols, ['jumlah_diterima', 'jumlah_dikonfirmasi', 'jumlah_dipesan']);

            if ($vendorDateColumn !== '' && $orderedColumn !== '' && $receivedColumn !== '') {
                $vendorDateExpr = $vendorDateColumn === 'created_at'
                    ? "DATE(vo.`{$vendorDateColumn}`)"
                    : "vo.`{$vendorDateColumn}`";
                $vendorStatusWhere = $vendorStatusColumn !== ''
                    ? " AND LOWER(COALESCE(vo.`{$vendorStatusColumn}`,'')) <> 'batal'"
                    : '';

                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(COALESCE(vd.`{$orderedColumn}`,0) * COALESCE(ap.harga_vendor,0)),0) AS estimasi_vendor,
                        COALESCE(SUM(COALESCE(vd.`{$receivedColumn}`,0) * COALESCE(ap.harga_vendor,0)),0) AS biaya_vendor
                    FROM air_vendor_order_detail vd
                    JOIN air_vendor_order vo ON vo.id = vd.vendor_order_id
                    LEFT JOIN air_produk ap ON ap.id = vd.produk_id
                    WHERE {$vendorDateExpr} BETWEEN :awal AND :akhir
                    {$vendorStatusWhere}
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $vendorRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $result['air_estimasi_vendor'] = (float)($vendorRow['estimasi_vendor'] ?? 0);
                $result['air_biaya_vendor'] = (float)($vendorRow['biaya_vendor'] ?? 0);
            }
        }

        $result['air_margin_operasional'] =
            (float)$result['air_tagihan'] - (float)$result['air_biaya_vendor'];

        return $result;
    }
}

if (!function_exists('rekonsiliasi_kas_bank_lk')) {
    /**
     * Menghitung penerimaan POS langsung dari tabel transaksi agar pembayaran
     * QRIS/non-tunai tidak salah masuk ke Kas. Jurnal POS lama dikeluarkan dari
     * perhitungan akun 101/102, lalu dibangun ulang berdasarkan metode bayar.
     *
     * @return array{kas:float,bank:float,rincian:array<string,float>}
     */
    function rekonsiliasi_kas_bank_lk(PDO $pdo, string $awal, string $akhir): array
    {
        $result = [
            'kas' => 0.0,
            'bank' => 0.0,
            'rincian' => [],
        ];

        $cols = table_columns_lk($pdo, 'transaksi');
        if (!$cols || !in_array('created_at', $cols, true) || !in_array('total', $cols, true)) {
            return $result;
        }

        $methodCandidates = ['metode_pembayaran', 'payment_method', 'metode', 'jenis_pembayaran', 'tipe_pembayaran'];
        $methodParts = [];
        foreach ($methodCandidates as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $methodParts[] = "NULLIF(TRIM(CAST(t.`{$candidate}` AS CHAR)), '')";
            }
        }

        $methodExpr = $methodParts
            ? 'COALESCE(' . implode(', ', $methodParts) . ", 'tunai')"
            : "'tunai'";

        $statusColumn = first_column_lk($cols, ['status_transaksi', 'status']);
        $whereStatus = $statusColumn !== ''
            ? " AND LOWER(COALESCE(t.`{$statusColumn}`,'')) NOT IN ('batal','cancel','cancelled','void')"
            : '';

        $whereKasSession = '';
        if (
            table_exists_lk($pdo, 'kas_harian')
            && in_array('user_id', $cols, true)
            && in_array('created_at', $cols, true)
        ) {
            $kasCols = table_columns_lk($pdo, 'kas_harian');
            if (
                in_array('user_id', $kasCols, true)
                && in_array('opened_at', $kasCols, true)
                && in_array('closed_at', $kasCols, true)
            ) {
                $whereKasSession = " AND EXISTS (
                    SELECT 1
                    FROM kas_harian kh
                    WHERE kh.user_id = t.user_id
                      AND t.created_at >= kh.opened_at
                      AND t.created_at <= COALESCE(kh.closed_at, NOW())
                )";
            }
        }

        $sql = "
            SELECT
                LOWER(REPLACE(REPLACE(REPLACE({$methodExpr}, '-', '_'), ' ', '_'), '/', '_')) AS metode,
                COALESCE(SUM(total), 0) AS jumlah
            FROM transaksi t
            WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
            {$whereStatus}
            {$whereKasSession}
            GROUP BY LOWER(REPLACE(REPLACE(REPLACE({$methodExpr}, '-', '_'), ' ', '_'), '/', '_'))
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);

        $cashMethods = ['tunai', 'cash', 'uang_tunai'];
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
                // QRIS, transfer, debit, kredit, EDC, dan non_tunai masuk Bank.
                $result['bank'] += $amount;
            }
        }

        return $result;
    }
}

if (!function_exists('saldo_non_pos_akun_lk')) {
    /** @return array{debit:float,kredit:float} */
    function saldo_non_pos_akun_lk(PDO $pdo, string $kodeAkun, string $awal, string $akhir): array
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
        $stmt->execute([':kode' => $kodeAkun, ':awal' => $awal, ':akhir' => $akhir]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'debit' => (float)($row['debit'] ?? 0),
            'kredit' => (float)($row['kredit'] ?? 0),
        ];
    }
}

if (!function_exists('terapkan_rekonsiliasi_kas_bank_lk')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    function terapkan_rekonsiliasi_kas_bank_lk(PDO $pdo, array $rows, string $awal, string $akhir, array $pos): array
    {
        foreach ($rows as &$row) {
            $kode = trim((string)($row['kode'] ?? ''));
            if (!in_array($kode, ['101', '102'], true)) {
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
                ? 'Transaksi tunai + jurnal non-POS'
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
    function sum_kategori_lk(array $rows, string $kategori): float
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


$activeMenu = 'laporan_keuangan';
$pageTitle = 'Laporan Keuangan';

$periode = periode_filter_lk();
$awal = $periode['awal'];
$akhir = $periode['akhir'];

$rows = [];
$recentJurnal = [];
$rekonsiliasiPembayaran = ['kas' => 0.0, 'bank' => 0.0, 'rincian' => []];
$ringkasanUnitUsaha = [
    'pos_toko' => 0.0,
    'cafe' => 0.0,
    'air_tagihan' => 0.0,
    'air_lunas' => 0.0,
    'air_piutang' => 0.0,
    'air_batal' => 0.0,
    'air_estimasi_vendor' => 0.0,
    'air_biaya_vendor' => 0.0,
    'air_margin_operasional' => 0.0,
    'cafe_terdeteksi' => false,
    'sumber_cafe' => '',
    'rincian_pos' => [],
];

try {
    $rows = ambil_saldo_coa_lk($pdo, $periode['where'], $periode['params']);

    // Rekonsiliasi akun Kas (101) dan Bank (102) langsung dari metode pembayaran POS.
    // Dengan ini QRIS/non-tunai tidak lagi ikut terhitung sebagai Kas.
    $rekonsiliasiPembayaran = rekonsiliasi_kas_bank_lk($pdo, $awal, $akhir);
    $rows = terapkan_rekonsiliasi_kas_bank_lk(
        $pdo,
        $rows,
        $awal,
        $akhir,
        $rekonsiliasiPembayaran
    );

    // Ringkasan unit usaha Cafe dan Air Mineral untuk monitoring manajemen.
    $ringkasanUnitUsaha = ringkasan_unit_usaha_lk($pdo, $awal, $akhir);

    $stmtRecent = $pdo->prepare("
        SELECT
            ju.id,
            ju.tanggal,
            ju.kode_jurnal,
            ju.keterangan,
            ju.ref_tabel,
            ju.ref_id,
            COALESCE(SUM(jd.debit),0) AS debit,
            COALESCE(SUM(jd.kredit),0) AS kredit
        FROM jurnal_umum ju
        LEFT JOIN jurnal_detail jd ON jd.jurnal_id = ju.id
        WHERE ju.tanggal BETWEEN :awal AND :akhir
        GROUP BY ju.id, ju.tanggal, ju.kode_jurnal, ju.keterangan, ju.ref_tabel, ju.ref_id
        ORDER BY ju.tanggal DESC, ju.id DESC
        LIMIT 30
    ");
    $stmtRecent->execute($periode['params']);
    $recentJurnal = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Gagal memuat laporan: ' . $e->getMessage();
}

$totalAktiva = sum_kategori_lk($rows, 'aktiva');
$totalKewajiban = sum_kategori_lk($rows, 'kewajiban');
$totalModal = sum_kategori_lk($rows, 'modal');
$totalPendapatan = sum_kategori_lk($rows, 'pendapatan');
$totalBeban = sum_kategori_lk($rows, 'beban');
$shu = $totalPendapatan - $totalBeban;

if (function_exists('catat_view_once')) {
    catat_view_once($pdo, 'Laporan Keuangan', 'Membuka halaman Laporan Keuangan');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan — Koperasi BSDK</title>
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

        /* Jurnal terbaru scroll clean */
        .jurnal-scroll {
            max-height: 520px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #d1d5db transparent;
        }

        .jurnal-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .jurnal-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .jurnal-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 20px;
        }

        .jurnal-scroll::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        @media (max-width: 768px) {
            .jurnal-scroll {
                max-height: 420px;
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
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight">Laporan Keuangan</h1>
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

            <?php if (!empty($error ?? '')): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <a href="neraca.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Aktiva</p>
                    <p class="text-xl font-black text-blue-600"><?= rupiah_lk($totalAktiva) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Kas, bank, piutang</p>
                </a>

                <a href="neraca.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Kewajiban</p>
                    <p class="text-xl font-black text-amber-600"><?= rupiah_lk($totalKewajiban) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Simpanan/hutang</p>
                </a>

                <a href="neraca.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Modal</p>
                    <p class="text-xl font-black"><?= rupiah_lk($totalModal) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Modal koperasi</p>
                </a>

                <a href="laba_rugi.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pendapatan</p>
                    <p class="text-xl font-black text-green-600"><?= rupiah_lk($totalPendapatan) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">POS/Toko, Cafe, Air Mineral, rental, bunga</p>
                </a>

                <a href="laba_rugi.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Beban</p>
                    <p class="text-xl font-black text-red-600"><?= rupiah_lk($totalBeban) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Operasional</p>
                </a>

                <a href="laba_rugi.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="bg-white border border-subtle p-4 md:p-5 hover:border-black transition-all">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">SHU</p>
                    <p class="text-xl font-black <?= $shu >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= rupiah_lk($shu) ?></p>
                    <p class="text-[10px] text-gray-400 mt-1">Pendapatan - beban</p>
                </a>
            </div>

            <section class="bg-white border border-subtle p-4 md:p-5 print-card">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-4">
                    <div>
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Ringkasan Unit Usaha</h2>
                        <p class="text-xs text-gray-400 mt-1">
                            Monitoring operasional Cafe dan Air Mineral. Nilai laporan keuangan resmi tetap mengikuti jurnal umum.
                        </p>
                    </div>
                    <span class="inline-flex self-start border border-blue-100 bg-blue-50 px-3 py-2 text-[9px] font-black uppercase tracking-widest text-blue-700">
                        Periode <?= h(tanggal_lk($awal)) ?> — <?= h(tanggal_lk($akhir)) ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
                    <div class="border border-subtle bg-gray-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Penjualan POS/Toko</p>
                        <p class="text-lg font-black mt-2"><?= rupiah_lk($ringkasanUnitUsaha['pos_toko'] ?? 0) ?></p>
                        <p class="text-[9px] text-gray-400 mt-1">Transaksi selain Cafe</p>
                    </div>

                    <div class="border border-amber-100 bg-amber-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Penjualan Cafe</p>
                        <p class="text-lg font-black text-amber-700 mt-2"><?= rupiah_lk($ringkasanUnitUsaha['cafe'] ?? 0) ?></p>
                        <p class="text-[9px] text-amber-600 mt-1">
                            <?= !empty($ringkasanUnitUsaha['cafe_terdeteksi']) ? 'Sumber: ' . h($ringkasanUnitUsaha['sumber_cafe']) : 'Belum ada sumber Cafe terdeteksi' ?>
                        </p>
                    </div>

                    <div class="border border-cyan-100 bg-cyan-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-cyan-700">Tagihan Air Mineral</p>
                        <p class="text-lg font-black text-cyan-800 mt-2"><?= rupiah_lk($ringkasanUnitUsaha['air_tagihan'] ?? 0) ?></p>
                        <p class="text-[9px] text-cyan-600 mt-1">Kwitansi selain batal</p>
                    </div>

                    <div class="border border-green-100 bg-green-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-green-700">Air Mineral Lunas</p>
                        <p class="text-lg font-black text-green-700 mt-2"><?= rupiah_lk($ringkasanUnitUsaha['air_lunas'] ?? 0) ?></p>
                        <p class="text-[9px] text-green-600 mt-1">Pembayaran diterima</p>
                    </div>

                    <div class="border border-amber-100 bg-white p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Piutang Air Mineral</p>
                        <p class="text-lg font-black text-amber-700 mt-2"><?= rupiah_lk($ringkasanUnitUsaha['air_piutang'] ?? 0) ?></p>
                        <p class="text-[9px] text-gray-400 mt-1">Tagihan belum lunas</p>
                    </div>

                    <div class="border border-red-100 bg-red-50 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-red-600">Biaya Vendor Air</p>
                        <p class="text-lg font-black text-red-700 mt-2"><?= rupiah_lk($ringkasanUnitUsaha['air_biaya_vendor'] ?? 0) ?></p>
                        <p class="text-[9px] text-red-500 mt-1">Barang diterima × harga vendor</p>
                    </div>

                    <div class="border <?= ($ringkasanUnitUsaha['air_margin_operasional'] ?? 0) >= 0 ? 'border-green-100 bg-green-50' : 'border-red-200 bg-red-50' ?> p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest <?= ($ringkasanUnitUsaha['air_margin_operasional'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>">Margin Air Mineral</p>
                        <p class="text-lg font-black mt-2 <?= ($ringkasanUnitUsaha['air_margin_operasional'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' ?>">
                            <?= rupiah_lk($ringkasanUnitUsaha['air_margin_operasional'] ?? 0) ?>
                        </p>
                        <p class="text-[9px] text-gray-500 mt-1">Tagihan − biaya vendor diterima</p>
                    </div>
                </div>

                <?php if ((float)($ringkasanUnitUsaha['air_estimasi_vendor'] ?? 0) > 0): ?>
                    <div class="mt-4 border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-700">
                        Estimasi nilai pesanan ke vendor pada periode ini:
                        <strong><?= rupiah_lk($ringkasanUnitUsaha['air_estimasi_vendor'] ?? 0) ?></strong>.
                        Biaya vendor pada kartu di atas memakai jumlah yang benar-benar diterima, bukan sekadar jumlah yang dipesan.
                    </div>
                <?php endif; ?>

                <?php if (empty($ringkasanUnitUsaha['cafe_terdeteksi'])): ?>
                    <div class="mt-4 border border-amber-100 bg-amber-50 px-4 py-3 text-xs text-amber-700">
                        Sistem belum menemukan kolom penanda unit Cafe pada tabel transaksi maupun tabel transaksi Cafe terpisah.
                        Nilai Cafe akan tampil setelah transaksi Cafe menyimpan sumber/unit usaha yang dapat dibedakan.
                    </div>
                <?php endif; ?>
            </section>

            <section class="bg-white border border-subtle p-4 md:p-5">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Rekonsiliasi Pembayaran POS & Cafe</h2>
                        <p class="text-xs text-gray-400 mt-1">Kas berasal dari transaksi tunai POS/Cafe. Bank berasal dari QRIS, transfer, debit, kredit, EDC, dan metode non-tunai lainnya. Transaksi batal/void dikeluarkan dan, bila tabel kas_harian tersedia, hanya transaksi dalam sesi kas yang dihitung. Air Mineral yang belum lunas tetap menjadi piutang operasional sampai dijurnal.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 min-w-full lg:min-w-[420px]">
                        <div class="border border-subtle bg-gray-50 p-3">
                            <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Kas POS/Cafe (Tunai)</p>
                            <p class="text-lg font-black mt-1"><?= rupiah_lk($rekonsiliasiPembayaran['kas'] ?? 0) ?></p>
                        </div>
                        <div class="border border-blue-100 bg-blue-50 p-3">
                            <p class="text-[9px] font-black uppercase tracking-widest text-blue-500">Bank POS/Cafe (Non-Tunai)</p>
                            <p class="text-lg font-black text-blue-700 mt-1"><?= rupiah_lk($rekonsiliasiPembayaran['bank'] ?? 0) ?></p>
                        </div>
                    </div>
                </div>
                <?php if (!empty($rekonsiliasiPembayaran['rincian'])): ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php foreach ($rekonsiliasiPembayaran['rincian'] as $metode => $nilai): ?>
                            <span class="inline-flex items-center gap-2 border border-subtle bg-white px-3 py-2 text-[10px] font-bold uppercase tracking-wide">
                                <?= h(str_replace('_', ' ', $metode)) ?>
                                <strong><?= rupiah_lk($nilai) ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
                <section class="xl:col-span-2 bg-white border border-subtle overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle flex items-center justify-between">
                        <div>
                            <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Saldo Akun</h2>
                            <p class="text-xs text-gray-400 mt-1">Ringkasan saldo berdasarkan jurnal detail</p>
                        </div>
                        <div class="no-print flex gap-2">
                            <a href="neraca.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="px-3 py-2 border border-subtle text-[10px] font-black uppercase tracking-widest">Neraca</a>
                            <a href="laba_rugi.php?awal=<?= h($awal) ?>&akhir=<?= h($akhir) ?>" class="px-3 py-2 bg-black text-white text-[10px] font-black uppercase tracking-widest">Laba Rugi</a>
                        </div>
                    </div>
                    <div class="overflow-x-auto no-scrollbar">
                        <table class="w-full text-left" style="min-width:760px">
                            <thead class="bg-gray-50 border-b border-subtle">
                                <tr>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Akun</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kategori</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Debit</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Kredit</th>
                                    <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f5f5f5]">
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="px-5 py-3 text-xs font-mono text-gray-500"><?= h($r['kode']) ?></td>
                                        <td class="px-5 py-3">
                                            <div class="text-sm font-bold"><?= h($r['nama']) ?></div>
                                            <div class="text-[10px] text-gray-400"><?= h($r['subkategori'] ?: '-') ?></div>
                                            <?php if (!empty($r['sumber_saldo'])): ?>
                                                <div class="text-[9px] text-blue-600 font-bold mt-1"><?= h($r['sumber_saldo']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-xs font-bold uppercase text-gray-500"><?= h($r['kategori']) ?></td>
                                        <td class="px-5 py-3 text-right text-xs"><?= rupiah_lk($r['total_debit']) ?></td>
                                        <td class="px-5 py-3 text-right text-xs"><?= rupiah_lk($r['total_kredit']) ?></td>
                                        <td class="px-5 py-3 text-right text-sm font-black"><?= rupiah_lk($r['saldo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="bg-white border border-subtle overflow-hidden">
                    <div class="px-5 py-4 border-b border-subtle">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Jurnal Terbaru</h2>
                        <p class="text-xs text-gray-400 mt-1">30 transaksi jurnal terakhir</p>
                    </div>
                    <div class="divide-y divide-[#f5f5f5] jurnal-scroll">
                        <?php if (!$recentJurnal): ?>
                            <div class="p-8 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada jurnal</div>
                        <?php endif; ?>
                        <?php foreach ($recentJurnal as $j): ?>
                            <div class="p-4">
                                <div class="flex justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-bold"><?= h($j['kode_jurnal']) ?></p>
                                        <p class="text-[10px] text-gray-400"><?= h(tanggal_lk($j['tanggal'])) ?> · <?= h($j['ref_tabel'] ?: '-') ?> #<?= h($j['ref_id'] ?: '-') ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-black"><?= rupiah_lk($j['debit']) ?></p>
                                        <p class="text-[10px] text-gray-400">Debit/Kredit</p>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2 leading-relaxed"><?= h($j['keterangan']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>

</html>