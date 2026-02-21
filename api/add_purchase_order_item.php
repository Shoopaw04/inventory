<?php
header('Content-Type: application/json');
error_reporting(0);

try {
    require_once __DIR__ . '/Database.php';

    // PDO connection 
    $database = Database::getInstance();
    $pdo = $database->getConnection();

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $po_id = (int) ($data['po_id'] ?? 0);
    $product_id = (int) ($data['product_id'] ?? 0);
    $quantity = (int) ($data['quantity'] ?? 0);
    $purchase_price = (float) ($data['purchase_price'] ?? 0.0);

    if ($po_id <= 0 || $product_id <= 0 || $quantity <= 0 || $purchase_price <= 0) {
        echo json_encode(['success' => false, 'error' => 'po_id, product_id, quantity and purchase_price are required and must be greater than 0']);
        exit;
    }

    // 
    $poCheck = $pdo->prepare("SELECT PO_ID FROM Purchase_Order WHERE PO_ID = ?");
    $poCheck->execute([$po_id]);
    if (!$poCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Purchase Order not found']);
        exit;
    }

    
    $productCheck = $pdo->prepare("SELECT Product_ID FROM Product WHERE Product_ID = ?");
    $productCheck->execute([$product_id]);
    if (!$productCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }

    $pdo->beginTransaction();

    // 
    $existingCheck = $pdo->prepare("SELECT PO_ID FROM Purchase_Order_Item WHERE PO_ID = ? AND Product_ID = ?");
    $existingCheck->execute([$po_id, $product_id]);

    if ($existingCheck->fetch()) {
        // 
        $stmt = $pdo->prepare("UPDATE Purchase_Order_Item SET Quantity = Quantity + ?, Purchase_price = ? WHERE PO_ID = ? AND Product_ID = ?");
        $stmt->execute([$quantity, $purchase_price, $po_id, $product_id]);
    } else {
        // 
        $stmt = $pdo->prepare("INSERT INTO Purchase_Order_Item (PO_ID, Product_ID, Quantity, Purchase_price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$po_id, $product_id, $quantity, $purchase_price]);
    }

    //
    $upd = $pdo->prepare("UPDATE Purchase_Order SET Total_amount = (
        SELECT IFNULL(SUM(Quantity * Purchase_price), 0) FROM Purchase_Order_Item WHERE PO_ID = ?
    ) WHERE PO_ID = ?");
    $upd->execute([$po_id, $po_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Item added successfully']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>