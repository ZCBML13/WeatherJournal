// Minimal frontend: every piece of data comes from our own JSON API via fetch().

const $ = (sel) => document.querySelector(sel);
let selectedId = null;

async function api(method, path, body) {
    const res = await fetch(path, {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 204) return null;
    const data = await res.json();
    if (!res.ok) {
        const details = data.details ? ' (' + Object.values(data.details).join(', ') + ')' : '';
        throw new Error((data.error || `HTTP ${res.status}`) + details);
    }
    return data;
}

function show(text, isError = false) {
    const el = $('#message');
    el.textContent = text;
    el.className = isError ? 'error' : '';
}

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const deg = (t) => (t == null ? '–' : `${Math.round(t)}°`);
const dayName = (date) => new Date(date + 'T12:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

async function loadCities() {
    const { cities } = await api('GET', '/api/cities');
    $('#empty').hidden = cities.length > 0;
    $('#cities').innerHTML = cities.map((c) => `
        <li data-id="${c.id}" class="${c.id === selectedId ? 'active' : ''}">
            <strong>${esc(c.name)}</strong> <span class="muted">${esc(c.country)}</span>
            <div class="temp" id="temp-${c.id}">…</div>
            <div class="muted" id="desc-${c.id}"></div>
        </li>`).join('');

    // Fill in current weather for each card (served from the server-side cache when fresh).
    for (const c of cities) {
        api('GET', `/api/cities/${c.id}/weather`)
            .then((w) => {
                $(`#temp-${c.id}`).textContent = `${w.current.icon} ${deg(w.current.temperature)}`;
                $(`#desc-${c.id}`).textContent = w.current.description;
            })
            .catch(() => { $(`#temp-${c.id}`).textContent = '⚠️'; });
    }
}

async function loadDetail(id, refresh = false) {
    selectedId = id;
    document.querySelectorAll('#cities li').forEach((li) => li.classList.toggle('active', Number(li.dataset.id) === id));
    const w = await api('GET', `/api/cities/${id}/weather${refresh ? '?refresh=1' : ''}`);
    const h = await api('GET', `/api/cities/${id}/history`);

    $('#detail').hidden = false;
    $('#detail-title').textContent = `${w.city.name}, ${w.city.country ?? ''}`;
    $('#detail-meta').textContent =
        `Now ${deg(w.current.temperature)} (feels like ${deg(w.current.feels_like)}), ` +
        `${w.current.humidity}% humidity, wind ${w.current.wind_speed} km/h · ` +
        `${w.cached ? 'cached' : 'fetched'} ${new Date(w.fetched_at).toLocaleTimeString()}`;

    $('#forecast').innerHTML = w.forecast.map((d) => `
        <li>
            <div class="muted">${dayName(d.date)}</div>
            <div class="icon" title="${esc(d.description)}">${d.icon}</div>
            <div>${deg(d.temp_max)} / ${deg(d.temp_min)}</div>
        </li>`).join('');

    $('#history').innerHTML = h.days.map((d) => `
        <tr>
            <td>${dayName(d.date)}</td>
            <td>${d.icon} ${esc(d.description)}</td>
            <td>${deg(d.temp_max)} / ${deg(d.temp_min)}</td>
            <td>${d.precipitation ?? '–'} mm</td>
            <td class="note">
                <textarea data-date="${d.date}" placeholder="How was the day?" maxlength="1000">${esc(d.note)}</textarea>
                <span class="saved"></span>
            </td>
        </tr>`).join('') || '<tr><td colspan="5" class="muted">No history yet.</td></tr>';
}

async function loadStats() {
    const s = await api('GET', '/api/stats?days=30');
    if (!s.cities.length) { $('#stats').innerHTML = '<p class="muted">Add a city to see stats.</p>'; return; }
    const highlight = [
        s.warmest_city && `🔥 Warmest: <strong>${esc(s.warmest_city.name)}</strong> (avg high ${s.warmest_city.avg_high}°)`,
        s.wettest_city && `💧 Wettest: <strong>${esc(s.wettest_city.name)}</strong> (${s.wettest_city.total_precipitation} mm)`,
    ].filter(Boolean).join(' · ');
    $('#stats').innerHTML = `<p>${highlight}</p>
        <table>
            <thead><tr><th>City</th><th>Days</th><th>Avg high / low</th><th>Rainy days</th><th>Total rain</th></tr></thead>
            <tbody>${s.cities.map((c) => `
                <tr><td>${esc(c.name)}</td><td>${c.days_recorded}</td>
                <td>${deg(c.avg_high)} / ${deg(c.avg_low)}</td><td>${c.rainy_days}</td><td>${c.total_precipitation} mm</td></tr>`).join('')}
            </tbody>
        </table>`;
}

// ---- events ------------------------------------------------------------------

$('#add-city').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = e.target.name;
    const button = e.target.querySelector('button');
    button.disabled = true;
    try {
        const city = await api('POST', '/api/cities', { name: input.value });
        show(`Added ${city.name}, ${city.country ?? ''}.`);
        input.value = '';
        await loadCities();
        await loadDetail(city.id);
        await loadStats();
    } catch (err) {
        show(err.message, true);
    } finally {
        button.disabled = false;
    }
});

$('#cities').addEventListener('click', (e) => {
    const li = e.target.closest('li[data-id]');
    if (li) loadDetail(Number(li.dataset.id)).catch((err) => show(err.message, true));
});

$('#refresh').addEventListener('click', () =>
    loadDetail(selectedId, true).then(loadStats).catch((err) => show(err.message, true)));

$('#delete-city').addEventListener('click', async () => {
    if (!confirm('Remove this city and all its notes?')) return;
    await api('DELETE', `/api/cities/${selectedId}`);
    selectedId = null;
    $('#detail').hidden = true;
    show('City removed.');
    await Promise.all([loadCities(), loadStats()]);
});

// Save a note when you leave the text box (PUT creates or replaces; empty deletes).
$('#history').addEventListener('change', async (e) => {
    const box = e.target.closest('textarea[data-date]');
    if (!box) return;
    const status = box.nextElementSibling;
    const path = `/api/cities/${selectedId}/notes/${box.dataset.date}`;
    try {
        if (box.value.trim()) {
            await api('PUT', path, { text: box.value });
        } else {
            await api('DELETE', path).catch(() => {}); // nothing to delete is fine
        }
        status.textContent = 'Saved';
        setTimeout(() => { status.textContent = ''; }, 1500);
    } catch (err) {
        status.textContent = err.message;
    }
});

Promise.all([loadCities(), loadStats()]).catch((err) => show(err.message, true));
