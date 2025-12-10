<?php

namespace App\Services;

use App\Config\ConfigApp;
use App\Core\Context;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;

class OAuthService {

    const POYNT_ENDPOINT_TOKEN = 'https://services.poynt.net/token';
    const POYNT_AUDIENCE = 'https://services.poynt.net';
    const JWT_SIGNING_ALGORITHM = 'RS256';
    const JWT_EXPIRATION_TIME = 30; // 3600

    private Context $context;
    private ClientInterface $httpClient;

    public function __construct(Context $context, ?ClientInterface $httpClient = null)
    {
        $this->context = $context;
        $this->httpClient = $httpClient ?? $context->getHttpClient();
    }


    /**
     * @return array|mixed|void
     * @throws GuzzleException
     */
    public function exchangeJwtForToken()
    {
        // Start from the directory of the current file
        $basePath = dirname(__DIR__, 2);
        $privateKeyPath = $basePath . DIRECTORY_SEPARATOR . 'private-key.pem';

        if (!file_exists($privateKeyPath)) {
            $this->context->getLog()->warning('Private key file not found');
            exit;
        }

        $privateKey = file_get_contents($privateKeyPath);

        if ($privateKey === false) {
            $this->context->getLog()->warning('Failed to read private key file');
        }

        // 1. Generate JWT
        $now = time();
        $appUrn = 'urn:aid:' . ConfigApp::$appId;
        $jwtPayload = [
            'exp' => $now + self::JWT_EXPIRATION_TIME,
            'iat' => $now,
            'iss' => $appUrn,
            'sub' => $appUrn,
            'aud' => self::POYNT_AUDIENCE,
            'jti' => Uuid::uuid4()->toString(),
        ];
        $jwt = JWT::encode($jwtPayload, $privateKey, self::JWT_SIGNING_ALGORITHM);

        // 2. Send token exchange request
        try {
            $responseData = [];
            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Accept' => 'application/json',
                    'api-version' => '1.2',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    // Poynt expects standard OAuth2 parameter naming
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                $this->context->getLog()->error("Error: " . $errorResponse);
            } else {
                $this->context->getLog()->error("Error: " . $e->getMessage());
            }
        }

        return $responseData;
    }

    /**
     * @param string $authCode
     * @param string $redirectUri
     * @param string|null $appAccessToken
     * @return mixed|void|null
     * @throws GuzzleException
     */
    public function exchangeAuthCodeForMerchantToken(string $authCode, string $redirectUri, ?string $appAccessToken = null)
    {
        // 1. Load private key
        $basePath = dirname(__DIR__, 2);
        $privateKeyPath = $basePath . DIRECTORY_SEPARATOR . 'private-key.pem';
        if (!file_exists($privateKeyPath)) {
            $this->context->getLog()->warning('Private key file not found');
            exit;
        }

        $privateKey = file_get_contents($privateKeyPath);
        if ($privateKey === false) {
            $this->context->getLog()->warning('Failed to read private key file');
            exit;
        }

        // 2. Generate self-signed JWT
        $now = time();
        $appUrn = 'urn:aid:' . ConfigApp::$appId;
        $jwtPayload = [
            'exp' => $now + self::JWT_EXPIRATION_TIME,
            'iat' => $now,
            'iss' => $appUrn,
            'sub' => $appUrn,
            'aud' => self::POYNT_AUDIENCE,
            'jti' => Uuid::uuid4()->toString(),
        ];

        // Sign the JWT with your private key (e.g. RS256)
        $jwt = JWT::encode($jwtPayload, $privateKey, self::JWT_SIGNING_ALGORITHM);

        // 3. Send POST request to exchange code for Merchant Access Token
        try {
            $authorizationToken = $appAccessToken ?? $jwt;

            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'api-version'   => '1.2',
                    'Authorization' => 'Bearer ' . $authorizationToken,
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'client_id'    => ConfigApp::$appId,
                    'code'         => $authCode,
                ],
            ]);

            // 4. Parse JSON response
            $responseData = json_decode($response->getBody(), true);
            return $responseData;

        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                $this->context->getLog()->error("Error: " . $errorResponse);
            } else {
                $this->context->getLog()->error("Error: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Refresh a merchant token using a refresh token.
     *
     * @param string $refreshToken
     * @return array|null
     */
    public function refreshMerchantToken(string $refreshToken): ?array
    {
        try {
            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'api-version'  => '1.2',
                ],
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id'     => ConfigApp::$appId,
                ],
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                $this->context->getLog()->error("Error: " . $errorResponse);
            } else {
                $this->context->getLog()->error("Error: " . $e->getMessage());
            }
        }

        return null;
    }
}

