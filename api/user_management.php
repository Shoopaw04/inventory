<?php
// user_management.php - User Management API (Admin Only)

header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

try {
    $db = Database::getInstance()->getConnection();
    $user = currentUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Only Admin can access this feature (case-insensitive)
    if (strtolower($user['Role_name'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied. Admin privileges required.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGet($db, $user);
            break;
        case 'POST':
            handlePost($db, $user);
            break;
        case 'PUT':
            handlePut($db, $user);
            break;
        case 'DELETE':
            handleDelete($db, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("User Management API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function handleGet($db, $user) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getUsersList($db);
            break;
        case 'roles':
            getRoles($db);
            break;
        case 'permissions':
            getPermissions($db);
            break;
        case 'user_details':
            getUserDetails($db);
            break;
        case 'user_sessions':
            getUserSessions($db);
            break;
        default:
            getUsersList($db);
    }
}

function getUsersList($db) {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role_filter = isset($_GET['role']) ? (int) $_GET['role'] : null;
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : null;

    $sql = "
        SELECT 
            u.User_ID,
            u.User_name,
            u.Status,
            r.Role_ID,
            r.Role_name,
            NULL as role_description,
            (
                SELECT COUNT(*) 
                FROM user_sessions us 
                WHERE us.User_ID = u.User_ID 
                  AND us.Last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ) AS active_sessions,
            (
                SELECT MAX(us2.Last_seen)
                FROM user_sessions us2
                WHERE us2.User_ID = u.User_ID
            ) AS last_login,
            (
                SELECT COUNT(*)
                FROM activity_log al2
                WHERE al2.User_ID = u.User_ID
            ) AS total_activities
        FROM users u
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";
    
    $params = [];

    if ($search) {
        $sql .= " AND (u.User_name LIKE :search1 OR r.Role_name LIKE :search2)";
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    if ($role_filter) {
        $sql .= " AND u.Role_ID = :role_id";
        $params[':role_id'] = $role_filter;
    }

    if ($status_filter) {
        $sql .= " AND u.Status = :status";
        $params[':status'] = $status_filter;
    }

    // MySQL does not allow bound params in LIMIT/OFFSET when emulation is off
    $limitInt = max(1, (int)$limit);
    $offsetInt = max(0, (int)$offset);
    $sql .= " ORDER BY u.User_name ASC LIMIT $limitInt OFFSET $offsetInt";

    $stmt = $db->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $countSql = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";
    
    $countParams = [];
    if ($search) {
        $countSql .= " AND (u.User_name LIKE :search1 OR r.Role_name LIKE :search2)";
        $countParams[':search1'] = '%' . $search . '%';
        $countParams[':search2'] = '%' . $search . '%';
    }
    if ($role_filter) {
        $countSql .= " AND u.Role_ID = :role_id";
        $countParams[':role_id'] = $role_filter;
    }
    if ($status_filter) {
        $countSql .= " AND u.Status = :status";
        $countParams[':status'] = $status_filter;
    }

    $countStmt = $db->prepare($countSql);
    foreach ($countParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get statistics
    $statsSql = "
        SELECT 
            COUNT(*) as total_users,
            COUNT(CASE WHEN Status = 'active' THEN 1 END) as active_users,
            COUNT(CASE WHEN Status = 'inactive' THEN 1 END) as inactive_users,
            COUNT(DISTINCT Role_ID) as unique_roles
        FROM users
    ";
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $users,
        'pagination' => [
            'total' => (int) $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ],
        'statistics' => $stats
    ]);
}

function getRoles($db) {
    $sql = "SELECT Role_ID, Role_name, Description FROM role ORDER BY Role_name";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $roles
    ]);
}

function getPermissions($db) {
    // Define available permissions
    $permissions = [
        'inventory' => [
            'view' => 'View Inventory',
            'edit' => 'Edit Inventory',
            'create' => 'Create Products',
            'delete' => 'Delete Products',
            'adjust' => 'Manual Adjustments',
            'stockin' => 'Stock In Operations'
        ],
        'sales' => [
            'view' => 'View Sales',
            'create' => 'Create Sales',
            'pos' => 'POS Access',
            'refund' => 'Process Refunds'
        ],
        'reports' => [
            'view' => 'View Reports',
            'export' => 'Export Data',
            'analytics' => 'View Analytics'
        ],
        'admin' => [
            'users' => 'Manage Users',
            'sessions' => 'Manage Sessions',
            'logs' => 'View Activity Logs',
            'settings' => 'System Settings'
        ]
    ];

    echo json_encode([
        'success' => true,
        'data' => $permissions
    ]);
}

function getUserDetails($db) {
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        return;
    }

    $sql = "
        SELECT 
            u.User_ID,
            u.User_name,
            u.Status,
            NULL as Created_at,
            r.Role_ID,
            r.Role_name,
            NULL as role_description
        FROM users u
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE u.User_ID = ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Get user's recent activity
    $activitySql = "
        SELECT 
            Activity_type,
            Time,
            Terminal_ID,
            NULL as Terminal_name
        FROM activity_log al
        WHERE al.User_ID = ?
        ORDER BY al.Time DESC
        LIMIT 10
    ";
    
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([$user_id]);
    $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user's active sessions
    $sessionsSql = "
        SELECT 
            Session_ID,
            Created_at,
            Last_seen,
            Ip_Address,
            User_Agent
        FROM user_sessions
        WHERE User_ID = ? AND Last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY Last_seen DESC
    ";
    
    $sessionsStmt = $db->prepare($sessionsSql);
    $sessionsStmt->execute([$user_id]);
    $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'user' => $user,
            'activities' => $activities,
            'sessions' => $sessions
        ]
    ]);
}

