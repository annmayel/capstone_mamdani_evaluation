<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

header('Location: fuzzy_rules.php');
exit;
