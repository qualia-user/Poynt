<?php

namespace App\Controllers;

use App\Config\ConfigApp;
use App\Core\Api;
use App\Core\Context;
use App\Core\Response;
use App\Fetchers\MerchantFetcher;
use App\Modules\OAuth\PlatformRegistry;
use App\Modules\OAuth\PoyntOAuthHandler;
use App\Services\MerchantService;
use App\Services\SubscriptionService;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;

class OAuthController extends Controller {

    private MerchantService $businessService;
    private StoreService    $storeService;
    private TokenService    $tokenService;
    private Context         $context;


    public function __construct($api, $conn, $log) {
        parent::__construct($api, $conn, $log);
        $this->context = new Context($api, $conn, $log);

        // TODO if we would like to keep one app for all providers
        #$this->platformRegistry = new PlatformRegistry($this->context);
    }

//    public function handleCallback(array $request): array {
//        $platform = $request['platform'];
//        $authCode = $request['code'];
//
//        $handler = $this->platformRegistry->getHandler(ConfigApp::$platform);
//        $tokens = $handler->exchangeAuthorizationCode($authCode);
//        $merchantData = $handler->fetchMerchantDetails($tokens['access_token']);
//
//        $merchantId = $this->merchantService->saveMerchantData($merchantData, $platform, $tokens);
//
//        return [
//            'message' => 'Installation successful',
//            'merchant_id' => $merchantId,
//            'platform' => $platform,
//        ];
//    }

    /**
     * Handles the "install" route.
     * Redirects the merchant to the OAuth authorization URL.
     */
    public function install($platform)
    {
        if (!$platform) {
            return ['error' => 'Platform not specified.'];
        }

        $handlerClass = "\\App\\Modules\\OAuth\\" . ucfirst($platform) . "Handler";
        if (!class_exists($handlerClass)) {
            return ['error' => "Handler for platform $platform not found."];
        }

        $config = require "../config/{$platform}.php";
        $handler = new $handlerClass($config['client_id'], $config['client_secret'], $config['redirect_uri']);

        $state = bin2hex(random_bytes(16)); // Prevent CSRF
        $_SESSION['state'] = $state;

        $this->log->info("Redirecting to authorization for platform: $platform");
        return ['redirect' => $handler->getAuthorizationUrl($state)];
    }


    /**
     * @return void
     * @throws \Exception
     */
    public function callback()
    {
        $poyntOAuthHandler = new PoyntOAuthHandler($this->context);

        try {
            // 1) Get tokens and stores
            [$appToken, $merchantToken] = $poyntOAuthHandler->retrieveTokens();
            $poyntOAuthHandler->storeTokens($appToken, $merchantToken);

            $merchantService = new MerchantService($this->context, $poyntOAuthHandler->getBusinessId());
            if (!$merchantService->merchantExists())
            {
                $stores = $merchantService->fetchBusinessStores();
                $inserted = $merchantService->upsertStores($stores);

                if (!$inserted) {
                    $this->context->getLog()->error('Failed to insert stores for new merchant.');
                    // TODO?
                    return;
                }

                // 2) Retrieve all available plans
                // TODO we probably want to instantiate with both access tokens?
                // TODO if not
                // TODO we probably want to be able to fetch business->store and store->business
                // TODO and then use TokenService

                // TODO for testing purposes, we will use recently fetched token
                $subscriptionService = new SubscriptionService($this->context);
                $plans = $subscriptionService->fetchPlans($appToken['accessToken']);

                // 3) Subscribe merchant's first store to trial
                // TODO probably should subscribe every single store ?
                $subscriptionId = $subscriptionService->startFreeTrial($poyntOAuthHandler->getBusinessId(), $poyntOAuthHandler->getStoreId());

                // 4) Register webhooks
                $poyntOAuthHandler->registerWebhooks();

                // 5) Insert merchant
                if ($merchant = $merchantService->fetchMerchantBusinessById($poyntOAuthHandler->getBusinessId())) {
                    $success = $merchantService->upsert($merchant);
                }
            } else {
                // we already have this user, maybe it's inactive?
                $success = true;
            }



            if ($success) {
                $this->log->info("Merchant data saved successfully.");
                Api::response(Response::STATUS_OK, ['message' => 'Merchant data saved successfully.']);
            } else {
                $this->log->error("Failed to save merchant data.");
                Api::response(Response::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Failed to save merchant data.']);
            }
        } catch (\Exception $e) {
            $this->log->error("Error during callback: " . $e->getMessage());
            Api::response(Response::STATUS_INTERNAL_SERVER_ERROR, ['error' => $e->getMessage()]);
        } catch (GuzzleException $e) {
        }

        exit;
    }



}
