/**
 * Escape Manager — Booking pubblico (no-build, ESM via CDN).
 * UI in stile Escape Navigator: vista Giorno/Settimana, striscia giorni,
 * righe stanza con chip orario (prezzo) e lucchetto per gli slot non disponibili.
 *
 * Configurazione iniettata da PHP in window.EM_BOOKING_CONFIG. Monta su #em-booking-root.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import { useState, useEffect, useMemo, useCallback, useRef } from 'https://esm.sh/preact@10.22.0/hooks';
import htm from 'https://esm.sh/htm@3.1.1';

const html = htm.bind(h);

const CONFIG = window.EM_BOOKING_CONFIG || {};
const LS_SESSION_KEY = 'em_booking_session_id';
const LS_ACTIVE_LOCK = 'em_booking_active_lock';

const DIFFICULTY = { 1: 'Facile', 2: 'Leggero', 3: 'Media', 4: 'Sopra la media', 5: 'Difficile' };
const SLOT_TITLE = { locked: 'In prenotazione da altri', booked: 'Prenotato', blocked: 'Non disponibile' };

function getSessionId() {
	let s = localStorage.getItem(LS_SESSION_KEY);
	if (!s) {
		s = ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, c =>
			(c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
		);
		localStorage.setItem(LS_SESSION_KEY, s);
	}
	return s;
}

async function api(method, path, body = null) {
	const opts = {
		method,
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CONFIG.nonce },
	};
	if (body) opts.body = JSON.stringify(body);
	let res;
	try {
		res = await fetch(CONFIG.apiBase + path, opts);
	} catch (netErr) {
		// §FIX 2026-07-13 (#4) — fetch fallito PRIMA di ricevere risposta:
		// rete assente, DNS, CORS o mixed-content. Distinto dal 403/500 sotto.
		throw { code: 'FETCH_FAILED', message: 'Connessione non riuscita. Controlla la rete e riprova.' };
	}
	const data = await res.json().catch(() => ({}));
	if (!res.ok) {
		// §FIX 2026-07-13 (#4) — Esponi lo stato HTTP reale invece del generico
		// "Errore di rete": così l'errore a schermo dice subito la causa
		// (es. 403 = nonce/cache, 404 = route/permalink, 5xx = errore server).
		if (data?.error) throw data.error;
		const hint = res.status === 403 ? ' (sessione scaduta: ricarica la pagina)'
			: res.status === 404 ? ' (endpoint non trovato)'
			: res.status >= 500 ? ' (errore del server)' : '';
		throw { code: 'HTTP_' + res.status, message: `Errore ${res.status}${hint}` };
	}
	return data;
}

/** Rilascia (lato server) e dimentica l'eventuale lock salvato per questa sessione. */
async function releaseStoredLock() {
	let saved = null;
	try { saved = JSON.parse(localStorage.getItem(LS_ACTIVE_LOCK) || 'null'); } catch (_) {}
	if (saved && saved.lock && saved.lock.lock_id) {
		try { await api('DELETE', `/temporary-lock/${saved.lock.lock_id}?session_id=${encodeURIComponent(getSessionId())}`); } catch (_) {}
	}
	localStorage.removeItem(LS_ACTIVE_LOCK);
}

// ── Formattazione ──

function formatMoney(cents, currency = CONFIG.currency || 'EUR') {
	return (cents / 100).toFixed(2).replace('.', ',') + ' ' + currency;
}
function formatPriceShort(cents) {
	if (cents == null) return '';
	const e = cents / 100;
	return (Number.isInteger(e) ? String(e) : e.toFixed(2).replace('.', ',')) + ' €';
}
function formatTime(iso) {
	const d = new Date(iso);
	return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: CONFIG.timezone });
}
function formatDate(iso) {
	const d = new Date(iso);
	return d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
}

// ── Helper date (TZ-safe, lavorano su 'YYYY-MM-DD' locale) ──

function isoToday() {
	const d = new Date();
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function isoAddDays(iso, n) {
	const d = new Date(iso + 'T00:00:00');
	d.setDate(d.getDate() + n);
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function weekdayShort(iso) {
	// §Fix 2026-07-02 — 3 lettere maiuscole (Gio, Ven, Sab…) per la strip
	// mobile del calendario. Prima erano 2 lettere lowercase ("gi", "ve").
	const s = new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { weekday: 'short' }).replace('.', '');
	const t = s.slice(0, 3);
	return t.charAt(0).toUpperCase() + t.slice(1);
}
function dayNum(iso) {
	return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit' });
}
function monthShort(iso) {
	return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { month: 'short' }).replace('.', '');
}
function dayMonthShort(iso) { return dayNum(iso) + ' ' + monthShort(iso); }
function weekdayLong(iso) { return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { weekday: 'long' }); }
function monthCap(iso) { const m = monthShort(iso); return m.charAt(0).toUpperCase() + m.slice(1); }
function monthLong(iso) { return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { month: 'long' }); }
function monthLongCap(iso) { const m = monthLong(iso); return m.charAt(0).toUpperCase() + m.slice(1); }
function yearOf(iso) { return new Date(iso + 'T00:00:00').getFullYear(); }
function pickerLabel(iso) { return dayNum(iso) + ' ' + monthLong(iso) + ' ' + yearOf(iso); }
/** Minuti dall'inizio giornata, con i turni notte (prima delle 05:00) spinti in fondo. */
function slotMinutes(slot) {
	const m = String(slot && slot.start || '').match(/T(\d{2}):(\d{2})/);
	if (!m) return 0;
	const hh = +m[1];
	let mins = hh * 60 + (+m[2]);
	if (hh < 5) mins += 24 * 60; // 00:30/01:00/02:00… → dopo il 23:30
	return mins;
}
function sortSlots(slots) { return Array.isArray(slots) ? slots.slice().sort((a, b) => slotMinutes(a) - slotMinutes(b)) : []; }
function rangeLabel(startIso) {
	const end = isoAddDays(startIso, 6);
	return dayNum(startIso) + ' ' + monthCap(startIso) + ' — ' + dayNum(end) + ' ' + monthCap(end);
}
function difficultyLabel(n) { return n && DIFFICULTY[n] ? DIFFICULTY[n] : null; }

