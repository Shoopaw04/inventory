document.addEventListener('DOMContentLoaded', async () => {
    try {
        await AuthHelper.requireAuthAndRole(['Admin']);
        loadAuditLogs();
    } catch (e) {
        window.location.href = 'login.html';
    }
});

// Expose for button onclick
window.loadAuditLogs = loadAuditLogs;

async function loadAuditLogs() {
    const filters = {};
    const df = document.getElementById('dateFrom').value;
    const dt = document.getElementById('dateTo').value;
    const uid = document.getElementById('userId').value;
    const entity = document.getElementById('entity').value.trim();
    const action = document.getElementById('actionType').value.trim();
    if (df) filters.date_from = df;
    if (dt) filters.date_to = dt;
    if (uid) filters.user_id = uid;
    if (entity) filters.entity = entity;
    if (action) filters.action_type = action;
    filters.limit = 200;
    filters.offset = 0;
    const resp = await getAuditLogs(filters);
    const rows = document.getElementById('auditRows');
    rows.innerHTML = '';
    if (!resp.success) {
        rows.innerHTML = `<tr><td colspan="8">${resp.error || 'Failed to load logs'}</td></tr>`;
        return;
    }
    const data = resp.data;
    if (!data || data.length === 0) {
        rows.innerHTML = '<tr><td colspan="8">No logs found</td></tr>';
        return;
    }
    rows.innerHTML = data.map(l => `
        <tr>
            <td>${formatDT(l.Time)}</td>
            <td>${l.User_name || l.User_ID}</td>
            <td>${l.Role_name || ''}</td>
            <td><span class="pill">${formatLabel(l.Entity)}</span></td>
            <td class="mono">${escapeHtml(l.Entity_ID || '')}</td>
            <td><span class="pill">${formatLabel(l.Action)}</span></td>
            <td class="mono">${formatState(l.Before_State)}</td>
            <td class="mono">${formatState(l.After_State)}</td>
        </tr>
    `).join('');
}

function formatLabel(s) {
    if (!s) return '';
    try {
        return String(s).replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase());
    } catch { return escapeHtml(String(s)); }
}

function normalizeStatus(s) {
    if (!s) return '';
    const raw = String(s).toUpperCase();
    if (raw.includes('APPROVED')) return 'Approved';
    if (raw.includes('PENDING')) return 'Pending';
    if (raw.includes('REJECTED')) return 'Rejected';
    return formatLabel(s);
}

function formatState(val) {
    if (!val) return '';
    // Handle strings like "Status: PENDING"
    if (typeof val === 'string') {
        const m = val.match(/status\s*:\s*([A-Z_]+)/i);
        if (m) return normalizeStatus(m[1]);
        // If it's JSON as string, fall through to parse
    }
    try {
        const obj = typeof val === 'string' ? JSON.parse(val) : val;
        if (obj && typeof obj === 'object') {
            const parts = [];
            for (const [k,v] of Object.entries(obj)) {
                const key = k === 'Is_discontinued' ? 'Discontinued' : formatLabel(k);
                let value = v;
                if (k === 'Is_discontinued') value = v ? 'Discontinued' : 'Active';
                else if (String(k).toLowerCase().includes('status')) value = normalizeStatus(v);
                parts.push(`${key}${value !== '' && value !== undefined ? `: ${value}` : ''}`);
            }
            return escapeHtml(parts.join(', '));
        }
        return escapeHtml(String(obj));
    } catch {
        // Plain string fallback
        return escapeHtml(normalizeStatus(val));
    }
}
function formatDT(s) { return s ? new Date(s).toLocaleString() : ''; }
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c])); }