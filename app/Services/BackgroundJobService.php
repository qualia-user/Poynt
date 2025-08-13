<?php

namespace App\Services;

use App\Core\Context;
use Psr\Log\LoggerInterface;

/**
 * BackgroundJobService periodically checks for expiring tokens
 * and refreshes them using OAuthService, persisting results
 * via TokenService.
 */
class BackgroundJobService
{
    private Context $context;
    private TokenService $tokenService;
    private OAuthService $oauthService;
    private LoggerInterface $log;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->log = $context->getLog();
        $this->tokenService = new TokenService($context);
        $this->oauthService = new OAuthService($context);
    }

    /**
     * Refresh both app and merchant tokens that are close to expiring.
     */
    public function refreshExpiringTokens(): void
    {
        $this->refreshAppTokens();
        $this->refreshMerchantTokens();
    }

    /**
     * Refresh expiring app-level tokens.
     */
    private function refreshAppTokens(): void
    {
        try {
            $tokens = $this->tokenService->findExpiringAppTokens();
        } catch (\Throwable $e) {
            $this->log->error('Failed to fetch expiring app tokens: ' . $e->getMessage());
            return;
        }

        foreach ($tokens as $token) {
            $bizId = $token['business_id'];

            try {
                $newToken = $this->oauthService->exchangeJwtForToken();
                if (HelperService::validateTokenResponse($newToken)) {
                    $this->tokenService->saveAppToken($bizId, $newToken);
                    $this->tokenService->logRefreshAttempt($bizId, 'app', true);
                } else {
                    $this->tokenService->logRefreshAttempt($bizId, 'app', false, 'invalid token response');
                }
            } catch (\Throwable $e) {
                $this->log->error("Failed to refresh app token for business_id={$bizId}: " . $e->getMessage());
                $this->tokenService->logRefreshAttempt($bizId, 'app', false, $e->getMessage());
            }
        }
    }

    /**
     * Refresh expiring merchant-level tokens.
     */
    private function refreshMerchantTokens(): void
    {
        try {
            $tokens = $this->tokenService->findExpiringMerchantTokens();
        } catch (\Throwable $e) {
            $this->log->error('Failed to fetch expiring merchant tokens: ' . $e->getMessage());
            return;
        }

        foreach ($tokens as $token) {
            $bizId = $token['business_id'];
            $refreshToken = $token['refresh_token'];

            try {
                $newToken = $this->oauthService->refreshMerchantToken($refreshToken);
                if ($newToken && HelperService::validateTokenResponse($newToken)) {
                    $this->tokenService->saveMerchantToken($bizId, $newToken);
                    $this->tokenService->logRefreshAttempt($bizId, 'merchant', true);
                } else {
                    $this->tokenService->logRefreshAttempt($bizId, 'merchant', false, 'invalid token response');
                }
            } catch (\Throwable $e) {
                $this->log->error("Failed to refresh merchant token for business_id={$bizId}: " . $e->getMessage());
                $this->tokenService->logRefreshAttempt($bizId, 'merchant', false, $e->getMessage());
            }
        }
    }
}
