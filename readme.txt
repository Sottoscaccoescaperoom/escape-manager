=== Escape Manager ===
Contributors: lucad
Tags: escape room, booking, crm, prenotazioni
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sistema proprietario di gestione escape room: prenotazioni pubbliche, CRM gestionale, calendario operativo, clienti, staff, pagamenti.

== Description ==

Escape Manager è un sistema completo per la gestione di una escape room, alternativa proprietaria a Escape Navigator.

= Funzionalità (MVP 1) =
* Booking pubblico via shortcode `[escape_booking]`
* Lock temporaneo slot durante checkout
* CRM admin con calendario giornaliero
* Gestione stanze, clienti, prenotazioni
* Sistema ruoli e permessi custom
* API REST sicure

= Roadmap =
* MVP 2: tariffe avanzate, export CSV, note/task UI, promocodes
* MVP 3: pagamento online (Stripe), WhatsApp, statistiche, multi-location, voucher

== Installation ==

1. Carica la cartella `escape-manager` in `wp-content/plugins/`
2. Attiva il plugin dal menu Plugin di WordPress
3. Vai su "Escape Manager" → "Dashboard" per verificare l'installazione
4. Vai su "Escape Manager" → "Diagnostica" per controllare ruoli, permessi e versioni

== Changelog ==

= 0.1.0 =
* Sprint 1: fondamenta plugin, 20 tabelle DB, ruoli e capability, pagina admin placeholder.
