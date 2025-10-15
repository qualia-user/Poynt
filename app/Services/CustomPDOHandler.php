<?php

namespace App\Services;

use App\Services\Support\PoyntDataFormatter as Format;
use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

class CustomPDOHandler extends AbstractProcessingHandler
{
    private Connection $conn;

    public function __construct(Connection $conn, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->conn = $conn;
    }

    protected function write(array $record): void
    {
        $sql = "INSERT INTO log (request_id, type, level, channel, message, merchant, url, details)"
            . " VALUES (:request_id, :type, :level, :channel, :message, :merchant, :url, :details)";

        $requestId = $record['context']['request_id'] ?? Uuid::uuid4()->toString();

        $this->conn->executeStatement($sql, [
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
