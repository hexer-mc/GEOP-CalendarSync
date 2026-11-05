<?php
declare(strict_types=1);

namespace Geop\Geop;

use DateTimeImmutable;
use DateTimeZone;
use Geop\Calendar\Lesson;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class EventParser
{
    /** @return list<Lesson> */
    public function parse(string $json, string $calendarKey, DateTimeImmutable $observedAt): array
    {
        if (trim($calendarKey) === '') {
            throw new InvalidArgumentException('È richiesta una chiave stabile del calendario.');
        }
        try {
            $events = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Risposta GEOP non valida: JSON atteso.');
        }
        if (!is_array($events)) {
            throw new InvalidArgumentException('Risposta GEOP non valida: array di eventi atteso.');
        }

        $lessons = [];
        foreach ($events as $index => $event) {
            $row = $index + 1;
            if (!$event instanceof stdClass) {
                throw new InvalidArgumentException("Evento GEOP n. $row: oggetto JSON atteso.");
            }
            $rawId = $event->id ?? null;
            $id = is_string($rawId) && ctype_digit($rawId) ? ltrim($rawId, '0') : $rawId;
            $id = (is_int($id) || (is_string($id) && ctype_digit($id)))
                ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $rendering = $event->rendering ?? '';
            if ($id === false && in_array($rendering, ['background', 'inverse-background'], true)) {
                // Evidenziazioni FullCalendar senza ID lezione: non sono lezioni da esportare.
                continue;
            }
            if ($id === false) {
                $value = is_int($rawId) ? ", valore $rawId" : '';
                $kind = $rendering === '' ? 'normale' : 'non riconosciuto';
                throw new InvalidArgumentException("Evento GEOP n. $row: ID non valido (tipo " . get_debug_type($rawId) . "$value; rendering $kind).");
            }
            if (!is_string($event->title ?? null)) {
                throw new InvalidArgumentException("Evento GEOP n. $row: titolo assente o non testuale.");
            }
            $location = '';
            if (isset($event->tooltip) && is_string($event->tooltip)) {
                foreach (explode("\n", $this->text($event->tooltip)) as $line) {
                    if (str_starts_with(trim($line), 'Aula:')) {
                        $location = trim(substr(trim($line), strlen('Aula:')));
                        break;
                    }
                }
            }
            $lesson = new Lesson(
                id: $calendarKey . ':lesson:' . $id,
                title: $this->text($event->title),
                start: $this->date($event->start ?? null),
                end: $this->date($event->end ?? null),
                updatedAt: $observedAt,
                location: $location,
            );
            if (isset($lessons[$id]) && $lessons[$id] != $lesson) {
                throw new InvalidArgumentException("Evento GEOP n. $row: lo stesso ID identifica lezioni diverse.");
            }
            $lessons[$id] = $lesson;
        }
        return array_values($lessons);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $value)) {
            throw new InvalidArgumentException('Data GEOP non valida: formato locale senza offset atteso.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone('Europe/Rome'));
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $value) {
            throw new InvalidArgumentException('Data GEOP inesistente o non valida.');
        }
        // Le ore ripetute al ritorno all'ora solare non identificano un istante univoco.
        foreach ([-3600, 3600] as $offset) {
            if ($date->setTimestamp($date->getTimestamp() + $offset)->format('Y-m-d\TH:i:s') === $value) {
                throw new InvalidArgumentException('Data GEOP ambigua al cambio di ora: serve un offset.');
            }
        }
        return $date;
    }

    private function text(string $html): string
    {
        $text = strip_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $html));
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
