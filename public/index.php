<?php
declare(strict_types=1);

require __DIR__ . '/../src/Web/security.php';

use Geop\Geop\Client;
use Geop\Web\PortalStore;
use Geop\Calendar\AcademicYear;

function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$https = ($_SERVER['HTTPS'] ?? '') === 'on';
$local = getenv('GEOFETCH_ALLOW_HTTP') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$secure = $https || $local;
$store = null;
$error = '';
$notice = '';
$metadata = [];
$account = null;
$source = getenv('GEOFETCH_SOURCE_URL') ?: 'https://github.com/hexer-mc/GEOP-CalendarSync';
if (!filter_var($source, FILTER_VALIDATE_URL) || parse_url($source, PHP_URL_SCHEME) !== 'https') {
    $source = '';
}
$zone = new DateTimeZone('Europe/Rome');
$today = new DateTimeImmutable('today', $zone);
$period = AcademicYear::range($today);
$start = $period['start'];
$end = $period['end'];
$yearLabel = substr($start, 0, 4) . '/' . substr($end, 2, 2);
try {
    $store = new PortalStore(__DIR__ . '/../var/web');
    $sessions = __DIR__ . '/../var/web/sessions';
    if (!is_dir($sessions) && !mkdir($sessions, 0700) && !is_dir($sessions)) {
        throw new RuntimeException('Sessioni del pannello non disponibili.');
    }
    session_save_path($sessions);
    session_name('geofetch_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/']);
    if (!session_start()) {
        throw new RuntimeException('Sessione del pannello non disponibile.');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if (isset($_SESSION['authenticated_at']) && $_SESSION['authenticated_at'] < time() - 1800) {
        unset($_SESSION['account'], $_SESSION['authenticated_at']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? null;
        if (!$secure) {
            http_response_code(400);
            throw new RuntimeException('Per inviare credenziali è necessaria una connessione HTTPS.');
        }
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403);
            throw new RuntimeException('La pagina è scaduta. Ricaricala prima di riprovare.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'delete' && is_string($_SESSION['account'] ?? null)) {
            $store->delete($_SESSION['account']);
            unset($_SESSION['account']);
            $_SESSION['notice'] = 'Calendario eliminato. Il precedente link non è più valido.';
        } elseif ($action === 'logout') {
            unset($_SESSION['account']);
            $_SESSION['notice'] = 'Pannello chiuso. Il feed rimane disponibile.';
        } elseif ($action === 'import') {
            foreach (['email', 'password'] as $field) {
                if (!is_string($_POST[$field] ?? null)) {
                    throw new RuntimeException('Compila tutti i campi richiesti.');
                }
            }
            if (($_POST['consent'] ?? '') !== '1') {
                throw new RuntimeException('Leggi e conferma come vengono utilizzati i dati.');
            }
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
                throw new RuntimeException('Controlla email e password.');
            }
            if (!$store->allowAttempt($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                http_response_code(429);
                throw new RuntimeException('Troppi tentativi da questa rete. Riprova tra 15 minuti.');
            }
            // La sessione PHP contiene solo CSRF e identificativo del feed, mai credenziali o cookie GEOP.
            session_write_close();
            try {
                $lessons = (new Client())->fetchLessons($email, $password, 3, null, $start, $end);
                $account = $store->save($lessons, $start, $end);
            } finally {
                unset($email, $password);
                $_POST = [];
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['account'] = $account;
            $_SESSION['authenticated_at'] = time();
            $_SESSION['notice'] = 'Fatto! Il tuo calendario è pronto, con ' . count($lessons) . ' lezioni importate.';
        } else {
            throw new RuntimeException('Operazione non disponibile.');
        }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: ./', true, 303);
        exit;
    }
    $account = $_SESSION['account'] ?? null;
    if (is_string($account)) {
        $metadata = $store->snapshot($account)->metadata();
    }
    $notice = $_SESSION['notice'] ?? '';
    unset($_SESSION['notice']);
} catch (Throwable $failure) {
    $error = $failure instanceof RuntimeException || $failure instanceof InvalidArgumentException
        ? $failure->getMessage() : 'Operazione non riuscita. Nessun calendario precedente è stato sostituito. Riprova più tardi.';
} finally {
    unset($email, $password);
    $_POST = [];
}
$csrf = $_SESSION['csrf'] ?? '';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$feed = isset($metadata['token']) && is_string($account) ? 'feed.php?id=' . rawurlencode($account) . '&token=' . rawurlencode($metadata['token']) : '';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GEOP | CalendarSync</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<main>
    <h1 class="page-logo"><img src="assets/images/geopcalsync_logo_transparent.png" alt="GEOP Calendar Sync" width="1024" height="1024"></h1>
    <p class="independent-note">Progetto indipendente da GEOP.</p>
    <p class="intro">GEOP Calendar Sync ti permette di importare il tuo calendario delle lezioni su Google Calendar o Apple Calendar</p>
    <p class="intro-more">Trovi più informazioni sotto.</p>

    <?php if ($error): ?>
        <p class="message error" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>
    <?php if ($notice): ?>
        <p class="message" role="status"><?= escape($notice) ?></p>
    <?php endif; ?>
    <?php if (!$secure): ?>
        <p class="message error">Connessione non protetta: il login richiede HTTPS.</p>
    <?php endif; ?>

    <?php if ($feed): ?>
        <section aria-labelledby="feed-title">
            <h2 id="feed-title">Calendario pronto</h2>
            <label for="feed-url">Link personale</label>
            <div class="copy-row">
                <input id="feed-url" data-absolute-url readonly value="<?= escape($feed) ?>">
                <button type="button" data-copy="feed-url">Copia</button>
            </div>
            <p class="note">Google Calendar: Altri calendari → + → Da URL. Poi puoi mostrarlo in Notion Calendar con lo stesso account Google.</p>
            <p class="note">Apple Calendar: Calendari → Aggiungi calendario con iscrizione. Su Mac: File → Nuova iscrizione a calendario.</p>
            <p class="note">Non condividere il link. Per aggiornare le lezioni, ripeti il login. Google può mostrare le modifiche con ritardo.</p>
        </section>
    <?php endif; ?>

    <form method="post" action="./" id="import-form">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
        <input type="hidden" name="action" value="import">
        <label class="field-label" for="email">Email istituzionale:</label>
        <input id="email" name="email" type="email" autocomplete="username" required maxlength="254">
        <label class="field-label" for="password">Password GEOP:</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="4096">
        <p class="info-row"><span class="info-icon" aria-hidden="true">i</span><span>Importiamo l’anno formativo <strong><?= escape($yearLabel) ?></strong>.</span></p>
        <p id="privacy" class="note privacy-note">Usiamo email e password sul server solo per questa importazione, senza salvarle. Conserviamo il tuo calendario e il suo link.</p>
        <label class="consent">
            <input name="consent" type="checkbox" value="1" required>
            <span>Ho letto la nota e autorizzo il recupero delle mie lezioni.</span>
        </label>
        <button class="import-button" type="submit" <?= !$secure || !$csrf ? 'disabled' : '' ?>><?= $feed ? 'Aggiorna calendario' : 'Importa calendario' ?></button>
        <p class="note" id="form-status" role="status"></p>
    </form>

    <?php if ($feed): ?>
        <div class="account-actions">
            <form method="post" action="./">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Chiudi pannello</button>
            </form>
            <form method="post" action="./" data-confirm="Eliminare il calendario e disattivare il link? Le copie già scaricate non vengono eliminate.">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit">Elimina calendario e link</button>
            </form>
        </div>
    <?php endif; ?>

    <details>
        <summary>Altre informazioni</summary>
        <p class="note">L’anno formativo considerato va dal 1° settembre al 31 agosto. Recuperiamo le lezioni disponibili in questo periodo; per leggere nuove modifiche su GEOP basta ripetere il login.</p>
        <p class="note">Conserviamo titoli delle lezioni, orari, aula, identificativi tecnici e token del feed. Non conserviamo cookie GEOP. L’eliminazione del feed non cancella copie già scaricate da Google o Notion.</p>
        <p class="note">Puoi eseguire il progetto sul tuo dispositivo. Il file locale opzionale conserva le credenziali solo se scegli di compilarlo.</p>
        <p class="note">Per consultare il codice sorgente, visita la pagina GitHub del progetto dal logo in fondo alla pagina.</p>
    </details>
    <p id="copy-status" class="note" role="status"></p>
    <footer class="page-footer">
        <?php if ($source): ?>
            <a class="github-link" href="<?= escape($source) ?>" target="_blank" rel="noopener noreferrer" aria-label="Codice del progetto su GitHub"><img src="assets/images/github-mark.svg" alt="GitHub" width="28" height="28"></a>
        <?php endif; ?>
        <p>&copy; <?= $today->format('Y') ?> - Ratti Dennis</p>
    </footer>
</main>
</body>
</html>
