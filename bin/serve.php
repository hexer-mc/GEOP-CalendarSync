<?php
declare(strict_types=1);

namespace Geop\Launcher;

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "Serve PHP 8.3 o successivo.\n");
    exit(1);
}

if (!function_exists('proc_open')) {
    fwrite(STDERR, "Abilita proc_open nel PHP CLI per avviare il server locale.\n");
    exit(1);
}

$php = [PHP_BINARY];
foreach (['curl', 'dom'] as $extension) {
    if (!extension_loaded($extension)) {
        $php[] = '-d';
        $php[] = 'extension=' . $extension;
    }
}
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$check = proc_open(
    [...$php, '-r', 'exit(extension_loaded("curl") && extension_loaded("dom") ? 0 : 1);'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
    $pipes,
);
if (!is_resource($check) || proc_close($check) !== 0) {
    fwrite(STDERR, "Installa o abilita le estensioni PHP cURL e DOM.\n");
    exit(1);
}

$address = '127.0.0.1:8080';
$existing = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
if ($existing !== false) {
    fclose($existing);
    fwrite(STDERR, "La porta 8080 e' occupata. Chiudi il precedente server locale e riprova.\n");
    exit(1);
}
$socket = @stream_socket_server('tcp://' . $address, $errorCode, $errorMessage);
if ($socket === false) {
    fwrite(STDERR, "La porta 8080 e' occupata. Chiudi il precedente server locale e riprova.\n");
    exit(1);
}
fclose($socket);

putenv('GEOFETCH_ALLOW_HTTP=1');
$root = dirname(__DIR__);
$server = proc_open([...$php, '-S', $address, '-t', $root . '/public'], [STDIN, STDOUT, STDERR], $pipes, $root);
if (!is_resource($server)) {
    fwrite(STDERR, "Impossibile avviare il server locale.\n");
    exit(1);
}
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server) && proc_get_status($server)['running']) {
        proc_terminate($server);
    }
});

$ready = false;
for ($attempt = 0; $attempt < 50; ++$attempt) {
    if (!proc_get_status($server)['running']) {
        break;
    }
    $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
    if ($connection !== false) {
        fclose($connection);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    proc_terminate($server);
    proc_close($server);
    fwrite(STDERR, "Il server non e' pronto. Controlla i messaggi sopra.\n");
    exit(1);
}

$url = 'http://' . $address . '/';
fwrite(STDOUT, "GeoFetch: $url\nLascia aperta questa finestra. Premi Ctrl+C per fermare il server.\n");
if (function_exists('exec')) {
    if (PHP_OS_FAMILY === 'Windows') {
        exec('start "" "' . $url . '" >NUL 2>&1');
    } elseif (getenv('DISPLAY') || getenv('WAYLAND_DISPLAY')) {
        exec('xdg-open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
    }
}
$result = proc_close($server);
exit($result < 0 ? 1 : $result);
