<?php

namespace App\Controllers;

use App\Core\Api;
use App\Core\Context;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

abstract class Controller
{
    /**
     * @var Context
     */
    protected Context $context;

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
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->api = $context->getApi();
        $this->conn = $context->getConn();
        $this->log = $context->getLog();
    }

    public function startSession() {
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
}