<?php
require_once __DIR__ . '/Database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $po_id = isset($_GET['po_id']) ? intval($_GET['po_id']) : 0;
    if ($po_id <= 0)
        throw new Exception("Valid PO ID required");

    $stmt = $db->prepare(
        "SELECT 
            po.PO_ID,
            po.PO_Reference,
            po.Order_date,
            po.Expected_date,
            po.Priority,
            po.Notes,
            po.Status,
            po.Total_amount,
            po.Supplier_ID,
            s.Name AS supplier_name,
            s.Contact_info
        FROM Purchase_Order po
        LEFT JOIN Supplier s ON po.Supplier_ID = s.Supplier_ID
        WHERE po.PO_ID = ?"
    );
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po)
        throw new Exception("Purchase Order not found");

    $itemsStmt = $db->prepare(
        "SELECT 
            poi.Product_ID,
            p.Name AS product_name,
            poi.Quantity,
            poi.Purchase_price,
            (poi.Quantity * poi.Purchase_price) AS subtotal,
            COALESCE(i.Quantity, 0) AS current_stock,
            sup.Supplier_ID AS item_supplier_id,
            sup.Name AS item_supplier_name
        FROM Purchase_Order_Item poi
        JOIN Product p ON poi.Product_ID = p.Product_ID
        LEFT JOIN Supplier sup ON p.Supplier_ID = sup.Supplier_ID
        LEFT JOIN Inventory i ON p.Product_ID = i.Product_ID
        WHERE poi.PO_ID = ?"
    );
    $itemsStmt->execute([$po_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    
    $suppliersInvolved = [];
    foreach ($items as $it) {
        if (!empty($it['item_supplier_name'])) {
            $key = $it['item_supplier_id'] ?: $it['item_supplier_name'];
            $suppliersInvolved[$key] = $it['item_supplier_name'];
        }
    }
    if ((empty($po['supplier_name']) || $po['supplier_name'] === '-') && !empty($suppliersInvolved)) {
        $po['supplier_name'] = implode(', ', array_values($suppliersInvolved));
    }

    echo json_encode(['success' => true, 'po' => $po, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>