// ── Calendario (Step 0) in stile Escape Navigator ──

/**
 * §Stanze media 2026-07-02 — l'icona stanza puo' essere immagine o video.
 * Rileviamo dall'estensione dell'URL: mp4/webm/mov/m4v → video autoplay
 * muted loop (necessario per l'autoplay su tutti i browser mobile).
 */
function isVideoMedia(url) {
	return typeof url === 'string' && /\.(mp4|webm|mov|m4v)(\?|$)/i.test(url);
}
function Avatar({ name, img }) {
	if (img && isVideoMedia(img)) {
		return html`<div class="emc-avatar">
			<video src=${img} muted autoplay loop playsinline preload="metadata"></video>
		</div>`;
	}
	return html`<div class="emc-avatar">${img
		? html`<img src=${img} alt=${name} />`
		: html`<span>${(name || '?').charAt(0).toUpperCase()}</span>`}</div>`;
}

function SlotChip({ room, slot, dayDate, onPick }) {
	const avail = slot.status === 'available';
	const slotDate = (slot.start || '').slice(0, 10);
	const crossesDay = dayDate && slotDate && slotDate !== dayDate;
	const hot = avail && slot.hot;
	return html`
		<button
			key=${slot.start}
			class=${'emc-slot ' + (avail ? 'is-available' : 'is-locked') + (hot ? ' is-hot' : '')}
			disabled=${!avail}
			title=${hot ? 'Orario molto richiesto' : (SLOT_TITLE[slot.status] || '')}
			onClick=${() => avail && onPick(room, slot)}>
			${hot ? html`<span class="emc-slot-hot" aria-label="Molto richiesto">🔥</span>` : ''}
			${avail ? html`
				<span class="emc-slot-time">${formatTime(slot.start)}</span>
				${crossesDay ? html`<span class="emc-slot-date">${dayMonthShort(slotDate)}</span>` : ''}
			` : html`<span class="emc-slot-lock">🔒</span>`}
		</button>`;
}

function RoomHead({ room }) {
	const diff = difficultyLabel(room.difficulty);
	return html`
		<div class="emc-room-head">
			<${Avatar} name=${room.room_name} img=${room.image_url} />
			<div>
				<div class="emc-room-name">${room.room_name}</div>
				<div class="emc-room-meta">
					${room.min_players} - ${room.max_players} persone <span class="emc-dot">·</span> ${room.duration_minutes} min.${diff ? html` <span class="emc-dot">·</span> ${diff}` : ''}
				</div>
			</div>
		</div>`;
}

/**
 * §Fix 2026-07-02 — Giornata effettiva con soglia 05:00.
 *
 * Il calendario del cliente deve mostrare i turni notturni (00:00-04:59)
 * come appartenenti al GIORNO PRECEDENTE. Esempio: un turno a 01:00 di
 * venerdi' 3 luglio deve apparire tra i turni di giovedi' 2 luglio.
 *
 * L'API /availability restituisce slot per giorno solare (start_datetime).
 * Qui riceviamo `nDays+1` giorni: per ogni giorno visibile spostiamo i
 * suoi slot < 05:00 al giorno precedente e teniamo per il giorno visibile
 * i suoi slot >= 05:00 + gli slot < 05:00 del giorno successivo.
 *
 * `days` = giorni "visibili" richiesti dall'utente (1 in day-view, 7 in week).
 */
function mergeNightSlots(rawDays, days) {
	if (!Array.isArray(rawDays) || rawDays.length === 0) return rawDays || [];
	const NIGHT_HOUR = 5; // soglia: slot con ora < 5 = giornata precedente
	const isNight = iso => {
		// start_datetime formato "YYYY-MM-DD HH:MM:SS" o ISO "YYYY-MM-DDTHH:MM:SS"
		const m = /T?\s?(\d{2}):/.exec(String(iso).slice(10));
		if (!m) return false;
		return parseInt(m[1], 10) < NIGHT_HOUR;
	};
	// Costruiamo output per i primi `days` giorni (i visibili).
	const out = [];
	for (let i = 0; i < days && i < rawDays.length; i++) {
		const cur = rawDays[i];
		const next = rawDays[i + 1];
		const roomsMerged = (cur.rooms || []).map(room => {
			// Slot del giorno corrente: teniamo quelli >= 05:00 (i notturni
			// sarebbero gia' passati, andrebbero al giorno precedente che
			// noi non stiamo mostrando qui — quindi li perdiamo se e' il
			// primo giorno, ma nella pratica sono pochi/nessuno).
			const dayNotNight = (room.slots || []).filter(s => !isNight(s.start));
			// Slot del giorno dopo, ma solo notturni: appartengono a "cur".
			const nextSameRoom = next ? (next.rooms || []).find(r => r.room_id === room.room_id) : null;
			const nightNext = nextSameRoom ? (nextSameRoom.slots || []).filter(s => isNight(s.start)) : [];
			return { ...room, slots: [...dayNotNight, ...nightNext] };
		});
		out.push({ ...cur, rooms: roomsMerged });
	}
	return out;
}

