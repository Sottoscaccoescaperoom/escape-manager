# ESCAPE MANAGER — Documento di Progetto

> Sistema proprietario di gestione escape room (sostituto di Escape Navigator).
> Documento sorgente per avviare lo sviluppo. Conservare in `escape-manager/PROGETTO.md`.

**Owner:** Luca D. (segreteria.perseva@gmail.com)
**Data redazione:** 2026-05-15
**Versione documento:** 1.0
**Stato:** Pronto allo sviluppo — Sprint 1 da avviare
**Lingua sviluppo:** italiano (codice in inglese, commenti/documentazione in italiano)

---

## INDICE

1. Obiettivo e scopo
2. Stack tecnologico
3. Struttura cartelle del plugin
4. Schema database completo (19 tabelle)
5. Macchina a stati prenotazioni
6. API REST — contratto completo
7. Ruoli e permessi
8. Flussi utente
9. Componenti frontend (Booking + CRM)
10. Sicurezza — checklist
11. Piano implementazione MVP (sprint-by-sprint)
12. Cosa NON fare in MVP 1
13. Rischi e mitigazioni
14. Glossario stati e termini
15. Prerequisiti ambiente di sviluppo
16. Comando di avvio sviluppo

---

## 1. OBIETTIVO E SCOPO

Costruire un sistema completo e proprietario per la gestione di una escape room, composto da:

- **Plugin WordPress** installabile, autonomo, no dipendenze esterne a pagamento.
- **Booking pubblico** integrabile nel sito WordPress esistente tramite shortcode `[escape_booking]`.
- **CRM gestionale interno** (pagina admin WP protetta) con calendario operativo, prenotazioni, clienti, staff, pagamenti, impostazioni.
- **Database proprietario MySQL** (prefisso `wp_em_`).
- **API REST sicure** (namespace `escape-manager/v1`).
- **Sistema ruoli/permessi** custom basato su capability WordPress.

Il sistema deve essere **modulare, scalabile, sicuro**, e indipendente da Escape Navigator.

---

## 2. STACK TECNOLOGICO

| Layer | Tecnologia | Note |
|---|---|---|
| Plugin host | WordPress 6.x + PHP 8.1+ | Plugin installabile da .zip |
| Database | MySQL 5.7+ / MariaDB 10.3+ | Tabelle custom con prefisso `wp_em_` |
| API | REST API WordPress | `register_rest_route`, namespace dedicato |
| Auth | WP user system + capability `em_*` + nonce REST | Mapping employees ↔ wp_users |
| Frontend Booking | React 18 + Vite | Bundle iniettato via shortcode |
| Frontend CRM | React 18 + Vite | Montato su pagina admin WP |
| State client | Zustand + TanStack Query | Niente Redux |
| UI | Tailwind CSS + Radix UI (headless) | Stessa toolchain Booking + CRM |
| Date | date-fns + date-fns-tz | Timezone Europe/Rome, locale it |
| Validazione | Zod (frontend) + sanitize/validate PHP (backend) | Doppio livello |
| Build | Vite produce due bundle indipendenti | `public/booking-app/dist/`, `admin/crm-app/dist/` |

---

## 3. STRUTTURA CARTELLE PLUGIN

```
escape-manager/
├── escape-manager.php              # Bootstrap plugin, header WP
├── uninstall.php
├── readme.txt
│
├── includes/
│   ├── class-plugin.php            # Singleton, bootstrap moduli
│   ├── class-activator.php         # dbDelta, ruoli, opzioni default
│   ├── class-deactivator.php       # NON cancella dati
│   ├── class-database.php          # Migrazioni versioned (em_db_version)
│   ├── class-i18n.php
│   │
│   ├── domain/                     # Entità (Booking, Room, Customer, ...)
│   ├── repositories/               # Accesso DB con $wpdb->prepare()
│   ├── services/                   # Business logic
│   │   ├── class-availability-service.php
│   │   ├── class-booking-service.php
│   │   ├── class-lock-service.php
│   │   ├── class-pricing-service.php
│   │   ├── class-payment-service.php
│   │   ├── class-notification-service.php
│   │   ├── class-permission-service.php
│   │   └── class-activity-logger.php
│   ├── rest/                       # Controller REST per ogni risorsa
│   ├── auth/
│   │   ├── class-capabilities.php
│   │   └── class-roles.php
│   ├── cron/
│   │   ├── class-lock-cleanup.php  # Rilascia lock scaduti ogni minuto
│   │   └── class-reminder-cron.php
│   └── helpers/
│
├── admin/
│   ├── class-admin.php             # Registra menu, enqueue
│   ├── views/crm-mount.php
│   └── crm-app/                    # Sorgenti React CRM
│       ├── package.json
│       ├── vite.config.js
│       └── src/
│
├── public/
│   ├── class-public.php
│   ├── class-shortcode-booking.php
│   └── booking-app/                # Sorgenti React Booking
│       ├── package.json
│       ├── vite.config.js
│       └── src/
│
├── assets/
├── languages/                       # .po/.mo it_IT
├── templates/emails/                # Override-abili da tema
└── tests/php/ + tests/js/
```

**Regole architetturali assolute:**
- Nessuna query SQL fuori dalle classi `*-repository.php`.
- Nessuna logica di business nei controller REST.
- Nessun output HTTP nei repository.
- Tutti gli importi memorizzati in **centesimi** (`BIGINT`), formattati solo in presentazione.
- Tutti i datetime memorizzati in **UTC**, convertiti a `Europe/Rome` in presentazione.

---

## 4. SCHEMA DATABASE — 19 TABELLE

### Convenzioni
- Prefisso: `{wp_prefix}em_` (es. `wp_em_bookings`).
- PK: `BIGINT UNSIGNED AUTO_INCREMENT`.
- Soft delete: `deleted_at TIMESTAMP NULL`.
- Audit: `created_at`, `updated_at`.
- Charset: `utf8mb4_unicode_520_ci`.
- Importi in centesimi (`BIGINT`).
- Datetime in UTC.

### Tabelle

