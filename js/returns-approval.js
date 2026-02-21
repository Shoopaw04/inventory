console.log('returns-approval.js loaded');

let CURRENT_TAB = 'all';

async function loadTab(tab) {
    CURRENT_TAB = tab;
    const body = document.getElementById('records-body');
    body.innerHTML = '';
    const colPick = document.getElementById('col-pick');
    const mgrActions = document.getElementById('mgr-actions');
    try {
        const params = new URLSearchParams();
        if (tab === 'pending') params.set('scope', 'pending');
        if (tab === 'approved') params.set('status', 'Approved');
        if (tab === 'rejected') params.set('status', 'Rejected');
        // tab 'all' -> no status filter, show everything
        const resp = await fetch('../api/customer_returns.php?' + params.toString());
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Load failed');

        // Role: hide pick/actions for cashiers
        const user = await AuthHelper.getCurrentUser();
        const isManager = user && (user.Role_name === 'Manager' || user.Role_name === 'Admin');
        colPick.textContent = isManager && tab === 'pending' ? '' : '';
        mgrActions.style.display = isManager && tab === 'pending' ? '' : 'none';

        const rows = data.data || [];
        // update header stats
        const totalEl = document.getElementById('stat-total');
        const pendEl = document.getElementById('stat-pending');
        const apprEl = document.getElementById('stat-approved');
        const rejEl = document.getElementById('stat-rejected');
        if (totalEl) totalEl.textContent = rows.length.toString();
        if (pendEl) pendEl.textContent = tab === 'pending' ? rows.length.toString() : '-';
        if (apprEl) apprEl.textContent = tab === 'approved' ? rows.length.toString() : '-';
        if (rejEl) rejEl.textContent = tab === 'rejected' ? rows.length.toString() : '-';

        for (const row of rows) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${isManager && tab === 'pending' ? `<input type="checkbox" class="pick" value="${row.Return_ID}">` : ''}</td>
                <td>${row.Return_ID}</td>
                <td>${isManager && tab === 'pending' ? renderRowActions(row) : ''}</td>
                <td>${row.Sale_ID || ''}</td>
                <td>${row.SaleItem_ID}</td>
                <td>${row.Product_Name || row.Product_ID}</td>
                <td>${row.Quantity}</td>
                <td><span class="chip">${row.Reason}</span></td>
                <td>${row.Return_Type}</td>
                <td>${renderStatus(row.Status)}</td>
                <td>${row.Approved_By_Name || ''}</td>
                <td>${row.Approved_Date || ''}</td>
                <td>${row.Terminal_ID || ''}</td>
                <td>${row.Cashier_Name || ''}</td>
                <td>${row.Return_Date} <button class="pill-btn" onclick="viewReturn(${row.Return_ID})">View</button></td>
            `;
            body.appendChild(tr);
        }
    } catch (e) {
        document.getElementById('mgr-status').textContent = e.message;
    }
}

function renderStatus(s) {
    const st = String(s || '').toLowerCase();
    if (st === 'approved') return '<span class="badge badge-approved">Approved</span>';
    if (st === 'rejected') return '<span class="badge badge-rejected">Rejected</span>';
    return '<span class="badge badge-pending">Pending</span>';
}

function renderRowActions(row) {
    return `<button class="pill-btn" onclick="openDecisionModal(${row.Return_ID}, 'APPROVED')">Approve</button>
            <button class="pill-btn" onclick="openDecisionModal(${row.Return_ID}, 'REJECTED')">Reject</button>`;
}

function openDecisionModal(returnId, decision) {
    const modal = document.getElementById('decisionModal');
    const body = document.getElementById('decisionBody');
    const footer = document.getElementById('decisionFooter');
    document.getElementById('decisionTitle').textContent = decision === 'APPROVED' ? 'Approve Return' : 'Reject Return';
    body.innerHTML = `
      <input type="hidden" id="decisionReturnId" value="${returnId}">
      <div class="form-group">
        <label><i data-lucide="message-square" class="icon-sm"></i> Comments</label>
        <input type="text" id="decisionComments" placeholder="Enter your comments">
      </div>
      <div class="checkbox-group" ${decision==='APPROVED' ? '' : 'style="display:none;"'}>
        <input type="checkbox" id="decisionExchangeOnly">
        <label><i data-lucide="repeat" class="icon-sm"></i> Exchange Only</label>
      </div>`;
    footer.style.display = '';
    modal.style.display = 'block';
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
    window.__DECISION__ = decision;
}

function closeDecisionModal() {
    document.getElementById('decisionModal').style.display = 'none';
}

async function submitDecision() {
    const id = Number(document.getElementById('decisionReturnId').value || 0);
    const comments = document.getElementById('decisionComments').value.trim();
    const exchangeOnly = document.getElementById('decisionExchangeOnly').checked;
    if (!id || !window.__DECISION__) return;
    const status = document.getElementById('mgr-status');
    try {
        const resp = await fetch('../api/customer_returns.php?action=decide', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ return_ids: [id], decision: window.__DECISION__, comments, exchange_only: exchangeOnly })
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Decision failed');
        closeDecisionModal();
        status.textContent = 'Updated';
        await loadTab(CURRENT_TAB);
    } catch (e) {
        status.textContent = e.message;
    }
}

window.openDecisionModal = openDecisionModal;
window.closeDecisionModal = closeDecisionModal;
window.submitDecision = submitDecision;

async function viewReturn(id) {
    try {
        const resp = await fetch(`../api/customer_returns.php?sub=details&return_id=${id}`);
        const json = await resp.json();
        if (!json.success) throw new Error(json.error || 'Failed to load details');
        const d = json.data;
        const modal = document.getElementById('decisionModal');
        document.getElementById('decisionTitle').textContent = `Return #${d.Return_ID}`;
        const bodyHost = document.getElementById('decisionBody');
        bodyHost.innerHTML = `
            <div style="display:grid;grid-template-columns:160px 1fr;gap:8px;">
              <div><strong>Product:</strong></div><div>${d.Product_Name || d.Product_ID}</div>
              <div><strong>Status:</strong></div><div>${d.Status}</div>
              <div><strong>Reason:</strong></div><div>${d.Reason}</div>
              <div><strong>Qty:</strong></div><div>${d.Quantity}</div>
              <div><strong>Sale ID:</strong></div><div>${d.Sale_ID || ''}</div>
              <div><strong>Requested By:</strong></div><div>${d.Requested_By_Name ? `${d.Requested_By_Name} (${d.Requested_By_Role||''})` : (d.Cashier_Name || '')}</div>
              <div><strong>Approved By:</strong></div><div>${d.Approved_By_Name ? `${d.Approved_By_Name} (${d.Approved_By_Role||''})` : '-'}</div>
              <div><strong>Approved On:</strong></div><div>${d.Approved_Date || '-'}</div>
              <div><strong>Terminal:</strong></div><div>${d.Terminal_ID || '-'}</div>
              <div><strong>Return Date:</strong></div><div>${d.Return_Date}</div>
            </div>
        `;
        document.getElementById('decisionFooter').style.display = 'none';
        modal.style.display = 'block';
    } catch (e) {
        document.getElementById('mgr-status').textContent = e.message;
    }
}

window.viewReturn = viewReturn;

async function decideSelected(decision) {
    const picks = Array.from(document.querySelectorAll('.pick:checked')).map(x => Number(x.value));
    const status = document.getElementById('mgr-status');
    const comments = document.getElementById('mgr-comments').value.trim();
    const exchangeOnly = document.getElementById('exchange-only').checked;
    if (picks.length === 0) { status.textContent = 'Select at least one'; return; }
    if (!comments) { status.textContent = 'Comments required'; return; }
    try {
        const resp = await fetch('../api/customer_returns.php?action=decide', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ return_ids: picks, decision, comments, exchange_only: exchangeOnly })
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Decision failed');
        status.textContent = decision + ' OK';
        await loadPending();
    } catch (e) {
        status.textContent = e.message;
    }
}

window.loadTab = loadTab;
window.decideSelected = decideSelected;

document.addEventListener('DOMContentLoaded', () => loadTab('all'));


