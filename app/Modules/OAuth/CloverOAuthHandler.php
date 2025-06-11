<?php

namespace App\Modules\OAuth;

use App\Config\ConfigApp;
use App\Config\ConfigClover;
use GuzzleHttp\Client;

//class CloverOAuthHandler implements OAuthHandlerInterface
class CloverOAuthHandler
{
    private $client;
    private $clientId;
    private $clientSecret;

    public function __construct() {
        $this->client = new Client();
        $this->clientId = ConfigClover::$clover[ConfigApp::$environment][ConfigApp::$location]['client_id'];
        $this->clientSecret = ConfigClover::$clover[ConfigApp::$environment][ConfigApp::$location]['client_secret'];
    }
    public function exchangeAuthorizationCode(string $authCode): array
    {
        $response = $this->client->post('https://api.clover.com/oauth/token', [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $authCode,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    public function refreshToken(string $refreshToken): array
    {
        $response = $this->client->post('https://api.clover.com/oauth/token', [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    public function fetchMerchantDetails(string $accessToken): array
    {
        $response = $this->client->get('https://api.clover.com/v3/merchants/self', [
            'headers' => [
                'Authorization' => "Bearer $accessToken",
            ],
        ]);

        return json_decode($response->getBody(), true);
    }
}