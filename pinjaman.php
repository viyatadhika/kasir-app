<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'activity_helper.php';

$activeMenu = 'pinjaman';
$pageTitle  = 'Pengajuan Pinjaman';
$backUrl    = 'dashboard.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

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

$userId = (int)(isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0);

// ── API Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $_GET['action'];

    try {
        if ($action === 'seleksi') {
            // Tandai sebagai diseleksi/disetujui/ditolak
            $id     = (int)(isset($input['id']) ? $input['id'] : 0);
            $status = isset($input['status']) ? $input['status'] : '';
            $catatan = trim(isset($input['catatan']) ? $input['catatan'] : '');

            if (!in_array($status, ['disetujui', 'ditolak', 'diseleksi'], true)) {
                echo json_encode(['success' => false, 'message' => 'Status tidak valid.']);
                exit;
            }

            // Ambil data pengajuan
            $stmt = $pdo->prepare("SELECT * FROM pengajuan_pinjaman WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pengajuan) {
                echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan.']);
                exit;
            }

            $setArr = [
                'status'         => $status,
                'catatan_petugas' => $catatan,
            ];

            if ($status === 'diseleksi') {
                $setArr['diseleksi_oleh'] = $userId;
                $setArr['diseleksi_at']   = date('Y-m-d H:i:s');
            } elseif ($status === 'disetujui') {
                $setArr['disetujui_oleh'] = $userId;
                $setArr['disetujui_at']   = date('Y-m-d H:i:s');
            }

            $setClauses = [];
            foreach (array_keys($setArr) as $col) {
                $setClauses[] = "$col = :$col";
            }
            $pdo->prepare("UPDATE pengajuan_pinjaman SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = :id")
                ->execute(array_merge($setArr, [':id' => $id]));

            // Kirim notifikasi ke member
            $pesanMap = [
                'diseleksi' => 'Pengajuan pinjaman Anda sedang dalam proses seleksi oleh petugas.',
                'disetujui' => 'Selamat! Pengajuan pinjaman Anda telah disetujui. Silakan hubungi petugas untuk pencairan.',
                'ditolak'   => 'Mohon maaf, pengajuan pinjaman Anda tidak dapat disetujui.' . ($catatan ? ' Alasan: ' . $catatan : ''),
            ];
            $judulMap = [
                'diseleksi' => 'Pengajuan Sedang Diseleksi',
                'disetujui' => 'Pengajuan Disetujui',
                'ditolak'   => 'Pengajuan Ditolak',
            ];
            $tipeMap = [
                'diseleksi' => 'pinjaman',
                'disetujui' => 'sukses',
                'ditolak'   => 'peringatan',
            ];

            $pdo->prepare("
                INSERT INTO notifikasi_member (member_id, judul, pesan, tipe, ref_id, ref_tipe)
                VALUES (:mid, :judul, :pesan, :tipe, :rid, 'pengajuan')
            ")->execute([
                ':mid'   => $pengajuan['member_id'],
                ':judul' => $judulMap[$status],
                ':pesan' => $pesanMap[$status],
                ':tipe'  => $tipeMap[$status],
                ':rid'   => $id,
            ]);

            catat_aktivitas($pdo, 'update', 'Pinjaman', "Ubah status pengajuan #$id ke $status");
            echo json_encode(['success' => true, 'message' => 'Status berhasil diperbarui.']);
        } elseif ($action === 'cairkan') {
            $id = (int)(isset($input['id']) ? $input['id'] : 0);

            $stmt = $pdo->prepare("SELECT * FROM pengajuan_pinjaman WHERE id = :id AND status = 'disetujui' LIMIT 1");
            $stmt->execute([':id' => $id]);
            $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pengajuan) {
                echo json_encode(['success' => false, 'message' => 'Pengajuan tidak ditemukan atau belum disetujui.']);
                exit;
            }

            // Ambil konfigurasi bunga
            $konfig = $pdo->query("SELECT * FROM konfigurasi_sp LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $bungaPct = $pengajuan['jenis'] === 'barang'
                ? (float)($konfig['bunga_barang'] ?? 1.5)
                : (float)($konfig['bunga_uang']   ?? 1.0);

            $pokok  = (int)$pengajuan['jumlah'];
            $tenor  = (int)$pengajuan['tenor'];
            $angPok = (int)round($pokok / $tenor);
            $angBng = (int)round($pokok * $bungaPct / 100);
            $angTot = $angPok + $angBng;

            $mulai   = date('Y-m-d');
            $selesai = date('Y-m-d', strtotime("+$tenor months"));

            $pdo->beginTransaction();

            // Insert pinjaman
            $pdo->prepare("
                INSERT INTO pinjaman (pengajuan_id, member_id, jenis, pokok, bunga_persen, tenor,
                    angsuran_pokok, angsuran_bunga, angsuran_total, tanggal_mulai, tanggal_selesai, status)
                VALUES (:pid, :mid, :jenis, :pokok, :bunga, :tenor, :apok, :abng, :atot, :mulai, :selesai, 'aktif')
            ")->execute([
                ':pid'   => $id,
                ':mid'   => $pengajuan['member_id'],
                ':jenis' => $pengajuan['jenis'],
                ':pokok' => $pokok,
                ':bunga' => $bungaPct,
                ':tenor' => $tenor,
                ':apok'  => $angPok,
                ':abng'  => $angBng,
                ':atot'  => $angTot,
                ':mulai' => $mulai,
                ':selesai' => $selesai,
            ]);
            $pinjamanId = (int)$pdo->lastInsertId();

            // Generate angsuran
            $insAng = $pdo->prepare("
                INSERT INTO pinjaman_angsuran (pinjaman_id, member_id, ke, bulan, tahun, jumlah_pokok, jumlah_bunga, jumlah_total, jatuh_tempo, status)
                VALUES (:pid, :mid, :ke, :bln, :thn, :jpok, :jbng, :jtot, :jatuh, 'belum_bayar')
            ");
            for ($i = 1; $i <= $tenor; $i++) {
                $jatuhDate = date('Y-m-d', strtotime("+$i months", strtotime($mulai)));
                $insAng->execute([
                    ':pid'  => $pinjamanId,
                    ':mid'  => $pengajuan['member_id'],
                    ':ke'   => $i,
                    ':bln'  => (int)date('n', strtotime($jatuhDate)),
                    ':thn'  => (int)date('Y', strtotime($jatuhDate)),
                    ':jpok' => $angPok,
                    ':jbng' => $angBng,
                    ':jtot' => $angTot,
                    ':jatuh' => $jatuhDate,
                ]);
            }

            // Update status pengajuan
            $pdo->prepare("
                UPDATE pengajuan_pinjaman SET status = 'dicairkan', dicairkan_oleh = :uid, dicairkan_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ")->execute([':uid' => $userId, ':id' => $id]);

            // Notifikasi
            $pdo->prepare("
                INSERT INTO notifikasi_member (member_id, judul, pesan, tipe, ref_id, ref_tipe)
                VALUES (:mid, 'Pinjaman Dicairkan', :pesan, 'sukses', :rid, 'pinjaman')
            ")->execute([
                ':mid'  => $pengajuan['member_id'],
                ':pesan' => "Pinjaman " . rupiah_sp($pokok) . " telah dicairkan. Angsuran pertama jatuh tempo " . date('d/m/Y', strtotime('+1 month', strtotime($mulai))) . ".",
                ':rid'  => $pinjamanId,
            ]);

            $pdo->commit();
            catat_aktivitas($pdo, 'create', 'Pinjaman', "Cairkan pinjaman #$id — " . rupiah_sp($pokok));
            echo json_encode(['success' => true, 'message' => 'Pinjaman berhasil dicairkan. Angsuran sudah dibuat.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterJenis  = isset($_GET['jenis'])  ? $_GET['jenis']  : '';
$q = trim(isset($_GET['q']) ? $_GET['q'] : '');

$where  = ['1=1'];
$params = [];

if ($filterStatus) {
    $where[] = 'pp.status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterJenis) {
    $where[] = 'pp.jenis = :jenis';
    $params[':jenis'] = $filterJenis;
}
if ($q) {
    $where[] = '(m.nama LIKE :q OR m.kode LIKE :q2)';
    $params[':q']  = "%$q%";
    $params[':q2'] = "%$q%";
}

$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT pp.*, m.nama AS member_nama, m.kode AS member_kode, m.no_hp AS member_hp,
           u.nama AS petugas_nama
    FROM pengajuan_pinjaman pp
    LEFT JOIN member m ON m.id = pp.member_id
    LEFT JOIN users u  ON u.id = pp.disetujui_oleh
    WHERE $whereStr
    ORDER BY FIELD(pp.status,'pending','diseleksi','disetujui','ditolak','dicairkan'), pp.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$pengajuanList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary
$sumStmt = $pdo->query("
    SELECT status, COUNT(*) AS total, SUM(jumlah) AS nilai
    FROM pengajuan_pinjaman
    GROUP BY status
");
$summary = [];
foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $summary[$row['status']] = $row;
}

$konfig = $pdo->query("SELECT * FROM konfigurasi_sp LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$rightActionHtml = '
<a href="konfigurasi_sp.php" class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:bg-gray-50 transition-all">
    Konfigurasi SP
</a>';

catat_view_once($pdo, 'Pengajuan Pinjaman', 'Membuka halaman Pengajuan Pinjaman');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Pinjaman — Koperasi BSDK</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
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

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
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

        #toast {
            transition: all .3s cubic-bezier(.34, 1.56, .64, 1);
            transform: translateY(20px);
            opacity: 0;
        }

        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }
    </style>
</head>

<body class="antialiased pb-20 lg:pb-0">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="content p-4 sm:p-6 lg:p-10">

        <!-- Konfigurasi aktif -->
        <?php if ($konfig): ?>
            <div class="mb-6 flex flex-wrap gap-4 text-[10px] font-bold text-gray-500 bg-gray-50 border border-gray-100 px-4 py-3">
                <span>Bunga Uang: <strong class="text-black"><?php echo h($konfig['bunga_uang']); ?>%/bln flat</strong></span>
                <span>·</span>
                <span>Bunga Barang: <strong class="text-black"><?php echo h($konfig['bunga_barang']); ?>%/bln flat</strong></span>
                <span>·</span>
                <span>Tenor Maks Uang: <strong class="text-black"><?php echo h($konfig['tenor_maks_uang']); ?> bln</strong></span>
                <span>·</span>
                <span>Tenor Maks Barang: <strong class="text-black"><?php echo h($konfig['tenor_maks_barang']); ?> bln</strong></span>
            </div>
        <?php endif; ?>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
            <?php
            $statusInfo = [
                'pending'   => ['label' => 'Pending',   'color' => 'text-amber-600'],
                'diseleksi' => ['label' => 'Diseleksi', 'color' => 'text-blue-600'],
                'disetujui' => ['label' => 'Disetujui', 'color' => 'text-green-600'],
                'ditolak'   => ['label' => 'Ditolak',   'color' => 'text-red-600'],
                'dicairkan' => ['label' => 'Dicairkan', 'color' => 'text-purple-600'],
            ];
            foreach ($statusInfo as $st => $info):
                $d = isset($summary[$st]) ? $summary[$st] : ['total' => 0, 'nilai' => 0];
            ?>
                <a href="?status=<?php echo $st; ?>" class="bg-white border border-gray-100 p-4 hover:border-gray-300 transition-all <?php echo $filterStatus === $st ? 'border-black' : ''; ?>">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1"><?php echo $info['label']; ?></p>
                    <p class="text-2xl font-bold <?php echo $info['color']; ?>"><?php echo number_format($d['total']); ?></p>
                    <p class="text-[10px] text-gray-400 mt-1"><?php echo rupiah_sp($d['nilai']); ?></p>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter -->
        <div class="flex flex-col md:flex-row gap-3 mb-4 bg-white border border-gray-100 p-4">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="q-input" value="<?php echo h($q); ?>" placeholder="Cari nama atau kode member..."
                    class="w-full bg-gray-50 border border-gray-100 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:border-black">
            </div>
            <select id="jenis-filter" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:outline-none focus:border-black">
                <option value="">Semua Jenis</option>
                <option value="uang" <?php echo $filterJenis === 'uang'   ? 'selected' : ''; ?>>Uang</option>
                <option value="barang" <?php echo $filterJenis === 'barang' ? 'selected' : ''; ?>>Barang</option>
            </select>
            <select id="status-filter" class="bg-gray-50 border border-gray-100 px-3 py-2.5 text-xs font-bold uppercase text-gray-500 focus:outline-none focus:border-black">
                <option value="">Semua Status</option>
                <?php foreach ($statusInfo as $st => $info): ?>
                    <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo $info['label']; ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-xs text-gray-400 font-medium self-center hidden sm:block">
                <?php echo number_format(count($pengajuanList)); ?> pengajuan
            </span>
        </div>

        <!-- DESKTOP: Tabel -->
        <div class="hidden lg:block bg-white border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto no-scrollbar">
                <table class="w-full text-left">
                    <thead class="border-b border-gray-100 bg-gray-50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Member</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Jenis</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Jumlah</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Tenor</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Angsuran Est.</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-center">Status</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tanggal</th>
                            <th class="px-5 py-4 text-[10px] font-bold uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if (empty($pengajuanList)): ?>
                            <tr>
                                <td colspan="8" class="py-16 text-center text-xs text-gray-400">Tidak ada pengajuan</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pengajuanList as $p):
                                $bungaPct   = $p['jenis'] === 'barang' ? (float)($konfig['bunga_barang'] ?? 1.5) : (float)($konfig['bunga_uang'] ?? 1.0);
                                $pokok      = (int)$p['jumlah'];
                                $tenor      = (int)$p['tenor'];
                                $angBng     = $tenor > 0 ? (int)round($pokok * $bungaPct / 100) : 0;
                                $angPok     = $tenor > 0 ? (int)round($pokok / $tenor) : 0;
                                $angTot     = $angPok + $angBng;
                                $badgeClass = [
                                    'pending'   => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'diseleksi' => 'bg-blue-50 text-blue-700 border-blue-200',
                                    'disetujui' => 'bg-green-50 text-green-700 border-green-200',
                                    'ditolak'   => 'bg-red-50 text-red-700 border-red-200',
                                    'dicairkan' => 'bg-purple-50 text-purple-700 border-purple-200',
                                ][$p['status']] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold"><?php echo h($p['member_nama']); ?></p>
                                        <p class="text-[10px] text-gray-400 font-mono"><?php echo h($p['member_kode']); ?> · <?php echo h($p['member_hp']); ?></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-[10px] font-bold uppercase px-2 py-1 <?php echo $p['jenis'] === 'barang' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                                            <?php echo ucfirst(h($p['jenis'])); ?>
                                        </span>
                                        <?php if ($p['nama_barang']): ?>
                                            <p class="text-[10px] text-gray-400 mt-1"><?php echo h($p['nama_barang']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-right font-bold text-sm"><?php echo rupiah_sp($pokok); ?></td>
                                    <td class="px-5 py-4 text-center text-sm font-bold"><?php echo $tenor; ?> bln</td>
                                    <td class="px-5 py-4 text-right">
                                        <p class="text-sm font-bold"><?php echo rupiah_sp($angTot); ?>/bln</p>
                                        <p class="text-[10px] text-gray-400">Pokok <?php echo rupiah_sp($angPok); ?> + Bunga <?php echo rupiah_sp($angBng); ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="text-[9px] font-black uppercase px-2 py-1 border <?php echo $badgeClass; ?>">
                                            <?php echo ucfirst(h($p['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-gray-500">
                                        <?php echo date('d/m/Y', strtotime($p['created_at'])); ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-1">
                                            <button onclick="openDetail(<?php echo (int)$p['id']; ?>)"
                                                class="px-3 py-1.5 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50 transition-all">
                                                Detail
                                            </button>
                                            <?php if ($p['status'] === 'pending' || $p['status'] === 'diseleksi'): ?>
                                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'disetujui')"
                                                    class="px-3 py-1.5 text-[10px] font-black uppercase bg-green-600 text-white hover:bg-green-700 transition-all">
                                                    ACC
                                                </button>
                                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'ditolak')"
                                                    class="px-3 py-1.5 text-[10px] font-black uppercase border border-red-200 text-red-700 hover:bg-red-50 transition-all">
                                                    Tolak
                                                </button>
                                            <?php elseif ($p['status'] === 'disetujui'): ?>
                                                <button onclick="cairkan(<?php echo (int)$p['id']; ?>)"
                                                    class="px-3 py-1.5 text-[10px] font-black uppercase bg-black text-white hover:bg-gray-800 transition-all">
                                                    Cairkan
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MOBILE: Card -->
        <div class="lg:hidden space-y-3">
            <?php if (empty($pengajuanList)): ?>
                <div class="py-16 text-center text-xs text-gray-400">Tidak ada pengajuan</div>
            <?php else: ?>
                <?php foreach ($pengajuanList as $p):
                    $bungaPct = $p['jenis'] === 'barang' ? (float)($konfig['bunga_barang'] ?? 1.5) : (float)($konfig['bunga_uang'] ?? 1.0);
                    $pokok    = (int)$p['jumlah'];
                    $tenor    = (int)$p['tenor'];
                    $angTot   = $tenor > 0 ? (int)round($pokok / $tenor + $pokok * $bungaPct / 100) : 0;
                    $badgeClass = [
                        'pending'   => 'bg-amber-50 text-amber-700 border-amber-200',
                        'diseleksi' => 'bg-blue-50 text-blue-700 border-blue-200',
                        'disetujui' => 'bg-green-50 text-green-700 border-green-200',
                        'ditolak'   => 'bg-red-50 text-red-700 border-red-200',
                        'dicairkan' => 'bg-purple-50 text-purple-700 border-purple-200',
                    ][$p['status']] ?? 'bg-gray-50 text-gray-700 border-gray-200';
                ?>
                    <div class="bg-white border border-gray-100 p-4">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div>
                                <p class="text-sm font-bold"><?php echo h($p['member_nama']); ?></p>
                                <p class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo h($p['member_kode']); ?></p>
                            </div>
                            <span class="text-[9px] font-black uppercase px-2 py-1 border flex-shrink-0 <?php echo $badgeClass; ?>">
                                <?php echo ucfirst(h($p['status'])); ?>
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Jenis</p>
                                <p class="text-sm font-bold"><?php echo ucfirst(h($p['jenis'])); ?></p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Jumlah</p>
                                <p class="text-sm font-bold"><?php echo rupiah_sp($pokok); ?></p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Tenor</p>
                                <p class="text-sm font-bold"><?php echo $tenor; ?> bulan</p>
                            </div>
                            <div class="border border-gray-100 p-3 bg-gray-50">
                                <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Angsuran/bln</p>
                                <p class="text-sm font-bold"><?php echo rupiah_sp($angTot); ?></p>
                            </div>
                        </div>
                        <div class="flex gap-2 pt-3 border-t border-gray-100">
                            <button onclick="openDetail(<?php echo (int)$p['id']; ?>)"
                                class="flex-1 py-2 text-[10px] font-black uppercase border border-gray-200 hover:bg-gray-50 transition-all">Detail</button>
                            <?php if ($p['status'] === 'pending' || $p['status'] === 'diseleksi'): ?>
                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'disetujui')"
                                    class="flex-1 py-2 text-[10px] font-black uppercase bg-green-600 text-white hover:bg-green-700">ACC</button>
                                <button onclick="aksiBulk(<?php echo (int)$p['id']; ?>, 'ditolak')"
                                    class="flex-1 py-2 text-[10px] font-black uppercase border border-red-200 text-red-700 hover:bg-red-50">Tolak</button>
                            <?php elseif ($p['status'] === 'disetujui'): ?>
                                <button onclick="cairkan(<?php echo (int)$p['id']; ?>)"
                                    class="flex-1 py-2 text-[10px] font-black uppercase bg-black text-white">Cairkan</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modal Detail -->
    <div id="modal-detail" class="fixed inset-0 z-[100] bg-black/40 items-center justify-center p-4" style="display:none">
        <div class="bg-white w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="text-xs font-black uppercase tracking-widest">Detail Pengajuan</h2>
                <button onclick="closeDetail()" class="p-2 hover:bg-gray-100">&times;</button>
            </div>
            <div class="px-6 py-5" id="modal-detail-body">
                <p class="text-xs text-gray-400">Memuat...</p>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex gap-3" id="modal-detail-actions"></div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed bottom-6 right-6 z-[200] flex items-center gap-3 bg-gray-900 text-white px-5 py-3 shadow-2xl pointer-events-none">
        <span id="toast-msg" class="text-sm font-medium"></span>
    </div>

    <script>
        var PENGAJUAN_DATA = <?php echo json_encode(array_column($pengajuanList, null, 'id')); ?>;
        var KONFIG = <?php echo json_encode($konfig ?: []); ?>;

        // ── Filter ───────────────────────────────────────────────────────────────────
        var searchTimer;
        document.getElementById('q-input').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilter, 400);
        });
        document.getElementById('jenis-filter').addEventListener('change', applyFilter);
        document.getElementById('status-filter').addEventListener('change', applyFilter);

        function applyFilter() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', document.getElementById('q-input').value);
            url.searchParams.set('jenis', document.getElementById('jenis-filter').value);
            url.searchParams.set('status', document.getElementById('status-filter').value);
            window.location.href = url.toString();
        }

        // ── Detail ───────────────────────────────────────────────────────────────────
        function openDetail(id) {
            var p = PENGAJUAN_DATA[id];
            if (!p) return;

            var bungaPct = p.jenis === 'barang' ? parseFloat(KONFIG.bunga_barang || 1.5) : parseFloat(KONFIG.bunga_uang || 1.0);
            var pokok = parseInt(p.jumlah || 0);
            var tenor = parseInt(p.tenor || 0);
            var angPok = tenor > 0 ? Math.round(pokok / tenor) : 0;
            var angBng = Math.round(pokok * bungaPct / 100);
            var angTot = angPok + angBng;

            var html = '<div class="space-y-3">';
            html += '<div class="grid grid-cols-2 gap-3">';
            html += box('Member', escHtml(p.member_nama || '-'));
            html += box('Kode', escHtml(p.member_kode || '-'));
            html += box('Jenis Pinjaman', ucFirst(p.jenis));
            html += box('Jumlah', rupiah(pokok));
            html += box('Tenor', tenor + ' bulan');
            html += box('Angsuran/bln', rupiah(angTot));
            html += box('Bunga', bungaPct + '%/bln flat');
            html += box('Status', ucFirst(p.status));
            if (p.nama_barang) html += box('Nama Barang', escHtml(p.nama_barang));
            if (p.keperluan) html += '<div class="col-span-2">' + box('Keperluan', escHtml(p.keperluan)) + '</div>';
            if (p.catatan_petugas) html += '<div class="col-span-2">' + box('Catatan Petugas', escHtml(p.catatan_petugas)) + '</div>';
            html += '</div>';

            // Simulasi angsuran
            html += '<p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mt-4 mb-2">Simulasi Angsuran</p>';
            html += '<div class="overflow-x-auto"><table class="w-full text-xs border border-gray-100">';
            html += '<thead class="bg-gray-50"><tr><th class="px-3 py-2 text-left">Ke</th><th class="px-3 py-2 text-right">Pokok</th><th class="px-3 py-2 text-right">Bunga</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody>';
            for (var i = 1; i <= Math.min(tenor, 6); i++) {
                html += '<tr class="border-t border-gray-50"><td class="px-3 py-1.5">' + i + '</td>';
                html += '<td class="px-3 py-1.5 text-right">' + rupiah(angPok) + '</td>';
                html += '<td class="px-3 py-1.5 text-right">' + rupiah(angBng) + '</td>';
                html += '<td class="px-3 py-1.5 text-right font-bold">' + rupiah(angTot) + '</td></tr>';
            }
            if (tenor > 6) {
                html += '<tr class="border-t border-gray-50"><td colspan="4" class="px-3 py-1.5 text-center text-gray-400">... dan ' + (tenor - 6) + ' angsuran lagi</td></tr>';
            }
            html += '<tr class="border-t border-gray-200 bg-gray-50 font-bold"><td class="px-3 py-2">Total</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(angPok * tenor) + '</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(angBng * tenor) + '</td>';
            html += '<td class="px-3 py-2 text-right">' + rupiah(angTot * tenor) + '</td></tr>';
            html += '</tbody></table></div></div>';

            document.getElementById('modal-detail-body').innerHTML = html;

            var actHtml = '<button onclick="closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase border border-gray-200 hover:bg-gray-50">Tutup</button>';
            if (p.status === 'pending' || p.status === 'diseleksi') {
                actHtml += '<button onclick="aksiBulk(' + id + ',\'disetujui\');closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase bg-green-600 text-white hover:bg-green-700">ACC</button>';
                actHtml += '<button onclick="aksiBulk(' + id + ',\'ditolak\');closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase border border-red-200 text-red-700 hover:bg-red-50">Tolak</button>';
            } else if (p.status === 'disetujui') {
                actHtml += '<button onclick="cairkan(' + id + ');closeDetail()" class="flex-1 py-2.5 text-xs font-bold uppercase bg-black text-white hover:bg-gray-800">Cairkan</button>';
            }
            document.getElementById('modal-detail-actions').innerHTML = actHtml;
            document.getElementById('modal-detail').style.display = 'flex';
        }

        function closeDetail() {
            document.getElementById('modal-detail').style.display = 'none';
        }

        document.getElementById('modal-detail').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        // ── Aksi ─────────────────────────────────────────────────────────────────────
        async function aksiBulk(id, status) {
            var label = status === 'disetujui' ? 'menyetujui' : 'menolak';
            var catatan = '';
            if (status === 'ditolak') {
                catatan = prompt('Alasan penolakan (opsional):') || '';
            }
            if (!confirm('Yakin ' + label + ' pengajuan #' + id + '?')) return;
            try {
                var res = await fetch('pinjaman.php?action=seleksi', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        status: status,
                        catatan: catatan
                    })
                });
                var data = await res.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(function() {
                    location.reload();
                }, 900);
            } catch (e) {
                showToast('Terjadi kesalahan', 'error');
            }
        }

        async function cairkan(id) {
            if (!confirm('Cairkan pinjaman #' + id + '? Angsuran akan dibuat otomatis.')) return;
            try {
                var res = await fetch('pinjaman.php?action=cairkan', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                });
                var data = await res.json();
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(function() {
                    location.reload();
                }, 900);
            } catch (e) {
                showToast('Terjadi kesalahan', 'error');
            }
        }

        // ── Helpers ──────────────────────────────────────────────────────────────────
        function box(label, val) {
            return '<div class="border border-gray-100 p-3 bg-gray-50"><p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">' + label + '</p><p class="text-sm font-bold">' + val + '</p></div>';
        }

        function rupiah(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function ucFirst(s) {
            s = String(s || '');
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = String(s || '');
            return d.innerHTML;
        }

        var toastTimer;

        function showToast(msg, type) {
            type = type || 'success';
            var t = document.getElementById('toast');
            document.getElementById('toast-msg').textContent = msg;
            t.style.background = type === 'error' ? '#dc2626' : '#111';
            t.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function() {
                t.classList.remove('show');
            }, 3000);
        }
    </script>

</body>

</html>