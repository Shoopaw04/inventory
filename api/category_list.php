<?php

header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sql = "SELECT 
        c.Category_ID AS category_id,
        c.Category_Name AS category_name,
        c.Description AS description,
        COUNT(p.Product_ID) AS product_count
    FROM Category c
    LEFT JOIN Product p ON p.Category_ID = c.Category_ID";

    $params = [];

    if ($q !== '') {
        $sql .= " WHERE c.Category_Name LIKE :q OR c.Description LIKE :q";
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= " GROUP BY c.Category_ID, c.Category_Name, c.Description
              ORDER BY c.Category_Name ASC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'categories' => $rows]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>