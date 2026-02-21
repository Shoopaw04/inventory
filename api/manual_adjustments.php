<?php
// manual_adjustments.php - Fixed version with improved user lookup
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance()->getConnection();

    switch ($method) {
        case 'GET':
            handleGetAdjustments($db);
            break;

        case 'POST':
            handleCreateAdjustment($db);
            break;

        case 'PUT':
            handleApproveRejectAdjustment($db);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Manual Adjustments API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleGetAdjustments($db)
{
    // Check authentication
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }

    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $adjusted_by_username = isset($_GET['adjusted_by']) ? trim($_GET['adjusted_by']) : '';
    $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $terminal_id = isset($_GET['terminal_id']) ? (int) $_GET['terminal_id'] : 0;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

    // Handle special actions
    switch ($action) {
        case 'pending':
            getPendingAdjustments($db);
            return;
        case 'summary':
            getAdjustmentSummary($db);
            return;
        case 'history':
            getAdjustmentHistory($db, $product_id);
            return;
    }

    // Main query - FIXED: Join with Inventory table and include user info
    $sql = "
        SELECT 
            a.Adjustment_ID,
            a.Product_ID,
            p.Name as product_name,
            a.Old_quantity,
            a.New_quantity,
            (a.New_quantity - a.Old_quantity) as quantity_change,
            ABS(a.New_quantity - a.Old_quantity) as abs_quantity_change,
            CASE 
                WHEN a.New_quantity > a.Old_quantity THEN 'INCREASE'
                WHEN a.New_quantity < a.Old_quantity THEN 'DECREASE'
                ELSE 'NO_CHANGE'
            END as adjustment_type,
            a.Adjustment_date,
            a.Reason,
            a.Adjusted_by,
            u1.User_name as adjusted_by_username,
            r1.Role_name as adjusted_by_role,
            a.Approved_by,
            u2.User_name as approved_by_username,
            r2.Role_name as approved_by_role,
            a.Approved_date,
            a.Status,
            a.Notes,
            a.Approval_notes,
            a.Terminal_ID,
            a.Created_at,
            a.Updated_at,
            t.Name as Terminal_name,
            t.Location as terminal_location,
            i.Quantity as current_quantity,
            CASE 
                WHEN a.Status = 'PENDING' THEN '⏳ Pending Approval'
                WHEN a.Status = 'APPROVED' THEN '✅ Approved'
                WHEN a.Status = 'REJECTED' THEN '❌ Rejected'
                WHEN a.Status = 'AUTO_APPLIED' THEN '🤖 Auto Applied'
                ELSE a.Status
            END as status_display,
            CASE 
                WHEN a.Status = 'PENDING' THEN DATEDIFF(NOW(), a.Adjustment_date)
                ELSE NULL
            END as days_pending,
            CASE 
                WHEN a.Status = 'PENDING' THEN 
                    CASE 
                        WHEN DATEDIFF(NOW(), a.Adjustment_date) > 7 THEN 'OVERDUE'
                        WHEN DATEDIFF(NOW(), a.Adjustment_date) > 3 THEN 'URGENT'
                        ELSE 'NORMAL'
                    END
                ELSE NULL
            END as priority_level
        FROM manual_adjustments a
        JOIN product p ON a.Product_ID = p.Product_ID
        JOIN inventory i ON a.Product_ID = i.Product_ID
        LEFT JOIN users u1 ON a.Adjusted_by = u1.User_ID
        LEFT JOIN role r1 ON u1.Role_ID = r1.Role_ID
        LEFT JOIN users u2 ON a.Approved_by = u2.User_ID
        LEFT JOIN role r2 ON u2.Role_ID = r2.Role_ID
        LEFT JOIN terminal t ON a.Terminal_ID = t.Terminal_ID
        WHERE 1=1
    ";

    $params = [];

    if ($status !== '') {
        $sql .= " AND a.Status = :status";
        $params[':status'] = $status;
    }

    if ($product_id > 0) {
        $sql .= " AND a.Product_ID = :product_id";
        $params[':product_id'] = $product_id;
    }

    if ($terminal_id > 0) {
        $sql .= " AND a.Terminal_ID = :terminal_id";
        $params[':terminal_id'] = $terminal_id;
    }

    // Role-based filtering: Non-admin/manager users can only see their own requests
    $role = strtolower((string)($user['Role_name'] ?? ''));
    if (!in_array($role, ['admin', 'manager'], true)) {
        // For clerks/staff, only show their own requests
        $sql .= " AND u1.User_name = :current_user";
        $params[':current_user'] = $user['User_name'];
    } elseif ($adjusted_by_username !== '' && $adjusted_by_username !== 'unknown' && $adjusted_by_username !== 'null') {
        // For admin/manager, allow filtering by specific user if requested
        $sql .= " AND u1.User_name = :adjusted_by";
        $params[':adjusted_by'] = $adjusted_by_username;
    }

    if ($date_from !== '') {
        $sql .= " AND DATE(a.Adjustment_date) >= :date_from";
        $params[':date_from'] = $date_from;
    }

    if ($date_to !== '') {
        $sql .= " AND DATE(a.Adjustment_date) <= :date_to";
        $params[':date_to'] = $date_to;
    }

    $sql .= " ORDER BY a.Adjustment_date $order LIMIT :limit";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $adjustments,
        'count' => count($adjustments)
    ]);
}

