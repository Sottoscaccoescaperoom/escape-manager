# GUIDA OPERATIVA — Da zero a sistema in produzione

> Documento da seguire **punto per punto** dall'inizio alla fine.
> Tempo totale stimato: 6-12 ore distribuite in 2-3 settimane (con test silenzioso 7 giorni).
> Spunta ogni casella man mano che procedi. Non saltare passaggi.

---

## INDICE

- **PARTE 1** — Setup ambiente (1-2 ore)
- **PARTE 2** — Configurazione iniziale (1 ora)
- **PARTE 3** — Test funzionali (2-3 ore)
- **PARTE 4** — Test bridge Sottoscacco (1 ora + 7 giorni silenziosi)
- **PARTE 5** — Pre-switchover (30 min)
- **PARTE 6** — Switchover (1 ora)
- **PARTE 7** — Post-switchover (7 giorni monitoraggio)
- **PARTE 8** — Cessazione Escape Navigator
- **APPENDICI** — Troubleshooting + comandi utili

---

# PARTE 1 — Setup ambiente

## 1.1 Installa WordPress in locale

Hai bisogno di un'installazione WordPress dove testare prima di toccare il sito vero.

- [ ] **Scarica Local by Flywheel** da https://localwp.com (gratis, Windows compatibile).
- [ ] Installalo.
- [ ] Apri Local → **Create a new site** → nome `escape-manager-staging` → Custom preferences:
  - PHP version: **8.1** o superiore
  - Web server: nginx o Apache (qualsiasi)
  - MySQL: 8.0 (default)
- [ ] Crea utente admin (segnati username + password).
- [ ] Una volta installato, clicca **Open site** → si apre `http://escape-manager-staging.local`.
- [ ] Clicca **WP Admin** → ti loggi.

✅ Pronto se vedi la dashboard WordPress.

---

## 1.2 Installa il plugin Escape Manager

**Opzione consigliata — symlink (modifiche live)**:
- [ ] Apri PowerShell **come amministratore** (Start → tasto destro su PowerShell → "Esegui come amministratore").
- [ ] Trova il percorso della cartella plugins. In Local: tasto destro sul sito → **Show folder** → `app/public/wp-content/plugins/`. Copialo.
- [ ] Esegui (sostituisci il percorso col tuo):
  ```powershell
  New-Item -ItemType SymbolicLink `
    -Path "C:\Users\lucad\Local Sites\escape-manager-staging\app\public\wp-content\plugins\escape-manager" `
    -Target "c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager"
  ```
- [ ] WP admin → **Plugin** → trova "Escape Manager" → **Attiva**.
- [ ] Vedi un nuovo menu **Escape Manager** nella sidebar di WordPress.

**Opzione alternativa — zip upload**:
- [ ] PowerShell: `Compress-Archive -Path "c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager\*" -DestinationPath "$env:TEMP\escape-manager.zip"`
- [ ] WP admin → Plugin → Aggiungi nuovo → Carica plugin → seleziona zip → installa → attiva.

✅ Pronto se vedi il menu **Escape Manager**.

---

## 1.3 Verifica installazione

- [ ] WP admin → **Escape Manager → Diagnostica**:
  - DB Schema: deve essere `2`
  - Ruoli em_roles: `6`
  - Permessi em_permissions: `114`
  - Utente admin: tutte le 19 capability `em_*` con ✅
- [ ] Apri DevTools del browser (F12) → Console → nessun errore rosso.
- [ ] Vai a **Escape Manager → CRM** (voce principale): deve apparire la sidebar con "Calendario", "Prenotazioni", "Clienti", ecc.

🐛 Se vedi schermata bianca: vedi **APPENDICE A** (Troubleshooting CDN bloccato).

---

# PARTE 2 — Configurazione iniziale

## 2.1 Crea la location (sede)

- [ ] Apri TablePlus / phpMyAdmin sul DB del sito locale.
- [ ] Esegui:
  ```sql
  INSERT INTO wp_em_locations (name, address, city, postal_code, country, is_active, created_at, updated_at)
  VALUES ('Sede Principale', 'Indirizzo reale', 'Torino', '10100', 'Italia', 1, NOW(), NOW());
  ```
  (in MVP 2 verrà aggiunta UI per locations; per ora SQL).

- [ ] Verifica: `SELECT * FROM wp_em_locations;` → deve restituire una riga.

