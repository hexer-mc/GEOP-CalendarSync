<?php
declare(strict_types=1);

namespace {
    require __DIR__ . '/fixtures/curl-mock.php';
    require __DIR__ . '/../src/bootstrap.php';

    use Geop\Geop\Client;
    use Geop\Geop\HttpFixture;

    if (!extension_loaded('curl')) {
        fwrite(STDERR, "Test client: abilitare cURL (su questo Windows: php -d extension=curl tests/client.php).\n");
        exit(1);
    }
    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        ++$checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
    $login = '<input type="hidden" id="LoadScriptDIVHidden" value="HomePageLoad();">';
    $json = file_get_contents(__DIR__ . '/fixtures/geop-events.json');
    $fetch = fn () => (new Client())->fetchLessons('demo@example.invalid', 'finta+&=password', 3, 123, '2026-11-09', '2026-11-16');
    HttpFixture::reset([[200, '<html>Login</html>'], [200, $login], [200, $json]]);
    check(count($fetch()) === 1, 'Flusso completo.');
    check(HttpFixture::$closed, 'Sessione rilasciata.');
    check(array_column(HttpFixture::$requests, 'method') === ['GET', 'POST', 'GET'], 'Sequenza GET/POST/GET.');
    $options = HttpFixture::$requests[1]['options'];
    check($options[CURLOPT_URL] === 'https://itsar.registrodiclasse.it/geopcfp2/update/login.asp?1=1&ajax_target=DIVHidden&ajax_tipotarget=login', 'URL osservato.');
    parse_str($options[CURLOPT_POSTFIELDS], $form);
    check($form === ['username' => 'demo@example.invalid', 'password' => 'finta+&=password'], 'Codifica delle credenziali.');
    check($options[CURLOPT_COOKIEFILE] === '' && !isset($options[CURLOPT_COOKIEJAR]), 'Nessun cookie jar su disco.');
    check($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'Verifica TLS.');
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        check($options[CURLOPT_SSL_OPTIONS] === CURLSSLOPT_NATIVE_CA, 'Archivio CA Windows.');
    }
    check(!$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Solo HTTPS, niente redirect.');
    parse_str(parse_url(HttpFixture::$requests[2]['options'][CURLOPT_URL], PHP_URL_QUERY), $query);
    check($query['idOggetto'] === '123' && $query['start'] === '2026-11-09' && $query['end'] === '2026-11-16', 'Contesto e intervallo osservati.');
    foreach ([
        [[[200, 'login'], [401, 'credenziali errate']], 2],
        [[[200, 'login'], [302, 'redirect']], 2],
        [[[200, 'login'], [200, 'risposta inattesa']], 2],
        [[[200, 'login'], [200, $login], [200, '<html>sessione scaduta</html>']], 3],
        [[[200, 'login'], [200, $login], [403, 'vietato']], 3],
        [[[200, false]], 1],
        [[[200, str_repeat('x', 5 * 1024 * 1024 + 1)]], 1],
    ] as [$responses, $expectedRequests]) {
        HttpFixture::reset($responses);
        try {
            $fetch();
            throw new LogicException('Errore non rilevato.');
        } catch (RuntimeException|InvalidArgumentException $error) {
            check(!str_contains($error->getMessage(), 'finta+&=password'), 'Errore privo di credenziali.');
            check(count(HttpFixture::$requests) === $expectedRequests, 'Nessuna ripetizione dopo errore.');
            check(HttpFixture::$closed, 'Rilascio sessione dopo errore.');
        }
    }
    HttpFixture::reset([[200, 'login'], [200, $login], [200, '[]']]);
    check($fetch() === [], 'Lista vuota mantenuta distinta dal successo con lezioni.');
    foreach ([['2026-02-30', '2026-03-01'], ['2026-11-16', '2026-11-09']] as [$start, $end]) {
        HttpFixture::reset([]);
        try {
            (new Client())->fetchLessons('demo@example.invalid', 'finta', 3, 123, $start, $end);
            throw new LogicException('Data non valida accettata.');
        } catch (InvalidArgumentException) {
            check(HttpFixture::$requests === [], 'Date rifiutate prima della rete.');
        }
    }
    $home = file_get_contents(__DIR__ . '/fixtures/geop-home.html');
    HttpFixture::reset([[200, 'login'], [200, $login], [200, $home], [200, $json]]);
    $personal = (new Client())->fetchLessons('demo@example.invalid', 'finta', 3, null, '2026-11-09', '2026-11-16');
    check(count($personal) === 1 && str_contains($personal[0]->id, 'alunno:123:'), 'Contesto da home autenticata.');
    check(count(HttpFixture::$requests) === 4, 'Home letta prima degli eventi.');
    HttpFixture::reset([[200, 'login'], [200, $login], [200, $json], [200, $json]]);
    $weeks = (new Client())->fetchLessons('demo@example.invalid', 'finta', 3, 123, '2026-11-09', '2026-11-23');
    check(count($weeks) === 1, 'Evento sovrapposto fra settimane deduplicato.');
    parse_str(parse_url(HttpFixture::$requests[3]['options'][CURLOPT_URL], PHP_URL_QUERY), $secondWeek);
    check($secondWeek['start'] === '2026-11-16' && $secondWeek['end'] === '2026-11-23', 'Intervallo suddiviso in settimane.');
    HttpFixture::reset([[200, 'login'], [200, $login], [200, $json], [500, 'errore']]);
    try {
        (new Client())->fetchLessons('demo@example.invalid', 'finta', 3, 123, '2026-11-09', '2026-11-23');
        throw new LogicException('Risultato parziale accettato.');
    } catch (RuntimeException) {
        check(count(HttpFixture::$requests) === 4, 'Errore dopo prima settimana interrompe tutto il recupero.');
    }
    echo "OK: $checks verifiche client con risposte simulate, nessuna rete.\n";
}
