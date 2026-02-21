<?php
header('Content-Type: application/json');
error_reporting(0);

try {
    require_once __DIR__ . '/Database.php';
    require_once __DIR__ . '/Inventory.php';

    $database = Database::getInstance();
    $pdo = $database->getConnection();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Input validation
    $po_id = (int) ($data['po_id'] ?? 0);
    $status = trim($data['status'] ?? '');
    $valid_statuses = ['Pending', 'Completed', 'Cancelled', 'Partial'];

    if ($po_id <= 0)
        throw new Exception('Invalid PO ID');
    if (!in_array($status, $valid_statuses)) {
        throw new Exception('Invalid status. Valid options: ' . implode(', ', $valid_statuses));
    }

    // Check PO existence
    $stmt = $pdo->prepare("SELECT * FROM purchase_order WHERE PO_ID = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch();

    if (!$po)
        throw new Exception('Purchase Order not found');

    // Prevent invalid status changes
    if ($po['Status'] === 'Completed' && $status !== 'Completed') {
        throw new Exception('Cannot modify completed PO');
    }

    // Handle cancellation logic
    if ($status === 'Cancelled') {
        // Check if any stock was received
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stockin_inventory WHERE PO_ID = ?");
        $stmt->execute([$po_id]);
        $received_count = $stmt->fetchColumn();

        if ($received_count > 0) {
            throw new Exception('Cannot cancel PO with received stock');
        }
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE purchase_order SET Status = ? WHERE PO_ID = ?");
    $stmt->execute([$status, $po_id]);

    echo json_encode([
        'success' => true,
        'po_id' => $po_id,
        'new_status' => $status
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>