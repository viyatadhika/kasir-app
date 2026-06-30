<?php
/*
|--------------------------------------------------------------------------
| sidebar.php - Grouped Role-Based Menu Filter, Compatible PHP 7 & 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('canSeeMenu')) {
    require_once __DIR__ . '/config_roles.php';
}

if (!isset($activeMenu)) {
    $file = basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');

    $map = array(
        'dashboard.php'            => 'dashboard',
        'index.php'                => 'dashboard',
        'pos.php'                  => 'pos',
        'produk.php'               => 'produk',
        'stok_opname.php'          => 'stok',
        'kas_harian.php'           => 'kas_harian',
        'diskon.php'               => 'diskon',
        'rental_bandara.php'       => 'rental',
        'driver.php'               => 'driver',
        'anggota.php'              => 'anggota',
        'simpanan.php'             => 'simpanan',
        'penarikan_simpanan.php'   => 'penarikan_simpanan',
        'pinjaman.php'             => 'pinjaman',
        'angsuran_pinjaman.php'    => 'angsuran_pinjaman',
        'laporan.php'              => 'laporan',
        'laporan_keuangan.php'     => 'laporan_keuangan',
        'neraca.php'               => 'neraca',
        'laba_rugi.php'            => 'laba_rugi',
        'log_aktivitas.php'        => 'log',
    );

    $activeMenu = isset($map[$file]) ? $map[$file] : '';
}

if (!function_exists('sidebar_h')) {
    /**
     * @param mixed $v
     * @return string
     */
    function sidebar_h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sidebar_can_show')) {
    /**
     * @param string $role
     * @param array<string,string> $menu
     * @return bool
     */
    function sidebar_can_show($role, array $menu)
    {
        if (!isset($menu['key'])) {
            return false;
        }

        $roleRaw = strtolower(trim((string)$role));
        $roleRaw = str_replace(array('_', '-'), ' ', $roleRaw);
        $roleRaw = preg_replace('/\s+/', ' ', $roleRaw);

        if (function_exists('normalizeRoleName')) {
            $role = normalizeRoleName($role);
        }

        // Fallback khusus supaya menu kas tetap muncul untuk akun Kasir,
        // baik session berisi "kasir", "Kasir Utama", "staff kasir", atau variasinya.
        if ((string)$menu['key'] === 'kas_harian') {
            if (in_array((string)$role, array('admin', 'kasir'), true)) {
                return true;
            }
            if (strpos($roleRaw, 'kasir') !== false) {
                return true;
            }
        }

        return canSeeMenu((string)$role, (string)$menu['key']);
    }
}

if (!function_exists('sidebar_filter_groups')) {
    /**
     * @param array<int,array{title:string,items:array<int,array<string,string>>}> $groups
     * @param string $role
     * @return array<int,array{title:string,items:array<int,array<string,string>>}>
     */
    function sidebar_filter_groups(array $groups, $role)
    {
        $filtered = array();

        foreach ($groups as $group) {
            $items = array();
            if (!isset($group['items']) || !is_array($group['items'])) {
                continue;
            }

            foreach ($group['items'] as $menu) {
                if (sidebar_can_show((string)$role, $menu)) {
                    $items[] = $menu;
                }
            }

            if ($items) {
                $filtered[] = array(
                    'title' => (string)($group['title'] ?? ''),
                    'items' => $items,
                );
            }
        }

        return $filtered;
    }
}

$loginNama = isset($_SESSION['nama'])
    ? $_SESSION['nama']
    : (isset($_SESSION['user']['nama']) ? $_SESSION['user']['nama'] : '-');

$currentRole = function_exists('getCurrentRole') ? getCurrentRole() : '';
if (function_exists('normalizeRoleName')) {
    $currentRole = normalizeRoleName($currentRole);
}

