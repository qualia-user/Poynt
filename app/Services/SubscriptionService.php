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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SubscriptionService
{
    public const POYNT_BILLING_BASE = 'https://billing.poynt.net/organizations';
    private const DEFAULT_TRIAL_DAYS = 14;
    private Context $context;
    private ClientInterface $http;
    private mixed $storeId;

    public function __construct(Context $context, $storeId = null, ?ClientInterface $httpClient = null)
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

        if (!is_null($storeId)) {
            $this->storeId = $storeId;
        }
    }

    /**
     * Fetches the list of subscription plans defined for your Poynt app.
     *
     * @param string $appAccessToken  App‐level OAuth token (JWT‐exchange)
     * @return array|null  List of plans (decoded JSON). Example keys: ['planId'=>'...', 'name'=>'...', 'price'=>..., …]
     * @throws RuntimeException on HTTP or decoding error
     */
    public function fetchPlans(string $appAccessToken): ?array
    {
        try {
            $response = $this->http->get(self::POYNT_BILLING_BASE . '/' . ConfigApp::$orgId . '/apps/urn:aid:' . ConfigApp::$appId . '/plans', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $appAccessToken,
                    'Accept'        => 'application/json',
                ]
            ]);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $e) {
            $this->context->getLog()->error("Error parsing plans response: %s. Body: %s" . $e->getMessage());

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
    public function fetchApplicationSubscriptionPlans(string $appAccessToken): ?array
    {
        return $this->fetchPlans($appAccessToken);
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
        $orgId = ConfigApp::$orgId;
        $appId = 'urn:aid:' . ConfigApp::$appId;
        $endpoint = "/{$orgId}/apps/{$appId}/subscriptions";

        $query = ['businessId' => $businessId];
        if ($storeId) {
            $query['storeId'] = $storeId;
        }
        if ($deviceId) {
            $query['deviceId'] = $deviceId;
        }
        if ($status) {
            $query['status'] = $status;
        }

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
                'status' => 'trialing',
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
     * @param string $appAccessToken App-level OAuth token
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
        string      $accessToken,
        string      $businessId,
        string      $storeId,
        string      $planId,
        bool        $replaceV2 = false,
        ?string     $startAt = null
    ): array {
        $orgId = ConfigApp::$orgId;
        $appId = 'urn:aid:' . ConfigApp::$appId;
        $endpoint = "/{$orgId}/apps/{$appId}/subscriptions";

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
                    'Authorization' => 'Bearer ' . $appAccessToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);
            $respData = json_decode((string)$response->getBody(), true);
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error("Poynt CREATE subscription failed: " . $msg . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));
        }

        // Overwrite local row (which likely already exists as a “trial”) with Poynt’s data
        $this->upsertLocalSubscription($respData);

        return $respData;
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 3) Fetch subscriptions from Poynt (GET), then upsert locally
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Fetches all subscriptions for a given businessId from Poynt,
     * upserts each into the local `subscription` table, and returns the list.
     *
     * @param string $appAccessToken  App-level OAuth token
     * @param string $businessId      Poynt business ID
     * @return array  List of subscription objects (decoded JSON)
     * @throws RuntimeException on HTTP/decoding error
     */
    public function fetchSubscriptions(
        string $appAccessToken,
        string $businessId
    ): array {
        $orgId = ConfigApp::$orgId;
        $appId = 'urn:aid:' . ConfigApp::$appId;
        $endpoint = "/{$orgId}/apps/{$appId}/subscriptions";

        try {
            $response = $this->http->get($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $appAccessToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'businessId' => $businessId,
                ],
            ]);
            $respList = json_decode((string)$response->getBody(), true);
        } catch (RequestException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->context->getLog()->error("Poynt GET subscription failed: " . $msg . ". Code: " . $e->getCode() . ". Whole object: ". json_encode($e));
        } catch (GuzzleException $e) {
            $this->context->getLog()->error("Poynt GET subscription failed: ". json_encode($e));
        }

        // Upsert each subscription into local DB
        foreach ($respList as $sub) {
            $this->upsertLocalSubscription($sub);
        }

        return $respList;
    }

// ────────────────────────────────────────────────────────────────────────────
    // 4) Delete a paid subscription on Poynt (DELETE), then remove local row
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Deletes (cancels) the subscription on Poynt, then deletes the local row.
     *
     * @param string $appAccessToken
     * @param string $subscriptionId
     * @return array  Poynt’s delete response (decoded JSON)
     * @throws RuntimeException|GuzzleException on HTTP or SQL error
     */
    public function deleteSubscription(
        string $appAccessToken,
        string $subscriptionId
    ): array {
        $orgId = ConfigApp::$orgId;
        $appId = 'urn:aid:' . ConfigApp::$appId;
        $endpoint = "/{$orgId}/apps/{$appId}/subscriptions/{$subscriptionId}";

        try {
            $response = $this->http->delete($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $appAccessToken,
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

        return $respData;
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
     *   - storeId              (string)
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
     * @return void
     * @throws RuntimeException on SQL error
     */
    public function upsertLocalSubscription(array $poyntSub): void
    {
        $subscriptionId   = $poyntSub['subscriptionId'];
        $businessId       = $poyntSub['businessId'];
        $storeId          = $poyntSub['storeId'];
        $planId           = $poyntSub['planId'];
        $status           = $poyntSub['status'];
        $phase            = $poyntSub['phase'];
        $trialStart       = Format::optionalTimestamp($poyntSub['trialStart']       ?? null);
        $trialEnd         = Format::optionalTimestamp($poyntSub['trialEnd']         ?? null);
        $startAt          = Format::optionalTimestamp($poyntSub['startAt']          ?? null);
        $currentPeriodEnd = Format::optionalTimestamp($poyntSub['currentPeriodEnd'] ?? null);
        $cancelAtEnd      = $poyntSub['cancelAtPeriodEnd'] ?? false;
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

}
