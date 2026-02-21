<?php
// File: api/update_supplier.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$supplier_id = (int) ($data['supplier_id'] ?? 0);
$name = trim($data['name'] ?? '');
$contact_info = trim($data['contact_info'] ?? '');
$address = trim($data['address'] ?? '');

if ($supplier_id <= 0 || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Supplier ID and name are required']);
    exit;
}

try {
    // Fetch before state for audit
    $beforeStmt = $pdo->prepare("SELECT Supplier_ID, Name, Contact_info, Address, is_active FROM Supplier WHERE Supplier_ID = ?");
    $beforeStmt->execute([$supplier_id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare("UPDATE Supplier SET Name = ?, Contact_info = ?, Address = ? WHERE Supplier_ID = ?");
    $success = $stmt->execute([$name, $contact_info, $address, $supplier_id]);

    if ($success) {
        // Audit log
        logAudit('supplier', $supplier_id, 'UPDATE', $before, [
            'Supplier_ID' => $supplier_id,
            'Name' => $name,
            'Contact_info' => $contact_info,
            'Address' => $address
        ]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update supplier']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>