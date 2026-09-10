<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'auth.php';
requireAccess();
require_once 'activity_helper.php';

$activeMenu  = 'laporan';
$pageTitle   = 'Laporan Operasional';
$backUrl     = 'dashboard.php';
$isAdmin       = has_role('admin');
$isKasirOnly   = has_role('kasir') && !$isAdmin;
$isCafeOnly    = has_role('cafe') && !$isAdmin;
$isMurniRental = has_role('rental') && !$isAdmin;
$isMurniKsp    = has_role('ksp') && !$isAdmin;
$isAirOnly      = (has_role('air_mineral') || has_role('air')) && !$isAdmin;
$showTabs      = $isAdmin; // admin melihat seluruh modul melalui tab
$isKasir       = has_role('admin', 'kasir');   // admin + kasir toko
$isCafe        = has_role('admin', 'cafe');    // admin + kasir cafe
$isRental      = has_role('admin', 'rental');  // admin + rental
$isKsp         = has_role('admin', 'ksp');     // admin + ksp
$isAirMineral  = has_role('admin', 'air_mineral') || has_role('air'); // admin + petugas air mineral (alias role air didukung)

// ── Helper functions ─────────────────────────────────────────────────────────
if (!function_exists('rupiah')) {
    /** @param mixed $v */
    function rupiah($v): string
    {
        return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
    }
}
if (!function_exists('angka')) {
    /** @param mixed $v */
    function angka($v): string
    {
        return number_format((float)($v ?? 0), 0, ',', '.');
    }
}
if (!function_exists('tgl')) {
    /** @param mixed $v */
    function tgl($v): string
    {
        return $v ? date('d/m/Y', strtotime((string)$v)) : '-';
    }
}
if (!function_exists('waktu')) {
    /** @param mixed $v */
    function waktu($v): string
    {
        return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
    }
}
if (!function_exists('e')) {
    /** @param mixed $v */
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('label_metode_pembayaran')) {
    /** @param mixed $value */
    function label_metode_pembayaran($value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        $map = [
            'cash' => 'Tunai',
            'tunai' => 'Tunai',
            'non_tunai' => 'Non Tunai',
            'non-tunai' => 'Non Tunai',
            'nontunai' => 'Non Tunai',
            'qris' => 'QRIS',
            'qr' => 'QRIS',
            'transfer' => 'Transfer',
            'bank_transfer' => 'Transfer',
            'debit' => 'Debit',
            'kartu_debit' => 'Debit',
            'credit' => 'Kredit',
            'kredit' => 'Kredit',
            'kartu_kredit' => 'Kredit',
            'edc' => 'EDC',
        ];
        return $map[$value] ?? ($value !== '' ? ucwords(str_replace(['_', '-'], ' ', $value)) : 'Tunai');
    }
}

function transaksi_col_exists(PDO $pdo, string $column): bool
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM transaksi")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return in_array($column, $cols, true);
}

function produk_col_exists(PDO $pdo, string $column): bool
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM produk")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return in_array($column, $cols, true);
}


if (!function_exists('laporan_table_exists')) {
    function laporan_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
            ");
            $stmt->execute([':table_name' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('laporan_column_exists')) {
    function laporan_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column,
            ]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

// ── Rentang tanggal ──────────────────────────────────────────────────────────
$today  = date('Y-m-d');
$preset = $_GET['preset'] ?? 'hari_ini';

if ($preset === 'minggu_ini') {
    $awal  = date('Y-m-d', strtotime('monday this week'));
    $akhir = date('Y-m-d', strtotime('sunday this week'));
} elseif ($preset === 'bulan_ini') {
    $awal  = date('Y-m-01');
    $akhir = date('Y-m-t');
} elseif ($preset === 'tahun_ini') {
    $awal  = date('Y-01-01');
    $akhir = date('Y-12-31');
} elseif ($preset === 'custom') {
    $awal  = $_GET['awal']  ?? $today;
    $akhir = $_GET['akhir'] ?? $today;
} else {
    $preset = 'hari_ini';
    $awal   = $today;
    $akhir  = $today;
}

// ── Pagination detail transaksi POS ─────────────────────────────────────────
$trxPage = max(1, (int)($_GET['trx_page'] ?? 1));
$trxAllowedLimits = [10, 15, 25, 50, 100];
$trxPerPage = (int)($_GET['trx_limit'] ?? 15);
if (!in_array($trxPerPage, $trxAllowedLimits, true)) {
    $trxPerPage = 15;
}
$trxOffset = ($trxPage - 1) * $trxPerPage;
$trxTotalPages = 1;

// ── Pagination detail transaksi Cafe ────────────────────────────────────────
$cafePage = max(1, (int)($_GET['cafe_page'] ?? 1));
$cafeAllowedLimits = [10, 15, 25, 50, 100];
$cafePerPage = (int)($_GET['cafe_limit'] ?? 15);
if (!in_array($cafePerPage, $cafeAllowedLimits, true)) {
    $cafePerPage = 15;
}
$cafeOffset = ($cafePage - 1) * $cafePerPage;
$cafeTotalPages = 1;

if (!function_exists('laporan_trx_page_url')) {
    function laporan_trx_page_url(int $page, int $limit): string
    {
        $query = $_GET;
        $query['trx_page'] = max(1, $page);
        $query['trx_limit'] = $limit;
        return '?' . http_build_query($query);
    }
}

if (!function_exists('laporan_cafe_page_url')) {
    function laporan_cafe_page_url(int $page, int $limit): string
    {
        $query = $_GET;
        $query['cafe_page'] = max(1, $page);
        $query['cafe_limit'] = $limit;
        return '?' . http_build_query($query);
    }
}


// ── Pagination detail pesanan Air Mineral ───────────────────────────────────
$airPage = max(1, (int)($_GET['air_page'] ?? 1));
$airAllowedLimits = [10, 15, 25, 50, 100];
$airPerPage = (int)($_GET['air_limit'] ?? 15);
if (!in_array($airPerPage, $airAllowedLimits, true)) {
    $airPerPage = 15;
}
$airOffset = ($airPage - 1) * $airPerPage;
$airTotalRows = 0;
$airTotalPages = 1;

if (!function_exists('laporan_air_page_url')) {
    function laporan_air_page_url(int $page, int $limit): string
    {
        $query = $_GET;
        $query['air_page'] = max(1, $page);
        $query['air_limit'] = $limit;
        return '?' . http_build_query($query);
    }
}

// ── Inisialisasi variabel ────────────────────────────────────────────────────
$summary              = [];
$transaksi            = [];
$produk               = [];
$diskonTransaksi      = [];
$diskonBarang         = [];
$memberPoin           = [];
$totalTransaksi       = 0;
$omzet                = 0;
$diskonTrxSum         = 0;
$diskonBarangTotal    = 0;
$totalDiskon          = 0;
$totalBayar           = 0;
$totalKembalian       = 0;
$totalPoint           = 0;
$totalPointPakai      = 0;
$totalNilaiPointPakai = 0;
$rata                 = 0;

// Cafe
$cafeSummary = [
    'total_transaksi' => 0,
    'omzet' => 0,
    'tunai' => 0,
    'nontunai' => 0,
    'margin' => 0,
    'rata' => 0,
];
$cafeTransaksi = [];
$cafeProduk = [];
$cafeTotalTransaksi = 0;
$cafeOmzet = 0;
$cafeTunai = 0;
$cafeNontunai = 0;
$cafeMargin = 0;
$cafeRata = 0;

// Produk kedaluwarsa
$expiredSummary = [
    'expired' => 0,
    'h7' => 0,
    'h30' => 0,
    'tanpa_tanggal' => 0,
    'stok_expired' => 0,
    'nilai_expired' => 0,
];
$produkExpiredList = [];
$expiredColumnReady = false;

// Rental
$ringkasanRental    = ['total_order' => 0, 'total_pendapatan' => 0, 'total_driver' => 0];
$orderRental        = [];
$totalOrderRental   = 0;
$pendapatanRental   = 0;

// KSP / Simpan Pinjam
$kspSummary = [
    'pengajuan_total' => 0,
    'pengajuan_nilai' => 0,
    'pinjaman_aktif' => 0,
    'pinjaman_pokok' => 0,
    'angsuran_tertagih' => 0,
    'angsuran_dibayar' => 0,
    'angsuran_belum_bayar' => 0,
];
$kspPengajuan = [];
$kspPinjaman = [];
$kspAngsuran = [];


// Air Mineral
$airSummary = [
    'total_pesanan' => 0,
    'status_baru' => 0,
    'status_diproses' => 0,
    'status_siap_dikirim' => 0,
    'status_dalam_pengiriman' => 0,
    'pesanan_selesai' => 0,
    'pesanan_batal' => 0,
    'data_migrasi' => 0,
    'data_baru' => 0,
    'total_lokasi' => 0,
    'total_unit' => 0,
    'rekap_vendor' => 0,
    'unit_dipesan_vendor' => 0,
    'unit_diterima_vendor' => 0,
    'selisih_vendor' => 0,
    'surat_jalan' => 0,
    'total_kwitansi' => 0,
    'nilai_tagihan' => 0,
    'nilai_lunas' => 0,
    'nilai_belum_bayar' => 0,
];
$airPesanan = [];
$airProduk = [];
$airLokasi = [];
$airVendor = [];
$airKwitansi = [];
$airLoadErrors = [];

// ════════════════════════════════════════════════════════════════════════════
// DATA: ADMIN & KASIR — Transaksi POS
// ════════════════════════════════════════════════════════════════════════════
if ($isKasir) {

    $hasPointPakai      = transaksi_col_exists($pdo, 'point_pakai');
    $hasNilaiPointPakai = transaksi_col_exists($pdo, 'nilai_point_pakai');

    /*
     * Beberapa versi POS menyimpan metode pembayaran dengan nama kolom berbeda.
     * Ambil nilai non-tunai yang benar terlebih dahulu, baru fallback ke tunai.
     */
    $metodeCandidates = [];
    foreach (['metode_pembayaran', 'payment_method', 'metode', 'jenis_pembayaran', 'tipe_pembayaran'] as $column) {
        if (transaksi_col_exists($pdo, $column)) {
            $metodeCandidates[] = "NULLIF(TRIM(CAST(t.`{$column}` AS CHAR)), '')";
        }
    }
    if ($metodeCandidates) {
        $nonTunaiCandidates = [];
        foreach ($metodeCandidates as $candidateSql) {
            $nonTunaiCandidates[] = "CASE WHEN LOWER({$candidateSql}) NOT IN ('tunai','cash') THEN {$candidateSql} END";
        }
        $metodeSql = 'COALESCE(' . implode(', ', $nonTunaiCandidates) . ', ' . implode(', ', $metodeCandidates) . ", 'tunai')";
    } else {
        $metodeSql = "'tunai'";
    }

    /*
     * Sinkron dengan Kas Harian:
     * - transaksi wajib berada di dalam sesi buka-tutup kas;
     * - user_id transaksi harus sama dengan operator sesi;
     * - transaksi batal/void tidak dihitung;
     * - payment_status tidak dipakai karena beberapa transaksi valid tersimpan pending.
     */
    $statusColumn = transaksi_col_exists($pdo, 'status_transaksi')
        ? 'status_transaksi'
        : (transaksi_col_exists($pdo, 'status') ? 'status' : '');

    $whereStatus = $statusColumn !== ''
        ? " AND LOWER(COALESCE(t.`{$statusColumn}`,'')) NOT IN ('batal','cancel','cancelled','void')"
        : '';

    // POS toko tidak mencampurkan transaksi cafe.
    $whereSumberPos = transaksi_col_exists($pdo, 'sumber_transaksi')
        ? " AND LOWER(COALESCE(t.sumber_transaksi,'toko')) <> 'cafe'"
        : '';

    $whereSesiKas = " AND EXISTS (
        SELECT 1
        FROM kas_harian kh
        WHERE kh.user_id = t.user_id
          AND kh.tanggal BETWEEN :awal AND :akhir
          AND t.created_at >= kh.opened_at
          AND t.created_at <= COALESCE(kh.closed_at, NOW())
    )";

    try {
        $diskonSum          = "COALESCE(SUM(t.diskon), 0)";
        $pointSum           = "COALESCE(SUM(t.point_dapat), 0)";
        $pointPakaiSum      = $hasPointPakai      ? "COALESCE(SUM(t.point_pakai), 0)"        : "0";
        $nilaiPointPakaiSum = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai), 0)"  : "0";

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(t.total),0) AS omzet,
                   $diskonSum AS diskon_transaksi, COALESCE(SUM(t.bayar),0) AS bayar,
                   COALESCE(SUM(t.kembalian),0) AS kembalian, $pointSum AS point,
                   $pointPakaiSum AS point_pakai, $nilaiPointPakaiSum AS nilai_point_pakai
            FROM transaksi t
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        /*
         * Pagination harus dihitung setelah jumlah transaksi periode diketahui.
         * Ini mencegah tabel kosong saat pengguna sebelumnya berada di halaman
         * tinggi lalu mengganti periode/filter yang jumlah datanya lebih sedikit.
         */
        $totalTransaksi = (int)($summary['total_transaksi'] ?? 0);
        $trxTotalPages  = max(1, (int)ceil($totalTransaksi / $trxPerPage));
        if ($trxPage > $trxTotalPages) {
            $trxPage = $trxTotalPages;
        }
        $trxOffset = ($trxPage - 1) * $trxPerPage;

        // Diskon barang
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total_diskon_barang
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarangTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total_diskon_barang'] ?? 0);

        $pointPakaiSelect = $hasPointPakai      ? "t.point_pakai"        : "0 AS point_pakai";
        $nilaiPPSelect    = $hasNilaiPointPakai ? "t.nilai_point_pakai"  : "0 AS nilai_point_pakai";
        $diskonBarangPerTrx = "(SELECT COALESCE(SUM(GREATEST(0,(COALESCE(td2.harga_normal,td2.harga)*td2.qty)-td2.subtotal)),0) FROM transaksi_detail td2 WHERE td2.transaksi_id=t.id)";

        $stmt = $pdo->prepare("
            SELECT t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
                   t.diskon, ($diskonBarangPerTrx) AS diskon_barang,
                   t.point_dapat, $pointPakaiSelect, $nilaiPPSelect,
                   m.nama AS member_nama, m.kode AS member_kode,
                   {$metodeSql} AS metode_pembayaran,
                   " . (transaksi_col_exists($pdo, 'payment_status') ? "t.payment_status" : "NULL AS payment_status") . "
            FROM transaksi t
            LEFT JOIN member m ON m.id = t.member_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT {$trxPerPage} OFFSET {$trxOffset}
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Produk terlaris
        $stmt = $pdo->prepare("
            SELECT td.produk_id, td.nama, MAX(td.kode) AS kode, SUM(td.qty) AS qty,
                   SUM(COALESCE(td.harga_normal,td.harga)*td.qty) AS subtotal_normal,
                   SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)) AS diskon,
                   SUM(td.subtotal) AS penjualan
            FROM transaksi_detail td JOIN transaksi t ON t.id=td.transaksi_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
            GROUP BY td.produk_id, td.nama ORDER BY qty DESC, penjualan DESC LIMIT 20
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Diskon transaksi
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Transaksi') AS nama, COUNT(t.id) AS jumlah,
                   COALESCE(SUM(t.diskon),0) AS total
            FROM transaksi t LEFT JOIN diskon d ON d.id=t.diskon_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos AND COALESCE(t.diskon,0)>0
            GROUP BY t.diskon_id, d.nama ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Diskon barang
        $stmt = $pdo->prepare("
            SELECT COALESCE(d.nama,'Diskon Barang') AS nama, td.diskon_id,
                   COUNT(td.id) AS jumlah,
                   COALESCE(SUM(GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)),0) AS total
            FROM transaksi_detail td
            JOIN transaksi t ON t.id=td.transaksi_id
            LEFT JOIN diskon d ON d.id=td.diskon_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
              AND GREATEST(0,(COALESCE(td.harga_normal,td.harga)*td.qty)-td.subtotal)>0
            GROUP BY td.diskon_id, d.nama ORDER BY total DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Member poin
        $ppMemberSum = $hasPointPakai ? "COALESCE(SUM(t.point_pakai),0)" : "0";
        $stmt = $pdo->prepare("
            SELECT m.kode, m.nama, m.no_hp, COUNT(t.id) AS jumlah_trx,
                   COALESCE(SUM(t.total),0) AS total_belanja,
                   COALESCE(SUM(t.point_dapat),0) AS point_periode,
                   $ppMemberSum AS point_pakai_periode, m.point AS point_total_lifetime
            FROM transaksi t JOIN member m ON m.id=t.member_id
            WHERE 1=1 $whereSesiKas $whereStatus $whereSumberPos
            GROUP BY t.member_id, m.kode, m.nama, m.no_hp, m.point ORDER BY total_belanja DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $memberPoin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        die('Gagal memuat laporan: ' . htmlspecialchars($e->getMessage()));
    }

    $totalTransaksi       = (int)($summary['total_transaksi']   ?? 0);
    $omzet                = (int)($summary['omzet']             ?? 0);
    $diskonTrxSum         = (int)($summary['diskon_transaksi']  ?? 0);
    $totalDiskon          = $diskonTrxSum + $diskonBarangTotal;
    $totalBayar           = (int)($summary['bayar']             ?? 0);
    $totalKembalian       = (int)($summary['kembalian']         ?? 0);
    $totalPoint           = (int)($summary['point']             ?? 0);
    $totalPointPakai      = (int)($summary['point_pakai']       ?? 0);
    $totalNilaiPointPakai = (int)($summary['nilai_point_pakai'] ?? 0);
    $rata                 = $totalTransaksi > 0 ? (int)floor($omzet / $totalTransaksi) : 0;
    $trxTotalPages        = max(1, (int)ceil($totalTransaksi / $trxPerPage));
}


