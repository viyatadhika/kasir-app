<?php
/*
|--------------------------------------------------------------------------
| sidebar.php — Role-Based Menu Filter, Compatible PHP 7 & 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load RBAC helper jika belum di-include
if (!function_exists('canSeeMenu')) {
    require_once __DIR__ . '/config_roles.php';
}

if (!isset($activeMenu)) {
    $file = basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');

    $map = [
        'dashboard.php'      => 'dashboard',
        'index.php'          => 'dashboard',
        'pos.php'            => 'pos',
        'produk.php'         => 'produk',
        'stok_opname.php'    => 'stok',
        'diskon.php'         => 'diskon',
        'rental_bandara.php' => 'rental',
        'driver.php'         => 'driver',
        'anggota.php'        => 'anggota',

        'simpanan.php'            => 'simpanan',
        'pinjaman.php'            => 'pinjaman',
        'angsuran_pinjaman.php'   => 'angsuran_pinjaman',

        'laporan.php'             => 'laporan',
        'laporan_keuangan.php'    => 'laporan_keuangan',
        'neraca.php'              => 'neraca',
        'laba_rugi.php'           => 'laba_rugi',

        'log_aktivitas.php'       => 'log',
    ];

    $activeMenu = isset($map[$file]) ? $map[$file] : '';
}

// ── Helper ───────────────────────────────────────────────────────────────
if (!function_exists('sidebar_h')) {
    function sidebar_h(string $v): string
    {
        return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
    }
}

$loginNama = isset($_SESSION['nama'])
    ? $_SESSION['nama']
    : (isset($_SESSION['user']['nama']) ? $_SESSION['user']['nama'] : '-');

$currentRole = getCurrentRole();

// ── Semua menu yang mungkin ada ───────────────────────────────────────────
$allMenus = [
    ['key' => 'dashboard', 'href' => 'dashboard.php',      'label' => 'Dashboard'],
    ['key' => 'pos',       'href' => 'pos.php',            'label' => 'Mesin Kasir (POS)'],
    ['key' => 'produk',    'href' => 'produk.php',         'label' => 'Kelola Produk'],
    ['key' => 'stok',      'href' => 'stok_opname.php',    'label' => 'Stok Opname'],
    ['key' => 'diskon',    'href' => 'diskon.php',         'label' => 'Kelola Diskon'],
    ['key' => 'rental',    'href' => 'rental_bandara.php', 'label' => 'Rental Bandara'],
    ['key' => 'driver',    'href' => 'driver.php',         'label' => 'Driver Mitra'],

    ['key' => 'anggota',            'href' => 'anggota.php',              'label' => 'Kelola Anggota'],
    ['key' => 'simpanan',           'href' => 'simpanan.php',             'label' => 'Kelola Simpanan'],
    ['key' => 'pinjaman',            'href' => 'pinjaman.php',             'label' => 'Kelola Pinjaman'],
    ['key' => 'angsuran_pinjaman',   'href' => 'angsuran_pinjaman.php',    'label' => 'Angsuran Pinjaman'],

    ['key' => 'laporan',             'href' => 'laporan.php',              'label' => 'Laporan Operasional'],
    ['key' => 'laporan_keuangan',    'href' => 'laporan_keuangan.php',     'label' => 'Laporan Keuangan'],
    ['key' => 'neraca',              'href' => 'neraca.php',               'label' => 'Neraca'],
    ['key' => 'laba_rugi',           'href' => 'laba_rugi.php',            'label' => 'Laba Rugi / SHU'],

    ['key' => 'log',                 'href' => 'log_aktivitas.php',        'label' => 'Log Aktivitas'],
];

// ── Filter menu berdasarkan role ──────────────────────────────────────────
$menus = array_values(array_filter($allMenus, function ($menu) use ($currentRole) {
    return canSeeMenu($currentRole, $menu['key']);
}));

// ── Label badge role ──────────────────────────────────────────────────────
$roleBadge = [
    'admin'  => ['label' => 'Admin',  'color' => 'bg-black text-white'],
    'kasir'  => ['label' => 'Kasir',  'color' => 'bg-blue-100 text-blue-700'],
    'rental' => ['label' => 'Rental', 'color' => 'bg-purple-100 text-purple-700'],
    'ksp' => ['label' => 'KSP', 'color' => 'bg-green-100 text-green-700'],
];
$badge = isset($roleBadge[$currentRole])
    ? $roleBadge[$currentRole]
    : ['label' => strtoupper($currentRole), 'color' => 'bg-gray-100 text-gray-600'];
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

    .side-link {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .12em;
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
        font-weight: 800;
    }

    .side-link.active::before {
        content: "";
        width: 7px;
        height: 7px;
        background: #111;
        display: inline-block;
        flex-shrink: 0;
    }

    .mobile-drawer-link {
        display: block;
        padding: 14px 0;
        border-bottom: 1px solid #f3f3f3;
        color: #9ca3af;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
    }

    .mobile-drawer-link.active {
        color: #111;
        font-weight: 900;
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
        .laporan-main-wrap {
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
        .laporan-main-wrap {
            margin-left: 0 !important;
        }
    }

    @media (max-width: 640px) {
        #mobileMenuContent {
            width: min(82vw, 280px) !important;
        }

        .mobile-drawer-link {
            font-size: 11px;
            padding: 13px 0;
        }
    }
</style>

<!-- ── Mobile Drawer Overlay ─────────────────────────────────────────────── -->
<div id="mobileMenuOverlay"
    class="fixed inset-0 bg-black/40 z-[100] opacity-0 invisible lg:hidden">

    <div id="mobileMenuContent"
        class="ml-auto w-[280px] max-w-[82vw] bg-white h-full translate-x-full shadow-2xl flex flex-col">

        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold tracking-widest uppercase text-gray-400">Navigasi</p>
                <p class="text-sm font-bold tracking-tight mt-1">SEJAHUB</p>
            </div>
            <button type="button"
                onclick="toggleMobileMenu()"
                class="w-9 h-9 border border-gray-100 bg-white hover:bg-gray-50 text-sm font-bold"
                aria-label="Tutup menu">
                &times;
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-6 py-4 no-scrollbar">
            <?php foreach ($menus as $menu): ?>
                <a href="<?php echo sidebar_h($menu['href']); ?>"
                    class="mobile-drawer-link <?php echo $activeMenu === $menu['key'] ? 'active' : ''; ?>">
                    <?php echo sidebar_h($menu['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="px-6 py-5 border-t border-gray-100">
            <p class="text-[10px] text-gray-400 font-medium uppercase">Login sebagai</p>
            <p class="text-xs font-bold text-gray-700 mt-1 truncate">
                <?php echo sidebar_h($loginNama); ?>
            </p>
            <!-- Badge role -->
            <span class="inline-block mt-2 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest rounded-sm <?php echo sidebar_h($badge['color']); ?>">
                <?php echo sidebar_h($badge['label']); ?>
            </span>
            <a href="logout.php"
                onclick="return confirm('Yakin mau logout?')"
                class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
                Logout
            </a>
        </div>

    </div>
</div>

<!-- ── Desktop Sidebar ───────────────────────────────────────────────────── -->
<aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-gray-100 bg-white p-8 z-30">

    <div class="mb-12">
        <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">
            SEJAHUB
        </span>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto no-scrollbar">
        <?php foreach ($menus as $menu): ?>
            <a href="<?php echo sidebar_h($menu['href']); ?>"
                class="side-link <?php echo $activeMenu === $menu['key'] ? 'active' : ''; ?>">
                <?php echo sidebar_h($menu['label']); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mt-auto pt-6 border-t border-gray-100">
        <p class="text-[10px] text-gray-400 font-medium truncate mt-1">
            <?php echo sidebar_h($loginNama); ?>
        </p>
        <!-- Badge role -->
        <span class="inline-block mt-1 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest rounded-sm <?php echo sidebar_h($badge['color']); ?>">
            <?php echo sidebar_h($badge['label']); ?>
        </span>
        <a href="logout.php"
            onclick="return confirm('Yakin mau logout?')"
            class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
            Logout
        </a>
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