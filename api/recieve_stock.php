<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Inventory.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$po_id = (int) ($data['po_id'] ?? 0);
$user_id = (int) ($data['user_id'] ?? 0); // User receiving the stock

if ($po_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Valid PO ID required']);
    exit;
}

try {
    $database = Database::getInstance();
    $pdo = $database->getConnection();
    $inventory = new Inventory($pdo);

    $pdo->beginTransaction();

    // 1. Get all PO items
    $stmt = $pdo->prepare("
        SELECT poi.POItem_ID, poi.Product_ID, poi.Quantity, p.Supplier_ID
        FROM purchase_order_item poi
        JOIN product p ON poi.Product_ID = p.Product_ID
        WHERE poi.PO_ID = ?
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception("No items found for PO: $po_id");
    }

    // 2. Process each item
    foreach ($items as $item) {
        $product_id = (int) $item['Product_ID'];
        $quantity = (int) $item['Quantity'];
        $supplier_id = (int) $item['Supplier_ID'];

        // Insert stock-in record
        $received_date = $data['received_date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO stockin_inventory (
                Product_ID, Supplier_ID, Stock_in_Quantity, 
                Date_in, Approved_by, Remarks, Status, PO_ID
            ) VALUES (?, ?, ?, ?, ?, ?, 'Received', ?)
        ");
        $stmt->execute([
            $product_id,
            $supplier_id,
            $quantity,
            $received_date,
            (string)($data['approved_by'] ?? $user_id), // Use approved_by from data or fallback to user_id, convert to string
            $data['remarks'] ?? 'PO Receipt',
            $po_id
        ]);
        $stockin_id = $pdo->lastInsertId();

        // Adjust inventory
        $inventory->adjustStock(
            product_id: $product_id,
            delta: $quantity,
            reference_id: $stockin_id,
            movement_type: 'PURCHASE_RECEIPT',
            performed_by: $user_id,
            source_table: 'stockin_inventory',
            terminal_id: null
        );
    }

    // 3. Update PO status to completed
    $stmt = $pdo->prepare("UPDATE purchase_order SET Status = 'Completed' WHERE PO_ID = ?");
    $stmt->execute([$po_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'All items received and inventory updated',
        'po_id' => $po_id,
        'items_processed' => count($items)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>