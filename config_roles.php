<?php
/*
|--------------------------------------------------------------------------
| config_roles.php — Role-Based Access Control (RBAC)
|--------------------------------------------------------------------------
| Definisi hak akses per role. Include file ini di config.php atau
| di setiap halaman yang butuh proteksi.
|
| Role yang tersedia:
|   admin   → akses semua menu
|   kasir   → dashboard, pos, produk, diskon, stok_opname, kas_harian, laporan
|   rental  → dashboard, rental_bandara, driver, laporan
|--------------------------------------------------------------------------
*/

// ── Daftar role & halaman yang boleh diakses ─────────────────────────────
define('ROLE_ACCESS', [

    'admin' => [
        // Admin bisa akses semua — '*' berarti wildcard
        'pages'  => ['*'],
        'menus'  => ['*'],
    ],

    'kasir' => [
        'pages' => [
            'dashboard.php',
            'pos.php',
            'produk.php',
            'diskon.php',
            'stok_opname.php',
            'kas_harian.php',
            'anggota.php',
            'laporan.php',

            // Halaman pendukung (ajax/sub-page) yang kasir butuhkan
            'struk.php',
            'buat_po.php',
        ],
        'menus' => [
            'dashboard',
            'pos',
            'produk',
            'diskon',
            'stok',
            'kas_harian',
            'anggota',
            'laporan',
        ],
    ],

    'rental' => [
        'pages' => [
            'dashboard.php',
            'rental_bandara.php',
            'driver.php',
            'laporan.php',
        ],
        'menus' => [
            'dashboard',
            'rental',
            'driver',
            'laporan',
        ],
    ],

    'ksp' => [
        'pages' => [
            'dashboard.php',

            // KSP
            'simpanan.php',
            'pinjaman.php',
            'angsuran_pinjaman.php',
            'anggota.php',

            // Laporan operasional KSP
            'laporan.php',
        ],
        'menus' => [
            'dashboard',

            // KSP
            'simpanan',
            'pinjaman',
            'angsuran_pinjaman',
            'anggota',

            // Laporan operasional KSP
            'laporan',
        ],
    ],

]);

// ── Halaman yang boleh diakses siapa saja (tanpa login sekalipun) ─────────
define('PUBLIC_PAGES', [
    'index.php',
    'login.php',
    'logout.php',
]);


// ══════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Ambil role user yang sedang login dari session.
 *
 * @return string  Role string, mis. 'admin' | 'kasir' | 'rental' | ''
 */
function getCurrentRole()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Support dua struktur session: $_SESSION['role'] atau $_SESSION['user']['role']
    if (isset($_SESSION['role'])) {
        return (string)$_SESSION['role'];
    }
    if (isset($_SESSION['user']['role'])) {
        return (string)$_SESSION['user']['role'];
    }
    return '';
}

/**
 * Cek apakah role tertentu boleh mengakses halaman (file) tertentu.
 *
 * @param  string  $role      Role string
 * @param  string  $page      Nama file, mis. 'dashboard.php'
 * @return bool
 */
function canAccessPage($role, $page)
{
    $roles = defined('ROLE_ACCESS') ? ROLE_ACCESS : [];

    if (!isset($roles[$role])) {
        return false;
    }

    $allowed = $roles[$role]['pages'];

    // Wildcard → admin boleh semua
    if (in_array('*', $allowed, true)) {
        return true;
    }

    return in_array($page, $allowed, true);
}

/**
 * Cek apakah role tertentu boleh melihat menu key tertentu.
 *
 * @param  string  $role     Role string
 * @param  string  $menuKey  Menu key, mis. 'pos'
 * @return bool
 */
function canSeeMenu($role, $menuKey)
{
    $roles = defined('ROLE_ACCESS') ? ROLE_ACCESS : [];

    if (!isset($roles[$role])) {
        return false;
    }

    $allowed = $roles[$role]['menus'];

    if (in_array('*', $allowed, true)) {
        return true;
    }

    return in_array($menuKey, $allowed, true);
}

/**
 * Guard — redirect ke halaman error/login jika tidak punya akses.
 * Panggil di bagian atas setiap halaman protected.
 *
 * @param  string  $page  Nama file halaman saat ini (opsional; auto-detect jika kosong)
 * @return void
 */
function requireAccess($page = '')
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Belum login → ke halaman login
    if (!isset($_SESSION['user']) && !isset($_SESSION['role'])) {
        header('Location: index.php');
        exit;
    }

    $role     = getCurrentRole();
    $page     = $page ?: basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');

    if (!canAccessPage($role, $page)) {
        // Halaman forbidden sederhana — bisa diganti ke forbidden.php
        http_response_code(403);
        $safePage = htmlspecialchars($page, ENT_QUOTES, 'UTF-8');
        $safeRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Akses Ditolak — SEJAHUB</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="text-center p-8 max-w-sm">
        <p class="text-6xl font-black text-gray-200 mb-4">403</p>
        <h1 class="text-lg font-bold mb-2">Akses Ditolak</h1>
        <p class="text-sm text-gray-500 mb-6">
            Role <strong>$safeRole</strong> tidak diizinkan mengakses
            halaman <strong>$safePage</strong>.
        </p>
        <a href="dashboard.php"
           class="inline-block bg-black text-white text-xs font-bold uppercase tracking-widest px-6 py-3 hover:bg-gray-800">
            Kembali ke Dashboard
        </a>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
