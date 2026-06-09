/**
 * Escape Manager — Booking pubblico (no-build, ESM via CDN).
 * UI in stile Escape Navigator: vista Giorno/Settimana, striscia giorni,
 * righe stanza con chip orario (prezzo) e lucchetto per gli slot non disponibili.
 *
 * Configurazione iniettata da PHP in window.EM_BOOKING_CONFIG. Monta su #em-booking-root.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import { useState, useEffect, useMemo, useCallback } from 'https://esm.sh/preact@10.22.0/hooks';
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
	const res = await fetch(CONFIG.apiBase + path, opts);
	const data = await res.json().catch(() => ({}));
	if (!res.ok) {
		throw data?.error || { code: 'HTTP_' + res.status, message: 'Errore di rete' };
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
	return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { weekday: 'short' }).replace('.', '').slice(0, 2);
}
function dayNum(iso) {
	return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit' });
}
function monthShort(iso) {
	return new Date(iso + 'T00:00:00').toLocaleDateString('it-IT', { month: 'short' }).replace('.', '');
}
function dayMonthShort(iso) { return dayNum(iso) + ' ' + monthShort(iso); }
function pickerLabel(iso) {
	const m = monthShort(iso);
	return dayNum(iso) + ' ' + m.charAt(0).toUpperCase() + m.slice(1);
}
function difficultyLabel(n) { return n && DIFFICULTY[n] ? DIFFICULTY[n] : null; }

// ── Calendario (Step 0) in stile Escape Navigator ──

function Avatar({ name, img }) {
	return html`<div class="emc-avatar">${img
		? html`<img src=${img} alt=${name} />`
		: html`<span>${(name || '?').charAt(0).toUpperCase()}</span>`}</div>`;
}

function SlotChip({ room, slot, dayDate, onPick }) {
	const avail = slot.status === 'available';
	const slotDate = (slot.start || '').slice(0, 10);
	const crossesDay = dayDate && slotDate && slotDate !== dayDate;
	return html`
		<button
			key=${slot.start}
			class=${'emc-slot ' + (avail ? 'is-available' : 'is-locked')}
			disabled=${!avail}
			title=${SLOT_TITLE[slot.status] || ''}
			onClick=${() => avail && onPick(room, slot)}>
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

function Step1_Calendar({ onPick }) {
	const [view, setView] = useState('day');
	const [selectedDate, setSelectedDate] = useState(isoToday());
	const [stripStart, setStripStart] = useState(isoToday());
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	const STRIP = 7;

	useEffect(() => {
		setLoading(true); setError(null);
		const qs = new URLSearchParams();
		if (view === 'day') { qs.set('date', selectedDate); qs.set('days', '1'); }
		else { qs.set('date', stripStart); qs.set('days', '7'); }
		if (CONFIG.locationId) qs.set('location_id', CONFIG.locationId);
		api('GET', '/availability?' + qs.toString())
			.then(r => setData(r.data || []))
			.catch(e => setError(e.message))
			.finally(() => setLoading(false));
	}, [view, selectedDate, stripStart]);

	const stripDays = useMemo(() => {
		const arr = [];
		for (let i = 0; i < STRIP; i++) arr.push(isoAddDays(stripStart, i));
		return arr;
	}, [stripStart]);

	const shift = (n) => {
		const ns = isoAddDays(stripStart, n);
		setStripStart(ns);
		if (view === 'day') setSelectedDate(ns);
	};
	const onPrev = () => shift(view === 'week' ? -7 : -STRIP);
	const onNext = () => shift(view === 'week' ? 7 : STRIP);
	const onPickDate = (iso) => {
		if (!iso) return;
		setSelectedDate(iso);
		setStripStart(iso);
	};

	const dayRooms = (view === 'day' && data && data[0]) ? data[0].rooms : [];
	const weekRooms = (view === 'week' && data && data[0]) ? data[0].rooms : [];

	return html`
		<div class="emc">
			<div class="emc-topbar">
				<div class="emc-toggle">
					<button class=${view === 'week' ? 'is-active' : ''} onClick=${() => setView('week')}>Settimana</button>
					<button class=${view === 'day' ? 'is-active' : ''} onClick=${() => setView('day')}>Giorno</button>
				</div>
				<label class="emc-datepick">
					<span class="emc-cal-ico">📅</span>
					<span class="emc-datepick-label">${pickerLabel(view === 'day' ? selectedDate : stripStart)}</span>
					<span class="emc-caret">▾</span>
					<input type="date" value=${view === 'day' ? selectedDate : stripStart} onInput=${e => onPickDate(e.target.value)} />
				</label>
			</div>

			<div class="emc-strip">
				<button class="emc-arrow" onClick=${onPrev} aria-label="Precedente">‹</button>
				<div class="emc-days">
					${stripDays.map(iso => html`
						<button key=${iso}
							class=${'emc-day ' + (view === 'day' && iso === selectedDate ? 'is-active' : '')}
							onClick=${() => onPickDate(iso)}>
							<span class="emc-day-wd">${weekdayShort(iso)}</span>
							<span class="emc-day-dm">${dayMonthShort(iso)}</span>
						</button>`)}
				</div>
				<button class="emc-arrow" onClick=${onNext} aria-label="Successivo">›</button>
			</div>

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
									: room.slots.map(slot => SlotChip({ room, slot, dayDate: selectedDate, onPick }))}
							</div>
						</div>`)}
				</div>`}

			${!loading && !error && view === 'week' && html`
				<div class="emc-body emc-week">
					${weekRooms.length === 0 && html`<div class="emc-empty">Nessuna stanza disponibile.</div>`}
					${weekRooms.map(r0 => html`
						<div class="emc-room" key=${r0.room_id}>
							<${RoomHead} room=${r0} />
							<div class="emc-week-grid">
								${data.map(day => {
									const r = day.rooms.find(x => x.room_id === r0.room_id) || { slots: [] };
									const avail = r.slots.filter(s => s.status === 'available');
									return html`
										<div class="emc-week-col" key=${day.date}>
											<div class="emc-week-colhead">
												<span class="emc-day-wd">${weekdayShort(day.date)}</span>
												<span class="emc-week-colnum">${dayNum(day.date)}</span>
											</div>
											<div class="emc-week-colslots">
												${avail.length === 0
													? html`<span class="emc-week-empty">—</span>`
													: avail.map(slot => SlotChip({ room: r0, slot, dayDate: day.date, onPick }))}
											</div>
										</div>`;
								})}
							</div>
						</div>`)}
				</div>`}

			<div class="emc-legend">
				<span class="emc-legend-item"><span class="emc-dot-c emc-dot-available"></span> Disponibile</span>
				<span class="emc-legend-item"><span class="emc-dot-c emc-dot-locked"></span> Occupato</span>
			</div>
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

function Countdown({ expiresAt, onExpire }) {
	const [remaining, setRemaining] = useState(0);
	useEffect(() => {
		// expires_at arriva dal server in UTC ('YYYY-MM-DD HH:MM:SS') → forziamo l'interpretazione UTC.
		const target = new Date(String(expiresAt).replace(' ', 'T') + 'Z').getTime();
		const tick = () => {
			const diff = target - Date.now();
			setRemaining(Math.max(0, Math.floor(diff / 1000)));
			if (diff <= 0) onExpire();
		};
		tick();
		const id = setInterval(tick, 1000);
		return () => clearInterval(id);
	}, [expiresAt]);
	const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
	const ss = String(remaining % 60).padStart(2, '0');
	return html`<div class="em-countdown">⏱ ${mm}:${ss} per completare</div>`;
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
	const valid = players >= room.min_players && players <= room.max_players;

	return html`
		<div class="em-step2">
			<h2>Quanti siete?</h2>
			<p class="em-muted-p">Stanza <strong>${room.room_name}</strong> · ${room.min_players}-${room.max_players} giocatori</p>

			${Counter({ label: 'Adulti', hint: '13+ anni · tariffa piena', value: adults, set: setAdults })}
			${Counter({ label: 'Ragazzi', hint: '7-12 anni · ridotto €15', value: reduced, set: setReduced })}
			${Counter({ label: 'Bambini', hint: '0-6 anni · gratis', value: free, set: setFree })}

			<div class="em-info-box">
				ℹ️ I bambini <strong>0-6 anni</strong> entrano <strong>gratis</strong>; i ragazzi <strong>7-12</strong> pagano un ridotto di <strong>€15</strong>; dai <strong>13 anni</strong> tariffa piena. Il totale esatto lo vedi nel riepilogo.
			</div>

			${!valid && html`<p class="em-error">Il numero totale di giocatori (${players}) deve essere tra ${room.min_players} e ${room.max_players}.</p>`}

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
	const [gift, setGift] = useState(false);
	const [comment, setComment] = useState('');
	const [code, setCode] = useState('');

	const isCeleb = CELEBRATIONS.has(eventType);
	const fieldLabel = EVENT_LABEL_FIELD[eventType];
	const labelRequired = isCeleb; // festeggiato obbligatorio; nome azienda opzionale
	const valid = !labelRequired || label.trim().length > 0;

	return html`
		<div class="em-step-event">
			<h2>Tipo di evento</h2>
			<div class="em-event-grid">
				${EVENT_TYPES.map(ev => html`
					<button key=${ev.id} class=${'em-event-opt ' + (eventType === ev.id ? 'is-active' : '')} onClick=${() => setEventType(ev.id)}>
						<span class="em-event-ico">${ev.icon}</span><span>${ev.label}</span>
					</button>`)}
			</div>

			${isCeleb && players >= 6 && html`<div class="em-info-box em-info-ok">🎉 Siete in ${players}: per questa occasione <strong>una persona non paga</strong> (−€22)!</div>`}
			${isCeleb && players < 6 && html`<div class="em-info-box">Da <strong>6 giocatori</strong> in su, per le occasioni speciali una persona non paga. Ora siete in ${players}.</div>`}

			${fieldLabel && html`
				<label class="em-field">${fieldLabel}${labelRequired ? ' *' : ''}
					<input value=${label} onInput=${e => setLabel(e.target.value)} placeholder=${fieldLabel} />
				</label>`}

			<label class="em-addon">
				<input type="checkbox" checked=${gift} onChange=${e => setGift(e.target.checked)} />
				<span>🎁 Nascondi un regalo nella stanza <strong>+€5</strong></span>
			</label>

			<label class="em-field">Note (opzionale)
				<textarea rows="2" value=${comment} onInput=${e => setComment(e.target.value)} placeholder="Allergie, dediche, esigenze speciali…"></textarea>
			</label>

			<label class="em-field">Codice sconto (opzionale)
				<input value=${code} onInput=${e => setCode(e.target.value.toUpperCase())} placeholder="Es. PROMO10" />
			</label>

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({
					event_type: eventType,
					event_label: label.trim() || null,
					addon_gift: gift,
					customer_comment: comment.trim() || null,
					discount_code: code.trim() || null,
				})}>Continua</button>
			</div>
		</div>`;
}

function Step3_Customer({ requiredFields, onNext, onBack }) {
	const [form, setForm] = useState({ first_name: '', last_name: '', phone: '', email: '', birthday: '', address: '' });
	const set = (k, v) => setForm({ ...form, [k]: v });

	const req = requiredFields || {};
	const errors = {};
	if (req.first_name && !form.first_name.trim()) errors.first_name = 'Nome obbligatorio';
	if (req.last_name && !form.last_name.trim()) errors.last_name = 'Cognome obbligatorio';
	if (req.phone && !form.phone.trim()) errors.phone = 'Telefono obbligatorio';
	if (req.email && !form.email.trim()) errors.email = 'Email obbligatoria';
	if (form.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) errors.email = 'Email non valida';
	if (form.phone && !/^\+?[\d\s\-().]{6,}$/.test(form.phone)) errors.phone = 'Telefono non valido';

	const valid = Object.keys(errors).length === 0;

	return html`
		<div class="em-step3">
			<h2>I tuoi dati</h2>
			<div class="em-form-grid">
				<label>Nome ${req.first_name ? '*' : ''}
					<input value=${form.first_name} onInput=${e => set('first_name', e.target.value)} />
					${errors.first_name && html`<small class="em-err">${errors.first_name}</small>`}
				</label>
				<label>Cognome ${req.last_name ? '*' : ''}
					<input value=${form.last_name} onInput=${e => set('last_name', e.target.value)} />
					${errors.last_name && html`<small class="em-err">${errors.last_name}</small>`}
				</label>
				<label>Telefono ${req.phone ? '*' : ''}
					<input type="tel" value=${form.phone} onInput=${e => set('phone', e.target.value)} placeholder="+39…" />
					${errors.phone && html`<small class="em-err">${errors.phone}</small>`}
				</label>
				<label>Email ${req.email ? '*' : ''}
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
			addon_gift: event.addon_gift,
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
					<div class="em-price-row em-price-total"><span>Totale in cassa</span><span>${formatMoney(quote.total_cents)}</span></div>
				</div>`}

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

function Step5_Result({ booking, onReset }) {
	return html`
		<div class="em-step5">
			<div class="em-success-badge">✓</div>
			<h2>Prenotazione confermata!</h2>
			<p>Codice prenotazione: <strong>${booking.booking_code}</strong></p>
			<p>Ti aspettiamo il ${formatDate(booking.start_datetime)} alle ${formatTime(booking.start_datetime)} per <strong>${booking.room?.name}</strong>.</p>
			<p>Riceverai una mail di conferma all'indirizzo fornito.</p>
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
				addon_gift: eventData.addon_gift,
				customer_comment: eventData.customer_comment,
				discount_code: eventData.discount_code,
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
			${lock && step > 0 && step < 5 && html`<${Countdown} expiresAt=${lock.expires_at} onExpire=${() => setLockExpired(true)} />`}

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
