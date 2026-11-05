<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
require_once __DIR__ . '/../bootstrap.php';
