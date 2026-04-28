<?php

require_once 'config.php';

/**
 * Mengambil data user yang sedang login.
 */
function user()
{
    return $_SESSION['user'] ?? null;
}

/**
 * Mengecek apakah user sudah login.
 */
function is_login()
{
    return isset($_SESSION['user']);
}

/**
 * Wajib login.
 * Kalau belum login, diarahkan ke login.php.
 */
function require_login()
{
    if (!is_login()) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

/**
 * Wajib admin.
 * Dipakai untuk halaman khusus admin.
 */
function require_admin()
{
    require_login();

    if (user()['role'] !== 'admin') {
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

/**
 * Logout user.
 */
function logout()
{
    session_destroy();
    header('Location: ' . base_url('login.php'));
    exit;
}