#### 4.1 `em_locations`
```
id, name, address, city, postal_code, country, latitude, longitude,
is_active, created_at, updated_at, deleted_at
```

#### 4.2 `em_rooms`
```
id, location_id, name, slug (UNIQUE), image_url, description, teaser,
important_info, duration_minutes, min_players, max_players, minimum_age,
difficulty (1-5), fear_level (1-5), room_type, has_actors,
tags (JSON), sort_order, is_active,
created_at, updated_at, deleted_at
```

#### 4.3 `em_room_time_slots`
```
id, room_id, day_of_week (0-6), start_time (TIME), end_time (TIME),
is_active, created_at, updated_at
```

#### 4.4 `em_room_blocked_periods`
```
id, room_id, start_datetime, end_datetime, reason, created_by, created_at
```
*Per manutenzioni/chiusure straordinarie senza sporcare `room_time_slots`.*

#### 4.5 `em_bookings`
```
id, booking_code (UNIQUE), room_id, location_id, customer_id,
start_datetime (UTC), end_datetime (UTC), timezone DEFAULT 'Europe/Rome',
adults, children, total_players,
total_amount (cents), paid_amount (cents),
payment_method, payment_status, booking_status, source,
customer_comment, internal_notes, cancellation_reason,
created_by (wp_user_id), assigned_staff_id,
created_at, updated_at, expires_at, deleted_at
```

**Indici critici:**
- `(room_id, start_datetime)`
- `(start_datetime)`
- `(booking_status)`
- `(customer_id)`
- `booking_code` UNIQUE

**Stati `booking_status`:**
`temporary_lock`, `booking_in_progress`, `confirmed`, `not_paid`,
`awaiting_payment`, `cancelled`, `unsuccessful_booking`, `completed`, `no_show`

**Stati `payment_status`:**
`unpaid`, `partially_paid`, `paid`, `refunded`, `awaiting_payment`

#### 4.6 `em_booking_participants`
```
id, booking_id, name, phone, email, type (adult|child), created_at
```

#### 4.7 `em_customers`
```
id, first_name, last_name, phone, email, birthday, address,
total_bookings (denormalizzato), last_booking_date, last_room_id,
created_at, updated_at, deleted_at
```
*Denormalizzati aggiornati a confirm/cancel via app, non da trigger SQL.*

**Indici:** `(phone)`, `(email)`

#### 4.8 `em_employees`
```
id, wp_user_id, first_name, last_name, email, phone, role_id,
position, is_active, last_visit, created_at, updated_at
```

#### 4.9 `em_roles`
```
id, name, slug (UNIQUE), description, created_at
```
**Seed iniziale:** `super_admin`, `admin`, `manager`, `game_master`, `staff`, `read_only`

#### 4.10 `em_permissions`
```
id, role_id, permission_key, allowed (BOOL)
```
**Permessi minimi:**
`view_dashboard`, `view_calendar`, `manage_calendar`, `view_bookings`,
`manage_bookings`, `delete_bookings`, `view_customers`, `manage_customers`,
`view_rooms`, `manage_rooms`, `view_settings`, `manage_settings`,
`view_staff`, `manage_staff`, `manage_roles`, `view_payments`,
`manage_payments`, `view_statistics`, `export_data`

#### 4.11 `em_payments`
```
id, booking_id, amount (cents), payment_method, payment_status,
transaction_id, paid_at, created_by, created_at
```

#### 4.12 `em_tariffs`
```
id, room_id (nullable = globale), title, min_players, max_players,
price_type (per_person|fixed), price_per_person (cents), fixed_price (cents),
created_at, updated_at
```

#### 4.13 `em_booking_rules`
```
id, title, block_online_before_hours, cancellation_without_penalty_hours,
cancellation_fee (cents), booking_only_by_phone_hours, created_at, updated_at
```

#### 4.14 `em_promocodes`
```
id, code (UNIQUE), type (percent|fixed), value, usage_limit, used_count,
valid_from, valid_to, is_active, created_at
```

#### 4.15 `em_vouchers`
```
id, code (UNIQUE), customer_id, amount (cents), status, valid_until, created_at
```

#### 4.16 `em_notes`
```
id, entity_type (booking|customer|...), entity_id, note, created_by, created_at
```

#### 4.17 `em_tasks`
```
id, entity_type, entity_id, title, description, assigned_to,
status (todo|doing|done), due_date, created_at, updated_at
```

#### 4.18 `em_activity_logs`
```
id, user_id, action, entity_type, entity_id,
old_value (JSON), new_value (JSON), ip_address, user_agent, created_at
```

#### 4.19 `em_temporary_locks`
```
id, room_id, start_datetime, end_datetime, session_id (UUID client),
customer_phone, expires_at, created_at
```

**Indici:** `(room_id, start_datetime)`, `(expires_at)`, `(session_id)`

#### 4.20 `em_settings`
```
id, setting_key (UNIQUE), setting_value (LONGTEXT, JSON o stringa),
autoload (BOOL), created_at, updated_at
```

### Strategia anti-overbooking

MySQL non supporta partial UNIQUE index. Strategia adottata:
1. Lock applicativo via `em_temporary_locks` (TTL 10min default).
2. Transazione `SELECT ... FOR UPDATE` su `em_temporary_locks` + check overlap su `em_bookings` attivi.
3. Idempotency-key sulla POST `/bookings` per evitare doppi click.

---

## 5. MACCHINA A STATI PRENOTAZIONI

```
                      ┌──> confirmed ──> completed
                      │                 └─> no_show
temporary_lock ──> booking_in_progress ──> awaiting_payment ──> confirmed
                      │                                          
                      └──> unsuccessful_booking (timeout)         
                                                                  
confirmed ──> cancelled
not_paid (legacy) ──> awaiting_payment ──> confirmed
```

**Regole transizioni:**
- `temporary_lock` → `booking_in_progress` quando cliente raggiunge step 3.
- `booking_in_progress` ha `expires_at` (estende il lock); se scade → `unsuccessful_booking`.
- `confirmed` solo se pagamento valido **o** pagamento sul posto accettato.
- `completed` e `no_show` impostati post-evento (manuale o cron).

