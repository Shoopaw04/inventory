<?php
require_once __DIR__ . '/Auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password are required']);
    exit;
}

try {
    $db = dbConn();
    $stmt = $db->prepare('SELECT u.User_ID, u.User_name, u.Password, u.Role_ID, u.Status, r.Role_name
                          FROM users u LEFT JOIN role r ON r.Role_ID = u.Role_ID
                          WHERE u.User_name = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Validate user exists and is active (case-insensitive; allow common truthy variants)
    $status = strtolower((string)($user['Status'] ?? ''));
    $isActive = in_array($status, ['active','1','true','enabled'], true) || ($user['Status'] === 1);
    if (!$user || !$isActive) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    // Support both hashed and legacy plain-text passwords
    $stored = (string)$user['Password'];
    $passwordOk = false;
    if ($stored !== '') {
        if (preg_match('/^\$2y\$|^\$argon2/i', $stored) === 1) {
            $passwordOk = password_verify($password, $stored);
        } else {
            $passwordOk = hash_equals($stored, $password);
        }
    }

    if (!$passwordOk) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['User_ID'];

    // Track user session
    trackUserSession($db, (int)$user['User_ID'], session_id());
    
    // Log login activity
    logActivity('LOGIN', 'User logged in successfully');

    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => (int)$user['User_ID'],
            'username' => $user['User_name'],
            'role_id' => (int)$user['Role_ID'],
            'role_name' => $user['Role_name']
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

function trackUserSession($db, $user_id, $session_id) {
    try {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Check if session already exists
        $checkStmt = $db->prepare('SELECT Session_ID FROM user_sessions WHERE Session_ID = ?');
        $checkStmt->execute([$session_id]);
        
        if ($checkStmt->fetch()) {
            // Update existing session
            $updateStmt = $db->prepare('UPDATE user_sessions SET Last_seen = NOW(), User_Agent = ?, Ip_Address = ? WHERE Session_ID = ?');
            $updateStmt->execute([$user_agent, $ip_address, $session_id]);
        } else {
            // Create new session
            $insertStmt = $db->prepare('INSERT INTO user_sessions (Session_ID, User_ID, Created_at, Last_seen, User_Agent, Ip_Address) VALUES (?, ?, NOW(), NOW(), ?, ?)');
            $insertStmt->execute([$session_id, $user_id, $user_agent, $ip_address]);
        }
    } catch (Exception $e) {
        error_log("Failed to track user session: " . $e->getMessage());
    }
}



