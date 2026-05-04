<?php

// ── Session & Timezone ───────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone PHP ke WIB
date_default_timezone_set('Asia/Jakarta');

// ── Konfigurasi Database ─────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'kasir_app');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/kasir-app/');

// ── Koneksi PDO ──────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Paksa MySQL pakai WIB (+07:00)
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    http_response_code(500);
    die("Koneksi database gagal");
}

// ── Helper Functions ─────────────────────────────────────────────────────────

/**
 * Buat URL dari base
 */
function base_url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * Escape HTML (anti XSS)
 * @param mixed $value
 */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format Rupiah
 * @param mixed $angka
 */
function rupiah($angka): string
{
    return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.');
}

/**
 * Alias rupiah
 * @param mixed $angka
 */
function formatRp($angka): string
{
    return rupiah($angka);
}

/**
 * Generate invoice unik
 */
function generateInvoice(): string
{
    return 'INV-' . date('YmdHis') . '-' . rand(100, 999);
}

// ── Sinkronisasi Auth Session ────────────────────────────────────────────────
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $_SESSION['user_id'] = $_SESSION['user']['id'] ?? null;
    $_SESSION['nama']    = $_SESSION['user']['nama'] ?? '';
    $_SESSION['role']    = $_SESSION['user']['role'] ?? '';
}
