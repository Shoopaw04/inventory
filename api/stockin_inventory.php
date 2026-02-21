<?php
// stockin_inventory.php - Stock In Inventory Management API

// Enhanced error handling and logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Log all requests for debugging
error_log("STOCKIN API - " . $_SERVER['REQUEST_METHOD'] . " request at " . date('Y-m-d H:i:s'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST body: " . file_get_contents('php://input'));
}

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

try {
    $db = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            http_response_code(405);
            outputJson(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("STOCKIN API ERROR: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    http_response_code(500);
    outputJson([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}

function outputJson($data)
{
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function handleGet($db)
{
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $supplier_id = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
    $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;

    $sql = "
        SELECT 
            si.Stockin_ID,
            si.Product_ID,
            p.Name as product_name,
            si.Supplier_ID,
            s.Name AS supplier_name,
            si.Stock_in_Quantity,
            si.Quantity_Ordered,
            si.Unit_Cost,
            si.Total_Cost,
            si.Batch_Number,
            si.Expiry_Date,
            si.Created_Date,
            si.Updated_Date,
            si.Date_in,
            si.Approved_by,
            COALESCE(u.User_name, si.Approved_by, 'System') as approved_by_name,
            COALESCE(r.Role_name, 'Unknown') as approved_by_role,
            si.Remarks,
            si.Status,
            si.PO_ID,
            CASE 
                WHEN si.Status = 'RECEIVED' THEN '✅'
                WHEN si.Status = 'PENDING' THEN '⏳'
                WHEN si.Status = 'PARTIAL' THEN '📦'
                WHEN si.Status = 'CANCELLED' THEN '❌'
                WHEN si.Status = 'EXPECTED' THEN '🔄'
                WHEN si.Status = 'COMPLETED' THEN '🎉'
                ELSE '❓'
            END as status_icon,
            CASE 
                WHEN si.Stock_in_Quantity >= si.Quantity_Ordered THEN 'COMPLETE'
                WHEN si.Stock_in_Quantity < si.Quantity_Ordered AND si.Stock_in_Quantity > 0 THEN 'PARTIAL'
                ELSE 'PENDING'
            END as delivery_status
        FROM stockin_inventory si
        JOIN Product p ON si.Product_ID = p.Product_ID
        LEFT JOIN Supplier s ON si.Supplier_ID = s.Supplier_ID
        LEFT JOIN users u ON si.Approved_by = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";
    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.Name LIKE :search OR s.Name LIKE :search OR si.Batch_Number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($supplier_id > 0) {
        $sql .= " AND si.Supplier_ID = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }

    if ($product_id > 0) {
        $sql .= " AND si.Product_ID = :product_id";
        $params[':product_id'] = $product_id;
    }

    if ($po_id > 0) {
        $sql .= " AND si.PO_ID = :po_id";
        $params[':po_id'] = $po_id;
    }

    if ($status !== '') {
        $sql .= " AND si.Status = :status";
        $params[':status'] = $status;
    }

    if ($date_from !== '') {
        $sql .= " AND si.Date_in >= :date_from";
        $params[':date_from'] = $date_from;
    }

    if ($date_to !== '') {
        $sql .= " AND si.Date_in <= :date_to";
        $params[':date_to'] = $date_to;
    }

    if ($limit <= 0)
        $limit = 100;
    $sql .= " ORDER BY si.Created_Date DESC, si.Stockin_ID DESC LIMIT :limit";

    // Debug: Log the final query
    error_log("STOCKIN GET: Final SQL: " . $sql);
    error_log("STOCKIN GET: Parameters: " . json_encode($params));

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $stockins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug: Log the query and results
    error_log("STOCKIN GET: Query executed successfully");
    error_log("STOCKIN GET: Found " . count($stockins) . " records");
    if (count($stockins) > 0) {
        error_log("STOCKIN GET: First record: " . json_encode($stockins[0]));
    }

    // Get suppliers for dropdown
    $supplierStmt = $db->prepare("SELECT Supplier_ID, Name AS supplier_name FROM Supplier ORDER BY Name");
    $supplierStmt->execute();
    $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get products for dropdown
    $productStmt = $db->prepare("SELECT Product_ID, Name FROM Product ORDER BY Name");
    $productStmt->execute();
    $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    outputJson([
        'success' => true,
        'data' => $stockins,
        'suppliers' => $suppliers,
        'products' => $products,
        'count' => count($stockins)
    ]);
}

function getUserIdFromInput($db, $approved_by_input) {
    error_log("getUserIdFromInput: Processing input: " . var_export($approved_by_input, true));
    
    // Simple fallback - if input is numeric and > 0, use it; otherwise use admin (1)
    if (is_numeric($approved_by_input) && $approved_by_input > 0) {
        error_log("getUserIdFromInput: Using provided user ID: $approved_by_input");
        return (string) $approved_by_input;
    }
    
    error_log("getUserIdFromInput: Using default admin user ID (1)");
    return '1';
}

function handlePost($db)
{
    $user = currentUser();
    if (!$user) { http_response_code(401); outputJson(['success'=>false,'error'=>'Unauthorized']); }
    $role = strtolower((string)($user['Role_name'] ?? ''));
    $isApprover = in_array($role, ['admin','manager'], true);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        error_log("STOCKIN POST ERROR: No valid JSON input received");
        throw new Exception('Invalid JSON input or empty request body');
    }

    error_log("STOCKIN POST: Received data: " . json_encode($input));
    
    // Debug: Check if PO_ID is present in the data
    foreach ($input as $index => $item) {
        error_log("STOCKIN POST: Item $index - PO_ID: " . ($item['PO_ID'] ?? 'NOT SET') . ", Date_in: " . ($item['Date_in'] ?? 'NOT SET') . ", received_date: " . ($item['received_date'] ?? 'NOT SET'));
    }

    // If single record, convert to array for uniform processing
    if (!isset($input[0])) {
        $input = [$input];
    }

    $db->beginTransaction();

    try {
        $created_stockins = [];

        foreach ($input as $index => $item) {
            // Enhanced validation with better error messages
            $required = ['Product_ID', 'Supplier_ID', 'Stock_in_Quantity', 'Unit_Cost'];
            foreach ($required as $field) {
                if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) {
                    throw new Exception("Field '$field' is required for item at index $index. Received: " . json_encode($item));
                }
            }

            // Validate data types and values
            if (!is_numeric($item['Product_ID']) || $item['Product_ID'] <= 0) {
                throw new Exception("Product_ID must be a positive number. Received: " . $item['Product_ID']);
            }

            if (!is_numeric($item['Supplier_ID']) || $item['Supplier_ID'] <= 0) {
                throw new Exception("Supplier_ID must be a positive number. Received: " . $item['Supplier_ID']);
            }

            if (!is_numeric($item['Stock_in_Quantity']) || $item['Stock_in_Quantity'] <= 0) {
                throw new Exception("Stock_in_Quantity must be a positive number. Received: " . $item['Stock_in_Quantity']);
            }

            if (!is_numeric($item['Unit_Cost']) || $item['Unit_Cost'] < 0) {
                throw new Exception("Unit_Cost must be a non-negative number. Received: " . $item['Unit_Cost']);
            }

            // Verify Product exists
            $productCheck = $db->prepare("SELECT Product_ID, Name FROM Product WHERE Product_ID = :product_id");
            $productCheck->execute([':product_id' => $item['Product_ID']]);
            $product = $productCheck->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception("Product with ID {$item['Product_ID']} does not exist");
            }

            // Verify Supplier exists
            $supplierCheck = $db->prepare("SELECT Supplier_ID, Name FROM Supplier WHERE Supplier_ID = :supplier_id");
            $supplierCheck->execute([':supplier_id' => $item['Supplier_ID']]);
            $supplier = $supplierCheck->fetch(PDO::FETCH_ASSOC);
            if (!$supplier) {
                throw new Exception("Supplier with ID {$item['Supplier_ID']} does not exist");
            }

            $total_cost = floatval($item['Stock_in_Quantity']) * floatval($item['Unit_Cost']);

            $sql = "
                INSERT INTO stockin_inventory (
                    Product_ID, Supplier_ID, Stock_in_Quantity, Quantity_Ordered, 
                    Unit_Cost, Total_Cost, Batch_Number, Expiry_Date, 
                    Date_in, Approved_by, Remarks, Status, PO_ID, Created_Date
                ) VALUES (
                    :product_id, :supplier_id, :stock_in_quantity, :quantity_ordered,
                    :unit_cost, :total_cost, :batch_number, :expiry_date,
                    :date_in, :approved_by, :remarks, :status, :po_id, NOW()
                )
            ";

            $stmt = $db->prepare($sql);
            // For clerk/staff, embed who requested in remarks for filtering
            $remarksValue = $item['Remarks'] ?? null;
            if (!$isApprover) {
                $who = (string)($user['User_name'] ?? 'unknown');
                $remarksValue = trim('Requested by: ' . $who . ($remarksValue ? ' - ' . $remarksValue : ''));
            }

            $result = $stmt->execute([
                ':product_id' => intval($item['Product_ID']),
                ':supplier_id' => intval($item['Supplier_ID']),
                ':stock_in_quantity' => floatval($item['Stock_in_Quantity']),
                ':quantity_ordered' => floatval($item['Quantity_Ordered'] ?? $item['Stock_in_Quantity']),
                ':unit_cost' => floatval($item['Unit_Cost']),
                ':total_cost' => $total_cost,
                ':batch_number' => $item['Batch_Number'] ?? null,
                ':expiry_date' => $item['Expiry_Date'] ?? null,
                ':date_in' => $item['Date_in'] ?? $item['received_date'] ?? date('Y-m-d'),
                ':approved_by' => $isApprover ? ($user['User_ID']) : null,
                ':remarks' => $remarksValue,
                // Clerks/Staff submit as PENDING; approvers can mark RECEIVED
                ':status' => $isApprover ? ($item['Status'] ?? 'RECEIVED') : 'PENDING',
                ':po_id' => !empty($item['PO_ID']) ? intval($item['PO_ID']) : null
            ]);

            if (!$result) {
                $error_info = $stmt->errorInfo();
                error_log("STOCKIN INSERT ERROR: " . implode(', ', $error_info));
                throw new Exception("Failed to insert stockin record: " . implode(', ', $error_info));
            }

            $stockin_id = $db->lastInsertId();
            $created_stockins[] = $stockin_id;

            error_log("STOCKIN: Created stockin_id $stockin_id for Product_ID {$item['Product_ID']}");

            // Check if inventory record exists for this product
            $checkInventorySql = "SELECT Inventory_ID, Quantity FROM Inventory WHERE Product_ID = :product_id";
            $checkStmt = $db->prepare($checkInventorySql);
            $checkStmt->execute([':product_id' => $item['Product_ID']]);
            $inventoryRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($isApprover && $inventoryRecord) {
                // Update existing inventory record
                $updateInventorySql = "
                    UPDATE Inventory 
                    SET Quantity = Quantity + :quantity,
                        Last_update = NOW()
                    WHERE Product_ID = :product_id
                ";
                $updateStmt = $db->prepare($updateInventorySql);
                $updateResult = $updateStmt->execute([
                    ':quantity' => floatval($item['Stock_in_Quantity']),
                    ':product_id' => $item['Product_ID']
                ]);

                if (!$updateResult) {
                    $error_info = $updateStmt->errorInfo();
                    error_log("STOCKIN INVENTORY UPDATE ERROR: " . implode(', ', $error_info));
                    throw new Exception("Failed to update inventory: " . implode(', ', $error_info));
                }

                error_log("STOCKIN: Updated inventory for Product_ID {$item['Product_ID']}, added {$item['Stock_in_Quantity']}");
            } else if ($isApprover && !$inventoryRecord) {
                // Create new inventory record
                $insertInventorySql = "
                    INSERT INTO Inventory (Product_ID, Quantity, Last_update, Reorder_level)
                    VALUES (:product_id, :quantity, NOW(), 0)
                ";
                $insertStmt = $db->prepare($insertInventorySql);
                $insertResult = $insertStmt->execute([
                    ':product_id' => $item['Product_ID'],
                    ':quantity' => floatval($item['Stock_in_Quantity'])
                ]);

                if (!$insertResult) {
                    $error_info = $insertStmt->errorInfo();
                    error_log("STOCKIN INVENTORY CREATE ERROR: " . implode(', ', $error_info));
                    throw new Exception("Failed to create inventory record: " . implode(', ', $error_info));
                }

                error_log("STOCKIN: Created new inventory record for Product_ID {$item['Product_ID']} with quantity {$item['Stock_in_Quantity']}");
            }

            // Only approvers create stock movement and update inventory
            if ($isApprover) {
                $terminal_id = null;
                $movementSql = "
                    INSERT INTO stock_movements (
                        Product_ID, Movement_type, Quantity, Reference_ID, 
                        Source_Table, Performed_by, Terminal_ID, Updated_at, Timestamp
                    ) VALUES (
                        :product_id, 'PURCHASE_RECEIPT', :quantity, :reference_id,
                        'stockin_inventory', :performed_by, :terminal_id, NOW(), NOW()
                    )
                ";
                $movementStmt = $db->prepare($movementSql);
                $movementResult = $movementStmt->execute([
                    ':product_id' => $item['Product_ID'],
                    ':quantity' => floatval($item['Stock_in_Quantity']),
                    ':reference_id' => $stockin_id,
                    ':performed_by' => intval($user['User_ID']),
                    ':terminal_id' => $terminal_id
                ]);
                if (!$movementResult) {
                    $error_info = $movementStmt->errorInfo();
                    error_log("STOCKIN MOVEMENT INSERT ERROR: " . implode(', ', $error_info));
                    throw new Exception("Failed to insert stock movement record: " . implode(', ', $error_info));
                }
                error_log("STOCKIN: Created stock movement record for Product_ID {$item['Product_ID']}");
            }
        }

        // Update PO status to Completed for all unique POs that had stock received
        $updatedPOs = [];
        foreach ($input as $item) {
            if (!empty($item['PO_ID']) && !in_array($item['PO_ID'], $updatedPOs)) {
                $updatePOSql = "UPDATE purchase_order SET Status = 'Completed' WHERE PO_ID = ?";
                $updatePOStmt = $db->prepare($updatePOSql);
                $result = $updatePOStmt->execute([$item['PO_ID']]);
                $updatedPOs[] = $item['PO_ID'];
                error_log("STOCKIN: Updated PO #{$item['PO_ID']} status to Completed. Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // Verify the update
                $checkStmt = $db->prepare("SELECT Status FROM purchase_order WHERE PO_ID = ?");
                $checkStmt->execute([$item['PO_ID']]);
                $newStatus = $checkStmt->fetchColumn();
                error_log("STOCKIN: PO #{$item['PO_ID']} new status: " . $newStatus);
            }
        }

        $db->commit();
        error_log("STOCKIN: Transaction committed successfully");

        // Log activity for stock-in creation
        $action = $isApprover ? 'Stock In (Approved)' : 'Stock In Request';
        $description = $isApprover ? 
            'Created and approved stock-in for ' . count($input) . ' items' : 
            'Submitted stock-in request for ' . count($input) . ' items';
        logActivity($action, $description, null);

        error_log("STOCKIN: Successfully processed " . count($input) . " stockin records");

        outputJson([
            'success' => true,
            'message' => 'Stock-in records created successfully',
            'count' => count($input),
            'stockin_ids' => $created_stockins,
            'stockin_id' => $created_stockins[0] ?? null // For backward compatibility
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        error_log("STOCKIN POST ERROR: " . $e->getMessage());
        error_log("STOCKIN POST ERROR: Transaction rolled back due to exception");
        http_response_code(500);
        outputJson([
            'success' => false,
            'error' => $e->getMessage(),
            'received_data' => $input
        ]);
    }
}

function handlePut($db)
{
    require_once __DIR__ . '/Auth.php';
    $user = currentUser();
    if (!$user) { http_response_code(401); outputJson(['success'=>false,'error'=>'Unauthorized']); }
    $role = strtolower((string)($user['Role_name'] ?? ''));
    $isApprover = in_array($role, ['admin','manager'], true);

    $input = json_decode(file_get_contents('php://input'), true);

    // Approval workflow: approve/reject pending requests
    if (isset($input['action']) && in_array($input['action'], ['approve','reject'], true)) {
        if (!$isApprover) { http_response_code(403); outputJson(['success'=>false,'error'=>'Forbidden']); }

        if (empty($input['Stockin_ID'])) throw new Exception('Stockin_ID is required');
        $stockin_id = (int)$input['Stockin_ID'];
        $approval_notes = trim((string)($input['approval_notes'] ?? ''));
        if ($approval_notes === '') throw new Exception('Approval notes are required');

        // Load record
        $stmt = $db->prepare("SELECT * FROM stockin_inventory WHERE Stockin_ID = :id");
        $stmt->execute([':id' => $stockin_id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new Exception('Stock-in record not found');
        if (strtoupper($rec['Status']) !== 'PENDING') throw new Exception('Only PENDING requests can be processed');

        $db->beginTransaction();
        try {
            if ($input['action'] === 'approve') {
                // Update inventory
                $updInv = $db->prepare("UPDATE Inventory SET Quantity = Quantity + :q, Last_update = NOW() WHERE Product_ID = :pid");
                $updInv->execute([':q' => (float)$rec['Stock_in_Quantity'], ':pid' => (int)$rec['Product_ID']]);

                // Movement
                $mv = $db->prepare("INSERT INTO stock_movements (Product_ID, Movement_type, Quantity, Reference_ID, Source_Table, Performed_by, Terminal_ID, Updated_at, Timestamp) VALUES (:pid, 'PURCHASE_RECEIPT', :q, :ref, 'stockin_inventory', :uid, NULL, NOW(), NOW())");
                $mv->execute([':pid' => (int)$rec['Product_ID'], ':q' => (float)$rec['Stock_in_Quantity'], ':ref' => $stockin_id, ':uid' => (int)$user['User_ID']]);

                // Mark received
                $up = $db->prepare("UPDATE stockin_inventory SET Status = 'RECEIVED', Approved_by = :uid, Updated_Date = NOW(), Remarks = CONCAT(COALESCE(Remarks,''), IF(COALESCE(Remarks,'')='','', '\n'), :notes) WHERE Stockin_ID = :id");
                $up->execute([':uid' => (int)$user['User_ID'], ':notes' => $approval_notes, ':id' => $stockin_id]);
            } else {
                // Reject without inventory ops
                $up = $db->prepare("UPDATE stockin_inventory SET Status = 'REJECTED', Approved_by = :uid, Updated_Date = NOW(), Remarks = CONCAT(COALESCE(Remarks,''), IF(COALESCE(Remarks,'')='','', '\n'), :notes) WHERE Stockin_ID = :id");
                $up->execute([':uid' => (int)$user['User_ID'], ':notes' => $approval_notes, ':id' => $stockin_id]);
            }

            $db->commit();
            
            // Log activity for approval/rejection
            $action = $input['action'] === 'approve' ? 'Stock In Approval' : 'Stock In Rejection';
            $description = $input['action'] === 'approve' ? 
                'Approved stock-in request #' . $stockin_id : 
                'Rejected stock-in request #' . $stockin_id;
            logActivity($action, $description, null);
            
            outputJson(['success'=>true,'message'=> ($input['action']==='approve'?'Approved':'Rejected') . ' successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    if (!$input || !isset($input['Stockin_ID'])) {
        throw new Exception('Invalid input or missing Stockin_ID');
    }

    $stockin_id = $input['Stockin_ID'];

    // Get current record for quantity comparison
    $currentStmt = $db->prepare("SELECT Stock_in_Quantity, Product_ID FROM stockin_inventory WHERE Stockin_ID = :id");
    $currentStmt->execute([':id' => $stockin_id]);
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        throw new Exception('Stock-in record not found');
    }

    $db->beginTransaction();

    try {
        // Calculate new total cost if quantity and unit cost provided
        $total_cost = null;
        if (isset($input['Stock_in_Quantity'], $input['Unit_Cost'])) {
            $total_cost = $input['Stock_in_Quantity'] * $input['Unit_Cost'];
        }

        $fields = [];
        $params = [':id' => $stockin_id];

        $updatable = [
            'Product_ID',
            'Supplier_ID',
            'Stock_in_Quantity',
            'Quantity_Ordered',
            'Unit_Cost',
            'Batch_Number',
            'Expiry_Date',
            'Date_in',
            'Approved_by',
            'Remarks',
            'Status',
            'PO_ID'
        ];

        foreach ($updatable as $field) {
            if (isset($input[$field])) {
                if ($field === 'Approved_by') {
                    // Handle Approved_by field specially to convert user ID to string
                    $fields[] = "$field = :$field";
                    $params[":$field"] = getUserIdFromInput($db, $input[$field]);
                } else {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $input[$field];
                }
            }
        }

        if ($total_cost !== null) {
            $fields[] = "Total_Cost = :total_cost";
            $params[':total_cost'] = $total_cost;
        }

        if (count($fields) === 0) {
            throw new Exception('No fields to update');
        }

        $fields[] = "Updated_Date = NOW()";

        $sql = "UPDATE stockin_inventory SET " . implode(', ', $fields) . " WHERE Stockin_ID = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Adjust inventory quantity difference if Stock_in_Quantity changed
        if (isset($input['Stock_in_Quantity'])) {
            $quantity_diff = $input['Stock_in_Quantity'] - $current['Stock_in_Quantity'];

            if ($quantity_diff != 0) {
                $updateInventorySql = "
                    UPDATE Inventory 
                    SET Quantity = Quantity + :quantity_diff,
                        Last_update = NOW()
                    WHERE Product_ID = :product_id
                ";

                $updateStmt = $db->prepare($updateInventorySql);
                $updateStmt->execute([
                    ':quantity_diff' => $quantity_diff,
                    ':product_id' => $current['Product_ID']
                ]);

                // For stock-in operations, use NULL terminal_id to indicate warehouse/system operation
                $terminal_id = null;

                // Add a stock movement record for the change
                $movementType = $quantity_diff > 0 ? 'PURCHASE_RECEIPT' : 'STOCK_OUT';

                $movementSql = "
                    INSERT INTO stock_movements (
                        Product_ID, Movement_type, Quantity, Reference_ID, 
                        Source_Table, Performed_by, Terminal_ID, Updated_at, Timestamp
                    ) VALUES (
                        :product_id, :movement_type, :quantity, :reference_id,
                        'stockin_inventory', :performed_by, :terminal_id, NOW(), NOW()
                    )
                ";

                $movementStmt = $db->prepare($movementSql);
                $movementStmt->execute([
                    ':product_id' => $current['Product_ID'],
                    ':movement_type' => $movementType,
                    ':quantity' => abs($quantity_diff),
                    ':reference_id' => $stockin_id,
                    ':performed_by' => $input['Performed_by'] ?? 1,
                    ':terminal_id' => $terminal_id
                ]);

                // Update PO status to Completed if this is from a PO
                if (!empty($input['PO_ID'])) {
                    $updatePOSql = "UPDATE purchase_order SET Status = 'Completed' WHERE PO_ID = ?";
                    $updatePOStmt = $db->prepare($updatePOSql);
                    $updatePOStmt->execute([$input['PO_ID']]);
                    error_log("STOCKIN UPDATE: Updated PO #{$input['PO_ID']} status to Completed");
                }
            }
        }

        $db->commit();

        outputJson([
            'success' => true,
            'message' => 'Stock-in record updated successfully',
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        outputJson([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function handleDelete($db)
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['Stockin_ID'])) {
        throw new Exception('Invalid input or missing Stockin_ID');
    }

    $stockin_id = $input['Stockin_ID'];

    $db->beginTransaction();

    try {
        $stmt = $db->prepare("SELECT Stock_in_Quantity, Product_ID FROM stockin_inventory WHERE Stockin_ID = :id");
        $stmt->execute([':id' => $stockin_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new Exception('Stock-in record not found');
        }

        $deleteStmt = $db->prepare("DELETE FROM stockin_inventory WHERE Stockin_ID = :id");
        $deleteStmt->execute([':id' => $stockin_id]);

        // Update inventory quantity (subtract the amount that was added)
        $updateInventorySql = "
            UPDATE Inventory 
            SET Quantity = Quantity - :quantity,
                Last_update = NOW()
            WHERE Product_ID = :product_id
        ";

        $updateStmt = $db->prepare($updateInventorySql);
        $updateStmt->execute([
            ':quantity' => $record['Stock_in_Quantity'],
            ':product_id' => $record['Product_ID']
        ]);

        // For stock-in operations, use NULL terminal_id to indicate warehouse/system operation
        $terminal_id = null;

        $movementSql = "
            INSERT INTO stock_movements (
                Product_ID, Movement_type, Quantity, Reference_ID, 
                Source_Table, Performed_by, Terminal_ID, Updated_at, Timestamp
            ) VALUES (
                :product_id, 'STOCK_OUT', :quantity, :reference_id,
                'stockin_inventory', 1, :terminal_id, NOW(), NOW()
            )
        ";

        $movementStmt = $db->prepare($movementSql);
        $movementStmt->execute([
            ':product_id' => $record['Product_ID'],
            ':quantity' => $record['Stock_in_Quantity'],
            ':reference_id' => $stockin_id,
            ':terminal_id' => $terminal_id
        ]);

        $db->commit();

        outputJson([
            'success' => true,
            'message' => 'Stock-in record deleted successfully',
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        outputJson([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>