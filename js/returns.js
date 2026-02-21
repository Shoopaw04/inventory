console.log('returns.js loaded');

let LAST_LOOKUP_ITEMS = [];

async function lookupSale() {
    const saleId = Number(document.getElementById('sale-id').value || 0);
    const date = document.getElementById('sale-date').value || '';
    const summary = document.getElementById('sale-summary');
    const foundWrap = document.getElementById('found-sale');
    const foundItems = document.getElementById('found-items');
    summary.textContent = '';
    foundWrap.style.display = 'none';
    foundItems.innerHTML = '';
    try {
        const qs = new URLSearchParams();
        qs.set('sub', 'lookup_sale');
        if (saleId) qs.set('sale_id', String(saleId));
        if (date) qs.set('date', date);
        const resp = await fetch('../api/customer_returns.php?' + qs.toString());
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Lookup failed');
        const sales = data.data || [];
        if (sales.length === 0) { summary.textContent = 'No matching transactions.'; return; }
        const sale = sales[0];
        summary.textContent = `Sale #${sale.Sale_ID} • Terminal #${sale.Terminal_ID} • Total ₱${Number(sale.Total_Amount||0).toFixed(2)}`;
        if (Array.isArray(sale.items)) {
            foundWrap.style.display = '';
            LAST_LOOKUP_ITEMS = sale.items.map(it => ({
                saleItemId: it.SaleItem_ID,
                productId: it.Product_ID,
                name: it.Name || String(it.Product_ID),
                qty: it.Quantity,
                price: Number(it.Sale_Price||0)
            }));
            // Populate datalist for manual product name input
            const dl = document.getElementById('sale-products');
            if (dl) {
                dl.innerHTML = '';
                LAST_LOOKUP_ITEMS.forEach(it => {
                    const opt = document.createElement('option');
                    opt.value = it.name;
                    dl.appendChild(opt);
                });
            }
            sale.items.forEach(it => {
                const div = document.createElement('div');
                div.className = 'list-row';
                div.innerHTML = `
                    <div>#${it.SaleItem_ID}</div>
                    <div>${it.Name || it.Product_ID}</div>
                    <div>₱${Number(it.Sale_Price||0).toFixed(2)}</div>
                    <div>${it.Quantity}</div>
                    <div><button class="btn btn-secondary" onclick="pickForReturn(${it.SaleItem_ID}, ${it.Product_ID}, ${it.Quantity}, ${Number(it.Sale_Price||0)})">Return</button></div>
                `;
                foundItems.appendChild(div);
            });
        }
    } catch (e) {
        summary.textContent = e.message;
    }
}

function pickForReturn(saleItemId, productId, maxQty, unitPrice) {
    const itemsDiv = document.getElementById('items');
    const row = document.createElement('div');
    row.className = 'return-item-row';
    const match = LAST_LOOKUP_ITEMS.find(x => x.saleItemId === saleItemId) || { name: '', productId };
    row.innerHTML = `
        <div class="field"><label>Sale Item ID</label><input type="number" class="si input" value="${saleItemId}" placeholder="e.g. 94"></div>
        <div class="field"><label>Product</label><input type="text" class="pname input" value="${match.name}" placeholder="Product name"><input type="hidden" class="pid" value="${match.productId}"></div>
        <div class="field"><label>Quantity</label><input type="number" class="qty input" placeholder="Qty" min="1" max="${maxQty}" value="1"></div>
        <div class="field"><label>Reason</label>
            <select class="reason input">
                <option value="Wrong item">Wrong item</option>
                <option value="Defective/Damaged product">Defective/Damaged product</option>
                <option value="Expired product">Expired product</option>
                <option value="Changed mind">Changed mind</option>
                <option value="Not as described">Not as described</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <button aria-label="Remove" class="btn-icon" onclick="this.parentElement.remove()">✖</button>
    `;
    itemsDiv.appendChild(row);
}

function addEmptyRow() {
    const itemsDiv = document.getElementById('items');
    const row = document.createElement('div');
    row.className = 'return-item-row';
    row.innerHTML = `
        <div class="field"><label>Sale Item ID</label><input type="number" class="si input" placeholder="e.g. 94"></div>
        <div class="field"><label>Product</label><input type="text" class="pname input" placeholder="Product name"><input type="hidden" class="pid" value="0"></div>
        <div class="field"><label>Quantity</label><input type="number" class="qty input" placeholder="Qty" min="1" value="1"></div>
        <div class="field"><label>Reason</label>
            <select class="reason input">
                <option value="Defective/Damaged product">Defective/Damaged product</option>
                <option value="Expired product">Expired product</option>
                <option value="Customer changed mind">Customer changed mind</option>
                <option value="Wrong item purchased">Wrong item purchased</option>
                <option value="Product not as described">Product not as described</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <button aria-label="Remove" class="btn-icon" onclick="this.parentElement.remove()">✖</button>
    `;
    itemsDiv.appendChild(row);
}

