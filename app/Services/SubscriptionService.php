<?php

namespace App\Services;

use App\Config\ConfigApp;
use App\Core\Context;
use App\Core\Response;
use App\Services\Support\PoyntDataFormatter as Format;
use DateInterval;
//use DateMalformedIntervalStringException;
//use DateMalformedStringException;
use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use function array_is_list;
use function str_starts_with;

class SubscriptionService
{
    public const POYNT_BILLING_BASE = 'https://billing.poynt.net/organizations';
    private const DEFAULT_TRIAL_DAYS = 14;
    private Context $context;
    private ClientInterface $http;
    private ?string $businessId = null;
    private ?string $storeId = null;

    public function __construct(
        Context $context,
        ?string $businessId = null,
        ?string $storeId = null,
        ?ClientInterface $httpClient = null
    )
    {
        $this->context = $context;
        if ($httpClient !== null) {
            $this->http = $httpClient;
        } else {
            $this->http = $context->getHttpClientFactory()->create([
                'base_uri' => self::POYNT_BILLING_BASE,
                'timeout' => 10.0,
            ]);
        }

        $this->businessId = $businessId;
        $this->storeId = $storeId;
    }

    /**
     * Fetches the list of subscription plans defined for your Poynt app.
     *
     * @param string $appAccessToken  App‐level OAuth token (JWT‐exchange)
     * @return array|null  List of plans (decoded JSON). Example keys: ['planId'=>'...', 'name'=>'...', 'price'=>..., …]
     * @throws RuntimeException on HTTP or decoding error
     */
    public function fetchPlans(
        string $appAccessToken,
        ?string $currency = null,
        ?string $businessId = null,
        ?string $status = null
    ): ?array
    {
        try {
            $query = array_filter([
                'currency'   => $currency,
                'businessId' => $businessId,
                'status'     => $status,
            ], static fn($value) => $value !== null && $value !== '');

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $appAccessToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
            ];

            if ($query !== []) {
                $options['query'] = $query;
            }

            $response = $this->http->get($this->buildAppResourceUrl('plans'), $options);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $e) {
            $this->context->getLog()->error(sprintf(
                'Error parsing plans response: %s. Body: %s',
                $e->getMessage(),
                $body ?? ''
            ));

            return null;
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error("Error fetching plans: " . $msg);

            return null;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Error fetching plans: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Legacy alias for fetching application subscription plans. Uses an
     * app-level access token obtained via the JWT exchange.
     *
     * @param string $appAccessToken
     * @return array|null
     */
    public function fetchApplicationSubscriptionPlans(
        string $appAccessToken,
        ?string $currency = null,
        ?string $businessId = null,
        ?string $status = null
    ): ?array
    {
        return $this->fetchPlans($appAccessToken, $currency, $businessId, $status);
    }

    /**
     * Fetch subscriptions for a merchant using the merchant's access token.
     *
     * @param string $merchantAccessToken
     * @param string $businessId
     * @param string|null $storeId
     * @param string|null $deviceId
     * @param string|null $status
     * @return array|null
     */
    public function fetchMerchantSubscriptions(
        string $merchantAccessToken,
        string $businessId,
        ?string $storeId = null,
        ?string $deviceId = null,
        ?string $status = null
    ): ?array {
        $endpoint = $this->buildAppResourceUrl('subscriptions');

        $query = array_filter([
            'businessId' => $businessId,
            'storeId'    => $storeId,
            'deviceId'   => $deviceId,
            'status'     => $status,
        ], static fn($value) => $value !== null && $value !== '');

        try {
            $response = $this->http->get($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Accept'        => 'application/json',
                ],
                'query' => $query,
            ]);
            return json_decode((string)$response->getBody(), true);
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error('Error fetching merchant subscriptions: ' . $msg);
            return null;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('Error fetching merchant subscriptions: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convenience wrapper around fetchMerchantSubscriptions for callers that
     * expect a singular method name.
     *
     * @param string      $merchantAccessToken
     * @param string      $businessId
     * @param string|null $storeId
     * @param string|null $deviceId
     * @param string|null $status
     * @return array|null
     */
    public function fetchMerchantSubscription(
        string $merchantAccessToken,
        string $businessId,
        ?string $storeId = null,
        ?string $deviceId = null,
        ?string $status = null
    ): ?array {
        return $this->fetchMerchantSubscriptions(
            $merchantAccessToken,
            $businessId,
            $storeId,
            $deviceId,
            $status
        );
    }

    /**
     * Determine if a subscription's trial period has expired using its ID.
     *
     *  Queries the local `subscription` table for the `trial_end_at` timestamp and
     *  compares it with the current time in UTC.
     *
     *  When the trial has elapsed callers may respond with
     *  {@see Response::STATUS_FREE_TRIAL_FINISHED} to enforce limits.
     *
     * @param string $subscriptionId Subscription identifier.
     * @return bool True if the trial end date has passed, false otherwise.
     */
    public function hasTrialExpired(string $subscriptionId): bool
    {
        $sql = <<<SQL
        SELECT trial_end_at
          FROM subscription
         WHERE subscription_id = :sub_id
         LIMIT 1
        SQL;

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [
                'sub_id' => $subscriptionId,
            ]);
        } catch (Exception $e) {
            $this->context->getLog()->error('Error fetching trial end date: ' . $e->getMessage());
            return false;
        }

        $trialEnd = $row['trial_end_at'] ?? null;
        if (!$trialEnd) {
            return false;
        }

        try {
            $trialEndDate = new DateTime($trialEnd, new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            return $now > $trialEndDate;
        } catch (Exception $e) {
            $this->context->getLog()->error('Error parsing trial end date: ' . $e->getMessage());
            return false;
        }
    }


    // ────────────────────────────────────────────────────────────────────────────
    // 1) Start a local-only free trial
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Marks a given store as “free trial” in the local database, without calling Poynt.
     *
     * @param string $businessId Poynt business ID
     * @param string $storeId Poynt store ID
     * @param string $trialPlanId Your local “free trial” plan identifier (e.g. "free_trial")
     *
     * @return string  The generated subscription_id (UUID)
     */
    public function startFreeTrial(
        string $businessId,
        string $storeId,
        string $trialPlanId = 'free_trial'
    ): string {
        // 1) Generate a new UUID for subscription_id
        $subscriptionId = Uuid::uuid4()->toString();

        // 2) Compute trial start/end timestamps (UTC)
        $trialDays = self::DEFAULT_TRIAL_DAYS;
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $trialEnd = (clone $now)->add(new DateInterval("P{$trialDays}D"));

        // 3) Insert into local subscription table as “trial”
        $sql = <<<SQL
        INSERT INTO subscription (
          subscription_id,
          business_id,
          store_id,
          plan_id,
          status,
          phase,
          trial_start_at,
          trial_end_at,
          start_at,
          current_period_end,
          cancel_at_period_end,
          canceled_at,
          created_at,
          updated_at
        ) VALUES (
          :sub_id, :biz, :store, :plan,
          :status, :phase,
          :tstart, :tend,
          :saat, :cpe,
          FALSE, NULL,
          NOW(), NOW()
        )
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'sub_id' => $subscriptionId,
                'biz'    => $businessId,
                'store'  => $storeId,
                'plan'   => $trialPlanId,
                'status' => 'free_trial',
                'phase'  => 'trial',
                'tstart' => $now->format('Y-m-d H:i:sP'),
                'tend'   => $trialEnd->format('Y-m-d H:i:sP'),
                'saat'   => $now->format('Y-m-d H:i:sP'),
                'cpe'    => $trialEnd->format('Y-m-d H:i:sP'),
            ]);
        } catch (Exception $e) {
            $this->context->getLog()->error("Failed to insert initial subscription for store_id={$storeId}: " . $e->getMessage() . ". " . (int)$e->getCode() . ". Exception: ". json_encode($e));
        }

        return $subscriptionId;
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 2) Create a paid subscription on Poynt, then upsert locally
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Creates a new paid subscription on Poynt (POST) and upserts the result into local DB.
     *
     * @param string $merchantAccessToken Merchant-level OAuth token
     * @param string $businessId Poynt business ID
     * @param string $storeId Poynt store ID
     * @param string $planId Poynt plan ID (e.g. "plan_basic_monthly")
     * @param bool $replaceV2 Always false in this scenario
     * @param string|null $startAt ISO8601 UTC start timestamp (e.g. "2025-04-22T19:41:24.649Z"); if null, uses now
     *
     * @return array  Decoded JSON response from Poynt (includes subscriptionId, status, phase, etc.)
     * @throws RuntimeException on HTTP or SQL error
     * @throws GuzzleException
     */
    public function createSubscription(
        string      $merchantAccessToken,
        string      $businessId,
        string      $storeId,
        string      $planId,
        bool        $replaceV2 = false,
        ?string     $startAt = null
    ): array {
        $orgId = ConfigApp::$orgId ?? '';
        if ($orgId === '') {
            throw new RuntimeException('ConfigApp::$orgId must be configured to create subscriptions.');
        }
        if ($merchantAccessToken === '') {
            $this->context->getLog()->error('Cannot create subscription without a merchant access token.');

            return [];
        }

        $endpoint = $this->buildAppResourceUrl('subscriptions');

        // If startAt is not provided, use current UTC
        $timestamp = $startAt
            ?? (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

        $body = [
            'businessId' => $businessId,
            'orgId'      => $orgId,
            'planId'     => $planId,
            'replaceV2'  => $replaceV2,
            'startAt'    => $timestamp,
            'storeId'    => $storeId,
        ];

        try {
            $response = $this->http->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);
            $respData = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error("Poynt CREATE subscription failed: " . $msg . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));

            return [];
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Poynt CREATE subscription failed: " . $e->getMessage());

            return [];
        } catch (JsonException $e) {
            $this->context->getLog()->error('Poynt CREATE subscription returned invalid JSON: ' . $e->getMessage());

            return [];
        }

        if (!is_array($respData)) {
            $this->context->getLog()->warning('Poynt CREATE subscription returned unexpected payload type.');

            return [];
        }

        // Overwrite local row (which likely already exists as a “trial”) with Poynt’s data
        $this->upsertLocalSubscription($respData, $storeId);

        return $respData;
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 3) Fetch subscriptions from Poynt (GET), then upsert locally
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Fetches all subscriptions for a given businessId from Poynt,
     * preferring a stored merchant access token when available, then upserts
     * each into the local `subscription` table, and returns the list.
     *
     * @param string $appAccessToken  App-level OAuth token (used when no merchant token is available or authorized)
     * @param string $businessId      Poynt business ID
     * @return array<int, array<string, mixed>>|null  List of subscription objects (decoded JSON) or null when request fails
     * @throws RuntimeException on HTTP/decoding error
     */
    public function fetchSubscriptions(
        string $appAccessToken,
        string $businessId,
        ?string $storeId = null,
        ?string $deviceId = null,
        ?string $status = null
    ): ?array {
        if ($businessId === '') {
            throw new InvalidArgumentException('Business ID must be provided to fetch subscriptions.');
        }

        $query = array_filter([
            'businessId' => $businessId,
            'storeId'    => $storeId,
            'deviceId'   => $deviceId,
            'status'     => $status,
        ], static fn($value) => $value !== null && $value !== '');

        $decoded = null;
        $attemptedMerchantFetch = false;

        $merchantToken = $this->getStoredMerchantToken($businessId);
        if ($merchantToken !== null) {
            $attemptedMerchantFetch = true;

            try {
                $decoded = $this->performSubscriptionFetch($merchantToken, $query);
            } catch (RequestException $merchantException) {
                $response = $merchantException->getResponse();
                if ($response !== null && $response->getStatusCode() === 401) {
                    $this->context->getLog()->info(
                        sprintf(
                            'Merchant token unauthorized for business %s; retrying subscription fetch with app token',
                            $businessId
                        )
                    );
                } else {
                    $this->logSubscriptionRequestException(
                        $merchantException,
                        'Poynt GET subscription with merchant token failed'
                    );
                }
            } catch (JsonException $merchantException) {
                $this->context->getLog()->error(
                    'Poynt GET subscription with merchant token returned invalid JSON: ' .
                    $merchantException->getMessage()
                );
            } catch (GuzzleException $merchantException) {
                $this->context->getLog()->error(
                    'Poynt GET subscription with merchant token failed: ' .
                    $merchantException->getMessage()
                );
            }
        }

        if ($decoded === null) {
            try {
                $decoded = $this->performSubscriptionFetch($appAccessToken, $query);
            } catch (RequestException $e) {
                if (!$attemptedMerchantFetch) {
                    $decoded = $this->retryFetchSubscriptionsWithMerchantToken($businessId, $query, $e);
                }

                if ($decoded === null) {
                    $this->logSubscriptionRequestException($e, 'Poynt GET subscription failed');

                    return null;
                }
            } catch (JsonException $e) {
                $this->context->getLog()->error('Poynt GET subscription returned invalid JSON: ' . $e->getMessage());

                return null;
            } catch (GuzzleException $e) {
                $this->context->getLog()->error('Poynt GET subscription failed: ' . $e->getMessage());

                return null;
            }
        }

        $respList = $this->normalizeSubscriptionList($decoded);

        // Upsert each subscription into local DB
        foreach ($respList as $index => $sub) {
            if (!is_array($sub)) {
                $this->context->getLog()->warning(
                    sprintf('Skipping non-array subscription payload at index %s', (string) $index)
                );
                continue;
            }

            $resolvedStoreId = $sub['storeId'] ?? $storeId ?? $this->storeId;
            $this->upsertLocalSubscription($sub, $resolvedStoreId);
        }

        return $respList;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     * @throws RequestException
     * @throws GuzzleException
     * @throws JsonException
     */
    private function performSubscriptionFetch(string $accessToken, array $query): array
    {
        $endpoint = $this->buildAppResourceUrl('subscriptions');

        $response = $this->http->get($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
            ],
            'query' => $query,
        ]);

        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function retryFetchSubscriptionsWithMerchantToken(
        string $businessId,
        array $query,
        RequestException $exception
    ): ?array {
        if (!$this->shouldRetryWithMerchantToken($exception)) {
            return null;
        }

        $merchantToken = $this->getStoredMerchantToken($businessId);
        if ($merchantToken === null) {
            return null;
        }

        $this->context->getLog()->info(
            sprintf('Retrying subscription fetch with merchant token for business %s', $businessId)
        );

        try {
            return $this->performSubscriptionFetch($merchantToken, $query);
        } catch (RequestException $retryException) {
            $this->logSubscriptionRequestException($retryException, 'Poynt GET subscription retry failed');
        } catch (JsonException $retryException) {
            $this->context->getLog()->error('Poynt GET subscription retry returned invalid JSON: ' . $retryException->getMessage());
        } catch (GuzzleException $retryException) {
            $this->context->getLog()->error('Poynt GET subscription retry failed: ' . $retryException->getMessage());
        }

        return null;
    }

    private function getStoredMerchantToken(string $businessId): ?string
    {
        $tokenService = new TokenService($this->context);

        try {
            $merchantToken = $tokenService->getMerchantToken($businessId);
        } catch (\Throwable $tokenError) {
            $this->context->getLog()->error(
                sprintf(
                    'SubscriptionService::fetchSubscriptions failed to load merchant token for business %s: %s',
                    $businessId,
                    $tokenError->getMessage()
                )
            );

            return null;
        }

        if (!is_string($merchantToken) || $merchantToken === '') {
            $this->context->getLog()->warning(
                sprintf(
                    'SubscriptionService::fetchSubscriptions has no merchant token stored for business %s; cannot retry.',
                    $businessId
                )
            );

            return null;
        }

        return $merchantToken;
    }

    private function shouldRetryWithMerchantToken(RequestException $exception): bool
    {
        $response = $exception->getResponse();
        if ($response === null || $response->getStatusCode() !== 401) {
            return false;
        }

        $bodyStream = $response->getBody();
        $body = (string) $bodyStream;
        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }
        if ($body === '') {
            return false;
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $this->context->getLog()->debug(
                'Failed to decode subscription error response for retry decision: ' . $jsonException->getMessage()
            );

            return false;
        }

        $messages = array_filter([
            $payload['developerMessage'] ?? null,
            $payload['message'] ?? null,
        ], 'is_string');

        foreach ($messages as $message) {
            if (stripos($message, 'businessid must be present in the jwt') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $payload
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSubscriptionList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $knownListKeys = ['subscriptions', 'items', 'list'];

        foreach ($knownListKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $this->context->getLog()->warning(
            'SubscriptionService::fetchSubscriptions received unexpected payload keys: ' .
            implode(',', array_map('strval', array_keys($payload)))
        );

        return [];
    }

    private function logSubscriptionRequestException(RequestException $exception, string $prefix): void
    {
        $msg = $exception->hasResponse()
            ? (string) $exception->getResponse()->getBody()
            : $exception->getMessage();

        $this->context->getLog()->error(
            sprintf(
                '%s: %s. Code: %s. Whole object: %s',
                $prefix,
                $msg,
                (string) $exception->getCode(),
                json_encode($exception)
            )
        );
    }

    /**
     * Fetch subscription data for onboarding by business identifier.
     */
    public function fetchByBusinessId(?string $businessId = null): array|false
    {
        if ($businessId === null) {
            $businessId = $this->businessId;
        }

        if (!$businessId) {
            return false;
        }

        $tokenService = new TokenService($this->context);

        try {
            $appToken = $tokenService->getAppToken($businessId);
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'SubscriptionService::fetchByBusinessId: failed to load app token for business %s: %s',
                    $businessId,
                    $e->getMessage()
                )
            );
            return false;
        }

        if (!is_string($appToken) || $appToken === '') {
            $this->context->getLog()->warning(
                sprintf(
                    'SubscriptionService::fetchByBusinessId: no app token stored for business %s',
                    $businessId
                )
            );
            return false;
        }

        $subscriptions = $this->fetchSubscriptions($appToken, $businessId);

        return $subscriptions ?: false;
    }

    /**
     * Onboarding-compatible upsert wrapper.
     */
    public function upsert(array $subscriptionData): bool
    {
        try {
            $resolvedStoreId = $subscriptionData['storeId'] ?? $this->storeId;
            $this->upsertLocalSubscription($subscriptionData, $resolvedStoreId);
            return true;
        } catch (\Throwable $e) {
            $this->context->getLog()->error(
                sprintf(
                    'SubscriptionService::upsert: failed for subscription %s: %s',
                    $subscriptionData['subscriptionId'] ?? 'unknown',
                    $e->getMessage()
                )
            );

            return false;
        }
    }