**Unico punto di transizione:** `Booking_Service::transition_to($booking, $new_status, $context)`.

---

## 6. API REST — CONTRATTO

### Convenzioni
- Namespace: `/wp-json/escape-manager/v1/`
- Auth: cookie WP + `X-WP-Nonce` per CRM; nonce pubblico generato dallo shortcode per booking.
- Risposta lista: `{ data: [...], meta: { pagination } }`
- Risposta errore: `{ error: { code, message, details } }`
- Status code: 200, 201, 204, 400, 401, 403, 404, 409, 422, 500.
- Idempotenza: header `Idempotency-Key` su POST mutanti.

### Endpoint pubblici (no login)

| Metodo | Path | Scopo |
|---|---|---|
| GET | `/locations` | Location attive |
| GET | `/rooms` | Stanze attive (filtro location) |
| GET | `/rooms/{slug}` | Dettaglio stanza pubblico |
| GET | `/availability` | Slot disponibili (params: date, room_id?, location_id?) |
| POST | `/temporary-lock` | Blocca slot → ritorna lock_id + expires_at |
| DELETE | `/temporary-lock/{id}` | Rilascia |
| POST | `/bookings/public` | Crea booking da lock |
| GET | `/bookings/public/{code}` | Stato prenotazione (no PII completa) |
| GET | `/tariffs/public` | Tariffe pubbliche per stanza |

### Endpoint CRM (auth + capability)

CRUD completo su: `rooms`, `locations`, `room_time_slots`, `bookings`, `customers`,
`employees`, `tariffs`, `payments`, `settings`, `roles`, `permissions`,
`promocodes`, `vouchers`, `notes`, `tasks`.

**Endpoint speciali:**
- `GET /me` — utente corrente + permissions array
- `GET /calendar` — vista aggregata (slot + bookings + locks)
- `POST /bookings/{id}/confirm`
- `POST /bookings/{id}/cancel` (body: reason)
- `POST /bookings/{id}/payment` (body: amount, method)
- `POST /bookings/{id}/assign-staff`
- `POST /bookings/{id}/notes`
- `POST /bookings/{id}/tasks`
- `GET /activity-logs`
- `GET /export/bookings.csv` (MVP 2)
- `GET /statistics/*` (MVP 3)

### Esempio `GET /availability?date=2026-05-20&location_id=1`

```json
{
  "data": [
    {
      "room_id": 1,
      "room_name": "La Cripta",
      "duration_minutes": 60,
      "slots": [
        { "start": "2026-05-20T15:00:00+02:00", "status": "available", "price_from": 8000 },
        { "start": "2026-05-20T16:30:00+02:00", "status": "locked", "lock_expires_at": "..." },
        { "start": "2026-05-20T18:00:00+02:00", "status": "booked" },
        { "start": "2026-05-20T19:30:00+02:00", "status": "blocked" }
      ]
    }
  ],
  "meta": { "timezone": "Europe/Rome", "generated_at": "..." }
}
```

### Esempio `POST /temporary-lock`

**Request:**
```json
{
  "room_id": 1,
  "start_datetime": "2026-05-20T15:00:00+02:00",
  "session_id": "uuid-client-generato",
  "expected_duration_minutes": 60
}
```

**Response 201:**
```json
{
  "data": { "lock_id": 42, "expires_at": "...", "ttl_seconds": 600 }
}
```

**Response 409 (slot occupato):**
```json
{ "error": { "code": "SLOT_UNAVAILABLE", "message": "Slot già prenotato o bloccato." } }
```

---

## 7. RUOLI E PERMESSI

### Capability WordPress custom (prefix `em_`)

`em_view_dashboard`, `em_view_calendar`, `em_manage_calendar`,
`em_view_bookings`, `em_manage_bookings`, `em_delete_bookings`,
`em_view_customers`, `em_manage_customers`,
`em_view_rooms`, `em_manage_rooms`,
`em_view_settings`, `em_manage_settings`,
`em_view_staff`, `em_manage_staff`, `em_manage_roles`,
`em_view_payments`, `em_manage_payments`,
`em_view_statistics`, `em_export_data`

### Mapping iniziale ruolo → capability

| Capability | super_admin | admin | manager | game_master | staff | read_only |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| view_dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| view_calendar | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| manage_calendar | ✅ | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| view_bookings | ✅ | ✅ | ✅ | ✅ (solo assegnate) | ✅ | ✅ |
| manage_bookings | ✅ | ✅ | ✅ | ⚠️ (→ completed) | ⛔ | ⛔ |
| delete_bookings | ✅ | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| manage_customers | ✅ | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| manage_rooms | ✅ | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| manage_settings | ✅ | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| manage_staff | ✅ | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |
| manage_roles | ✅ | ⛔ | ⛔ | ⛔ | ⛔ | ⛔ |
| manage_payments | ✅ | ✅ | ✅ | ⛔ | ⛔ | ⛔ |
| view_statistics | ✅ | ✅ | ✅ | ⛔ | ⛔ | ✅ |
| export_data | ✅ | ✅ | ⛔ | ⛔ | ⛔ | ⛔ |

### Enforcement
- **Backend (autoritativo):** ogni controller REST verifica `current_user_can('em_xxx')`.
- **Frontend (cosmetic):** CRM riceve da `/me` il payload `permissions: [...]` e nasconde menu/bottoni.
- **MAI** fidarsi solo del frontend.

### Auto-logout CRM
- Reset su `mousemove`, `click`, `keydown`, `touchstart`, `scroll`.
- Default 15 min (configurabile in Settings).
- A `timeout - 60s`: modale countdown "Sei ancora qui?".
- Allo scadere: `POST /logout` + redirect login.

---

## 8. FLUSSI UTENTE

### 8.1 Booking pubblico (5 step)

