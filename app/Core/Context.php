<?php

namespace App\Core;

class Context
{
    public $api;
    public $conn;
    public $log;

    public function __construct($api, $conn, $log)
    {
        $this->api = $api;
        $this->conn = $conn;
        $this->log = $log;
    }
}