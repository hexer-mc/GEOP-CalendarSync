<?php
declare(strict_types=1);

namespace Geop\Geop;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class CalendarContext
{
    public function studentId(string $html): int
    {
        if (!extension_loaded('dom')) {
            throw new RuntimeException('È richiesta l’estensione PHP DOM per riconoscere il calendario.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $html !== '' && $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loaded) {
                throw new RuntimeException('La home GEOP non contiene un calendario riconoscibile.');
            }
            $nodes = (new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " fullcalendar ") and @data-oggetto="idAlunno"]');
            $ids = [];
            foreach ($nodes as $node) {
                $value = $node->getAttribute('data-id-oggetto');
                if (ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                    $ids[(int) $value] = true;
                }
            }
            if (count($ids) !== 1) {
                throw new RuntimeException('Non è stato riconosciuto un unico calendario alunno autorizzato nella home GEOP.');
            }
            return array_key_first($ids);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