## 2.2 Crea le stanze

- [ ] Vai a **Escape Manager → Stanze**.
- [ ] Click **+ Nuova stanza** per ogni stanza reale che hai.
- [ ] Per ogni stanza compila:
  - **Nome**: es. "La Cripta"
  - **Slug**: ⚠️ CRITICO — deve essere **identico** allo slug della stessa stanza nel Firestore di Sottoscacco. Esempio: `la-cripta`, `il-laboratorio`.
  - **Location**: Sede Principale
  - **Durata**: minuti di gioco effettivo (es. 60)
  - **Min/Max giocatori**
  - **Foto URL**: link a immagine (puoi caricarla via WP Media Library e copiare l'URL)
  - **Descrizione**, **Info importanti** (facoltativi)
  - **Attiva**: Sì
- [ ] Salva.

### 2.2.1 ⚠️ Allineamento slug stanze EM ↔ Sottoscacco

Vai su Firebase Console → progetto Sottoscacco → Firestore → collection `rooms`. Per ogni stanza confronta il campo `slug`.

| Stanza reale | Slug Sottoscacco | Slug EM | Match? |
|---|---|---|---|
| ____ | ____ | ____ | ☐ |
| ____ | ____ | ____ | ☐ |

🐛 Se non combaciano: cambia lo slug in **Escape Manager → Stanze → Modifica** finché non coincidono. Lo slug Sottoscacco è quello che il bridge userà per matchare le stanze.

## 2.3 Configura gli orari (time slots) per ogni stanza

⚠️ MVP 2 non ha ancora UI per gli orari. Usa SQL diretto.

- [ ] Per ogni stanza, esegui SQL (sostituisci `room_id`):
  ```sql
  -- Esempio: stanza id=1, slot ogni giorno alle 15:00, 16:30, 18:00, 19:30, 21:00, 22:30
  INSERT INTO wp_em_room_time_slots (room_id, day_of_week, start_time, end_time, is_active, created_at)
  VALUES
    (1, 0, '15:00:00', '16:00:00', 1, NOW()),  -- domenica
    (1, 0, '16:30:00', '17:30:00', 1, NOW()),
    (1, 0, '18:00:00', '19:00:00', 1, NOW()),
    (1, 1, '15:00:00', '16:00:00', 1, NOW()),  -- lunedì
    (1, 1, '16:30:00', '17:30:00', 1, NOW()),
    -- ripeti per giorni 2-6 (martedì-sabato)
    (1, 6, '15:00:00', '16:00:00', 1, NOW()),
    (1, 6, '21:00:00', '22:00:00', 1, NOW());
  ```
  `day_of_week`: 0=domenica, 1=lunedì, 2=martedì, 3=mercoledì, 4=giovedì, 5=venerdì, 6=sabato.

- [ ] Verifica: `SELECT * FROM wp_em_room_time_slots;` → vedi gli slot.

## 2.4 Crea le tariffe

- [ ] WP admin → **Escape Manager → Tariffe** → **+ Nuova tariffa**.
- [ ] Esempio configurazione:
  - **Titolo**: "Tariffa 2-4 giocatori La Cripta"
  - **Stanza**: La Cripta (oppure "Globale" se vale per tutte)
  - **Min giocatori**: 2
  - **Max giocatori**: 4
  - **Tipo prezzo**: Fisso
  - **Prezzo fisso**: 60.00
- [ ] Salva.
- [ ] Ripeti per altre fasce (5-6 giocatori) e altre stanze.

## 2.5 Configura le impostazioni base

- [ ] **Escape Manager → Impostazioni**:
  - **TTL lock**: 10 (minuti per completare prenotazione)
  - **Valuta**: EUR
  - **Timezone**: Europe/Rome
  - **Timeout inattività**: 15
  - **Prefisso codice booking**: lascia EM o cambia in SCH per Sottoscacco
  - **Nome mittente email**: "Sottoscacco" (o il tuo brand)
  - **Email mittente**: usa un indirizzo del tuo dominio (es. `prenotazioni@sottoscacco.it`)
- [ ] Salva.

## 2.6 Inserisci lo shortcode booking sul sito WP

Solo per **test in locale**. NON ancora sul sito di produzione.

- [ ] WP admin → Pagine → **Aggiungi nuova** → Titolo "Prenotazioni Test".
- [ ] Nel contenuto incolla:
  ```
  [escape_booking]
  ```
- [ ] Pubblica.
- [ ] Apri la pagina nel browser (anche incognito). Devi vedere lo stepper "Data e ora → Partecipanti → I tuoi dati → Riepilogo → Conferma".

🐛 Se non vedi il widget o vedi schermata vuota: **APPENDICE A**.

---

# PARTE 3 — Test funzionali

> Esegui ogni test e spunta la casella. Se uno fallisce, **fermati e correggi prima di proseguire**.

## 3.1 Test booking pubblico end-to-end

- [ ] **3.1.1** Apri pagina con shortcode in browser **incognito** (non loggato).
- [ ] **3.1.2** Step 1: seleziona data futura → vedi stanze e slot disponibili.
- [ ] **3.1.3** Click su uno slot disponibile → passa a Step 2, vedi countdown 10:00 minuti.
- [ ] **3.1.4** Apri **altra finestra incognito** sulla stessa pagina, click sullo **stesso slot** → errore "Slot non più disponibile" (anti-overbooking ✓).
- [ ] **3.1.5** Torna alla prima finestra. Step 2: imposta 3 adulti → click "Continua".
- [ ] **3.1.6** Step 3: compila nome, cognome, telefono (es. +39 333 1234567), email → "Continua".
- [ ] **3.1.7** Step 4: vedi riepilogo. Spunta "accetto" → "Conferma prenotazione".
- [ ] **3.1.8** Step 5: vedi codice prenotazione (es. EM-XXXXXX-XXXXXX) → ✅ booking creato.
- [ ] **3.1.9** Verifica email: arriva all'indirizzo del cliente (se non lo vedi, controlla spam). Se hai usato un'email vera, controlla la inbox.
- [ ] **3.1.10** Verifica nel CRM (WP admin → Escape Manager → Calendario): la prenotazione appare nella colonna giusta della stanza all'orario giusto.

🐛 Se l'email non arriva: vedi **APPENDICE B** (configurazione wp_mail).

## 3.2 Test gestione CRM

- [ ] **3.2.1** WP admin → **Escape Manager → Calendario** (vista giorno):
  - Vedi colonne per stanza
  - Vedi la prenotazione test
  - Click sulla card → si apre drawer laterale con dettagli
- [ ] **3.2.2** Drawer: click **+ Pagamento** → "60.00" → metodo "on_site". Stato pagamento diventa "paid".
- [ ] **3.2.3** Drawer: click **+ Nota** → "Cliente speciale". Salvata.
- [ ] **3.2.4** Cambia vista **Settimana**: ora vedi 7 giorni × stanze, prenotazione visibile nel giorno corretto.
- [ ] **3.2.5** Torna vista **Giorno**. Click su uno **slot vuoto** → si apre modale "Nuova prenotazione manuale" con stanza e orario pre-compilati.
- [ ] **3.2.6** Compila i dati cliente (oppure cerca cliente esistente) → "Crea prenotazione". Booking aggiunto al calendario.
- [ ] **3.2.7** **Drag&drop test**: trascina una booking card su un altro slot vuoto (altra ora o altra stanza). Il booking viene spostato nel calendario.
- [ ] **3.2.8** Drawer: click **Annulla** → motivo → conferma. Status diventa "Annullato".

## 3.3 Test promocodes

- [ ] **3.3.1** WP admin → **Escape Manager → Codici sconto** → **+ Nuovo codice**:
  - Codice: `TEST10`
  - Tipo: Percentuale
  - Valore: 10
  - Attivo: Sì
- [ ] **3.3.2** Salva.
- [ ] **3.3.3** Apri pagina booking in incognito → fai una prenotazione test.
- [ ] **3.3.4** Step 4 → inserisci `TEST10` nel campo codice → "Applica" → vedi "✓ TEST10 applicato (sconto fino a X €)".
- [ ] **3.3.5** Completa prenotazione.
- [ ] **3.3.6** Verifica nel DB: `SELECT used_count FROM wp_em_promocodes WHERE code='TEST10';` → deve essere 1.
- [ ] **3.3.7** Verifica drawer del booking: `total_amount` ridotto.

## 3.4 Test voucher / gift card

- [ ] **3.4.1** WP admin → **Escape Manager → Voucher** → **+ Nuovo voucher**:
  - Importo: 50.00 €
  - Valido fino a: 6 mesi dopo
  - Cliente: vuoto o compila
- [ ] **3.4.2** "Crea voucher" → ricevi codice tipo `GIFT-AB123CDE`.
- [ ] **3.4.3** Apri booking pubblico → completa step 1-3.
- [ ] **3.4.4** Step 4: inserisci `GIFT-AB123CDE` → "Applica" → sconto applicato.
- [ ] **3.4.5** Completa booking.
- [ ] **3.4.6** Verifica DB: `SELECT status FROM wp_em_vouchers WHERE code='GIFT-AB123CDE';` → deve essere `used`.
- [ ] **3.4.7** Riprova ad applicare lo stesso voucher su un nuovo booking → deve fallire "Voucher non valido".

## 3.5 Test statistiche

- [ ] **3.5.1** WP admin → **Escape Manager → Statistiche**.
- [ ] **3.5.2** Imposta range: ultimi 30 giorni.
- [ ] **3.5.3** Verifica KPI:
  - Prenotazioni: numero corrispondente ai test fatti
  - Fatturato totale e incassato
  - Occupazione % (slot prenotati / totali)
  - Party size media
- [ ] **3.5.4** Verifica grafici "Fatturato per stanza" e "Fatturato per giorno" → vedi barre per ogni giorno/stanza testata.

## 3.6 Test export CSV

- [ ] **3.6.1** WP admin → **Escape Manager → Prenotazioni**.
- [ ] **3.6.2** Click **📥 Esporta CSV** → scaricato file `bookings-YYYY-MM-DD-HHMMSS.csv`.
- [ ] **3.6.3** Apri in Excel/LibreOffice → verifica colonne: ID, Codice, Data e ora, Stanza, Stato, Cliente, Telefono, Email, Totale, Pagato, ecc.
- [ ] **3.6.4** Verifica che caratteri italiani siano corretti (à, è, ò) — il file usa BOM UTF-8.

## 3.7 Test permessi e ruoli

- [ ] **3.7.1** WP admin → Utenti → **Aggiungi nuovo** → username `staff-test`, ruolo: `EM Staff`.
- [ ] **3.7.2** Logout → login come `staff-test`.
- [ ] **3.7.3** Vai a **Escape Manager → CRM**. Nella sidebar vedi solo: Calendario, Prenotazioni, Stanze (in lettura). NON vedi Tariffe, Promocodes, Voucher, Statistiche, Impostazioni.
- [ ] **3.7.4** Apri drawer di una prenotazione: NON vedi i bottoni "Conferma", "Annulla", "+Pagamento" (em_manage_bookings mancante).
- [ ] **3.7.5** Logout, rilogga come admin.

✅ Test funzionali completati.

---

# PARTE 4 — Test bridge Sottoscacco

## 4.1 Configurazione preliminare

⚠️ **NON ATTIVARE IL BRIDGE FINCHÉ NON HAI COMPLETATO LA 4.3.**

- [ ] **4.1.1** Apri Sottoscacco: verifica nelle env var che `ESCAPE_NAVIGATOR_WEBHOOK_SECRET` sia impostato. Copialo.
- [ ] **4.1.2** WP admin → **Escape Manager → Bridge Sottoscacco**:
  - URL webhook: `https://sottoscacco.app/api/webhooks/escape-navigator` (o staging URL)
  - Secret webhook: incolla il secret
  - Max tentativi: 5
  - **Prefisso external_id**: `em-staging-` ⚠️ usa staging per non collidere
- [ ] **4.1.3** **NON spuntare "Stato bridge".** Salva impostazioni.

## 4.2 Test connessione

- [ ] **4.2.1** Click **Test connessione**.
- [ ] **4.2.2** Se HTTP 2xx → ✅ connesso.
- [ ] **4.2.3** Se errore: verifica URL, secret, raggiungibilità da WP staging a Sottoscacco.

## 4.3 ⚠️ Verifica slug stanze (riconferma)

Stesso check di sezione 2.2.1 — riconferma slug allineati. Se anche uno solo è diverso, fixalo ora.

## 4.4 Attiva bridge e test webhook

- [ ] **4.4.1** Bridge Sottoscacco → spunta "Stato bridge" → Salva.
- [ ] **4.4.2** Crea una nuova prenotazione confermata da CRM EM (manualmente o pubblico).
- [ ] **4.4.3** Apri **Bridge Sottoscacco** nel CRM EM: nella tabella "Ultimi 20 webhook" vedi una riga con `event_type=new-order`, `status=pending`.
- [ ] **4.4.4** Click **Esegui dispatch ora** → riga diventa `status=sent`.
- [ ] **4.4.5** Apri Firestore di Sottoscacco → collection `bookings` → vedi nuovo documento con:
  - `externalBookingId`: `em-staging-<id>`
  - `externalSource`: `escape_navigator`
  - `roomId` valorizzato (slug match funzionato ✓)
  - `scheduledAt` corretto

🐛 Se `roomId` è null: lo slug della stanza non coincide. Aggiusta.

## 4.5 Test transizioni → webhook

- [ ] **4.5.1** CRM EM → annulla la prenotazione test. Nella coda webhook deve apparire un `cancel-order` → sent.
- [ ] **4.5.2** Su Firestore: stesso booking deve avere `status=cancelled`.
- [ ] **4.5.3** Crea altro booking. Modifica orario (drag&drop o REST). Nella coda webhook deve apparire `update-order`.

## 4.6 Test retry e replay

- [ ] **4.6.1** Tempora-cambia URL webhook in `Bridge Sottoscacco` con uno **invalido** (es. `https://broken.test/api/webhooks/escape-navigator`).
- [ ] **4.6.2** Crea booking.
- [ ] **4.6.3** Esegui dispatch → webhook va in retry (attempts=1, next_attempt_at = +2 min).
- [ ] **4.6.4** Attendi 5 retry → status diventa `failed`.
- [ ] **4.6.5** Ripristina URL corretto. Click **Replay falliti** → webhook torna `pending` e viene inviato con successo.

## 4.7 Test silenzioso 7 giorni (CRITICO)

⚠️ Per **almeno 7 giorni** prima del go-live:
- Lascia `external_id_prefix='em-staging-'`
- Crea 2-3 booking di test al giorno da EM staging
- Verifica che TUTTI arrivino a Sottoscacco senza failed

| Giorno | Webhook sent | Webhook failed | Check-in Sottoscacco OK? |
|---|---|---|---|
| Lun | ___ | ___ | ☐ |
| Mar | ___ | ___ | ☐ |
| Mer | ___ | ___ | ☐ |
| Gio | ___ | ___ | ☐ |
| Ven | ___ | ___ | ☐ |
| Sab | ___ | ___ | ☐ |
| Dom | ___ | ___ | ☐ |

**Criterio di successo**: 7 giorni con 0 failed.

---

# PARTE 5 — Pre-switchover (giorno X-1)

Esegui tutte queste verifiche **il giorno prima** dello switch:

- [ ] **5.1** Tutti i test parte 3 verdi (rifai veloce un giro).
- [ ] **5.2** Bridge test 7 giorni completato senza failed.
- [ ] **5.3** Backup completo DB del sito WP eseguito e **testato il restore** su un altro ambiente. Conserva backup.
- [ ] **5.4** Export completo dati Escape Navigator scaricato (CSV/JSON da EN dashboard).
- [ ] **5.5** Lista operatori avvisati del cambio (chi gestisce il banco, game master, manager).
- [ ] **5.6** Numero di telefono/contatto per emergenze tecniche disponibile durante il giorno X.
- [ ] **5.7** Slug stanze EM ↔ Sottoscacco riconfermati (cambia rapido fai 1 check).
- [ ] **5.8** Cambia in Bridge Sottoscacco:
  - **Prefisso external_id**: da `em-staging-` a `em-` ⚠️ produzione
  - **URL webhook**: punta al Sottoscacco di PRODUZIONE (non staging!)
  - Salva e fai **Test connessione**.
- [ ] **5.9** Tariffe verificate: ogni stanza ha le tariffe corrette per ogni fascia di giocatori.
- [ ] **5.10** Email mittente testata con `wp_mail` (fai un booking test, verifica arrivo a indirizzo reale).
- [ ] **5.11** Bandiera "Stato bridge" → **OFF temporaneamente** finché non sei nel giorno X.
- [ ] **5.12** Decidi orario X (es. lunedì mattina 9:00 quando hai pochi booking di passaggio).
- [ ] **5.13** (Opzionale) Comunicazione clienti via social/email: "Cambiamo sistema di prenotazione il [data], potresti notare un nuovo design".

---

# PARTE 6 — Switchover (giorno X)

⚠️ Esegui in ordine. Annota l'orario di ogni step per debug rapido.

| # | Step | Orario | Esito |
|---|---|---|---|
| 6.1 | **Disattiva EN**: dal pannello EN, blocca creazione nuovi booking (di solito hanno "pause widget"). | ___ | ☐ |
| 6.2 | **Sul sito WP PRODUZIONE**: rimuovi il vecchio widget EN dalla pagina di prenotazione (snippet JS, iframe, ecc.). | ___ | ☐ |
| 6.3 | **Inserisci shortcode `[escape_booking]`** nella pagina di prenotazione. | ___ | ☐ |
| 6.4 | **Verifica frontend produzione**: apri sito in incognito → vedi widget EM. | ___ | ☐ |
| 6.5 | **WP admin produzione → Bridge Sottoscacco → "Stato bridge" → ON → Salva**. | ___ | ☐ |
| 6.6 | **Test booking REALE**: tu (o un operatore) esegue una prenotazione vera end-to-end sul sito di produzione. | ___ | ☐ |
| 6.7 | **Verifica Sottoscacco produzione**: il booking arriva, check-in funziona. | ___ | ☐ |
| 6.8 | **Monitor primi 60 min**: tieni aperta la pagina Bridge → controllo coda webhook ogni 5 minuti. | ___ | ☐ |

🚨 **Se qualcosa va male → ROLLBACK** (vedi sezione 6.9 sotto).

### 6.9 Rollback plan (se necessario)

Se nei primi 60 minuti qualcosa esplode (booking persi, errori massivi, Sottoscacco non riceve):

1. **Sul sito WP**: sostituisci shortcode `[escape_booking]` con widget EN salvato come backup.
2. **CRM EM → Bridge Sottoscacco**: "Stato bridge" → OFF → Salva.
3. **Da EN**: riattiva creazione nuovi booking.
4. **Booking creati in EM durante l'incidente**: scaricali via export CSV, inseriscili manualmente in EN se possibile.
5. **Tempo rollback realistico**: < 15 minuti.

---

# PARTE 7 — Post-switchover (giorni X → X+7)

Monitora attivamente per 7 giorni:

- [ ] **7.1** Ogni 4 ore primo giorno: WP admin → Bridge Sottoscacco → coda webhook → verifica 0 failed.
- [ ] **7.2** Ogni giorno: controlla `wp-content/debug.log` (se WP_DEBUG_LOG=true) per errori.
- [ ] **7.3** Spot check: ogni giorno verifica 5 booking presi → email arrivata → in Sottoscacco arrivato → check-in funzionato.
- [ ] **7.4** Feedback operatori in sala: chiedi se notano anomalie.
- [ ] **7.5** Verifica esportazione CSV settimanale per contabilità.

### Criterio "switchover riuscito"
- 7 giorni consecutivi senza incidenti.
- 0 webhook falliti (o tutti recuperati con replay).
- Almeno 20 booking processati end-to-end.

---

# PARTE 8 — Cessazione Escape Navigator

Dopo 1-2 mesi di funzionamento stabile:

- [ ] **8.1** Export finale completo dati EN (booking storici, clienti) → archivia.
- [ ] **8.2** **Cancella abbonamento Escape Navigator** dal loro pannello (risparmio inizia subito).
- [ ] **8.3** Rimuovi eventuali script residui di EN dal sito WP.
- [ ] **8.4** (Opzionale) Import storico EN in EM via CSV — solo se serve per statistiche storiche.

🎉 Migrazione completata.

---

# APPENDICE A — Troubleshooting

## A.1 Schermata bianca al CRM o al booking
**Causa**: il browser blocca caricamento moduli ES da CDN (`esm.sh`).
**Soluzione**:
1. Apri DevTools (F12) → Console → leggi l'errore esatto.
2. Se "blocked by CSP": il tuo tema/plugin sicurezza blocca esm.sh. Aggiungi `https://esm.sh` alla whitelist CSP.
3. Se "Failed to fetch": problema di connessione internet o firewall.

## A.2 Tabelle DB mancanti
- Diagnostica indica quali mancano → **Plugin → Disattiva → Attiva** Escape Manager. dbDelta riapplica.
- Se permangono: verifica permessi MySQL utente WP (deve avere CREATE).

## A.3 Cron WP non gira (lock non scadono, webhook non si inviano)
WP-Cron richiede traffico al sito. In locale a volte non è triggerato.

**Soluzione**:
- WP-CLI: `wp cron event run --due-now`
- Oppure visita una pagina del sito periodicamente.
- Produzione: in `wp-config.php` aggiungi `define('DISABLE_WP_CRON', true);` e setta system cron:
  ```cron
  * * * * * curl -s https://il-tuo-sito.it/wp-cron.php >/dev/null 2>&1
  ```

## A.4 wp_mail non invia email
WordPress di default usa la funzione `mail()` PHP, che spesso non funziona su hosting locale o shared.

**Soluzione consigliata**: installa plugin **WP Mail SMTP** o **FluentSMTP**, configura con Mailgun/SendGrid/Brevo (gratuiti fino a 100/giorno).

## A.5 Webhook Sottoscacco sempre pending
- Verifica cron WP attivo (vedi A.3).
- Forza dispatch: Bridge Sottoscacco → "Esegui dispatch ora".
- Controlla logs "Ultimi 20 webhook" → leggi `last_error`.

## A.6 Slot mostrati ma non prenotabili
- Controlla che `room_time_slots` abbia righe per il `day_of_week` corretto della data selezionata.
- Controlla che la stanza sia `is_active=1`.
- Controlla `wp_em_room_blocked_periods` per blocchi temporanei.

## A.7 Drag&drop non funziona
- Funziona su desktop, non su mobile (HTML5 drag&drop nativo).
- Verifica nessun plugin di sicurezza interferisca con `dragstart`.

---

# APPENDICE B — Comandi utili durante l'uso

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

### Tail log WP in tempo reale (debug)
```powershell
Get-Content "C:\Users\lucad\Local Sites\escape-manager-staging\app\public\wp-content\debug.log" -Wait -Tail 50
```

### Query bookings recenti
```sql
SELECT id, booking_code, room_id, start_datetime, booking_status, payment_status, created_at
FROM wp_em_bookings
ORDER BY created_at DESC LIMIT 20;
```

### Query coda webhook
```sql
SELECT id, event_type, status, attempts, last_error, next_attempt_at, sent_at, created_at
FROM wp_em_webhook_queue
ORDER BY id DESC LIMIT 50;
```

### Forza cron WP
```powershell
# Se hai WP-CLI installato:
wp cron event run em_cron_lock_cleanup
wp cron event run em_cron_webhook_dispatcher
```

---

# APPENDICE C — Funzionalità ancora non sviluppate (post-MVP 2)

Queste features NON sono presenti, da sviluppare in future iterazioni:

- ❌ **Pagamento online Stripe/PayPal** — richiede 2-3 giorni di dev + setup PCI.
- ❌ **WhatsApp conferma/reminder automatici** — riusa WATI di Sottoscacco, 1-2 giorni dev.
- ❌ **Multi-location switcher UI** — schema pronto, manca UI per gestire >1 sede.
- ❌ **Block periods UI** (chiusure straordinarie) — schema pronto, manca UI.
- ❌ **Custom form fields** booking pubblico.
- ❌ **Note/Task list UI ricca** (lista, gestione assegnazioni).
- ❌ **Block Gutenberg** per shortcode (per ora solo shortcode classico).

Se ti servono, dimmelo e procediamo con MVP 3.

---

# RIASSUNTO RAPIDO — Cosa devi fare ORA

1. **Setup**: Local by Flywheel + symlink plugin (PARTE 1).
2. **Configurazione**: location, stanze (slug match!), time slots SQL, tariffe (PARTE 2).
3. **Test funzionali**: 30 minuti, tutti i 7 blocchi della PARTE 3.
4. **Bridge silenzioso 7 giorni**: PARTE 4 con `em-staging-` prefix.
5. **Pre-switchover** giorno X-1 (PARTE 5).
6. **Switchover** giorno X (PARTE 6).
7. **Monitoraggio 7 giorni** (PARTE 7).
8. **Cessazione EN** dopo 1-2 mesi (PARTE 8).

🎯 **Timeline realistica**: 2-3 settimane dall'inizio al go-live.

---

*Documento operativo Escape Manager v0.3. Versione guida 1.0 — 2026-05-18. Aggiornato per MVP 2 features (UI booking manuale, vista settimanale, drag&drop, statistics, promocodes, vouchers, export CSV).*
