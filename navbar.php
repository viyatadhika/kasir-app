<?php
/*
|--------------------------------------------------------------------------
| navbar.php — Compatible PHP 7 & 8
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle))       $pageTitle       = 'Dashboard';
if (!isset($backUrl))         $backUrl         = '';
if (!isset($rightActionHtml)) $rightActionHtml = '';

// ── Helper — tanpa type hint agar kompatibel PHP 7 ──────────────────────────
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
        transition: background .15s ease, border-color .15s ease;
        cursor: pointer;
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
    }

    .navbar-actions::-webkit-scrollbar {
        display: none;
    }

    .mobile-only-btn {
        display: none;
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
            max-width: 32vw;
        }
    }
</style>

<header class="app-header page-header sticky top-0 bg-white border-b border-gray-100 px-4 sm:px-6 py-3 flex justify-between items-center z-40 shadow-sm">

    <div class="flex items-center gap-2 sm:gap-3 min-w-0">

        <button type="button"
            onclick="toggleMobileMenu()"
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