function Step1_Calendar({ onPick }) {
	const [view, setView] = useState('day');
	const [selectedDate, setSelectedDate] = useState(isoToday());
	const [stripStart, setStripStart] = useState(isoToday());
	const [weekStart, setWeekStart] = useState(isoToday());
	const [selectedRoomId, setSelectedRoomId] = useState(null);
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	const STRIP = 7;
	const WEEKS = 8;

	useEffect(() => {
		setLoading(true); setError(null);
		const qs = new URLSearchParams();
		// §Fix 2026-07-02 — Giornata effettiva con soglia 05:00: gli slot
		// 00:00-04:59 del giorno DOPO fanno parte del giorno mostrato.
		// Fetch 2 giorni consecutivi in vista "day" (7+1 in vista "week")
		// e filtriamo lato client per merge.
		if (view === 'day') { qs.set('date', selectedDate); qs.set('days', '2'); }
		else { qs.set('date', weekStart); qs.set('days', '8'); }
		if (CONFIG.locationId) qs.set('location_id', CONFIG.locationId);
		api('GET', '/availability?' + qs.toString())
			.then(r => setData(mergeNightSlots(r.data || [], view === 'day' ? 1 : 7)))
			.catch(e => setError(e.message))
			.finally(() => setLoading(false));
	}, [view, selectedDate, weekStart]);

	const stripDays = useMemo(() => {
		const arr = [];
		for (let i = 0; i < STRIP; i++) arr.push(isoAddDays(stripStart, i));
		return arr;
	}, [stripStart]);
	const weekPills = useMemo(() => {
		const arr = [];
		for (let i = 0; i < WEEKS; i++) arr.push(isoAddDays(weekStart, i * 7));
		return arr;
	}, [weekStart]);

	// §Fix 2026-07-01 — Non si può navigare al passato dal frontend cliente.
	const today = isoToday();
	const isPast = (iso) => iso < today;
	const canGoPrevStrip = !isPast(isoAddDays(stripStart, -1));
	const canGoPrevWeek = !isPast(isoAddDays(weekStart, -1));

	const onPickDate = (iso) => {
		if (!iso) return;
		if (isPast(iso)) return; // clip al passato
		setSelectedDate(iso);
		setStripStart(iso);
	};
	const dayPrev = () => {
		let ns = isoAddDays(stripStart, -STRIP);
		if (isPast(ns)) ns = today; // non andare oltre oggi
		setStripStart(ns);
		setSelectedDate(ns);
	};
	const dayNext = () => { const ns = isoAddDays(stripStart, STRIP); setStripStart(ns); setSelectedDate(ns); };

	const dayRooms = (view === 'day' && data && data[0]) ? data[0].rooms : [];
	const weekRooms = (view === 'week' && data && data[0]) ? data[0].rooms : [];
	const activeRoom = weekRooms.find(r => r.room_id === selectedRoomId) || weekRooms[0];

	return html`
		<div class="emc">
			<div class="emc-topbar">
				<div class="emc-month">${monthLongCap(selectedDate)}</div>
				<label class="emc-datepick">
					<span class="emc-cal-ico">📅</span>
					<span class="emc-datepick-label">${pickerLabel(selectedDate)}</span>
					<span class="emc-caret">▾</span>
					<input type="date" value=${selectedDate} min=${today} onInput=${e => onPickDate(e.target.value)} />
				</label>
			</div>

			${view === 'day' && html`
				<div class="emc-strip">
					<button class="emc-arrow" onClick=${dayPrev} disabled=${!canGoPrevStrip} aria-label="Precedente">‹</button>
					<div class="emc-days">
						${stripDays.map(iso => html`
							<button key=${iso} class=${'emc-day ' + (iso === selectedDate ? 'is-active' : '') + (isPast(iso) ? ' is-past' : '')} disabled=${isPast(iso)} onClick=${() => onPickDate(iso)}>
								<span class="emc-day-wd">${weekdayShort(iso)}</span>
								<span class="emc-day-dm">${dayNum(iso)}</span>
							</button>`)}
					</div>
					<button class="emc-arrow" onClick=${dayNext} aria-label="Successivo">›</button>
				</div>`}

			${view === 'week' && html`
				<div class="emc-strip">
					<button class="emc-arrow" onClick=${() => { const ns = isoAddDays(weekStart, -7); setWeekStart(isPast(ns) ? today : ns); }} disabled=${!canGoPrevWeek} aria-label="Precedente">‹</button>
					<div class="emc-days">
						${weekPills.map(ws => html`
							<button key=${ws} class=${'emc-day ' + (ws === weekStart ? 'is-active' : '')} onClick=${() => setWeekStart(ws)}>
								<span class="emc-day-wd">${monthShort(ws)}</span>
								<span class="emc-day-dm">${dayNum(ws)} — ${dayNum(isoAddDays(ws, 6))}</span>
							</button>`)}
					</div>
					<button class="emc-arrow" onClick=${() => setWeekStart(isoAddDays(weekStart, 7))} aria-label="Successivo">›</button>
				</div>`}

			${loading && html`<div class="emc-loading">Caricamento orari…</div>`}
			${error && html`<div class="emc-error">${error}</div>`}

			${!loading && !error && view === 'day' && html`
				<div class="emc-body">
					${dayRooms.length === 0 && html`<div class="emc-empty">Nessuna stanza disponibile per questa data.</div>`}
					${dayRooms.map(room => html`
						<div class="emc-room" key=${room.room_id}>
							<${RoomHead} room=${room} />
							<div class="emc-slots">
								${room.slots.length === 0
									? html`<span class="emc-slot-empty">Nessun orario</span>`
									: sortSlots(room.slots).map(slot => SlotChip({ room, slot, dayDate: selectedDate, onPick }))}
							</div>
						</div>`)}
				</div>`}

			${!loading && !error && view === 'week' && (weekRooms.length === 0
				? html`<div class="emc-empty">Nessuna stanza disponibile.</div>`
				: html`
				<div class="emc-weekview">
					<aside class="emc-side">
						${activeRoom && (activeRoom.location_address || activeRoom.location_name) && html`
							<div class="emc-side-loc">
								${activeRoom.location_address ? html`<div>${activeRoom.location_address}</div>` : ''}
								${activeRoom.location_city ? html`<div>${activeRoom.location_city}</div>` : ''}
							</div>`}
						<div class="emc-side-rooms">
							${weekRooms.map(r => html`
								<button key=${r.room_id} class=${'emc-side-room ' + (activeRoom && r.room_id === activeRoom.room_id ? 'is-active' : '')} onClick=${() => setSelectedRoomId(r.room_id)}>
									<${Avatar} name=${r.room_name} img=${r.image_url} />
									<span>${r.room_name}</span>
								</button>`)}
						</div>
					</aside>
					<div class="emc-main">
						${activeRoom && html`
							<${RoomHead} room=${activeRoom} />
							<div class="emc-week-days">
								${data.map(day => {
									const r = day.rooms.find(x => x.room_id === activeRoom.room_id) || { slots: [] };
									return html`
										<div class="emc-week-day" key=${day.date}>
											<div class="emc-week-daylabel">
												<span class="emc-wd-num">${dayMonthShort(day.date)}</span>
												<span class="emc-wd-wd">${weekdayLong(day.date)}</span>
											</div>
											<div class="emc-week-dayslots">
												${r.slots.length === 0
													? html`<span class="emc-slot-empty">—</span>`
													: sortSlots(r.slots).map(slot => SlotChip({ room: r, slot, dayDate: day.date, onPick }))}
											</div>
										</div>`;
								})}
							</div>`}
					</div>
				</div>`)}

		</div>`;
}

