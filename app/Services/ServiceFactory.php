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

    public function category(?string $businessId = null): CategoryService
    {
        return new CategoryService($this->context, $businessId);
    }

    public function inventory(?string $businessId = null): InventoryService
    {
        return new InventoryService($this->context, $businessId);
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

    public function hook(?string $businessId = null): WebhookService
    {
        return new WebhookService($this->context, $businessId);
    }

    public function store(?string $businessId = null): StoreService
    {
        return new StoreService($this->context, $businessId);
    }

    public function terminal(): TerminalService
    {
        return new TerminalService($this->context);
    }

    public function business(?string $businessId = null): BusinessService
    {
        return new BusinessService($this->context, $businessId);
    }

    public function subscription(?string $businessId = null, ?string $storeId = null): SubscriptionService
    {
        return new SubscriptionService($this->context, $businessId, $storeId);
    }

    public function order(?string $businessId = null): OrderService
    {
        return new OrderService($this->context, $businessId);
    }

    public function token(): TokenService
    {
        return new TokenService($this->context);
    }

    public function transaction(?string $businessId = null): TransactionService
    {
        return new TransactionService($this->context, $businessId);
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
            $this->business($businessId),
            $this->store($businessId),
            $this->businessUser($businessId),
            $this->subscription($businessId),
            $this->catalog($businessId),
            $this->category($businessId),
            $this->customer($businessId),
            $this->inventory($businessId),
            $this->paylink($businessId),
            $this->product($businessId),
            $this->tax($businessId),
            $this->hook($businessId),
            $this->order($businessId),
            $this->transaction($businessId),
        ];
    }
}
