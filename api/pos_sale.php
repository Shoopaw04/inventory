<?php
// api/pos_sale.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/sale.php';
require_once __DIR__ . '/Auth.php';

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Only POST method allowed");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid JSON input.");
    }

    // Validate required fields
    if (empty($input['user_id']) || !isset($input['items']) || !is_array($input['items'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'user_id and items[] are required',
            'required_format' => [
                'user_id' => 'integer',
                'terminal_id' => 'integer (optional, defaults to 1)',
                'payment' => 'string (optional, defaults to CASH)',
                'items' => [
                    [
                        'product_id' => 'integer',
                        'quantity' => 'integer',
                        'price' => 'float'
                    ]
                ]
            ]
        ]);
        exit;
    }

    // Validate items array
    if (empty($input['items'])) {
        throw new Exception("At least one item is required for sale");
    }

    foreach ($input['items'] as $index => $item) {
        if (!isset($item['product_id']) || !isset($item['quantity']) || !isset($item['price'])) {
            throw new Exception("Item at index {$index} is missing required fields (product_id, quantity, price)");
        }

        if (!is_numeric($item['product_id']) || !is_numeric($item['quantity']) || !is_numeric($item['price'])) {
            throw new Exception("Item at index {$index} has invalid numeric values");
        }

        if ((int) $item['quantity'] <= 0) {
            throw new Exception("Item at index {$index} has invalid quantity (must be > 0)");
        }

        if ((float) $item['price'] < 0) {
            throw new Exception("Item at index {$index} has invalid price (must be >= 0)");
        }
    }

    // Get database connection and create sale service
    $db = Database::getInstance()->getConnection();
    $saleService = new SaleService($db);

    // Process the sale
    $result = $saleService->createSale(
        (int) $input['user_id'],
        (int) ($input['terminal_id'] ?? 1),  // Default terminal_id = 1
        $input['items'],
        $input['payment'] ?? 'CASH'  // Default payment method
    );

    // Log successful sale
    error_log("Sale created successfully: Sale ID {$result['sale_id']}, Total: {$result['total']}");
    
    // Log activity
    $terminal_id = $input['terminal_id'] ?? null;
    $items_count = count($input['items']);
    logActivity('SALE_CREATED', "Sale #{$result['sale_id']} created with $items_count items, Total: ₱{$result['total']}", $terminal_id);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Sale processed successfully',
        'data' => [
            'sale_id' => $result['sale_id'],
            'total_amount' => $result['total'],
            'items_count' => count($input['items']),
            'payment_method' => $input['payment'] ?? 'CASH',
            'terminal_id' => (int) ($input['terminal_id'] ?? 1),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Log error for debugging
    error_log("POS Sale Error: " . $e->getMessage());

    // Determine appropriate HTTP status code
    $error_message = $e->getMessage();
    $status_code = 400;

    if (strpos($error_message, 'not found') !== false) {
        $status_code = 404;
    } elseif (strpos($error_message, 'Insufficient') !== false) {
        $status_code = 409; // Conflict - insufficient stock
    } elseif (strpos($error_message, 'Invalid') !== false) {
        $status_code = 400; // Bad request
    } else {
        $status_code = 500; // Internal server error
    }

    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'error' => $error_message,
        'error_code' => $status_code,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>