// ────────────────────────────────────────────────────────────────────────────
    // 4) Delete a paid subscription on Poynt (DELETE), then remove local row
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Deletes (cancels) the subscription on Poynt, then deletes the local row.
     *
     * @param string $subscriptionId
     * @param string|null $merchantAccessToken  Explicit merchant token to use; if null we attempt to load stored token
     * @return array  Poynt’s delete response (decoded JSON)
     * @throws RuntimeException|GuzzleException on HTTP or SQL error
     */
    public function deleteSubscription(
        string $subscriptionId,
        ?string $merchantAccessToken = null
    ): array {
        if ($merchantAccessToken === null || $merchantAccessToken === '') {
            if ($this->businessId === null || $this->businessId === '') {
                throw new RuntimeException('Cannot delete subscription without a business ID or merchant access token.');
            }

            $merchantAccessToken = $this->getStoredMerchantToken($this->businessId);

            if (!is_string($merchantAccessToken) || $merchantAccessToken === '') {
                throw new RuntimeException(
                    sprintf('No merchant access token available for business %s; cannot delete subscription.', $this->businessId)
                );
            }
        }

        $endpoint = $this->buildAppResourceUrl('subscriptions/' . $subscriptionId);

        try {
            $response = $this->http->delete($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantAccessToken,
                    'Accept'        => 'application/json',
                ],
            ]);
            $respData = json_decode((string)$response->getBody(), true);
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error("Poynt DELETE subscription failed: " . $msg . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));
        }

        // Remove from local subscription table
        try {
            $this->context->getConn()->executeStatement(
                "UPDATE subscription SET status = :status, updated_at = :upat WHERE subscription_id = :sub_id",
                ['sub_id' => $subscriptionId, 'status' => 'canceled', 'upat' => date('Y-m-d H:i:s')]
            );
        } catch (\Exception $e) {
            $this->context->getLog()->error("Poynt DELETE subscription failed: " . $e->getMessage() . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));
        }

        return $respData ?? [];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 5) Unified upsert method for local subscription table
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Given a Poynt subscription object (decoded JSON), upserts into the local `subscription` table.
     *
     * Poynt’s JSON is expected to include:
     *   - subscriptionId       (string)
     *   - businessId           (string)
     *   - storeId              (string, when available in payload)
     *   - planId               (string)
     *   - status               (string)
     *   - phase                (string)
     *   - trialStart           (string|null, ISO8601)
     *   - trialEnd             (string|null, ISO8601)
     *   - startAt              (string, ISO8601)
     *   - currentPeriodEnd     (string|null, ISO8601)
     *   - cancelAtPeriodEnd    (bool)
     *   - canceledAt           (string|null, ISO8601)
     *
     * @param array $poyntSub
     * @param string|null $storeId Optional store identifier when missing from payload
     * @return void
     * @throws RuntimeException on SQL error
     */
    public function upsertLocalSubscription(array $poyntSub, ?string $storeId = null): void
    {
        $subscriptionId   = $poyntSub['subscriptionId'];
        $businessId       = $poyntSub['businessId'];
        $storeId          = $storeId ?? ($poyntSub['storeId'] ?? $this->storeId);
        $planId           = $poyntSub['planId'];
        $status           = $poyntSub['status'];
        $phase            = $poyntSub['phase'];
        $trialStart       = Format::optionalTimestamp($poyntSub['trialStart']       ?? null);
        $trialEnd         = Format::optionalTimestamp($poyntSub['trialEnd']         ?? null);
        $startAt          = Format::optionalTimestamp($poyntSub['startAt']          ?? null);
        $currentPeriodEnd = Format::optionalTimestamp($poyntSub['currentPeriodEnd'] ?? null);
        $cancelAtEnd      = Format::optionalBool($poyntSub['cancelAtPeriodEnd'] ?? null) ?? false;
        $canceledAt       = Format::optionalTimestamp($poyntSub['canceledAt']       ?? null);

        $sql = <<<SQL
        INSERT INTO subscription (
          subscription_id,
          business_id,
          store_id,
          plan_id,
          status,
          phase,
          trial_start_at,
          trial_end_at,
          start_at,
          current_period_end,
          cancel_at_period_end,
          canceled_at,
          created_at,
          updated_at
        ) VALUES (
          :sub_id, :biz, :store, :plan,
          :status, :phase,
          :tstart, :tend,
          :saat, :cpe,
          :cancelAtEnd, :canceledAt,
          NOW(), NOW()
        )
        ON CONFLICT (subscription_id) DO UPDATE SET
          business_id          = EXCLUDED.business_id,
          store_id             = EXCLUDED.store_id,
          plan_id              = EXCLUDED.plan_id,
          status               = EXCLUDED.status,
          phase                = EXCLUDED.phase,
          trial_start_at       = EXCLUDED.trial_start_at,
          trial_end_at         = EXCLUDED.trial_end_at,
          start_at             = EXCLUDED.start_at,
          current_period_end   = EXCLUDED.current_period_end,
          cancel_at_period_end = EXCLUDED.cancel_at_period_end,
          canceled_at          = EXCLUDED.canceled_at,
          updated_at           = NOW()
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'sub_id'      => $subscriptionId,
                'biz'         => $businessId,
                'store'       => $storeId,
                'plan'        => $planId,
                'status'      => $status,
                'phase'       => $phase,
                'tstart'      => $trialStart,
                'tend'        => $trialEnd,
                'saat'        => $startAt,
                'cpe'         => $currentPeriodEnd,
                'cancelAtEnd' => $cancelAtEnd,
                'canceledAt'  => $canceledAt,
            ]);
        } catch (\Exception $e) {
            $this->context->getLog()->error("Failed to upsert local subscription {$subscriptionId}: " . $e->getMessage() . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));
        }
    }

    /**
     * Marks an existing subscription row as active.
     *
     * @param string $subscriptionId
     * @param string $businessId
     * @param string $storeId
     * @return void
     */
    public function activateSubscription(string $subscriptionId, string $businessId, string $storeId): void
    {
        $sql = <<<SQL
        UPDATE subscription
           SET status = :status,
               start_at = COALESCE(start_at, NOW()),
               updated_at = NOW()
         WHERE subscription_id = :sub_id
           AND business_id = :biz
           AND store_id = :store
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'status' => 'active',
                'sub_id' => $subscriptionId,
                'biz'    => $businessId,
                'store'  => $storeId,
            ]);
        } catch (Exception $e) {
            $this->context->getLog()->error('Failed to activate subscription ' . $subscriptionId . ': ' . $e->getMessage());
        }
    }

    /**
     * Cancels an existing subscription row.
     *
     * @param string $subscriptionId
     * @param string $businessId
     * @param string $storeId
     * @return void
     */
    public function cancelSubscription(string $subscriptionId, string $businessId, string $storeId): void
    {
        $sql = <<<SQL
        UPDATE subscription
           SET status = :status,
               canceled_at = COALESCE(canceled_at, NOW()),
               updated_at = NOW()
         WHERE subscription_id = :sub_id
           AND business_id = :biz
           AND store_id = :store
        SQL;

        try {
            $this->context->getConn()->executeStatement($sql, [
                'status' => 'canceled',
                'sub_id' => $subscriptionId,
                'biz'    => $businessId,
                'store'  => $storeId,
            ]);
        } catch (Exception $e) {
            $this->context->getLog()->error('Failed to cancel subscription ' . $subscriptionId . ': ' . $e->getMessage());
        }
    }

    private function getAppUrn(): string
    {
        $appId = ConfigApp::$appId ?? '';

        return str_starts_with($appId, 'urn:aid:') ? $appId : 'urn:aid:' . $appId;
    }

    private function buildAppResourceUrl(string $resource = ''): string
    {
        $orgId = ConfigApp::$orgId ?? '';

        if ($orgId === '') {
            throw new RuntimeException('ConfigApp::$orgId must be configured to build billing URLs.');
        }

        $appUrn = $this->getAppUrn();
        $base = rtrim(self::POYNT_BILLING_BASE, '/');
        $resourcePath = ltrim($resource, '/');

        $url = sprintf('%s/%s/apps/%s', $base, $orgId, $appUrn);

        return $resourcePath === '' ? $url : $url . '/' . $resourcePath;
    }
}
