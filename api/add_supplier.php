<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$name = trim($data['name'] ?? '');
$contact = trim($data['contact_info'] ?? '');
$address = trim($data['address'] ?? '');

if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Supplier name required']);
    exit();
}

try {
    //  PDO connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $stmt = $pdo->prepare("INSERT INTO Supplier (Name, Contact_info, Address, is_active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$name, $contact, $address]);
    $newId = (int)$pdo->lastInsertId();
    
    // Log activity and audit
    logActivity('SUPPLIER_CREATED', "Created supplier: {$name}");
    logAudit('supplier', $newId, 'CREATE', null, [
        'Name' => $name,
        'Contact_info' => $contact,
        'Address' => $address,
        'is_active' => 1
    ]);
    
    echo json_encode(['success' => true, 'supplier_id' => $newId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>