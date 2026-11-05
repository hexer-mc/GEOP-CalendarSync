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
        $seen = [];
        foreach ($events as $event) {
            if (!$event instanceof stdClass || !is_int($event->id ?? null) || $event->id <= 0
                || !is_string($event->title ?? null) || isset($seen[$event->id])) {
                throw new InvalidArgumentException('Evento GEOP non valido o ID duplicato.');
            }
            $seen[$event->id] = true;
            $location = '';
            if (isset($event->tooltip) && is_string($event->tooltip)) {
                foreach (explode("\n", $this->text($event->tooltip)) as $line) {
                    if (str_starts_with(trim($line), 'Aula:')) {
                        $location = trim(substr(trim($line), strlen('Aula:')));
                        break;
                    }
                }
            }
            $lessons[] = new Lesson(
                id: $calendarKey . ':lesson:' . $event->id,
                title: $this->text($event->title),
                start: $this->date($event->start ?? null),
                end: $this->date($event->end ?? null),
                updatedAt: $observedAt,
                location: $location,
            );
        }
        return $lessons;
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