```
Step 1 — Data + Stanza + Orario
  GET /availability
  click slot → POST /temporary-lock { session_id da localStorage }
  → riceve lock_id + expires_at → avvia countdown UI

Step 2 — Partecipanti + commento
  validazione min/max per stanza

Step 3 — Dati cliente
  campi obbligatori da settings.required_fields_public
  validazione telefono con libphonenumber-js

Step 4 — Riepilogo + accettazione condizioni
  ricalcolo prezzo server-side (POST /bookings/preview)
  checkbox condizioni obbligatorio

Step 5 — POST /bookings/public
  body: { lock_id, customer_data, participants, payment_method, promocode? }
  server: valida lock, calcola prezzo, crea customer+booking, libera lock
  → status: confirmed (sul posto) o awaiting_payment (online)

Conferma — mostra booking_code, invia email
```

**Edge case lock scaduto durante checkout:**
- Client mostra countdown.
- Allo scadere: redirect a Step 1 con messaggio "Tempo scaduto, riseleziona orario".
- Lato server: cron `Lock_Cleanup` + check al booking-create.

### 8.2 CRM apertura calendario

```
Login CRM → GET /me (user + permissions + employee profile)
         → GET /calendar?date=oggi&view=day&location_id=default
         → GET /rooms (per colonne)
Refetch automatico ogni 30s (polling MVP, WebSocket futuro)
```

### 8.3 Booking manuale telefonico

```
Calendar → click slot vuoto
        → drawer "Nuova prenotazione manuale"
        → seleziona cliente esistente o crea nuovo (autocomplete telefono)
        → seleziona tariffa + partecipanti
        → POST /bookings (skipping flusso pubblico)
        → status: confirmed direttamente
```

---

## 9. COMPONENTI FRONTEND

### 9.1 Booking Public App (`public/booking-app/`)

```
<BookingWidget>
 ├── <Stepper currentStep={0..4} />
 ├── <Step1_DateRoomSlot>
 │    ├── <DatePicker />
 │    ├── <RoomList>
 │    │    └── <RoomCard /> (img, durata, difficoltà, min/max)
 │    └── <TimeSlotGrid>
 │         ├── <DayColumn>
 │         │    └── <TimeSlotButton status="available|locked|booked|blocked" />
 │         └── <LegendBar />
 ├── <Step2_Participants />
 ├── <Step3_CustomerForm />
 ├── <Step4_Summary>
 │    ├── <PriceBreakdown />
 │    ├── <PaymentMethodSelector />
 │    ├── <PromocodeInput />
 │    └── <TermsCheckbox />
 ├── <Step5_Result>
 │    ├── <BookingSuccess />
 │    └── <BookingError />
 ├── <CountdownTimer /> (sticky, mostra TTL lock)
 └── <TemporaryLockModal /> (collision)
```

**Store globale (Zustand `bookingStore`):**
`currentStep`, `selectedRoom`, `selectedSlot`, `lockId`, `lockExpiresAt`,
`participants`, `customer`, `paymentMethod`, `promocode`

### 9.2 CRM App (`admin/crm-app/`)

```
<CrmApp>
 ├── <AuthGate />
 ├── <CrmLayout>
 │    ├── <Sidebar /> (filtrata per permessi)
 │    ├── <Header>
 │    │    ├── <LocationSwitcher />
 │    │    ├── <NotificationBell />
 │    │    └── <UserMenu />
 │    └── <Routes>
 │         ├── /dashboard
 │         ├── /calendar
 │         │    ├── <MiniCalendar />
 │         │    ├── <ViewToggle day|week />
 │         │    ├── <RoomFilter />
 │         │    ├── <CalendarGrid>
 │         │    │    ├── <TimeAxis />
 │         │    │    └── <RoomColumn>
 │         │    │         ├── <BookingCard status />
 │         │    │         └── <EmptySlot />
 │         │    └── <BookingDrawer />     ← condiviso con /bookings
 │         ├── /bookings
 │         ├── /customers
 │         ├── /marketing/* (gift, promocodes, cross-sell, services)
 │         ├── /employees/* (list, shifts, bonuses, roles, positions, reports)
 │         ├── /finances/* (payments, cashboxes, salaries, counterparties)
 │         ├── /statistics
 │         └── /settings/* (rooms, booking, widgets, integrations, company, …)
 └── <IdleTimer />
```

### 9.3 Pattern API client

- `src/api/client.ts`: wrapper fetch con base `/wp-json/escape-manager/v1`,
  inietta header `X-WP-Nonce`, gestisce 401 → redirect login.
- TanStack Query keys: `['bookings', filters]`, `['booking', id]`,
  `['calendar', date, view, locationId]`, ecc.
- Mutation invalida cache **mirate** (no global invalidation).

### 9.4 Colori stati booking (UI)

| Stato | Colore |
|---|---|
| `confirmed` | blu/verde |
| `not_paid` | rosso/arancio |
| `booking_in_progress` | grigio/arancio |
| `awaiting_payment` | arancio |
| `cancelled` | grigio |
| `unsuccessful_booking` | grigio scuro |
| `completed` | verde scuro |
| `no_show` | nero/rosso |

---

## 10. SICUREZZA — CHECKLIST

| Vettore | Mitigazione |
|---|---|
| SQL injection | `$wpdb->prepare()` ovunque |
| XSS output | `esc_html`, `esc_attr`, `wp_kses_post`; React per default escape |
| CSRF | Nonce REST + `current_user_can` |
| Brute force login | Plugin esterno (Wordfence/Limit Login) + rate-limit endpoint critici |
| Enumeration booking | `GET /bookings/public/{code}` non rivela PII full |
| Lock abuse | Rate-limit `POST /temporary-lock` 10/min per IP+session, 1 lock attivo/session |
| Bot booking | reCAPTCHA v3 opzionale + honeypot field |
| Permessi REST | Ogni endpoint dichiara `permission_callback` esplicito |
| Activity log | `em_activity_logs` traccia ip, user_agent, user_id, action, diff |
| GDPR | Export + cancellazione dati cliente; soft delete + hard delete pianificato 30gg |
| File upload foto | Media Library WP, MIME whitelist |

---

## 11. PIANO IMPLEMENTAZIONE — MVP 1

