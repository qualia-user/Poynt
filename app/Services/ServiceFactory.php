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

    public function business(?string $businessId = null): BusinessService
    {
        return new BusinessService($this->context, $businessId);
    }

    public function subscription(?string $storeId = null): SubscriptionService
    {
        return new SubscriptionService($this->context, $storeId);
    }

    public function order(): OrderService
    {
        return new OrderService($this->context);
    }

    public function token(): TokenService
    {
        return new TokenService($this->context);
    }

    public function transaction(): TransactionService
    {
        return new TransactionService($this->context);
    }

    /**
     * Return the list of resource services that should be synchronized during onboarding.
     *
     * @param string $businessId
     * @return array
     */
    public function onboardingResources(string $businessId): array
    {
        return [
            $this->businessUser($businessId),
            $this->catalog($businessId),
            $this->customer($businessId),
            $this->inventorySummary($businessId),
            $this->paylink($businessId),
            $this->product($businessId),
            $this->tax($businessId),
            $this->hook($businessId),
        ];
    }
}
