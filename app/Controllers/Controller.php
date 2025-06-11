<?php

namespace App\Controllers;

use App\Core\Api;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

abstract class Controller
{
    /**
     * @var Api
     */
    protected Api $api;

    /**
     * @var Connection
     */
    protected Connection $conn;

    /**
     * @var Logger
     */
    protected Logger $log;

    /**
     * @param Api $api
     * @param Connection $conn
     * @param Logger $log
     */
    public function __construct(Api $api, Connection $conn, Logger $log)
    {
        $this->api = $api;
        $this->conn = $conn;
        $this->log = $log;
    }

    public function startSession() {
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
}