<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Geop\Geop\CalendarContext;
use Geop\Geop\EventParser;
use Geop\Web\PortalStore;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
function fails(callable $operation): void
{
    try { $operation(); } catch (RuntimeException) { check(true, 'Input rifiutato.'); return; }
    throw new LogicException('Input non valido accettato.');
}
$context = new CalendarContext();
$home = file_get_contents(__DIR__ . '/fixtures/geop-home.html');
check($context->studentId($home) === 123, 'ID esclusivamente dalla home autenticata.');
fails(fn () => $context->studentId(''));
fails(fn () => $context->studentId('<html>Login scaduto</html>'));
fails(fn () => $context->studentId(str_replace('idAlunno', 'idDocente', $home)));
fails(fn () => $context->studentId(str_replace('123', '../1', $home)));
fails(fn () => $context->studentId($home . str_replace('123', '456', $home)));
$dir = sys_get_temp_dir() . '/geofetch-test-' . bin2hex(random_bytes(8));
try {
    $store = new PortalStore($dir);
    $parser = new EventParser();
    $json = file_get_contents(__DIR__ . '/fixtures/geop-events.json');
    $first = $parser->parse($json, 'geop:ente:3:alunno:123', new DateTimeImmutable('2026-11-05T10:00:00Z'));
    $second = $parser->parse($json, 'geop:ente:3:alunno:456', new DateTimeImmutable('2026-11-05T10:00:00Z'));
    $a = $store->save($first, '2026-11-09', '2026-11-16');
    $b = $store->save($second, '2026-11-09', '2026-11-16');
    check($a !== $b, 'Utenti separati.');
    $tokenA = $store->snapshot($a)->metadata()['token'];
    $tokenB = $store->snapshot($b)->metadata()['token'];
    check($tokenA !== $tokenB, 'Token personali casuali.');
    check($store->snapshot($b)->authorizedCalendar($tokenA) === null, 'Un token non legge altri calendari.');
    check(str_contains($store->snapshot($a)->authorizedCalendar($tokenA), 'BEGIN:VCALENDAR'), 'Token corretto legge ICS.');
    $store->save($first, '2026-11-09', '2026-12-01');
    check($store->snapshot($a)->metadata()['token'] === $tokenA, 'Link stabile dopo aggiornamento/intervallo diverso.');
    fails(fn () => $store->save([], '2026-11-09', '2026-11-16'));
    fails(fn () => $store->save(array_merge($first, $second), '2026-11-09', '2026-11-16'));
    fails(fn () => $store->snapshot('../../config/local.php'));
    check($store->snapshot($a)->authorizedCalendar($tokenA) !== null, 'Errore non sovrascrive il feed.');
    $store->delete($a);
    check($store->snapshot($a)->authorizedCalendar($tokenA) === null, 'Eliminazione revoca il feed.');
    check($store->snapshot($b)->authorizedCalendar($tokenB) !== null, 'Eliminazione isolata per utente.');
    $store->save($first, '2026-11-09', '2026-11-16');
    check($store->snapshot($a)->metadata()['token'] !== $tokenA, 'Nuova importazione non riattiva link eliminato.');
    for ($attempt = 0; $attempt < 30; ++$attempt) { check($store->allowAttempt('192.0.2.1'), 'Tentativo iniziale consentito.'); }
    check(!$store->allowAttempt('192.0.2.1'), 'Limite tentativi applicato.');
    check($store->allowAttempt('192.0.2.2'), 'Limite distinto per rete.');
    $reopened = new PortalStore($dir);
    check($reopened->save($first, '2026-11-09', '2026-11-16') === $a, 'Identità stabile dopo nuovo login/riavvio.');
    echo "OK: $checks verifiche portale.\n";
} finally {
    // Solo file generati in questa directory temporanea univoca, senza rimozioni ricorsive.
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
        foreach (glob($child . '/*') ?: [] as $file) { unlink($file); }
        rmdir($child);
    }
    if (is_file($dir . '/app.key')) { unlink($dir . '/app.key'); }
    if (is_dir($dir)) { rmdir($dir); }
}
