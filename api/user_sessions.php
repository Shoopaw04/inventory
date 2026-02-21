<?php
// user_sessions.php - User Sessions Management API (Admin Only)

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

    // Only Admin can access this feature
    if ($user['Role_name'] !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied. Admin privileges required.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGet($db, $user);
            break;
        case 'DELETE':
            handleDelete($db, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("User Sessions API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function handleGet($db, $user) {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $user_filter = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : null;

    $sql = "
        SELECT 
            us.Session_ID,
            us.User_ID,
            u.User_name,
            r.Role_name,
            us.Created_at,
            us.Last_seen,
            us.User_Agent,
            us.Ip_Address,
            CASE 
                WHEN us.Last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'active'
                WHEN us.Last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 'idle'
                ELSE 'expired'
            END as session_status,
            TIMESTAMPDIFF(MINUTE, us.Created_at, us.Last_seen) as session_duration_minutes,
            TIMESTAMPDIFF(MINUTE, us.Last_seen, NOW()) as minutes_since_last_activity
        FROM user_sessions us
        LEFT JOIN users u ON us.User_ID = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";
    
    $params = [];

    if ($user_filter) {
        $sql .= " AND us.User_ID = :user_id";
        $params[':user_id'] = $user_filter;
    }

    if ($status_filter) {
        if ($status_filter === 'active') {
            $sql .= " AND us.Last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        } elseif ($status_filter === 'idle') {
            $sql .= " AND us.Last_seen BETWEEN DATE_SUB(NOW(), INTERVAL 24 HOUR) AND DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        } elseif ($status_filter === 'expired') {
            $sql .= " AND us.Last_seen < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        }
    }

    $sql .= " ORDER BY us.Last_seen DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*) as total
        FROM user_sessions us
        LEFT JOIN users u ON us.User_ID = u.User_ID
        WHERE 1=1
    ";
    
    $countParams = [];
    if ($user_filter) {
        $countSql .= " AND us.User_ID = :user_id";
        $countParams[':user_id'] = $user_filter;
    }

    if ($status_filter) {
        if ($status_filter === 'active') {
            $countSql .= " AND us.Last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        } elseif ($status_filter === 'idle') {
            $countSql .= " AND us.Last_seen BETWEEN DATE_SUB(NOW(), INTERVAL 24 HOUR) AND DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        } elseif ($status_filter === 'expired') {
            $countSql .= " AND us.Last_seen < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        }
    }

    $countStmt = $db->prepare($countSql);
    foreach ($countParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get session statistics
    $statsSql = "
        SELECT 
            COUNT(*) as total_sessions,
            COUNT(CASE WHEN Last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 END) as active_sessions,
            COUNT(CASE WHEN Last_seen BETWEEN DATE_SUB(NOW(), INTERVAL 24 HOUR) AND DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 END) as idle_sessions,
            COUNT(CASE WHEN Last_seen < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as expired_sessions,
            COUNT(DISTINCT User_ID) as unique_users
        FROM user_sessions
    ";
    
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $sessions,
        'pagination' => [
            'total' => (int) $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ],
        'statistics' => $stats
    ]);
}

function handleDelete($db, $user) {
    $session_id = isset($_GET['session_id']) ? trim($_GET['session_id']) : null;
    
    if (!$session_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Session ID is required']);
        return;
    }

    // Check if this is the current user's session
    $current_session_id = session_id();
    $is_current_session = ($session_id === $current_session_id);

    // Delete the session from database
    $sql = "DELETE FROM user_sessions WHERE Session_ID = :session_id";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':session_id', $session_id);
    $result = $stmt->execute();

    if ($result && $stmt->rowCount() > 0) {
        // Log this activity
        logActivity('SESSION_TERMINATED', "Terminated session: $session_id");
        
        // If this is the current session, destroy the PHP session
        if ($is_current_session) {
            session_destroy();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Session terminated successfully',
            'current_session_terminated' => $is_current_session
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Session not found'
        ]);
    }
}

?>
