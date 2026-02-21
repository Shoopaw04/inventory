<?php

require_once __DIR__ . '/Database.php';

// 
if (session_status() === PHP_SESSION_NONE) {
    session_name('INVSESS');
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function dbConn() {
    // PDO connection
    return Database::getInstance()->getConnection();
}

function currentUser() {
   
    
    try {
        $db = dbConn();
        $session_id = session_id();
        
        // 
        $sessionCheck = $db->prepare("SELECT User_ID FROM user_sessions WHERE Session_ID = ?");
        $sessionCheck->execute([$session_id]);
        $sessionData = $sessionCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$sessionData) {
            // Session termination
            session_destroy();
            return null;
        }
        
        // 
        if (empty($_SESSION['user_id'])) {
            $_SESSION['user_id'] = $sessionData['User_ID'];
        }
        
        $sql = "SELECT u.User_ID, u.User_name, u.Role_ID, u.Status, r.Role_name
                FROM users u
                LEFT JOIN role r ON r.Role_ID = u.Role_ID
                WHERE u.User_ID = ? AND u.Status = 'active'";
        $stmt = $db->prepare($sql);
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // 
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

function requireAuth() {
    $u = currentUser();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    return $u;
}

function requireRole(array $allowedRoleNames) {
    $u = requireAuth();
    $roleName = $u['Role_name'] ?? '';
    if (!in_array($roleName, $allowedRoleNames, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    return $u;
}

function logActivity($activity_type, $details = null, $terminal_id = null) {
    try {
        $user = currentUser();
        if (!$user) return false;
        
        $db = dbConn();
        $sql = "INSERT INTO activity_log (User_ID, Activity_type, Time, Terminal_ID) VALUES (?, ?, NOW(), ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['User_ID'], $activity_type, $terminal_id]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

function updateUserSession() {
    try {
        $user = currentUser();
        if (!$user) return false;
        
        $db = dbConn();
        $session_id = session_id();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // 
        $stmt = $db->prepare('UPDATE user_sessions SET Last_seen = NOW(), User_Agent = ?, Ip_Address = ? WHERE Session_ID = ?');
        $stmt->execute([$user_agent, $ip_address, $session_id]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to update user session: " . $e->getMessage());
        return false;
    }
}


function logAudit($entity, $entityId, $action, $beforeState = null, $afterState = null, $details = null, $terminal_id = null) {
    try {
        $actor = currentUser();
        if (!$actor) return false;

        $db = dbConn();

        // 
        $colsStmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'");
        $colsStmt->execute();
        $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $isLegacy = in_array('log_id', $columns, true) && in_array('table_name', $columns, true);

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $beforeJson = $beforeState !== null ? json_encode($beforeState, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;
        $afterJson = $afterState !== null ? json_encode($afterState, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;

        if ($isLegacy) {
            // 
            $stmt = $db->prepare("INSERT INTO audit_log (
                table_name, record_id, action, old_values, new_values, changed_by, changed_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                (string)$entity,
                $entityId !== null ? (string)$entityId : '0',
                (string)$action,
                $beforeJson,
                $afterJson,
                (string)$actor['User_ID']
            ]);
        } else {
            // 
            $stmt = $db->prepare("INSERT INTO audit_log (
                User_ID, Entity, Entity_ID, Action, Before_State, After_State, Details, Time, Terminal_ID, Ip_Address, User_Agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
            $stmt->execute([
                $actor['User_ID'],
                (string)$entity,
                $entityId !== null ? (string)$entityId : null,
                (string)$action,
                $beforeJson,
                $afterJson,
                $details,
                $terminal_id,
                $ip_address,
                $user_agent
            ]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('logAudit failed: ' . $e->getMessage());
        return false;
    }
}

?>


