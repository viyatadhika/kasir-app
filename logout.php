<?php

require 'auth.php';

// Hapus session
session_destroy();

// Kembali ke login
header('Location: index.php');
exit;
