<?php

namespace App\Services\Tenant;

use App\Core\Context;
use Throwable;

class TenantProvisioningService
{
    private Context $context;

    private Provisioner $provisioner;

    public function __construct(Context $context, ?Provisioner $provisioner = null)
    {
        $this->context = $context;
        $this->provisioner = $provisioner ?? new Provisioner($context);
    }

    /**
     * Trigger tenant schema provisioning after the tenant row is created.
     *
     * @param string   $businessId Tenant identifier
     * @param string[] $templates  Template base names requested by the caller
     *
     * @return array{success: bool, status: string, tenantId?: string, templates?: string[], templateVersion?: int, message?: string}
     */
    public function provisionTenant(string $businessId, array $templates = []): array
    {
        $tenantId = strtolower(trim($businessId));

        try {
            $templates = $this->normalizeTemplates($templates);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }

        if (!$this->ensureCoreSchemaExists()) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Failed to prepare core schema for tenant provisioning',
            ];
        }

        if ($tenantId === '') {
            return [
                'success' => false,
                'status' => 'invalid',
                'message' => 'Missing tenant identifier',
            ];
        }

        $provisioningResult = $this->provisioner->provision($tenantId, $templates);
        $response = [
            'success' => $provisioningResult['success'],
            'status' => $provisioningResult['success'] ? 'provisioned' : 'failed',
            'tenantId' => $tenantId,
            'templates' => $provisioningResult['registeredTables'] ?? $templates,
        ];

        if (isset($provisioningResult['templateVersion'])) {
            $response['templateVersion'] = $provisioningResult['templateVersion'];
        }

        if (!$provisioningResult['success'] && isset($provisioningResult['error'])) {
            $response['message'] = $provisioningResult['error'];
        }

        return $response;
    }

    private function ensureCoreSchemaExists(): bool
    {
        $conn = $this->context->getConn();

        $tableExists = $conn->fetchOne("SELECT to_regclass('public.merchant_token')");
        if ($tableExists !== null) {
            return true;
        }

        $migrationPath = dirname(__DIR__, 3) . '/database/migrations/20241010000000_create_core_entities.sql';

        if (!is_file($migrationPath)) {
            $this->context->getLog()->error(
                sprintf('TenantProvisioningService::ensureCoreSchemaExists missing migration file at %s', $migrationPath)
            );

            return false;
        }

        $contents = file_get_contents($migrationPath);

        if ($contents === false) {
            $this->context->getLog()->error(
                sprintf('TenantProvisioningService::ensureCoreSchemaExists unable to read migration file at %s', $migrationPath)
            );

            return false;
        }

        $statements = array_filter(array_map('trim', explode(';', $contents))); // @codeCoverageIgnore

        try {
            foreach ($statements as $statement) {
                if ($statement === '') {
                    continue;
                }

                $conn->executeStatement($statement);
            }

            $this->context->getLog()->info(
                'TenantProvisioningService::ensureCoreSchemaExists applied baseline core schema',
                ['migrationPath' => $migrationPath]
            );

            return true;
        } catch (Throwable $exception) {
            $this->context->getLog()->error(
                'TenantProvisioningService::ensureCoreSchemaExists failed: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return false;
        }
    }

    /**
     * @param string[] $templates
     *
     * @return string[]
     */
    private function normalizeTemplates(array $templates): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $template): string => strtolower(trim($template)),
            array_map('strval', $templates)
        ), static fn (string $template): bool => $template !== '')));

        if ($normalized === []) {
            return $this->provisioner->getTemplateBaseNames();
        }

        return $normalized;
    }
}
