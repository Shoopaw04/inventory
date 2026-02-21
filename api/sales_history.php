<?php
// sales_history.php - Sales History and Reports API

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
    error_log("Sales History API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function handleGet($db, $user) {
    $terminal_id = isset($_GET['terminal_id']) ? (int) $_GET['terminal_id'] : null;
    $sale_id = isset($_GET['sale_id']) ? (int) $_GET['sale_id'] : null;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $payment_method = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $only_mine = isset($_GET['only_mine']) ? (int) $_GET['only_mine'] : 0;

    // If requesting specific sale details
    if ($sale_id) {
        getSaleDetails($db, $user, $sale_id);
        return;
    }

    $sql = "
        SELECT 
            s.Sale_ID,
            s.Terminal_ID,
            DATE_FORMAT(COALESCE(sm.sale_dt, CONCAT(s.Sale_date, ' 00:00:00')), '%Y-%m-%dT%H:%i:%s') as Sale_Date,
            s.Payment as Payment_Method,
            s.Total_Amount,
            s.Total_Amount as Subtotal,
            0 as Tax_Amount,
            COALESCE(item_counts.total_items, 0) as Total_Items,
            'COMPLETED' as Status,
            u.User_name as Cashier_Name,
            r.Role_name as Cashier_Role
        FROM sale s
        LEFT JOIN users u ON s.User_ID = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        LEFT JOIN (
            SELECT Sale_ID, SUM(Quantity) as total_items
            FROM sale_item
            GROUP BY Sale_ID
        ) item_counts ON s.Sale_ID = item_counts.Sale_ID
        LEFT JOIN (
            SELECT Reference_ID, MIN(`Timestamp`) as sale_dt, MAX(COALESCE(Terminal_ID, 0)) as sale_term
            FROM stock_movements
            WHERE Movement_type = 'SALE'
            GROUP BY Reference_ID
        ) sm ON sm.Reference_ID = s.Sale_ID
        WHERE 1=1
    ";
    
    $params = [];

    // Role-based scoping
    if (($user['Role_name'] ?? '') === 'Cashier') {
        // Cashier can only see their own sales; terminal filter is optional (use client-supplied terminal if present)
        if ($terminal_id) {
            $sql .= " AND COALESCE(s.Terminal_ID, sm.sale_term) = :terminal_id";
            $params[':terminal_id'] = $terminal_id;
        }
        $sql .= " AND s.User_ID = :current_user_id";
        $params[':current_user_id'] = (int)$user['User_ID'];
    } else {
        // Non-cashiers: apply optional filters
        if ($terminal_id) {
            $sql .= " AND COALESCE(s.Terminal_ID, sm.sale_term) = :terminal_id";
            $params[':terminal_id'] = $terminal_id;
        }
        if ($only_mine) {
            $sql .= " AND s.User_ID = :current_user_id";
            $params[':current_user_id'] = (int)$user['User_ID'];
        }
    }

    // Temporarily disable date filtering to debug
    // if ($date_from) {
    //     $sql .= " AND DATE(s.Sale_date) >= :date_from";
    //     $params[':date_from'] = $date_from;
    // }

    // if ($date_to) {
    //     $sql .= " AND DATE(s.Sale_date) <= :date_to";
    //     $params[':date_to'] = $date_to;
    // }

    if ($payment_method) {
        $sql .= " AND s.Payment = :payment_method";
        $params[':payment_method'] = $payment_method;
    }

    $sql .= " ORDER BY s.Sale_date DESC LIMIT :limit";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug logging
    error_log("Sales History Query: " . $sql);
    error_log("Sales History Params: " . json_encode($params));
    error_log("Sales History Results: " . count($sales) . " sales found");
    
    // Additional debug - log first few sales
    if (count($sales) > 0) {
        error_log("First sale: " . json_encode($sales[0]));
        error_log("Last sale: " . json_encode($sales[count($sales)-1]));
    }

    echo json_encode([
        'success' => true,
        'data' => $sales,
        'count' => count($sales)
    ]);
}

function getSaleDetails($db, $user, $sale_id) {
    // Get sale details with items
    $sql = "
        SELECT 
            s.Sale_ID,
            s.Terminal_ID,
            s.User_ID,
            DATE_FORMAT(COALESCE(sm.sale_dt, CONCAT(s.Sale_date, ' 00:00:00')), '%Y-%m-%dT%H:%i:%s') as Sale_Date,
            s.Payment as Payment_Method,
            s.Total_Amount,
            s.Total_Amount as Subtotal,
            0 as Tax_Amount,
            COALESCE(item_counts.total_items, 0) as Total_Items,
            'COMPLETED' as Status,
            u.User_name as Cashier_Name,
            r.Role_name as Cashier_Role
        FROM sale s
        LEFT JOIN users u ON s.User_ID = u.User_ID
        LEFT JOIN role r ON u.Role_ID = r.Role_ID
        LEFT JOIN (
            SELECT Sale_ID, SUM(Quantity) as total_items
            FROM sale_item
            WHERE Sale_ID = :sale_id1
            GROUP BY Sale_ID
        ) item_counts ON s.Sale_ID = item_counts.Sale_ID
        LEFT JOIN (
            SELECT Reference_ID, MIN(`Timestamp`) as sale_dt
            FROM stock_movements
            WHERE Movement_type = 'SALE'
            GROUP BY Reference_ID
        ) sm ON sm.Reference_ID = s.Sale_ID
        WHERE s.Sale_ID = :sale_id2
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':sale_id1', $sale_id, PDO::PARAM_INT);
    $stmt->bindValue(':sale_id2', $sale_id, PDO::PARAM_INT);
    $stmt->execute();
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug logging
    error_log("Sale Details Query: " . $sql);
    error_log("Sale Details Params: sale_id1=$sale_id, sale_id2=$sale_id");
    error_log("Sale Details Result: " . ($sale ? "Found sale" : "Sale not found"));

    if (!$sale) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sale not found']);
        return;
    }

    // Enforce cashier can only view own sale on their terminal
    if (($user['Role_name'] ?? '') === 'Cashier') {
        $termStmt = $db->prepare("SELECT Terminal_ID FROM terminal WHERE Current_User_ID = ? LIMIT 1");
        $termStmt->execute([(int)$user['User_ID']]);
        $termRow = $termStmt->fetch(PDO::FETCH_ASSOC);
        $enforcedTerminal = $termRow ? (int)$termRow['Terminal_ID'] : null;

        if ((int)$sale['User_ID'] !== (int)$user['User_ID'] || ($enforcedTerminal !== null && (int)$sale['Terminal_ID'] !== $enforcedTerminal)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            return;
        }
    }

    // Get sale items
    $itemsSql = "
        SELECT 
            si.*,
            p.Name as Product_Name,
            p.Retail_Price as Product_Price
        FROM sale_item si
        LEFT JOIN product p ON si.Product_ID = p.Product_ID
        WHERE si.Sale_ID = :sale_id
    ";
    
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->execute([':sale_id' => $sale_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sale['items'] = $items;

    echo json_encode([
        'success' => true,
        'data' => [$sale]
    ]);
}
?>