function getPendingAdjustments($db)
{
    // Check authentication
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    $sql = "
        SELECT 
            a.Adjustment_ID,
            a.Product_ID,
            p.Name as product_name,
            a.Old_quantity,
            a.New_quantity,
            (a.New_quantity - a.Old_quantity) as quantity_change,
            a.Reason,
            a.Adjusted_by,
            u.User_name as adjusted_by_username,
            r.Role_name as adjusted_by_role,
            a.Adjustment_date,
            a.Notes,
            t.Name as Terminal_name,
            DATEDIFF(NOW(), a.Adjustment_date) as days_pending,
            CASE 
                WHEN DATEDIFF(NOW(), a.Adjustment_date) > 7 THEN 'OVERDUE'
                WHEN DATEDIFF(NOW(), a.Adjustment_date) > 3 THEN 'URGENT'
                ELSE 'NORMAL'
            END as priority_level
        FROM manual_adjustments a
        JOIN product p ON a.Product_ID = p.Product_ID
        LEFT JOIN users u ON a.Adjusted_by = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        LEFT JOIN terminal t ON a.Terminal_ID = t.Terminal_ID
        WHERE a.Status = 'PENDING'
        ORDER BY a.Adjustment_date ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'pending_adjustments' => $pending,
        'count' => count($pending)
    ]);
}

function getAdjustmentSummary($db)
{
    // Check authentication
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    // Get summary statistics
    $sql = "
        SELECT 
            COUNT(*) as total_adjustments,
            SUM(CASE WHEN Status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN Status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN Status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN Status = 'PENDING' AND DATEDIFF(NOW(), Adjustment_date) > 7 THEN 1 ELSE 0 END) as overdue_count,
            AVG(CASE WHEN Status != 'PENDING' THEN DATEDIFF(Approved_date, Adjustment_date) ELSE NULL END) as avg_approval_days
        FROM manual_adjustments
        WHERE Adjustment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get top reasons for adjustments
    $sql = "
        SELECT 
            Reason,
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
        FROM manual_adjustments
        WHERE Adjustment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY Reason
        ORDER BY count DESC
        LIMIT 5
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'top_reasons' => $reasons
    ]);
}

