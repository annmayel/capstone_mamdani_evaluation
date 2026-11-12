<?php

function require_dean_access(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION['user']) && ($_SESSION['user']['role'] ?? null) === 'dean') {
        return;
    }

    $isEmbeddedRequest = ($_GET['embedded'] ?? '') === '1'
        || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'iframe';

    if ($isEmbeddedRequest) {
        http_response_code(403);
        ?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Session expired</title></head>
<body style="margin:0;padding:28px;font:14px/1.6 Arial,sans-serif;background:#f8fafc;color:#173b43">
<main style="max-width:560px;margin:0 auto;padding:24px;background:#fff;border:1px solid #d8edef;border-radius:12px">
<h1 style="margin:0 0 8px;font-size:20px">Your Dean session has expired</h1>
<p style="margin:0 0 16px">This dashboard section could not verify your access. Sign in again to continue; the public homepage will not be loaded inside this section.</p>
<a href="../index.php?session_expired=1" target="_top" style="display:inline-block;padding:10px 14px;border-radius:7px;background:#b58863;color:#fff;font-weight:700;text-decoration:none">Return to sign in</a>
</main>
</body>
</html><?php
        exit;
    }

    header('Location: ../index.php?session_expired=1');
    exit;
}
