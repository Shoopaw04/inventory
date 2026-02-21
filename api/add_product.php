<?php
// Prevent any HTML error output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 
ob_start();

// 
header('Content-Type: application/json');

try {
    // 
    $databasePath = __DIR__ . '/Database.php';
    $productPath = __DIR__ . '/Product.php';

    if (!file_exists($databasePath)) {
        throw new Exception("Database class file not found at: " . $databasePath);
    }

    if (!file_exists($productPath)) {
        throw new Exception("Product class file not found at: " . $productPath);
    }

    //
    require_once $databasePath;
    require_once $productPath;
    require_once __DIR__ . '/Auth.php';

    // 
    $user = currentUser();
    if (!$user) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Check if user has Admin or Manager role
    if (!in_array($user['Role_name'], ['Admin', 'Manager'])) {
        ob_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied. Admin or Manager role required.']);
        exit;
    }

    // 
    $input = file_get_contents('php://input');

    if (!$input) {
        throw new Exception("No input data received");
    }

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    if (!$data) {
        throw new Exception("Empty input data");
    }

    // Basic validation
    if (empty($data['name']) || empty($data['category_name'])) {
        
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'name and category_name are required'
        ]);
        exit;
    }

    // 
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }

    if (!method_exists('Database', 'getInstance')) {
        throw new Exception("Database::getInstance() method not found");
    }

    // database connection
    $db = Database::getInstance()->getConnection();

    if (!$db) {
        throw new Exception("Failed to get database connection");
    }

    // Check if Product class exists
    if (!class_exists('Product')) {
        throw new Exception("Product class not found");
    }

    // 
    $product = new Product($db);

    // Check if the method exists
    if (!method_exists($product, 'createProductWithInventory')) {
        throw new Exception("Product::createProductWithInventory() method not found");
    }

    // Call the method 
    $result = $product->createProductWithInventory([
        'name' => $data['name'],
        'category_name' => $data['category_name'],
        'category_description' => $data['category_description'] ?? null,
        'retail_price' => $data['retail_price'] ?? 0.00,
        'description' => $data['description'] ?? null,
        'supplier_id' => $data['supplier_id'] ?? null,
        'expiration_date' => $data['expiration_date'] ?? null,
        'batch_number' => $data['batch_number'] ?? null,
        'reorder_level' => $data['reorder_level'] ?? 0,
        'unit_measure' => $data['unit_measure'] ?? null,
        'initial_stock' => $data['initial_stock'] ?? 0,
        'performed_by' => $user['User_ID'], // Use current logged-in user
        'terminal_id' => $data['terminal_id'] ?? null,
    ]);

    // Log audit trail for product creation
    if ($result['success']) {
        $productId = $result['product_id'];
        $productData = [
            'name' => $data['name'],
            'category_name' => $data['category_name'],
            'retail_price' => $data['retail_price'] ?? 0.00,
            'reorder_level' => $data['reorder_level'] ?? 0,
            'initial_stock' => $data['initial_stock'] ?? 0,
            'is_discontinued' => 0
        ];
        
        try {
            logAudit('Product', $productId, 'CREATE', null, $productData);
        } catch (Exception $e) {
            
            error_log("Audit logging failed for product creation: " . $e->getMessage());
        }
    }

    // 
    ob_clean();

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    
    ob_clean();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename(__FILE__),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    // 
    ob_clean();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $e->getMessage(),
        'file' => basename(__FILE__),
        'line' => $e->getLine()
    ]);
}


ob_end_flush();
?>