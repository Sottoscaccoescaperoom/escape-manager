/**
 * Escape Manager — Booking pubblico (no-build, ESM via CDN).
 *
 * Configurazione iniettata da PHP in window.EM_BOOKING_CONFIG.
 * Monta su #em-booking-root.
 *
 * Per sostituire con un bundle Vite in futuro: tieni stesso ID root
 * e stesso shape di config.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import { useState, useEffect, useMemo, useCallback } from 'https://esm.sh/preact@10.22.0/hooks';
import htm from 'https://esm.sh/htm@3.1.1';

const html = htm.bind(h);

const CONFIG = window.EM_BOOKING_CONFIG || {};
const LS_SESSION_KEY = 'em_booking_session_id';

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
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': CONFIG.nonce,
		},
	};
	if (body) opts.body = JSON.stringify(body);
	const res = await fetch(CONFIG.apiBase + path, opts);
	const data = await res.json().catch(() => ({}));
	if (!res.ok) {
		const err = data?.error || { code: 'HTTP_' + res.status, message: 'Errore di rete' };
		throw err;
	}
	return data;
}

function formatMoney(cents, currency = CONFIG.currency || 'EUR') {
	return (cents / 100).toFixed(2).replace('.', ',') + ' ' + currency;
}

function formatTime(iso) {
	const d = new Date(iso);
	return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: CONFIG.timezone });
}

function formatDate(iso) {
	const d = new Date(iso);
	return d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
}

function todayISO() {
	const d = new Date();
	return d.toISOString().slice(0, 10);
}

// ── Components ──

function Stepper({ current, total }) {
	const steps = ['Data e ora', 'Partecipanti', 'I tuoi dati', 'Riepilogo', 'Conferma'];
	return html`
		<div class="em-stepper">
			${steps.map((label, i) => html`
				<div class=${'em-step ' + (i === current ? 'is-active' : '') + (i < current ? ' is-done' : '')}>
					<span class="em-step-num">${i + 1}</span>
					<span class="em-step-label">${label}</span>
				</div>
			`)}
		</div>
	`;
}

function Countdown({ expiresAt, onExpire }) {
	const [remaining, setRemaining] = useState(0);
	useEffect(() => {
		const tick = () => {
			const diff = new Date(expiresAt).getTime() - Date.now();
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

function Step1_DateRoomSlot({ onPick }) {
	const [date, setDate] = useState(todayISO());
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	useEffect(() => {
		setLoading(true);
		setError(null);
		const qs = new URLSearchParams({ date });
		if (CONFIG.locationId) qs.set('location_id', CONFIG.locationId);
		api('GET', '/availability?' + qs.toString())
			.then(r => setData(r.data || []))
			.catch(e => setError(e.message))
			.finally(() => setLoading(false));
	}, [date]);

	const dateOptions = useMemo(() => {
		const arr = [];
		for (let i = 0; i < 30; i++) {
			const d = new Date();
			d.setDate(d.getDate() + i);
			arr.push(d.toISOString().slice(0, 10));
		}
		return arr;
	}, []);

	return html`
		<div class="em-step1">
			<h2>Scegli data e orario</h2>
			<div class="em-date-picker">
				<label>Data:
					<select value=${date} onChange=${e => setDate(e.target.value)}>
						${dateOptions.map(d => html`<option value=${d}>${formatDate(d + 'T12:00:00')}</option>`)}
					</select>
				</label>
			</div>

			${loading && html`<p class="em-loading">Caricamento orari…</p>`}
			${error && html`<p class="em-error">${error}</p>`}

			${data && data.length === 0 && html`<p class="em-empty">Nessuna stanza disponibile per questa data.</p>`}

			${data && data.map(room => html`
				<div class="em-room-card" key=${room.room_id}>
					${room.image_url && html`<img src=${room.image_url} alt=${room.room_name} />`}
					<div class="em-room-info">
						<h3>${room.room_name}</h3>
						<p class="em-room-meta">${room.duration_minutes} min · ${room.min_players}-${room.max_players} giocatori</p>
						<div class="em-slots">
							${room.slots.length === 0 && html`<p class="em-slot-empty">Nessun orario disponibile</p>`}
							${room.slots.map(slot => html`
								<button
									class=${'em-slot em-slot-' + slot.status}
									disabled=${slot.status !== 'available'}
									onClick=${() => slot.status === 'available' && onPick(room, slot)}
									title=${slot.status === 'locked' ? 'In fase di prenotazione' : slot.status === 'booked' ? 'Prenotato' : slot.status === 'blocked' ? 'Non disponibile' : ''}>
									${formatTime(slot.start)}
								</button>
							`)}
						</div>
					</div>
				</div>
			`)}

			<div class="em-legend">
				<span class="em-legend-item"><span class="em-dot em-dot-available"></span> Disponibile</span>
				<span class="em-legend-item"><span class="em-dot em-dot-locked"></span> In prenotazione da altri</span>
				<span class="em-legend-item"><span class="em-dot em-dot-booked"></span> Prenotato</span>
			</div>
		</div>
	`;
}

function Step2_Participants({ room, onNext, onBack }) {
	const [adults, setAdults] = useState(room.min_players);
	const [children, setChildren] = useState(0);
	const [comment, setComment] = useState('');
	const total = adults + children;
	const valid = total >= room.min_players && total <= room.max_players;

	return html`
		<div class="em-step2">
			<h2>Quanti siete?</h2>
			<p>Stanza: <strong>${room.room_name}</strong> · ${room.min_players}-${room.max_players} giocatori</p>

			<div class="em-num-input">
				<label>Adulti</label>
				<div class="em-counter">
					<button onClick=${() => setAdults(Math.max(0, adults - 1))}>−</button>
					<span>${adults}</span>
					<button onClick=${() => setAdults(adults + 1)}>+</button>
				</div>
			</div>

			<div class="em-num-input">
				<label>Bambini</label>
				<div class="em-counter">
					<button onClick=${() => setChildren(Math.max(0, children - 1))}>−</button>
					<span>${children}</span>
					<button onClick=${() => setChildren(children + 1)}>+</button>
				</div>
			</div>

			${!valid && html`<p class="em-error">Numero totale (${total}) deve essere tra ${room.min_players} e ${room.max_players}.</p>`}

			<label class="em-textarea-label">Note/commento (opzionale)
				<textarea value=${comment} onInput=${e => setComment(e.target.value)} rows="3" placeholder="Compleanno, dedica, esigenze speciali…"></textarea>
			</label>

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({ adults, children, customer_comment: comment })}>Continua</button>
			</div>
		</div>
	`;
}

function Step3_Customer({ requiredFields, onNext, onBack }) {
	const [form, setForm] = useState({
		first_name: '', last_name: '', phone: '', email: '', birthday: '', address: '',
	});
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
					</label>
				`}
				${req.address && html`
					<label>Indirizzo
						<input value=${form.address} onInput=${e => set('address', e.target.value)} />
					</label>
				`}
			</div>

			<div class="em-actions">
				<button class="em-btn em-btn-secondary" onClick=${onBack}>Indietro</button>
				<button class="em-btn em-btn-primary" disabled=${!valid} onClick=${() => onNext({ customer: form })}>Continua</button>
			</div>
		</div>
	`;
}

function Step4_Summary({ room, slot, participants, customer, onConfirm, onBack, submitting, error }) {
	const [method, setMethod] = useState('on_site');
	const [accepted, setAccepted] = useState(false);
	const [promoInput, setPromoInput] = useState('');
	const [promoApplied, setPromoApplied] = useState(null); // { code, discount_cents, type: 'promocode'|'voucher' }
	const [promoError, setPromoError] = useState(null);
	const [validating, setValidating] = useState(false);

	// Stima totale lato client: useremo l'output server per la cifra esatta
	const totalPlayers = participants.adults + participants.children;

	const applyCode = async () => {
		if (!promoInput) return;
		setValidating(true); setPromoError(null);
		try {
			// Prima tenta promocode
			try {
				const r = await api('POST', '/promocodes/validate', { code: promoInput, amount_cents: 9999999 });
				setPromoApplied({ code: r.data.code, discount_cents: r.data.discount_cents, type: 'promocode' });
				return;
			} catch {}
			// Poi tenta voucher
			const r2 = await api('POST', '/vouchers/validate', { code: promoInput, amount_cents: 9999999 });
			setPromoApplied({ code: r2.data.code, discount_cents: r2.data.discount_cents, type: 'voucher' });
		} catch (e) {
			setPromoError(e.message || 'Codice non valido');
			setPromoApplied(null);
		} finally { setValidating(false); }
	};

	const removeCode = () => { setPromoApplied(null); setPromoInput(''); setPromoError(null); };

	const onSubmit = () => {
		const payload = { payment_method: method };
		if (promoApplied) {
			payload[promoApplied.type === 'voucher' ? 'voucher_code' : 'promocode'] = promoApplied.code;
		}
		onConfirm(payload);
	};

	return html`
		<div class="em-step4">
			<h2>Riepilogo</h2>
			<div class="em-summary">
				<div><strong>Stanza:</strong> ${room.room_name}</div>
				<div><strong>Data:</strong> ${formatDate(slot.start)}</div>
				<div><strong>Ora:</strong> ${formatTime(slot.start)}</div>
				<div><strong>Giocatori:</strong> ${participants.adults} adulti${participants.children ? ' + ' + participants.children + ' bambini' : ''}</div>
				<div><strong>Nome:</strong> ${customer.first_name} ${customer.last_name}</div>
				<div><strong>Telefono:</strong> ${customer.phone}</div>
				${customer.email && html`<div><strong>Email:</strong> ${customer.email}</div>`}
				${participants.customer_comment && html`<div><strong>Note:</strong> ${participants.customer_comment}</div>`}
			</div>

			<h3>Codice sconto / Voucher</h3>
			${!promoApplied && html`
				<div class="em-promo-row">
					<input type="text" placeholder="Inserisci codice" value=${promoInput} onInput=${e => setPromoInput(e.target.value.toUpperCase())} />
					<button class="em-btn em-btn-secondary" disabled=${!promoInput || validating} onClick=${applyCode}>${validating ? '…' : 'Applica'}</button>
				</div>
				${promoError && html`<p class="em-error" style="margin-top:0.5rem;">${promoError}</p>`}
			`}
			${promoApplied && html`
				<div class="em-promo-applied">
					✓ <strong>${promoApplied.code}</strong> applicato (sconto fino a ${formatMoney(promoApplied.discount_cents)})
					<button class="em-link" onClick=${removeCode}>rimuovi</button>
				</div>
			`}

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
				<button class="em-btn em-btn-primary" disabled=${!accepted || submitting} onClick=${onSubmit}>
					${submitting ? 'Invio in corso…' : 'Conferma prenotazione'}
				</button>
			</div>
		</div>
	`;
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
		</div>
	`;
}

function LockExpiredModal({ onRestart }) {
	return html`
		<div class="em-modal-backdrop">
			<div class="em-modal">
				<h3>Tempo scaduto</h3>
				<p>Il tempo per completare questa prenotazione è scaduto. Riseleziona un orario.</p>
				<button class="em-btn em-btn-primary" onClick=${onRestart}>Ricomincia</button>
			</div>
		</div>
	`;
}

function App() {
	const [step, setStep] = useState(0);
	const [selectedRoom, setSelectedRoom] = useState(null);
	const [selectedSlot, setSelectedSlot] = useState(null);
	const [lock, setLock] = useState(null);
	const [participants, setParticipants] = useState(null);
	const [customer, setCustomer] = useState(null);
	const [booking, setBooking] = useState(null);
	const [submitting, setSubmitting] = useState(false);
	const [error, setError] = useState(null);
	const [lockExpired, setLockExpired] = useState(false);

	const reset = useCallback(() => {
		setStep(0);
		setSelectedRoom(null);
		setSelectedSlot(null);
		setLock(null);
		setParticipants(null);
		setCustomer(null);
		setBooking(null);
		setError(null);
		setLockExpired(false);
	}, []);

	const pickSlot = useCallback(async (room, slot) => {
		setError(null);
		try {
			const result = await api('POST', '/temporary-lock', {
				room_id: room.room_id,
				start_datetime: slot.start,
				session_id: getSessionId(),
			});
			setSelectedRoom(room);
			setSelectedSlot(slot);
			setLock(result.data);
			setStep(1);
		} catch (e) {
			setError(e.message || 'Errore nella prenotazione dello slot');
			alert(e.message || 'Slot non più disponibile, riprova');
		}
	}, []);

	const confirm = useCallback(async ({ payment_method }) => {
		setSubmitting(true);
		setError(null);
		try {
			const r = await api('POST', '/bookings/public', {
				lock_id: lock.lock_id,
				session_id: getSessionId(),
				adults: participants.adults,
				children: participants.children,
				customer_comment: participants.customer_comment,
				customer,
				payment_method,
			});
			setBooking(r.data);
			setStep(4);
		} catch (e) {
			setError(e.message || 'Errore durante la conferma');
		} finally {
			setSubmitting(false);
		}
	}, [lock, participants, customer]);

	if (lockExpired && step > 0 && step < 4) {
		return html`<${LockExpiredModal} onRestart=${reset} />`;
	}

	return html`
		<div class="em-booking-app">
			<${Stepper} current=${step} />
			${lock && step > 0 && step < 4 && html`<${Countdown} expiresAt=${lock.expires_at} onExpire=${() => setLockExpired(true)} />`}

			${step === 0 && html`<${Step1_DateRoomSlot} onPick=${pickSlot} />`}
			${step === 1 && html`<${Step2_Participants} room=${selectedRoom} onNext=${p => { setParticipants(p); setStep(2); }} onBack=${() => setStep(0)} />`}
			${step === 2 && html`<${Step3_Customer} requiredFields=${CONFIG.requiredFields} onNext=${c => { setCustomer(c.customer); setStep(3); }} onBack=${() => setStep(1)} />`}
			${step === 3 && html`<${Step4_Summary} room=${selectedRoom} slot=${selectedSlot} participants=${participants} customer=${customer} onConfirm=${confirm} onBack=${() => setStep(2)} submitting=${submitting} error=${error} />`}
			${step === 4 && booking && html`<${Step5_Result} booking=${booking} onReset=${reset} />`}
		</div>
	`;
}

const root = document.getElementById('em-booking-root');
if (root) {
	render(h(App), root);
}
