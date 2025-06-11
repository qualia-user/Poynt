<?php

namespace App\Services;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class CustomPDOHandler extends AbstractProcessingHandler {
    private \PDO $pdo;

    public function __construct(\PDO $pdo, $level = Logger::DEBUG, bool $bubble = true) {
        parent::__construct($level, $bubble);
        $this->pdo = $pdo;
    }

    protected function write(array $record): void {
        $sql = "INSERT INTO log (request_id, type, level, channel, message, merchant, url, details)
                VALUES (:request_id, :type, :level, :channel, :message, :merchant, :url, :details)";
        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
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
