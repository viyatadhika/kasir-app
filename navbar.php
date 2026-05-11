<?php
/*
|--------------------------------------------------------------------------
| navbar.php
|--------------------------------------------------------------------------
| Navbar/header global untuk aplikasi kasir.
|
| Variabel opsional sebelum require:
| $pageTitle = 'Dashboard';
| $backUrl = 'dashboard.php';
| $rightActionHtml = '';
*/

if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}

if (!isset($backUrl)) {
    $backUrl = 'dashboard.php';
}

if (!isset($rightActionHtml)) {
    $rightActionHtml = '';
}

if (!function_exists('navbar_h')) {
    function navbar_h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>

<header class="app-header page-header sticky top-0 bg-white border-b border-subtle px-4 sm:px-6 py-4 flex justify-between items-center z-40 shadow-sm">
    <div class="flex items-center gap-3 sm:gap-4">
        <button type="button" onclick="toggleMobileMenu()" class="lg:hidden p-2 hover:bg-gray-100 transition-colors" aria-label="Menu">☰</button>

        <?php if (!empty($backUrl)): ?>
            <a href="<?= navbar_h($backUrl) ?>" class="p-2 hover:bg-gray-100 transition-colors" aria-label="Kembali">←</a>
        <?php endif; ?>

        <h1 class="header-title page-header-title text-sm font-bold tracking-[0.2em] uppercase">
            <?= navbar_h($pageTitle) ?>
        </h1>
    </div>

    <div class="flex items-center gap-2 sm:gap-3">
        <?= $rightActionHtml ?>
    </div>
</header>