<?php
// api/transfer_stock.php - Transfer stock from warehouse to display

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Auth.php';

// Logging function
function logError($message)
{
    error_log("[TRANSFER_STOCK] " . $message);
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// FIXED: Capture input - handle both JSON and FormData properly
$input = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    logError("Content-Type: " . $contentType);

    // Check if it's JSON
    if (strpos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        logError("Raw JSON input: " . $rawInput);

        if (!empty($rawInput)) {
            $input = json_decode($rawInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                logError("JSON decode error: " . json_last_error_msg());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input: ' . json_last_error_msg()]);
                exit;
            }
        }
    } else {
        // Handle FormData/regular POST data
        $input = $_POST;
        logError("FormData input: " . print_r($_POST, true));
    }

    // FALLBACK: If input is still empty, try to get from both sources
    if (empty($input)) {
        logError("Primary input method failed, trying fallback");

        // Try $_POST first
        if (!empty($_POST)) {
            $input = $_POST;
            logError("Using $_POST as fallback: " . print_r($_POST, true));
        } else {
            // Try raw input as JSON
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $input = $decoded;
                    logError("Using JSON as fallback: " . print_r($input, true));
                }
            }
        }
    }
} else {
    // Allow GET for testing only
    $input = $_GET;
    logError("GET input: " . print_r($_GET, true));
}

// Log final input
logError("Final processed input: " . print_r($input, true));

// Validate input
$productId = (int) ($input['product_id'] ?? 0);
$transferQty = (int) ($input['quantity'] ?? 0);
$userId = (int) ($input['user_id'] ?? 1);

logError("Validated params - Product ID: $productId, Quantity: $transferQty, User ID: $userId");

if (!$productId || !$transferQty) {
    logError("Validation failed - Product ID: $productId, Quantity: $transferQty");
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid parameters.',
        'debug' => [
            'product_id' => $productId,
            'quantity' => $transferQty,
            'received_input' => $input
        ]
    ]);
    exit;
}

try {
    // Test database connection
    $db = Database::getInstance()->getConnection();

    if (!$db) {
        throw new Exception("Failed to get database connection");
    }

    logError("Database connection successful");

    $inventory = new Inventory($db);

    // Get current stock
    logError("Getting current stock for product $productId");
    $stock = $inventory->getTotalStock($productId);
    logError("Current stock: " . print_r($stock, true));

    if ($stock['warehouse_qty'] < $transferQty) {
        throw new Exception("Insufficient warehouse stock. Available: {$stock['warehouse_qty']}, Requested: {$transferQty}");
    }

    // Start transaction for data consistency
    $db->beginTransaction();

    try {
        // Reduce warehouse stock using the TRANSFER_OUT movement type
        logError("Adjusting warehouse stock (reducing by $transferQty)");
        $inventory->adjustStock(
            $productId,
            -$transferQty,
            null,
            'TRANSFER_OUT',
            $userId,
            'transfer_stock',
            0
        );

        // Manually update display stock (as per your original logic)
        logError("Updating display stock (increasing by $transferQty)");
        $stmt = $db->prepare("UPDATE product SET Display_stocks = Display_stocks + :qty WHERE Product_ID = :pid");
        $result = $stmt->execute([':qty' => $transferQty, ':pid' => $productId]);

        if (!$result) {
            throw new Exception("Failed to update display stock");
        }

        // Log the display stock increase movement
        logError("Logging display stock increase movement");
        logError("Movement type: TRANSFER_IN");
        logError("Quantity: $transferQty");
        logError("Product ID: $productId");
        logError("User ID: $userId");
        
        $stmt = $db->prepare("INSERT INTO stock_movements (
            Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, 
            Updated_at, Source_Table, Terminal_ID, Timestamp
        ) VALUES (
            :product_id, :movement_type, :quantity, :reference_id, :performed_by,
            NOW(), :source_table, :terminal_id, NOW()
        )");
        
        $params = [
            ':product_id' => $productId,
            ':movement_type' => 'TRANSFER_IN',
            ':quantity' => $transferQty,
            ':reference_id' => $productId,
            ':performed_by' => $userId,
            ':source_table' => 'transfer_stock',
            ':terminal_id' => 0
        ];
        
        logError("Parameters: " . print_r($params, true));
        
        $result = $stmt->execute($params);
        
        if (!$result) {
            logError("Failed to insert stock movement: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Failed to log stock movement");
        }
        
        logError("Stock movement logged successfully");

        // Audit log for transfer
        logAudit(
            'Inventory',
            (string)$productId,
            'TRANSFER_STOCK',
            ['Display_stocks_delta' => 0 - $transferQty],
            ['Display_stocks_delta' => +$transferQty],
            'transfer_stock endpoint'
        );

        // Commit transaction
        $db->commit();
        logError("Transaction committed successfully");

        $updatedStock = $inventory->getTotalStock($productId);
        logError("Updated stock: " . print_r($updatedStock, true));

        echo json_encode([
            'success' => true,
            'message' => 'Stock transferred successfully',
            'data' => $updatedStock
        ]);

    } catch (Exception $e) {
        // Rollback on error
        $db->rollback();
        logError("Transaction rolled back due to error");
        throw $e;
    }

} catch (Exception $e) {
    logError("Error occurred: " . $e->getMessage());
    logError("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>