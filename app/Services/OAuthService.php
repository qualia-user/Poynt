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

        // 1. Self-signed JWT
        $jwt = $this->generateSelfSignedJwt();

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



    private function generateSelfSignedJwt(): string
    {
        $basePath = dirname(__DIR__, 2);
        $privateKeyPath = $basePath . DIRECTORY_SEPARATOR . 'private-key.pem';

        if (!is_readable($privateKeyPath)) {
            $this->context->getLog()->error('Private key file not found or not readable: ' . $privateKeyPath);
            throw new \RuntimeException('Private key file not found');
        }

        $privateKey = file_get_contents($privateKeyPath);
        if ($privateKey === false) {
            $this->context->getLog()->error('Failed to read private key file: ' . $privateKeyPath);
            throw new \RuntimeException('Failed to read private key');
        }

        $now   = time();
        $appUrn = 'urn:aid:' . ConfigApp::$appId;

        $jwtPayload = [
            'exp' => $now + self::JWT_EXPIRATION_TIME, // npr. 300 ili 600 sekundi
            'iat' => $now,
            'iss' => $appUrn,
            'sub' => $appUrn,
            'aud' => self::POYNT_AUDIENCE,
            'jti' => Uuid::uuid4()->toString(),
        ];

        return JWT::encode($jwtPayload, $privateKey, self::JWT_SIGNING_ALGORITHM);
    }

    /**
     * @param string $authCode
     * @param string $redirectUri
     * @return mixed|void|null
     * @throws GuzzleException
     */
    public function exchangeAuthCodeForMerchantToken(
        string $authCode,
        string $redirectUri
    ): ?array {
        try {
//            $authorizationToken = $this->resolveAuthorizationCodeToken($appAccessToken);
            $jwt = $this->generateSelfSignedJwt();
            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'api-version'   => '1.2',
                    'Authorization' => 'Bearer ' . $jwt,
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'client_id'    => 'urn:aid:' . ConfigApp::$appId,
                    'code'         => $authCode,
                ],
            ]);

            $body = (string) $response->getBody();

            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorBody = (string)$e->getResponse()->getBody();
                $this->context->getLog()->error(sprintf(
                    'MerchantToken exchange error (HTTP %s): %s',
                    $e->getResponse()->getStatusCode(),
                    $errorBody
                ));
            } else {
                $this->context->getLog()->error('MerchantToken exchange error: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->context->getLog()->error('MerchantToken unexpected error: ' . $e->getMessage());
        }

        return null;
    }



    public function _exchangeAuthCodeForMerchantToken(string $authCode, string $redirectUri, ?string $appAccessToken = null)
    {
        $this->logPoyntToFile('Starting merchant token exchange', [
            'redirectUri'      => $redirectUri,
            'authCode_preview' => substr($authCode, 0, 8) . '***', // sigurnosni preview
        ]);

        // 1. Load private key
        $basePath       = dirname(__DIR__, 2);
        $privateKeyPath = $basePath . DIRECTORY_SEPARATOR . 'private-key.pem';

        if (!file_exists($privateKeyPath)) {
            $this->logPoyntToFile('Private key file not found', [
                'privateKeyPath' => $privateKeyPath,
            ]);
            exit;
        }

        $privateKey = file_get_contents($privateKeyPath);
        if ($privateKey === false) {
            $this->logPoyntToFile('Failed to read private key file', [
                'privateKeyPath' => $privateKeyPath,
            ]);
            exit;
        }

        $this->logPoyntToFile('Private key successfully loaded');

        // 2. Generate self-signed JWT
        $now    = time();
        $appUrn = 'urn:aid:' . ConfigApp::$appId;

        $jwtPayload = [
            'exp' => $now + self::JWT_EXPIRATION_TIME,
            'iat' => $now,
            'iss' => $appUrn,
            'sub' => $appUrn,
            'aud' => self::POYNT_AUDIENCE,
            'jti' => Uuid::uuid4()->toString(),
        ];

        $this->logPoyntToFile('JWT payload for merchant token', [
            'jwtPayload' => $jwtPayload,
        ]);

        // Sign the JWT with your private key (e.g. RS256)
        $jwt = JWT::encode($jwtPayload, $privateKey, self::JWT_SIGNING_ALGORITHM);

        $this->logPoyntToFile('Signed JWT generated', [
            'jwt_preview' => substr($jwt, 0, 25) . '...',
            'jwt_length'  => strlen($jwt),
        ]);

        // 3. Send POST request to exchange code for Merchant Access Token
        try {
            $this->logPoyntToFile('Sending merchant token request', [
                'token_endpoint' => self::POYNT_ENDPOINT_TOKEN,
                'headers'        => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'api-version'  => '1.2',
                    'Authorization'=> 'Bearer ***', // ne logiramo stvarni token
                ],
                'form_params'    => [
                    'grant_type'   => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'client_id'    => $appUrn,
                    'code_preview' => substr($authCode, 0, 8) . '***',
                ],
            ]);

            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'api-version'   => '1.2',
                    'Authorization' => 'Bearer ' . $jwt,
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'client_id'    => $appUrn,
                    'code'         => $authCode,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $bodyRaw    = (string) $response->getBody();

            $this->logPoyntToFile('Merchant token response received', [
                'status'  => $statusCode,
                'bodyRaw' => $bodyRaw,
            ]);

            // 4. Parse JSON response
            $responseData = json_decode($bodyRaw, true);

            $this->logPoyntToFile('Merchant token response decoded', [
                'responseData' => $responseData,
            ]);

            return $responseData;

        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response     = $e->getResponse();
                $statusCode   = $response->getStatusCode();
                $reasonPhrase = $response->getReasonPhrase();
                $errorBody    = $response->getBody()->getContents();

                $this->logPoyntToFile('Merchant token exchange failed with HTTP error', [
                    'status'  => $statusCode,
                    'reason'  => $reasonPhrase,
                    'body'    => $errorBody,
                ]);
            } else {
                $this->logPoyntToFile('Merchant token exchange failed with network/other error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logPoyntToFile('Merchant token exchange finished with NULL result');
        return null;
    }

    public function _exchangeAuthCodeForMerchantToken(string $authCode, string $redirectUri)
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
            // The authorization_code grant must be authorized by an application issuer.
            // Using the self-signed JWT ensures the issuer is `urn:aid:<APP_ID>` instead
            // of a previously issued token (issuer `https://services.poynt.net`) that
            // would be rejected with INVALID_PARAMETER.
            $authorizationToken = $jwt;

            $response = $this->httpClient->post(self::POYNT_ENDPOINT_TOKEN, [
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'api-version'   => '1.2',
                    'Authorization' => 'Bearer ' . $authorizationToken,
                ],
                'form_params' => [
                    'grant_type'   => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'client_id'    => $appUrn,
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

    private function logPoyntToFile(string $message, array $context = []): void
    {
        // 1) Izračun base patha
        $basePath = dirname(__DIR__, 2); // prilagodi ako ti je projekt drugdje

        // 2) Folder za logove
        $logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            // pokušaj kreirati
            if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                // Ako ovo ne uspije, logiraj barem u PHP error log
                error_log('[Poynt] Failed to create log directory: ' . $logDir);
                return;
            }
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'poynt-merchant-token.log';

        $entry = [
            'time'    => date('c'),
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $result = file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            // Ako ne može pisati u file, barem ćeš vidjeti ovo u error logu
            error_log('[Poynt] Failed to write to log file: ' . $logFile);
        }
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


    private function resolveAuthorizationCodeToken(?string $appAccessToken): string
    {
        if ($appAccessToken !== null) {
            if ($this->tokenHasAppIssuer($appAccessToken)) {
                return $appAccessToken;
            }

            $this->context->getLog()->warning('App access token has non-application issuer; falling back to self-signed JWT.');
        }

        $this->context->getLog()->info('Using self-signed JWT for authorization_code grant.');

        return $this->generateSelfSignedJwt();
    }

    private function tokenHasAppIssuer(string $token): bool
    {
        $segments = explode('.', $token);

        if (count($segments) < 2) {
            return false;
        }

        $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);

        if (!is_array($payload) || !array_key_exists('iss', $payload)) {
            return false;
        }

        return $payload['iss'] === 'urn:aid:' . ConfigApp::$appId;
    }

}

