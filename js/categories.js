 // Guard: Managers and Admins
 document.addEventListener('DOMContentLoaded', async () => {
    const user = await AuthHelper.requireAuthAndRole(['Manager','Admin']);
    if (!user) return;
  });
  let allProducts = []; // Store all products globally
async function loadCategories() {
const grid = document.getElementById('categories-grid');
grid.innerHTML = '<div class="loading">🔄 Loading categories...</div>';

try {
  const q = document.getElementById('category-search').value.trim();
  const inventoryJson = await getJSON('product_list.php' + (q ? ('?q=' + encodeURIComponent(q)) : ''));
  
  if (inventoryJson.success && inventoryJson.products && inventoryJson.products.length > 0) {
    allProducts = inventoryJson.products;
    const categoryStats = {};
    
    allProducts.forEach(product => {
      const category = product.category_name || 'Uncategorized';
      if (!categoryStats[category]) {
        categoryStats[category] = {
          name: category,
          product_count: 0,
          total_value: 0,
          low_stock_count: 0
        };
      }
      
      categoryStats[category].product_count++;
      // Use total stock value for product value
      const warehouseQty = parseInt(product.quantity || 0);
      const displayQty = parseInt(product.display_stocks || 0);
      const totalStock = warehouseQty + displayQty;
      categoryStats[category].total_value += parseFloat(product.price || 0) * totalStock;
      
      const reorderLevel = parseInt(product.reorder_level || 5);
      const isDiscontinued = product.is_discontinued == 1;
      if (!isDiscontinued && totalStock <= reorderLevel) {
        categoryStats[category].low_stock_count++;
      }
    });
    
    const categories = Object.values(categoryStats);
    
    if (categories.length > 0) {
      grid.innerHTML = categories.map(cat => `
        <div class="category-card" onclick="showCategoryProducts('${cat.name.replace(/'/g, "\\'")}')">
          <div class="category-header">
            <div class="category-name">${cat.name}</div>
            <div class="category-count">${cat.product_count}</div>
          </div>
          
          ${cat.low_stock_count > 0 ? `<div style="margin-top: 10px;"><span style="background: #ffc107; color: #212529; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: bold;">${cat.low_stock_count} Low Stock</span></div>` : ''}
          
          <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e1e8ed;">
            <div style="font-size: 0.9em; color: #6c757d;">Total Value: <strong>₱${cat.total_value.toFixed(2)}</strong></div>
            <div style="font-size: 0.9em; color: #6c757d;">Avg per Product: <strong>₱${(cat.total_value / cat.product_count).toFixed(2)}</strong></div>
          </div>
        </div>
      `).join('');
    } else {
      grid.innerHTML = '<div class="no-data">📭 No categories found</div>';
    }
  } else {
    grid.innerHTML = '<div class="no-data">📭 No products found to categorize</div>';
  }
} catch (err) {
  console.error('Error loading categories:', err);
  grid.innerHTML = `<div class="no-data">❌ Error: ${err.message}</div>`;
}
}


  function showCategoryProducts(categoryName) {
    // Filter products by category
    const categoryProducts = allProducts.filter(product => 
      (product.category_name || 'Uncategorized') === categoryName
    );

    // Hide categories grid and show products section
    document.getElementById('categories-grid').style.display = 'none';
    document.getElementById('products-section').style.display = 'block';
    document.getElementById('selected-category-title').textContent = `Products in ${categoryName} (${categoryProducts.length})`;

    // Populate products table
    const tbody = document.getElementById('products-body');
    if (categoryProducts.length > 0) {
      tbody.innerHTML = categoryProducts.map(p => {
        let stockClass = '';
        const warehouseQty = parseInt(p.quantity || 0);
        const displayQty = parseInt(p.display_stocks || 0);
        const totalStock = warehouseQty + displayQty;
        const reorderLevel = parseInt(p.reorder_level || 5);
        const isDiscontinued = p.is_discontinued == 1;
        
        if (!isDiscontinued) {
          if (totalStock === 0) stockClass = 'out-of-stock';
          else if (totalStock <= reorderLevel) stockClass = 'low-stock';
        }
        
        return `<tr class="${stockClass}">
          <td>${p.product_id || ''}</td>
          <td>${p.name || ''}</td>
          <td>${p.unit_measure || ''}</td>
          <td>${p.batch_number || ''}</td>
          <td>${p.expiration_date || ''}</td>
          <td>${p.supplier_name || ''}</td>
          <td><span class="stock-level">${totalStock}</span></td>
          <td>${p.reorder_level || 'Not set'}</td>
          <td>₱${parseFloat(p.price || 0).toFixed(2)}</td>
          <td>${formatDate(p.last_update)}</td>
        </tr>`;
      }).join('');
    } else {
      tbody.innerHTML = '<tr><td colspan="10">📭 No products found in this category</td></tr>';
    }
  }

  function showAllCategories() {
    // Show categories grid and hide products section
    document.getElementById('categories-grid').style.display = 'grid';
    document.getElementById('products-section').style.display = 'none';
  }

  function formatDate(dateStr) {
    if (!dateStr) return 'Never';
    try {
      return new Date(dateStr).toLocaleDateString();
    } catch (e) {
      return 'Invalid Date';
    }
  }

  function refreshCategories() {
    const searchInput = document.getElementById('category-search');
    if (searchInput) searchInput.value = '';
    showAllCategories(); // Go back to categories view
    loadCategories();
  }

  // Load data on page load
  document.addEventListener('DOMContentLoaded', loadCategories);