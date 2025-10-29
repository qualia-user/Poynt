<?php

namespace App\Services\Support;

use App\Config\ConfigApp;
use Psr\Log\LoggerInterface;

final class FetchResponseLogger
{
    private function __construct()
    {
    }

    public static function info(LoggerInterface $logger, string $message, array $context = []): void
    {
        if (!self::shouldLogResponses()) {
            return;
        }

        $logger->info($message, $context);
    }

    private static function shouldLogResponses(): bool
    {
        if (!class_exists(ConfigApp::class)) {
            return false;
        }

        foreach ([
            'logFetchByBusinessIdResponses',
            'logFetchResponses',
            'enableFetchLogging',
            'isDevelopment',
            'development',
        ] as $property) {
            if (property_exists(ConfigApp::class, $property)) {
                return (bool) ConfigApp::${$property};
            }
        }

        if (property_exists(ConfigApp::class, 'environment')) {
            $environment = ConfigApp::$environment;
            if (is_string($environment) && strtolower($environment) === 'development') {
                return true;
            }
        }

        $env = self::getEnv('APP_ENV');
        if (is_string($env) && strtolower($env) === 'development') {
            return true;
        }

        $flag = self::getEnv('POYNT_LOG_FETCH_RESPONSES');
        if (is_string($flag)) {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return false;
    }

    private static function getEnv(string $key): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: null;
    }
}