Ogni sprint termina con un plugin **installabile e funzionante** al livello raggiunto.

### Sprint 1 — Fondamenta plugin (2-3 giorni)
1. `escape-manager.php` con header WP + autoloader PSR-4.
2. `class-activator.php` con `dbDelta` per tutte le 20 tabelle.
3. Seeding ruoli + capability via `class-roles.php`.
4. Settings default (`lock_ttl_minutes=10`, `currency=EUR`, `timezone=Europe/Rome`).
5. Pagina admin "Escape Manager" placeholder.

**Verifica:** attivazione plugin crea tabelle, ruoli, mostra menu.

### Sprint 2 — REST API minimale (3-4 giorni)
1. `class-rest-controller-base.php` (auth/permission/validation helpers).
2. CRUD `rooms` + `locations` + `room_time_slots`.
3. `GET /availability` (Availability_Service genera slot da `room_time_slots` × range − `bookings` − `blocked_periods`).
4. `POST /temporary-lock` + `DELETE /temporary-lock/{id}` con transazione.
5. Cron `Lock_Cleanup` ogni minuto.

**Verifica:** chiamate Postman creano/leggono dati, lock scadono.

### Sprint 3 — Booking pubblico React (4-5 giorni)
1. Setup Vite + Tailwind in `public/booking-app/`.
2. Shortcode `[escape_booking]` monta root `#em-booking-root`, espone `window.EM_BOOKING_CONFIG`.
3. Step 1 (DateRoomSlot) + lock al click.
4. Step 2-3-4 (form + summary).
5. `POST /bookings/public`.
6. Countdown timer + modale collision.
7. Step 5 conferma + email base.

**Verifica:** flusso completo da sito WP a booking confermato.

### Sprint 4 — CRM core (5-6 giorni)
1. Setup Vite CRM in `admin/crm-app/`, mount su pagina admin (`em_view_dashboard`).
2. Auth gate via `/me`.
3. Layout + sidebar (voci nascoste senza permesso).
4. `/calendar` vista giorno.
5. `<BookingDrawer>` (dettagli, cambio stato, assegna staff, nota).
6. `/bookings` lista con filtri base.
7. `/customers` lista + scheda.

**Verifica:** CRM gestisce booking creati da pubblico, conferma manuale, annullamento.

### Sprint 5 — Rifinitura MVP 1 + Bridge Sottoscacco (3-4 giorni)
1. Pagamento sul posto.
2. UI minimale `manage_rooms`.
3. Email conferma + cancellazione.
4. Activity log su transizioni.
5. Idle timer CRM.
6. **Sottoscacco_Bridge_Service** + tabella `em_webhook_queue` (vedi APPENDICE C).
7. Cron `em_cron_webhook_dispatcher` per retry esponenziale.
8. Pagina admin "Integrations → Sottoscacco" minimale (config URL + secret + test).
9. Test end-to-end con switchover staging.

**Verifica:** booking creato in EM → arriva a Sottoscacco con `external_source='escape_navigator'` → check-in funziona.

### MVP 2 (post-MVP1)
- Tariffe avanzate (per fascia oraria, weekend)
- Filtri booking estesi + export CSV
- Note/Task UI completa
- Gestione employees + assegnazione game master
- Settings campi obbligatori
- Promocodes base

### MVP 3
- Voucher gift cards
- Stripe (pagamento online)
- WhatsApp via WATI/Twilio
- Statistiche
- Multi-location avanzato (switcher, permessi per location)
- Vista calendario settimanale
- Block periods UI
- Drag & drop calendario

---

## 12. COSA NON FACCIO IN MVP 1 (esplicito)

- ❌ Online payment (Stripe/PayPal) → MVP 3
- ❌ WhatsApp/SMS → MVP 3
- ❌ Voucher e promocodes UI completa → MVP 2/3
- ❌ Statistiche → MVP 3 (solo route vuoto)
- ❌ Multi-location switcher UI → MVP 3 (schema già pronto)
- ❌ Vista settimanale calendario → MVP 2
- ❌ Drag & drop calendario → MVP 3
- ❌ Custom form fields → MVP 2/3
- ❌ Block Gutenberg → post-MVP

---

## 13. RISCHI E MITIGAZIONI

| Rischio | Probabilità | Impatto | Mitigazione |
|---|---|---|---|
| Race condition booking simultaneo | Media | Alto | Lock + transazione `FOR UPDATE` + idempotency-key |
| Performance calendario con molte stanze | Bassa MVP | Medio | Indici mirati, eventualmente materialized view in MVP3 |
| Hosting WP shared con cron lento | Media | Medio | Documentare setup WP-Cron via system cron |
| Aggiornamenti WP/PHP rompono plugin | Media | Alto | PHPUnit + lock versioni minime nel header plugin |
| Conflitto altri plugin booking | Bassa | Medio | Namespace `em_`, prefissi, capability dedicate |
| Bundle JS pesante in pagina pubblica | Media | Medio | Code splitting Vite, target < 150kb gzip iniziale |
| Refactor multi-location futuro | Alta | Medio | `location_id` già presente ovunque |

---

## 14. GLOSSARIO

| Termine | Significato |
|---|---|
| **Booking** | Prenotazione completa, con stato e cliente associato |
| **Temporary Lock** | Blocco temporaneo di uno slot (max 10min) durante checkout |
| **Slot** | Orario disponibile generato da `room_time_slots` |
| **Game Master** | Operatore che conduce il gioco, può segnare completata |
| **Source** | Origine della prenotazione (online, telefono, walk-in, …) |
| **Soft delete** | Eliminazione logica via `deleted_at`, dati conservati |
| **Idempotency-Key** | Header HTTP per evitare doppie POST per doppio click |

---

## 15. PREREQUISITI AMBIENTE DI SVILUPPO

Prima di partire con Sprint 1 servono:

1. **WordPress locale** funzionante (raccomandato Local by Flywheel, oppure Laragon su Windows).
   - PHP 8.1+
   - MySQL 5.7+ o MariaDB 10.3+
   - URL locale tipo `http://escape-manager.local`

