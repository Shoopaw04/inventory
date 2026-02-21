<?php
// File: api/get_po_statistics.php
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    // 
    $stats = [];

    // Total POs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM Purchase_Order");
    $stats['total_pos'] = $stmt->fetchColumn();

    
    $stmt = $pdo->query("SELECT COUNT(*) as pending FROM Purchase_Order WHERE Status = 'Pending'");
    $stats['pending_pos'] = $stmt->fetchColumn();

    // Total value
    $stmt = $pdo->query("SELECT IFNULL(SUM(Total_amount), 0) as total_value FROM Purchase_Order");
    $stats['total_value'] = $stmt->fetchColumn();

    // Recent activity
    $stmt = $pdo->query("
        SELECT COUNT(*) as recent_pos 
        FROM Purchase_Order 
        WHERE Order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['recent_pos'] = $stmt->fetchColumn();

    // Top suppliers
    $stmt = $pdo->query("
        SELECT s.Name, COUNT(*) as po_count, IFNULL(SUM(po.Total_amount), 0) as total_value
        FROM Purchase_Order po
        JOIN Supplier s ON po.Supplier_ID = s.Supplier_ID
        GROUP BY s.Supplier_ID, s.Name
        ORDER BY po_count DESC
        LIMIT 5
    ");
    $stats['top_suppliers'] = $stmt->fetchAll();

    echo json_encode(['success' => true, 'stats' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>