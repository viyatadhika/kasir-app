<?php

require 'config.php';

// Kalau sudah login, langsung ke kasir
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = "";

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'aktif'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'nama' => $user['nama'],
            'username' => $user['username'],
            'role' => $user['role']
        ];

        header('Location: dashboard.php');
        exit;
    } else {
        $error = "Username atau password salah";
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Kasir</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff;
        }

        /* style konsisten */
        .border-subtle {
            border-color: #f0f0f0;
        }

        .btn {
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: #000;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1f1f1f;
        }

        /* input */
        .input {
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
        }

        .input:focus {
            outline: none;
            border-color: #000;
            background: #fff;
        }
    </style>

</head>

<body class="min-h-screen flex items-center justify-center px-4">

    <!-- CARD -->
    <div class="w-full max-w-sm border border-subtle p-8">

        <div class="mb-8">
            <h1 class="text-lg font-bold uppercase tracking-widest">
                Login Kasir
            </h1>
            <p class="text-xs text-gray-400 mt-1">
                Masuk untuk melanjutkan
            </p>
        </div>

        <?php if ($error): ?>
            <div class="mb-4 text-xs font-bold text-red-500 uppercase">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">

            <input type="text" name="username"
                placeholder="Username"
                class="input w-full px-4 py-3 text-sm"
                required autofocus>

            <input type="password" name="password"
                placeholder="Password"
                class="input w-full px-4 py-3 text-sm"
                required>

            <button type="submit"
                class="btn btn-primary w-full py-3 mt-2">
                Login
            </button>

        </form>
    </div>

</body>

</html>