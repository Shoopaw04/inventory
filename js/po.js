// ========= CONFIG =========
// Use global API_BASE from api.js
const API_BASE = window.API_BASE || "../api";

// ========= STATE =========
let currentStep = 1;
let products = [];
let selectedProducts = {}; // { [productId]: { name, qty, price, stock } }
let currentView = 'grid'; // 'grid' or 'table'

// PO list state (client-side pagination fallback)
let poRecords = [];         // unified array from API
let poTotalPages = 1;       // server value if provided
let poCurrentPage = 1;
let pageSize = 10;
let usingServerPaging = false;

// ========= TABS =========
function showTab(tabId) {
  document.getElementById('poSection').classList.add('hidden');
  document.getElementById(tabId).classList.remove('hidden');
  document.querySelectorAll("nav button").forEach(btn => btn.classList.remove("active"));
  if (tabId === 'poSection') document.getElementById("tabPO").classList.add("active");
}

// ========= MULTI-STEP =========
function goStep(step) {
  document.getElementById("step" + currentStep).classList.add("hidden");
  document.getElementById("step" + step).classList.remove("hidden");
  currentStep = step;
  if (step === 3) renderReview();
}

function resetForm() {
  const orderDateEl = document.getElementById("orderDate");
  const priorityEl = document.getElementById("priority");
  const notesEl = document.getElementById("notes");
  const supplierSelectEl = document.getElementById("supplierSelect");
  const deliveryDateEl = document.getElementById("deliveryDate");
  
  if (orderDateEl) orderDateEl.value = "";
  if (priorityEl) priorityEl.value = "Normal";
  if (notesEl) notesEl.value = "";
  if (supplierSelectEl) supplierSelectEl.value = "";
  if (deliveryDateEl) deliveryDateEl.value = "";
  
  selectedProducts = {};
  renderProducts();
  renderCart();
  goStep(1);
}

function renderReview() {
  const supplierSel = document.getElementById("supplierSelect");
  const supplierText = supplierSel ? (supplierSel.value ? supplierSel.selectedOptions[0].text : "(none selected)") : "(none selected)";
  
  // Update review cards with null checks
  const orderDateEl = document.getElementById("orderDate");
  const deliveryDateEl = document.getElementById("deliveryDate");
  const priorityEl = document.getElementById("priority");
  const notesEl = document.getElementById("notes");
  
  const reviewOrderDateEl = document.getElementById("reviewOrderDate");
  const reviewDeliveryDateEl = document.getElementById("reviewDeliveryDate");
  const reviewPriorityEl = document.getElementById("reviewPriority");
  const reviewSupplierEl = document.getElementById("reviewSupplier");
  
  if (reviewOrderDateEl) reviewOrderDateEl.textContent = orderDateEl ? (orderDateEl.value || "-") : "-";
  if (reviewDeliveryDateEl) reviewDeliveryDateEl.textContent = deliveryDateEl ? (deliveryDateEl.value || "Not specified") : "Not specified";
  if (reviewPriorityEl) reviewPriorityEl.textContent = priorityEl ? priorityEl.value : "Normal";
  if (reviewSupplierEl) reviewSupplierEl.textContent = supplierText;
  
  // Handle notes
  const notes = notesEl ? notesEl.value.trim() : "";
  const notesCard = document.getElementById("reviewNotesCard");
  if (notes && notesCard) {
    const reviewNotesEl = document.getElementById("reviewNotes");
    if (reviewNotesEl) reviewNotesEl.textContent = notes;
    notesCard.style.display = "block";
  } else if (notesCard) {
    notesCard.style.display = "none";
  }
  
  // Render products list grouped by supplier
  const items = Object.entries(selectedProducts);
  const productsList = document.getElementById("reviewProductsList");
  
  if (!items.length) {
    productsList.innerHTML = '<div style="padding: 20px; text-align: center; color: #6c757d; font-style: italic;">No products selected</div>';
  } else {
    productsList.innerHTML = "";
    let totalAmount = 0;
    let totalItems = 0;

    // Group items by supplierId/name
    const bySupplier = new Map();
    items.forEach(([pid, p]) => {
      const key = p.supplierId ?? p.supplierName ?? 'unknown';
      if (!bySupplier.has(key)) {
        bySupplier.set(key, { supplierId: p.supplierId, supplierName: p.supplierName || 'Unspecified Supplier', items: [] });
      }
      bySupplier.get(key).items.push({ pid, ...p });
    });

    // Update the supplier summary card on the right to show all involved suppliers
    const reviewSupplierCard = document.getElementById('reviewSupplier');
    if (reviewSupplierCard && bySupplier.size > 0) {
      const names = Array.from(bySupplier.values()).map(g => g.supplierName).filter(Boolean);
      reviewSupplierCard.textContent = names.join(', ');
    }

    bySupplier.forEach(group => {
      const header = document.createElement('div');
      header.className = 'review-card';
      header.innerHTML = `<h4>🏢 Supplier: ${group.supplierName}</h4>`;
      productsList.appendChild(header);

      group.items.forEach(p => {
        const lineTotal = (Number(p.qty) || 0) * (Number(p.price) || 0);
        totalAmount += lineTotal;
        totalItems += Number(p.qty) || 0;
        const item = document.createElement('div');
        item.className = 'review-product-item';
        item.innerHTML = `
          <div>
            <div class="review-product-name">${p.name}</div>
            <div class="review-product-details">${p.qty} × ₱${Number(p.price).toFixed(2)}</div>
          </div>
          <div class="review-product-total">₱${lineTotal.toFixed(2)}</div>
        `;
        productsList.appendChild(item);
      });
    });

    // Update summary
    document.getElementById("reviewTotal").textContent = `₱${totalAmount.toFixed(2)}`;
    document.getElementById("reviewItemsSummary").textContent = `${totalItems} items • ${items.length} products • ${bySupplier.size} supplier(s)`;
  }
}