$menuGroups = array(
    array(
        'title' => 'Utama',
        'items' => array(
            array('key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'),
        ),
    ),
    array(
        'title' => 'Operasional POS',
        'items' => array(
            array('key' => 'pos',    'href' => 'pos.php',         'label' => 'Mesin Kasir'),
            array('key' => 'produk', 'href' => 'produk.php',      'label' => 'Produk'),
            array('key' => 'stok',       'href' => 'stok_opname.php', 'label' => 'Stok Opname'),
            array('key' => 'diskon',     'href' => 'diskon.php',      'label' => 'Diskon'),
            array('key' => 'kas_harian', 'href' => 'kas_harian.php',  'label' => 'Buka & Tutup Kas'),
        ),
    ),
    array(
        'title' => 'Koperasi',
        'items' => array(
            array('key' => 'anggota',             'href' => 'anggota.php',             'label' => 'Anggota'),
            array('key' => 'simpanan',            'href' => 'simpanan.php',            'label' => 'Simpanan'),
            array('key' => 'penarikan_simpanan',  'href' => 'penarikan_simpanan.php',  'label' => 'Penarikan Simpanan'),
            array('key' => 'pinjaman',            'href' => 'pinjaman.php',            'label' => 'Pinjaman'),
            array('key' => 'angsuran_pinjaman',   'href' => 'angsuran_pinjaman.php',   'label' => 'Angsuran Pinjaman'),
        ),
    ),
    array(
        'title' => 'Rental',
        'items' => array(
            array('key' => 'rental', 'href' => 'rental_bandara.php', 'label' => 'Rental Bandara'),
            array('key' => 'driver', 'href' => 'driver.php',         'label' => 'Driver Mitra'),
        ),
    ),
    array(
        'title' => 'Laporan',
        'items' => array(
            array('key' => 'laporan',          'href' => 'laporan.php',           'label' => 'Operasional'),
            array('key' => 'laporan_keuangan', 'href' => 'laporan_keuangan.php',  'label' => 'Keuangan'),
            array('key' => 'neraca',           'href' => 'neraca.php',            'label' => 'Neraca'),
            array('key' => 'laba_rugi',        'href' => 'laba_rugi.php',         'label' => 'Laba Rugi / SHU'),
        ),
    ),
    array(
        'title' => 'Sistem',
        'items' => array(
            array('key' => 'log', 'href' => 'log_aktivitas.php', 'label' => 'Log Aktivitas'),
        ),
    ),
);

$visibleGroups = sidebar_filter_groups($menuGroups, (string)$currentRole);

$roleBadge = array(
    'admin'  => array('label' => 'Admin',  'color' => 'bg-black text-white'),
    'kasir'  => array('label' => 'Kasir',  'color' => 'bg-blue-100 text-blue-700'),
    'rental' => array('label' => 'Rental', 'color' => 'bg-purple-100 text-purple-700'),
    'ksp'    => array('label' => 'KSP',    'color' => 'bg-green-100 text-green-700'),
);

$badge = isset($roleBadge[$currentRole])
    ? $roleBadge[$currentRole]
    : array('label' => strtoupper((string)$currentRole), 'color' => 'bg-gray-100 text-gray-600');
?>

<style>
    :root {
        --sidebar-w: 220px;
    }

    #mobileMenuOverlay {
        transition: opacity .25s ease, visibility .25s ease;
    }

    #mobileMenuContent {
        transition: transform .28s cubic-bezier(.4, 0, .2, 1);
    }

    .sidebar {
        width: var(--sidebar-w);
    }

    .side-group {
        margin-bottom: 24px;
    }

    .side-group-title {
        margin-bottom: 10px;
        font-size: 9px;
        font-weight: 900;
        color: #d1d5db;
        text-transform: uppercase;
        letter-spacing: .18em;
    }

    .side-link {
        display: block;
        padding: 5px 0;
        font-size: 11px;
        font-weight: 650;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .11em;
        transition: color .15s ease;
    }

    .side-link:hover {
        color: #111;
    }

    .side-link.active {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #111;
        font-weight: 900;
    }

    .side-link.active::before {
        content: "";
        width: 7px;
        height: 7px;
        background: #111;
        display: inline-block;
        flex-shrink: 0;
    }

    .mobile-group {
        padding: 6px 0 12px;
        border-bottom: 1px solid #f3f3f3;
    }

    .mobile-group-title {
        padding: 10px 0 3px;
        font-size: 9px;
        font-weight: 900;
        color: #d1d5db;
        text-transform: uppercase;
        letter-spacing: .18em;
    }

    .mobile-drawer-link {
        display: block;
        padding: 10px 0;
        color: #9ca3af;
        font-size: 12px;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .11em;
    }

    .mobile-drawer-link.active {
        color: #111;
        font-weight: 950;
    }

    .no-scrollbar::-webkit-scrollbar {
        display: none;
    }

    .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    @media (min-width: 1024px) {

        .app-header,
        .page-header,
        .main-wrap,
        .content,
        .produk-header,
        .produk-main,
        .diskon-header,
        .diskon-main,
        .stok-header,
        .stok-main-wrap,
        .laporan-header,
        .laporan-main-wrap,
        .penarikan-simpanan-main,
        .penarikan-main {
            margin-left: var(--sidebar-w);
        }
    }

    @media (max-width: 1023px) {
        body {
            padding-bottom: 0;
        }

        .app-header,
        .page-header,
        .main-wrap,
        .content,
        .produk-header,
        .produk-main,
        .diskon-header,
        .diskon-main,
        .stok-header,
        .stok-main-wrap,
        .laporan-header,
        .laporan-main-wrap,
        .penarikan-simpanan-main,
        .penarikan-main {
            margin-left: 0 !important;
        }
    }

    @media (max-width: 640px) {
        #mobileMenuContent {
            width: min(82vw, 280px) !important;
        }

        .mobile-drawer-link {
            font-size: 11px;
            padding: 9px 0;
        }
    }
