<?php
declare(strict_types=1);

namespace Geop\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Icalendar
{
    /** @param list<Lesson> $lessons */
    public function render(array $lessons): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GEOP Calendar Sync//IT', 'CALSCALE:GREGORIAN'];
        $seen = [];
        foreach ($lessons as $lesson) {
            if (!$lesson instanceof Lesson || isset($seen[$lesson->id])) {
                throw new InvalidArgumentException('Lezioni non valide o ID duplicato.');
            }
            $seen[$lesson->id] = true;
            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:' . hash('sha256', $lesson->id) . '@geop-calendar-sync',
                'DTSTAMP:' . $this->utc($lesson->updatedAt),
                'LAST-MODIFIED:' . $this->utc($lesson->updatedAt),
                'DTSTART:' . $this->utc($lesson->start),
                'DTEND:' . $this->utc($lesson->end),
                'SUMMARY:' . $this->escape($lesson->title),
                'LOCATION:' . $this->escape($lesson->location),
                'END:VEVENT',
            ]);
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return strtr(str_replace(["\r\n", "\r"], "\n", $text), ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\n" => '\\n']);
    }

    private function fold(string $line): string
    {
        $result = '';
        while (strlen($line) > 75) {
            $length = 75;
            // Non spezzare una sequenza UTF-8 fra due byte.
            while ((ord($line[$length]) & 0xC0) === 0x80) {
                --$length;
            }
            $result .= substr($line, 0, $length) . "\r\n";
            $line = ' ' . substr($line, $length);
        }
        return $result . $line;
    }
}