// ── Stepper + countdown ──

function Stepper({ current }) {
	const steps = ['Partecipanti', 'Evento', 'I tuoi dati', 'Riepilogo', 'Conferma'];
	return html`
		<div class="em-stepper">
			${steps.map((label, i) => {
				const idx = i + 1; // step 0 è il calendario, non nello stepper
				return html`
					<div class=${'em-step ' + (idx === current ? 'is-active' : '') + (idx < current ? ' is-done' : '')}>
						<span class="em-step-num">${idx}</span>
						<span class="em-step-label">${label}</span>
					</div>`;
			})}
		</div>`;
}

/**
 * §Fix 2026-07-01 — WatchingAlert: sostituisce il vecchio Countdown/lock.
 *
 * Comportamento: dopo un periodo di INATTIVITA' dell'utente (nessun click,
 * scroll o tasto per >= 2 minuti), mostra un breve avviso fittizio
 * "qualcuno sta guardando il tuo turno" per creare urgenza gentile.
 * Timing randomizzato per non sembrare artificiale (2-5 min).
 * L'alert scompare automaticamente dopo 6 secondi e si "arma" di nuovo
 * quando l'utente ricomincia a interagire e resta fermo per un altro
 * ciclo.
 */
function WatchingAlert() {
	const [visible, setVisible] = useState(false);
	const timerRef = useRef(null);
	const hideRef = useRef(null);
	useEffect(() => {
		function arm() {
			if (timerRef.current) clearTimeout(timerRef.current);
			// 2-5 minuti di inattivita' → alert
			const delay = (120 + Math.floor(Math.random() * 180)) * 1000;
			timerRef.current = setTimeout(() => {
				setVisible(true);
				if (hideRef.current) clearTimeout(hideRef.current);
				hideRef.current = setTimeout(() => setVisible(false), 6000);
			}, delay);
		}
		function onActivity() {
			// L'attivita' resetta il timer di inattivita'.
			if (!visible) arm();
		}
		const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
		events.forEach(ev => window.addEventListener(ev, onActivity, { passive: true }));
		arm();
		return () => {
			events.forEach(ev => window.removeEventListener(ev, onActivity));
			if (timerRef.current) clearTimeout(timerRef.current);
			if (hideRef.current) clearTimeout(hideRef.current);
		};
	}, [visible]);
	if (!visible) return null;
	return html`
		<div class="em-watching-alert" role="status" aria-live="polite">
			<span class="em-watching-ico">👀</span>
			<span class="em-watching-text">Qualcun altro sta guardando questo turno in questo momento…</span>
		</div>`;
}

function Countdown({ expiresAt, onExpire }) {
	// Aggiornamento imperativo via ref: il componente si disegna UNA volta e
	// il testo cambia direttamente nel DOM. Niente re-render → niente accumulo
	// di nodi (bug htm/Preact con figli testo che si ripetevano a ogni tick).
	const ref = useRef(null);
	useEffect(() => {
		// expires_at arriva dal server in UTC ('YYYY-MM-DD HH:MM:SS') → interpretazione UTC.
		const target = new Date(String(expiresAt).replace(' ', 'T') + 'Z').getTime();
		const tick = () => {
			const diff = target - Date.now();
			const rem = Math.max(0, Math.floor(diff / 1000));
			const mm = String(Math.floor(rem / 60)).padStart(2, '0');
			const ss = String(rem % 60).padStart(2, '0');
			if (ref.current) ref.current.textContent = '⏱ ' + mm + ':' + ss + ' per completare';
			if (diff <= 0) onExpire();
		};
		tick();
		const id = setInterval(tick, 1000);
		return () => clearInterval(id);
	}, [expiresAt]);
	return html`<div class="em-countdown" ref=${ref}></div>`;
}

function Counter({ label, hint, value, set, min }) {
	const lo = min || 0;
	return html`
		<div class="em-num-input">
			<div class="em-num-label"><span>${label}</span>${hint ? html`<small class="em-hint">${hint}</small>` : ''}</div>
			<div class="em-counter">
				<button onClick=${() => set(Math.max(lo, value - 1))}>−</button>
				<span>${value}</span>
				<button onClick=${() => set(value + 1)}>+</button>
			</div>
		</div>`;
}

