<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'activity_helper.php';

$activeMenu = 'simpanan';
$pageTitle  = 'Import Simpanan';
$backUrl    = 'simpanan.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

// Cek role — hanya admin dan petugas_sp
$userRole = isset($_SESSION['user']['role']) ? $_SESSION['user']['role'] : 'kasir';
if (!in_array($userRole, ['admin', 'ksp'], true)) {
    header('Location: dashboard.php');
    exit;
}

// ── Helper ───────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    /**
     * @param mixed $v
     * @return string
     */
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rupiah_sp')) {
    /**
     * @param mixed $n
     * @return string
     */
    function rupiah_sp($n)
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }
}

if (!function_exists('json_response_sp')) {
    /**
     * @param array<string,mixed> $data
     * @param int $status
     * @return void
     */
    function json_response_sp(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}

$error   = '';
$success = '';
$importLog = [];

if (isset($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ── Proses import JSON dari browser (tanpa PhpSpreadsheet) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';

    if (strpos($contentType, 'application/json') !== false) {
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            json_response_sp(['ok' => false, 'message' => 'Payload import tidak valid.'], 400);
        }

        $action = isset($payload['action']) ? (string)$payload['action'] : '';
        if ($action !== 'import_json') {
            json_response_sp(['ok' => false, 'message' => 'Action import tidak valid.'], 400);
        }

        $bulan   = (int)(isset($payload['bulan']) ? $payload['bulan'] : 0);
        $tahun   = (int)(isset($payload['tahun']) ? $payload['tahun'] : 0);
        $oriName = trim((string)(isset($payload['filename']) ? $payload['filename'] : 'import_excel.xlsx'));
        $sheets  = isset($payload['sheets']) && is_array($payload['sheets']) ? $payload['sheets'] : [];

        if ($bulan < 1 || $bulan > 12 || $tahun < 2000) {
            json_response_sp(['ok' => false, 'message' => 'Bulan/tahun tidak valid.'], 422);
        }
        if (empty($sheets)) {
            json_response_sp(['ok' => false, 'message' => 'Tidak ada data Excel yang bisa diimport.'], 422);
        }

        try {
            $targetSheets = ['PNS', 'PPPK', 'PPNPN', 'NON PPNPN'];
            $targetUpper  = array_map('strtoupper', $targetSheets);
            $totalBaris   = 0;
            $totalSheet   = 0;
            $userId       = (int)(isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0);

            $logInsert = $pdo->prepare("
                INSERT INTO simpanan_import (filename, sheet_name, bulan, tahun, jumlah_baris, status, imported_by)
                VALUES (:fn, :sheet, :bulan, :tahun, 0, 'proses', :uid)
            ");

            $insertSimpanan = $pdo->prepare("
                INSERT INTO simpanan (member_id, jenis, jumlah, bulan, tahun, keterangan, import_id)
                VALUES (:mid, :jenis, :jumlah, :bulan, :tahun, :ket, :iid)
                ON DUPLICATE KEY UPDATE jumlah = VALUES(jumlah)
            ");

            $memberStmt = $pdo->prepare("SELECT id FROM member WHERE nama LIKE :nama LIMIT 1");
            $updateLog = $pdo->prepare("UPDATE simpanan_import SET jumlah_baris = :jml, status = 'selesai' WHERE id = :id");

            $pdo->beginTransaction();

            foreach ($sheets as $sheetData) {
                if (!is_array($sheetData)) {
                    continue;
                }

                $sheetNameClean = trim((string)(isset($sheetData['sheet_name']) ? $sheetData['sheet_name'] : ''));
                if ($sheetNameClean === '' || !in_array(strtoupper($sheetNameClean), $targetUpper, true)) {
                    continue;
                }

                $rows = isset($sheetData['rows']) && is_array($sheetData['rows']) ? $sheetData['rows'] : [];
                if (empty($rows)) {
                    continue;
                }

                $logInsert->execute([
                    ':fn'    => $oriName,
                    ':sheet' => $sheetNameClean,
                    ':bulan' => $bulan,
                    ':tahun' => $tahun,
                    ':uid'   => $userId
                ]);
                $importId = (int)$pdo->lastInsertId();
                $barisMasuk = 0;

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $nama = trim((string)(isset($row['nama']) ? $row['nama'] : ''));
                    if ($nama === '' || is_numeric($nama)) {
                        continue;
                    }

                    $memberStmt->execute([':nama' => '%' . $nama . '%']);
                    $mRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$mRow) {
                        continue;
                    }

                    $memberId = (int)$mRow['id'];
                    $jenisList = [
                        'wajib'    => isset($row['wajib']) ? $row['wajib'] : 0,
                        'pokok'    => isset($row['pokok']) ? $row['pokok'] : 0,
                        'sukarela' => isset($row['sukarela']) ? $row['sukarela'] : 0,
                    ];

                    foreach ($jenisList as $jenis => $jumlahRaw) {
                        $jumlah = (int)preg_replace('/[^0-9\-]/', '', (string)$jumlahRaw);
                        if ($jumlah <= 0) {
                            continue;
                        }

                        $insertSimpanan->execute([
                            ':mid'    => $memberId,
                            ':jenis'  => $jenis,
                            ':jumlah' => $jumlah,
                            ':bulan'  => $bulan,
                            ':tahun'  => $tahun,
                            ':ket'    => $sheetNameClean,
                            ':iid'    => $importId
                        ]);
                        $barisMasuk++;
                    }
                }

                $updateLog->execute([':jml' => $barisMasuk, ':id' => $importId]);
                $totalBaris += $barisMasuk;
                $totalSheet++;
            }

            if ($totalSheet === 0) {
                $pdo->rollBack();
                json_response_sp(['ok' => false, 'message' => 'Sheet PNS, PPPK, PPNPN, atau NON PPNPN tidak ditemukan / tidak ada data valid.'], 422);
            }

            $pdo->commit();

            catat_aktivitas($pdo, 'import', 'Simpanan', "Import Excel simpanan bulan $bulan/$tahun — $totalBaris baris");
            $_SESSION['flash_success'] = "Import berhasil! Total $totalBaris data simpanan diproses untuk bulan $bulan/$tahun.";

            json_response_sp([
                'ok' => true,
                'message' => $_SESSION['flash_success'],
                'total_baris' => $totalBaris,
                'total_sheet' => $totalSheet
            ]);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response_sp(['ok' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 500);
        }
    }
}

$bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// ── Ambil log import terakhir ─────────────────────────────────────────────────
try {
    $logStmt = $pdo->query("
        SELECT si.*, u.nama AS imported_nama
        FROM simpanan_import si
        LEFT JOIN users u ON u.id = si.imported_by
        ORDER BY si.created_at DESC
        LIMIT 20
    ");
    $importLog = $logStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $importLog = [];
}

// ── Ringkasan simpanan bulan ini ─────────────────────────────────────────────
$bulanAktif = (int)date('n');
$tahunAktif = (int)date('Y');

try {
    $sumStmt = $pdo->prepare("
        SELECT
            jenis,
            SUM(jumlah) AS total,
            COUNT(DISTINCT member_id) AS jumlah_member
        FROM simpanan
        WHERE bulan = :b AND tahun = :t
        GROUP BY jenis
    ");
    $sumStmt->execute([':b' => $bulanAktif, ':t' => $tahunAktif]);
    $ringkasanBulan = [];
    foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ringkasanBulan[$row['jenis']] = $row;
    }
} catch (Exception $e) {
    $ringkasanBulan = [];
}

$rightActionHtml = '
<a href="simpanan.php" class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:bg-gray-50 transition-all">
    Kelola Simpanan
</a>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Simpanan — Koperasi BSDK</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        @media (min-width: 1024px) {

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

<body class="antialiased pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">

        <!-- Alert -->
        <div id="js-alert" class="hidden mb-6 flex items-start gap-3 px-4 py-3 text-xs font-bold"></div>

        <?php if ($success): ?>
            <div class="mb-6 flex items-start gap-3 bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-xs font-bold">
                <span class="text-green-500 mt-0.5">&#10003;</span>
                <?php echo h($success); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-6 flex items-start gap-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-xs font-bold">
                <span class="text-red-500 mt-0.5">&#9888;</span>
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <!-- Ringkasan bulan ini -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <?php
            $jenisInfo = [
                'pokok'    => ['label' => 'Simpanan Pokok',    'color' => 'text-blue-600'],
                'wajib'    => ['label' => 'Simpanan Wajib',    'color' => 'text-green-600'],
                'sukarela' => ['label' => 'Simpanan Sukarela', 'color' => 'text-purple-600'],
            ];
            foreach ($jenisInfo as $jenis => $info):
                $data = isset($ringkasanBulan[$jenis]) ? $ringkasanBulan[$jenis] : ['total' => 0, 'jumlah_member' => 0];
            ?>
                <div class="bg-white border border-gray-100 p-5">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo $info['label']; ?></p>
                    <p class="text-2xl font-bold <?php echo $info['color']; ?>"><?php echo rupiah_sp($data['total']); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?php echo number_format($data['jumlah_member']); ?> member · <?php echo $bulanNama[$bulanAktif]; ?> <?php echo $tahunAktif; ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

            <!-- Form Upload -->
            <div class="bg-white border border-gray-100 p-6">
                <h2 class="text-xs font-bold uppercase tracking-widest mb-6">Import Excel Simpanan</h2>

                <!-- Panduan format -->
                <div class="bg-amber-50 border border-amber-200 p-4 mb-6 text-xs font-semibold text-amber-800 leading-relaxed">
                    <p class="font-black mb-2">Format Excel yang didukung:</p>
                    <ul class="space-y-1 list-disc list-inside">
                        <li>Sheet: <strong>PNS, PPPK, PPNPN, NON PPNPN</strong></li>
                        <li>Kolom wajib: <strong>NAMA PEGAWAI, WAJIB, POKOK, SUKARELA</strong></li>
                        <li>Pastikan nama member di Excel sesuai data member sistem</li>
                        <li>File .xlsx dari format rekap koperasi BSDK langsung bisa digunakan</li>
                    </ul>
                </div>

                <form id="excel-form" onsubmit="return false;">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Bulan *</label>
                            <select id="bulan" name="bulan" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m === $bulanAktif ? 'selected' : ''; ?>>
                                        <?php echo $bulanNama[$m]; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Tahun *</label>
                            <select id="tahun" name="tahun" class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black" required>
                                <?php for ($y = (int)date('Y'); $y >= 2023; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y === $tahunAktif ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">File Excel (.xlsx) *</label>
                        <div class="border-2 border-dashed border-gray-200 p-6 text-center hover:border-gray-400 transition-colors">
                            <input type="file" name="file_excel" accept=".xlsx,.xls" class="hidden" id="file-input" required onchange="handleExcelFile(this)">
                            <label for="file-input" class="cursor-pointer">
                                <svg class="w-8 h-8 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p id="file-label" class="text-xs font-bold text-gray-400 uppercase tracking-widest">Klik untuk pilih file Excel</p>
                                <p class="text-[10px] text-gray-300 mt-1">Format rekap BSDK (.xlsx)</p>
                            </label>
                        </div>
                    </div>

                    <button type="button" onclick="parseSelectedExcel()" class="w-full py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">
                        Upload &amp; Preview
                    </button>
                </form>

                <div id="import-ready" class="hidden mt-6 border border-green-200 bg-green-50 p-4">
                    <p class="text-xs font-bold text-green-800 mb-1">File siap diimport:</p>
                    <p id="ready-file-name" class="text-[10px] font-semibold text-green-700"></p>
                    <p id="ready-period" class="text-[10px] text-green-600 mt-1"></p>
                    <p id="ready-summary" class="text-[10px] text-green-600 mt-1"></p>
                    <div class="mt-3">
                        <button type="button" id="btn-import" onclick="confirmImport()" class="w-full py-2.5 bg-green-700 text-white text-[10px] font-black uppercase tracking-widest hover:bg-green-800 transition-all">
                            Konfirmasi &amp; Mulai Import
                        </button>
                    </div>
                    <div class="mt-2">
                        <button type="button" onclick="batalImport()" class="w-full py-2 border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50 transition-all">
                            Batal
                        </button>
                    </div>
                </div>
            </div>

            <!-- Log Import -->
            <div class="bg-white border border-gray-100 p-6">
                <h2 class="text-xs font-bold uppercase tracking-widest mb-6">Riwayat Import</h2>

                <?php if (empty($importLog)): ?>
                    <p class="text-xs text-gray-400 text-center py-8">Belum ada riwayat import</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($importLog as $log): ?>
                            <div class="border border-gray-100 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold truncate"><?php echo h($log['filename']); ?></p>
                                        <p class="text-[10px] text-gray-400 mt-0.5">
                                            Sheet: <?php echo h($log['sheet_name']); ?> ·
                                            <?php
                                            $bln = (int)$log['bulan'];
                                            echo $bulanNama[$bln] . ' ' . $log['tahun'];
                                            ?>
                                        </p>
                                        <p class="text-[10px] text-gray-400 mt-0.5">
                                            <?php echo number_format($log['jumlah_baris']); ?> baris ·
                                            <?php echo h($log['imported_nama'] ?? 'Sistem'); ?> ·
                                            <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                        </p>
                                    </div>
                                    <span class="text-[9px] font-black uppercase px-2 py-1 flex-shrink-0
                                    <?php echo $log['status'] === 'selesai' ? 'bg-green-50 text-green-700 border border-green-200' : ($log['status'] === 'gagal' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-amber-50 text-amber-700 border border-amber-200'); ?>">
                                        <?php echo ucfirst(h($log['status'])); ?>
                                    </span>
                                </div>
                                <?php if ($log['catatan']): ?>
                                    <p class="text-[10px] text-gray-400 mt-2"><?php echo h($log['catatan']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>


        <!-- Modal Preview Import -->
        <div id="preview-modal" class="fixed inset-0 z-[100] hidden">
            <div class="absolute inset-0 bg-black/40" onclick="closePreviewModal()"></div>
            <div class="relative w-full h-full bg-white flex flex-col overflow-hidden">
                <div class="flex items-start justify-between gap-4 px-4 sm:px-6 py-4 border-b border-gray-100 bg-white">
                    <div class="min-w-0">
                        <h2 class="text-xs font-black uppercase tracking-widest text-gray-900">Preview Import Simpanan</h2>
                        <p id="preview-meta" class="text-[10px] text-gray-400 font-semibold mt-1 truncate">Belum ada data</p>
                    </div>
                    <button type="button" onclick="closePreviewModal()" class="w-10 h-10 border border-gray-200 text-gray-500 hover:bg-gray-50 text-lg leading-none flex-shrink-0">
                        &times;
                    </button>
                </div>

                <div class="px-4 sm:px-6 py-3 bg-amber-50 border-b border-amber-100 text-[10px] font-semibold text-amber-800">
                    Preview ini hanya menampilkan data yang terbaca dari Excel. Saat konfirmasi import, sistem tetap mencocokkan nama dengan data member di database.
                </div>

                <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-white">
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-center">
                        <div class="lg:col-span-5">
                            <input type="text" id="preview-search" oninput="previewSearchChanged()" placeholder="Cari nama pegawai atau sheet..." class="w-full bg-gray-50 border border-gray-100 px-4 py-2.5 text-xs font-semibold focus:outline-none focus:border-black">
                        </div>
                        <div class="lg:col-span-3">
                            <select id="preview-page-size" onchange="changePreviewPageSize(this.value)" class="w-full bg-gray-50 border border-gray-100 px-4 py-2.5 text-xs font-bold focus:outline-none focus:border-black">
                                <option value="50">50 data / halaman</option>
                                <option value="100" selected>100 data / halaman</option>
                                <option value="250">250 data / halaman</option>
                                <option value="500">500 data / halaman</option>
                            </select>
                        </div>
                        <div class="lg:col-span-4 text-[10px] font-semibold text-gray-400 lg:text-right">
                            Klik kolom nama/nominal untuk edit sebelum import.
                        </div>
                    </div>
                    <div class="mt-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <label class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-500">
                            <input type="checkbox" id="preview-select-all" onchange="toggleSelectAllVisible(this.checked)" class="w-4 h-4 accent-black">
                            Pilih semua di halaman ini
                        </label>
                        <div class="flex flex-wrap items-center gap-2">
                            <span id="preview-page-info" class="text-[10px] font-bold text-gray-400">Halaman 1</span>
                            <span id="preview-selected-count" class="text-[10px] font-bold text-gray-400">0 data dipilih</span>
                            <button type="button" onclick="deleteSelectedPreviewRows()" id="btn-delete-selected" class="px-3 py-2 border border-red-200 text-red-700 text-[10px] font-black uppercase tracking-widest hover:bg-red-50 transition-all disabled:opacity-40 disabled:cursor-not-allowed" disabled>
                                Hapus Terpilih
                            </button>
                        </div>
                    </div>
                </div>

                <div class="overflow-auto flex-1 bg-white">
                    <table class="w-full text-left border-collapse min-w-[920px]">
                        <thead class="sticky top-0 bg-gray-50 z-10 shadow-sm">
                            <tr class="text-[10px] font-black uppercase tracking-widest text-gray-400 border-b border-gray-100">
                                <th class="px-4 py-3 w-10"></th>
                                <th class="px-4 py-3 w-12">No</th>
                                <th class="px-4 py-3 w-32">Sheet</th>
                                <th class="px-4 py-3 min-w-[260px]">Nama Pegawai</th>
                                <th class="px-4 py-3 text-right w-36">Wajib</th>
                                <th class="px-4 py-3 text-right w-36">Pokok</th>
                                <th class="px-4 py-3 text-right w-36">Sukarela</th>
                                <th class="px-4 py-3 text-center w-24">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="preview-tbody" class="text-xs font-semibold text-gray-700"></tbody>
                    </table>
                </div>

                <div class="px-4 sm:px-6 py-3 border-t border-gray-100 bg-gray-50 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                    <div class="flex items-center justify-center lg:justify-start gap-2">
                        <button type="button" onclick="changePreviewPage(-1)" id="btn-preview-prev" class="px-3 py-2 border border-gray-200 bg-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Sebelumnya</button>
                        <span id="preview-pagination-label" class="text-[10px] font-bold text-gray-500 min-w-[110px] text-center">Halaman 1 / 1</span>
                        <button type="button" onclick="changePreviewPage(1)" id="btn-preview-next" class="px-3 py-2 border border-gray-200 bg-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Berikutnya</button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:w-[520px]">
                        <button type="button" onclick="closePreviewModal()" class="py-3 bg-white border border-gray-200 text-[10px] font-black uppercase tracking-widest hover:bg-gray-50 transition-all">
                            Cek Lagi
                        </button>
                        <button type="button" onclick="batalImport(); closePreviewModal();" class="py-3 bg-white border border-red-200 text-red-700 text-[10px] font-black uppercase tracking-widest hover:bg-red-50 transition-all">
                            Batal
                        </button>
                        <button type="button" id="btn-import" onclick="confirmImport()" class="py-3 bg-green-700 text-white text-[10px] font-black uppercase tracking-widest hover:bg-green-800 transition-all">
                            Konfirmasi &amp; Import
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <script>
        var selectedExcelFile = null;
        var importPayload = null;
        var selectedPreviewRows = {};
        var previewPage = 1;
        var previewPageSize = 100;
        var previewCurrentKeys = [];
        var bulanNama = <?php echo json_encode($bulanNama); ?>;

        function showAlert(type, message) {
            var box = document.getElementById('js-alert');
            var isSuccess = type === 'success';
            box.className = 'mb-6 flex items-start gap-3 px-4 py-3 text-xs font-bold ' +
                (isSuccess ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800');
            box.innerHTML = '<span class="' + (isSuccess ? 'text-green-500' : 'text-red-500') + ' mt-0.5">' +
                (isSuccess ? '&#10003;' : '&#9888;') + '</span><span>' + escapeHtml(message) + '</span>';
            box.classList.remove('hidden');
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function handleExcelFile(input) {
            var label = document.getElementById('file-label');
            selectedExcelFile = input.files && input.files[0] ? input.files[0] : null;
            importPayload = null;
            selectedPreviewRows = {};
            previewPage = 1;
            document.getElementById('import-ready').classList.add('hidden');

            if (selectedExcelFile) {
                label.textContent = selectedExcelFile.name;
            } else {
                label.textContent = 'Klik untuk pilih file Excel';
            }
        }

        function normalizeCell(value) {
            if (value === null || value === undefined) return '';
            return String(value).trim();
        }

        function toNumber(value) {
            if (value === null || value === undefined || value === '') return 0;
            if (typeof value === 'number') return Math.round(value);
            var cleaned = String(value).replace(/[^0-9\-]/g, '');
            return cleaned ? parseInt(cleaned, 10) : 0;
        }

        function parseSelectedExcel() {
            if (!selectedExcelFile) {
                showAlert('error', 'Silakan pilih file Excel terlebih dahulu.');
                return;
            }

            var ext = selectedExcelFile.name.split('.').pop().toLowerCase();
            if (['xlsx', 'xls'].indexOf(ext) === -1) {
                showAlert('error', 'File harus berformat .xlsx atau .xls');
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = new Uint8Array(e.target.result);
                    var workbook = XLSX.read(data, {
                        type: 'array'
                    });
                    var targetSheets = ['PNS', 'PPPK', 'PPNPN', 'NON PPNPN'];
                    var targetUpper = targetSheets.map(function(s) {
                        return s.toUpperCase();
                    });
                    var sheets = [];
                    var totalRows = 0;

                    workbook.SheetNames.forEach(function(sheetName) {
                        var sheetNameClean = String(sheetName || '').trim();
                        if (targetUpper.indexOf(sheetNameClean.toUpperCase()) === -1) return;

                        var worksheet = workbook.Sheets[sheetName];
                        var rows = XLSX.utils.sheet_to_json(worksheet, {
                            header: 1,
                            defval: ''
                        });
                        if (!rows.length) return;

                        var headerRow = -1;
                        var maxHeaderScan = Math.min(10, rows.length);
                        for (var r = 0; r < maxHeaderScan; r++) {
                            for (var c = 0; c < Math.min(5, rows[r].length); c++) {
                                var val = normalizeCell(rows[r][c]).toUpperCase();
                                if (val.indexOf('NAMA PEGAWAI') !== -1) {
                                    headerRow = r;
                                    break;
                                }
                            }
                            if (headerRow !== -1) break;
                        }

                        if (headerRow === -1) return;

                        var subHeaderRow = headerRow + 1;
                        var maxCols = 0;
                        rows.forEach(function(row) {
                            if (row.length > maxCols) maxCols = row.length;
                        });

                        var colNama = -1;
                        var colWajib = -1;
                        var colPokok = -1;
                        var colSukarela = -1;

                        for (var col = 0; col < maxCols; col++) {
                            var hVal = normalizeCell(rows[headerRow] && rows[headerRow][col]).toUpperCase();
                            var sVal = normalizeCell(rows[subHeaderRow] && rows[subHeaderRow][col]).toUpperCase();

                            if (hVal.indexOf('NAMA') !== -1 || sVal.indexOf('NAMA') !== -1) {
                                colNama = col;
                            }
                            if (colWajib === -1 && sVal.indexOf('WAJIB') !== -1) {
                                colWajib = col;
                            }
                            if (colPokok === -1 && sVal.indexOf('POKOK') !== -1) {
                                colPokok = col;
                            }
                            if (colSukarela === -1 && sVal.indexOf('SUKARELA') !== -1) {
                                colSukarela = col;
                            }
                        }

                        if (colNama === -1) return;

                        var dataRows = [];
                        for (var i = subHeaderRow + 1; i < rows.length; i++) {
                            var row = rows[i] || [];
                            var nama = normalizeCell(row[colNama]);
                            if (!nama || !isNaN(nama)) continue;

                            var item = {
                                nama: nama,
                                wajib: colWajib > -1 ? toNumber(row[colWajib]) : 0,
                                pokok: colPokok > -1 ? toNumber(row[colPokok]) : 0,
                                sukarela: colSukarela > -1 ? toNumber(row[colSukarela]) : 0
                            };

                            if (item.wajib > 0 || item.pokok > 0 || item.sukarela > 0) {
                                dataRows.push(item);
                            }
                        }

                        if (dataRows.length > 0) {
                            sheets.push({
                                sheet_name: sheetNameClean,
                                rows: dataRows
                            });
                            totalRows += dataRows.length;
                        }
                    });

                    if (sheets.length === 0) {
                        showAlert('error', 'Data tidak ditemukan. Pastikan sheet PNS, PPPK, PPNPN, atau NON PPNPN berisi header NAMA PEGAWAI dan kolom WAJIB/POKOK/SUKARELA.');
                        return;
                    }

                    var bulan = parseInt(document.getElementById('bulan').value, 10);
                    var tahun = parseInt(document.getElementById('tahun').value, 10);

                    importPayload = {
                        action: 'import_json',
                        filename: selectedExcelFile.name,
                        bulan: bulan,
                        tahun: tahun,
                        sheets: sheets
                    };

                    document.getElementById('ready-file-name').textContent = selectedExcelFile.name;
                    document.getElementById('ready-period').textContent = 'Periode: ' + bulanNama[bulan] + ' ' + tahun;
                    document.getElementById('ready-summary').textContent = sheets.length + ' sheet terbaca · ' + totalRows + ' baris siap dicek ke data member';
                    document.getElementById('import-ready').classList.remove('hidden');
                    var previewSearch = document.getElementById('preview-search');
                    if (previewSearch) previewSearch.value = '';
                    selectedPreviewRows = {};
                    previewPage = 1;
                    renderPreviewModal(importPayload);
                    openPreviewModal();
                    showAlert('success', 'File berhasil dibaca. Lakukan konfirmasi import.');
                } catch (err) {
                    showAlert('error', 'Gagal membaca file Excel: ' + err.message);
                }
            };
            reader.readAsArrayBuffer(selectedExcelFile);
        }


        function formatNumber(value) {
            var n = parseInt(value || 0, 10);
            return n.toLocaleString('id-ID');
        }

        function openPreviewModal() {
            document.getElementById('preview-modal').classList.remove('hidden');
        }

        function closePreviewModal() {
            document.getElementById('preview-modal').classList.add('hidden');
        }

        function getPreviewRows(payload) {
            var rows = [];
            if (!payload || !payload.sheets) return rows;
            payload.sheets.forEach(function(sheet, sheetIndex) {
                sheet.rows.forEach(function(row, rowIndex) {
                    rows.push({
                        key: sheetIndex + ':' + rowIndex,
                        sheetIndex: sheetIndex,
                        rowIndex: rowIndex,
                        sheetName: sheet.sheet_name,
                        row: row
                    });
                });
            });
            return rows;
        }

        function getFilteredPreviewRows(payload) {
            var qInput = document.getElementById('preview-search');
            var keyword = qInput ? qInput.value.toLowerCase().trim() : '';
            var rows = getPreviewRows(payload);
            if (!keyword) return rows;
            return rows.filter(function(item) {
                var haystack = (String(item.sheetName || '') + ' ' + String(item.row.nama || '')).toLowerCase();
                return haystack.indexOf(keyword) !== -1;
            });
        }

        function previewSearchChanged() {
            previewPage = 1;
            renderPreviewModal(importPayload);
        }

        function changePreviewPageSize(value) {
            previewPageSize = parseInt(value || 100, 10);
            previewPage = 1;
            renderPreviewModal(importPayload);
        }

        function changePreviewPage(direction) {
            var filteredRows = getFilteredPreviewRows(importPayload);
            var totalPages = Math.max(1, Math.ceil(filteredRows.length / previewPageSize));
            previewPage = Math.min(totalPages, Math.max(1, previewPage + direction));
            renderPreviewModal(importPayload);
        }

        function renderPreviewModal(payload) {
            var tbody = document.getElementById('preview-tbody');
            var meta = document.getElementById('preview-meta');
            var qInput = document.getElementById('preview-search');
            var keyword = qInput ? qInput.value.toLowerCase().trim() : '';
            var html = '';
            var allRows = getPreviewRows(payload);
            var filteredRows = getFilteredPreviewRows(payload);
            var totalRows = allRows.length;
            var totalWajib = 0;
            var totalPokok = 0;
            var totalSukarela = 0;

            allRows.forEach(function(item) {
                totalWajib += parseInt(item.row.wajib || 0, 10);
                totalPokok += parseInt(item.row.pokok || 0, 10);
                totalSukarela += parseInt(item.row.sukarela || 0, 10);
            });

            if (!payload || !payload.sheets) {
                tbody.innerHTML = '<tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada data preview</td></tr>';
                meta.textContent = 'Belum ada data';
                updatePaginationControls(0, 1, 1, []);
                return;
            }

            var totalPages = Math.max(1, Math.ceil(filteredRows.length / previewPageSize));
            if (previewPage > totalPages) previewPage = totalPages;
            if (previewPage < 1) previewPage = 1;
            var start = (previewPage - 1) * previewPageSize;
            var pageRows = filteredRows.slice(start, start + previewPageSize);
            previewCurrentKeys = pageRows.map(function(item) {
                return item.key;
            });

            pageRows.forEach(function(item, pageIndex) {
                var row = item.row;
                var sheetIndex = item.sheetIndex;
                var rowIndex = item.rowIndex;
                var rowKey = item.key;
                var no = start + pageIndex + 1;
                html += '<tr class="border-b border-gray-50 hover:bg-gray-50">' +
                    '<td class="px-4 py-3 text-center"><input type="checkbox" class="preview-row-check w-4 h-4 accent-black" data-key="' + rowKey + '" onchange="togglePreviewRowSelection(\'' + rowKey + '\', this.checked)" ' + (selectedPreviewRows[rowKey] ? 'checked' : '') + '></td>' +
                    '<td class="px-4 py-3 text-gray-400">' + no + '</td>' +
                    '<td class="px-4 py-3 text-gray-500 whitespace-nowrap">' + escapeHtml(item.sheetName) + '</td>' +
                    '<td class="px-4 py-2 min-w-[260px]">' +
                    '<input type="text" value="' + escapeHtml(row.nama) + '" onchange="updatePreviewValue(' + sheetIndex + ',' + rowIndex + ',\'nama\',this.value)" class="w-full bg-white border border-gray-100 px-2 py-1.5 text-xs font-bold text-gray-900 focus:outline-none focus:border-black">' +
                    '</td>' +
                    '<td class="px-4 py-2 text-right min-w-[120px]">' +
                    '<input type="number" min="0" value="' + parseInt(row.wajib || 0, 10) + '" onchange="updatePreviewValue(' + sheetIndex + ',' + rowIndex + ',\'wajib\',this.value)" class="w-full bg-white border border-gray-100 px-2 py-1.5 text-xs font-semibold text-right focus:outline-none focus:border-black">' +
                    '</td>' +
                    '<td class="px-4 py-2 text-right min-w-[120px]">' +
                    '<input type="number" min="0" value="' + parseInt(row.pokok || 0, 10) + '" onchange="updatePreviewValue(' + sheetIndex + ',' + rowIndex + ',\'pokok\',this.value)" class="w-full bg-white border border-gray-100 px-2 py-1.5 text-xs font-semibold text-right focus:outline-none focus:border-black">' +
                    '</td>' +
                    '<td class="px-4 py-2 text-right min-w-[120px]">' +
                    '<input type="number" min="0" value="' + parseInt(row.sukarela || 0, 10) + '" onchange="updatePreviewValue(' + sheetIndex + ',' + rowIndex + ',\'sukarela\',this.value)" class="w-full bg-white border border-gray-100 px-2 py-1.5 text-xs font-semibold text-right focus:outline-none focus:border-black">' +
                    '</td>' +
                    '<td class="px-4 py-2 text-center">' +
                    '<button type="button" onclick="deletePreviewRow(' + sheetIndex + ',' + rowIndex + ')" class="px-2 py-1 border border-red-100 text-red-600 hover:bg-red-50 text-[10px] font-black uppercase">Hapus</button>' +
                    '</td>' +
                    '</tr>';
            });

            tbody.innerHTML = html || '<tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada data preview yang cocok</td></tr>';
            meta.textContent = payload.filename + ' · ' + bulanNama[payload.bulan] + ' ' + payload.tahun + ' · ' + payload.sheets.length + ' sheet · ' + totalRows + ' baris' + (keyword ? ' · hasil cari ' + filteredRows.length + ' baris' : '') + ' · Total: Rp ' + formatNumber(totalWajib + totalPokok + totalSukarela);
            updatePaginationControls(filteredRows.length, previewPage, totalPages, previewCurrentKeys);
        }

        function updatePaginationControls(filteredCount, currentPage, totalPages, visibleKeys) {
            var pageInfo = document.getElementById('preview-page-info');
            var pageLabel = document.getElementById('preview-pagination-label');
            var prevBtn = document.getElementById('btn-preview-prev');
            var nextBtn = document.getElementById('btn-preview-next');
            if (pageInfo) pageInfo.textContent = filteredCount + ' data tampil';
            if (pageLabel) pageLabel.textContent = 'Halaman ' + currentPage + ' / ' + totalPages;
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
            updateSelectionControls(visibleKeys || []);
        }

        function updateSelectionControls(visibleKeys) {
            var selectedCount = Object.keys(selectedPreviewRows).filter(function(key) {
                return selectedPreviewRows[key];
            }).length;
            var countLabel = document.getElementById('preview-selected-count');
            var deleteBtn = document.getElementById('btn-delete-selected');
            var selectAll = document.getElementById('preview-select-all');
            if (countLabel) countLabel.textContent = selectedCount + ' data dipilih';
            if (deleteBtn) deleteBtn.disabled = selectedCount === 0;
            if (selectAll) {
                var keys = visibleKeys || [];
                var checkedVisible = keys.filter(function(key) {
                    return selectedPreviewRows[key];
                }).length;
                selectAll.checked = keys.length > 0 && checkedVisible === keys.length;
                selectAll.indeterminate = checkedVisible > 0 && checkedVisible < keys.length;
            }
        }

        function togglePreviewRowSelection(rowKey, checked) {
            if (checked) {
                selectedPreviewRows[rowKey] = true;
            } else {
                delete selectedPreviewRows[rowKey];
            }
            updateSelectionControls(previewCurrentKeys || []);
        }

        function toggleSelectAllVisible(checked) {
            (previewCurrentKeys || []).forEach(function(key) {
                if (checked) {
                    selectedPreviewRows[key] = true;
                } else {
                    delete selectedPreviewRows[key];
                }
            });
            renderPreviewModal(importPayload);
        }

        function rebuildSelectionAfterDelete() {
            var valid = {};
            getPreviewRows(importPayload).forEach(function(item) {
                valid[item.key] = true;
            });
            var nextSelected = {};
            Object.keys(selectedPreviewRows).forEach(function(key) {
                if (valid[key]) nextSelected[key] = true;
            });
            selectedPreviewRows = nextSelected;
        }

        function deleteSelectedPreviewRows() {
            if (!importPayload || !importPayload.sheets) return;
            var keys = Object.keys(selectedPreviewRows).filter(function(key) {
                return selectedPreviewRows[key];
            });
            if (keys.length === 0) return;
            if (!confirm('Hapus ' + keys.length + ' baris terpilih dari preview import?')) return;

            var grouped = {};
            keys.forEach(function(key) {
                var parts = key.split(':');
                var sheetIndex = parseInt(parts[0], 10);
                var rowIndex = parseInt(parts[1], 10);
                if (!grouped[sheetIndex]) grouped[sheetIndex] = [];
                grouped[sheetIndex].push(rowIndex);
            });

            Object.keys(grouped).forEach(function(sheetIndexText) {
                var sheetIndex = parseInt(sheetIndexText, 10);
                if (!importPayload.sheets[sheetIndex]) return;
                grouped[sheetIndex].sort(function(a, b) {
                    return b - a;
                }).forEach(function(rowIndex) {
                    importPayload.sheets[sheetIndex].rows.splice(rowIndex, 1);
                });
            });

            importPayload.sheets = importPayload.sheets.filter(function(sheet) {
                return sheet.rows.length > 0;
            });
            selectedPreviewRows = {};
            renderPreviewModal(importPayload);
        }

        function updatePreviewValue(sheetIndex, rowIndex, field, value) {
            if (!importPayload || !importPayload.sheets[sheetIndex] || !importPayload.sheets[sheetIndex].rows[rowIndex]) return;
            if (field === 'nama') {
                importPayload.sheets[sheetIndex].rows[rowIndex][field] = String(value || '').trim();
            } else {
                importPayload.sheets[sheetIndex].rows[rowIndex][field] = Math.max(0, toNumber(value));
            }
            renderPreviewModal(importPayload);
        }

        function deletePreviewRow(sheetIndex, rowIndex) {
            if (!importPayload || !importPayload.sheets[sheetIndex]) return;
            if (!confirm('Hapus baris ini dari preview import?')) return;
            importPayload.sheets[sheetIndex].rows.splice(rowIndex, 1);
            importPayload.sheets = importPayload.sheets.filter(function(sheet) {
                return sheet.rows.length > 0;
            });
            selectedPreviewRows = {};
            renderPreviewModal(importPayload);
        }

        function confirmImport() {
            if (!importPayload) {
                showAlert('error', 'Silakan upload dan preview file terlebih dahulu.');
                return;
            }
            var totalPreviewRows = 0;
            importPayload.sheets.forEach(function(sheet) {
                totalPreviewRows += sheet.rows.length;
            });
            if (totalPreviewRows === 0) {
                showAlert('error', 'Tidak ada data yang bisa diimport.');
                return;
            }
            if (!confirm('Lanjutkan import data simpanan? Data yang sudah ada untuk periode ini akan ditimpa.')) {
                return;
            }

            var btn = document.getElementById('btn-import');
            btn.disabled = true;
            btn.textContent = 'Memproses Import...';

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(importPayload)
                })
                .then(function(res) {
                    return res.json().then(function(data) {
                        if (!res.ok || !data.ok) {
                            throw new Error(data.message || 'Gagal import data.');
                        }
                        return data;
                    });
                })
                .then(function(data) {
                    showAlert('success', data.message || 'Import berhasil.');
                    closePreviewModal();
                    selectedExcelFile = null;
                    importPayload = null;
                    selectedPreviewRows = {};
                    previewPage = 1;
                    document.getElementById('file-input').value = '';
                    document.getElementById('file-label').textContent = 'Klik untuk pilih file Excel';
                    document.getElementById('import-ready').classList.add('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Konfirmasi & Import';
                })
                .catch(function(err) {
                    showAlert('error', err.message);
                    btn.disabled = false;
                    btn.textContent = 'Konfirmasi & Mulai Import';
                });
        }

        function batalImport() {
            if (confirm('Batalkan upload file ini?')) {
                selectedExcelFile = null;
                importPayload = null;
                document.getElementById('file-input').value = '';
                document.getElementById('file-label').textContent = 'Klik untuk pilih file Excel';
                document.getElementById('import-ready').classList.add('hidden');
            }
        }
    </script>

</body>

</html>