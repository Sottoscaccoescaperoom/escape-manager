/**
 * Escape Manager — CRM v0.3 (MVP 2 features)
 * No-build: Preact + htm via ESM CDN.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import { useState, useEffect, useMemo, useCallback, useRef } from 'https://esm.sh/preact@10.22.0/hooks';
import htm from 'https://esm.sh/htm@3.1.1';

const html = htm.bind(h);
const CONFIG = window.EM_CRM_CONFIG || {};

async function api(method, path, body = null) {
	const opts = {
		method,
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CONFIG.nonce },
		credentials: 'same-origin',
	};
	if (body) opts.body = JSON.stringify(body);
	const res = await fetch(CONFIG.apiBase + path, opts);
	const data = await res.json().catch(() => ({}));
	if (!res.ok) {
		const err = data?.error || { code: 'HTTP_' + res.status, message: 'Errore' };
		throw err;
	}
	return data;
}

function formatMoney(cents) {
	return ((cents || 0) / 100).toFixed(2).replace('.', ',') + ' €';
}

function formatDateTime(iso) {
	if (!iso) return '';
	const d = new Date(iso);
	return d.toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short', timeZone: CONFIG.timezone });
}

function formatTime(iso) {
	if (!iso) return '';
	return new Date(iso).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: CONFIG.timezone });
}

function formatDateShort(iso) {
	if (!iso) return '';
	return new Date(iso).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' });
}

function todayISO() { return new Date().toISOString().slice(0, 10); }
function addDays(iso, d) { const x = new Date(iso + 'T12:00:00'); x.setDate(x.getDate() + d); return x.toISOString().slice(0, 10); }
function startOfWeek(iso) {
	const x = new Date(iso + 'T12:00:00');
	const day = x.getDay() || 7; // 1..7 (Mon..Sun)
	x.setDate(x.getDate() - day + 1);
	return x.toISOString().slice(0, 10);
}

const STATUS_LABELS = {
	temporary_lock: 'Lock temporaneo',
	booking_in_progress: 'In corso',
	confirmed: 'Confermato',
	not_paid: 'Non pagato',
	awaiting_payment: 'In attesa pag.',
	cancelled: 'Annullato',
	unsuccessful_booking: 'Fallito',
	completed: 'Completato',
	no_show: 'No show',
};

// ── Sidebar ──

function Sidebar({ current, onNavigate, perms }) {
	const items = [
		{ id: 'calendar', label: 'Calendario', cap: 'em_view_calendar', icon: '📅' },
		{ id: 'bookings', label: 'Prenotazioni', cap: 'em_view_bookings', icon: '🎟️' },
		{ id: 'customers', label: 'Clienti', cap: 'em_view_customers', icon: '👥' },
		{ id: 'statistics', label: 'Statistiche', cap: 'em_view_statistics', icon: '📊' },
		{ id: 'rooms', label: 'Stanze', cap: 'em_view_rooms', icon: '🚪' },
		{ id: 'tariffs', label: 'Tariffe', cap: 'em_view_settings', icon: '💶' },
		{ id: 'promocodes', label: 'Codici sconto', cap: 'em_view_settings', icon: '🏷️' },
		{ id: 'vouchers', label: 'Voucher', cap: 'em_view_payments', icon: '🎁' },
		{ id: 'settings', label: 'Impostazioni', cap: 'em_view_settings', icon: '⚙️' },
	].filter(item => perms[item.cap]);

	return html`
		<aside class="em-sidebar">
			<div class="em-logo">Escape Manager</div>
			<nav>
				${items.map(item => html`
					<button class=${'em-nav-item ' + (current === item.id ? 'is-active' : '')}
						onClick=${() => onNavigate(item.id)}>
						<span>${item.icon}</span> ${item.label}
					</button>
				`)}
			</nav>
		</aside>
	`;
}

// ── Calendar (giorno + settimana + drag&drop) ──

function CalendarPage({ onOpenBooking, perms }) {
	const [date, setDate] = useState(todayISO());
	const [view, setView] = useState('day'); // 'day' | 'week'
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);
	const [newBooking, setNewBooking] = useState(null); // { room_id, start }

	const load = useCallback(() => {
		setLoading(true); setError(null);
		api('GET', `/calendar?date=${date}&view=${view}`)
			.then(r => setData(r.data))
			.catch(e => setError(e.message))
			.finally(() => setLoading(false));
	}, [date, view]);
	useEffect(() => { load(); }, [load]);

	// Auto-refresh ogni 30s
	useEffect(() => {
		const id = setInterval(load, 30000);
		return () => clearInterval(id);
	}, [load]);

	const onMove = async (bookingId, newRoomId, newStartIso) => {
		try {
			await api('POST', `/bookings/${bookingId}/move`, { room_id: newRoomId, start_datetime: newStartIso });
			load();
		} catch (e) { alert(e.message || 'Spostamento fallito'); }
	};

	if (loading && !data) return html`<p class="em-loading">Caricamento…</p>`;
	if (error) return html`<p class="em-error">${error}</p>`;
	if (!data) return null;

	return html`
		<div class="em-calendar-page">
			<header class="em-page-header">
				<h1>Calendario</h1>
				<div class="em-toolbar">
					<div class="em-view-toggle">
						<button class=${view === 'day' ? 'is-active' : ''} onClick=${() => setView('day')}>Giorno</button>
						<button class=${view === 'week' ? 'is-active' : ''} onClick=${() => setView('week')}>Settimana</button>
					</div>
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(addDays(date, view === 'week' ? -7 : -1))}>◀</button>
					<input type="date" value=${date} onChange=${e => setDate(e.target.value)} />
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(addDays(date, view === 'week' ? 7 : 1))}>▶</button>
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(todayISO())}>Oggi</button>
					<button class="em-btn em-btn-secondary" onClick=${load}>↻</button>
					${perms.em_manage_bookings && html`
						<button class="em-btn em-btn-primary" onClick=${() => setNewBooking({})}>+ Nuova prenotazione</button>
					`}
				</div>
			</header>

			${view === 'day'
				? html`<${DayView} data=${data} onOpenBooking=${onOpenBooking} onMove=${onMove} onEmptySlotClick=${(roomId, slot) => perms.em_manage_bookings && setNewBooking({ room_id: roomId, start: slot.start })} />`
				: html`<${WeekView} data=${data} startDate=${date} onOpenBooking=${onOpenBooking} onMove=${onMove} />`}

			${newBooking && html`<${NewBookingModal} initial=${newBooking} onClose=${() => setNewBooking(null)} onSaved=${() => { setNewBooking(null); load(); }} />`}
		</div>
	`;
}

function DayView({ data, onOpenBooking, onMove, onEmptySlotClick }) {
	const bookingsByRoom = useMemo(() => {
		const m = {};
		(data.rooms || []).forEach(r => { m[r.id] = []; });
		(data.bookings || []).forEach(b => { (m[b.room_id] ||= []).push(b); });
		return m;
	}, [data]);

	const availByRoom = useMemo(() => {
		const m = {};
		(data.availability || []).forEach(r => { m[r.room_id] = r.slots || []; });
		return m;
	}, [data]);

	const onDragStart = (e, booking) => {
		e.dataTransfer.setData('application/json', JSON.stringify({ id: booking.id, originalStart: booking.start_datetime }));
		e.dataTransfer.effectAllowed = 'move';
	};
	const onDragOver = (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; };
	const onDrop = (e, roomId, slot) => {
		e.preventDefault();
		try {
			const payload = JSON.parse(e.dataTransfer.getData('application/json'));
			if (payload && payload.id) {
				onMove(payload.id, roomId, slot.start);
			}
		} catch {}
	};

	return html`
		<div class="em-calendar-grid">
			${(data.rooms || []).map(room => html`
				<div class="em-room-column" key=${room.id}>
					<div class="em-room-header">${room.name}</div>
					<div class="em-room-body">
						${(availByRoom[room.id] || []).map(slot => {
							const matching = (bookingsByRoom[room.id] || []).find(b => formatTime(b.start_datetime) === formatTime(slot.start));
							if (matching) {
								return html`
									<div class=${'em-booking-card em-status-' + matching.booking_status}
										key=${matching.id}
										draggable=${true}
										onDragStart=${e => onDragStart(e, matching)}
										onClick=${() => onOpenBooking(matching.id)}>
										<div class="em-bc-time">${formatTime(matching.start_datetime)}</div>
										<div class="em-bc-name">${matching.customer ? (matching.customer.first_name + ' ' + (matching.customer.last_name || '')) : '—'}</div>
										<div class="em-bc-meta">${matching.total_players} giocatori · ${formatMoney(matching.total_amount)}</div>
										<div class="em-bc-status">${STATUS_LABELS[matching.booking_status] || matching.booking_status}</div>
									</div>
								`;
							}
							const cls = 'em-empty-slot em-slot-' + slot.status;
							return html`
								<div class=${cls} key=${slot.start}
									onDragOver=${slot.status === 'available' ? onDragOver : undefined}
									onDrop=${slot.status === 'available' ? (e => onDrop(e, room.id, slot)) : undefined}
									onClick=${() => slot.status === 'available' && onEmptySlotClick(room.id, slot)}>
									<span>${formatTime(slot.start)}</span>
									<small>${slot.status === 'available' ? '+ aggiungi' : slot.status}</small>
								</div>
							`;
						})}
						${(bookingsByRoom[room.id] || [])
							.filter(b => !(availByRoom[room.id] || []).some(s => formatTime(s.start) === formatTime(b.start_datetime)))
							.map(b => html`
								<div class=${'em-booking-card em-status-' + b.booking_status}
									key=${b.id}
									draggable=${true}
									onDragStart=${e => onDragStart(e, b)}
									onClick=${() => onOpenBooking(b.id)}>
									<div class="em-bc-time">${formatTime(b.start_datetime)}</div>
									<div class="em-bc-name">${b.customer ? (b.customer.first_name + ' ' + (b.customer.last_name || '')) : '—'}</div>
									<div class="em-bc-meta">${b.total_players} giocatori · ${formatMoney(b.total_amount)}</div>
									<div class="em-bc-status">${STATUS_LABELS[b.booking_status]}</div>
								</div>
							`)}
					</div>
				</div>
			`)}
		</div>
	`;
}

function WeekView({ data, startDate, onOpenBooking, onMove }) {
	// startDate è la data scelta; mostriamo 7 giorni a partire da startOfWeek(startDate)
	const weekStart = startOfWeek(startDate);
	const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);

	const bookingsByDayAndRoom = useMemo(() => {
		const m = {};
		(data.bookings || []).forEach(b => {
			const day = b.start_datetime.slice(0, 10);
			(m[day] ||= {});
			(m[day][b.room_id] ||= []).push(b);
		});
		return m;
	}, [data]);

	const rooms = data.rooms || [];

	return html`
		<div class="em-week-view">
			<table class="em-week-table">
				<thead>
					<tr>
						<th></th>
						${days.map(d => html`<th key=${d}>${formatDateShort(d + 'T12:00:00')}</th>`)}
					</tr>
				</thead>
				<tbody>
					${rooms.map(room => html`
						<tr key=${room.id}>
							<th class="em-week-room">${room.name}</th>
							${days.map(d => {
								const list = bookingsByDayAndRoom[d]?.[room.id] || [];
								return html`
									<td key=${d} class="em-week-cell">
										${list.length === 0 ? html`<span class="em-empty-cell">—</span>` :
											list.map(b => html`
												<div class=${'em-week-booking em-status-' + b.booking_status}
													key=${b.id}
													onClick=${() => onOpenBooking(b.id)}>
													${formatTime(b.start_datetime)} · ${b.customer?.first_name || '—'}
													<small>${b.total_players}p · ${formatMoney(b.total_amount)}</small>
												</div>
											`)
										}
									</td>
								`;
							})}
						</tr>
					`)}
				</tbody>
			</table>
		</div>
	`;
}

// ── NewBookingModal (manual, telefono) ──

function NewBookingModal({ initial, onClose, onSaved }) {
	const [rooms, setRooms] = useState([]);
	const [customers, setCustomers] = useState([]);
	const [search, setSearch] = useState('');
	const [form, setForm] = useState({
		room_id: initial.room_id || null,
		start_datetime: initial.start ? new Date(initial.start).toISOString().slice(0, 16) : '',
		adults: 2, children: 0,
		customer_id: null,
		customer: { first_name: '', last_name: '', phone: '', email: '' },
		total_amount_units: 0,
		paid_amount_units: 0,
		payment_method: 'on_site',
		customer_comment: '',
	});
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState(null);

	useEffect(() => {
		api('GET', '/rooms/admin').then(r => {
			setRooms(r.data || []);
			if (!form.room_id && r.data?.[0]) setForm(f => ({ ...f, room_id: r.data[0].id }));
		});
	}, []);

	// Customer search
	useEffect(() => {
		if (search.length < 2) { setCustomers([]); return; }
		const id = setTimeout(() => {
			api('GET', '/customers/search?q=' + encodeURIComponent(search)).then(r => setCustomers(r.data || []));
		}, 250);
		return () => clearTimeout(id);
	}, [search]);

	const set = (k, v) => setForm({ ...form, [k]: v });
	const setCustomerField = (k, v) => setForm({ ...form, customer: { ...form.customer, [k]: v } });

	const selectCustomer = (c) => {
		setForm({ ...form, customer_id: c.id, customer: { first_name: c.first_name, last_name: c.last_name || '', phone: c.phone || '', email: c.email || '' } });
		setSearch('');
		setCustomers([]);
	};

	const room = rooms.find(r => r.id == form.room_id);

	const save = async () => {
		setSaving(true); setError(null);
		try {
			const payload = {
				room_id: parseInt(form.room_id),
				start_datetime: new Date(form.start_datetime).toISOString(),
				adults: parseInt(form.adults),
				children: parseInt(form.children),
				payment_method: form.payment_method,
				customer_comment: form.customer_comment,
				total_amount: Math.round(parseFloat(form.total_amount_units || 0) * 100),
				paid_amount: Math.round(parseFloat(form.paid_amount_units || 0) * 100),
			};
			if (form.customer_id) payload.customer_id = form.customer_id;
			else payload.customer = form.customer;
			await api('POST', '/bookings', payload);
			onSaved();
		} catch (e) {
			setError(e.message || 'Errore');
		} finally { setSaving(false); }
	};

	return html`
		<div class="em-modal-backdrop" onClick=${onClose}>
			<div class="em-modal em-modal-lg" onClick=${e => e.stopPropagation()}>
				<header class="em-modal-header">
					<h2>Nuova prenotazione manuale</h2>
					<button class="em-close" onClick=${onClose}>×</button>
				</header>

				<h3>1. Stanza e orario</h3>
				<div class="em-form-grid">
					<label>Stanza
						<select value=${form.room_id || ''} onChange=${e => set('room_id', e.target.value)}>
							${rooms.map(r => html`<option value=${r.id}>${r.name}</option>`)}
						</select>
					</label>
					<label>Data e ora
						<input type="datetime-local" value=${form.start_datetime} onInput=${e => set('start_datetime', e.target.value)} />
					</label>
					<label>Adulti <input type="number" min="0" value=${form.adults} onInput=${e => set('adults', e.target.value)} /></label>
					<label>Bambini <input type="number" min="0" value=${form.children} onInput=${e => set('children', e.target.value)} /></label>
				</div>
				${room && html`<p class="em-info">Stanza ${room.min_players}-${room.max_players} giocatori, durata ${room.duration_minutes} min</p>`}

				<h3>2. Cliente</h3>
				${form.customer_id && html`
					<div class="em-selected-customer">
						<strong>${form.customer.first_name} ${form.customer.last_name}</strong> · ${form.customer.phone}
						<button class="em-link" onClick=${() => setForm({ ...form, customer_id: null, customer: { first_name: '', last_name: '', phone: '', email: '' } })}>cambia</button>
					</div>
				`}
				${!form.customer_id && html`
					<div class="em-customer-search">
						<input type="search" placeholder="Cerca cliente esistente (nome / telefono / email)…"
							value=${search} onInput=${e => setSearch(e.target.value)} />
						${customers.length > 0 && html`
							<ul class="em-search-results">
								${customers.map(c => html`<li onClick=${() => selectCustomer(c)} key=${c.id}>
									<strong>${c.first_name} ${c.last_name || ''}</strong> · ${c.phone || '—'} · ${c.email || '—'}
								</li>`)}
							</ul>
						`}
						<p class="em-or">— oppure inserisci nuovo cliente —</p>
						<div class="em-form-grid">
							<label>Nome <input value=${form.customer.first_name} onInput=${e => setCustomerField('first_name', e.target.value)} /></label>
							<label>Cognome <input value=${form.customer.last_name} onInput=${e => setCustomerField('last_name', e.target.value)} /></label>
							<label>Telefono <input type="tel" value=${form.customer.phone} onInput=${e => setCustomerField('phone', e.target.value)} /></label>
							<label>Email <input type="email" value=${form.customer.email} onInput=${e => setCustomerField('email', e.target.value)} /></label>
						</div>
					</div>
				`}

				<h3>3. Importo e pagamento</h3>
				<div class="em-form-grid">
					<label>Totale (€) <input type="number" step="0.01" value=${form.total_amount_units} onInput=${e => set('total_amount_units', e.target.value)} /></label>
					<label>Pagato (€) <input type="number" step="0.01" value=${form.paid_amount_units} onInput=${e => set('paid_amount_units', e.target.value)} /></label>
					<label>Metodo
						<select value=${form.payment_method} onChange=${e => set('payment_method', e.target.value)}>
							<option value="on_site">Sul posto</option>
							<option value="cash">Contanti</option>
							<option value="card">Carta</option>
							<option value="transfer">Bonifico</option>
							<option value="voucher">Voucher</option>
						</select>
					</label>
				</div>

				<label class="em-textarea-label">Note interne / commento cliente
					<textarea rows="2" value=${form.customer_comment} onInput=${e => set('customer_comment', e.target.value)}></textarea>
				</label>

				${error && html`<p class="em-error">${error}</p>`}

				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" disabled=${saving} onClick=${save}>${saving ? 'Salvataggio…' : 'Crea prenotazione'}</button>
				</div>
			</div>
		</div>
	`;
}

// ── Bookings list (con export CSV) ──

function BookingsPage({ onOpenBooking, perms }) {
	const [rows, setRows] = useState([]);
	const [filters, setFilters] = useState({ from: todayISO(), to: addDays(todayISO(), 60), status: '', payment_status: '', room_id: '' });
	const [rooms, setRooms] = useState([]);
	const [loading, setLoading] = useState(false);

	useEffect(() => { api('GET', '/rooms/admin').then(r => setRooms(r.data || [])); }, []);

	const load = useCallback(() => {
		setLoading(true);
		const qs = new URLSearchParams();
		Object.entries(filters).forEach(([k, v]) => { if (v) qs.set(k, v); });
		api('GET', '/bookings?' + qs.toString())
			.then(r => setRows(r.data || []))
			.finally(() => setLoading(false));
	}, [filters]);
	useEffect(() => { load(); }, [load]);

	const exportCsv = () => {
		const qs = new URLSearchParams();
		Object.entries(filters).forEach(([k, v]) => { if (v) qs.set(k, v); });
		window.open(CONFIG.apiBase + '/export/bookings.csv?' + qs.toString() + '&_wpnonce=' + CONFIG.nonce, '_blank');
	};

	return html`
		<div class="em-bookings-page">
			<header class="em-page-header">
				<h1>Prenotazioni</h1>
				${perms.em_export_data && html`<button class="em-btn em-btn-secondary" onClick=${exportCsv}>📥 Esporta CSV</button>`}
			</header>
			<div class="em-filters">
				<label>Da <input type="date" value=${filters.from} onChange=${e => setFilters({ ...filters, from: e.target.value })} /></label>
				<label>A <input type="date" value=${filters.to} onChange=${e => setFilters({ ...filters, to: e.target.value })} /></label>
				<label>Stato
					<select value=${filters.status} onChange=${e => setFilters({ ...filters, status: e.target.value })}>
						<option value="">Tutti</option>
						${Object.entries(STATUS_LABELS).map(([k, v]) => html`<option value=${k}>${v}</option>`)}
					</select>
				</label>
				<label>Pagamento
					<select value=${filters.payment_status} onChange=${e => setFilters({ ...filters, payment_status: e.target.value })}>
						<option value="">Tutti</option>
						<option value="unpaid">Non pagato</option>
						<option value="partially_paid">Parziale</option>
						<option value="paid">Pagato</option>
						<option value="refunded">Rimborsato</option>
					</select>
				</label>
				<label>Stanza
					<select value=${filters.room_id} onChange=${e => setFilters({ ...filters, room_id: e.target.value })}>
						<option value="">Tutte</option>
						${rooms.map(r => html`<option value=${r.id}>${r.name}</option>`)}
					</select>
				</label>
			</div>

			${loading && html`<p class="em-loading">Caricamento…</p>`}

			<table class="widefat striped em-table">
				<thead>
					<tr><th>Quando</th><th>Codice</th><th>Stanza</th><th>Cliente</th><th>Telefono</th><th>Giocatori</th><th>Totale</th><th>Pagato</th><th>Stato</th></tr>
				</thead>
				<tbody>
					${rows.length === 0 && html`<tr><td colspan="9"><em>Nessuna prenotazione.</em></td></tr>`}
					${rows.map(b => html`
						<tr key=${b.id} class="em-row-click" onClick=${() => onOpenBooking(b.id)}>
							<td>${formatDateTime(b.start_datetime)}</td>
							<td><code>${b.booking_code}</code></td>
							<td>${b.room?.name || '—'}</td>
							<td>${b.customer ? (b.customer.first_name + ' ' + (b.customer.last_name || '')) : '—'}</td>
							<td>${b.customer?.phone || '—'}</td>
							<td>${b.total_players}</td>
							<td>${formatMoney(b.total_amount)}</td>
							<td>${formatMoney(b.paid_amount)}</td>
							<td><span class=${'em-pill em-status-' + b.booking_status}>${STATUS_LABELS[b.booking_status]}</span></td>
						</tr>
					`)}
				</tbody>
			</table>
		</div>
	`;
}

// ── Customers ──

function CustomersPage() {
	const [rows, setRows] = useState([]);
	const [q, setQ] = useState('');
	const [loading, setLoading] = useState(false);

	const load = useCallback(() => {
		setLoading(true);
		const path = q.length >= 2 ? '/customers/search?q=' + encodeURIComponent(q) : '/customers';
		api('GET', path).then(r => setRows(r.data || [])).finally(() => setLoading(false));
	}, [q]);
	useEffect(() => { const id = setTimeout(load, 300); return () => clearTimeout(id); }, [load]);

	return html`
		<div class="em-customers-page">
			<header class="em-page-header"><h1>Clienti</h1></header>
			<input type="search" placeholder="Cerca per nome, telefono, email…" value=${q} onInput=${e => setQ(e.target.value)} class="em-search-input" />
			${loading && html`<p class="em-loading">Caricamento…</p>`}
			<table class="widefat striped em-table">
				<thead><tr><th>Nome</th><th>Telefono</th><th>Email</th><th>Prenotazioni</th><th>Ultima visita</th></tr></thead>
				<tbody>
					${rows.length === 0 && html`<tr><td colspan="5"><em>Nessun cliente.</em></td></tr>`}
					${rows.map(c => html`
						<tr key=${c.id}>
							<td>${c.first_name} ${c.last_name || ''}</td>
							<td>${c.phone || '—'}</td>
							<td>${c.email || '—'}</td>
							<td>${c.total_bookings || 0}</td>
							<td>${c.last_booking_date ? formatDateTime(c.last_booking_date) : '—'}</td>
						</tr>
					`)}
				</tbody>
			</table>
		</div>
	`;
}

// ── Statistics ──

function StatisticsPage() {
	const [from, setFrom] = useState(addDays(todayISO(), -30));
	const [to, setTo] = useState(todayISO());
	const [stats, setStats] = useState(null);
	const [loading, setLoading] = useState(false);

	const load = useCallback(() => {
		setLoading(true);
		api('GET', `/statistics/overview?from=${from}&to=${to}`)
			.then(r => setStats(r.data))
			.finally(() => setLoading(false));
	}, [from, to]);
	useEffect(() => { load(); }, [load]);

	if (loading || !stats) return html`<p class="em-loading">Caricamento statistiche…</p>`;

	const maxByDay = Math.max(1, ...stats.by_day.map(d => d.revenue_cents));
	const maxByRoom = Math.max(1, ...stats.by_room.map(r => r.revenue_cents));

	return html`
		<div class="em-stats-page">
			<header class="em-page-header">
				<h1>Statistiche</h1>
				<div class="em-toolbar">
					<label>Da <input type="date" value=${from} onChange=${e => setFrom(e.target.value)} /></label>
					<label>A <input type="date" value=${to} onChange=${e => setTo(e.target.value)} /></label>
				</div>
			</header>

			<div class="em-kpi-grid">
				<div class="em-kpi"><h3>Prenotazioni</h3><div class="em-kpi-value">${stats.total_bookings}</div><small>${stats.confirmed} confermate · ${stats.cancelled} annullate</small></div>
				<div class="em-kpi"><h3>Fatturato totale</h3><div class="em-kpi-value">${formatMoney(stats.total_revenue_cents)}</div><small>incassato: ${formatMoney(stats.paid_revenue_cents)}</small></div>
				<div class="em-kpi"><h3>Occupazione</h3><div class="em-kpi-value">${stats.occupancy_rate}%</div><small>slot prenotati/totali</small></div>
				<div class="em-kpi"><h3>Party size media</h3><div class="em-kpi-value">${stats.avg_party_size}</div><small>giocatori per prenotazione</small></div>
				<div class="em-kpi"><h3>Completate</h3><div class="em-kpi-value">${stats.completed}</div><small>no-show: ${stats.no_show}</small></div>
			</div>

			<h2>Fatturato per stanza</h2>
			<div class="em-bar-chart">
				${stats.by_room.length === 0 && html`<p class="em-empty">Nessun dato</p>`}
				${stats.by_room.map(r => html`
					<div class="em-bar-row">
						<span class="em-bar-label">${r.room_name}</span>
						<div class="em-bar-track">
							<div class="em-bar-fill" style=${`width: ${(r.revenue_cents / maxByRoom * 100).toFixed(1)}%`}></div>
						</div>
						<span class="em-bar-val">${formatMoney(r.revenue_cents)} (${r.count})</span>
					</div>
				`)}
			</div>

			<h2>Fatturato per giorno</h2>
			<div class="em-bar-chart">
				${stats.by_day.length === 0 && html`<p class="em-empty">Nessun dato</p>`}
				${stats.by_day.map(d => html`
					<div class="em-bar-row">
						<span class="em-bar-label">${d.day}</span>
						<div class="em-bar-track">
							<div class="em-bar-fill" style=${`width: ${(d.revenue_cents / maxByDay * 100).toFixed(1)}%`}></div>
						</div>
						<span class="em-bar-val">${formatMoney(d.revenue_cents)} (${d.count})</span>
					</div>
				`)}
			</div>
		</div>
	`;
}

// ── Promocodes ──

function PromocodesPage() {
	const [rows, setRows] = useState([]);
	const [editing, setEditing] = useState(null);

	const load = useCallback(() => api('GET', '/promocodes').then(r => setRows(r.data || [])), []);
	useEffect(() => { load(); }, [load]);

	const save = async (data) => {
		try {
			if (data.id) await api('PUT', `/promocodes/${data.id}`, data);
			else await api('POST', '/promocodes', data);
			setEditing(null); load();
		} catch (e) { alert(e.message); }
	};

	return html`
		<div class="em-promo-page">
			<header class="em-page-header">
				<h1>Codici sconto</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setEditing({ code: '', type: 'percent', value: 10, is_active: 1 })}>+ Nuovo codice</button>
			</header>

			<table class="widefat striped em-table">
				<thead><tr><th>Codice</th><th>Tipo</th><th>Valore</th><th>Usi</th><th>Validità</th><th>Stato</th><th></th></tr></thead>
				<tbody>
					${rows.length === 0 && html`<tr><td colspan="7"><em>Nessun codice.</em></td></tr>`}
					${rows.map(p => html`
						<tr key=${p.id}>
							<td><code>${p.code}</code></td>
							<td>${p.type === 'percent' ? '%' : '€ fissi'}</td>
							<td>${p.type === 'percent' ? p.value + '%' : formatMoney(p.value)}</td>
							<td>${p.used_count}${p.usage_limit ? '/' + p.usage_limit : ''}</td>
							<td>${p.valid_from || '—'} → ${p.valid_to || '—'}</td>
							<td>${p.is_active == 1 ? '✅' : '⛔'}</td>
							<td><button class="em-btn em-btn-secondary" onClick=${() => setEditing(p)}>Modifica</button></td>
						</tr>
					`)}
				</tbody>
			</table>

			${editing && html`<${PromoEditModal} item=${editing} onClose=${() => setEditing(null)} onSave=${save} />`}
		</div>
	`;
}

function PromoEditModal({ item, onClose, onSave }) {
	const [form, setForm] = useState({ ...item, value_units: item.type === 'fixed' ? (item.value / 100).toFixed(2) : item.value });
	const set = (k, v) => setForm({ ...form, [k]: v });

	const handleSave = () => {
		const data = { ...form };
		data.value = data.type === 'fixed'
			? Math.round(parseFloat(data.value_units || 0) * 100)
			: parseInt(data.value_units || 0);
		delete data.value_units;
		onSave(data);
	};

	return html`
		<div class="em-modal-backdrop" onClick=${onClose}>
			<div class="em-modal" onClick=${e => e.stopPropagation()}>
				<h2>${form.id ? 'Modifica codice' : 'Nuovo codice'}</h2>
				<label class="em-textarea-label">Codice <input value=${form.code || ''} onInput=${e => set('code', e.target.value.toUpperCase())} placeholder="ESTATE25" /></label>
				<div class="em-form-grid">
					<label>Tipo
						<select value=${form.type} onChange=${e => set('type', e.target.value)}>
							<option value="percent">Percentuale</option>
							<option value="fixed">Importo fisso (€)</option>
						</select>
					</label>
					<label>${form.type === 'percent' ? 'Valore %' : 'Valore €'}
						<input type="number" step=${form.type === 'percent' ? '1' : '0.01'} value=${form.value_units} onInput=${e => set('value_units', e.target.value)} />
					</label>
					<label>Limite usi (vuoto = illimitato)
						<input type="number" value=${form.usage_limit || ''} onInput=${e => set('usage_limit', e.target.value ? parseInt(e.target.value) : null)} />
					</label>
					<label>Valido da (vuoto = subito)
						<input type="datetime-local" value=${form.valid_from || ''} onInput=${e => set('valid_from', e.target.value)} />
					</label>
					<label>Valido fino a (vuoto = illimitato)
						<input type="datetime-local" value=${form.valid_to || ''} onInput=${e => set('valid_to', e.target.value)} />
					</label>
					<label>Attivo
						<select value=${form.is_active ? '1' : '0'} onChange=${e => set('is_active', parseInt(e.target.value))}>
							<option value="1">Sì</option>
							<option value="0">No</option>
						</select>
					</label>
				</div>
				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${handleSave}>Salva</button>
				</div>
			</div>
		</div>
	`;
}

// ── Vouchers ──

function VouchersPage() {
	const [rows, setRows] = useState([]);
	const [creating, setCreating] = useState(false);

	const load = useCallback(() => api('GET', '/vouchers').then(r => setRows(r.data || [])), []);
	useEffect(() => { load(); }, [load]);

	const issue = async (data) => {
		try {
			await api('POST', '/vouchers', data);
			setCreating(false); load();
		} catch (e) { alert(e.message); }
	};

	return html`
		<div class="em-vouchers-page">
			<header class="em-page-header">
				<h1>Voucher / Gift cards</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setCreating(true)}>+ Nuovo voucher</button>
			</header>

			<table class="widefat striped em-table">
				<thead><tr><th>Codice</th><th>Importo</th><th>Cliente</th><th>Stato</th><th>Valido fino a</th><th>Creato</th></tr></thead>
				<tbody>
					${rows.length === 0 && html`<tr><td colspan="6"><em>Nessun voucher.</em></td></tr>`}
					${rows.map(v => html`
						<tr key=${v.id}>
							<td><code>${v.code}</code></td>
							<td>${formatMoney(v.amount)}</td>
							<td>${v.customer_id || '—'}</td>
							<td><span class=${'em-pill em-status-' + (v.status === 'active' ? 'confirmed' : 'cancelled')}>${v.status}</span></td>
							<td>${v.valid_until || '—'}</td>
							<td>${formatDateTime(v.created_at)}</td>
						</tr>
					`)}
				</tbody>
			</table>

			${creating && html`<${VoucherCreateModal} onClose=${() => setCreating(false)} onSave=${issue} />`}
		</div>
	`;
}

function VoucherCreateModal({ onClose, onSave }) {
	const [amount, setAmount] = useState('50.00');
	const [validUntil, setValidUntil] = useState('');
	const [customer, setCustomer] = useState({ first_name: '', last_name: '', phone: '', email: '' });

	const handleSave = () => {
		onSave({
			amount_cents: Math.round(parseFloat(amount || 0) * 100),
			valid_until: validUntil || null,
			customer: customer.first_name ? customer : null,
		});
	};

	return html`
		<div class="em-modal-backdrop" onClick=${onClose}>
			<div class="em-modal" onClick=${e => e.stopPropagation()}>
				<h2>Nuovo voucher / gift card</h2>
				<div class="em-form-grid">
					<label>Importo (€) <input type="number" step="0.01" value=${amount} onInput=${e => setAmount(e.target.value)} /></label>
					<label>Valido fino a <input type="date" value=${validUntil} onInput=${e => setValidUntil(e.target.value)} /></label>
				</div>
				<h3>Cliente destinatario (opzionale)</h3>
				<div class="em-form-grid">
					<label>Nome <input value=${customer.first_name} onInput=${e => setCustomer({ ...customer, first_name: e.target.value })} /></label>
					<label>Cognome <input value=${customer.last_name} onInput=${e => setCustomer({ ...customer, last_name: e.target.value })} /></label>
					<label>Telefono <input value=${customer.phone} onInput=${e => setCustomer({ ...customer, phone: e.target.value })} /></label>
					<label>Email <input type="email" value=${customer.email} onInput=${e => setCustomer({ ...customer, email: e.target.value })} /></label>
				</div>
				<p class="em-info">Il codice voucher verrà generato automaticamente.</p>
				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${handleSave}>Crea voucher</button>
				</div>
			</div>
		</div>
	`;
}

// ── Rooms (compat MVP1) ──

function RoomsPage() {
	const [rows, setRows] = useState([]);
	const [locations, setLocations] = useState([]);
	const [editing, setEditing] = useState(null);

	const load = useCallback(() => {
		Promise.all([api('GET', '/rooms/admin'), api('GET', '/locations')])
			.then(([r, l]) => { setRows(r.data || []); setLocations(l.data || []); });
	}, []);
	useEffect(() => { load(); }, [load]);

	const save = async (data) => {
		if (data.id) await api('PUT', `/rooms/${data.id}`, data);
		else await api('POST', '/rooms', data);
		setEditing(null); load();
	};

	return html`
		<div class="em-rooms-page">
			<header class="em-page-header">
				<h1>Stanze</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setEditing({ name: '', slug: '', location_id: locations[0]?.id, duration_minutes: 60, min_players: 2, max_players: 6, is_active: 1 })}>+ Nuova stanza</button>
			</header>
			<table class="widefat striped em-table">
				<thead><tr><th>Nome</th><th>Slug</th><th>Durata</th><th>Giocatori</th><th>Stato</th><th></th></tr></thead>
				<tbody>
					${rows.map(r => html`
						<tr key=${r.id}>
							<td>${r.name}</td>
							<td><code>${r.slug}</code></td>
							<td>${r.duration_minutes} min</td>
							<td>${r.min_players}-${r.max_players}</td>
							<td>${r.is_active == 1 ? '✅' : '⛔'}</td>
							<td><button class="em-btn em-btn-secondary" onClick=${() => setEditing(r)}>Modifica</button></td>
						</tr>
					`)}
				</tbody>
			</table>
			${editing && html`<${RoomEditModal} room=${editing} locations=${locations} onClose=${() => setEditing(null)} onSave=${save} />`}
		</div>
	`;
}

function RoomEditModal({ room, locations, onClose, onSave }) {
	const [form, setForm] = useState(room);
	const set = (k, v) => setForm({ ...form, [k]: v });
	return html`
		<div class="em-modal-backdrop" onClick=${onClose}>
			<div class="em-modal em-modal-lg" onClick=${e => e.stopPropagation()}>
				<h2>${form.id ? 'Modifica stanza' : 'Nuova stanza'}</h2>
				<div class="em-form-grid">
					<label>Nome <input value=${form.name || ''} onInput=${e => set('name', e.target.value)} /></label>
					<label>Slug (deve combaciare con Sottoscacco) <input value=${form.slug || ''} onInput=${e => set('slug', e.target.value)} /></label>
					<label>Location
						<select value=${form.location_id || ''} onChange=${e => set('location_id', parseInt(e.target.value))}>
							${locations.map(l => html`<option value=${l.id}>${l.name}</option>`)}
						</select>
					</label>
					<label>Durata (min) <input type="number" value=${form.duration_minutes || 60} onInput=${e => set('duration_minutes', parseInt(e.target.value))} /></label>
					<label>Min giocatori <input type="number" value=${form.min_players || 2} onInput=${e => set('min_players', parseInt(e.target.value))} /></label>
					<label>Max giocatori <input type="number" value=${form.max_players || 6} onInput=${e => set('max_players', parseInt(e.target.value))} /></label>
					<label>Foto URL <input value=${form.image_url || ''} onInput=${e => set('image_url', e.target.value)} /></label>
					<label>Attiva
						<select value=${form.is_active ? '1' : '0'} onChange=${e => set('is_active', parseInt(e.target.value))}>
							<option value="1">Sì</option><option value="0">No</option>
						</select>
					</label>
				</div>
				<label class="em-textarea-label">Descrizione <textarea rows="3" value=${form.description || ''} onInput=${e => set('description', e.target.value)}></textarea></label>
				<label class="em-textarea-label">Info importanti <textarea rows="2" value=${form.important_info || ''} onInput=${e => set('important_info', e.target.value)}></textarea></label>
				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${() => onSave(form)}>Salva</button>
				</div>
			</div>
		</div>
	`;
}

// ── Tariffs / Settings (riprese da MVP1) ──

function TariffsPage() {
	const [rows, setRows] = useState([]);
	const [rooms, setRooms] = useState([]);
	const [editing, setEditing] = useState(null);
	const load = useCallback(() => Promise.all([api('GET', '/tariffs'), api('GET', '/rooms/admin')]).then(([t, r]) => { setRows(t.data || []); setRooms(r.data || []); }), []);
	useEffect(() => { load(); }, [load]);
	const save = async (data) => { if (data.id) await api('PUT', `/tariffs/${data.id}`, data); else await api('POST', '/tariffs', data); setEditing(null); load(); };

	return html`
		<div>
			<header class="em-page-header">
				<h1>Tariffe</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setEditing({ title: '', min_players: 2, max_players: 6, price_type: 'fixed', fixed_price: 0, price_per_person: 0 })}>+ Nuova tariffa</button>
			</header>
			<table class="widefat striped em-table">
				<thead><tr><th>Titolo</th><th>Stanza</th><th>Giocatori</th><th>Tipo</th><th>Prezzo</th><th></th></tr></thead>
				<tbody>
					${rows.map(t => html`<tr key=${t.id}>
						<td>${t.title}</td>
						<td>${rooms.find(r => r.id == t.room_id)?.name || 'Globale'}</td>
						<td>${t.min_players}-${t.max_players}</td>
						<td>${t.price_type === 'fixed' ? 'Fisso' : 'Per persona'}</td>
						<td>${formatMoney(t.price_type === 'fixed' ? t.fixed_price : t.price_per_person)}${t.price_type === 'per_person' ? ' /pp' : ''}</td>
						<td><button class="em-btn em-btn-secondary" onClick=${() => setEditing(t)}>Modifica</button></td>
					</tr>`)}
				</tbody>
			</table>
			${editing && html`<${TariffEditModal} tariff=${editing} rooms=${rooms} onClose=${() => setEditing(null)} onSave=${save} />`}
		</div>
	`;
}

function TariffEditModal({ tariff, rooms, onClose, onSave }) {
	const [form, setForm] = useState({ ...tariff, fixed_price_units: ((tariff.fixed_price || 0) / 100).toFixed(2), price_per_person_units: ((tariff.price_per_person || 0) / 100).toFixed(2) });
	const set = (k, v) => setForm({ ...form, [k]: v });
	const handleSave = () => {
		const d = { ...form, fixed_price: Math.round(parseFloat(form.fixed_price_units || 0) * 100), price_per_person: Math.round(parseFloat(form.price_per_person_units || 0) * 100) };
		delete d.fixed_price_units; delete d.price_per_person_units; onSave(d);
	};
	return html`
		<div class="em-modal-backdrop" onClick=${onClose}>
			<div class="em-modal" onClick=${e => e.stopPropagation()}>
				<h2>${form.id ? 'Modifica tariffa' : 'Nuova tariffa'}</h2>
				<label class="em-textarea-label">Titolo <input value=${form.title || ''} onInput=${e => set('title', e.target.value)} /></label>
				<label class="em-textarea-label">Stanza (vuoto = globale)
					<select value=${form.room_id || ''} onChange=${e => set('room_id', e.target.value ? parseInt(e.target.value) : null)}>
						<option value="">Globale</option>
						${rooms.map(r => html`<option value=${r.id}>${r.name}</option>`)}
					</select>
				</label>
				<div class="em-form-grid">
					<label>Min giocatori <input type="number" value=${form.min_players} onInput=${e => set('min_players', parseInt(e.target.value))} /></label>
					<label>Max giocatori <input type="number" value=${form.max_players} onInput=${e => set('max_players', parseInt(e.target.value))} /></label>
				</div>
				<label class="em-textarea-label">Tipo prezzo
					<select value=${form.price_type} onChange=${e => set('price_type', e.target.value)}>
						<option value="fixed">Fisso</option>
						<option value="per_person">Per persona</option>
					</select>
				</label>
				${form.price_type === 'fixed' && html`<label class="em-textarea-label">Prezzo fisso (€) <input type="number" step="0.01" value=${form.fixed_price_units} onInput=${e => set('fixed_price_units', e.target.value)} /></label>`}
				${form.price_type === 'per_person' && html`<label class="em-textarea-label">Prezzo per persona (€) <input type="number" step="0.01" value=${form.price_per_person_units} onInput=${e => set('price_per_person_units', e.target.value)} /></label>`}
				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${handleSave}>Salva</button>
				</div>
			</div>
		</div>
	`;
}

function SettingsPage() {
	const [data, setData] = useState(null);
	const [msg, setMsg] = useState(null);
	const [saving, setSaving] = useState(false);
	useEffect(() => { api('GET', '/settings').then(r => setData(r.data)); }, []);
	if (!data) return html`<p class="em-loading">Caricamento…</p>`;
	const set = (k, v) => setData({ ...data, [k]: v });
	const save = async () => {
		setSaving(true);
		try { const r = await api('PUT', '/settings', data); setData(r.data); setMsg('Salvato.'); }
		catch (e) { setMsg('Errore: ' + e.message); }
		finally { setSaving(false); setTimeout(() => setMsg(null), 3000); }
	};
	return html`
		<div>
			<header class="em-page-header"><h1>Impostazioni</h1></header>
			<h2>Generale</h2>
			<table class="form-table">
				<tr><th>TTL lock (min)</th><td><input type="number" value=${data.em_lock_ttl_minutes || 10} onInput=${e => set('em_lock_ttl_minutes', parseInt(e.target.value))} /></td></tr>
				<tr><th>Valuta</th><td><input value=${data.em_currency || 'EUR'} onInput=${e => set('em_currency', e.target.value)} /></td></tr>
				<tr><th>Timezone</th><td><input value=${data.em_timezone || 'Europe/Rome'} onInput=${e => set('em_timezone', e.target.value)} /></td></tr>
				<tr><th>Timeout inattività (min)</th><td><input type="number" value=${data.em_idle_timeout_minutes || 15} onInput=${e => set('em_idle_timeout_minutes', parseInt(e.target.value))} /></td></tr>
				<tr><th>Prefisso codice booking</th><td><input value=${data.em_booking_code_prefix || 'EM'} onInput=${e => set('em_booking_code_prefix', e.target.value)} /></td></tr>
			</table>
			<h2>Email</h2>
			<table class="form-table">
				<tr><th>Nome mittente</th><td><input value=${data.em_email_from_name || ''} onInput=${e => set('em_email_from_name', e.target.value)} /></td></tr>
				<tr><th>Email mittente</th><td><input type="email" value=${data.em_email_from_address || ''} onInput=${e => set('em_email_from_address', e.target.value)} /></td></tr>
			</table>
			<p><strong>Bridge Sottoscacco</strong>: configurabile dalla voce di menu dedicata "Bridge Sottoscacco".</p>
			<p>
				<button class="em-btn em-btn-primary" disabled=${saving} onClick=${save}>${saving ? 'Salvataggio…' : 'Salva'}</button>
				${msg && html`<span class="em-save-msg">${msg}</span>`}
			</p>
		</div>
	`;
}

// ── Booking Drawer ──

function BookingDrawer({ bookingId, onClose, onChanged }) {
	const [b, setB] = useState(null);
	const [loading, setLoading] = useState(true);
	const [actionLoading, setActionLoading] = useState(false);
	const load = useCallback(() => { setLoading(true); api('GET', '/bookings/' + bookingId).then(r => setB(r.data)).finally(() => setLoading(false)); }, [bookingId]);
	useEffect(() => { load(); }, [load]);
	const doAction = async (path, body = {}, confirmMsg = null) => {
		if (confirmMsg && !confirm(confirmMsg)) return;
		setActionLoading(true);
		try { await api('POST', path, body); load(); onChanged?.(); }
		catch (e) { alert(e.message || 'Errore'); }
		finally { setActionLoading(false); }
	};

	if (loading || !b) return html`<div class="em-drawer-backdrop" onClick=${onClose}><div class="em-drawer" onClick=${e => e.stopPropagation()}><p class="em-loading">Caricamento…</p></div></div>`;

	return html`
		<div class="em-drawer-backdrop" onClick=${onClose}>
			<div class="em-drawer" onClick=${e => e.stopPropagation()}>
				<header class="em-drawer-header">
					<div><h2>${b.room?.name || 'Prenotazione'}</h2><p>${formatDateTime(b.start_datetime)} · <code>${b.booking_code}</code></p></div>
					<button class="em-close" onClick=${onClose}>×</button>
				</header>
				<div class="em-drawer-body">
					<div class="em-status-row">
						<span class=${'em-pill em-status-' + b.booking_status}>${STATUS_LABELS[b.booking_status]}</span>
						<span class=${'em-pill em-payment-' + b.payment_status}>${b.payment_status}</span>
					</div>
					<h3>Cliente</h3>
					${b.customer ? html`<p>${b.customer.first_name} ${b.customer.last_name || ''}</p><p>${b.customer.phone || '—'} · ${b.customer.email || '—'}</p>` : html`<p><em>Nessuno</em></p>`}
					<h3>Dettagli</h3>
					<table class="em-detail-table">
						<tr><th>Giocatori</th><td>${b.total_players} (${b.adults} adulti + ${b.children} bambini)</td></tr>
						<tr><th>Totale</th><td>${formatMoney(b.total_amount)}</td></tr>
						<tr><th>Pagato</th><td>${formatMoney(b.paid_amount)}</td></tr>
						<tr><th>Metodo</th><td>${b.payment_method || '—'}</td></tr>
						<tr><th>Fonte</th><td>${b.source}</td></tr>
						${b.customer_comment && html`<tr><th>Note cliente</th><td>${b.customer_comment}</td></tr>`}
						${b.internal_notes && html`<tr><th>Note interne</th><td>${b.internal_notes}</td></tr>`}
					</table>
					<h3>Pagamenti</h3>
					${(b.payments || []).length === 0 && html`<p><em>Nessuno</em></p>`}
					<ul>${(b.payments || []).map(p => html`<li>${formatMoney(p.amount)} · ${p.payment_method} · ${formatDateTime(p.paid_at)}</li>`)}</ul>
					<h3>Azioni</h3>
					<div class="em-action-buttons">
						${b.booking_status !== 'confirmed' && html`<button class="em-btn em-btn-primary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/confirm`)}>Conferma</button>`}
						${b.booking_status !== 'cancelled' && html`<button class="em-btn em-btn-danger" disabled=${actionLoading} onClick=${() => { const reason = prompt('Motivo:'); if (reason !== null) doAction(`/bookings/${b.id}/cancel`, { reason }); }}>Annulla</button>`}
						${b.booking_status === 'confirmed' && html`<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/transition`, { status: 'completed' })}>Completata</button><button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/transition`, { status: 'no_show' })}>No-show</button>`}
						<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => { const amt = prompt('Importo € (es. 60.00):'); if (amt) { const m = prompt('Metodo:', 'on_site') || 'on_site'; doAction(`/bookings/${b.id}/payment`, { amount_cents: Math.round(parseFloat(amt) * 100), payment_method: m }); } }}>+ Pagamento</button>
						<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => { const n = prompt('Nota:'); if (n) doAction(`/bookings/${b.id}/notes`, { note: n }); }}>+ Nota</button>
					</div>
				</div>
			</div>
		</div>
	`;
}

// ── Idle Timer ──

function IdleTimer({ minutes, onTimeout }) {
	useEffect(() => {
		let timer;
		const reset = () => { clearTimeout(timer); timer = setTimeout(onTimeout, minutes * 60 * 1000); };
		const events = ['mousemove', 'click', 'keydown', 'touchstart', 'scroll'];
		events.forEach(e => window.addEventListener(e, reset));
		reset();
		return () => { clearTimeout(timer); events.forEach(e => window.removeEventListener(e, reset)); };
	}, [minutes]);
	return null;
}

// ── App root ──

function App() {
	const [me, setMe] = useState(null);
	const [error, setError] = useState(null);
	const [page, setPage] = useState('calendar');
	const [openBookingId, setOpenBookingId] = useState(null);

	useEffect(() => { api('GET', '/me').then(r => setMe(r.data)).catch(e => setError(e.message)); }, []);
	if (error) return html`<div class="em-error">${error}</div>`;
	if (!me) return html`<div class="em-loading">Caricamento CRM…</div>`;
	const perms = me.permissions || {};

	let content;
	if (page === 'calendar' && perms.em_view_calendar) content = html`<${CalendarPage} onOpenBooking=${id => setOpenBookingId(id)} perms=${perms} />`;
	else if (page === 'bookings' && perms.em_view_bookings) content = html`<${BookingsPage} onOpenBooking=${id => setOpenBookingId(id)} perms=${perms} />`;
	else if (page === 'customers' && perms.em_view_customers) content = html`<${CustomersPage} />`;
	else if (page === 'statistics' && perms.em_view_statistics) content = html`<${StatisticsPage} />`;
	else if (page === 'rooms' && perms.em_view_rooms) content = html`<${RoomsPage} />`;
	else if (page === 'tariffs' && perms.em_view_settings) content = html`<${TariffsPage} />`;
	else if (page === 'promocodes' && perms.em_view_settings) content = html`<${PromocodesPage} />`;
	else if (page === 'vouchers' && perms.em_view_payments) content = html`<${VouchersPage} />`;
	else if (page === 'settings' && perms.em_view_settings) content = html`<${SettingsPage} />`;
	else content = html`<p>Permessi insufficienti.</p>`;

	const idleMin = me.settings?.idle_timeout_minutes || 15;

	return html`
		<div class="em-crm">
			<${Sidebar} current=${page} onNavigate=${setPage} perms=${perms} />
			<main class="em-main">
				<div class="em-topbar">Ciao, <strong>${me.display_name}</strong> (${(me.roles || []).join(', ')})</div>
				${content}
			</main>
			${openBookingId && html`<${BookingDrawer} bookingId=${openBookingId} onClose=${() => setOpenBookingId(null)} onChanged=${() => {}} />`}
			<${IdleTimer} minutes=${idleMin} onTimeout=${() => { if (!confirm('Sei inattivo. Continuare?')) window.location.reload(); }} />
		</div>
	`;
}

const root = document.getElementById('em-crm-root');
if (root) render(h(App), root);