// ========= VIEW SWITCHING =========
function switchView(view) {
  currentView = view;
  document.getElementById('gridViewBtn').classList.toggle('active', view === 'grid');
  document.getElementById('tableViewBtn').classList.toggle('active', view === 'table');
  document.getElementById('productGrid').classList.toggle('active', view === 'grid');
  document.getElementById('productTable').classList.toggle('active', view === 'table');
}

// ========= POS-STYLE CART FUNCTIONS =========
function addToCart(productId, quantity = 1) {
  const product = products.find(p => (p.Product_ID ?? p.product_id ?? p.id) == productId);
  if (!product) return;

  const id = product.Product_ID ?? product.product_id ?? product.id;
  const name = product.Name ?? product.name ?? `#${id}`;
  const stock = product.stock ?? product.quantity ?? product.current_stock ?? 0;
  const defaultPrice = product.price ?? product.Purchase_price ?? 0;

  // Determine supplier context at time of adding
  const supplierSelectStep2 = document.getElementById('supplierSelectStep2');
  const supplierSelectStep1 = document.getElementById('supplierSelect');
  const supplierIdFromFilter = supplierSelectStep2 && supplierSelectStep2.value ? Number(supplierSelectStep2.value) : (supplierSelectStep1 && supplierSelectStep1.value ? Number(supplierSelectStep1.value) : null);
  const supplierNameFromFilter = (() => {
    if (supplierSelectStep2 && supplierSelectStep2.value) {
      const opt = supplierSelectStep2.selectedOptions && supplierSelectStep2.selectedOptions[0];
      return opt ? opt.text : '';
    }
    if (supplierSelectStep1 && supplierSelectStep1.value) {
      const opt = supplierSelectStep1.selectedOptions && supplierSelectStep1.selectedOptions[0];
      return opt ? opt.text : '';
    }
    return '';
  })();
  const supplierId = product.Supplier_ID ?? product.supplier_id ?? supplierIdFromFilter;
  const supplierName = product.supplier_name ?? product.Supplier_name ?? supplierNameFromFilter;

  if (selectedProducts[productId]) {
    selectedProducts[productId].qty += quantity;
  } else {
    selectedProducts[productId] = {
      name, 
      qty: quantity,
      price: defaultPrice, 
      stock,
      supplierId: supplierId ?? null,
      supplierName: supplierName || 'Unspecified Supplier'
    };
  }
  renderCart();
  renderProducts(); // Update grid to show "in cart" state
}

function removeFromCart(productId) {
  delete selectedProducts[productId];
  renderCart();
  renderProducts();
}

function updateCartQty(productId, newQty) {
  if (selectedProducts[productId]) {
    if (newQty <= 0) {
      removeFromCart(productId);
    } else {
      selectedProducts[productId].qty = newQty;
      renderCart();
      renderProducts();
    }
  }
}

