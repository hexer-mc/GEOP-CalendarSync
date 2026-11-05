<?php
declare(strict_types=1);

namespace Geop\Geop;

use CurlHandle;
use DateTimeImmutable;
use Geop\Calendar\Lesson;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final class Client
{
    private const BASE = 'https://itsar.registrodiclasse.it/geopcfp2/';

    /** @return list<Lesson> */
    public function fetchLessons(
        #[SensitiveParameter] string $email,
        #[SensitiveParameter] string $password,
        int $entityId,
        ?int $studentId,
        string $start,
        string $end,
    ): array {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $entityId < 1 || ($studentId !== null && $studentId < 1)) {
            throw new InvalidArgumentException('Configurazione GEOP non valida: controllare email, password e ID positivi.');
        }
        foreach ([$start, $end] as $day) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if ($date === false || $date->format('Y-m-d') !== $day) {
                throw new InvalidArgumentException('Usare date valide nel formato YYYY-MM-DD.');
            }
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('La fine deve essere successiva all’inizio.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Abilitare l’estensione PHP cURL.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare cURL.');
        }
        try {
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            curl_setopt_array($handle, [
                CURLOPT_COOKIEFILE => '', // Cookie solo in memoria per questa esecuzione.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'GEOP-Calendar-Sync/0.1',
                CURLOPT_REFERER => self::BASE . '?idEnte=' . $entityId,
                CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
            ]);
            $this->request($handle, '?idEnte=' . $entityId, 'Apertura GEOP');
            $login = $this->request(
                $handle,
                'update/login.asp?1=1&ajax_target=DIVHidden&ajax_tipotarget=login',
                'Login',
                http_build_query(['username' => $email, 'password' => $password], '', '&', PHP_QUERY_RFC3986),
            );
            if (!preg_match('/<input\b[^>]*\bid\s*=\s*["\x27]LoadScriptDIVHidden["\x27][^>]*\bvalue\s*=\s*["\x27]\s*HomePageLoad\(\);\s*["\x27]/i', $login)) {
                throw new RuntimeException('Login non confermato: risposta inattesa. Nessun tentativo automatico ripetuto.');
            }
            if ($studentId === null) {
                $home = $this->request($handle, 'ajax/page/home.asp?1=1&ajax_target=DIVContenuto&ajax_tipotarget=home', 'Identificazione calendario');
                $studentId = (new CalendarContext())->studentId($home);
            }
            $cursor = new DateTimeImmutable($start);
            $stop = new DateTimeImmutable($end);
            $observedAt = new DateTimeImmutable();
            $lessons = [];
            while ($cursor < $stop) {
                $next = min($cursor->modify('+7 days'), $stop);
                $query = http_build_query([
                    'Oggetto' => 'idAlunno', 'idOggetto' => $studentId, 'editable' => 'false',
                    'start' => $cursor->format('Y-m-d'), 'end' => $next->format('Y-m-d'),
                    'z' => (string) (int) (microtime(true) * 1000),
                    '_' => (string) (int) (microtime(true) * 1000),
                ], '', '&', PHP_QUERY_RFC3986);
                $json = $this->request($handle, 'json/fullcalendar_events_alunno.asp?' . $query, 'Lettura calendario');
                foreach ((new EventParser())->parse($json, "geop:ente:$entityId:alunno:$studentId", $observedAt) as $lesson) {
                    if (isset($lessons[$lesson->id]) && $lessons[$lesson->id] != $lesson) {
                        throw new RuntimeException('Calendario cambiato durante la lettura. Nessun aggiornamento salvato: riprova.');
                    }
                    $lessons[$lesson->id] = $lesson;
                }
                $cursor = $next;
            }
            return array_values($lessons);
        } finally {
            curl_close($handle);
        }
    }

    private function request(CurlHandle $handle, string $path, string $phase, #[SensitiveParameter] ?string $form = null): string
    {
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_URL => self::BASE . $path,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 5 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($form === null) {
            curl_setopt($handle, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form]);
        }
        if (@curl_exec($handle) === false) {
            throw new RuntimeException("$phase: trasferimento non riuscito (cURL " . curl_errno($handle) . '). Controllare rete, certificati e dimensione della risposta.');
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            throw new RuntimeException("$phase: HTTP $status inatteso. Redirect e tentativi automatici disabilitati.");
        }
        return $body;
    }
}