function Step2_Participants({ room, onNext, onBack }) {
	const [adults, setAdults] = useState(Math.max(room.min_players, 1));
	const [reduced, setReduced] = useState(0);
	const [free, setFree] = useState(0);
	const players = adults + reduced + free;
	const paying = adults + reduced;                 // adulti + ragazzi (7-12)
	const inRange = players >= room.min_players && players <= room.max_players;
	const kidsOk = paying >= 1 && paying >= free;    // i bambini 0-6 non possono essere la maggioranza
	const valid = inRange && kidsOk;

	return html`
		<div class="em-step2">
			<h2>Quanti siete?</h2>
			<p class="em-muted-p">Stanza <strong>${room.room_name}</strong> · ${room.min_players}-${room.max_players} giocatori</p>

			${Counter({ label: 'Adulti', hint: '13+ anni · tariffa piena', value: adults, set: setAdults })}
			${Counter({ label: 'Ragazzi *', hint: '7-12 anni · ridotto €15', value: reduced, set: setReduced })}
			${Counter({ label: 'Bambini *', hint: '0-6 anni · gratis', value: free, set: setFree })}

			<div class="em-info-box">
				ℹ️ I bambini <strong>0-6 anni</strong> entrano <strong>gratis</strong>; i ragazzi <strong>7-12</strong> pagano un ridotto di <strong>€15</strong>; dai <strong>13 anni</strong> tariffa piena. Il totale esatto lo vedi nel riepilogo.
			</div>
			${(reduced > 0 || free > 0) && html`
				<div class="em-info-box em-info-warn">
					<strong>* Documenti eventualmente richiesti:</strong> il giorno del gioco <em>potrebbero</em> essere richiesti i documenti d'identità dei minori per confermare la tariffa ridotta o l'ingresso gratuito.
				</div>`}

			${!valid && html`<p class="em-error">${
				!kidsOk
					? (paying < 1
						? 'Serve almeno un adulto o un ragazzo (7-12): non si può giocare solo con bambini 0-6 anni.'
						: `I bambini 0-6 anni non possono essere la maggioranza: servono almeno ${free} tra adulti e ragazzi.`)
					: `Il numero totale di giocatori (${players}) deve essere tra ${room.min_players} e ${room.max_players}.`
			}</p>`}

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({ adults, children_reduced: reduced, children_free: free })}>Continua</button>
			</div>
		</div>`;
}

const EVENT_TYPES = [
	{ id: 'standard',       label: 'Gioco standard',     icon: '🎮' },
	{ id: 'compleanno',     label: 'Compleanno',         icon: '🎂' },
	{ id: 'addio_celibato', label: 'Addio al celibato',  icon: '🤵' },
	{ id: 'addio_nubilato', label: 'Addio al nubilato',  icon: '👰' },
	{ id: 'team_building',  label: 'Team building',      icon: '🤝' },
];
const CELEBRATIONS = new Set(['compleanno', 'addio_celibato', 'addio_nubilato']);
const EVENT_LABEL_FIELD = {
	compleanno:     'Nome del festeggiato',
	addio_celibato: 'Nome dello sposo',
	addio_nubilato: 'Nome della sposa',
	team_building:  'Nome azienda',
};
function eventLabelOf(id) { const e = EVENT_TYPES.find(x => x.id === id); return e ? e.label : id; }

function Step_Event({ players, onNext, onBack }) {
	const [eventType, setEventType] = useState('standard');
	const [label, setLabel] = useState('');
	const [comment, setComment] = useState('');
	const [extras, setExtras] = useState([]);            // extra attivi (dalla dashboard)
	const [selectedExtras, setSelectedExtras] = useState([]); // id scelti

	useEffect(() => {
		api('GET', '/event-extras/public').then(r => setExtras(r.data || [])).catch(() => {});
	}, []);

	const isCeleb = CELEBRATIONS.has(eventType);
	const fieldLabel = EVENT_LABEL_FIELD[eventType];
	const labelRequired = isCeleb; // festeggiato obbligatorio; nome azienda opzionale
	const valid = !labelRequired || label.trim().length > 0;

	// Extra applicabili al tipo evento scelto ("all" oppure CSV che contiene l'evento).
	const visibleExtras = extras.filter(ex => {
		const t = (ex.event_types || 'all').trim();
		return t === '' || t === 'all' || t.split(',').map(s => s.trim()).includes(eventType);
	});
	// Cambiando evento, scarto dalla selezione gli extra non più validi.
	useEffect(() => {
		const okIds = new Set(visibleExtras.map(e => e.id));
		setSelectedExtras(prev => prev.filter(id => okIds.has(id)));
	}, [eventType, extras]);
	const toggleExtra = id => setSelectedExtras(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);

	return html`
		<div class="em-step-event">
			<h2>Tipo di evento</h2>
			<div class="em-event-grid">
				${EVENT_TYPES.map(ev => html`
					<button key=${ev.id} class=${'em-event-opt ' + (eventType === ev.id ? 'is-active' : '')} onClick=${() => setEventType(ev.id)}>
						<span class="em-event-ico" data-ico=${ev.icon}></span><span>${ev.label}</span>
					</button>`)}
			</div>

			${isCeleb && players >= 6 && html`<div class="em-info-box em-info-ok">🎉 Siete in ${players}: per questa occasione <strong>una persona non paga</strong> (−€22)!</div>`}
			${isCeleb && players < 6 && html`<div class="em-info-box">Da <strong>6 giocatori</strong> in su, per le occasioni speciali una persona non paga. Ora siete in ${players}.</div>`}

			${fieldLabel && html`
				<label class="em-field">${fieldLabel}${labelRequired ? ' *' : ''}
					<input value=${label} onInput=${e => setLabel(e.target.value)} placeholder=${fieldLabel} />
				</label>`}

			${visibleExtras.length > 0 && html`
				<div class="em-extras">
					<h3 class="em-extras-title">Rendi speciale il tuo evento</h3>
					<p class="em-extras-sub">Aggiungi un tocco in più alla tua esperienza (facoltativo).</p>
					${visibleExtras.map(ex => {
						// §Fix 2026-07-01 — prezzo 0 = "Omaggio"; se e' presente info_url
						// mostriamo un piccolo link "Scopri di piu'" che apre in nuova scheda.
						const cents = parseInt(ex.price_cents, 10) || 0;
						const priceLabel = cents === 0 ? 'Omaggio' : ('+' + formatPriceShort(cents));
						return html`
							<label class="em-addon" key=${ex.id}>
								<input type="checkbox" checked=${selectedExtras.includes(ex.id)} onChange=${() => toggleExtra(ex.id)} />
								<span class="em-addon-body">
									<span class="em-addon-head">
										${ex.title} <strong class=${cents === 0 ? 'em-addon-free' : ''}>${priceLabel}</strong>
									</span>
									${ex.description ? html`<span class="em-addon-desc">${ex.description}</span>` : ''}
									${ex.info_url ? html`<a class="em-addon-link" href=${ex.info_url} target="_blank" rel="noopener noreferrer" onClick=${e => e.stopPropagation()}>Scopri di più ↗</a>` : ''}
								</span>
							</label>`;
					})}
				</div>`}

			<label class="em-field em-field-notes">
				<span class="em-field-label">📝 Qualcosa di importante da segnalarci? <span class="em-optional">(opzionale)</span></span>
				<textarea class="em-notes-textarea" rows="6" value=${comment} onInput=${e => setComment(e.target.value)} placeholder="Scrivi qui le tue note…"></textarea>
				<span class="em-field-hint">Es. dediche, esigenze speciali, fobie o paure di chi partecipa al gioco.</span>
			</label>

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({
					event_type: eventType,
					event_label: label.trim() || null,
					extras: selectedExtras,
					customer_comment: comment.trim() || null,
				})}>Continua</button>
			</div>
		</div>`;
}