function updateCartPrice(productId, newPrice) {
  if (selectedProducts[productId]) {
    selectedProducts[productId].price = Math.max(0, newPrice);
    renderCart();
  }
}

function clearCart() {
  selectedProducts = {};
  renderCart();
  renderProducts();
}

function renderCart() {
  const cartBody = document.getElementById("cartBody");
  const cartCount = document.getElementById("cartCount");
  const cartItemCount = document.getElementById("cartItemCount");
  const cartTotal = document.getElementById("cartTotal");
  
  if (!cartBody) return;

  const items = Object.entries(selectedProducts);
  cartCount.textContent = items.length;
  
  if (items.length === 0) {
    cartBody.innerHTML = '<div class="cart-empty">No items in cart</div>';
    cartItemCount.textContent = "0";
    cartTotal.textContent = "₱0.00";
    return;
  }

  let totalAmount = 0;
  let totalItems = 0;
  cartBody.innerHTML = "";

  items.forEach(([pid, item]) => {
    const lineTotal = (Number(item.qty) || 0) * (Number(item.price) || 0);
    totalAmount += lineTotal;
    totalItems += Number(item.qty) || 0;

    const cartItem = document.createElement("div");
    cartItem.className = "cart-item";
    cartItem.innerHTML = `
      <div class="cart-item-header">
        <div class="cart-item-name">${item.name}</div>
        <button class="cart-remove" onclick="removeFromCart('${pid}')">×</button>
      </div>
      <div class="cart-item-controls">
        <div class="cart-qty-controls">
          <button class="cart-qty-btn" onclick="updateCartQty('${pid}', ${item.qty - 1})">−</button>
          <input type="number" class="cart-qty-input" min="1" value="${item.qty}" onchange="updateCartQty('${pid}', Number(this.value))" />
          <button class="cart-qty-btn" onclick="updateCartQty('${pid}', ${item.qty + 1})">+</button>
        </div>
        <input type="number" class="cart-price-input" value="${item.price}" step="0.01" min="0" 
               onchange="updateCartPrice('${pid}', Number(this.value))">
        <div class="cart-line-total">₱${lineTotal.toFixed(2)}</div>
      </div>
    `;
    cartBody.appendChild(cartItem);
  });

  cartItemCount.textContent = totalItems;
  cartTotal.textContent = `₱${totalAmount.toFixed(2)}`;
}

// ========= PRODUCTS (STEP 2) =========
function renderProducts() {
  renderProductGrid();
  renderProductTable();
}

function renderProductGrid() {
  const grid = document.getElementById("productGrid");
  if (!grid) return;
  
  grid.innerHTML = "";
  
  const filteredProducts = getFilteredProducts();
  
  filteredProducts.forEach(product => {
    const id = product.Product_ID ?? product.product_id ?? product.id;
    const name = product.Name ?? product.name ?? `#${id}`;
    const stock = product.stock ?? product.quantity ?? product.current_stock ?? 0;
    const defaultPrice = product.price ?? product.Purchase_price ?? 0;
    
    const isInCart = selectedProducts[id];
    const currentQty = isInCart ? isInCart.qty : 0;
    
    const card = document.createElement("div");
    card.className = `product-card ${isInCart ? 'in-cart' : ''}`;
    card.innerHTML = `
      <div class="product-header">
        <div class="product-name">${name}</div>
        <div class="product-stock ${stock < 10 ? 'low' : ''}">Stock: ${stock}</div>
      </div>
      <div class="product-price">₱${Number(defaultPrice).toFixed(2)}</div>
      <div class="product-actions">
        ${isInCart ? `
          <div class="qty-controls">
            <button class="qty-btn" onclick="updateCartQty('${id}', ${currentQty - 1})">−</button>
            <input type="number" class="qty-input" min="1" value="${currentQty}" onchange="updateCartQty('${id}', Number(this.value))" />
            <button class="qty-btn" onclick="updateCartQty('${id}', ${currentQty + 1})">+</button>
          </div>
          <input type="number" class="price-input" value="${isInCart.price}" step="0.01" min="0"
                 onchange="updateCartPrice('${id}', Number(this.value))">
        ` : `
          <div class="qty-controls">
            <button class="qty-btn" onclick="adjustPreQty('${id}', -1)">−</button>
            <input type="number" class="qty-input" id="preQty_${id}" min="1" value="1" onchange="onPreQtyInput('${id}', this.value)" />
            <button class="qty-btn" onclick="adjustPreQty('${id}', 1)">+</button>
          </div>
          <button class="add-btn" onclick="addToCart('${id}', Number(document.getElementById('preQty_${id}').value))">
            Add to Cart
          </button>
        `}
      </div>
    `;
    grid.appendChild(card);
  });
}