2. **Node.js 20+** + npm 10+ (per Vite e bundle React).

3. **Composer 2+** (per autoloader PSR-4 e PHPUnit futuro).

4. **Editor** con supporto PHP + TypeScript (VS Code consigliato, già in uso).

5. **Browser** moderno per testing manuale (Chrome/Firefox).

6. **Cartella plugin** symlinkata o sviluppata direttamente in:
   `{WP_INSTALL}/wp-content/plugins/escape-manager/`
   oppure sviluppata qui e copiata via script:
   `c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager\`

7. **Tool consigliati:**
   - Postman / Bruno per testare REST API
   - TablePlus / DBeaver per ispezionare MySQL
   - WP-CLI per gestione WordPress da terminale

---

## 16. COMANDO DI AVVIO SVILUPPO

Quando sarai pronto a partire, apri Claude Code in questa cartella e dai questo prompt:

```
Apri il file escape-manager/PROGETTO.md, leggilo per intero,
poi inizia Sprint 1 — Fondamenta plugin.

Crea:
1. escape-manager.php con header WP + autoloader PSR-4
2. includes/class-activator.php con dbDelta per tutte le 20 tabelle elencate
3. includes/auth/class-capabilities.php + class-roles.php con seed iniziale
4. includes/class-database.php per migrazioni versionate (em_db_version)
5. admin/class-admin.php con pagina menu placeholder
6. uninstall.php che NON cancella dati di default

Lavora step by step. Al termine di ogni file fammi un riepilogo
e attendi conferma prima di passare al prossimo.

Non saltare la progettazione del DB: usa esattamente lo schema
documentato in PROGETTO.md sezione 4.
```

---

### Sprint 2 — Estensione DB (oltre quanto già documentato)
A `em_db_version = 2` aggiungere tabella `em_webhook_queue`:
```sql
CREATE TABLE wp_em_webhook_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target VARCHAR(40) NOT NULL DEFAULT 'sottoscacco',
  event_type VARCHAR(40) NOT NULL,
  payload LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  next_attempt_at DATETIME NULL,
  sent_at DATETIME NULL,
  booking_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY status_next (status, next_attempt_at),
  KEY booking_id (booking_id)
);
```

## APPENDICE A — Decisioni architetturali ratificate

- ✅ Stack: WordPress + PHP 8.1 + MySQL + React 18 + Vite + Tailwind + Zustand + TanStack Query
- ✅ DB: tabelle custom (no `wp_posts`), prefisso `wp_em_`, importi in centesimi, datetime UTC
- ✅ Auth: capability WordPress custom `em_*` + nonce REST
- ✅ Anti-overbooking: lock + transazione + idempotency-key
- ✅ Soft delete con `deleted_at`
- ✅ Activity log completo (audit trail)
- ✅ Sviluppo MVP incrementale: MVP1 (5 sprint) → MVP2 → MVP3
- ✅ Nessun pagamento online in MVP1 (solo sul posto)
- ✅ Nessuna integrazione WhatsApp in MVP1 (riservato MVP3)
- ✅ Cartella di sviluppo: `c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager\`

## APPENDICE B — Riferimenti progetto

- Sottoscacco (sistema operativo in sala, Next.js+Firebase): `c:\Users\lucad\Nuovo sistema gestione squadre\sottoscacco\`
- Escape Manager (questo progetto, WordPress plugin): `c:\Users\lucad\Nuovo sistema gestione squadre\escape-manager\`

I due progetti hanno ruoli **complementari, non sovrapposti**:

| Sistema | Ruolo |
|---|---|
| **Escape Manager** (NEW) | Booking pubblico + CRM + calendario + clienti + tariffe + pagamenti — **sostituisce Escape Navigator** |
| **Sottoscacco** (esistente) | Operatività in sala: check-in, sessioni, partecipanti, firme privacy, foto, lavagna, recap, TV, master assegnati, token fisici, dashboard emergenza |

**Flusso dati:**
```
Cliente prenota su sito WP ─→ Escape Manager (booking SOURCE OF TRUTH)
                                       │
                                       │ webhook drop-in (compatibile EN)
                                       ▼
                              Sottoscacco riceve booking e li gestisce in sala
                              (check-in, sessione, partecipanti, foto…)
