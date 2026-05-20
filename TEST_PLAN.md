# TEST PLAN — Escape Manager pre-switch Escape Navigator

> **Obiettivo**: validare punto per punto il sistema prima di sostituire Escape Navigator.
> **Durata stimata**: 4-6 ore di test guidati su staging + 1 settimana di test silenzioso in parallelo.
> **Risultato atteso**: tutti i check ✅ verdi prima del giorno X (switchover).

**Convenzioni**:
- ✅ = test obbligatorio (blocking)
- ⚠️ = test consigliato (nice to have)
- 🐛 = se fallisce, è un bug bloccante

---

## FASE 0 — Prerequisiti ambiente

| # | Check | Come verificare | Esito |
|---|---|---|---|
| 0.1 ✅ | WordPress staging installato | URL `https://staging.tuosito.it` accessibile | ☐ |
| 0.2 ✅ | PHP 8.1+ attivo | `php -v` su SSH oppure WP admin → Tools → Site Health | ☐ |
| 0.3 ✅ | MySQL 5.7+ / MariaDB 10.3+ | Site Health → Info → Database | ☐ |
| 0.4 ✅ | Plugin Escape Manager installato e attivato | WP admin → Plugin → "Escape Manager" attivo | ☐ |
| 0.5 ✅ | Cron WP funzionante | Site Health → Info → Cron (no avvisi) | ☐ |
| 0.6 ✅ | wp_mail funzionante | Plugin "Check Email" → test invio | ☐ |
| 0.7 ✅ | Backup giornaliero attivo | UpdraftPlus / hosting backup attivo | ☐ |
| 0.8 ⚠️ | Sottoscacco STAGING separato per test | `https://sottoscacco-staging.app` o env var | ☐ |

---

## FASE 1 — Installazione e schema

| # | Check | Comando / Procedura | Risultato atteso | Esito |
|---|---|---|---|---|
| 1.1 ✅ | Tabelle DB create | `SHOW TABLES LIKE 'wp_em_%';` su MySQL | 21 tabelle (em_locations, em_rooms, …, em_settings, em_webhook_queue) | ☐ |
| 1.2 ✅ | DB version corretta | `SELECT option_value FROM wp_options WHERE option_name='em_db_version';` | `2` | ☐ |
| 1.3 ✅ | Ruoli WP custom presenti | `wp role list --format=json` (WP-CLI) oppure Diagnostica | 6 ruoli `em_*` | ☐ |
| 1.4 ✅ | Permessi seed in em_permissions | `SELECT COUNT(*) FROM wp_em_permissions;` | `114` (6 ruoli × 19 capability) | ☐ |
| 1.5 ✅ | Capability su admin WP | Diagnostica → utente admin → tutte ✅ | 19/19 verdi | ☐ |
| 1.6 ✅ | Diagnostica senza warning | `/wp-admin/admin.php?page=escape-manager-diagnostics` | Nessun avviso rosso | ☐ |

---

## FASE 2 — REST API base (senza UI)

Esegui via `curl` o Postman. Per gli endpoint autenticati, ottieni il nonce loggandoti come admin in WP e copiando da `wpApiSettings.nonce` in console browser.

| # | Endpoint | Comando | Risposta attesa | Esito |
|---|---|---|---|---|
| 2.1 ✅ | `GET /rooms` (vuoto) | `curl http://staging/wp-json/escape-manager/v1/rooms` | `{"data":[]}` | ☐ |
| 2.2 ✅ | `POST /locations` (admin) | con cookie + nonce, body `{"name":"Test","city":"Milano"}` | 201 con `data.id` | ☐ |
| 2.3 ✅ | `POST /rooms` (admin) | body `{"name":"La Cripta","slug":"la-cripta","location_id":1,"duration_minutes":60,"min_players":2,"max_players":6}` | 201 | ☐ |
| 2.4 ✅ | `POST /rooms/1/time-slots` | body con `slots: [{day_of_week:1,start_time:"15:00:00",end_time:"16:00:00"}]` | 200 con array slot | ☐ |
| 2.5 ✅ | `GET /availability?date=<lunedì-futuro>` | (pubblico) | Array con stanza La Cripta, slot 15:00 status `available` | ☐ |
| 2.6 ✅ | `POST /temporary-lock` | body `{"room_id":1,"start_datetime":"2026-06-01T15:00:00+02:00","session_id":"test-uuid"}` | 201 con `lock_id`, `expires_at`, `ttl_seconds` | ☐ |
| 2.7 ✅ | Re-POST stesso slot da altro session | body con `session_id` diverso | 409 `SLOT_UNAVAILABLE` | ☐ |
| 2.8 ✅ | `DELETE /temporary-lock/<id>` | con stesso `session_id` query param | 204 No Content | ☐ |
| 2.9 ✅ | Disponibilità refresh | dopo delete, `GET /availability` | Slot torna `available` | ☐ |
| 2.10 ⚠️ | Permessi enforcement | `GET /bookings` senza login | 401 | ☐ |
| 2.11 ✅ | Lock scaduto cleanup | inserisci lock con `expires_at` nel passato, attendi 60s cron | Lock rimosso dalla tabella | ☐ |

