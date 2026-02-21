// Guard: Managers and Admins (and optionally Inventory Staff if desired)
document.addEventListener('DOMContentLoaded', async () => {
    const user = await AuthHelper.requireAuthAndRole(['Manager','Admin']);
    if (!user) return;
  });
  let currentPage = 1;
  let suppliers = [];
  let products = [];
  let purchaseOrders = [];
  let currentReplacementReturn = null;

  function showNotification(message, type) {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type} show`;
    
    setTimeout(() => {
      notification.classList.remove('show');
    }, 3000);
  }

  async function loadDropdownData() {
    try {
      const response = await fetch('../api/supplier_returns.php?action=dropdowns');
      const json = await response.json();
      
      if (json.success) {
        suppliers = json.suppliers || [];
        products = json.products || [];
        purchaseOrders = json.purchase_orders || [];
        
        populateDropdowns();
      }
    } catch (err) {
      console.error('Error loading dropdown data:', err);
    }
  }

  function populateDropdowns() {
    // Populate supplier dropdowns
    const supplierSelects = ['return-supplier', 'supplier-filter'];
    supplierSelects.forEach(selectId => {
      const select = document.getElementById(selectId);
      if (select) {
        const isFilter = selectId.includes('filter');
        select.innerHTML = (isFilter ? '<option value="">All Suppliers</option>' : '<option value="">Select Supplier...</option>') +
          suppliers.map(s => `<option value="${s.Supplier_ID}">${s.supplier_name}</option>`).join('');
      }
    });
    
    // Populate product dropdown
    const productSelect = document.getElementById('return-product');
    if (productSelect) {
      productSelect.innerHTML = '<option value="">Select Product...</option>' +
        products.map(p => `<option value="${p.Product_ID}" data-price="${p.Retail_Price}">${p.Name} (${p.Unit_measure})</option>`).join('');
    }
    
    // Populate PO dropdown
    const poSelect = document.getElementById('return-po');
    if (poSelect) {
      poSelect.innerHTML = '<option value="">Select PO (Optional)...</option>' +
        purchaseOrders.map(po => `<option value="${po.PO_ID}">PO #${po.PO_ID} - ${po.supplier_name} (${formatDate(po.Order_date)})</option>`).join('');
    }
  }

  // Auto-fill unit cost when product is selected
  document.addEventListener('change', function(e) {
    if (e.target.id === 'return-product') {
      const selectedOption = e.target.options[e.target.selectedIndex];
      const price = selectedOption.dataset.price;
      if (price) {
        document.getElementById('return-unit-cost').value = parseFloat(price).toFixed(2);
      }
    }
  });

  function toggleReturnForm() {
    const form = document.getElementById('return-form');
    const btn = document.getElementById('toggle-form-btn');
    
    form.classList.toggle('hide');
    
    if (form.classList.contains('hide')) {
      btn.textContent = '+ Create New Return';
    } else {
      btn.textContent = 'Cancel';
      if (suppliers.length === 0) {
        loadDropdownData();
      }
    }
  }

  async function loadSummary() {
    try {
      const response = await fetch('../api/supplier_returns.php?action=summary');
      const json = await response.json();
      
      if (json.success && json.summary) {
        const summaryData = {
          pending: 0,
          processing: 0,
          completed: 0,
          pending_value: 0
        };
        
        json.summary.forEach(item => {
          if (item.Status === 'Pending') {
            summaryData.pending += parseInt(item.count);
            summaryData.pending_value += parseFloat(item.total_refunds || 0);
          }
          if (item.Status === 'Processing') summaryData.processing += parseInt(item.count);
          if (item.Status === 'Completed') summaryData.completed += parseInt(item.count);
        });
        
        document.getElementById('pending-count').textContent = summaryData.pending;
        document.getElementById('processing-count').textContent = summaryData.processing;
        document.getElementById('completed-count').textContent = summaryData.completed;
        document.getElementById('total-pending-value').textContent = '₱' + summaryData.pending_value.toFixed(2);
      }
    } catch (err) {
      console.error('Error loading summary:', err);
    }
  }

  async function loadReturns(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('returns-body');
    const search = document.getElementById('returns-search').value;
    const supplierId = document.getElementById('supplier-filter').value;
    const status = document.getElementById('status-filter').value;
    const returnType = document.getElementById('type-filter').value;
    const dateFrom = document.getElementById('date-from').value;
    const dateTo = document.getElementById('date-to').value;
    
    tbody.innerHTML = '<tr><td colspan="11" class="loading">Loading returns...</td></tr>';
    
    try {
      let url = '../api/supplier_returns.php';
      const params = [`page=${page}`];
      
      if (search) params.push('search=' + encodeURIComponent(search));
      if (supplierId) params.push('supplier_id=' + encodeURIComponent(supplierId));
      if (status) params.push('status=' + encodeURIComponent(status));
      if (returnType) params.push('return_type=' + encodeURIComponent(returnType));
      if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
      if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
      
      if (params.length > 0) url += '?' + params.join('&');
      
      const response = await fetch(url);
      const json = await response.json();
      
      if (json.success && json.data && json.data.length > 0) {
        tbody.innerHTML = json.data.map(item => {
          return `<tr>
            <td>#${item.SupplierReturn_ID}</td>
            <td>${item.formatted_return_date}</td>
            <td>${item.supplier_name || 'Unknown'}</td>
            <td><strong>${item.product_name}</strong><br><small>${item.Unit_measure}</small></td>
            <td style="text-align: center; font-weight: bold;">${item.Quantity}</td>
            <td>
              <span style="display: inline-block; padding: 2px 6px; background: #f8f9fa; border-radius: 3px; font-size: 12px;">
                ${item.Reason}
              </span>
            </td>
            <td>${getTypeIcon(item.Return_Type)} ${item.Return_Type}</td>
            <td style="font-family: monospace;">₱${parseFloat(item.Total_Amount || 0).toFixed(2)}</td>
            <td class="status-${item.Status.toLowerCase()}">${getStatusIcon(item.Status)} ${item.Status}</td>
            <td>${item.Updated_at ? formatDate(item.Updated_at) : formatDate(item.Return_date)}</td>
            <td>
              <div class="action-buttons">
                ${getWorkflowActions(item)}
              </div>
            </td>
          </tr>`;
        }).join('');
        
        // Update pagination
        updatePagination(json.pagination);
        
      } else {
        tbody.innerHTML = '<tr><td colspan="11">No returns found</td></tr>';
        document.getElementById('pagination').innerHTML = '';
      }
    } catch (err) {
      console.error('Error loading returns:', err);
      tbody.innerHTML = `<tr><td colspan="11">Error: ${err.message}</td></tr>`;
    }
  }

  function getTypeIcon(type) {
    return type === 'Refund' ? '💰' : '🔄';
  }

  function getStatusIcon(status) {
    const icons = {
      'Pending': '⏳',
      'Processing': '⚙️',
      'Completed': '✅',
      'Rejected': '❌'
    };
    return icons[status] || '❓';
  }

  function getWorkflowActions(item) {
    const actions = [];
    
    switch (item.Status) {
      case 'Pending':
        actions.push(`<button class="process-btn" onclick="startProcessing(${item.SupplierReturn_ID})">Start Processing</button>`);
        actions.push(`<button class="reject-btn" onclick="rejectReturn(${item.SupplierReturn_ID})">Reject</button>`);
        break;
        
      case 'Processing':
        if (item.Return_Type === 'Replace') {
          actions.push(`<button class="receive-btn" onclick="receiveReplacements(${item.SupplierReturn_ID})">Receive Replacements</button>`);
        } else {
          actions.push(`<button class="complete-btn" onclick="completeReturn(${item.SupplierReturn_ID})">Mark Completed</button>`);
        }
        break;
        
      case 'Completed':
        actions.push('<span style="color: #28a745; font-size: 12px;">✓ Process compelete</span>');
        break;
        
      case 'Rejected':
        actions.push('<span style="color: #dc3545; font-size: 12px;">✗ Rejected</span>');
        break;
        
      default:
        actions.push('No actions available');
    }
    
    return actions.join('');
  }

  // WORKFLOW FUNCTIONS

  async function startProcessing(returnId) {
    const confirmed = confirm('Start processing this return? This will change status from PENDING to PROCESSING.');
    if (!confirmed) return;
    
    try {
      const response = await fetch('../api/supplier_returns.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          SupplierReturn_ID: returnId,
          action: 'start_processing'
        })
      });
      
      const json = await response.json();
      
      if (json.success) {
        showNotification(`Return #${returnId} is now being processed`, 'success');
        loadReturns(currentPage);
        loadSummary();
      } else {
        showNotification('Error starting processing: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  }
async function receiveReplacements(returnId) {
try {
  // Get return details first
  const detailsResponse = await fetch(`../api/supplier_returns.php?return_id=${returnId}`);
  const detailsJson = await detailsResponse.json();
  
  if (!detailsJson.success || !detailsJson.data || detailsJson.data.length === 0) {
    showNotification('Error loading return details', 'error');
    return;
  }
  
  const returnData = detailsJson.data[0];
  currentReplacementReturn = returnData;
  
  // Show replacement processing modal
  const modal = document.getElementById('replacement-modal');
  const details = document.getElementById('replacement-details');
  
  details.innerHTML = `
    <p><strong>Return #${returnData.SupplierReturn_ID}</strong></p>
    <p><strong>Product:</strong> ${returnData.product_name}</p>
    <p><strong>Quantity:</strong> ${returnData.Quantity} ${returnData.Unit_measure}</p>
    <p><strong>Supplier:</strong> ${returnData.supplier_name}</p>
    <div style="background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;">
      <strong>⚠️ Important:</strong> This will call your stockin_inventory system to add these replacement items to inventory, then mark this return as completed.
    </div>
  `;
  
  modal.classList.remove('hide');
  
} catch (err) {
  showNotification('Error: ' + err.message, 'error');
}
}

  function closeReplacementModal() {
    document.getElementById('replacement-modal').classList.add('hide');
    currentReplacementReturn = null;
  }

  // Process stockin for replacements// Process stockin for replacements
document.getElementById('process-stockin-btn').addEventListener('click', async function() {
if (!currentReplacementReturn) return;

const confirmed = confirm('Are you sure the physical replacement items have arrived and you want to add them to inventory?');
if (!confirmed) return;

try {
  // FIXED: Map frontend fields to backend expected fields
  const stockinData = {
    Product_ID: currentReplacementReturn.Product_ID,
    Supplier_ID: currentReplacementReturn.Supplier_ID,
    Stock_in_Quantity: currentReplacementReturn.Quantity,  // Changed from 'Quantity' to 'Stock_in_Quantity'
    Quantity_Ordered: currentReplacementReturn.Quantity,   // Added this required field
    Unit_Cost: currentReplacementReturn.Unit_Cost,
    Expiry_Date: currentReplacementReturn.Expiry_Date,
    Batch_Number: currentReplacementReturn.Batch_Number,
    Remarks: `Replacement items for Return #${currentReplacementReturn.SupplierReturn_ID}`,  // Changed from 'Notes' to 'Remarks'
    Status: 'RECEIVED',  // Set appropriate status
    Date_in: new Date().toISOString().split('T')[0],  // Today's date
    Approved_by: 'Return System',  // Set appropriate approver
    Performed_by: 1  // User ID for stock movements
  };
  
  const stockinResponse = await fetch('../api/stockin_inventory.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(stockinData)
  });
  
  const stockinJson = await stockinResponse.json();
  
  if (stockinJson.success) {
    // Now mark the return as completed
    const completeResponse = await fetch('../api/supplier_returns.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        SupplierReturn_ID: currentReplacementReturn.SupplierReturn_ID,
        action: 'complete_with_stockin',
        stockin_reference: 'Processed via replacement workflow'
      })
    });
    
    const completeJson = await completeResponse.json();
    
    if (completeJson.success) {
      showNotification(`Replacements processed successfully! Return #${currentReplacementReturn.SupplierReturn_ID} completed.`, 'success');
      closeReplacementModal();
      loadReturns(currentPage);
      loadSummary();
    } else {
      showNotification('Stockin successful but error updating return: ' + completeJson.error, 'error');
    }
  } else {
    showNotification('Error processing stockin: ' + stockinJson.error, 'error');
    console.error('Stockin error details:', stockinJson);
  }
  
} catch (err) {
  showNotification('Error: ' + err.message, 'error');
  console.error('Network error:', err);
}
}); // <- This closing brace and parenthesis were missing!

