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

/*
 * Audit safe:
 * Cek seluruh sesi kas yang masih terbuka milik user tanpa membatasi tanggal.
 * Jadi kas kemarin atau beberapa hari lalu tetap wajib ditutup sebelum logout.
 */
if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, tanggal, opened_at
            FROM kas_harian
            WHERE user_id = :user_id
              AND status = 'buka'
            ORDER BY opened_at ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId
        ]);

        $kasTerbuka = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($kasTerbuka) {
            $tanggalKas = !empty($kasTerbuka['tanggal'])
                ? date('d/m/Y', strtotime($kasTerbuka['tanggal']))
                : '-';

            $_SESSION['kas_warning'] =
                'Masih ada sesi kas terbuka sejak ' . $tanggalKas .
                '. Silakan tutup kas terlebih dahulu sebelum logout.';

            header('Location: kas_harian.php');
            exit;
        }
    } catch (Throwable $e) {
        error_log('VALIDASI LOGOUT KAS ERROR: ' . $e->getMessage());
        $_SESSION['kas_warning'] = 'Tidak bisa memvalidasi status kas. Silakan coba lagi.';
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
