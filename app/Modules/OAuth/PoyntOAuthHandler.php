<?php

namespace App\Modules\OAuth;

use App\Config\ConfigApp;
use App\Core\Api;
use App\Core\Response;
use App\Core\Context;
use App\Fetchers\MerchantFetcher;
use App\Services\MerchantService;
use App\Services\OAuthService;
use App\Services\TokenService;
use Doctrine\DBAL\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\HelperService;
use App\Services\WebhookService;

class PoyntOAuthHandler implements OAuthHandlerInterface
{

    private ?MerchantService $merchantService = null;

    private $client;
    private $clientId;
    private $clientSecret;
    private $storeId;
    private $businessId;
    private $tokenResponse;
    private $context;
    private $code;

    public function __construct(Context $context) {
        $this->context = $context;
        $this->client = new Client();

        // TODO handle
//        if ($config) {
//            $this->clientId = $config['client_id'];
//            $this->clientSecret = $config['client_secret'];
//        }
    }

    public function getMerchantService(): MerchantService {
        if ($this->merchantService === null) {
            $this->merchantService = new MerchantService(
                $this->context->getApi(),
                $this->context->getConn(),
                $this->context->getLog(),
                new MerchantFetcher($this->context->getLog())
            );
        }
        return $this->merchantService;
    }

    /**
     * @return string
     */
    public function getBusinessId(): string
    {
        return $this->businessId;
    }

