<?php

require_once __DIR__ . '/config.php';
// config.php sudah include config_roles.php, jadi getCurrentRole(),
// canAccessPage(), canSeeMenu(), requireAccess() langsung tersedia.

// ══════════════════════════════════════════════════════════════════════════
// FUNGSI DASAR AUTH (backward-compatible dengan kode lama)
// ══════════════════════════════════════════════════════════════════════════

/**
 * Data user yang sedang login.
 */
function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Cek apakah user sudah login.
 */
function is_login(): bool
{
    return isset($_SESSION['user']);
}

/**
 * Wajib login — redirect ke login.php jika belum.
 * (Tetap ada untuk backward-compatibility.)
 */
function require_login(): void
{
    if (!is_login()) {
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

/**
 * Wajib admin — redirect jika bukan admin.
 * (Tetap ada untuk backward-compatibility.)
 * Untuk halaman baru, gunakan requireAccess() dari config_roles.php.
 */
function require_admin(): void
{
    require_login();

    if ((user()['role'] ?? '') !== 'admin') {
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
}

/**
 * Logout user.
 */
function logout(): void
{
    session_destroy();
    header('Location: ' . base_url('index.php'));
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// SHORTCUT ROLE HELPERS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Ambil role user aktif.
 * Wrapper tipis atas getCurrentRole() dari config_roles.php.
 */
function role(): string
{
    return getCurrentRole();
}

/**
 * Cek apakah user aktif memiliki salah satu dari role yang diberikan.
 *
 * Contoh:
 *   has_role('admin')               → true jika admin
 *   has_role('kasir', 'admin')      → true jika kasir atau admin
 *   has_role(['admin','rental'])    → bisa juga pakai array
 *
 * @param  string|array  ...$roles
 */
function has_role(...$roles): bool
{
    $current = getCurrentRole();

    // Support has_role(['admin','kasir']) maupun has_role('admin','kasir')
    $check = [];
    foreach ($roles as $r) {
        if (is_array($r)) {
            $check = array_merge($check, $r);
        } else {
            $check[] = (string)$r;
        }
    }

    return in_array($current, $check, true);
}

/**
 * Tampilkan konten hanya jika user punya role yang sesuai.
 * Berguna untuk blade/template inline.
 *
 * Contoh:
 *   <?php if (role_can('admin', 'kasir')): ?>
 *       <button>Hapus</button>
 *   <?php endif; ?>
 *
 * @param  string  ...$roles
 */
function role_can(string ...$roles): bool
{
    return has_role(...$roles);
}
