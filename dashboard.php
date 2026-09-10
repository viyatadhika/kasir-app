<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
require_once 'activity_helper.php';
requireAccess();

if (!function_exists('formatRp')) {
    /** @param int|float $angka */
    function formatRp($angka): string
    {
        return 'Rp ' . number_format((float)$angka, 0, ',', '.');
    }
}

if (!function_exists('dashboard_pinjaman_status_label')) {
    function dashboard_pinjaman_status_label(string $status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === '') {
            return 'Batal Pengajuan';
        }

        $map = [
            'pending' => 'Pending',
            'diseleksi' => 'Diseleksi',
            'diproses' => 'Diproses',
            'disetujui' => 'Disetujui',
            'ditolak' => 'Ditolak',
            'dibatalkan' => 'Batal Pengajuan',
            'dicairkan' => 'Dicairkan',
            'aktif' => 'Aktif',
            'berjalan' => 'Aktif',
            'lunas' => 'Lunas',
        ];

        return $map[$status] ?? ucfirst($status);
    }
}

if (!function_exists('dashboard_pinjaman_status_class')) {
    function dashboard_pinjaman_status_class(string $status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'pending') {
            return 'bg-amber-50 text-amber-700 border-amber-200';
        }

        if (in_array($status, ['diseleksi', 'diproses'], true)) {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }

        if (in_array($status, ['disetujui', 'dicairkan', 'aktif', 'berjalan', 'lunas'], true)) {
            return 'bg-green-50 text-green-700 border-green-200';
        }

        if (in_array($status, ['ditolak', 'dibatalkan', ''], true)) {
            return 'bg-red-50 text-red-700 border-red-200';
        }

        return 'bg-gray-50 text-gray-700 border-gray-200';
    }
}


