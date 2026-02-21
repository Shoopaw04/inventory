// Guard: Manager and Admin can access full inventory
document.addEventListener('DOMContentLoaded', async () => {
   const user = await AuthHelper.requireAuthAndRole(['Manager','Admin']);
   if (!user) return;
 });
  let currentInventoryData = [];
  let currentTransferProduct = null;
  let currentEditProduct = null;
  let activeTab = 'reorder';
  let lastInventoryStats = {};

  // Field normalization helpers (non-invasive)
  function getWarehouseQty(p) {
    return parseInt((p.quantity !== undefined ? p.quantity : (p.Quantity !== undefined ? p.Quantity : 0)) || 0);
  }
  function getDisplayQty(p) {
    return parseInt((p.display_stocks !== undefined ? p.display_stocks : (p.Display_stocks !== undefined ? p.Display_stocks : 0)) || 0);
  }
  function getReorderLevel(p) {
    return parseInt((p.reorder_level !== undefined ? p.reorder_level : (p.Reorder_Level !== undefined ? p.Reorder_Level : 5)) || 5);
  }
  function isDiscontinued(p) {
    const flag = (p.is_discontinued !== undefined ? p.is_discontinued : (p.Is_discontinued !== undefined ? p.Is_discontinued : 0));
    return String(flag) === '1' || flag === 1 || flag === true;
  }

  // Real-time search
  document.getElementById('inventory-search').addEventListener('input', debounce(loadInventory, 300));
  document.getElementById('category-filter').addEventListener('change', loadInventory);
  document.getElementById('stock-status-filter').addEventListener('change', loadInventory);
  document.getElementById('supplier-filter').addEventListener('change', loadInventory);
  document.getElementById('show-inactive').addEventListener('change', loadInventory);

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  async function loadInventory() {
    const tbody = document.getElementById('inventory-body');
    tbody.innerHTML = '<tr><td colspan="13" class="loading">🔄 Loading...</td></tr>';

    try {
      const search = document.getElementById('inventory-search').value.trim();
      const category = document.getElementById('category-filter').value;
      const stockStatus = document.getElementById('stock-status-filter').value;
      const supplier = document.getElementById('supplier-filter').value;
      const showInactive = document.getElementById('show-inactive').checked;

      let url = 'product_list.php';
      const params = [];

      if (search) params.push('q=' + encodeURIComponent(search));
      if (category) params.push('category=' + encodeURIComponent(category));
      if (stockStatus) params.push('stock_status=' + encodeURIComponent(stockStatus));
      if (supplier) params.push('supplier=' + encodeURIComponent(supplier));
      if (showInactive) params.push('show_inactive=true');

      if (params.length > 0) url += '?' + params.join('&');

      console.log('Making request to:', url);

      const json = await getJSON(url);
      console.log("API Response:", json);

      if (json.success && json.products) {
        // Clear and store the inventory data
        currentInventoryData = [];
        const uniqueProducts = new Map();
        
        // Create unique products map using product_id as key
        json.products.forEach(product => {
          const id = product.product_id ?? product.Product_ID ?? product.id;
          if (!uniqueProducts.has(id)) uniqueProducts.set(id, product);
        });
        
        // Convert back to array
        currentInventoryData = Array.from(uniqueProducts.values());
        
        if (currentInventoryData.length > 0) {
          tbody.innerHTML = '';
          
          currentInventoryData.forEach(p => {
            const warehouseQty = parseInt(p.quantity || 0);
            const displayQty = parseInt(p.display_stocks || 0);
            const totalStock = warehouseQty + displayQty;
            const reorderLevel = parseInt(p.reorder_level || 5);
            const isDiscontinued = p.is_discontinued == 1;

            let stockClass = '';
            if (isDiscontinued) {
              stockClass = 'inactive-product';
            } else if (totalStock === 0) {
              stockClass = 'out-of-stock';
            } else if (totalStock <= reorderLevel) {
              stockClass = 'low-stock';
            }

            const statusIcon = isDiscontinued ? '❌ Inactive' : 
                             totalStock === 0 ? '🔴 Out of Stock' :
                             totalStock <= reorderLevel ? '⚠️ Low Stock' : ' In Stock';

            // Escape quotes for onclick parameters
            const escapedName = (p.name || '').replace(/'/g, "\\'");

            const row = document.createElement('tr');
            row.className = stockClass;
            row.setAttribute('data-product-id', p.product_id);
            const totalPillClass = totalStock === 0 ? 'zero' : (totalStock <= reorderLevel ? 'low' : 'ok');
            const totalPill = `<span class="stock-pill ${totalPillClass}">${totalStock}</span>`;
            const currentPill = `<span class="stock-pill ${totalPillClass}">${totalStock}</span>`;
            row.innerHTML = `
              
              <td class="editable-name" onclick="openEditModal(${p.product_id}, '${escapedName}', ${reorderLevel}, ${totalStock}, '${(p.category_name || '').replace(/'/g, "\\'")}', '${(p.supplier_name || '').replace(/'/g, "\\'")}', '${(p.unit_measure || '').replace(/'/g, "\\'")}', ${p.price || 0})" title="Click to edit product details">${p.name || ''}</td>
              <td>${p.category_name || 'N/A'}</td>
              <td>${p.unit_measure || ''}</td>
              <td>${p.supplier_name || 'N/A'}</td>
              <td><span class="stock-level">${warehouseQty}</span></td>
              <td><span class="stock-level">${displayQty}</span></td>
              <td><strong>${totalPill}</strong></td>
              <td class="editable-cell" onclick="openEditModal(${p.product_id}, '${escapedName}', ${reorderLevel}, ${totalStock}, '${(p.category_name || '').replace(/'/g, "\\'")}', '${(p.supplier_name || '').replace(/'/g, "\\'")}', '${(p.unit_measure || '').replace(/'/g, "\\'")}', ${p.price || 0})" title="Click to edit">${reorderLevel}</td>
              <td>₱${parseFloat(p.price || 0).toFixed(2)}</td>
              <td>${statusIcon}</td>
               <td>${p.expiration_date || 'N/A'}</td>
              <td>${formatDate(p.last_update)}</td>
              <td>
                <div class="action-buttons">
                  ${!isDiscontinued ? `
                    <button class="action-btn btn-transfer" onclick="openTransferModal(${p.product_id}, '${escapedName}', ${warehouseQty}, ${displayQty})" title="Transfer to Display">
                      📦→🏪
                    </button>
                    <button class="action-btn btn-edit" onclick="openEditModal(${p.product_id}, '${escapedName}', ${reorderLevel}, ${totalStock}, '${(p.category_name || '').replace(/'/g, "\\'")}', '${(p.supplier_name || '').replace(/'/g, "\\'")}', '${(p.unit_measure || '').replace(/'/g, "\\'")}', ${p.price || 0})" title="Edit Product">
                      ✏️
                    </button>
                    <button class="action-btn btn-delete" onclick="softDeleteProduct(${p.product_id}, '${escapedName}')" title="Mark as Inactive">
                      🗑️
                    </button>
                  ` : `
                    <button class="action-btn btn-restore" onclick="restoreProduct(${p.product_id}, '${escapedName}')" title="Restore Product">
                      ↩️
                    </button>
                  `}
                </div>
              </td>
            `;
            tbody.appendChild(row);
          });
        } else {
          tbody.innerHTML = '<tr><td colspan="13">📭 No products match your filters</td></tr>';
        }

        updateStats(json.stats || {});
        populateFilters(json.filters || {});
        checkLowStockAlert();
      } else {
        tbody.innerHTML = '<tr><td colspan="13">📭 No inventory data found</td></tr>';
      }
    } catch (err) {
      console.error('Error loading inventory:', err);
      tbody.innerHTML = `<tr><td colspan="13">❌ Error: ${err.message}</td></tr>`;
    }
  }

  function updateStats(stats) {
    lastInventoryStats = stats || {};
    document.getElementById('total-products').textContent = (stats.total_products || 0).toLocaleString();
    document.getElementById('total-value').textContent = '₱' + (stats.total_value || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('low-stock-count').textContent = stats.low_stock_count || 0;
    document.getElementById('out-of-stock-count').textContent = stats.out_of_stock_count || 0;
    document.getElementById('categories-count').textContent = stats.categories_count || 0;
  }

  function populateFilters(filters) {
    // Populate category filter
    const categoryFilter = document.getElementById('category-filter');
    if (filters && filters.categories && Array.isArray(filters.categories)) {
      const currentCategory = categoryFilter.value;
      categoryFilter.innerHTML = '<option value="">All Categories</option>' +
        filters.categories.map(cat => `<option value="${cat}" ${cat === currentCategory ? 'selected' : ''}>${cat}</option>`).join('');
    }

    // Populate supplier filter
    const supplierFilter = document.getElementById('supplier-filter');
    if (filters && filters.suppliers && Array.isArray(filters.suppliers)) {
      const currentSupplier = supplierFilter.value;
      supplierFilter.innerHTML = '<option value="">All Suppliers</option>' +
        filters.suppliers.map(sup => `<option value="${sup}" ${sup === currentSupplier ? 'selected' : ''}>${sup}</option>`).join('');
    }
  }

  function checkLowStockAlert() {
    // Prefer server stats when available for badge/message accuracy
    const serverLow = parseInt((lastInventoryStats.low_stock_count || 0));

    // Combined-stock low stock (for supplier reorder)
    let lowCount = 0;
    if (!isNaN(serverLow) && serverLow >= 0) {
      lowCount = serverLow;
    } else {
      lowCount = currentInventoryData.filter(p => {
        const totalStock = getWarehouseQty(p) + getDisplayQty(p);
        const reorderLevel = getReorderLevel(p);
        return totalStock <= reorderLevel && !isDiscontinued(p);
      }).length;
    }

    // Display-only replenish count
    const displayThreshold = 3;
    const displayCount = currentInventoryData.filter(p => {
      const displayQty = getDisplayQty(p);
      return displayQty >= 0 && displayQty <= displayThreshold && !isDiscontinued(p);
    }).length;

    const totalAlerts = lowCount + displayCount;
    const alertBadge = document.getElementById('stock-alert-badge');
    if (alertBadge) {
      if (totalAlerts > 0) {
        alertBadge.textContent = totalAlerts;
        alertBadge.style.display = 'block';
        document.getElementById('low-stock-alert').style.display = 'block';
        document.getElementById('low-stock-message').textContent = `${totalAlerts} alert(s): ${lowCount} low stock, ${displayCount} display replenish.`;
      } else {
        alertBadge.style.display = 'none';
        document.getElementById('low-stock-alert').style.display = 'none';
      }
    }
  }

  function toggleAdvancedFilters() {
    const advanced = document.getElementById('advanced-filters');
    advanced.style.display = advanced.style.display === 'block' ? 'none' : 'block';
  }

  function refreshInventory() {
    document.getElementById('inventory-search').value = '';
    document.getElementById('category-filter').value = '';
    document.getElementById('stock-status-filter').value = '';
    document.getElementById('supplier-filter').value = '';
    document.getElementById('show-inactive').checked = false;
    loadInventory();
  }

  // Tab switching functionality
  function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
      tab.classList.remove('active');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.modal-tab').forEach(tab => {
      tab.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Add active class to clicked tab
    event.target.classList.add('active');
    
    activeTab = tabName;
  }

  // Enhanced Edit Modal Function
  function openEditModal(productId, productName, reorderLevel, currentStock, category, supplier, unit, price) {
    currentEditProduct = {
      id: productId,
      name: productName,
      reorderLevel: reorderLevel,
      currentStock: currentStock,
      category: category,
      supplier: supplier,
      unit: unit,
      price: price
    };

    // Populate reorder tab
    document.getElementById('reorder-product-name').textContent = productName;
    document.getElementById('reorder-current-stock').textContent = currentStock;
    document.getElementById('new-reorder-level').value = reorderLevel;

    // Populate details tab
    document.getElementById('edit-product-name').value = productName;
    document.getElementById('edit-product-price').value = price;
    document.getElementById('edit-product-unit').value = unit;
    document.getElementById('edit-product-id').textContent = productId;
    document.getElementById('edit-product-category').textContent = category;
    document.getElementById('edit-product-supplier').textContent = supplier;

    // Clear previous messages
    hideValidationMessages();
    document.getElementById('edit-success-message').style.display = 'none';
    
    document.getElementById('reorder-edit-modal').style.display = 'block';
  }

  function closeReorderModal() {
    document.getElementById('reorder-edit-modal').style.display = 'none';
    currentEditProduct = null;
    activeTab = 'reorder';
    
    // Reset to reorder tab
    switchTabProgrammatically('reorder');
    hideValidationMessages();
  }

  function switchTabProgrammatically(tabName) {
    document.querySelectorAll('.tab-content').forEach(tab => {
      tab.classList.remove('active');
    });
    document.querySelectorAll('.modal-tab').forEach(tab => {
      tab.classList.remove('active');
    });
    
    document.getElementById(tabName + '-tab').classList.add('active');
    document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
  }

  function hideValidationMessages() {
    document.querySelectorAll('.validation-message').forEach(msg => {
      msg.style.display = 'none';
    });
  }

  function showValidationMessage(elementId, message) {
    const msgElement = document.getElementById(elementId);
    msgElement.textContent = message;
    msgElement.style.display = 'block';
  }

  function validateProductName(name) {
    if (!name || name.trim().length < 2) {
      return "Product name must be at least 2 characters long";
    }
    if (name.trim().length > 100) {
      return "Product name cannot exceed 100 characters";
    }
    return null;
  }

  function validatePrice(price) {
    if (price < 0) {
      return "Price cannot be negative";
    }
    if (price > 999999.99) {
      return "Price cannot exceed ₱999,999.99";
    }
    return null;
  }

  function validateReorderLevel(level) {
    if (level < 0) {
      return "Reorder level cannot be negative";
    }
    if (level > 99999) {
      return "Reorder level cannot exceed 99,999";
    }
    return null;
  }

  async function saveChanges() {
    if (!currentEditProduct) return;

    hideValidationMessages();
    document.getElementById('edit-success-message').style.display = 'none';
    
    let hasErrors = false;
    const updates = {};

    if (activeTab === 'reorder') {
      // Validate and save reorder level
      const newLevel = parseInt(document.getElementById('new-reorder-level').value);
      const levelError = validateReorderLevel(newLevel);
      
      if (levelError) {
        showValidationMessage('reorder-validation', levelError);
        hasErrors = true;
      } else if (newLevel !== currentEditProduct.reorderLevel) {
        updates.reorder_level = newLevel;
      }
    } else if (activeTab === 'details') {
      // Validate and save product details
      const newName = document.getElementById('edit-product-name').value.trim();
      const newPrice = parseFloat(document.getElementById('edit-product-price').value);
      const newUnit = document.getElementById('edit-product-unit').value.trim();

      const nameError = validateProductName(newName);
      const priceError = validatePrice(newPrice);

      if (nameError) {
        showValidationMessage('name-validation', nameError);
        hasErrors = true;
      } else if (newName !== currentEditProduct.name) {
        updates.name = newName;
      }

      if (priceError) {
        showValidationMessage('price-validation', priceError);
        hasErrors = true;
      } else if (newPrice !== currentEditProduct.price) {
        updates.price = newPrice;
      }

      if (newUnit !== currentEditProduct.unit) {
        updates.unit_measure = newUnit;
      }
    }

    if (hasErrors) return;

    if (Object.keys(updates).length === 0) {
      document.getElementById('edit-success-message').innerHTML = 'ℹ️ No changes detected.';
      document.getElementById('edit-success-message').style.display = 'block';
      return;
    }

    try {
      updates.product_id = currentEditProduct.id;
      
   const response = await postJSON('update_product.php', updates);

      if (response.success) {
        const successMsg = document.getElementById('edit-success-message');
        successMsg.innerHTML = '✅ Product updated successfully!';
        successMsg.style.display = 'block';
        
        // Update current product data
        Object.assign(currentEditProduct, updates);
        
        setTimeout(() => {
          closeReorderModal();
          loadInventory(); // Refresh the table
        }, 1500);
      } else {
        alert('Error: ' + response.error);
      }
    } catch (error) {
      alert('Network error: ' + error.message);
    }
  }

  // Stock Transfer Functions
  function openTransferModal(productId, productName, warehouseQty, displayQty) {
    currentTransferProduct = {
      id: productId,
      name: productName,
      warehouseQty: warehouseQty,
      displayQty: displayQty
    };

    document.getElementById('transfer-product-name').textContent = productName;
    document.getElementById('transfer-warehouse-stock').textContent = warehouseQty;
    document.getElementById('transfer-display-stock').textContent = displayQty;
    document.getElementById('transfer-quantity').value = '';
    document.getElementById('transfer-quantity').max = warehouseQty;
    
    document.getElementById('stock-transfer-modal').style.display = 'block';
  }

  function closeTransferModal() {
    document.getElementById('stock-transfer-modal').style.display = 'none';
    currentTransferProduct = null;
  }

  async function executeTransfer() {
    if (!currentTransferProduct) return;

    const quantity = parseInt(document.getElementById('transfer-quantity').value);
    if (!quantity || quantity <= 0) {
      alert('Please enter a valid quantity');
      return;
    }

    if (quantity > currentTransferProduct.warehouseQty) {
      alert('Cannot transfer more than available warehouse stock');
      return;
    }

    try {
      const transferData = {
        product_id: currentTransferProduct.id,
        quantity: quantity,
        user_id: 1
      };

      console.log('Sending transfer request:', transferData);

      const result = await postJSON('transfer_stock.php', transferData);
      console.log('Transfer response:', result);

      if (result.success) {
        alert('Stock transferred successfully!');
        closeTransferModal();
        loadInventory();
      } else {
        alert('Error: ' + (result.error || 'Unknown error occurred'));
      }
    } catch (error) {
      alert('Network error: ' + error.message);
    }
  }

  // Soft Delete Functions
  async function softDeleteProduct(productId, productName) {
    if (!confirm(`Are you sure you want to mark "${productName}" as inactive?`)) {
      return;
    }

    try {
      const response = await postJSON('soft_delete_product.php', {
        product_id: productId,
        action: 'delete'
      });

      if (response.success) {
        alert('Product marked as inactive');
        loadInventory();
      } else {
        alert('Error: ' + response.error);
      }
    } catch (error) {
      alert('Network error: ' + error.message);
    }
  }

  async function restoreProduct(productId, productName) {
    if (!confirm(`Are you sure you want to restore "${productName}"?`)) {
      return;
    }

    try {
      const response = await postJSON('soft_delete_product.php', {
        product_id: productId,
        action: 'restore'
      });

      if (response.success) {
        alert('Product restored successfully');
        loadInventory();
      } else {
        alert('Error: ' + response.error);
      }
    } catch (error) {
      alert('Network error: ' + error.message);
    }
  }

  // Low Stock Alert and Display Replenishment
  function showLowStockAlert() { showStockAlerts(); }
  function closeDisplayReplenishModal() { closeLowStockModal(); }
  async function showDisplayReplenishAlert() { showStockAlerts(); }

  function closeLowStockModal() {
    document.getElementById('low-stock-modal').style.display = 'none';
  }

  function hideLowStockAlert() {
    document.getElementById('low-stock-alert').style.display = 'none';
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
    const transferModal = document.getElementById('stock-transfer-modal');
    const reorderModal = document.getElementById('reorder-edit-modal');
    const lowStockModal = document.getElementById('low-stock-modal');
    
    if (event.target === transferModal) closeTransferModal();
    if (event.target === reorderModal) closeReorderModal();
    if (event.target === lowStockModal) closeLowStockModal();
  }

  // Load inventory on page load
  document.addEventListener('DOMContentLoaded', loadInventory);

  // Refresh inventory every 5 minutes to keep data current
  setInterval(loadInventory, 300000);

  // Stock Alerts modal (merged low stock + display replenish)
  async function showStockAlerts() {
    document.getElementById('low-stock-modal').style.display = 'block';
    const container = document.getElementById('low-stock-products');
    container.innerHTML = '<p>Loading alerts...</p>';

    // Fetch authoritative low-stock list
    let lowStockProducts = [];
    try {
      const resp = await getJSON('low_stock_alert.php');
      if (resp && resp.success && Array.isArray(resp.products)) {
        lowStockProducts = resp.products;
      }
    } catch (e) {}

    // Compute display-only replenish list from current data
    const displayThreshold = 3;
    const displayLow = currentInventoryData.filter(p => {
      const displayQty = getDisplayQty(p);
      return displayQty >= 0 && displayQty <= displayThreshold && !isDiscontinued(p);
    }).map(p => ({
      name: p.name,
      warehouse_qty: getWarehouseQty(p),
      display_qty: getDisplayQty(p),
      total_stock: getWarehouseQty(p) + getDisplayQty(p),
      reorder_level: getReorderLevel(p)
    }));

    if ((lowStockProducts.length + displayLow.length) === 0) {
      container.innerHTML = '<p>✅ All products and display shelves are adequately stocked!</p>';
      return;
    }

    const lowTable = lowStockProducts.length > 0 ? `
      <h4>Supplier Reorder (Low Stock)</h4>
      <table style="width: 100%; margin-top: 10px;">
        <thead>
          <tr style=\"background: #f8f9fa;\">
            <th style=\"padding: 8px; text-align: left;\">Product</th>
            <th style=\"padding: 8px; text-align: center;\">Warehouse</th>
            <th style=\"padding: 8px; text-align: center;\">Display</th>
            <th style=\"padding: 8px; text-align: center;\">Total</th>
            <th style=\"padding: 8px; text-align: center;\">Reorder</th>
          </tr>
        </thead>
        <tbody>
          ${lowStockProducts.map(p => {
            const w = parseInt(p.warehouse_qty || 0);
            const d = parseInt(p.display_qty || 0);
            const t = w + d;
            const r = parseInt(p.reorder_level || 5);
            return `
              <tr>
                <td style=\"padding: 8px;\">${p.name}</td>
                <td style=\"padding: 8px; text-align: center;\">${w}</td>
                <td style=\"padding: 8px; text-align: center;\">${d}</td>
                <td style=\"padding: 8px; text-align: center; font-weight: bold;\">${t}</td>
                <td style=\"padding: 8px; text-align: center;\">${r}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    ` : '';

    const dispTable = displayLow.length > 0 ? `
      <h4 style=\"margin-top:16px;\">Display Replenishment</h4>
      <table style="width: 100%; margin-top: 10px;">
        <thead>
          <tr style=\"background: #f8f9fa;\">
            <th style=\"padding: 8px; text-align: left;\">Product</th>
            <th style=\"padding: 8px; text-align: center;\">Warehouse</th>
            <th style=\"padding: 8px; text-align: center;\">Display</th>
            <th style=\"padding: 8px; text-align: center;\">Total</th>
            <th style=\"padding: 8px; text-align: center;\">Reorder</th>
          </tr>
        </thead>
        <tbody>
          ${displayLow.map(p => `
              <tr>
                <td style=\"padding: 8px;\">${p.name}</td>
                <td style=\"padding: 8px; text-align: center;\">${p.warehouse_qty}</td>
                <td style=\"padding: 8px; text-align: center;\">${p.display_qty}</td>
                <td style=\"padding: 8px; text-align: center; font-weight: bold;\">${p.total_stock}</td>
                <td style=\"padding: 8px; text-align: center;\">${p.reorder_level}</td>
              </tr>
          `).join('')}
        </tbody>
      </table>
    ` : '';

    container.innerHTML = `${lowTable}${dispTable}` || '<p>✅ All products and display shelves are adequately stocked!</p>';
  }
  // Wire global open function for Stock Alerts button
  window.showStockAlerts = showStockAlerts;