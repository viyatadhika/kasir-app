<?php
session_start();
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| member_login.php
|--------------------------------------------------------------------------
| Login member menggunakan nomor HP atau kode member.
| Untuk tahap awal tidak memakai password/PIN.
*/

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $loginClean = preg_replace('/[^0-9]/', '', $login);

    if ($login === '') {
        $error = 'Masukkan nomor HP atau kode member.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, kode, nama, no_hp, point, total_belanja, status
                FROM member
                WHERE (kode = :kode OR no_hp = :no_hp)
                  AND status = 'aktif'
                LIMIT 1
            ");
            $stmt->execute([
                ':kode' => $login,
                ':no_hp' => $loginClean ?: $login,
            ]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($member) {
                $_SESSION['member_id'] = (int)$member['id'];
                $_SESSION['member_nama'] = $member['nama'];
                $_SESSION['member_kode'] = $member['kode'];

                header('Location: member_dashboard.php');
                exit;
            }

            $error = 'Member tidak ditemukan atau tidak aktif.';
        } catch (Throwable $e) {
            $error = 'Gagal login: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Login Member - Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background:#f8fafc; color:#111827; }
        .border-subtle { border-color:#e5e7eb; }
        button, a.btn { border-radius:2px!important; font-size:11px!important; font-weight:800!important; text-transform:uppercase!important; letter-spacing:.08em!important; }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white border border-subtle shadow-sm p-8">
        <div class="text-center mb-8">
            <div class="text-sm font-black tracking-tighter border-b-2 border-black inline-block pb-1">KOPERASI BSDK</div>
            <h1 class="mt-6 text-xl font-black uppercase tracking-[0.2em]">Login Member</h1>
            <p class="mt-2 text-xs text-gray-400 font-semibold">Lihat point, riwayat belanja, dan struk transaksi.</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-5 bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-xs font-bold">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">
                    Nomor HP / Kode Member
                </label>
                <input type="text" name="login" value="<?= h($_POST['login'] ?? '') ?>" required
                    class="w-full border border-gray-200 px-4 py-3 text-sm focus:outline-none focus:border-black"
                    placeholder="Contoh: 08111000001 / MBR001">
            </div>

            <button type="submit" class="w-full bg-black text-white py-4">
                Masuk
            </button>
        </form>

        <div class="mt-6 text-center">
            <p class="text-xs text-gray-400 mb-3">Belum punya akun member?</p>
            <a href="member_daftar.php" class="btn inline-block border border-subtle px-5 py-3 hover:bg-gray-50">
                Daftar Member
            </a>
        </div>
    </div>
</body>

</html>
