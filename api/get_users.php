<?php
require_once __DIR__ . '/Database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    //
    $stmt = $db->prepare("SELECT User_ID, Username, Name FROM users WHERE Status = 'Active' ORDER BY Name");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>