/** Validazione stringente del numero per controllarne il più possibile la plausibilità.
 *  Accetta SOLO: cellulare italiano (prefisso 3, 10 cifre esatte, 2ª cifra 1-9) con
 *  eventuale prefisso +39/0039; oppure numero estero in formato internazionale (+...). */
function isValidPhone(raw) {
	const s = (raw || '').replace(/[\s\-().]/g, '');
	// scarta sequenze tutte uguali (es. 3333333333) o palesemente finte
	const digits = s.replace(/^\+/, '');
	if (/^(\d)\1+$/.test(digits)) return false;
	const it   = /^(?:\+39|0039|39)?3[1-9]\d{8}$/; // cellulare IT: 3 + (1-9) + 8 cifre = 10 totali
	const intl = /^\+[1-9]\d{9,14}$/;               // estero: + e 10-15 cifre totali
	return it.test(s) || intl.test(s);
}

function Step3_Customer({ requiredFields, onNext, onBack }) {
	const [form, setForm] = useState({ first_name: '', last_name: '', phone: '+39 ', email: '', birthday: '', address: '' });
	const set = (k, v) => setForm({ ...form, [k]: v });

	const req = requiredFields || {};
	const errors = {};
	// Regola fissa: Nome, Cognome e Cellulare obbligatori; Email opzionale.
	if (!form.first_name.trim()) errors.first_name = 'Nome obbligatorio';
	if (!form.last_name.trim())  errors.last_name  = 'Cognome obbligatorio';
	if (!form.phone.trim())      errors.phone      = 'Cellulare obbligatorio';
	else if (!isValidPhone(form.phone)) errors.phone = 'Numero di cellulare non valido';
	if (form.email.trim() && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email.trim())) errors.email = 'Email non valida';

	const valid = Object.keys(errors).length === 0;

	return html`
		<div class="em-step3">
			<h2>I tuoi dati</h2>
			<div class="em-form-grid">
				<label>Nome *
					<input value=${form.first_name} onInput=${e => set('first_name', e.target.value)} />
					${errors.first_name && html`<small class="em-err">${errors.first_name}</small>`}
				</label>
				<label>Cognome *
					<input value=${form.last_name} onInput=${e => set('last_name', e.target.value)} />
					${errors.last_name && html`<small class="em-err">${errors.last_name}</small>`}
				</label>
				<label>Cellulare *
					<input type="tel" value=${form.phone} onInput=${e => set('phone', e.target.value)} placeholder="+39 333 1234567" />
					${errors.phone && html`<small class="em-err">${errors.phone}</small>`}
				</label>
				<label>Email (opzionale)
					<input type="email" value=${form.email} onInput=${e => set('email', e.target.value)} />
					${errors.email && html`<small class="em-err">${errors.email}</small>`}
				</label>
				${req.birthday && html`
					<label>Data di nascita
						<input type="date" value=${form.birthday} onInput=${e => set('birthday', e.target.value)} />
					</label>`}
				${req.address && html`
					<label>Indirizzo
						<input value=${form.address} onInput=${e => set('address', e.target.value)} />
					</label>`}
			</div>

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({ customer: form })}>Continua</button>
			</div>
		</div>`;
}