function getAdjustmentHistory($db, $product_id)
{
    // Check authentication
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    $sql = "
        SELECT 
            a.Adjustment_ID,
            a.Product_ID,
            p.Name as product_name,
            a.Old_quantity,
            a.New_quantity,
            (a.New_quantity - a.Old_quantity) as quantity_change,
            a.Reason,
            a.Status,
            a.Adjusted_by,
            u1.User_name as adjusted_by_username,
            r1.Role_name as adjusted_by_role,
            a.Approved_by,
            u2.User_name as approved_by_username,
            r2.Role_name as approved_by_role,
            a.Adjustment_date,
            a.Approved_date,
            t.Name as Terminal_name
        FROM manual_adjustments a
        JOIN product p ON a.Product_ID = p.Product_ID
        LEFT JOIN users u1 ON a.Adjusted_by = u1.User_ID
        LEFT JOIN role r1 ON u1.Role_ID = r1.Role_ID
        LEFT JOIN users u2 ON a.Approved_by = u2.User_ID
        LEFT JOIN role r2 ON u2.Role_ID = r2.Role_ID
        LEFT JOIN terminal t ON a.Terminal_ID = t.Terminal_ID
        WHERE a.Status IN ('APPROVED', 'AUTO_APPLIED')
    ";

    $params = [];

    if ($product_id > 0) {
        $sql .= " AND a.Product_ID = :product_id";
        $params[':product_id'] = $product_id;
    }

    $sql .= " ORDER BY a.Adjustment_date DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'history' => $history,
        'count' => count($history)
    ]);
}

