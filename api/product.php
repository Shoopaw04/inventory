    <?php
    header("Content-Type: application/json");
    require_once __DIR__ . '/Database.php';
    require_once __DIR__ . '/Auth.php';

    class Product
    {
        private $db;

        public function __construct($database)
        {
            $this->db = $database;
        }

        public function createProductWithInventory($data)
        {
            try {
                $this->db->beginTransaction();

                // 1. Get or create category
                $categoryId = $this->getOrCreateCategory(
                    $data['category_name'],
                    $data['category_description'] ?? null
                );

                // 2. Insert product directly into Product table (includes Display_stocks column)
                $sql = "INSERT INTO Product (
                            Name, Description, Category_ID, Supplier_ID,
                            Retail_Price, Expiration_date, Batch_number, 
                            Reorder_Level, Unit_measure, Is_discontinued, Display_stocks
                        ) VALUES (
                            :name, :description, :category_id, :supplier_id,
                            :retail_price, :expiration_date, :batch_number,
                            :reorder_level, :unit_measure, :is_discontinued, :display_stocks
                        )";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':name' => $data['name'],
                    ':description' => $data['description'] ?? null,
                    ':category_id' => $categoryId,
                    ':supplier_id' => $data['supplier_id'] ?? null,
                    ':retail_price' => $data['retail_price'] ?? 0,
                    ':expiration_date' => $data['expiration_date'] ?? null,
                    ':batch_number' => $data['batch_number'] ?? null,
                    ':reorder_level' => $data['reorder_level'] ?? 0,
                    ':unit_measure' => $data['unit_measure'] ?? null,
                    ':is_discontinued' => $data['is_discontinued'] ?? 0,
                    ':display_stocks' => $data['display_stocks'] ?? 0
                ]);

                $productId = $this->db->lastInsertId();

                // 3. Inventory (warehouse stock)
                $initialStock = $data['initial_stock'] ?? 0;
                $this->createInventoryRecord($productId, $initialStock, $data['reorder_level'] ?? 0);

                // 4. Stock movements
                if ($initialStock > 0) {
                    $this->addStockMovement($productId, 'IN', $initialStock, $data);
                }
                if (!empty($data['display_stocks']) && $data['display_stocks'] > 0) {
                    $this->addStockMovement($productId, 'IN', $data['display_stocks'], $data, 'Product');
                }

                $this->db->commit();

                return [
                    'success' => true,
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'initial_stock' => $initialStock,
                    'display_stocks' => $data['display_stocks'] ?? 0,
                    'message' => 'Product created successfully'
                ];
            } catch (Exception $e) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        private function getOrCreateCategory($categoryName, $description = null)
        {
            $sql = "SELECT Category_ID FROM Category WHERE Category_Name = :name LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':name' => $categoryName]);
            $result = $stmt->fetch();

            if ($result) {
                return $result['Category_ID'];
            }

            $sql = "INSERT INTO Category (Category_Name, Description) VALUES (:name, :description)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $categoryName,
                ':description' => $description
            ]);

            return $this->db->lastInsertId();
        }

        private function createInventoryRecord($productId, $initialQuantity, $reorderLevel)
        {
            $sql = "INSERT INTO Inventory (Product_ID, Last_update, Quantity, Reorder_level) 
                    VALUES (:product_id, NOW(), :quantity, :reorder_level)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity' => $initialQuantity,
                ':reorder_level' => $reorderLevel
            ]);
        }

       private function addStockMovement($productId, $movementType, $quantity, $data, $sourceTable = 'Inventory')
{
    // Determine proper movement type - use ADJUSTMENT_IN as per database enum constraints
    $properMovementType = 'ADJUSTMENT_IN';
    
    // Ensure quantity is always positive for initial stock
    $finalQuantity = abs($quantity);
    
    $sql = "INSERT INTO stock_movements (
                Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, 
                Updated_at, Source_Table, Terminal_ID, Timestamp
            ) VALUES (
                :product_id, :movement_type, :quantity, :reference_id, :performed_by,
                NOW(), :source_table, :terminal_id, NOW()
            )";

    $stmt = $this->db->prepare($sql);
    
    $params = [
        ':product_id' => $productId,
        ':movement_type' => $properMovementType,
        ':quantity' => $finalQuantity, 
        ':reference_id' => $productId,
        ':performed_by' => $data['performed_by'] ?? null,
        ':terminal_id' => $data['terminal_id'] ?? null,
        ':source_table' => $sourceTable
    ];
    
    $stmt->execute($params);
}
    }

    // ================== API ENTRYPOINT ==================
    $response = ['success' => false, 'message' => 'Invalid request'];

    try {
        // Check authentication and role
        $user = currentUser();
        if (!$user) {
            http_response_code(401);
            $response = ['success' => false, 'error' => 'Authentication required'];
            echo json_encode($response);
            exit;
        }

        // Check if user has Admin or Manager role
        if (!in_array($user['Role_name'], ['Admin', 'Manager'])) {
            http_response_code(403);
            $response = ['success' => false, 'error' => 'Access denied. Admin or Manager role required.'];
            echo json_encode($response);
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $product = new Product($db);

        // accept JSON or form-data
        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input) {
            $input = $_POST;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($input['name']) || !isset($input['category_name'])) {
                throw new Exception("Product name and category are required.");
            }
            
            // Use current user for performed_by
            $input['performed_by'] = $user['User_ID'];
            
            $response = $product->createProductWithInventory($input);
            
            // Log activity for product creation
            if ($response['success']) {
                logActivity('Adding Product', 'Created new product: ' . $input['name'], null);
            }
            
            // Log audit trail for product creation
            if ($response['success']) {
                $productId = $response['product_id'];
                $productData = [
                    'name' => $input['name'],
                    'category_name' => $input['category_name'],
                    'retail_price' => $input['retail_price'] ?? 0.00,
                    'reorder_level' => $input['reorder_level'] ?? 0,
                    'initial_stock' => $input['initial_stock'] ?? 0,
                    'is_discontinued' => 0
                ];
                
                try {
                    logAudit('Product', $productId, 'CREATE', null, $productData);
                } catch (Exception $e) {
                    // Log the error but don't fail the product creation
                    error_log("Audit logging failed for product creation: " . $e->getMessage());
                }
            }
        } else {
            throw new Exception("Only POST method is allowed.");
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
    ?>