function renderProductTable() {
  const tbody = document.querySelector("#productTable tbody");
  if (!tbody) return;
  
  tbody.innerHTML = "";
  
  const filteredProducts = getFilteredProducts();
  
  filteredProducts.forEach(product => {
    const id = product.Product_ID ?? product.product_id ?? product.id;
    const name = product.Name ?? product.name ?? `#${id}`;
    const stock = product.stock ?? product.quantity ?? product.current_stock ?? 0;
    const defaultPrice = product.price ?? product.Purchase_price ?? 0;
    
    const isInCart = selectedProducts[id];
    
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${name}</td>
      <td><span class="${stock < 10 ? 'low' : ''}">${stock}</span></td>
      <td>₱${Number(defaultPrice).toFixed(2)}</td>
      <td>
        ${isInCart ? `
          <button class="add-to-cart-btn in-cart" onclick="removeFromCart('${id}')">Remove</button>
        ` : `
          <button class="add-to-cart-btn" onclick="addToCart('${id}', 1)">Add</button>
        `}
      </td>
    `;
    tbody.appendChild(tr);
  });
}

function getFilteredProducts() {
  const search = (document.getElementById("productSearch").value || "").toLowerCase();
  return products.filter(product => {
    const name = (product.Name ?? product.name ?? "").toLowerCase();
    return name.includes(search);
  });
}

function adjustPreQty(productId, delta) {
  const qtyEl = document.getElementById(`preQty_${productId}`);
  if (qtyEl) {
    const current = Number(qtyEl.value) || 1;
    const newQty = Math.max(1, current + delta);
    qtyEl.value = newQty;
  }
}

function onPreQtyInput(productId, value) {
  const qty = Math.max(1, Number(value) || 1);
  const input = document.getElementById(`preQty_${productId}`);
  if (input) input.value = qty;
}

function filterProducts() {
  renderProducts();
}

function selectAllProducts() {
  getFilteredProducts().forEach(product => {
    const id = product.Product_ID ?? product.product_id ?? product.id;
    if (!selectedProducts[id]) {
      addToCart(id, 1);
    }
  });
}

function clearAllProducts() {
  clearCart();
}

async function loadProducts(supplierId) {
  const statusEl = document.getElementById("productLoadStatus");
  statusEl.textContent = "Loading products...";
  try {
    const url = supplierId ? `${API_BASE}/supplier_product.php?supplier_id=${encodeURIComponent(supplierId)}` : `${API_BASE}/product_list.php`;
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) { 
      const body = await res.text().catch(() => "");
      throw new Error(`HTTP ${res.status} @ ${url} ${body ? `- ${body}` : ""}`);
    }
    const data = await res.json();

    let list = [];
    if (data.success && Array.isArray(data.products)) {
      list = data.products;
    } else if (Array.isArray(data)) {
      list = data; // raw array fallback
    }

    // Filter out inactive / soft-deleted if those flags exist
    const uniqueProducts = new Map();
    list.filter(p => {
      const active = (p.active === undefined) ? true : !!p.active;
      const deleted = (p.deleted === undefined) ? false : !!p.deleted;
      return active && !deleted;
    }).forEach(product => {
      const id = product.Product_ID ?? product.product_id ?? product.id;
      if (!uniqueProducts.has(id)) {
        uniqueProducts.set(id, product);
      }
    });

    products = Array.from(uniqueProducts.values());
    renderProducts();
    statusEl.textContent = `${products.length} products loaded`;
  } catch (err) {
    document.getElementById("productLoadStatus").textContent =
      `Failed to load products (${err.message})`;
  }
}

// ========= SUPPLIERS =========
async function loadSuppliers() {
  const sel = document.getElementById("supplierSelect");
  const sel2 = document.getElementById("supplierSelectStep2");
  if (sel) sel.innerHTML = '<option value="">-- Select Supplier --</option>';
  if (sel2) sel2.innerHTML = '<option value="">-- All Suppliers --</option>';
  try {
    const res = await fetch(`${API_BASE}/list_supplier.php`, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();

    let list = [];
    if (data.success && Array.isArray(data.suppliers)) {
      list = data.suppliers;
    } else if (Array.isArray(data)) {
      list = data; // raw array fallback
    }

    list
      .filter(s => {
        const active = (s.active === undefined) ? true : !!s.active;
        const deleted = (s.deleted === undefined) ? false : !!s.deleted;
        return active && !deleted;
      })
      .forEach(s => {
        const id = s.Supplier_ID ?? s.supplier_id ?? s.id;
        const name = s.Name ?? s.name ?? `#${id}`;
        const opt = document.createElement("option");
        opt.value = id;
        opt.textContent = name;
        if (sel) sel.appendChild(opt);
        if (sel2) {
          const o2 = document.createElement("option");
          o2.value = id;
          o2.textContent = name;
          sel2.appendChild(o2);
        }
      });
  } catch (err) {
    const msg = `Failed to load suppliers (${err.message}). Check ${API_BASE}/list_supplier.php`;
    console.error(msg);
    if (sel) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = msg;
      sel.appendChild(opt);
    }
  }
}

