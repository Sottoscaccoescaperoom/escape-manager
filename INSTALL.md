# Installazione e setup

## Prerequisiti

- WordPress 6.0+ in ambiente locale ([Local by Flywheel](https://localwp.com/) consigliato, oppure Laragon, XAMPP, Docker)
- PHP 8.1+
- MySQL 5.7+ o MariaDB 10.3+
- Internet attivo al primo caricamento (gli script React/htm sono caricati via ESM CDN; cache browser dopo)

## Installazione

### Opzione A — Sviluppo via symlink (consigliato)

PowerShell come **amministratore**:
```powershell
New-Item -ItemType SymbolicLink `
  -Path "C:\percorso\al\sito\wp-content\plugins\escape-manager" `
  -Target "c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager"
```

### Opzione B — Zip + upload

```powershell
Compress-Archive -Path "c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager\*" -DestinationPath "$env:TEMP\escape-manager.zip"
```
Poi WordPress admin → Plugin → Aggiungi nuovo → Carica plugin.

## Attivazione

1. WordPress admin → Plugin → trova **Escape Manager** → Attiva.
2. Menu laterale → **Escape Manager** → si apre il CRM.

Al primo avvio vengono create:
- 21 tabelle DB con prefisso `wp_em_*`
- 6 ruoli WP custom (`em_super_admin`, `em_admin`, `em_manager`, `em_game_master`, `em_staff`, `em_read_only`)
- 19 capability `em_*` assegnate ai ruoli + all'admin WP nativo
- Settings di default (lock TTL 10min, timezone Europe/Rome, currency EUR)

## Verifica installazione

### Diagnostica
WP admin → Escape Manager → **Diagnostica** — deve mostrare:
- DB Schema: `2` (atteso `2`)
- Ruoli em_roles: 6
- Permessi em_permissions: 114
- Utente admin: tutte le 19 capability `em_*` con ✅

### CRM
WP admin → Escape Manager → si apre l'app React. Se vedi solo schermata bianca:
- Apri DevTools (F12) → Console: cerca errori (probabilmente CSP o ESM block).
- Controlla che il tuo sito non blocchi `esm.sh` (in caso, vedi sezione "Hosting senza CDN" sotto).

### REST API
Da terminale:
```powershell
curl -X GET "http://il-tuo-sito.local/wp-json/escape-manager/v1/rooms" -H "Accept: application/json"
```
Deve rispondere `{"data": []}` (lista vuota all'inizio).

## Configurazione minima per partire

### 1. Crea almeno una Location
**Opzione 1 — CRM**: non c'è ancora un'UI nel CRM (MVP1) → usa REST direttamente:
```powershell
curl -X POST "http://il-tuo-sito.local/wp-json/escape-manager/v1/locations" `
  -H "Content-Type: application/json" `
  -H "X-WP-Nonce: <nonce>" `
  -b "<cookie WP>" `
  -d '{"name":"Sede Principale","city":"Milano","is_active":1}'
```

**Opzione 2 — phpMyAdmin / TablePlus** (più veloce per il primo seed):
```sql
INSERT INTO wp_em_locations (name, city, country, is_active, created_at, updated_at)
VALUES ('Sede Principale', 'Milano', 'Italia', 1, NOW(), NOW());
```

### 2. Crea le stanze
CRM → menu **Stanze** → "+ Nuova stanza". Compila campi.

**Importante**: lo **slug della stanza DEVE corrispondere** allo slug della stessa stanza nel database Firestore di Sottoscacco (per il bridge). Vedi `PROGETTO.md` sezione "Allineamento slug stanze".

### 3. Configura orari delle stanze (time slots)
Endpoint REST (per ora non c'è UI):
```powershell
$body = @{
  slots = @(
    @{ day_of_week = 0; start_time = "15:00:00"; end_time = "16:00:00" }
    @{ day_of_week = 0; start_time = "17:00:00"; end_time = "18:00:00" }
    @{ day_of_week = 6; start_time = "15:00:00"; end_time = "16:00:00" }
  )
} | ConvertTo-Json -Depth 3
curl -X POST "http://localhost/wp-json/escape-manager/v1/rooms/1/time-slots" `
  -H "Content-Type: application/json" -H "X-WP-Nonce: <nonce>" -b "<cookie>" -d $body
```
- `day_of_week`: 0=domenica, 1=lunedì, …, 6=sabato
- `start_time`: HH:MM:SS in **locale** (sarà convertito UTC quando si calcola disponibilità)

### 4. Crea tariffe
CRM → **Tariffe** → "+ Nuova tariffa". Esempio: 2-4 giocatori → prezzo fisso 60€.

### 5. Inserisci shortcode nel sito
In una pagina/articolo WordPress, aggiungi:
```
[escape_booking]
```
oppure con filtro location:
```
[escape_booking location_id="1"]
```

Apri la pagina nel browser: vedi il widget di prenotazione.

### 6. Configura email
CRM → **Impostazioni** → sezione "Email" → imposta nome e indirizzo mittente.

### 7. Configura Bridge Sottoscacco
CRM → **Bridge Sottoscacco** (voce di menu):
- URL: `https://sottoscacco.app/api/webhooks/escape-navigator`
- Secret: stesso valore di `ESCAPE_NAVIGATOR_WEBHOOK_SECRET` su Sottoscacco
- Salva → clicca **Test connessione** → deve rispondere HTTP 2xx
- **NON ATTIVARE** "Stato bridge" ancora! Vedi TEST_PLAN.md per il piano di test pre-switch.

## Hosting senza accesso CDN (esm.sh bloccato)

Se il tuo hosting blocca esm.sh, vedi `BUILD.md` per istruzioni su come pre-buildare i bundle React in locale con Vite e servirli dal plugin stesso. Per default usiamo CDN per ridurre la friction al primo run.

## Troubleshooting

### "Permessi insufficienti" su /me
Stai loggato con un utente senza ruolo EM. Loggati come WP admin nativo (ha tutte le capability `em_*`).

### Schermata bianca al CRM
- DevTools (F12) → Console
- Probabile causa: il browser blocca import ES modules da CDN. Soluzioni:
  - Verifica connessione internet
  - CSP del tema/plugin di sicurezza che blocca `esm.sh` → whitelisting

### Tabelle mancanti
- Diagnostica indica quali mancano → disattiva/riattiva plugin (ricarica `dbDelta`)
- Verifica permessi utente MySQL: serve `CREATE TABLE`

### Lock non scadono
Cron WP non gira: hai bisogno di un visitatore (admin o pubblico) per triggerare `wp-cron.php`. Per produzione: disabilita WP-Cron via `define('DISABLE_WP_CRON', true);` in `wp-config.php` e setta un cron di sistema:
```cron
* * * * * curl -s https://il-tuo-sito.it/wp-cron.php >/dev/null 2>&1
```

### Webhook bridge sempre in "pending"
- Verifica cron WP attivo
- Forza dispatch manuale: Bridge Sottoscacco → "Esegui dispatch ora"
- Controlla log "Ultimi 20 webhook" per `last_error`

## Sviluppo dopo MVP 1

Vedi `PROGETTO.md` sezioni 11 e Appendici C/D per MVP 2 (tariffe avanzate, employees, promocodes, export) e MVP 3 (Stripe, WhatsApp, statistiche).
