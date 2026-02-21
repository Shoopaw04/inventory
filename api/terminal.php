<?php
// terminals.php - API endpoint for fetching terminals
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/Database.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance()->getConnection();

    if ($method === 'GET') {
        handleGetTerminals($db);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleGetTerminals($db)
{
    $active_only = isset($_GET['active_only']) && $_GET['active_only'] === 'true';
    $location = isset($_GET['location']) ? trim($_GET['location']) : '';

    // Build the query
    $sql = "
        SELECT 
            Terminal_ID,
            Terminal_name,
            Location,
            Status,
            Created_at,
            Updated_at,
            CASE 
                WHEN Status = 'active' THEN '✅ Active'
                WHEN Status = 'inactive' THEN '❌ Inactive'
                WHEN Status = 'maintenance' THEN '🔧 Maintenance'
                ELSE Status
            END as status_display
        FROM Terminal
        WHERE 1=1
    ";

    $params = [];

    // Add active status filter
    if ($active_only) {
        $sql .= " AND Status = 'active'";
    }

    // Add location filter
    if ($location !== '') {
        $sql .= " AND Location LIKE :location";
        $params[':location'] = '%' . $location . '%';
    }

    $sql .= " ORDER BY Terminal_name ASC";

    $stmt = $db->prepare($sql);

    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get summary statistics
    $summary_sql = "
        SELECT 
            COUNT(*) as total_terminals,
            SUM(CASE WHEN Status = 'active' THEN 1 ELSE 0 END) as active_terminals,
            SUM(CASE WHEN Status = 'inactive' THEN 1 ELSE 0 END) as inactive_terminals,
            SUM(CASE WHEN Status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_terminals
        FROM Terminal
    ";

    $summary_stmt = $db->prepare($summary_sql);
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $terminals,
        'count' => count($terminals),
        'summary' => $summary,
        'filters_applied' => [
            'active_only' => $active_only,
            'location' => $location
        ]
    ]);
}
?>