// ════════════════════════════════════════════════════════════════════════════
// DATA: CAFE — transaksi yang SUDAH LUNAS berdasarkan cafe_pesanan.paid_at
// ════════════════════════════════════════════════════════════════════════════
if ($isCafe) {
    try {
        if (!laporan_table_exists($pdo, 'cafe_pesanan')) {
            throw new RuntimeException('Tabel cafe_pesanan tidak ditemukan.');
        }
        if (!laporan_table_exists($pdo, 'transaksi')) {
            throw new RuntimeException('Tabel transaksi tidak ditemukan.');
        }

        // Cafe berbeda dengan POS toko: pesanan dapat dibuat dahulu dan dibayar
        // kemudian. Karena itu tanggal laporan yang benar adalah paid_at.
        $cafeWhere = "
            LOWER(TRIM(COALESCE(cp.status_pembayaran,''))) = 'lunas'
            AND LOWER(TRIM(COALESCE(cp.status,''))) <> 'batal'
            AND cp.paid_at IS NOT NULL
            AND DATE(cp.paid_at) BETWEEN :awal AND :akhir
        ";

        $cafeStatusColumn = transaksi_col_exists($pdo, 'status_transaksi')
            ? 'status_transaksi'
            : (transaksi_col_exists($pdo, 'status') ? 'status' : '');
        if ($cafeStatusColumn !== '') {
            $cafeWhere .= " AND LOWER(COALESCE(t.`{$cafeStatusColumn}`,'')) NOT IN ('batal','cancel','cancelled','void')";
        }

        // Nilai penjualan aktual mengikuti kas harian Cafe: bayar-kembalian,
        // fallback ke total untuk data lama.
        $hasBayarCafe = transaksi_col_exists($pdo, 'bayar');
        $hasKembalianCafe = transaksi_col_exists($pdo, 'kembalian');
        if ($hasBayarCafe) {
            $changeExpr = $hasKembalianCafe ? 'COALESCE(t.kembalian,0)' : '0';
            $cafeSaleExpr = "CASE WHEN (COALESCE(t.bayar,0) <> 0 OR {$changeExpr} <> 0) THEN GREATEST(COALESCE(t.bayar,0)-{$changeExpr},0) ELSE COALESCE(t.total,0) END";
        } else {
            $cafeSaleExpr = 'COALESCE(t.total,0)';
        }

        $cafeMetodeCandidates = [];
        foreach (['metode_pembayaran', 'payment_method', 'metode', 'jenis_pembayaran', 'tipe_pembayaran'] as $column) {
            if (transaksi_col_exists($pdo, $column)) {
                $cafeMetodeCandidates[] = "NULLIF(TRIM(CAST(t.`{$column}` AS CHAR)), '')";
            }
        }
        $cafeMetodeSql = $cafeMetodeCandidates
            ? 'COALESCE(' . implode(', ', $cafeMetodeCandidates) . ", 'tunai')"
            : "'tunai'";

        $stmtCafe = $pdo->prepare("
            SELECT
                COUNT(DISTINCT t.id) AS total_transaksi,
                COALESCE(SUM({$cafeSaleExpr}),0) AS omzet,
                COALESCE(SUM(CASE WHEN LOWER({$cafeMetodeSql}) IN ('tunai','cash','uang tunai') THEN {$cafeSaleExpr} ELSE 0 END),0) AS tunai,
                COALESCE(SUM(CASE WHEN LOWER({$cafeMetodeSql}) NOT IN ('tunai','cash','uang tunai','') THEN {$cafeSaleExpr} ELSE 0 END),0) AS nontunai
            FROM cafe_pesanan cp
            JOIN transaksi t ON t.id = cp.transaksi_id
            WHERE {$cafeWhere}
        ");
        $stmtCafe->execute([':awal' => $awal, ':akhir' => $akhir]);
        $cafeSummaryRow = $stmtCafe->fetch(PDO::FETCH_ASSOC) ?: [];

        $cafeTotalTransaksi = (int)($cafeSummaryRow['total_transaksi'] ?? 0);
        $cafeOmzet = (float)($cafeSummaryRow['omzet'] ?? 0);
        $cafeTunai = (float)($cafeSummaryRow['tunai'] ?? 0);
        $cafeNontunai = (float)($cafeSummaryRow['nontunai'] ?? 0);
        $cafeRata = $cafeTotalTransaksi > 0 ? ($cafeOmzet / $cafeTotalTransaksi) : 0;

        $cafeTotalPages = max(1, (int)ceil($cafeTotalTransaksi / $cafePerPage));
        if ($cafePage > $cafeTotalPages) $cafePage = $cafeTotalPages;
        $cafeOffset = ($cafePage - 1) * $cafePerPage;

        // Margin hanya transaksi Cafe yang benar-benar lunas pada periode paid_at.
        $hasDetailHargaBeli = false;
        try {
            $detailCols = $pdo->query("SHOW COLUMNS FROM transaksi_detail")->fetchAll(PDO::FETCH_COLUMN);
            $hasDetailHargaBeli = in_array('harga_beli', $detailCols, true);
        } catch (Throwable $e) {
            $detailCols = [];
        }

        $hargaBeliExpr = $hasDetailHargaBeli
            ? 'COALESCE(td.harga_beli,0)'
            : (produk_col_exists($pdo, 'harga_beli') ? 'COALESCE(p.harga_beli,0)' : '0');
        $joinProdukMargin = (!$hasDetailHargaBeli && produk_col_exists($pdo, 'harga_beli'))
            ? ' LEFT JOIN produk p ON p.id = td.produk_id '
            : '';

        $promoExpr = laporan_column_exists($pdo, 'cafe_pesanan', 'promo_diskon') ? 'COALESCE(cp.promo_diskon,0)' : '0';
        $pointExpr = transaksi_col_exists($pdo, 'nilai_point_pakai') ? 'COALESCE(t.nilai_point_pakai,0)' : '0';

        $stmtCafeMargin = $pdo->prepare("
            SELECT COALESCE(SUM(
                COALESCE(td.subtotal, COALESCE(td.harga,0)*COALESCE(td.qty,0))
                - ({$hargaBeliExpr}*COALESCE(td.qty,0))
            ),0)
            - COALESCE(SUM(DISTINCT {$promoExpr}),0)
            - COALESCE(SUM(DISTINCT {$pointExpr}),0)
            FROM cafe_pesanan cp
            JOIN transaksi t ON t.id = cp.transaksi_id
            JOIN transaksi_detail td ON td.transaksi_id = t.id
            {$joinProdukMargin}
            WHERE {$cafeWhere}
        ");
        $stmtCafeMargin->execute([':awal' => $awal, ':akhir' => $akhir]);
        $cafeMargin = (float)$stmtCafeMargin->fetchColumn();

        $cafeSummary = [
            'total_transaksi' => $cafeTotalTransaksi,
            'omzet' => $cafeOmzet,
            'tunai' => $cafeTunai,
            'nontunai' => $cafeNontunai,
            'margin' => $cafeMargin,
            'rata' => $cafeRata,
        ];

        $hasCafeMeja = laporan_table_exists($pdo, 'cafe_meja');
        $joinCafeMeja = $hasCafeMeja ? ' LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id ' : '';
        $selectCafeMeja = $hasCafeMeja ? 'cm.nomor_meja' : 'NULL AS nomor_meja';

        $stmtCafeList = $pdo->prepare("
            SELECT
                t.id, t.invoice,
                cp.paid_at AS created_at,
                {$cafeSaleExpr} AS total,
                t.bayar, t.kembalian,
                {$cafeMetodeSql} AS metode_pembayaran,
                u.nama AS kasir,
                cp.nomor_pesanan, cp.tipe_pesanan, cp.status AS status_pesanan,
                {$selectCafeMeja}
            FROM cafe_pesanan cp
            JOIN transaksi t ON t.id = cp.transaksi_id
            LEFT JOIN users u ON u.id = t.user_id
            {$joinCafeMeja}
            WHERE {$cafeWhere}
            ORDER BY cp.paid_at DESC, t.id DESC
            LIMIT {$cafePerPage} OFFSET {$cafeOffset}
        ");
        $stmtCafeList->execute([':awal' => $awal, ':akhir' => $akhir]);
        $cafeTransaksi = $stmtCafeList->fetchAll(PDO::FETCH_ASSOC);

        $stmtCafeProduk = $pdo->prepare("
            SELECT td.produk_id, td.nama, MAX(td.kode) AS kode,
                   SUM(td.qty) AS qty, SUM(td.subtotal) AS penjualan
            FROM cafe_pesanan cp
            JOIN transaksi t ON t.id = cp.transaksi_id
            JOIN transaksi_detail td ON td.transaksi_id = t.id
            WHERE {$cafeWhere}
            GROUP BY td.produk_id, td.nama
            ORDER BY qty DESC, penjualan DESC
            LIMIT 20
        ");
        $stmtCafeProduk->execute([':awal' => $awal, ':akhir' => $akhir]);
        $cafeProduk = $stmtCafeProduk->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cafeTransaksi = [];
        $cafeProduk = [];
        $cafeSummary = ['total_transaksi' => 0, 'omzet' => 0, 'tunai' => 0, 'nontunai' => 0, 'margin' => 0, 'rata' => 0];
        $cafeTotalTransaksi = 0;
        $cafeTotalPages = 1;
        $cafePage = 1;
        $cafeError = $e->getMessage();
        error_log('LAPORAN CAFE ERROR: ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
// DATA: PRODUK KEDALUWARSA — Admin & Kasir
// ════════════════════════════════════════════════════════════════════════════
if ($isKasir) {
    $expiredColumnReady = produk_col_exists($pdo, 'expired_date');

    if ($expiredColumnReady) {
        try {
            $stmtExpiredSummary = $pdo->query("
                SELECT
                    SUM(CASE WHEN expired_date IS NOT NULL AND expired_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN expired_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS h7,
                    SUM(CASE WHEN expired_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS h30,
                    SUM(CASE WHEN expired_date IS NULL THEN 1 ELSE 0 END) AS tanpa_tanggal,
                    SUM(CASE WHEN expired_date IS NOT NULL AND expired_date < CURDATE() THEN stok ELSE 0 END) AS stok_expired,
                    SUM(CASE WHEN expired_date IS NOT NULL AND expired_date < CURDATE() THEN (stok * harga_beli) ELSE 0 END) AS nilai_expired
                FROM produk
                WHERE status = 'aktif'
            ");
            $rowExpiredSummary = $stmtExpiredSummary->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($expiredSummary as $key => $defaultValue) {
                $expiredSummary[$key] = (float)($rowExpiredSummary[$key] ?? $defaultValue);
            }

            $stmtExpiredList = $pdo->query("
                SELECT id, kode, nama, kategori, stok, satuan, harga_beli, harga_jual, expired_date,
                       DATEDIFF(expired_date, CURDATE()) AS sisa_hari
                FROM produk
                WHERE status = 'aktif'
                  AND expired_date IS NOT NULL
                  AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ORDER BY
                    CASE WHEN expired_date < CURDATE() THEN 0 ELSE 1 END,
                    expired_date ASC,
                    nama ASC
            ");
            $produkExpiredList = $stmtExpiredList->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $produkExpiredList = [];
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// DATA: RENTAL — Data rental_bandara saja
// ════════════════════════════════════════════════════════════════════════════
if ($isRental) {
    try {
        // Ringkasan
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_order,
                   COALESCE(SUM(total_bayar), 0) AS total_pendapatan
            FROM rental_bandara
            WHERE DATE(created_at) BETWEEN :awal AND :akhir
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $ringkasanRental = $stmt->fetch(PDO::FETCH_ASSOC) ?: $ringkasanRental;

        // Detail order
        $stmt = $pdo->prepare("
            SELECT r.*, d.nama AS nama_driver
            FROM rental_bandara r
            LEFT JOIN driver d ON r.driver_id = d.id
            WHERE DATE(r.created_at) BETWEEN :awal AND :akhir
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $orderRental = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Tabel belum ada atau kolom berbeda — tampil kosong, tidak crash
        $orderRental = [];
    }

    $totalOrderRental = (int)($ringkasanRental['total_order']      ?? 0);
    $pendapatanRental = (int)($ringkasanRental['total_pendapatan'] ?? 0);
}


// ════════════════════════════════════════════════════════════════════════════
// DATA: KSP — Pengajuan, pinjaman aktif, dan angsuran
// ════════════════════════════════════════════════════════════════════════════
if ($isKsp) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(jumlah), 0) AS nilai
            FROM pengajuan_pinjaman
            WHERE DATE(created_at) BETWEEN :awal AND :akhir
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kspSummary['pengajuan_total'] = (int)($row['total'] ?? 0);
        $kspSummary['pengajuan_nilai'] = (float)($row['nilai'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode
            FROM pengajuan_pinjaman pp
            LEFT JOIN member m ON m.id = pp.member_id
            WHERE DATE(pp.created_at) BETWEEN :awal AND :akhir
            ORDER BY pp.created_at DESC, pp.id DESC
            LIMIT 100
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $kspPengajuan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kspPengajuan = [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(pokok), 0) AS pokok
            FROM pinjaman
            WHERE status IN ('aktif', 'berjalan')
              AND DATE(created_at) BETWEEN :awal AND :akhir
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kspSummary['pinjaman_aktif'] = (int)($row['total'] ?? 0);
        $kspSummary['pinjaman_pokok'] = (float)($row['pokok'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT p.*, m.nama AS member_nama, m.kode AS member_kode
            FROM pinjaman p
            LEFT JOIN member m ON m.id = p.member_id
            WHERE DATE(p.created_at) BETWEEN :awal AND :akhir
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT 100
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $kspPinjaman = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kspPinjaman = [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(jumlah_total), 0) AS tertagih,
                COALESCE(SUM(CASE WHEN status IN ('dibayar', 'lunas') THEN jumlah_total ELSE 0 END), 0) AS dibayar,
                COALESCE(SUM(CASE WHEN status NOT IN ('dibayar', 'lunas') THEN jumlah_total ELSE 0 END), 0) AS belum_bayar
            FROM pinjaman_angsuran
            WHERE jatuh_tempo BETWEEN :awal AND :akhir
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kspSummary['angsuran_tertagih'] = (float)($row['tertagih'] ?? 0);
        $kspSummary['angsuran_dibayar'] = (float)($row['dibayar'] ?? 0);
        $kspSummary['angsuran_belum_bayar'] = (float)($row['belum_bayar'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT pa.*, m.nama AS member_nama, m.kode AS member_kode
            FROM pinjaman_angsuran pa
            LEFT JOIN member m ON m.id = pa.member_id
            WHERE pa.jatuh_tempo BETWEEN :awal AND :akhir
            ORDER BY pa.jatuh_tempo ASC, pa.id ASC
            LIMIT 100
        ");
        $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
        $kspAngsuran = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kspAngsuran = [];
    }
}



// ════════════════════════════════════════════════════════════════════════════
// DATA: AIR MINERAL — Pesanan, lokasi, vendor, produk, surat jalan, penagihan
// ════════════════════════════════════════════════════════════════════════════
if ($isAirMineral) {
    try {
        if (laporan_table_exists($pdo, 'air_pesanan')) {
            $hasAirHistorical = laporan_column_exists($pdo, 'air_pesanan', 'is_historical');
            $hasAirSource = laporan_column_exists($pdo, 'air_pesanan', 'sumber_data');

            $historicalExpr = $hasAirHistorical
                ? "COALESCE(is_historical,0)=1"
                : ($hasAirSource ? "LOWER(COALESCE(sumber_data,''))='migrasi'" : "1=0");

            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) <> 'batal' THEN 1 ELSE 0 END) AS total_pesanan,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'baru' THEN 1 ELSE 0 END) AS status_baru,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'diproses' THEN 1 ELSE 0 END) AS status_diproses,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'siap_dikirim' THEN 1 ELSE 0 END) AS status_siap_dikirim,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'dalam_pengiriman' THEN 1 ELSE 0 END) AS status_dalam_pengiriman,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'selesai' THEN 1 ELSE 0 END) AS pesanan_selesai,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'batal' THEN 1 ELSE 0 END) AS pesanan_batal,
                    SUM(CASE WHEN LOWER(COALESCE(status,'')) <> 'batal' AND ({$historicalExpr}) THEN 1 ELSE 0 END) AS data_migrasi
                FROM air_pesanan
                WHERE DATE(COALESCE(tanggal_kirim, tanggal_pemesanan, created_at)) BETWEEN :awal AND :akhir
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            foreach (['total_pesanan', 'status_baru', 'status_diproses', 'status_siap_dikirim', 'status_dalam_pengiriman', 'pesanan_selesai', 'pesanan_batal', 'data_migrasi'] as $key) {
                $airSummary[$key] = (int)($row[$key] ?? 0);
            }
            $airSummary['data_baru'] = max(0, $airSummary['total_pesanan'] - $airSummary['data_migrasi']);

            if (laporan_table_exists($pdo, 'air_pesanan_lokasi')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM air_pesanan_lokasi l
                    JOIN air_pesanan p ON p.id = l.pesanan_id
                    WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
                      AND LOWER(COALESCE(p.status,'')) <> 'batal'
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $airSummary['total_lokasi'] = (int)$stmt->fetchColumn();
            }

            if (laporan_table_exists($pdo, 'air_pesanan_detail')) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(d.qty),0)
                    FROM air_pesanan_detail d
                    JOIN air_pesanan p ON p.id = d.pesanan_id
                    WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
                      AND LOWER(COALESCE(p.status,'')) <> 'batal'
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $airSummary['total_unit'] = (int)$stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT
                        d.nama_produk,
                        COALESCE(pr.satuan, 'unit') AS satuan,
                        SUM(d.qty) AS qty
                    FROM air_pesanan_detail d
                    JOIN air_pesanan p ON p.id = d.pesanan_id
                    LEFT JOIN air_produk pr ON pr.id = d.produk_id
                    WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
                      AND LOWER(COALESCE(p.status,'')) <> 'batal'
                    GROUP BY d.produk_id, d.nama_produk, pr.satuan
                    ORDER BY qty DESC, d.nama_produk ASC
                    LIMIT 30
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $airProduk = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (laporan_table_exists($pdo, 'air_pesanan_lokasi')) {
                    $stmt = $pdo->prepare("
                        SELECT
                            l.lokasi,
                            COUNT(DISTINCT p.id) AS total_pesanan,
                            COALESCE(SUM(d.qty),0) AS total_unit
                        FROM air_pesanan_lokasi l
                        JOIN air_pesanan p ON p.id = l.pesanan_id
                        LEFT JOIN air_pesanan_detail d
                          ON d.pesanan_id = p.id
                         AND d.lokasi_id = l.id
                        WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
                          AND LOWER(COALESCE(p.status,'')) <> 'batal'
                        GROUP BY l.lokasi
                        ORDER BY total_unit DESC, total_pesanan DESC, l.lokasi ASC
                        LIMIT 50
                    ");
                    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                    $airLokasi = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            $stmtCountAir = $pdo->prepare("
                SELECT COUNT(*)
                FROM air_pesanan p
                WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
            ");
            $stmtCountAir->execute([':awal' => $awal, ':akhir' => $akhir]);
            $airTotalRows = (int)$stmtCountAir->fetchColumn();
            $airTotalPages = max(1, (int)ceil($airTotalRows / $airPerPage));
            if ($airPage > $airTotalPages) {
                $airPage = $airTotalPages;
            }
            $airOffset = ($airPage - 1) * $airPerPage;

            $sourceSelect = $hasAirSource
                ? "p.sumber_data"
                : "'publik' AS sumber_data";
            $historicalSelect = $hasAirHistorical
                ? "p.is_historical"
                : "0 AS is_historical";

            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.nomor_pesanan,
                    p.tanggal_pemesanan,
                    p.tanggal_kirim,
                    p.status,
                    p.created_at,
                    {$sourceSelect},
                    {$historicalSelect},
                    COALESCE(c.nama, '-') AS nama_pemesan,
                    COALESCE(c.no_hp, '-') AS no_hp,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT l.lokasi ORDER BY l.urutan ASC SEPARATOR ', ')
                        FROM air_pesanan_lokasi l
                        WHERE l.pesanan_id = p.id
                    ), '-') AS lokasi,
                    COALESCE((
                        SELECT SUM(d.qty)
                        FROM air_pesanan_detail d
                        WHERE d.pesanan_id = p.id
                    ),0) AS total_unit
                FROM air_pesanan p
                LEFT JOIN air_pelanggan c ON c.id = p.pelanggan_id
                WHERE DATE(COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at)) BETWEEN :awal AND :akhir
                ORDER BY COALESCE(p.tanggal_kirim, p.tanggal_pemesanan, p.created_at) DESC, p.id DESC
                LIMIT {$airPerPage} OFFSET {$airOffset}
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $airPesanan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $airPesanan = [];
        $airProduk = [];
        $airLokasi = [];
        $airLoadErrors[] = 'Pesanan: ' . $e->getMessage();
        error_log('LAPORAN AIR PESANAN ERROR: ' . $e->getMessage());
    }

    try {
        if (laporan_table_exists($pdo, 'air_vendor_order')) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM air_vendor_order
                WHERE DATE(COALESCE(tanggal_kebutuhan, created_at)) BETWEEN :awal AND :akhir
                  AND LOWER(COALESCE(status,'')) <> 'batal'
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $airSummary['rekap_vendor'] = (int)$stmt->fetchColumn();

            $hasNomorSurat = laporan_column_exists($pdo, 'air_vendor_order', 'nomor_surat_jalan');
            $hasTanggalSurat = laporan_column_exists($pdo, 'air_vendor_order', 'tanggal_surat_jalan');
            $hasFileSurat = laporan_column_exists($pdo, 'air_vendor_order', 'surat_jalan_file');
            $hasPenerima = laporan_column_exists($pdo, 'air_vendor_order', 'nama_penerima');

            if ($hasNomorSurat || $hasFileSurat) {
                $suratCondition = [];
                if ($hasNomorSurat) $suratCondition[] = "NULLIF(TRIM(nomor_surat_jalan),'') IS NOT NULL";
                if ($hasFileSurat) $suratCondition[] = "NULLIF(TRIM(surat_jalan_file),'') IS NOT NULL";

                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM air_vendor_order
                    WHERE DATE(COALESCE(tanggal_kebutuhan, created_at)) BETWEEN :awal AND :akhir
                      AND LOWER(COALESCE(status,'')) <> 'batal'
                      AND (" . implode(' OR ', $suratCondition) . ")
                ");
                $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
                $airSummary['surat_jalan'] = (int)$stmt->fetchColumn();
            }

            $selectNomorSurat = $hasNomorSurat ? "vo.nomor_surat_jalan" : "NULL AS nomor_surat_jalan";
            $selectTanggalSurat = $hasTanggalSurat ? "vo.tanggal_surat_jalan" : "NULL AS tanggal_surat_jalan";
            $selectFileSurat = $hasFileSurat ? "vo.surat_jalan_file" : "NULL AS surat_jalan_file";
            $selectPenerima = $hasPenerima ? "vo.nama_penerima" : "NULL AS nama_penerima";

            $stmt = $pdo->prepare("
                SELECT
                    vo.id,
                    vo.nomor_vendor_order,
                    vo.tanggal_rekap,
                    vo.tanggal_kebutuhan,
                    vo.vendor_nama,
                    vo.vendor_wa,
                    vo.status,
                    {$selectNomorSurat},
                    {$selectTanggalSurat},
                    {$selectFileSurat},
                    {$selectPenerima},
                    COALESCE((SELECT COUNT(*) FROM air_vendor_order_source s WHERE s.vendor_order_id = vo.id),0) AS source_count,
                    COALESCE((SELECT SUM(d.jumlah_dipesan) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id),0) AS total_dipesan,
                    COALESCE((SELECT SUM(d.jumlah_diterima) FROM air_vendor_order_detail d WHERE d.vendor_order_id = vo.id),0) AS total_diterima
                FROM air_vendor_order vo
                WHERE DATE(COALESCE(vo.tanggal_kebutuhan, vo.created_at)) BETWEEN :awal AND :akhir
                  AND LOWER(COALESCE(vo.status,'')) <> 'batal'
                ORDER BY COALESCE(vo.tanggal_kebutuhan, vo.created_at) DESC, vo.id DESC
                LIMIT 100
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $airVendor = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (laporan_table_exists($pdo, 'air_vendor_order_detail') && laporan_table_exists($pdo, 'air_vendor_order')) {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(d.jumlah_dipesan),0) AS dipesan,
                    COALESCE(SUM(d.jumlah_diterima),0) AS diterima
                FROM air_vendor_order_detail d
                JOIN air_vendor_order v ON v.id = d.vendor_order_id
                WHERE DATE(COALESCE(v.tanggal_kebutuhan, v.created_at)) BETWEEN :awal AND :akhir
                  AND LOWER(COALESCE(v.status,'')) <> 'batal'
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $airSummary['unit_dipesan_vendor'] = (int)($row['dipesan'] ?? 0);
            $airSummary['unit_diterima_vendor'] = (int)($row['diterima'] ?? 0);
            $airSummary['selisih_vendor'] = max(0, $airSummary['unit_dipesan_vendor'] - $airSummary['unit_diterima_vendor']);
        }
    } catch (Throwable $e) {
        $airVendor = [];
        $airLoadErrors[] = 'Vendor: ' . $e->getMessage();
        error_log('LAPORAN AIR VENDOR ERROR: ' . $e->getMessage());
    }

    try {
        if (laporan_table_exists($pdo, 'air_kwitansi')) {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_kwitansi,
                    COALESCE(SUM(CASE WHEN status_pembayaran <> 'batal' THEN total_tagihan ELSE 0 END),0) AS nilai_tagihan,
                    COALESCE(SUM(CASE WHEN status_pembayaran = 'lunas' THEN total_tagihan ELSE 0 END),0) AS nilai_lunas,
                    COALESCE(SUM(CASE WHEN status_pembayaran = 'belum_bayar' THEN total_tagihan ELSE 0 END),0) AS nilai_belum_bayar
                FROM air_kwitansi
                WHERE tanggal_kwitansi BETWEEN :awal AND :akhir
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $airSummary['total_kwitansi'] = (int)($row['total_kwitansi'] ?? 0);
            $airSummary['nilai_tagihan'] = (float)($row['nilai_tagihan'] ?? 0);
            $airSummary['nilai_lunas'] = (float)($row['nilai_lunas'] ?? 0);
            $airSummary['nilai_belum_bayar'] = (float)($row['nilai_belum_bayar'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT id, nomor_kwitansi, tanggal_kwitansi, nama_instansi,
                       total_tagihan, status_pembayaran, tanggal_bayar
                FROM air_kwitansi
                WHERE tanggal_kwitansi BETWEEN :awal AND :akhir
                ORDER BY tanggal_kwitansi DESC, id DESC
                LIMIT 100
            ");
            $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
            $airKwitansi = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $airKwitansi = [];
        $airLoadErrors[] = 'Kwitansi: ' . $e->getMessage();
        error_log('LAPORAN AIR KWITANSI ERROR: ' . $e->getMessage());
    }
}

catat_view_once($pdo, 'Laporan Operasional', 'Membuka laporan POS Toko, Cafe, Rental, Simpan Pinjam, atau Air Mineral');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Operasional — SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #fcfcfc;
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

        tbody tr {
            transition: background 0.15s;
        }

        tbody tr:hover {
            background: #f9f9f9;
        }

        input:focus,
        select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .06);
            border-color: #1a1a1a !important;
        }

        .invoice-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .invoice-link:hover {
            text-decoration: underline;
        }

        .rank-circle {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #111;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .badge-aktif {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-blue {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .badge-gray {
            background: #f5f5f5;
            color: #404040;
            border: 1px solid #e5e5e5;
        }

        .badge-trx {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .badge-barang {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .badge-selesai {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .badge-proses {
            background: #fefce8;
            color: #a16207;
            border: 1px solid #fde68a;
        }

        .badge-batal {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .badge-expired {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .badge-expired-h7 {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        .badge-expired-h30 {
            background: #fffbeb;
            color: #a16207;
            border: 1px solid #fde68a;
        }

        .card-list {
            display: none;
        }

        @media (min-width:1024px) {

            .laporan-header,
            .laporan-main-wrap {
                margin-left: 220px;
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

        @media (max-width:1023px) {
            body {
                padding-bottom: 76px;
            }

            .laporan-header {
                margin-left: 0 !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            .laporan-main-wrap {
                margin-left: 0 !important;
            }

            .laporan-main {
                padding: 1rem !important;
                padding-bottom: 6rem !important;
            }

            .tbl-desktop {
                display: none !important;
            }

            .card-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .75rem;
                padding: .75rem;
                background: #fff;
            }

            .table-footer-mobile {
                grid-column: 1/-1;
                background: #fafafa;
                border: 1px solid #f0f0f0;
                padding: .85rem 1rem;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .07em;
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                color: #737373;
            }
        }

        @media (max-width:640px) {
            .card-list {
                grid-template-columns: 1fr;
            }

            .laporan-main {
                padding: .75rem !important;
                padding-bottom: 6rem !important;
            }
        }

        .air-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .air-status-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .air-mini-card {
            border: 1px solid #f0f0f0;
            background: #fff;
            padding: 14px;
        }

        .air-list-card {
            border: 1px solid #f0f0f0;
            background: #fff;
            padding: 14px;
        }

        .air-list-card+.air-list-card {
            margin-top: 8px;
        }

        @media (min-width:768px) {
            .air-kpi-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .air-status-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width:1280px) {
            .air-kpi-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }

            .air-status-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }

        .print-report-header {
            display: none;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 9mm;
            }

            html,
            body {
                width: 100%;
                background: #fff !important;
                color: #111 !important;
                font-family: Arial, sans-serif !important;
                font-size: 10px !important;
            }

            body {
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print,
            .sidebar,
            nav,
            header,
            .app-header,
            .page-header {
                display: none !important;
            }

            .laporan-header,
            .laporan-main-wrap,
            .laporan-main,
            .content,
            main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: none !important;
            }

            .laporan-main {
                display: block !important;
            }

            .print-report-header {
                display: block !important;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 2px solid #111;
            }

            .print-brand {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .print-brand img {
                width: 42px;
                height: 42px;
                object-fit: contain;
            }

            .print-brand h1 {
                margin: 0;
                font-size: 20px;
                text-transform: uppercase;
                letter-spacing: .8px;
            }

            .print-brand p {
                margin: 2px 0 0;
                font-size: 10px;
                color: #555;
            }

            .print-meta {
                margin-top: 7px;
                display: flex;
                justify-content: space-between;
                gap: 15px;
                font-size: 9px;
            }

            #panel-kasir,
            #panel-cafe,
            #panel-rental,
            #panel-ksp,
            #panel-air {
                display: block !important;
            }

            .card-list {
                display: none !important;
            }

            .tbl-desktop {
                display: block !important;
                overflow: visible !important;
            }

            section,
            .bg-white,
            .border,
            .border-subtle {
                box-shadow: none !important;
            }

            section {
                break-inside: avoid;
                margin-bottom: 10px !important;
            }

            table {
                width: 100% !important;
                min-width: 0 !important;
                border-collapse: collapse !important;
                font-size: 8px !important;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr {
                break-inside: avoid;
            }

            th,
            td {
                border: 1px solid #bbb !important;
                padding: 4px 5px !important;
                color: #111 !important;
                background: #fff !important;
            }

            th {
                background: #e5e7eb !important;
                font-size: 7.5px !important;
            }

            .grid {
                gap: 6px !important;
            }

            .text-2xl,
            .text-lg {
                font-size: 14px !important;
            }

            a {
                color: #111 !important;
                text-decoration: none !important;
            }
        }


        /* Pagination seluruh tabel laporan */
        .report-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }

        .report-pagination-info {
            font-size: 11px;
            color: #737373;
            font-weight: 700;
        }

        .report-pagination-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            max-width: 100%;
        }

        .report-pagination button,
        .report-pagination select {
            min-height: 34px;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 800;
            color: #404040;
            cursor: pointer;
            border-radius: 0;
        }

        .report-pagination button.is-active {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .report-pagination button:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        @media (max-width:640px) {
            .report-pagination {
                align-items: stretch;
                flex-direction: column;
            }

            .report-pagination-actions {
                width: 100%;
            }
        }

        @media print {
            .report-pagination {
                display: none !important;
            }

            .report-page-hidden {
                display: table-row !important;
            }

            .card-list .report-page-hidden {
                display: block !important;
            }
        }
    </style>
</head>

<body class="antialiased min-h-screen pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <div class="laporan-main-wrap">
        <main class="laporan-main p-4 sm:p-5 md:p-8 lg:p-10 flex flex-col gap-5 md:gap-6">

            <section class="print-report-header" style="display:none">
                <div class="print-brand">
                    <img src="assets/sejahub_icon.png" alt="SEJAHUB">
                    <div>
                        <h1>Laporan Operasional</h1>
                        <p>SEJAHUB — Sistem Informasi Koperasi dan Penjualan</p>
                    </div>
                </div>
                <div class="print-meta">
                    <span>Periode: <strong><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></strong></span>
                    <span>Dicetak: <strong><?= date('d/m/Y H:i') ?> WIB</strong></span>
                </div>
            </section>

            <!-- ── Judul + Tab (admin lihat semua) ─────────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold tracking-tight">Laporan Operasional</h2>
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-0.5">
                        Monitoring seluruh aktivitas operasional koperasi meliputi POS Toko, POS Cafe, Rental Bandara, Simpan Pinjam, dan Air Mineral.
                    </p>
                </div>
                <div class="no-print flex flex-wrap gap-2">
                    <button type="button" onclick="window.print()" class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">Cetak / Simpan PDF</button>
                </div>
            </div>

            <!-- Tab navigasi — hanya muncul untuk admin -->
            <?php if ($showTabs): ?>
                <div class="flex gap-0 border-b border-subtle no-print">
                    <button id="tab-kasir" onclick="switchTab('kasir')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Kasir / POS
                    </button>
                    <button id="tab-cafe" onclick="switchTab('cafe')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Cafe
                    </button>
                    <button id="tab-rental" onclick="switchTab('rental')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Rental Bandara
                    </button>
                    <button id="tab-ksp" onclick="switchTab('ksp')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Simpan Pinjam
                    </button>
                    <button id="tab-air" onclick="switchTab('air')"
                        class="tab-btn px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition-all">
                        Air Mineral
                    </button>
                    <!-- ke depan: tambah tab baru di sini -->
                </div>
            <?php endif; ?>

            <!-- ── Filter Periode ────────────────────────────────────────────────── -->
            <section class="no-print bg-white border border-subtle p-4">
                <form method="GET">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Preset Periode</label>
                            <select name="preset" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                                <option value="hari_ini" <?= $preset === 'hari_ini'  ? 'selected' : '' ?>>Hari Ini</option>
                                <option value="minggu_ini" <?= $preset === 'minggu_ini' ? 'selected' : '' ?>>Minggu Ini</option>
                                <option value="bulan_ini" <?= $preset === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                                <option value="tahun_ini" <?= $preset === 'tahun_ini' ? 'selected' : '' ?>>Tahun Ini</option>
                                <option value="custom" <?= $preset === 'custom'    ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Awal</label>
                            <input type="date" name="awal" value="<?= e($awal) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tanggal Akhir</label>
                            <input type="date" name="akhir" value="<?= e($akhir) ?>" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <button type="submit" class="w-full px-5 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">Tampilkan</button>
                        </div>
                        <div>
                            <a href="laporan.php" class="w-full flex justify-center px-4 py-2.5 border border-subtle text-[10px] font-black uppercase tracking-widest text-gray-500 hover:bg-gray-50 transition-all">Reset</a>
                        </div>
                    </div>
                </form>
            </section>


            <?php if ($isKasir): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN KASIR / POS                                                -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-kasir">

                    <!-- KPI Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Omzet Bersih</p>
                            <p class="text-2xl font-bold text-blue-600"><?= rupiah($omzet) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Setelah diskon transaksi</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Transaksi</p>
                            <p class="text-2xl font-bold"><?= angka($totalTransaksi) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Rata-rata <?= rupiah($rata) ?></p>
                        </div>
                        <div class="bg-white border <?= $totalDiskon > 0 ? 'border-red-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Diskon</p>
                            <p class="text-2xl font-bold <?= $totalDiskon > 0 ? 'text-red-600' : '' ?>"><?= rupiah($totalDiskon) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Trx <?= rupiah($diskonTrxSum) ?> + Barang <?= rupiah($diskonBarangTotal) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Member</p>
                            <p class="text-2xl font-bold text-green-600"><?= angka($totalPoint) ?> pt</p>
                            <p class="text-[10px] text-gray-400 mt-1">Diberikan periode ini</p>
                        </div>
                        <div class="bg-white border <?= $totalPointPakai > 0 ? 'border-yellow-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Point Ditukar</p>
                            <p class="text-2xl font-bold <?= $totalPointPakai > 0 ? 'text-yellow-600' : '' ?>"><?= angka($totalPointPakai) ?> pt</p>
                            <p class="text-[10px] text-gray-400 mt-1">Potongan <?= rupiah($totalNilaiPointPakai) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bayar Diterima</p>
                            <p class="text-2xl font-bold"><?= rupiah($totalBayar) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Kembalian</p>
                            <p class="text-2xl font-bold"><?= rupiah($totalKembalian) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">&nbsp;</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5 md:col-span-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                            <p class="text-lg font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($totalTransaksi) ?> transaksi</p>
                        </div>
                    </div>

                    <!-- Detail Transaksi -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Transaksi</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka($totalTransaksi) ?> transaksi ditemukan · halaman <?= angka($trxPage) ?> dari <?= angka($trxTotalPages) ?></p>
                            </div>
                        </div>
                        <!-- Desktop -->
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:860px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Invoice</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Metode</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon Trx</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon Barang</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Bayar</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Kembali</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$transaksi): ?>
                                        <tr>
                                            <td colspan="10" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada transaksi</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($transaksi as $t):
                                        $metodeRaw = $t['metode_pembayaran'] ?? 'tunai';
                                        $metode = label_metode_pembayaran($metodeRaw);
                                        $bCls   = strtolower($metode) !== 'tunai' ? 'badge-blue' : 'badge-gray';
                                    ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($t['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4"><a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm"><?= e($t['invoice']) ?></a></td>
                                            <td class="px-5 py-4">
                                                <?php if (!empty($t['member_nama'])): ?>
                                                    <div class="font-semibold text-sm"><?= e($t['member_nama']) ?></div>
                                                    <div class="text-[10px] text-gray-400 font-mono"><?= e($t['member_kode'] ?? '') ?></div>
                                                <?php else: ?><span class="text-gray-400 text-sm">Umum</span><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4"><span class="<?= $bCls ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(strtoupper($metode)) ?></span></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= ($t['diskon'] ?? 0) > 0 ? rupiah($t['diskon']) : '<span class="text-gray-300">—</span>' ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-orange-600"><?= ($t['diskon_barang'] ?? 0) > 0 ? rupiah($t['diskon_barang']) : '<span class="text-gray-300">—</span>' ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['bayar'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($t['kembalian'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= ($t['point_dapat'] ?? 0) > 0 ? angka($t['point_dapat']) . ' pt' : '<span class="text-gray-300">—</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($transaksi): ?>
                                    <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                        <tr>
                                            <td colspan="4" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total</td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-red-600"><?= rupiah($diskonTrxSum) ?></td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-orange-600"><?= rupiah($diskonBarangTotal) ?></td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-blue-600"><?= rupiah($omzet) ?></td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold"><?= rupiah($totalBayar) ?></td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold"><?= rupiah($totalKembalian) ?></td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-green-600"><?= angka($totalPoint) ?> pt</td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                        <!-- Mobile -->
                        <div class="card-list">
                            <?php if (!$transaksi): ?>
                                <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada transaksi</div>
                            <?php endif; ?>
                            <?php foreach ($transaksi as $t):
                                $metode = $t['metode_pembayaran'] ?? 'tunai';
                                $bCls   = in_array(strtolower($metode), ['transfer', 'qris', 'debit', 'kredit']) ? 'badge-blue' : 'badge-gray';
                            ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>" target="_blank" class="invoice-link text-sm block truncate"><?= e($t['invoice']) ?></a>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($t['created_at'] ?? null)) ?></p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold"><?= rupiah($t['total'] ?? 0) ?></p>
                                            <span class="<?= $bCls ?> text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block"><?= e(strtoupper($metode)) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-400">Member</span>
                                            <span class="font-semibold"><?= !empty($t['member_nama']) ? e($t['member_nama']) : '<span class="text-gray-400">Umum</span>' ?></span>
                                        </div>
                                        <?php if (($t['diskon'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Diskon Trx</span><span class="font-bold text-red-600"><?= rupiah($t['diskon']) ?></span></div><?php endif; ?>
                                        <?php if (($t['diskon_barang'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Diskon Barang</span><span class="font-bold text-orange-600"><?= rupiah($t['diskon_barang']) ?></span></div><?php endif; ?>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Bayar</span><span class="font-semibold"><?= rupiah($t['bayar'] ?? 0) ?></span></div>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Kembalian</span><span class="font-semibold"><?= rupiah($t['kembalian'] ?? 0) ?></span></div>
                                        <?php if (($t['point_dapat'] ?? 0) > 0): ?><div class="flex justify-between text-xs"><span class="text-gray-400">Poin</span><span class="font-bold text-green-600"><?= angka($t['point_dapat']) ?> pt</span></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($transaksi): ?>
                                <div class="table-footer-mobile">
                                    <span>Total: <strong class="text-blue-600"><?= rupiah($omzet) ?></strong></span>
                                    <span>Diskon: <strong class="text-red-600"><?= rupiah($totalDiskon) ?></strong></span>
                                    <span>Bayar: <strong><?= rupiah($totalBayar) ?></strong></span>
                                    <span>Poin: <strong class="text-green-600"><?= angka($totalPoint) ?> pt</strong></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($trxTotalPages > 1): ?>
                            <div class="no-print px-4 md:px-5 py-4 border-t border-subtle bg-gray-50 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                                <div class="text-xs text-gray-500">
                                    Menampilkan <?= angka($trxOffset + 1) ?>–<?= angka(min($trxOffset + $trxPerPage, $totalTransaksi)) ?>
                                    dari <?= angka($totalTransaksi) ?> transaksi
                                </div>
                                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                    <form method="GET" class="flex items-center gap-2">
                                        <?php foreach ($_GET as $key => $value): ?>
                                            <?php if (!in_array($key, ['trx_page', 'trx_limit'], true) && !is_array($value)): ?>
                                                <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <input type="hidden" name="trx_page" value="1">
                                        <select name="trx_limit" onchange="this.form.submit()" class="border border-gray-200 bg-white px-3 py-2 text-xs font-bold">
                                            <?php foreach ($trxAllowedLimits as $limitOption): ?>
                                                <option value="<?= $limitOption ?>" <?= $trxPerPage === $limitOption ? 'selected' : '' ?>><?= $limitOption ?> / halaman</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <div class="flex items-center gap-1 overflow-x-auto no-scrollbar">
                                        <?php if ($trxPage > 1): ?>
                                            <a href="<?= e(laporan_trx_page_url($trxPage - 1, $trxPerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&larr;</a>
                                        <?php endif; ?>
                                        <?php for ($pg = max(1, $trxPage - 2); $pg <= min($trxTotalPages, $trxPage + 2); $pg++): ?>
                                            <a href="<?= e(laporan_trx_page_url($pg, $trxPerPage)) ?>"
                                                class="px-3 py-2 text-xs font-bold <?= $pg === $trxPage ? 'bg-black text-white' : 'border border-gray-200 bg-white text-gray-700' ?>">
                                                <?= $pg ?>
                                            </a>
                                        <?php endfor; ?>
                                        <?php if ($trxPage < $trxTotalPages): ?>
                                            <a href="<?= e(laporan_trx_page_url($trxPage + 1, $trxPerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&rarr;</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Produk Terlaris + Diskon -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 md:gap-6">

                        <!-- Produk Terlaris -->
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk Terlaris</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Top 20 berdasarkan qty terjual</p>
                            </div>
                            <div class="tbl-desktop overflow-x-auto no-scrollbar">
                                <table class="w-full text-left" style="min-width:400px">
                                    <thead class="border-b border-subtle bg-gray-50">
                                        <tr>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Qty</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diskon</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Penjualan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#f5f5f5]">
                                        <?php if (!$produk): ?><tr>
                                                <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada produk</td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($produk as $i => $p): ?>
                                            <tr>
                                                <td class="px-5 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <?php if ($i < 3): ?><span class="rank-circle"><?= $i + 1 ?></span><?php endif; ?>
                                                        <div>
                                                            <div class="font-semibold text-sm"><?= e($p['nama']) ?></div>
                                                            <div class="text-[10px] text-gray-400 font-mono"><?= e($p['kode'] ?? '') ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($p['qty']) ?></td>
                                                <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= $p['diskon'] > 0 ? rupiah($p['diskon']) : '<span class="text-gray-300">—</span>' ?></td>
                                                <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($p['penjualan']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-list">
                                <?php if (!$produk): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada produk</div><?php endif; ?>
                                <?php foreach ($produk as $i => $p): ?>
                                    <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <?php if ($i < 3): ?><span class="rank-circle shrink-0"><?= $i + 1 ?></span><?php endif; ?>
                                                <div class="min-w-0">
                                                    <p class="font-bold text-sm truncate"><?= e($p['nama']) ?></p>
                                                </div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-bold"><?= rupiah($p['penjualan']) ?></p>
                                                <p class="text-[10px] text-gray-400"><?= angka($p['qty']) ?> pcs</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <!-- Diskon Terpakai -->
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Diskon Terpakai</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Promo transaksi dan barang</p>
                            </div>
                            <div class="tbl-desktop overflow-x-auto no-scrollbar">
                                <table class="w-full text-left" style="min-width:360px">
                                    <thead class="border-b border-subtle bg-gray-50">
                                        <tr>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nama</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tipe</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Pakai</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#f5f5f5]">
                                        <?php if (!$diskonTransaksi && !$diskonBarang): ?><tr>
                                                <td colspan="4" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada diskon</td>
                                            </tr><?php endif; ?>
                                        <?php foreach ($diskonTransaksi as $d): ?>
                                            <tr>
                                                <td class="px-5 py-4 text-sm font-semibold"><?= e($d['nama']) ?></td>
                                                <td class="px-5 py-4"><span class="badge-trx text-[9px] font-bold uppercase px-2 py-0.5 rounded-full">Transaksi</span></td>
                                                <td class="px-5 py-4 text-right text-sm"><?= angka($d['jumlah']) ?>×</td>
                                                <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php foreach ($diskonBarang as $d): ?>
                                            <tr>
                                                <td class="px-5 py-4 text-sm font-semibold"><?= e($d['nama']) ?></td>
                                                <td class="px-5 py-4"><span class="badge-barang text-[9px] font-bold uppercase px-2 py-0.5 rounded-full">Barang</span></td>
                                                <td class="px-5 py-4 text-right text-sm"><?= angka($d['jumlah']) ?>×</td>
                                                <td class="px-5 py-4 text-right text-sm font-bold text-red-600"><?= rupiah($d['total']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <?php if ($diskonTransaksi || $diskonBarang): ?>
                                        <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                            <tr>
                                                <td colspan="3" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total Diskon</td>
                                                <td class="px-5 py-3 text-right text-[11px] font-bold text-red-600"><?= rupiah($totalDiskon) ?></td>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </section>
                    </div>

                    <!-- Poin Member — hanya admin & kasir, rental tidak perlu lihat ini -->
                    <?php if ($memberPoin): ?>
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Poin Member — Periode Ini</h2>
                            </div>
                            <div class="tbl-desktop overflow-x-auto no-scrollbar">
                                <table class="w-full text-left" style="min-width:600px">
                                    <thead class="border-b border-subtle bg-gray-50">
                                        <tr>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kode</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Trx</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total Belanja</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin Periode</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Poin Total (DB)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#f5f5f5]">
                                        <?php foreach ($memberPoin as $mp): ?>
                                            <tr>
                                                <td class="px-5 py-4 text-sm font-semibold"><?= e($mp['nama']) ?></td>
                                                <td class="px-5 py-4 text-[11px] text-gray-400 font-mono"><?= e($mp['kode']) ?></td>
                                                <td class="px-5 py-4 text-right text-sm"><?= angka($mp['jumlah_trx']) ?></td>
                                                <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($mp['total_belanja']) ?></td>
                                                <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= angka($mp['point_periode']) ?> pt</td>
                                                <td class="px-5 py-4 text-right text-sm text-gray-400"><?= angka($mp['point_total_lifetime']) ?> pt</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endif; ?>


                    <!-- Laporan Produk Kedaluwarsa -->
                    <section class="bg-white border border-subtle overflow-hidden mt-5 md:mt-6">
                        <div class="px-5 py-4 border-b border-subtle flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Laporan Produk Kedaluwarsa</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Monitoring produk kedaluwarsa dan yang akan kedaluwarsa dalam 30 hari</p>
                            </div>
                            <div class="flex flex-wrap gap-2 no-print">
                                <a href="produk.php?expired=expired" class="px-3 py-2 border border-red-200 bg-red-50 text-red-700 text-[9px] font-black uppercase tracking-widest">Lihat Produk Expired</a>
                                <button type="button" onclick="window.print()" class="px-3 py-2 bg-black text-white text-[9px] font-black uppercase tracking-widest">Cetak / Simpan PDF</button>
                            </div>
                        </div>

                        <?php if (!$expiredColumnReady): ?>
                            <div class="m-5 border border-yellow-200 bg-yellow-50 px-4 py-3">
                                <p class="text-xs font-bold text-yellow-800">Kolom expired_date belum tersedia pada tabel produk.</p>
                                <p class="text-[10px] text-yellow-700 mt-1">Buka halaman Produk terlebih dahulu agar kolom tanggal kedaluwarsa dibuat otomatis.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 p-4 md:p-5 border-b border-subtle bg-gray-50/50">
                                <div class="bg-white border border-red-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-red-500">Sudah Kedaluwarsa</p>
                                    <p class="text-2xl font-black text-red-700 mt-1"><?= angka($expiredSummary['expired']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">SKU aktif</p>
                                </div>
                                <div class="bg-white border border-orange-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-orange-500">H-7</p>
                                    <p class="text-2xl font-black text-orange-700 mt-1"><?= angka($expiredSummary['h7']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Segera diperiksa</p>
                                </div>
                                <div class="bg-white border border-yellow-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-yellow-600">H-8 s.d. H-30</p>
                                    <p class="text-2xl font-black text-yellow-700 mt-1"><?= angka($expiredSummary['h30']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Perlu dipantau</p>
                                </div>
                                <div class="bg-white border border-gray-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-500">Tanpa Tanggal</p>
                                    <p class="text-2xl font-black text-gray-800 mt-1"><?= angka($expiredSummary['tanpa_tanggal']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Belum diisi</p>
                                </div>
                                <div class="bg-white border border-red-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-red-500">Stok Expired</p>
                                    <p class="text-2xl font-black text-red-700 mt-1"><?= angka($expiredSummary['stok_expired']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Total unit</p>
                                </div>
                                <div class="bg-white border border-red-200 p-3">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-red-500">Nilai HPP Expired</p>
                                    <p class="text-lg font-black text-red-700 mt-1"><?= rupiah($expiredSummary['nilai_expired']) ?></p>
                                    <p class="text-[9px] text-gray-400 mt-1">Estimasi kerugian</p>
                                </div>
                            </div>

                            <div class="tbl-desktop overflow-x-auto no-scrollbar">
                                <table class="w-full text-left" style="min-width:860px">
                                    <thead class="border-b border-subtle bg-gray-50">
                                        <tr>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kategori</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Stok</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal Kedaluwarsa</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Nilai HPP</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#f5f5f5]">
                                        <?php if (!$produkExpiredList): ?>
                                            <tr>
                                                <td colspan="6" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Tidak ada produk kedaluwarsa atau H-30</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($produkExpiredList as $px):
                                            $sisaHari = (int)($px['sisa_hari'] ?? 0);
                                            if ($sisaHari < 0) {
                                                $statusExpired = 'Kedaluwarsa ' . abs($sisaHari) . ' hari';
                                                $expiredClass = 'badge-expired';
                                            } elseif ($sisaHari <= 7) {
                                                $statusExpired = $sisaHari === 0 ? 'Kedaluwarsa Hari Ini' : 'H-' . $sisaHari;
                                                $expiredClass = 'badge-expired-h7';
                                            } else {
                                                $statusExpired = 'H-' . $sisaHari;
                                                $expiredClass = 'badge-expired-h30';
                                            }
                                        ?>
                                            <tr>
                                                <td class="px-5 py-4">
                                                    <div class="font-semibold text-sm"><?= e($px['nama'] ?? '-') ?></div>
                                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?= e($px['kode'] ?? '') ?></div>
                                                </td>
                                                <td class="px-5 py-4 text-sm text-gray-600"><?= e($px['kategori'] ?? '-') ?></td>
                                                <td class="px-5 py-4 text-center text-sm font-bold"><?= angka($px['stok'] ?? 0) ?> <span class="text-[9px] text-gray-400 font-normal"><?= e($px['satuan'] ?? '') ?></span></td>
                                                <td class="px-5 py-4 text-sm font-semibold"><?= e(tgl($px['expired_date'] ?? null)) ?></td>
                                                <td class="px-5 py-4 text-center"><span class="<?= $expiredClass ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e($statusExpired) ?></span></td>
                                                <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah(((float)($px['harga_beli'] ?? 0)) * ((float)($px['stok'] ?? 0))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="card-list">
                                <?php if (!$produkExpiredList): ?>
                                    <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Tidak ada produk kedaluwarsa atau H-30</div>
                                <?php endif; ?>
                                <?php foreach ($produkExpiredList as $px):
                                    $sisaHari = (int)($px['sisa_hari'] ?? 0);
                                    if ($sisaHari < 0) {
                                        $statusExpired = 'Kedaluwarsa ' . abs($sisaHari) . ' hari';
                                        $expiredClass = 'badge-expired';
                                    } elseif ($sisaHari <= 7) {
                                        $statusExpired = $sisaHari === 0 ? 'Kedaluwarsa Hari Ini' : 'H-' . $sisaHari;
                                        $expiredClass = 'badge-expired-h7';
                                    } else {
                                        $statusExpired = 'H-' . $sisaHari;
                                        $expiredClass = 'badge-expired-h30';
                                    }
                                ?>
                                    <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold truncate"><?= e($px['nama'] ?? '-') ?></p>
                                                <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?= e($px['kode'] ?? '') ?></p>
                                            </div>
                                            <span class="<?= $expiredClass ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full shrink-0"><?= e($statusExpired) ?></span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle">
                                            <div>
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Kedaluwarsa</p>
                                                <p class="text-xs font-bold mt-0.5"><?= e(tgl($px['expired_date'] ?? null)) ?></p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Stok</p>
                                                <p class="text-xs font-bold mt-0.5"><?= angka($px['stok'] ?? 0) ?> <?= e($px['satuan'] ?? '') ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Kategori</p>
                                                <p class="text-xs font-semibold mt-0.5"><?= e($px['kategori'] ?? '-') ?></p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Nilai HPP</p>
                                                <p class="text-xs font-bold mt-0.5"><?= rupiah(((float)($px['harga_beli'] ?? 0)) * ((float)($px['stok'] ?? 0))) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                </div><!-- end panel-kasir -->
            <?php endif; // end isKasir 
            ?>



            <?php if ($isCafe): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN CAFE                                                       -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-cafe">
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Omzet Cafe</p>
                            <p class="text-2xl font-bold text-amber-600"><?= rupiah($cafeOmzet) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Transaksi sumber cafe</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Transaksi</p>
                            <p class="text-2xl font-bold"><?= angka($cafeTotalTransaksi) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Rata-rata <?= rupiah($cafeRata) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tunai</p>
                            <p class="text-xl font-bold text-green-600"><?= rupiah($cafeTunai) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Pembayaran cash</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Non-Tunai</p>
                            <p class="text-xl font-bold text-purple-600"><?= rupiah($cafeNontunai) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">QRIS / EDC / transfer</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Margin</p>
                            <p class="text-xl font-bold text-blue-600"><?= rupiah($cafeMargin) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Penjualan dikurangi modal</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                            <p class="text-sm font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($cafeTotalTransaksi) ?> transaksi</p>
                        </div>
                    </div>

                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Transaksi Cafe</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= angka($cafeTotalTransaksi) ?> transaksi ditemukan · halaman <?= angka($cafePage) ?> dari <?= angka($cafeTotalPages) ?></p>
                        </div>

                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:900px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Invoice</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kasir</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tipe</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Meja</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Metode</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$cafeTransaksi): ?>
                                        <tr>
                                            <td colspan="8" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada transaksi cafe</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($cafeTransaksi as $ct): ?>
                                        <?php
                                        $cafeMetodeLabel = label_metode_pembayaran($ct['metode_pembayaran'] ?? 'tunai');
                                        $cafeBadgeMetode = strtolower($cafeMetodeLabel) !== 'tunai' ? 'badge-blue' : 'badge-gray';
                                        $cafeStatus = strtolower((string)($ct['status_pesanan'] ?? 'selesai'));
                                        $cafeStatusClass = $cafeStatus === 'selesai'
                                            ? 'badge-selesai'
                                            : ($cafeStatus === 'batal' ? 'badge-batal' : 'badge-proses');
                                        ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($ct['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4"><a href="struk.php?invoice=<?= urlencode($ct['invoice']) ?>" target="_blank" class="invoice-link text-sm"><?= e($ct['invoice']) ?></a></td>
                                            <td class="px-5 py-4 text-sm font-semibold"><?= e($ct['kasir'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-sm font-semibold"><?= e(($ct['tipe_pesanan'] ?? '') === 'takeaway' ? 'Takeaway' : 'Dine In') ?></td>
                                            <td class="px-5 py-4 text-sm"><?= e($ct['nomor_meja'] ?? '-') ?></td>
                                            <td class="px-5 py-4"><span class="<?= $cafeStatusClass ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e($cafeStatus ?: 'selesai') ?></span></td>
                                            <td class="px-5 py-4"><span class="<?= $cafeBadgeMetode ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e($cafeMetodeLabel) ?></span></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($ct['total'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($cafeTransaksi): ?>
                                    <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                        <tr>
                                            <td colspan="7" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total Cafe</td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-amber-600"><?= rupiah($cafeOmzet) ?></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>

                        <div class="card-list">
                            <?php if (!$cafeTransaksi): ?>
                                <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada transaksi cafe</div>
                            <?php endif; ?>
                            <?php foreach ($cafeTransaksi as $ct): ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <a href="struk.php?invoice=<?= urlencode($ct['invoice']) ?>" target="_blank" class="invoice-link text-sm block truncate"><?= e($ct['invoice']) ?></a>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($ct['created_at'] ?? null)) ?></p>
                                        </div>
                                        <p class="text-sm font-bold shrink-0"><?= rupiah($ct['total'] ?? 0) ?></p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-subtle text-xs">
                                        <div><span class="text-gray-400 block">Tipe</span><strong><?= e(($ct['tipe_pesanan'] ?? '') === 'takeaway' ? 'Takeaway' : 'Dine In') ?></strong></div>
                                        <div><span class="text-gray-400 block">Meja</span><strong><?= e($ct['nomor_meja'] ?? '-') ?></strong></div>
                                        <div><span class="text-gray-400 block">Kasir</span><strong><?= e($ct['kasir'] ?? '-') ?></strong></div>
                                        <div><span class="text-gray-400 block">Metode</span><strong><?= e(label_metode_pembayaran($ct['metode_pembayaran'] ?? 'tunai')) ?></strong></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($cafeTotalPages > 1): ?>
                            <div class="no-print px-4 md:px-5 py-4 border-t border-subtle bg-gray-50 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                                <div class="text-xs text-gray-500">
                                    Menampilkan <?= angka($cafeOffset + 1) ?>–<?= angka(min($cafeOffset + $cafePerPage, $cafeTotalTransaksi)) ?>
                                    dari <?= angka($cafeTotalTransaksi) ?> transaksi
                                </div>
                                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                    <form method="GET" class="flex items-center gap-2">
                                        <?php foreach ($_GET as $key => $value): ?>
                                            <?php if (!in_array($key, ['cafe_page', 'cafe_limit'], true) && !is_array($value)): ?>
                                                <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <input type="hidden" name="cafe_page" value="1">
                                        <select name="cafe_limit" onchange="this.form.submit()" class="border border-gray-200 bg-white px-3 py-2 text-xs font-bold">
                                            <?php foreach ($cafeAllowedLimits as $limitOption): ?>
                                                <option value="<?= $limitOption ?>" <?= $cafePerPage === $limitOption ? 'selected' : '' ?>><?= $limitOption ?> / halaman</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <div class="flex items-center gap-1 overflow-x-auto no-scrollbar">
                                        <?php if ($cafePage > 1): ?>
                                            <a href="<?= e(laporan_cafe_page_url($cafePage - 1, $cafePerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&larr;</a>
                                        <?php endif; ?>
                                        <?php for ($pg = max(1, $cafePage - 2); $pg <= min($cafeTotalPages, $cafePage + 2); $pg++): ?>
                                            <a href="<?= e(laporan_cafe_page_url($pg, $cafePerPage)) ?>"
                                                class="px-3 py-2 text-xs font-bold <?= $pg === $cafePage ? 'bg-black text-white' : 'border border-gray-200 bg-white text-gray-700' ?>">
                                                <?= $pg ?>
                                            </a>
                                        <?php endfor; ?>
                                        <?php if ($cafePage < $cafeTotalPages): ?>
                                            <a href="<?= e(laporan_cafe_page_url($cafePage + 1, $cafePerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&rarr;</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Menu Cafe Terlaris</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Top 20 berdasarkan jumlah terjual</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
                            <?php if (!$cafeProduk): ?>
                                <div class="md:col-span-2 xl:col-span-3 py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada menu terjual</div>
                            <?php endif; ?>
                            <?php foreach ($cafeProduk as $i => $cp): ?>
                                <div class="border border-subtle p-4 flex items-center justify-between gap-3">
                                    <div class="min-w-0 flex items-center gap-3">
                                        <span class="rank-circle"><?= $i + 1 ?></span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($cp['nama'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e($cp['kode'] ?? '') ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-bold"><?= angka($cp['qty'] ?? 0) ?>x</p>
                                        <p class="text-[10px] text-gray-400"><?= rupiah($cp['penjualan'] ?? 0) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; // end isCafe 
            ?>

            <?php if ($isRental): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN RENTAL                                                      -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-rental">

                    <!-- KPI Cards Rental -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Order</p>
                            <p class="text-2xl font-bold text-blue-600"><?= angka($totalOrderRental) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Dalam periode ini</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pendapatan</p>
                            <p class="text-2xl font-bold text-green-600"><?= rupiah($pendapatanRental) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Total bayar diterima</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5 sm:col-span-1">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Periode</p>
                            <p class="text-lg font-bold leading-snug"><?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($totalOrderRental) ?> order</p>
                        </div>
                    </div>

                    <!-- Detail Order Rental -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Order Rental</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($orderRental)) ?> order ditemukan</p>
                        </div>
                        <!-- Desktop -->
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:700px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Penumpang</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Driver</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tujuan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total Bayar</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$orderRental): ?>
                                        <tr>
                                            <td colspan="6" class="py-20 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada order rental</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($orderRental as $r):
                                        $status   = strtolower($r['status'] ?? 'proses');
                                        $badgeSts = $status === 'selesai' ? 'badge-selesai' : ($status === 'batal' ? 'badge-batal' : 'badge-proses');
                                    ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($r['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4 text-sm font-semibold"><?= e($r['nama_penumpang'] ?? $r['nama'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-600"><?= e($r['nama_driver'] ?? 'Belum assign') ?></td>
                                            <td class="px-5 py-4 text-sm text-gray-600"><?= e($r['tujuan'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($r['total_bayar'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center"><span class="<?= $badgeSts ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(strtoupper($status)) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($orderRental): ?>
                                    <tfoot class="bg-gray-50 border-t-2 border-subtle">
                                        <tr>
                                            <td colspan="4" class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Total</td>
                                            <td class="px-5 py-3 text-right text-[11px] font-bold text-green-600"><?= rupiah($pendapatanRental) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                        <!-- Mobile -->
                        <div class="card-list">
                            <?php if (!$orderRental): ?>
                                <div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada order rental</div>
                            <?php endif; ?>
                            <?php foreach ($orderRental as $r):
                                $status   = strtolower($r['status'] ?? 'proses');
                                $badgeSts = $status === 'selesai' ? 'badge-selesai' : ($status === 'batal' ? 'badge-batal' : 'badge-proses');
                            ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($r['nama_penumpang'] ?? $r['nama'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($r['created_at'] ?? null)) ?></p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold"><?= rupiah($r['total_bayar'] ?? 0) ?></p>
                                            <span class="<?= $badgeSts ?> text-[9px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 inline-block"><?= e(strtoupper($status)) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5 pt-2 border-t border-subtle">
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Driver</span><span class="font-semibold"><?= e($r['nama_driver'] ?? 'Belum assign') ?></span></div>
                                        <div class="flex justify-between text-xs"><span class="text-gray-400">Tujuan</span><span class="font-semibold"><?= e($r['tujuan'] ?? '-') ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($orderRental): ?>
                                <div class="table-footer-mobile">
                                    <span>Total Order: <strong><?= angka($totalOrderRental) ?></strong></span>
                                    <span>Pendapatan: <strong class="text-green-600"><?= rupiah($pendapatanRental) ?></strong></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                </div><!-- end panel-rental -->
            <?php endif; // end isRental 
            ?>


            <?php if ($isKsp): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN KSP / SIMPAN PINJAM                                        -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-ksp">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pengajuan Periode Ini</p>
                            <p class="text-2xl font-bold text-amber-600"><?= angka($kspSummary['pengajuan_total']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Nilai <?= rupiah($kspSummary['pengajuan_nilai']) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pinjaman Aktif Dibuat</p>
                            <p class="text-2xl font-bold text-blue-600"><?= angka($kspSummary['pinjaman_aktif']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Pokok <?= rupiah($kspSummary['pinjaman_pokok']) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Angsuran Tertagih</p>
                            <p class="text-2xl font-bold"><?= rupiah($kspSummary['angsuran_tertagih']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Berdasarkan jatuh tempo periode ini</p>
                        </div>
                        <div class="bg-white border <?= $kspSummary['angsuran_belum_bayar'] > 0 ? 'border-red-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Belum Dibayar</p>
                            <p class="text-2xl font-bold <?= $kspSummary['angsuran_belum_bayar'] > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= rupiah($kspSummary['angsuran_belum_bayar']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Dibayar <?= rupiah($kspSummary['angsuran_dibayar']) ?></p>
                        </div>
                    </div>

                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Pengajuan Pinjaman</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($kspPengajuan)) ?> pengajuan ditemukan</p>
                            </div>
                            <a href="pinjaman.php" class="text-[10px] font-black uppercase tracking-widest underline no-print">Kelola</a>
                        </div>
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:760px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Jenis</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Jumlah</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Tenor</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$kspPengajuan): ?>
                                        <tr>
                                            <td colspan="6" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada pengajuan</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($kspPengajuan as $p): ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(waktu($p['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4">
                                                <div class="font-semibold text-sm"><?= e($p['member_nama'] ?? '-') ?></div>
                                                <div class="text-[10px] text-gray-400 font-mono"><?= e($p['member_kode'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-sm font-bold uppercase"><?= e($p['jenis'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($p['jumlah'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center text-sm"><?= angka($p['tenor'] ?? 0) ?> bln</td>
                                            <td class="px-5 py-4 text-center"><span class="badge-gray text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e($p['status'] ?? '-') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-list">
                            <?php if (!$kspPengajuan): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada pengajuan</div><?php endif; ?>
                            <?php foreach ($kspPengajuan as $p): ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($p['member_nama'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(waktu($p['created_at'] ?? null)) ?></p>
                                        </div>
                                        <span class="badge-gray text-[9px] font-bold uppercase px-2 py-0.5 rounded-full"><?= e($p['status'] ?? '-') ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Jumlah</span><span class="font-bold"><?= rupiah($p['jumlah'] ?? 0) ?></span></div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Jenis / Tenor</span><span class="font-semibold"><?= e($p['jenis'] ?? '-') ?> · <?= angka($p['tenor'] ?? 0) ?> bln</span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle">
                            <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Jadwal Angsuran Periode Ini</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($kspAngsuran)) ?> angsuran ditemukan</p>
                        </div>
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:760px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Jatuh Tempo</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Ke</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Pokok</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Bunga</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$kspAngsuran): ?>
                                        <tr>
                                            <td colspan="7" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada jadwal angsuran</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($kspAngsuran as $a): ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(tgl($a['jatuh_tempo'] ?? null)) ?></td>
                                            <td class="px-5 py-4">
                                                <div class="font-semibold text-sm"><?= e($a['member_nama'] ?? '-') ?></div>
                                                <div class="text-[10px] text-gray-400 font-mono"><?= e($a['member_kode'] ?? '') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-center text-sm font-bold"><?= angka($a['ke'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($a['jumlah_pokok'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm"><?= rupiah($a['jumlah_bunga'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= rupiah($a['jumlah_total'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center"><span class="badge-gray text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e($a['status'] ?? '-') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-list">
                            <?php if (!$kspAngsuran): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada jadwal angsuran</div><?php endif; ?>
                            <?php foreach ($kspAngsuran as $a): ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($a['member_nama'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5">Jatuh tempo <?= e(tgl($a['jatuh_tempo'] ?? null)) ?></p>
                                        </div>
                                        <span class="badge-gray text-[9px] font-bold uppercase px-2 py-0.5 rounded-full"><?= e($a['status'] ?? '-') ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Angsuran Ke</span><span class="font-semibold"><?= angka($a['ke'] ?? 0) ?></span></div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Total</span><span class="font-bold"><?= rupiah($a['jumlah_total'] ?? 0) ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                </div><!-- end panel-ksp -->
            <?php endif; // end isKsp 
            ?>

            <?php if ($isAirMineral): ?>
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <!-- KONTEN AIR MINERAL                                                  -->
                <!-- ══════════════════════════════════════════════════════════════════ -->
                <div id="panel-air" class="flex flex-col gap-5 md:gap-6">
                    <?php if ($airLoadErrors): ?>
                        <div class="no-print border border-red-200 bg-red-50 px-4 py-3">
                            <p class="text-xs font-bold text-red-700">Sebagian data Air Mineral gagal dimuat.</p>
                            <?php foreach ($airLoadErrors as $airLoadError): ?>
                                <p class="text-[10px] text-red-600 mt-1"><?= e($airLoadError) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <!-- KPI Utama -->
                    <div class="air-kpi-grid">
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Pesanan</p>
                            <p class="text-2xl font-bold text-blue-600"><?= angka($airSummary['total_pesanan']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Batal <?= angka($airSummary['pesanan_batal']) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Produk</p>
                            <p class="text-2xl font-bold"><?= angka($airSummary['total_unit']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($airSummary['total_lokasi']) ?> titik lokasi</p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Rekap Vendor</p>
                            <p class="text-2xl font-bold text-purple-600"><?= angka($airSummary['rekap_vendor']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Dipesan <?= angka($airSummary['unit_dipesan_vendor']) ?></p>
                        </div>
                        <div class="bg-white border <?= $airSummary['selisih_vendor'] > 0 ? 'border-red-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Selisih Vendor</p>
                            <p class="text-2xl font-bold <?= $airSummary['selisih_vendor'] > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= angka($airSummary['selisih_vendor']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Diterima <?= angka($airSummary['unit_diterima_vendor']) ?></p>
                        </div>
                        <div class="bg-white border border-subtle p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nilai Tagihan</p>
                            <p class="text-lg font-bold text-amber-600"><?= rupiah($airSummary['nilai_tagihan']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= angka($airSummary['total_kwitansi']) ?> kwitansi</p>
                        </div>
                        <div class="bg-white border <?= $airSummary['nilai_belum_bayar'] > 0 ? 'border-amber-200' : 'border-subtle' ?> p-4 md:p-5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Belum Dibayar</p>
                            <p class="text-lg font-bold <?= $airSummary['nilai_belum_bayar'] > 0 ? 'text-amber-600' : 'text-green-600' ?>"><?= rupiah($airSummary['nilai_belum_bayar']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1">Lunas <?= rupiah($airSummary['nilai_lunas']) ?></p>
                        </div>
                    </div>

                    <!-- Status & Sumber Data -->
                    <section class="bg-white border border-subtle p-4 md:p-5">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Status & Sumber Pesanan</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Ringkasan progres operasional dan pemisahan data baru dengan data migrasi.</p>
                            </div>
                            <p class="text-[10px] text-gray-400">Periode <?= e(tgl($awal)) ?> – <?= e(tgl($akhir)) ?></p>
                        </div>
                        <div class="air-status-grid">
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Baru</p>
                                <p class="text-xl font-black text-blue-600 mt-1"><?= angka($airSummary['status_baru']) ?></p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Diproses</p>
                                <p class="text-xl font-black text-amber-600 mt-1"><?= angka($airSummary['status_diproses']) ?></p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Siap Dikirim</p>
                                <p class="text-xl font-black text-purple-600 mt-1"><?= angka($airSummary['status_siap_dikirim']) ?></p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Dalam Pengiriman</p>
                                <p class="text-xl font-black text-cyan-600 mt-1"><?= angka($airSummary['status_dalam_pengiriman']) ?></p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Selesai</p>
                                <p class="text-xl font-black text-green-600 mt-1"><?= angka($airSummary['pesanan_selesai']) ?></p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Pesanan Baru</p>
                                <p class="text-xl font-black mt-1"><?= angka($airSummary['data_baru']) ?></p>
                                <p class="text-[9px] text-gray-400 mt-1">Bukan migrasi</p>
                            </div>
                            <div class="air-mini-card">
                                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Data Lama</p>
                                <p class="text-xl font-black text-orange-600 mt-1"><?= angka($airSummary['data_migrasi']) ?></p>
                                <p class="text-[9px] text-gray-400 mt-1">Hasil migrasi</p>
                            </div>
                        </div>
                    </section>

                    <!-- Detail Pesanan -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Detail Pesanan Air Mineral</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka($airTotalRows) ?> pesanan ditemukan · halaman <?= angka($airPage) ?> dari <?= angka($airTotalPages) ?></p>
                            </div>
                            <a href="air_pesanan.php" class="text-[10px] font-black uppercase tracking-widest underline no-print">Kelola</a>
                        </div>
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:1080px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Nomor</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Pemesan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Lokasi</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Jumlah</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Sumber</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$airPesanan): ?><tr>
                                            <td colspan="7" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada pesanan air</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($airPesanan as $ap): ?>
                                        <?php
                                        $airStatus = strtolower((string)($ap['status'] ?? 'baru'));
                                        $airBadge = $airStatus === 'selesai' ? 'badge-selesai' : ($airStatus === 'batal' ? 'badge-batal' : 'badge-proses');
                                        $isHistoricalAir = !empty($ap['is_historical']) || strtolower((string)($ap['sumber_data'] ?? '')) === 'migrasi';
                                        ?>
                                        <tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(tgl($ap['tanggal_pemesanan'] ?? $ap['created_at'] ?? null)) ?></td>
                                            <td class="px-5 py-4 text-sm font-bold"><?= e($ap['nomor_pesanan'] ?? '-') ?></td>
                                            <td class="px-5 py-4">
                                                <div class="font-semibold text-sm"><?= e($ap['nama_pemesan'] ?? '-') ?></div>
                                                <div class="text-[10px] text-gray-400"><?= e($ap['no_hp'] ?? '-') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600"><?= e($ap['lokasi'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($ap['total_unit'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center"><span class="<?= $isHistoricalAir ? 'badge-barang' : 'badge-blue' ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= $isHistoricalAir ? 'Data Lama' : 'Pesanan Baru' ?></span></td>
                                            <td class="px-5 py-4 text-center"><span class="<?= $airBadge ?> text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(str_replace('_', ' ', $airStatus)) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-list">
                            <?php if (!$airPesanan): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada pesanan air</div><?php endif; ?>
                            <?php foreach ($airPesanan as $ap): ?>
                                <?php $isHistoricalAir = !empty($ap['is_historical']) || strtolower((string)($ap['sumber_data'] ?? '')) === 'migrasi'; ?>
                                <div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($ap['nomor_pesanan'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e(tgl($ap['tanggal_pemesanan'] ?? $ap['created_at'] ?? null)) ?></p>
                                        </div>
                                        <p class="text-sm font-bold text-blue-600"><?= angka($ap['total_unit'] ?? 0) ?> unit</p>
                                    </div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Pemesan</span><span class="font-semibold text-right"><?= e($ap['nama_pemesan'] ?? '-') ?></span></div>
                                    <div class="flex justify-between text-xs gap-3"><span class="text-gray-400">Lokasi</span><span class="font-semibold text-right"><?= e($ap['lokasi'] ?? '-') ?></span></div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Sumber</span><span class="font-bold <?= $isHistoricalAir ? 'text-orange-600' : 'text-blue-600' ?>"><?= $isHistoricalAir ? 'Data Lama' : 'Pesanan Baru' ?></span></div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Status</span><span class="font-bold uppercase"><?= e(str_replace('_', ' ', (string)($ap['status'] ?? '-'))) ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($airTotalPages > 1): ?>
                            <div class="no-print px-4 md:px-5 py-4 border-t border-subtle bg-gray-50 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                                <div class="text-xs text-gray-500">Menampilkan <?= angka($airOffset + 1) ?>–<?= angka(min($airOffset + $airPerPage, $airTotalRows)) ?> dari <?= angka($airTotalRows) ?> pesanan</div>
                                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                    <form method="GET" class="flex items-center gap-2">
                                        <?php foreach ($_GET as $key => $value): ?>
                                            <?php if (!in_array($key, ['air_page', 'air_limit'], true) && !is_array($value)): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endif; ?>
                                        <?php endforeach; ?>
                                        <input type="hidden" name="air_page" value="1">
                                        <select name="air_limit" onchange="this.form.submit()" class="border border-gray-200 bg-white px-3 py-2 text-xs font-bold">
                                            <?php foreach ($airAllowedLimits as $limitOption): ?><option value="<?= $limitOption ?>" <?= $airPerPage === $limitOption ? 'selected' : '' ?>><?= $limitOption ?> / halaman</option><?php endforeach; ?>
                                        </select>
                                    </form>
                                    <div class="flex items-center gap-1 overflow-x-auto no-scrollbar">
                                        <?php if ($airPage > 1): ?><a href="<?= e(laporan_air_page_url($airPage - 1, $airPerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&larr;</a><?php endif; ?>
                                        <?php for ($pg = max(1, $airPage - 2); $pg <= min($airTotalPages, $airPage + 2); $pg++): ?><a href="<?= e(laporan_air_page_url($pg, $airPerPage)) ?>" class="px-3 py-2 text-xs font-bold <?= $pg === $airPage ? 'bg-black text-white' : 'border border-gray-200 bg-white text-gray-700' ?>"><?= $pg ?></a><?php endfor; ?>
                                        <?php if ($airPage < $airTotalPages): ?><a href="<?= e(laporan_air_page_url($airPage + 1, $airPerPage)) ?>" class="px-3 py-2 border border-gray-200 bg-white text-xs font-bold">&rarr;</a><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- Produk + Lokasi -->
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 md:gap-6">
                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Produk Air Mineral</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Rekap jumlah per jenis produk</p>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-4">
                                <?php if (!$airProduk): ?><div class="md:col-span-2 py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada produk</div><?php endif; ?>
                                <?php foreach ($airProduk as $i => $prod): ?><div class="border border-subtle p-4 flex items-center justify-between gap-3">
                                        <div class="min-w-0 flex items-center gap-3"><span class="rank-circle"><?= $i + 1 ?></span>
                                            <p class="text-sm font-bold"><?= e($prod['nama_produk'] ?? '-') ?></p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-bold"><?= angka($prod['qty'] ?? 0) ?></p>
                                            <p class="text-[10px] text-gray-400"><?= e($prod['satuan'] ?? 'unit') ?></p>
                                        </div>
                                    </div><?php endforeach; ?>
                            </div>
                        </section>

                        <section class="bg-white border border-subtle overflow-hidden">
                            <div class="px-5 py-4 border-b border-subtle">
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Rekap Lokasi Pengantaran</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Kebutuhan air berdasarkan titik pengantaran</p>
                            </div>
                            <div class="p-4">
                                <?php if (!$airLokasi): ?><div class="py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada data lokasi</div><?php endif; ?>
                                <?php foreach ($airLokasi as $i => $loc): ?><div class="air-list-card flex items-center justify-between gap-4">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2"><span class="rank-circle"><?= $i + 1 ?></span>
                                                <p class="text-sm font-bold"><?= e($loc['lokasi'] ?? '-') ?></p>
                                            </div>
                                            <p class="text-[10px] text-gray-400 mt-1 ml-8"><?= angka($loc['total_pesanan'] ?? 0) ?> pesanan</p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-base font-black text-blue-600"><?= angka($loc['total_unit'] ?? 0) ?></p>
                                            <p class="text-[9px] text-gray-400 uppercase">unit</p>
                                        </div>
                                    </div><?php endforeach; ?>
                            </div>
                        </section>
                    </div>

                    <!-- Vendor & Surat Jalan -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Rekap Vendor & Surat Jalan</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($airVendor)) ?> rekap ditampilkan · <?= angka($airSummary['surat_jalan']) ?> memiliki arsip surat jalan</p>
                            </div><a href="air_rekap_vendor.php" class="text-[10px] font-black uppercase tracking-widest underline no-print">Kelola</a>
                        </div>
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:1080px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kebutuhan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Rekap</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Vendor</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Pesanan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Dipesan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Diterima</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Surat Jalan</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$airVendor): ?><tr>
                                            <td colspan="8" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada rekap vendor</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($airVendor as $av): ?><tr>
                                            <td class="px-5 py-4 text-xs text-gray-500 whitespace-nowrap"><?= e(tgl($av['tanggal_kebutuhan'] ?? null)) ?></td>
                                            <td class="px-5 py-4">
                                                <div class="text-sm font-bold"><?= e($av['nomor_vendor_order'] ?? '-') ?></div>
                                                <div class="text-[10px] text-gray-400"><?= e(tgl($av['tanggal_rekap'] ?? null)) ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="text-sm font-semibold"><?= e($av['vendor_nama'] ?? '-') ?></div>
                                                <div class="text-[10px] text-gray-400"><?= e($av['vendor_wa'] ?? '-') ?></div>
                                            </td>
                                            <td class="px-5 py-4 text-center text-sm font-bold"><?= angka($av['source_count'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold"><?= angka($av['total_dipesan'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-green-600"><?= angka($av['total_diterima'] ?? 0) ?></td>
                                            <td class="px-5 py-4">
                                                <div class="text-xs font-semibold"><?= e($av['nomor_surat_jalan'] ?? '-') ?></div><?php if (!empty($av['tanggal_surat_jalan'])): ?><div class="text-[10px] text-gray-400 mt-0.5"><?= e(tgl($av['tanggal_surat_jalan'])) ?></div><?php endif; ?><?php if (!empty($av['surat_jalan_file'])): ?><a href="<?= e($av['surat_jalan_file']) ?>" target="_blank" class="text-[9px] font-black text-blue-600 underline no-print">Lihat Arsip</a><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-center"><span class="badge-gray text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(str_replace('_', ' ', (string)($av['status'] ?? '-'))) ?></span></td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-list">
                            <?php if (!$airVendor): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada rekap vendor</div><?php endif; ?>
                            <?php foreach ($airVendor as $av): ?><div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?= e($av['nomor_vendor_order'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5"><?= e($av['vendor_nama'] ?? '-') ?> · <?= e(tgl($av['tanggal_kebutuhan'] ?? null)) ?></p>
                                        </div><span class="badge-gray text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(str_replace('_', ' ', (string)($av['status'] ?? '-'))) ?></span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-subtle">
                                        <div><span class="text-gray-400 block">Dipesan</span><strong><?= angka($av['total_dipesan'] ?? 0) ?></strong></div>
                                        <div class="text-right"><span class="text-gray-400 block">Diterima</span><strong class="text-green-600"><?= angka($av['total_diterima'] ?? 0) ?></strong></div>
                                        <div><span class="text-gray-400 block">Pesanan Pelanggan</span><strong><?= angka($av['source_count'] ?? 0) ?></strong></div>
                                        <div class="text-right"><span class="text-gray-400 block">Surat Jalan</span><strong><?= e($av['nomor_surat_jalan'] ?? '-') ?></strong></div>
                                    </div>
                                </div><?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Kwitansi -->
                    <section class="bg-white border border-subtle overflow-hidden">
                        <div class="px-5 py-4 border-b border-subtle flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Kwitansi Penagihan</h2>
                                <p class="text-xs text-gray-400 mt-0.5"><?= angka(count($airKwitansi)) ?> dokumen ditemukan</p>
                            </div><a href="air_kwitansi.php" class="text-[10px] font-black uppercase tracking-widest underline no-print">Kelola</a>
                        </div>
                        <div class="tbl-desktop overflow-x-auto no-scrollbar">
                            <table class="w-full text-left" style="min-width:620px">
                                <thead class="border-b border-subtle bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Kwitansi</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Instansi</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Total</th>
                                        <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f5f5f5]">
                                    <?php if (!$airKwitansi): ?><tr>
                                            <td colspan="5" class="py-16 text-center text-[10px] font-bold uppercase tracking-widest text-gray-300">Belum ada kwitansi</td>
                                        </tr><?php endif; ?>
                                    <?php foreach ($airKwitansi as $kw): ?><tr>
                                            <td class="px-5 py-4 text-xs text-gray-500"><?= e(tgl($kw['tanggal_kwitansi'] ?? null)) ?></td>
                                            <td class="px-5 py-4 text-sm font-bold"><?= e($kw['nomor_kwitansi'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-sm"><?= e($kw['nama_instansi'] ?? '-') ?></td>
                                            <td class="px-5 py-4 text-right text-sm font-bold text-blue-600"><?= rupiah($kw['total_tagihan'] ?? 0) ?></td>
                                            <td class="px-5 py-4 text-center"><span class="badge-gray text-[9px] font-bold uppercase px-2 py-1 rounded-full"><?= e(str_replace('_', ' ', $kw['status_pembayaran'] ?? '-')) ?></span></td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-list"><?php if (!$airKwitansi): ?><div class="col-span-full py-12 text-center text-[10px] font-bold uppercase tracking-widest text-gray-400">Belum ada kwitansi</div><?php endif; ?><?php foreach ($airKwitansi as $kw): ?><div class="bg-white border border-subtle p-4 flex flex-col gap-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-bold"><?= e($kw['nomor_kwitansi'] ?? '-') ?></p>
                                            <p class="text-[10px] text-gray-400"><?= e(tgl($kw['tanggal_kwitansi'] ?? null)) ?></p>
                                        </div>
                                        <p class="text-sm font-bold text-blue-600"><?= rupiah($kw['total_tagihan'] ?? 0) ?></p>
                                    </div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Instansi</span><span class="font-semibold"><?= e($kw['nama_instansi'] ?? '-') ?></span></div>
                                    <div class="flex justify-between text-xs"><span class="text-gray-400">Status</span><span class="font-bold uppercase"><?= e(str_replace('_', ' ', $kw['status_pembayaran'] ?? '-')) ?></span></div>
                                </div><?php endforeach; ?></div>
                    </section>
                </div><!-- end panel-air -->
            <?php endif; // end isAirMineral 
            ?>

        </main>
    </div>

    <script>
        // ── Tab switching (admin only) ────────────────────────────────────────────
        var activeTab = '<?php echo $isAdmin ? "kasir" : ($isCafeOnly ? "cafe" : ($isMurniRental ? "rental" : ($isMurniKsp ? "ksp" : ($isAirOnly ? "air" : "kasir")))); ?>';

        function switchTab(tab) {
            activeTab = tab;

            // Panel visibility
            var panelKasir = document.getElementById('panel-kasir');
            var panelCafe = document.getElementById('panel-cafe');
            var panelRental = document.getElementById('panel-rental');
            var panelKsp = document.getElementById('panel-ksp');
            var panelAir = document.getElementById('panel-air');
            if (panelKasir) panelKasir.style.display = tab === 'kasir' ? '' : 'none';
            if (panelCafe) panelCafe.style.display = tab === 'cafe' ? '' : 'none';
            if (panelRental) panelRental.style.display = tab === 'rental' ? '' : 'none';
            if (panelKsp) panelKsp.style.display = tab === 'ksp' ? '' : 'none';
            if (panelAir) panelAir.style.display = tab === 'air' ? '' : 'none';

            // Tab button style
            document.querySelectorAll('.tab-btn').forEach(function(btn) {
                btn.classList.remove('border-black', 'text-black');
                btn.classList.add('border-transparent', 'text-gray-400');
            });
            var active = document.getElementById('tab-' + tab);
            if (active) {
                active.classList.remove('border-transparent', 'text-gray-400');
                active.classList.add('border-black', 'text-black');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Init tab state
            <?php if ($showTabs): ?>
                switchTab(activeTab);
            <?php else: ?>
                // Non-admin: pastikan panel yang relevan tampil, sisanya hidden
                var panelKasir = document.getElementById('panel-kasir');
                var panelCafe = document.getElementById('panel-cafe');
                var panelRental = document.getElementById('panel-rental');
                var panelKsp = document.getElementById('panel-ksp');
                var panelAir = document.getElementById('panel-air');

                if (panelKasir) panelKasir.style.display = 'none';
                if (panelCafe) panelCafe.style.display = 'none';
                if (panelRental) panelRental.style.display = 'none';
                if (panelKsp) panelKsp.style.display = 'none';
                if (panelAir) panelAir.style.display = 'none';

                <?php if ($isCafeOnly): ?>
                    if (panelCafe) panelCafe.style.display = '';
                <?php elseif ($isMurniRental): ?>
                    if (panelRental) panelRental.style.display = '';
                <?php elseif ($isMurniKsp): ?>
                    if (panelKsp) panelKsp.style.display = '';
                <?php elseif ($isAirOnly): ?>
                    if (panelAir) panelAir.style.display = '';
                <?php else: ?>
                    if (panelKasir) panelKasir.style.display = '';
                <?php endif; ?>
            <?php endif; ?>

            // Auto-switch preset ke custom saat ubah tanggal manual
            document.querySelectorAll('input[name="awal"], input[name="akhir"]').forEach(function(el) {
                el.addEventListener('change', function() {
                    document.querySelector('select[name="preset"]').value = 'custom';
                });
            });
        });


        // Pagination otomatis untuk seluruh tabel/card laporan selain Detail Transaksi
        (function() {
            var DEFAULT_LIMIT = 10;

            function sectionTitle(section) {
                var h = section.querySelector('h2');
                return h ? h.textContent.trim().toLowerCase() : '';
            }

            function collectItems(section) {
                var rows = Array.prototype.slice.call(section.querySelectorAll('.tbl-desktop tbody > tr'))
                    .filter(function(row) {
                        return !row.querySelector('td[colspan]');
                    });
                var cards = Array.prototype.slice.call(section.querySelectorAll('.card-list > div'))
                    .filter(function(card) {
                        return !card.classList.contains('table-footer-mobile') &&
                            !card.classList.contains('col-span-full');
                    });
                return {
                    rows: rows,
                    cards: cards
                };
            }

            function makeButton(label, disabled, className) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = label;
                b.disabled = !!disabled;
                if (className) b.className = className;
                return b;
            }

            function initSectionPagination(section, index) {
                var title = sectionTitle(section);
                // Detail Transaksi POS/Cafe dan Detail Pesanan Air sudah memiliki pagination server sendiri.
                if (title.indexOf('detail transaksi') !== -1 || title.indexOf('detail pesanan air mineral') !== -1) return;

                var items = collectItems(section);
                var total = Math.max(items.rows.length, items.cards.length);
                if (total <= DEFAULT_LIMIT) return;

                var state = {
                    page: 1,
                    limit: DEFAULT_LIMIT
                };
                var wrap = document.createElement('div');
                wrap.className = 'report-pagination no-print';
                wrap.setAttribute('data-pagination-index', String(index));

                var info = document.createElement('div');
                info.className = 'report-pagination-info';

                var actions = document.createElement('div');
                actions.className = 'report-pagination-actions';

                var limit = document.createElement('select');
                [10, 15, 25, 50, 100].forEach(function(n) {
                    var opt = document.createElement('option');
                    opt.value = String(n);
                    opt.textContent = n + ' / halaman';
                    limit.appendChild(opt);
                });
                actions.appendChild(limit);
                wrap.appendChild(info);
                wrap.appendChild(actions);
                section.appendChild(wrap);

                function setVisibility(list, start, end, isCard) {
                    list.forEach(function(el, i) {
                        var visible = i >= start && i < end;
                        el.classList.toggle('report-page-hidden', !visible);
                        el.style.display = visible ? '' : 'none';
                    });
                }

                function render() {
                    var pages = Math.max(1, Math.ceil(total / state.limit));
                    if (state.page > pages) state.page = pages;
                    var start = (state.page - 1) * state.limit;
                    var end = Math.min(total, start + state.limit);

                    setVisibility(items.rows, start, end, false);
                    setVisibility(items.cards, start, end, true);
                    info.textContent = 'Menampilkan ' + (total ? start + 1 : 0) + '–' + end + ' dari ' + total + ' data';

                    while (actions.children.length > 1) actions.removeChild(actions.lastChild);
                    var prev = makeButton('←', state.page <= 1);
                    prev.addEventListener('click', function() {
                        state.page--;
                        render();
                    });
                    actions.appendChild(prev);

                    var from = Math.max(1, state.page - 2);
                    var to = Math.min(pages, state.page + 2);
                    for (var p = from; p <= to; p++) {
                        (function(pageNo) {
                            var btn = makeButton(String(pageNo), false, pageNo === state.page ? 'is-active' : '');
                            btn.addEventListener('click', function() {
                                state.page = pageNo;
                                render();
                            });
                            actions.appendChild(btn);
                        })(p);
                    }

                    var next = makeButton('→', state.page >= pages);
                    next.addEventListener('click', function() {
                        state.page++;
                        render();
                    });
                    actions.appendChild(next);
                }

                limit.addEventListener('change', function() {
                    state.limit = parseInt(this.value, 10) || DEFAULT_LIMIT;
                    state.page = 1;
                    render();
                });
                render();

                window.addEventListener('beforeprint', function() {
                    items.rows.concat(items.cards).forEach(function(el) {
                        el.style.display = '';
                    });
                });
                window.addEventListener('afterprint', render);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var sections = Array.prototype.slice.call(document.querySelectorAll('.laporan-main section'));
                sections.forEach(initSectionPagination);
            });
        })();
    </script>
</body>

</html>