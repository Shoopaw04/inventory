<?php
// api/update_reorder_level.php - Update reorder level for a product
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Only Admins can change reorder levels
    $actor = requireRole(['Admin']);

    $input = json_decode(file_get_contents('php://input'), true);
    $productId = (int) ($input['product_id'] ?? 0);
    $reorderLevel = (int) ($input['reorder_level'] ?? -1);

    if ($productId <= 0 || $reorderLevel < 0) {
        throw new Exception('Invalid product ID or reorder level');
    }

    $db = Database::getInstance()->getConnection();

    // Load current state for audit
    $beforeStmt = $db->prepare("SELECT Product_ID, Name, Reorder_Level FROM product WHERE Product_ID = ?");
    $beforeStmt->execute([$productId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new Exception('Product not found');
    }

    // If no change, return success with no update
    if ((int)$before['Reorder_Level'] === $reorderLevel) {
        echo json_encode([
            'success' => true,
            'message' => 'No changes were made',
            'product_id' => $productId,
            'reorder_level' => $reorderLevel
        ]);
        exit;
    }

    $stmt = $db->prepare("UPDATE product SET Reorder_Level = ? WHERE Product_ID = ?");
    $stmt->execute([$reorderLevel, $productId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('No changes made');
    }

    // Audit log
    logAudit(
        'Product',
        (string)$productId,
        'REORDER_LEVEL_UPDATE',
        ['Reorder_Level' => (int)$before['Reorder_Level']],
        ['Reorder_Level' => (int)$reorderLevel],
        'Updated by ' . ($actor['User_name'] ?? 'unknown')
    );

    echo json_encode([
        'success' => true,
        'message' => 'Reorder level updated successfully',
        'product_id' => $productId,
        'old_level' => (int)$before['Reorder_Level'],
        'new_level' => (int)$reorderLevel
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>