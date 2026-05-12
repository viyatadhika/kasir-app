<?php
/*
|--------------------------------------------------------------------------
| sidebar.php
|--------------------------------------------------------------------------
| CLEAN FINAL SIDEBAR
| - Desktop : sidebar kiri
| - Mobile  : drawer kanan
| - Tanpa bottom menu
|--------------------------------------------------------------------------
*/

if (!isset($activeMenu)) {
    $file = basename($_SERVER['PHP_SELF'] ?? '');

    $map = [
        'dashboard.php'      => 'dashboard',
        'index.php'          => 'dashboard',
        'pos.php'            => 'pos',
        'produk.php'         => 'produk',
        'stok_opname.php'    => 'stok',
        'rental_bandara.php' => 'rental',
        'driver.php'         => 'driver',
        'diskon.php'         => 'diskon',
        'laporan.php'        => 'laporan',
        'log_aktivitas.php'  => 'log',
    ];

    $activeMenu = $map[$file] ?? '';
}

if (!function_exists('sidebar_h')) {
    function sidebar_h(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$loginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');

$menus = [
    ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'],
    ['key' => 'pos', 'href' => 'pos.php', 'label' => 'Mesin Kasir (POS)'],
    ['key' => 'produk', 'href' => 'produk.php', 'label' => 'Kelola Produk'],
    ['key' => 'stok', 'href' => 'stok_opname.php', 'label' => 'Stok Opname'],
    ['key' => 'rental', 'href' => 'rental_bandara.php', 'label' => 'Rental Bandara'],
    ['key' => 'driver', 'href' => 'driver.php', 'label' => 'Driver Mitra'],
    ['key' => 'diskon', 'href' => 'diskon.php', 'label' => 'Kelola Diskon'],
    ['key' => 'laporan', 'href' => 'laporan.php', 'label' => 'Laporan Keuangan'],
    ['key' => 'log', 'href' => 'log_aktivitas.php', 'label' => 'Log Aktivitas'],
];
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

<div id="mobileMenuOverlay"
    class="fixed inset-0 bg-black/40 z-[100] opacity-0 invisible lg:hidden">

    <div id="mobileMenuContent"
        class="ml-auto w-[280px] max-w-[82vw] bg-white h-full translate-x-full shadow-2xl flex flex-col">

        <div class="px-6 py-5 border-b border-subtle flex items-center justify-between">
            <div>
                <p class="text-[10px] font-bold tracking-widest uppercase text-gray-400">
                    Navigasi
                </p>
                <p class="text-sm font-bold tracking-tight mt-1">
                    SEJAHUB
                </p>
            </div>

            <button type="button"
                onclick="toggleMobileMenu()"
                class="w-9 h-9 border border-subtle bg-white hover:bg-gray-50 text-sm font-bold"
                aria-label="Tutup menu">
                ×
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-6 py-4 no-scrollbar">
            <?php foreach ($menus as $menu): ?>
                <a href="<?= sidebar_h($menu['href']) ?>"
                    class="mobile-drawer-link <?= $activeMenu === $menu['key'] ? 'active' : '' ?>">
                    <?= sidebar_h($menu['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="px-6 py-5 border-t border-subtle">
            <p class="text-[10px] text-gray-400 font-medium uppercase">
                Login
            </p>

            <p class="text-xs font-bold text-gray-700 mt-1 truncate">
                <?= sidebar_h($loginNama) ?>
            </p>

            <a href="logout.php"
                onclick="return confirm('Yakin mau logout?')"
                class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
                Logout
            </a>
        </div>
    </div>
</div>

<aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">

    <div class="mb-12">
        <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">
            SEJAHUB
        </span>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto no-scrollbar">
        <?php foreach ($menus as $menu): ?>
            <a href="<?= sidebar_h($menu['href']) ?>"
                class="side-link <?= $activeMenu === $menu['key'] ? 'active' : '' ?>">
                <?= sidebar_h($menu['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mt-auto pt-6 border-t border-subtle">
        <p class="text-[10px] text-gray-400 font-medium truncate mt-1">
            Login: <?= sidebar_h($loginNama) ?>
        </p>

        <a href="logout.php"
            onclick="return confirm('Yakin mau logout?')"
            class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
            Logout
        </a>
    </div>

</aside>

<script>
    window.toggleMobileMenu = function() {
        const overlay = document.getElementById('mobileMenuOverlay');
        const content = document.getElementById('mobileMenuContent');

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
        const overlay = document.getElementById('mobileMenuOverlay');

        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    window.toggleMobileMenu();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const overlay = document.getElementById('mobileMenuOverlay');

                if (overlay && !overlay.classList.contains('invisible')) {
                    window.toggleMobileMenu();
                }
            }
        });
    });
</script>