function resolveUserID($db, $user_input)
{
    $user_input = trim($user_input);

    // If it's already a numeric ID, validate it exists
    if (is_numeric($user_input)) {
        $user_id = (int) $user_input;
        $stmt = $db->prepare("SELECT User_ID, User_name FROM users WHERE User_ID = ? AND Status = 'active'");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("User ID '$user_id' not found or inactive");
        }

        return (int) $user['User_ID'];
    }

    // Try to find by username first
    $stmt = $db->prepare("SELECT User_ID, User_name FROM users WHERE User_name = ? AND Status = 'active'");
    $stmt->execute([$user_input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return (int) $user['User_ID'];
    }

    // Try to find by role name (fallback for frontend issues) - with typo handling
    $role_mapping = [
        'Admin' => 'Admin',
        'Manager' => 'Manager',
        'Inventory Clerk' => 'Inventory Clerk',
        'Inventort Clerk' => 'Inventory Clerk', // Handle typo
        'Inventory Staff' => 'Inventory Staff',
        'Cashier' => 'Cashier'
    ];

    $role_to_search = isset($role_mapping[$user_input]) ? $role_mapping[$user_input] : $user_input;

    $stmt = $db->prepare("
        SELECT u.User_ID, u.User_name, r.Role_name 
        FROM users u 
        JOIN role r ON u.Role_ID = r.Role_ID 
        WHERE r.Role_name = ? AND u.Status = 'active'
        ORDER BY u.User_ID ASC
        LIMIT 1
    ");
    $stmt->execute([$role_to_search]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return (int) $user['User_ID'];
    }

    // Get available users for error message
    $stmt = $db->prepare("
        SELECT u.User_name, r.Role_name 
        FROM users u 
        LEFT JOIN role r ON u.Role_ID = r.Role_ID 
        WHERE u.Status = 'active' 
        ORDER BY u.User_ID ASC
    ");
    $stmt->execute();
    $available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $user_list = [];
    foreach ($available_users as $u) {
        $user_list[] = $u['User_name'] . ' (' . ($u['Role_name'] ?: 'No Role') . ')';
    }

    // No user found
    throw new Exception("User '$user_input' not found. Available users: " . implode(', ', $user_list));
}

function handleCreateAdjustment($db)
{
    // Only logged-in users can create requests; all roles allowed to submit
    $user = currentUser();
    if (!$user) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); return; }

    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields (derive adjusted_by from session)
    $required = ['product_id', 'old_quantity', 'new_quantity', 'reason'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            throw new Exception("Field '$field' is required");
        }
    }

    $product_id = (int) $input['product_id'];
    $old_quantity = (int) $input['old_quantity'];
    $new_quantity = (int) $input['new_quantity'];
    $reason = trim($input['reason']);
    // Use the logged-in user as the adjusted_by
    $adjusted_by_input = (string)($user['User_name'] ?? $user['User_ID']);
    $notes = isset($input['notes']) ? trim($input['notes']) : '';
    // Terminal is deprecated; ignore any provided terminal_id
    $terminal_id = null;
    $auto_apply = isset($input['auto_apply']) && $input['auto_apply'] === true;

    // Enforce: Only Admin/Manager can auto-apply. Others force PENDING.
    $role = strtolower((string)($user['Role_name'] ?? ''));
    $canAutoApply = in_array($role, ['admin','manager'], true);
    if (!$canAutoApply) { $auto_apply = false; }

    // Resolve user ID with improved error handling
    $adjusted_by = resolveUserID($db, $adjusted_by_input);

    // Validate quantities
    if ($old_quantity < 0 || $new_quantity < 0) {
        throw new Exception("Quantities cannot be negative");
    }

    if ($old_quantity === $new_quantity) {
        throw new Exception("New quantity must be different from old quantity");
    }

    // Check if product exists and get current quantity from Inventory table
    $stmt = $db->prepare("
        SELECT p.Name, i.Quantity 
        FROM product p 
        JOIN inventory i ON p.Product_ID = i.Product_ID 
        WHERE p.Product_ID = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new Exception("Product not found or not in inventory");
    }

    // Verify current quantity matches old_quantity
    if ((int) $product['Quantity'] !== $old_quantity) {
        throw new Exception("Current system quantity ({$product['Quantity']}) does not match provided old quantity ($old_quantity)");
    }

    // Determine initial status
    $initial_status = $auto_apply ? 'AUTO_APPLIED' : 'PENDING';
    $approved_by = $auto_apply ? $adjusted_by : null;
    $approved_date = $auto_apply ? 'NOW()' : 'NULL';

    $db->beginTransaction();

    try {
        // Insert adjustment record
        $sql = "
            INSERT INTO manual_adjustments (
                Product_ID, Old_quantity, New_quantity, Adjustment_date, 
                Reason, Adjusted_by, Approved_by, Approved_date, Status, Notes, Terminal_ID
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, $approved_date, ?, ?, NULL)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $product_id,
            $old_quantity,
            $new_quantity,
            $reason,
            $adjusted_by,
            $approved_by,
            $initial_status,
            $notes
        ]);

        $adjustment_id = $db->lastInsertId();

        // If auto-apply, update inventory quantity and create stock movement
        if ($auto_apply) {
            // Update inventory quantity (NOT Product table)
            $stmt = $db->prepare("UPDATE Inventory SET Quantity = ?, Last_update = NOW() WHERE Product_ID = ?");
            $stmt->execute([$new_quantity, $product_id]);

            // Create stock movement record
            $quantity_change = $new_quantity - $old_quantity;
            $movement_type = $quantity_change > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';

            $stmt = $db->prepare("
                INSERT INTO stock_movements (
                    Product_ID, Movement_type, Quantity, Reference_ID, 
                    Performed_by, Timestamp, Source_Table, Terminal_ID
                ) VALUES (?, ?, ?, ?, ?, NOW(), 'manual_adjustments', NULL)
            ");
            $stmt->execute([
                $product_id,
                $movement_type,
                abs($quantity_change),
                $adjustment_id,
                $adjusted_by
            ]);
        }

        $db->commit();

        $message = $auto_apply ?
            'Adjustment created and applied automatically' :
            'Adjustment request created successfully. Awaiting approval.';

        // Log activity
        logActivity('MANUAL_ADJUSTMENT_CREATED', "Created adjustment #$adjustment_id for product ID $product_id (Old: $old_quantity, New: $new_quantity)");

        echo json_encode([
            'success' => true,
            'adjustment_id' => $adjustment_id,
            'status' => $initial_status,
            'message' => $message
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleApproveRejectAdjustment($db)
{
    // Only Admin/Manager can approve/reject
    $user = currentUser();
    if (!$user) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); return; }
    $role = strtolower((string)($user['Role_name'] ?? ''));
    if (!in_array($role, ['admin','manager'], true)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $adjustment_id = (int) $input['adjustment_id'];
    $action = trim($input['action']); // 'approve' or 'reject'
    $approved_by_input = $input['approved_by'] ?? ($user['User_name'] ?? $user['User_ID']); // default to current approver
    $approval_notes = isset($input['approval_notes']) ? trim($input['approval_notes']) : '';

    // Resolve user ID with improved error handling
    $approved_by = resolveUserID($db, $approved_by_input);

    if (!in_array($action, ['approve', 'reject'])) {
        throw new Exception("Invalid action. Must be 'approve' or 'reject'");
    }

    // Get adjustment details with current quantity from Inventory table
    $stmt = $db->prepare("
        SELECT a.*, p.Name as product_name, i.Quantity as current_quantity
        FROM manual_adjustments a
        JOIN product p ON a.Product_ID = p.Product_ID
        JOIN inventory i ON a.Product_ID = i.Product_ID
        WHERE a.Adjustment_ID = ? AND a.Status = 'PENDING'
    ");
    $stmt->execute([$adjustment_id]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adjustment) {
        throw new Exception("Adjustment not found or already processed");
    }

    // Additional validation: check if current quantity still matches for approval only
    if ($action === 'approve') {
        if ((int) $adjustment['current_quantity'] !== (int) $adjustment['Old_quantity']) {
            throw new Exception("Product quantity has changed since adjustment was requested. Current: {$adjustment['current_quantity']}, Expected: {$adjustment['Old_quantity']}");
        }
    }

    $db->beginTransaction();

    try {
        if ($action === 'approve') {
            // Update inventory quantity (NOT Product table)
            $stmt = $db->prepare("UPDATE Inventory SET Quantity = ?, Last_update = NOW() WHERE Product_ID = ?");
            $stmt->execute([$adjustment['New_quantity'], $adjustment['Product_ID']]);

            // Create stock movement record
            $quantity_change = $adjustment['New_quantity'] - $adjustment['Old_quantity'];
            $movement_type = $quantity_change > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';

            $stmt = $db->prepare("
                INSERT INTO stock_movements (
                    Product_ID, Movement_type, Quantity, Reference_ID, 
                    Performed_by, Timestamp, Source_Table, Terminal_ID
                ) VALUES (?, ?, ?, ?, ?, NOW(), 'manual_adjustments', NULL)
            ");
            $stmt->execute([
                $adjustment['Product_ID'],
                $movement_type,
                abs($quantity_change),
                $adjustment_id,
                $approved_by
            ]);

            // Update adjustment status
            $stmt = $db->prepare("
                UPDATE manual_adjustments 
                SET Status = 'APPROVED', Approved_by = ?, Approval_notes = ?, Approved_date = NOW()
                WHERE Adjustment_ID = ?
            ");
            $stmt->execute([$approved_by, $approval_notes, $adjustment_id]);

            $message = "Adjustment approved and applied successfully";

        } else { // reject
            $stmt = $db->prepare("
                UPDATE manual_adjustments 
                SET Status = 'REJECTED', Approved_by = ?, Approval_notes = ?, Approved_date = NOW()
                WHERE Adjustment_ID = ?
            ");
            $stmt->execute([$approved_by, $approval_notes, $adjustment_id]);

            $message = "Adjustment rejected";
        }

        $db->commit();

        // Log activity
        logActivity('MANUAL_ADJUSTMENT_' . strtoupper($action), "Adjustment #$adjustment_id $action by user");

        echo json_encode([
            'success' => true,
            'message' => $message,
            'adjustment_id' => $adjustment_id,
            'action' => $action
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}