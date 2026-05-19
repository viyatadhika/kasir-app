<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'activity_helper.php';

$activeMenu = 'sp';
$pageTitle  = 'Konfigurasi SP';
$backUrl    = 'dashboard.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$userRole = isset($_SESSION['user']['role']) ? $_SESSION['user']['role'] : 'kasir';
if (!in_array($userRole, ['admin', 'ksp', 'petugas_sp'], true)) {
    header('Location: dashboard.php');
    exit;
}

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$error   = '';
$success = '';
$userId  = (int)(isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0);

$config = [
    'id' => 1,
    'bunga_uang' => 1.00,
    'bunga_barang' => 1.50,
    'tenor_maks_uang' => 24,
    'tenor_maks_barang' => 36,
    'updated_at' => null,
    'updated_by' => null,
    'updated_nama' => null,
];

function refreshConfig($pdo, $default)
{
    $stmt = $pdo->query("
        SELECT k.*, u.nama AS updated_nama
        FROM konfigurasi_sp k
        LEFT JOIN users u ON u.id = k.updated_by
        ORDER BY k.id ASC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? array_merge($default, $row) : $default;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS konfigurasi_tenor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jenis ENUM('uang','barang') NOT NULL,
            tenor INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unik_jenis_tenor (jenis, tenor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $config = refreshConfig($pdo, $config);

    $cekTenor = $pdo->query("SELECT COUNT(*) FROM konfigurasi_tenor")->fetchColumn();
    if ((int)$cekTenor === 0) {
        $seed = $pdo->prepare("INSERT IGNORE INTO konfigurasi_tenor (jenis, tenor) VALUES (:jenis, :tenor)");
        foreach ([3, 6, 12, 18, 24] as $tenor) {
            $seed->execute([':jenis' => 'uang', ':tenor' => $tenor]);
        }
        foreach ([6, 12, 18, 24, 36] as $tenor) {
            $seed->execute([':jenis' => 'barang', ':tenor' => $tenor]);
        }
    }
} catch (Exception $e) {
    $error = 'Gagal menyiapkan konfigurasi SP: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_config') {
        $bungaUang   = isset($_POST['bunga_uang']) ? (float)str_replace(',', '.', $_POST['bunga_uang']) : 0;
        $bungaBarang = isset($_POST['bunga_barang']) ? (float)str_replace(',', '.', $_POST['bunga_barang']) : 0;

        if ($bungaUang < 0 || $bungaBarang < 0) {
            $error = 'Bunga tidak boleh kurang dari 0.';
        } else {
            try {
                $tenorMaksUang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'uang'")->fetchColumn();
                $tenorMaksBarang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'barang'")->fetchColumn();

                if ($tenorMaksUang < 1) {
                    $tenorMaksUang = 24;
                }
                if ($tenorMaksBarang < 1) {
                    $tenorMaksBarang = 36;
                }

                $update = $pdo->prepare("
                    UPDATE konfigurasi_sp
                    SET
                        bunga_uang = :bunga_uang,
                        bunga_barang = :bunga_barang,
                        tenor_maks_uang = :tenor_maks_uang,
                        tenor_maks_barang = :tenor_maks_barang,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                $update->execute([
                    ':bunga_uang' => $bungaUang,
                    ':bunga_barang' => $bungaBarang,
                    ':tenor_maks_uang' => $tenorMaksUang,
                    ':tenor_maks_barang' => $tenorMaksBarang,
                    ':updated_by' => $userId,
                    ':id' => (int)$config['id'],
                ]);

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'update', 'Konfigurasi SP', "Update bunga SP: uang {$bungaUang}%, barang {$bungaBarang}%");
                }

                $success = 'Konfigurasi bunga berhasil diperbarui.';
                $config = refreshConfig($pdo, $config);
            } catch (Exception $e) {
                $error = 'Gagal menyimpan konfigurasi bunga: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'add_tenor') {
        $jenis = isset($_POST['jenis']) ? strtolower(trim($_POST['jenis'])) : '';
        $tenor = isset($_POST['tenor']) ? (int)$_POST['tenor'] : 0;

        if (!in_array($jenis, ['uang', 'barang'], true)) {
            $error = 'Jenis tenor tidak valid.';
        } elseif ($tenor < 1) {
            $error = 'Tenor minimal 1 bulan.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO konfigurasi_tenor (jenis, tenor) VALUES (:jenis, :tenor)");
                $stmt->execute([':jenis' => $jenis, ':tenor' => $tenor]);

                $tenorMaksUang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'uang'")->fetchColumn();
                $tenorMaksBarang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'barang'")->fetchColumn();

                $pdo->prepare("
                    UPDATE konfigurasi_sp
                    SET tenor_maks_uang = :tu,
                        tenor_maks_barang = :tb,
                        updated_at = NOW(),
                        updated_by = :uid
                    WHERE id = :id
                ")->execute([
                    ':tu' => $tenorMaksUang ?: 24,
                    ':tb' => $tenorMaksBarang ?: 36,
                    ':uid' => $userId,
                    ':id' => (int)$config['id'],
                ]);

                if (function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'create', 'Tenor SP', "Tambah tenor {$jenis}: {$tenor} bulan");
                }

                $success = 'Pilihan tenor berhasil ditambahkan.';
                $config = refreshConfig($pdo, $config);
            } catch (Exception $e) {
                $error = 'Gagal menambahkan tenor: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_tenor') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if ($id < 1) {
            $error = 'Data tenor tidak valid.';
        } else {
            try {
                $stmtInfo = $pdo->prepare("SELECT jenis, tenor FROM konfigurasi_tenor WHERE id = :id LIMIT 1");
                $stmtInfo->execute([':id' => $id]);
                $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("DELETE FROM konfigurasi_tenor WHERE id = :id");
                $stmt->execute([':id' => $id]);

                $tenorMaksUang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'uang'")->fetchColumn();
                $tenorMaksBarang = (int)$pdo->query("SELECT COALESCE(MAX(tenor), 0) FROM konfigurasi_tenor WHERE jenis = 'barang'")->fetchColumn();

                $pdo->prepare("
                    UPDATE konfigurasi_sp
                    SET tenor_maks_uang = :tu,
                        tenor_maks_barang = :tb,
                        updated_at = NOW(),
                        updated_by = :uid
                    WHERE id = :id
                ")->execute([
                    ':tu' => $tenorMaksUang ?: 0,
                    ':tb' => $tenorMaksBarang ?: 0,
                    ':uid' => $userId,
                    ':id' => (int)$config['id'],
                ]);

                if ($info && function_exists('catat_aktivitas')) {
                    catat_aktivitas($pdo, 'delete', 'Tenor SP', "Hapus tenor {$info['jenis']}: {$info['tenor']} bulan");
                }

                $success = 'Pilihan tenor berhasil dihapus.';
                $config = refreshConfig($pdo, $config);
            } catch (Exception $e) {
                $error = 'Gagal menghapus tenor: ' . $e->getMessage();
            }
        }
    }
}

