  // Add product: Admin and Manager
  document.addEventListener('DOMContentLoaded', async () => {
    await AuthHelper.requireAuthAndRole(['Admin','Manager']);
  });
  // ---- Supplier dropdown population----
  async function loadSuppliers() {
    const sel = document.getElementById('supplier_id');
    try {
      console.log('Loading suppliers from: ../api/list_supplier.php'); // Debug
      
      // Direct fetch to your list_supplier.php API
      const response = await fetch('../api/list_supplier.php');
      const result = await response.json();
      
      console.log('Supplier API response:', result); // Debug
      
      if (result.success && result.suppliers) {
        console.log('Found suppliers:', result.suppliers.length); // Debug
        result.suppliers.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.Supplier_ID;  // Match your database field name
          opt.textContent = s.Name;    // Match your database field name
          sel.appendChild(opt);
        });
        console.log('Suppliers loaded successfully'); // Debug
      } else {
        throw new Error(result.error || 'No suppliers returned or API failed');
      }
    } catch (e) {
      console.error('Failed to load suppliers:', e);
      showMessage('Warning: Could not load suppliers - ' + e.message + '. Please refresh the page.', 'error');
    }
  }

  // Load suppliers only (removed users loading)
  loadSuppliers();

  // ---- Category autocomplete functionality ----
  let categoryTimeout;
  let selectedCategoryId = null;
  
  const categoryInput = document.getElementById('category_name');
  const categoryStatus = document.getElementById('category_status');
  const suggestions = document.getElementById('category_suggestions');
  const categoryDescInput = document.getElementById('category_description');

  async function searchCategories(query) {
    try {
      if (!query.trim()) {
        suggestions.style.display = 'none';
        updateCategoryStatus('');
        return;
      }

      const response = await fetch(`../api/category_list.php?q=${encodeURIComponent(query)}`);
      const result = await response.json();
      
      if (result.success && result.categories) {
        displaySuggestions(result.categories, query);
      } else {
        suggestions.style.display = 'none';
        updateCategoryStatus(query);
      }
    } catch (e) {
      console.error('Failed to search categories:', e);
      suggestions.style.display = 'none';
      updateCategoryStatus(query);
    }
  }

  function displaySuggestions(categories, query) {
    suggestions.innerHTML = '';
    
    if (categories.length === 0) {
      updateCategoryStatus(query);
      suggestions.style.display = 'none';
      return;
    }

    categories.forEach(cat => {
      const item = document.createElement('div');
      item.className = 'suggestion-item';
      item.innerHTML = `
        <div><strong>${cat.category_name}</strong></div>
        <div class="suggestion-meta">${cat.description || 'No description'} • ${cat.product_count} products</div>
      `;
      
      item.addEventListener('click', () => {
        categoryInput.value = cat.category_name;
        selectedCategoryId = cat.category_id;
        suggestions.style.display = 'none';
        
        // Pre-fill category description if available
        if (cat.description && !categoryDescInput.value) {
          categoryDescInput.value = cat.description;
        }
        
        updateCategoryStatus(cat.category_name, true);
      });
      
      suggestions.appendChild(item);
    });
    
    suggestions.style.display = 'block';
  }

  function updateCategoryStatus(categoryName, isExisting = false) {
    if (!categoryName.trim()) {
      categoryStatus.innerHTML = '';
      selectedCategoryId = null;
      return;
    }

    if (isExisting) {
      categoryStatus.innerHTML = '<span class="category-status existing-category">Existing Category</span>';
    } else {
      categoryStatus.innerHTML = '<span class="category-status new-category">New Category (will be created)</span>';
      selectedCategoryId = null;
    }
  }

  categoryInput.addEventListener('input', (e) => {
    clearTimeout(categoryTimeout);
    const query = e.target.value;
    
    // Reset selected category when typing
    selectedCategoryId = null;
    
    categoryTimeout = setTimeout(() => {
      searchCategories(query);
    }, 300); // Debounce 300ms
  });

  // Hide suggestions when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.autocomplete-container')) {
      suggestions.style.display = 'none';
    }
  });

  // Handle manual typing (check if category exists)
  categoryInput.addEventListener('blur', () => {
    setTimeout(() => {
      if (suggestions.style.display === 'none') {
        updateCategoryStatus(categoryInput.value);
      }
    }, 200);
  });

  // ---- Minimum date for expiration (today) ----
  document.getElementById('expiration_date').min = new Date().toISOString().split('T')[0];

  // ---- Auto-sync display stocks with initial stock ----
  document.getElementById('initial_stock').addEventListener('input', function() {
    const displayStocks = document.getElementById('display_stocks');
    if (!displayStocks.value || displayStocks.value == 0) {
      displayStocks.value = this.value;
    }
  });

  // ---- Form submission ----
  const form = document.getElementById('addProductForm');
  const msg = document.getElementById('msg');
  const submitBtn = document.getElementById('submitBtn');

  function showMessage(text, type='') {
    msg.className = 'message ' + type;
    msg.textContent = text;
    msg.style.display = 'block';
  }

  function hideMessage() {
    msg.style.display = 'none';
  }

  // Auto-format price on blur
  document.getElementById('retail_price').addEventListener('blur', function() {
    if (this.value) this.value = parseFloat(this.value).toFixed(2);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideMessage();

    // Enhanced validation
    const name = form.name.value.trim();
    const category_name = form.category_name.value.trim();
    const supplier_id = form.supplier_id.value;

    if (!name || !category_name) {
      showMessage('Product Name and Category Name are required.', 'error');
      return;
    }

    if (!supplier_id) {
      showMessage('Please select a supplier. Products must be linked to suppliers for purchase orders.', 'error');
      return;
    }

    if (name.length > 100 || category_name.length > 100) {
      showMessage('Name/Category must not exceed 100 characters.', 'error');
      return;
    }

    // Validate stock quantities
    const initialStock = parseInt(form.initial_stock.value) || 0;
    const displayStocks = parseInt(form.display_stocks.value) || 0;
    
    if (displayStocks > initialStock) {
      showMessage('Display stocks cannot exceed initial stock quantity.', 'error');
      return;
    }

    // Build payload matching DB fields
    const payload = {
      name,
      description: form.description.value.trim() || null,
      category_name,
      category_description: form.category_description.value.trim() || null,
      retail_price: parseFloat(form.retail_price.value) || 0,
      initial_stock: initialStock,
      display_stocks: displayStocks,
      reorder_level: parseInt(form.reorder_level.value) || 0,
      unit_measure: form.unit_measure.value || null,
      batch_number: form.batch_number.value.trim() || null,
      expiration_date: form.expiration_date.value || null,
      supplier_id: parseInt(supplier_id),
      is_discontinued: form.is_discontinued.checked ? 1 : 0
    };

    // UI loading state
    submitBtn.disabled = true;
    showMessage('Saving product...', 'loading');
    try {
      const res = await postJSON('../api/product.php', payload);
      if (res.success) {
        showMessage('✅ Product added successfully! ID: ' + (res.product_id || res.data?.product_id || 'Generated'), 'success');
        form.reset();
        // Reset to default values
        form.retail_price.value = '0.00';
        form.initial_stock.value = '0';
        form.display_stocks.value = '0';
        form.reorder_level.value = '0';
      } else {
        showMessage('❌ ' + (res.message || res.error || 'Failed to add product'), 'error');
      }
    } catch (err) {
      showMessage('❌ Network error: ' + err.message, 'error');
    } finally {
      submitBtn.disabled = false;
    }
  });

  // Hide message on input change
  form.addEventListener('input', hideMessage);