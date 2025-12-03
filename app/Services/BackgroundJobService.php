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
        foreach ($this->fetchActiveTenantIds() as $bizId) {
            try {
                $tokens = $this->tokenService->findExpiringAppTokens($bizId);
            } catch (\Throwable $e) {
                $this->log->error("Failed to fetch expiring app tokens for tenant={$bizId}: " . $e->getMessage());
                continue;
            }

            foreach ($tokens as $token) {
                try {
                    $newToken = $this->oauthService->exchangeJwtForToken();
                    if (HelperService::validateTokenResponse($newToken)) {
                        $this->tokenService->saveAppToken($bizId, $newToken);
                        $this->tokenService->logRefreshAttempt($bizId, 'app', true);
                    } else {
                        $this->tokenService->logRefreshAttempt($bizId, 'app', false, 'invalid token response');
                    }
                } catch (\Throwable $e) {
                    $this->log->error("Failed to refresh app token for tenant={$bizId}: " . $e->getMessage());
                    $this->tokenService->logRefreshAttempt($bizId, 'app', false, $e->getMessage());
                }
            }
        }
    }

    /**
     * Refresh expiring merchant-level tokens.
     */
    private function refreshMerchantTokens(): void
    {
        foreach ($this->fetchActiveTenantIds() as $bizId) {
            try {
                $tokens = $this->tokenService->findExpiringMerchantTokens($bizId);
            } catch (\Throwable $e) {
                $this->log->error("Failed to fetch expiring merchant tokens for tenant={$bizId}: " . $e->getMessage());
                continue;
            }

            foreach ($tokens as $token) {
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
                    $this->log->error("Failed to refresh merchant token for tenant={$bizId}: " . $e->getMessage());
                    $this->tokenService->logRefreshAttempt($bizId, 'merchant', false, $e->getMessage());
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function fetchActiveTenantIds(): array
    {
        try {
            $rows = $this->context->getConn()->fetchFirstColumn(
                'SELECT business_id FROM business WHERE active = TRUE'
            );

            return array_map('strval', $rows ?: []);
        } catch (\Throwable $e) {
            $this->log->error('Failed to load active tenants: ' . $e->getMessage());

            return [];
        }
    }
}
