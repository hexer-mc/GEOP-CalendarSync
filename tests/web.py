"""Prova HTTP con GEOP simulato in una copia temporanea, senza credenziali reali."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parent.parent
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


with tempfile.TemporaryDirectory(prefix="geofetch-http-") as temporary:
    work = Path(temporary)
    for folder in ("src", "public", "tests", "bin"):
        shutil.copytree(root / folder, work / folder)
    (work / "config").mkdir()
    (work / "config/local.php").write_text("<?php throw new RuntimeException('LOCAL_CONFIG_MUST_NOT_LOAD');", encoding="utf-8")
    (work / "var").mkdir()
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    environment = os.environ.copy()
    environment.pop("GEOFETCH_ALLOW_HTTP", None)
    server = subprocess.Popen(
        ["php", "-d", "extension=curl", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/web-router.php"],
        cwd=work, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    base = f"http://127.0.0.1:{port}"
    jars = [http.cookiejar.CookieJar(), http.cookiejar.CookieJar()]
    browsers = [urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect()) for jar in jars]

    def request(path="/", data=None, browser=0):
        encoded = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = browsers[browser].open(urllib.request.Request(base + path, encoded), timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, response.headers, response.read().decode()

    def csrf(browser=0):
        code, headers, html = request(browser=browser)
        check(code == 200, "Pagina disponibile")
        return re.search(r'name="csrf" value="([a-f0-9]{64})"', html)[1]

    def form(token, email="first@example.invalid"):
        return dict(csrf=token, action="import", consent="1", email=email, password="FAKE_PRIVATE_PASSWORD_123",
                    start="2026-11-09", end="2026-11-16")

    try:
        for _ in range(50):
            try:
                request()
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError("Il server PHP di test non parte")
        secure_token = csrf()
        check('Connessione non protetta' in request()[2], 'Login HTTP disabilitato per impostazione predefinita')
        check(request(data=form(secure_token))[0] == 400, 'POST senza HTTPS rifiutato')
        server.terminate()
        server.wait(timeout=5)
        environment['GEOFETCH_ALLOW_HTTP'] = '1'
        server = subprocess.Popen(
            ["php", "-d", "extension=curl", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/web-router.php"],
            cwd=work, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(50):
            try:
                request()
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError('Riavvio server locale fallito')
        token = csrf()
        code, headers, html = request()
        check("default-src 'self'" in headers["Content-Security-Policy"], "CSP presente")
        check("no-store" in headers["Cache-Control"], "Cache esclusa")
        check("HttpOnly" in str(jars[0]._cookies), "Cookie HttpOnly")
        check("LOCAL_CONFIG_MUST_NOT_LOAD" not in html, "Configurazione privata non caricata")
        check('https://github.com/hexer-mc/GEOP-CalendarSync' in html, "Repository corretto in pagina")
        check(request(data=form("bad"))[0] == 403, "CSRF errato rifiutato")
        rejected = form(token)
        rejected.pop("consent")
        check("conferma" in request(data=rejected)[2], "Consenso richiesto")
        failed = request(data=form(token, "denied@example.invalid"))
        check("Login non confermato" in failed[2], "Login fallito gestito")
        check("FAKE_PRIVATE_PASSWORD_123" not in failed[2] and "denied@example.invalid" not in failed[2], "Credenziali non riflesse")
        check(not list((work / "var/web").glob("*/calendar.json")), "Login fallito non crea feed")
        check(request(data=form(token))[0] == 303, "Importazione riuscita con redirect pulito")
        _, _, dashboard = request()
        check('type="password"' in dashboard and 'value="FAKE_PRIVATE' not in dashboard, "Password non ripopolata")
        feed = re.search(r'id="feed-url"[^>]*value="([^"]+)"', dashboard)[1].replace("&amp;", "&")
        check(request("/" + feed)[0] == 200, "Feed pubblico con token")
        check(request("/" + feed)[1]["Content-Type"] == "text/calendar; charset=utf-8", "Formato ICS")
        check(request("/" + feed.replace("token=", "token=x"))[0] == 404, "Token errato rifiutato")
        check(request('/feed.php?token=' + 'a' * 64)[0] == 404, 'Vecchio feed senza identificativo rimosso')
        check(request("/feed.php?id=../../config/local.php&token=" + "a" * 64)[0] == 404, "Traversal rifiutato")
        check("feed-url" not in request(browser=1)[2], "Browser anonimo non vede il pannello altrui")
        token2 = csrf(1)
        check(request(data=form(token2, "second@example.invalid"), browser=1)[0] == 303, "Secondo utente importato")
        second_dashboard = request(browser=1)[2]
        feed2 = re.search(r'id="feed-url"[^>]*value="([^"]+)"', second_dashboard)[1].replace("&amp;", "&")
        check(feed != feed2, "Link separati per utente")
        query1 = urllib.parse.parse_qs(urllib.parse.urlparse(feed).query)
        query2 = urllib.parse.parse_qs(urllib.parse.urlparse(feed2).query)
        mixed = "/feed.php?" + urllib.parse.urlencode(dict(id=query2["id"][0], token=query1["token"][0]))
        check(request(mixed)[0] == 404, "Token di A non legge B")
        check(request(data=dict(action="delete", csrf=csrf()))[0] == 303, "Eliminazione autenticata")
        check(request("/" + feed)[0] == 404, "Link eliminato revocato")
        check(request("/" + feed2)[0] == 200, "Altro utente preservato")
        for stored in (work / "var/web").rglob("*"):
            if stored.is_file():
                contents = stored.read_bytes()
                check(b"FAKE_PRIVATE_PASSWORD_123" not in contents and b"@example.invalid" not in contents, "Nessuna credenziale nei file o sessioni")
        environment_check = subprocess.run(["php", "-d", "extension=curl", "bin/probe.php", "2026-11-09", "2026-11-16"], cwd=work, capture_output=True)
        check(environment_check.returncode == 1, "Config locale riservata alla CLI")
        (work / 'config/local.php').write_text("<?php return ['email'=>'local@example.invalid','password'=>'LOCAL_ONLY_FAKE','entityId'=>3,'studentId'=>123];", encoding='utf-8')
        config_script = b'''<?php
$argc=3; $argv=['probe.php','2026-11-09','2026-11-16'];
$config=require 'bin/config.php';
if ($config['email'] !== 'local@example.invalid' || $config['studentId'] !== 123) { exit(1); }
putenv('GEOP_EMAIL=override@example.invalid');
$config=require 'bin/config.php';
if ($config['email'] !== 'override@example.invalid') { exit(1); }
'''
        config_environment = os.environ.copy()
        for key in ('GEOP_EMAIL', 'GEOP_PASSWORD', 'GEOP_ENTITY_ID', 'GEOP_STUDENT_ID'):
            config_environment.pop(key, None)
        loaded = subprocess.run(['php'], input=config_script, cwd=work, env=config_environment, capture_output=True)
        check(loaded.returncode == 0, 'Config locale CLI e precedenza ambiente verificate senza login')
        sync_script = b'''<?php
require 'tests/fixtures/curl-mock.php';
Geop\\Geop\\HttpFixture::reset([
    [200, 'login'],
    [200, '<input type="hidden" id="LoadScriptDIVHidden" value="HomePageLoad();">'],
    [200, file_get_contents('tests/fixtures/geop-events.json')],
]);
$argc=3; $argv=['sync.php','2026-11-09','2026-11-16'];
require 'bin/sync.php';
'''
        synced = subprocess.run(['php', '-d', 'extension=curl'], input=sync_script, cwd=work, env=config_environment, capture_output=True)
        check(synced.returncode == 0, 'CLI usa lo stesso archivio del portale')
        cli_feed = re.search(r'feed\.php\?id=[a-f0-9]{64}&token=[a-f0-9]{64}', synced.stdout.decode())
        check(cli_feed is not None and request('/' + cli_feed[0])[0] == 200, 'Feed CLI leggibile dal medesimo endpoint')
        again = subprocess.run(['php', '-d', 'extension=curl'], input=sync_script, cwd=work, env=config_environment, capture_output=True)
        check(again.returncode == 0 and cli_feed[0] in again.stdout.decode(), 'Aggiornamento CLI conserva il link')
        check(not (work / 'var/calendar.json').exists(), 'Nessuno snapshot legacy creato')
        print(f"OK: {checks} verifiche HTTP, GEOP simulato, nessuna richiesta esterna.")
    finally:
        server.terminate()
        server.wait(timeout=5)
