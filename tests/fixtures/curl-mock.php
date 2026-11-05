<?php
declare(strict_types=1);

namespace Geop\Geop {
    use CurlHandle;

    // Sostituisce solo l'I/O nei test: le opzioni passano comunque al vero cURL.
    final class HttpFixture
    {
        public static array $responses = [];
        public static array $requests = [];
        public static array $options = [];
        public static int $status = 0;
        public static bool $closed = false;
        public static string $method = 'GET';

        public static function reset(array $responses): void
        {
            self::$responses = $responses;
            self::$requests = self::$options = [];
            self::$status = 0;
            self::$closed = false;
            self::$method = 'GET';
        }
    }

    function curl_init(): CurlHandle|false { return \curl_init(); }
    function curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
    {
        HttpFixture::$options[$option] = $value;
        if ($option === CURLOPT_HTTPGET && $value) {
            HttpFixture::$method = 'GET';
        } elseif ($option === CURLOPT_POST && $value) {
            HttpFixture::$method = 'POST';
        }
        return \curl_setopt($handle, $option, $value);
    }
    function curl_setopt_array(CurlHandle $handle, array $options): bool
    {
        foreach ($options as $option => $value) {
            if (!curl_setopt($handle, $option, $value)) {
                return false;
            }
        }
        return true;
    }
    function curl_exec(CurlHandle $handle): bool
    {
        HttpFixture::$requests[] = ['method' => HttpFixture::$method, 'options' => HttpFixture::$options];
        if (HttpFixture::$responses === []) {
            throw new \RuntimeException('Richiesta inattesa nel test.');
        }
        [$status, $body] = array_shift(HttpFixture::$responses);
        HttpFixture::$status = $status;
        if ($body === false) {
            return false;
        }
        return (HttpFixture::$options[CURLOPT_WRITEFUNCTION])($handle, $body) === strlen($body);
    }
    function curl_getinfo(CurlHandle $handle, int $option): int { return HttpFixture::$status; }
    function curl_errno(CurlHandle $handle): int { return 7; }
    function curl_close(CurlHandle $handle): void
    {
        HttpFixture::$closed = true;
        \curl_close($handle);
    }
}
