<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Inventory.php';

$product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
    exit;
}

try {
    $database = Database::getInstance();
    $pdo = $database->getConnection();

    $inventory = new Inventory($pdo);
    $stock = $inventory->getStock($product_id);

    echo json_encode([
        'success' => true,
        'product_id' => $product_id,
        'stock' => $stock
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}