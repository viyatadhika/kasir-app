<?php
/*
|--------------------------------------------------------------------------
| meja_cafe.php — Kelola Meja Cafe
|--------------------------------------------------------------------------
| - Compatible PHP 7 & 8
| - CRUD meja cafe
| - Status: kosong, terisi, reservasi, nonaktif
| - Responsive desktop, tablet, dan mobile
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'meja_cafe';
$pageTitle  = 'Meja Cafe';
$backUrl    = 'dashboard.php';

if (!function_exists('meja_h')) {
    function meja_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('meja_table_exists')) {
    function meja_table_exists(PDO $pdo, $table)
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
            ");
            $stmt->execute([':table_name' => (string)$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('meja_column_exists')) {
    function meja_column_exists(PDO $pdo, $table, $column)
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name'  => (string)$table,
                ':column_name' => (string)$column,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

try {
    if (!meja_table_exists($pdo, 'cafe_meja')) {
        $pdo->exec("
            CREATE TABLE cafe_meja (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nomor_meja VARCHAR(30) NOT NULL,
                nama_meja VARCHAR(100) NULL,
                kapasitas INT NOT NULL DEFAULT 2,
                lokasi VARCHAR(100) NULL,
                status ENUM('kosong','terisi','reservasi','nonaktif') NOT NULL DEFAULT 'kosong',
                catatan VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_cafe_meja_nomor (nomor_meja),
                INDEX idx_cafe_meja_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        if (!meja_column_exists($pdo, 'cafe_meja', 'nama_meja')) {
            $pdo->exec("ALTER TABLE cafe_meja ADD COLUMN nama_meja VARCHAR(100) NULL AFTER nomor_meja");
        }
        if (!meja_column_exists($pdo, 'cafe_meja', 'lokasi')) {
            $pdo->exec("ALTER TABLE cafe_meja ADD COLUMN lokasi VARCHAR(100) NULL AFTER kapasitas");
        }
        if (!meja_column_exists($pdo, 'cafe_meja', 'catatan')) {
            $pdo->exec("ALTER TABLE cafe_meja ADD COLUMN catatan VARCHAR(255) NULL AFTER status");
        }
        if (!meja_column_exists($pdo, 'cafe_meja', 'updated_at')) {
            $pdo->exec("ALTER TABLE cafe_meja ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        }
    }
} catch (Throwable $e) {
    // Ditampilkan melalui pesan error di bawah.
}

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save') {
            $id         = (int)($_POST['id'] ?? 0);
            $nomorMeja  = trim((string)($_POST['nomor_meja'] ?? ''));
            $namaMeja   = trim((string)($_POST['nama_meja'] ?? ''));
            $kapasitas  = max(1, (int)($_POST['kapasitas'] ?? 2));
            $lokasi     = trim((string)($_POST['lokasi'] ?? ''));
            $status     = strtolower(trim((string)($_POST['status'] ?? 'kosong')));
            $catatan    = trim((string)($_POST['catatan'] ?? ''));

            if ($nomorMeja === '') {
                throw new RuntimeException('Nomor meja wajib diisi.');
            }

            if (!in_array($status, ['kosong', 'terisi', 'reservasi', 'nonaktif'], true)) {
                $status = 'kosong';
            }

            $checkSql = "SELECT id FROM cafe_meja WHERE nomor_meja = :nomor_meja";
            $checkParams = [':nomor_meja' => $nomorMeja];

            if ($id > 0) {
                $checkSql .= " AND id <> :id";
                $checkParams[':id'] = $id;
            }

            $check = $pdo->prepare($checkSql . " LIMIT 1");
            $check->execute($checkParams);

            if ($check->fetchColumn()) {
                throw new RuntimeException('Nomor meja sudah digunakan.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE cafe_meja
                    SET nomor_meja = :nomor_meja,
                        nama_meja = :nama_meja,
                        kapasitas = :kapasitas,
                        lokasi = :lokasi,
                        status = :status,
                        catatan = :catatan,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':nomor_meja' => $nomorMeja,
                    ':nama_meja'  => $namaMeja,
                    ':kapasitas'  => $kapasitas,
                    ':lokasi'     => $lokasi,
                    ':status'     => $status,
                    ':catatan'    => $catatan,
                    ':id'         => $id,
                ]);
                $flash = 'Data meja berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cafe_meja
                        (nomor_meja, nama_meja, kapasitas, lokasi, status, catatan, created_at)
                    VALUES
                        (:nomor_meja, :nama_meja, :kapasitas, :lokasi, :status, :catatan, NOW())
                ");
                $stmt->execute([
                    ':nomor_meja' => $nomorMeja,
                    ':nama_meja'  => $namaMeja,
                    ':kapasitas'  => $kapasitas,
                    ':lokasi'     => $lokasi,
                    ':status'     => $status,
                    ':catatan'    => $catatan,
                ]);
                $flash = 'Meja baru berhasil ditambahkan.';
            }
        }

        if ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = strtolower(trim((string)($_POST['status'] ?? 'kosong')));

            if (!in_array($status, ['kosong', 'terisi', 'reservasi', 'nonaktif'], true)) {
                throw new RuntimeException('Status meja tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE cafe_meja
                SET status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $status,
                ':id'     => $id,
            ]);

            $flash = 'Status meja berhasil diperbarui.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM cafe_meja
                WHERE id = :id
                  AND status IN ('kosong','nonaktif')
            ");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Meja terisi atau reservasi tidak dapat dihapus.');
            }

            $flash = 'Meja berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(nomor_meja LIKE :q OR nama_meja LIKE :q OR lokasi LIKE :q OR catatan LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if (in_array($filterStatus, ['kosong', 'terisi', 'reservasi', 'nonaktif'], true)) {
    $where[] = "status = :status";
    $params[':status'] = $filterStatus;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$mejaList = [];
try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM cafe_meja
        $whereSql
        ORDER BY
            CASE status
                WHEN 'terisi' THEN 1
                WHEN 'reservasi' THEN 2
                WHEN 'kosong' THEN 3
                ELSE 4
            END,
            CAST(nomor_meja AS UNSIGNED) ASC,
            nomor_meja ASC
    ");
    $stmt->execute($params);
    $mejaList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat data meja: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$summary = [
    'total'     => 0,
    'kosong'    => 0,
    'terisi'    => 0,
    'reservasi' => 0,
    'nonaktif'  => 0,
    'kapasitas' => 0,
];

foreach ($mejaList as $meja) {
    $summary['total']++;
    $summary['kapasitas'] += (int)($meja['kapasitas'] ?? 0);
    $status = (string)($meja['status'] ?? '');
    if (isset($summary[$status])) {
        $summary[$status]++;
    }
}

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meja Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111827;
        }

        .meja-main {
            min-height: calc(100vh - 64px);
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 0;
            box-shadow: none;
        }

        .field {
            width: 100%;
            min-height: 42px;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 0;
        }

        textarea.field {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .btn {
            min-height: 42px;
            border-radius: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 5px 8px;
            border: 1px solid;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .status-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            background: currentColor;
            border-radius: 999px;
        }

        .meja-card {
            transition: border-color .15s ease, transform .15s ease;
        }

        .meja-card:hover {
            border-color: #d1d5db;
            transform: translateY(-1px);
        }

        @media (min-width:1024px) {
            .meja-main {
                margin-left: 220px;
            }
        }

        @media (max-width:1023px) {
            .meja-main {
                margin-left: 0;
                padding-bottom: 90px !important;
            }
        }
    </style>
</head>

<body>
    <main class="meja-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 px-4 py-3 border text-xs font-bold <?php echo $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'; ?>">
                <?php echo meja_h($flash); ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-gray-400">Operasional Cafe</p>
                <h1 class="text-2xl font-black mt-1">Kelola Meja Cafe</h1>
                <p class="text-xs text-gray-400 mt-1">Pantau meja kosong, terisi, reservasi, dan kapasitas tempat duduk.</p>
            </div>
            <button type="button" onclick="openMejaModal()" class="btn bg-black text-white px-5 hover:bg-gray-800">
                Tambah Meja
            </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-6">
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Meja</p>
                <p class="text-2xl font-black mt-1"><?php echo number_format($summary['total']); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Kosong</p>
                <p class="text-2xl font-black text-green-700 mt-1"><?php echo number_format($summary['kosong']); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-red-600">Terisi</p>
                <p class="text-2xl font-black text-red-700 mt-1"><?php echo number_format($summary['terisi']); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-amber-600">Reservasi</p>
                <p class="text-2xl font-black text-amber-700 mt-1"><?php echo number_format($summary['reservasi']); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Nonaktif</p>
                <p class="text-2xl font-black text-gray-700 mt-1"><?php echo number_format($summary['nonaktif']); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Kapasitas</p>
                <p class="text-2xl font-black text-blue-700 mt-1"><?php echo number_format($summary['kapasitas']); ?></p>
            </div>
        </div>

        <form method="get" class="card p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_220px_auto_auto] gap-3">
                <input type="search" name="q" value="<?php echo meja_h($q); ?>" placeholder="Cari nomor meja, nama, lokasi..." class="field">
                <select name="status" class="field">
                    <option value="">Semua Status</option>
                    <option value="kosong" <?php echo $filterStatus === 'kosong' ? 'selected' : ''; ?>>Kosong</option>
                    <option value="terisi" <?php echo $filterStatus === 'terisi' ? 'selected' : ''; ?>>Terisi</option>
                    <option value="reservasi" <?php echo $filterStatus === 'reservasi' ? 'selected' : ''; ?>>Reservasi</option>
                    <option value="nonaktif" <?php echo $filterStatus === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
                <button type="submit" class="btn bg-black text-white px-5">Terapkan</button>
                <a href="meja_cafe.php" class="btn border border-gray-200 bg-white px-5 inline-flex items-center justify-center">Reset</a>
            </div>
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
            <?php foreach ($mejaList as $meja): ?>
                <?php
                $status = (string)($meja['status'] ?? 'kosong');

                if ($status === 'kosong') {
                    $badgeClass = 'bg-green-50 border-green-200 text-green-700';
                    $cardClass  = 'border-green-100';
                } elseif ($status === 'terisi') {
                    $badgeClass = 'bg-red-50 border-red-200 text-red-700';
                    $cardClass  = 'border-red-100';
                } elseif ($status === 'reservasi') {
                    $badgeClass = 'bg-amber-50 border-amber-200 text-amber-700';
                    $cardClass  = 'border-amber-100';
                } else {
                    $badgeClass = 'bg-gray-100 border-gray-200 text-gray-600';
                    $cardClass  = 'border-gray-200';
                }
                ?>
                <div class="card meja-card p-5 <?php echo $cardClass; ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Meja</p>
                            <p class="text-3xl font-black mt-1 truncate"><?php echo meja_h($meja['nomor_meja']); ?></p>
                            <?php if (!empty($meja['nama_meja'])): ?>
                                <p class="text-xs font-bold text-gray-500 mt-1 truncate"><?php echo meja_h($meja['nama_meja']); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge <?php echo $badgeClass; ?>"><?php echo meja_h($status); ?></span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-5 pt-4 border-t border-gray-100">
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Kapasitas</p>
                            <p class="text-sm font-black mt-1"><?php echo number_format((int)$meja['kapasitas']); ?> orang</p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Lokasi</p>
                            <p class="text-sm font-black mt-1 truncate"><?php echo meja_h($meja['lokasi'] ?: '-'); ?></p>
                        </div>
                    </div>

                    <?php if (!empty($meja['catatan'])): ?>
                        <div class="mt-4 bg-gray-50 border border-gray-100 p-3">
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Catatan</p>
                            <p class="text-xs text-gray-600 mt-1"><?php echo meja_h($meja['catatan']); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-2 mt-4">
                        <button type="button"
                            onclick='editMeja(<?php echo json_encode($meja, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                            class="btn border border-gray-200 bg-white">
                            Edit
                        </button>

                        <button type="button"
                            onclick="toggleStatusMenu(<?php echo (int)$meja['id']; ?>)"
                            class="btn bg-black text-white">
                            Ubah Status
                        </button>
                    </div>

                    <form method="post" id="status-form-<?php echo (int)$meja['id']; ?>" class="hidden mt-2">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?php echo (int)$meja['id']; ?>">
                        <div class="grid grid-cols-2 gap-2">
                            <button type="submit" name="status" value="kosong" class="btn bg-green-600 text-white">Kosong</button>
                            <button type="submit" name="status" value="terisi" class="btn bg-red-600 text-white">Terisi</button>
                            <button type="submit" name="status" value="reservasi" class="btn bg-amber-500 text-white">Reservasi</button>
                            <button type="submit" name="status" value="nonaktif" class="btn bg-gray-500 text-white">Nonaktif</button>
                        </div>
                    </form>

                    <form method="post" onsubmit="return confirm('Hapus meja ini?')" class="mt-2">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$meja['id']; ?>">
                        <button type="submit" class="btn w-full border border-red-200 bg-red-50 text-red-600">
                            Hapus Meja
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if (empty($mejaList)): ?>
                <div class="card p-10 text-center sm:col-span-2 xl:col-span-3 2xl:col-span-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Belum ada meja cafe</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="mejaModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-2xl bg-white border border-gray-200 max-h-[92vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Form Meja</p>
                    <h2 id="mejaModalTitle" class="text-lg font-black mt-1">Tambah Meja Cafe</h2>
                </div>
                <button type="button" onclick="closeMejaModal()" class="w-10 h-10 border border-gray-200 text-xl font-bold">&times;</button>
            </div>

            <form method="post" class="p-5">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="meja-id" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Nomor Meja</label>
                        <input type="text" name="nomor_meja" id="meja-nomor" required class="field" placeholder="01">
                    </div>

                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Nama Meja</label>
                        <input type="text" name="nama_meja" id="meja-nama" class="field" placeholder="Meja Jendela">
                    </div>

                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Kapasitas</label>
                        <input type="number" name="kapasitas" id="meja-kapasitas" min="1" value="2" class="field">
                    </div>

                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Lokasi</label>
                        <input type="text" name="lokasi" id="meja-lokasi" class="field" placeholder="Indoor / Outdoor / Lantai 1">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Status</label>
                        <select name="status" id="meja-status" class="field">
                            <option value="kosong">Kosong</option>
                            <option value="terisi">Terisi</option>
                            <option value="reservasi">Reservasi</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Catatan</label>
                        <textarea name="catatan" id="meja-catatan" rows="3" class="field" placeholder="Contoh: dekat jendela, khusus 4 orang"></textarea>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-6">
                    <button type="button" onclick="closeMejaModal()" class="btn border border-gray-200 bg-white">Batal</button>
                    <button type="submit" class="btn bg-black text-white">Simpan Meja</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openMejaModal() {
            document.getElementById('mejaModalTitle').textContent = 'Tambah Meja Cafe';
            document.getElementById('meja-id').value = '0';
            document.getElementById('meja-nomor').value = '';
            document.getElementById('meja-nama').value = '';
            document.getElementById('meja-kapasitas').value = '2';
            document.getElementById('meja-lokasi').value = '';
            document.getElementById('meja-status').value = 'kosong';
            document.getElementById('meja-catatan').value = '';

            var modal = document.getElementById('mejaModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function editMeja(meja) {
            document.getElementById('mejaModalTitle').textContent = 'Edit Meja Cafe';
            document.getElementById('meja-id').value = meja.id || 0;
            document.getElementById('meja-nomor').value = meja.nomor_meja || '';
            document.getElementById('meja-nama').value = meja.nama_meja || '';
            document.getElementById('meja-kapasitas').value = meja.kapasitas || 2;
            document.getElementById('meja-lokasi').value = meja.lokasi || '';
            document.getElementById('meja-status').value = meja.status || 'kosong';
            document.getElementById('meja-catatan').value = meja.catatan || '';

            var modal = document.getElementById('mejaModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeMejaModal() {
            var modal = document.getElementById('mejaModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function toggleStatusMenu(id) {
            var form = document.getElementById('status-form-' + id);
            if (!form) return;
            form.classList.toggle('hidden');
        }

        document.getElementById('mejaModal').addEventListener('click', function(event) {
            if (event.target === this) closeMejaModal();
        });
    </script>
</body>

</html>