// ========= PO LIST + PAGINATION =========
function changePageSize() {
  pageSize = Number(document.getElementById("pageSize").value) || 10;
  if (usingServerPaging) {
    loadPOs(1); // reload from server with new page size
  } else {
    poCurrentPage = 1;
    renderPOs();
  }
}

async function loadPOs(page = 1) {
  const statusEl = document.getElementById("poLoadStatus");
  statusEl.textContent = "Loading purchase orders...";
  usingServerPaging = false;
  try {
    // Try server-side pagination if supported
    const res = await fetch(`${API_BASE}/list_purchase_order.php?page=${page}&limit=${pageSize}`, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();

    // Case A: server returns { success, records, totalPages, page }
    if (data && data.success && Array.isArray(data.records)) {
      usingServerPaging = true;
      const uniquePOs = new Map();
      data.records.forEach(po => {
        const id = po.PO_ID ?? po.po_id ?? po.id;
        if (!uniquePOs.has(id)) {
          uniquePOs.set(id, po);
        }
      });
      poRecords = Array.from(uniquePOs.values());
      poTotalPages = Number(data.totalPages) || 1;
      poCurrentPage = Number(data.page) || page;
    } else {
      // Case B: legacy endpoint { success, pos: [...] } (no server paging)
      const pos = Array.isArray(data.pos) ? data.pos : (Array.isArray(data) ? data : []);
      const uniquePOs = new Map();
      pos.forEach(po => {
        const id = po.PO_ID ?? po.po_id ?? po.id;
        if (!uniquePOs.has(id)) {
          uniquePOs.set(id, po);
        }
      });
      poRecords = Array.from(uniquePOs.values());
      usingServerPaging = false;
      poCurrentPage = 1; // we paginate client-side
    }
    renderPOs();
    if (usingServerPaging) {
      statusEl.textContent = `Page ${poCurrentPage} of ${poTotalPages}`;
    } else {
      statusEl.textContent = `${poRecords.length} records (client-side pagination)`;
    }
  } catch (err) {
    statusEl.textContent = `Failed to load POs (${err.message}). Check ${API_BASE}/list_purchase_order.php`;
    console.error(err);
  }
}

function renderPOs() {
  const tbody = document.querySelector("#poTable tbody");
  tbody.innerHTML = "";

  // Filter by search & status (client-side)
  const q = (document.getElementById("poSearch").value || "").toLowerCase();
  const statusFilter = document.getElementById("statusFilter").value;

  let rows = poRecords.filter(po => {
    const supplier_name = (po.suppliers_involved || po.supplier_name || po.Supplier_name || "");
    const status = po.Status ?? "";
    const idStr = String(po.PO_ID ?? "");
    const matchQ = !q || supplier_name.toLowerCase().includes(q) || idStr.includes(q);
    const matchStatus = !statusFilter || status === statusFilter;
    return matchQ && matchStatus;
  });

  // Client-side pagination if server didn't provide
  let totalPagesLocal = 1;
  let pageData = rows;
  if (!usingServerPaging) {
    totalPagesLocal = Math.max(1, Math.ceil(rows.length / pageSize));
    if (poCurrentPage > totalPagesLocal) poCurrentPage = totalPagesLocal;
    const start = (poCurrentPage - 1) * pageSize;
    pageData = rows.slice(start, start + pageSize);
  }

  pageData.forEach(po => {
    const tagClass = (po.Status || "").toLowerCase();
    const total = po.Total_amount ?? 0;
    const itemCount = po.item_count ?? po.Items ?? po.items ?? 0;
    const supplier = (po.suppliers_involved || po.supplier_name || po.Supplier_name || "-");
    const orderDate = po.Order_date ?? po.order_date ?? "-";
    const expectedDate = po.Expected_date ?? po.expected_date ?? "Not specified";
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${po.PO_ID}</td>
      <td>${supplier}</td>
      <td>${orderDate}</td>
      <td>${expectedDate}</td>
      <td><span class="tag ${tagClass}">${po.Status ?? "-"}</span></td>
      <td>₱${total}</td>
      <td>${itemCount}</td>
      <td><button onclick="viewPO(${po.PO_ID})">View</button></td>
    `;
    tbody.appendChild(tr);
  });

  // Render pager
  const pager = document.getElementById("poPagination");
  pager.innerHTML = "";
  const totalPages = usingServerPaging ? poTotalPages : totalPagesLocal;

  const prevBtn = document.createElement("button");
  prevBtn.textContent = "Prev";
  prevBtn.disabled = poCurrentPage <= 1;
  prevBtn.onclick = () => {
    if (usingServerPaging) loadPOs(poCurrentPage - 1);
    else { poCurrentPage--; renderPOs(); }
  };
  pager.appendChild(prevBtn);

  // show up to 7 buttons around current
  const maxButtons = 7;
  let start = Math.max(1, poCurrentPage - Math.floor(maxButtons / 2));
  let end = Math.min(totalPages, start + maxButtons - 1);
  if (end - start + 1 < maxButtons) start = Math.max(1, end - maxButtons + 1);

  for (let i = start; i <= end; i++) {
    const b = document.createElement("button");
    b.textContent = i;
    if (i === poCurrentPage) b.classList.add("active");
    b.onclick = () => {
      if (usingServerPaging) loadPOs(i);
      else { poCurrentPage = i; renderPOs(); }
    };
    pager.appendChild(b);
  }

  const nextBtn = document.createElement("button");
  nextBtn.textContent = "Next";
  nextBtn.disabled = poCurrentPage >= totalPages;
  nextBtn.onclick = () => {
    if (usingServerPaging) loadPOs(poCurrentPage + 1);
    else { poCurrentPage++; renderPOs(); }
  };
  pager.appendChild(nextBtn);
}

// ========= PO DETAILS =========
async function viewPO(po_id) {
  try {
    const res = await fetch(`${API_BASE}/get_purchase_order.php?po_id=${encodeURIComponent(po_id)}`, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || "Unknown error");
    // Open modal
    const overlay = document.getElementById("poDetailsOverlay");
    overlay.style.display = "flex";
    document.getElementById("detailPOID").textContent = data.po.PO_ID;
    // Supplier header removed; suppliers will be shown per item
    document.getElementById("detailOrderDate").textContent = data.po.Order_date ?? "-";
    document.getElementById("detailExpectedDate").textContent = data.po.Expected_date ?? "Not specified";
    document.getElementById("detailStatus").textContent = data.po.Status ?? "-";
    document.getElementById("detailTotal").textContent = `₱${data.po.Total_amount ?? 0}`;

    const tbody = document.querySelector("#poItemsTable tbody");
    tbody.innerHTML = "";
    
    if (data.items && Array.isArray(data.items)) {
      data.items.forEach(it => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${it.product_name}</td>
          <td>${it.item_supplier_name || '-'}</td>
          <td>${it.Quantity}</td>
          <td>₱${it.Purchase_price}</td>
          <td>${it.current_stock}</td>
        `;
        tbody.appendChild(tr);
      });
    }
  } catch (err) {
    alert(`Failed to load PO details: ${err.message}`);
  }
}