```

Vedi **APPENDICE C — Bridge Sottoscacco** per il dettaglio del contratto webhook,
e **APPENDICE D — Sostituzione Escape Navigator** per il piano di switchover.

---

## APPENDICE C — Bridge Sottoscacco (sostituzione drop-in di Escape Navigator)

### C.1 Stato attuale (pre-Escape Manager)
Sottoscacco riceve i booking da Escape Navigator tramite webhook:

- **Endpoint:** `POST {SOTTOSCACCO}/api/webhooks/escape-navigator`
- **Auth:** header `Authorization: Bearer <ESCAPE_NAVIGATOR_WEBHOOK_SECRET>` oppure `X-Api-Key`
- **Eventi:** `new-order`, `update-order` (alias `update-order-date`), `cancel-order`
- **Payload:**
  ```json
  {
    "action": "new-order",
    "data": {
      "id": "12345",
      "utcDate": "2026-03-15T13:30:00.000Z",
      "players": 4,
      "client": { "name": "Mario", "surname": "Rossi", "phone": "+39...", "email": "..." },
      "questroom": { "title": "La Cripta", "slug": "la-cripta" },
      "comment": "Compleanno"
    }
  }
  ```
- Sottoscacco salva il booking con:
  - `externalBookingId = data.id`
  - `externalSource = "escape_navigator"`
  - Trova `roomId` matchando `data.questroom.slug` con il campo `slug` della collection `rooms` Firestore (fallback su match per `name`).

### C.2 Approccio: emulazione perfetta del payload EN

Escape Manager **NON modifica Sottoscacco**. Si comporta esattamente come Escape Navigator:
- Stesso endpoint `POST /api/webhooks/escape-navigator`
- Stessa firma Bearer/X-Api-Key
- Stesso formato payload
- Stessi eventi (`new-order`, `update-order`, `cancel-order`)

Sottoscacco continua a vedere `externalSource: "escape_navigator"`. Compatibilità totale,
nessun rischio di rompere il sistema in sala.

### C.3 Nuovo servizio: `Sottoscacco_Bridge_Service`

**File:** `includes/services/class-sottoscacco-bridge-service.php` (Sprint 5, MVP 1)

Responsabilità:
- Ascolta hook `em_booking_status_changed` (emesso da `Booking_Service::transition_to`).
- Per transizioni rilevanti emette webhook outbound:

| Transizione EM | Evento webhook |
|---|---|
| → `confirmed` (da temporary_lock o booking_in_progress) | `new-order` |
| modifiche a `start_datetime` / `total_players` su booking `confirmed` | `update-order` |
| → `cancelled` (da `confirmed`) | `cancel-order` |

### C.4 Schema payload outbound (replica EN)

```php
$payload = [
    'action' => 'new-order',
    'data' => [
        'id'        => 'em-' . $booking->id,        // prefisso per evitare collisioni con vecchi EN
        'utcDate'   => $booking->start_datetime_utc, // ISO 8601 UTC
        'players'   => $booking->total_players,
        'client'    => [
            'name'    => $customer->first_name,
            'surname' => $customer->last_name,
            'phone'   => $customer->phone,
            'email'   => $customer->email,
        ],
        'questroom' => [
            'title' => $room->name,
            'slug'  => $room->slug,
        ],
        'comment'   => $booking->customer_comment,
    ],
];
```

### C.5 Configurazione (nuovi setting EM)

| Setting key | Default | Descrizione |
|---|---|---|
| `em_sottoscacco_bridge_enabled` | `false` | Master switch. Attivare quando switchover OK |
| `em_sottoscacco_webhook_url` | — | `https://sottoscacco.app/api/webhooks/escape-navigator` |
| `em_sottoscacco_webhook_secret` | — | Stesso valore di `ESCAPE_NAVIGATOR_WEBHOOK_SECRET` su Sottoscacco |
| `em_sottoscacco_max_retries` | `5` | Retry exponential backoff |
| `em_sottoscacco_external_id_prefix` | `em-` | Per distinguere booking EM in `external_booking_id` |

### C.6 Affidabilità: outbound queue

I webhook outbound **non vanno mai persi**. Strategia:

**Nuova tabella `em_webhook_queue`** (aggiunta in `em_db_version = 2`, Sprint 2):
```
id, target (sottoscacco), event_type, payload (JSON), status (pending|sending|sent|failed),
attempts, last_error, next_attempt_at, sent_at, booking_id, created_at, updated_at
```

Cron `em_cron_webhook_dispatcher` ogni minuto:
1. Pesca righe `status='pending' AND next_attempt_at <= NOW()` (limit 50).
2. POST → endpoint Sottoscacco.
3. 2xx → `status='sent'`; 4xx → `status='failed'` (no retry); 5xx/timeout → `attempts++`, `next_attempt_at = NOW() + 2^attempts minutes`, max retries da setting.

Dashboard CRM `/integrations/sottoscacco` (MVP 2):
- Stato connessione (test POST con `action='ping'` o health check)
- Coda webhook (pending / failed / sent ultimi 7gg)
- Pulsante "Replay failed"
- Log ultimi 100 invii

### C.7 Allineamento slug stanze (critico)

Lo slug della stanza in Escape Manager **DEVE corrispondere** allo slug della stanza
in Sottoscacco/Firestore. Altrimenti i webhook arrivano e Sottoscacco non trova la stanza.

**Soluzione (Sprint 2):**
- Pre-popolamento di `em_rooms.slug` con valori già usati in Firestore.
- Validation a salvataggio stanza in CRM EM: avvisa se lo slug non esiste in Sottoscacco
  (chiamata GET a `/api/admin/rooms` di Sottoscacco con API key dedicata).
- Setting `em_sottoscacco_admin_api_key` per query inverse a Sottoscacco.

### C.8 Sincronizzazione iniziale (one-shot)

All'attivazione del bridge:
1. EM legge stanze da Sottoscacco (GET `/api/admin/rooms`).
2. Mostra mapping suggerito EM ↔ Sottoscacco.
3. Operatore conferma o corregge gli slug.
4. EM scrive `em_rooms.slug` aggiornati.

Booking storici (pre-switchover) NON vengono importati: restano in Sottoscacco con `externalSource='escape_navigator'` legacy.

---

## APPENDICE D — Sostituzione totale di Escape Navigator

### D.1 Inventario funzionalità EN da coprire

Mappatura cosa fa EN oggi e dove finisce in Escape Manager:

| Funzionalità EN | Coperta in EM | Sprint |
|---|---|---|
| Booking pubblico (widget sito) | ✅ Shortcode `[escape_booking]` | MVP 1 — Sprint 3 |
| Calendario CRM giornaliero/settimanale | ✅ `/calendar` | MVP 1 — Sprint 4 / MVP 2 settimanale |
| Lista bookings + filtri | ✅ `/bookings` | MVP 1 — Sprint 4 |
| Anagrafica clienti | ✅ `/customers` | MVP 1 — Sprint 4 |
| Gestione stanze | ✅ Settings → Escape rooms | MVP 1 — Sprint 5 |
| Tariffe per stanza | ✅ `em_tariffs` | MVP 1 base, MVP 2 avanzate |
| Booking rules (cancellazione, blocco online) | ✅ `em_booking_rules` | MVP 2 |
| Promocodes | ✅ `em_promocodes` | MVP 2 |
| Gift certificates / vouchers | ✅ `em_vouchers` | MVP 3 |
| Pagamento online (Stripe/PayPal) | ✅ | MVP 3 |
| Pagamento sul posto | ✅ | MVP 1 — Sprint 5 |
| Email conferma/cancellazione | ✅ | MVP 1 — Sprint 5 |
| WhatsApp conferma/reminder | ✅ Riusa pattern WATI di Sottoscacco | MVP 3 |
| Ruoli e permessi staff | ✅ `em_roles` + `em_permissions` + capability WP | MVP 1 — Sprint 1 |
| Game master assegnati | ✅ `bookings.assigned_staff_id` + bridge porta info a Sottoscacco | MVP 2 |
| Note/Task su booking | ✅ `em_notes`, `em_tasks` | MVP 1 dettaglio, MVP 2 UI |
| Statistics fatturato/occupazione | ✅ `/statistics` | MVP 3 |
| Activity log audit | ✅ `em_activity_logs` | MVP 1 — Sprint 5 |
| Multi-location | ✅ Schema pronto, UI MVP 3 | MVP 3 |
| Block periods (chiusure straordinarie) | ✅ `em_room_blocked_periods` | MVP 1 schema, MVP 3 UI |
| Cross-sell / Up-sell / Servizi extra | ⚠️ Schema base, UI in MVP 3 | MVP 3 |
| Warehouse / Magazzino | ❌ Out of scope (basso uso reale) | Non pianificato |
| Affiliate program | ❌ Out of scope MVP | Non pianificato |
| API keys per terze parti | ⚠️ Setting predisposta | MVP 3 |
| Custom form fields | ⚠️ Settings predisposta | MVP 2/3 |
| Subscription billing EN-side | N/A — EM è self-hosted, no fee mensile | — |

