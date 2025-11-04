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

        $logger->info($message, self::normaliseContext($context));
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
            if (!property_exists(ConfigApp::class, $property)) {
                continue;
            }

            if ((bool) ConfigApp::${$property}) {
                return true;
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

    /**
     * Ensure all context payload is stored under the "details" key so it reaches the database layer.
     */
    private static function normaliseContext(array $context): array
    {
        $details = [];

        if (array_key_exists('details', $context)) {
            $providedDetails = $context['details'];

            if (is_array($providedDetails)) {
                $details = $providedDetails;
            } elseif ($providedDetails !== null) {
                $details = ['value' => $providedDetails];
            }

            unset($context['details']);
        }

        $allowedTopLevelKeys = array_flip([
            'type',
            'merchant',
            'url',
            'request_id',
        ]);

        $extraContext = array_diff_key($context, $allowedTopLevelKeys);
        $context = array_intersect_key($context, $allowedTopLevelKeys);

        if (!empty($extraContext)) {
            $details = array_merge($extraContext, $details);
        }

        $context['details'] = $details;

        if (!isset($context['type'])) {
            $context['type'] = 'fetch_response';
        }

        return $context;
    }
}
