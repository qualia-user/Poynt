<?php

namespace App\Services;

use App\Core\Context;
use Doctrine\DBAL\Exception as DBALException;

/**
 * PoyntDataService provides minimal upsert helpers for the extended
 * Poynt schema. Each method expects the incoming array to already be
 * mapped to column names used in the database. Only very small
 * conveniences are provided – mainly a generic ON CONFLICT upsert.
 */
class PoyntDataService
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Generic helper used by all save* methods. Builds a simple INSERT ..
     * ON CONFLICT statement based on provided data and primary key columns.
     *
     * @param string $table
     * @param array  $data         associative array column=>value
     * @param array  $primaryKeys  columns forming the primary key
     * @return void
     */
    private function upsert(string $table, array $data, array $primaryKeys): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);
        $insertCols = implode(', ', $columns);
        $insertVals = implode(', ', $placeholders);

        $updateCols = array_diff($columns, $primaryKeys);
        $updateSet = implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", $updateCols));
        if ($updateSet !== '') {
            $updateSet .= ', updated_at = NOW()';
        } else {
            $updateSet = 'updated_at = NOW()';
        }

        $pk = implode(', ', $primaryKeys);
        $sql = "INSERT INTO {$table} ({$insertCols}) VALUES ({$insertVals}) "
             . "ON CONFLICT ({$pk}) DO UPDATE SET {$updateSet}";

        try {
            $this->context->getConn()->executeStatement($sql, $data);
        } catch (DBALException $e) {
            $this->context->getLog()->error("PoyntDataService upsert into {$table} failed: " . $e->getMessage());
        }
    }

    // ────────────────────────────── Orders ──────────────────────────────

    public function saveOrder(array $order): void
    {
        $this->upsert('"order"', $order, ['order_id']);
    }

    public function saveOrderItems(string $orderId, array $items): void
    {
        foreach ($items as $item) {
            $item['order_id'] = $orderId;
            $this->upsert('order_item', $item, ['order_id', 'order_item_id']);
        }
    }

    public function saveOrderHistory(string $orderId, array $history): void
    {
        foreach ($history as $event) {
            $event['order_id'] = $orderId;
            $this->upsert('order_history', $event, ['order_id', 'event', 'ts_ext']);
        }
    }

    public function saveOrderShipments(string $orderId, array $shipments): void
    {
        foreach ($shipments as $shipment) {
            $shipment['order_id'] = $orderId;
            $this->upsert('order_shipment', $shipment, ['order_id', 'shipment_id']);
        }
    }

    // ─────────────────────────── Transactions ──────────────────────────

    public function saveTransaction(array $txn): void
    {
        $this->upsert('transaction', $txn, ['transaction_id']);
    }

    public function saveTransactionReceipt(array $receipt): void
    {
        $this->upsert('transaction_receipt', $receipt, ['transaction_id']);
    }

    // ───────────────────────────── Customers ────────────────────────────

    public function saveCustomer(array $customer): void
    {
        $this->upsert('customer', $customer, ['customer_id']);
    }

    // ─────────────────────────── Business users ─────────────────────────

    public function saveBusinessUser(array $user): void
    {
        $this->upsert('business_user', $user, ['business_id', 'user_id']);
    }

    // ───────────────────────────── Products ─────────────────────────────

    public function saveProduct(array $product): void
    {
        $this->upsert('product', $product, ['product_id']);
    }

    public function saveProductVariants(string $productId, array $variants): void
    {
        foreach ($variants as $variant) {
            $variant['product_id'] = $productId;
            $this->upsert('product_variant', $variant, ['product_id', 'variant_id']);
        }
    }

    // ───────────────────────────── Inventory ────────────────────────────

    public function saveInventorySummary(array $summary): void
    {
        $this->upsert('inventory_summary', $summary, ['business_id', 'product_id']);
    }

    public function saveInventory(array $inventory): void
    {
        $this->upsert('inventory', $inventory, ['business_id', 'store_id', 'product_id']);
    }

    public function saveVariantInventory(array $inventory): void
    {
        $this->upsert('variant_inventory', $inventory, ['business_id', 'store_id', 'product_id', 'variant_id']);
    }

    // ──────────────────────── Catalogs & Categories ─────────────────────

    public function saveCatalog(array $catalog): void
    {
        $this->upsert('catalog', $catalog, ['catalog_id']);
    }

    public function saveCatalogProducts(string $catalogId, array $products): void
    {
        foreach ($products as $product) {
            $product['catalog_id'] = $catalogId;
            $this->upsert('catalog_product', $product, ['catalog_id', 'product_id']);
        }
    }

    public function saveCategory(array $category): void
    {
        $this->upsert('category', $category, ['category_id']);
    }

    // ───────────────────────────── Taxes ────────────────────────────────

    public function saveTax(array $tax): void
    {
        $this->upsert('tax', $tax, ['tax_id']);
    }

    // ──────────────────────────── Paylinks ──────────────────────────────

    public function savePaylink(array $paylink): void
    {
        $this->upsert('paylink', $paylink, ['paylink_id']);
    }

    // ───────────────────────────── Hooks ────────────────────────────────

    public function saveHook(array $hook): void
    {
        $this->upsert('hook', $hook, ['hook_id']);
    }

    public function saveHookDelivery(array $delivery): void
    {
        $this->upsert('hook_delivery', $delivery, ['delivery_id']);
    }
}