function addEmptyRowFromInputs() {
    const si = Number(document.getElementById('manual-si').value || 0);
    const pname = (document.getElementById('manual-pname').value || '').toLowerCase().trim();
    const found = LAST_LOOKUP_ITEMS.find(x => x.name.toLowerCase() === pname);
    const pid = found ? found.productId : 0;
    const qty = Number(document.getElementById('manual-qty').value || 1);
    if (!si || !pid || qty <= 0) return;
    pickForReturn(si, pid, qty, 0);
}

async function submitReturn() {
    const status = document.getElementById('submit-status');
    const itemsDiv = document.getElementById('items');
    const rows = Array.from(itemsDiv.querySelectorAll('.return-item-row'));
    const returnType = document.getElementById('return-type').value;
    // Build items with validation and friendly messages
    const items = [];
    let invalidMsg = '';
    rows.forEach((r, index) => {
        console.log(`Processing row ${index}:`, r);
        const si = Number(r.querySelector('.si').value || 0);
        const pidHidden = r.querySelector('.pid');
        const pnameInput = r.querySelector('.pname');
        const qty = Number(r.querySelector('.qty').value || 0);
        const reason = r.querySelector('.reason').value;
        
        console.log(`Row ${index} values:`, { si, qty, reason, hasPidHidden: !!pidHidden, hasPnameInput: !!pnameInput });
        
        let pid = pidHidden ? Number(pidHidden.value || 0) : 0;
        if (!pid && pnameInput && LAST_LOOKUP_ITEMS.length) {
            const name = (pnameInput.value || '').toLowerCase().trim();
            const found = LAST_LOOKUP_ITEMS.find(x => x.name.toLowerCase() === name);
            if (found) pid = found.productId;
            console.log(`Row ${index} product lookup:`, { name, found: !!found, pid });
        }
        
        if (!si || !pid || qty <= 0) {
            invalidMsg = `Row ${index + 1}: Please provide valid Sale Item ID, Product name, and Quantity > 0.`;
            console.log(`Row ${index} validation failed:`, { si, pid, qty });
            return;
        }
        items.push({ sale_item_id: si, product_id: pid, quantity: qty, reason });
    });
    if (invalidMsg) {
        status.textContent = invalidMsg;
        return;
    }
    if (items.length === 0) {
        status.textContent = 'Add at least one valid item';
        return;
    }
    console.log('Submitting items:', items);
    
    try {
        const payload = { 
            items, 
            return_type: returnType, 
            auto_apply: document.getElementById('auto-apply').checked 
        };
        console.log('Payload:', payload);
        
        const resp = await fetch('../api/customer_returns.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        console.log('Response status:', resp.status);
        const data = await resp.json();
        console.log('Response data:', data);
        
        if (!data.success) throw new Error(data.error || 'Submit failed');
        status.textContent = `Submitted. Pending manager approval. IDs: ${data.created_ids.join(', ')}`;
        document.getElementById('pending-status').textContent = 'Awaiting approval...';
        // Reload "My Returns" to show the new pending rows immediately
        await loadMyReturns('All');
    } catch (e) {
        console.error('Submit error:', e);
        const msg = (e && e.message) ? e.message : String(e || '');
        const low = (msg || '').toLowerCase();
        const looksDuplicate = low.includes('already') || low.includes('duplicate') || low.includes('fully returned') || low.includes('processed');
        if (looksDuplicate) {
            showDuplicateReturnModal(msg);
            return; // Don't show error in status, modal handles it
        }
        status.textContent = e.message;
    }
}

window.lookupSale = lookupSale;
window.addEmptyRow = addEmptyRow;
window.submitReturn = submitReturn;
window.pickForReturn = pickForReturn;
window.addEmptyRowFromInputs = addEmptyRowFromInputs;

async function loadMyReturns(status) {
    const target = document.getElementById('my-returns');
    target.innerHTML = '';
    const qs = new URLSearchParams();
    if (status && status !== 'All') qs.set('status', status);
    const resp = await fetch('../api/customer_returns.php?' + qs.toString());
    const data = await resp.json();
    if (!data.success) { target.innerHTML = '<div class="list-row"><div colspan="5">Failed to load</div></div>'; return; }
    const rows = data.data || [];
    if (rows.length === 0) {
        target.innerHTML = '<div class="empty-state">No returns found</div>';
    }
    rows.forEach(row => {
        const div = document.createElement('div');
        div.className = 'list-row';
        div.innerHTML = `
            <div>#${row.Return_ID}</div>
            <div>${row.Product_Name || row.Product_ID}</div>
            <div>${row.Status}</div>
            <div>${row.Approved_Date || ''}</div>
            <div>${row.Quantity} <button class="btn btn-secondary" onclick="viewReturnDetails(${row.Return_ID})">View</button></div>
        `;
        target.appendChild(div);
    });
}

document.addEventListener('DOMContentLoaded', () => loadMyReturns('All'));

async function viewReturnDetails(id) {
    try {
        const resp = await fetch(`../api/customer_returns.php?sub=details&return_id=${id}`);
        const json = await resp.json();
        if (!json.success) throw new Error(json.error || 'Failed to load details');
        const d = json.data;
        document.getElementById('retDetailsTitle').textContent = `Return #${d.Return_ID}`;
        document.getElementById('retDetailsBody').innerHTML = `
          <div style="display:grid;grid-template-columns:160px 1fr;gap:8px;">
            <div><strong>Product:</strong></div><div>${d.Product_Name || d.Product_ID}</div>
            <div><strong>Status:</strong></div><div>${d.Status}</div>
            <div><strong>Reason:</strong></div><div>${d.Reason}</div>
            <div><strong>Qty:</strong></div><div>${d.Quantity}</div>
            <div><strong>Sale ID:</strong></div><div>${d.Sale_ID || ''}</div>
            <div><strong>Requested By:</strong></div><div>${d.Requested_By_Name || d.Cashier_Name || '-'}</div>
            <div><strong>Approved By:</strong></div><div>${d.Approved_By_Name || '-'}</div>
            <div><strong>Approved On:</strong></div><div>${d.Approved_Date || '-'}</div>
            <div><strong>Terminal:</strong></div><div>${d.Terminal_ID || '-'}</div>
            <div><strong>Return Date:</strong></div><div>${d.Return_Date}</div>
          </div>`;
        document.getElementById('retDetailsModal').style.display = 'block';
    } catch (e) {
        console.error(e);
    }
}

async function openMySalesLookup() {
    try {
        const resp = await fetch('../api/customer_returns.php?sub=my_sales&limit=25');
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Failed to load my sales');
        const list = document.getElementById('my-sales-list');
        list.innerHTML = '<div class="list-head" style="display:grid;grid-template-columns:100px 1fr 120px 120px;gap:8px;padding:12px 16px;background:#f8fafc;font-weight:600;color:#4a5568;">\
            <div>Sale ID</div><div>Items</div><div>Total</div><div>Action</div></div>';
        (data.data || []).forEach(s => {
            const row = document.createElement('div');
            row.className = 'list-row';
            const items = (s.items||[]).map(i => `${i.Name||i.Product_ID}×${i.Quantity}`).join(', ');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '100px 1fr 120px 120px';
            row.style.gap = '8px';
            row.style.padding = '12px 16px';
            row.innerHTML = `<div>#${s.Sale_ID}</div><div>${items}</div><div>₱${Number(s.Total_Amount||0).toFixed(2)}</div><div><button class="btn btn-secondary" onclick="selectSale(${s.Sale_ID})">Select</button></div>`;
            list.appendChild(row);
        });
        document.getElementById('mySalesModal').style.display = 'block';
    } catch (e) {
        console.error(e);
    }
}

async function selectSale(saleId) {
    // Fill the search field and reuse existing lookup logic
    document.getElementById('sale-id').value = String(saleId);
    document.getElementById('mySalesModal').style.display = 'none';
    await lookupSale();
}

window.openMySalesLookup = openMySalesLookup;
window.selectSale = selectSale;

function showDuplicateReturnModal(message) {
    const modal = document.getElementById('duplicateReturnModal');
    const text = document.getElementById('duplicateReturnMessage');
    if (text) text.textContent = message || 'This sale or item was already returned or fully requested.';
    if (modal) modal.style.display = 'block';
}


window.showDuplicateReturnModal = showDuplicateReturnModal;


