<?php
header('Content-Type: application/json');
error_reporting(0);

try {
    require_once __DIR__ . '/Database.php';

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['supplier_id']) || !isset($input['order_date'])) {
        throw new Exception('Supplier ID and order date are required');
    }

    //  PDO connection 
    $database = Database::getInstance();
    $pdo = $database->getConnection();

    // Verify supplier exists
    $supplierCheck = $pdo->prepare("SELECT name FROM Suppliers WHERE supplier_id = ?");
    $supplierCheck->execute([$input['supplier_id']]);
    $supplier = $supplierCheck->fetch();

    if (!$supplier) {
        throw new Exception('Invalid supplier selected');
    }

    // Create Purchase Order table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Purchase_Order (
            po_id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            order_date DATE NOT NULL,
            status ENUM('Pending', 'Partial', 'Completed', 'Cancelled') DEFAULT 'Pending',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES Suppliers(supplier_id),
            INDEX idx_po_supplier (supplier_id),
            INDEX idx_po_date (order_date),
            INDEX idx_po_status (status)
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO Purchase_Order (supplier_id, supplier_name, order_date, notes) 
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $input['supplier_id'],
        $supplier['name'],
        $input['order_date'],
        trim($input['notes'] ?? '')
    ]);

    $po_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'po_id' => $po_id,
        'message' => 'Purchase Order created successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>