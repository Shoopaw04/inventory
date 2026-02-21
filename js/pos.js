// Use centralized API functions from api.js
// getJSON and postJSON are available globally from api.js

  // DOM Elements
  const barcodeInput = document.getElementById('barcodeInput');
  const productSearch = document.getElementById('productSearch');
  const searchResults = document.getElementById('searchResults');
  const transactionList = document.getElementById('transactionList');
  const transactionCount = document.getElementById('transactionCount');
  const subtotalAmount = document.getElementById('subtotalAmount');
  const taxAmount = document.getElementById('taxAmount');
  const discountAmount = document.getElementById('discountAmount');
  const finalTotal = document.getElementById('finalTotal');
  const processPayment = document.getElementById('processPayment');
  const clearAll = document.getElementById('clearAll');
  const statusSuccess = document.getElementById('statusSuccess');
  const statusError = document.getElementById('statusError');
  const currentTimeEl = document.getElementById('currentTime');
  const paymentAmount = document.getElementById('paymentAmount');
  const changeDisplay = document.getElementById('changeDisplay');
  const changeAmount = document.getElementById('changeAmount');

  // State
  const cart = new Map();
  let products = [];
  let selectedPayment = 'CASH';
  let currentTotal = 0;

  // Utility functions
  function fmt(n) {
    return Number(n).toFixed(2);
  }

  function showStatus(element, message, duration = 3000) {
    element.textContent = message;
    element.style.display = 'block';
    setTimeout(() => {
      element.style.display = 'none';
    }, duration);
  }

  // Payment amount functions
  window.setExactAmount = function() {
    paymentAmount.value = fmt(currentTotal);
    calculateChange();
  };

  window.addQuickAmount = function(amount) {
    const current = parseFloat(paymentAmount.value) || 0;
    paymentAmount.value = fmt(current + amount);
    calculateChange();
  };

  window.clearPaymentAmount = function() {
    paymentAmount.value = '';
    changeDisplay.classList.remove('show');
  };

  function calculateChange() {
    const payment = parseFloat(paymentAmount.value) || 0;
    const total = currentTotal;
    
    if (payment > 0) {
      const change = payment - total;
      changeAmount.textContent = `₱${fmt(Math.abs(change))}`;
      
      changeDisplay.classList.add('show');
      
      if (change < 0) {
        changeDisplay.classList.add('insufficient');
        changeAmount.classList.add('insufficient');
        changeDisplay.querySelector('.change-label').textContent = 'Insufficient Amount:';
      } else {
        changeDisplay.classList.remove('insufficient');
        changeAmount.classList.remove('insufficient');
        changeDisplay.querySelector('.change-label').textContent = 'Change Due:';
      }
    } else {
      changeDisplay.classList.remove('show');
    }
  }

  // Payment amount input listener
  paymentAmount.addEventListener('input', calculateChange);

  // Load products
  async function loadProducts() {
    try {
      const productsJson = await getJSON('../api/product_list.php');
      
      if (productsJson.success) {
        const rawProducts = productsJson.data || productsJson.products || [];
        
        // Remove duplicates
        const uniqueProducts = [];
        const seenIds = new Set();
        
        rawProducts.forEach(product => {
          const productId = product.Product_ID || product.product_id;
          if (!seenIds.has(productId)) {
            seenIds.add(productId);
            uniqueProducts.push(product);
          }
        });
        
        products = uniqueProducts;
        console.log('Products loaded:', products.length);
      } else {
        showStatus(statusError, productsJson.error || 'Error loading products');
      }
    } catch (err) {
      console.error('Load products error:', err);
      showStatus(statusError, 'Network error: ' + err.message);
    }
  }

  // Find product by ID or barcode
  function findProduct(searchTerm) {
    return products.find(p => {
      const productId = (p.Product_ID || p.product_id || '').toString();
      const productName = (p.Name || p.name || p.product_name || '').toLowerCase();
      const productDescription = (p.Description || p.description || '').toLowerCase();
      
      return productId === searchTerm || 
             productName.includes(searchTerm.toLowerCase()) ||
             productDescription.includes(searchTerm.toLowerCase());
    });
  }

  // Add item to transaction
  function addToTransaction(product, quantity = 1) {
    const productId = product.Product_ID || product.product_id;
    const productName = product.Name || product.name || product.product_name;
    const productPrice = product.Retail_Price || product.price || product.retail_price;
    // Get total available stock (warehouse + display stocks)
    const warehouseStock = product.quantity || 0;
    const displayStock = product.display_stocks || 0;
    const totalStock = warehouseStock + displayStock;

    if (quantity < 1) quantity = 1;
    if (quantity > totalStock) {
      showStatus(statusError, `Insufficient stock. Only ${totalStock} available for ${productName}`);
      return;
    }

    if (cart.has(productId)) {
      let currentQty = cart.get(productId).qty;
      if (currentQty + quantity > totalStock) {
        showStatus(statusError, `Cannot exceed stock limit for ${productName}`);
        return;
      }
      cart.get(productId).qty += quantity;
    } else {
      const normalizedProduct = {
        product_id: productId,
        name: productName,
        price: productPrice,
        quantity: totalStock
      };
      cart.set(productId, { product: normalizedProduct, qty: quantity });
    }

    showStatus(statusSuccess, `${productName} added to transaction`);
    updateTransactionDisplay();
  }

  // Update transaction display
  function updateTransactionDisplay() {
    const totalItems = Array.from(cart.values()).reduce((sum, item) => sum + item.qty, 0);
    transactionCount.textContent = totalItems;

    if (cart.size === 0) {
      transactionList.innerHTML = '<div class="empty-transaction">No items scanned yet<br><small>Scan a barcode or search for products to begin</small></div>';
      updateTotals(0, 0, 0, 0);
      processPayment.disabled = true;
      return;
    }

    let html = '';
    let subtotal = 0;

    cart.forEach(({ product, qty }, pid) => {
      const lineTotal = qty * product.price;
      subtotal += lineTotal;
      
      html += `
        <div class="transaction-item">
          <div class="item-info">
            <div class="item-name">${product.name}</div>
            <div class="item-details">₱${fmt(product.price)} each</div>
          </div>
          <div class="item-controls">
            <div class="qty-controls">
              <button class="qty-btn" onclick="updateQuantity(${pid}, ${qty - 1})">−</button>
              <input type="number" class="qty-input" value="${qty}" min="1" max="${product.quantity}" 
                     onblur="updateQuantityFromInput(${pid}, this.value)" 
                     onkeypress="if(event.key==='Enter') this.blur()"
                     onclick="this.select()">
              <button class="qty-btn" onclick="updateQuantity(${pid}, ${qty + 1})">+</button>
            </div>
            <div class="item-total">₱${fmt(lineTotal)}</div>
            <button class="void-btn" onclick="voidItem(${pid})">VOID</button>
          </div>
        </div>
      `;
    });

    transactionList.innerHTML = html;

    const tax = subtotal * 0.12;
    const discount = 0;
    const total = subtotal + tax - discount;
    
    updateTotals(subtotal, tax, discount, total);
    processPayment.disabled = false;
  }

  // Update totals display
  function updateTotals(subtotal, tax, discount, total) {
    subtotalAmount.textContent = fmt(subtotal);
    taxAmount.textContent = fmt(tax);
    discountAmount.textContent = fmt(discount);
    finalTotal.textContent = fmt(total);
    currentTotal = total;
    
    // Recalculate change if payment amount is entered
    if (paymentAmount.value) {
      calculateChange();
    }
  }

  // Update item quantity
  window.updateQuantity = function(pid, newQty) {
    if (!cart.has(pid)) return;
    
    const item = cart.get(pid);
    const maxQty = item.product.quantity;
    
    if (newQty <= 0) {
      cart.delete(pid);
      showStatus(statusSuccess, `${item.product.name} removed from transaction`);
    } else if (newQty <= maxQty) {
      item.qty = newQty;
    } else {
      showStatus(statusError, `Cannot exceed stock limit of ${maxQty}`);
      return;
    }
    
    updateTransactionDisplay();
  };

  // Update quantity from manual input
  window.updateQuantityFromInput = function(pid, newQty) {
    if (!cart.has(pid)) return;
    
    const item = cart.get(pid);
    const maxQty = item.product.quantity;
    
    newQty = parseInt(newQty) || 0;
    
    if (newQty <= 0) {
      cart.delete(pid);
      showStatus(statusSuccess, `${item.product.name} removed from transaction`);
    } else if (newQty <= maxQty) {
      item.qty = newQty;
      showStatus(statusSuccess, `${item.product.name} quantity updated to ${newQty}`);
    } else {
      showStatus(statusError, `Cannot exceed stock limit of ${maxQty}`);
      // Reset input to current quantity
      setTimeout(() => updateTransactionDisplay(), 100);
      return;
    }
    
    updateTransactionDisplay();
  };

  // Void item
  window.voidItem = function(pid) {
    if (!cart.has(pid)) return;
    const item = cart.get(pid);
    cart.delete(pid);
    showStatus(statusSuccess, `${item.product.name} voided from transaction`);
    updateTransactionDisplay();
  };

  // Void last item
  window.voidLastItem = function() {
    if (cart.size === 0) return;
    const lastKey = Array.from(cart.keys()).pop();
    voidItem(lastKey);
  };

  // Clear transaction
  window.clearTransaction = function() {
    if (cart.size === 0) return;
    if (confirm('Clear entire transaction?')) {
      cart.clear();
      paymentAmount.value = '';
      changeDisplay.classList.remove('show');
      updateTransactionDisplay();
      showStatus(statusSuccess, 'Transaction cleared');
    }
  };

  // Suspend transaction
  window.suspendTransaction = function() {
    if (cart.size === 0) return;
    showStatus(statusSuccess, 'Transaction suspended (feature in development)');
  };

  // Barcode input handler
  barcodeInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
      const barcode = this.value.trim();
      if (!barcode) return;

      const product = findProduct(barcode);
      if (product) {
        addToTransaction(product);
        this.value = '';
      } else {
        showStatus(statusError, `Product not found: ${barcode}`);
        this.value = '';
      }
    }
  });

  // Auto-focus barcode input
  barcodeInput.focus();
  document.addEventListener('click', (e) => {
    if (document.activeElement !== productSearch && 
        document.activeElement !== paymentAmount && 
        !e.target.classList.contains('qty-input')) {
      barcodeInput.focus();
    }
  });

  // Product search functionality
  let searchTimeout;
  productSearch.addEventListener('input', function() {
    const searchTerm = this.value.trim().toLowerCase();
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      if (searchTerm.length < 2) {
        searchResults.style.display = 'none';
        return;
      }

      const matches = products.filter(p => {
        const name = (p.Name || p.name || p.product_name || '').toLowerCase();
        const description = (p.Description || p.description || '').toLowerCase();
        return name.includes(searchTerm) || description.includes(searchTerm);
      }).slice(0, 10);

      if (matches.length > 0) {
        searchResults.innerHTML = matches.map(p => {
          const productName = p.Name || p.name || p.product_name;
          const productPrice = p.Retail_Price || p.price || p.retail_price;
          const warehouseStock = p.quantity || 0;
          const displayStock = p.display_stocks || 0;
          const totalStock = warehouseStock + displayStock;
          
          return `
            <div class="search-item" onclick="selectSearchProduct(${p.Product_ID || p.product_id})">
              <div class="search-item-name">${productName}</div>
              <div class="search-item-price">₱${fmt(productPrice)}</div>
            </div>
          `;
        }).join('');
        searchResults.style.display = 'block';
      } else {
        searchResults.innerHTML = '<div class="search-item">No products found</div>';
        searchResults.style.display = 'block';
      }
    }, 300);
  });

  // Select product from search
  window.selectSearchProduct = function(productId) {
    const product = products.find(p => (p.Product_ID || p.product_id) === productId);
    if (product) {
      addToTransaction(product);
      productSearch.value = '';
      searchResults.style.display = 'none';
      barcodeInput.focus();
    }
  };

  // Hide search results when clicking outside
  document.addEventListener('click', function(e) {
    if (!productSearch.contains(e.target) && !searchResults.contains(e.target)) {
      searchResults.style.display = 'none';
    }
  });

  // Payment method selection
  document.querySelector('.payment-grid').addEventListener('click', function(e) {
    if (e.target.classList.contains('payment-btn')) {
      document.querySelectorAll('.payment-btn').forEach(btn => btn.classList.remove('active'));
      e.target.classList.add('active');
      selectedPayment = e.target.dataset.payment;
      
      // For non-cash payments, hide change calculation
      if (selectedPayment !== 'CASH') {
        changeDisplay.classList.remove('show');
        paymentAmount.value = fmt(currentTotal);
      }
    }
  });

  // Clear transaction button
  clearAll.onclick = function() {
    clearTransaction();
  };

  // Process payment with validation
  processPayment.onclick = async function() {
    if (cart.size === 0) {
      showStatus(statusError, 'No items in transaction');
      return;
    }

    const payment = parseFloat(paymentAmount.value) || 0;
    const total = currentTotal;

    // Validate payment amount for cash transactions
    if (selectedPayment === 'CASH') {
      if (payment === 0) {
        showStatus(statusError, 'Please enter payment amount');
        paymentAmount.focus();
        return;
      }
      if (payment < total) {
        showStatus(statusError, `Insufficient payment. Need ₱${fmt(total - payment)} more`);
        paymentAmount.focus();
        return;
      }
    } else {
      // For card/digital payments, set payment amount to exact total
      paymentAmount.value = fmt(total);
    }

    processPayment.disabled = true;
    processPayment.innerHTML = '<div class="spinner"></div>Processing Payment...';

    const items = [];
    cart.forEach(({ product, qty }) => {
      items.push({
        product_id: product.product_id,
        quantity: qty,
        price: product.price,
        product_name: product.name
      });
    });

    let subtotal = 0;
    cart.forEach(({ product, qty }) => {
      subtotal += qty * product.price;
    });
    
    const tax = subtotal * 0.12;

    try {
      const terminalId = getTerminalId();
      const saleData = {
        user_id: CURRENT_CASHIER_ID,
        terminal_id: terminalId,
        payment: selectedPayment,
        total_amount: total,
        subtotal: subtotal,
        tax_amount: tax,
        payment_amount: parseFloat(paymentAmount.value) || total,
        items: items
      };

      const json = await postJSON('pos_sale.php', saleData);

      if (json.success) {
        const receiptData = {
          sale_id: json.data.sale_id || json.sale_id,
          total: total,
          subtotal: subtotal,
          tax: tax,
          payment_method: selectedPayment,
          payment_amount: parseFloat(paymentAmount.value) || total,
          change_due: selectedPayment === 'CASH' ? Math.max(0, parseFloat(paymentAmount.value) - total) : 0,
          items: items,
          terminal_id: terminalId,
          cashier_name: CURRENT_CASHIER
        };
        
        showReceipt(receiptData);
        cart.clear();
        paymentAmount.value = '';
        changeDisplay.classList.remove('show');
        updateTransactionDisplay();
        await loadProducts();
        showStatus(statusSuccess, `Payment processed successfully! Receipt #${receiptData.sale_id}`);
      } else {
        showStatus(statusError, json.error || 'Error processing payment');
      }
    } catch (err) {
      showStatus(statusError, 'Network error: ' + err.message);
    } finally {
      processPayment.disabled = false;
      processPayment.innerHTML = '💳 Process Payment';
    }
  };

  // Receipt functionality
  function showReceipt(saleData) {
    const modal = document.getElementById('receiptModal');
    const content = document.getElementById('receiptContent');
    
    let itemsHtml = '';
    let subtotal = 0;
    
    saleData.items.forEach(item => {
      const lineTotal = item.quantity * item.price;
      subtotal += lineTotal;
      itemsHtml += `
        <div class="receipt-item">
          <div>${item.product_name}</div>
          <div>${item.quantity} x ₱${fmt(item.price)} = ₱${fmt(lineTotal)}</div>
        </div>
      `;
    });
    
    const tax = subtotal * 0.12;
    const total = subtotal + tax;
    
    content.innerHTML = `
      <div class="receipt-header">
        <h3>🏪 Grocery Store POS System</h3>
        <p>Receipt #${saleData.sale_id}</p>
        <p>${new Date().toLocaleString()}</p>
        <p>Terminal: #${saleData.terminal_id} | Cashier: ${saleData.cashier_name || 'Admin'}</p>
      </div>
      
      <div class="receipt-items">
        ${itemsHtml}
      </div>
      
      <div class="receipt-total">
        <div class="receipt-item">
          <div>Subtotal:</div>
          <div>₱${fmt(subtotal)}</div>
        </div>
        <div class="receipt-item">
          <div>Tax (12%):</div>
          <div>₱${fmt(tax)}</div>
        </div>
        <div class="receipt-item">
          <div><strong>Total:</strong></div>
          <div><strong>₱${fmt(total)}</strong></div>
        </div>
        <div class="receipt-item">
          <div>Payment (${saleData.payment_method}):</div>
          <div>₱${fmt(saleData.payment_amount)}</div>
        </div>
        ${saleData.change_due > 0 ? `
        <div class="receipt-item">
          <div><strong>Change:</strong></div>
          <div><strong>₱${fmt(saleData.change_due)}</strong></div>
        </div>
        ` : ''}
      </div>
      
      <div style="text-align: center; margin-top: 2rem; padding-top: 1rem; border-top: 2px dashed #ddd;">
        <p>Thank you for your business!</p>
        <p>Please come again!</p>
      </div>
    `;
    
    modal.style.display = 'block';
  }

  // Print receipt function
  window.printReceipt = function() {
    const printContent = document.getElementById('receiptContent').innerHTML;
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    
    printWindow.document.write(`
      <html>
        <head>
          <title>Receipt</title>
          <style>
            body { font-family: 'Courier New', monospace; padding: 20px; }
            .receipt-header { text-align: center; margin-bottom: 2rem; }
            .receipt-item { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
            .receipt-total { border-top: 2px solid #333; padding-top: 1rem; margin-top: 1rem; }
          </style>
        </head>
        <body>${printContent}</body>
      </html>
    `);
    
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
  };

  // Modal controls
  document.getElementById('closeModal').onclick = function() {
    document.getElementById('receiptModal').style.display = 'none';
  };

  window.onclick = function(event) {
    const modal = document.getElementById('receiptModal');
    if (event.target === modal) {
      modal.style.display = 'none';
    }
  };

  // Time display
  function updateTime() {
    const now = new Date();
    currentTimeEl.textContent = `🕐 ${now.toLocaleString()}`;
  }

  setInterval(updateTime, 1000);
  updateTime();

  // Enhanced keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    // F1 - Focus barcode scanner
    if (e.key === 'F1') {
      e.preventDefault();
      barcodeInput.focus();
    }
    
    // F2 - Focus product search
    if (e.key === 'F2') {
      e.preventDefault();
      productSearch.focus();
    }
    
    // F3 - Process payment
    if (e.key === 'F3') {
      e.preventDefault();
      if (!processPayment.disabled) {
        processPayment.click();
      }
    }
    
    // F4 - Clear transaction
    if (e.key === 'F4') {
      e.preventDefault();
      clearTransaction();
    }
    
    // F5 - Void last item
    if (e.key === 'F5') {
      e.preventDefault();
      voidLastItem();
    }
    
    // F6 - Focus payment amount
    if (e.key === 'F6') {
      e.preventDefault();
      paymentAmount.focus();
      paymentAmount.select();
    }
    
    // F7 - Set exact amount
    if (e.key === 'F7') {
      e.preventDefault();
      setExactAmount();
    }
    
    // Escape - Close modal or clear search
    if (e.key === 'Escape') {
      document.getElementById('receiptModal').style.display = 'none';
      searchResults.style.display = 'none';
      productSearch.value = '';
      barcodeInput.focus();
    }
    
    // Enter on search results
    if (e.key === 'Enter' && document.activeElement === productSearch) {
      const firstResult = searchResults.querySelector('.search-item');
      if (firstResult) {
        firstResult.click();
      }
    }
  });

  // Get terminal ID from URL parameter
  function getTerminalId() {
    const urlParams = new URLSearchParams(window.location.search);
    const terminal = urlParams.get('terminal');
    return terminal ? parseInt(terminal) : 1; // Default to terminal 1
  }

  // Initialize the system with role guard
  let CURRENT_CASHIER = 'Admin';
  let CURRENT_CASHIER_ID = null;
  async function initializePOS() {
    const user = await AuthHelper.requireAuthAndRole(['Cashier','Admin','Manager']);
    if (!user) return;
    
    // Set terminal number
    const terminalId = getTerminalId();
    document.getElementById('terminalNumber').textContent = `#${terminalId}`;
    
    // Store terminal ID in session for API calls
    sessionStorage.setItem('currentTerminalId', terminalId);
    
    CURRENT_CASHIER = user.User_name || 'Admin';
    CURRENT_CASHIER_ID = user.User_ID || null;
    document.getElementById('userInfo').textContent = `👤 Cashier: ${CURRENT_CASHIER} (${user.Role_name})`;
    document.getElementById('logoutBtn').onclick = async () => {
      try {
        await fetch('../api/terminal_management.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ terminal_id: terminalId, action: 'unregister' })
        });
      } catch (e) {}
      AuthHelper.logoutAndRedirect('login.html');
    };
    console.log(`Initializing Enhanced Grocery Store POS System - Terminal #${terminalId}...`);
    // Terminal status check removed; terminal management is no longer enforced

    // Register this user as current user on this terminal
    try {
      await fetch('../api/terminal_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ terminal_id: terminalId, action: 'register' })
      });
    } catch (e) {
      console.warn('Terminal register failed:', e);
    }

    // Unregister on window close/navigation
    window.addEventListener('beforeunload', () => {
      navigator.sendBeacon('../api/terminal_management.php', JSON.stringify({ terminal_id: terminalId, action: 'unregister' }));
    });

    // Watch terminal status and auto-logout if not online
    (function startTerminalWatcher() {
      async function checkTerminal() {
        try {
          const res = await fetch('../api/terminal_management.php');
          const json = await res.json();
          if (json && json.success && Array.isArray(json.data)) {
            const term = json.data.find(t => Number(t.id) === Number(terminalId));
            if (term) {
              const st = (term.status || '').toLowerCase();
              if (st && st !== 'online') {
                try {
                  await fetch('../api/terminal_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ terminal_id: terminalId, action: 'unregister' })
                  });
                } catch (e) {}
                alert(`This Terminal is ${st === 'maintenance' ? 'Under Maintenance' : 'Offline'}. You will be logged out.`);
                AuthHelper.logoutAndRedirect('login.html');
                return;
              }
            }
          }
        } catch (e) {
          // Ignore transient errors
        }
      }
      // Initial check, then periodic
      checkTerminal();
      setInterval(checkTerminal, 10000);
    })();

    await loadProducts();
    console.log(`POS System ready with enhanced features on Terminal #${terminalId}!`);
    barcodeInput.focus();
  }

  // Start the system
  initializePOS();