function closePOModal() {
  const overlay = document.getElementById("poDetailsOverlay");
  overlay.style.display = "none";
}

// ========= CREATE PO (CALL API + ADD ITEMS) =========
async function submitPO() {
  const supplierSelectEl = document.getElementById("supplierSelect");
  const orderDateEl = document.getElementById("orderDate");
  const deliveryDateEl = document.getElementById("deliveryDate");
  const priorityEl = document.getElementById("priority");
  const notesEl = document.getElementById("notes");
  
  if (!orderDateEl) {
    console.error("Required form elements not found");
    return;
  }
  
  const supplier_id = supplierSelectEl ? supplierSelectEl.value : null;
  const order_date = orderDateEl.value;
  const expected_date = deliveryDateEl ? deliveryDateEl.value : null;
  const priority = priorityEl ? priorityEl.value : "Normal";
  const notes = notesEl ? notesEl.value : null;

  const respEl = document.getElementById("addPOResponse");
  if (respEl) {
    respEl.textContent = "";
    respEl.className = "muted";
  }

  // Supplier is optional now; determined from items if needed
  if (!order_date) { 
    if (respEl) {
      respEl.textContent = "Please set an order date."; 
      respEl.className = "error"; 
    }
    return; 
  }

  const items = Object.entries(selectedProducts);
  if (!items.length) { 
    if (respEl) {
      respEl.textContent = "Please add at least one product to cart."; 
      respEl.className = "error"; 
    }
    return; 
  }

  // Build payload for your existing add_purchase_order.php (supplier_id, order_date, expected_date, priority, notes)
  const payload = { 
    order_date, 
    expected_date,
    priority,
    notes 
  };

  try {
    // 1) Create PO
    const res = await fetch(`${API_BASE}/add_purchase_order.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success || !data.po_id) throw new Error(data.error || "Failed to create PO");

    const po_id = data.po_id;

    // 2) Add items
    // Group items by supplier to allow mixed-supplier PO addition
    // Backend still creates one PO; we simply add all items regardless of supplier
    for (const [pid, p] of items) {
      const itemPayload = {
        po_id: Number(po_id),
        product_id: Number(pid),
        quantity: Number(p.qty),
        purchase_price: Number(p.price)
      };
      const r = await fetch(`${API_BASE}/add_purchase_order_item.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(itemPayload)
      });
      // Even if one fails, we surface the error and stop
      const j = await r.json().catch(() => ({}));
      if (!j.success) throw new Error(j.error || `Failed to add item ${pid}`);
    }

    if (respEl) {
      respEl.textContent = `✅ Purchase Order #${po_id} created successfully with ${items.length} items!`;
      respEl.className = "success";
    }
    resetForm();
    // Refresh list; if server paging, reload current displayed page
    if (usingServerPaging) loadPOs(poCurrentPage);
    else loadPOs(1);
  } catch (err) {
    if (respEl) {
      respEl.textContent = `❌ Create failed: ${err.message}`;
      respEl.className = "error";
    }
  }
}

