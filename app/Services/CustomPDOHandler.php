<?php

namespace App\Services;

use App\Services\Support\PoyntDataFormatter as Format;
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

    public function __construct(Connection $primaryConn, ?Connection $logConn = null, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->primaryConn = $primaryConn;
        $this->logConn = $logConn;

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

        $sql = "INSERT INTO log (request_id, type, level, channel, message, merchant, url, details)"
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
}
