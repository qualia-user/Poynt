<?php

declare(strict_types=1);

namespace App\Config {
    if (!class_exists(__NAMESPACE__ . '\\ConfigApp')) {
        class ConfigApp
        {
            public static bool $logFetchByBusinessIdResponses = false;
            public static bool $logFetchResponses = true;
        }
    }
}

namespace Tests\Services\Support {

    use App\Services\Support\FetchResponseLogger;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;
    use Psr\Log\LogLevel;

    class FetchResponseLoggerTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            \App\Config\ConfigApp::$logFetchByBusinessIdResponses = false;
            \App\Config\ConfigApp::$logFetchResponses = true;
        }

        public function testLoggingEnabledWhenAnyConfigFlagIsTrue(): void
        {
            \App\Config\ConfigApp::$logFetchByBusinessIdResponses = true;
            \App\Config\ConfigApp::$logFetchResponses = false;

            $logger = new RecordingLogger();

            FetchResponseLogger::info($logger, 'enabled message');

            self::assertCount(1, $logger->records);
            $record = $logger->records[0];
            self::assertSame('enabled message', $record['message']);
        }

        public function testContextIsMovedIntoDetailsAndTypeDefaults(): void
        {
            $logger = new RecordingLogger();

            FetchResponseLogger::info($logger, 'test message', [
                'businessId' => 'biz-123',
                'payload' => ['foo' => 'bar'],
            ]);

            self::assertCount(1, $logger->records);
            $record = $logger->records[0];

            self::assertSame(LogLevel::INFO, $record['level']);
            self::assertSame('test message', $record['message']);
            self::assertSame('fetch_response', $record['context']['type']);
            self::assertSame([
                'businessId' => 'biz-123',
                'payload' => ['foo' => 'bar'],
            ], $record['context']['details']);
        }

        public function testProvidedTypeAndDetailsAreRespected(): void
        {
            $logger = new RecordingLogger();

            FetchResponseLogger::info($logger, 'custom message', [
                'type' => 'custom_type',
                'merchant' => 'merchant-42',
                'details' => ['payload' => ['baz' => 'qux']],
                'extra' => 'value',
            ]);

            self::assertCount(1, $logger->records);
            $record = $logger->records[0];

            self::assertSame('custom_type', $record['context']['type']);
            self::assertSame('merchant-42', $record['context']['merchant']);
            self::assertArrayNotHasKey('extra', $record['context']);
            self::assertSame([
                'extra' => 'value',
                'payload' => ['baz' => 'qux'],
            ], $record['context']['details']);
        }
    }

    /**
     * Minimal PSR-3 logger that records log invocations for assertions.
     */
    class RecordingLogger implements LoggerInterface
    {
        /** @var array<int, array{level:string,message:string,context:array}> */
        public array $records = [];

        public function emergency($message, array $context = []): void
        {
            $this->log(LogLevel::EMERGENCY, $message, $context);
        }

        public function alert($message, array $context = []): void
        {
            $this->log(LogLevel::ALERT, $message, $context);
        }

        public function critical($message, array $context = []): void
        {
            $this->log(LogLevel::CRITICAL, $message, $context);
        }

        public function error($message, array $context = []): void
        {
            $this->log(LogLevel::ERROR, $message, $context);
        }

        public function warning($message, array $context = []): void
        {
            $this->log(LogLevel::WARNING, $message, $context);
        }

        public function notice($message, array $context = []): void
        {
            $this->log(LogLevel::NOTICE, $message, $context);
        }

        public function info($message, array $context = []): void
        {
            $this->log(LogLevel::INFO, $message, $context);
        }

        public function debug($message, array $context = []): void
        {
            $this->log(LogLevel::DEBUG, $message, $context);
        }

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];
        }
    }
}