</style>

<div id="mobileMenuOverlay" class="fixed inset-0 bg-black/40 z-[100] opacity-0 invisible lg:hidden">
    <div id="mobileMenuContent" class="ml-auto w-[280px] max-w-[82vw] bg-white h-full translate-x-full shadow-2xl flex flex-col">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold tracking-widest uppercase text-gray-400">Navigasi</p>
                <p class="text-sm font-bold tracking-tight mt-1">SEJAHUB</p>
            </div>
            <button type="button" onclick="toggleMobileMenu()" class="w-9 h-9 border border-gray-100 bg-white hover:bg-gray-50 text-sm font-bold" aria-label="Tutup menu">&times;</button>
        </div>

        <nav class="flex-1 overflow-y-auto px-6 py-4 no-scrollbar">
            <?php foreach ($visibleGroups as $group): ?>
                <div class="mobile-group">
                    <p class="mobile-group-title"><?php echo sidebar_h($group['title']); ?></p>
                    <?php foreach ($group['items'] as $menu): ?>
                        <a href="<?php echo sidebar_h($menu['href']); ?>" class="mobile-drawer-link <?php echo $activeMenu === $menu['key'] ? 'active' : ''; ?>">
                            <?php echo sidebar_h($menu['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="px-6 py-5 border-t border-gray-100">
            <p class="text-[10px] text-gray-400 font-medium uppercase">Login sebagai</p>
            <p class="text-xs font-bold text-gray-700 mt-1 truncate"><?php echo sidebar_h($loginNama); ?></p>
            <span class="inline-block mt-2 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest rounded-sm <?php echo sidebar_h($badge['color']); ?>">
                <?php echo sidebar_h($badge['label']); ?>
            </span>
            <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
        </div>
    </div>
</div>

<aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-gray-100 bg-white p-8 z-30">
    <div class="mb-10">
        <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">SEJAHUB</span>
    </div>

    <nav class="flex-1 overflow-y-auto no-scrollbar pr-1">
        <?php foreach ($visibleGroups as $group): ?>
            <div class="side-group">
                <p class="side-group-title"><?php echo sidebar_h($group['title']); ?></p>
                <div class="space-y-1">
                    <?php foreach ($group['items'] as $menu): ?>
                        <a href="<?php echo sidebar_h($menu['href']); ?>" class="side-link <?php echo $activeMenu === $menu['key'] ? 'active' : ''; ?>">
                            <?php echo sidebar_h($menu['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="mt-auto pt-6 border-t border-gray-100">
        <p class="text-[10px] text-gray-400 font-medium truncate mt-1"><?php echo sidebar_h($loginNama); ?></p>
        <span class="inline-block mt-1 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest rounded-sm <?php echo sidebar_h($badge['color']); ?>">
            <?php echo sidebar_h($badge['label']); ?>
        </span>
        <a href="logout.php" onclick="return confirm('Yakin mau logout?')" class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">Logout</a>
    </div>
</aside>

<script>
    window.toggleMobileMenu = function() {
        var overlay = document.getElementById('mobileMenuOverlay');
        var content = document.getElementById('mobileMenuContent');
        if (!overlay || !content) return;

        if (overlay.classList.contains('invisible')) {
            overlay.classList.remove('invisible');
            overlay.classList.add('opacity-100');
            content.classList.remove('translate-x-full');
            document.body.style.overflow = 'hidden';
        } else {
            overlay.classList.add('invisible');
            overlay.classList.remove('opacity-100');
            content.classList.add('translate-x-full');
            document.body.style.overflow = '';
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        var overlay = document.getElementById('mobileMenuOverlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) window.toggleMobileMenu();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var ov = document.getElementById('mobileMenuOverlay');
                if (ov && !ov.classList.contains('invisible')) window.toggleMobileMenu();
            }
        });
    });
</script>