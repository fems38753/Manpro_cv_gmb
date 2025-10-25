<?php
// auth/logout.php
require_once __DIR__ . '/../config/database.php';
session_unset();
session_destroy();
session_start();
$_SESSION['flash_ok'] = 'Kamu sudah logout.';
redirect('auth/login.php');