// ========= INIT =========
function init() {
  // Pre-select today's date
  const today = new Date();
  const orderDateEl = document.getElementById("orderDate");
  if (orderDateEl) {
    orderDateEl.value = today.toISOString().slice(0, 10);
  }

  loadSuppliers();
  loadProducts();
  loadPOs(1);

  // Reload products when supplier changes (fetch supplier-specific products when supported)
  // Re-enable Step 1 supplier linkage if present
  const supplierSelectEl = document.getElementById("supplierSelect");
  if (supplierSelectEl) {
    supplierSelectEl.addEventListener("change", function() {
      const supplierId = this.value ? Number(this.value) : undefined;
      const sel2 = document.getElementById("supplierSelectStep2");
      if (sel2) sel2.value = this.value || "";
      // Do NOT clear cart; just reload products list
      loadProducts(supplierId);
    });
  }

  const supplierSelectEl2 = document.getElementById("supplierSelectStep2");
  if (supplierSelectEl2) {
    supplierSelectEl2.addEventListener("change", function() {
      const supplierId = this.value ? Number(this.value) : undefined;
      const s1 = document.getElementById("supplierSelect");
      if (s1) s1.value = this.value || "";
      // Do NOT clear cart; just reload products list
      loadProducts(supplierId);
    });
  }

  // Close modal when clicking overlay
  const poDetailsOverlay = document.getElementById("poDetailsOverlay");
  if (poDetailsOverlay) {
    poDetailsOverlay.addEventListener("click", function(e) {
      if (e.target === this) closePOModal();
    });
  }
}

// Defer init until DOM is ready so target elements exist
document.addEventListener('DOMContentLoaded', init);