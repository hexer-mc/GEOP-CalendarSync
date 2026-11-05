<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Geop\Calendar\Icalendar;
use Geop\Calendar\Lesson;
use Geop\Geop\EventParser;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        check(true, 'Input rifiutato.');
        return;
    }
    check(false, 'Un input non valido è stato accettato.');
}

$zone = new DateTimeZone('Europe/Rome');
$start = new DateTimeImmutable('2026-11-09 09:00', $zone);
$end = $start->modify('+2 hours');
$updated = new DateTimeImmutable('2026-11-05T00:00:00Z');
$title = str_repeat('è📅', 30) . ",;\\\r\nEND:VEVENT";
$lesson = new Lesson('ente:3:lezione:1', $title, $start, $end, $updated);
$writer = new Icalendar();
$ics = $writer->render([$lesson]);
$unfolded = str_replace("\r\n ", '', $ics);
check(str_starts_with($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"), 'Intestazione ICS.');
check(str_ends_with($ics, "END:VCALENDAR\r\n"), 'Chiusura ICS.');
check(!preg_match('/(?<!\r)\n|\r(?!\n)/', $ics), 'Terminatori CRLF.');
foreach (explode("\r\n", $ics) as $line) {
    check(strlen($line) <= 75, 'Limite di 75 ottetti.');
    check(preg_match('//u', $line) === 1, 'Folding UTF-8.');
}
check(str_contains($unfolded, 'DTSTART:20261109T080000Z'), 'Ora invernale UTC.');
check(str_contains($unfolded, 'DTSTAMP:20261105T000000Z'), 'Timestamp stabile.');
check(str_contains($unfolded, '\\,\\;\\\\\\nEND:VEVENT'), 'Escape e protezione da iniezione ICS.');
check(substr_count($unfolded, "\r\nEND:VEVENT\r\n") === 1, 'Un solo evento.');
$changed = new Lesson($lesson->id, 'Titolo cambiato', $start->modify('+1 day'), $end->modify('+1 day'), $updated);
preg_match('/UID:([^\r]+)/', $unfolded, $uid);
check(str_contains(str_replace("\r\n ", '', $writer->render([$changed])), $uid[0]), 'UID stabile dopo modifica.');
$summer = new Lesson('summer', 'Estate', new DateTimeImmutable('2026-07-01 09:00', $zone), new DateTimeImmutable('2026-07-01 10:00', $zone), $updated);
check(str_contains($writer->render([$summer]), 'DTSTART:20260701T070000Z'), 'Ora estiva UTC.');
check(!str_contains($writer->render([]), 'BEGIN:VEVENT'), 'Calendario vuoto valido.');
rejects(fn () => new Lesson('', 'Titolo', $start, $end, $updated));
rejects(fn () => new Lesson('id', ' ', $start, $end, $updated));
rejects(fn () => new Lesson('id', 'Titolo', $start, $start, $updated));
rejects(fn () => new Lesson('id', "\xFF", $start, $end, $updated));
rejects(fn () => new Lesson('id', "Titolo\x00", $start, $end, $updated));
rejects(fn () => $writer->render([$lesson, $lesson]));

$parser = new EventParser();
$fixture = file_get_contents(__DIR__ . '/fixtures/geop-events.json');
$parsed = $parser->parse($fixture, 'demo:ente-3:calendario-a', $updated);
check(count($parsed) === 1, 'Evento GEOP convertito.');
check($parsed[0]->id === 'demo:ente-3:calendario-a:lesson:101', 'ID con contesto calendario.');
check($parsed[0]->location === 'DEMO-1', 'Aula estratta dal tooltip.');
check(!str_contains($parsed[0]->title, '<br>'), 'Titolo ripulito.');
check(!str_contains($writer->render($parsed), 'Registro lezione'), 'Stato del registro non esportato.');
check($parsed[0]->updatedAt === $updated, 'Timestamp fornito dal chiamante, non inventato.');
check(str_contains($writer->render($parsed), 'DTSTART:20261112T130000Z'), 'GEOP locale a UTC.');
check($parser->parse('[]', 'demo', $updated) === [], 'Risposta senza eventi.');
$event = json_decode($fixture, true, flags: JSON_THROW_ON_ERROR)[0];
$parseEvent = fn (array $value) => $parser->parse(json_encode([$value], JSON_THROW_ON_ERROR), 'demo', $updated);
$htmlEvent = $parseEvent(array_replace($event, ['title' => '<b>A &amp; B</b><BR />&lt;testo&gt;', 'tooltip' => 'Aula: A &amp; B<br/>']));
check($htmlEvent[0]->title === "A & B\n<testo>", 'Entità HTML e separatori preservati come testo.');
check($htmlEvent[0]->location === 'A & B', 'Entità nell’aula.');
$noTooltip = $event;
unset($noTooltip['tooltip']);
check($parseEvent($noTooltip)[0]->location === '', 'Tooltip opzionale.');
check($parseEvent(array_replace($event, ['tooltip' => ['formato nuovo']]))[0]->location === '', 'Tooltip non riconosciuto ignorato.');
rejects(fn () => $parser->parse('<html>Login</html>', 'demo', $updated));
rejects(fn () => $parser->parse('{}', 'demo', $updated));
rejects(fn () => $parser->parse('null', 'demo', $updated));
rejects(fn () => $parser->parse('[null]', 'demo', $updated));
rejects(fn () => $parser->parse($fixture, '', $updated));
rejects(fn () => $parser->parse(json_encode([$event, $event]), 'demo', $updated));
rejects(fn () => $parseEvent(array_replace($event, ['id' => '101'])));
rejects(fn () => $parseEvent(array_replace($event, ['id' => 0])));
rejects(fn () => $parseEvent(array_replace($event, ['title' => '<br>'])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => '2026-02-30T14:00:00'])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => '2026-11-12T14:00:00Z'])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => '2026-11-12T18:00:00'])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => null])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => '2026-03-29T02:30:00'])));
rejects(fn () => $parseEvent(array_replace($event, ['start' => '2026-10-25T02:30:00'])));
echo "OK: $checks verifiche.\n";
