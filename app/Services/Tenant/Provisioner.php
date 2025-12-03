<?php

namespace App\Services\Tenant;

use App\Core\Context;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class Provisioner
{
    private const TEMPLATE_VERSION = 2025120201;

    /**
     * Order used when tearing down tenant tables to satisfy foreign key dependencies.
     *
     * @var string[]
     */
    private const DROP_ORDER = [
        'transaction_receipt',
        'transaction',
        'order_shipment',
        'order_history',
        'order_item',
        'order',
        'hook_delivery',
        'hook',
        'paylink_link',
        'paylink_payment',
        'paylink_item',
        'paylink',
        'category_tax',
        'category_product',
        'category',
        'catalog_available_discount',
        'catalog_product_tax',
        'catalog_product',
        'catalog',
        'variant_inventory',
        'inventory',
        'inventory_summary',
        'product_variant',
        'product',
        'business_user',
        'customer',
        'token_refresh_log',
        'log',
        'webhook_audit',
        'subscription',
        'merchant_token',
        'app_token',
        'terminal',
        'store',
    ];

    private Context $context;

    private Connection $conn;

    private string $templatePath;

    /**
     * @var string[]|null
     */
    private ?array $templateBaseNames = null;

    public function __construct(Context $context, ?string $templatePath = null)
    {
        $this->context = $context;
        $this->conn = $context->getConn();
        $this->templatePath = $templatePath ?? $this->getDefaultTemplatePath();
    }

    /**
     * @param string   $businessId        Tenant identifier
     * @param string[] $tableBaseNames    Optional subset of template base names to register
     *
     * @return array{success: bool, templateVersion?: int, registeredTables?: string[], error?: string}
     */
    public function provision(string $businessId, array $tableBaseNames = []): array
    {
        $tenantId = $this->normalizeTenantId($businessId);

        if ($tenantId === '') {
            $this->context->getLog()->error('Provisioner::provision: missing business id');

            return [
                'success' => false,
                'error' => 'Missing business id',
            ];
        }

        try {
            $tableBaseNames = $this->resolveRequestedBaseNames($tableBaseNames);
        } catch (\Throwable $exception) {
            $this->context->getLog()->error($exception->getMessage());

            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        try {
            $statements = $this->renderStatements($tenantId, $tableBaseNames);

            $this->conn->beginTransaction();

            $this->acquireTenantLock($tenantId);

            foreach ($statements as $statement) {
                $this->conn->executeStatement($statement);
            }

            $this->registerTenantTables($tenantId, $tableBaseNames);

            $this->upsertTenantSchemaVersion($tenantId);

            $this->conn->commit();

            $this->context->getLog()->info(
                'Provisioner::provision: tenant tables provisioned',
                [
                    'businessId' => $tenantId,
                    'templateVersion' => self::TEMPLATE_VERSION,
                    'statementCount' => count($statements),
                    'registeredTables' => count($tableBaseNames),
                ]
            );

            return [
                'success' => true,
                'templateVersion' => self::TEMPLATE_VERSION,
                'registeredTables' => $tableBaseNames,
            ];
        } catch (\Throwable $exception) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }

            $message = sprintf(
                'Provisioner::provision: failed to provision tenant %s with template %s: %s',
                $tenantId,
                self::TEMPLATE_VERSION,
                $exception->getMessage()
            );

            $this->context->getLog()->error($message);

            return [
                'success' => false,
                'error' => $message,
            ];
        }
    }

    /**
     * @return string[]
     */
    public function getTemplateBaseNames(): array
    {
        if ($this->templateBaseNames !== null) {
            return $this->templateBaseNames;
        }

        $templateSql = $this->loadTemplateSql();

        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([A-Za-z0-9_]+)_template/i', $templateSql, $matches);

        $baseNames = array_map('strtolower', $matches[1] ?? []);

        $this->templateBaseNames = array_values(array_unique($baseNames));

        return $this->templateBaseNames;
    }

    /**
     * @return array{success: bool, templateVersion?: int, error?: string}
     */
    public function drop(string $businessId): array
    {
        $tenantId = $this->normalizeTenantId($businessId);

        if ($tenantId === '') {
            $this->context->getLog()->error('Provisioner::drop: missing business id');

            return [
                'success' => false,
                'error' => 'Missing business id',
            ];
        }

        try {
            $this->conn->beginTransaction();
            $this->acquireTenantLock($tenantId);

            foreach (self::DROP_ORDER as $baseName) {
                $this->conn->executeStatement(
                    sprintf('DROP TABLE IF EXISTS public.%s_%s CASCADE', $tenantId, $baseName)
                );
            }

            $this->conn->executeStatement(
                'DELETE FROM tenant_schema_version WHERE tenant_id = ?',
                [$tenantId],
                [ParameterType::STRING]
            );

            $this->conn->commit();

            $this->context->getLog()->info(
                'Provisioner::drop: tenant tables dropped',
                [
                    'businessId' => $tenantId,
                    'templateVersion' => self::TEMPLATE_VERSION,
                ]
            );

            return [
                'success' => true,
                'templateVersion' => self::TEMPLATE_VERSION,
            ];
        } catch (\Throwable $exception) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }

            $message = sprintf(
                'Provisioner::drop: failed to drop tenant %s: %s',
                $tenantId,
                $exception->getMessage()
            );

            $this->context->getLog()->error($message);

            return [
                'success' => false,
                'error' => $message,
            ];
        }
    }

    private function registerTenantTables(string $businessId, array $tableBaseNames): void
    {
        $this->conn->executeStatement('DELETE FROM tenant_table_registry WHERE business_id = ?', [$businessId]);

        foreach ($tableBaseNames as $baseName) {
            $this->conn->insert('tenant_table_registry', [
                'business_id' => $businessId,
                'table_name' => $this->tableName($businessId, $baseName),
                'template_version' => self::TEMPLATE_VERSION,
            ]);
        }
    }

    /**
     * @param string[] $tableBaseNames
     *
     * @return string[]
     */
    private function normalizeTableBaseNames(array $tableBaseNames): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $name): string => strtolower(trim($name)),
            array_map('strval', $tableBaseNames)
        ), static fn (string $name): bool => $name !== '')));

        return $normalized;
    }

    /**
     * @return string[]
     */
    private function renderStatements(string $businessId, array $tableBaseNames): array
    {
        $templateSql = $this->loadTemplateSql();
        $templateStatements = $this->extractTemplateStatements($templateSql, $tableBaseNames);

        $renderedStatements = array_map(
            static fn (string $statement): string => (string) preg_replace(
                '/([A-Za-z0-9_]+)_template/',
                sprintf('%s_\1', $businessId),
                $statement
            ),
            $templateStatements
        );

        return $renderedStatements;
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

    private function extractTemplateStatements(string $templateSql, array $requestedBaseNames): array
    {
        $withoutComments = preg_replace('/^--.*$/m', '', $templateSql);
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', (string) $withoutComments);

        $statements = array_filter(
            array_map('trim', explode(';', (string) $withoutComments)),
            static fn (string $statement): bool => $statement !== '' && stripos($statement, '_template') !== false
        );

        if ($requestedBaseNames === []) {
            return $statements;
        }

        return array_values(array_filter(
            $statements,
            static function (string $statement) use ($requestedBaseNames): bool {
                foreach ($requestedBaseNames as $baseName) {
                    if (stripos($statement, sprintf('%s_template', $baseName)) !== false) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    private function tableName(string $businessId, string $baseName): string
    {
        return sprintf('%s_%s', $businessId, $baseName);
    }

    private function normalizeTenantId(string $businessId): string
    {
        return strtolower(trim($businessId));
    }

    private function resolveRequestedBaseNames(array $tableBaseNames): array
    {
        $normalized = $this->normalizeTableBaseNames($tableBaseNames);
        $available = $this->getTemplateBaseNames();

        if ($normalized === []) {
            return $available;
        }

        $unknown = array_diff($normalized, $available);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown template base names requested: %s', implode(', ', $unknown))
            );
        }

        return $normalized;
    }

    private function acquireTenantLock(string $tenantId): void
    {
        $this->conn->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [sprintf('provision_tenant_schema_%s', $tenantId)],
            [ParameterType::STRING]
        );
    }

    private function upsertTenantSchemaVersion(string $tenantId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO tenant_schema_version (tenant_id, version, applied_at) VALUES (?, ?, NOW())
                ON CONFLICT (tenant_id) DO UPDATE SET version = EXCLUDED.version, applied_at = EXCLUDED.applied_at',
            [$tenantId, self::TEMPLATE_VERSION],
            [ParameterType::STRING, ParameterType::INTEGER]
        );
    }

    private function getDefaultTemplatePath(): string
    {
        return dirname(__DIR__, 3) . '/SQL/poynt-tenant-templates.sql';
    }
}
