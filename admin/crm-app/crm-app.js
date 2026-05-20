/**
 * Escape Manager — CRM (no-build, ESM via CDN).
 *
 * Config iniettata da PHP in window.EM_CRM_CONFIG.
 * Monta su #em-crm-root.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import { useState, useEffect, useMemo, useCallback } from 'https://esm.sh/preact@10.22.0/hooks';
import htm from 'https://esm.sh/htm@3.1.1';

const html = htm.bind(h);
const CONFIG = window.EM_CRM_CONFIG || {};

async function api(method, path, body = null) {
	const opts = {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': CONFIG.nonce,
		},
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
	return d.toLocaleString('it-IT', {
		dateStyle: 'short',
		timeStyle: 'short',
		timeZone: CONFIG.timezone || 'Europe/Rome',
	});
}

function formatTime(iso) {
	if (!iso) return '';
	const d = new Date(iso);
	return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: CONFIG.timezone });
}

function todayISO() {
	return new Date().toISOString().slice(0, 10);
}

function addDays(iso, days) {
	const d = new Date(iso + 'T12:00:00');
	d.setDate(d.getDate() + days);
	return d.toISOString().slice(0, 10);
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

// ── Layout / Routing ──

function Sidebar({ current, onNavigate, perms }) {
	const items = [
		{ id: 'calendar', label: 'Calendario', cap: 'em_view_calendar', icon: '📅' },
		{ id: 'bookings', label: 'Prenotazioni', cap: 'em_view_bookings', icon: '🎟️' },
		{ id: 'customers', label: 'Clienti', cap: 'em_view_customers', icon: '👥' },
		{ id: 'rooms', label: 'Stanze', cap: 'em_view_rooms', icon: '🚪' },
		{ id: 'tariffs', label: 'Tariffe', cap: 'em_view_settings', icon: '💶' },
		{ id: 'settings', label: 'Impostazioni', cap: 'em_view_settings', icon: '⚙️' },
	].filter(item => perms[item.cap]);

	return html`
		<aside class="em-sidebar">
			<div class="em-logo">Escape Manager</div>
			<nav>
				${items.map(item => html`
					<button
						class=${'em-nav-item ' + (current === item.id ? 'is-active' : '')}
						onClick=${() => onNavigate(item.id)}>
						<span>${item.icon}</span> ${item.label}
					</button>
				`)}
			</nav>
		</aside>
	`;
}

// ── Calendar (vista giorno) ──

function CalendarPage({ onOpenBooking }) {
	const [date, setDate] = useState(todayISO());
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);

	const load = useCallback(() => {
		setLoading(true);
		setError(null);
		api('GET', `/calendar?date=${date}&view=day`)
			.then(r => setData(r.data))
			.catch(e => setError(e.message))
			.finally(() => setLoading(false));
	}, [date]);

	useEffect(() => { load(); }, [load]);

	// Refresh ogni 30s
	useEffect(() => {
		const id = setInterval(load, 30000);
		return () => clearInterval(id);
	}, [load]);

	const bookingsByRoom = useMemo(() => {
		if (!data) return {};
		const map = {};
		(data.rooms || []).forEach(r => { map[r.id] = []; });
		(data.bookings || []).forEach(b => {
			if (!map[b.room_id]) map[b.room_id] = [];
			map[b.room_id].push(b);
		});
		return map;
	}, [data]);

	return html`
		<div class="em-calendar-page">
			<header class="em-page-header">
				<h1>Calendario</h1>
				<div class="em-toolbar">
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(addDays(date, -1))}>◀</button>
					<input type="date" value=${date} onChange=${e => setDate(e.target.value)} />
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(addDays(date, 1))}>▶</button>
					<button class="em-btn em-btn-secondary" onClick=${() => setDate(todayISO())}>Oggi</button>
					<button class="em-btn em-btn-secondary" onClick=${load}>↻ Aggiorna</button>
				</div>
			</header>

			${loading && html`<p class="em-loading">Caricamento…</p>`}
			${error && html`<p class="em-error">${error}</p>`}

			${data && html`
				<div class="em-calendar-grid">
					${(data.rooms || []).map(room => html`
						<div class="em-room-column" key=${room.id}>
							<div class="em-room-header">${room.name}</div>
							<div class="em-room-body">
								${(bookingsByRoom[room.id] || []).length === 0 && html`<p class="em-empty">Nessuna prenotazione</p>`}
								${(bookingsByRoom[room.id] || []).map(b => html`
									<div class=${'em-booking-card em-status-' + b.booking_status} key=${b.id} onClick=${() => onOpenBooking(b.id)}>
										<div class="em-bc-time">${formatTime(b.start_datetime)}</div>
										<div class="em-bc-name">${b.customer ? (b.customer.first_name + ' ' + (b.customer.last_name || '')) : '—'}</div>
										<div class="em-bc-meta">${b.total_players} giocatori · ${formatMoney(b.total_amount)}</div>
										<div class="em-bc-status">${STATUS_LABELS[b.booking_status] || b.booking_status}</div>
									</div>
								`)}
							</div>
						</div>
					`)}
				</div>
			`}
		</div>
	`;
}

// ── Bookings list ──

function BookingsPage({ onOpenBooking }) {
	const [rows, setRows] = useState([]);
	const [filters, setFilters] = useState({ from: todayISO(), to: addDays(todayISO(), 30), status: '' });
	const [loading, setLoading] = useState(false);

	const load = useCallback(() => {
		setLoading(true);
		const qs = new URLSearchParams();
		if (filters.from) qs.set('from', filters.from);
		if (filters.to) qs.set('to', filters.to);
		if (filters.status) qs.set('status', filters.status);
		api('GET', '/bookings?' + qs.toString())
			.then(r => setRows(r.data || []))
			.finally(() => setLoading(false));
	}, [filters]);

	useEffect(() => { load(); }, [load]);

	return html`
		<div class="em-bookings-page">
			<header class="em-page-header"><h1>Prenotazioni</h1></header>
			<div class="em-filters">
				<label>Da: <input type="date" value=${filters.from} onChange=${e => setFilters({ ...filters, from: e.target.value })} /></label>
				<label>A: <input type="date" value=${filters.to} onChange=${e => setFilters({ ...filters, to: e.target.value })} /></label>
				<label>Stato:
					<select value=${filters.status} onChange=${e => setFilters({ ...filters, status: e.target.value })}>
						<option value="">Tutti</option>
						${Object.entries(STATUS_LABELS).map(([k, v]) => html`<option value=${k}>${v}</option>`)}
					</select>
				</label>
			</div>

			${loading && html`<p class="em-loading">Caricamento…</p>`}

			<table class="widefat striped em-table">
				<thead>
					<tr>
						<th>Quando</th>
						<th>Codice</th>
						<th>Stanza</th>
						<th>Cliente</th>
						<th>Telefono</th>
						<th>Giocatori</th>
						<th>Totale</th>
						<th>Pagato</th>
						<th>Stato</th>
					</tr>
				</thead>
				<tbody>
					${rows.length === 0 && html`<tr><td colspan="9"><em>Nessuna prenotazione trovata.</em></td></tr>`}
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
							<td><span class=${'em-pill em-status-' + b.booking_status}>${STATUS_LABELS[b.booking_status] || b.booking_status}</span></td>
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
		api('GET', path)
			.then(r => setRows(r.data || []))
			.finally(() => setLoading(false));
	}, [q]);

	useEffect(() => {
		const id = setTimeout(load, 300);
		return () => clearTimeout(id);
	}, [load]);

	return html`
		<div class="em-customers-page">
			<header class="em-page-header"><h1>Clienti</h1></header>
			<input
				type="search" placeholder="Cerca per nome, telefono, email…"
				value=${q} onInput=${e => setQ(e.target.value)}
				class="em-search-input" />

			${loading && html`<p class="em-loading">Caricamento…</p>`}

			<table class="widefat striped em-table">
				<thead>
					<tr>
						<th>Nome</th>
						<th>Telefono</th>
						<th>Email</th>
						<th>Prenotazioni</th>
						<th>Ultima visita</th>
					</tr>
				</thead>
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

// ── Rooms admin ──

function RoomsPage() {
	const [rows, setRows] = useState([]);
	const [locations, setLocations] = useState([]);
	const [loading, setLoading] = useState(false);
	const [editing, setEditing] = useState(null);

	const load = useCallback(() => {
		setLoading(true);
		Promise.all([
			api('GET', '/rooms/admin'),
			api('GET', '/locations'),
		]).then(([r, l]) => {
			setRows(r.data || []);
			setLocations(l.data || []);
		}).finally(() => setLoading(false));
	}, []);

	useEffect(() => { load(); }, [load]);

	const save = async (data) => {
		const id = data.id;
		if (id) {
			await api('PUT', `/rooms/${id}`, data);
		} else {
			await api('POST', '/rooms', data);
		}
		setEditing(null);
		load();
	};

	return html`
		<div class="em-rooms-page">
			<header class="em-page-header">
				<h1>Stanze</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setEditing({ name: '', slug: '', location_id: locations[0]?.id, duration_minutes: 60, min_players: 2, max_players: 6 })}>+ Nuova stanza</button>
			</header>

			${loading && html`<p class="em-loading">Caricamento…</p>`}

			<table class="widefat striped em-table">
				<thead><tr><th>Nome</th><th>Slug</th><th>Durata</th><th>Giocatori</th><th>Stato</th><th></th></tr></thead>
				<tbody>
					${rows.map(r => html`
						<tr key=${r.id}>
							<td>${r.name}</td>
							<td><code>${r.slug}</code></td>
							<td>${r.duration_minutes} min</td>
							<td>${r.min_players}-${r.max_players}</td>
							<td>${r.is_active == 1 ? '✅ Attiva' : '⛔ Disattiva'}</td>
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
					<label>Slug <input value=${form.slug || ''} onInput=${e => set('slug', e.target.value)} /></label>
					<label>Location
						<select value=${form.location_id || ''} onChange=${e => set('location_id', parseInt(e.target.value))}>
							${locations.map(l => html`<option value=${l.id}>${l.name}</option>`)}
						</select>
					</label>
					<label>Durata (minuti) <input type="number" value=${form.duration_minutes || 60} onInput=${e => set('duration_minutes', parseInt(e.target.value))} /></label>
					<label>Min giocatori <input type="number" value=${form.min_players || 2} onInput=${e => set('min_players', parseInt(e.target.value))} /></label>
					<label>Max giocatori <input type="number" value=${form.max_players || 6} onInput=${e => set('max_players', parseInt(e.target.value))} /></label>
					<label>Foto URL <input value=${form.image_url || ''} onInput=${e => set('image_url', e.target.value)} placeholder="https://..." /></label>
					<label>Attiva
						<select value=${form.is_active ? '1' : '0'} onChange=${e => set('is_active', parseInt(e.target.value))}>
							<option value="1">Sì</option>
							<option value="0">No</option>
						</select>
					</label>
				</div>
				<label class="em-textarea-label">Descrizione
					<textarea rows="3" value=${form.description || ''} onInput=${e => set('description', e.target.value)}></textarea>
				</label>
				<label class="em-textarea-label">Info importanti
					<textarea rows="2" value=${form.important_info || ''} onInput=${e => set('important_info', e.target.value)}></textarea>
				</label>

				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${() => onSave(form)}>Salva</button>
				</div>
			</div>
		</div>
	`;
}

// ── Tariffs ──

function TariffsPage() {
	const [rows, setRows] = useState([]);
	const [rooms, setRooms] = useState([]);
	const [editing, setEditing] = useState(null);

	const load = useCallback(() => {
		Promise.all([api('GET', '/tariffs'), api('GET', '/rooms/admin')])
			.then(([t, r]) => { setRows(t.data || []); setRooms(r.data || []); });
	}, []);
	useEffect(() => { load(); }, [load]);

	const save = async (data) => {
		if (data.id) await api('PUT', `/tariffs/${data.id}`, data);
		else await api('POST', '/tariffs', data);
		setEditing(null); load();
	};

	return html`
		<div class="em-tariffs-page">
			<header class="em-page-header">
				<h1>Tariffe</h1>
				<button class="em-btn em-btn-primary" onClick=${() => setEditing({ title: '', min_players: 2, max_players: 6, price_type: 'fixed', fixed_price: 0, price_per_person: 0 })}>+ Nuova tariffa</button>
			</header>

			<table class="widefat striped em-table">
				<thead><tr><th>Titolo</th><th>Stanza</th><th>Giocatori</th><th>Tipo</th><th>Prezzo</th><th></th></tr></thead>
				<tbody>
					${rows.map(t => html`
						<tr key=${t.id}>
							<td>${t.title}</td>
							<td>${rooms.find(r => r.id == t.room_id)?.name || 'Globale'}</td>
							<td>${t.min_players}-${t.max_players}</td>
							<td>${t.price_type === 'fixed' ? 'Fisso' : 'Per persona'}</td>
							<td>${formatMoney(t.price_type === 'fixed' ? t.fixed_price : t.price_per_person)}${t.price_type === 'per_person' ? ' /pp' : ''}</td>
							<td><button class="em-btn em-btn-secondary" onClick=${() => setEditing(t)}>Modifica</button></td>
						</tr>
					`)}
				</tbody>
			</table>

			${editing && html`<${TariffEditModal} tariff=${editing} rooms=${rooms} onClose=${() => setEditing(null)} onSave=${save} />`}
		</div>
	`;
}

function TariffEditModal({ tariff, rooms, onClose, onSave }) {
	const [form, setForm] = useState({
		...tariff,
		fixed_price_units: ((tariff.fixed_price || 0) / 100).toFixed(2),
		price_per_person_units: ((tariff.price_per_person || 0) / 100).toFixed(2),
	});
	const set = (k, v) => setForm({ ...form, [k]: v });

	const handleSave = () => {
		const data = {
			...form,
			fixed_price: Math.round(parseFloat(form.fixed_price_units || 0) * 100),
			price_per_person: Math.round(parseFloat(form.price_per_person_units || 0) * 100),
		};
		delete data.fixed_price_units;
		delete data.price_per_person_units;
		onSave(data);
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
				${form.price_type === 'fixed' && html`
					<label class="em-textarea-label">Prezzo fisso (€) <input type="number" step="0.01" value=${form.fixed_price_units} onInput=${e => set('fixed_price_units', e.target.value)} /></label>
				`}
				${form.price_type === 'per_person' && html`
					<label class="em-textarea-label">Prezzo per persona (€) <input type="number" step="0.01" value=${form.price_per_person_units} onInput=${e => set('price_per_person_units', e.target.value)} /></label>
				`}
				<div class="em-actions">
					<button class="em-btn em-btn-secondary" onClick=${onClose}>Annulla</button>
					<button class="em-btn em-btn-primary" onClick=${handleSave}>Salva</button>
				</div>
			</div>
		</div>
	`;
}

// ── Settings ──

function SettingsPage() {
	const [data, setData] = useState(null);
	const [saving, setSaving] = useState(false);
	const [msg, setMsg] = useState(null);

	useEffect(() => {
		api('GET', '/settings').then(r => setData(r.data));
	}, []);

	const save = async () => {
		setSaving(true);
		try {
			const r = await api('PUT', '/settings', data);
			setData(r.data);
			setMsg('Salvato.');
		} catch (e) { setMsg('Errore: ' + e.message); }
		finally { setSaving(false); setTimeout(() => setMsg(null), 3000); }
	};

	if (!data) return html`<p class="em-loading">Caricamento…</p>`;

	const set = (k, v) => setData({ ...data, [k]: v });

	return html`
		<div class="em-settings-page">
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

	const load = useCallback(() => {
		setLoading(true);
		api('GET', '/bookings/' + bookingId)
			.then(r => setB(r.data))
			.finally(() => setLoading(false));
	}, [bookingId]);

	useEffect(() => { load(); }, [load]);

	const doAction = async (path, body = {}, confirmMsg = null) => {
		if (confirmMsg && !confirm(confirmMsg)) return;
		setActionLoading(true);
		try {
			await api('POST', path, body);
			load();
			onChanged?.();
		} catch (e) {
			alert(e.message || 'Errore');
		} finally { setActionLoading(false); }
	};

	if (loading || !b) return html`
		<div class="em-drawer-backdrop" onClick=${onClose}>
			<div class="em-drawer" onClick=${e => e.stopPropagation()}>
				<p class="em-loading">Caricamento…</p>
			</div>
		</div>
	`;

	return html`
		<div class="em-drawer-backdrop" onClick=${onClose}>
			<div class="em-drawer" onClick=${e => e.stopPropagation()}>
				<header class="em-drawer-header">
					<div>
						<h2>${b.room?.name || 'Prenotazione'}</h2>
						<p>${formatDateTime(b.start_datetime)} · <code>${b.booking_code}</code></p>
					</div>
					<button class="em-close" onClick=${onClose}>×</button>
				</header>

				<div class="em-drawer-body">
					<div class="em-status-row">
						<span class=${'em-pill em-status-' + b.booking_status}>${STATUS_LABELS[b.booking_status]}</span>
						<span class=${'em-pill em-payment-' + b.payment_status}>${b.payment_status}</span>
					</div>

					<h3>Cliente</h3>
					${b.customer ? html`
						<p>${b.customer.first_name} ${b.customer.last_name || ''}</p>
						<p>${b.customer.phone || '—'} · ${b.customer.email || '—'}</p>
					` : html`<p><em>Nessun cliente associato</em></p>`}

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
					${(b.payments || []).length === 0 && html`<p><em>Nessun pagamento registrato</em></p>`}
					<ul>
						${(b.payments || []).map(p => html`<li>${formatMoney(p.amount)} · ${p.payment_method} · ${formatDateTime(p.paid_at)}</li>`)}
					</ul>

					<h3>Azioni</h3>
					<div class="em-action-buttons">
						${b.booking_status !== 'confirmed' && html`
							<button class="em-btn em-btn-primary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/confirm`)}>Conferma</button>
						`}
						${b.booking_status !== 'cancelled' && html`
							<button class="em-btn em-btn-danger" disabled=${actionLoading} onClick=${() => {
								const reason = prompt('Motivo cancellazione:');
								if (reason !== null) doAction(`/bookings/${b.id}/cancel`, { reason }, 'Annullare la prenotazione?');
							}}>Annulla</button>
						`}
						${b.booking_status === 'confirmed' && html`
							<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/transition`, { status: 'completed' })}>Segna completata</button>
							<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => doAction(`/bookings/${b.id}/transition`, { status: 'no_show' })}>No-show</button>
						`}
						<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => {
							const amt = prompt('Importo pagamento in € (es. 60.00):');
							if (amt) {
								const method = prompt('Metodo (on_site, card, cash, transfer):', 'on_site') || 'on_site';
								doAction(`/bookings/${b.id}/payment`, { amount_cents: Math.round(parseFloat(amt) * 100), payment_method: method });
							}
						}}>+ Pagamento</button>
						<button class="em-btn em-btn-secondary" disabled=${actionLoading} onClick=${() => {
							const note = prompt('Nota:');
							if (note) doAction(`/bookings/${b.id}/notes`, { note });
						}}>+ Nota</button>
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
		const reset = () => {
			clearTimeout(timer);
			timer = setTimeout(onTimeout, minutes * 60 * 1000);
		};
		const events = ['mousemove', 'click', 'keydown', 'touchstart', 'scroll'];
		events.forEach(e => window.addEventListener(e, reset));
		reset();
		return () => {
			clearTimeout(timer);
			events.forEach(e => window.removeEventListener(e, reset));
		};
	}, [minutes]);
	return null;
}

// ── App root ──

function App() {
	const [me, setMe] = useState(null);
	const [error, setError] = useState(null);
	const [page, setPage] = useState('calendar');
	const [openBookingId, setOpenBookingId] = useState(null);

	useEffect(() => {
		api('GET', '/me').then(r => setMe(r.data)).catch(e => setError(e.message));
	}, []);

	if (error) return html`<div class="em-error">${error}</div>`;
	if (!me) return html`<div class="em-loading">Caricamento CRM…</div>`;

	const perms = me.permissions || {};

	let content;
	if (page === 'calendar' && perms.em_view_calendar) content = html`<${CalendarPage} onOpenBooking=${id => setOpenBookingId(id)} />`;
	else if (page === 'bookings' && perms.em_view_bookings) content = html`<${BookingsPage} onOpenBooking=${id => setOpenBookingId(id)} />`;
	else if (page === 'customers' && perms.em_view_customers) content = html`<${CustomersPage} />`;
	else if (page === 'rooms' && perms.em_view_rooms) content = html`<${RoomsPage} />`;
	else if (page === 'tariffs' && perms.em_view_settings) content = html`<${TariffsPage} />`;
	else if (page === 'settings' && perms.em_view_settings) content = html`<${SettingsPage} />`;
	else content = html`<p>Permessi insufficienti per questa pagina.</p>`;

	const idleMin = me.settings?.idle_timeout_minutes || 15;

	return html`
		<div class="em-crm">
			<${Sidebar} current=${page} onNavigate=${setPage} perms=${perms} />
			<main class="em-main">
				<div class="em-topbar">
					<span>Ciao, <strong>${me.display_name}</strong> (${(me.roles || []).join(', ')})</span>
				</div>
				${content}
			</main>
			${openBookingId && html`<${BookingDrawer} bookingId=${openBookingId} onClose=${() => setOpenBookingId(null)} onChanged=${() => {}} />`}
			<${IdleTimer} minutes=${idleMin} onTimeout=${() => { if (confirm('Sei stato inattivo. Vuoi continuare?')) {} else window.location.reload(); }} />
		</div>
	`;
}

const root = document.getElementById('em-crm-root');
if (root) {
	render(h(App), root);
}
