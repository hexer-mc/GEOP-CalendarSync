<?php
declare(strict_types=1);

namespace Geop\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Lesson
{
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public DateTimeImmutable $updatedAt,
        public string $location = '',
    ) {
        foreach ([$id, $title, $location] as $text) {
            if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                throw new InvalidArgumentException('Testo non valido: sono richiesti UTF-8 e nessun carattere di controllo.');
            }
        }
        if (trim($id) === '' || trim($title) === '' || $end <= $start) {
            throw new InvalidArgumentException('Servono ID, titolo e un intervallo temporale positivo.');
        }
    }
}
