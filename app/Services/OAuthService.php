<?php

namespace App\Services;

use App\Config\ConfigApp;
use App\Core\Api;
use App\Core\Context;
use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

class OAuthService {

    const POYNT_ENDPOINT_TOKEN = 'https://services.poynt.net/token';
    const POYNT_AUDIENCE = 'https://services.poynt.net';
    const JWT_SIGNING_ALGORITHM = 'RS256';
    const JWT_EXPIRATION_TIME = 30; // 3600

    private Api $api;
    private Connection $conn;
    private Logger $log;

    private Context $context;

    public function __construct(Context $context)
    {
//        $this->api = $api;
//        $this->conn = $conn;
//        $this->log = $log;

        $this->context = $context;
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
            $this->context->log->warning('Private key file not found');
            exit;
        }

        $privateKey = file_get_contents($privateKeyPath);

        if ($privateKey === false) {
            $this->context->log->warning('Failed to read private key file');
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
            $client = new Client();
            $response = $client->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Accept' => 'application/json',
                    'api-version' => '1.2',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grantType' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorResponse = $e->getResponse()->getBody()->getContents();
                $this->context->log->error("Error: " . $errorResponse);
            } else {
                $this->context->log->error("Error: " . $e->getMessage());
            }
        }

        return $responseData;
    }

    /**
     * @param string $authCode
     * @param string $redirectUri
     * @return mixed|void|null
     * @throws GuzzleException
     */
    public function exchangeAuthCodeForMerchantToken(string $authCode, string $redirectUri)
    {
        // 1. Load private key
        $basePath = dirname(__DIR__, 2);
        $privateKeyPath = $basePath . DIRECTORY_SEPARATOR . 'private-key.pem';
        if (!file_exists($privateKeyPath)) {
            $this->context->log->warning('Private key file not found');
            exit;
        }

        $privateKey = file_get_contents($privateKeyPath);
        if ($privateKey === false) {
            $this->context->log->warning('Failed to read private key file');
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
            $client = new Client();
            $response = $client->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'api-version'   => '1.2',
                    'Authorization' => 'Bearer ' . $jwt,
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
                $this->context->log->error("Error: " . $errorResponse);
            } else {
                $this->context->log->error("Error: " . $e->getMessage());
            }
        }

        return null;
    }
}

