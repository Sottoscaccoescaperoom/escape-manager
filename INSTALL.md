# Installazione e verifica Sprint 1

## Prerequisiti

- WordPress 6.0+ in ambiente locale (Local by Flywheel, Laragon, XAMPP, Docker)
- PHP 8.1+
- MySQL 5.7+ o MariaDB 10.3+

## Installazione

### Opzione A — Sviluppo diretto in WordPress locale (consigliato)

1. Trova la cartella `wp-content/plugins/` della tua installazione WP locale.
2. Crea un symlink:
   ```powershell
   New-Item -ItemType SymbolicLink `
     -Path "C:\percorso\wordpress\wp-content\plugins\escape-manager" `
     -Target "c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager"
   ```
   *(esegui PowerShell come amministratore)*

### Opzione B — Copia manuale

1. Comprimi la cartella `escape-manager/` in `escape-manager.zip`.
2. WordPress admin → Plugin → Aggiungi nuovo → Carica plugin → seleziona zip.

## Attivazione

1. WordPress admin → Plugin → trova "Escape Manager" → **Attiva**.
2. Vai su menu laterale → **Escape Manager**.

## Verifica Sprint 1

### Check 1 — Tabelle create
La pagina "Escape Manager → Dashboard" mostra:
```
Tabelle Escape Manager rilevate: 20 / 20
```
Se mancano tabelle, disattiva e riattiva il plugin.

### Check 2 — Ruoli WordPress creati
WordPress admin → Utenti → Aggiungi nuovo → menu "Ruolo":
Devono apparire:
- EM Super Admin
- EM Admin
- EM Manager
- EM Game Master
- EM Staff
- EM Read Only

### Check 3 — Diagnostica
"Escape Manager → Diagnostica" mostra:
- DB Schema: `1` (atteso `1`)
- Ruoli em_roles: `6`
- Permessi em_permissions: `114` (6 ruoli × 19 capability)
- Per l'utente admin: tutte le 19 capability `em_*` con ✅

### Check 4 — Ispezione DB diretta
Apri TablePlus/phpMyAdmin sul DB WP locale e verifica esistano tutte e 20 le tabelle con prefisso `wp_em_` (o il prefisso configurato).

## Risoluzione problemi

### Errore "Cannot redeclare class"
Probabilmente hai installato due copie del plugin. Disattiva e cancella la duplicata.

### Tabelle non create
- Verifica permessi MySQL dell'utente WP (deve avere CREATE).
- Controlla `wp-config.php` → `define('WP_DEBUG', true)` per vedere errori dbDelta.
- Attiva log: `define('WP_DEBUG_LOG', true)` → file `wp-content/debug.log`.

### Pagina admin "Permessi insufficienti"
Stai loggato con un utente che non ha capability `em_view_dashboard`. Usa admin WordPress nativo (ha tutto).

## Disinstallazione

Plugin → Disinstalla. Per **default i dati restano**. Per cancellare completamente:

```php
// Esegui prima della disinstallazione (es. plugin Code Snippets o wp-cli):
update_option('em_purge_on_uninstall', true);
```

Poi disinstalla normalmente: tabelle e ruoli verranno rimossi.

## Prossimo sprint

Quando Sprint 1 è verificato, lancia Sprint 2 con questo prompt:

```
Apri escape-manager/PROGETTO.md sezione 6 (API REST).
Inizia Sprint 2 — REST API minimale:

1. includes/rest/class-rest-controller-base.php (auth helpers, permission callback,
   validazione, formato risposta JSON)
2. includes/repositories/ per locations, rooms, room_time_slots, temporary_locks
3. includes/services/class-availability-service.php
4. includes/services/class-lock-service.php con transazione FOR UPDATE
5. includes/rest/class-rooms-controller.php (GET, POST, PUT, DELETE)
6. includes/rest/class-locations-controller.php
7. includes/rest/class-availability-controller.php (GET con date/room_id/location_id)
8. includes/rest/class-locks-controller.php (POST, DELETE)
9. includes/cron/class-lock-cleanup.php (wp_schedule_event ogni minuto)

Registra tutto su 'rest_api_init'. Usa namespace EM_REST_NAMESPACE.

Al termine fammi un riepilogo con curl di test per ogni endpoint.
```