---

## FASE 3 — Flusso booking pubblico end-to-end

Crea una pagina WP `/test-booking` con shortcode `[escape_booking]`. Apri in browser pulito (incognito).

| # | Step | Azione utente | Risultato atteso | Esito |
|---|---|---|---|---|
| 3.1 ✅ | Caricamento widget | Visita pagina | Stepper visibile, step 1 attivo, no errori console | ☐ |
| 3.2 ✅ | Selezione data | Cambia data dal dropdown | Slot aggiornati per la nuova data | ☐ |
| 3.3 ✅ | Slot disponibile | Click su uno slot `available` | Lock creato, passa a step 2, countdown visibile (es. 10:00) | ☐ |
| 3.4 ✅ | Slot indisponibile | Stesso slot in altra finestra/incognito → click | Errore "Slot non più disponibile" | ☐ |
| 3.5 ✅ | Partecipanti | Imposta 3 adulti | Bottone "Continua" abilitato | ☐ |
| 3.6 ✅ | Validazione min/max | Imposta 1 adulto (sotto min) | Bottone disabilitato + messaggio errore | ☐ |
| 3.7 ✅ | Form cliente | Compila nome, telefono, email | Bottone "Continua" abilitato | ☐ |
| 3.8 ✅ | Validazione email | Email malformata | Errore inline + bottone disabilitato | ☐ |
| 3.9 ✅ | Riepilogo | Step 4: dati corretti visualizzati | Codice prenotazione NON ancora generato | ☐ |
| 3.10 ✅ | Conferma | Spunta "accetto", click "Conferma" | Step 5: codice prenotazione mostrato, messaggio successo | ☐ |
| 3.11 ✅ | Booking creato in DB | `SELECT * FROM wp_em_bookings ORDER BY id DESC LIMIT 1;` | Riga con `booking_status='confirmed'`, `source='public'` | ☐ |
| 3.12 ✅ | Lock rilasciato | `SELECT COUNT(*) FROM wp_em_temporary_locks;` | Lock di test eliminato | ☐ |
| 3.13 ✅ | Cliente salvato | `SELECT * FROM wp_em_customers WHERE phone='<phone-test>';` | Riga presente | ☐ |
| 3.14 ✅ | Email conferma ricevuta | Controlla inbox dell'email del test | Email con codice prenotazione | ☐ |
| 3.15 ✅ | Lock scaduto durante checkout | Inizia flusso, aspetta 10+ min senza completare | Modale "Tempo scaduto" + reset al step 1 | ☐ |

---

## FASE 4 — CRM

Login come WP admin. Vai a Escape Manager.

