<?php
// audit_logs.php - Audit Logs API (Admin Only)

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

    if ($user['Role_name'] !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied. Admin privileges required.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // 
    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        Audit_ID INT AUTO_INCREMENT PRIMARY KEY,
        User_ID INT NOT NULL,
        Entity VARCHAR(100) NOT NULL,
        Entity_ID VARCHAR(64) NULL,
        Action VARCHAR(50) NOT NULL,
        Before_State TEXT NULL,
        After_State TEXT NULL,
        Details TEXT NULL,
        Time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Terminal_ID INT NULL,
        Ip_Address VARCHAR(64) NULL,
        User_Agent VARCHAR(255) NULL,
        INDEX idx_entity (Entity, Entity_ID),
        INDEX idx_action_time (Action, Time),
        INDEX idx_user_time (User_ID, Time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 
    $colsStmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'");
    $colsStmt->execute();
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasBefore = in_array('Before_State', $columns, true);
    $hasAfter = in_array('After_State', $columns, true);
    $hasDetails = in_array('Details', $columns, true);
    $hasOld = in_array('old_values', $columns, true);
    $hasNew = in_array('new_values', $columns, true);

    // 
    $colId = in_array('Audit_ID', $columns, true) ? 'al.Audit_ID' : (in_array('log_id', $columns, true) ? 'al.log_id' : 'NULL');
    $colUser = in_array('User_ID', $columns, true) ? 'al.User_ID' : (in_array('changed_by', $columns, true) ? 'al.changed_by' : 'NULL');
    $colEntity = in_array('Entity', $columns, true) ? 'al.Entity' : (in_array('table_name', $columns, true) ? 'al.table_name' : "'Unknown'");
    $colEntityId = in_array('Entity_ID', $columns, true) ? 'al.Entity_ID' : (in_array('record_id', $columns, true) ? 'al.record_id' : 'NULL');
    $colAction = in_array('Action', $columns, true) ? 'al.Action' : (in_array('action', $columns, true) ? 'al.action' : "'UNKNOWN'");
    $colTime = in_array('Time', $columns, true) ? 'al.Time' : (in_array('changed_at', $columns, true) ? 'al.changed_at' : 'NOW()');
    $colTerminal = in_array('Terminal_ID', $columns, true) ? 'al.Terminal_ID' : 'NULL';

    $selectBeforeExpr = $hasBefore ? 'al.Before_State' : ($hasOld ? 'al.old_values' : 'NULL');
    $selectAfterExpr = $hasAfter ? 'al.After_State' : ($hasNew ? 'al.new_values' : 'NULL');
    $selectDetailsExpr = $hasDetails ? 'al.Details' : 'NULL';

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $entity = isset($_GET['entity']) ? trim($_GET['entity']) : null;
    $action = isset($_GET['action_type']) ? trim($_GET['action_type']) : null;
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;

    $sql = "
        SELECT 
            {$colId} AS Audit_ID,
            {$colUser} AS User_ID,
            u.User_name,
            r.Role_name,
            {$colEntity} AS Entity,
            {$colEntityId} AS Entity_ID,
            {$colAction} AS Action,
            {$selectBeforeExpr} AS Before_State,
            {$selectAfterExpr} AS After_State,
            {$selectDetailsExpr} AS Details,
            {$colTime} AS Time,
            {$colTerminal} AS Terminal_ID,
            NULL AS Ip_Address,
            NULL AS User_Agent
        FROM audit_log al
        LEFT JOIN users u ON {$colUser} = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE 1=1
    ";

    $params = [];
    if ($user_id) {
        $sql .= " AND {$colUser} = :user_id";
        $params[':user_id'] = $user_id;
    }
    if ($entity) {
        $sql .= " AND {$colEntity} = :entity";
        $params[':entity'] = $entity;
    }
    if ($action) {
        $sql .= " AND {$colAction} = :action";
        $params[':action'] = $action;
    }
    if ($date_from) {
        $sql .= " AND {$colTime} >= :date_from";
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $sql .= " AND {$colTime} <= :date_to";
        $params[':date_to'] = $date_to . ' 23:59:59';
    }

    $limitInt = max(1, (int)$limit);
    $offsetInt = max(0, (int)$offset);
    $sql .= " ORDER BY {$colTime} DESC LIMIT $limitInt OFFSET $offsetInt";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 
    $countSql = "SELECT COUNT(*) AS total FROM audit_log al WHERE 1=1";
    $countParams = [];
    if ($user_id) { $countSql .= " AND {$colUser} = :user_id"; $countParams[':user_id'] = $user_id; }
    if ($entity) { $countSql .= " AND {$colEntity} = :entity"; $countParams[':entity'] = $entity; }
    if ($action) { $countSql .= " AND {$colAction} = :action"; $countParams[':action'] = $action; }
    if ($date_from) { $countSql .= " AND {$colTime} >= :date_from"; $countParams[':date_from'] = $params[':date_from']; }
    if ($date_to) { $countSql .= " AND {$colTime} <= :date_to"; $countParams[':date_to'] = $params[':date_to']; }

    $countStmt = $db->prepare($countSql);
    foreach ($countParams as $k => $v) { $countStmt->bindValue($k, $v); }
    $countStmt->execute();
    $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    echo json_encode([
        'success' => true,
        'data' => $logs,
        'pagination' => [
            'total' => $total,
            'limit' => $limitInt,
            'offset' => $offsetInt,
            'has_more' => ($offsetInt + $limitInt) < $total
        ]
    ]);
} catch (Throwable $e) {
    error_log('Audit Logs API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>


