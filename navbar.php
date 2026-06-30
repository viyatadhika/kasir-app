<?php
/*
|--------------------------------------------------------------------------
| navbar.php — Compatible PHP 7 & 8
|--------------------------------------------------------------------------
| Navbar global SEJAHUB.
| - Mobile: tombol menu + tombol kembali
| - Desktop: tombol menu/back otomatis disembunyikan
| - Mendukung tombol kanan dari variabel $rightActionHtml
| - Aman dipakai di halaman kas_harian.php, produk.php, laporan, dll.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle))       $pageTitle       = 'Dashboard';
if (!isset($backUrl))         $backUrl         = '';
if (!isset($rightActionHtml)) $rightActionHtml = '';

// Helper aman untuk PHP 7 & 8
if (!function_exists('navbar_h')) {
    /**
     * @param  mixed  $v
     * @return string
     */
    function navbar_h($v)
    {
        return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8');
    }
}
?>

<style>
    .app-header {
        min-height: 64px;
    }

    .navbar-btn {
        width: 38px;
        height: 38px;
        border: 1px solid #f0f0f0;
        background: #fff;
        border-radius: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 800;
        line-height: 1;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
        cursor: pointer;
        color: #111827;
        text-decoration: none;
        flex-shrink: 0;
    }

    .navbar-btn:hover {
        background: #fafafa;
        border-color: #e5e7eb;
    }

    .navbar-title {
        max-width: 56vw;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .navbar-actions {
        max-width: 42vw;
        overflow-x: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .navbar-actions::-webkit-scrollbar {
        display: none;
    }

    .mobile-only-btn {
        display: none;
    }

    @media (min-width: 1024px) {

        .app-header,
        .page-header {
            margin-left: 220px;
        }
    }

    @media (max-width: 1023px) {
        .mobile-only-btn {
            display: inline-flex;
        }
    }

    @media (max-width: 640px) {
        .app-header {
            min-height: 58px;
            padding-left: .75rem !important;
            padding-right: .75rem !important;
        }

        .navbar-title {
            max-width: 50vw;
            font-size: 12px !important;
            letter-spacing: .14em !important;
        }

        .navbar-actions {
            max-width: 34vw;
        }

        .navbar-actions button,
        .navbar-actions a {
            white-space: nowrap;
        }
    }

    @media print {

        .app-header,
        .page-header,
        .navbar-actions,
        .navbar-btn {
            display: none !important;
        }
    }
</style>

<header class="app-header page-header sticky top-0 bg-white border-b border-gray-100 px-4 sm:px-6 py-3 flex justify-between items-center z-40 shadow-sm">

    <div class="flex items-center gap-2 sm:gap-3 min-w-0">

        <button type="button"
            onclick="if (typeof toggleMobileMenu === 'function') { toggleMobileMenu(); }"
            class="navbar-btn mobile-only-btn"
            aria-label="Buka menu">
            &#9776;
        </button>

        <?php if (!empty($backUrl)): ?>
            <a href="<?php echo navbar_h($backUrl); ?>"
                class="navbar-btn mobile-only-btn"
                aria-label="Kembali">
                &larr;
            </a>
        <?php endif; ?>

        <div class="min-w-0">
            <p class="hidden sm:block text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-0.5">
                SEJAHUB
            </p>
            <h1 class="navbar-title header-title page-header-title text-sm font-bold tracking-[0.2em] uppercase">
                <?php echo navbar_h($pageTitle); ?>
            </h1>
        </div>

    </div>

    <div class="navbar-actions flex items-center gap-2 sm:gap-3">
        <?php echo $rightActionHtml; ?>
    </div>

</header>