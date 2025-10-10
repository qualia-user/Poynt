<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Services\SubscriptionService;

class SubscriptionController extends Controller
{
    private SubscriptionService $subscriptionService;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->subscriptionService = new SubscriptionService($context);
    }

    /**
     * GET /subscriptions/status
     *
     * Returns the current subscription status along with trial expiration state.
     */
    public function status(): void
    {
        $merchantToken = $this->api->getParam('merchantAccessToken');
        $businessId    = $this->api->getParam('businessId');
        $storeId       = $this->api->getParam('storeId');

        if (!$merchantToken || !$businessId) {
            Api::response(Response::STATUS_BAD_REQUEST, ['error' => 'Missing required parameters']);
        }

        $subscriptionData = $this->subscriptionService->fetchMerchantSubscription(
            $merchantToken,
            $businessId,
            $storeId
        );

        $subscription   = $subscriptionData[0] ?? $subscriptionData;
        $subscriptionId = $subscription['subscriptionId'] ?? $subscription['subscription_id'] ?? null;
        $trialExpired   = $subscriptionId ? $this->subscriptionService->hasTrialExpired($subscriptionId) : false;

        $response = [
            'subscriptionStatus' => $subscription['status'] ?? null,
            'trialExpired'       => $trialExpired,
        ];

        $statusCode = $trialExpired ? Response::STATUS_FREE_TRIAL_FINISHED : Response::STATUS_OK;
        Api::response($statusCode, $response);
    }

    /**
     * POST /subscriptions/start-trial
     *
     * Initiates a local free trial for a store.
     */
    public function startTrial(): array
    {
        $businessId = $this->api->getParam('businessId');
        $storeId    = $this->api->getParam('storeId');
        $planId     = $this->api->getParam('trialPlanId', 'free_trial');

        $subscriptionId = $this->subscriptionService->startFreeTrial($businessId, $storeId, $planId);

        return [
            'subscriptionId' => $subscriptionId,
            'status' => 'trialing',
        ];
    }

    public function setSubscriptionService(SubscriptionService $subscriptionService): void
    {
        $this->subscriptionService = $subscriptionService;
    }
}
