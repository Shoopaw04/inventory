<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$supplier_id = (int) ($data['supplier_id'] ?? 0);

if ($supplier_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid supplier ID']);
    exit;
}

try {
    //
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Purchase_Order WHERE Supplier_ID = ?");
    $stmt->execute([$supplier_id]);
    $po_count = $stmt->fetchColumn();

    if ($po_count > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete supplier with existing purchase orders']);
        exit;
    }

    // 
    $beforeStmt = $pdo->prepare("SELECT Supplier_ID, Name, Contact_info, Address, is_active FROM Supplier WHERE Supplier_ID = ?");
    $beforeStmt->execute([$supplier_id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 
    $stmt = $pdo->prepare("UPDATE Supplier SET is_active = 0 WHERE Supplier_ID = ?");
    $success = $stmt->execute([$supplier_id]);

    if ($success) {
        // Audit log
        logAudit('supplier', $supplier_id, 'DEACTIVATE', $before, ['is_active' => 0]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete supplier']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
