<?php

namespace App\Modules\OAuth;

interface OAuthHandlerInterface
{
    public function retrieveTokens(): array;
    public function storeTokens($appToken, $merchantToken): void;
    public function registerWebhooks(): void;
    public function getBusinessId(): ?string;
    public function getStoreId(): ?string;
}
