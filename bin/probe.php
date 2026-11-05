<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

use Geop\Calendar\Icalendar;
use Geop\Geop\Client;

try {
    $config = require __DIR__ . '/config.php';
    $lessons = (new Client())->fetchLessons(...$config);
    (new Icalendar())->render($lessons);
    if ($lessons === []) {
        fwrite(STDOUT, "Risposta JSON valida ma nessuna lezione: verificare settimana e contesto. Autenticazione non conclusivamente verificata.\n");
    } else {
        fwrite(STDOUT, 'OK: login e calendario verificati; ' . count($lessons) . " lezioni valide, conversione ICS riuscita.\n");
    }
    fwrite(STDOUT, "Nessun file scritto o feed pubblicato.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