| # | Funzionalità | Azione | Risultato atteso | Esito |
|---|---|---|---|---|
| 4.1 ✅ | Caricamento CRM | Apri menu Escape Manager | Sidebar + topbar con nome utente, no errori console | ☐ |
| 4.2 ✅ | Calendario giorno | Click "Calendario" | Colonne per stanza, prenotazione test visibile (orario, nome, importo) | ☐ |
| 4.3 ✅ | Navigazione data | Click ◀ / ▶ / Oggi | Calendario si aggiorna | ☐ |
| 4.4 ✅ | Refresh auto | Resta sulla pagina 30s | Calendario refresh automatico | ☐ |
| 4.5 ✅ | Apertura drawer | Click su una booking card | Drawer si apre con dettagli, cliente, totale | ☐ |
| 4.6 ✅ | Confermare booking | Booking in stato `confirmed` non mostra bottone Conferma; se in stato `awaiting_payment`, mostra bottone | Vedi azione contestuale corretta | ☐ |
| 4.7 ✅ | Annullare booking | Click "Annulla" → motivo → conferma | Status diventa `cancelled` nel drawer + table | ☐ |
| 4.8 ✅ | Aggiungere pagamento | Click "+ Pagamento" → importo 60€ → metodo `on_site` | `paid_amount` aggiornato, `payment_status='paid'` | ☐ |
| 4.9 ✅ | Aggiungere nota | Click "+ Nota" → testo | Nota salvata in `wp_em_notes` | ☐ |
| 4.10 ✅ | Lista prenotazioni | Menu "Prenotazioni" | Tabella con tutte le booking, filtri data/stato | ☐ |
| 4.11 ✅ | Filtro stato | Seleziona "Confermato" | Solo confermate visibili | ☐ |
| 4.12 ✅ | Click riga apre drawer | Click su riga tabella | Stesso drawer di calendario | ☐ |
| 4.13 ✅ | Clienti | Menu "Clienti" | Lista clienti con count prenotazioni | ☐ |
| 4.14 ✅ | Ricerca cliente | Digita nome / telefono | Risultati filtrati (con debounce 300ms) | ☐ |
| 4.15 ✅ | Stanze admin | Menu "Stanze" → "+ Nuova stanza" | Modale aperto, salva nuova stanza | ☐ |
| 4.16 ✅ | Modifica stanza | Click "Modifica" su una stanza | Modale popolato, salva modifiche | ☐ |
| 4.17 ✅ | Tariffe | Menu "Tariffe" → crea tariffa fissa 60€ per 2-4 giocatori | Salvata, visibile in lista | ☐ |
| 4.18 ✅ | Settings | Menu "Impostazioni" → cambia TTL lock → salva | Modifica persistita (verifica con refresh) | ☐ |
| 4.19 ⚠️ | Idle timer | Resta inattivo 15+ min sulla pagina | Modale (se confermi mantiene; se cancelli reload) | ☐ |
| 4.20 ✅ | Permessi UI nascosta | Crea utente con ruolo `em_staff` → login → CRM | Menu "Stanze", "Settings", "Tariffe" non visibili | ☐ |
| 4.21 ✅ | Permessi backend bloccato | Stesso utente staff: `POST /rooms` via API | 403 Forbidden | ☐ |

---

## FASE 5 — Booking manuale (CRM telefonico)

> **Limitazione MVP 1**: la UI per creare manualmente un booking dal calendario non è implementata. Usa REST `POST /bookings` direttamente con curl/Postman, oppure attendi MVP 2.

| # | Check | Esito |
|---|---|---|
| 5.1 ✅ | `POST /bookings` con `room_id`, `start_datetime`, `adults`, `children`, `customer:{first_name,phone}` | ☐ |
| 5.2 ✅ | Booking creato con `source='manual'`, `booking_status='confirmed'` | ☐ |
| 5.3 ✅ | Cliente nuovo creato automaticamente | ☐ |
| 5.4 ✅ | Booking compare in calendario CRM | ☐ |

---

## FASE 6 — Concorrenza e race conditions

| # | Test | Procedura | Risultato atteso | Esito |
|---|---|---|---|---|
| 6.1 ✅ | Doppio lock stesso slot | Apri 2 browser incognito, click sullo stesso slot quasi contemporaneamente | UNO solo ottiene il lock (201), l'altro 409 | ☐ |
| 6.2 ✅ | Booking simultaneo da 2 lock diversi (slot diversi) | 2 lock acquisiti, conferma entrambi | Entrambi i booking creati | ☐ |
| 6.3 ✅ | Double-click bottone "Conferma" | Click rapido 2x sullo stesso bottone | Una sola prenotazione (no duplicato) — booking_code unico | ☐ |
| 6.4 ⚠️ | Stress test | Loop 50 richieste `POST /availability` in parallelo | Tutte rispondono < 2s, no errore 500 | ☐ |

---

## FASE 7 — Bridge Sottoscacco (test silenzioso in parallelo)

### 7.1 Configurazione iniziale

| # | Check | Esito |
|---|---|---|
| 7.1.1 ✅ | URL webhook configurato in CRM → Bridge | ☐ |
| 7.1.2 ✅ | Secret webhook impostato (stesso di Sottoscacco) | ☐ |
| 7.1.3 ✅ | Prefisso `external_id` impostato a `em-staging-` (per non collidere con vecchi booking EN reali) | ☐ |
| 7.1.4 ✅ | Test connessione → HTTP 2xx | ☐ |
| 7.1.5 ✅ | Bridge **NON ANCORA ATTIVATO** in checkbox "Stato bridge" | ☐ |

### 7.2 Allineamento slug stanze (CRITICO)

Confronta gli slug:

