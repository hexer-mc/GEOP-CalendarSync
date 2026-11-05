<?php
declare(strict_types=1);

namespace Geop\Web;

use Geop\Calendar\Lesson;
use Geop\Calendar\Snapshot;
use RuntimeException;

final class PortalStore
{
    private string $secret;

    public function __construct(private readonly string $directory)
    {
        $this->ensureDirectory($directory);
        $key = fopen($directory . '/app.key', 'c+b');
        if ($key === false) {
            throw new RuntimeException('Archivio del portale non disponibile.');
        }
        try {
            if (!flock($key, LOCK_EX) || !chmod($directory . '/app.key', 0600)) {
                throw new RuntimeException('Archivio del portale non disponibile.');
            }
            $secret = stream_get_contents($key);
            if ($secret === '') {
                $secret = bin2hex(random_bytes(32));
                if (fwrite($key, $secret) !== 64 || !fflush($key)) {
                    throw new RuntimeException('Impossibile inizializzare l’archivio.');
                }
            }
            if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
                throw new RuntimeException('Chiave del portale non valida.');
            }
            $this->secret = $secret;
        } finally {
            fclose($key);
        }
    }

    /** @param list<Lesson> $lessons */
    public function save(array $lessons, string $start, string $end): string
    {
        if ($lessons === []) {
            throw new RuntimeException('Nessuna lezione trovata: calendario precedente conservato. Prova un altro intervallo.');
        }
        $context = strstr($lessons[0]->id, ':lesson:', true);
        if ($context === false) {
            throw new RuntimeException('Contesto calendario non riconosciuto.');
        }
        foreach ($lessons as $lesson) {
            if (strstr($lesson->id, ':lesson:', true) !== $context) {
                throw new RuntimeException('La risposta contiene calendari diversi.');
            }
        }
        $account = hash_hmac('sha256', $context, $this->secret);
        $this->ensureDirectory($this->directory . '/' . $account);
        $lock = $this->lock($account);
        try {
            $snapshot = $this->snapshot($account);
            $metadata = $snapshot->metadata();
            $token = $metadata['token'] ?? bin2hex(random_bytes(32));
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new RuntimeException('Token del calendario non valido.');
            }
            $snapshot->save($lessons, $account, ['token' => $token, 'start' => $start, 'end' => $end]);
            return $account;
        } finally {
            fclose($lock);
        }
    }

    public function snapshot(string $account): Snapshot
    {
        $this->validateAccount($account);
        return new Snapshot($this->directory . '/' . $account . '/calendar.json');
    }

    public function delete(string $account): void
    {
        $lock = $this->lock($account);
        try {
            $path = $this->directory . '/' . $account . '/calendar.json';
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Eliminazione non riuscita.');
            }
        } finally {
            fclose($lock);
        }
    }

    public function allowAttempt(string $ip): bool
    {
        $this->ensureDirectory($this->directory . '/limits');
        $path = $this->directory . '/limits/' . hash_hmac('sha256', $ip, $this->secret);
        $file = fopen($path, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Controllo accessi non disponibile.');
        }
        try {
            if (!flock($file, LOCK_EX) || !chmod($path, 0600)) {
                throw new RuntimeException('Controllo accessi non disponibile.');
            }
            $limit = json_decode(stream_get_contents($file), true);
            if (!is_array($limit) || !isset($limit['since'], $limit['count']) || $limit['since'] < time() - 900) {
                $limit = ['since' => time(), 'count' => 0];
            }
            if ($limit['count'] >= 30) {
                return false;
            }
            ++$limit['count'];
            $data = json_encode($limit, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $data) !== strlen($data) || !fflush($file)) {
                throw new RuntimeException('Controllo accessi non disponibile.');
            }
            return true;
        } finally {
            fclose($file);
        }
    }

    private function lock(string $account)
    {
        $this->validateAccount($account);
        $file = fopen($this->directory . '/' . $account . '/update.lock', 'c');
        if ($file === false || !flock($file, LOCK_EX | LOCK_NB)) {
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('Aggiornamento già in corso. Riprova tra poco.');
        }
        return $file;
    }

    private function validateAccount(string $account): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $account)) {
            throw new RuntimeException('Calendario non valido.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Archivio privato non disponibile.');
        }
    }
}
