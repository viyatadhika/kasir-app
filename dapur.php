<?php
/*
|--------------------------------------------------------------------------
| dapur.php — Kitchen Display System Cafe
|--------------------------------------------------------------------------
| - Compatible PHP 7 & 8
| - Menampilkan antrean pesanan cafe
| - Status: baru, diproses, siap, selesai, batal
| - Auto refresh tanpa reload penuh
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';
requireAccess();

$activeMenu = 'dapur';
$pageTitle  = 'Dapur Cafe';
$backUrl    = 'dashboard.php';

if (!function_exists('dapur_h')) {
    function dapur_h($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dapur_table_exists')) {
    function dapur_table_exists(PDO $pdo, $table)
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

if (!function_exists('dapur_column_exists')) {
    function dapur_column_exists(PDO $pdo, $table, $column)
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
                ':table_name' => (string)$table,
                ':column_name' => (string)$column,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

try {
    if (!dapur_table_exists($pdo, 'cafe_pesanan')) {
        $pdo->exec("
            CREATE TABLE cafe_pesanan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaksi_id INT NULL,
                nomor_pesanan VARCHAR(50) NULL,
                meja_id INT NULL,
                tipe_pesanan ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
                status ENUM('baru','diproses','siap','selesai','batal') NOT NULL DEFAULT 'baru',
                catatan TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_cafe_pesanan_status (status),
                INDEX idx_cafe_pesanan_transaksi (transaksi_id),
                INDEX idx_cafe_pesanan_meja (meja_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        if (!dapur_column_exists($pdo, 'cafe_pesanan', 'nomor_pesanan')) {
            $pdo->exec("ALTER TABLE cafe_pesanan ADD COLUMN nomor_pesanan VARCHAR(50) NULL AFTER transaksi_id");
        }
        if (!dapur_column_exists($pdo, 'cafe_pesanan', 'updated_at')) {
            $pdo->exec("ALTER TABLE cafe_pesanan ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        }
    }
} catch (Throwable $e) {
    // tetap lanjut
}

if (isset($_GET['action']) && $_GET['action'] === 'list') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
        $allowed = ['baru', 'diproses', 'siap', 'selesai', 'batal'];

        $where = [];
        $params = [];

        if (in_array($statusFilter, $allowed, true)) {
            $where[] = 'cp.status = :status';
            $params[':status'] = $statusFilter;
        } else {
            $where[] = "cp.status IN ('baru','diproses','siap')";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                cp.*,
                cm.nomor_meja,
                cm.nama_meja,
                t.invoice,
                t.total,
                t.created_at AS transaksi_created_at
            FROM cafe_pesanan cp
            LEFT JOIN cafe_meja cm ON cm.id = cp.meja_id
            LEFT JOIN transaksi t ON t.id = cp.transaksi_id
            $whereSql
            ORDER BY
                FIELD(cp.status, 'baru', 'diproses', 'siap', 'selesai', 'batal'),
                cp.created_at ASC,
                cp.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pesanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];

        foreach ($pesanan as $p) {
            $detail = [];

            if (!empty($p['transaksi_id']) && dapur_table_exists($pdo, 'transaksi_detail')) {
                $stmtDetail = $pdo->prepare("
                    SELECT *
                    FROM transaksi_detail
                    WHERE transaksi_id = :transaksi_id
                    ORDER BY id ASC
                ");
                $stmtDetail->execute([':transaksi_id' => (int)$p['transaksi_id']]);
                $detail = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);
            }

            $items[] = [
                'id' => (int)$p['id'],
                'transaksi_id' => (int)($p['transaksi_id'] ?? 0),
                'nomor_pesanan' => (string)($p['nomor_pesanan'] ?: ($p['invoice'] ?? ('ORDER-' . $p['id']))),
                'nomor_meja' => (string)($p['nomor_meja'] ?? ''),
                'nama_meja' => (string)($p['nama_meja'] ?? ''),
                'tipe_pesanan' => (string)($p['tipe_pesanan'] ?? 'dine_in'),
                'status' => (string)($p['status'] ?? 'baru'),
                'catatan' => (string)($p['catatan'] ?? ''),
                'created_at' => (string)($p['created_at'] ?? ''),
                'updated_at' => (string)($p['updated_at'] ?? ''),
                'total' => (float)($p['total'] ?? 0),
                'items' => array_map(function ($d) {
                    return [
                        'nama' => (string)($d['nama'] ?? 'Menu'),
                        'qty' => (int)($d['qty'] ?? 0),
                        'catatan_item' => (string)($d['catatan_item'] ?? ''),
                    ];
                }, $detail),
            ];
        }

        echo json_encode([
            'success' => true,
            'items' => $items,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'status') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $id = (int)($input['id'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? '')));

        if ($id <= 0) {
            throw new RuntimeException('ID pesanan tidak valid.');
        }

        if (!in_array($status, ['baru', 'diproses', 'siap', 'selesai', 'batal'], true)) {
            throw new RuntimeException('Status pesanan tidak valid.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE cafe_pesanan
            SET status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Pesanan tidak ditemukan.');
        }

        $stmtPesanan = $pdo->prepare("
            SELECT meja_id, tipe_pesanan
            FROM cafe_pesanan
            WHERE id = :id
            LIMIT 1
        ");
        $stmtPesanan->execute([':id' => $id]);
        $pesanan = $stmtPesanan->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($pesanan['meja_id']) && ($pesanan['tipe_pesanan'] ?? '') === 'dine_in') {
            if ($status === 'selesai' || $status === 'batal') {
                $stmtMeja = $pdo->prepare("
                    UPDATE cafe_meja
                    SET status = 'kosong',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtMeja->execute([':id' => (int)$pesanan['meja_id']]);
            } elseif (in_array($status, ['baru', 'diproses', 'siap'], true)) {
                $stmtMeja = $pdo->prepare("
                    UPDATE cafe_meja
                    SET status = 'terisi',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtMeja->execute([':id' => (int)$pesanan['meja_id']]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Status pesanan berhasil diperbarui.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
    <title>Dapur Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .dapur-main {
            min-height: calc(100vh - 64px);
        }

        .board {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0;
        }

        .order-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0;
            transition: transform .15s ease, border-color .15s ease;
        }

        .order-card:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }

        .btn {
            min-height: 40px;
            border-radius: 0;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .status-pill {
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

        .status-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: currentColor;
        }

        @media (min-width:1024px) {
            .dapur-main {
                margin-left: 220px;
            }
        }

        @media (max-width:1023px) {
            .dapur-main {
                margin-left: 0;
                padding-bottom: 90px !important;
            }
        }
    </style>
</head>

<body>
    <main class="dapur-main p-4 sm:p-5 md:p-8 lg:p-10">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-gray-400">Kitchen Display System</p>
                <h1 class="text-2xl font-black mt-1">Antrean Dapur Cafe</h1>
                <p class="text-xs text-gray-400 mt-1">Pesanan baru akan muncul otomatis tanpa perlu refresh halaman.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" data-filter="" class="filter-btn btn bg-black text-white px-4">Aktif</button>
                <button type="button" data-filter="baru" class="filter-btn btn border border-gray-200 bg-white px-4">Baru</button>
                <button type="button" data-filter="diproses" class="filter-btn btn border border-gray-200 bg-white px-4">Diproses</button>
                <button type="button" data-filter="siap" class="filter-btn btn border border-gray-200 bg-white px-4">Siap</button>
                <button type="button" data-filter="selesai" class="filter-btn btn border border-gray-200 bg-white px-4">Selesai</button>
            </div>
        </div>

        <div id="summaryGrid" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="board p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Pesanan Baru</p>
                <p id="count-baru" class="text-2xl font-black mt-1">0</p>
            </div>
            <div class="board p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-blue-600">Diproses</p>
                <p id="count-diproses" class="text-2xl font-black text-blue-700 mt-1">0</p>
            </div>
            <div class="board p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-green-600">Siap Diantar</p>
                <p id="count-siap" class="text-2xl font-black text-green-700 mt-1">0</p>
            </div>
            <div class="board p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Total Aktif</p>
                <p id="count-total" class="text-2xl font-black mt-1">0</p>
            </div>
        </div>

        <div id="dapurAlert" class="hidden mb-5 px-4 py-3 border border-red-200 bg-red-50 text-xs font-bold text-red-700"></div>

        <div id="orderGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
            <div class="board p-10 text-center md:col-span-2 xl:col-span-3 2xl:col-span-4">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Memuat pesanan...</p>
            </div>
        </div>
    </main>

    <script>
        var currentFilter = '';
        var loadingOrders = false;
        var lastSignature = '';

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatTime(value) {
            if (!value) return '-';
            var date = new Date(String(value).replace(' ', 'T'));
            if (isNaN(date.getTime())) return value;
            return date.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function elapsedMinutes(value) {
            if (!value) return 0;
            var start = new Date(String(value).replace(' ', 'T'));
            if (isNaN(start.getTime())) return 0;
            return Math.max(0, Math.floor((Date.now() - start.getTime()) / 60000));
        }

        function statusClass(status) {
            if (status === 'baru') return 'bg-amber-50 border-amber-200 text-amber-700';
            if (status === 'diproses') return 'bg-blue-50 border-blue-200 text-blue-700';
            if (status === 'siap') return 'bg-green-50 border-green-200 text-green-700';
            if (status === 'selesai') return 'bg-gray-100 border-gray-200 text-gray-700';
            return 'bg-red-50 border-red-200 text-red-700';
        }

        function typeLabel(type) {
            return type === 'takeaway' ? 'Takeaway' : 'Dine In';
        }

        function renderOrders(items) {
            var grid = document.getElementById('orderGrid');

            if (!Array.isArray(items) || items.length === 0) {
                grid.innerHTML =
                    '<div class="board p-10 text-center md:col-span-2 xl:col-span-3 2xl:col-span-4">' +
                    '<p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Tidak ada pesanan</p>' +
                    '</div>';
                updateSummary([]);
                return;
            }

            var html = items.map(function(order) {
                var details = Array.isArray(order.items) ? order.items : [];
                var itemHtml = details.length > 0 ?
                    details.map(function(item) {
                        var note = item.catatan_item ?
                            '<p class="text-[10px] text-amber-700 mt-1">Catatan: ' + escapeHtml(item.catatan_item) + '</p>' :
                            '';
                        return '<div class="py-2 border-b border-gray-100 last:border-b-0">' +
                            '<div class="flex items-start justify-between gap-3">' +
                            '<p class="text-sm font-bold">' + escapeHtml(item.nama) + '</p>' +
                            '<span class="text-sm font-black shrink-0">' + Number(item.qty || 0) + 'x</span>' +
                            '</div>' + note +
                            '</div>';
                    }).join('') :
                    '<p class="text-xs text-gray-400 py-3">Detail item belum tersedia.</p>';

                var tableInfo = order.tipe_pesanan === 'takeaway' ?
                    'Takeaway' :
                    ('Meja ' + escapeHtml(order.nomor_meja || '-'));

                var age = elapsedMinutes(order.created_at);
                var ageClass = age >= 20 ? 'text-red-600' : (age >= 10 ? 'text-amber-600' : 'text-gray-500');

                var actions = '';
                if (order.status === 'baru') {
                    actions =
                        '<button onclick="updateStatus(' + Number(order.id) + ',\'diproses\')" class="btn bg-blue-600 text-white w-full">Mulai Proses</button>';
                } else if (order.status === 'diproses') {
                    actions =
                        '<button onclick="updateStatus(' + Number(order.id) + ',\'siap\')" class="btn bg-green-600 text-white w-full">Tandai Siap</button>';
                } else if (order.status === 'siap') {
                    actions =
                        '<button onclick="updateStatus(' + Number(order.id) + ',\'selesai\')" class="btn bg-black text-white w-full">Selesaikan</button>';
                } else {
                    actions =
                        '<button onclick="updateStatus(' + Number(order.id) + ',\'baru\')" class="btn border border-gray-200 bg-white w-full">Aktifkan Kembali</button>';
                }

                return '<div class="order-card p-5">' +
                    '<div class="flex items-start justify-between gap-3">' +
                    '<div class="min-w-0">' +
                    '<p class="text-[9px] font-black uppercase tracking-widest text-gray-400">Nomor Pesanan</p>' +
                    '<p class="text-xl font-black mt-1 truncate">' + escapeHtml(order.nomor_pesanan) + '</p>' +
                    '<p class="text-xs text-gray-500 mt-1">' + tableInfo + ' · ' + typeLabel(order.tipe_pesanan) + '</p>' +
                    '</div>' +
                    '<span class="status-pill ' + statusClass(order.status) + '">' + escapeHtml(order.status) + '</span>' +
                    '</div>' +

                    '<div class="grid grid-cols-2 gap-3 mt-4 pt-4 border-t border-gray-100">' +
                    '<div>' +
                    '<p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Masuk</p>' +
                    '<p class="text-sm font-bold mt-1">' + formatTime(order.created_at) + '</p>' +
                    '</div>' +
                    '<div>' +
                    '<p class="text-[8px] font-black uppercase tracking-widest text-gray-400">Durasi</p>' +
                    '<p class="text-sm font-black mt-1 ' + ageClass + '">' + age + ' menit</p>' +
                    '</div>' +
                    '</div>' +

                    '<div class="mt-4">' + itemHtml + '</div>' +

                    (order.catatan ?
                        '<div class="mt-4 bg-amber-50 border border-amber-100 p-3">' +
                        '<p class="text-[8px] font-black uppercase tracking-widest text-amber-600">Catatan Pesanan</p>' +
                        '<p class="text-xs text-amber-800 mt-1">' + escapeHtml(order.catatan) + '</p>' +
                        '</div>' :
                        '') +

                    '<div class="grid grid-cols-1 gap-2 mt-4">' +
                    actions +
                    (order.status !== 'batal' && order.status !== 'selesai' ?
                        '<button onclick="updateStatus(' + Number(order.id) + ',\'batal\')" class="btn border border-red-200 bg-red-50 text-red-600 w-full">Batalkan</button>' :
                        '') +
                    '</div>' +
                    '</div>';
            }).join('');

            grid.innerHTML = html;
            updateSummary(items);
        }

        function updateSummary(items) {
            var counts = {
                baru: 0,
                diproses: 0,
                siap: 0
            };

            items.forEach(function(item) {
                if (counts.hasOwnProperty(item.status)) {
                    counts[item.status]++;
                }
            });

            document.getElementById('count-baru').textContent = counts.baru;
            document.getElementById('count-diproses').textContent = counts.diproses;
            document.getElementById('count-siap').textContent = counts.siap;
            document.getElementById('count-total').textContent = counts.baru + counts.diproses + counts.siap;
        }

        function showError(message) {
            var el = document.getElementById('dapurAlert');
            el.textContent = message;
            el.classList.remove('hidden');
        }

        function clearError() {
            document.getElementById('dapurAlert').classList.add('hidden');
        }

        function loadOrders(force) {
            if (loadingOrders || document.hidden) return;

            loadingOrders = true;
            clearError();

            fetch('dapur.php?action=list&status=' + encodeURIComponent(currentFilter) + '&_=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || payload.success !== true) {
                        throw new Error((payload && payload.message) || 'Respons tidak valid');
                    }

                    var signature = JSON.stringify(payload.items || []);
                    if (force || signature !== lastSignature) {
                        lastSignature = signature;
                        renderOrders(payload.items || []);
                    }
                })
                .catch(function(error) {
                    showError('Gagal memuat antrean dapur: ' + error.message);
                })
                .finally(function() {
                    loadingOrders = false;
                });
        }

        function updateStatus(id, status) {
            if (status === 'batal' && !confirm('Batalkan pesanan ini?')) {
                return;
            }

            fetch('dapur.php?action=status', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        status: status
                    })
                })
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || payload.success !== true) {
                        throw new Error((payload && payload.message) || 'Gagal memperbarui status');
                    }
                    loadOrders(true);
                })
                .catch(function(error) {
                    alert(error.message);
                });
        }

        document.querySelectorAll('.filter-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                currentFilter = this.getAttribute('data-filter') || '';

                document.querySelectorAll('.filter-btn').forEach(function(btn) {
                    btn.className = 'filter-btn btn border border-gray-200 bg-white px-4';
                });

                this.className = 'filter-btn btn bg-black text-white px-4';
                loadOrders(true);
            });
        });

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) loadOrders(true);
        });

        window.addEventListener('focus', function() {
            loadOrders(true);
        });

        loadOrders(true);
        setInterval(function() {
            loadOrders(false);
        }, 3000);
    </script>
</body>

</html>