**Conclusione:** Escape Manager copre il 100% delle funzionalità EN che usi realmente.
Le voci ❌ sono escluse perché out-of-scope per una escape room operativa (warehouse =
magazzino merchandise; affiliate program = commissioni partner). Vanno aggiunte solo
se le usavi davvero.

### D.2 Piano switchover (giorno X)

**Fase 1 — Sviluppo parallelo (MVP 1 + MVP 2 in corso)**
- EN resta sorgente unica: continua a fluire su Sottoscacco.
- EM viene sviluppato e testato in **ambiente staging WordPress separato**.
- Slug stanze allineati EN ↔ EM ↔ Sottoscacco.

**Fase 2 — Test silenzioso (1-2 settimane prima switch)**
- Bridge EM → Sottoscacco attivo ma con `external_id_prefix='em-staging-'` per non collidere.
- Booking di test partono da EM → arrivano a Sottoscacco → si verifica check-in funzionante.
- Verifica end-to-end: prenotazione su sito WP → email → check-in tablet → sessione → recap.

**Fase 3 — Switchover (giorno X)**
- 24h prima: comunicare a clienti via social/sito che il sistema cambia (rare problematiche).
- Su sito WordPress: rimuovere widget EN, attivare shortcode `[escape_booking]`.
- Su Sottoscacco: invariato.
- Su EN: disabilitare creazione nuovi booking (lasciare letture per storico).
- Su EM: abilitare bridge produzione (`em_sottoscacco_bridge_enabled = true`).

**Fase 4 — Coesistenza temporanea (1-2 mesi)**
- EN tenuto attivo solo per consultare booking storici e fatturazione passata.
- Tutti i nuovi booking passano da EM.
- Backup EN scaricati prima di cancellare abbonamento.

**Fase 5 — Cessazione EN (mese 2-3 post-switch)**
- Export finale dati da EN.
- Cancellazione abbonamento EN.
- Eventuale import storico in EM via CSV (se utile per statistiche; opzionale).

### D.3 Rollback plan (se EM va in crash post-switch)

Switchover atomico ma reversibile:
1. **Su sito WP:** sostituisci shortcode EM con widget EN (file `header.php` o pagina).
2. **Su EM:** disabilita bridge (`em_sottoscacco_bridge_enabled = false`).
3. **Su EN:** riattiva creazione booking.
4. I booking creati in EM tra crash e rollback restano in EM (export manuale, eventuale reinserimento EN).

Tempo di rollback realistico: **< 15 minuti**.

### D.4 Backup strategy obbligatoria post-switch
- Backup MySQL DB WP **giornaliero** (cron + S3 o equivalente).
- Snapshot completo plugin file system settimanale.
- Test restore mensile su staging.

---

## APPENDICE E — Funzionalità Sottoscacco che restano in Sottoscacco

Per chiarezza, **Escape Manager NON tocca** queste aree di Sottoscacco:

| Area Sottoscacco | Resta in Sottoscacco | Motivo |
|---|---|---|
| Check-in tablet partecipanti | ✅ | Touch UX in sala, già perfetto |
| Firma privacy/foto/marketing | ✅ | Flusso legale specifico |
| Token fisici (drag & drop) | ✅ | Operatività sala |
| Master assegnato al turno | ✅ + bridge | EM può preassegnare via API, Sottoscacco lo riceve nel webhook esteso |
| Lavagna staff | ✅ | Dashboard real-time sala |
| Recap fine partita | ✅ | Esperienza giocatore |
| TV playlist e gallery | ✅ | Schermo in sala |
| Foto post-partita (overlay, Gelato) | ✅ | Servizio specifico |
| Dashboard emergenza | ✅ | Sicurezza fisica |
| Cashdrawer | ⚠️ Da rivalutare | EM gestisce pagamenti booking; cashdrawer fisico in sala resta Sottoscacco |
| Push notifications staff | ✅ | Operatività |
| WATI integration esistente | ⚠️ Riusa | EM in MVP 3 farà invio via Sottoscacco proxy oppure WATI diretto |

**Regola:** Escape Manager finisce dove inizia l'esperienza fisica del cliente in sala.
Tutto ciò che è "in sala" resta a Sottoscacco.

### E.1 Estensione bridge MVP 2: master assegnati
EM aggiunge campi opzionali al payload webhook (Sottoscacco li ignora se non presenti):
```json
"data": {
  ...
  "assigned_master": { "ninox_id": 12, "name": "Luca" }
}
```
Sottoscacco già supporta `assignedMasterNinoxId` e `assignedMasterName` → estensione trasparente.

---

*Fine documento. Versione 1.1 — 2026-05-18. Aggiunte appendici C/D/E (bridge Sottoscacco + sostituzione EN).*
