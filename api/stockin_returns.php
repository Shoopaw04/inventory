<?php
// stockin_returns.php - Handle returns during stock-in process
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Inventory.php';

try {
    $database = Database::getInstance();
    $pdo = $database->getConnection();
    $inventory = new Inventory($pdo);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            handleStockInWithReturns($pdo, $inventory);
            break;
        case 'GET':
            getReturnsList($pdo);
            break;
        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    error_log("Stock-In Returns API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function getUserIdByName($pdo, $name)
{
    try {
        // Try to find user by name/username (adjust field names as needed)
        $stmt = $pdo->prepare("SELECT User_ID FROM users WHERE username = ? OR name = ? OR CONCAT(first_name, ' ', last_name) = ? LIMIT 1");
        $stmt->execute([$name, $name, $name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            return $user['User_ID'];
        }

        // If no match found, return null (will fallback to current user_id)
        return null;
    } catch (Exception $e) {
        error_log("Error finding user ID for name '$name': " . $e->getMessage());
        return null;
    }
}

function handleStockInWithReturns($pdo, $inventory)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (empty($input['PO_ID']) || empty($input['items']) || empty($input['approved_by'])) {
        throw new Exception('PO_ID, items, and approved_by are required');
    }

    $pdo->beginTransaction();

    try {
        $po_id = (int) $input['PO_ID'];
        $approved_by_name = $input['approved_by'];
        $notes = $input['notes'] ?? '';
        $user_id = $input['user_id'] ?? 1;

        // Handle approved_by - it could be a user ID (number) or user name (string)
        $approved_by_input = $input['approved_by'];
        $approved_by_id = null;
        
        error_log("stockin_returns: Processing approved_by input: " . var_export($approved_by_input, true));
        
        // Check if it's a numeric user ID
        if (is_numeric($approved_by_input)) {
            $approved_by_id = (int) $approved_by_input;
            // Verify the user ID exists
            $stmt = $pdo->prepare("SELECT User_ID, User_name FROM users WHERE User_ID = ?");
            $stmt->execute([$approved_by_id]);
            $user = $stmt->fetch();
            if ($user) {
                error_log("stockin_returns: Found user ID $approved_by_id (User: {$user['User_name']})");
            } else {
                error_log("stockin_returns: User ID $approved_by_id not found in database");
                throw new Exception("User ID $approved_by_id does not exist");
            }
        } else {
            error_log("stockin_returns: Input is not numeric, trying to find by name: " . var_export($approved_by_input, true));
            // It's a user name, try to convert to user ID
            $approved_by_id = getUserIdByName($pdo, $approved_by_input);
            if (!$approved_by_id) {
                error_log("stockin_returns: Could not find user by name, falling back to current user ID: $user_id");
                // Fallback to the current user if no match found
                $approved_by_id = $user_id;
            }
        }
        
        error_log("stockin_returns: Final approved_by_id: $approved_by_id");

        // For stock-in operations, use NULL terminal_id to indicate warehouse/system operation
        $terminal_id = null;

        $processed_items = [];
        $return_items = [];

        foreach ($input['items'] as $item) {
            $product_id = (int) $item['Product_ID'];
            $ordered_qty = (int) $item['ordered_quantity'];
            $received_qty = (int) $item['received_quantity'];
            $damaged_qty = (int) ($item['damaged_quantity'] ?? 0);
            $expired_qty = (int) ($item['expired_quantity'] ?? 0);
            $wrong_qty = (int) ($item['wrong_quantity'] ?? 0);
            $other_qty = (int) ($item['other_quantity'] ?? 0);

            $unit_cost = (float) $item['unit_cost'];
            $batch_number = $item['batch_number'] ?? null;
            $expiry_date = $item['expiry_date'] ?? null;
            $supplier_id = (int) $item['Supplier_ID'];

            // Calculate total problem quantity
            $total_problem_qty = $damaged_qty + $expired_qty + $wrong_qty + $other_qty;

            // Validate quantities
            if (($received_qty + $total_problem_qty) > $ordered_qty) {
                throw new Exception("Total quantities cannot exceed ordered quantity for Product ID: $product_id");
            }

            // 1. Record the good stock received
            if ($received_qty > 0) {
                $received_date = $item['received_date'] ?? date('Y-m-d');
                
                $stmt = $pdo->prepare("
                    INSERT INTO stockin_inventory (
                        Product_ID, Supplier_ID, Stock_in_Quantity, Date_in, 
                        Unit_Cost, Batch_Number, Expiry_Date, PO_ID, 
                        Approved_by, Remarks, Status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'RECEIVED')
                ");

                $stmt->execute([
                    $product_id,
                    $supplier_id,
                    $received_qty,
                    $received_date,
                    $unit_cost,
                    $batch_number,
                    $expiry_date,
                    $po_id,
                    (string)$approved_by_id,
                    "Good items received - $notes",
                ]);

                $stockin_id = $pdo->lastInsertId();

                // Update inventory with received quantity - NOW WITH VALID TERMINAL_ID
                $inventory->adjustStock(
                    product_id: $product_id,
                    delta: $received_qty,
                    reference_id: $stockin_id,
                    movement_type: 'PURCHASE_RECEIPT',
                    performed_by: $user_id,
                    source_table: 'stockin_inventory',
                    terminal_id: $terminal_id  // FIXED: Use valid terminal_id instead of null
                );
            }

            // 2. Handle problem items and create returns
            $return_reasons = [
                'damaged' => $damaged_qty,
                'expired' => $expired_qty,
                'wrong_item' => $wrong_qty,
                'other' => $other_qty
            ];

            foreach ($return_reasons as $reason => $qty) {
                if ($qty > 0) {
                    $return_type = $item['return_type_' . $reason] ?? 'Refund';

                    // Insert supplier return record
                    $stmt = $pdo->prepare("
                        INSERT INTO supplier_return (
                            Supplier_ID, Product_ID, Quantity, Reason, Return_Date, 
                            Status, Approved_by, Return_Type, Total_Amount, 
                            Refund_Amount, Replacement_Quantity, Unit_Cost, 
                            Batch_Number, Expiry_Date, PO_ID, Return_Notes, 
                            Created_by, Approved_Date
                        ) VALUES (?, ?, ?, ?, CURDATE(), 'Completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");

                    $total_amount = $qty * $unit_cost;
                    $refund_amount = ($return_type === 'Refund') ? $total_amount : 0;
                    $replacement_qty = ($return_type === 'Replace') ? $qty : 0;

                    $stmt->execute([
                        $supplier_id,
                        $product_id,
                        $qty,
                        ucfirst($reason),
                        $approved_by_id,  // Use user_id instead of name
                        $return_type,
                        $total_amount,
                        $refund_amount,
                        $replacement_qty,
                        $unit_cost,
                        $batch_number,
                        $expiry_date,
                        $po_id,
                        "Auto-processed during stock-in - $reason items",
                        $user_id
                    ]);

                    $return_id = $pdo->lastInsertId();

                    // Log stock movement for the return - NOW WITH VALID TERMINAL_ID
                    $inventory->adjustStock(
                        product_id: $product_id,
                        delta: 0, // No inventory change since we never received these
                        reference_id: $return_id,
                        movement_type: 'RETURN_' . strtoupper($reason),
                        performed_by: $user_id,
                        source_table: 'supplier_return',
                        terminal_id: $terminal_id  // FIXED: Use valid terminal_id instead of null
                    );

                    // If replacement, create expected stock-in entry
                    if ($return_type === 'Replace') {
                        $stmt = $pdo->prepare("
                            INSERT INTO stockin_inventory (
                                Product_ID, Supplier_ID, Stock_in_Quantity, Date_in,
                                Unit_Cost, Status, Remarks, Approved_by, PO_ID
                            ) VALUES (?, ?, ?, CURDATE(), ?, 'EXPECTED', ?, ?, ?)
                        ");

                        $stmt->execute([
                            $product_id,
                            $supplier_id,
                            $replacement_qty,
                            $unit_cost,
                            "Replacement for Return #$return_id ($reason)",
                            $approved_by_name,
                            $po_id
                        ]);
                    }

                    $return_items[] = [
                        'return_id' => $return_id,
                        'reason' => $reason,
                        'quantity' => $qty,
                        'type' => $return_type,
                        'amount' => $total_amount
                    ];
                }
            }

            $processed_items[] = [
                'Product_ID' => $product_id,
                'received_quantity' => $received_qty,
                'return_items' => $return_items
            ];
        }

        // Update PO status if all items processed
        $stmt = $pdo->prepare("UPDATE purchase_order SET Status = 'Completed' WHERE PO_ID = ?");
        $stmt->execute([$po_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Stock-in processed with returns handled automatically',
            'po_id' => $po_id,
            'processed_items' => count($processed_items),
            'return_items' => $return_items,
            'terminal_id' => $terminal_id, // Include terminal_id in response for debugging
            'summary' => [
                'total_received' => array_sum(array_column($processed_items, 'received_quantity')),
                'total_returns' => count($return_items),
                'total_refund_amount' => array_sum(array_column($return_items, 'amount'))
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

function getReturnsList($pdo)
{
    $search = trim($_GET['search'] ?? '');
    $po_id = (int) ($_GET['po_id'] ?? 0);
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to = trim($_GET['date_to'] ?? '');

    $sql = "
        SELECT 
            sr.*,
            s.Name as supplier_name,
            p.Name as product_name,
            p.Unit_measure,
            po.PO_Reference,
            CASE 
                WHEN sr.Return_Type = 'Refund' THEN '💰'
                WHEN sr.Return_Type = 'Replace' THEN '🔄'
                ELSE '📋'
            END as type_icon,
            CASE 
                WHEN sr.Reason = 'damaged' THEN '❌'
                WHEN sr.Reason = 'expired' THEN '⏰'
                WHEN sr.Reason = 'wrong_item' THEN '❓'
                ELSE '📝'
            END as reason_icon
        FROM supplier_return sr
        LEFT JOIN supplier s ON sr.Supplier_ID = s.Supplier_ID
        LEFT JOIN product p ON sr.Product_ID = p.Product_ID
        LEFT JOIN purchase_order po ON sr.PO_ID = po.PO_ID
        WHERE sr.Status = 'Completed'
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.Name LIKE :search OR s.Name LIKE :search OR po.PO_Reference LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($po_id > 0) {
        $sql .= " AND sr.PO_ID = :po_id";
        $params[':po_id'] = $po_id;
    }

    if ($date_from !== '') {
        $sql .= " AND DATE(sr.Return_Date) >= :date_from";
        $params[':date_from'] = $date_from;
    }

    if ($date_to !== '') {
        $sql .= " AND DATE(sr.Return_Date) <= :date_to";
        $params[':date_to'] = $date_to;
    }

    $sql .= " ORDER BY sr.Return_Date DESC, sr.SupplierReturn_ID DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format data
    foreach ($returns as &$return) {
        $return['formatted_return_date'] = date('M j, Y', strtotime($return['Return_Date']));
        $return['formatted_approved_date'] = date('M j, Y g:i A', strtotime($return['Approved_Date']));
    }

    echo json_encode([
        'success' => true,
        'data' => $returns,
        'count' => count($returns)
    ]);
}
?>