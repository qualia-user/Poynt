<?php

namespace App\Services;

use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class CustomPDOHandler extends AbstractProcessingHandler {
    private Connection $conn;

    public function __construct(Connection $conn, $level = Logger::DEBUG, bool $bubble = true) {
        parent::__construct($level, $bubble);
        $this->conn = $conn;
    }

    protected function write(array $record): void {
        $sql = "INSERT INTO log (request_id, type, level, channel, message, merchant, url, details)
                VALUES (:request_id, :type, :level, :channel, :message, :merchant, :url, :details)";

        $this->conn->executeStatement($sql, [
            'request_id' => $record['context']['request_id'] ?? null,
            'type' => $record['context']['type'] ?? null, // Log type corresponds to the Monolog channel
            'level' => $record['level'],
            'channel' => $record['channel'],
            'message' => $record['message'],
            'merchant' => $record['context']['merchant'] ?? null,
            'url' => $record['context']['url'] ?? null,
            'details' => json_encode($record['context']['details'] ?? []),
        ]);
    }
}