$tenorUang = [];
$tenorBarang = [];

try {
    $stmt = $pdo->query("SELECT * FROM konfigurasi_tenor ORDER BY jenis ASC, tenor ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['jenis'] === 'uang') {
            $tenorUang[] = $row;
        } elseif ($row['jenis'] === 'barang') {
            $tenorBarang[] = $row;
        }
    }
} catch (Exception $e) {
    if (!$error) {
        $error = 'Gagal mengambil data tenor: ' . $e->getMessage();
    }
}

$rightActionHtml = '
<a href="dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 text-[10px] font-black uppercase tracking-widest border border-gray-200 hover:bg-gray-50 transition-all">
    Dashboard
</a>';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfigurasi SP — Koperasi BSDK</title>
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white border border-gray-100 p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Uang</p>
                <p class="text-2xl font-bold text-green-600"><?php echo number_format((float)$config['bunga_uang'], 2, ',', '.'); ?>%</p>
                <p class="text-[10px] text-gray-400 mt-1">Per bulan</p>
            </div>

            <div class="bg-white border border-gray-100 p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Bunga Barang</p>
                <p class="text-2xl font-bold text-blue-600"><?php echo number_format((float)$config['bunga_barang'], 2, ',', '.'); ?>%</p>
                <p class="text-[10px] text-gray-400 mt-1">Per bulan</p>
            </div>

            <div class="bg-white border border-gray-100 p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pilihan Tenor Uang</p>
                <p class="text-2xl font-bold text-purple-600"><?php echo number_format(count($tenorUang)); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Maks <?php echo number_format((int)$config['tenor_maks_uang']); ?> bulan</p>
            </div>

            <div class="bg-white border border-gray-100 p-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pilihan Tenor Barang</p>
                <p class="text-2xl font-bold text-orange-600"><?php echo number_format(count($tenorBarang)); ?></p>
                <p class="text-[10px] text-gray-400 mt-1">Maks <?php echo number_format((int)$config['tenor_maks_barang']); ?> bulan</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <div class="lg:col-span-2 bg-white border border-gray-100 p-6">
                <h2 class="text-xs font-bold uppercase tracking-widest mb-6">Konfigurasi Bunga</h2>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="save_config">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-8">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Bunga Pinjaman Uang (%) *</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="bunga_uang"
                                value="<?php echo h($config['bunga_uang']); ?>"
                                class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black"
                                required>
                            <p class="text-[10px] text-gray-400 mt-1">Contoh: 1.00 berarti 1% per bulan.</p>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Bunga Pinjaman Barang (%) *</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="bunga_barang"
                                value="<?php echo h($config['bunga_barang']); ?>"
                                class="w-full bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black"
                                required>
                            <p class="text-[10px] text-gray-400 mt-1">Contoh: 1.50 berarti 1,5% per bulan.</p>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="w-full sm:w-auto px-6 py-3 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800 transition-all">
                        Simpan Bunga
                    </button>
                </form>
            </div>

            <div class="bg-white border border-gray-100 p-6">
                <h2 class="text-xs font-bold uppercase tracking-widest mb-6">Informasi</h2>

                <div class="space-y-4 text-xs font-semibold text-gray-500 leading-relaxed">
                    <div class="border border-gray-100 p-4 bg-gray-50">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Terakhir Diupdate</p>
                        <p class="text-sm font-bold text-gray-900">
                            <?php echo $config['updated_at'] ? date('d/m/Y H:i', strtotime($config['updated_at'])) : 'Belum pernah'; ?>
                        </p>
                    </div>

                    <div class="border border-gray-100 p-4 bg-gray-50">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Diupdate Oleh</p>
                        <p class="text-sm font-bold text-gray-900">
                            <?php echo h($config['updated_nama'] ?: '-'); ?>
                        </p>
                    </div>

                    <div class="border border-amber-200 bg-amber-50 p-4 text-amber-800">
                        <p class="font-black mb-2">Catatan:</p>
                        <p>
                            Tenor maksimal otomatis mengikuti angka terbesar dari daftar pilihan tenor.
                        </p>
                    </div>
                </div>
            </div>

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">

            <div class="bg-white border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-3 mb-6">
                    <h2 class="text-xs font-bold uppercase tracking-widest">Pilihan Tenor Uang</h2>
                    <span class="text-[10px] font-black uppercase tracking-widest text-purple-600 bg-purple-50 border border-purple-100 px-2 py-1">
                        <?php echo number_format(count($tenorUang)); ?> opsi
                    </span>
                </div>

                <form method="POST" class="flex gap-3 mb-5">
                    <input type="hidden" name="action" value="add_tenor">
                    <input type="hidden" name="jenis" value="uang">
                    <input
                        type="number"
                        min="1"
                        name="tenor"
                        placeholder="Contoh: 12"
                        class="flex-1 bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black"
                        required>
                    <button
                        type="submit"
                        class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tambah
                    </button>
                </form>

                <?php if (empty($tenorUang)): ?>
                    <p class="text-xs text-gray-400 text-center py-8">Belum ada pilihan tenor uang.</p>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($tenorUang as $row): ?>
                            <div class="inline-flex items-center gap-2 border border-purple-100 bg-purple-50 text-purple-700 px-3 py-2">
                                <span class="text-xs font-black"><?php echo number_format((int)$row['tenor']); ?> Bulan</span>
                                <form method="POST" onsubmit="return confirm('Hapus tenor <?php echo (int)$row['tenor']; ?> bulan?')" class="inline">
                                    <input type="hidden" name="action" value="delete_tenor">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="text-purple-300 hover:text-red-600 font-black">&times;</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-3 mb-6">
                    <h2 class="text-xs font-bold uppercase tracking-widest">Pilihan Tenor Barang</h2>
                    <span class="text-[10px] font-black uppercase tracking-widest text-orange-600 bg-orange-50 border border-orange-100 px-2 py-1">
                        <?php echo number_format(count($tenorBarang)); ?> opsi
                    </span>
                </div>

                <form method="POST" class="flex gap-3 mb-5">
                    <input type="hidden" name="action" value="add_tenor">
                    <input type="hidden" name="jenis" value="barang">
                    <input
                        type="number"
                        min="1"
                        name="tenor"
                        placeholder="Contoh: 36"
                        class="flex-1 bg-gray-50 border border-gray-100 px-3 py-2.5 text-sm font-semibold focus:outline-none focus:border-black"
                        required>
                    <button
                        type="submit"
                        class="px-4 py-2.5 bg-black text-white text-[10px] font-black uppercase tracking-widest hover:bg-gray-800">
                        Tambah
                    </button>
                </form>

                <?php if (empty($tenorBarang)): ?>
                    <p class="text-xs text-gray-400 text-center py-8">Belum ada pilihan tenor barang.</p>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($tenorBarang as $row): ?>
                            <div class="inline-flex items-center gap-2 border border-orange-100 bg-orange-50 text-orange-700 px-3 py-2">
                                <span class="text-xs font-black"><?php echo number_format((int)$row['tenor']); ?> Bulan</span>
                                <form method="POST" onsubmit="return confirm('Hapus tenor <?php echo (int)$row['tenor']; ?> bulan?')" class="inline">
                                    <input type="hidden" name="action" value="delete_tenor">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="text-orange-300 hover:text-red-600 font-black">&times;</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </main>

</body>

</html>