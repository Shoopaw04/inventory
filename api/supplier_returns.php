<?php
// supplier_returns.php - Comprehensive Supplier Returns Management API
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
        case 'GET':
            handleGetRequests($pdo);
            break;
        case 'POST':
            handleCreateReturn($pdo, $inventory);
            break;
        case 'PUT':
            handleUpdateReturn($pdo, $inventory);
            break;
        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    error_log("Supplier Returns API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function handleGetRequests($pdo)
{
    // Check if requesting single return details
    if (isset($_GET['return_id'])) {
        $stmt = $pdo->prepare("
            SELECT sr.*, s.Name as supplier_name, p.Name as product_name, p.Unit_measure
            FROM supplier_return sr
            LEFT JOIN supplier s ON sr.Supplier_ID = s.Supplier_ID
            LEFT JOIN product p ON sr.Product_ID = p.Product_ID
            WHERE sr.SupplierReturn_ID = ?
        ");
        $stmt->execute([$_GET['return_id']]);
        $return = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $return
        ]);
        return;
    }

    // Check if requesting dropdowns/reference data
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'dropdowns':
                getDropdownData($pdo);
                return;
            case 'summary':
                getReturnsSummary($pdo);
                return;
        }
    }

    // Main returns listing with filters
    $search = trim($_GET['search'] ?? '');
    $supplier_id = (int) ($_GET['supplier_id'] ?? 0);
    $status = trim($_GET['status'] ?? '');
    $return_type = trim($_GET['return_type'] ?? '');
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to = trim($_GET['date_to'] ?? '');
    $po_id = (int) ($_GET['po_id'] ?? 0);
    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 50)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    // Build query with filters
    $sql = "
        SELECT 
            sr.*,
            s.Name as supplier_name,
            p.Name as product_name,
            p.Unit_measure,
            po.PO_Reference,
            CASE 
                WHEN sr.Status = 'Pending' THEN ''
                WHEN sr.Status = 'Approved' THEN ''
                WHEN sr.Status = 'Rejected' THEN ''
                WHEN sr.Status = 'Processing' THEN ''
                WHEN sr.Status = 'Completed' THEN ''
                WHEN sr.Status = 'Cancelled' THEN ''
                ELSE '❓'
            END as status_icon,
            CASE 
                WHEN sr.Return_Type = 'Refund' THEN '💰'
                WHEN sr.Return_Type = 'Replace' THEN '🔄'
                ELSE '📋'
            END as type_icon
        FROM supplier_return sr
        LEFT JOIN supplier s ON sr.Supplier_ID = s.Supplier_ID
        LEFT JOIN product p ON sr.Product_ID = p.Product_ID
        LEFT JOIN purchase_order po ON sr.PO_ID = po.PO_ID
        WHERE 1=1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.Name LIKE :search OR s.Name LIKE :search OR sr.Reason LIKE :search OR po.PO_Reference LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($supplier_id > 0) {
        $sql .= " AND sr.Supplier_ID = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }

    if ($status !== '') {
        $sql .= " AND sr.Status = :status";
        $params[':status'] = $status;
    }

    if ($return_type !== '') {
        $sql .= " AND sr.Return_Type = :return_type";
        $params[':return_type'] = $return_type;
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

    // Get total count for pagination
    $countSql = str_replace('sr.*,', 'COUNT(*) as total_count,', $sql);
    $countSql = preg_replace('/SELECT.*?FROM/', 'SELECT COUNT(*) as total_count FROM', $countSql);

    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total_count'];

    // Add ordering and pagination
    $sql .= " ORDER BY sr.Return_Date DESC, sr.SupplierReturn_ID DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format data
    foreach ($returns as &$return) {
        $return['formatted_return_date'] = date('M j, Y', strtotime($return['Return_Date']));
        $return['formatted_approved_date'] = $return['Approved_Date'] ? date('M j, Y g:i A', strtotime($return['Approved_Date'])) : null;
        $return['total_calculated'] = calculateReturnTotal($return);
    }

    echo json_encode([
        'success' => true,
        'data' => $returns,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($totalCount / $limit),
            'total_count' => $totalCount,
            'limit' => $limit
        ]
    ]);
}

