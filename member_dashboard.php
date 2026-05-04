<?php
session_start();
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| member_dashboard.php - FINAL FIX
|--------------------------------------------------------------------------
| Dashboard pembeli/member:
| - Data member
| - Total point dari tabel member
| - Total belanja dihitung dari transaksi member agar akurat
| - Riwayat transaksi
| - Diskon ditampilkan dari harga sebelum diskon - harga setelah diskon
| - Point dari transaksi.point_dapat dengan fallback total / 10.000
| - Link struk
*/

if (isset($_GET['logout'])) {
    unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_kode']);
    header('Location: member_login.php');
    exit;
}

if (empty($_SESSION['member_id'])) {
    header('Location: member_login.php');
    exit;
}

/**
 * Escape output for safe HTML rendering.
 *
 * @param mixed $v
 * @return string
 */
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format value as Indonesian Rupiah.
 *
 * @param mixed $v
 * @return string
 */
function rupiah_member($v): string
{
    return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * Format numeric value with Indonesian separators.
 *
 * @param mixed $v
 * @return string
 */
function angka_member($v): string
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

/**
 * Format datetime for member transaction display.
 *
 * @param mixed $v
 * @return string
 */
function tanggal_member($v): string
{
    return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
}

function has_column_member(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$memberId = (int)$_SESSION['member_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            kode, 
            nama, 
            no_hp, 
            point, 
            total_belanja, 
            status, 
            created_at, 
            updated_at
        FROM member
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        unset($_SESSION['member_id'], $_SESSION['member_nama'], $_SESSION['member_kode']);
        header('Location: member_login.php');
        exit;
    }

    $hasDiskon = has_column_member($pdo, 'transaksi', 'diskon');
    $hasPoint  = has_column_member($pdo, 'transaksi', 'point_dapat');
    $hasPointPakai = has_column_member($pdo, 'transaksi', 'point_pakai');
    $hasNilaiPointPakai = has_column_member($pdo, 'transaksi', 'nilai_point_pakai');

    $diskonSelect = $hasDiskon ? "COALESCE(t.diskon, 0) AS diskon_transaksi" : "0 AS diskon_transaksi";
    $pointSelect  = $hasPoint  ? "COALESCE(t.point_dapat, 0) AS point_transaksi" : "0 AS point_transaksi";
    $pointPakaiSelect = $hasPointPakai ? "COALESCE(t.point_pakai, 0) AS point_pakai" : "0 AS point_pakai";
    $nilaiPointPakaiSelect = $hasNilaiPointPakai ? "COALESCE(t.nilai_point_pakai, 0) AS nilai_point_pakai" : "0 AS nilai_point_pakai";

    $sumDiskon = $hasDiskon ? "COALESCE(SUM(t.diskon), 0)" : "0";
    $sumPoint  = $hasPoint
        ? "COALESCE(SUM(CASE WHEN COALESCE(t.point_dapat,0) > 0 THEN t.point_dapat ELSE FLOOR(COALESCE(t.total,0) / 10000) END), 0)"
        : "COALESCE(SUM(FLOOR(COALESCE(t.total,0) / 10000)), 0)";
    $sumPointPakai = $hasPointPakai ? "COALESCE(SUM(t.point_pakai), 0)" : "0";
    $sumNilaiPointPakai = $hasNilaiPointPakai ? "COALESCE(SUM(t.nilai_point_pakai), 0)" : "0";

    $stmtSum = $pdo->prepare("
        SELECT 
            COUNT(t.id) AS jumlah_transaksi,
            COALESCE(SUM(t.total), 0) AS total_belanja_transaksi,
            COALESCE(SUM(
                GREATEST(
                    COALESCE(t.diskon, 0),
                    GREATEST(
                        0,
                        COALESCE((
                            SELECT SUM(COALESCE(td.harga_normal, td.harga) * td.qty)
                            FROM transaksi_detail td
                            WHERE td.transaksi_id = t.id
                        ), t.total) - COALESCE(t.total, 0)
                    )
                )
            ), 0) AS total_diskon_transaksi,
            $sumPoint AS total_point_dari_transaksi,
            $sumPointPakai AS total_point_pakai,
            $sumNilaiPointPakai AS total_nilai_point_pakai
        FROM transaksi t
        WHERE t.member_id = :member_id
    ");
    $stmtSum->execute([':member_id' => $memberId]);
    $summary = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtTrx = $pdo->prepare("
        SELECT 
            t.id AS transaksi_id,
            t.invoice AS invoice,
            t.created_at AS tanggal_transaksi,
            COALESCE(t.total, 0) AS total_transaksi,
            COALESCE(t.bayar, 0) AS bayar_transaksi,
            COALESCE(t.kembalian, 0) AS kembalian_transaksi,
            COALESCE((
                SELECT SUM(
                    COALESCE(td.harga_normal, td.harga) * td.qty
                )
                FROM transaksi_detail td
                WHERE td.transaksi_id = t.id
            ), t.total) AS total_sebelum_diskon,
            $diskonSelect,
            $pointSelect,
            $pointPakaiSelect,
            $nilaiPointPakaiSelect
        FROM transaksi t
        WHERE t.member_id = :member_id
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 50
    ");
    $stmtTrx->execute([':member_id' => $memberId]);
    $transaksi = $stmtTrx->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die('Gagal memuat dashboard member: ' . h($e->getMessage()));
}

$jumlahTransaksi         = (int)($summary['jumlah_transaksi']          ?? 0);
$totalBelanjaTransaksi   = (int)($summary['total_belanja_transaksi']   ?? 0);
$totalDiskonTransaksi    = (int)($summary['total_diskon_transaksi']    ?? 0);
$totalPointDariTransaksi = (int)($summary['total_point_dari_transaksi'] ?? 0);
$totalPointPakai          = (int)($summary['total_point_pakai'] ?? 0);
$totalNilaiPointPakai     = (int)($summary['total_nilai_point_pakai'] ?? 0);

$saldoPoint       = (int)($member['point']         ?? 0);
$totalBelanjaProfil = (int)($member['total_belanja'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Dashboard Member - Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
            color: #111827;
        }

        .border-subtle {
            border-color: #e5e7eb;
        }

        button,
        a.btn {
            border-radius: 2px !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: .08em !important;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .member-mobile-card {
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .member-mobile-card:hover {
            transform: translateY(-1px);
            border-color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        }

        @media (max-width: 1023px) {
            body {
                background: #fcfcfc;
                padding-bottom: 76px;
            }

            header {
                padding: 1rem !important;
                gap: .75rem;
            }

            header>div:first-child h1 {
                max-width: 170px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            main {
                padding: 1rem !important;
                max-width: 100% !important;
                gap: 1rem !important;
            }

            section {
                border-radius: 2px;
            }

            .summary-grid-responsive {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: .75rem !important;
            }

            .desktop-table-wrap {
                display: none !important;
            }

            .mobile-card-list {
                display: block !important;
            }

            .member-profile-responsive {
                gap: 1rem !important;
            }

            .member-profile-name {
                font-size: 1.35rem !important;
                line-height: 1.2 !important;
            }
        }

        @media (max-width: 640px) {
            .summary-grid-responsive {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .summary-grid-responsive>div {
                padding: 1rem !important;
            }

            .summary-grid-responsive p:nth-child(2) {
                font-size: 1.15rem !important;
                line-height: 1.2 !important;
            }

            .member-mobile-card {
                padding: 1rem !important;
            }
        }
    </style>
</head>

<body class="min-h-screen">
    <header class="bg-white border-b border-subtle px-4 sm:px-8 py-4 flex justify-between items-center sticky top-0 z-10">
        <div>
            <div class="text-sm font-black tracking-tighter border-b-2 border-black inline-block pb-1">KOPERASI BSDK</div>
            <h1 class="mt-2 text-sm font-black uppercase tracking-[0.2em]">Dashboard Member</h1>
        </div>
        <a href="member_dashboard.php?logout=1" class="btn px-4 py-2 border border-red-200 text-red-600 hover:bg-red-50">
            Logout
        </a>
    </header>

    <main class="p-4 sm:p-8 max-w-6xl mx-auto space-y-6">

        <section class="bg-white border border-subtle shadow-sm p-6">
            <div class="member-profile-responsive flex flex-col md:flex-row md:items-center md:justify-between gap-5">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Member Aktif</p>
                    <h2 class="member-profile-name mt-2 text-2xl font-black uppercase"><?= h($member['nama']) ?></h2>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold text-gray-500">
                        <span class="border border-subtle px-3 py-1"><?= h($member['kode']) ?></span>
                        <span class="border border-subtle px-3 py-1"><?= h($member['no_hp']) ?></span>
                        <span class="border border-green-200 bg-green-50 text-green-700 px-3 py-1"><?= h($member['status']) ?></span>
                    </div>
                </div>

                <div class="text-left md:text-right">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Saldo Point Saat Ini</p>
                    <p class="mt-1 text-4xl font-black text-blue-600"><?= angka_member($saldoPoint) ?> pt</p>
                </div>
            </div>
        </section>

        <section class="summary-grid-responsive grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="bg-white border border-subtle shadow-sm p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Total Belanja</p>
                <p class="mt-2 text-2xl font-black"><?= rupiah_member($totalBelanjaTransaksi) ?></p>
                <?php if ($totalBelanjaProfil !== $totalBelanjaTransaksi): ?>
                    <p class="mt-1 text-[10px] text-orange-500 font-bold uppercase tracking-widest">
                        Profil: <?= rupiah_member($totalBelanjaProfil) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="bg-white border border-subtle shadow-sm p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Jumlah Transaksi</p>
                <p class="mt-2 text-2xl font-black"><?= angka_member($jumlahTransaksi) ?></p>
            </div>

            <div class="bg-white border border-subtle shadow-sm p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Total Diskon</p>
                <p class="mt-2 text-2xl font-black text-red-600"><?= rupiah_member($totalDiskonTransaksi) ?></p>
            </div>

            <div class="bg-white border border-subtle shadow-sm p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Point Dari Transaksi</p>
                <p class="mt-2 text-2xl font-black text-green-600"><?= angka_member($totalPointDariTransaksi) ?> pt</p>
            </div>

            <div class="bg-white border border-subtle shadow-sm p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Point Ditukar</p>
                <p class="mt-2 text-2xl font-black text-purple-600"><?= angka_member($totalPointPakai) ?> pt</p>
                <p class="mt-1 text-[10px] text-purple-500 font-bold uppercase tracking-widest"><?= rupiah_member($totalNilaiPointPakai) ?></p>
            </div>
        </section>

        <section class="bg-white border border-subtle shadow-sm overflow-hidden">
            <div class="p-5 border-b border-subtle">
                <h2 class="text-sm font-black uppercase tracking-[0.2em]">Riwayat Transaksi</h2>
                <!-- <p class="mt-1 text-xs text-gray-400 font-semibold">
                    Diskon dihitung dari <b>harga sebelum diskon - harga setelah diskon</b>. Point memakai <b>transaksi.point_dapat</b>, dan jika transaksi lama masih 0 maka dihitung otomatis dari total / 10.000.
                </p> -->
            </div>

            <div class="desktop-table-wrap overflow-x-auto no-scrollbar">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-subtle">
                        <tr class="text-left text-[10px] uppercase tracking-widest text-gray-400">
                            <th class="px-4 py-3">Tanggal</th>
                            <th class="px-4 py-3">Invoice</th>
                            <th class="px-4 py-3 text-right">Sebelum Diskon</th>
                            <th class="px-4 py-3 text-right">Diskon</th>
                            <th class="px-4 py-3 text-right">Setelah Diskon</th>
                            <th class="px-4 py-3 text-right">Bayar</th>
                            <th class="px-4 py-3 text-right">Kembali</th>
                            <th class="px-4 py-3 text-right">Point</th>
                            <th class="px-4 py-3 text-right">Point Pakai</th>
                            <th class="px-4 py-3 text-center">Struk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!$transaksi): ?>
                            <tr>
                                <td colspan="10" class="px-4 py-10 text-center text-gray-400 text-xs uppercase tracking-widest">
                                    Belum ada riwayat transaksi
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($transaksi as $t): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?= h(tanggal_member($t['tanggal_transaksi'])) ?>
                                </td>
                                <td class="px-4 py-3 font-bold whitespace-nowrap">
                                    <?= h($t['invoice']) ?>
                                </td>
                                <?php
                                $sebelumDiskon   = (int)($t['total_sebelum_diskon'] ?? 0);
                                $setelahDiskon   = (int)($t['total_transaksi']      ?? 0);
                                $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                                $diskonDb        = (int)($t['diskon_transaksi']     ?? 0);
                                $diskonTampil    = max($diskonDb, $diskonDariSelisih);
                                ?>
                                <td class="px-4 py-3 text-right">
                                    <?= rupiah_member($sebelumDiskon) ?>
                                </td>
                                <td class="px-4 py-3 text-right text-red-600 font-bold">
                                    <?= rupiah_member($diskonTampil) ?>
                                </td>
                                <td class="px-4 py-3 text-right font-black">
                                    <?= rupiah_member($setelahDiskon) ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?= rupiah_member($t['bayar_transaksi'] ?? 0) ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <?= rupiah_member($t['kembalian_transaksi'] ?? 0) ?>
                                </td>
                                <?php
                                $pointDb     = (int)($t['point_transaksi'] ?? 0);
                                $totalTrx    = (int)($t['total_transaksi'] ?? 0);
                                $pointTampil = $pointDb > 0 ? $pointDb : (int)floor($totalTrx / 10000);
                                ?>
                                <td class="px-4 py-3 text-right text-blue-600 font-bold">
                                    <?= angka_member($pointTampil) ?> pt
                                </td>
                                <?php $pointPakai = (int)($t['point_pakai'] ?? 0);
                                $nilaiPointPakai = (int)($t['nilai_point_pakai'] ?? 0); ?>
                                <td class="px-4 py-3 text-right text-purple-600 font-bold">
                                    <?= $pointPakai > 0 ? angka_member($pointPakai) . ' pt<br><span class="text-[10px] text-purple-400">-' . rupiah_member($nilaiPointPakai) . '</span>' : '<span class="text-gray-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank"
                                        class="btn inline-block bg-black text-white px-3 py-2">
                                        Lihat
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile & Tablet Card List -->
            <div class="mobile-card-list hidden">
                <?php if (!$transaksi): ?>
                    <div class="py-16 text-center">
                        <div class="inline-flex flex-col items-center gap-3 opacity-30">
                            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z" />
                            </svg>
                            <p class="text-xs font-black uppercase tracking-widest">Belum ada riwayat transaksi</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 md:p-4">
                        <?php foreach ($transaksi as $t): ?>
                            <?php
                            $sebelumDiskon     = (int)($t['total_sebelum_diskon'] ?? 0);
                            $setelahDiskon     = (int)($t['total_transaksi']      ?? 0);
                            $diskonDariSelisih = max(0, $sebelumDiskon - $setelahDiskon);
                            $diskonDb          = (int)($t['diskon_transaksi']     ?? 0);
                            $diskonTampil      = max($diskonDb, $diskonDariSelisih);
                            $pointDb           = (int)($t['point_transaksi'] ?? 0);
                            $totalTrx          = (int)($t['total_transaksi'] ?? 0);
                            $pointTampil       = $pointDb > 0 ? $pointDb : (int)floor($totalTrx / 10000);
                            $pointPakai        = (int)($t['point_pakai'] ?? 0);
                            $nilaiPointPakai   = (int)($t['nilai_point_pakai'] ?? 0);
                            ?>
                            <article class="member-mobile-card bg-white border border-subtle p-4">
                                <div class="flex items-start justify-between gap-3 mb-4">
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                                            <?= h(tanggal_member($t['tanggal_transaksi'])) ?>
                                        </p>
                                        <h3 class="font-black text-sm leading-tight truncate"><?= h($t['invoice']) ?></h3>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-lg font-black text-blue-600"><?= rupiah_member($setelahDiskon) ?></p>
                                        <p class="text-[9px] text-gray-400 font-bold uppercase tracking-widest">setelah diskon</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="border border-subtle bg-gray-50/60 p-3">
                                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-1">Sebelum Diskon</p>
                                        <p class="text-sm font-black text-gray-700"><?= rupiah_member($sebelumDiskon) ?></p>
                                    </div>
                                    <div class="border border-red-100 bg-red-50/60 p-3">
                                        <p class="text-[9px] font-bold text-red-400 uppercase tracking-widest mb-1">Diskon</p>
                                        <p class="text-sm font-black text-red-600"><?= rupiah_member($diskonTampil) ?></p>
                                    </div>
                                </div>

                                <div class="space-y-2 text-xs font-semibold">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-400">Bayar</span>
                                        <span><?= rupiah_member($t['bayar_transaksi'] ?? 0) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-400">Kembali</span>
                                        <span><?= rupiah_member($t['kembalian_transaksi'] ?? 0) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-400">Point Didapat</span>
                                        <span class="text-blue-600 font-black"><?= angka_member($pointTampil) ?> pt</span>
                                    </div>
                                    <?php if ($pointPakai > 0): ?>
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-gray-400">Point Ditukar</span>
                                            <span class="text-purple-600 font-black"><?= angka_member($pointPakai) ?> pt / -<?= rupiah_member($nilaiPointPakai) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 pt-3 border-t border-subtle">
                                    <a href="struk.php?invoice=<?= urlencode($t['invoice']) ?>&member=1" target="_blank"
                                        class="btn w-full inline-flex items-center justify-center bg-black text-white px-4 py-3">
                                        Lihat Struk
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </section>

        <div class="text-center text-xs text-gray-400 font-semibold pb-6">
            Jika angka total belanja profil berbeda, acuan paling akurat adalah total dari riwayat transaksi.
        </div>
    </main>
</body>

</html>