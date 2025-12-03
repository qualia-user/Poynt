<?php

namespace App\Services\Tenant;

use App\Core\Context;

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

        if ($tenantId === '') {
            return [
                'success' => false,
                'status' => 'invalid',
                'message' => 'Missing tenant identifier',
            ];
        }

        if (!$this->tenantRowExists($tenantId)) {
            return [
                'success' => false,
                'status' => 'not_found',
                'message' => sprintf('Tenant row for %s not found', $tenantId),
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

    private function tenantRowExists(string $tenantId): bool
    {
        $existing = $this->context->getConn()->fetchOne(
            'SELECT 1 FROM business WHERE business_id = ? LIMIT 1',
            [$tenantId]
        );

        return $existing !== false;
    }
}
