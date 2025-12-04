<?php

namespace App\Services;

use App\Core\Context;
use App\Services\Support\TableNamer;
use DateInterval;
use DateTime;
use Throwable;

/**
 * TokenService manages persistence for both app-level tokens (app_token)
 * and merchant-level tokens (merchant_token) in a PostgreSQL database.
 *
 * All DB operations use $this->context->getConn() and wrap SQL calls in try/catch,
 * rethrowing as RuntimeException on failure.
 */
class TokenService {

    private Context $context;

    private TableNamer $tableNamer;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->tableNamer = new TableNamer($context->getConn());
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // App-level token methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Insert or update the app-level token row for a given tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param array  $token
     * @return void
     * @throws \DateMalformedIntervalStringException
     */
    public function saveAppToken(
        string   $businessId,
        array    $token
    ): void {
        $this->resetFailedTransactionIfNeeded();

        if (!($token['expiresIn'] instanceof DateTime)) {
            // $token['expiresIn'] is a number of seconds from now, not an absolute timestamp.
            $expiresAt = new DateTime();
            // Create a DateInterval of “PT{seconds}S” and add it:
            $expiresAt->add(new DateInterval('PT' . (int)$token['expiresIn'] . 'S'));
            $token['expiresIn'] = $expiresAt;
        }

        $appTokenTable = $this->tableNamer->for(null, 'app_token');
        $sql = <<<SQL
        INSERT INTO {$appTokenTable}
            (business_id, access_token, refresh_token, expires_at)
        VALUES (:businessId, :access, :refresh, :exp)
        ON CONFLICT (business_id) DO UPDATE SET
            access_token  = EXCLUDED.access_token,
            refresh_token = EXCLUDED.refresh_token,
            expires_at    = EXCLUDED.expires_at,
            updated_at    = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'businessId' => $businessId,
                'access'  => $token['accessToken'],
                'refresh' => $token['refreshToken'],
                'exp'     => $token['expiresIn']->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save app token for tenant={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Fetch the current app-level token (access, refresh, expires_at) for a given tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param bool $array
     * @return array|null  ['access_token'=>string, 'refresh_token'=>string, 'expires_at'=>string] or null if not found
     */
    public function getAppToken(string $businessId, bool $array = false): mixed
    {
        $this->resetFailedTransactionIfNeeded();

        $appTokenTable = $this->tableNamer->for(null, 'app_token');
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM {$appTokenTable}
         WHERE business_id = :businessId
         LIMIT 1
        SQL;

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [
                'businessId' => $businessId,
            ]);

            if ($array) {
                return $row === false ? null : $row;
            }

            return $row === false ? null : $row['access_token'];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to fetch app token for tenant={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Find all app-level tokens that expire within the next $minutes minutes for a tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param int    $minutes     Look-ahead window in minutes (defaults to 30).
     * @return array[]      Each element: ['access_token'=>..., 'refresh_token'=>..., 'expires_at'=>...]
     * @throws Exception on SQL error
     */
    public function findExpiringAppTokens(string $businessId, int $minutes = 30): array
    {
        $this->resetFailedTransactionIfNeeded();

        // Safely interpolate $minutes into the INTERVAL clause
        $intervalSql = "NOW() + INTERVAL '{$minutes} minutes'";

        $appTokenTable = $this->tableNamer->for(null, 'app_token');
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM {$appTokenTable}
         WHERE business_id = :businessId AND expires_at <= {$intervalSql}
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            return $stmt->executeQuery(['businessId' => $businessId])->fetchAllAssociative();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to query expiring app tokens for tenant={$businessId} (next {$minutes} minutes): " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Merchant-level token methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Insert or update the merchant-level token row for a given tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param array  $token
     * @return void
     * @throws \DateMalformedIntervalStringException
     */
    public function saveMerchantToken(
        string $businessId,
        array $token
    ): void {
        $this->resetFailedTransactionIfNeeded();

        if (!($token['expiresIn'] instanceof DateTime)) {
            $expiresAt = new DateTime();
            $expiresAt->add(new DateInterval('PT' . (int)$token['expiresIn'] . 'S'));
            $token['expiresIn'] = $expiresAt;
        }

        $merchantTokenTable = $this->tableNamer->for(null, 'merchant_token');
        $sql = <<<SQL
        INSERT INTO {$merchantTokenTable}
            (business_id, access_token, refresh_token, expires_at)
        VALUES (:businessId, :access, :refresh, :exp)
        ON CONFLICT (business_id) DO UPDATE SET
            access_token  = EXCLUDED.access_token,
            refresh_token = EXCLUDED.refresh_token,
            expires_at    = EXCLUDED.expires_at,
            updated_at    = NOW()
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'businessId' => $businessId,
                'access'  => $token['accessToken'],
                'refresh' => $token['refreshToken'],
                'exp'     => $token['expiresIn']->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save merchant token for tenant={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Fetch the current merchant-level token for a given tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param bool $array
     * @return array|null   ['access_token'=>string, 'refresh_token'=>string, 'expires_at'=>string] or null if not found
     */
    public function getMerchantToken(string $businessId, bool $array = false): mixed
    {
        $this->resetFailedTransactionIfNeeded();

        $merchantTokenTable = $this->tableNamer->for(null, 'merchant_token');
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM {$merchantTokenTable}
         WHERE business_id = :businessId
         LIMIT 1
        SQL;

        try {
            $row = $this->context->getConn()->fetchAssociative($sql, [
                'businessId' => $businessId,
            ]);

            if ($array) {
                return $row === false ? null : $row;
            }

            return $row === false ? null : $row['access_token'];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to fetch merchant token for tenant={$businessId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Find all merchant-level tokens that are expiring within the next $minutes minutes for a tenant.
     *
     * @param string $businessId Tenant identifier used to resolve the table name
     * @param int    $minutes  Look-ahead window in minutes (defaults to 30).
     * @return array[]      Each element: ['access_token'=>..., 'refresh_token'=>..., 'expires_at'=>...]
     * @throws Exception on SQL error
     */
    public function findExpiringMerchantTokens(string $businessId, int $minutes = 30): array
    {
        $this->resetFailedTransactionIfNeeded();

        $intervalSql = "NOW() + INTERVAL '{$minutes} minutes'";

        $merchantTokenTable = $this->tableNamer->for(null, 'merchant_token');
        $sql = <<<SQL
        SELECT access_token, refresh_token, expires_at
          FROM {$merchantTokenTable}
         WHERE business_id = :businessId AND expires_at <= {$intervalSql}
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            return $stmt->executeQuery(['businessId' => $businessId])->fetchAllAssociative();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to query expiring merchant tokens for tenant={$businessId} (next {$minutes} minutes): " . $e->getMessage(),
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
     * @param string      $businessId Tenant identifier used to resolve the table name
     * @param string      $type     'app' or 'merchant'
     * @param bool        $success
     * @param string|null $message
     * @return void
     */
    public function logRefreshAttempt(string $businessId, string $type, bool $success, ?string $message = null): void
    {
        $this->resetFailedTransactionIfNeeded();

        $refreshLogTable = $this->tableNamer->for($businessId, 'token_refresh_log');
        $sql = <<<SQL
        INSERT INTO {$refreshLogTable} (token_type, attempted_at, success, message)
        VALUES (:type, NOW(), :success, :message)
        SQL;

        try {
            $stmt = $this->context->getConn()->prepare($sql);
            $stmt->executeStatement([
                'type'    => $type,
                'success' => $success,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            $this->context->getLog()->error(
                "Failed to log token refresh attempt for tenant={$businessId}: " . $e->getMessage()
            );
        }
    }

    /**
     * Roll back the active transaction if it has been left in a failed state.
     */
    private function resetFailedTransactionIfNeeded(): void
    {
        $conn = $this->context->getConn();

        if (!method_exists($conn, 'isTransactionActive')) {
            return;
        }

        try {
            if (!$conn->isTransactionActive()) {
                return;
            }

            if (!method_exists($conn, 'isRollbackOnly')) {
                return;
            }

            if (!$conn->isRollbackOnly()) {
                return;
            }

            $conn->rollBack();
            $this->context->getLog()->warning(
                'TokenService::resetFailedTransactionIfNeeded: rolled back aborted transaction before continuing.'
            );
        } catch (Throwable $e) {
            $this->context->getLog()->warning(
                'TokenService::resetFailedTransactionIfNeeded: unable to verify/rollback transaction: ' .
                $e->getMessage()
            );
        }
    }
}
