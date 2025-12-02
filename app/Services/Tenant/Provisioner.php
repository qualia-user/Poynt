<?php

namespace App\Services\Tenant;

use App\Core\Context;
use Doctrine\DBAL\Connection;

class Provisioner
{
    private const TEMPLATE_VERSION = 2025120201;

    /**
     * List of base table names defined in the tenant DDL template.
     *
     * @var string[]
     */
    private const TABLE_BASE_NAMES = [
        'store',
        'terminal',
        'app_token',
        'merchant_token',
        'subscription',
        'webhook_audit',
        'log',
        'token_refresh_log',
        'customer',
        'business_user',
        'product',
        'product_variant',
        'inventory_summary',
        'inventory',
        'variant_inventory',
        'catalog',
        'catalog_product',
        'catalog_product_tax',
        'catalog_available_discount',
        'category',
        'category_product',
        'category_tax',
        'tax',
        'paylink',
        'paylink_item',
        'paylink_payment',
        'paylink_link',
        'hook',
        'hook_delivery',
        'order',
        'order_item',
        'order_history',
        'order_shipment',
        'transaction',
        'transaction_receipt',
    ];

    private Context $context;

    private Connection $conn;

    private string $templatePath;

    public function __construct(Context $context, ?string $templatePath = null)
    {
        $this->context = $context;
        $this->conn = $context->getConn();
        $this->templatePath = $templatePath ?? $this->getDefaultTemplatePath();
    }

    public function provision(string $businessId): bool
    {
        $tenantId = trim($businessId);

        if ($tenantId === '') {
            $this->context->getLog()->error('Provisioner::provision: missing business id');

            return false;
        }

        try {
            if ($this->tenantAlreadyProvisioned($tenantId)) {
                $this->context->getLog()->info(
                    'Provisioner::provision: tenant already provisioned, skipping',
                    [
                        'businessId' => $tenantId,
                        'templateVersion' => self::TEMPLATE_VERSION,
                    ]
                );

                return true;
            }

            $statements = $this->renderStatements($tenantId);

            $this->conn->beginTransaction();

            foreach ($statements as $statement) {
                $this->conn->executeStatement($statement);
            }

            $this->registerTenantTables($tenantId);

            $this->conn->commit();

            $this->context->getLog()->info(
                'Provisioner::provision: tenant tables provisioned',
                [
                    'businessId' => $tenantId,
                    'templateVersion' => self::TEMPLATE_VERSION,
                    'statementCount' => count($statements),
                    'registeredTables' => count(self::TABLE_BASE_NAMES),
                ]
            );

            return true;
        } catch (\Throwable $exception) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }

            $this->context->getLog()->error(
                sprintf(
                    'Provisioner::provision: failed to provision tenant %s with template %s: %s',
                    $tenantId,
                    self::TEMPLATE_VERSION,
                    $exception->getMessage()
                )
            );

            return false;
        }
    }

    private function tenantAlreadyProvisioned(string $businessId): bool
    {
        $existing = $this->conn->fetchOne(
            'SELECT 1 FROM tenant_table_registry WHERE business_id = ? LIMIT 1',
            [$businessId]
        );

        return $existing !== false;
    }

    private function registerTenantTables(string $businessId): void
    {
        foreach (self::TABLE_BASE_NAMES as $baseName) {
            $this->conn->insert('tenant_table_registry', [
                'business_id' => $businessId,
                'table_name' => $this->tableName($businessId, $baseName),
                'template_version' => self::TEMPLATE_VERSION,
            ]);
        }
    }

    /**
     * @return string[]
     */
    private function renderStatements(string $businessId): array
    {
        $templateSql = $this->loadTemplateSql();
        $sqlWithPlaceholder = str_replace('_template', '{{business_id}}_', $templateSql);
        $renderedSql = str_replace('{{business_id}}', $businessId, $sqlWithPlaceholder);
        $renderedSql = preg_replace('/^--.*$/m', '', $renderedSql);

        $statements = array_filter(
            array_map('trim', explode(';', (string) $renderedSql)),
            static fn (string $statement) => $statement !== ''
        );

        return $statements;
    }

    private function loadTemplateSql(): string
    {
        if (!is_file($this->templatePath)) {
            throw new \RuntimeException(
                sprintf('Provisioner::loadTemplateSql: template file not found at %s', $this->templatePath)
            );
        }

        $contents = file_get_contents($this->templatePath);

        if ($contents === false) {
            throw new \RuntimeException(
                sprintf('Provisioner::loadTemplateSql: unable to read template file at %s', $this->templatePath)
            );
        }

        return $contents;
    }

    private function tableName(string $businessId, string $baseName): string
    {
        return sprintf('%s_%s', $businessId, $baseName);
    }

    private function getDefaultTemplatePath(): string
    {
        return dirname(__DIR__, 3) . '/SQL/poynt-tenant-templates.sql';
    }
}
