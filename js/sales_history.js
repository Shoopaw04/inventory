let currentTerminal = 'all';
    let salesData = [];

    // Terminal selection
    document.querySelectorAll('.terminal-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.terminal-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentTerminal = this.dataset.terminal;
        loadSalesHistory();
      });
    });

    // Role guard
    document.addEventListener('DOMContentLoaded', async () => {
      const user = await AuthHelper.requireAuthAndRole(['Admin','Manager','Cashier']);
      if (!user) return;
      // Show terminal selector only for Admin/Manager
      if (user.Role_name === 'Admin' || user.Role_name === 'Manager') {
        const sel = document.getElementById('terminalSelector');
        if (sel) sel.style.display = '';
      }
      // If opened from POS scope, pre-filter to current terminal and my sales
      const params = new URLSearchParams(window.location.search);
      if (params.get('scope') === 'pos') {
        const term = sessionStorage.getItem('currentTerminalId') || '1';
        currentTerminal = String(term);
        document.querySelectorAll('.terminal-btn').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`.terminal-btn[data-terminal="${currentTerminal}"]`);
        if (btn) btn.classList.add('active');
        // Set a flag so loadSalesHistory adds only_mine=1 automatically for Cashier
        window.__POS_SCOPE__ = true;
      }
      
      // Set default date range to last 7 days
      const today = new Date().toISOString().split('T')[0];
      const lastWeek = new Date();
      lastWeek.setDate(lastWeek.getDate() - 7);
      const lastWeekStr = lastWeek.toISOString().split('T')[0];
      
      document.getElementById('dateFrom').value = lastWeekStr;
      document.getElementById('dateTo').value = today;
      
      loadSalesHistory();
    });

    async function loadSalesHistory() {
      const tbody = document.getElementById('salesBody');
      tbody.innerHTML = '<tr><td colspan="11" class="loading">🔄 Loading sales history...</td></tr>';
      
      try {
        let url = '../api/sales_history.php';
        const params = [];
        
        if (currentTerminal !== 'all') {
          params.push('terminal_id=' + currentTerminal);
        }
        
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;
        const paymentMethod = document.getElementById('paymentMethod').value;
        
        if (dateFrom) params.push('date_from=' + dateFrom);
        if (dateTo) params.push('date_to=' + dateTo);
        if (paymentMethod) params.push('payment_method=' + paymentMethod);
        
        // In POS scope, enforce only_mine=1
        if (window.__POS_SCOPE__) {
          params.push('only_mine=1');
        }
        if (params.length > 0) {
          url += '?' + params.join('&');
        }
        
        // Add cache-busting parameter
        url += (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
        
        const response = await fetch(url, {
            credentials: 'include'
        });
        const json = await response.json();
        
        // Debug logging
        console.log('Sales History API URL:', url);
        console.log('Sales History API Response:', json);
        console.log('Sales count:', json.data ? json.data.length : 'no data');
        
        if (json.success && json.data) {
          salesData = json.data;
          displaySalesHistory(json.data);
          updateSummaryCards(json.data);
        } else {
          tbody.innerHTML = '<tr><td colspan="11">No sales data found</td></tr>';
          updateSummaryCards([]);
        }
      } catch (err) {
        console.error('Error loading sales history:', err);
        tbody.innerHTML = '<tr><td colspan="11">Error loading sales history</td></tr>';
      }
    }

    function displaySalesHistory(sales) {
      const tbody = document.getElementById('salesBody');
      
      if (sales.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11">No sales found for the selected criteria</td></tr>';
        return;
      }
      
      tbody.innerHTML = sales.map(sale => `
        <tr>
          <td>#${sale.Sale_ID || sale.sale_id}</td>
          <td>Terminal #${sale.Terminal_ID || sale.terminal_id || 'N/A'}</td>
          <td>${formatDateTime(sale.Sale_Date || sale.sale_date)}</td>
          <td>${sale.Cashier_Name || sale.cashier_name || 'Unknown'}</td>
          <td>${sale.Payment_Method || sale.payment_method || 'N/A'}</td>
          <td>₱${parseFloat(sale.Subtotal || sale.subtotal || 0).toFixed(2)}</td>
          <td>₱${parseFloat(sale.Tax_Amount || sale.tax_amount || 0).toFixed(2)}</td>
          <td>₱${parseFloat(sale.Total_Amount || sale.total_amount || 0).toFixed(2)}</td>
          <td>${sale.Total_Items || sale.total_items || 0}</td>
          <td><span class="status-badge status-${(sale.Status || 'completed').toLowerCase()}">${sale.Status || 'Completed'}</span></td>
          <td><button class="btn-view" onclick="viewSaleDetails(${sale.Sale_ID || sale.sale_id})">View</button></td>
        </tr>
      `).join('');
    }

    function updateSummaryCards(sales) {
      const totalSales = sales.reduce((sum, sale) => sum + parseFloat(sale.Total_Amount || sale.total_amount || 0), 0);
      const totalItems = sales.reduce((sum, sale) => sum + parseInt(sale.Total_Items || sale.total_items || 0), 0);
      const totalTransactions = sales.length;
      const averageSale = totalTransactions > 0 ? totalSales / totalTransactions : 0;
      
      document.getElementById('totalSales').textContent = '₱' + totalSales.toFixed(2);
      document.getElementById('totalItems').textContent = totalItems.toString();
      document.getElementById('totalTransactions').textContent = totalTransactions.toString();
      document.getElementById('averageSale').textContent = '₱' + averageSale.toFixed(2);
    }

    function formatDateTime(dateStr) {
      if (!dateStr) return 'N/A';
      try {
        // Normalize common MySQL datetime format 'YYYY-MM-DD HH:MM:SS' to local time
        // by parsing components manually to avoid timezone shifts
        const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        let date;
        if (m) {
          const [_, y, mo, d, h, mi, s] = m;
          date = new Date(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi), Number(s || 0));
        } else {
          // Fallback: replace space with 'T' so it's treated as local
          date = new Date(dateStr.replace(' ', 'T'));
        }
        return date.toLocaleString('en-US', {
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit'
        });
      } catch (e) {
        return 'Invalid Date';
      }
    }

    async function viewSaleDetails(saleId) {
      try {
        const response = await fetch(`../api/sales_history.php?sale_id=${saleId}`, {
            credentials: 'include'
        });
        const json = await response.json();
        
        if (json.success && json.data) {
          const sale = json.data[0];
          const modal = document.getElementById('saleDetailsModal');
          const content = document.getElementById('saleDetailsContent');
          
          // Build items list
          let itemsHtml = '';
          if (sale.items && sale.items.length > 0) {
            itemsHtml = `
              <h4>Items Sold:</h4>
              <table class="items-table" style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                  <tr style="background-color: #f5f5f5;">
                    <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Product</th>
                    <th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Quantity</th>
                    <th style="padding: 8px; border: 1px solid #ddd; text-align: right;">Unit Price</th>
                    <th style="padding: 8px; border: 1px solid #ddd; text-align: right;">Total</th>
                  </tr>
                </thead>
                <tbody>
            `;
            
            sale.items.forEach(item => {
              const unitPrice = parseFloat(item.Product_Price || item.unit_price || 0);
              const quantity = parseInt(item.Quantity || item.quantity || 0);
              const lineTotal = unitPrice * quantity;
              
              itemsHtml += `
                <tr>
                  <td style="padding: 8px; border: 1px solid #ddd;">${item.Product_Name || item.product_name || 'Unknown Product'}</td>
                  <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${quantity}</td>
                  <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${unitPrice.toFixed(2)}</td>
                  <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${lineTotal.toFixed(2)}</td>
                </tr>
              `;
            });
            
            itemsHtml += `
                </tbody>
              </table>
            `;
          } else {
            itemsHtml = '<p><em>No items found for this sale.</em></p>';
          }

          content.innerHTML = `
            <div class="sale-details">
              <h3>Sale #${sale.Sale_ID || sale.sale_id}</h3>
              <div class="sale-info">
                <p><strong>Terminal:</strong> #${sale.Terminal_ID || sale.terminal_id || 'N/A'}</p>
                <p><strong>Date:</strong> ${formatDateTime(sale.Sale_Date || sale.sale_date)}</p>
                <p><strong>Cashier:</strong> ${sale.Cashier_Name || sale.cashier_name || 'Unknown'}</p>
                <p><strong>Payment Method:</strong> ${sale.Payment_Method || sale.payment_method || 'N/A'}</p>
                <p><strong>Subtotal:</strong> ₱${parseFloat(sale.Subtotal || sale.subtotal || 0).toFixed(2)}</p>
                <p><strong>Tax:</strong> ₱${parseFloat(sale.Tax_Amount || sale.tax_amount || 0).toFixed(2)}</p>
                <p><strong>Total:</strong> ₱${parseFloat(sale.Total_Amount || sale.total_amount || 0).toFixed(2)}</p>
                <p><strong>Total Items:</strong> ${sale.Total_Items || sale.total_items || 0}</p>
              </div>
              ${itemsHtml}
            </div>
          `;
          
          modal.style.display = 'block';
        } else {
          alert('Sale details not found');
        }
      } catch (err) {
        console.error('Error loading sale details:', err);
        alert('Error loading sale details');
      }
    }

    function closeSaleDetailsModal() {
      document.getElementById('saleDetailsModal').style.display = 'none';
    }

    function refreshSalesHistory() {
      document.getElementById('dateFrom').value = '';
      document.getElementById('dateTo').value = '';
      document.getElementById('paymentMethod').value = '';
      loadSalesHistory();
    }

    function exportSalesReport() {
      if (salesData.length === 0) {
        alert('No data to export');
        return;
      }
      
      // Simple CSV export
      const headers = ['Sale ID', 'Terminal', 'Date', 'Cashier', 'Payment Method', 'Subtotal', 'Tax', 'Total', 'Items', 'Status'];
      const csvContent = [
        headers.join(','),
        ...salesData.map(sale => [
          sale.Sale_ID || sale.sale_id,
          sale.Terminal_ID || sale.terminal_id || 'N/A',
          formatDateTime(sale.Sale_Date || sale.sale_date),
          sale.Cashier_Name || sale.cashier_name || 'Unknown',
          sale.Payment_Method || sale.payment_method || 'N/A',
          parseFloat(sale.Subtotal || sale.subtotal || 0).toFixed(2),
          parseFloat(sale.Tax_Amount || sale.tax_amount || 0).toFixed(2),
          parseFloat(sale.Total_Amount || sale.total_amount || 0).toFixed(2),
          sale.Total_Items || sale.total_items || 0,
          sale.Status || 'Completed'
        ].join(','))
      ].join('\n');
      
      const blob = new Blob([csvContent], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `sales_report_${currentTerminal}_${new Date().toISOString().split('T')[0]}.csv`;
      a.click();
      window.URL.revokeObjectURL(url);
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('saleDetailsModal');
      if (event.target === modal) {
        modal.style.display = 'none';
      }
    }