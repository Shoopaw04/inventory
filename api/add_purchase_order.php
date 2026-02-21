<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $user = currentUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input || !isset($input['order_date'])) {
        throw new Exception("Missing required field: order_date");
    }

    $stmt = $db->prepare(
        "INSERT INTO Purchase_Order (Supplier_ID, Order_date, Expected_date, Priority, Notes, Status, Total_amount)
        VALUES (?, ?, ?, ?, ?, 'Pending', 0)"
    );
    $stmt->execute([
        $input['supplier_id'] ?? null,
        $input['order_date'],
        $input['expected_date'] ?? null,
        $input['priority'] ?? 'Normal',
        $input['notes'] ?? null
    ]);

    $po_id = $db->lastInsertId();
    
    // Log activity for purchase order creation
    logActivity('Created Purchase Order', 'Created purchase order #' . $po_id, null);

    echo json_encode(['success' => true, 'po_id' => $po_id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>