<?php

namespace App\Modules\OAuth;

interface OAuthHandlerInterface {
    public function exchangeAuthorizationCode(string $authCode): array;

    public function _getTokenResponse();

    public function refreshToken(string $refreshToken): array;
//    public function fetchMerchantDetails(array $tokenResponse): array;
    public function fetchMerchantOrders(array $tokenResponse): array;
    public function saveMerchantData(array $merchantData): bool;
}