function handleCreateReturn($pdo, $inventory)
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Validation
    $required = ['Supplier_ID', 'Product_ID', 'Quantity', 'Return_Type', 'Reason'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field '$field' is required");
        }
    }

    $pdo->beginTransaction();

    try {
        // Get product info for calculations
        $stmt = $pdo->prepare("SELECT * FROM product WHERE Product_ID = ?");
        $stmt->execute([$input['Product_ID']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception('Product not found');
        }

        // Calculate amounts based on return type
        $unit_cost = (float) ($input['Unit_Cost'] ?? $product['Retail_Price'] ?? 0);
        $quantity = (int) $input['Quantity'];
        $total_amount = $unit_cost * $quantity;

        $refund_amount = 0;
        $replacement_quantity = 0;

        if ($input['Return_Type'] === 'Refund') {
            $refund_amount = $total_amount;
        } else if ($input['Return_Type'] === 'Replace') {
            $replacement_quantity = $quantity;
        }

        // Insert supplier return record
        $stmt = $pdo->prepare("
            INSERT INTO supplier_return (
                Supplier_ID, Product_ID, Quantity, Reason, Return_Date, Status, 
                Return_Type, Total_Amount, PO_ID, Refund_Amount, Replacement_Quantity, 
                Unit_Cost, Batch_Number, Expiry_Date, Return_Notes, Created_by
            ) VALUES (?, ?, ?, ?, CURDATE(), 'Pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $input['Supplier_ID'],
            $input['Product_ID'],
            $quantity,
            $input['Reason'],
            $input['Return_Type'],
            $total_amount,
            $input['PO_ID'] ?? null,
            $refund_amount,
            $replacement_quantity,
            $unit_cost,
            $input['Batch_Number'] ?? null,
            $input['Expiry_Date'] ?? null,
            $input['Return_Notes'] ?? null,
            $input['Created_by'] ?? 1 // Default user
        ]);

        $return_id = $pdo->lastInsertId();

        // Get terminal_id from input or use default
        $terminal_id = $input['Terminal_ID'] ?? 1;

        // Verify terminal exists, create if needed
        $terminalCheck = $pdo->prepare("SELECT Terminal_ID FROM terminal WHERE Terminal_ID = ?");
        $terminalCheck->execute([$terminal_id]);
        if (!$terminalCheck->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO terminal (Terminal_ID, Terminal_Name, Location) VALUES (1, 'Main Terminal', 'Primary Location')")->execute();
            $terminal_id = 1;
        }

        // Log stock movement for the return (removal from inventory)
        $movementStmt = $pdo->prepare("
            INSERT INTO stock_movements 
            (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Source_Table, Terminal_ID, Updated_at, Timestamp) 
            VALUES (?, 'RETURN', ?, ?, ?, 'supplier_return', ?, NOW(), NOW())
        ");

        $movementStmt->execute([
            $input['Product_ID'],
            $quantity,
            $return_id,
            $input['Created_by'] ?? 1,
            $terminal_id
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Supplier return created successfully',
            'return_id' => $return_id,
            'total_amount' => $total_amount
        ]);

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

function handleUpdateReturn($pdo, $inventory)
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['SupplierReturn_ID'])) {
        throw new Exception('SupplierReturn_ID is required');
    }

    if (empty($input['action'])) {
        throw new Exception('Action is required (approve/reject/complete/start_processing/complete_with_stockin)');
    }

    $pdo->beginTransaction();

    try {
        // Get current return data
        $stmt = $pdo->prepare("SELECT * FROM supplier_return WHERE SupplierReturn_ID = ?");
        $stmt->execute([$input['SupplierReturn_ID']]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return) {
            throw new Exception('Supplier return not found');
        }

        $return_id = $return['SupplierReturn_ID'];
        $approved_by = $input['Approved_by'] ?? null;

        switch ($input['action']) {
            case 'approve':
                if ($return['Status'] !== 'Pending') {
                    throw new Exception('Only pending returns can be approved');
                }

                // Update status to approved
                $stmt = $pdo->prepare("
                    UPDATE supplier_return 
                    SET Status = 'Approved', Approved_by = ?, Approved_Date = NOW() 
                    WHERE SupplierReturn_ID = ?
                ");
                $stmt->execute([$approved_by, $return_id]);

                // Handle based on return type
                if ($return['Return_Type'] === 'Refund') {
                    // For refunds, mark as completed immediately
                    $stmt = $pdo->prepare("UPDATE supplier_return SET Status = 'Completed' WHERE SupplierReturn_ID = ?");
                    $stmt->execute([$return_id]);

                } else if ($return['Return_Type'] === 'Replace') {
                    // For replacements, create a new stock-in entry for when replacement arrives
                    $stmt = $pdo->prepare("
                        INSERT INTO stockin_inventory (
                            Product_ID, Supplier_ID, Stock_in_Quantity, Date_in, 
                            Unit_Cost, Status, Remarks, Approved_by, Batch_Number, Expiry_Date
                        ) VALUES (?, ?, ?, CURDATE(), ?, 'EXPECTED', ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $return['Product_ID'],
                        $return['Supplier_ID'],
                        $return['Replacement_Quantity'],
                        $return['Unit_Cost'],
                        "Replacement for Return #{$return_id}",
                        $approved_by,
                        $return['Batch_Number'],
                        $return['Expiry_Date']
                    ]);

                    $stockin_id = $pdo->lastInsertId();

                    // Get terminal_id
                    $terminal_id = $input['Terminal_ID'] ?? 1;

                    // Log the expected replacement
                    $movementStmt = $pdo->prepare("
                        INSERT INTO stock_movements 
                        (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Source_Table, Terminal_ID, Updated_at, Timestamp) 
                        VALUES (?, 'REPLACEMENT_EXPECTED', ?, ?, ?, 'stockin_inventory', ?, NOW(), NOW())
                    ");

                    $movementStmt->execute([
                        $return['Product_ID'],
                        0, // No immediate inventory change
                        $stockin_id,
                        $approved_by ?? 1,
                        $terminal_id
                    ]);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Return approved successfully',
                    'return_type' => $return['Return_Type']
                ]);
                break;

            case 'reject':
                if ($return['Status'] !== 'Pending') {
                    throw new Exception('Only pending returns can be rejected');
                }

                $stmt = $pdo->prepare("
                    UPDATE supplier_return 
                    SET Status = 'Rejected', Approved_by = ?, Approved_Date = NOW() 
                    WHERE SupplierReturn_ID = ?
                ");
                $stmt->execute([$approved_by, $return_id]);

                // Get terminal_id
                $terminal_id = $input['Terminal_ID'] ?? 1;

                // Reverse the stock movement (add back to inventory)
                $movementStmt = $pdo->prepare("
                    INSERT INTO stock_movements 
                    (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Source_Table, Terminal_ID, Updated_at, Timestamp) 
                    VALUES (?, 'ADJUSTMENT_IN', ?, ?, ?, 'supplier_return', ?, NOW(), NOW())
                ");

                $movementStmt->execute([
                    $return['Product_ID'],
                    $return['Quantity'],
                    $return_id,
                    $approved_by ?? 1,
                    $terminal_id
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Return rejected and inventory restored'
                ]);
                break;

            case 'start_processing':
                if ($return['Status'] !== 'Pending') {
                    throw new Exception('Only pending returns can be moved to processing');
                }

                $stmt = $pdo->prepare("
                    UPDATE supplier_return 
                    SET Status = 'Processing', Updated_at = NOW() 
                    WHERE SupplierReturn_ID = ?
                ");
                $stmt->execute([$return_id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Return moved to processing status'
                ]);
                break;

            case 'complete_with_stockin':
                if ($return['Status'] !== 'Processing') {
                    throw new Exception('Only processing returns can be completed with stockin');
                }

                $stmt = $pdo->prepare("
                    UPDATE supplier_return 
                    SET Status = 'Completed', Updated_at = NOW() 
                    WHERE SupplierReturn_ID = ?
                ");
                $stmt->execute([$return_id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Return completed with stockin integration',
                    'stockin_id' => $input['stockin_id'] ?? null
                ]);
                break;

            case 'complete':
                if (!in_array($return['Status'], ['Approved', 'Processing'])) {
                    throw new Exception('Only approved or processing returns can be completed');
                }

                $inventory_updated = false;

                // If it's a replacement, we need to process the actual stock-in
                if ($return['Return_Type'] === 'Replace') {
                    // Update status to Processing first
                    $stmt = $pdo->prepare("UPDATE supplier_return SET Status = 'Processing' WHERE SupplierReturn_ID = ?");
                    $stmt->execute([$return_id]);

                    // Find the expected stock-in entry we created during approval
                    $stmt = $pdo->prepare("
                        SELECT * FROM stockin_inventory 
                        WHERE Product_ID = ? AND Supplier_ID = ? AND Status = 'EXPECTED' 
                        AND Remarks LIKE ? 
                        ORDER BY StockIn_ID DESC LIMIT 1
                    ");
                    $stmt->execute([
                        $return['Product_ID'],
                        $return['Supplier_ID'],
                        "%Replacement for Return #{$return_id}%"
                    ]);
                    $expected_stockin = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($expected_stockin) {
                        // Get terminal_id
                        $terminal_id = $input['Terminal_ID'] ?? 1;

                        // Process the replacement through inventory system
                        $movementStmt = $pdo->prepare("
                            INSERT INTO stock_movements 
                            (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Source_Table, Terminal_ID, Updated_at, Timestamp) 
                            VALUES (?, 'REPLACEMENT_RECEIVED', ?, ?, ?, 'stockin_inventory', ?, NOW(), NOW())
                        ");

                        $movementStmt->execute([
                            $return['Product_ID'],
                            $return['Replacement_Quantity'], // Add to inventory
                            $expected_stockin['StockIn_ID'],
                            $approved_by ?? 1,
                            $terminal_id
                        ]);

                        // Update the stockin_inventory record to COMPLETED
                        $stmt = $pdo->prepare("UPDATE stockin_inventory SET Status = 'COMPLETED' WHERE StockIn_ID = ?");
                        $stmt->execute([$expected_stockin['StockIn_ID']]);

                        $inventory_updated = true;
                    }
                }

                // Finally mark the return as completed
                $stmt = $pdo->prepare("UPDATE supplier_return SET Status = 'Completed' WHERE SupplierReturn_ID = ?");
                $stmt->execute([$return_id]);

                echo json_encode([
                    'success' => true,
                    'message' => $return['Return_Type'] === 'Replace' ?
                        'Replacement processed and added to inventory' :
                        'Return marked as completed',
                    'inventory_updated' => $inventory_updated
                ]);
                break;

            default:
                throw new Exception('Invalid action');
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

function getDropdownData($pdo)
{
    // Get suppliers
    $stmt = $pdo->prepare("SELECT Supplier_ID, Name as supplier_name FROM supplier WHERE is_active = 1 ORDER BY Name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get products
    $stmt = $pdo->prepare("SELECT Product_ID, Name, Unit_measure, Retail_Price FROM product WHERE Is_discontinued = 0 ORDER BY Name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get purchase orders
    $stmt = $pdo->prepare("
        SELECT po.PO_ID, po.PO_Reference, s.Name as supplier_name, po.Order_date
        FROM purchase_order po 
        LEFT JOIN supplier s ON po.Supplier_ID = s.Supplier_ID
        WHERE po.Status IN ('Approved', 'Completed')
        ORDER BY po.Order_date DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'suppliers' => $suppliers,
        'products' => $products,
        'purchase_orders' => $purchase_orders
    ]);
}

function getReturnsSummary($pdo)
{
    $stmt = $pdo->prepare("
        SELECT 
            Status,
            Return_Type,
            COUNT(*) as count,
            SUM(Total_Amount) as total_amount,
            SUM(CASE WHEN Return_Type = 'Refund' THEN Refund_Amount ELSE 0 END) as total_refunds,
            SUM(CASE WHEN Return_Type = 'Replace' THEN Replacement_Quantity ELSE 0 END) as total_replacements
        FROM supplier_return 
        WHERE Return_Date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY Status, Return_Type
        ORDER BY Status, Return_Type
    ");
    $stmt->execute();
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => $summary
    ]);
}

function calculateReturnTotal($return)
{
    if ($return['Return_Type'] === 'Refund') {
        return $return['Refund_Amount'];
    } else if ($return['Return_Type'] === 'Replace') {
        return $return['Replacement_Quantity'] * $return['Unit_Cost'];
    }
    return $return['Total_Amount'];
}

?>