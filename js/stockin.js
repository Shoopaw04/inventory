async function promptNotes(title){
    const reason = prompt(title + '\nPlease enter notes:');
    return reason ? reason.trim() : '';
  }
  
  async function approveStockin(id){
    const notes = await promptNotes('Approve stock-in #' + id);
    if (!notes) { alert('Approval notes are required.'); return; }
    try{
      const res = await fetch('../api/stockin_inventory.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'approve', Stockin_ID: id, approval_notes: notes })
      });
      const json = await res.json();
      if (json.success) {
        const cell = document.getElementById('act-' + id);
        if (cell) { cell.textContent = 'Successful'; cell.className = 'action-success'; }
        showNotification('Approved successfully','success');
        loadStockIns();
      }
      else { showNotification('Error: ' + (json.error||'Approve failed'),'error'); }
    } catch(e){ showNotification('Network error: ' + e.message, 'error'); }
  }
  
  async function rejectStockin(id){
    const notes = await promptNotes('Reject stock-in #' + id);
    if (!notes) { alert('Rejection notes are required.'); return; }
    try{
      const res = await fetch('../api/stockin_inventory.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reject', Stockin_ID: id, approval_notes: notes })
      });
      const json = await res.json();
      if (json.success) {
        const cell = document.getElementById('act-' + id);
        if (cell) { cell.textContent = 'Successful'; cell.className = 'action-success'; }
        showNotification('Rejected successfully','success');
        loadStockIns();
      }
      else { showNotification('Error: ' + (json.error||'Reject failed'),'error'); }
    } catch(e){ showNotification('Network error: ' + e.message, 'error'); }
  }
  document.addEventListener('DOMContentLoaded', async () => {
    // Admin, Manager, Inventory Clerk/Staff can access
    const user = await AuthHelper.requireAuthAndRole(['Admin','Manager','Inventory Clerk','Inventory Staff']);
    if (!user) return;
    window.CURRENT_USER = user.User_name;
    window.CURRENT_ROLE = user.Role_name;
    // Hide approver-only controls for clerks/staff
    const isApprover = ['Admin','Manager'].includes(window.CURRENT_ROLE || '');
    if (!isApprover) {
      // Hide only the Approved By label and select, not the whole action bar
      const approvedSelect = document.getElementById('approved-by');
      if (approvedSelect) approvedSelect.style.display = 'none';
      const approvedLabel = document.querySelector('label[for="approved-by"]');
      if (approvedLabel) approvedLabel.style.display = 'none';
      const manualApprovedWrap = document.getElementById('manual-approved-by')?.closest('.form-group');
      if (manualApprovedWrap) manualApprovedWrap.style.display = 'none';
      const approvedByEl = document.getElementById('approved-by');
      if (approvedByEl) approvedByEl.required = false;
      const manualApprovedEl = document.getElementById('manual-approved-by');
      if (manualApprovedEl) manualApprovedEl.required = false;
      // Change CTA text for clerks/staff to indicate request
      const btn = document.getElementById('process-btn');
      if (btn) btn.textContent = '📨 Submit Request';
      const manualBtn = document.getElementById('manual-submit-btn');
      if (manualBtn) manualBtn.textContent = '📨 Submit Manual Request';
      // Hide Actions header in PO items table
      const thActions = document.getElementById('th-actions');
      if (thActions) thActions.style.display = 'none';
      // Hide actions column in history table and fix colspan
      const thHistActions = document.getElementById('hist-actions-th');
      if (thHistActions) thHistActions.style.display = 'none';
      const loadingRow = document.getElementById('stockin-loading-row');
      if (loadingRow && loadingRow.firstElementChild) loadingRow.firstElementChild.setAttribute('colspan', '11');
    }
  });
  
  let currentPO = null;
  let suppliers = [];
  let products = [];
  let productSupplierMap = {};
  let enhancedMode = false;
  
  function showNotification(message, type) {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type} show`;
    
    setTimeout(() => {
      notification.classList.remove('show');
    }, 3000);
  }
  
  function toggleReceivingMode() {
    enhancedMode = !enhancedMode;
    const toggleBtn = document.getElementById('mode-toggle');
    const description = document.getElementById('mode-description');
    const receivingSection = document.getElementById('receiving-section');
    
    if (enhancedMode) {
      toggleBtn.textContent = '🔄 Switch to Simple Mode';
      description.textContent = 'Current: Enhanced Mode - Handle returns, damaged items, and issues';
      receivingSection.classList.add('enhanced-mode');
    } else {
      toggleBtn.textContent = '🔧 Switch to Enhanced Mode (Handle Returns)';
      description.textContent = 'Current: Simple Mode - Basic receiving only';
      receivingSection.classList.remove('enhanced-mode');
      document.getElementById('enhanced-returns-section').classList.add('hide');
    }
    
    // Reload PO details if currently loaded
    if (currentPO) {
      loadPODetails();
    }
  }
  
  async function loadPurchaseOrders() {
    try {
      const json = await getJSON('list_purchase_order.php');
      
      if (json.success && json.pos) {
        const poSelect = document.getElementById('po-select');
        
        // Filter POs that are approved or have pending items
        const availablePOs = json.pos.filter(po => 
          po.Status === 'Approved' || po.Status === 'Pending'
        );
        
        poSelect.innerHTML = '<option value="">Choose a Purchase Order...</option>' + 
          availablePOs.map(po => {
            const supplierText = po.suppliers_involved || po.supplier_name || '-';
            const receivedText = po.received > 0 ? ` (${po.received} received)` : '';
            return `<option value="${po.PO_ID}">PO #${po.PO_ID} - ${supplierText} - ₱${parseFloat(po.Total_amount||0).toFixed(2)}${receivedText}</option>`;
          }).join('');
        
        // Enable load button when PO is selected
        poSelect.addEventListener('change', function() {
          document.getElementById('load-po-btn').disabled = !this.value;
        });
      }
    } catch (err) {
      console.error('Error loading POs:', err);
      showNotification('Error loading purchase orders', 'error');
    }
  }
  
  async function loadPODetails() {
    const poId = document.getElementById('po-select').value;
    if (!poId) return;
    
    try {
      const json = await getJSON(`get_purchase_order.php?po_id=${poId}`);
      
      if (json.success && json.po) {
        currentPO = json.po;
        displayPODetails(json.po, json.items);
        document.getElementById('po-details').classList.remove('hide');
      } else {
        showNotification('Error loading PO: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  }
  
  async function displayPODetails(po, items) {
    // Display PO info
    document.getElementById('po-info').innerHTML = `
      <h4>📋 PO #${po.PO_ID} Details</h4>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <div><strong>Supplier:</strong> ${po.supplier_name || 'Unknown'}</div>
        <div><strong>Order Date:</strong> ${formatDate(po.Order_date)}</div>
        <div><strong>Status:</strong> <span class="status-${po.Status.toLowerCase()}">${po.Status}</span></div>
        <div><strong>Total Amount:</strong> ₱${parseFloat(po.Total_amount || 0).toFixed(2)}</div>
      </div>
    `;
  
    // No need to derive a single Supplier_ID; items contain their own Supplier_ID
  
    // Get already received quantities for each item
    const receivedData = await getReceivedQuantities(po.PO_ID);
  
    // Build product->supplier map for later use
    productSupplierMap = {};
    (items || []).forEach(it => {
      productSupplierMap[it.Product_ID] = it.item_supplier_id || null;
    });

    // Display items
    const tbody = document.getElementById('po-items-body');
    const isApprover = ['Admin','Manager'].includes(window.CURRENT_ROLE || '');
    tbody.innerHTML = items.map(item => {
      const received = receivedData[item.Product_ID] || 0;
      const remaining = item.Quantity - received;
      const isFullyReceived = remaining <= 0;
      const isPartiallyReceived = received > 0 && remaining > 0;
  
      let rowClass = '';
      if (isFullyReceived) rowClass = 'fully-received';
      else if (isPartiallyReceived) rowClass = 'partially-received';
  
      return `<tr class="item-row ${rowClass}" data-product-id="${item.Product_ID}">
        <td><strong>${item.product_name}</strong></td>
        <td>${item.Quantity}</td>
        <td>${received}</td>
        <td>${Math.max(0, remaining)}</td>
        <td>
          <input type="number" 
                 class="receive-input" 
                 data-product-id="${item.Product_ID}"
                 data-supplier-id="${item.item_supplier_id}"
                 data-field="quantity"
                 data-max="${Math.max(0, remaining)}"
                 data-ordered="${remaining}"
                 min="0" 
                 max="${Math.max(0, remaining)}"
                 placeholder="0"
                 ${isFullyReceived ? 'disabled' : ''}
                 onchange="updateReturnsSection()">
        </td>
        <td>
          <input type="number" 
                 class="receive-input" 
                 data-product-id="${item.Product_ID}"
                 data-field="cost"
                 step="0.01"
                 min="0"
                 value="${item.Purchase_price}"
                 ${isFullyReceived ? 'disabled' : ''}>
        </td>
        <td>
          <input type="text" 
                 class="receive-input" 
                 data-product-id="${item.Product_ID}"
                 data-field="batch"
                 placeholder="Batch #"
                 style="width: 100px;"
                 ${isFullyReceived ? 'disabled' : ''}>
        </td>
        <td>
          <input type="date" 
                 class="receive-input" 
                 data-product-id="${item.Product_ID}"
                 data-field="expiry"
                 ${isFullyReceived ? 'disabled' : ''}>
        </td>
        ${isApprover ? `<td>${!isFullyReceived ? `<button class=\"receive-all-btn\" onclick=\"receiveAll(${item.Product_ID}, ${Math.max(0, remaining)})\">Receive All</button>` : '✅ Complete'}</td>` : ''}
      </tr>`;
    }).join('');
  
    // Show enhanced returns section if in enhanced mode
    if (enhancedMode) {
      setupReturnsSection(items);
    }
  }
  
  function setupReturnsSection(items) {
    const returnsSection = document.getElementById('enhanced-returns-section');
    const container = document.getElementById('returns-inputs-container');
    
    container.innerHTML = items.map(item => {
      return `
        <div class="returns-item" data-product-id="${item.Product_ID}" style="border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px;">
          <h5>${item.product_name}</h5>
          
          <div class="return-type-grid">
            <div class="problem-item">
              <label>Damaged Qty</label>
              <input type="number" 
                     class="return-input" 
                     data-product-id="${item.Product_ID}"
                     data-return-type="damaged"
                     data-field="quantity"
                     min="0" 
                     placeholder="0"
                     onchange="updateReturnsSummary()">
              <select class="return-input" 
                      data-product-id="${item.Product_ID}"
                      data-return-type="damaged"
                      data-field="action">
                <option value="Refund">💰 Refund</option>
                <option value="Replace">🔄 Replace</option>
              </select>
            </div>
            
            <div class="problem-item">
              <label>Expired Qty</label>
              <input type="number" 
                     class="return-input" 
                     data-product-id="${item.Product_ID}"
                     data-return-type="expired"
                     data-field="quantity"
                     min="0" 
                     placeholder="0"
                     onchange="updateReturnsSummary()">
              <select class="return-input" 
                      data-product-id="${item.Product_ID}"
                      data-return-type="expired"
                      data-field="action">
                <option value="Refund">💰 Refund</option>
                <option value="Replace">🔄 Replace</option>
              </select>
            </div>
            
            <div class="problem-item">
              <label>Wrong Item Qty</label>
              <input type="number" 
                     class="return-input" 
                     data-product-id="${item.Product_ID}"
                     data-return-type="wrong_item"
                     data-field="quantity"
                     min="0" 
                     placeholder="0"
                     onchange="updateReturnsSummary()">
              <select class="return-input" 
                      data-product-id="${item.Product_ID}"
                      data-return-type="wrong_item"
                      data-field="action">
                <option value="Refund">💰 Refund</option>
                <option value="Replace">🔄 Replace</option>
              </select>
            </div>
            
            <div class="problem-item">
              <label>Other Issues Qty</label>
              <input type="number" 
                     class="return-input" 
                     data-product-id="${item.Product_ID}"
                     data-return-type="other"
                     data-field="quantity"
                     min="0" 
                     placeholder="0"
                     onchange="updateReturnsSummary()">
              <select class="return-input" 
                      data-product-id="${item.Product_ID}"
                      data-return-type="other"
                      data-field="action">
                <option value="Refund">💰 Refund</option>
                <option value="Replace">🔄 Replace</option>
              </select>
            </div>
          </div>
        </div>
      `;
    }).join('');
    
    returnsSection.classList.remove('hide');
  }
  
  function updateReturnsSection() {
    if (!enhancedMode) return;
    
    // Update the max values for return inputs based on received quantities
    const quantityInputs = document.querySelectorAll('input.receive-input[data-field="quantity"]');
    
    quantityInputs.forEach(input => {
      const productId = input.dataset.productId;
      const receivedQty = parseInt(input.value) || 0;
      const orderedQty = parseInt(input.dataset.ordered) || 0;
      
      // Update max values for return inputs for this product
      const returnInputs = document.querySelectorAll(`input.return-input[data-product-id="${productId}"][data-field="quantity"]`);
      returnInputs.forEach(returnInput => {
        returnInput.max = receivedQty;
        if (parseInt(returnInput.value) > receivedQty) {
          returnInput.value = receivedQty;
        }
      });
    });
    
    updateReturnsSummary();
  }
  
  function updateReturnsSummary() {
    if (!enhancedMode) return;
    
    const summaryDiv = document.getElementById('returns-summary');
    const contentDiv = document.getElementById('returns-summary-content');
    
    let totalReturns = 0;
    let totalRefundAmount = 0;
    let totalReplacements = 0;
    let returnDetails = [];
    
    const returnInputs = document.querySelectorAll('input.return-input[data-field="quantity"]');
    
    returnInputs.forEach(input => {
      const qty = parseInt(input.value) || 0;
      if (qty > 0) {
        const productId = input.dataset.productId;
        const returnType = input.dataset.returnType;
        
        const actionSelect = document.querySelector(`select.return-input[data-product-id="${productId}"][data-return-type="${returnType}"]`);
        const action = actionSelect ? actionSelect.value : 'Refund';
        
        const costInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="cost"]`);
        const unitCost = parseFloat(costInput ? costInput.value : 0);
        
        const productName = document.querySelector(`tr[data-product-id="${productId}"] strong`).textContent;
        
        totalReturns += qty;
        
        if (action === 'Refund') {
          totalRefundAmount += qty * unitCost;
        } else {
          totalReplacements += qty;
        }
        
        returnDetails.push({
          product: productName,
          type: returnType,
          quantity: qty,
          action: action,
          amount: qty * unitCost
        });
      }
    });
    
    if (totalReturns > 0) {
      contentDiv.innerHTML = `
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
          <div><strong>Total Return Items:</strong> ${totalReturns}</div>
          <div><strong>Total Refund Amount:</strong> ₱${totalRefundAmount.toFixed(2)}</div>
          <div><strong>Total Replacements:</strong> ${totalReplacements}</div>
        </div>
        <div style="margin-top: 10px;">
          <strong>Return Details:</strong>
          <ul style="margin: 5px 0; padding-left: 20px;">
            ${returnDetails.map(detail => 
              `<li>${detail.product}: ${detail.quantity} ${detail.type} (${detail.action} - ₱${detail.amount.toFixed(2)})</li>`
            ).join('')}
          </ul>
        </div>
      `;
      summaryDiv.classList.remove('hide');
    } else {
      summaryDiv.classList.add('hide');
    }
  }
  
  async function getReceivedQuantities(poId) {
    try {
      // Get already received quantities from stockin_inventory
      const json = await getJSON(`stockin_inventory.php?po_id=${poId}`);
      
      const received = {};
      if (json.success && json.data) {
        json.data.forEach(stockin => {
          const productId = stockin.Product_ID;
          received[productId] = (received[productId] || 0) + parseInt(stockin.Stock_in_Quantity);
        });
      }
      return received;
    } catch (err) {
      console.error('Error getting received quantities:', err);
      return {};
    }
  }
  
  function receiveAll(productId, maxQuantity) {
    const quantityInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="quantity"]`);
    if (quantityInput) {
      quantityInput.value = maxQuantity;
      updateReturnsSection();
    }
  }
  
  async function processReceiving() {
    console.log('=== Processing Receiving - Mode:', enhancedMode ? 'Enhanced' : 'Simple');
  
    const selectedPoId = parseInt(document.getElementById('po-select')?.value || '0');
    if (!currentPO && !selectedPoId) {
      showNotification('Error: No purchase order loaded', 'error');
      return;
    }
  
    const isApprover = ['Admin','Manager'].includes(window.CURRENT_ROLE || '');
    const approvedBy = document.getElementById('approved-by').value;
    if (isApprover) {
      if (!approvedBy) {
        showNotification('Please select who approved this receiving', 'error');
        return;
      }
    }
  
    const notes = document.getElementById('receiving-notes').value || '';
  
    if (enhancedMode) {
      await processEnhancedReceiving(isApprover ? approvedBy : null, notes);
    } else {
      await processSimpleReceiving(isApprover ? approvedBy : null, notes);
    }
  }
  
  async function processEnhancedReceiving(approvedBy, notes) {
    const quantityInputs = document.querySelectorAll('input.receive-input[data-field="quantity"]');
    const items = [];
  
    // Get the received date from the form
    const receivedDate = document.getElementById('received-date').value || new Date().toISOString().split('T')[0];
    console.log('Simple Receiving - Received Date:', receivedDate);
  
    // Collect all item data including returns
    quantityInputs.forEach(input => {
      const productId = parseInt(input.dataset.productId);
      const receivedQty = parseInt(input.value) || 0;
      const orderedQty = parseInt(input.dataset.ordered) || 0;
  
      if (receivedQty > 0 || hasReturnItems(productId)) {
        const costInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="cost"]`);
        const batchInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="batch"]`);
        const expiryInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="expiry"]`);
  
        const supplierIdAttr = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="quantity"]`)?.dataset?.supplierId;
        const item = {
          Product_ID: productId,
          Supplier_ID: supplierIdAttr ? parseInt(supplierIdAttr) : null,
          ordered_quantity: orderedQty,
          received_quantity: receivedQty,
          unit_cost: parseFloat(costInput?.value || 0),
          batch_number: batchInput?.value || null,
          expiry_date: expiryInput?.value || null,
          received_date: receivedDate
        };
  
        // Add return quantities and types
        const returnTypes = ['damaged', 'expired', 'wrong_item', 'other'];
        returnTypes.forEach(returnType => {
          const qtyInput = document.querySelector(`input.return-input[data-product-id="${productId}"][data-return-type="${returnType}"][data-field="quantity"]`);
          const actionSelect = document.querySelector(`select.return-input[data-product-id="${productId}"][data-return-type="${returnType}"]`);
          
          const qty = parseInt(qtyInput?.value || 0);
          const action = actionSelect?.value || 'Refund';
          
          item[`${returnType}_quantity`] = qty;
          item[`return_type_${returnType}`] = action;
        });
  
        items.push(item);
      }
    });
  
    if (items.length === 0) {
      showNotification('No items to process', 'error');
      return;
    }
  
    try {
      const payload = {
        PO_ID: (currentPO && currentPO.PO_ID) ? currentPO.PO_ID : (parseInt(document.getElementById('po-select')?.value || '0') || null),
        items: items,
        approved_by: approvedBy,
        notes: notes,
        user_id: 1 // You might want to get this from session
      };
  
      console.log('Sending enhanced payload:', payload);
  
      const response = await fetch('../api/stockin_returns.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
  
      const json = await response.json();
  
      if (json.success) {
        showNotification(`Successfully processed! ${json.summary.total_received} items received, ${json.summary.total_returns} returns processed`, 'success');
        loadPODetails(); // Refresh the PO details
        loadStockIns(); // Refresh the history
        
        // Reset form
        document.getElementById('approved-by').value = '';
        document.getElementById('received-date').value = '';
        document.getElementById('receiving-notes').value = '';
        
        // Clear all inputs
        document.querySelectorAll('.receive-input, .return-input').forEach(input => {
          if (input.type === 'number') input.value = '';
          else if (input.tagName === 'SELECT') input.selectedIndex = 0;
          else input.value = '';
        });
        
        updateReturnsSummary();
      } else {
        showNotification('Error processing receiving: ' + json.error, 'error');
      }
  
    } catch (err) {
      console.error('Enhanced receiving error:', err);
      showNotification('Error: ' + err.message, 'error');
    }
  }
  
  async function processSimpleReceiving(approvedBy, notes) {
    const quantityInputs = document.querySelectorAll('input.receive-input[data-product-id]');
    const itemsToReceive = [];
    
    // Get the received date from the form
    const receivedDate = document.getElementById('received-date').value || new Date().toISOString().split('T')[0];
    console.log('Simple Receiving - Received Date:', receivedDate);
    
    quantityInputs.forEach(input => {
      const productId = input.dataset.productId;
      const field = input.dataset.field;
  
      if (field === "quantity") {
        const quantity = parseInt(input.value) || 0;
        if (quantity > 0) {
          const costInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="cost"]`);
          const batchInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="batch"]`);
          const expiryInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="expiry"]`);
  
          const supplierIdAttr = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="quantity"]`)?.dataset?.supplierId;
          const resolvedSupplierId = supplierIdAttr ? parseInt(supplierIdAttr) : (productSupplierMap[productId] || null);
          if (!resolvedSupplierId) {
            showNotification(`Error: Missing supplier for product ID ${productId}. Please assign a supplier to the product before receiving.`, 'error');
            return;
          }
          const poIdFromUI = parseInt(document.getElementById('po-select')?.value || '0') || null;
          itemsToReceive.push({
            Product_ID: parseInt(productId),
            Supplier_ID: resolvedSupplierId,
            Stock_in_Quantity: quantity,
            Unit_Cost: parseFloat(costInput?.value || 0),
            Batch_Number: batchInput?.value || null,
            Expiry_Date: expiryInput?.value || null,
            PO_ID: (currentPO && currentPO.PO_ID) ? currentPO.PO_ID : poIdFromUI,
            Approved_by: approvedBy,
            Remarks: notes,
            Status: (['Admin','Manager'].includes(window.CURRENT_ROLE || '')) ? 'RECEIVED' : 'PENDING',
            Date_in: receivedDate
          });
        }
      }
    });
  
    if (itemsToReceive.length === 0) {
      showNotification('No items selected for receiving', 'error');
      return;
    }
  
    try {
      const response = await fetch('../api/stockin_inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(itemsToReceive)
      });
  
      const json = await response.json();
      if (json.success) {
        showNotification(`Successfully received ${itemsToReceive.length} items!`, 'success');
        loadPODetails();
        loadStockIns();
        // Reset form
        document.getElementById('approved-by').value = '';
        document.getElementById('received-date').value = '';
        document.getElementById('receiving-notes').value = '';
      } else {
        showNotification('Error: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  }
  
  function hasReturnItems(productId) {
    const returnInputs = document.querySelectorAll(`input.return-input[data-product-id="${productId}"][data-field="quantity"]`);
    for (let input of returnInputs) {
      if (parseInt(input.value) > 0) return true;
    }
    return false;
  }
  
  async function loadDropdownsForManual() {
    try {
      const json = await getJSON('stockin_inventory.php');
      
      if (json.success) {
        if (json.suppliers) {
          const supplierSelect = document.getElementById('manual-supplier');
          supplierSelect.innerHTML = '<option value="">Select Supplier...</option>' + 
            json.suppliers.map(s => `<option value="${s.Supplier_ID}">${s.supplier_name}</option>`).join('');
        }
        
        if (json.products) {
          const productSelect = document.getElementById('manual-product');
          productSelect.innerHTML = '<option value="">Select Product...</option>' + 
            json.products.map(p => `<option value="${p.Product_ID}">${p.Name}</option>`).join('');
        }
      }
    } catch (err) {
      console.error('Error loading dropdowns:', err);
    }
  }
  
  function toggleManualEntry() {
    const section = document.getElementById('manual-entry');
    section.classList.toggle('hide');
    
    if (!section.classList.contains('hide')) {
      loadDropdownsForManual();
    }
  }
  
  async function loadStockIns() {
    const tbody = document.getElementById('stockin-body');
    const search = document.getElementById('stockin-search').value;
    const supplierId = document.getElementById('supplier-filter').value;
    const status = document.getElementById('status-filter').value;
    const dateFrom = document.getElementById('date-from').value;
    const dateTo = document.getElementById('date-to').value;
    
    tbody.innerHTML = '<tr><td colspan="12" class="loading">Loading stock ins...</td></tr>';
    
    try {
      let url = 'stockin_inventory.php';
      const params = [];
      
      if (search) params.push('search=' + encodeURIComponent(search));
      if (supplierId) params.push('supplier_id=' + encodeURIComponent(supplierId));
      if (status) params.push('status=' + encodeURIComponent(status));
      // For clerks/staff: show only own requests when role is known and is non-approver
      // Do not force status for clerks/staff; let them view all statuses unless they choose a filter
      if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
      if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
      
      if (params.length > 0) url += '?' + params.join('&');
      
      const json = await getJSON(url);
      
      if (json.success && json.data && json.data.length > 0) {
        let records = json.data;
        // For clerks/staff, show all PENDING records without restricting to own remarks
        // This avoids empty lists when historical entries lack the remark tag
        // Populate supplier filter
        if (json.suppliers) {
          const supplierFilter = document.getElementById('supplier-filter');
          const currentValue = supplierFilter.value;
          supplierFilter.innerHTML = '<option value="">All Suppliers</option>' + 
            json.suppliers.map(s => `<option value="${s.Supplier_ID}">${s.supplier_name}</option>`).join('');
          if (currentValue) supplierFilter.value = currentValue;
        }
  
        tbody.innerHTML = records.map(item => `<tr>
          <td>${item.Stockin_ID}</td>
          <td>${item.PO_ID ? `PO #${item.PO_ID}` : 'Manual'}</td>
          <td>${formatDate(item.Date_in)}</td>
          <td>
            <strong>${item.product_name}</strong>
          </td>
          <td>${item.supplier_name || 'Unknown'}</td>
          <td class="quantity-display" style="color: #28a745; font-weight: bold;">+${item.Stock_in_Quantity}</td>
          <td style="font-family: monospace;">₱${parseFloat(item.Unit_Cost || 0).toFixed(2)}</td>
          <td style="font-family: monospace;"><strong>₱${parseFloat(item.Total_Cost || 0).toFixed(2)}</strong></td>
          <td>${item.Batch_Number || '-'}</td>
          <td class="status-${item.Status.toLowerCase()}">${item.Status}</td>
          <td>${item.approved_by_name ? `${item.approved_by_name} (${item.approved_by_role || 'Unknown Role'})` : (item.Approved_by || '')}</td>
          ${(['Admin','Manager'].includes(window.CURRENT_ROLE || '') && String(item.Status).toUpperCase()==='PENDING') ? `
            <td id="act-${item.Stockin_ID}">
              <button class="btn-approve" onclick="approveStockin(${item.Stockin_ID})">Approve</button>
              <button class="btn-reject" onclick="rejectStockin(${item.Stockin_ID})">Reject</button>
            </td>
          ` : (['Admin','Manager'].includes(window.CURRENT_ROLE || '') ? '<td class="action-success">Successful</td>' : '')}
        </tr>`).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="12">No stock in records found</td></tr>';
      }
    } catch (err) {
      console.error('Error loading stock ins:', err);
      tbody.innerHTML = `<tr><td colspan="11">Error: ${err.message}</td></tr>`;
    }
  }
  
  function refreshStockIns() {
    document.getElementById('stockin-search').value = '';
    document.getElementById('supplier-filter').value = '';
    document.getElementById('status-filter').value = '';
    document.getElementById('date-from').value = '';
    document.getElementById('date-to').value = '';
    loadStockIns();
  }
  
  function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    try {
      const date = new Date(dateStr);
      return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      });
    } catch (e) {
      return 'Invalid Date';
    }
  }
  
  // Manual form submission
  document.getElementById('manual-stockin-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = {};
    
    for (let [key, value] of formData.entries()) {
      if (value !== '') {
        data[key] = value;
      }
    }
    
    // Validation: only approvers must select Approved By
    const isApprover = ['Admin','Manager'].includes(window.CURRENT_ROLE || '');
    if (isApprover) {
      if (!data.Approved_by) {
        showNotification('Please select who approved this stock in', 'error');
        return;
      }
    } else {
      // For clerks/staff, ensure Approved_by is not sent
      delete data.Approved_by;
    }
    
    // Add manual entry defaults
    data.Date_in = new Date().toISOString().split('T')[0];
    data.Status = isApprover ? 'RECEIVED' : 'PENDING';
    
    try {
      const response = await fetch('../api/stockin_inventory.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
      });
      
      const json = await response.json();
      
      if (json.success) {
        showNotification('Manual stock in added successfully!', 'success');
        toggleManualEntry();
        e.target.reset();
        loadStockIns();
      } else {
        showNotification('Error: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error adding manual stock in: ' + err.message, 'error');
    }
  });
  
  // Load data on page load
  document.addEventListener('DOMContentLoaded', function() {
    // Set default received date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('received-date').value = today;
    document.getElementById('manual-received-date').value = today;
    
    loadPurchaseOrders();
    loadStockIns();
  
    // Ensure CTA reflects role even if role init runs earlier/later
    const isApprover = ['Admin','Manager'].includes(window.CURRENT_ROLE || '');
    if (!isApprover) {
      const btn = document.getElementById('process-btn');
      if (btn) btn.textContent = '📨 Submit Request';
      const notes = document.getElementById('receiving-notes');
      if (notes && (!notes.placeholder || notes.placeholder === 'Any remarks...')) {
        notes.placeholder = 'Reason or remarks for this request...';
      }
    }
  });

  // Expose for inline onclicks
  window.toggleReceivingMode = toggleReceivingMode;
  window.loadPODetails = loadPODetails;
  window.toggleManualEntry = toggleManualEntry;
  window.processReceiving = processReceiving;
  window.refreshStockIns = refreshStockIns;
  window.approveStockin = approveStockin;
  window.rejectStockin = rejectStockin;
  // Function to load users with appropriate roles for approval
  async function loadUsers() {
    try {
      const response = await fetch('../api/users.php');
      const json = await response.json();
      
      if (json.success && json.approvers) {
        const approvedBySelect = document.getElementById('approved-by');
        const manualApprovedBySelect = document.getElementById('manual-approved-by');
        
        // Create options for users who can approve (Admin, Manager roles)
        const approverOptions = json.approvers.map(user => 
          `<option value="${user.User_ID}">${user.User_name} (${user.Role_name})</option>`
        ).join('');
        
        approvedBySelect.innerHTML = '<option value="">Select Manager/Approver...</option>' + approverOptions;
        if (manualApprovedBySelect) {
          manualApprovedBySelect.innerHTML = '<option value="">Select Manager/Approver...</option>' + approverOptions;
        }
        
        console.log('Loaded approvers:', json.approvers);
      } else {
        // Fallback to hardcoded options if users API doesn't exist
        console.log('Users API not available, using fallback options');
        const fallbackOptions = `
          <option value="1">admin (Admin)</option>
          <option value="2">admin (Admin)</option>
          <option value="3">manager1 (Manager)</option>
        `;
        document.getElementById('approved-by').innerHTML = '<option value="">Select Manager/Approver...</option>' + fallbackOptions;
        document.getElementById('manual-approved-by').innerHTML = '<option value="">Select Manager/Approver...</option>' + fallbackOptions;
      }
    } catch (err) {
      console.error('Error loading users:', err);
      // Keep existing hardcoded options as fallback
    }
  }
  
  // Modified processEnhancedReceiving function
  async function processEnhancedReceiving(approvedBy, notes) {
    const quantityInputs = document.querySelectorAll('input.receive-input[data-field="quantity"]');
    const items = [];
  
    // Get the received date from the form
    const receivedDate = document.getElementById('received-date').value || new Date().toISOString().split('T')[0];
    console.log('Enhanced Receiving - Received Date:', receivedDate);
  
    // Collect all item data including returns
    quantityInputs.forEach(input => {
      const productId = parseInt(input.dataset.productId);
      const receivedQty = parseInt(input.value) || 0;
      const orderedQty = parseInt(input.dataset.ordered) || 0;
  
      if (receivedQty > 0 || hasReturnItems(productId)) {
        const costInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="cost"]`);
        const batchInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="batch"]`);
        const expiryInput = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="expiry"]`);
  
        const supplierIdAttr2 = document.querySelector(`input.receive-input[data-product-id="${productId}"][data-field="quantity"]`)?.dataset?.supplierId;
        const item = {
          Product_ID: productId,
          Supplier_ID: supplierIdAttr2 ? parseInt(supplierIdAttr2) : null,
          ordered_quantity: orderedQty,
          received_quantity: receivedQty,
          unit_cost: parseFloat(costInput?.value || 0),
          batch_number: batchInput?.value || null,
          expiry_date: expiryInput?.value || null,
          received_date: receivedDate
        };
  
        // Add return quantities and types
        const returnTypes = ['damaged', 'expired', 'wrong_item', 'other'];
        returnTypes.forEach(returnType => {
          const qtyInput = document.querySelector(`input.return-input[data-product-id="${productId}"][data-return-type="${returnType}"][data-field="quantity"]`);
          const actionSelect = document.querySelector(`select.return-input[data-product-id="${productId}"][data-return-type="${returnType}"]`);
          
          const qty = parseInt(qtyInput?.value || 0);
          const action = actionSelect?.value || 'Refund';
          
          item[`${returnType}_quantity`] = qty;
          item[`return_type_${returnType}`] = action;
        });
  
        items.push(item);
      }
    });
  
    if (items.length === 0) {
      showNotification('No items to process', 'error');
      return;
    }
  
    try {
      // Get the currently logged-in user ID (you may need to implement this)
      const currentUserId = await getCurrentUserId(); // You'll need to implement this
  
      const payload = {
        PO_ID: currentPO.PO_ID,
        items: items,
        approved_by: approvedBy, // This should now be a user ID from the select
        notes: notes,
        user_id: currentUserId || 1, // Current user performing the action
        terminal_id: 1 // Add terminal ID - use appropriate terminal
      };
  
      console.log('Sending enhanced payload:', payload);
  
      const response = await fetch('../api/stockin_returns.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
  
      const json = await response.json();
  
      if (json.success) {
        showNotification(`Successfully processed! ${json.summary.total_received} items received, ${json.summary.total_returns} returns processed`, 'success');
        loadPODetails(); // Refresh the PO details
        loadStockIns(); // Refresh the history
        
        // Reset form
        document.getElementById('approved-by').value = '';
        document.getElementById('received-date').value = '';
        document.getElementById('receiving-notes').value = '';
        
        // Clear all inputs
        document.querySelectorAll('.receive-input, .return-input').forEach(input => {
          if (input.type === 'number') input.value = '';
          else if (input.tagName === 'SELECT') input.selectedIndex = 0;
          else input.value = '';
        });
        
        updateReturnsSummary();
      } else {
        showNotification('Error processing receiving: ' + json.error, 'error');
      }
  
    } catch (err) {
      console.error('Enhanced receiving error:', err);
      showNotification('Error: ' + err.message, 'error');
    }
  }
  
  // Add this helper function to get current user ID
  async function getCurrentUserId() {
    try {
      // You'll need to implement this based on your authentication system
      // This could be from session, localStorage, or an API call
      // For now, return a default value
      return 1; // Replace with actual logic to get current user
    } catch (err) {
      console.error('Error getting current user ID:', err);
      return 1; // Fallback
    }
  }
  
  // Update the DOMContentLoaded event listener
  document.addEventListener('DOMContentLoaded', function() {
    loadUsers(); // Add this line
    loadPurchaseOrders();
    loadStockIns();
  });