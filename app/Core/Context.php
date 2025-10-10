<?php

namespace App\Core;

use App\Http\GuzzleClientFactory;
use Doctrine\DBAL\Connection;
use GuzzleHttp\ClientInterface;
use Monolog\Logger;

class Context
{
    private Api $api;
    private Connection $conn;
    private Logger $log;
    private GuzzleClientFactory $httpClientFactory;

    public function __construct(Api $api, Connection $conn, Logger $log, ?GuzzleClientFactory $httpClientFactory = null)
    {
        $this->api = $api;
        $this->conn = $conn;
        $this->log = $log;
        $this->httpClientFactory = $httpClientFactory ?? new GuzzleClientFactory();
    }

    public function getApi(): Api
    {
        return $this->api;
    }

    public function getConn(): Connection
    {
        return $this->conn;
    }

    public function getLog(): Logger
    {
        return $this->log;
    }

    public function getHttpClient(): ClientInterface
    {
        return $this->httpClientFactory->getDefaultClient();
    }

    public function getHttpClientFactory(): GuzzleClientFactory
    {
        return $this->httpClientFactory;
    }
}
