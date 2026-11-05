<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

use Geop\Geop\Client;
use Geop\Web\PortalStore;

$lock = false;
try {
    $config = require __DIR__ . '/config.php';
    $lock = fopen(__DIR__ . '/../var/sync.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Sincronizzazione già in corso o lock non disponibile.');
    }
    $lessons = (new Client())->fetchLessons(...$config);
    $store = new PortalStore(__DIR__ . '/../var/web');
    $account = $store->save($lessons, $config['start'], $config['end']);
    $token = $store->snapshot($account)->metadata()['token'];
    fwrite(STDOUT, 'OK: calendario aggiornato; ' . count($lessons) . " lezioni.\n");
    fwrite(STDOUT, "Link relativo alla tua installazione (riservato): feed.php?id=$account&token=$token\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        fclose($lock);
    }
}
