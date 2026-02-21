<?php
// customer_returns.php - Customer Returns API

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

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    switch ($method) {
        case 'GET':
            $sub = $_GET['sub'] ?? null;
            if ($sub === 'lookup_sale') {
                handleLookupSale($db, $user);
            } elseif ($sub === 'my_sales') {
                handleMySales($db, $user);
            } elseif ($sub === 'details') {
                handleDetails($db, $user);
            } else {
                handleGet($db, $user);
            }
            break;
        case 'POST':
            if ($action === 'create') {
                handleCreate($db, $user);
            } elseif ($action === 'complete_refund') {
                handleCompleteRefund($db, $user);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown POST action']);
            }
            break;
        case 'PUT':
            if ($action === 'decide') {
                handleDecide($db, $user);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown PUT action']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    error_log('Customer Returns API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleGet(PDO $db, array $user): void {
    $scope = $_GET['scope'] ?? null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : null;
    $status = $_GET['status'] ?? null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

    $approvedCol = resolveApprovedTimestampColumn($db);
    $terminalExpr = 'COALESCE(cr.Terminal_ID, s.Terminal_ID)';
    $sql = "
        SELECT cr.Return_ID, cr.SaleItem_ID, cr.Product_ID, cr.Quantity, cr.Reason, cr.Return_Date, cr.Status,
               cr.Approved_by, cr.Return_Type, " . ($approvedCol ? ("cr." . $approvedCol . " AS Approved_Date") : "NULL AS Approved_Date") . ",
               si.Sale_ID, si.Sale_Price, p.Name AS Product_Name,
               u.User_name AS Approved_By_Name, r.Role_name AS Approved_By_Role,
               " . $terminalExpr . " AS Terminal_ID, s.User_ID AS Cashier_ID, uc.User_name AS Cashier_Name
        FROM customer_return cr
        LEFT JOIN sale_item si ON si.SaleItem_ID = cr.SaleItem_ID
        LEFT JOIN sale s ON s.Sale_ID = si.Sale_ID
        LEFT JOIN product p ON p.Product_ID = cr.Product_ID
        LEFT JOIN users u ON u.User_ID = cr.Approved_by
        LEFT JOIN role r ON r.Role_ID = u.Role_ID
        LEFT JOIN users uc ON uc.User_ID = s.User_ID
        WHERE 1=1
    ";
    $params = [];

    if ($scope === 'pending') {
        $sql .= " AND cr.Status = 'Pending'";
    }

    if ($status) {
        $sql .= " AND cr.Status = :status";
        $params[':status'] = $status;
    }

    if ($sale_id) {
        $sql .= " AND si.Sale_ID = :sale_id";
        $params[':sale_id'] = $sale_id;
    }

    if ($date_from) {
        $sql .= " AND cr.Return_Date >= :date_from";
        $params[':date_from'] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND cr.Return_Date <= :date_to";
        $params[':date_to'] = $date_to;
    }

    // Cashier scoping: prefer Created_by when present; fallback to sale owner
    if (($user['Role_name'] ?? '') === 'Cashier') {
        $hasCreated = hasColumn($db, 'customer_return', 'Created_by');
        if ($hasCreated) {
            $sql .= " AND (cr.Created_by = :me1 OR (cr.Created_by IS NULL AND s.User_ID = :me2))";
            $params[':me1'] = (int)$user['User_ID'];
            $params[':me2'] = (int)$user['User_ID'];
        } else {
            $sql .= " AND s.User_ID = :me";
            $params[':me'] = (int)$user['User_ID'];
        }
    }

    $limitVal = max(1, (int)$limit);
    $offsetVal = max(0, (int)$offset);
    $sql .= " ORDER BY cr.Return_ID DESC LIMIT $limitVal OFFSET $offsetVal";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
}

function handleLookupSale(PDO $db, array $user): void {
    // Cashier/Manager/Admin can lookup a sale by sale_id or receipt number
    if (!in_array($user['Role_name'], ['Cashier', 'Manager', 'Admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        return;
    }

    $sale_id = isset($_GET['sale_id']) ? (int) $_GET['sale_id'] : null;
    $receipt = isset($_GET['receipt']) ? trim($_GET['receipt']) : null; // alias of sale_id if your receipt maps 1:1
    $date = isset($_GET['date']) ? trim($_GET['date']) : null;

    if (!$sale_id && !$receipt && !$date) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide sale_id/receipt or date']);
        return;
    }

    $sql = "
        SELECT s.Sale_ID, s.Terminal_ID, s.User_ID, s.Sale_date,
               s.Payment as Payment_Method, s.Total_Amount,
               u.User_name as Cashier_Name,
               r.Role_name as Cashier_Role
        FROM sale s
        LEFT JOIN users u ON u.User_ID = s.User_ID
        LEFT JOIN role r ON r.Role_ID = u.Role_ID
        WHERE 1=1
    ";
    $params = [];
    if ($sale_id || $receipt) {
        $sql .= " AND s.Sale_ID = :sid";
        $params[':sid'] = $sale_id ?: (int)$receipt;
    }
    if ($date) {
        $sql .= " AND DATE(s.Sale_date) = :dt";
        $params[':dt'] = $date;
    }

    // Cashier visibility: only own terminal and own sales
    if ($user['Role_name'] === 'Cashier') {
        $t = $db->prepare("SELECT Terminal_ID FROM terminal WHERE Current_User_ID = ? LIMIT 1");
        $t->execute([$user['User_ID']]);
        $row = $t->fetch(PDO::FETCH_ASSOC);
        $term = $row ? (int)$row['Terminal_ID'] : null;
        if ($term !== null) {
            $sql .= " AND s.Terminal_ID = :tid AND s.User_ID = :uid";
            $params[':tid'] = $term;
            $params[':uid'] = (int)$user['User_ID'];
        }
    }

    $sql .= " ORDER BY s.Sale_ID DESC LIMIT 10";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load items for each sale
    $itemsStmt = $db->prepare("SELECT si.SaleItem_ID, si.Product_ID, si.Quantity, si.Sale_Price, p.Name FROM sale_item si LEFT JOIN product p ON p.Product_ID = si.Product_ID WHERE si.Sale_ID = ?");
    foreach ($sales as &$s) {
        $itemsStmt->execute([$s['Sale_ID']]);
        $s['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $sales]);
}

function handleMySales(PDO $db, array $user): void {
    // Only Cashier/Manager/Admin; cashiers only see their own
    if (!in_array($user['Role_name'], ['Cashier', 'Manager', 'Admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        return;
    }
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    $sql = "
        SELECT s.Sale_ID, s.Terminal_ID, s.User_ID, s.Sale_date, s.Total_Amount
        FROM sale s
        WHERE 1=1
    ";
    $params = [];
    if ($user['Role_name'] === 'Cashier') {
        $sql .= " AND s.User_ID = :uid";
        $params[':uid'] = (int)$user['User_ID'];
    }
    $sql .= " ORDER BY s.Sale_ID DESC LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach first few items for preview
    $itemsStmt = $db->prepare("SELECT si.SaleItem_ID, si.Product_ID, si.Quantity, si.Sale_Price, p.Name FROM sale_item si LEFT JOIN product p ON p.Product_ID = si.Product_ID WHERE si.Sale_ID = ? LIMIT 5");
    foreach ($sales as &$s) {
        $itemsStmt->execute([$s['Sale_ID']]);
        $s['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $sales]);
}

function handleCreate(PDO $db, array $user): void {
    // Cashier or Manager/Admin can create
    if (!in_array($user['Role_name'], ['Cashier', 'Manager', 'Admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $items = $input['items'] ?? [];
    $return_type = strtoupper($input['return_type'] ?? 'REFUND'); // REFUND or REPLACE
    $auto_apply = (bool)($input['auto_apply'] ?? false); // when true and rule passes, immediately mark completed
    $return_date = date('Y-m-d');

    if (!is_array($items) || empty($items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No items provided']);
        return;
    }

    $db->beginTransaction();
    try {
        $createdIds = [];
        ensureCreatedByColumn($db);
        // Determine active terminal for the requesting user (if available)
        $terminalId = null;
        try {
            $t = $db->prepare("SELECT Terminal_ID FROM terminal WHERE Current_User_ID = ? LIMIT 1");
            $t->execute([$user['User_ID']]);
            $tr = $t->fetch(PDO::FETCH_ASSOC);
            if ($tr && isset($tr['Terminal_ID'])) { $terminalId = (int)$tr['Terminal_ID']; }
        } catch (Throwable $e) { /* ignore */ }

        $hasTerminalCol = hasColumn($db, 'customer_return', 'Terminal_ID');
        if ($hasTerminalCol) {
            $stmt = $db->prepare("INSERT INTO customer_return (SaleItem_ID, Product_ID, Quantity, Reason, Return_Date, Status, Approved_by, Return_Type, Created_by, Terminal_ID) VALUES (?, ?, ?, ?, ?, 'Pending', NULL, ?, ?, ?)");
        } else {
            $stmt = $db->prepare("INSERT INTO customer_return (SaleItem_ID, Product_ID, Quantity, Reason, Return_Date, Status, Approved_by, Return_Type, Created_by) VALUES (?, ?, ?, ?, ?, 'Pending', NULL, ?, ?)");
        }

        $seenSaleItems = [];
        foreach ($items as $it) {
            $saleItemId = (int)($it['sale_item_id'] ?? 0);
            $productId = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $reason = trim($it['reason'] ?? 'Other');
            if ($saleItemId <= 0 || $productId <= 0 || $qty <= 0) {
                throw new RuntimeException('Invalid item payload');
            }
            // Partial returns policy: allow multiple requests as long as cumulative qty does not exceed sold qty
            $soldStmt = $db->prepare("SELECT Quantity FROM sale_item WHERE SaleItem_ID = ?");
            $soldStmt->execute([$saleItemId]);
            $soldRow = $soldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$soldRow) {
                throw new RuntimeException("Sale item not found: #$saleItemId");
            }
            $originalQty = (int)$soldRow['Quantity'];
            $alreadyStmt = $db->prepare("SELECT COALESCE(SUM(Quantity),0) AS used FROM customer_return WHERE SaleItem_ID = ? AND Status IN ('Pending','Approved')");
            $alreadyStmt->execute([$saleItemId]);
            $already = (int)($alreadyStmt->fetch(PDO::FETCH_ASSOC)['used'] ?? 0);
            $inRequest = (int)($seenSaleItems[$saleItemId] ?? 0);
            $remaining = $originalQty - $already - $inRequest;
            if ($remaining <= 0) {
                throw new RuntimeException("SaleItem #$saleItemId is fully returned already");
            }
            if ($qty > $remaining) {
                throw new RuntimeException("Only $remaining unit(s) remaining available to return for SaleItem #$saleItemId");
            }
            $seenSaleItems[$saleItemId] = $inRequest + $qty;
            if ($hasTerminalCol) {
                // Fallback: derive terminal from the sale if not found via terminal table
                if ($terminalId === null) {
                    $saleMeta = findSaleBySaleItem($db, $saleItemId);
                    if ($saleMeta && isset($saleMeta['Terminal_ID'])) {
                        $terminalId = (int)$saleMeta['Terminal_ID'];
                    }
                }
                $stmt->execute([$saleItemId, $productId, $qty, $reason, $return_date, $return_type, (int)$user['User_ID'], $terminalId]);
            } else {
                $stmt->execute([$saleItemId, $productId, $qty, $reason, $return_date, $return_type, (int)$user['User_ID']]);
            }
            $createdId = (int)$db->lastInsertId();
            $createdIds[] = $createdId;
            logAudit('Customer_Return', $createdId, 'CREATE', null, [
                'SaleItem_ID' => $saleItemId,
                'Product_ID' => $productId,
                'Quantity' => $qty,
                'Reason' => $reason,
                'Return_Type' => $return_type
            ]);

            // Auto-approval rule engine (basic): <= 50 amount and <= 30 days since purchase
            if ($auto_apply) {
                $saleRow = findSaleBySaleItem($db, $saleItemId);
                if ($saleRow) {
                    $daysSince = daysSinceDate($saleRow['Sale_date'] ?? null);
                    $itemAmount = getSaleItemAmount($db, $saleItemId, $qty);
                    if ($itemAmount !== null && $itemAmount <= 50 && $daysSince !== null && $daysSince <= 30) {
                        autoApproveReturn($db, $user, $createdId, $productId, $qty, $reason);
                    }
                }
            }
        }

        $db->commit();
        logActivity('CUSTOMER_RETURN_CREATED');
        echo json_encode(['success' => true, 'created_ids' => $createdIds]);
    } catch (Throwable $e) {
        $db->rollBack();
        // Validation/runtime issues should not be 500
        if ($e instanceof RuntimeException) {
            http_response_code(400);
        } else {
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleDecide(PDO $db, array $user): void {
    // Manager/Admin only
    if (!in_array($user['Role_name'], ['Manager', 'Admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        return;
    }

    parse_str(file_get_contents('php://input'), $inputRaw);
    $payload = json_decode(file_get_contents('php://input'), true);
    $input = is_array($payload) ? $payload : $inputRaw;

    $ids = $input['return_ids'] ?? [];
    if (is_string($ids)) { $ids = [$ids]; }
    $decision = strtoupper($input['decision'] ?? ''); // APPROVED | REJECTED
    $comments = trim($input['comments'] ?? '');
    $exchangeOnly = (bool)($input['exchange_only'] ?? false);

    if (empty($ids) || !in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid decision payload']);
        return;
    }

    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        // Load rows
        $inPlace = implode(',', array_fill(0, count($ids), '?'));
        $fetch = $db->prepare("SELECT * FROM customer_return WHERE Return_ID IN ($inPlace) FOR UPDATE");
        $fetch->execute(array_map('intval', $ids));
        $rows = $fetch->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            throw new RuntimeException('No returns found');
        }

        $approvedCol = getApprovedTimestampColumnForWrite($db);
        $upd = $db->prepare("UPDATE customer_return SET Status = ?, Approved_by = ?, $approvedCol = ? WHERE Return_ID = ?");

        foreach ($rows as $row) {
            $before = $row;
            $upd->execute([$decision === 'APPROVED' ? 'Approved' : 'Rejected', $user['User_ID'], $now, $row['Return_ID']]);

            if ($decision === 'APPROVED' && !$exchangeOnly) {
                // Inventory impact depends on reason
                $reason = strtolower((string)$row['Reason']);
                $productId = (int)$row['Product_ID'];
                $qty = (int)$row['Quantity'];
                $saleItemId = (int)$row['SaleItem_ID'];

                if (strpos($reason, 'damage') !== false || strpos($reason, 'expired') !== false) {
                    // Record as damaged; do not return to sellable inventory
                    $dg = $db->prepare("INSERT INTO damage_goods (Product_ID, Quantity, Date_reported, Reported_by, Reason, Adjustment_ID, Status, Terminal_ID) VALUES (?, ?, CURDATE(), ?, ?, NULL, 'Logged', ?)");
                    $dg->execute([$productId, $qty, $user['User_ID'], $row['Reason'], $row['Terminal_ID'] ?? null]);
                    // Log stock movement as DAMAGE
                    $sm = $db->prepare("INSERT INTO stock_movements (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Terminal_ID, Timestamp) VALUES (?, 'DAMAGE', ?, ?, ?, NOW(), 'customer_return', ?, NOW())");
                    $sm->execute([$productId, $qty, $row['Return_ID'], $user['User_ID'], $row['Terminal_ID'] ?? null]);
                } else {
                    // Restockable: increase inventory and log RETURN
                    $invSel = $db->prepare("SELECT Inventory_ID, Quantity FROM inventory WHERE Product_ID = ? FOR UPDATE");
                    $invSel->execute([$productId]);
                    $inv = $invSel->fetch(PDO::FETCH_ASSOC);
                    if ($inv) {
                        $newQty = (int)$inv['Quantity'] + $qty;
                        $invUpd = $db->prepare("UPDATE inventory SET Quantity = ?, Last_update = NOW() WHERE Inventory_ID = ?");
                        $invUpd->execute([$newQty, $inv['Inventory_ID']]);
                    }
                    $sm = $db->prepare("INSERT INTO stock_movements (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Terminal_ID, Timestamp) VALUES (?, 'RETURN', ?, ?, ?, NOW(), 'customer_return', ?, NOW())");
                    $sm->execute([$productId, $qty, $saleItemId, $user['User_ID'], $row['Terminal_ID'] ?? null]);
                }
            }

            logAudit('Customer_Return', (int)$row['Return_ID'], 'STATUS_CHANGE', $before, [
                'Status' => $decision === 'APPROVED' ? 'Approved' : 'Rejected',
                'Approved_by' => $user['User_ID'],
                'Comments' => $comments,
                'Exchange_Only' => $exchangeOnly ? 1 : 0
            ]);
        }

        $db->commit();
        logActivity($decision === 'APPROVED' ? 'CUSTOMER_RETURN_APPROVED' : 'CUSTOMER_RETURN_REJECTED');
        echo json_encode(['success' => true, 'updated' => array_map('intval', $ids)]);
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleCompleteRefund(PDO $db, array $user): void {
    // Cashier finalizes refund after approval
    if (!in_array($user['Role_name'], ['Cashier', 'Manager', 'Admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $return_ids = $input['return_ids'] ?? [];
    if (is_string($return_ids)) { $return_ids = [$return_ids]; }
    if (empty($return_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No return IDs provided']);
        return;
    }

    // For now just log completion; payment processing happens in POS
    foreach ($return_ids as $rid) {
        logAudit('Customer_Return', (int)$rid, 'REFUND_COMPLETED', null, null, 'Refund processed at POS');
    }
    logActivity('CUSTOMER_RETURN_REFUND_COMPLETED');
    echo json_encode(['success' => true]);
}

// Helpers for auto-approval
function findSaleBySaleItem(PDO $db, int $saleItemId): ?array {
    $stmt = $db->prepare("SELECT s.Sale_ID, s.Sale_date, s.Terminal_ID FROM sale s JOIN sale_item si ON si.Sale_ID = s.Sale_ID WHERE si.SaleItem_ID = ?");
    $stmt->execute([$saleItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function handleDetails(PDO $db, array $user): void {
    $id = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid return_id']);
        return;
    }
    $approvedCol = resolveApprovedTimestampColumn($db);
    $terminalExpr = 'COALESCE(cr.Terminal_ID, s.Terminal_ID)';
    $sql = "
        SELECT cr.Return_ID, cr.SaleItem_ID, cr.Product_ID, cr.Quantity, cr.Reason, cr.Return_Date, cr.Status,
               cr.Approved_by, cr.Return_Type, " . ($approvedCol ? ("cr." . $approvedCol . " AS Approved_Date") : "NULL AS Approved_Date") . ",
               si.Sale_ID, si.Sale_Price,
               p.Name AS Product_Name,
               u.User_name AS Approved_By_Name, r.Role_name AS Approved_By_Role,
               " . $terminalExpr . " AS Terminal_ID, s.User_ID AS Cashier_ID, uc.User_name AS Cashier_Name,
               ua.User_name AS Requested_By_Name, ra.Role_name AS Requested_By_Role
        FROM customer_return cr
        LEFT JOIN sale_item si ON si.SaleItem_ID = cr.SaleItem_ID
        LEFT JOIN sale s ON s.Sale_ID = si.Sale_ID
        LEFT JOIN product p ON p.Product_ID = cr.Product_ID
        LEFT JOIN users u ON u.User_ID = cr.Approved_by
        LEFT JOIN role r ON r.Role_ID = u.Role_ID
        LEFT JOIN users uc ON uc.User_ID = s.User_ID
        LEFT JOIN users ua ON ua.User_ID = cr.Created_by
        LEFT JOIN role ra ON ra.Role_ID = ua.Role_ID
        WHERE cr.Return_ID = :id
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Return not found']);
        return;
    }
    echo json_encode(['success' => true, 'data' => $row]);
}

function getSaleItemAmount(PDO $db, int $saleItemId, int $qty): ?float {
    $stmt = $db->prepare("SELECT Sale_Price, Quantity FROM sale_item WHERE SaleItem_ID = ?");
    $stmt->execute([$saleItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $price = (float)$row['Sale_Price'];
    $originalQty = (int)$row['Quantity'];
    $applyQty = max(1, min($qty, $originalQty));
    return $price * $applyQty;
}

function daysSinceDate(?string $date): ?int {
    if (!$date) return null;
    $d1 = new DateTime($date);
    $d2 = new DateTime();
    return (int)$d1->diff($d2)->days;
}

function autoApproveReturn(PDO $db, array $user, int $returnId, int $productId, int $qty, string $reason): void {
    $now = date('Y-m-d H:i:s');
    ensureApprovedDateColumn($db);
    $upd = $db->prepare("UPDATE customer_return SET Status = 'Approved', Approved_by = ?, Approved_Date = ? WHERE Return_ID = ?");
    $upd->execute([$user['User_ID'], $now, $returnId]);

    // Apply inventory or damage flow immediately
    if (stripos($reason, 'damage') !== false || stripos($reason, 'expired') !== false) {
        $dg = $db->prepare("INSERT INTO damage_goods (Product_ID, Quantity, Date_reported, Reported_by, Reason, Adjustment_ID, Status, Terminal_ID) VALUES (?, ?, CURDATE(), ?, ?, NULL, 'Logged', NULL)");
        $dg->execute([$productId, $qty, $user['User_ID'], $reason]);
        $sm = $db->prepare("INSERT INTO stock_movements (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Terminal_ID, Timestamp) VALUES (?, 'DAMAGE', ?, ?, ?, NOW(), 'customer_return', NULL, NOW())");
        $sm->execute([$productId, $qty, $returnId, $user['User_ID']]);
    } else {
        $invSel = $db->prepare("SELECT Inventory_ID, Quantity FROM inventory WHERE Product_ID = ? FOR UPDATE");
        $invSel->execute([$productId]);
        $inv = $invSel->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $newQty = (int)$inv['Quantity'] + $qty;
            $invUpd = $db->prepare("UPDATE inventory SET Quantity = ?, Last_update = NOW() WHERE Inventory_ID = ?");
            $invUpd->execute([$newQty, $inv['Inventory_ID']]);
        }
        $sm = $db->prepare("INSERT INTO stock_movements (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Terminal_ID, Timestamp) VALUES (?, 'RETURN', ?, ?, ?, NOW(), 'customer_return', NULL, NOW())");
        $sm->execute([$productId, $qty, $returnId, $user['User_ID']]);
    }
    logAudit('Customer_Return', $returnId, 'AUTO_APPROVED', null, ['qty' => $qty, 'product_id' => $productId, 'reason' => $reason]);
    logActivity('CUSTOMER_RETURN_AUTO_APPROVED');
}

function ensureApprovedDateColumn(PDO $db): void {
    // Create Approved_Date column if missing (DATETIME NULL)
    try {
        $check = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_return' AND COLUMN_NAME = 'Approved_Date'");
        $check->execute();
        $exists = (bool)$check->fetch(PDO::FETCH_NUM);
        if (!$exists) {
            $db->exec("ALTER TABLE customer_return ADD COLUMN Approved_Date DATETIME NULL");
        }
    } catch (Throwable $e) {
        // As a fallback (for engines that support IF NOT EXISTS)
        try { $db->exec("ALTER TABLE customer_return ADD COLUMN IF NOT EXISTS Approved_Date DATETIME NULL"); } catch (Throwable $ignored) {}
    }
}

function ensureCreatedByColumn(PDO $db): void {
    try {
        $check = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_return' AND COLUMN_NAME = 'Created_by'");
        $check->execute();
        $exists = (bool)$check->fetch(PDO::FETCH_NUM);
        if (!$exists) {
            $db->exec("ALTER TABLE customer_return ADD COLUMN Created_by INT NULL");
        }
    } catch (Throwable $e) {
        try { $db->exec("ALTER TABLE customer_return ADD COLUMN IF NOT EXISTS Created_by INT NULL"); } catch (Throwable $ignored) {}
    }
}

function backfillCreatedByIfMissing(PDO $db): void {
    try {
        // Backfill Created_by using the sale's User_ID for rows that have it NULL
        $db->exec("UPDATE customer_return cr
                   LEFT JOIN sale_item si ON si.SaleItem_ID = cr.SaleItem_ID
                   LEFT JOIN sale s ON s.Sale_ID = si.Sale_ID
                   SET cr.Created_by = s.User_ID
                   WHERE cr.Created_by IS NULL AND s.User_ID IS NOT NULL");
    } catch (Throwable $e) {
        // best-effort; ignore failures
    }
}

function hasColumn(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

function resolveApprovedTimestampColumn(PDO $db): ?string {
    // Prefer Approved_Date; fallback to approved_at/Approved_At variants
    if (hasColumn($db, 'customer_return', 'Approved_Date')) return 'Approved_Date';
    if (hasColumn($db, 'customer_return', 'Approved_At')) return 'Approved_At';
    if (hasColumn($db, 'customer_return', 'approved_at')) return 'approved_at';
    return null;
}

function getApprovedTimestampColumnForWrite(PDO $db): string {
    $col = resolveApprovedTimestampColumn($db);
    if ($col) return $col;
    // Create Approved_Date if neither exists
    try {
        $db->exec("ALTER TABLE customer_return ADD COLUMN Approved_Date DATETIME NULL");
    } catch (Throwable $e) {
        // ignore
    }
    return 'Approved_Date';
}

?>


