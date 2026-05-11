<?php
/*
|--------------------------------------------------------------------------
| sidebar.php
|--------------------------------------------------------------------------
| Sidebar + mobile menu global untuk aplikasi kasir.
|
| Variabel opsional sebelum require:
| $activeMenu = 'dashboard';
*/

if (!isset($activeMenu)) {
    $currentFile = basename($_SERVER['PHP_SELF'] ?? '');
    $activeMap = [
        'dashboard.php' => 'dashboard',
        'index.php' => 'dashboard',
        'pos.php' => 'pos',
        'produk.php' => 'produk',
        'stok_opname.php' => 'stok',
        'rental_bandara.php' => 'rental',
        'driver.php' => 'driver',
        'diskon.php' => 'diskon',
        'laporan.php' => 'laporan',
        'log_aktivitas.php' => 'log',
    ];
    $activeMenu = $activeMap[$currentFile] ?? '';
}

if (!function_exists('sidebar_h')) {
    function sidebar_h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$sidebarLoginNama = $_SESSION['nama'] ?? ($_SESSION['user']['nama'] ?? '-');

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

<div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[100] opacity-0 invisible flex justify-end lg:hidden">
    <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
        <div class="flex justify-between items-center mb-10">
            <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
            <button type="button" onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100 transition-colors" aria-label="Tutup Menu">✕</button>
        </div>

        <nav class="space-y-7 flex-1">
            <?php foreach ($menus as $menu): ?>
                <a href="<?= sidebar_h($menu['href']) ?>"
                    class="block text-sm <?= $activeMenu === $menu['key'] ? 'font-bold text-black' : 'font-medium text-gray-400 hover:text-black' ?> uppercase tracking-widest transition-colors">
                    <?= sidebar_h($menu['label']) ?>
                </a>
            <?php endforeach; ?>

            <a href="logout.php"
                onclick="return confirm('Yakin mau logout?')"
                class="block text-sm font-bold text-red-500 uppercase tracking-widest">
                Logout
            </a>
        </nav>

        <div class="pt-8 border-t border-subtle">
            <p class="text-[10px] text-gray-400 font-medium uppercase">KOPERASI BSDK</p>
            <p class="text-[10px] text-gray-400 font-medium">Login: <?= sidebar_h($sidebarLoginNama) ?></p>
        </div>
    </div>
</div>

<aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
    <div class="mb-12">
        <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span>
    </div>

    <nav class="flex-1 space-y-6">
        <?php foreach ($menus as $menu): ?>
            <?php if ($activeMenu === $menu['key']): ?>
                <a href="<?= sidebar_h($menu['href']) ?>" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2">
                    <span class="w-2 h-2 bg-black rounded-full"></span><?= sidebar_h($menu['label']) ?>
                </a>
            <?php else: ?>
                <a href="<?= sidebar_h($menu['href']) ?>" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">
                    <?= sidebar_h($menu['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="mt-auto">
        <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
        <p class="text-[10px] text-gray-400 font-medium">v 2.5.1</p>
        <a href="logout.php"
            onclick="return confirm('Yakin mau logout?')"
            class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
            Logout
        </a>
    </div>
</aside>

<script>
    if (typeof window.toggleMobileMenu !== 'function') {
        window.toggleMobileMenu = function() {
            const overlay = document.getElementById('mobileMenuOverlay');
            const content = document.getElementById('mobileMenuContent');

            if (!overlay || !content) return;

            if (overlay.classList.contains('invisible')) {
                overlay.classList.remove('invisible');
                overlay.classList.add('opacity-100');
                content.classList.remove('translate-x-full');
            } else {
                overlay.classList.add('invisible');
                overlay.classList.remove('opacity-100');
                content.classList.add('translate-x-full');
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('mobileMenuOverlay');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) window.toggleMobileMenu();
                });
            }
        });
    }
</script>