<?php

namespace App\Services\Support;

use App\Services\CustomPDOHandler;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

class LoggerFactory
{
    /**
     * Create a Monolog logger that writes through CustomPDOHandler using a dedicated logging connection.
     *
     * @return array{0: Logger, 1: string} Tuple containing the configured logger and the generated request identifier.
     */
    public static function create(Connection $primaryConn, ?Connection $logConn = null, string $channel = 'app-poynt-log'): array
    {
        $handler = new CustomPDOHandler($primaryConn, $logConn);

        $logger = new Logger($channel);
        $logger->pushHandler($handler);

        $requestId = Uuid::uuid4()->toString();
        $logger->pushProcessor(static function (array $record) use ($requestId): array {
            $record['context']['request_id'] = $requestId;

            return $record;
        });

        return [$logger, $requestId];
    }
}