function Step4_Summary({ room, slot, participants, event, customer, onConfirm, onBack, submitting, error }) {
	const [method, setMethod] = useState('on_site');
	const [accepted, setAccepted] = useState(false);
	const [quote, setQuote] = useState(null);
	const [qloading, setQloading] = useState(true);
	const [qerror, setQerror] = useState(null);

	useEffect(() => {
		setQloading(true); setQerror(null);
		api('POST', '/bookings/quote', {
			adults: participants.adults,
			children_reduced: participants.children_reduced,
			children_free: participants.children_free,
			event_type: event.event_type,
			extras: event.extras || [],
			code: event.discount_code,
		}).then(r => setQuote(r.data)).catch(e => setQerror(e.message || 'Errore nel calcolo')).finally(() => setQloading(false));
	}, []);

	const playersLabel = () => {
		const p = [];
		if (participants.adults) p.push(participants.adults + ' adulti');
		if (participants.children_reduced) p.push(participants.children_reduced + ' ragazzi (7-12)');
		if (participants.children_free) p.push(participants.children_free + ' bimbi (0-6)');
		return p.join(' + ');
	};

	return html`
		<div class="em-step4">
			<h2>Riepilogo</h2>
			<div class="em-summary">
				<div><strong>Stanza:</strong> ${room.room_name}</div>
				<div><strong>Data:</strong> ${formatDate(slot.start)}</div>
				<div><strong>Ora:</strong> ${formatTime(slot.start)}</div>
				<div><strong>Giocatori:</strong> ${playersLabel()}</div>
				<div><strong>Evento:</strong> ${eventLabelOf(event.event_type)}${event.event_label ? ' · ' + event.event_label : ''}</div>
				<div><strong>Nome:</strong> ${customer.first_name} ${customer.last_name}</div>
				<div><strong>Telefono:</strong> ${customer.phone}</div>
				${customer.email && html`<div><strong>Email:</strong> ${customer.email}</div>`}
				${event.customer_comment && html`<div><strong>Note:</strong> ${event.customer_comment}</div>`}
			</div>

			${qloading && html`<p class="em-muted-p">Calcolo del totale…</p>`}
			${qerror && html`<p class="em-error">${qerror}</p>`}
			${quote && html`
				<div class="em-price-box">
					<div class="em-price-row"><span>Giocatori paganti</span><span>${formatMoney(quote.subtotal_cents)}</span></div>
					${quote.event_discount_cents > 0 && html`<div class="em-price-row em-discount"><span>Sconto ${eventLabelOf(event.event_type)} (1 gratis)</span><span>−${formatMoney(quote.event_discount_cents)}</span></div>`}
					${quote.code_discount_cents > 0 && html`<div class="em-price-row em-discount"><span>Codice ${quote.applied_code || ''}</span><span>−${formatMoney(quote.code_discount_cents)}</span></div>`}
					${quote.addons_cents > 0 && html`<div class="em-price-row"><span>🎁 Regalo nascosto</span><span>+${formatMoney(quote.addons_cents)}</span></div>`}
					${(quote.extras || []).map(x => html`<div class="em-price-row"><span class="em-extra-row" data-ico="✨">${x.title}</span><span>+${formatMoney(x.price_cents)}</span></div>`)}
					<div class="em-price-row em-price-total"><span>Totale in cassa</span><span>${formatMoney(quote.total_cents)}</span></div>
				</div>`}

			<div class="em-giftnote">
				<strong>Hai una gift card o un QR code valido?</strong><br/>
				Presentalo <strong>alla cassa al momento del pagamento</strong>: l'importo verrà <strong>scalato dal totale</strong>. Non serve inserirlo qui — la verifica avviene in sede.
			</div>

			<h3>Metodo di pagamento</h3>
			<div class="em-payment-methods">
				<label class=${'em-payment-option ' + (method === 'on_site' ? 'is-active' : '')}>
					<input type="radio" name="method" value="on_site" checked=${method === 'on_site'} onChange=${() => setMethod('on_site')} />
					Pagamento sul posto
				</label>
			</div>

			<label class="em-terms">
				<input type="checkbox" checked=${accepted} onChange=${e => setAccepted(e.target.checked)} />
				Confermo i dati e accetto le condizioni di prenotazione.
			</label>

			${error && html`<p class="em-error">${error}</p>`}

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack} disabled=${submitting}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!accepted || submitting || qloading} onClick=${() => onConfirm({ payment_method: method })}>
					${submitting ? 'Invio in corso…' : 'Conferma prenotazione'}
				</button>
			</div>
		</div>`;
}

/** Countdown live fino al giorno/ora dell'esperienza (gg/ore/min/sec).
 *  Aggiornamento imperativo via ref (niente re-render → niente duplicazioni). */
function ExperienceCountdown({ target }) {
	const ref = useRef(null);
	useEffect(() => {
		let t = new Date(target).getTime();
		if (isNaN(t)) t = new Date(String(target).replace(' ', 'T')).getTime();
		const cell = (n, l) => `<div class="em-xc-cell"><span class="em-xc-num">${n}</span><span class="em-xc-lbl">${l}</span></div>`;
		const tick = () => {
			let diff = t - Date.now();
			if (diff < 0) diff = 0;
			const d = Math.floor(diff / 86400000);
			const h = Math.floor((diff % 86400000) / 3600000);
			const m = Math.floor((diff % 3600000) / 60000);
			const s = Math.floor((diff % 60000) / 1000);
			if (ref.current) ref.current.innerHTML = cell(d, 'giorni') + cell(String(h).padStart(2, '0'), 'ore') + cell(String(m).padStart(2, '0'), 'min') + cell(String(s).padStart(2, '0'), 'sec');
		};
		tick();
		const id = setInterval(tick, 1000);
		return () => clearInterval(id);
	}, [target]);
	return html`<div class="em-xcount" ref=${ref}></div>`;
}

function Step5_Result({ booking, onReset }) {
	return html`
		<div class="em-step5">
			<img class="em-step5-logo" src="https://app.sottoscacco.it/logo2.png" alt="Sottoscacco" />
			<div class="em-lock" aria-hidden="true">
				<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
					<path class="em-lock-shackle" d="M20 30 V24 a12 12 0 0 1 24 0 V30" />
					<rect class="em-lock-body" x="14" y="30" width="36" height="26" rx="6" />
					<circle class="em-lock-keyhole" cx="32" cy="41" r="3.2" />
					<rect class="em-lock-keyhole" x="30.6" y="42" width="2.8" height="7" rx="1.4" />
				</svg>
			</div>
			<h2>Prenotazione avvenuta con successo!</h2>
			<div class="em-wa">
				<span class="em-wa-ico">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#fff" d="M19.05 4.91A9.82 9.82 0 0 0 12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.004c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.02zM12.04 20.15a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.51.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29z"/></svg>
				</span>
				<span class="em-wa-txt">Messaggio di conferma inviato su <strong>WhatsApp</strong></span>
			</div>
			<p class="em-step5-lead">Controlla il telefono: riceverai a breve il <strong>riepilogo</strong> della prenotazione e tutte le <strong>indicazioni</strong> utili.</p>
			<div class="em-step5-recap">
				<div><strong>Stanza:</strong> ${booking.room?.name}</div>
				<div><strong>Quando:</strong> ${formatDate(booking.start_datetime)} alle ${formatTime(booking.start_datetime)}</div>
				<div><strong>Codice:</strong> ${booking.booking_code}</div>
			</div>
			<p class="em-xc-title">Inizia il conto alla rovescia della tua esperienza indimenticabile</p>
			<${ExperienceCountdown} target=${booking.start_datetime} />
			<p class="em-xc-slogan">Preparatevi… vi aspetta un'avventura indimenticabile da <strong>Sottoscacco</strong>.</p>

			<p class="em-step5-thanks">Grazie per esserti affidato a noi.<br/>Ci vediamo presto!</p>
			<button class="em-btn em-btn-secondary" onClick=${onReset}>Nuova prenotazione</button>
		</div>`;
}