| Stanza in EM | Slug EM | Slug Firestore Sottoscacco | Match? |
|---|---|---|---|
| Stanza 1 | _____ | _____ | ☐ |
| Stanza 2 | _____ | _____ | ☐ |
| Stanza 3 | _____ | _____ | ☐ |
| Stanza 4 | _____ | _____ | ☐ |

Slug Firestore: query collection `rooms` su console Firebase oppure (se hai accesso) WP-CLI Firebase admin.

⚠️ **Se anche UNO solo non combacia, il bridge crea booking orfani in Sottoscacco.**

### 7.3 Test webhook drop-in

**Pre-requisito**: punto 7.1 OK, slug allineati (7.2), bridge attivato → "Stato bridge" ON, salva.

| # | Step | Azione | Risultato atteso | Esito |
|---|---|---|---|---|
| 7.3.1 ✅ | Crea booking confermato da EM (pubblico o manual) | Booking → `confirmed` | Riga in `wp_em_webhook_queue` con `event_type='new-order'`, `status='pending'` | ☐ |
| 7.3.2 ✅ | Forza dispatch | CRM → Bridge → "Esegui dispatch ora" | Riga diventa `status='sent'`, `sent_at` valorizzato | ☐ |
| 7.3.3 ✅ | Sottoscacco riceve | Apri Firestore `bookings` | Nuova riga con `externalBookingId='em-staging-<id>'`, `externalSource='escape_navigator'` | ☐ |
| 7.3.4 ✅ | RoomId risolto in Sottoscacco | Stessa riga ha `roomId` valorizzato (NON null) | Slug match funziona | ☐ |
| 7.3.5 ✅ | Check-in Sottoscacco funziona | Da dashboard staff Sottoscacco → check-in del booking | Sessione creata regolarmente, partecipanti accettati | ☐ |
| 7.3.6 ✅ | Modifica booking → update-order | CRM EM → modifica `start_datetime` di un confirmed | Nuovo webhook `update-order` enqueued; Sottoscacco aggiorna `scheduledAt` | ☐ |
| 7.3.7 ✅ | Cancel booking → cancel-order | CRM EM → annulla booking confermato | Webhook `cancel-order` → Sottoscacco mette status `cancelled` | ☐ |
| 7.3.8 ✅ | Retry su errore 5xx | Tempora-spegni Sottoscacco / cambia URL invalido per test | Webhook va in retry esponenziale (2, 4, 8 min…) | ☐ |
| 7.3.9 ✅ | Mark failed dopo max_retries | Tieni URL invalido fino al raggiungimento di 5 tentativi | Webhook `status='failed'` | ☐ |
| 7.3.10 ✅ | Replay failed | Ripristina URL corretto → click "Replay falliti" | Webhook tornano `pending` e vengono inviati | ☐ |

### 7.4 Test silenzioso 1 settimana (parallelo)

Per **almeno 7 giorni** prima dello switch:
- ✅ Mantieni **EN attivo e Sorgente di verità** per i clienti reali.
- ✅ Bridge EM in **staging** (prefix `em-staging-`).
- ✅ Crea **2-3 booking di test** al giorno via EM staging → verifica arrivino a Sottoscacco senza problemi.
- ✅ Monitora la queue: zero failed.

| # | Giorno | Webhook sent | Webhook failed | Note | Esito |
|---|---|---|---|---|---|
| 7.4.1 | Lun | _____ | _____ | _____ | ☐ |
| 7.4.2 | Mar | _____ | _____ | _____ | ☐ |
| 7.4.3 | Mer | _____ | _____ | _____ | ☐ |
| 7.4.4 | Gio | _____ | _____ | _____ | ☐ |
| 7.4.5 | Ven | _____ | _____ | _____ | ☐ |
| 7.4.6 | Sab | _____ | _____ | _____ | ☐ |
| 7.4.7 | Dom | _____ | _____ | _____ | ☐ |

**Criterio di successo:** 7 giorni consecutivi con 0 webhook failed e 100% match in Sottoscacco.

---

## FASE 8 — Email transazionali

