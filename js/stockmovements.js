 // Guard: Managers and Admins
 document.addEventListener('DOMContentLoaded', async () => {
    const user = await AuthHelper.requireAuthAndRole(['Manager','Admin']);
    if (!user) return;
  });
  async function loadMovements() {
    const tbody = document.getElementById('movements-body');
    const search = document.getElementById('movements-search').value;
    const movementType = document.getElementById('movement-type-filter').value;
    const terminal = document.getElementById('terminal-filter').value;
    const dateFrom = document.getElementById('date-from').value;
    const dateTo = document.getElementById('date-to').value;
    
    tbody.innerHTML = '<tr><td colspan="9" class="loading">🔄 Loading movements...</td></tr>';
    
    try {
      let url = 'stock_movements.php';
      const params = [];
      
      if (search) params.push('search=' + encodeURIComponent(search));
      if (movementType) params.push('type=' + encodeURIComponent(movementType));
      if (terminal) params.push('terminal_id=' + encodeURIComponent(terminal));
      if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
      if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
      
      if (params.length > 0) url += '?' + params.join('&');
      
      const json = await getJSON(url);
      
      if (json.success && json.data && json.data.length > 0) {
        // Populate terminal filter if terminals are returned
        if (json.terminals) {
          populateTerminalFilter(json.terminals);
        }

        tbody.innerHTML = json.data.map(m => {
          const mType = m.Movement_type || '';
          const increaseTypes = ['PURCHASE_RECEIPT','RETURN','ADJUSTMENT_IN','REPLACEMENT_RECEIVED','INITIAL_STOCK','DISPLAY_STOCK_IN','STOCK_IN','TRANSFER_IN'];
          const isIncrease = increaseTypes.includes(mType);
          const quantityClass = isIncrease ? 'movement-in' : 'movement-out';
          const quantityPrefix = isIncrease ? '+' : '-';
          const icon = getMovementIcon(mType);
          const formattedType = m.movement_description || (mType ? mType.replace(/_/g, ' ') : 'Unknown');
          const terminalName = m.terminal_name || m.Terminal_name || '';
          const performedBy = m.performed_by_name || m.Performed_by || 'System';
          return `<tr>
            <td>${m.Transaction_ID || 'N/A'}</td>
            <td>${formatDateTime(m.Timestamp)}</td>
            <td>${m.product_name || 'Unknown Product'}</td>
            <td>${icon} ${formattedType}</td>
            <td class="${quantityClass}">${quantityPrefix}${m.Quantity || 0}</td>
            <td>${m.reference_info || m.Reference_ID || 'N/A'}</td>
            <td>${terminalName}${m.terminal_location ? ` ${m.terminal_location}` : ''}</td>
            <td>${performedBy}</td>
            <td>${m.Source_Table || 'N/A'}</td>
          </tr>`;
        }).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="9">📭 No movement data found</td></tr>';
      }
    } catch (err) {
      console.error('Error loading movements:', err);
      tbody.innerHTML = `<tr><td colspan="9">❌ Error: ${err.message}</td></tr>`;
    }
  }

  function populateTerminalFilter(terminals) {
    const terminalFilter = document.getElementById('terminal-filter');
    if (terminalFilter && terminals && terminals.length > 0) {
      const currentValue = terminalFilter.value;
      terminalFilter.innerHTML = '<option value="">All Terminals</option>' + 
        terminals.map(t => `<option value="${t.Terminal_ID}">${t.Terminal_name}${t.Location ? ` - ${t.Location}` : ''}</option>`).join('');
      
      // Restore previous selection if it exists
      if (currentValue) {
        terminalFilter.value = currentValue;
      }
    }
  }

  function getMovementIcon(movementType) {
    const icons = {
      'SALE': '',
      'PURCHASE': '',
      'PURCHASE_RECEIPT': '',
      'ADJUSTMENT_IN': '⬆',
      'ADJUSTMENT_OUT': '⬇',
      'RETURN': '↩',
      'TRANSFER_IN': '',
      'TRANSFER_OUT': '',
      'DAMAGE': '💥',
      'EXPIRED': '⏰',
      'THEFT': '🚨',
      'LOSS': '💥',
      'STOCK_IN': '📥',
      'INITIAL_STOCK': '🆕',
      'DISPLAY_STOCK_IN': '📋',
      'REPLENISH_DISPLAY': '🔄'
    };
    return icons[movementType] || '';
  }

  function refreshMovements() {
    const searchInput = document.getElementById('movements-search');
    const typeFilter = document.getElementById('movement-type-filter');
    const terminalFilter = document.getElementById('terminal-filter');
    const dateFrom = document.getElementById('date-from');
    const dateTo = document.getElementById('date-to');
    
    if (searchInput) searchInput.value = '';
    if (typeFilter) typeFilter.value = '';
    if (terminalFilter) terminalFilter.value = '';
    if (dateFrom) dateFrom.value = '';
    if (dateTo) dateTo.value = '';
    
    loadMovements();
  }

  function formatDateTime(dateStr) {
    if (!dateStr) return 'Unknown';
    try {
      const date = new Date(dateStr);
      return date.toLocaleString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });
    } catch (e) {
      return 'Invalid Date';
    }
  }

  // Load data on page load
  document.addEventListener('DOMContentLoaded', loadMovements);
  // Expose functions for inline onclicks
  window.loadMovements = loadMovements;
  window.refreshMovements = refreshMovements;