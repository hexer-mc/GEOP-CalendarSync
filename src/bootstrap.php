<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('È richiesto PHP 8.3 o successivo.');
}

require_once __DIR__ . '/Calendar/Lesson.php';
require_once __DIR__ . '/Calendar/AcademicYear.php';
require_once __DIR__ . '/Calendar/Icalendar.php';
require_once __DIR__ . '/Calendar/Snapshot.php';
require_once __DIR__ . '/Geop/EventParser.php';
require_once __DIR__ . '/Geop/Client.php';
require_once __DIR__ . '/Geop/CalendarContext.php';
require_once __DIR__ . '/Web/PortalStore.php';
