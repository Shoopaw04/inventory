  // Role guard: Admin, Manager, Inventory Clerk/Staff
  let CURRENT_USER = 'unknown';
  let CURRENT_ROLE = 'unknown';
  document.addEventListener('DOMContentLoaded', async () => {
    const user = await AuthHelper.requireAuthAndRole(['Admin','Manager','Inventory Clerk','Inventory Staff']);
    if (!user) return;
    CURRENT_USER = user.User_name;
    CURRENT_ROLE = user.Role_name;
    const isApprover = ['Admin','Manager'].includes(CURRENT_ROLE);
    if (!isApprover) {
      // Hide Requested By block
      const requestedBy = document.getElementById('adj-requested-by');
      if (requestedBy) {
        const fg = requestedBy.closest('.form-group');
        if (fg) fg.style.display = 'none';
      }
    }
  });
  
  async function loadAdjustments() {
    const tbody = document.getElementById('adjustments-body');
    const statusFilter = document.getElementById('adjustment-status-filter').value;
    const dateFrom = document.getElementById('filter-date-from') ? document.getElementById('filter-date-from').value : '';
    const dateTo = document.getElementById('filter-date-to') ? document.getElementById('filter-date-to').value : '';
    const order = document.getElementById('order-filter') ? document.getElementById('order-filter').value : 'desc';
    
    tbody.innerHTML = '<tr><td colspan="8" class="loading">🔄 Loading adjustments...</td></tr>';
    
    try {
      let url = 'manual_adjustments.php';
      const params = [];
      if (statusFilter) params.push('status=' + encodeURIComponent(statusFilter));
      if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
      if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
      if (order) params.push('order=' + encodeURIComponent(order));
      if (!['Admin','Manager'].includes(CURRENT_ROLE) && CURRENT_USER && CURRENT_USER !== 'unknown') {
        params.push('adjusted_by=' + encodeURIComponent(CURRENT_USER));
      }
      if (params.length > 0) url += '?' + params.join('&');
      
      const json = await getJSON(url);
      console.log('Adjustments response:', json); // Debug log
      
      if (json.success && json.data && json.data.length > 0) {
        tbody.innerHTML = json.data.map(adj => {
          const quantityChange = parseInt(adj.quantity_change || (adj.New_quantity - adj.Old_quantity));
          const changeClass = quantityChange > 0 ? 'movement-in' : 'movement-out';
          const changePrefix = quantityChange > 0 ? '+' : '';
          
          let statusBadge = '';
          switch(adj.Status) {
            case 'PENDING':
              statusBadge = '<span class="badge-pending">⏳ Pending</span>';
              break;
            case 'APPROVED':
              statusBadge = '<span class="badge-approved">✅ Approved</span>';
              break;
            case 'REJECTED':
              statusBadge = '<span class="badge-rejected">❌ Rejected</span>';
              break;
            default:
              statusBadge = `<span class="badge-default">${adj.Status}</span>`;
          }

          const isApprover = ['Admin','Manager'].includes(CURRENT_ROLE);
          let actions = '';
          if (adj.Status === 'PENDING' && isApprover) {
            actions = `
              <button onclick="showApprovalModal(${adj.Adjustment_ID}, 'approve')" class="btn-approve">✓ Approve</button>
              <button onclick="showApprovalModal(${adj.Adjustment_ID}, 'reject')" class="btn-reject">✗ Reject</button>
            `;
          } else {
            actions = `<button onclick="viewAdjustment(${adj.Adjustment_ID})" class="btn-view"> View</button>`;
          }
          
          return `<tr>
            <td>${formatDate(adj.Adjustment_date)}</td>
            <td>${adj.product_name || 'Unknown Product'}</td>
            <td>${adj.Old_quantity}</td>
            <td>${adj.New_quantity}</td>
            <td class="${changeClass}">${changePrefix}${quantityChange}</td>
            <td>${formatReason(adj.Reason)}</td>
            <td>${statusBadge}</td>
            <td>${actions}</td>
          </tr>`;
        }).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="8">📭 No adjustments found</td></tr>';
      }
    } catch (err) {
      console.error('Error loading adjustments:', err);
      tbody.innerHTML = `<tr><td colspan="8">❌ Error: ${err.message}</td></tr>`;
    }
  }

  async function showNewAdjustmentModal() {
    document.getElementById('adjustmentModal').style.display = 'block';
    
    await loadProductsForAdjustment();
    // Removed terminal and users loading; requester is current user
  }

  // ADD THIS MISSING FUNCTION
  function closeAdjustmentModal() {
    document.getElementById('adjustmentModal').style.display = 'none';
    document.getElementById('adjustmentForm').reset();
  }

  async function loadProductsForAdjustment() {
    const select = document.getElementById('adj-product-select');
    
    try {
      console.log('Loading products from product_list.php...');
      const json = await getJSON('product_list.php');
      console.log('Products response:', json);
      
      if (json.success && json.products && Array.isArray(json.products)) {
        if (json.products.length > 0) {
          select.innerHTML = '<option value="">Select a product...</option>' + 
            json.products.map(p => {
              // Use the exact property names from your product_list.php
              const id = p.product_id;
              const name = p.name;
              const qty = p.quantity || 0;
              
              return `<option value="${id}" data-quantity="${qty}">${name} (Stock: ${qty})</option>`
            }).join('');
          console.log('Products loaded successfully:', json.products.length, 'products');
        } else {
          select.innerHTML = '<option value="">No products available</option>';
          console.warn('No products found - empty array');
        }
      } else {
        select.innerHTML = '<option value="">Error: Invalid response format</option>';
        console.error('Invalid response format or missing products array:', json);
      }
    } catch (err) {
      console.error('Error loading products:', err);
      select.innerHTML = '<option value="">Error loading products</option>';
    }
  }

  async function loadTerminalsForAdjustment() {
    const select = document.getElementById('adj-terminal');
    
    try {
      console.log('Loading terminals from stock_movements.php...'); 
      
      // Use stock_movements.php which returns terminals in filters.terminals
      const json = await getJSON('stock_movements.php?limit=1');
      console.log('Full response:', json); 
      console.log('Filters:', json.filters);
      console.log('Terminals:', json.filters?.terminals);
      
      // Check the correct path: json.filters.terminals
      if (json.success && json.filters && json.filters.terminals && Array.isArray(json.filters.terminals)) {
        if (json.filters.terminals.length > 0) {
          select.innerHTML = '<option value="">Select terminal...</option>' + 
            json.filters.terminals.map(t => {
              const id = t.Terminal_ID;
              const name = t.Terminal_name;
              const location = t.Location || '';
              
              return `<option value="${id}">${name}${location ? ' - ' + location : ''}</option>`
            }).join('');
          console.log('Terminals loaded successfully:', json.filters.terminals.length, 'terminals');
        } else {
          select.innerHTML = '<option value="">No active terminals found</option>';
          console.warn('No terminals found - empty array');
        }
      } else {
        select.innerHTML = '<option value="">No active terminals available</option>';
        console.warn('No terminals found in filters.terminals. Response structure:', {
          success: json.success,
          hasFilters: !!json.filters,
          hasTerminals: !!(json.filters && json.filters.terminals),
          isArray: Array.isArray(json.filters?.terminals)
        });
      }
    } catch (err) {
      console.error('Error loading terminals:', err);
      select.innerHTML = '<option value="">Error loading terminals</option>';
    }
  }

  async function loadUsersForAdjustment() {
    try {
      console.log('Loading users from get_users.php...');
      const json = await getJSON('get_users.php');
      console.log('Users response:', json);
      
      if (json.success && json.users && json.users.length > 0) {
        // Update both user dropdowns
        const requestedBySelect = document.getElementById('adj-requested-by');
        const approvalUserSelect = document.getElementById('approval-user');
        
        const userOptions = '<option value="">Select user...</option>' + 
          json.users.map(u => {
            const id = u.User_ID;
            const username = u.Username;
            const name = u.Name;
            
            return `<option value="${username}">${username} (${name})</option>`
          }).join('');
        
        if (requestedBySelect) {
          requestedBySelect.innerHTML = userOptions;
        }
        if (approvalUserSelect) {
          approvalUserSelect.innerHTML = userOptions;
        }
        
        console.log('Users loaded successfully:', json.users.length, 'users');
      }
    } catch (err) {
      console.error('Error loading users:', err);
    }
  }

  function showApprovalModal(adjustmentId, action) {
    document.getElementById('approvalModal').style.display = 'block';
    document.getElementById('approval-adjustment-id').value = adjustmentId;
    document.getElementById('approval-action').value = action;
    
    const title = document.getElementById('approvalModalTitle');
    const submitBtn = document.getElementById('approvalSubmitBtn');
    
    if (action === 'approve') {
      title.textContent = '✅ Approve Adjustment';
      submitBtn.textContent = 'Approve';
      submitBtn.className = 'primary';
      document.getElementById('approval-notes').placeholder = 'Enter approval notes (optional)...';
    } else {
      title.textContent = '❌ Reject Adjustment';
      submitBtn.textContent = 'Reject';
      submitBtn.className = 'danger';
      document.getElementById('approval-notes').placeholder = 'Enter rejection reason (required)...';
    }
  }

  function closeApprovalModal() {
    document.getElementById('approvalModal').style.display = 'none';
    document.getElementById('approvalForm').reset();
  }

  // Handle product selection change
  document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('adj-product-select');
    const currentQtyInput = document.getElementById('adj-current-qty');
    
    if (productSelect && currentQtyInput) {
      productSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.dataset.quantity !== undefined) {
          currentQtyInput.value = selectedOption.dataset.quantity;
        } else {
          currentQtyInput.value = '';
        }
      });
    }

    // Load adjustments on page load
    loadAdjustments();
  });

  // Handle adjustment form submission
  document.getElementById('adjustmentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = {
      product_id: document.getElementById('adj-product-select').value,
      old_quantity: parseInt(document.getElementById('adj-current-qty').value),
      new_quantity: parseInt(document.getElementById('adj-physical-qty').value),
      reason: document.getElementById('adj-reason').value,
      adjusted_by: CURRENT_USER && CURRENT_USER !== 'unknown' ? CURRENT_USER : null,
      notes: document.getElementById('adj-notes').value
    };
    
    console.log('Submitting adjustment:', formData); // Debug log
    
    try {
      const response = await fetch('../api/manual_adjustments.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
      });
      
      const json = await response.json();
      console.log('Submission response:', json); // Debug log
      
      if (json.success) {
        alert('✅ Adjustment request submitted successfully!');
        closeAdjustmentModal();
        loadAdjustments();
      } else {
        alert('❌ Error: ' + json.error);
      }
    } catch (err) {
      console.error('Submission error:', err);
      alert('❌ Error submitting adjustment: ' + err.message);
    }
  });

  // Expose for inline onclicks
  window.refreshAdjustments = refreshAdjustments;
  window.showNewAdjustmentModal = showNewAdjustmentModal;
  window.closeAdjustmentModal = closeAdjustmentModal;
  window.closeViewAdjustmentModal = closeViewAdjustmentModal;
  window.showApprovalModal = showApprovalModal;
  window.closeApprovalModal = closeApprovalModal;
  window.viewAdjustment = viewAdjustment;
  window.loadAdjustments = loadAdjustments;

  // Handle approval form submission
  document.getElementById('approvalForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const adjustmentId = document.getElementById('approval-adjustment-id').value;
    const action = document.getElementById('approval-action').value;
    const approvalNotes = document.getElementById('approval-notes').value;
    
    if (action === 'reject' && !approvalNotes.trim()) {
      alert('Please provide notes for this decision.');
      return;
    }
    
    const requestData = {
      adjustment_id: parseInt(adjustmentId),
      action: action,
      approval_notes: approvalNotes
    };
    
    console.log('Submitting approval:', requestData); // Debug log
    
    try {
      const response = await fetch('../api/manual_adjustments.php', {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(requestData)
      });
      
      const json = await response.json();
      console.log('Approval response:', json); // Debug log
      
      if (json.success) {
        const message = action === 'approve' ? 'approved' : 'rejected';
        alert(`✅ Adjustment ${message} successfully!`);
        closeApprovalModal();
        loadAdjustments();
      } else {
        alert('❌ Error: ' + json.error);
      }
    } catch (err) {
      console.error('Approval error:', err);
      alert(`❌ Error ${action}ing adjustment: ` + err.message);
    }
  });

  async function viewAdjustment(adjustmentId) {
    try {
      const json = await getJSON('manual_adjustments.php');
      if (json.success && Array.isArray(json.data)) {
        const adj = json.data.find(a => String(a.Adjustment_ID) === String(adjustmentId));
        if (!adj) { alert('Adjustment not found'); return; }
        document.getElementById('view-adj-id').textContent = adj.Adjustment_ID;
        document.getElementById('view-adj-product').textContent = adj.product_name || '-';
        document.getElementById('view-adj-old').textContent = adj.Old_quantity;
        document.getElementById('view-adj-new').textContent = adj.New_quantity;
        const ch = (adj.New_quantity - adj.Old_quantity);
        document.getElementById('view-adj-change').textContent = (ch > 0 ? '+' : '') + ch;
        document.getElementById('view-adj-reason').textContent = formatReason(adj.Reason);
        document.getElementById('view-adj-status').textContent = adj.Status;
        document.getElementById('view-adj-date').textContent = formatDate(adj.Adjustment_date);
        document.getElementById('view-adj-notes').value = adj.Notes || '';
        document.getElementById('view-adj-approval-notes').value = adj.Approval_notes || '';
        // Requested/Approved by (with role if available)
        const requested = adj.adjusted_by_username || adj.Adjusted_by || '-';
        const requestedRole = adj.adjusted_by_role ? ` (${adj.adjusted_by_role})` : '';
        const approved = adj.approved_by_username || adj.Approved_by || '-';
        const approvedRole = adj.approved_by_role ? ` (${adj.approved_by_role})` : '';
        const requestedEl = document.getElementById('view-adj-requested');
        const approvedEl = document.getElementById('view-adj-approved');
        if (requestedEl) requestedEl.textContent = requested + requestedRole;
        if (approvedEl) approvedEl.textContent = approved + approvedRole;
        document.getElementById('viewAdjustmentModal').style.display = 'block';
      } else {
        alert('Unable to load adjustment details');
      }
    } catch (err) {
      alert('Error loading details: ' + err.message);
    }
  }

  function closeViewAdjustmentModal() {
    document.getElementById('viewAdjustmentModal').style.display = 'none';
  }

  function refreshAdjustments() {
    const statusFilter = document.getElementById('adjustment-status-filter');
    if (statusFilter) statusFilter.value = '';
    loadAdjustments();
  }

  function formatReason(reason) {
    return reason ? reason.replace(/_/g, ' ').toLowerCase()
      .replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown';
  }

  function formatDate(dateStr) {
    if (!dateStr) return 'Never';
    try {
      return new Date(dateStr).toLocaleDateString();
    } catch (e) {
      return 'Invalid Date';
    }
  }

  // Close modals when clicking outside
  window.onclick = function(event) {
    const adjustmentModal = document.getElementById('adjustmentModal');
    const approvalModal = document.getElementById('approvalModal');
    
    if (event.target == adjustmentModal) {
      closeAdjustmentModal();
    }
    if (event.target == approvalModal) {
      closeApprovalModal();
    }
  }