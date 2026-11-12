<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'dean') {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

http_response_code(410);
echo json_encode(['ok' => false, 'error' => 'Membership functions are fixed in the system configuration and cannot be submitted for validation.']);
