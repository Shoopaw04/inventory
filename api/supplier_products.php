<?php
// Minimal endpoint: returns products for a supplier using supplier_products mapping and Product.Supplier_ID
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    if (!$db) { throw new Exception('DB connection failed'); }
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $supplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
    if ($supplierId <= 0) { throw new Exception('supplier_id is required'); }

    // Aggregate inventory per product
    $sql = "SELECT DISTINCT
                p.Product_ID AS product_id,
                p.Name AS name,
                COALESCE(c.Category_Name, '') AS category_name,
                COALESCE(p.Description, '') AS description,
                COALESCE(sp.Price_Offered, p.Retail_Price, 0.00) AS price,
                COALESCE(inv.Quantity, 0) AS quantity,
                inv.Last_update as last_update,
                COALESCE(p.Unit_measure, '') AS unit_measure,
                COALESCE(p.Batch_number, '') AS batch_number,
                p.Expiration_date AS expiration_date,
                COALESCE(p.Supplier_ID, 0) AS supplier_id,
                COALESCE(s.Name, '') AS supplier_name,
                COALESCE(p.Reorder_Level, 5) AS reorder_level,
                COALESCE(p.Is_discontinued, 0) AS is_discontinued,
                COALESCE(p.Display_stocks, 0) AS display_stocks
            FROM `product` p
            LEFT JOIN (
                SELECT Product_ID, MAX(Last_update) AS Last_update, SUM(COALESCE(Quantity,0)) AS Quantity
                FROM `inventory` GROUP BY Product_ID
            ) inv ON inv.Product_ID = p.Product_ID
            LEFT JOIN `category` c ON c.Category_ID = p.Category_ID
            LEFT JOIN `supplier` s ON s.Supplier_ID = p.Supplier_ID
            LEFT JOIN `supplier_products` sp ON sp.Product_ID = p.Product_ID AND sp.Supplier_ID = :sid_join
            WHERE (COALESCE(p.Supplier_ID, 0) = :sid_p OR sp.Supplier_ID = :sid_sp)
            AND COALESCE(p.Is_discontinued, 0) = 0
            ORDER BY p.Name ASC LIMIT 500";

    $stmt = $db->prepare($sql);
    $stmt->execute([':sid_join' => $supplierId, ':sid_p' => $supplierId, ':sid_sp' => $supplierId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['total_stock'] = (int)$r['quantity'] + (int)$r['display_stocks'];
    }

    echo json_encode(['success' => true, 'products' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>


