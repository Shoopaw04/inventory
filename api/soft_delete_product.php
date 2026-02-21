<?php
// api/soft_delete_product.php - Soft delete/restore a product
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Allow Admin and Manager for activation/deactivation
    $actor = requireRole(['Admin','Manager']);

    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : $_POST;
    $productId = (int) $input['product_id'];
    $action = $input['action']; // 'delete' or 'restore'

    if ($productId <= 0 || !in_array($action, ['delete', 'restore'])) {
        throw new Exception('Invalid product ID or action');
    }

    $db = Database::getInstance()->getConnection();

    // Load current state before change
    $curStmt = $db->prepare("SELECT Product_ID, Name, COALESCE(Is_discontinued,0) AS Is_discontinued FROM product WHERE Product_ID = ?");
    $curStmt->execute([$productId]);
    $before = $curStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new Exception('Product not found');
    }

    $isDiscontinued = ($action === 'delete') ? 1 : 0;
    $stmt = $db->prepare("UPDATE product SET Is_discontinued = ? WHERE Product_ID = ?");
    $stmt->execute([$isDiscontinued, $productId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Product not found or no changes made');
    }

    $message = ($action === 'delete') ? 'Product marked as inactive' : 'Product restored';

    // Audit log (legacy/new schema handled inside logAudit)
    $after = [
        'Is_discontinued' => (int)$isDiscontinued
    ];
    $beforeState = [
        'Is_discontinued' => (int)$before['Is_discontinued']
    ];
    $actionType = ($action === 'delete') ? 'PRODUCT_INACTIVATE' : 'PRODUCT_RESTORE';
    logAudit('Product', (string)$productId, $actionType, $beforeState, $after, $message);

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>