<?php
require_once __DIR__ . '/../config.php';
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') die('Forbidden');
$file = __DIR__ . '/setup_passwords.php';
if (file_exists($file)) {
    unlink($file);
    echo 'setup_passwords.php removed.';
} else echo 'File not found or already removed.';
