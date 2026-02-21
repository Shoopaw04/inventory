<?php
// stock_movements.php - Enhanced with Terminal Names
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Enhanced parameters
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $movement_type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $terminal_id = isset($_GET['terminal_id']) ? (int) $_GET['terminal_id'] : 0;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

    // Calculate offset for pagination
    $offset = ($page - 1) * $limit;

    // Count query for pagination
    $countSql = "
        SELECT COUNT(*) as total_count
        FROM stock_movements sm
        JOIN product p ON sm.Product_ID = p.Product_ID
        LEFT JOIN terminal t ON sm.Terminal_ID = t.Terminal_ID
        LEFT JOIN users u ON sm.Performed_by = u.User_ID
        WHERE 1=1
    ";

  $sql = "
    SELECT 
        sm.Transaction_ID,
        sm.Product_ID,
        p.Name as product_name,
        sm.Movement_type,
        sm.Quantity,
        sm.Reference_ID,
        sm.Performed_by,
        COALESCE(u.User_name, 'System') as performed_by_name,
        sm.Timestamp,
        sm.Updated_at,
        sm.Source_Table,
        sm.Terminal_ID,
        CASE 
            WHEN sm.Terminal_ID IS NULL OR sm.Terminal_ID = 0 THEN 
                CASE 
                    WHEN sm.Movement_type IN ('INITIAL_STOCK', 'DISPLAY_STOCK_IN') THEN 'Web Interface'
                    ELSE 'System'
                END
            ELSE COALESCE(t.Name, 'Unknown Terminal')
        END as terminal_name,
        CASE 
            WHEN sm.Terminal_ID IS NULL OR sm.Terminal_ID = 0 THEN ''
            ELSE COALESCE(t.Location, 'Unknown Location')
        END as terminal_location,
        CASE 
            WHEN sm.Source_Table = 'purchase_order' THEN CONCAT('PO #', sm.Reference_ID)
            WHEN sm.Source_Table = 'sales' THEN CONCAT('Sale #', sm.Reference_ID)
            WHEN sm.Source_Table = 'supplier_return' THEN CONCAT('Return #', sm.Reference_ID)
            WHEN sm.Source_Table = 'stockin_inventory' THEN CONCAT('Stock In #', sm.Reference_ID)
            WHEN sm.Source_Table = 'manual_adjustments' THEN CONCAT('Adjustment #', sm.Reference_ID)
            WHEN sm.Source_Table = 'auto_replenishment' THEN 'Auto Replenish'
            WHEN sm.Source_Table = 'Inventory' AND sm.Movement_type = 'ADJUSTMENT_IN' THEN 'New Product - Initial Stock'
            WHEN sm.Source_Table = 'Product' AND sm.Movement_type = 'ADJUSTMENT_IN' THEN 'New Product - Display Stock'
            WHEN sm.Source_Table = 'Inventory' AND sm.Movement_type = 'INITIAL_STOCK' THEN 'Initial Stock'
            WHEN sm.Source_Table = 'Product' AND sm.Movement_type = 'DISPLAY_STOCK_IN' THEN 'Display Stock'
            WHEN sm.Source_Table = 'transfer_stock' THEN 'Stock Transfer'
            ELSE CONCAT('Ref #', COALESCE(sm.Reference_ID, 'N/A'))
        END as reference_info,
        CASE 
            WHEN sm.Movement_type IN ('PURCHASE_RECEIPT', 'STOCK_IN', 'INITIAL_STOCK', 'DISPLAY_STOCK_IN') THEN '📦'
            WHEN sm.Movement_type = 'SALE' THEN '💰'
            WHEN sm.Movement_type IN ('RETURN', 'REPLACEMENT_EXPECTED', 'REPLACEMENT_RECEIVED') THEN '↩️'
            WHEN sm.Movement_type IN ('ADJUSTMENT_IN', 'ADJUSTMENT_OUT') THEN '⬆'
            WHEN sm.Movement_type LIKE '%DAMAGE%' THEN '💥'
            WHEN sm.Movement_type = 'REPLENISH_DISPLAY' THEN '🔄'
            WHEN sm.Movement_type IN ('TRANSFER_IN', 'TRANSFER_OUT') THEN '🔄'
            ELSE '📋'
        END as movement_icon,
        CASE 
            WHEN sm.Movement_type IN ('PURCHASE_RECEIPT', 'STOCK_IN', 'ADJUSTMENT_IN', 'REPLACEMENT_RECEIVED', 'INITIAL_STOCK', 'DISPLAY_STOCK_IN', 'TRANSFER_IN') THEN 'IN'
            WHEN sm.Movement_type IN ('SALE', 'RETURN', 'DAMAGE', 'ADJUSTMENT_OUT', 'REPLENISH_DISPLAY', 'TRANSFER_OUT') THEN 'OUT'
            WHEN sm.Movement_type = 'REPLACEMENT_EXPECTED' THEN 'NEUTRAL'
            ELSE 'NEUTRAL'
        END as movement_direction,
        CASE 
            WHEN sm.Movement_type = 'RETURN' THEN 'Returned to Supplier'
            WHEN sm.Movement_type = 'REPLACEMENT_EXPECTED' THEN 'Replacement Expected'
            WHEN sm.Movement_type = 'REPLACEMENT_RECEIVED' THEN 'Replacement Received'
            WHEN sm.Movement_type = 'ADJUSTMENT_IN' AND sm.Source_Table = 'Inventory' THEN 'Initial Stock Added'
            WHEN sm.Movement_type = 'ADJUSTMENT_IN' AND sm.Source_Table = 'Product' THEN 'Display Stock Added'
            WHEN sm.Movement_type = 'ADJUSTMENT_IN' THEN 'Inventory Adjustment (In)'
            WHEN sm.Movement_type = 'ADJUSTMENT_OUT' THEN 'Inventory Adjustment (Out)'
            WHEN sm.Movement_type = 'PURCHASE_RECEIPT' AND sm.Source_Table = 'stockin_inventory' THEN 'Stock Received'
            WHEN sm.Movement_type = 'STOCK_IN' THEN 'Stock Received'
            WHEN sm.Movement_type = 'INITIAL_STOCK' THEN 'Initial Stock Added'
            WHEN sm.Movement_type = 'DISPLAY_STOCK_IN' THEN 'Display Stock Added'
            WHEN sm.Movement_type = 'SALE' THEN 'Sale Transaction'
            WHEN sm.Movement_type = 'REPLENISH_DISPLAY' THEN 'Stock Transfer to Display'
            WHEN sm.Movement_type = 'TRANSFER_IN' THEN 'Stock Transfer In'
            WHEN sm.Movement_type = 'TRANSFER_OUT' THEN 'Stock Transfer Out'
            ELSE REPLACE(sm.Movement_type, '_', ' ')
        END as movement_description
    FROM stock_movements sm
    JOIN product p ON sm.Product_ID = p.Product_ID
    LEFT JOIN terminal t ON sm.Terminal_ID = t.Terminal_ID
    LEFT JOIN users u ON sm.Performed_by = u.User_ID
    WHERE 1=1
