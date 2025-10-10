<?php

namespace App\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class GuzzleClientFactory
{
    /**
     * @var array<string, mixed>
     */
    private array $defaultConfig;

    private ?ClientInterface $defaultClient = null;

    /**
     * @param array<string, mixed> $defaultConfig
     */
    public function __construct(array $defaultConfig = [])
    {
        $this->defaultConfig = array_merge([
            'base_uri' => 'https://services.poynt.net',
            'timeout' => 10.0,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ], $defaultConfig);
    }

    public function getDefaultClient(): ClientInterface
    {
        if ($this->defaultClient === null) {
            $this->defaultClient = $this->create();
        }

        return $this->defaultClient;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config = []): ClientInterface
    {
        $mergedConfig = $this->mergeConfig($config);

        return new Client($mergedConfig);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function mergeConfig(array $config): array
    {
        $merged = array_merge($this->defaultConfig, $config);

        if (isset($this->defaultConfig['headers']) || isset($config['headers'])) {
            $merged['headers'] = array_merge(
                $this->defaultConfig['headers'] ?? [],
                $config['headers'] ?? []
            );
        }

        return $merged;
    }
}

