<?php
declare(strict_types=1);

// Router esclusivamente di test. Non va copiato nella document root o usato in produzione.
require __DIR__ . '/fixtures/curl-mock.php';
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $login = '<input type="hidden" id="LoadScriptDIVHidden" value="HomePageLoad();">';
    $home = file_get_contents(__DIR__ . '/fixtures/geop-home.html');
    if (($_POST['email'] ?? '') === 'second@example.invalid') {
        $home = str_replace('123', '456', $home);
    }
    if (($_POST['email'] ?? '') === 'denied@example.invalid') {
        $login = 'Accesso negato';
    }
    $responses = [[200, '<html>GEOP login</html>'], [200, $login], [200, $home]];
    for ($week = 0; $week < 13; ++$week) {
        $responses[] = [200, file_get_contents(__DIR__ . '/fixtures/geop-events.json')];
    }
    \Geop\Geop\HttpFixture::reset($responses);
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/../public/index.php';
} elseif ($path === '/feed.php') {
    require __DIR__ . '/../public/feed.php';
} elseif (in_array($path, ['/assets/app.css', '/assets/app.js'], true)) {
    return false;
} else {
    http_response_code(404);
}
