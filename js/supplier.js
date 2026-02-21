  const modal = document.getElementById('supplierModal');
  const productsModal = document.getElementById('productsModal');
  const modalTitle = document.getElementById('modalTitle');
  const productsModalTitle = document.getElementById('productsModalTitle');
  const openModalBtn = document.getElementById('openModal');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const closeProductsModalBtn = document.getElementById('closeProductsModal');
  const saveSupplierBtn = document.getElementById('saveSupplierBtn');
  let editSupplierId = null;
  let totalProducts = 0;

  // Event listeners
  openModalBtn.addEventListener('click', () => {
    editSupplierId = null;
    modalTitle.textContent = 'Add New Supplier';
    document.getElementById('supplierName').value = '';
    document.getElementById('supplierContact').value = '';
    document.getElementById('supplierAddress').value = '';
    modal.style.display = 'flex';
  });

  closeModalBtn.addEventListener('click', () => modal.style.display = 'none');
  closeProductsModalBtn.addEventListener('click', () => productsModal.style.display = 'none');
  
  window.addEventListener('click', e => { 
    if(e.target == modal) modal.style.display = 'none';
    if(e.target == productsModal) productsModal.style.display = 'none';
  });

  async function fetchSuppliers() {
    try {
      const data = await getJSON('list_supplier.php');
      if (data.success) {
        // Update stats
        document.getElementById('totalSuppliers').textContent = data.total_count || data.suppliers.length;
        totalProducts = data.suppliers.reduce((sum, supplier) => sum + (parseInt(supplier.product_count) || 0), 0);
        document.getElementById('totalProducts').textContent = totalProducts;

        const tbody = document.querySelector("#suppliersTable tbody");
        tbody.innerHTML = "";
        
        data.suppliers.forEach(supplier => {
          const row = document.createElement('tr');
          row.innerHTML = `
            <td>${supplier.Supplier_ID}</td>
            <td><strong>${supplier.Name}</strong></td>
            <td>${supplier.Contact_info || 'N/A'}</td>
            <td>${supplier.Address || 'N/A'}</td>
            <td>
              <span class="product-count">
                📦 ${supplier.product_count || 0} products
              </span>
            </td>
            <td>
              <div class="action-buttons">
                <button class="btn-small products-btn" data-id="${supplier.Supplier_ID}" data-name="${supplier.Name}">
                  👁️ View Products
                </button>
                <button class="btn-small edit-btn" data-id="${supplier.Supplier_ID}" data-name="${supplier.Name}" data-contact="${supplier.Contact_info}" data-address="${supplier.Address}">
                  ✏️ Edit
                </button>
                <button class="btn-small delete-btn" data-id="${supplier.Supplier_ID}" data-name="${supplier.Name}">
                  🗑️ Delete
                </button>
              </div>
            </td>
          `;
          tbody.appendChild(row);
        });

        // Add event listeners for action buttons
        document.querySelectorAll('.edit-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            editSupplierId = btn.dataset.id;
            modalTitle.textContent = 'Edit Supplier';
            document.getElementById('supplierName').value = btn.dataset.name;
            document.getElementById('supplierContact').value = btn.dataset.contact || '';
            document.getElementById('supplierAddress').value = btn.dataset.address || '';
            modal.style.display = 'flex';
          });
        });

        document.querySelectorAll('.delete-btn').forEach(btn => {
          btn.addEventListener('click', async () => {
            const supplierName = btn.dataset.name;
            if (!confirm(`Are you sure you want to delete "${supplierName}"? This action cannot be undone.`)) return;
            try {
              const data = await postJSON('delete_supplier.php', { supplier_id: btn.dataset.id });
              if (data.success) {
                alert('Supplier deleted successfully!');
                fetchSuppliers();
              } else {
                alert('Error: ' + data.error);
              }
            } catch (err) {
              console.error(err);
              alert('Failed to delete supplier.');
            }
          });
        });

        document.querySelectorAll('.products-btn').forEach(btn => {
          btn.addEventListener('click', async () => {
            const supplierId = btn.dataset.id;
            const supplierName = btn.dataset.name;
            await showSupplierProducts(supplierId, supplierName);
          });
        });
      } else {
        alert("Error fetching suppliers: " + data.error);
      }
    } catch (err) {
      console.error("Fetch error:", err);
    }
  }

  async function showSupplierProducts(supplierId, supplierName) {
    try {
      productsModalTitle.textContent = `Products from ${supplierName}`;
      document.getElementById('productsContainer').innerHTML = '<div style="text-align: center; padding: 20px;">Loading products...</div>';
      productsModal.style.display = 'flex';

      const data = await getJSON(`supplier_products.php?supplier_id=${supplierId}`);
      if (data.success) {
        const container = document.getElementById('productsContainer');
        if (data.products && data.products.length > 0) {
          container.innerHTML = `
            <div class="products-grid">
              ${data.products.map(product => `
                <div class="product-card">
                  <div class="product-name">${product.name}</div>
                  <div class="product-details">
                    <div><strong>Category:</strong> ${product.category_name || 'N/A'}</div>
                    <div><strong>Price:</strong> ₱${parseFloat(product.price || 0).toFixed(2)}</div>
                    <div><strong>Unit:</strong> ${product.unit_measure || 'N/A'}</div>
                    ${product.description ? `<div><strong>Description:</strong> ${product.description}</div>` : ''}
                    <div class="product-stock ${parseInt(product.total_stock || 0) <= (parseInt(product.reorder_level || 5) ? 'low' : '')}">
                      Stock: ${product.total_stock || 0} ${product.unit_measure || ''}
                    </div>
                  </div>
                </div>
              `).join('')}
            </div>
          `;
        } else {
          container.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No products found for this supplier.</div>';
        }
      } else {
        document.getElementById('productsContainer').innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading products: ' + data.error + '</div>';
      }
    } catch (err) {
      console.error('Error fetching supplier products:', err);
      document.getElementById('productsContainer').innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Failed to load products.</div>';
    }
  }
  saveSupplierBtn.addEventListener('click', async () => {
    const name = document.getElementById('supplierName').value.trim();
    const contact = document.getElementById('supplierContact').value.trim();
    const address = document.getElementById('supplierAddress').value.trim();
    
    if (!name) {
      alert('Supplier name is required');
      return;
    }

    try {
      const payload = editSupplierId 
        ? { supplier_id: editSupplierId, name, contact_info: contact, address }
        : { name, contact_info: contact, address };
      
      const data = await postJSON(
        editSupplierId ? 'update_supplier.php' : 'add_supplier.php', 
        payload
      );
      
      if (data.success) {
        alert(editSupplierId ? 'Supplier updated successfully!' : 'Supplier added successfully!');
        modal.style.display = 'none';
        editSupplierId = null;
        fetchSuppliers();
      } else {
        alert('Error: ' + data.error);
      }
    } catch (err) {
      console.error(err);
      alert('Failed to save supplier.');
    }
  });

  // Initialize the page
  fetchSuppliers();

  // Expose functions to global scope
  window.fetchSuppliers = fetchSuppliers;
  window.showSupplierProducts = showSupplierProducts;