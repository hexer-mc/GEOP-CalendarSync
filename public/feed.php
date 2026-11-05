<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$provided = $_GET['token'] ?? '';
$account = $_GET['id'] ?? null;
if (!is_string($provided) || !is_string($account)
    || !preg_match('/^[a-f0-9]{64}$/D', $account) || !preg_match('/^[a-f0-9]{64}$/D', $provided)) {
    http_response_code(404);
    exit('Non trovato.');
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

try {
    require __DIR__ . '/../src/bootstrap.php';
    $calendar = (new \Geop\Web\PortalStore(__DIR__ . '/../var/web'))->snapshot($account)->authorizedCalendar($provided);
} catch (Throwable) {
    http_response_code(503);
    exit('Calendario non disponibile.');
}
if ($calendar === null) {
    http_response_code(404);
    exit('Non trovato.');
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="geop-calendar.ics"');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo $calendar;
}
