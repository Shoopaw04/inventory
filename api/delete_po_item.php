<?php
// File: api/delete_po_item.php
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$item_id = (int) ($data['item_id'] ?? 0);

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid item ID']);
    exit;
}

try {
    // 
    $stmt = $pdo->prepare("SELECT PO_ID FROM Purchase_Order_Item WHERE POItem_ID = ?");
    $stmt->execute([$item_id]);
    $po_id = $stmt->fetchColumn();

    if (!$po_id) {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }

    // Delete the item
    $stmt = $pdo->prepare("DELETE FROM Purchase_Order_Item WHERE POItem_ID = ?");
    $success = $stmt->execute([$item_id]);

    if ($success) {
        //
        updatePOTotal($pdo, $po_id);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete item']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function updatePOTotal($pdo, $po_id)
{
    $stmt = $pdo->prepare("
        UPDATE Purchase_Order 
        SET Total_amount = (
            SELECT IFNULL(SUM(Quantity * Purchase_price), 0) 
            FROM Purchase_Order_Item 
            WHERE PO_ID = ?
        ) 
        WHERE PO_ID = ?
    ");
    $stmt->execute([$po_id, $po_id]);
}
?>