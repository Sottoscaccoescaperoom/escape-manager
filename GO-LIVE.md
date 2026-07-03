# Checklist Go-Live — sostituzione di Escape Navigator su sottoscacco.it

> Obiettivo: mettere in produzione Escape Manager (EM) come sistema di prenotazione
> del sito **sottoscacco.it**, al posto di Escape Navigator (EN), senza interruzioni.
>
> Regola d'oro: **EN resta attivo finché EM non ha superato il test silenzioso.**
> Gestione prenotazioni lato admin = **CRM di EM** (WP admin → Escape Manager).

---

## FASE A — Preparazione (in locale, prima di toccare la produzione)

- [ ] A1. Flusso pubblico nuovo testato end-to-end (calendario → partecipanti → evento → dati → riepilogo/totale → conferma)
- [ ] A2. CRM testato (Fase 4 del TEST_PLAN): calendario, lista prenotazioni, drawer, conferma/annulla, +pagamento, +nota, sposta orario, creazione manuale
- [ ] A3. Email di conferma/cancellazione verificate (Mailpit in locale)
- [ ] A4. Prezzi evento verificati (adulti 30/25/22, ragazzi 15, bimbi gratis, celebrazione −22 da 6 giocatori, regalo +5)
- [ ] A5. Backup del codice EM su git (già fatto: repo Sottoscaccoescaperoom/sottoscacco… → cartella escape-manager)

## FASE B — Installazione su produzione (WP di sottoscacco.it, Hostinger)

- [ ] B1. Accesso WP admin di sottoscacco.it + backup completo del sito (UpdraftPlus o backup hosting) **verificato**
- [ ] B2. PHP 8.1+ attivo (Site Health) e cron WP funzionante
- [ ] B3. Installare il plugin **Escape Manager** (zip o deploy git) e attivarlo
- [ ] B4. **Diagnostica** verde: DB Schema = 3, 6 ruoli, 114 permessi, capability admin 19/19
- [ ] B5. `GET /wp-json/escape-manager/v1/rooms` risponde (anche `{"data":[...]}`)

## FASE C — Configurazione dati reali

- [ ] C1. Creare la **Location** (indirizzo reale che apparirà nel widget settimana)
- [ ] C2. Creare le **Stanze** reali — ⚠️ lo **slug di ogni stanza DEVE coincidere con lo slug della stessa stanza su Firestore/Sottoscacco** (vedi tabella sotto)
- [ ] C3. Inserire gli **orari** (time-slots) di ogni stanza per ogni giorno della settimana
- [ ] C4. Verificare/regolare i **prezzi** in Impostazioni (fasce adulti/ragazzi/bimbi, soglia e sconto celebrazione, add-on regalo)
- [ ] C5. Impostare **email mittente** (nome + indirizzo) in Impostazioni
- [ ] C6. (Opzionale) Creare promocodes/voucher reali

### Tabella allineamento slug stanze (CRITICO) — verificata 2026-07-03
| Stanza | Slug EM | Slug Firestore Sottoscacco | OK? |
|---|---|---|---|
| Biocrisis | `biocrisis` | `biocrisis` | ✅ |
| Death Row | `death-row` | `death-row` | ✅ |
| Fumo di Londra | `fumo-di-londra` | `fumo-di-londra` | ✅ |
| Furto al Museo | `furto-al-museo` | `furto-al-museo` | ✅ |
| Occhio di Ra | `occhio-di-ra` | `occhio-di-ra` | ✅ |
| Red Room | `red-room` | `redroom` | ❌ **DA ALLINEARE** |
| Sottosopra | `sottosopra` | `sottosopra` | ✅ |
| Un'Eredità Perduta | `un-eredita-perduta` | `un-eredita-perduta` | ✅ |

> ⚠️ **Red Room disallineata**: cambiare lo slug EM da `red-room` a `redroom`
> (allinea EM → Firestore, che è la fonte operativa di check-in/lavagna).
> Se anche UN solo slug non combacia → il bridge crea prenotazioni "orfane"
> in Sottoscacco (senza stanza). Fonte Firestore: `app/api/admin/seed-rooms`.

---

## VARIANTE ATTIVA — "Parallelo LIVE" (test 7 giorni senza toccare l'home)

Decisione 2026-07-03: EM va reso **operativo davvero** ma in parallelo, NON
sostituendo EN sull'home. Configurazione durante il test:
- **EN resta LIVE sull'home** per i clienti veri (invariato).
- **EM live su `https://sottoscacco.it/test-booking/`** (shortcode `[escape_booking]`).
- **Bridge ON, prefisso `em-`** (NON `em-staging-`): le prenotazioni fatte da
  EM arrivano DAVVERO nella dashboard con roomId valorizzato.
- Prenotazioni manuali "nel sistema nuovo" = crearle da **Prenotazioni → Nuova**
  (NewBookingFormEM → EM), non dalla vecchia `/bookings/new`.
