<?php

namespace App\Modules\OAuth;

use App\Config\ConfigApp;
use App\Core\Response;
use App\Core\Context;
use App\Services\OAuthService;
use App\Services\TokenService;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\WebhookService;

class PoyntOAuthHandler implements OAuthHandlerInterface
{

    private ?string $storeId = null;
    private ?string $businessId = null;
    private Context $context;
    private ?string $code = null;

    public function __construct(Context $context) {
        $this->context = $context;
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
            $merchantAccessToken = $oauthService->exchangeAuthCodeForMerchantToken(
                $code,
                ConfigApp::$redirectUri
            );
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

            // APPLICATION_SUBSCRIPTION_* hooks are registered separately via
            // scripts/register_subscription_webhooks.php for production rollouts.

            // BUSINESS_USER_*
            // TODO
            $webhookService->registerBusinessUserWebhooks($merchantToken);

            // CATALOG_*
            $webhookService->registerCatalogWebhooks($merchantToken);

            // CATEGORY_*
            $webhookService->registerCategoryWebhooks($merchantToken);

            // INVENTORY_UPDATED (only one event under this prefix)
            $webhookService->registerInventoryWebhook($merchantToken);

            // ORDER_*
            $webhookService->registerOrderWebhooks($merchantToken);

            // ORDER_ITEM_*
            $webhookService->registerOrderItemWebhooks($merchantToken);

            // PRODUCT_*
            $webhookService->registerProductWebhooks($merchantToken);

            // STORE_*
            $webhookService->registerStoreWebhooks($merchantToken);

            // TAX_*
            $webhookService->registerTaxWebhooks($merchantToken);

            // TRANSACTION_*
            $webhookService->registerTransactionWebhooks($merchantToken);

            // USER_*
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
            $appAccessToken = $oauthService->exchangeJwtForToken();

            $token = $oauthService->exchangeAuthCodeForMerchantToken(
                $code,
                ConfigApp::$redirectUri,
                is_array($appAccessToken) ? ($appAccessToken['accessToken'] ?? null) : null
            );
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
}
