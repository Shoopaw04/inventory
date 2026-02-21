<?php
// activity_logs.php - Activity Logs API (Admin Only)

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
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
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("Activity Logs API Error: " . $e->getMessage());
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
    $activity_filter = isset($_GET['activity_type']) ? trim($_GET['activity_type']) : null;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : null;
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : null;

    $sql = "
        SELECT 
            al.Log_ID,
            al.User_ID,
            u.User_name,
            r.Role_name,
            al.Activity_type,
            al.Time
        FROM activity_log al
        LEFT JOIN users u ON al.User_ID = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";
    
    $params = [];

    if ($user_filter) {
        $sql .= " AND al.User_ID = :user_id";
        $params[':user_id'] = $user_filter;
    }

    if ($activity_filter) {
        $sql .= " AND al.Activity_type = :activity_type";
        $params[':activity_type'] = $activity_filter;
    }

    // Temporarily disable date filtering to debug
    // if ($date_from) {
    //     $sql .= " AND DATE(al.Time) >= :date_from";
    //     $params[':date_from'] = $date_from;
    // }

    // if ($date_to) {
    //     $sql .= " AND DATE(al.Time) <= :date_to";
    //     $params[':date_to'] = $date_to;
    // }

    $sql .= " ORDER BY al.Time DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*) as total
        FROM activity_log al
        LEFT JOIN users u ON al.User_ID = u.User_ID
        WHERE 1=1
    ";
    
    $countParams = [];
    if ($user_filter) {
        $countSql .= " AND al.User_ID = :user_id";
        $countParams[':user_id'] = $user_filter;
    }

    if ($activity_filter) {
        $countSql .= " AND al.Activity_type = :activity_type";
        $countParams[':activity_type'] = $activity_filter;
    }

    if ($date_from) {
        $countSql .= " AND DATE(al.Time) >= :date_from";
        $countParams[':date_from'] = $date_from;
    }

    if ($date_to) {
        $countSql .= " AND DATE(al.Time) <= :date_to";
        $countParams[':date_to'] = $date_to;
    }

    $countStmt = $db->prepare($countSql);
    foreach ($countParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // activity statistics
    $statsSql = "
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT User_ID) as unique_users,
            COUNT(DISTINCT Activity_type) as unique_activity_types,
            COUNT(CASE WHEN Time >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as activities_today,
            COUNT(CASE WHEN Time >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as activities_week,
            COUNT(CASE WHEN Time >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as activities_month
        FROM activity_log
    ";
    
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // activity type breakdown
    $breakdownSql = "
        SELECT 
            Activity_type,
            COUNT(*) as count,
            COUNT(DISTINCT User_ID) as unique_users
        FROM activity_log
        WHERE Time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY Activity_type
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $breakdownStmt = $db->prepare($breakdownSql);
    $breakdownStmt->execute();
    $breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

    // user activity 
    $userBreakdownSql = "
        SELECT 
            u.User_name,
            r.Role_name,
            COUNT(al.Log_ID) as activity_count,
            MAX(al.Time) as last_activity
        FROM activity_log al
        LEFT JOIN users u ON al.User_ID = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE al.Time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY al.User_ID, u.User_name, r.Role_name
        ORDER BY activity_count DESC
        LIMIT 10
    ";
    
    $userBreakdownStmt = $db->prepare($userBreakdownSql);
    $userBreakdownStmt->execute();
    $userBreakdown = $userBreakdownStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $activities,
        'pagination' => [
            'total' => (int) $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ],
        'statistics' => $stats,
        'activity_breakdown' => $breakdown,
        'user_breakdown' => $userBreakdown
    ]);
}
?>
