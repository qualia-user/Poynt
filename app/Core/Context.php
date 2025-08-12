<?php

namespace App\Core;

use Doctrine\DBAL\Connection;
use Monolog\Logger;

class Context
{
    private Api $api;
    private Connection $conn;
    private Logger $log;

    public function __construct(Api $api, Connection $conn, Logger $log)
    {
        $this->api = $api;
        $this->conn = $conn;
        $this->log = $log;
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
}