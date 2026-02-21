<?php
// Set proper encoding and error reporting
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't show errors in JSON response

// Headers with charset specification
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/Database.php';

$db = null;

try {
    // Get database connection
    $db = Database::getInstance()->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Set database charset to UTF-8
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Get parameters with proper encoding handling
    $q = isset($_GET['q']) ? mb_convert_encoding(trim($_GET['q']), 'UTF-8', 'auto') : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $stockStatus = isset($_GET['stock_status']) ? trim($_GET['stock_status']) : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $supplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
    $showInactive = isset($_GET['show_inactive']) ? ($_GET['show_inactive'] === 'true') : false;

    // Validate search query
    if (strlen($q) > 100) {
        throw new Exception('Search query too long');
    }

    // Log search for debugging
    error_log("Searching for: '$q' (length: " . strlen($q) . ")");

    // Build SQL with safe parameter binding
    $sql = "SELECT DISTINCT
        p.Product_ID AS product_id,
        p.Name AS name,
        p.Category_ID AS category_id,
        COALESCE(c.Category_Name, '') AS category_name,
        COALESCE(c.Description, '') AS category_description,
        COALESCE(p.Description, '') AS description,
        COALESCE(sp.Price_Offered, p.Retail_Price, 0.00) AS price,
        COALESCE(i.Quantity, 0) AS quantity,
        i.Last_update as last_update,
        COALESCE(p.Unit_measure, '') AS unit_measure,
        COALESCE(p.Batch_number, '') AS batch_number,
        p.Expiration_date AS expiration_date,
        COALESCE(p.Supplier_ID, 0) AS supplier_id,
        COALESCE(s.Name, '') AS supplier_name,
        COALESCE(p.Reorder_Level, 5) AS reorder_level,
        COALESCE(p.Is_discontinued, 0) AS is_discontinued,
        COALESCE(p.Display_stocks, 0) AS display_stocks
    FROM Product p
    LEFT JOIN (
        SELECT Product_ID, MAX(Last_update) AS Last_update, SUM(COALESCE(Quantity,0)) AS Quantity
        FROM Inventory
        GROUP BY Product_ID
    ) i ON i.Product_ID = p.Product_ID
    LEFT JOIN Category c ON c.Category_ID = p.Category_ID
    LEFT JOIN Supplier s ON s.Supplier_ID = p.Supplier_ID
    LEFT JOIN (
        SELECT Product_ID, MAX(Price_Offered) as Price_Offered
        FROM Supplier_Products
        GROUP BY Product_ID
    ) sp ON sp.Product_ID = p.Product_ID";

    $whereConditions = [];
    $params = [];

    // Hide inactive products
    if (!$showInactive) {
        $whereConditions[] = "COALESCE(p.Is_discontinued, 0) = 0";
    }

    // Search filter with encoding safety
    if (!empty($q)) {
        // Escape special characters that might cause issues
        $searchTerm = '%' . $q . '%';
        $whereConditions[] = "(
            p.Name LIKE ? OR 
            COALESCE(c.Category_Name, '') LIKE ? OR 
            COALESCE(p.Description, '') LIKE ? OR 
            COALESCE(s.Name, '') LIKE ?
        )";
        // Add the same parameter 4 times for the 4 LIKE conditions
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Category filter
    if (!empty($category)) {
        $whereConditions[] = "c.Category_Name = ?";
        $params[] = $category;
    }

    // Supplier filters: include both ID and Name when available to avoid missing rows
    if ($supplierId > 0 && !empty($supplier)) {
        $whereConditions[] = "(COALESCE(p.Supplier_ID, 0) = ? OR sp.Supplier_ID = ? OR s.Name = ?)";
        $params[] = $supplierId;
        $params[] = $supplierId;
        $params[] = $supplier;
    } elseif ($supplierId > 0) {
        $whereConditions[] = "(COALESCE(p.Supplier_ID, 0) = ? OR sp.Supplier_ID = ?)";
        $params[] = $supplierId;
        $params[] = $supplierId;
    } elseif (!empty($supplier)) {
        $whereConditions[] = "s.Name = ?";
        $params[] = $supplier;
    }

    // Build final query
    if (!empty($whereConditions)) {
        $sql .= " WHERE " . implode(" AND ", $whereConditions);
    }
    $sql .= " GROUP BY p.Product_ID, p.Name, p.Category_ID, c.Category_Name, c.Description, p.Description, i.Last_update, p.Unit_measure, p.Batch_number, p.Expiration_date, p.Supplier_ID, s.Name, p.Reorder_Level, p.Is_discontinued, p.Display_stocks, sp.Price_Offered
              ORDER BY p.Product_ID DESC LIMIT 1000";

    // Log the query for debugging
    error_log("Final SQL: " . $sql);
    error_log("Parameters: " . print_r($params, true));

    // Execute query with error checking
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $errorInfo = $db->errorInfo();
        throw new Exception('Failed to prepare SQL: ' . print_r($errorInfo, true));
    }

    $executeResult = $stmt->execute($params);
    if (!$executeResult) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception('Failed to execute SQL: ' . print_r($errorInfo, true));
    }

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($products === false) {
        throw new Exception('Failed to fetch results');
    }

    // Add calculated total_stock to each product
    foreach ($products as &$product) {
        $product['total_stock'] = (int) $product['quantity'] + (int) $product['display_stocks'];
    }

    // Apply stock status filter if needed
    if (!empty($stockStatus)) {
        $products = array_filter($products, function ($product) use ($stockStatus) {
            $totalStock = (int) $product['total_stock'];
            $reorderLevel = (int) $product['reorder_level'];

            switch ($stockStatus) {
                case 'in-stock':
                    return $totalStock > $reorderLevel;
                case 'low-stock':
                    return $totalStock > 0 && $totalStock <= $reorderLevel;
                case 'out-of-stock':
                    return $totalStock === 0;
                default:
                    return true;
            }
        });
        $products = array_values($products);
    }

    // Calculate final statistics
    $totalProducts = count($products);
    $totalValue = 0;
    $lowStockCount = 0;
    $outOfStockCount = 0;

    foreach ($products as $product) {
        $price = (float) $product['price'];
        $totalStock = (int) $product['total_stock'];
        $reorderLevel = (int) $product['reorder_level'];

        $totalValue += $price * $totalStock;

        if ($totalStock === 0) {
            $outOfStockCount++;
        } elseif ($totalStock <= $reorderLevel) {
            $lowStockCount++;
        }
    }

    // Get unique filter values
    $categories = array_values(array_unique(array_filter(array_column($products, 'category_name'))));
    $suppliers = array_values(array_unique(array_filter(array_column($products, 'supplier_name'))));

    // Final response
    echo json_encode([
        'success' => true,
        'products' => $products,
        'stats' => [
            'total_products' => $totalProducts,
            'total_value' => round($totalValue, 2),
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'categories_count' => count($categories)
        ],
        'filters' => [
            'categories' => $categories,
            'suppliers' => $suppliers
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log error details
    error_log("Product search error: " . $e->getMessage());
    error_log("Search query was: " . ($_GET['q'] ?? 'none'));

    if ($db && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'search_term' => $_GET['q'] ?? 'none',
            'error_line' => $e->getLine(),
            'error_file' => basename($e->getFile())
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>