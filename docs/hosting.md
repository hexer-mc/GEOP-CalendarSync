# Hosting su mini PC o VPS: /geofetch/

Esempio per Linux con HTTPS già configurato per dencode.it. Non è un deploy eseguito. Adattare percorsi, utente e socket alla distribuzione.

## Installazione

1. PHP 8.3+ CLI/FPM, cURL, DOM e certificati CA aggiornati.
2. Codice in `/opt/geofetch`, di proprietà amministratore e non scrivibile dal servizio. Escludere credenziali locali, dati reali di `var/` e router di test dai pacchetti distribuiti.
3. Pool FPM con utente dedicato e permessi di scrittura soltanto a `var/` (0700). Lo stesso utente serve i feed, salvati con 0600.
4. Nessuna credenziale GEOP nell'ambiente del pool pubblico. Il file locale non serve al pannello. Non impostare `GEOFETCH_ALLOW_HTTP` in produzione.
5. Document root limitata a `public/`. Pubblicare la versione effettiva sul repository indicato.

## Esempio Nginx nel server HTTPS

Le location PHP vanno prima di eventuali regole PHP generiche. Verificare il socket sul server.

```nginx
location = /geofetch { return 301 /geofetch/; }
location = /geofetch/ { rewrite ^ /geofetch/index.php last; }

location ~ ^/geofetch/(?<geofetch_script>index|feed)\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /opt/geofetch/public/$geofetch_script.php;
    fastcgi_param SCRIPT_NAME /geofetch/$geofetch_script.php;
    fastcgi_param HTTPS on;
    fastcgi_pass unix:/run/php/geofetch.sock;
    fastcgi_read_timeout 180s;
    client_max_body_size 16k;
    client_body_buffer_size 16k;
    client_body_in_file_only off;
    fastcgi_request_buffering off;
    fastcgi_buffering off;
    access_log off;
}
location /geofetch/assets/ { alias /opt/geofetch/public/assets/; }
location /geofetch/ { return 404; }
```

Limite e buffering evitano che il corpo POST accettato dal form debba finire nei temporanei Nginx. Niente cache/CDN sulle risposte del pannello e del feed. Non usare il router simulato dei test.

Impostazioni del pool FPM dedicato, oltre a user/group e permessi del socket compatibili con Nginx:

```ini
listen = /run/php/geofetch.sock
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = off
php_admin_flag[file_uploads] = off
php_admin_value[post_max_size] = 16K
php_admin_value[max_execution_time] = 180
php_admin_value[zend.exception_ignore_args] = 1
env[GEOFETCH_SOURCE_URL] = https://github.com/hexer-mc/GEOP-CalendarSync
```

Se HTTPS termina su un proxy, configurare l'informazione TLS dal solo proxy fidato. L'app non considera fidato `X-Forwarded-Proto` fornito direttamente dal client.

## Verifica prima di invitare utenti

- HTTP deve reindirizzare a HTTPS prima del login; certificato valido e login non protetto bloccato.
- Proxy, WAF, APM, debug, PHP-FPM e sistemi di monitoraggio non devono registrare corpi POST, cookie, argomenti delle eccezioni o URL completi dei feed. La location senza access log non copre eventuali altri livelli, error log o servizi esterni.
- Evitare swap non protetto, core dump e catture della memoria dei processi di login. L'app non promette di impedire a un amministratore del server di osservare credenziali in uso.
- Provare con un proprio account il riconoscimento dalla home, più settimane, login successivo con stesso link e errore senza sostituzione del calendario precedente.
- Provare almeno due account alunno autorizzati di corsi diversi. Nessun test con ID di terzi. Home non riconosciute devono interrompere l'importazione.
- Verificare l'inaccessibilità di `config/`, `var/`, `src/`, `tests/` e `bin/` via web e verificare revoca del feed dopo eliminazione.
- Definire durata di conservazione e cancellazione dei calendari e dei backup. Non esiste ancora un processo automatico di pulizia.

Conservare `var/web/app.key` e gli snapshot privati durante gli aggiornamenti. Cambiare la chiave cambia gli identificativi dei calendari. Non è previsto un timer con password degli utenti: il recupero GEOP resta manuale.
