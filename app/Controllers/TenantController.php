<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Services\Tenant\TenantProvisioningService;

class TenantController extends Controller
{
    private TenantProvisioningService $tenantProvisioningService;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->tenantProvisioningService = new TenantProvisioningService($context);
    }

    public function provision(): void
    {
        $tenantId = (string) $this->api->getParam('tenantId');
        $templates = $this->api->getParam('templates', []);
        $templates = is_array($templates) ? $templates : [];

        $result = $this->tenantProvisioningService->provisionTenant($tenantId, $templates);
        $statusCode = $result['success'] ? Response::STATUS_OK : Response::STATUS_BAD_REQUEST;

        Api::response($statusCode, $result);
    }

    public function setTenantProvisioningService(TenantProvisioningService $tenantProvisioningService): void
    {
        $this->tenantProvisioningService = $tenantProvisioningService;
    }
}
