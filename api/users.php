<?php
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Get users with roles that can approve stock-ins (Admin, Manager)
    $sql = "
        SELECT 
            u.User_ID,
            u.User_name,
            r.Role_name,
            r.Description as role_description,
            u.Status
        FROM users u
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE u.Status = 'active' 
        AND (r.Role_name IN ('Admin', 'Manager') OR u.User_ID = 1)
        ORDER BY r.Role_name, u.User_name
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get all users for general use (if needed elsewhere)
    $allUsersSql = "
        SELECT 
            u.User_ID,
            u.User_name,
            r.Role_name,
            r.Description as role_description,
            u.Status
        FROM users u
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        WHERE u.Status = 'active'
        ORDER BY r.Role_name, u.User_name
    ";
    
    $allStmt = $db->prepare($allUsersSql);
    $allStmt->execute();
    $allUsers = $allStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'approvers' => $users, // Users who can approve stock-ins
        'users' => $allUsers,  // All active users
        'count' => count($users)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>