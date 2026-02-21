<?php
require_once __DIR__ . '/Auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$u = currentUser();
if (!$u) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Add session validation to prevent cross-tab contamination
if (empty($_SESSION['user_id']) || $_SESSION['user_id'] !== $u['User_ID']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session invalid']);
    exit;
}

echo json_encode(['success' => true, 'data' => $u]);


