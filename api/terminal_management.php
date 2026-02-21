<?php
// terminal_management.php - Terminal Management API

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

	// Only require admin for modifying terminals; allow GET for all authenticated users

	$method = $_SERVER['REQUEST_METHOD'];

	switch ($method) {
		case 'GET':
			handleGet($db);
			break;
		case 'POST':
			// Register/unregister current user for a terminal
			handlePost($db, $user);
			break;
		case 'PUT':
			// Admin-only
			$role = strtolower((string)($user['Role_name'] ?? ''));
			if ($role !== 'admin') {
				http_response_code(403);
				echo json_encode(['success' => false, 'error' => 'Admin access required']);
				exit;
			}
			handlePut($db);
			break;
		default:
			http_response_code(405);
			echo json_encode(['success' => false, 'error' => 'Method not allowed']);
	}
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage()
	]);
}

function handleGet($db) {
	// Get terminal data with current activity
	$sql = "
		SELECT 
			t.Terminal_ID as id,
			t.Name as name,
			t.Location as location,
			t.Status as status,
			t.Notes as notes,
			t.Created_Date as created_date,
			t.Last_Activity as last_activity,
			u.User_name as `current_user`,
			r.Role_name as current_user_role,
			COALESCE(daily_stats.sales_today, 0) as sales_today,
			COALESCE(daily_stats.transactions_today, 0) as transactions_today
		FROM terminal t
		LEFT JOIN users u ON t.Current_User_ID = u.User_ID
		LEFT JOIN role r ON u.Role_ID = r.Role_ID
		LEFT JOIN (
			SELECT 
				Terminal_ID,
				SUM(Total_Amount) as sales_today,
				COUNT(*) as transactions_today
			FROM sale 
			WHERE DATE(Sale_date) = CURDATE()
			GROUP BY Terminal_ID
		) daily_stats ON t.Terminal_ID = daily_stats.Terminal_ID
		ORDER BY t.Terminal_ID
	";
	
	$stmt = $db->prepare($sql);
	$stmt->execute();
	$terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// If no terminals exist, create default ones
	if (empty($terminals)) {
		createDefaultTerminals($db);
		$stmt->execute();
		$terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	echo json_encode([
		'success' => true,
		'data' => $terminals
	]);
}

function handlePost($db, $user) {
	$input = json_decode(file_get_contents('php://input'), true);
	if (!$input || !isset($input['terminal_id'])) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'terminal_id is required']);
		return;
	}
	$terminal_id = (int)$input['terminal_id'];
	$action = isset($input['action']) ? strtolower(trim($input['action'])) : 'register';

	if ($action === 'register') {
		$sql = "UPDATE terminal SET Current_User_ID = ?, Last_Activity = NOW() WHERE Terminal_ID = ?";
		$stmt = $db->prepare($sql);
		$stmt->execute([$user['User_ID'], $terminal_id]);
		echo json_encode(['success' => true, 'message' => 'User registered to terminal']);
		return;
	}
	
	if ($action === 'unregister') {
		// Only clear if the current user is the one set on the terminal to avoid stepping on others
		$sql = "UPDATE terminal SET Current_User_ID = NULL, Last_Activity = NOW() WHERE Terminal_ID = ? AND Current_User_ID = ?";
		$stmt = $db->prepare($sql);
		$stmt->execute([$terminal_id, $user['User_ID']]);
		echo json_encode(['success' => true, 'message' => 'User unregistered from terminal']);
		return;
	}
	
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function handlePut($db) {
	$input = json_decode(file_get_contents('php://input'), true);
	
	if (!$input || !isset($input['terminal_id'])) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Terminal ID required']);
		return;
	}

	$terminal_id = (int) $input['terminal_id'];
	$updates = [];
	$params = [':terminal_id' => $terminal_id];

	$allowed_fields = ['name', 'location', 'status', 'notes'];
	
	foreach ($allowed_fields as $field) {
		if (isset($input[$field])) {
			$updates[] = "$field = :$field";
			$params[":$field"] = $input[$field];
		}
	}

	if (empty($updates)) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'No fields to update']);
		return;
	}

	$updates[] = "Last_Activity = NOW()";
	
	$sql = "UPDATE terminal SET " . implode(', ', $updates) . " WHERE Terminal_ID = :terminal_id";
	$stmt = $db->prepare($sql);
	$result = $stmt->execute($params);

	if ($result) {
		// Log activity when status is updated
		if (isset($input['status'])) {
			logActivity('TERMINAL_STATUS_UPDATED', "Terminal #{$terminal_id} status set to {$input['status']}", $terminal_id);
		}
		echo json_encode([
			'success' => true,
			'message' => 'Terminal updated successfully'
		]);
	} else {
		echo json_encode([
			'success' => false,
			'error' => 'Failed to update terminal'
		]);
	}
}

function createDefaultTerminals($db) {
	$terminals = [
		[
			'name' => 'Terminal #1',
			'location' => 'Main Counter',
			'status' => 'online',
			'notes' => 'Primary checkout terminal'
		],
		[
			'name' => 'Terminal #2',
			'location' => 'Back Office',
			'status' => 'online',
			'notes' => 'Secondary terminal for office use'
		],
		[
			'name' => 'Terminal #3',
			'location' => 'Customer Service',
			'status' => 'online',
			'notes' => 'Customer service and returns'
		]
	];

	$sql = "
		INSERT INTO terminal (Name, Location, Status, Notes, Created_Date, Last_Activity)
		VALUES (:name, :location, :status, :notes, NOW(), NOW())
	";
	
	$stmt = $db->prepare($sql);
	
	foreach ($terminals as $terminal) {
		$stmt->execute([
			':name' => $terminal['name'],
			':location' => $terminal['location'],
			':status' => $terminal['status'],
			':notes' => $terminal['notes']
		]);
	}
}
?>
