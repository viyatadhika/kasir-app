<?php
require_once 'config.php';
date_default_timezone_set('Asia/Jakarta');

/**
 * @param mixed $value
 * @return string
 */
function lat_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function lat_status_label(string $status): string
{
    $map = [
        'baru' => 'Pesanan Diterima',
        'diproses' => 'Sedang Diproses',
        'siap_dikirim' => 'Siap Dikirim',
        'dalam_pengiriman' => 'Dalam Pengiriman',
        'selesai' => 'Selesai',
        'batal' => 'Dibatalkan',
    ];
    return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function lat_status_step(string $status): int
{
    $map = [
        'baru' => 1,
        'diproses' => 2,
        'siap_dikirim' => 3,
        'dalam_pengiriman' => 4,
        'selesai' => 5,
        'batal' => 0,
    ];
    return $map[$status] ?? 1;
}

$token = trim((string)($_GET['t'] ?? ''));
$order = null;
$locations = [];
$items = [];
$error = '';

if ($token === '' || !preg_match('/^[a-f0-9]{48}$/i', $token)) {
    $error = 'Link tracking tidak valid.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT p.id,p.nomor_pesanan,p.tanggal_pemesanan,p.tanggal_kirim,p.status,p.created_at,c.nama AS nama_pemesan FROM air_pesanan p JOIN air_pelanggan c ON c.id=p.pelanggan_id WHERE p.tracking_token=:token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$order) {
            $error = 'Pesanan tidak ditemukan atau link tracking sudah tidak berlaku.';
        } else {
            $stmtLoc = $pdo->prepare("SELECT id,lokasi,urutan,catatan FROM air_pesanan_lokasi WHERE pesanan_id=:id ORDER BY urutan ASC,id ASC");
            $stmtLoc->execute([':id' => (int)$order['id']]);
            $locations = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
            $stmtItem = $pdo->prepare("SELECT lokasi_id,nama_produk,qty FROM air_pesanan_detail WHERE pesanan_id=:id ORDER BY lokasi_id ASC,id ASC");
            $stmtItem->execute([':id' => (int)$order['id']]);
            foreach ($stmtItem->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $locationId = (int)($item['lokasi_id'] ?? 0);
                if (!isset($items[$locationId])) $items[$locationId] = [];
                $items[$locationId][] = $item;
            }
        }
    } catch (Throwable $e) {
        $error = 'Tracking pesanan sedang tidak dapat dimuat.';
    }
}

$status = $order ? (string)$order['status'] : '';
$currentStep = lat_status_step($status);
$steps = [
    1 => ['label' => 'Diterima', 'desc' => 'Pesanan sudah masuk ke sistem.'],
    2 => ['label' => 'Diproses', 'desc' => 'Pesanan sedang direkap dan diproses petugas.'],
    3 => ['label' => 'Siap Dikirim', 'desc' => 'Pesanan sudah siap untuk dikirim.'],
    4 => ['label' => 'Dalam Pengiriman', 'desc' => 'Pesanan sedang menuju lokasi tujuan.'],
    5 => ['label' => 'Selesai', 'desc' => 'Pesanan sudah selesai.'],
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>Tracking Pesanan Air - SEJAHUB</title>
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f6f7f9;
            color: #111827
        }

        .surface {
            background: #fff;
            border: 1px solid #eef0f3
        }

        .step-line {
            width: 2px;
            background: #e5e7eb;
            position: absolute;
            top: 30px;
            bottom: -12px;
            left: 15px
        }

        .step:last-child .step-line {
            display: none
        }

        .step-dot {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 2px solid #e5e7eb;
            background: #fff;
            color: #9ca3af;
            position: relative;
            z-index: 2
        }

        .step.done .step-dot {
            background: #111827;
            border-color: #111827;
            color: #fff
        }

        .step.current .step-dot {
            background: #eff6ff;
            border-color: #2563eb;
            color: #2563eb
        }

        @media(max-width:640px) {
            .page-pad {
                padding: 12px !important
            }
        }
    </style>
</head>