function handlePost($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'create_user';

    switch ($action) {
        case 'create_user':
            createUser($db, $input, $user);
            break;
        case 'update_user':
            updateUserDetails($db, $input);
            break;
        case 'reset_password':
            resetPassword($db, $input, $user);
            break;
        case 'bulk_action':
            bulkAction($db, $input, $user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

function createUser($db, $input, $current_user) {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $role_id = (int) ($input['role_id'] ?? 0);
    $status = trim($input['status'] ?? 'active');

    if (!$username || !$password || !$role_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username, password, and role are required']);
        return;
    }

    // Check if username already exists
    $checkStmt = $db->prepare("SELECT User_ID FROM users WHERE User_name = ?");
    $checkStmt->execute([$username]);
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        return;
    }

    // Validate role exists
    $roleStmt = $db->prepare("SELECT Role_ID FROM role WHERE Role_ID = ?");
    $roleStmt->execute([$role_id]);
    if (!$roleStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid role']);
        return;
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Create user (omit Created_at to be compatible with schemas without this column)
    try {
        $insertStmt = $db->prepare("INSERT INTO users (User_name, Password, Role_ID, Status) VALUES (?, ?, ?, ?)");
        $result = $insertStmt->execute([$username, $hashedPassword, $role_id, $status]);
    } catch (Throwable $e) {
        error_log('Create user failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create user: ' . $e->getMessage()]);
        return;
    }

    if ($result) {
        $new_user_id = $db->lastInsertId();
        
        // Log activity
        logActivity('USER_CREATED', "Created user: $username with role ID: $role_id");
        // Audit
        logAudit('user', $new_user_id, 'CREATE', null, [
            'User_name' => $username,
            'Role_ID' => (int)$role_id,
            'Status' => $status
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'user_id' => $new_user_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create user']);
    }
}

function resetPassword($db, $input, $current_user) {
    $user_id = (int) ($input['user_id'] ?? 0);
    $new_password = trim($input['new_password'] ?? '');

    if (!$user_id || !$new_password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User ID and new password are required']);
        return;
    }

    // Get user details
    $userStmt = $db->prepare("SELECT User_name FROM users WHERE User_ID = ?");
    $userStmt->execute([$user_id]);
    $target_user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Hash new password
    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password
    $updateStmt = $db->prepare("UPDATE users SET Password = ? WHERE User_ID = ?");
    $result = $updateStmt->execute([$hashedPassword, $user_id]);

    if ($result) {
        // Log activity
        logActivity('PASSWORD_RESET', "Reset password for user: {$target_user['User_name']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to reset password']);
    }
}

function handlePut($db, $user) {
    $input = json_decode(file_get_contents('php://input'), true);
    updateUserDetails($db, $input);
}

function updateUserDetails($db, $input) {
    $user_id = (int) ($input['user_id'] ?? 0);

    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        return;
    }

    $username = trim($input['username'] ?? '');
    $role_id = (int) ($input['role_id'] ?? 0);
    $status = trim($input['status'] ?? '');

    if (!$username || !$role_id || !$status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username, role, and status are required']);
        return;
    }

    // Check if user exists
    $checkStmt = $db->prepare("SELECT User_name FROM users WHERE User_ID = ?");
    $checkStmt->execute([$user_id]);
    $existing_user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Check if username is taken by another user
    $usernameStmt = $db->prepare("SELECT User_ID FROM users WHERE User_name = ? AND User_ID != ?");
    $usernameStmt->execute([$username, $user_id]);
    if ($usernameStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        return;
    }

    // Validate role exists
    $roleStmt = $db->prepare("SELECT Role_ID FROM role WHERE Role_ID = ?");
    $roleStmt->execute([$role_id]);
    if (!$roleStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid role']);
        return;
    }

    // Fetch before state for audit
    $beforeStmt = $db->prepare("SELECT User_ID, User_name, Role_ID, Status FROM users WHERE User_ID = ?");
    $beforeStmt->execute([$user_id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Update user
    $updateStmt = $db->prepare("UPDATE users SET User_name = ?, Role_ID = ?, Status = ? WHERE User_ID = ?");
    $result = $updateStmt->execute([$username, $role_id, $status, $user_id]);

    if ($result) {
        // Log activity
        logActivity('USER_UPDATED', "Updated user: $username (ID: $user_id)");
        // Audit
        logAudit('user', $user_id, 'UPDATE', $before, [
            'User_ID' => $user_id,
            'User_name' => $username,
            'Role_ID' => $role_id,
            'Status' => $status
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'User updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update user']);
    }
}

function handleDelete($db, $user) {
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        return;
    }

    // Prevent deleting own account
    if ($user_id === $user['User_ID']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
        return;
    }

    // Get user details before deletion
    $userStmt = $db->prepare("SELECT User_name FROM users WHERE User_ID = ?");
    $userStmt->execute([$user_id]);
    $target_user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Fetch before state for audit
    $beforeStmt = $db->prepare("SELECT User_ID, User_name, Role_ID, Status FROM users WHERE User_ID = ?");
    $beforeStmt->execute([$user_id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Remove user sessions
    $sessionsStmt = $db->prepare("DELETE FROM user_sessions WHERE User_ID = ?");
    $sessionsStmt->execute([$user_id]);

    // Soft delete: mark user inactive instead of deleting
    $deleteStmt = $db->prepare("UPDATE users SET Status = 'inactive' WHERE User_ID = ?");
    $result = $deleteStmt->execute([$user_id]);

    if ($result) {
        // Log activity
        logActivity('USER_DELETED', "Deleted user: {$target_user['User_name']} (ID: $user_id)");
        // Audit
        logAudit('user', $user_id, 'DEACTIVATE', $before, ['Status' => 'inactive']);
        
        echo json_encode([
            'success' => true,
            'message' => 'User deactivated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
    }
}

function bulkAction($db, $input, $current_user) {
    $action = $input['bulk_action'] ?? '';
    $user_ids = $input['user_ids'] ?? [];

    if (!$action || empty($user_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Action and user IDs are required']);
        return;
    }

    $user_ids = array_map('intval', $user_ids);
    $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';

    switch ($action) {
        case 'activate':
            $stmt = $db->prepare("UPDATE users SET Status = 'active' WHERE User_ID IN ($placeholders)");
            $stmt->execute($user_ids);
            $message = 'Users activated successfully';
            break;
        case 'deactivate':
            $stmt = $db->prepare("UPDATE users SET Status = 'inactive' WHERE User_ID IN ($placeholders)");
            $stmt->execute($user_ids);
            $message = 'Users deactivated successfully';
            break;
        case 'delete':
            // Prevent deleting own account
            if (in_array($current_user['User_ID'], $user_ids)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
                return;
            }

            // Delete sessions first
            $sessionsStmt = $db->prepare("DELETE FROM user_sessions WHERE User_ID IN ($placeholders)");
            $sessionsStmt->execute($user_ids);

            // Soft delete: mark as inactive
            $stmt = $db->prepare("UPDATE users SET Status = 'inactive' WHERE User_ID IN ($placeholders)");
            $stmt->execute($user_ids);
            $message = 'Users deactivated successfully';
            // Audit (bulk)
            logAudit('user', implode(',', $user_ids), 'BULK_DEACTIVATE', null, ['Status' => 'inactive', 'Count' => count($user_ids)]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid bulk action']);
            return;
    }

    // Log activity
    logActivity('BULK_ACTION', "Bulk action '$action' performed on " . count($user_ids) . " users");

    echo json_encode([
        'success' => true,
        'message' => $message,
        'affected_users' => count($user_ids)
    ]);
}

?>