if (!function_exists('air_status_label')) {
    function air_status_label(string $status): string
    {
        $map = [
            'baru' => 'Baru',
            'diproses' => 'Diproses',
            'siap_dikirim' => 'Siap Dikirim',
            'dalam_pengiriman' => 'Dalam Pengiriman',
            'selesai' => 'Selesai',
            'batal' => 'Batal',
        ];
        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('air_status_class')) {
    function air_status_class(string $status): string
    {
        $map = [
            'baru' => 'bg-blue-50 text-blue-700 border-blue-200',
            'diproses' => 'bg-amber-50 text-amber-700 border-amber-200',
            'siap_dikirim' => 'bg-purple-50 text-purple-700 border-purple-200',
            'dalam_pengiriman' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
            'selesai' => 'bg-green-50 text-green-700 border-green-200',
            'batal' => 'bg-red-50 text-red-700 border-red-200',
        ];
        return $map[$status] ?? 'bg-gray-50 text-gray-700 border-gray-200';
    }
}

if (!function_exists('dashboard_current_user_id_safe')) {
    function dashboard_current_user_id_safe(): int
    {
        foreach (['user_id', 'id_user', 'id', 'admin_id'] as $k) {
            if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
        }
        if (!empty($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
        return 0;
    }
}

if (!function_exists('dashboard_has_table')) {
    function dashboard_has_table(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
            $st->execute([':table_name' => $table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('dashboard_has_column')) {
    function dashboard_has_column(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
            $st->execute([':table_name' => $table, ':column_name' => $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('dashboard_first_column')) {
    function dashboard_first_column(PDO $pdo, string $table, array $candidates, string $fallback = ''): string
    {
        foreach ($candidates as $candidate) {
            if (dashboard_has_column($pdo, $table, $candidate)) return $candidate;
        }
        return $fallback;
    }
}

if (!function_exists('dashboard_hitung_penjualan_shift')) {
    function dashboard_hitung_penjualan_shift(PDO $pdo, string $openedAt, ?string $closedAt, int $operatorUserId): array
    {
        $data = [
            'total_sales' => 0.0,
            'total_tunai' => 0.0,
            'total_nontunai' => 0.0,
            'total_struk' => 0,
            'margin' => 0.0,
            'fee_promosi' => 0.0,
        ];

        try {
            if (!dashboard_has_table($pdo, 'transaksi')) return $data;

            $dateCol = dashboard_first_column($pdo, 'transaksi', ['created_at', 'tanggal', 'waktu', 'tgl_transaksi', 'tanggal_transaksi']);
            $totalCol = dashboard_first_column($pdo, 'transaksi', ['total', 'grand_total', 'total_bayar', 'subtotal', 'total_harga']);
            $payCol = dashboard_first_column($pdo, 'transaksi', ['metode_pembayaran', 'payment_method', 'metode_bayar', 'jenis_bayar', 'pembayaran']);
            $statusCol = dashboard_first_column($pdo, 'transaksi', ['status_transaksi', 'status']);
            $userCol = dashboard_first_column($pdo, 'transaksi', ['user_id', 'kasir_id', 'operator_id', 'created_by']);
            $promoCol = dashboard_first_column($pdo, 'transaksi', ['fee_promosi', 'biaya_promosi', 'promo_fee']);
            if ($dateCol === '' || $totalCol === '') return $data;

            $queryStart = $openedAt;
            $queryEnd = $closedAt ?: date('Y-m-d H:i:s');
            $where = ["`$dateCol` >= :start", "`$dateCol` <= :end"];
            $params = [':start' => $queryStart, ':end' => $queryEnd];

            if ($operatorUserId > 0 && $userCol !== '') {
                $where[] = "`$userCol` = :operator_user_id";
                $params[':operator_user_id'] = $operatorUserId;
            }
            if ($statusCol !== '') {
                $where[] = "LOWER(COALESCE(`$statusCol`,'')) NOT IN ('batal','cancel','cancelled','void')";
            }

            $totalExpr = "COALESCE(`$totalCol`,0)";
            if ($payCol !== '') {
                $methodExpr = "LOWER(TRIM(COALESCE(`$payCol`,'')))";
                $tunaiExpr = "SUM(CASE WHEN $methodExpr IN ('tunai','cash','uang tunai') THEN $totalExpr ELSE 0 END)";
                $nontunaiExpr = "SUM(CASE WHEN $methodExpr NOT IN ('tunai','cash','uang tunai','') THEN $totalExpr ELSE 0 END)";
            } else {
                $tunaiExpr = "SUM($totalExpr)";
                $nontunaiExpr = "0";
            }
            $promoExpr = $promoCol !== '' ? "SUM(COALESCE(`$promoCol`,0))" : "0";

            $sql = "SELECT COALESCE(SUM($totalExpr),0) AS total_sales,
                           COALESCE($tunaiExpr,0) AS total_tunai,
                           COALESCE($nontunaiExpr,0) AS total_nontunai,
                           COUNT(*) AS total_struk,
                           COALESCE($promoExpr,0) AS fee_promosi
                    FROM transaksi WHERE " . implode(' AND ', $where);
            $st = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $data['total_sales'] = (float)($row['total_sales'] ?? 0);
            $data['total_tunai'] = (float)($row['total_tunai'] ?? 0);
            $data['total_nontunai'] = (float)($row['total_nontunai'] ?? 0);
            $data['total_struk'] = (int)($row['total_struk'] ?? 0);
            $data['fee_promosi'] = (float)($row['fee_promosi'] ?? 0);

            if (dashboard_has_table($pdo, 'transaksi_detail')) {
                $detailTransCol = dashboard_first_column($pdo, 'transaksi_detail', ['transaksi_id', 'id_transaksi']);
                $detailQtyCol = dashboard_first_column($pdo, 'transaksi_detail', ['qty', 'jumlah', 'kuantitas']);
                $detailHargaCol = dashboard_first_column($pdo, 'transaksi_detail', ['harga', 'harga_jual', 'harga_satuan']);
                $detailSubtotalCol = dashboard_first_column($pdo, 'transaksi_detail', ['subtotal', 'total', 'jumlah_harga']);
                $detailBeliCol = dashboard_first_column($pdo, 'transaksi_detail', ['harga_beli', 'harga_modal', 'harga_pokok', 'modal', 'hpp']);
                $detailProdukCol = dashboard_first_column($pdo, 'transaksi_detail', ['produk_id', 'id_produk']);
                $idCol = dashboard_first_column($pdo, 'transaksi', ['id', 'transaksi_id']);

                if ($detailTransCol !== '' && $detailQtyCol !== '' && $detailHargaCol !== '' && $idCol !== '') {
                    $whereMargin = ["t.`$dateCol` >= :start", "t.`$dateCol` <= :end"];
                    if ($operatorUserId > 0 && $userCol !== '') $whereMargin[] = "t.`$userCol` = :operator_user_id";
                    if ($statusCol !== '') $whereMargin[] = "LOWER(COALESCE(t.`$statusCol`,'')) NOT IN ('batal','cancel','cancelled','void')";

                    $joinProduk = '';
                    $hargaBeliExpr = '0';
                    if ($detailBeliCol !== '') {
                        $hargaBeliExpr = "COALESCE(d.`$detailBeliCol`,0)";
                    } elseif ($detailProdukCol !== '' && dashboard_has_table($pdo, 'produk')) {
                        $produkIdCol = dashboard_first_column($pdo, 'produk', ['id', 'produk_id']);
                        $produkBeliCol = dashboard_first_column($pdo, 'produk', ['harga_beli', 'harga_modal', 'harga_pokok', 'modal', 'hpp']);
                        if ($produkIdCol !== '' && $produkBeliCol !== '') {
                            $joinProduk = " LEFT JOIN produk p ON p.`$produkIdCol` = d.`$detailProdukCol` ";
                            $hargaBeliExpr = "COALESCE(p.`$produkBeliCol`,0)";
                        }
                    }

                    $pendapatanItemExpr = $detailSubtotalCol !== ''
                        ? "COALESCE(d.`$detailSubtotalCol`,0)"
                        : "COALESCE(d.`$detailHargaCol`,0)*COALESCE(d.`$detailQtyCol`,0)";
                    $sqlMargin = "SELECT COALESCE(SUM($pendapatanItemExpr-($hargaBeliExpr*COALESCE(d.`$detailQtyCol`,0))),0)
                                  FROM transaksi_detail d
                                  JOIN transaksi t ON t.`$idCol` = d.`$detailTransCol`
                                  $joinProduk
                                  WHERE " . implode(' AND ', $whereMargin);
                    $stm = $pdo->prepare($sqlMargin);
                    foreach ($params as $key => $value) {
                        $stm->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                    }
                    $stm->execute();
                    $data['margin'] = (float)$stm->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            error_log('DASHBOARD HITUNG KAS ERROR: ' . $e->getMessage());
        }

        return $data;
    }
}


$activeMenu  = 'dashboard';
$pageTitle   = 'Dashboard';
$backUrl     = '';
$currentRole = getCurrentRole(); // 'admin' | 'kasir' | 'cafe' | 'air_mineral' | 'rental' | 'ksp'
$today       = date('Y-m-d');
$userId      = dashboard_current_user_id_safe();
$isCafeRole  = has_role('cafe');

// ── Monitoring Kas Harian ───────────────────────────────────────────────────
$kasHariIni = [
    'total_operator' => 0,
    'sudah_buka'     => 0,
    'belum_buka'     => 0,
    'masih_buka'     => 0,
    'sudah_tutup'    => 0,
    'terlambat_tutup' => 0,
    'kas_awal'       => 0,
    'total_sales'    => 0,
    'total_tunai'    => 0,
    'total_nontunai' => 0,
    'total_struk'    => 0,
    'margin'         => 0,
    'kas_akhir'      => 0,
    'kas_aktual'     => 0,
    'selisih'        => 0,
    'opened_at'      => null,
    'closed_at'      => null,
    'status'         => null,
];
$kasOperatorList = [];


// ── Inisialisasi variabel (mencegah undefined variable warning) ───────────────
$ringkasan          = ['total_sales' => 0, 'jumlah_struk' => 0];
$avgStruk           = 0;
$produkStokLimit    = [];
$produkExpiredSummary = [
    'expired' => 0,
    'h7'      => 0,
    'h30'     => 0,
];
$produkExpiredList  = [];
$produkExpiredError = '';
$strukTerakhir      = [];
$fastMoving         = [];
$chartLabels        = [];
$chartData          = [];

// Dashboard khusus Cafe
$cafeSummary = [
    'total_sales' => 0,
    'jumlah_struk' => 0,
    'tunai' => 0,
    'nontunai' => 0,
    'avg_struk' => 0,
];
$cafeStatusPesanan = ['baru' => 0, 'diproses' => 0, 'siap' => 0, 'selesai' => 0];
$cafeMeja = ['total' => 0, 'kosong' => 0, 'terisi' => 0, 'reservasi' => 0];
$cafePesananTerakhir = [];
$cafeMenuTerlaris = [];
$cafeStokMenipis = [];

// Dashboard khusus Air Mineral
$airSummary = [
    'total' => 0,
    'baru' => 0,
    'diproses' => 0,
    'siap_dikirim' => 0,
    'dalam_pengiriman' => 0,
    'selesai' => 0,
    'batal' => 0,
    'total_unit' => 0,
    'total_lokasi' => 0,
];
$airPesananTerbaru = [];
$airRekapProduk = [];
$airRekapLokasi = [];
$airDashboardError = '';

$ringkasanRental    = ['total_order' => 0, 'total_pendapatan' => 0];
$orderRentalTerbaru = [];
$totalDriver        = 0;

// ── Inisialisasi variabel KSP / Simpan Pinjam ───────────────────────────────
$kspPengajuanSummary = [
    'pending' => 0,
    'diseleksi' => 0,
    'disetujui' => 0,
    'ditolak' => 0,
    'dibatalkan' => 0,
    'dicairkan' => 0,
];
$kspTotalPengajuanNilai = 0;
$kspPinjamanAktif = ['total' => 0, 'pokok' => 0, 'angsuran_bulanan' => 0];
$kspAngsuranJatuhTempo = ['total' => 0, 'nilai' => 0];
$kspPengajuanTerbaru = [];
$kspKonfig = ['bunga_uang' => 0, 'bunga_barang' => 0, 'tenor_maks_uang' => 0, 'tenor_maks_barang' => 0];

// ── API: Direct Thermal Reprint ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'reprint_data') {
    header('Content-Type: application/json; charset=utf-8');

    // Admin, kasir toko, dan kasir cafe boleh reprint
    if (!has_role('admin', 'kasir', 'cafe')) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
        exit;
    }

    $id      = isset($_GET['id'])      ? (int)$_GET['id']              : 0;
    $invoice = isset($_GET['invoice']) ? trim((string)$_GET['invoice']) : '';

    if ($id <= 0 && $invoice === '') {
        echo json_encode(['success' => false, 'message' => 'ID / invoice kosong.']);
        exit;
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT t.*, u.nama AS kasir,
                       m.nama  AS member_nama,
                       m.kode  AS member_kode,
                       m.point AS member_point_total
                FROM transaksi t
                LEFT JOIN users  u ON t.user_id  = u.id
                LEFT JOIN member m ON t.member_id = m.id
                WHERE t.id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT t.*, u.nama AS kasir,
                       m.nama  AS member_nama,
                       m.kode  AS member_kode,
                       m.point AS member_point_total
                FROM transaksi t
                LEFT JOIN users  u ON t.user_id  = u.id
                LEFT JOIN member m ON t.member_id = m.id
                WHERE t.invoice = :invoice LIMIT 1
            ");
            $stmt->execute([':invoice' => $invoice]);
        }

        $trx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trx) {
            echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan.']);
            exit;
        }

        $stmtDetail = $pdo->prepare("
            SELECT * FROM transaksi_detail
            WHERE transaksi_id = :tid ORDER BY id ASC
        ");
        $stmtDetail->execute([':tid' => (int)$trx['id']]);
        $details = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

        $items          = [];
        $subtotalNormal = 0;
        $diskonBarang   = 0;

        foreach ($details as $d) {
            $qty          = (int)($d['qty'] ?? 0);
            $hargaNormal  = (int)(($d['harga_normal'] ?? null) !== null ? $d['harga_normal'] : ($d['harga'] ?? 0));
            $hargaFinal   = (int)($d['harga'] ?? $hargaNormal);
            $subtotalItem = (int)($d['subtotal'] ?? ($hargaFinal * $qty));
            $diskonItem   = isset($d['diskon']) ? (int)$d['diskon'] : max(0, ($hargaNormal * $qty) - $subtotalItem);

            $subtotalNormal += $hargaNormal * $qty;
            $diskonBarang   += $diskonItem;

            $items[] = [
                'nama'          => strtoupper((string)($d['nama'] ?? 'PRODUK')),
                'qty'           => $qty,
                'harga_normal'  => $hargaNormal,
                'normal_item'   => $hargaNormal * $qty,
                'subtotal_item' => $subtotalItem,
                'diskon_item'   => $diskonItem,
                'diskon_satuan' => $qty > 0 ? (int)round($diskonItem / $qty) : $diskonItem,
                'nama_diskon'   => '',
            ];
        }

        $totalDiskon     = (int)($trx['diskon'] ?? 0);
        if ($totalDiskon <= 0) $totalDiskon = $diskonBarang;
        $diskonTransaksi = max(0, $totalDiskon - $diskonBarang);

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'                 => (int)$trx['id'],
                'invoice'            => (string)$trx['invoice'],
                'tanggal'            => date('d/m/Y H:i:s', strtotime($trx['created_at'] ?? 'now')),
                'operator'           => (string)($trx['kasir'] ?? ($_SESSION['nama'] ?? 'Kasir')),
                'member_nama'        => (string)($trx['member_nama'] ?? ''),
                'member_kode'        => (string)($trx['member_kode'] ?? ''),
                'member_point_total' => (int)($trx['member_point_total'] ?? 0),
                'items'              => $items,
                'subtotal_normal'    => $subtotalNormal,
                'diskon_barang'      => $diskonBarang,
                'diskon_transaksi'   => $diskonTransaksi,
                'total_diskon'       => $totalDiskon,
                'total_bayar'        => (int)($trx['total'] ?? 0),
                'bayar'              => (int)($trx['bayar'] ?? 0),
                'kembalian'          => (int)($trx['kembalian'] ?? 0),
                'point_dapat'        => (int)($trx['point_dapat'] ?? 0),
                'point_dipakai'      => (int)($trx['point_pakai'] ?? 0),
                'nilai_point'        => (int)($trx['nilai_point_pakai'] ?? 0),
                'nama_diskon_trx'    => '',
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


// ── Data: Monitoring Kas Harian (sinkron dengan kas_harian.php) ─────────────
if (has_role('admin', 'kasir', 'cafe')) {
    try {
        /*
         * Dibaca dengan query sederhana agar kompatibel dengan MariaDB/XAMPP.
         * Seluruh pengelompokan dilakukan di PHP sehingga satu query bermasalah
         * tidak membuat semua KPI monitoring menjadi nol.
         */
        $stmtSemuaKas = $pdo->query("SELECT * FROM kas_harian ORDER BY id DESC");
        $semuaKas = $stmtSemuaKas->fetchAll(PDO::FETCH_ASSOC);

        if (has_role('admin')) {
            $aktifPerOperator = [];
            $tutupHariIniPerOperator = [];

            foreach ($semuaKas as $rowKas) {
                $statusKas = strtolower(trim((string)($rowKas['status'] ?? '')));
                $uidKas = (int)($rowKas['user_id'] ?? 0);
                $operatorKas = trim((string)($rowKas['operator'] ?? ''));
                $operatorKey = $uidKas > 0 ? ('u-' . $uidKas) : ('n-' . strtolower($operatorKas));

                $openedRaw = (string)($rowKas['opened_at'] ?? '');
                $openedTs = $openedRaw !== '' ? strtotime($openedRaw) : false;
                $tanggalSesi = trim((string)($rowKas['tanggal'] ?? ''));
                if ($tanggalSesi === '' && $openedTs !== false) {
                    $tanggalSesi = date('Y-m-d', $openedTs);
                }

                $rowKas['durasi_detik'] = $openedTs !== false ? max(0, time() - $openedTs) : 0;
                $rowKas['terlambat_tutup'] = (
                    $statusKas === 'buka'
                    && $tanggalSesi !== ''
                    && $tanggalSesi < $today
                );

                if ($statusKas === 'buka') {
                    // Karena data diurutkan id DESC, ambil sesi aktif terbaru per operator.
                    if (!isset($aktifPerOperator[$operatorKey])) {
                        $aktifPerOperator[$operatorKey] = $rowKas;
                    }
                    continue;
                }

                if ($statusKas === 'tutup') {
                    $closedRaw = (string)($rowKas['closed_at'] ?? '');
                    $closedTs = $closedRaw !== '' ? strtotime($closedRaw) : false;
                    $tanggalTutup = $closedTs !== false
                        ? date('Y-m-d', $closedTs)
                        : $tanggalSesi;

                    if ($tanggalTutup === $today && !isset($tutupHariIniPerOperator[$operatorKey])) {
                        $tutupHariIniPerOperator[$operatorKey] = $rowKas;
                    }
                }
            }

            // Sesi aktif selalu diprioritaskan. Riwayat tutup hari ini hanya ditambahkan
            // jika operator tersebut tidak memiliki sesi aktif.
            $kasOperatorList = array_values($aktifPerOperator);
            foreach ($tutupHariIniPerOperator as $operatorKey => $rowTutup) {
                if (!isset($aktifPerOperator[$operatorKey])) {
                    $kasOperatorList[] = $rowTutup;
                }
            }

            usort($kasOperatorList, function ($a, $b) {
                $aOpen = strtolower((string)($a['status'] ?? '')) === 'buka';
                $bOpen = strtolower((string)($b['status'] ?? '')) === 'buka';
                if ($aOpen !== $bOpen) return $aOpen ? -1 : 1;
                return strcmp((string)($b['opened_at'] ?? ''), (string)($a['opened_at'] ?? ''));
            });

            $kasHariIni['total_operator'] = count($kasOperatorList);
            foreach ($kasOperatorList as $k) {
                $hitungKas = dashboard_hitung_penjualan_shift(
                    $pdo,
                    (string)($k['opened_at'] ?? ''),
                    !empty($k['closed_at']) ? (string)$k['closed_at'] : null,
                    (int)($k['user_id'] ?? 0)
                );
                $k['total_sales'] = $hitungKas['total_sales'];
                $k['total_tunai'] = $hitungKas['total_tunai'];
                $k['total_nontunai'] = $hitungKas['total_nontunai'];
                $k['total_struk'] = $hitungKas['total_struk'];
                $k['margin'] = $hitungKas['margin'];
                $k['kas_akhir_sistem'] = (float)($k['kas_awal'] ?? 0) + (float)$hitungKas['total_sales'];
                $k['kas_aktual'] = $k['kas_akhir_sistem'];
                $statusKas = strtolower(trim((string)($k['status'] ?? '')));
                $kasHariIni['kas_awal'] += (float)($k['kas_awal'] ?? 0);
                $kasHariIni['total_sales'] += (float)($k['total_sales'] ?? 0);
                $kasHariIni['total_tunai'] += (float)($k['total_tunai'] ?? 0);
                $kasHariIni['total_nontunai'] += (float)($k['total_nontunai'] ?? 0);
                $kasHariIni['total_struk'] += (int)($k['total_struk'] ?? 0);
                $kasHariIni['margin'] += (float)($k['margin'] ?? 0);
                $kasHariIni['kas_akhir'] += (float)($k['kas_akhir_sistem'] ?? 0);
                $aktualKas = (float)($k['kas_aktual'] ?? ($k['kas_akhir_sistem'] ?? 0));
                $kasHariIni['kas_aktual'] += $aktualKas;
                $kasHariIni['selisih'] += $aktualKas - (float)($k['kas_akhir_sistem'] ?? 0);

                if ($statusKas === 'buka') {
                    $kasHariIni['sudah_buka']++;
                    $kasHariIni['masih_buka']++;
                    if (!empty($k['terlambat_tutup'])) {
                        $kasHariIni['terlambat_tutup']++;
                    }
                } elseif ($statusKas === 'tutup') {
                    $kasHariIni['sudah_buka']++;
                    $kasHariIni['sudah_tutup']++;
                }
            }
        } else {
            $kasSaya = null;
            $kasHariIniTerakhir = null;

            foreach ($semuaKas as $rowKas) {
                if ((int)($rowKas['user_id'] ?? 0) !== $userId) continue;

                $statusKas = strtolower(trim((string)($rowKas['status'] ?? '')));
                if ($statusKas === 'buka' && $kasSaya === null) {
                    $kasSaya = $rowKas;
                    break;
                }

                $tanggalRow = trim((string)($rowKas['tanggal'] ?? ''));
                if ($kasHariIniTerakhir === null && $tanggalRow === $today) {
                    $kasHariIniTerakhir = $rowKas;
                }
            }

            if (!$kasSaya) $kasSaya = $kasHariIniTerakhir;

            if ($kasSaya) {
                $hitungKasSaya = dashboard_hitung_penjualan_shift(
                    $pdo,
                    (string)($kasSaya['opened_at'] ?? ''),
                    !empty($kasSaya['closed_at']) ? (string)$kasSaya['closed_at'] : null,
                    (int)($kasSaya['user_id'] ?? 0)
                );
                $kasSaya['total_sales'] = $hitungKasSaya['total_sales'];
                $kasSaya['total_tunai'] = $hitungKasSaya['total_tunai'];
                $kasSaya['total_nontunai'] = $hitungKasSaya['total_nontunai'];
                $kasSaya['total_struk'] = $hitungKasSaya['total_struk'];
                $kasSaya['margin'] = $hitungKasSaya['margin'];
                $kasSaya['kas_akhir_sistem'] = (float)($kasSaya['kas_awal'] ?? 0) + (float)$hitungKasSaya['total_sales'];
                $kasSaya['kas_aktual'] = $kasSaya['kas_akhir_sistem'];
                $statusKas = strtolower(trim((string)($kasSaya['status'] ?? '')));
                $openedRaw = (string)($kasSaya['opened_at'] ?? '');
                $openedTs = $openedRaw !== '' ? strtotime($openedRaw) : false;
                $tanggalSesi = trim((string)($kasSaya['tanggal'] ?? ''));
                if ($tanggalSesi === '' && $openedTs !== false) {
                    $tanggalSesi = date('Y-m-d', $openedTs);
                }

                $kasHariIni['total_operator'] = 1;
                $kasHariIni['sudah_buka'] = 1;
                $kasHariIni['status'] = (string)($kasSaya['status'] ?? '');
                $kasHariIni['kas_awal'] = (float)($kasSaya['kas_awal'] ?? 0);
                $kasHariIni['total_sales'] = (float)($kasSaya['total_sales'] ?? 0);
                $kasHariIni['total_tunai'] = (float)($kasSaya['total_tunai'] ?? 0);
                $kasHariIni['total_nontunai'] = (float)($kasSaya['total_nontunai'] ?? 0);
                $kasHariIni['total_struk'] = (int)($kasSaya['total_struk'] ?? 0);
                $kasHariIni['margin'] = (float)($kasSaya['margin'] ?? 0);
                $kasHariIni['kas_akhir'] = (float)($kasSaya['kas_akhir_sistem'] ?? 0);
                $kasHariIni['kas_aktual'] = (float)($kasSaya['kas_aktual'] ?? ($kasSaya['kas_akhir_sistem'] ?? 0));
                $kasHariIni['selisih'] = $kasHariIni['kas_aktual'] - $kasHariIni['kas_akhir'];
                $kasHariIni['opened_at'] = $kasSaya['opened_at'] ?? null;
                $kasHariIni['closed_at'] = $kasSaya['closed_at'] ?? null;

                if ($statusKas === 'buka') {
                    $kasHariIni['masih_buka'] = 1;
                    $kasHariIni['durasi_detik'] = $openedTs !== false ? max(0, time() - $openedTs) : 0;
                    $kasHariIni['terlambat_tutup'] = (
                        $tanggalSesi !== ''
                        && $tanggalSesi < $today
                    ) ? 1 : 0;
                } elseif ($statusKas === 'tutup') {
                    $kasHariIni['sudah_tutup'] = 1;
                }
            } else {
                $kasHariIni['belum_buka'] = 1;
            }
        }
    } catch (Throwable $e) {
        $kasOperatorList = [];
        $kasHariIni['monitor_error'] = $e->getMessage();
        error_log('DASHBOARD MONITOR KAS ERROR: ' . $e->getMessage());
    }
}

// ── Data: Admin & Kasir ──────────────────────────────────────────────────────
if (has_role('admin', 'kasir')) {

    $stmtSales = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) AS total_sales, COUNT(*) AS jumlah_struk
        FROM transaksi WHERE DATE(created_at) = :today
    ");
    $stmtSales->execute([':today' => $today]);
    $ringkasan = $stmtSales->fetch();

    $avgStruk = $ringkasan['jumlah_struk'] > 0
        ? (int)($ringkasan['total_sales'] / $ringkasan['jumlah_struk'])
        : 0;

    $stmtStok = $pdo->query("
        SELECT id, nama, stok, stok_minimum, kategori
        FROM produk
        WHERE stok <= stok_minimum AND status = 'aktif'
        ORDER BY (stok / stok_minimum) ASC
    ");
    $produkStokLimit = $stmtStok->fetchAll();

    // Monitoring tanggal kedaluwarsa produk.
    // Dibungkus try/catch agar dashboard tetap aman apabila kolom belum tersedia.
    try {
        $stmtExpiredSummary = $pdo->query("
            SELECT
                SUM(CASE
                    WHEN expired_date IS NOT NULL
                     AND expired_date < CURDATE()
                    THEN 1 ELSE 0 END) AS expired,
                SUM(CASE
                    WHEN expired_date IS NOT NULL
                     AND expired_date >= CURDATE()
                     AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    THEN 1 ELSE 0 END) AS h7,
                SUM(CASE
                    WHEN expired_date IS NOT NULL
                     AND expired_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    THEN 1 ELSE 0 END) AS h30
            FROM produk
            WHERE status = 'aktif'
        ");
        $expiredRow = $stmtExpiredSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        $produkExpiredSummary['expired'] = (int)($expiredRow['expired'] ?? 0);
        $produkExpiredSummary['h7']      = (int)($expiredRow['h7'] ?? 0);
        $produkExpiredSummary['h30']     = (int)($expiredRow['h30'] ?? 0);

        $stmtExpiredList = $pdo->query("
            SELECT id, kode, nama, kategori, stok, satuan, expired_date
            FROM produk
            WHERE status = 'aktif'
              AND expired_date IS NOT NULL
              AND expired_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY
                CASE WHEN expired_date < CURDATE() THEN 0 ELSE 1 END ASC,
                expired_date ASC,
                nama ASC
            LIMIT 8
        ");
        $produkExpiredList = $stmtExpiredList->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $produkExpiredSummary = ['expired' => 0, 'h7' => 0, 'h30' => 0];
        $produkExpiredList = [];
        $produkExpiredError = $e->getMessage();
        error_log('DASHBOARD EXPIRED PRODUCT ERROR: ' . $e->getMessage());
    }

    $stmtStruk = $pdo->query("
        SELECT t.id, t.invoice, t.total, t.bayar, t.kembalian, t.created_at,
               u.nama AS kasir
        FROM transaksi t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC LIMIT 5
    ");
    $strukTerakhir = $stmtStruk->fetchAll();

    $stmtFast = $pdo->prepare("
        SELECT td.nama, SUM(td.qty) AS total_qty
        FROM transaksi_detail td
        JOIN transaksi t ON td.transaksi_id = t.id
        WHERE DATE(t.created_at) = :today
        GROUP BY td.produk_id, td.nama
        ORDER BY total_qty DESC LIMIT 5
    ");
    $stmtFast->execute([':today' => $today]);
    $fastMoving = $stmtFast->fetchAll();

    $stmtJam = $pdo->prepare("
        SELECT HOUR(created_at) AS jam, COALESCE(SUM(total), 0) AS sales
        FROM transaksi WHERE DATE(created_at) = :today
        GROUP BY HOUR(created_at) ORDER BY jam ASC
    ");
    $stmtJam->execute([':today' => $today]);
    $salesPerJam = $stmtJam->fetchAll();

    $chartLabels = [];
    $chartData   = [];
    for ($h = 6; $h <= 22; $h++) {
        $chartLabels[] = str_pad($h, 2, '0', STR_PAD_LEFT);
        $found = false;
        foreach ($salesPerJam as $row) {
            if ((int)$row['jam'] === $h) {
                $chartData[] = round($row['sales'] / 1000000, 2);
                $found = true;
                break;
            }
        }
        if (!$found) $chartData[] = 0;
    }
}

// ── Data: Dashboard khusus Kasir Cafe ───────────────────────────────────────
if (has_role('cafe')) {
    try {
        $sourceFilter = dashboard_has_column($pdo, 'transaksi', 'sumber_transaksi')
            ? " AND LOWER(COALESCE(t.sumber_transaksi,'')) = 'cafe'"
            : " AND 1=0";

        $paymentCol = dashboard_first_column($pdo, 'transaksi', ['metode_pembayaran', 'payment_method', 'metode_bayar', 'jenis_bayar', 'pembayaran']);
        $paymentExpr = $paymentCol !== '' ? "LOWER(TRIM(COALESCE(t.`$paymentCol`,'')))" : "'tunai'";

        $stmtCafeSummary = $pdo->prepare("\n            SELECT\n                COALESCE(SUM(t.total),0) AS total_sales,\n                COUNT(*) AS jumlah_struk,\n                COALESCE(SUM(CASE WHEN $paymentExpr IN ('tunai','cash','uang tunai') THEN t.total ELSE 0 END),0) AS tunai,\n                COALESCE(SUM(CASE WHEN $paymentExpr NOT IN ('tunai','cash','uang tunai','') THEN t.total ELSE 0 END),0) AS nontunai\n            FROM transaksi t\n            WHERE DATE(t.created_at) = :today $sourceFilter\n        ");
        $stmtCafeSummary->execute([':today' => $today]);
        $cafeSummary = array_merge($cafeSummary, $stmtCafeSummary->fetch(PDO::FETCH_ASSOC) ?: []);
        $cafeSummary['avg_struk'] = (int)$cafeSummary['jumlah_struk'] > 0
            ? (float)$cafeSummary['total_sales'] / (int)$cafeSummary['jumlah_struk']
            : 0;

        if (dashboard_has_table($pdo, 'cafe_pesanan')) {
            $stmtCafeStatus = $pdo->prepare("\n                SELECT status, COUNT(*) AS total\n                FROM cafe_pesanan\n                WHERE DATE(created_at) = :today\n                GROUP BY status\n            ");
            $stmtCafeStatus->execute([':today' => $today]);
            foreach ($stmtCafeStatus->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $st = strtolower((string)($row['status'] ?? ''));
                if (array_key_exists($st, $cafeStatusPesanan)) {
                    $cafeStatusPesanan[$st] = (int)$row['total'];
                }
            }
        }

        if (dashboard_has_table($pdo, 'cafe_meja')) {
            $stmtCafeMeja = $pdo->query("\n                SELECT status, COUNT(*) AS total\n                FROM cafe_meja\n                WHERE status <> 'nonaktif'\n                GROUP BY status\n            ");
            foreach ($stmtCafeMeja->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $st = strtolower((string)($row['status'] ?? ''));
                $cafeMeja['total'] += (int)$row['total'];
                if (array_key_exists($st, $cafeMeja)) $cafeMeja[$st] = (int)$row['total'];
            }
        }

        $joinPesanan = dashboard_has_table($pdo, 'cafe_pesanan') ? " LEFT JOIN cafe_pesanan cp ON cp.transaksi_id = t.id " : '';
        $joinMeja = dashboard_has_table($pdo, 'cafe_meja') && dashboard_has_table($pdo, 'cafe_pesanan') ? " LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id " : '';
        $selectPesanan = dashboard_has_table($pdo, 'cafe_pesanan')
            ? "cp.nomor_pesanan, cp.tipe_pesanan, cp.status AS status_pesanan"
            : "NULL AS nomor_pesanan, NULL AS tipe_pesanan, NULL AS status_pesanan";
        $selectMeja = $joinMeja !== '' ? "cm.nomor_meja" : "NULL AS nomor_meja";

        $stmtCafeLast = $pdo->prepare("\n            SELECT t.id, t.invoice, t.total, t.created_at, u.nama AS kasir,\n                   $selectPesanan, $selectMeja\n            FROM transaksi t\n            LEFT JOIN users u ON u.id = t.user_id\n            $joinPesanan\n            $joinMeja\n            WHERE DATE(t.created_at) = :today $sourceFilter\n            ORDER BY t.created_at DESC, t.id DESC\n            LIMIT 6\n        ");
        $stmtCafeLast->execute([':today' => $today]);
        $cafePesananTerakhir = $stmtCafeLast->fetchAll(PDO::FETCH_ASSOC);

        $stmtCafeTop = $pdo->prepare("\n            SELECT td.nama, SUM(td.qty) AS total_qty, SUM(td.subtotal) AS omzet\n            FROM transaksi_detail td\n            JOIN transaksi t ON t.id = td.transaksi_id\n            WHERE DATE(t.created_at) = :today $sourceFilter\n            GROUP BY td.produk_id, td.nama\n            ORDER BY total_qty DESC, omzet DESC\n            LIMIT 6\n        ");
        $stmtCafeTop->execute([':today' => $today]);
        $cafeMenuTerlaris = $stmtCafeTop->fetchAll(PDO::FETCH_ASSOC);

        if (dashboard_has_column($pdo, 'produk', 'tipe_produk')) {
            $stmtCafeStok = $pdo->query("\n                SELECT id, nama, stok, stok_minimum, kategori\n                FROM produk\n                WHERE LOWER(COALESCE(tipe_produk,'')) = 'cafe'\n                  AND status = 'aktif'\n                  AND stok <= stok_minimum\n                ORDER BY stok ASC, nama ASC\n                LIMIT 6\n            ");
            $cafeStokMenipis = $stmtCafeStok->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmtCafeJam = $pdo->prepare("\n            SELECT HOUR(t.created_at) AS jam, COALESCE(SUM(t.total),0) AS sales\n            FROM transaksi t\n            WHERE DATE(t.created_at) = :today $sourceFilter\n            GROUP BY HOUR(t.created_at)\n            ORDER BY jam ASC\n        ");
        $stmtCafeJam->execute([':today' => $today]);
        $salesPerJam = $stmtCafeJam->fetchAll(PDO::FETCH_ASSOC);
        $chartLabels = [];
        $chartData = [];
        for ($h = 6; $h <= 22; $h++) {
            $chartLabels[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $nilai = 0;
            foreach ($salesPerJam as $row) {
                if ((int)$row['jam'] === $h) {
                    $nilai = (float)$row['sales'];
                    break;
                }
            }
            $chartData[] = round($nilai / 1000000, 2);
        }
    } catch (Throwable $e) {
        error_log('DASHBOARD CAFE ERROR: ' . $e->getMessage());
    }
}

// ── Data: Dashboard Air Mineral ──────────────────────────────────────────────
if (has_role('air_mineral', 'admin')) {
    try {
        if (dashboard_has_table($pdo, 'air_pesanan')) {
            $tanggalPesanExpr = dashboard_has_column($pdo, 'air_pesanan', 'tanggal_pemesanan')
                ? "COALESCE(p.tanggal_pemesanan, DATE(p.created_at))"
                : "DATE(p.created_at)";

            $stmtAirSummary = $pdo->query("\n                SELECT\n                    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.status,''))) <> 'batal' THEN 1 ELSE 0 END),0) AS total,\n                    COALESCE(SUM(CASE WHEN p.status = 'baru' THEN 1 ELSE 0 END),0) AS baru,\n                    COALESCE(SUM(CASE WHEN p.status = 'diproses' THEN 1 ELSE 0 END),0) AS diproses,\n                    COALESCE(SUM(CASE WHEN p.status = 'siap_dikirim' THEN 1 ELSE 0 END),0) AS siap_dikirim,\n                    COALESCE(SUM(CASE WHEN p.status = 'dalam_pengiriman' THEN 1 ELSE 0 END),0) AS dalam_pengiriman,\n                    COALESCE(SUM(CASE WHEN p.status = 'selesai' THEN 1 ELSE 0 END),0) AS selesai,\n                    COALESCE(SUM(CASE WHEN p.status = 'batal' THEN 1 ELSE 0 END),0) AS batal,\n                    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.status,''))) <> 'batal' THEN (SELECT COALESCE(SUM(d.qty),0) FROM air_pesanan_detail d WHERE d.pesanan_id = p.id) ELSE 0 END),0) AS total_unit,\n                    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.status,''))) <> 'batal' THEN (SELECT COUNT(*) FROM air_pesanan_lokasi l WHERE l.pesanan_id = p.id) ELSE 0 END),0) AS total_lokasi\n                FROM air_pesanan p\n            ");
            $airSummary = array_merge($airSummary, $stmtAirSummary->fetch(PDO::FETCH_ASSOC) ?: []);

            $stmtAirLatest = $pdo->query("\n                SELECT p.id, p.nomor_pesanan, p.status, p.tanggal_pemesanan, p.tanggal_kirim, p.created_at,\n                       c.nama AS nama_pemesan, c.no_hp,\n                       COALESCE((SELECT SUM(d.qty) FROM air_pesanan_detail d WHERE d.pesanan_id = p.id),0) AS total_unit,\n                       COALESCE((SELECT COUNT(*) FROM air_pesanan_lokasi l WHERE l.pesanan_id = p.id),0) AS total_lokasi,\n                       (SELECT GROUP_CONCAT(l.lokasi ORDER BY l.urutan, l.id SEPARATOR ' • ') FROM air_pesanan_lokasi l WHERE l.pesanan_id = p.id) AS daftar_lokasi\n                FROM air_pesanan p\n                JOIN air_pelanggan c ON c.id = p.pelanggan_id\n                ORDER BY FIELD(p.status,'baru','diproses','siap_dikirim','dalam_pengiriman','selesai','batal'), p.id DESC\n                LIMIT 8\n            ");
            $airPesananTerbaru = $stmtAirLatest->fetchAll(PDO::FETCH_ASSOC);

            if (dashboard_has_table($pdo, 'air_pesanan_detail')) {
                $stmtAirProduk = $pdo->query("\n                    SELECT d.nama_produk, COALESCE(SUM(d.qty),0) AS total_qty\n                    FROM air_pesanan_detail d\n                    JOIN air_pesanan p ON p.id = d.pesanan_id\n                    WHERE p.status <> 'batal'\n                    GROUP BY d.nama_produk\n                    ORDER BY total_qty DESC, d.nama_produk ASC\n                    LIMIT 6\n                ");
                $airRekapProduk = $stmtAirProduk->fetchAll(PDO::FETCH_ASSOC);
            }

            if (dashboard_has_table($pdo, 'air_pesanan_lokasi') && dashboard_has_table($pdo, 'air_pesanan_detail')) {
                $stmtAirLokasi = $pdo->query("\n                    SELECT l.lokasi, COALESCE(SUM(d.qty),0) AS total_qty, COUNT(DISTINCT l.pesanan_id) AS total_pesanan\n                    FROM air_pesanan_lokasi l\n                    JOIN air_pesanan p ON p.id = l.pesanan_id\n                    LEFT JOIN air_pesanan_detail d ON d.lokasi_id = l.id\n                    WHERE p.status <> 'batal'\n                    GROUP BY l.lokasi\n                    ORDER BY total_qty DESC, l.lokasi ASC\n                    LIMIT 8\n                ");
                $airRekapLokasi = $stmtAirLokasi->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $airDashboardError = $e->getMessage();
        error_log('DASHBOARD AIR MINERAL ERROR: ' . $e->getMessage());
    }
}

// ── Data: Rental ─────────────────────────────────────────────────────────────
if (has_role('rental', 'admin')) {

    // Jumlah order rental hari ini
    // Sesuaikan nama tabel/kolom dengan skema database Anda
    try {
        $stmtRental = $pdo->prepare("
            SELECT COUNT(*) AS total_order,
                   COALESCE(SUM(total_bayar), 0) AS total_pendapatan
            FROM rental_bandara
            WHERE DATE(created_at) = :today
        ");
        $stmtRental->execute([':today' => $today]);
        $ringkasanRental = $stmtRental->fetch();
    } catch (Exception $e) {
        $ringkasanRental = ['total_order' => 0, 'total_pendapatan' => 0];
    }

    // Order rental terbaru
    try {
        $stmtOrderRental = $pdo->prepare("
            SELECT r.*, d.nama AS nama_driver
            FROM rental_bandara r
            LEFT JOIN driver d ON r.driver_id = d.id
            ORDER BY r.created_at DESC LIMIT 5
        ");
        $stmtOrderRental->execute();
        $orderRentalTerbaru = $stmtOrderRental->fetchAll();
    } catch (Exception $e) {
        $orderRentalTerbaru = [];
    }

    // Jumlah driver aktif
    try {
        $stmtDriver = $pdo->query("SELECT COUNT(*) AS total FROM driver WHERE status = 'aktif'");
        $totalDriver = (int)$stmtDriver->fetchColumn();
    } catch (Exception $e) {
        $totalDriver = 0;
    }
}


// ── Data: KSP / Simpan Pinjam ───────────────────────────────────────────────
if (has_role('ksp', 'admin')) {
    try {
        $stmtKspStatus = $pdo->query("
            SELECT status, COUNT(*) AS total, COALESCE(SUM(jumlah), 0) AS nilai
            FROM pengajuan_pinjaman
            GROUP BY status
        ");
        foreach ($stmtKspStatus->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $st = strtolower(trim((string)($row['status'] ?? '')));
            if ($st === '') {
                $st = 'dibatalkan';
            }

            if (array_key_exists($st, $kspPengajuanSummary)) {
                $kspPengajuanSummary[$st] = (int)$row['total'];
            }

            $kspTotalPengajuanNilai += (float)($row['nilai'] ?? 0);
        }
    } catch (Throwable $e) {
        $kspPengajuanSummary = ['pending' => 0, 'diseleksi' => 0, 'disetujui' => 0, 'ditolak' => 0, 'dibatalkan' => 0, 'dicairkan' => 0];
        $kspTotalPengajuanNilai = 0;
    }

    try {
        $stmtKspAktif = $pdo->query("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(pokok), 0) AS pokok,
                   COALESCE(SUM(angsuran_total), 0) AS angsuran_bulanan
            FROM pinjaman
            WHERE status IN ('aktif', 'berjalan')
        ");
        $kspPinjamanAktif = $stmtKspAktif->fetch(PDO::FETCH_ASSOC) ?: $kspPinjamanAktif;
    } catch (Throwable $e) {
        $kspPinjamanAktif = ['total' => 0, 'pokok' => 0, 'angsuran_bulanan' => 0];
    }

    try {
        $stmtKspJatuh = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(jumlah_total), 0) AS nilai
            FROM angsuran_pinjaman
            WHERE status IN ('belum_bayar', 'pending', 'menunggak')
              AND jatuh_tempo <= DATE_ADD(:today, INTERVAL 7 DAY)
        ");
        $stmtKspJatuh->execute([':today' => $today]);
        $kspAngsuranJatuhTempo = $stmtKspJatuh->fetch(PDO::FETCH_ASSOC) ?: $kspAngsuranJatuhTempo;
    } catch (Throwable $e) {
        $kspAngsuranJatuhTempo = ['total' => 0, 'nilai' => 0];
    }

    try {
        $stmtKspTerbaru = $pdo->query("
            SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode
            FROM pengajuan_pinjaman pp
            LEFT JOIN member m ON m.id = pp.member_id
            ORDER BY FIELD(COALESCE(NULLIF(pp.status, ''), 'dibatalkan'), 'pending', 'diseleksi', 'disetujui', 'ditolak', 'dibatalkan', 'dicairkan'), pp.created_at DESC, pp.id DESC
            LIMIT 6
        ");
        $kspPengajuanTerbaru = $stmtKspTerbaru->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kspPengajuanTerbaru = [];
    }

    try {
        $rowKspKonfig = $pdo->query("
            SELECT bunga_uang, bunga_barang, tenor_maks_uang, tenor_maks_barang
            FROM konfigurasi_sp
            ORDER BY id ASC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($rowKspKonfig) {
            $kspKonfig = array_merge($kspKonfig, $rowKspKonfig);
        }
    } catch (Throwable $e) {
        $kspKonfig = ['bunga_uang' => 0, 'bunga_barang' => 0, 'tenor_maks_uang' => 0, 'tenor_maks_barang' => 0];
    }
}

$title = 'Dashboard - ' . ($_SESSION['nama'] ?? 'SEJAHUB');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff;
            color: #111827;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        @media (min-width: 1024px) {
            .chart-container {
                height: 280px;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .dashboard-role-section {
            margin-top: 56px;
            padding-top: 48px;
            border-top: 1px solid #f0f0f0;
        }

        .dashboard-role-section:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .dashboard-section-title {
            margin-bottom: 24px;
        }

        .dashboard-kpi-block {
            margin-bottom: 56px;
        }

        @media (max-width: 768px) {
            .dashboard-role-section {
                margin-top: 40px;
                padding-top: 32px;
            }

            .dashboard-kpi-block {
                margin-bottom: 40px;
            }
        }


        /* Mobile dashboard action buttons */
        .dashboard-action-wrap {
            display: flex;
            gap: 8px;
            align-items: stretch;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .dashboard-action-btn {
            min-width: 170px;
            height: 56px;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
            line-height: 1.15;
            white-space: normal;
        }

        .dashboard-action-btn svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }


        .kas-monitor-card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 2px;
        }

        .kas-monitor-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border: 1px solid transparent;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
        }

        @media (max-width: 640px) {
            .dashboard-action-wrap {
                display: grid;
                grid-template-columns: 1fr;
                gap: 6px;
                overflow: visible;
                width: 100%;
            }

            .dashboard-action-btn {
                width: 100%;
                min-width: 0;
                height: 42px;
                padding: 0 10px;
                font-size: 9px;
                letter-spacing: .04em;
            }
        }

        @media (max-width: 1023px) {
            body {
                background: #f8fafc;
            }

            main.content {
                padding: 18px !important;
                overflow-x: hidden;
            }

            .dashboard-header-main {
                margin-bottom: 22px !important;
                gap: 16px !important;
                align-items: stretch !important;
            }

            .dashboard-header-main>div:first-child {
                min-width: 0;
            }

            .dashboard-action-wrap {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                width: 100%;
                overflow: visible;
            }

            .dashboard-action-btn {
                width: 100%;
                min-width: 0;
                height: 48px;
                padding: 0 10px;
                font-size: 9px !important;
                line-height: 1.2;
            }

            .kas-monitor-card {
                padding: 16px !important;
                overflow: hidden;
            }

            .kas-monitor-layout {
                gap: 16px !important;
            }

            .kas-monitor-kpis {
                grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
                gap: 8px !important;
                min-width: 0 !important;
            }

            .kas-monitor-kpis>div,
            .kas-monitor-kpis>a {
                min-width: 0;
                padding: 10px !important;
            }

            .kas-monitor-kpis p:first-child {
                font-size: 8px !important;
                line-height: 1.2;
                word-break: break-word;
            }

            .kas-monitor-kpis p:nth-child(2) {
                font-size: 20px !important;
            }

            .kas-table-desktop {
                display: none !important;
            }

            .kas-mobile-list {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid #f0f0f0;
            }

            .kas-mobile-card {
                border: 1px solid #e5e7eb;
                background: #fff;
                padding: 13px;
                min-width: 0;
            }

            .kas-mobile-card .kas-monitor-badge {
                max-width: 100%;
                white-space: normal;
                text-align: center;
                line-height: 1.2;
            }
        }

        /* Tablet: tombol aksi header dirapikan ke sisi kanan */
        @media (min-width: 768px) and (max-width: 1023px) {
            .dashboard-header-main {
                flex-direction: row !important;
                align-items: flex-end !important;
                justify-content: space-between !important;
            }

            .dashboard-header-main>div:first-child {
                flex: 1 1 auto;
                min-width: 0;
            }

            .dashboard-action-wrap {
                width: auto !important;
                margin-left: auto !important;
                display: flex !important;
                justify-content: flex-end !important;
                align-items: stretch !important;
                flex-wrap: nowrap !important;
                gap: 8px !important;
                overflow: visible !important;
            }

            .dashboard-action-btn {
                width: auto !important;
                min-width: 168px !important;
                flex: 0 0 auto !important;
                padding-left: 16px !important;
                padding-right: 16px !important;
            }
        }

        @media (max-width: 640px) {
            main.content {
                padding: 12px !important;
            }

            .dashboard-header-main h1 {
                font-size: 20px !important;
                line-height: 1.25;
            }

            .dashboard-action-wrap {
                grid-template-columns: 1fr;
                gap: 7px;
            }

            .dashboard-action-btn {
                height: 44px;
                font-size: 9px !important;
            }

            .kas-monitor-card {
                padding: 14px !important;
            }

            .kas-monitor-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .kas-monitor-kpis> :last-child:nth-child(odd) {
                grid-column: 1 / -1;
            }

            .kas-monitor-kpis p:nth-child(2) {
                font-size: 19px !important;
            }

            .kas-mobile-list {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .kas-monitor-action {
                width: 100%;
            }
        }

        @media (min-width: 1024px) {
            .kas-mobile-list {
                display: none !important;
            }
        }


        /* Dashboard kas modern */
        .kas-modern-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 18px;
            border-bottom: 1px solid #eef2f7;
        }

        .kas-modern-title {
            min-width: 0;
        }

        .kas-modern-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            white-space: nowrap;
        }

        .kas-modern-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
        }

        .kas-modern-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-top: 18px;
        }

        .kas-modern-item {
            min-width: 0;
            padding: 14px;
            border: 1px solid #edf1f5;
            background: #fafbfc;
        }

        .kas-modern-item .label {
            font-size: 9px;
            line-height: 1.25;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #94a3b8;
        }

        .kas-modern-item .value {
            margin-top: 7px;
            font-size: 18px;
            line-height: 1.15;
            font-weight: 900;
            color: #0f172a;
            overflow-wrap: anywhere;
        }

        .kas-modern-item .sub {
            margin-top: 5px;
            font-size: 9px;
            color: #94a3b8;
            font-weight: 700;
        }

        .kas-modern-item.primary {
            background: #eff6ff;
            border-color: #dbeafe;
        }

        .kas-modern-item.primary .value {
            color: #2563eb;
        }

        .kas-modern-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 18px;
        }

        .kas-modern-actions a {
            min-height: 42px;
        }

        @media (max-width: 1200px) {
            .kas-modern-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .kas-modern-head {
                flex-direction: column;
            }

            .kas-modern-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .kas-modern-item .value {
                font-size: 16px;
            }
        }

        @media (max-width: 420px) {
            .kas-modern-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="antialiased pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-5 md:p-8 lg:p-12">

        <!-- ── Header ──────────────────────────────────────────────────────────── -->
        <header class="dashboard-header-main flex flex-col md:flex-row justify-between items-start md:items-end mb-8 md:mb-12 gap-4">
            <div>
                <?php if (has_role('air_mineral')): ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">Operasional Air Mineral &ndash; <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></h1>
                <?php elseif (has_role('cafe')): ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">Operasional Cafe &ndash; <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></h1>
                <?php elseif (has_role('admin', 'kasir')): ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">
                        Shift 01 &ndash; <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </h1>
                <?php else: ?>
                    <h1 class="text-xl md:text-2xl font-light tracking-tight">
                        Selamat datang, <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </h1>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-1">
                    <?php echo date('l, d F Y', strtotime($today)); ?> | <?php echo date('H:i'); ?> WIB
                </p>
            </div>

            <!-- Tombol aksi sesuai role -->
            <div class="dashboard-action-wrap w-full md:w-auto">
                <?php if (has_role('admin', 'kasir')): ?>
                    <a href="pos.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>MESIN KASIR <span class="opacity-70">(TOKO)</span></span>
                    </a>
                <?php endif; ?>

                <?php if (has_role('admin', 'cafe')): ?>
                    <a href="pos_cafe.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>MESIN KASIR <span class="opacity-70">(CAFE)</span></span>
                    </a>
                <?php endif; ?>
                <?php if (has_role('admin', 'air_mineral')): ?>
                    <a href="air_pesanan.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>PEMESANAN<span class="hidden sm:inline"> </span><br class="sm:hidden">AIR</span>
                    </a>
                <?php endif; ?>

                <?php if (has_role('admin', 'rental')): ?>
                    <a href="rental_bandara.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>RENTAL<span class="hidden sm:inline"> </span><br class="sm:hidden">BANDARA</span>
                    </a>
                <?php endif; ?>
                <?php if (has_role('admin', 'ksp')): ?>
                    <a href="pinjaman.php" class="dashboard-action-btn text-xs font-bold bg-black text-white hover:bg-gray-800 transition-all rounded-sm shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>PENGAJUAN<span class="hidden sm:inline"> </span><br class="sm:hidden">PINJAMAN</span>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (has_role('cafe')): ?>
            <section class="dashboard-role-section cafe-dashboard">
                <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3 mb-6">
                    <div class="kas-monitor-card p-4 xl:col-span-2">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Omzet Cafe Hari Ini</p>
                        <p class="text-2xl md:text-3xl font-black text-amber-700 mt-2"><?php echo formatRp($cafeSummary['total_sales']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format((int)$cafeSummary['jumlah_struk']); ?> transaksi terbayar</p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Rata-rata Order</p>
                        <p class="text-xl font-black mt-2"><?php echo formatRp($cafeSummary['avg_struk']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1">Per transaksi</p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Meja Terisi</p>
                        <p class="text-xl font-black text-red-600 mt-2"><?php echo number_format((int)$cafeMeja['terisi']); ?> / <?php echo number_format((int)$cafeMeja['total']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format((int)$cafeMeja['kosong']); ?> meja kosong</p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Sedang Diproses</p>
                        <p class="text-xl font-black text-blue-600 mt-2"><?php echo number_format((int)$cafeStatusPesanan['diproses']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format((int)$cafeStatusPesanan['baru']); ?> pesanan baru</p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Siap Disajikan</p>
                        <p class="text-xl font-black text-green-600 mt-2"><?php echo number_format((int)$cafeStatusPesanan['siap']); ?></p>
                        <p class="text-[10px] text-gray-400 mt-1">Menunggu diantar</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
                    <div class="xl:col-span-2 kas-monitor-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pesanan Cafe Terakhir</p>
                                <p class="text-xs text-gray-400 mt-1">Dine in dan takeaway hari ini</p>
                            </div>
                            <a href="laporan.php" class="text-[9px] font-black uppercase tracking-widest underline">Lihat Laporan</a>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php if (empty($cafePesananTerakhir)): ?>
                                <div class="p-10 text-center text-xs font-bold text-gray-400">Belum ada pesanan cafe hari ini.</div>
                            <?php endif; ?>
                            <?php foreach ($cafePesananTerakhir as $order): ?>
                                <?php
                                $tipe = ($order['tipe_pesanan'] ?? '') === 'takeaway' ? 'Takeaway' : 'Dine In';
                                $statusOrder = strtolower((string)($order['status_pesanan'] ?? 'selesai'));
                                $statusClass = $statusOrder === 'siap' ? 'bg-green-50 text-green-700 border-green-200' : ($statusOrder === 'diproses' ? 'bg-blue-50 text-blue-700 border-blue-200' : ($statusOrder === 'baru' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-gray-50 text-gray-700 border-gray-200'));
                                ?>
                                <div class="p-4 md:p-5 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-10 h-10 bg-amber-50 border border-amber-100 flex items-center justify-center shrink-0"><i data-lucide="coffee" class="w-5 h-5 text-amber-700"></i></div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-black truncate"><?php echo htmlspecialchars((string)($order['nomor_pesanan'] ?: $order['invoice']), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-gray-400 mt-1"><?php echo $tipe; ?><?php echo !empty($order['nomor_meja']) ? ' · Meja ' . htmlspecialchars((string)$order['nomor_meja'], ENT_QUOTES, 'UTF-8') : ''; ?> · <?php echo date('H:i', strtotime((string)$order['created_at'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-black"><?php echo formatRp($order['total']); ?></p>
                                        <span class="inline-flex mt-1 px-2 py-1 border text-[8px] font-black uppercase tracking-widest <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusOrder ?: 'selesai', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="kas-monitor-card p-5">
                        <div class="mb-4">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Menu Cafe Terlaris</p>
                            <p class="text-xs text-gray-400 mt-1">Berdasarkan jumlah terjual hari ini</p>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($cafeMenuTerlaris)): ?><p class="py-8 text-center text-xs text-gray-400">Belum ada menu terjual.</p><?php endif; ?>
                            <?php
                            $maxQty = 1;
                            if (!empty($cafeMenuTerlaris)) {
                                $qtyList = array_map(function ($x) {
                                    return (int)($x['total_qty'] ?? 0);
                                }, $cafeMenuTerlaris);
                                $maxQty = !empty($qtyList) ? max($qtyList) : 1;
                            }
                            ?>
                            <?php foreach ($cafeMenuTerlaris as $i => $menu): ?>
                                <div>
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-xs font-bold truncate"><span class="text-gray-300 mr-2"><?php echo $i + 1; ?></span><?php echo htmlspecialchars((string)$menu['nama'], ENT_QUOTES, 'UTF-8'); ?></p><span class="text-xs font-black shrink-0"><?php echo number_format((int)$menu['total_qty']); ?>x</span>
                                    </div>
                                    <div class="h-1 bg-gray-100 mt-2">
                                        <div class="h-1 bg-amber-600" style="width:<?php echo max(6, round(((int)$menu['total_qty'] / max(1, $maxQty)) * 100)); ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="xl:col-span-2 kas-monitor-card p-5">
                        <div class="mb-4">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Omzet Cafe Per Jam</p>
                            <p class="text-xs text-gray-400 mt-1">Grafik transaksi Cafe hari ini</p>
                        </div>
                        <div class="chart-container"><canvas id="salesChart"></canvas></div>
                    </div>
                    <div class="kas-monitor-card p-5">
                        <div class="mb-4">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Stok Menu Menipis</p>
                            <p class="text-xs text-gray-400 mt-1">Hanya menu bertipe Cafe</p>
                        </div>
                        <div class="space-y-3">
                            <?php if (empty($cafeStokMenipis)): ?><div class="bg-green-50 border border-green-100 p-4 text-center text-xs font-bold text-green-700">Semua stok menu Cafe aman.</div><?php endif; ?>
                            <?php foreach ($cafeStokMenipis as $stok): ?>
                                <div class="border border-red-100 bg-red-50 p-3 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold truncate"><?php echo htmlspecialchars((string)$stok['nama'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-[9px] text-gray-400 mt-1"><?php echo htmlspecialchars((string)($stok['kategori'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div><span class="text-xs font-black text-red-600 shrink-0">Sisa <?php echo number_format((int)$stok['stok']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="menu_cafe.php" class="mt-4 w-full min-h-[42px] inline-flex items-center justify-center bg-black text-white text-[10px] font-black uppercase tracking-widest">Kelola Menu Cafe</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (has_role('admin', 'kasir')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN ADMIN & KASIR                                                -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <!-- Monitoring Kas Harian -->
                <?php if (!empty($kasHariIni['monitor_error'])): ?>
                    <div class="mb-4 border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700">
                        Monitoring kas gagal membaca database: <?php echo htmlspecialchars((string)$kasHariIni['monitor_error'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <div class="kas-monitor-card p-5 md:p-6 mb-8 md:mb-10">
                    <div class="kas-modern-head">
                        <div class="kas-modern-title">
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">
                                <?php echo has_role('admin') ? 'Monitoring Kas Operator' : 'Status Kas Saya'; ?>
                            </p>
                            <h2 class="text-lg md:text-xl font-black tracking-tight">
                                <?php if (has_role('admin')): ?>
                                    Buka & Tutup Kas Operator
                                <?php else: ?>
                                    <?php echo $kasHariIni['sudah_buka'] ? ($kasHariIni['masih_buka'] ? (!empty($kasHariIni['terlambat_tutup']) ? 'Kas Lama Belum Ditutup' : 'Kas Sedang Buka') : 'Kas Sudah Tutup') : 'Belum Buka Kas'; ?>
                                <?php endif; ?>
                            </h2>
                            <p class="text-xs text-gray-400 mt-1">
                                <?php echo has_role('admin') ? 'Pantau posisi kas, penjualan, metode pembayaran, dan sesi seluruh operator.' : 'Ringkasan sesi kas aktif dan transaksi yang sudah tercatat.'; ?>
                            </p>
                        </div>
                        <?php if (!has_role('admin')): ?>
                            <div class="kas-modern-status" style="<?php echo !empty($kasHariIni['terlambat_tutup']) ? 'background:#fef2f2;border-color:#fecaca;color:#b91c1c' : (!$kasHariIni['masih_buka'] ? 'background:#f8fafc;border-color:#e2e8f0;color:#475569' : ''); ?>">
                                <span class="kas-modern-dot"></span>
                                <?php echo !empty($kasHariIni['terlambat_tutup']) ? 'Terlambat Tutup' : ($kasHariIni['masih_buka'] ? 'Kas Buka' : ($kasHariIni['sudah_tutup'] ? 'Kas Tutup' : 'Belum Buka')); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (has_role('admin')): ?>
                        <div class="kas-modern-grid">
                            <div class="kas-modern-item primary">
                                <p class="label">Sudah Buka</p>
                                <p class="value"><?php echo number_format((int)$kasHariIni['sudah_buka']); ?></p>
                                <p class="sub">Operator hari ini</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Masih Buka</p>
                                <p class="value"><?php echo number_format((int)$kasHariIni['masih_buka']); ?></p>
                                <p class="sub">Sesi aktif</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Sudah Tutup</p>
                                <p class="value"><?php echo number_format((int)$kasHariIni['sudah_tutup']); ?></p>
                                <p class="sub">Sesi selesai</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Total Sales</p>
                                <p class="value"><?php echo formatRp($kasHariIni['total_sales']); ?></p>
                                <p class="sub"><?php echo number_format((int)$kasHariIni['total_struk']); ?> struk</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Kas Akhir</p>
                                <p class="value"><?php echo formatRp($kasHariIni['kas_akhir']); ?></p>
                                <p class="sub">Seluruh operator</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="kas-modern-grid">
                            <div class="kas-modern-item">
                                <p class="label">Kas Awal</p>
                                <p class="value"><?php echo formatRp($kasHariIni['kas_awal']); ?></p>
                                <p class="sub">Modal awal sesi</p>
                            </div>
                            <div class="kas-modern-item primary">
                                <p class="label">Total Sales</p>
                                <p class="value"><?php echo formatRp($kasHariIni['total_sales']); ?></p>
                                <p class="sub"><?php echo number_format((int)$kasHariIni['total_struk']); ?> struk</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Tunai</p>
                                <p class="value"><?php echo formatRp($kasHariIni['total_tunai']); ?></p>
                                <p class="sub">Pembayaran tunai</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Non-Tunai</p>
                                <p class="value"><?php echo formatRp($kasHariIni['total_nontunai']); ?></p>
                                <p class="sub">QRIS / EDC / transfer</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Margin</p>
                                <p class="value"><?php echo formatRp($kasHariIni['margin']); ?></p>
                                <p class="sub">Estimasi keuntungan</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Kas Akhir</p>
                                <p class="value"><?php echo formatRp($kasHariIni['kas_akhir']); ?></p>
                                <p class="sub">Kas awal + sales</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Kas Aktual</p>
                                <p class="value"><?php echo formatRp($kasHariIni['kas_aktual']); ?></p>
                                <p class="sub">Nilai fisik tercatat</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Selisih</p>
                                <p class="value" style="color:<?php echo $kasHariIni['selisih'] < 0 ? '#dc2626' : ($kasHariIni['selisih'] > 0 ? '#16a34a' : '#0f172a'); ?>"><?php echo formatRp($kasHariIni['selisih']); ?></p>
                                <p class="sub">Aktual - sistem</p>
                            </div>
                            <div class="kas-modern-item">
                                <p class="label">Jam Buka</p>
                                <p class="value"><?php echo !empty($kasHariIni['opened_at']) ? date('d/m H:i', strtotime((string)$kasHariIni['opened_at'])) : '-'; ?></p>
                                <p class="sub"><?php echo !empty($kasHariIni['closed_at']) ? 'Tutup ' . date('d/m H:i', strtotime((string)$kasHariIni['closed_at'])) : 'Sesi berlangsung'; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (has_role('admin') && !empty($kasOperatorList)): ?>
                        <div class="kas-table-desktop mt-5 border-t border-gray-100 pt-4 overflow-x-auto no-scrollbar">
                            <table class="w-full text-left min-w-[760px]">
                                <thead>
                                    <tr class="border-b border-gray-100">
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Operator</th>
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Dibuka</th>
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400">Ditutup</th>
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Kas Awal</th>
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-right">Tunai</th>
                                        <th class="py-3 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach (array_slice($kasOperatorList, 0, 6) as $kasOp): ?>
                                        <tr>
                                            <td class="py-3 text-xs font-bold"><?php echo htmlspecialchars((string)($kasOp['operator'] ?? 'Operator'), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="py-3 text-xs text-gray-500"><?php echo !empty($kasOp['opened_at']) ? date('d/m/Y H:i', strtotime((string)$kasOp['opened_at'])) : '-'; ?></td>
                                            <td class="py-3 text-xs text-gray-500"><?php echo !empty($kasOp['closed_at']) ? date('d/m/Y H:i', strtotime((string)$kasOp['closed_at'])) : '-'; ?></td>
                                            <td class="py-3 text-xs text-right font-bold"><?php echo formatRp($kasOp['kas_awal'] ?? 0); ?></td>
                                            <td class="py-3 text-xs text-right font-bold"><?php echo formatRp($kasOp['total_tunai'] ?? 0); ?></td>
                                            <td class="py-3 text-center">
                                                <?php if (($kasOp['status'] ?? '') === 'buka' && !empty($kasOp['terlambat_tutup'])): ?>
                                                    <span class="kas-monitor-badge bg-red-50 text-red-700 border-red-200">Terlambat Tutup</span>
                                                <?php elseif (($kasOp['status'] ?? '') === 'buka'): ?>
                                                    <span class="kas-monitor-badge bg-yellow-50 text-yellow-700 border-yellow-200">Masih Buka</span>
                                                <?php else: ?>
                                                    <span class="kas-monitor-badge bg-green-50 text-green-700 border-green-200">Sudah Tutup</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="kas-mobile-list">
                            <?php foreach (array_slice($kasOperatorList, 0, 6) as $kasOp): ?>
                                <div class="kas-mobile-card">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-black truncate"><?php echo htmlspecialchars((string)($kasOp['operator'] ?? 'Operator'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-gray-400 mt-1"><?php echo !empty($kasOp['opened_at']) ? date('d/m/Y H:i', strtotime((string)$kasOp['opened_at'])) : '-'; ?></p>
                                        </div>
                                        <?php if (($kasOp['status'] ?? '') === 'buka' && !empty($kasOp['terlambat_tutup'])): ?>
                                            <span class="kas-monitor-badge bg-red-50 text-red-700 border-red-200">Terlambat Tutup</span>
                                        <?php elseif (($kasOp['status'] ?? '') === 'buka'): ?>
                                            <span class="kas-monitor-badge bg-yellow-50 text-yellow-700 border-yellow-200">Masih Buka</span>
                                        <?php else: ?>
                                            <span class="kas-monitor-badge bg-green-50 text-green-700 border-green-200">Sudah Tutup</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-gray-100">
                                        <div>
                                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Kas Awal</p>
                                            <p class="text-[11px] font-bold mt-1"><?php echo formatRp($kasOp['kas_awal'] ?? 0); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Tunai</p>
                                            <p class="text-[11px] font-bold mt-1"><?php echo formatRp($kasOp['total_tunai'] ?? 0); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Ditutup</p>
                                            <p class="text-[11px] font-bold mt-1"><?php echo !empty($kasOp['closed_at']) ? date('d/m H:i', strtotime((string)$kasOp['closed_at'])) : '-'; ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!has_role('admin') && !empty($kasHariIni['terlambat_tutup'])): ?>
                        <div class="mt-5 bg-red-50 border border-red-200 px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-red-700">Kas melewati pergantian hari</p>
                            <p class="text-xs text-red-600 mt-1">Sesi kas dari hari sebelumnya wajib ditutup sebelum membuka sesi baru, transaksi, atau logout.</p>
                        </div>
                    <?php endif; ?>

                    <div class="kas-modern-actions">
                        <a href="kas_harian.php" class="kas-monitor-action inline-flex items-center justify-center px-4 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                            <?php echo has_role('admin') ? 'Lihat Semua Riwayat Kas' : ($kasHariIni['masih_buka'] ? 'Tutup Kas' : 'Buka / Riwayat Kas'); ?>
                        </a>
                        <?php if (!has_role('admin') && !$kasHariIni['sudah_buka']): ?>
                            <a href="<?php echo has_role('cafe') ? 'pos_cafe.php' : 'pos.php'; ?>" class="inline-flex items-center justify-center px-4 py-3 border border-red-200 text-red-600 bg-red-50 text-[10px] font-black uppercase tracking-widest rounded-sm">
                                <?php echo has_role('cafe') ? 'POS Cafe Akan Terkunci Sampai Kas Dibuka' : 'POS Akan Terkunci Sampai Kas Dibuka'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monitoring Produk Kedaluwarsa -->
                <?php
                $totalPerluPerhatian = (int)$produkExpiredSummary['expired']
                    + (int)$produkExpiredSummary['h7']
                    + (int)$produkExpiredSummary['h30'];
                ?>

                <?php if ((int)$produkExpiredSummary['expired'] > 0): ?>
                    <div class="mb-5 border border-red-200 bg-red-50 px-4 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-red-700">Peringatan Produk Kedaluwarsa</p>
                            <p class="text-xs text-red-600 mt-1">
                                Ada <strong><?php echo number_format((int)$produkExpiredSummary['expired']); ?> produk</strong> yang sudah melewati tanggal kedaluwarsa dan tidak boleh dijual.
                            </p>
                        </div>
                        <a href="produk.php?expired=expired&status=aktif"
                            class="inline-flex items-center justify-center px-4 py-3 bg-red-600 text-white text-[10px] font-black uppercase tracking-widest hover:bg-red-700 transition-all whitespace-nowrap">
                            Lihat Produk
                        </a>
                    </div>
                <?php endif; ?>

                <div class="kas-monitor-card p-5 md:p-6 mb-8 md:mb-10">
                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Monitoring Produk</p>
                            <h2 class="text-lg md:text-xl font-black tracking-tight">Tanggal Kedaluwarsa</h2>
                            <p class="text-xs text-gray-400 mt-1">Pantau produk kedaluwarsa dan produk yang akan kedaluwarsa dalam 30 hari.</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full lg:w-auto lg:min-w-[520px]">
                            <a href="produk.php?expired=expired&status=aktif" class="bg-red-50 border border-red-200 p-3 hover:border-red-300 transition-all">
                                <p class="text-[9px] font-black text-red-600 uppercase tracking-widest">Sudah Kedaluwarsa</p>
                                <p class="text-2xl font-black text-red-700 mt-1"><?php echo number_format((int)$produkExpiredSummary['expired']); ?></p>
                                <p class="text-[9px] text-red-500 mt-1">Tidak boleh dijual</p>
                            </a>
                            <a href="produk.php?expired=30_hari&status=aktif" class="bg-orange-50 border border-orange-200 p-3 hover:border-orange-300 transition-all">
                                <p class="text-[9px] font-black text-orange-600 uppercase tracking-widest">H-7 Kedaluwarsa</p>
                                <p class="text-2xl font-black text-orange-700 mt-1"><?php echo number_format((int)$produkExpiredSummary['h7']); ?></p>
                                <p class="text-[9px] text-orange-500 mt-1">Perlu diprioritaskan</p>
                            </a>
                            <a href="produk.php?expired=30_hari&status=aktif" class="bg-yellow-50 border border-yellow-200 p-3 hover:border-yellow-300 transition-all">
                                <p class="text-[9px] font-black text-yellow-600 uppercase tracking-widest">H-8 s.d. H-30</p>
                                <p class="text-2xl font-black text-yellow-700 mt-1"><?php echo number_format((int)$produkExpiredSummary['h30']); ?></p>
                                <p class="text-[9px] text-yellow-600 mt-1">Segera dipantau</p>
                            </a>
                        </div>
                    </div>

                    <?php if ($produkExpiredError !== ''): ?>
                        <div class="mt-5 border border-yellow-200 bg-yellow-50 px-4 py-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-yellow-700">Monitoring kedaluwarsa belum tersedia</p>
                            <p class="text-xs text-yellow-700 mt-1">Pastikan kolom <code>expired_date</code> sudah tersedia pada tabel produk.</p>
                        </div>
                    <?php elseif (!empty($produkExpiredList)): ?>
                        <div class="mt-5 border-t border-gray-100 pt-4">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Produk Perlu Perhatian</p>
                                    <p class="text-xs text-gray-400 mt-0.5"><?php echo number_format($totalPerluPerhatian); ?> produk dalam pemantauan</p>
                                </div>
                                <a href="produk.php?expired=30_hari&status=aktif" class="text-[10px] font-black uppercase tracking-widest underline">Lihat Semua</a>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <?php foreach ($produkExpiredList as $expProduk): ?>
                                    <?php
                                    $expDate = (string)($expProduk['expired_date'] ?? '');
                                    $expTs = $expDate !== '' ? strtotime($expDate) : false;
                                    $todayTs = strtotime($today);
                                    $daysLeft = ($expTs !== false && $todayTs !== false)
                                        ? (int)floor(($expTs - $todayTs) / 86400)
                                        : 0;
                                    $isExpiredProduct = $expDate !== '' && $expDate < $today;

                                    if ($isExpiredProduct) {
                                        $expBadgeClass = 'bg-red-50 text-red-700 border-red-200';
                                        $expBadgeLabel = 'Kedaluwarsa';
                                    } elseif ($daysLeft <= 7) {
                                        $expBadgeClass = 'bg-orange-50 text-orange-700 border-orange-200';
                                        $expBadgeLabel = 'H-' . max(0, $daysLeft);
                                    } else {
                                        $expBadgeClass = 'bg-yellow-50 text-yellow-700 border-yellow-200';
                                        $expBadgeLabel = 'H-' . max(0, $daysLeft);
                                    }
                                    ?>
                                    <a href="produk.php?q=<?php echo urlencode((string)($expProduk['kode'] ?? '')); ?>&status=aktif"
                                        class="border border-gray-100 p-3 flex items-center justify-between gap-3 hover:border-gray-300 transition-all">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold truncate"><?php echo htmlspecialchars((string)($expProduk['nama'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-gray-400 mt-0.5 truncate">
                                                <?php echo htmlspecialchars((string)($expProduk['kode'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                &bull; <?php echo htmlspecialchars((string)($expProduk['kategori'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                &bull; Stok <?php echo number_format((int)($expProduk['stok'] ?? 0)); ?>
                                            </p>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <span class="kas-monitor-badge <?php echo $expBadgeClass; ?>"><?php echo htmlspecialchars($expBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <p class="text-[10px] font-bold text-gray-500 mt-1"><?php echo $expTs !== false ? date('d/m/Y', $expTs) : '-'; ?></p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mt-5 border border-green-100 bg-green-50 px-4 py-4 text-center">
                            <p class="text-xs font-bold text-green-700">Tidak ada produk yang kedaluwarsa atau akan kedaluwarsa dalam 30 hari.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 md:gap-8 dashboard-kpi-block border-b border-subtle pb-10 md:pb-14">

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Sales (Nett)</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo formatRp($ringkasan['total_sales']); ?>
                        </p>
                        <span class="text-[10px] font-bold text-gray-400 bg-gray-50 px-2 py-0.5 mt-2 inline-block italic">Target: Rp 12Jt</span>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jumlah Struk</p>
                        <p class="text-2xl md:text-3xl font-medium">
                            <?php echo number_format($ringkasan['jumlah_struk']); ?>
                            <span class="text-sm text-gray-300 font-bold">INV</span>
                        </p>
                        <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 mt-2 inline-block">
                            Avg: <?php echo formatRp($avgStruk); ?>
                        </span>
                    </div>

                    <div class="py-2 sm:col-span-2 md:col-span-1">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Stok Limit</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo count($produkStokLimit); ?>
                            <span class="text-sm text-red-200 uppercase font-bold">SKU</span>
                        </p>
                        <?php if (count($produkStokLimit) > 0): ?>
                            <a href="#stok-limit" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block underline cursor-pointer">Cek Detail</a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Chart + Stok Limit -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mb-8 md:mb-12">

                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-widest">Realisasi Sales (Per Jam)</h3>
                                <p class="text-[10px] text-gray-400 italic mt-1">*Satuan dalam Juta Rupiah</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <div class="lg:col-span-1" id="stok-limit">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-red-600 mb-6">
                            Peringatan Stok Limit
                            <?php if (count($produkStokLimit) === 0): ?>
                                <span class="text-gray-400 normal-case font-medium">(aman)</span>
                            <?php endif; ?>
                        </h3>

                        <?php if (count($produkStokLimit) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach (array_slice($produkStokLimit, 0, 4) as $p): ?>
                                    <?php $pct = $p['stok_minimum'] > 0 ? min(100, round($p['stok'] / $p['stok_minimum'] * 100)) : 0; ?>
                                    <div class="p-3 border border-red-100 bg-red-50 rounded-sm">
                                        <div class="flex justify-between items-start mb-1">
                                            <span class="text-sm font-bold block leading-tight"><?php echo htmlspecialchars($p['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="text-[10px] font-black text-red-600 uppercase">Sisa <?php echo $p['stok']; ?></span>
                                        </div>
                                        <p class="text-[9px] text-gray-400 uppercase mb-2">
                                            <?php echo htmlspecialchars($p['kategori'], ENT_QUOTES, 'UTF-8'); ?> | Min: <?php echo $p['stok_minimum']; ?>
                                        </p>
                                        <div class="w-full bg-gray-100 h-1">
                                            <div class="bg-red-500 h-1" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-4 bg-green-50 border border-green-100 rounded-sm text-center">
                                <p class="text-xs font-bold text-green-600">Semua stok dalam kondisi aman &#10003;</p>
                            </div>
                        <?php endif; ?>

                        <?php if (has_role('admin', 'kasir', 'cafe')): ?>
                            <a href="buat_po.php">
                                <button type="button" class="mt-4 w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                                    BUAT PO BARANG
                                </button>
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Struk Terakhir + Fast Moving -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mt-12 md:mt-20">

                    <div class="lg:col-span-2">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Struk Terakhir</h3>

                        <?php if (empty($strukTerakhir)): ?>
                            <div class="p-6 border border-gray-100 rounded-sm text-center">
                                <p class="text-xs text-gray-400">Belum ada transaksi hari ini</p>
                            </div>
                        <?php else: ?>

                            <!-- Desktop -->
                            <div class="hidden lg:block overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-black">
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">ID Struk</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Kasir</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Total</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Opsi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($strukTerakhir as $t): ?>
                                            <tr>
                                                <td class="py-4 text-sm font-medium">
                                                    #<?php echo htmlspecialchars(substr($t['invoice'], -6), ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="text-[10px] text-gray-400 ml-2"><?php echo date('H:i', strtotime($t['created_at'])); ?></span>
                                                </td>
                                                <td class="py-4 text-sm font-medium text-gray-600">
                                                    <?php echo htmlspecialchars($t['kasir'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td class="py-4 text-sm font-medium"><?php echo formatRp($t['total']); ?></td>
                                                <td class="py-4 text-sm text-right">
                                                    <button type="button"
                                                        onclick="reprintThermal(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars($t['invoice'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                        class="text-[10px] font-bold underline hover:text-blue-600">REPRINT</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile -->
                            <div class="lg:hidden space-y-3">
                                <?php foreach ($strukTerakhir as $t): ?>
                                    <div class="border border-gray-100 rounded-sm p-4 flex items-center justify-between gap-3 hover:border-gray-300 transition-all">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-9 h-9 bg-gray-50 border border-gray-100 rounded-sm flex items-center justify-center flex-shrink-0">
                                                <span class="text-[9px] font-black text-gray-400 uppercase">INV</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold">
                                                    #<?php echo htmlspecialchars(substr($t['invoice'], -6), ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="text-[10px] text-gray-400 font-normal ml-1"><?php echo date('H:i', strtotime($t['created_at'])); ?></span>
                                                </p>
                                                <p class="text-[10px] text-gray-400 mt-0.5 truncate"><?php echo htmlspecialchars($t['kasir'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3 flex-shrink-0">
                                            <div class="text-right">
                                                <p class="text-sm font-bold"><?php echo formatRp($t['total']); ?></p>
                                                <p class="text-[10px] text-gray-400 mt-0.5">Total</p>
                                            </div>
                                            <button type="button"
                                                onclick="reprintThermal(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars($t['invoice'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                class="px-3 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:bg-black hover:text-white hover:border-black transition-all rounded-sm">
                                                Reprint
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-1">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Top Fast Moving</h3>
                        <div class="space-y-4">
                            <?php if (empty($fastMoving)): ?>
                                <p class="text-xs text-gray-400">Belum ada data penjualan hari ini</p>
                            <?php else: ?>
                                <?php foreach ($fastMoving as $i => $fm): ?>
                                    <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                                        <div class="flex items-center gap-3">
                                            <span class="text-[10px] font-black text-gray-300 w-4"><?php echo $i + 1; ?></span>
                                            <span class="text-sm font-medium"><?php echo htmlspecialchars($fm['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <span class="text-sm font-bold text-gray-400"><?php echo number_format($fm['total_qty']); ?>x</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </section>
        <?php endif; // end has_role admin|kasir|cafe 
        ?>


        <?php if (has_role('rental', 'admin')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN RENTAL                                                       -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <!-- KPI Cards Rental -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-8 mb-8 md:mb-12 border-b border-subtle pb-8 md:pb-12">

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Order Hari Ini</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo number_format($ringkasanRental['total_order'] ?? 0); ?>
                            <span class="text-sm text-gray-300 font-bold">ORDER</span>
                        </p>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pendapatan Hari Ini</p>
                        <p class="text-2xl md:text-3xl font-medium text-green-600">
                            <?php echo formatRp($ringkasanRental['total_pendapatan'] ?? 0); ?>
                        </p>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Driver Aktif</p>
                        <p class="text-2xl md:text-3xl font-medium">
                            <?php echo number_format($totalDriver ?? 0); ?>
                            <span class="text-sm text-gray-300 font-bold">DRIVER</span>
                        </p>
                        <a href="driver.php" class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 mt-2 inline-block">
                            Kelola Driver
                        </a>
                    </div>

                </div>

                <!-- Order Rental Terbaru -->
                <div class="mt-4">
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Order Rental Terbaru</h3>

                    <?php if (empty($orderRentalTerbaru)): ?>
                        <div class="p-6 border border-gray-100 rounded-sm text-center">
                            <p class="text-xs text-gray-400">Belum ada order rental hari ini</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($orderRentalTerbaru as $r): ?>
                                <div class="border border-gray-100 rounded-sm p-4 flex items-center justify-between gap-3 hover:border-gray-300 transition-all">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold truncate">
                                            <?php echo htmlspecialchars($r['nama_penumpang'] ?? $r['nama'] ?? 'Penumpang', ENT_QUOTES, 'UTF-8'); ?>
                                        </p>
                                        <p class="text-[10px] text-gray-400 mt-0.5">
                                            Driver: <?php echo htmlspecialchars($r['nama_driver'] ?? 'Belum assign', ENT_QUOTES, 'UTF-8'); ?>
                                            &bull; <?php echo date('H:i', strtotime($r['created_at'] ?? 'now')); ?>
                                        </p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-sm font-bold"><?php echo formatRp($r['total_bayar'] ?? 0); ?></p>
                                        <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-sm
                                <?php echo ($r['status'] ?? '') === 'selesai' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                            <?php echo htmlspecialchars($r['status'] ?? 'proses', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <a href="rental_bandara.php">
                        <button type="button" class="mt-6 w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm">
                            LIHAT SEMUA ORDER RENTAL
                        </button>
                    </a>
                </div>

            </section>
        <?php endif; // end has_role rental 
        ?>


        <?php if (has_role('ksp', 'admin')): ?>
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <!-- KONTEN KSP / SIMPAN PINJAM                                          -->
            <!-- ════════════════════════════════════════════════════════════════════ -->
            <section class="dashboard-role-section">

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-6 md:gap-8 mb-8 md:mb-12 border-b border-subtle pb-8 md:pb-12">

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pengajuan Pending</p>
                        <p class="text-2xl md:text-3xl font-medium text-amber-600">
                            <?php echo number_format((int)$kspPengajuanSummary['pending']); ?>
                            <span class="text-sm text-amber-200 font-bold">REQ</span>
                        </p>
                        <a href="pinjaman.php?status=pending" class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 mt-2 inline-block">Proses Pengajuan</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Disetujui Belum Cair</p>
                        <p class="text-2xl md:text-3xl font-medium text-green-600">
                            <?php echo number_format((int)$kspPengajuanSummary['disetujui']); ?>
                            <span class="text-sm text-green-200 font-bold">ACC</span>
                        </p>
                        <a href="pinjaman.php?status=disetujui" class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 mt-2 inline-block">Cairkan</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Batal Pengajuan</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo number_format((int)$kspPengajuanSummary['dibatalkan']); ?>
                            <span class="text-sm text-red-200 font-bold">BTL</span>
                        </p>
                        <a href="pinjaman.php?status=dibatalkan" class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block">Lihat Batal</a>
                    </div>

                    <div class="py-2 border-b sm:border-b-0 border-subtle pb-4 sm:pb-0">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pinjaman Aktif</p>
                        <p class="text-2xl md:text-3xl font-medium text-blue-600">
                            <?php echo number_format((int)($kspPinjamanAktif['total'] ?? 0)); ?>
                            <span class="text-sm text-blue-200 font-bold">AKTIF</span>
                        </p>
                        <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 mt-2 inline-block">
                            <?php echo formatRp($kspPinjamanAktif['pokok'] ?? 0); ?>
                        </span>
                    </div>

                    <div class="py-2">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jatuh Tempo 7 Hari</p>
                        <p class="text-2xl md:text-3xl font-medium text-red-600">
                            <?php echo number_format((int)($kspAngsuranJatuhTempo['total'] ?? 0)); ?>
                            <span class="text-sm text-red-200 font-bold">ANGS</span>
                        </p>
                        <span class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 mt-2 inline-block">
                            <?php echo formatRp($kspAngsuranJatuhTempo['nilai'] ?? 0); ?>
                        </span>
                    </div>

                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 md:gap-12 mb-8 md:mb-12">

                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-widest">Pengajuan Terbaru</h3>
                                <p class="text-[10px] text-gray-400 italic mt-1">Data terbaru dari member yang mengajukan pinjaman</p>
                            </div>
                            <a href="pinjaman.php" class="text-[10px] font-black uppercase tracking-widest underline">Lihat Semua</a>
                        </div>

                        <?php if (empty($kspPengajuanTerbaru)): ?>
                            <div class="p-6 border border-gray-100 rounded-sm text-center">
                                <p class="text-xs text-gray-400">Belum ada pengajuan pinjaman</p>
                            </div>
                        <?php else: ?>
                            <div class="hidden lg:block overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-black">
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Member</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest">Jenis</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Jumlah</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-center">Tenor</th>
                                            <th class="py-3 text-[10px] font-bold uppercase tracking-widest text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($kspPengajuanTerbaru as $p): ?>
                                            <tr>
                                                <td class="py-4 text-sm font-medium">
                                                    <?php echo htmlspecialchars($p['member_nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                                    <span class="block text-[10px] text-gray-400 font-mono"><?php echo htmlspecialchars($p['member_kode'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                                </td>
                                                <td class="py-4 text-sm font-bold uppercase"><?php echo htmlspecialchars($p['jenis'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="py-4 text-sm font-bold text-right"><?php echo formatRp($p['jumlah'] ?? 0); ?></td>
                                                <td class="py-4 text-sm text-center"><?php echo number_format((int)($p['tenor'] ?? 0)); ?> bln</td>
                                                <td class="py-4 text-right">
                                                    <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo dashboard_pinjaman_status_class((string)($p['status'] ?? '')); ?>">
                                                        <?php echo htmlspecialchars(strtoupper(dashboard_pinjaman_status_label((string)($p['status'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="lg:hidden space-y-3">
                                <?php foreach ($kspPengajuanTerbaru as $p): ?>
                                    <div class="border border-gray-100 rounded-sm p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-bold"><?php echo htmlspecialchars($p['member_nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo htmlspecialchars($p['member_kode'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo dashboard_pinjaman_status_class((string)($p['status'] ?? '')); ?>">
                                                <?php echo htmlspecialchars(strtoupper(dashboard_pinjaman_status_label((string)($p['status'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 mt-4">
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Jenis</p>
                                                <p class="text-xs font-bold uppercase"><?php echo htmlspecialchars($p['jenis'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Jumlah</p>
                                                <p class="text-xs font-bold"><?php echo formatRp($p['jumlah'] ?? 0); ?></p>
                                            </div>
                                            <div class="bg-gray-50 border border-gray-100 p-2">
                                                <p class="text-[9px] text-gray-400 font-bold uppercase">Tenor</p>
                                                <p class="text-xs font-bold"><?php echo number_format((int)($p['tenor'] ?? 0)); ?> bln</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-1">
                        <h3 class="text-xs font-bold uppercase tracking-widest mb-6">Konfigurasi SP Aktif</h3>
                        <div class="space-y-4">
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Uang</p>
                                <p class="text-2xl font-medium text-green-600"><?php echo number_format((float)($kspKonfig['bunga_uang'] ?? 0), 2, ',', '.'); ?>%</p>
                            </div>
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Barang</p>
                                <p class="text-2xl font-medium text-blue-600"><?php echo number_format((float)($kspKonfig['bunga_barang'] ?? 0), 2, ',', '.'); ?>%</p>
                            </div>
                            <div class="p-4 border border-gray-100 bg-gray-50 rounded-sm">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tenor Maksimal</p>
                                <p class="text-sm font-bold">Uang <?php echo number_format((int)($kspKonfig['tenor_maks_uang'] ?? 0)); ?> bln · Barang <?php echo number_format((int)($kspKonfig['tenor_maks_barang'] ?? 0)); ?> bln</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-2 mt-4">
                            <a href="pinjaman.php" class="w-full py-3 text-[10px] font-bold bg-black text-white uppercase tracking-widest hover:bg-gray-800 transition-all rounded-sm text-center">
                                KELOLA PENGAJUAN
                            </a>
                            <a href="sp.php" class="w-full py-3 text-[10px] font-bold border border-gray-200 uppercase tracking-widest hover:bg-gray-50 transition-all rounded-sm text-center">
                                KONFIGURASI SP
                            </a>
                            <a href="laporan.php" class="w-full py-3 text-[10px] font-bold border border-gray-200 uppercase tracking-widest hover:bg-gray-50 transition-all rounded-sm text-center">
                                LAPORAN SP
                            </a>
                        </div>
                    </div>

                </div>

            </section>
        <?php endif; // end has_role ksp 
        ?>

        <!-- ════════════════════════════════════════════════════════════════════ -->
        <!-- KONTEN AIR MINERAL — DILETAKKAN SETELAH SIMPAN PINJAM              -->
        <!-- ════════════════════════════════════════════════════════════════════ -->
        <?php if (has_role('air_mineral', 'admin')): ?>
            <section class="dashboard-role-section air-dashboard">
                <div class="dashboard-section-title flex flex-col md:flex-row md:items-end md:justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Operasional Air Mineral</p>
                        <h2 class="text-lg md:text-xl font-black tracking-tight mt-1">Pesanan dan Distribusi Keseluruhan</h2>
                        <p class="text-xs text-gray-400 mt-1">Ringkasan pesanan publik, lokasi pengantaran, dan jumlah produk.</p>
                    </div>
                    <a href="air_pesanan.php" class="inline-flex min-h-[42px] items-center justify-center gap-2 bg-black px-4 text-[10px] font-black uppercase tracking-widest text-white">
                        <i data-lucide="clipboard-list" class="w-4 h-4"></i> Kelola Pesanan
                    </a>
                </div>

                <?php if ($airDashboardError !== ''): ?>
                    <div class="mb-5 border border-red-200 bg-red-50 px-4 py-3 text-xs font-bold text-red-700">
                        Data Air Mineral gagal dimuat: <?php echo htmlspecialchars($airDashboardError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
                    <div class="kas-monitor-card p-4 xl:col-span-1">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Pesanan</p>
                        <p class="text-2xl font-black mt-2"><?php echo number_format((int)$airSummary['total']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Baru</p>
                        <p class="text-2xl font-black text-blue-700 mt-2"><?php echo number_format((int)$airSummary['baru']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Diproses</p>
                        <p class="text-2xl font-black text-amber-700 mt-2"><?php echo number_format((int)$airSummary['diproses']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-purple-600">Siap Dikirim</p>
                        <p class="text-2xl font-black text-purple-700 mt-2"><?php echo number_format((int)$airSummary['siap_dikirim']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-cyan-600">Dalam Pengiriman</p>
                        <p class="text-2xl font-black text-blue-600 mt-2"><?php echo number_format((int)$airSummary['dalam_pengiriman']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Selesai</p>
                        <p class="text-2xl font-black text-green-700 mt-2"><?php echo number_format((int)$airSummary['selesai']); ?></p>
                    </div>
                    <div class="kas-monitor-card p-4 col-span-2 md:col-span-1">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Produk</p>
                        <p class="text-2xl font-black text-blue-600 mt-2"><?php echo number_format((int)$airSummary['total_unit']); ?></p>
                        <p class="text-[9px] text-gray-400 mt-1"><?php echo number_format((int)$airSummary['total_lokasi']); ?> lokasi • tidak termasuk pesanan batal</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
                    <div class="xl:col-span-2 kas-monitor-card overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Pesanan Air Terbaru</p>
                                <p class="text-xs text-gray-400 mt-1">Pesanan terbaru dari seluruh data</p>
                            </div>
                            <a href="air_pesanan.php" class="text-[9px] font-black uppercase tracking-widest underline">Lihat Semua</a>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php if (empty($airPesananTerbaru)): ?>
                                <div class="p-10 text-center text-xs font-bold text-gray-400">Belum ada data pesanan air.</div>
                            <?php endif; ?>
                            <?php foreach ($airPesananTerbaru as $order): ?>
                                <?php $airStatus = strtolower((string)($order['status'] ?? 'baru')); ?>
                                <a href="air_pesanan.php?q=<?php echo urlencode((string)$order['nomor_pesanan']); ?>" class="p-4 md:p-5 flex items-center justify-between gap-4 hover:bg-gray-50">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-10 h-10 bg-blue-50 border border-blue-100 flex items-center justify-center shrink-0"><i data-lucide="droplets" class="w-5 h-5 text-blue-600"></i></div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-black truncate"><?php echo htmlspecialchars((string)$order['nomor_pesanan'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-gray-400 mt-1 truncate"><?php echo htmlspecialchars((string)$order['nama_pemesan'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo number_format((int)$order['total_lokasi']); ?> lokasi</p>
                                            <p class="text-[9px] text-gray-400 mt-1 truncate"><?php echo htmlspecialchars((string)($order['daftar_lokasi'] ?: 'Lokasi belum tersedia'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-lg font-black"><?php echo number_format((int)$order['total_unit']); ?></p>
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Unit</p>
                                        <span class="inline-flex mt-2 px-2 py-1 border text-[8px] font-black uppercase tracking-widest <?php echo air_status_class($airStatus); ?>"><?php echo htmlspecialchars(air_status_label($airStatus), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="kas-monitor-card p-5">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Rekap Produk Keseluruhan</p>
                        <p class="text-xs text-gray-400 mt-1 mb-4">Jumlah produk seluruh lokasi</p>
                        <div class="space-y-3">
                            <?php if (empty($airRekapProduk)): ?><p class="py-8 text-center text-xs text-gray-400">Belum ada produk dipesan.</p><?php endif; ?>
                            <?php foreach ($airRekapProduk as $produkAir): ?>
                                <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-3">
                                    <p class="text-xs font-bold leading-5"><?php echo htmlspecialchars((string)$produkAir['nama_produk'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <span class="text-sm font-black text-blue-600 shrink-0"><?php echo number_format((int)$produkAir['total_qty']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="kas-monitor-card p-5">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Distribusi per Lokasi</p>
                            <p class="text-xs text-gray-400 mt-1">Lokasi dengan kebutuhan terbanyak dari seluruh data</p>
                        </div>
                        <a href="air_pesanan.php" class="text-[9px] font-black uppercase tracking-widest underline">Detail</a>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                        <?php if (empty($airRekapLokasi)): ?><div class="sm:col-span-2 xl:col-span-4 p-8 text-center text-xs text-gray-400 border border-gray-100">Belum ada distribusi lokasi.</div><?php endif; ?>
                        <?php foreach ($airRekapLokasi as $lokasiAir): ?>
                            <div class="border border-gray-100 bg-gray-50 p-4">
                                <p class="text-xs font-black leading-5"><?php echo htmlspecialchars((string)$lokasiAir['lokasi'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="flex items-end justify-between gap-3 mt-3">
                                    <div>
                                        <p class="text-2xl font-black text-blue-600"><?php echo number_format((int)$lokasiAir['total_qty']); ?></p>
                                        <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Unit</p>
                                    </div>
                                    <p class="text-[9px] font-bold text-gray-400"><?php echo number_format((int)$lokasiAir['total_pesanan']); ?> pesanan</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- ── Reprint Toast (hanya untuk admin & kasir) ─────────────────────────── -->
    <?php if (has_role('admin', 'kasir', 'cafe')): ?>
        <div id="reprint-status" class="fixed bottom-24 right-4 left-4 md:left-auto md:w-96 bg-white border border-gray-200 shadow-2xl z-[120] p-4 rounded-sm hidden">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Thermal Reprint</p>
                    <p id="reprint-status-text" class="text-sm font-bold mt-1">Menyiapkan struk...</p>
                </div>
                <button type="button" onclick="hideReprintStatus()" class="text-xs font-black text-gray-400 hover:text-black">&#10005;</button>
            </div>
            <div class="mt-3 flex gap-2">
                <button id="reprint-fallback-btn" type="button" onclick="openLastReceiptFallback()" class="hidden flex-1 py-2 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50">Buka Struk</button>
                <button type="button" onclick="hideReprintStatus()" class="flex-1 py-2 text-[10px] font-black uppercase bg-black text-white hover:bg-gray-800">Tutup</button>
            </div>
        </div>
    <?php endif; ?>

    <script>
        <?php if (has_role('admin', 'kasir', 'cafe')): ?>
                // ── Chart ────────────────────────────────────────────────────────────────────
                (function() {
                    var ctx = document.getElementById('salesChart').getContext('2d');
                    var isMobile = window.innerWidth < 768;
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($chartLabels); ?>,
                            datasets: [{
                                label: 'Sales (Jt)',
                                data: <?php echo json_encode($chartData); ?>,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37,99,235,0.05)',
                                fill: true,
                                tension: 0.4,
                                borderWidth: isMobile ? 1.5 : 2,
                                pointRadius: isMobile ? 2 : 4,
                                pointBackgroundColor: '#2563eb'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        borderDash: [5, 5],
                                        color: '#f0f0f0'
                                    },
                                    ticks: {
                                        font: {
                                            size: 9
                                        },
                                        color: '#999',
                                        callback: v => 'Rp' + v + 'M'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        font: {
                                            size: 9,
                                            weight: '700'
                                        },
                                        color: '#999'
                                    }
                                }
                            }
                        }
                    });
                }());

            // ── Bluetooth Thermal Reprint ─────────────────────────────────────────────────
            var BT_PRINTER_CONFIG = [{
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
            var ESC_BYTE = 0x1B,
                GS_BYTE = 0x1D;
            var ESCPOS = {
                init: [ESC_BYTE, 0x40],
                alignLeft: [ESC_BYTE, 0x61, 0x00],
                alignCenter: [ESC_BYTE, 0x61, 0x01],
                boldOn: [ESC_BYTE, 0x45, 0x01],
                boldOff: [ESC_BYTE, 0x45, 0x00],
                fontBig: [GS_BYTE, 0x21, 0x11],
                fontNormal: [GS_BYTE, 0x21, 0x00],
                feed: function(n) {
                    return [ESC_BYTE, 0x64, n];
                },
                cut: [GS_BYTE, 0x56, 0x41, 0x03]
            };
            var PRINT_W = 32,
                lastReprintFallbackUrl = '#';

            function showReprintStatus(msg, type) {
                type = type || 'info';
                var box = document.getElementById('reprint-status');
                var txt = document.getElementById('reprint-status-text');
                if (!box || !txt) return;
                txt.textContent = msg;
                txt.className = 'text-sm font-bold mt-1 ' + (type === 'error' ? 'text-red-600' : type === 'success' ? 'text-green-600' : 'text-gray-900');
                box.classList.remove('hidden');
            }

            function hideReprintStatus() {
                var b = document.getElementById('reprint-status');
                if (b) b.classList.add('hidden');
            }

            function setFallbackVisible(show) {
                var b = document.getElementById('reprint-fallback-btn');
                if (b) b.classList.toggle('hidden', !show);
            }

            function openLastReceiptFallback() {
                if (lastReprintFallbackUrl !== '#') window.open(lastReprintFallbackUrl, '_blank');
            }

            function _fmt(n) {
                return Number(n || 0).toLocaleString('id-ID');
            }

            function _lr(l, r, w) {
                var W = w || PRINT_W,
                    ls = String(l || ''),
                    rs = String(r || ''),
                    sp = Math.max(1, W - ls.length - rs.length);
                return ls + ' '.repeat(sp) + rs;
            }

            function _dash() {
                return '-'.repeat(PRINT_W);
            }

            function _solid() {
                return '='.repeat(PRINT_W);
            }

            function _enc(str) {
                var out = [];
                str = String(str || '');
                for (var i = 0; i < str.length; i++) {
                    var c = str.charCodeAt(i);
                    out.push(c < 256 ? c : 0x3F);
                }
                return out;
            }

            function buildEscPos(d) {
                var buf = [];

                function push(a) {
                    for (var i = 0; i < a.length; i++) buf.push(a[i]);
                }

                function text(s) {
                    push(_enc(String(s || '') + '\n'));
                }

                function line(s) {
                    push(_enc(String(s || '')));
                }
                push(ESCPOS.init);
                push(ESCPOS.alignCenter);
                push(ESCPOS.boldOn);
                push(ESCPOS.fontBig);
                text('KOPERASI BSDK');
                push(ESCPOS.fontNormal);
                push(ESCPOS.boldOff);
                text('MESIN KASIR / POS');
                text('REPRINT STRUK');
                push(ESCPOS.alignLeft);
                line(_dash() + '\n');
                line(_lr('No', d.invoice) + '\n');
                line(_lr('Tgl', d.tanggal) + '\n');
                line(_lr('Kasir', String(d.operator || '').substring(0, 18)) + '\n');
                if (d.member_nama) {
                    line(_lr('Member', String(d.member_nama).substring(0, 18)) + '\n');
                    if (d.member_kode) line(_lr('Kode', String(d.member_kode)) + '\n');
                }
                line(_dash() + '\n');
                (d.items || []).forEach(function(item) {
                    push(ESCPOS.boldOn);
                    text(String(item.nama || 'PRODUK').substring(0, 32));
                    push(ESCPOS.boldOff);
                    line(_lr((item.qty || 0) + ' x ' + _fmt(item.harga_normal), _fmt(item.normal_item)) + '\n');
                    if (Number(item.diskon_item || 0) > 0) {
                        if (item.nama_diskon) text('Promo: ' + String(item.nama_diskon).substring(0, 26));
                        line(_lr('Disc/pcs ' + _fmt(item.diskon_satuan) + ' x ' + item.qty, '-' + _fmt(item.diskon_item)) + '\n');
                        push(ESCPOS.boldOn);
                        line(_lr('Subtotal', _fmt(item.subtotal_item)) + '\n');
                        push(ESCPOS.boldOff);
                    }
                });
                line(_dash() + '\n');
                line(_lr('SUBTOTAL', _fmt(d.subtotal_normal)) + '\n');
                if (Number(d.diskon_barang || 0) > 0) line(_lr('DISKON BARANG', '-' + _fmt(d.diskon_barang)) + '\n');
                if (Number(d.diskon_transaksi || 0) > 0) {
                    line(_lr('DISKON PROMO', '-' + _fmt(d.diskon_transaksi)) + '\n');
                    if (d.nama_diskon_trx) text('Promo: ' + String(d.nama_diskon_trx).substring(0, 26));
                }
                if (Number(d.point_dipakai || 0) > 0) {
                    line(_lr('POINT DIPAKAI', '-' + d.point_dipakai + ' pt') + '\n');
                    if (Number(d.nilai_point || 0) > 0) line(_lr('NILAI POINT', '-' + _fmt(d.nilai_point)) + '\n');
                }
                if (Number(d.total_diskon || 0) > 0) {
                    push(ESCPOS.boldOn);
                    line(_lr('TOTAL DISKON', '-' + _fmt(d.total_diskon)) + '\n');
                    push(ESCPOS.boldOff);
                }
                line(_solid() + '\n');
                push(ESCPOS.boldOn);
                line(_lr('TOTAL BAYAR', _fmt(d.total_bayar)) + '\n');
                push(ESCPOS.boldOff);
                line(_lr('TUNAI/QRIS', _fmt(d.bayar)) + '\n');
                line(_lr('KEMBALI', _fmt(d.kembalian)) + '\n');
                if (d.member_nama && Number(d.point_dapat || 0) > 0) {
                    line(_dash() + '\n');
                    push(ESCPOS.boldOn);
                    text('POINT MEMBER');
                    push(ESCPOS.boldOff);
                    line(_lr('Point Didapat', '+' + _fmt(d.point_dapat) + ' pt') + '\n');
                    if (d.member_point_total) line(_lr('Total Point', _fmt(d.member_point_total) + ' pt') + '\n');
                }
                line(_dash() + '\n');
                push(ESCPOS.alignCenter);
                text('BARANG YANG SUDAH DIBELI');
                text('TIDAK DAPAT DITUKAR/DIKEMBALIKAN');
                text('');
                text('*** TERIMA KASIH ***');
                push(ESCPOS.alignLeft);
                push(ESCPOS.feed(5));
                push(ESCPOS.cut);
                return new Uint8Array(buf);
            }

            function btSendData(ch, data) {
                var C = 100,
                    chain = Promise.resolve();
                for (var p = 0; p < data.length; p += C) {
                    (function(s) {
                        chain = chain.then(function() {
                            return ch.writeValueWithoutResponse(s);
                        }).then(function() {
                            return new Promise(function(r) {
                                setTimeout(r, 60);
                            });
                        });
                    }(data.slice(p, p + C)));
                }
                return chain;
            }

            function btConnectAndPrint(device, data) {
                return device.gatt.connect().then(function(server) {
                    function t(i) {
                        if (i >= BT_PRINTER_CONFIG.length) return Promise.reject(new Error('UUID printer tidak cocok.'));
                        return server.getPrimaryService(BT_PRINTER_CONFIG[i].service).then(function(s) {
                            return s.getCharacteristic(BT_PRINTER_CONFIG[i].characteristic);
                        }).catch(function() {
                            return t(i + 1);
                        });
                    }
                    return t(0).then(function(c) {
                        return btSendData(c, data).then(function() {
                            try {
                                server.disconnect();
                            } catch (e) {}
                        });
                    });
                });
            }

            async function reprintThermal(id, invoice) {
                lastReprintFallbackUrl = 'struk.php?invoice=' + encodeURIComponent(invoice || '') + '&print=1';
                setFallbackVisible(false);
                showReprintStatus('Mengambil data struk...', 'info');
                try {
                    var res = await fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>?action=reprint_data&id=' + encodeURIComponent(id), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    var json = await res.json();
                    if (!json.success) throw new Error(json.message || 'Data struk tidak ditemukan.');
                    var data = json.data;
                    if (data.invoice) lastReprintFallbackUrl = 'struk.php?invoice=' + encodeURIComponent(data.invoice) + '&print=1';
                    if (!navigator.bluetooth) {
                        setFallbackVisible(true);
                        throw new Error('Web Bluetooth tidak tersedia.');
                    }
                    showReprintStatus('Pilih printer Bluetooth...', 'info');
                    var escData = buildEscPos(data);
                    var device = await navigator.bluetooth.requestDevice({
                        acceptAllDevices: true,
                        optionalServices: BT_PRINTER_CONFIG.map(function(c) {
                            return c.service;
                        })
                    });
                    showReprintStatus('Menghubungkan ke printer...', 'info');
                    await btConnectAndPrint(device, escData);
                    showReprintStatus('Struk berhasil dicetak ulang.', 'success');
                } catch (err) {
                    var msg = (err && err.name === 'NotFoundError') ? 'Tidak ada printer yang dipilih.' : (err.message || 'Gagal reprint struk.');
                    setFallbackVisible(true);
                    showReprintStatus(msg, (err && err.name === 'NotFoundError') ? 'info' : 'error');
                }
            }
        <?php endif; // end JS untuk admin|kasir|cafe 
        ?>
    </script>

</body>

</html>