<body class="min-h-screen">
    <header class="bg-white border-b border-gray-100">
        <div class="max-w-3xl mx-auto px-4 py-4 flex items-center gap-3"><img src="assets/sejahub_icon.png" alt="SEJAHUB" class="w-10 h-10 object-contain">
            <div>
                <p class="text-[9px] font-extrabold tracking-[.18em] text-gray-400">SEJAHUB SUPER APP</p>
                <h1 class="text-base font-extrabold">Tracking Pesanan Air</h1>
            </div>
        </div>
    </header>
    <main class="max-w-3xl mx-auto p-4 md:p-8 page-pad">
        <?php if ($error !== ''): ?>
            <section class="surface p-6 md:p-8 text-center">
                <div class="w-14 h-14 mx-auto border border-red-200 bg-red-50 text-red-600 flex items-center justify-center rounded-full"><i data-lucide="circle-alert" class="w-6 h-6"></i></div>
                <h2 class="text-lg font-extrabold mt-4">Tracking Tidak Tersedia</h2>
                <p class="text-sm text-gray-500 mt-2"><?= lat_h($error) ?></p><a href="pesan_air.php" class="mt-5 inline-flex items-center justify-center px-4 py-3 bg-black text-white text-xs font-extrabold uppercase tracking-wider">Buat Pesanan Baru</a>
            </section>
        <?php else: ?>
            <section class="surface p-5 md:p-7">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <p class="text-[9px] font-extrabold uppercase tracking-[.16em] text-gray-400">Nomor Pesanan</p>
                        <h2 class="text-xl md:text-2xl font-extrabold mt-1"><?= lat_h($order['nomor_pesanan']) ?></h2>
                        <p class="text-xs text-gray-400 mt-2"><?= lat_h($order['nama_pemesan']) ?></p>
                    </div>
                    <div class="border border-blue-100 bg-blue-50 px-4 py-3">
                        <p class="text-[8px] font-extrabold uppercase tracking-widest text-blue-500">Status Saat Ini</p>
                        <p class="text-sm font-extrabold text-blue-700 mt-1"><?= lat_h(lat_status_label($status)) ?></p>
                    </div>
                </div>
                <?php if ($status === 'batal'): ?><div class="mt-5 border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">Pesanan ini telah dibatalkan.</div><?php else: ?><div class="mt-7"><?php foreach ($steps as $stepNo => $step): $class = $stepNo < $currentStep ? 'done' : ($stepNo === $currentStep ? 'current' : ''); ?><div class="step <?= $class ?> relative flex gap-4 pb-6">
                                <div class="relative">
                                    <div class="step-dot"><?php if ($stepNo < $currentStep): ?><i data-lucide="check" class="w-4 h-4"></i><?php else: ?><?= $stepNo ?><?php endif; ?></div>
                                    <div class="step-line"></div>
                                </div>
                                <div class="pt-1">
                                    <p class="text-sm font-extrabold"><?= lat_h($step['label']) ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?= lat_h($step['desc']) ?></p>
                                </div>
                            </div><?php endforeach; ?></div><?php endif; ?>
            </section>
            <section class="surface p-5 md:p-7 mt-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[9px] font-extrabold uppercase tracking-[.16em] text-gray-400">Rincian Pengantaran</p>
                        <p class="text-sm font-bold mt-1">Tanggal kirim <?= lat_h(date('d/m/Y', strtotime((string)$order['tanggal_kirim']))) ?></p>
                    </div><i data-lucide="map-pin" class="w-5 h-5 text-gray-400"></i>
                </div>
                <div class="mt-4 space-y-3"><?php foreach ($locations as $location): $locationId = (int)$location['id']; ?><div class="border border-gray-100 bg-gray-50 p-4">
                            <p class="text-xs font-extrabold"><?= lat_h($location['lokasi']) ?></p><?php if (!empty($location['catatan'])): ?><p class="text-[10px] text-gray-400 mt-1"><?= lat_h($location['catatan']) ?></p><?php endif; ?><div class="mt-3 space-y-2"><?php foreach (($items[$locationId] ?? []) as $item): ?><div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-2"><span class="text-xs font-semibold"><?= lat_h($item['nama_produk']) ?></span><span class="text-xs font-extrabold text-blue-700"><?= number_format((int)$item['qty']) ?></span></div><?php endforeach; ?></div>
                        </div><?php endforeach; ?></div>
            </section>
            <p class="text-center text-[10px] text-gray-400 mt-5 leading-5">Halaman ini dapat dibuka tanpa login. Jangan bagikan link tracking kepada pihak yang tidak berkepentingan.</p>
        <?php endif; ?>
    </main>
    <script>
        if (window.lucide) lucide.createIcons();
        <?php if (!$error && $status !== 'selesai' && $status !== 'batal'): ?>setTimeout(function() {
            window.location.reload()
        }, 60000);
        <?php endif; ?>
    </script>
</body>

</html>