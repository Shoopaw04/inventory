let terminals = [];
let currentEditingTerminal = null;

// Role guard - Admin only
document.addEventListener('DOMContentLoaded', async () => {
  const user = await AuthHelper.requireAuthAndRole(['Admin']);
  if (!user) return;
  
  loadTerminalData();
  setInterval(loadTerminalData, 30000); // Refresh every 30 seconds
});

// Expose for inline usage
window.openTerminal = openTerminal;
window.configureTerminal = configureTerminal;
window.viewTerminalHistory = viewTerminalHistory;
window.setMaintenance = setMaintenance;

async function loadTerminalData() {
  try {
    const response = await fetch('../api/terminal_management.php');
    const json = await response.json();
    
    if (json.success && json.data) {
      terminals = json.data;
      displayTerminals();
      updateSummary();
      displayTerminalStats();
    } else {
      console.error('Error loading terminal data:', json.error);
    }
  } catch (err) {
    console.error('Error loading terminal data:', err);
  }
}

function displayTerminals() {
  const grid = document.getElementById('terminalGrid');
  
  grid.innerHTML = terminals.map(terminal => `
    <div class="terminal-card ${terminal.status}">
      <div class="terminal-header">
        <div class="terminal-title">${terminal.name || `Terminal #${terminal.id}`}</div>
        <div class="terminal-status status-${terminal.status}">${terminal.status}</div>
      </div>
      <div class="terminal-info">
        <p><strong>Location:</strong> ${terminal.location || 'Not specified'}</p>
        <p><strong>Current User:</strong> ${terminal.current_user || 'None'}</p>
        <p><strong>Last Activity:</strong> ${formatDateTime(terminal.last_activity) || 'Never'}</p>
        <p><strong>Sales Today:</strong> ₱${parseFloat(terminal.sales_today || 0).toFixed(2)}</p>
        <p><strong>Transactions:</strong> ${terminal.transactions_today || 0}</p>
      </div>
      <div class="terminal-actions">
        <button class="btn-terminal" onclick="openTerminal(${terminal.id})">Open POS</button>
        <button class="btn-terminal" onclick="configureTerminal(${terminal.id})">Configure</button>
        <button class="btn-terminal" onclick="viewTerminalHistory(${terminal.id})">History</button>
        <button class="btn-terminal danger" onclick="setMaintenance(${terminal.id})">Maintenance</button>
      </div>
    </div>
  `).join('');
}

function displayTerminalStats() {
  const tbody = document.getElementById('terminalStatsBody');
  
  tbody.innerHTML = terminals.map(terminal => `
    <tr>
      <td>${terminal.name || `Terminal #${terminal.id}`}</td>
      <td><span class="terminal-status status-${terminal.status}">${terminal.status}</span></td>
      <td>${terminal.current_user || 'None'}</td>
      <td>₱${parseFloat(terminal.sales_today || 0).toFixed(2)}</td>
      <td>${terminal.transactions_today || 0}</td>
      <td>${formatDateTime(terminal.last_activity) || 'Never'}</td>
      <td>
        <button class="btn-terminal" onclick="openTerminal(${terminal.id})">Open</button>
        <button class="btn-terminal" onclick="configureTerminal(${terminal.id})">Config</button>
      </td>
    </tr>
  `).join('');
}

function updateSummary() {
  const total = terminals.length;
  const online = terminals.filter(t => t.status === 'online').length;
  const offline = terminals.filter(t => t.status === 'offline').length;
  const maintenance = terminals.filter(t => t.status === 'maintenance').length;
  
  document.getElementById('totalTerminals').textContent = total;
  document.getElementById('onlineTerminals').textContent = online;
  document.getElementById('offlineTerminals').textContent = offline;
  document.getElementById('maintenanceTerminals').textContent = maintenance;
}

function formatDateTime(dateStr) {
  if (!dateStr) return null;
  try {
    const date = new Date(dateStr);
    return date.toLocaleString('en-US', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    });
  } catch (e) {
    return null;
  }
}

function openTerminal(terminalId) {
  window.open(`pos.html?terminal=${terminalId}`, '_blank');
}

function configureTerminal(terminalId) {
  const terminal = terminals.find(t => t.id === terminalId);
  if (!terminal) return;
  
  currentEditingTerminal = terminalId;
  document.getElementById('terminalName').value = terminal.name || `Terminal #${terminalId}`;
  document.getElementById('terminalLocation').value = terminal.location || '';
  document.getElementById('terminalStatus').value = terminal.status || 'online';
  document.getElementById('terminalNotes').value = terminal.notes || '';
  
  document.getElementById('terminalConfigModal').style.display = 'block';
}

function closeTerminalConfigModal() {
  document.getElementById('terminalConfigModal').style.display = 'none';
  currentEditingTerminal = null;
}

function viewTerminalHistory(terminalId) {
  window.open(`sales-history.html?terminal=${terminalId}`, '_blank');
}

async function setMaintenance(terminalId) {
  if (!confirm('Set this terminal to maintenance mode? This will prevent users from accessing it.')) {
    return;
  }
  
  try {
    const response = await fetch('../api/terminal_management.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        terminal_id: terminalId,
        status: 'maintenance'
      })
    });
    
    const json = await response.json();
    if (json.success) {
      showNotification('Terminal set to maintenance mode', 'success');
      loadTerminalData();
    } else {
      showNotification('Error: ' + json.error, 'error');
    }
  } catch (err) {
    showNotification('Network error: ' + err.message, 'error');
  }
}

// Terminal configuration form submission
document.getElementById('terminalConfigForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  if (!currentEditingTerminal) return;
  
  const formData = new FormData(e.target);
  const data = {
    terminal_id: currentEditingTerminal,
    name: formData.get('terminal_name'),
    location: formData.get('location'),
    status: formData.get('status'),
    notes: formData.get('notes')
  };
  
  try {
    const response = await fetch('../api/terminal_management.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    
    const json = await response.json();
    if (json.success) {
      showNotification('Terminal configuration updated', 'success');
      closeTerminalConfigModal();
      loadTerminalData();
    } else {
      showNotification('Error: ' + json.error, 'error');
    }
  } catch (err) {
    showNotification('Network error: ' + err.message, 'error');
  }
});

function showNotification(message, type) {
  const notification = document.getElementById('notification');
  notification.textContent = message;
  notification.className = `notification ${type} show`;
  
  setTimeout(() => {
    notification.classList.remove('show');
  }, 3000);
}

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById('terminalConfigModal');
  if (event.target === modal) {
    closeTerminalConfigModal();
  }
}