    /**
     * @return string
     */
    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function retrieveTokens(): array
    {
        $this->businessId = $businessId = $this->context->getApi()->getParam('businessId', null);
        $this->storeId = $storeId = $this->context->getApi()->getParam('store_id', null);
        $this->code = $code = $this->context->getApi()->getParam('code', null);

        if (!$businessId || !$storeId || !$code) {
            $this->context->getLog()->error("Error: Missing required parameters");
            return [
                'success' => false,
                'status' => Response::STATUS_BAD_REQUEST,
                'error' => 'Missing required parameters',
            ];
        }

        $oauthService = new OAuthService($this->context);

        try {
            $appAccessToken = $oauthService->exchangeJwtForToken();
        } catch (\Exception $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Token exchange failed.',
            ];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Guzzle exception. Token exchange failed.',
            ];
        }

        try {
            $merchantAccessToken = $oauthService->exchangeAuthCodeForMerchantToken($code, ConfigApp::$redirectUri);
        } catch (\Exception $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Token exchange failed.',
            ];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Guzzle exception. Token exchange failed.',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'appAccessToken' => $appAccessToken,
                'merchantAccessToken' => $merchantAccessToken,
            ],
        ];
    }

    /**
     * @throws \DateMalformedIntervalStringException
     */
    public function storeTokens($appToken, $merchantToken): void
    {
        $tokenService = new TokenService($this->context);

        $tokenService->saveAppToken($this->businessId, $appToken);
        $tokenService->saveMerchantToken($this->businessId, $merchantToken);

    }

    /**
     * @throws Exception
     */
    public function registerWebhooks(): void
    {
        $tokenService = new TokenService($this->context);
        if (!empty($merchantToken = $tokenService->getMerchantToken($this->businessId)) && !empty($appToken = $tokenService->getAppToken($this->businessId)))
        {
            $webhookService = new WebhookService($this->context, $this->businessId);

            // 1) APPLICATION_SUBSCRIPTION_*
            $webhookService->registerSubscriptionWebhooks($merchantToken);

            // 2) BUSINESS_USER_*
            // TODO
            $webhookService->registerBusinessUserWebhooks($merchantToken);

            // 3) CATALOG_*
            $webhookService->registerCatalogWebhooks($merchantToken);

            // 4) CATEGORY_*
            $webhookService->registerCategoryWebhooks($merchantToken);

            // 5) INVENTORY_UPDATED (only one event under this prefix)
            $webhookService->registerInventoryWebhook($merchantToken);

            // 6) ORDER_*
            $webhookService->registerOrderWebhooks($merchantToken);

            // 7) ORDER_ITEM_*
            $webhookService->registerOrderItemWebhooks($merchantToken);

            // 8) PRODUCT_*
            $webhookService->registerProductWebhooks($merchantToken);

            // 9) STORE_*
            $webhookService->registerStoreWebhooks($merchantToken);

            // 10) TAX_*
            $webhookService->registerTaxWebhooks($merchantToken);

            // 11) TRANSACTION_*
            $webhookService->registerTransactionWebhooks($merchantToken);

            // 12) USER_*
            // TODO
            $webhookService->registerUserWebhooks($merchantToken);
        }



    }


    /**
     * @return array
     * @throws GuzzleException
     */
    public function getTokenResponse(): array
    {
        $this->businessId = $businessId = $this->context->getApi()->getParam('businessId', null);
        $this->storeId = $storeId = $this->context->getApi()->getParam('store_id', null);

        if (!$businessId || !$storeId) {
            $this->context->getLog()->error("Error: Missing required parameters");
            return [
                'success' => false,
                'status' => Response::STATUS_BAD_REQUEST,
                'error' => 'Missing required parameters',
            ];
        }

        try {
            $oauthService = new OAuthService($this->context);
            $token = $oauthService->exchangeJwtForToken();
            return [
                'success' => true,
                'data' => $token,
            ];
        } catch (\Exception $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Token exchange failed.',
            ];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Guzzle exception. Token exchange failed.',
            ];
        }
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function getMerchantTokenResponse(): array
    {
        $this->businessId = $businessId = $this->context->getApi()->getParam('businessId', null);
        $this->storeId = $storeId = $this->context->getApi()->getParam('store_id', null);
        $this->code = $code = $this->context->getApi()->getParam('code', null);

        if (!$businessId || !$storeId || !$code) {
            $this->context->getLog()->error("Error: Missing required parameters");
            return [
                'success' => false,
                'status' => Response::STATUS_BAD_REQUEST,
                'error' => 'Missing required parameters',
            ];
        }

        try {
            $oauthService = new OAuthService($this->context);
            $token = $oauthService->exchangeAuthCodeForMerchantToken($code, ConfigApp::$redirectUri);
            return [
                'success' => true,
                'data' => $token,
            ];
        } catch (\Exception $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Token exchange failed.',
            ];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                'error' => 'Guzzle exception. Token exchange failed.',
            ];
        }
    }

    public function _getTokenResponse()
    {
        return $this->getTokenResponse();
    }

    // TODO remove along with interface definition
    public function exchangeAuthorizationCode(string $authCode): array {
        $response = $this->client->post('https://services.poynt.net/token', [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $authCode,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    // TODO remove along with interface definition
    public function refreshToken(string $refreshToken): array {
        $response = $this->client->post('https://services.poynt.net/token', [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Business resource represents the merchant business. The Poynt system assumes the following business
     * hierarchy. There is the parent business at the root. Each business could have 0 or more stores. Each
     * store could have 0 or more terminals. This resource provides operations to create, update and view
     * this entire hierarchy.
     *
     * @param array $tokenResponse
     * @return array|false
     * @throws GuzzleException
     */
    public function fetchMerchantBusinessById(array $tokenResponse): array|false {

        if (HelperService::validateTokenResponse($tokenResponse)) {
            // Fetch merchant business
            if (!$merchantBusiness = $this->getMerchantService()->fetchMerchantBusinessById($tokenResponse['accessToken'], $this->businessId)) {
//            if (!$merchantBusiness = $this->getMerchantService()->fetchMerchantBusiness($tokenResponse['accessToken'], $this->businessId)) {
                $this->context->getLog()->error("Error: Failed to fetch merchant business");
                Api::response(Response::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Failed to fetch merchant business.']);
                return false;
            } else {
                return $merchantBusiness;
            }
        }
        return false;
    }


    /**
     * @param array $tokenResponse
     * @return array|false
     */
    public function fetchMerchantBusiness(array $tokenResponse): array|false {

        if (HelperService::validateTokenResponse($tokenResponse)) {
            // Fetch merchant business
            if (!$merchantBusiness = $this->getMerchantService()->fetchMerchantBusiness($tokenResponse['accessToken'])) {
                $this->context->getLog()->error("Error: Failed to fetch merchant business");
                Api::response(Response::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Failed to fetch merchant business.']);
                return false;
            } else {
                return $merchantBusiness;
            }
        }
        return false;
    }

    /**
     *
     *
     * @param array $tokenResponse
     * @return array|false
     */
    public function fetchMerchantSubscription(array $tokenResponse): array|false
    {
        if (HelperService::validateTokenResponse($tokenResponse)) {
            $subscription = $this->getMerchantService()->fetchMerchantSubscription($tokenResponse['accessToken'], $this->businessId);

            if (!$subscription) {
                $this->context->getLog()->error("Error: Failed to fetch subscription details.");
                return false;
            }
            return $subscription;
        }
        return false;
    }


    /**
     * In order to provide GoDaddy Poynt merchants with the ability to subscribe to your app
     * directly from your web application, you must connect with the billing system first to
     * fetch your active billing plans
     *
     * @param array $tokenResponse
     * @return array|false
     */
    public function fetchApplicationSubscriptionPlans(array $tokenResponse): array|false
    {
        if (HelperService::validateTokenResponse($tokenResponse)) {
            $appSubscriptionPlans = $this->getMerchantService()->fetchApplicationSubscriptionPlans($tokenResponse['accessToken']);

            if (!$appSubscriptionPlans) {
                $this->context->getLog()->error("Error: Failed to fetch subscription plans.");
                return false;
            }
            return $appSubscriptionPlans;
        }
        return false;
    }

    /**
     * @param array $tokenResponse
     * @param $businessId
     * @param $planId
     * @return array|false
     */
    public function createOrUpdateSubscription(array $tokenResponse, $businessId, $planId): array|false
    {
        $businessId = $this->businessId;
        $planId = 'todo';

        if (HelperService::validateTokenResponse($tokenResponse)) {
            $subscription = $this->getMerchantService()->createOrUpdateSubscription($tokenResponse['accessToken'], $businessId, $planId);

            if (!$subscription) {
                $this->context->getLog()->error("Error: Failed to create or update subscription.");
                return false;
            }
            return $subscription;
        }
        return false;
    }


    /**
     * @param array $tokenResponse
     * @param string $subscriptionId
     * @return false
     */
    public function deleteSubscription(array $tokenResponse, string $subscriptionId): array|false
    {
        if (HelperService::validateTokenResponse($tokenResponse)) {
            $subscriptionDeleted = $this->getMerchantService()->deleteSubscription($tokenResponse['accessToken'], $subscriptionId);

            if(!$subscriptionDeleted) {
                $this->context->getLog()->error("Error: Failed to delete subscription.");
                return false;
            }
            return $subscriptionDeleted;
        }
        return false;
    }



//    public function fetchMerchantSubscriptions(array $tokenResponse)
//    {
//        if(HelperService::validateTokenResponse($tokenResponse)) {
//            $subscriptions = $this->getMerchantService()->fetchMerchantSubscriptions($tokenResponse['accessToken'], $this->businessId);
//        }
//    }



    // TODO refactor/move all fetch methods since this is PoyntOAuthHandler
    // first we will check if this access token is suitable for requesting orders
    public function fetchMerchantOrders(array $tokenResponse): array
    {
        if (HelperService::validateTokenResponse($tokenResponse)) {
            if (!$merchantOrders = $this->getMerchantService()->fetchMerchantOrders($tokenResponse['accessToken'], $this->businessId)) {
                $this->context->getLog()->error("Error: Failed to fetch merchant orders");
                return [
                    'success' => false,
                    'status' => Response::STATUS_INTERNAL_SERVER_ERROR,
                    'error' => 'Failed to fetch merchant details.',
                ];
            }
            return [
                'success' => true,
                'data' => $merchantOrders,
            ];
        }
        return [
            'success' => false,
            'status' => Response::STATUS_BAD_REQUEST,
            'error' => 'Invalid token response',
        ];
    }





    /**
     * @param array $merchantData
     * @return bool
     */
    public function saveMerchantData(array $merchantData) : bool
    {
        if (!$saved = $this->getMerchantService()->saveMerchant($merchantData, $this->storeId, $this->tokenResponse)) {
            $this->context->getLog()->error("Error: Failed to save merchant details");
            return false;
        }
        $this->context->getLog()->info('Merchant data saved successfully.');
        return true;

//
//        Api::response(Response::STATUS_OK, ['message' => 'Merchant data saved successfully.']);
//        exit;
//
//
//
//
//        $response = $this->client->get('https://services.poynt.net/businesses/self', [
//            'headers' => [
//                'Authorization' => "Bearer $accessToken",
//            ],
//        ]);
//
//        return json_decode($response->getBody(), true);
    }
}
