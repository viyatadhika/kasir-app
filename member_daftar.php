<?php
session_start();
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| member_daftar.php
|--------------------------------------------------------------------------
| Halaman daftar member pembeli.
| Field tabel member yang dipakai:
| id, kode, nama, no_hp, point, total_belanja, status, created_at, updated_at
*/

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function buatKodeMember(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT kode FROM member WHERE kode LIKE 'MBR%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/MBR(\d+)/', $last, $m)) {
        $next = ((int)$m[1]) + 1;
    } else {
        $next = 1;
    }

    return 'MBR' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $noHp = trim($_POST['no_hp'] ?? '');

    $noHp = preg_replace('/[^0-9]/', '', $noHp);

    if ($nama === '') {
        $error = 'Nama wajib diisi.';
    } elseif ($noHp === '') {
        $error = 'Nomor HP wajib diisi.';
    } elseif (strlen($noHp) < 9) {
        $error = 'Nomor HP tidak valid.';
    } else {
        try {
            $cek = $pdo->prepare("SELECT id, kode FROM member WHERE no_hp = :no_hp LIMIT 1");
            $cek->execute([':no_hp' => $noHp]);
            $existing = $cek->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $error = 'Nomor HP sudah terdaftar. Kode member Anda: ' . $existing['kode'];
            } else {
                $kode = buatKodeMember($pdo);

                $stmt = $pdo->prepare("
                    INSERT INTO member (kode, nama, no_hp, point, total_belanja, status, created_at, updated_at)
                    VALUES (:kode, :nama, :no_hp, 0, 0, 'aktif', NOW(), NOW())
                ");
                $stmt->execute([
                    ':kode' => $kode,
                    ':nama' => $nama,
                    ':no_hp' => $noHp,
                ]);

                $_SESSION['member_id'] = (int)$pdo->lastInsertId();
                $_SESSION['member_nama'] = $nama;
                $_SESSION['member_kode'] = $kode;

                header('Location: member_dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Gagal daftar member: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Daftar Member - Sejahub</title>
    <link rel="icon" href="assets/sejahub_icon.png" sizes="192x192">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f5f5f5;
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

        input:focus {
            outline: none;
            border-color: #000 !important;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, .04);
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md bg-white border border-subtle shadow-sm p-10">

        <div class="text-center mb-10">
            <img
                src="assets/sejahub_icon.png"
                alt="SEJAHUB"
                class="h-28 w-auto mx-auto object-contain select-none mb-6"
                draggable="false">

            <h1 class="text-xl font-black uppercase tracking-[0.2em]">
                Daftar Member
            </h1>

            <p class="mt-3 text-xs text-gray-400 font-semibold leading-relaxed">
                Daftar untuk melihat point,<br>
                dan riwayat transaksi member.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="mb-5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">
                    Nama Lengkap
                </label>
                <input
                    type="text"
                    name="nama"
                    value="<?= h($_POST['nama'] ?? '') ?>"
                    required
                    class="w-full border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:border-black"
                    placeholder="Contoh: Andi Wijaya">
            </div>

            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">
                    Nomor HP
                </label>
                <input
                    type="text"
                    name="no_hp"
                    value="<?= h($_POST['no_hp'] ?? '') ?>"
                    required
                    class="w-full border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:border-black"
                    placeholder="Contoh: 081234567890">
            </div>

            <button type="submit" class="w-full bg-black text-white py-4">
                Daftar Sekarang
            </button>
        </form>

        <div class="mt-6 text-center">
            <p class="text-xs text-gray-400 mb-3">Sudah punya akun member?</p>
            <a href="member_login.php" class="btn inline-block border border-subtle px-5 py-3 hover:bg-gray-50">
                Login Member
            </a>
        </div>

    </div>

</body>

</html>