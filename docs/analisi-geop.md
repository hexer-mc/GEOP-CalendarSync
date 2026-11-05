# Protocollo GEOP osservato

## Ambito

Portale configurato: `https://itsar.registrodiclasse.it/geopcfp2/`, contesto ente 3. L'host è fisso per evitare destinazioni arbitrarie. Non sono documentati endpoint inventati o accessi ad altri utenti. I dati di esempio nei test sono fittizi.

## Login

1. GET alla pagina iniziale con `idEnte` per aprire il contesto dell'ente.
2. POST a `update/login.asp?1=1&ajax_target=DIVHidden&ajax_tipotarget=login`, corpo form-urlencoded con `username` e `password`.
3. Il login osservato risponde HTTP 200 con un input nascosto `LoadScriptDIVHidden` che contiene `HomePageLoad();`.
4. Il client riconosce il segnale senza eseguire JavaScript e verifica poi lo schema della risposta eventi. Il solo HTTP 200 non prova il successo del login.

Il flusso email/password e il recupero eventi sono stati verificati dal diagnostico locale con un account autorizzato. Il client mantiene cookie soltanto in memoria, non segue redirect e non ripete login falliti.

## Identificazione del calendario

Il codice pubblico `javascript/framework.js` mostra che `HomePageLoad()` richiama la home tramite `ajax/page/home.asp?1=1&ajax_target=DIVContenuto&ajax_tipotarget=home`.

Il client cerca nella home autenticata un unico elemento `.fullcalendar` con `data-oggetto="idAlunno"` e `data-id-oggetto` numerico. Questi attributi sono quelli letti dal codice pubblico di configurazione FullCalendar. Il modulo web non accetta un ID alunno scelto dal visitatore. Una home non riconosciuta o ambigua interrompe l'operazione.

Il riconoscimento è coperto da fixture ma deve ancora essere verificato sul pannello reale e con account autorizzati di corsi diversi. La CLI personale consente l'ID esplicito per riprodurre la richiesta già verificata del proprio account.

## Lettura eventi

GET osservata: `json/fullcalendar_events_alunno.asp`.

| Parametro | Uso osservato |
| --- | --- |
| `Oggetto` | `idAlunno` |
| `idOggetto` | Contesto alunno autorizzato |
| `editable` | `false`, opzione UI, non garanzia di autorizzazione |
| `start`, `end` | Date `YYYY-MM-DD` |
| `z`, `_` | Valori numerici di anti-cache; non è stata verificata la possibilità di ometterli |

Il corpo è un array JSON. Campi usati: `id` numerico, `title`, `start`, `end`, eventuale `tooltip` per la riga `Aula:`. Colori e opzioni dell'interfaccia vengono ignorati. Il titolo viene ripulito dall'HTML senza dividerlo sui trattini; il tooltip non viene esportato interamente.

Le date osservate hanno formato locale `YYYY-MM-DDTHH:mm:ss` senza offset. Gli orari coincidono con quelli italiani del registro: viene usato `Europe/Rome`, poi convertito in UTC per ICS. Date inesistenti o ambigue al cambio ora sono rifiutate.

La lettura è suddivisa in settimane, nella stessa sessione. Un errore interrompe tutto il recupero. Eventi identici presenti in più segmenti vengono deduplicati; versioni discordanti dello stesso ID interrompono l'importazione.

## Pubblicazione e persistenza

Web e CLI usano lo stesso archivio di feed personali. L'identificativo locale deriva tramite HMAC dal contesto GEOP; il token di lettura è casuale. Gli aggiornamenti mantengono lo stesso link e gli UID delle lezioni. Il timestamp cambia soltanto quando cambiano i dati esportati, perché GEOP non fornisce un campo di modifica nel campione osservato.

ICS e metadati sono in un unico snapshot privato, scritto con rinomina atomica. Un nuovo intervallo sostituisce il precedente. Una risposta non vuota è trattata come snapshot completo; una finestra completamente vuota è rifiutata finché non è verificato il comportamento delle cancellazioni totali.

L'importazione è manuale, senza conservare credenziali o sessioni GEOP nel portale. L'eventuale file locale CLI è una scelta separata e volontaria di conservazione sul proprio dispositivo.

## Limiti da verificare

- Riconoscimento della home e più corsi sul pannello reale.
- Cancellazioni totali, confini dell'intervallo e possibili limiti/paginazione server.
- Stabilità dell'ID sorgente dopo riprogrammazione e scadenza effettiva della sessione.
- Configurazione HTTPS, proxy e log dell'installazione di produzione.

Non modificare lezioni o provare ID di terzi per eseguire queste verifiche.

## Riferimenti

- [RFC 5545, iCalendar](https://www.rfc-editor.org/rfc/rfc5545.html)
- [Google Calendar, aggiunta da URL](https://support.google.com/calendar/answer/37100?hl=it)
- [Notion Calendar, gestione dei calendari](https://www.notion.com/help/manage-your-calendars-and-events)
