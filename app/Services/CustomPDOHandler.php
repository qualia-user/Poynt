<?php

namespace App\Services;

use App\Services\Support\PoyntDataFormatter as Format;
use App\Services\Support\TableNamer;
use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Monolog handler that writes log records into the database.
 *
 * Prefer using LoggerFactory::create() to obtain a logger configured with this handler
 * and a generated request identifier, unless you need low-level customisation.
 */
class CustomPDOHandler extends AbstractProcessingHandler
{
    private Connection $primaryConn;
    private ?Connection $logConn;
    private TableNamer $tableNamer;

    /**
     * @var array<string, bool>
     */
    private array $ensuredTables = [];

    public function __construct(Connection $primaryConn, ?Connection $logConn = null, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->primaryConn = $primaryConn;
        $this->logConn = $logConn;
        $this->tableNamer = new TableNamer($logConn ?? $primaryConn);

        if ($this->logConn !== null && method_exists($this->logConn, 'setAutoCommit')) {
            $this->logConn->setAutoCommit(true);
        }
    }

    protected function write(array $record): void
    {
        $connection = $this->logConn ?? $this->primaryConn;

        if (!$connection->isConnected()) {
            $connection->connect();
        }

        $businessId = $this->resolveBusinessId($record);

        $tableName = $this->tableNamer->for($businessId, 'log');

        $this->ensureLogTableExists($connection, $tableName);

        $sql = "INSERT INTO {$tableName} (request_id, type, level, channel, message, merchant, url, details)"
            . " VALUES (:request_id, :type, :level, :channel, :message, :merchant, :url, :details)";

        $requestId = $record['context']['request_id'] ?? Uuid::uuid4()->toString();

        $connection->executeStatement($sql, [
            'request_id' => $requestId,
            'type' => $record['context']['type'] ?? null,
            'level' => $record['level'],
            'channel' => $record['channel'],
            'message' => $record['message'],
            'merchant' => $record['context']['merchant'] ?? null,
            'url' => $record['context']['url'] ?? null,
            'details' => Format::jsonObject($record['context']['details'] ?? []),
        ]);
    }

    private function resolveBusinessId(array $record): ?string
    {
        $context = $record['context'] ?? [];
        $extra = $record['extra'] ?? [];

        if ($this->isSharedScope($context, $extra)) {
            return null;
        }

        foreach (['businessId', 'business_id', 'merchant'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
                return $context[$key];
            }

            if (isset($extra[$key]) && is_string($extra[$key]) && $extra[$key] !== '') {
                return $extra[$key];
            }
        }

        return null;
    }

    private function isSharedScope(array $context, array $extra): bool
    {
        $logScope = $context['log_scope'] ?? $context['logScope'] ?? $extra['log_scope'] ?? $extra['logScope'] ?? null;

        if ($logScope === null) {
            return false;
        }

        if (is_bool($logScope)) {
            return $logScope === true;
        }

        if (is_string($logScope) && strtolower(trim($logScope)) === 'shared') {
            return true;
        }

        return false;
    }

    private function ensureLogTableExists(Connection $connection, string $tableName): void
    {
        if (isset($this->ensuredTables[$tableName])) {
            return;
        }

        $sanitizedBaseName = preg_replace('/[^a-z0-9_]+/i', '_', trim($tableName, '"'));

        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$tableName} (
                id SERIAL PRIMARY KEY,
                request_id UUID NOT NULL,
                type TEXT,
                timestamp TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                level SMALLINT,
                channel VARCHAR(64),
                message TEXT,
                merchant VARCHAR(64),
                url TEXT,
                details JSONB NOT NULL DEFAULT '{}'::JSONB
            )"
        );

        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_request_id ON {$tableName} (request_id)");
        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_type ON {$tableName} (type)");
        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_timestamp ON {$tableName} (timestamp)");
        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_channel ON {$tableName} (channel)");
        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_level ON {$tableName} (level)");
        $connection->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$sanitizedBaseName}_details_jsonb ON {$tableName} USING GIN (details)");

        $this->ensuredTables[$tableName] = true;
    }
}