async function completeReturn(returnId) {
const confirmed = confirm('Mark this return as completed?');
if (!confirmed) return;

try {
  const response = await fetch('../api/supplier_returns.php', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      SupplierReturn_ID: returnId,
      action: 'complete'
    })
  });
  
  const json = await response.json();
  
  if (json.success) {
    showNotification(`Return #${returnId} completed successfully`, 'success');
    loadReturns(currentPage);
    loadSummary();
  } else {
    showNotification('Error completing return: ' + json.error, 'error');
  }
} catch (err) {
  showNotification('Error: ' + err.message, 'error');
}
}

  async function completeReturn(returnId) {
    const confirmed = confirm('Mark this return as completed?');
    if (!confirmed) return;
    
    try {
      const response = await fetch('../api/supplier_returns.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          SupplierReturn_ID: returnId,
          action: 'complete'
        })
      });
      
      const json = await response.json();
      
      if (json.success) {
        showNotification(`Return #${returnId} completed successfully`, 'success');
        loadReturns(currentPage);
        loadSummary();
      } else {
        showNotification('Error completing return: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  }

  async function rejectReturn(returnId) {
    const reason = prompt('Enter reason for rejection:');
    if (!reason) return;
    
    const confirmed = confirm('Are you sure you want to reject this return? This will restore the inventory.');
    if (!confirmed) return;
    
    try {
      const response = await fetch('../api/supplier_returns.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          SupplierReturn_ID: returnId,
          action: 'reject',
          rejection_reason: reason
        })
      });
      
      const json = await response.json();
      
      if (json.success) {
        showNotification(`Return #${returnId} rejected and inventory restored`, 'success');
        loadReturns(currentPage);
        loadSummary();
      } else {
        showNotification('Error rejecting return: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  }

  function updatePagination(pagination) {
    const paginationDiv = document.getElementById('pagination');
    
    if (!pagination || pagination.total_pages <= 1) {
      paginationDiv.innerHTML = '';
      return;
    }
    
    let paginationHTML = `
      <span>Page ${pagination.current_page} of ${pagination.total_pages} (${pagination.total_count} total)</span>
      <div style="margin-top: 10px;">
    `;
    
    // Previous button
    if (pagination.current_page > 1) {
      paginationHTML += `<button onclick="loadReturns(${pagination.current_page - 1})" class="secondary">Previous</button> `;
    }
    
    // Page numbers
    for (let i = Math.max(1, pagination.current_page - 2); i <= Math.min(pagination.total_pages, pagination.current_page + 2); i++) {
      if (i === pagination.current_page) {
        paginationHTML += `<button class="primary" disabled>${i}</button> `;
      } else {
        paginationHTML += `<button onclick="loadReturns(${i})" class="secondary">${i}</button> `;
      }
    }
    
    // Next button
    if (pagination.current_page < pagination.total_pages) {
      paginationHTML += `<button onclick="loadReturns(${pagination.current_page + 1})" class="secondary">Next</button>`;
    }
    
    paginationHTML += '</div>';
    paginationDiv.innerHTML = paginationHTML;
  }

  function refreshReturns() {
    document.getElementById('returns-search').value = '';
    document.getElementById('supplier-filter').value = '';
    document.getElementById('status-filter').value = '';
    document.getElementById('type-filter').value = '';
    document.getElementById('date-from').value = '';
    document.getElementById('date-to').value = '';
    currentPage = 1;
    loadReturns();
  }

  function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    try {
      const date = new Date(dateStr);
      return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit'
      });
    } catch (e) {
      return 'Invalid Date';
    }
  }

  // New return form submission
  document.getElementById('new-return-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = {};
    
    for (let [key, value] of formData.entries()) {
      if (value !== '') {
        data[key] = value;
      }
    }
    
    // Add created_by (you might want to get this from session)
    data.Created_by = 1;
    data.Status = 'Pending'; // Ensure status is set to Pending
    
    try {
      const response = await fetch('../api/supplier_returns.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
      });
      
      const json = await response.json();
      
      if (json.success) {
        showNotification('Return request created successfully with PENDING status!', 'success');
        toggleReturnForm();
        e.target.reset();
        loadReturns(currentPage);
        loadSummary();
      } else {
        showNotification('Error creating return: ' + json.error, 'error');
      }
    } catch (err) {
      showNotification('Error: ' + err.message, 'error');
    }
  });

  // Load data on page load
  document.addEventListener('DOMContentLoaded', function() {
    loadDropdownData();
    loadReturns();
    loadSummary();
  });