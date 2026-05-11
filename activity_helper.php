<?php
/*
|--------------------------------------------------------------------------
| activity_helper.php
|--------------------------------------------------------------------------
| Helper pencatatan aktivitas aplikasi.
|
| Cara pakai:
| require_once 'activity_helper.php';
| catat_aktivitas($pdo, 'update', 'Produk', 'Mengubah produk ABC');
*/

if (!function_exists('ensure_log_aktivitas_table')) {
    function ensure_log_aktivitas_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS log_aktivitas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                nama_user VARCHAR(150) DEFAULT NULL,
                role_user VARCHAR(50) DEFAULT NULL,
                aksi VARCHAR(80) NOT NULL,
                modul VARCHAR(120) DEFAULT NULL,
                keterangan TEXT DEFAULT NULL,
                ip_address VARCHAR(80) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY user_id (user_id),
                KEY aksi (aksi),
                KEY modul (modul),
                KEY role_user (role_user),
                KEY created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('catat_aktivitas')) {
    /**
     * @param mixed $aksi
     * @param mixed $modul
     * @param mixed $keterangan
     */
    function catat_aktivitas(PDO $pdo, $aksi, $modul = null, $keterangan = null): void
    {
        try {
            ensure_log_aktivitas_table($pdo);

            $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['member_id'] ?? null));
            $namaUser = $_SESSION['nama']
                ?? ($_SESSION['user']['nama']
                    ?? ($_SESSION['member_nama']
                        ?? ($_SESSION['username'] ?? 'User')));

            $roleUser = $_SESSION['role']
                ?? ($_SESSION['user']['role']
                    ?? (isset($_SESSION['member_id']) ? 'member' : 'admin'));

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO log_aktivitas
                (
                    user_id,
                    nama_user,
                    role_user,
                    aksi,
                    modul,
                    keterangan,
                    ip_address,
                    user_agent,
                    created_at
                )
                VALUES
                (
                    :user_id,
                    :nama_user,
                    :role_user,
                    :aksi,
                    :modul,
                    :keterangan,
                    :ip_address,
                    :user_agent,
                    NOW()
                )
            ");

            $stmt->execute([
                ':user_id' => $userId ? (int)$userId : null,
                ':nama_user' => $namaUser,
                ':role_user' => $roleUser,
                ':aksi' => strtolower(trim((string)$aksi)),
                ':modul' => $modul !== null ? trim((string)$modul) : null,
                ':keterangan' => $keterangan !== null ? trim((string)$keterangan) : null,
                ':ip_address' => $ip,
                ':user_agent' => $agent,
            ]);
        } catch (Throwable $e) {
            // Jangan hentikan aplikasi hanya karena gagal mencatat log.
        }
    }
}

if (!function_exists('catat_view_once')) {
    /**
     * Catat buka halaman hanya sekali per request.
     *
     * @param mixed $modul
     * @param mixed $keterangan
     */
    function catat_view_once(PDO $pdo, $modul, $keterangan = null): void
    {
        static $done = [];

        $key = strtolower((string)$modul);

        if (isset($done[$key])) {
            return;
        }

        $done[$key] = true;
        catat_aktivitas($pdo, 'view', $modul, $keterangan);
    }
}
