<?php
require_once __DIR__ . '/Auth.php';

header('Content-Type: application/json');

try {
    $user = currentUser();
    $session_id = session_id();
    
    // Log logout activity before destroying session
    if ($user) {
        $db = dbConn();
        logActivity('LOGOUT', 'User logged out successfully');
        
        // Remove session from user_sessions table
        $stmt = $db->prepare('DELETE FROM user_sessions WHERE Session_ID = ?');
        $stmt->execute([$session_id]);
    }
} catch (Exception $e) {
    error_log("Error during logout: " . $e->getMessage());
}

// Invalidate session safely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

echo json_encode(['success' => true]);



