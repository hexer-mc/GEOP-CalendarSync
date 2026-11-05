<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Geop\Calendar\Lesson;
use Geop\Calendar\Snapshot;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function fails(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException|InvalidArgumentException) {
        check(true, 'Errore rilevato.');
        return;
    }
    throw new LogicException('Operazione non valida accettata.');
}
$directory = sys_get_temp_dir() . '/geop-snapshot-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/calendar.json';
$store = new Snapshot($path);
$token = str_repeat('a', 64);
$metadata = ['token' => $token];
$start = new DateTimeImmutable('2026-11-12T14:00:00+01:00');
$end = $start->modify('+2 hours');
$first = new DateTimeImmutable('2026-11-05T10:00:00Z');
$later = $first->modify('+1 hour');
$a = new Lesson('demo:a', 'Materia fittizia', $start, $end, $first, 'Aula demo');
$b = new Lesson('demo:b', 'Altra materia fittizia', $start, $end, $first);
try {
    check($store->authorizedCalendar($token) === null, 'Nessuno snapshot iniziale.');
    $store->save([$a, $b], 'demo-scope', $metadata);
    $original = $store->authorizedCalendar($token);
    check(substr_count($original, 'BEGIN:VEVENT') === 2, 'Due eventi salvati.');
    check(str_contains($original, 'DTSTAMP:20261105T100000Z'), 'Prima osservazione conservata.');
    $same = new Lesson($a->id, $a->title, $start, $end, $later, $a->location);
    $sameB = new Lesson($b->id, $b->title, $start, $end, $later);
    $store->save([$sameB, $same], 'demo-scope', $metadata);
    check($store->authorizedCalendar($token) === $original, 'Eventi e ordine della risposta invariati non cambiano ICS.');
    $changed = new Lesson($a->id, 'Titolo aggiornato', $start, $end, $later, 'Aula demo');
    $store->save([$changed, $sameB], 'demo-scope', $metadata);
    $updated = $store->authorizedCalendar($token);
    check(substr_count($updated, 'DTSTAMP:20261105T100000Z') === 1, 'Evento invariato mantiene il timestamp.');
    check(substr_count($updated, 'DTSTAMP:20261105T110000Z') === 1, 'Solo evento modificato cambia timestamp.');
    preg_match_all('/UID:([^\r]+)/', str_replace("\r\n ", '', $original), $oldUids);
    preg_match_all('/UID:([^\r]+)/', str_replace("\r\n ", '', $updated), $newUids);
    check($oldUids === $newUids, 'UID persistenti.');
    $beforeFailure = file_get_contents($path);
    fails(fn () => $store->save([], 'demo-scope', $metadata));
    fails(fn () => $store->save([$a], 'different-scope', $metadata));
    fails(fn () => $store->save([$a, $a], 'demo-scope', $metadata));
    check(file_get_contents($path) === $beforeFailure, 'Input invalido non altera il file precedente.');
    $store->save([$changed], 'demo-scope', $metadata);
    check(substr_count($store->authorizedCalendar($token), 'BEGIN:VEVENT') === 1, 'Snapshot completo sostituisce la stessa finestra.');
    check(glob($directory . '/.calendar-*') === [], 'Nessun temporaneo residuo.');
    file_put_contents($path, '{file corrotto');
    fails(fn () => $store->authorizedCalendar($token));
    fails(fn () => $store->save([$a], 'demo-scope', $metadata));
    check(file_get_contents($path) === '{file corrotto', 'Snapshot corrotto non sovrascritto.');
    if (PHP_OS_FAMILY !== 'Windows') {
        check((fileperms($path) & 0777) === 0600, 'Permessi privati.');
    }
    echo "OK: $checks verifiche snapshot.\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    }
    rmdir($directory);
}
