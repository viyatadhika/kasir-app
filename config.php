<?php

// ── Session & Timezone ───────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
            PDO::ATTR_EMULATE_PREPARES   => false, // lebih aman dari SQL injection
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    // Pada production: jangan tampilkan pesan error ke user
    die("Koneksi database gagal: " . $e->getMessage());
}

// ── Helper Functions ─────────────────────────────────────────────────────────

// Buat URL dari base
function base_url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

// Escape output HTML (cegah XSS)
function e($value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Format angka ke Rupiah
function rupiah($angka): string
{
    return 'Rp ' . number_format((int)$angka, 0, ',', '.');
}

// Alias rupiah (dipakai di index.php & pos.php)
function formatRp($angka): string
{
    return rupiah($angka);
}

// Generate nomor invoice unik
function generateInvoice(): string
{
    return 'INV-' . date('Ymd') . date('His') . '-' . rand(100, 999);
}

// ── Sinkronisasi Auth Session ────────────────────────────────────────────────
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $_SESSION['user_id'] = $_SESSION['user']['id'] ?? null;
    $_SESSION['nama']    = $_SESSION['user']['nama'] ?? '';
    $_SESSION['role']    = $_SESSION['user']['role'] ?? '';
}