- A fine test lo switch è solo: disabilita plugin EN + metti `[escape_booking]`
  sull'home. (fase E sotto.)

> ⚠️ **RISCHIO doppia prenotazione**: EN ed EM NON condividono la disponibilità.
> Uno slot preso su EN resta "libero" su EM (e viceversa). Poiché `/test-booking/`
> non è sull'home, tenerlo per test controllati (staff + clienti selezionati),
> NON diffonderlo pubblicamente, per evitare doppi booking sullo stesso orario.

### STATO CONFIGURAZIONE — eseguito via SSH/WP-CLI il 2026-07-03
- [x] Slug Red Room allineato: `red-room` → **`redroom`** (match Firestore) ✔ verificato
- [x] 8 stanze presenti, **58 slot orari ciascuna** ✔
- [x] Prezzi impostati espliciti: adult 30/25/22€, child 15€, soglia celeb 6, sconto 22€, addon 5€ ✔
- [x] Bridge **ON**, prefisso **`em-`**, URL con token → coda webhook **7/7 `sent`, 0 errori** ✔
- [x] Pagina **`/test-booking/`** (post 5132) pubblicata, con shortcode `[escape_booking]` ✔
- [x] Anticipo minimo prenotazione: 120 min ✔
- [x] Location "Sottoscacco" presente (id 1) ✔
- [ ] Location: **indirizzo/città/CAP da compilare** (ora vuoti) — per la week-view
- [ ] Email mittente: `em_email_from_name` impostato; **`em_email_from_address` da impostare** (mailbox reale)
- [ ] **Smoke test finale**: una prenotazione reale da `/test-booking/` su **Red Room** → verificare arrivo in dashboard con stanza valorizzata + check-in

> Tutto il funzionale è pronto: EM è operativo in parallelo. Restano solo
> indirizzo location, email mittente e lo smoke test end-to-end su Red Room.

## FASE D — Bridge verso Sottoscacco (test silenzioso, NON live)

- [ ] D1. CRM → **Bridge Sottoscacco**: URL webhook = `https://app.sottoscacco.it/api/webhooks/escape-navigator`
- [ ] D2. Secret = stesso valore di `ESCAPE_NAVIGATOR_WEBHOOK_SECRET` su Sottoscacco
- [ ] D3. Prefisso external_id = `em-staging-` (per non collidere con le prenotazioni EN reali)
- [ ] D4. **Test connessione** → risposta 2xx
- [ ] D5. Checkbox "Stato bridge" = **OFF** (ancora non attivo)
- [ ] D6. Creare 2-3 prenotazioni di test/giorno via EM → forzare il dispatch → verificare che arrivino in Sottoscacco con `roomId` valorizzato e che il **check-in funzioni**
- [ ] D7. Ripetere per **almeno 7 giorni** con EN ancora sorgente reale → criterio: 0 webhook falliti, 100% match stanze

## FASE E — Switchover (Giorno X)

- [ ] E1. Cambiare prefisso bridge da `em-staging-` a `em-` (produzione)
- [ ] E2. Sul sito: **rimuovere il widget Escape Navigator** dalla pagina di prenotazione
- [ ] E3. Inserire lo shortcode **`[escape_booking]`** nella stessa pagina
- [ ] E4. Verificare che il widget EM si carichi correttamente (front-end)
- [ ] E5. CRM → Bridge → **"Stato bridge" = ON**, salva
- [ ] E6. Eseguire **1 prenotazione reale di prova** end-to-end e verificarne l'arrivo in Sottoscacco + check-in
- [ ] E7. Su EN: **bloccare la creazione di nuove prenotazioni**
- [ ] E8. Monitorare la coda webhook le prime 2-3 ore (0 falliti)

## FASE F — Post-switch (Giorno X → X+7)

- [ ] F1. Monitor coda webhook 0 falliti (ogni 4h il primo giorno, poi 1/giorno)
- [ ] F2. Spot-check email di conferma effettivamente recapitate
- [ ] F3. Feedback operatori in sala (anomalie?)
- [ ] F4. Verifica che Sottoscacco continui a funzionare normalmente
- [ ] Criterio "switch riuscito": 7 giorni senza incidenti, ≥20 prenotazioni processate end-to-end

## FASE G — Dismissione Escape Navigator (X+1 → X+2 mesi)

- [ ] G1. Export finale dati EN (prenotazioni storiche, clienti)
- [ ] G2. (Opzionale) Import storico in EM per le statistiche
- [ ] G3. Disdetta abbonamento EN
- [ ] G4. Rimozione script/asset EN residui dal sito

---

## Piano di ROLLBACK (se qualcosa va storto, < 15 min)
1. Sito: rimetti il widget EN al posto di `[escape_booking]`.
2. CRM → Bridge → "Stato bridge" = OFF.
3. EN: riattiva la creazione prenotazioni.
4. Eventuali prenotazioni nate in EM nel frattempo → export manuale + reinserimento in EN.

*Riferimento completo: `TEST_PLAN.md` (Fasi 11-14).*