";

    $params = [];

    // Apply filters to both count and main query
    if ($search !== '') {
        $searchCondition = " AND (p.Name LIKE :search OR sm.Movement_type LIKE :search OR t.Terminal_Name LIKE :search OR u.User_name LIKE :search)";
        $sql .= $searchCondition;
        $countSql .= $searchCondition;
        $params[':search'] = '%' . $search . '%';
    }

    if ($movement_type !== '') {
        $typeCondition = " AND sm.Movement_type = :movement_type";
        $sql .= $typeCondition;
        $countSql .= $typeCondition;
        $params[':movement_type'] = $movement_type;
    }

    if ($terminal_id > 0) {
        $terminalCondition = " AND sm.Terminal_ID = :terminal_id";
        $sql .= $terminalCondition;
        $countSql .= $terminalCondition;
        $params[':terminal_id'] = $terminal_id;
    }

    if ($product_id > 0) {
        $productCondition = " AND sm.Product_ID = :product_id";
        $sql .= $productCondition;
        $countSql .= $productCondition;
        $params[':product_id'] = $product_id;
    }

    if ($date_from !== '') {
        $dateFromCondition = " AND DATE(sm.Timestamp) >= :date_from";
        $sql .= $dateFromCondition;
        $countSql .= $dateFromCondition;
        $params[':date_from'] = $date_from;
    }

    if ($date_to !== '') {
        $dateToCondition = " AND DATE(sm.Timestamp) <= :date_to";
        $sql .= $dateToCondition;
        $countSql .= $dateToCondition;
        $params[':date_to'] = $date_to;
    }

    // Get total count for pagination
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total_count'];

    // Add ordering and pagination
    if ($limit <= 0)
        $limit = 100;
    $sql .= " ORDER BY sm.Timestamp DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get terminals for filter dropdown (remove Status filter if column doesn't exist)
    $terminalStmt = $db->prepare("SELECT Terminal_ID, Name as Terminal_Name, Location FROM terminal ORDER BY Name");
    $terminalStmt->execute();
    $terminals = $terminalStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get movement types for filter dropdown
    $movementTypesStmt = $db->prepare("SELECT DISTINCT Movement_type FROM stock_movements ORDER BY Movement_type");
    $movementTypesStmt->execute();
    $movementTypes = $movementTypesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Calculate pagination info
    $totalPages = ceil($totalCount / $limit);

    // Format timestamps for better readability
    foreach ($movements as &$movement) {
        $movement['formatted_timestamp'] = date('M j, Y g:i A', strtotime($movement['Timestamp']));
        $movement['formatted_updated_at'] = $movement['Updated_at'] ? date('M j, Y g:i A', strtotime($movement['Updated_at'])) : null;
        $movement['date_only'] = date('Y-m-d', strtotime($movement['Timestamp']));
        $movement['time_only'] = date('g:i A', strtotime($movement['Timestamp']));
    }

    // Get summary statistics
    $summaryStmt = $db->prepare("
        SELECT 
            sm.Movement_type,
            COUNT(*) as count,
            SUM(sm.Quantity) as total_quantity,
            COUNT(CASE WHEN sm.Movement_type IN ('PURCHASE_RECEIPT', 'STOCK_IN', 'ADJUSTMENT_IN', 'REPLACEMENT_RECEIVED', 'INITIAL_STOCK', 'DISPLAY_STOCK_IN') THEN 1 END) as in_movements,
            COUNT(CASE WHEN sm.Movement_type IN ('SALE', 'RETURN', 'DAMAGE', 'ADJUSTMENT_OUT', 'REPLENISH_DISPLAY') THEN 1 END) as out_movements
        FROM stock_movements sm
        WHERE DATE(sm.Timestamp) >= COALESCE(:date_from, DATE_SUB(CURDATE(), INTERVAL 30 DAY))
        AND DATE(sm.Timestamp) <= COALESCE(:date_to, CURDATE())
        GROUP BY sm.Movement_type
        ORDER BY count DESC
    ");

    $summaryStmt->execute([
        ':date_from' => $date_from ?: null,
        ':date_to' => $date_to ?: null
    ]);
    $movementSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $movements,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'limit' => $limit,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ],
        'filters' => [
            'terminals' => $terminals,
            'movement_types' => $movementTypes
        ],
        'summary' => [
            'total_movements' => $totalCount,
            'current_showing' => count($movements),
            'movement_breakdown' => $movementSummary,
            'applied_filters' => [
                'search' => $search !== '' ? $search : null,
                'movement_type' => $movement_type !== '' ? $movement_type : null,
                'terminal_id' => $terminal_id > 0 ? $terminal_id : null,
                'product_id' => $product_id > 0 ? $product_id : null,
                'date_from' => $date_from !== '' ? $date_from : null,
                'date_to' => $date_to !== '' ? $date_to : null
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Stock Movements API Error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>