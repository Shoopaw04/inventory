<?php
// classes/Sale.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Inventory.php';

class SaleService
{
    private PDO $pdo;
    private Inventory $inventory;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->inventory = new Inventory($pdo);
    }

    /**
     * $user_id, $terminal_id, $items = [ ['product_id'=>int, 'quantity'=>int, 'price'=>float], ... ], $payment (string)
     */
    public function createSale(int $user_id, int $terminal_id, array $items, string $payment = 'CASH'): array
    {
        if (count($items) === 0) {
            throw new Exception("No items provided for sale.");
        }

        $this->pdo->beginTransaction();
        try {
            // 1) Pre-validate stock availability (FIFO check)
            foreach ($items as $it) {
                $pid = (int) $it['product_id'];
                $qty = (int) $it['quantity'];

                if ($qty <= 0) {
                    throw new Exception("Invalid quantity for product_id {$pid}");
                }

                // Check Display_stocks first (FIFO)
                $stockCheck = $this->pdo->prepare("SELECT Display_stocks, Name FROM Product WHERE Product_ID = :pid");
                $stockCheck->execute([':pid' => $pid]);
                $product = $stockCheck->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new Exception("Product with ID {$pid} not found.");
                }

                $availableDisplayStock = (int) $product['Display_stocks'];

                if ($availableDisplayStock < $qty) {
                    throw new Exception("Insufficient display stock for {$product['Name']}. Available: {$availableDisplayStock}, Requested: {$qty}");
                }
            }

            // 2) Calculate total
            $total = 0.0;
            foreach ($items as $it) {
                $total += ((float) $it['price']) * ((int) $it['quantity']);
            }

            // 3) Insert Sale header
            $stmt = $this->pdo->prepare("INSERT INTO sale (Sale_date, User_ID, Total_Amount, Terminal_ID, Payment) VALUES (NOW(), :uid, :total, :term, :payment)");
            $stmt->execute([':uid' => $user_id, ':total' => $total, ':term' => $terminal_id, ':payment' => $payment]);
            $sale_id = (int) $this->pdo->lastInsertId();

            // 4) For each item: insert Sale_Item and process through inventory system
            $insItem = $this->pdo->prepare("INSERT INTO sale_item (Sale_ID, Product_ID, Quantity, Sale_Price) VALUES (:sale_id, :product_id, :q, :price)");

            foreach ($items as $it) {
                $pid = (int) $it['product_id'];
                $qty = (int) $it['quantity'];
                $price = (float) $it['price'];

                // Insert sale item
                $insItem->execute([':sale_id' => $sale_id, ':product_id' => $pid, ':q' => $qty, ':price' => $price]);

                // FIXED: Use inventory system for proper stock movement logging
                // This will handle both stock deduction AND proper logging to stock_movements
                $this->inventory->adjustStock(
                    $pid,
                    -$qty,           // Negative for sale
                    $sale_id,        // Reference to sale
                    'SALE',          // Proper movement type
                    $user_id,
                    'Sales',         // Source table
                    $terminal_id
                );
            }

            $this->pdo->commit();
            return ['sale_id' => $sale_id, 'total' => $total];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction())
                $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Separate method for display stock replenishment - call this manually when needed
     */
    public function replenishDisplayStockIfNeeded(int $product_id, int $user_id, int $terminal_id = 1): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT p.Display_stocks, p.Reorder_Level, p.Name, COALESCE(i.Quantity, 0) as inventory_qty 
                FROM Product p 
                LEFT JOIN Inventory i ON i.Product_ID = p.Product_ID 
                WHERE p.Product_ID = :pid
            ");
            $stmt->execute([':pid' => $product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product)
                return false;

            $displayStock = (int) $product['Display_stocks'];
            $reorderLevel = (int) $product['Reorder_Level'];
            $inventoryQty = (int) $product['inventory_qty'];

            if ($displayStock <= $reorderLevel && $inventoryQty > 0) {
                $replenishQty = min($inventoryQty, $reorderLevel * 2);

                if ($replenishQty > 0) {
                    // FIXED: Log replenishment properly as separate transaction
                    $this->inventory->adjustStock(
                        $product_id,
                        -$replenishQty,      // Deduct from warehouse
                        0,                   // No specific reference
                        'REPLENISH_DISPLAY',
                        $user_id,
                        'Auto Replenishment',
                        $terminal_id
                    );

                    // Then add to display stock
                    $this->pdo->prepare("UPDATE Product SET Display_stocks = Display_stocks + :qty WHERE Product_ID = :pid")
                        ->execute([':qty' => $replenishQty, ':pid' => $product_id]);

                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            error_log("Failed to auto-replenish display stock for product {$product_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get sales summary
     */
    public function getSalesSummary(string $start_date = null, string $end_date = null): array
    {
        $sql = "SELECT COUNT(*) as total_sales, SUM(Total_Amount) as total_revenue FROM sale WHERE 1=1";
        $params = [];

        if ($start_date) {
            $sql .= " AND Sale_date >= :start_date";
            $params[':start_date'] = $start_date;
        }

        if ($end_date) {
            $sql .= " AND Sale_date <= :end_date";
            $params[':end_date'] = $end_date;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales' => 0, 'total_revenue' => 0];
    }

    /**
     * Get recent sales
     */
    public function getRecentSales(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.User_name, t.Name as Terminal_name 
            FROM sale s 
            LEFT JOIN users u ON s.User_ID = u.User_ID 
            LEFT JOIN terminal t ON s.Terminal_ID = t.Terminal_ID 
            ORDER BY s.Sale_date DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get sale details with items
     */
    public function getSaleDetails(int $sale_id): array
    {
        // Get sale header
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.User_name, t.Name as Terminal_name 
            FROM sale s 
            LEFT JOIN users u ON s.User_ID = u.User_ID 
            LEFT JOIN terminal t ON s.Terminal_ID = t.Terminal_ID 
            WHERE s.Sale_ID = :sale_id
        ");
        $stmt->execute([':sale_id' => $sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception("Sale not found");
        }

        // Get sale items
        $stmt = $this->pdo->prepare("
            SELECT si.*, p.Name as product_name 
            FROM sale_item si 
            JOIN Product p ON si.Product_ID = p.Product_ID 
            WHERE si.Sale_ID = :sale_id
        ");
        $stmt->execute([':sale_id' => $sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'sale' => $sale,
            'items' => $items
        ];
    }
}