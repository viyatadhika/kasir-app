<?php
/*
|--------------------------------------------------------------------------
| menu_cafe.php — Kelola Menu Cafe
|--------------------------------------------------------------------------
| - Compatible PHP 7 & 8
| - Menggunakan tabel produk yang sama dengan POS toko
| - Memisahkan menu cafe melalui kolom tipe_produk
| - CRUD tambah, edit, aktif/nonaktif, dan hapus
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'menu_cafe';
$pageTitle  = 'Menu Cafe';
$backUrl    = 'dashboard.php';

if (!function_exists('mc_h')) {
    function mc_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mc_rupiah')) {
    function mc_rupiah($value)
    {
        return 'Rp ' . number_format((float)($value ?? 0), 0, ',', '.');
    }
}

if (!function_exists('mc_has_column')) {
    function mc_has_column(PDO $pdo, $table, $column)
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

if (!function_exists('mc_first_column')) {
    function mc_first_column(PDO $pdo, $table, array $candidates, $fallback = '')
    {
        foreach ($candidates as $candidate) {
            if (mc_has_column($pdo, $table, $candidate)) {
                return $candidate;
            }
        }
        return $fallback;
    }
}

try {
    if (!mc_has_column($pdo, 'produk', 'tipe_produk')) {
        $pdo->exec("
            ALTER TABLE produk
            ADD COLUMN tipe_produk VARCHAR(30) NOT NULL DEFAULT 'retail'
        ");
    }
} catch (Throwable $e) {
    // Halaman tetap berjalan; pesan struktur akan ditampilkan jika query gagal.
}

$idCol       = mc_first_column($pdo, 'produk', ['id', 'produk_id'], 'id');
$kodeCol     = mc_first_column($pdo, 'produk', ['kode', 'kode_produk', 'barcode'], 'kode');
$namaCol     = mc_first_column($pdo, 'produk', ['nama', 'nama_produk'], 'nama');
$kategoriCol = mc_first_column($pdo, 'produk', ['kategori', 'nama_kategori'], 'kategori');
$jualCol     = mc_first_column($pdo, 'produk', ['harga_jual', 'harga', 'harga_satuan'], 'harga_jual');
$beliCol     = mc_first_column($pdo, 'produk', ['harga_beli', 'harga_modal', 'harga_pokok', 'hpp'], '');
$stokCol     = mc_first_column($pdo, 'produk', ['stok', 'stock'], 'stok');
$satuanCol   = mc_first_column($pdo, 'produk', ['satuan', 'unit'], '');
$statusCol   = mc_first_column($pdo, 'produk', ['status', 'aktif'], 'status');
$gambarCol   = mc_first_column($pdo, 'produk', ['gambar', 'foto', 'image'], '');

$allowedTypes = ['makanan', 'minuman', 'topping', 'cafe'];

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $kode      = trim((string)($_POST['kode'] ?? ''));
            $nama      = trim((string)($_POST['nama'] ?? ''));
            $kategori  = trim((string)($_POST['kategori'] ?? ''));
            $tipe      = strtolower(trim((string)($_POST['tipe_produk'] ?? 'makanan')));
            $hargaJual = (float)preg_replace('/[^0-9]/', '', (string)($_POST['harga_jual'] ?? 0));
            $hargaBeli = (float)preg_replace('/[^0-9]/', '', (string)($_POST['harga_beli'] ?? 0));
            $stok      = (int)preg_replace('/[^0-9]/', '', (string)($_POST['stok'] ?? 0));
            $satuan    = trim((string)($_POST['satuan'] ?? 'pcs'));
            $status    = trim((string)($_POST['status'] ?? 'aktif'));

            if ($kode === '' || $nama === '') {
                throw new RuntimeException('Kode dan nama menu wajib diisi.');
            }

            if (!in_array($tipe, $allowedTypes, true)) {
                $tipe = 'makanan';
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                $status = 'aktif';
            }

            $fields = [
                "`$kodeCol`"     => ':kode',
                "`$namaCol`"     => ':nama',
                "`$kategoriCol`" => ':kategori',
                "`$jualCol`"     => ':harga_jual',
                "`$stokCol`"     => ':stok',
                "`$statusCol`"   => ':status',
                "`tipe_produk`"  => ':tipe_produk',
            ];

            $params = [
                ':kode'         => $kode,
                ':nama'         => $nama,
                ':kategori'     => $kategori,
                ':harga_jual'   => $hargaJual,
                ':stok'         => $stok,
                ':status'       => $status,
                ':tipe_produk'  => $tipe,
            ];

            if ($beliCol !== '') {
                $fields["`$beliCol`"] = ':harga_beli';
                $params[':harga_beli'] = $hargaBeli;
            }

            if ($satuanCol !== '') {
                $fields["`$satuanCol`"] = ':satuan';
                $params[':satuan'] = $satuan;
            }

            if ($id > 0) {
                $set = [];
                foreach ($fields as $field => $placeholder) {
                    $set[] = $field . '=' . $placeholder;
                }
                $params[':id'] = $id;

                $stmt = $pdo->prepare("
                    UPDATE produk
                    SET " . implode(', ', $set) . "
                    WHERE `$idCol` = :id
                ");
                $stmt->execute($params);
                $flash = 'Menu cafe berhasil diperbarui.';
            } else {
                $columns = array_keys($fields);
                $values  = array_values($fields);

                $stmt = $pdo->prepare("
                    INSERT INTO produk (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $values) . ")
                ");
                $stmt->execute($params);
                $flash = 'Menu cafe berhasil ditambahkan.';
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $current = trim((string)($_POST['current_status'] ?? 'aktif'));
            $next = $current === 'aktif' ? 'nonaktif' : 'aktif';

            $stmt = $pdo->prepare("
                UPDATE produk
                SET `$statusCol` = :status
                WHERE `$idCol` = :id
            ");
            $stmt->execute([
                ':status' => $next,
                ':id'     => $id,
            ]);
            $flash = 'Status menu berhasil diperbarui.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM produk
                WHERE `$idCol` = :id
                  AND tipe_produk IN ('makanan','minuman','topping','cafe')
            ");
            $stmt->execute([':id' => $id]);
            $flash = 'Menu cafe berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$filterType = strtolower(trim((string)($_GET['tipe'] ?? '')));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? '')));

$where = ["tipe_produk IN ('makanan','minuman','topping','cafe')"];
$params = [];

if ($q !== '') {
    $where[] = "(`$kodeCol` LIKE :q OR `$namaCol` LIKE :q OR `$kategoriCol` LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if (in_array($filterType, $allowedTypes, true)) {
    $where[] = "tipe_produk = :tipe";
    $params[':tipe'] = $filterType;
}

if (in_array($filterStatus, ['aktif', 'nonaktif'], true)) {
    $where[] = "`$statusCol` = :status";
    $params[':status'] = $filterStatus;
}

$select = [
    "`$idCol` AS id",
    "`$kodeCol` AS kode",
    "`$namaCol` AS nama",
    "`$kategoriCol` AS kategori",
    "`$jualCol` AS harga_jual",
    "`$stokCol` AS stok",
    "`$statusCol` AS status",
    "tipe_produk",
];

if ($beliCol !== '') {
    $select[] = "`$beliCol` AS harga_beli";
} else {
    $select[] = "0 AS harga_beli";
}

if ($satuanCol !== '') {
    $select[] = "`$satuanCol` AS satuan";
} else {
    $select[] = "'pcs' AS satuan";
}

if ($gambarCol !== '') {
    $select[] = "`$gambarCol` AS gambar";
} else {
    $select[] = "'' AS gambar";
}

$menus = [];
try {
    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $select) . "
        FROM produk
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            FIELD(tipe_produk, 'makanan', 'minuman', 'topping', 'cafe'),
            `$namaCol` ASC
    ");
    $stmt->execute($params);
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($flash === '') {
        $flash = 'Gagal memuat menu cafe: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$totalAktif = 0;
$totalMakanan = 0;
$totalMinuman = 0;
$totalTopping = 0;

foreach ($menus as $menu) {
    if (($menu['status'] ?? '') === 'aktif') $totalAktif++;
    if (($menu['tipe_produk'] ?? '') === 'makanan') $totalMakanan++;
    if (($menu['tipe_produk'] ?? '') === 'minuman') $totalMinuman++;
    if (($menu['tipe_produk'] ?? '') === 'topping') $totalTopping++;
}

require_once 'sidebar.php';
require_once 'navbar.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #111827;
        }

        .menu-main {
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
            height: 42px;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 0;
        }

        textarea.field {
            height: auto;
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

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border: 1px solid;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        @media (min-width:1024px) {
            .menu-main {
                margin-left: 220px;
            }
        }

        @media (max-width:1023px) {
            .menu-main {
                margin-left: 0;
                padding-bottom: 90px !important;
            }
        }
    </style>
</head>

<body>
    <main class="menu-main p-4 sm:p-5 md:p-8 lg:p-10">
        <?php if ($flash !== ''): ?>
            <div class="mb-5 px-4 py-3 border text-xs font-bold <?php echo $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'; ?>">
                <?php echo mc_h($flash); ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-gray-400">Operasional Cafe</p>
                <h1 class="text-2xl font-black mt-1">Kelola Menu Cafe</h1>
                <p class="text-xs text-gray-400 mt-1">Menu yang aktif otomatis muncul pada Mesin Kasir Cafe.</p>
            </div>
            <button type="button" onclick="openMenuModal()" class="btn bg-black text-white px-5 hover:bg-gray-800">
                Tambah Menu
            </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Menu Aktif</p>
                <p class="text-2xl font-black mt-1"><?php echo number_format($totalAktif); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Makanan</p>
                <p class="text-2xl font-black mt-1"><?php echo number_format($totalMakanan); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Minuman</p>
                <p class="text-2xl font-black mt-1"><?php echo number_format($totalMinuman); ?></p>
            </div>
            <div class="card p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Topping</p>
                <p class="text-2xl font-black mt-1"><?php echo number_format($totalTopping); ?></p>
            </div>
        </div>

        <form method="get" class="card p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <input type="search" name="q" value="<?php echo mc_h($q); ?>" placeholder="Cari kode, nama, kategori..." class="field md:col-span-2">
                <select name="tipe" class="field">
                    <option value="">Semua Jenis</option>
                    <option value="makanan" <?php echo $filterType === 'makanan' ? 'selected' : ''; ?>>Makanan</option>
                    <option value="minuman" <?php echo $filterType === 'minuman' ? 'selected' : ''; ?>>Minuman</option>
                    <option value="topping" <?php echo $filterType === 'topping' ? 'selected' : ''; ?>>Topping</option>
                    <option value="cafe" <?php echo $filterType === 'cafe' ? 'selected' : ''; ?>>Cafe</option>
                </select>
                <select name="status" class="field">
                    <option value="">Semua Status</option>
                    <option value="aktif" <?php echo $filterStatus === 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="nonaktif" <?php echo $filterStatus === 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 mt-3">
                <button type="submit" class="btn bg-black text-white px-5">Terapkan</button>
                <a href="menu_cafe.php" class="btn border border-gray-200 bg-white text-gray-600 px-5 inline-flex items-center justify-center">Reset</a>
            </div>
        </form>

        <div class="hidden lg:block card overflow-x-auto">
            <table class="w-full min-w-[900px] text-left">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Kode</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Menu</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400">Jenis</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-right">Harga</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-right">Stok</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-center">Status</th>
                        <th class="px-5 py-4 text-[9px] font-black uppercase tracking-widest text-gray-400 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($menus as $menu): ?>
                        <tr>
                            <td class="px-5 py-4 text-xs font-mono font-bold"><?php echo mc_h($menu['kode']); ?></td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-black"><?php echo mc_h($menu['nama']); ?></p>
                                <p class="text-[10px] text-gray-400 mt-0.5"><?php echo mc_h($menu['kategori'] ?: '-'); ?></p>
                            </td>
                            <td class="px-5 py-4 text-xs font-bold uppercase"><?php echo mc_h($menu['tipe_produk']); ?></td>
                            <td class="px-5 py-4 text-xs font-black text-right"><?php echo mc_rupiah($menu['harga_jual']); ?></td>
                            <td class="px-5 py-4 text-xs font-black text-right"><?php echo number_format((int)$menu['stok']); ?> <?php echo mc_h($menu['satuan']); ?></td>
                            <td class="px-5 py-4 text-center">
                                <span class="badge <?php echo $menu['status'] === 'aktif' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-100 border-gray-200 text-gray-600'; ?>">
                                    <?php echo mc_h($menu['status']); ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <button type="button" onclick='editMenu(<?php echo json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-gray-200 bg-white px-3">Edit</button>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo mc_h($menu['status']); ?>">
                                        <button type="submit" class="btn border border-gray-200 bg-white px-3"><?php echo $menu['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Hapus menu ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                                        <button type="submit" class="btn bg-red-600 text-white px-3">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($menus)): ?>
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-xs font-bold text-gray-400 uppercase tracking-widest">
                                Belum ada menu cafe
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="lg:hidden grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($menus as $menu): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-black truncate"><?php echo mc_h($menu['nama']); ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?php echo mc_h($menu['kode']); ?> · <?php echo mc_h($menu['kategori'] ?: '-'); ?></p>
                        </div>
                        <span class="badge <?php echo $menu['status'] === 'aktif' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-gray-100 border-gray-200 text-gray-600'; ?>">
                            <?php echo mc_h($menu['status']); ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-4 pt-4 border-t border-gray-100">
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Jenis</p>
                            <p class="text-xs font-bold uppercase mt-1"><?php echo mc_h($menu['tipe_produk']); ?></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Harga</p>
                            <p class="text-xs font-bold mt-1"><?php echo mc_rupiah($menu['harga_jual']); ?></p>
                        </div>
                        <div>
                            <p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Stok</p>
                            <p class="text-xs font-bold mt-1"><?php echo number_format((int)$menu['stok']); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <button type="button" onclick='editMenu(<?php echo json_encode($menu, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn border border-gray-200 bg-white">Edit</button>
                        <form method="post">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo mc_h($menu['status']); ?>">
                            <button type="submit" class="btn border border-gray-200 bg-white w-full"><?php echo $menu['status'] === 'aktif' ? 'Nonaktif' : 'Aktif'; ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('Hapus menu ini?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$menu['id']; ?>">
                            <button type="submit" class="btn bg-red-600 text-white w-full">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <div id="menuModal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-black/50 p-4">
        <div class="w-full max-w-2xl bg-white border border-gray-200 max-h-[92vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Form Menu</p>
                    <h2 id="menuModalTitle" class="text-lg font-black mt-1">Tambah Menu Cafe</h2>
                </div>
                <button type="button" onclick="closeMenuModal()" class="w-10 h-10 border border-gray-200 text-xl font-bold">&times;</button>
            </div>

            <form method="post" class="p-5">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="menu-id" value="0">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Kode Menu</label>
                        <input type="text" name="kode" id="menu-kode" required class="field" placeholder="CF001">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Nama Menu</label>
                        <input type="text" name="nama" id="menu-nama" required class="field" placeholder="Es Kopi Susu">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Kategori</label>
                        <input type="text" name="kategori" id="menu-kategori" class="field" placeholder="Kopi / Makanan Berat">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Jenis</label>
                        <select name="tipe_produk" id="menu-tipe" class="field">
                            <option value="makanan">Makanan</option>
                            <option value="minuman">Minuman</option>
                            <option value="topping">Topping</option>
                            <option value="cafe">Cafe</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Harga Beli / Modal</label>
                        <input type="text" name="harga_beli" id="menu-harga-beli" inputmode="numeric" class="field" placeholder="10000">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Harga Jual</label>
                        <input type="text" name="harga_jual" id="menu-harga-jual" inputmode="numeric" required class="field" placeholder="15000">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Stok</label>
                        <input type="number" name="stok" id="menu-stok" min="0" class="field" value="0">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Satuan</label>
                        <input type="text" name="satuan" id="menu-satuan" class="field" value="pcs">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Status</label>
                        <select name="status" id="menu-status" class="field">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-6">
                    <button type="button" onclick="closeMenuModal()" class="btn border border-gray-200 bg-white">Batal</button>
                    <button type="submit" class="btn bg-black text-white">Simpan Menu</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openMenuModal() {
            document.getElementById('menuModalTitle').textContent = 'Tambah Menu Cafe';
            document.getElementById('menu-id').value = '0';
            document.getElementById('menu-kode').value = '';
            document.getElementById('menu-nama').value = '';
            document.getElementById('menu-kategori').value = '';
            document.getElementById('menu-tipe').value = 'makanan';
            document.getElementById('menu-harga-beli').value = '';
            document.getElementById('menu-harga-jual').value = '';
            document.getElementById('menu-stok').value = '0';
            document.getElementById('menu-satuan').value = 'pcs';
            document.getElementById('menu-status').value = 'aktif';

            var modal = document.getElementById('menuModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function editMenu(menu) {
            document.getElementById('menuModalTitle').textContent = 'Edit Menu Cafe';
            document.getElementById('menu-id').value = menu.id || 0;
            document.getElementById('menu-kode').value = menu.kode || '';
            document.getElementById('menu-nama').value = menu.nama || '';
            document.getElementById('menu-kategori').value = menu.kategori || '';
            document.getElementById('menu-tipe').value = menu.tipe_produk || 'makanan';
            document.getElementById('menu-harga-beli').value = Number(menu.harga_beli || 0);
            document.getElementById('menu-harga-jual').value = Number(menu.harga_jual || 0);
            document.getElementById('menu-stok').value = Number(menu.stok || 0);
            document.getElementById('menu-satuan').value = menu.satuan || 'pcs';
            document.getElementById('menu-status').value = menu.status || 'aktif';

            var modal = document.getElementById('menuModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeMenuModal() {
            var modal = document.getElementById('menuModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('menuModal').addEventListener('click', function(event) {
            if (event.target === this) closeMenuModal();
        });
    </script>
</body>

</html>