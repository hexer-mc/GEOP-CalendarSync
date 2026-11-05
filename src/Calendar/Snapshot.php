<?php
declare(strict_types=1);

namespace Geop\Calendar;

use DateTimeImmutable;
use RuntimeException;

final readonly class Snapshot
{
    public function __construct(private string $path) {}

    /** @param list<Lesson> $lessons */
    public function save(array $lessons, string $scope, array $metadata): void
    {
        // shortcut: non pubblica finestre vuote, abilitare solo dopo verifica delle cancellazioni totali GEOP.
        if ($lessons === [] || $scope === '') {
            throw new RuntimeException('Snapshot non aggiornato: nessuna lezione o contesto assente.');
        }
        $previous = $this->read();
        if ($previous !== null && $previous['scope'] !== $scope) {
            throw new RuntimeException('Snapshot non aggiornato: account o intervallo diverso dal precedente.');
        }
        $state = [];
        $stable = [];
        foreach ($lessons as $lesson) {
            $fingerprint = hash('sha256', json_encode([
                $lesson->title, $lesson->start->getTimestamp(), $lesson->end->getTimestamp(),
                $lesson->location,
            ], JSON_THROW_ON_ERROR));
            $old = $previous['events'][$lesson->id] ?? null;
            $updated = $old !== null && $old['fingerprint'] === $fingerprint
                ? new DateTimeImmutable($old['updatedAt']) : $lesson->updatedAt;
            $stable[] = new Lesson($lesson->id, $lesson->title, $lesson->start, $lesson->end, $updated, $lesson->location);
            $state[$lesson->id] = ['fingerprint' => $fingerprint, 'updatedAt' => $updated->format(DATE_ATOM)];
        }
        usort($stable, static fn (Lesson $a, Lesson $b): int => strcmp($a->id, $b->id));
        $json = json_encode([
            'scope' => $scope, 'events' => $state, 'calendar' => (new Icalendar())->render($stable), 'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $directory = dirname($this->path);
        $temp = tempnam($directory, '.calendar-');
        if ($temp === false) {
            throw new RuntimeException('Impossibile creare il file temporaneo dello snapshot.');
        }
        try {
            if (realpath(dirname($temp)) !== realpath($directory)
                || !chmod($temp, 0600)
                || file_put_contents($temp, $json) !== strlen($json)
                || !rename($temp, $this->path)) {
                throw new RuntimeException('Scrittura snapshot fallita: calendario precedente conservato.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function metadata(): array
    {
        $data = $this->read();
        return $data === null ? [] : array_merge($data['metadata'] ?? [], ['count' => count($data['events'])]);
    }

    public function authorizedCalendar(string $token): ?string
    {
        $data = $this->read();
        $stored = $data['metadata']['token'] ?? null;
        return is_string($stored) && hash_equals($stored, $token) ? $data['calendar'] : null;
    }

    private function read(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }
        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new RuntimeException('Impossibile leggere lo snapshot.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['scope'] ?? null) || $data['scope'] === ''
                || !is_array($data['events'] ?? null) || !is_string($data['calendar'] ?? null)
                || (isset($data['metadata']) && !is_array($data['metadata']))
                || !str_starts_with($data['calendar'], "BEGIN:VCALENDAR\r\n")
                || !str_ends_with($data['calendar'], "END:VCALENDAR\r\n")) {
                throw new RuntimeException();
            }
            foreach ($data['events'] as $event) {
                if (!is_array($event) || !is_string($event['fingerprint'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $event['fingerprint'])
                    || !is_string($event['updatedAt'] ?? null)) {
                    throw new RuntimeException();
                }
                $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $event['updatedAt']);
                if ($date === false || $date->format(DATE_ATOM) !== $event['updatedAt']) {
                    throw new RuntimeException();
                }
            }
        } catch (\Throwable) {
            throw new RuntimeException('Snapshot non valido: nessun aggiornamento eseguito.');
        }
        return $data;
    }
}
