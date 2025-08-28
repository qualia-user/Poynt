<?php

namespace App\Services;

use App\Core\Context;
use DateInterval;
use DateTime;

/**
 * TokenService manages persistence for both app-level tokens (app_token)
 * and merchant-level tokens (merchant_token) in a PostgreSQL database.
 *
 * All DB operations use $this->context->getConn() and wrap SQL calls in try/catch,
 * rethrowing as RuntimeException on failure.
 */
class TokenService {

    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // App-level token methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Insert or update the app-level token row for a given business_id.
     *
     * @param string $businessId
     * @param array $token
     * @return void
     * @throws \DateMalformedIntervalStringException
     */
    public function saveAppToken(
        string   $businessId,
        array    $token
    ): void {
        if (!($token['expiresIn'] instanceof DateTime)) {
            // $token['expiresIn'] is a number of seconds from now, not an absolute timestamp.
            $expiresAt = new DateTime();
            // Create a DateInterval of “PT{seconds}S” and add it:
            $expiresAt->add(new DateInterval('PT' . (int)$token['expiresIn'] . 'S'));
            $token['expiresIn'] = $expiresAt;
        }

        $sql = <<<SQL
        INSERT INTO app_token
            (business_id, access_token, refresh_token, expires_at)
        VALUES (:biz, :access, :refresh, :exp)
        ON CONFLICT (business_id) DO UPDATE SET
            access_token  = EXCLUDED.access_token,
            refresh_token = EXCLUDED.refresh_token,
            expires_at    = EXCLUDED.expires_at,
            updated_at    = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'biz'     => $businessId,
                'access'  => $token['accessToken'],
                'refresh' => $token['refreshToken'],
                'exp'     => $token['expiresIn']->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save app token for business_id={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Fetch the current app-level token (access, refresh, expires_at) for a given business_id.
     *
     * @param string $businessId
     * @param bool $array
     * @return array|null  ['access_token'=>string, 'refresh_token'=>string, 'expires_at'=>string] or null if not found
     */
    public function getAppToken(string $businessId, bool $array = false): mixed
    {
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM app_token
         WHERE business_id = :biz
         LIMIT 1
        SQL;

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [
                'biz' => $businessId
            ]);

            if ($array) {
                return $row === false ? null : $row;
            }

            return $row === false ? null : $row['access_token'];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to fetch app token for business_id={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Find all app-level tokens that expire within the next $minutes minutes.
     *
     * @param int $minutes  Look-ahead window in minutes (defaults to 30).
     * @return array[]      Each element: ['business_id'=>..., 'access_token'=>..., 'refresh_token'=>..., 'expires_at'=>...]
     * @throws Exception on SQL error
     */
    public function findExpiringAppTokens(int $minutes = 30): array
    {
        // Safely interpolate $minutes into the INTERVAL clause
        $intervalSql = "NOW() + INTERVAL '{$minutes} minutes'";

        $sql = <<<SQL
        SELECT business_id, access_token, refresh_token, expires_at
          FROM app_token
         WHERE expires_at <= {$intervalSql}
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            return $stmt->executeQuery()->fetchAllAssociative();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to query expiring app tokens (next {$minutes} minutes): " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Merchant-level token methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Insert or update the merchant-level token row for a given business_id.
     *
     * @param string $businessId
     * @param array $token
     * @return void
     * @throws \DateMalformedIntervalStringException
     */
    public function saveMerchantToken(
        string $businessId,
        array $token
    ): void {
        if (!($token['expiresIn'] instanceof DateTime)) {
            $expiresAt = new DateTime();
            $expiresAt->add(new DateInterval('PT' . (int)$token['expiresIn'] . 'S'));
            $token['expiresIn'] = $expiresAt;
        }

        $sql = <<<SQL
        INSERT INTO merchant_token
            (business_id, access_token, refresh_token, expires_at)
        VALUES (:biz, :access, :refresh, :exp)
        ON CONFLICT (business_id) DO UPDATE SET
            access_token  = EXCLUDED.access_token,
            refresh_token = EXCLUDED.refresh_token,
            expires_at    = EXCLUDED.expires_at,
            updated_at    = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'biz'     => $businessId,
                'access'  => $token['accessToken'],
                'refresh' => $token['refreshToken'],
                'exp'     => $token['expiresIn']->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save merchant token for business_id={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Fetch the current merchant-level token for a given business_id.
     *
     * @param string $businessId
     * @param bool $array
     * @return array|null   ['access_token'=>string, 'refresh_token'=>string, 'expires_at'=>string] or null if not found
     */
    public function getMerchantToken(string $businessId, bool $array = false): mixed
    {
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM merchant_token
         WHERE business_id = :biz
         LIMIT 1
        SQL;

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [
                'biz' => $businessId
            ]);

            if ($array) {
                return $row === false ? null : $row;
            }

            return $row === false ? null : $row['access_token'];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to fetch merchant token for business_id={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Find all merchant-level tokens that are expiring within the next $minutes minutes.
     *
     * @param int $minutes  Look-ahead window in minutes (defaults to 30).
     * @return array[]      Each element: ['business_id'=>..., 'access_token'=>..., 'refresh_token'=>..., 'expires_at'=>...]
     * @throws Exception on SQL error
     */
    public function findExpiringMerchantTokens(int $minutes = 30): array
    {
        $intervalSql = "NOW() + INTERVAL '{$minutes} minutes'";

        $sql = <<<SQL
        SELECT business_id, access_token, refresh_token, expires_at
          FROM merchant_token
         WHERE expires_at <= {$intervalSql}
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            return $stmt->executeQuery()->fetchAllAssociative();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to query expiring merchant tokens (next {$minutes} minutes): " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Helper: translate expires_in (seconds) → DateTime
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Given a raw “expires_in” value (e.g. 3600 seconds),
     * produce a DateTime for “now + expires_in seconds” in UTC.
     *
     * @param int $expiresInSeconds
     * @return DateTime
     */
    public function makeExpiresAt(int $expiresInSeconds): DateTime
    {
        try {
            $now = new DateTime('now', new \DateTimeZone('UTC'));
            return $now->add(new DateInterval('PT' . $expiresInSeconds . 'S'));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to construct expiration DateTime: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Persist an attempt to refresh a token.
     *
     * @param string      $businessId
     * @param string      $type     'app' or 'merchant'
     * @param bool        $success
     * @param string|null $message
     * @return void
     */
    public function logRefreshAttempt(string $businessId, string $type, bool $success, ?string $message = null): void
    {
        $sql = <<<SQL
        INSERT INTO token_refresh_log (business_id, token_type, attempted_at, success, message)
        VALUES (:biz, :type, NOW(), :success, :message)
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'biz'     => $businessId,
                'type'    => $type,
                'success' => $success,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            $this->context->getLog()->error(
                "Failed to log token refresh attempt for business_id={$businessId}: " . $e->getMessage()
            );
        }
    }
}