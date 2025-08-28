<?php

namespace App\Services;

use App\Core\Context;

class ServiceFactory
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function customer(?string $businessId = null): CustomerService
    {
        return new CustomerService($this->context, $businessId);
    }

    public function businessUser(?string $businessId = null): BusinessUserService
    {
        return new BusinessUserService($this->context, $businessId);
    }

    public function product(?string $businessId = null): ProductService
    {
        return new ProductService($this->context, $businessId);
    }

    public function inventorySummary(?string $businessId = null): InventorySummaryService
    {
        return new InventorySummaryService($this->context, $businessId);
    }

    public function catalog(?string $businessId = null): CatalogService
    {
        return new CatalogService($this->context, $businessId);
    }

    public function tax(?string $businessId = null): TaxService
    {
        return new TaxService($this->context, $businessId);
    }

    public function paylink(?string $businessId = null): PaylinkService
    {
        return new PaylinkService($this->context, $businessId);
    }

    public function hook(?string $businessId = null): HookService
    {
        return new HookService($this->context, $businessId);
    }
}
