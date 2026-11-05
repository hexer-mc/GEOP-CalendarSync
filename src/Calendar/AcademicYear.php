<?php
declare(strict_types=1);

namespace Geop\Calendar;

use DateTimeImmutable;

final class AcademicYear
{
    /** @return array{start: string, end: string} */
    public static function range(DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        if ((int) $date->format('n') < 9) {
            --$year;
        }
        // Convenzione dell'app: settembre-agosto, non un limite restituito da GEOP.
        return ['start' => "$year-09-01", 'end' => ($year + 1) . '-09-01'];
    }
}