function LockExpiredModal({ onRestart }) {
	return html`
		<div class="em-modal-backdrop">
			<div class="em-modal">
				<h3>Tempo scaduto</h3>
				<p>Il tempo per completare questa prenotazione è scaduto. Riseleziona un orario.</p>
				<button class="em-btn em-btn-primary" onClick=${onRestart}>Ricomincia</button>
			</div>
		</div>`;
}

function App() {
	const [step, setStep] = useState(0);
	const [selectedRoom, setSelectedRoom] = useState(null);
	const [selectedSlot, setSelectedSlot] = useState(null);
	const [lock, setLock] = useState(null);
	const [participants, setParticipants] = useState(null);
	const [eventData, setEventData] = useState(null);
	const [customer, setCustomer] = useState(null);
	const [booking, setBooking] = useState(null);
	const [submitting, setSubmitting] = useState(false);
	const [error, setError] = useState(null);
	const [lockExpired, setLockExpired] = useState(false);

	// §Fix 2026-07-02 — Al cambio step (mobile in particolare) portiamo la
	// vista in cima al widget: senza questo, gli utenti si trovavano al form
	// di compilazione a meta' schermo e dovevano scrollare per vederlo.
	useEffect(() => {
		try {
			const el = document.querySelector('.em-booking-app');
			if (el && typeof el.scrollIntoView === 'function') {
				el.scrollIntoView({ behavior: 'smooth', block: 'start' });
			} else {
				window.scrollTo({ top: 0, behavior: 'smooth' });
			}
		} catch (_) { /* SSR / no-op */ }
	}, [step]);

	// Resume: se ricarico la pagina con un lock ancora valido, riprendo dallo step Partecipanti.
	useEffect(() => {
		let saved = null;
		try { saved = JSON.parse(localStorage.getItem(LS_ACTIVE_LOCK) || 'null'); } catch (_) {}
		if (saved && saved.lock && saved.lock.lock_id && saved.savedAt) {
			const ttlMs = (CONFIG.lockTtlMin || 10) * 60 * 1000;
			if (Date.now() - saved.savedAt < ttlMs) {
				setSelectedRoom(saved.room);
				setSelectedSlot(saved.slot);
				setLock(saved.lock);
				setStep(1);
			} else {
				localStorage.removeItem(LS_ACTIVE_LOCK);
			}
		}
	}, []);

	const reset = useCallback(() => {
		releaseStoredLock();
		setStep(0);
		setSelectedRoom(null);
		setSelectedSlot(null);
		setLock(null);
		setParticipants(null);
		setEventData(null);
		setCustomer(null);
		setBooking(null);
		setError(null);
		setLockExpired(false);
	}, []);

	const pickSlot = useCallback(async (room, slot) => {
		setError(null);
		try {
			// Abbandona un eventuale hold precedente (back/reload) per evitare il blocco "un lock per sessione".
			await releaseStoredLock();
			const result = await api('POST', '/temporary-lock', {
				room_id: room.room_id,
				start_datetime: slot.start,
				session_id: getSessionId(),
			});
			setSelectedRoom(room);
			setSelectedSlot(slot);
			setLock(result.data);
			setStep(1);
			try { localStorage.setItem(LS_ACTIVE_LOCK, JSON.stringify({ lock: result.data, room, slot, savedAt: Date.now() })); } catch (_) {}
		} catch (e) {
			setError(e.message || 'Errore nella prenotazione dello slot');
			alert(e.message || 'Slot non più disponibile, riprova');
		}
	}, []);

	const backToCalendar = useCallback(async () => {
		await releaseStoredLock();
		setLock(null);
		setStep(0);
	}, []);

	const confirm = useCallback(async ({ payment_method }) => {
		setSubmitting(true);
		setError(null);
		try {
			const r = await api('POST', '/bookings/public', {
				lock_id: lock.lock_id,
				session_id: getSessionId(),
				adults: participants.adults,
				children_reduced: participants.children_reduced,
				children_free: participants.children_free,
				event_type: eventData.event_type,
				event_label: eventData.event_label,
				extras: eventData.extras || [],
				customer_comment: eventData.customer_comment,
				customer,
				payment_method,
			});
			localStorage.removeItem(LS_ACTIVE_LOCK);
			setBooking(r.data);
			setStep(5);
		} catch (e) {
			setError(e.message || 'Errore durante la conferma');
		} finally {
			setSubmitting(false);
		}
	}, [lock, participants, eventData, customer]);

	if (lockExpired && step > 0 && step < 5) {
		return html`<${LockExpiredModal} onRestart=${reset} />`;
	}

	const players = participants ? (participants.adults + participants.children_reduced + participants.children_free) : 0;

	return html`
		<div class="em-booking-app">
			${step > 0 && html`<${Stepper} current=${step} />`}
			${/* §Fix 2026-07-01 — Countdown rimosso: non blocchiamo piu' il turno
			     agli altri mentre uno sta prenotando. Vedi WatchingAlert per la
			     notifica "qualcuno sta guardando" (2 min di inattivita'). */ ''}
			${step > 0 && step < 5 && html`<${WatchingAlert} />`}

			${step === 0 && html`<${Step1_Calendar} onPick=${pickSlot} />`}
			${step === 1 && html`<${Step2_Participants} room=${selectedRoom} onNext=${p => { setParticipants(p); setStep(2); }} onBack=${backToCalendar} />`}
			${step === 2 && html`<${Step_Event} players=${players} onNext=${e => { setEventData(e); setStep(3); }} onBack=${() => setStep(1)} />`}
			${step === 3 && html`<${Step3_Customer} requiredFields=${CONFIG.requiredFields} onNext=${c => { setCustomer(c.customer); setStep(4); }} onBack=${() => setStep(2)} />`}
			${step === 4 && html`<${Step4_Summary} room=${selectedRoom} slot=${selectedSlot} participants=${participants} event=${eventData} customer=${customer} onConfirm=${confirm} onBack=${() => setStep(3)} submitting=${submitting} error=${error} />`}
			${step === 5 && booking && html`<${Step5_Result} booking=${booking} onReset=${reset} />`}
		</div>`;
}

const root = document.getElementById('em-booking-root');
if (root) {
	render(h(App), root);
}
