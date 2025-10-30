<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\FetchResponseLogger;
use App\Services\Support\PoyntDataFormatter as Format;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class OrderService
{
    private const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    private Context $context;
    private ClientInterface $httpClient;
    private ?string $businessId = null;

    public function __construct(Context $context, ?string $businessId = null, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
        if ($businessId !== null) {
            $this->businessId = $businessId;
        }
    }

    public function fetchByBusinessId(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);
        $accessToken = $tokenService->getMerchantToken($businessId);

        if (!$accessToken) {
            $this->context->getLog()->warning(
                sprintf('OrderService::fetchByBusinessId: missing merchant token for business %s', $businessId)
            );
            return false;
        }

        try {
            $response = $this->httpClient->get(self::POYNT_ENDPOINT . '/' . $businessId . '/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (isset($data['orders']) && is_array($data['orders'])) {
                $payload = $data['orders'];

                FetchResponseLogger::info(
                    $this->context->getLog(),
                    'OrderService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'orders',
                        'payload' => $payload,
                    ]
                );

                return $payload;
            }

            if (is_array($data)) {
                FetchResponseLogger::info(
                    $this->context->getLog(),
                    'OrderService::fetchByBusinessId response',
                    [
                        'businessId' => $businessId,
                        'entity' => 'orders',
                        'payload' => $data,
                    ]
                );

                return $data;
            }

            return false;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error(
                sprintf('OrderService::fetchByBusinessId: %s', $e->getMessage())
            );

            return false;
        }
    }

    /**
     * Upsert order and related entities (items, history, shipments).
     *
     * @param array $orderData
     */
    public function upsert(array $orderData): bool
    {
        $businessId = $this->coalesceValue($orderData, [
            ['businessId'],
            ['context', 'businessId'],
        ]);

        if (!isset($orderData['id']) || $businessId === null) {
            $this->context->getLog()->error(
                'OrderService::upsert: missing required order fields (id or businessId)'
            );
            return false;
        }

        $orderId = $orderData['id'];
        $storeId = $this->coalesceValue($orderData, [
            ['storeId'],
            ['context', 'storeId'],
        ]);
        $status = $this->coalesceValue($orderData, [
            ['status'],
            ['statuses', 'status'],
        ]);
        $fulfillmentStatus = $this->coalesceValue($orderData, [
            ['fulfillmentStatus'],
            ['statuses', 'fulfillmentStatus'],
        ]);
        $transactionStatusSummary = $this->coalesceValue($orderData, [
            ['transactionStatusSummary'],
            ['statuses', 'transactionStatusSummary'],
        ]);

        $currency = $this->coalesceValue($orderData, [
            ['currency'],
            ['amounts', 'currency'],
            ['totals', 'currency'],
            ['amounts', 'transactionAmount', 'currency'],
            ['totals', 'transactionAmount', 'currency'],
        ]);

        $subtotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'subtotal'],
            ['amounts', 'subTotal'],
            ['totals', 'subtotal'],
            ['totals', 'subTotal'],
        ]);
        $discountMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'discount'],
            ['totals', 'discount'],
            ['totals', 'discountTotal'],
        ]);
        $taxTotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'tax'],
            ['totals', 'tax'],
            ['totals', 'taxTotal'],
        ]);
        $tipTotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'tip'],
            ['amounts', 'tipAmount'],
            ['totals', 'tip'],
        ]);
        $feeTotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'fee'],
            ['amounts', 'feeAmount'],
            ['totals', 'fee'],
        ]);
        $shippingTotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'shipping'],
            ['totals', 'shipping'],
            ['totals', 'shippingTotal'],
        ]);
        $netTotalMinor = $this->coalesceAmount($orderData, [
            ['amounts', 'net'],
            ['amounts', 'netAmount'],
            ['totals', 'net'],
            ['totals', 'netTotal'],
        ]);

        $customerUserId = Format::optionalInt($orderData['customerUserId'] ?? $orderData['customer']['userId'] ?? null);
        $employeeUserId = Format::optionalInt($orderData['employeeUserId'] ?? $orderData['context']['employeeUserId'] ?? null);
        $storeDeviceId = $this->coalesceValue($orderData, [
            ['storeDeviceId'],
            ['deviceId'],
            ['context', 'storeDeviceId'],
        ]);
        $source = $this->coalesceValue($orderData, [
            ['source'],
            ['context', 'source'],
        ]);
        $sourceApp = $this->coalesceValue($orderData, [
            ['sourceApp'],
            ['sourceApplication'],
            ['context', 'sourceApp'],
            ['context', 'sourceApplication'],
        ]);

        $taxExempted = Format::optionalBool($orderData['taxExempted'] ?? null);
        $valid = Format::optionalBool($orderData['valid'] ?? null);
        $accepted = Format::optionalBool($orderData['accepted'] ?? null);
        $notes = Format::stringOrNull($orderData['notes'] ?? null);

        $customerJson = Format::jsonObject($orderData['customer'] ?? []);
        $transactionsJson = Format::jsonArray($orderData['transactions'] ?? []);
        $amountsJson = Format::jsonObject($orderData['amounts'] ?? ($orderData['totals'] ?? []));
        $contextJson = Format::jsonObject($orderData['context'] ?? []);
        $rawPayload = Format::jsonObject($orderData);

        $createdAtExt = Format::optionalTimestamp($orderData['createdAt'] ?? null);
        $updatedAtExt = Format::optionalTimestamp($orderData['updatedAt'] ?? null);

        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        try {
            $params = [
                'orderId' => $orderId,
                'businessId' => $businessId,
                'storeId' => $storeId,
                'currency' => $currency,
                'status' => $status,
                'fulfillmentStatus' => $fulfillmentStatus,
                'transactionStatusSummary' => $transactionStatusSummary,
                'subtotalMinor' => $subtotalMinor,
                'discountTotalMinor' => $discountMinor,
                'taxTotalMinor' => $taxTotalMinor,
                'tipTotalMinor' => $tipTotalMinor,
                'feeTotalMinor' => $feeTotalMinor,
                'shippingTotalMinor' => $shippingTotalMinor,
                'netTotalMinor' => $netTotalMinor,
                'taxExempted' => $taxExempted,
                'valid' => $valid,
                'accepted' => $accepted,
                'notes' => $notes,
                'customerUserId' => $customerUserId,
                'employeeUserId' => $employeeUserId,
                'storeDeviceId' => $storeDeviceId,
                'source' => $source,
                'sourceApp' => $sourceApp,
                'customerJson' => $customerJson,
                'transactionsJson' => $transactionsJson,
                'amountsJson' => $amountsJson,
                'contextJson' => $contextJson,
                'rawPayload' => $rawPayload,
                'createdAtExt' => $createdAtExt,
                'updatedAtExt' => $updatedAtExt,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $types = [
                'taxExempted' => $taxExempted === null ? ParameterType::NULL : ParameterType::BOOLEAN,
                'valid' => $valid === null ? ParameterType::NULL : ParameterType::BOOLEAN,
                'accepted' => $accepted === null ? ParameterType::NULL : ParameterType::BOOLEAN,
            ];

            $this->context->getConn()->executeStatement(
                'INSERT INTO "order" (
                    order_id, business_id, store_id, currency,
                    status, fulfillment_status, transaction_status_summary,
                    subtotal_minor, discount_total_minor, tax_total_minor, tip_total_minor,
                    fee_total_minor, shipping_total_minor, net_total_minor,
                    tax_exempted, valid, accepted, notes,
                    customer_user_id, employee_user_id, store_device_id,
                    source, source_app,
                    customer_json, transactions_json, amounts_json, context_json, raw_payload,
                    created_at_ext, updated_at_ext,
                    created_at, updated_at
                ) VALUES (
                    :orderId, :businessId, :storeId, :currency,
                    :status, :fulfillmentStatus, :transactionStatusSummary,
                    :subtotalMinor, :discountTotalMinor, :taxTotalMinor, :tipTotalMinor,
                    :feeTotalMinor, :shippingTotalMinor, :netTotalMinor,
                    :taxExempted, :valid, :accepted, :notes,
                    :customerUserId, :employeeUserId, :storeDeviceId,
                    :source, :sourceApp,
                    :customerJson, :transactionsJson, :amountsJson, :contextJson, :rawPayload,
                    :createdAtExt, :updatedAtExt,
                    :createdAt, :updatedAt
                ) ON CONFLICT (order_id) DO UPDATE SET
                    business_id = EXCLUDED.business_id,
                    store_id = EXCLUDED.store_id,
                    currency = EXCLUDED.currency,
                    status = EXCLUDED.status,
                    fulfillment_status = EXCLUDED.fulfillment_status,
                    transaction_status_summary = EXCLUDED.transaction_status_summary,
                    subtotal_minor = EXCLUDED.subtotal_minor,
                    discount_total_minor = EXCLUDED.discount_total_minor,
                    tax_total_minor = EXCLUDED.tax_total_minor,
                    tip_total_minor = EXCLUDED.tip_total_minor,
                    fee_total_minor = EXCLUDED.fee_total_minor,
                    shipping_total_minor = EXCLUDED.shipping_total_minor,
                    net_total_minor = EXCLUDED.net_total_minor,
                    tax_exempted = EXCLUDED.tax_exempted,
                    valid = EXCLUDED.valid,
                    accepted = EXCLUDED.accepted,
                    notes = EXCLUDED.notes,
                    customer_user_id = EXCLUDED.customer_user_id,
                    employee_user_id = EXCLUDED.employee_user_id,
                    store_device_id = EXCLUDED.store_device_id,
                    source = EXCLUDED.source,
                    source_app = EXCLUDED.source_app,
                    customer_json = EXCLUDED.customer_json,
                    transactions_json = EXCLUDED.transactions_json,
                    amounts_json = EXCLUDED.amounts_json,
                    context_json = EXCLUDED.context_json,
                    raw_payload = EXCLUDED.raw_payload,
                    created_at_ext = EXCLUDED.created_at_ext,
                    updated_at_ext = EXCLUDED.updated_at_ext,
                    updated_at = EXCLUDED.updated_at',
                $params,
                $types
            );

            $this->upsertItems($orderId, $orderData['items'] ?? []);
            $this->upsertHistory($orderId, $orderData['history'] ?? []);
            $this->upsertShipments($orderId, $orderData['shipments'] ?? []);

            $this->context->getLog()->info("OrderService::upsert: upserted order {$orderId}");
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                "OrderService::upsert: database error for order_id={$orderId}: " . $e->getMessage()
            );
            return false;
        }
    }

    private function upsertItems(string $orderId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $sql = 'INSERT INTO order_item (
                order_id, order_item_id, product_id, name, sku, category_id,
                quantity, unit_price_minor, discount_minor, tax_minor, fee_minor,
                unit_of_measure, status, taxes_json, raw_payload,
                created_at_ext, updated_at_ext, created_at, updated_at
            ) VALUES (
                :orderId, :itemId, :productId, :name, :sku, :categoryId,
                :quantity, :unitPriceMinor, :discountMinor, :taxMinor, :feeMinor,
                :unitOfMeasure, :status, :taxesJson, :payload,
                :createdAtExt, :updatedAtExt, :createdAt, :updatedAt
            ) ON CONFLICT (order_id, order_item_id) DO UPDATE SET
                product_id = EXCLUDED.product_id,
                name = EXCLUDED.name,
                sku = EXCLUDED.sku,
                category_id = EXCLUDED.category_id,
                quantity = EXCLUDED.quantity,
                unit_price_minor = EXCLUDED.unit_price_minor,
                discount_minor = EXCLUDED.discount_minor,
                tax_minor = EXCLUDED.tax_minor,
                fee_minor = EXCLUDED.fee_minor,
                unit_of_measure = EXCLUDED.unit_of_measure,
                status = EXCLUDED.status,
                taxes_json = EXCLUDED.taxes_json,
                raw_payload = EXCLUDED.raw_payload,
                created_at_ext = EXCLUDED.created_at_ext,
                updated_at_ext = EXCLUDED.updated_at_ext,
                updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($items as $item) {
            if (!isset($item['id'])) {
                $this->context->getLog()->error('OrderService::upsertItems: missing item id');
                continue;
            }
            $itemId = $item['id'];
            $name = $item['name'] ?? null;
            $sku = $item['sku'] ?? ($item['upc'] ?? null);
            $productId = $item['productId'] ?? null;
            $categoryId = $item['categoryId'] ?? null;
            $quantity = Format::optionalNumericString($item['quantity'] ?? null);
            $unitPriceMinor = $this->coalesceAmount($item, [
                ['unitPrice'],
                ['price'],
                ['unitPriceAmount'],
            ]);
            $discountMinor = $this->coalesceAmount($item, [
                ['discount'],
                ['discountAmount'],
            ]);
            $taxMinor = $this->coalesceAmount($item, [
                ['tax'],
                ['taxAmount'],
            ]);
            $feeMinor = $this->coalesceAmount($item, [
                ['fee'],
                ['feeAmount'],
            ]);
            $unitOfMeasure = $item['unitOfMeasure'] ?? $item['unitOfMeasureCode'] ?? null;
            $status = $item['status'] ?? null;
            $taxesJson = Format::jsonArray($item['taxes'] ?? []);
            $payload = Format::jsonObject($item);
            $createdAtExt = Format::optionalTimestamp($item['createdAt'] ?? null);
            $updatedAtExt = Format::optionalTimestamp($item['updatedAt'] ?? null);

            $stmt->executeStatement([
                'orderId' => $orderId,
                'itemId' => $itemId,
                'productId' => $productId,
                'name' => $name,
                'sku' => $sku,
                'categoryId' => $categoryId,
                'quantity' => $quantity,
                'unitPriceMinor' => $unitPriceMinor,
                'discountMinor' => $discountMinor,
                'taxMinor' => $taxMinor,
                'feeMinor' => $feeMinor,
                'unitOfMeasure' => $unitOfMeasure,
                'status' => $status,
                'taxesJson' => $taxesJson,
                'payload' => $payload,
                'createdAtExt' => $createdAtExt,
                'updatedAtExt' => $updatedAtExt,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    private function upsertHistory(string $orderId, array $history): void
    {
        if (empty($history)) {
            return;
        }

        $sql = 'INSERT INTO order_history (
                order_id, event, ts_ext, payload, created_at, updated_at
            ) VALUES (
                :orderId, :event, :tsExt, :payload, :createdAt, :updatedAt
            ) ON CONFLICT (order_id, event, ts_ext) DO UPDATE SET
                payload = EXCLUDED.payload,
                updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($history as $entry) {
            $event = Format::stringOrNull($entry['eventType'] ?? $entry['status'] ?? $entry['event'] ?? $entry['action'] ?? null);
            $tsExt = Format::optionalTimestamp($entry['timestamp'] ?? $entry['eventTime'] ?? $entry['createdAt'] ?? $entry['updatedAt'] ?? null);

            if ($event === null || $tsExt === null) {
                $this->context->getLog()->error('OrderService::upsertHistory: missing required event or timestamp');
                continue;
            }
            $payload = Format::jsonObject($entry);

            $stmt->executeStatement([
                'orderId' => $orderId,
                'event' => $event,
                'tsExt' => $tsExt,
                'payload' => $payload,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    private function upsertShipments(string $orderId, array $shipments): void
    {
        if (empty($shipments)) {
            return;
        }

        $sql = 'INSERT INTO order_shipment (
                order_id, shipment_id, status, amount_minor, carrier, tracking_no,
                fulfill_at_ext, shipped_at_ext, delivered_at_ext,
                payload, created_at, updated_at
            ) VALUES (
                :orderId, :shipmentId, :status, :amountMinor, :carrier, :trackingNo,
                :fulfillAtExt, :shippedAtExt, :deliveredAtExt,
                :payload, :createdAt, :updatedAt
            ) ON CONFLICT (order_id, shipment_id) DO UPDATE SET
                status = EXCLUDED.status,
                amount_minor = EXCLUDED.amount_minor,
                carrier = EXCLUDED.carrier,
                tracking_no = EXCLUDED.tracking_no,
                fulfill_at_ext = EXCLUDED.fulfill_at_ext,
                shipped_at_ext = EXCLUDED.shipped_at_ext,
                delivered_at_ext = EXCLUDED.delivered_at_ext,
                payload = EXCLUDED.payload,
                updated_at = EXCLUDED.updated_at';
        $stmt = $this->context->getConn()->prepare($sql);
        $now = (new \DateTime('now'))->format('Y-m-d H:i:sP');

        foreach ($shipments as $shipment) {
            $shipmentId = $shipment['id'] ?? $shipment['shipmentId'] ?? null;
            if (!$shipmentId) {
                $this->context->getLog()->error('OrderService::upsertShipments: missing shipment id');
                continue;
            }
            $status = $shipment['status'] ?? null;
            $amountMinor = $this->coalesceAmount($shipment, [
                ['amount'],
                ['amounts', 'total'],
            ]);
            $carrier = $shipment['carrier'] ?? $shipment['carrierName'] ?? null;
            $trackingNo = $shipment['trackingNumber'] ?? $shipment['trackingNo'] ?? null;
            $fulfillAtExt = Format::optionalTimestamp($shipment['fulfillAt'] ?? $shipment['fulfilledAt'] ?? null);
            $shippedAtExt = Format::optionalTimestamp($shipment['shippedAt'] ?? null);
            $deliveredAtExt = Format::optionalTimestamp($shipment['deliveredAt'] ?? null);
            $payload = Format::jsonObject($shipment);

            $stmt->executeStatement([
                'shipmentId' => $shipmentId,
                'orderId' => $orderId,
                'status' => $status,
                'amountMinor' => $amountMinor,
                'carrier' => $carrier,
                'trackingNo' => $trackingNo,
                'fulfillAtExt' => $fulfillAtExt,
                'shippedAtExt' => $shippedAtExt,
                'deliveredAtExt' => $deliveredAtExt,
                'payload' => $payload,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    /**
     * @param array<mixed> $data
     * @param array<int, array<int, string>> $paths
     */
    private function coalesceValue(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->nestedValue($data, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     * @param array<int, array<int, string>> $paths
     */
    private function coalesceAmount(array $data, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = $this->nestedValue($data, $path);
            $amount = Format::amount($value);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     * @param array<int, string> $path
     */
    private function nestedValue(array $data, array $path): mixed
    {
        $current = $data;

        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

