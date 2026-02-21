<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Fetch only active suppliers with product count
    $stmt = $pdo->prepare("
        SELECT 
            s.Supplier_ID, 
            s.Name, 
            s.Contact_info, 
            s.Address,
            COUNT(DISTINCT p.Product_ID) as product_count
        FROM Supplier s
        LEFT JOIN Product p ON p.Supplier_ID = s.Supplier_ID AND COALESCE(p.Is_discontinued, 0) = 0
        WHERE s.is_active = 1 
        GROUP BY s.Supplier_ID, s.Name, s.Contact_info, s.Address
        ORDER BY s.Name ASC
    ");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total supplier count
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM Supplier WHERE is_active = 1");
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true, 
        'suppliers' => $suppliers,
        'total_count' => (int)$totalCount
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
