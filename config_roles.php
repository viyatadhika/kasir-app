<?php
/*
|--------------------------------------------------------------------------
| config_roles.php — Role-Based Access Control (RBAC)
|--------------------------------------------------------------------------
| Definisi hak akses per role.
| Compatible PHP 7 & 8.
|--------------------------------------------------------------------------
*/

define('ROLE_ACCESS', [

    'admin' => [
        'pages' => ['*'],
        'menus' => ['*'],
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

    'cafe' => [
        'pages' => [
            'dashboard.php',
            'pos_cafe.php',
            'menu_cafe.php',
            'meja_cafe.php',
            'dapur.php',
            'kas_harian.php',
            'laporan.php',
            'struk.php',
        ],
        'menus' => [
            'dashboard',
            'pos_cafe',
            'menu_cafe',
            'meja_cafe',
            'dapur',
            'kas_harian',
            'laporan',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Air Mineral
    |--------------------------------------------------------------------------
    | Digunakan petugas internal untuk melihat dan memproses pesanan yang
    | masuk dari halaman publik pesan_air.php serta rekap ke vendor.
    */
    'air_mineral' => [
        'pages' => [
            'dashboard.php',
            'air_pesanan.php',
            'air_pesanan_import.php',
            'air_rekap_vendor.php',
            'air_tagihan_vendor.php',
            'air_kwitansi.php',
            'air_produk.php',
            'laporan.php',
        ],
        'menus' => [
            'dashboard',
            'air_pesanan',
            'air_rekap_vendor',
            'air_tagihan_vendor',
            'air_kwitansi',
            'air_produk',
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
            'simpanan.php',
            'pinjaman.php',
            'angsuran_pinjaman.php',
            'anggota.php',
            'laporan.php',
        ],
        'menus' => [
            'dashboard',
            'simpanan',
            'pinjaman',
            'angsuran_pinjaman',
            'anggota',
            'laporan',
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Halaman Publik
|--------------------------------------------------------------------------
| pesan_air.php dapat dibuka pelanggan tanpa login.
*/
define('PUBLIC_PAGES', [
    'index.php',
    'login.php',
    'logout.php',
    'pesan_air.php',
    'lacak_air.php',
]);

if (!function_exists('normalizeRoleName')) {
    /**
     * @param mixed $role
     * @return string
     */
    function normalizeRoleName($role)
    {
        $role = strtolower(trim((string)$role));
        $role = str_replace(['-', '_'], ' ', $role);
        $role = preg_replace('/\s+/', ' ', $role);

        $map = [
            'administrator'          => 'admin',
            'super admin'            => 'admin',
            'superadmin'             => 'admin',
            'owner'                  => 'admin',

            'staff kasir'            => 'kasir',
            'kasir toko'             => 'kasir',
            'kasir utama'            => 'kasir',

            'kasir cafe'             => 'cafe',
            'cafe cashier'           => 'cafe',
            'staff cafe'             => 'cafe',

            'air mineral'            => 'air_mineral',
            'petugas air'            => 'air_mineral',
            'petugas air mineral'    => 'air_mineral',
            'staff air'              => 'air_mineral',
            'staff air mineral'      => 'air_mineral',
            'operator air'           => 'air_mineral',
            'operator air mineral'   => 'air_mineral',
            'pemesanan air'          => 'air_mineral',
            'pemesanan air mineral'  => 'air_mineral',
            'admin air'              => 'air_mineral',
            'admin air mineral'      => 'air_mineral',

            'staff rental'           => 'rental',

            'simpan pinjam'          => 'ksp',
            'staff simpan pinjam'    => 'ksp',
        ];

        return isset($map[$role]) ? $map[$role] : str_replace(' ', '_', $role);
    }
}

if (!function_exists('getCurrentRole')) {
    /**
     * @return string
     */
    function getCurrentRole()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['role'])) {
            return normalizeRoleName($_SESSION['role']);
        }

        if (isset($_SESSION['user']['role'])) {
            return normalizeRoleName($_SESSION['user']['role']);
        }

        return '';
    }
}

if (!function_exists('canAccessPage')) {
    /**
     * @param mixed $role
     * @param mixed $page
     * @return bool
     */
    function canAccessPage($role, $page)
    {
        $roles = defined('ROLE_ACCESS') ? ROLE_ACCESS : [];
        $role = normalizeRoleName($role);
        $page = basename((string)$page);

        if (!isset($roles[$role])) {
            return false;
        }

        $allowed = isset($roles[$role]['pages']) && is_array($roles[$role]['pages'])
            ? $roles[$role]['pages']
            : [];

        if (in_array('*', $allowed, true)) {
            return true;
        }

        return in_array($page, $allowed, true);
    }
}

if (!function_exists('canSeeMenu')) {
    /**
     * @param mixed $role
     * @param mixed $menuKey
     * @return bool
     */
    function canSeeMenu($role, $menuKey)
    {
        $roles = defined('ROLE_ACCESS') ? ROLE_ACCESS : [];
        $role = normalizeRoleName($role);
        $menuKey = trim((string)$menuKey);

        if (!isset($roles[$role])) {
            return false;
        }

        $allowed = isset($roles[$role]['menus']) && is_array($roles[$role]['menus'])
            ? $roles[$role]['menus']
            : [];

        if (in_array('*', $allowed, true)) {
            return true;
        }

        return in_array($menuKey, $allowed, true);
    }
}

if (!function_exists('requireAccess')) {
    /**
     * @param mixed $page
     * @return void
     */
    function requireAccess($page = '')
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $currentPage = $page !== ''
            ? basename((string)$page)
            : basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');

        if (defined('PUBLIC_PAGES') && in_array($currentPage, PUBLIC_PAGES, true)) {
            return;
        }

        if (!isset($_SESSION['user']) && !isset($_SESSION['role'])) {
            header('Location: index.php');
            exit;
        }

        $role = getCurrentRole();

        if (!canAccessPage($role, $currentPage)) {
            http_response_code(403);

            $safePage = htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8');
            $safeRole = htmlspecialchars($role !== '' ? $role : '-', ENT_QUOTES, 'UTF-8');

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
            Role <strong>{$safeRole}</strong> tidak diizinkan mengakses
            halaman <strong>{$safePage}</strong>.
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
}
