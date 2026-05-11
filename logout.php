<?php
session_start();
require_once 'config.php';
require_once 'activity_helper.php';

catat_aktivitas(
    $pdo,
    'logout',
    'Logout',
    'User keluar dari aplikasi'
);

session_unset();
session_destroy();

header('Location: login.php');
exit;