| # | Check | Esito |
|---|---|---|
| 8.1 ✅ | Email conferma arriva al cliente alla creazione booking confermato (pubblico) | ☐ |
| 8.2 ✅ | Email conferma ha codice prenotazione, stanza, data, ora, totale | ☐ |
| 8.3 ✅ | Email cancellazione arriva alla transizione → `cancelled` | ☐ |
| 8.4 ⚠️ | From name/address rispetta Settings → Email | ☐ |
| 8.5 ⚠️ | Email rendering corretto su Gmail / Outlook / iPhone Mail | ☐ |
| 8.6 ⚠️ | Footer email non finisce in spam | Test con [mail-tester.com](https://www.mail-tester.com/) | ☐ |

---

## FASE 9 — Sicurezza

| # | Test | Procedura | Esito |
|---|---|---|---|
| 9.1 ✅ | SQL injection bookings | `GET /bookings?search=' OR 1=1--` con auth | Nessun crash, no leak, query valida | ☐ |
| 9.2 ✅ | XSS payload in customer name | Crea booking con nome `<script>alert(1)</script>` | Output escaped in CRM, no script eseguito | ☐ |
| 9.3 ✅ | Permessi REST: utente staff non manage_settings | `PUT /settings` da staff | 403 | ☐ |
| 9.4 ✅ | Permessi REST: utente non loggato non vede /bookings | `GET /bookings` no cookie | 401 | ☐ |
| 9.5 ✅ | Booking pubblico (`/bookings/public/{code}`) non rivela PII | Risposta NON contiene email/phone full | ☐ |
| 9.6 ✅ | Webhook secret non esposto in `GET /settings` | Valore appare come `••• configurato •••` | ☐ |
| 9.7 ✅ | Rate limit lock pubblici | 11+ richieste `POST /temporary-lock` in 1 min da stesso IP/session | Almeno una bloccata (TOO_MANY_LOCKS o no-overlap-self) | ☐ |
| 9.8 ⚠️ | HTTPS forzato in produzione | Browser carica solo https://, no contenuti misti | ☐ |
| 9.9 ⚠️ | Plugin sec esterno installato (Wordfence/Limit Login) | Plugin attivo | ☐ |

---

## FASE 10 — Performance e affidabilità

| # | Test | Strumento | Soglia | Esito |
|---|---|---|---|---|
| 10.1 ✅ | Tempo risposta `GET /availability` | DevTools Network | < 800ms | ☐ |
| 10.2 ✅ | Tempo risposta `GET /calendar` | DevTools Network | < 1.2s con 20+ bookings | ☐ |
| 10.3 ✅ | Bundle JS booking app | DevTools Network → booking-app.js | < 25KB (codice) + CDN cache hit dopo prima visita | ☐ |
| 10.4 ⚠️ | Bundle JS CRM app | < 60KB | ☐ |
| 10.5 ⚠️ | Lighthouse pagina booking | Performance score > 75 | ☐ |
| 10.6 ✅ | Cron WP gira | Da admin, controlla "next scheduled" di `em_cron_lock_cleanup` < 2 min | ☐ |

---

## FASE 11 — Pre-switchover (giorno X-1)

| # | Check | Esito |
|---|---|---|
| 11.1 ✅ | Tutte le fasi 1-10 verdi | ☐ |
| 11.2 ✅ | Backup completo DB + filesystem WP eseguito e **verificato** (restore test su altro ambiente) | ☐ |
| 11.3 ✅ | Backup completo dati Escape Navigator (export CSV / dump) | ☐ |
| 11.4 ✅ | Documentazione operatori aggiornata (cosa cambia per loro) | ☐ |
| 11.5 ✅ | Numero di telefono/email assistenza disponibile per emergenze | ☐ |
| 11.6 ✅ | Slug stanze EM == slug stanze Sottoscacco (riconferma) | ☐ |
| 11.7 ✅ | Bridge prefix cambiato da `em-staging-` a `em-` (produzione) | ☐ |
| 11.8 ✅ | URL webhook punta a Sottoscacco di PRODUZIONE (non staging) | ☐ |
| 11.9 ✅ | Tariffe configurate e verificate per ogni stanza | ☐ |
| 11.10 ✅ | Email mittente configurata e testata | ☐ |
| 11.11 ⚠️ | Comunicazione clienti pre-switch inviata (social, mail) | ☐ |

---

## FASE 12 — Switchover (giorno X)

Esegui in ordine. Annota orario di ogni step.

| # | Step | Orario | Esito |
|---|---|---|---|
| 12.1 ✅ | Disattiva EN: blocca creazione nuovi booking dal pannello EN | ___ | ☐ |
| 12.2 ✅ | Su sito WP: rimuovi vecchio widget EN | ___ | ☐ |
| 12.3 ✅ | Su sito WP: inserisci shortcode `[escape_booking]` | ___ | ☐ |
| 12.4 ✅ | Verifica frontend: widget EM si carica correttamente | ___ | ☐ |
| 12.5 ✅ | Su CRM EM → Bridge → "Stato bridge" → ON, salva | ___ | ☐ |
| 12.6 ✅ | Test booking REALE end-to-end (operatore esegue prenotazione test) | ___ | ☐ |
| 12.7 ✅ | Verifica arrivo in Sottoscacco | ___ | ☐ |
| 12.8 ✅ | Monitor queue webhook prime 2 ore | ___ | ☐ |

---

## FASE 13 — Post-switchover (giorno X → X+7)

| # | Check | Frequenza | Esito |
|---|---|---|---|
| 13.1 ✅ | Monitor coda webhook 0 failed | Ogni 4h primo giorno, poi 1/giorno | ☐ |
| 13.2 ✅ | Monitor logs WP `wp-content/debug.log` | 1/giorno | ☐ |
| 13.3 ✅ | Verifica email conferma effettivamente recapitate | Spot check 5 booking/giorno | ☐ |
| 13.4 ✅ | Feedback operatori in sala (qualunque anomalia) | 1/giorno | ☐ |
| 13.5 ✅ | Verifica Sottoscacco continua a funzionare normalmente | 1/giorno | ☐ |

### Criterio di "switch riuscito"
- 7 giorni consecutivi senza incidenti, senza booking persi, senza webhook failed.
- Almeno 20 booking creati e processati correttamente end-to-end.

### Rollback plan (se va male)
1. Su sito WP: sostituisci shortcode `[escape_booking]` con vecchio widget EN (file/snippet preservato).
2. Su CRM EM → Bridge → "Stato bridge" → OFF, salva.
3. Su EN: riattiva creazione booking.
4. Eventuali booking creati in EM nel periodo intermedio → export manuale + reinserimento EN.
5. **Tempo rollback realistico**: < 15 minuti.

---

## FASE 14 — Cessazione Escape Navigator (mese X+1 → X+2)

| # | Step | Esito |
|---|---|---|
| 14.1 ✅ | Export finale completo dati EN (booking storici, clienti) | ☐ |
| 14.2 ✅ | Cancellazione abbonamento EN | ☐ |
| 14.3 ⚠️ | Eventuale import storico in EM via CSV (per statistiche storiche) | ☐ |
| 14.4 ✅ | Rimozione asset/script EN residui dal sito WP | ☐ |

---

## Appendice: Comandi utili durante i test

### Forza esecuzione cron
```powershell
wp cron event run em_cron_lock_cleanup
wp cron event run em_cron_webhook_dispatcher
```

### Reset rapido per test ripetuti (ATTENZIONE: cancella tutto!)
```sql
DELETE FROM wp_em_bookings;
DELETE FROM wp_em_booking_participants;
DELETE FROM wp_em_temporary_locks;
DELETE FROM wp_em_payments;
DELETE FROM wp_em_webhook_queue;
DELETE FROM wp_em_activity_logs;
UPDATE wp_em_customers SET total_bookings = 0, last_booking_date = NULL;
```

### Tail log WP in tempo reale
```powershell
Get-Content "C:\path\wp-content\debug.log" -Wait -Tail 50
```

### Query bookings recenti
```sql
SELECT id, booking_code, room_id, start_datetime, booking_status, payment_status, created_at
FROM wp_em_bookings
ORDER BY created_at DESC
LIMIT 20;
```

### Query coda webhook
```sql
SELECT id, event_type, status, attempts, last_error, next_attempt_at, created_at
FROM wp_em_webhook_queue
ORDER BY id DESC LIMIT 50;
```

---

## Riassunto: GO/NO-GO checklist

Per il GO-LIVE sostituzione di Escape Navigator:

- [ ] Fase 1 (installazione) tutta verde
- [ ] Fase 2 (REST API) tutta verde
- [ ] Fase 3 (booking pubblico) tutta verde
- [ ] Fase 4 (CRM) tutta verde
- [ ] Fase 6 (concorrenza) tutta verde
- [ ] Fase 7 (bridge Sottoscacco) tutta verde + 7 giorni test silenzioso OK
- [ ] Fase 8 (email) almeno 8.1-8.4 verdi
- [ ] Fase 9 (sicurezza) tutta verde
- [ ] Fase 11 (pre-switchover) tutta verde

**Se anche UNO dei punti ✅ non è verde, NON fare lo switchover.** Fix prima.

---

*Documento di test redatto secondo PROGETTO.md v1.1. Versione test plan 1.0 — 2026-05-18.*
