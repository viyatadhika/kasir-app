<?php
// Pastikan file ini ada di direktori yang sama
require_once 'config.php';

// Cek session - Jika sudah login, lempar ke dashboard
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = "";

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        // Mencari user yang aktif
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'aktif'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Verifikasi password (menggunakan password_verify untuk keamanan)
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'nama'     => $user['nama'],
                'username' => $user['username'],
                'role'     => $user['role']
            ];

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama']    = $user['nama'];
            $_SESSION['role']    = $user['role'];

            header('Location: dashboard.php');
            exit;
        } else {
            $error = "Username atau password salah";
        }
    } catch (PDOException $e) {
        $error = "Terjadi kesalahan sistem";
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | KOPERASI BSDK</title>

    <!-- Framework & Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Ikon Lucide -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #fcfcfc;
            color: #1a1a1a;
        }

        /* Gaya Input Flat ala POS */
        .input-flat {
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
            border-radius: 2px;
            transition: all 0.2s ease;
        }

        .input-flat:focus {
            outline: none;
            border-color: #000;
            background: #fff;
        }

        /* Gaya Tombol Hitam sesuai Gambar Referensi */
        .btn-black {
            background: #000;
            color: #fff;
            text-transform: uppercase;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.12em;
            border-radius: 2px;
            transition: all 0.2s;
        }

        .btn-black:hover {
            opacity: 0.85;
            transform: translateY(-1px);
        }

        .btn-black:active {
            transform: translateY(0px);
        }

        /* Gaya Logo Brand */
        .brand-logo {
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            text-transform: uppercase;
            border-bottom: 3px solid black;
            display: inline-block;
            padding-bottom: 2px;
            line-height: 1;
        }

        /* Gaya Kotak Pesan Error */
        .error-box {
            border-left: 4px solid #ef4444;
            background: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        /* Menyembunyikan icon bawaan browser (Edge/Chrome) untuk password */
        input::-ms-reveal,
        input::-ms-clear {
            display: none;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col justify-center items-center p-4 sm:p-8">

    <!-- Container Utama -->
    <div class="w-full max-w-[400px]">

        <!-- Bagian Header Logo -->
        <div class="text-center mb-10">
            <div class="brand-logo">KOPERASI BSDK</div>
        </div>

        <!-- Kartu Form Login -->
        <div class="bg-white border border-[#f0f0f0] p-6 sm:p-10 shadow-sm rounded-sm">

            <?php if ($error): ?>
                <div class="error-box mb-8 p-4 flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-500"></i>
                    <span class="text-[11px] font-bold text-red-600 uppercase tracking-tight">
                        <?= htmlspecialchars($error) ?>
                    </span>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="space-y-6">
                <!-- Field Username -->
                <div class="space-y-2">
                    <label class="text-[10px] font-extrabold text-gray-400 uppercase tracking-widest ml-0.5">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i data-lucide="user" class="w-4 h-4 text-gray-300"></i>
                        </div>
                        <input type="text" name="username"
                            class="input-flat w-full pl-11 pr-4 py-3.5 text-sm font-medium placeholder:text-gray-200"
                            placeholder="NAMA PENGGUNA"
                            required autofocus>
                    </div>
                </div>

                <!-- Field Kata Sandi -->
                <div class="space-y-2">
                    <label class="text-[10px] font-extrabold text-gray-400 uppercase tracking-widest ml-0.5">Kata Sandi</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i data-lucide="lock" class="w-4 h-4 text-gray-300"></i>
                        </div>
                        <input type="password" name="password" id="passwordInput"
                            class="input-flat w-full pl-11 pr-12 py-3.5 text-sm font-medium placeholder:text-gray-200"
                            placeholder="••••••••"
                            required>
                        <!-- Tombol Intip Password -->
                        <button type="button" onclick="togglePassword()"
                            class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-300 hover:text-black transition-colors focus:outline-none">
                            <i id="toggleIcon" data-lucide="eye" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <!-- Tombol Submit -->
                <div class="pt-2">
                    <button type="submit"
                        class="btn-black w-full py-4 shadow-sm active:scale-[0.98]">
                        Masuk Sekarang
                    </button>
                </div>
            </form>
        </div>

        <!-- Bagian Footer (Tanpa Versi) -->
        <div class="mt-12 text-center">
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-[0.2em]">
                &copy; <?= date('Y') ?> KOPERASI BSDK
            </p>
        </div>

    </div>

    <script>
        // Menginisialisasi ikon dari library Lucide
        lucide.createIcons();

        function togglePassword() {
            const passwordInput = document.getElementById('passwordInput');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                // Tampilkan Password
                passwordInput.type = 'text';
                // Ganti ikon menjadi eye-off menggunakan API Lucide
                toggleIcon.setAttribute('data-lucide', 'eye-off');
            } else {
                // Sembunyikan Password
                passwordInput.type = 'password';
                // Ganti ikon menjadi eye menggunakan API Lucide
                toggleIcon.setAttribute('data-lucide', 'eye');
            }
            // Refresh icon agar perubahan visual terlihat
            lucide.createIcons();
        }
    </script>
</body>

</html>