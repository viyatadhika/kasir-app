<?php
session_start();

require_once 'config.php';
require_once 'activity_helper.php';

$userId = 0;

foreach (['user_id', 'id_user', 'id', 'admin_id'] as $k) {
    if (!empty($_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}

if (!$userId && !empty($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];
}

$today = date('Y-m-d');

/*
 * Cek apakah user ini masih punya kas terbuka hari ini.
 * Kalau masih buka, logout dibatalkan.
 */
if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM kas_harian
            WHERE tanggal = :tanggal
              AND user_id = :user_id
              AND status = 'buka'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':tanggal' => $today,
            ':user_id' => $userId
        ]);

        $kasTerbuka = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($kasTerbuka) {
            $_SESSION['kas_warning'] = 'Kas hari ini masih terbuka. Silakan tutup kas terlebih dahulu sebelum logout.';
            header('Location: kas_harian.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['kas_warning'] = 'Tidak bisa validasi status kas. Silakan coba lagi.';
        header('Location: kas_harian.php');
        exit;
    }
}

catat_aktivitas(
    $pdo,
    'logout',
    'Logout',
    'User keluar dari aplikasi'
);

session_unset();
session_destroy();

header('Location: index.php');
exit;
