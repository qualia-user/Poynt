<?php
namespace App\Core;

class Response
{
    const STATUS_OK = 200;

    const STATUS_BAD_REQUEST = 400;
    const STATUS_UNAUTHORIZED = 401;
    const STATUS_FORBIDDEN = 403;
    const STATUS_NOT_FOUND = 404;
    const STATUS_METHOD_NOT_ALLOWED = 405;
    const STATUS_CONFLICT = 409;
    const STATUS_REQUEST_ENTITY_TOO_LARGE = 413;
    const STATUS_SUBSCRIPTION_PLAN_LIMIT = 441;
    const STATUS_EMAIL_ALREADY_EXISTS = 442;
    const STATUS_FREE_TRIAL_FINISHED = 443;
    const STATUS_MODEL_CREATION_LIMIT = 445;
    const STATUS_API_RATE_LIMIT_EXCEEDED = 429;
    const STATUS_INTERNAL_SERVER_ERROR = 500;
    const STATUS_NOT_IMPLEMENTED = 501;

    public static $imageTypes = [
        'image/jpeg',
        'image/png',
        'image/svg+xml'
    ];

    /**
     * @var array|object
     */
    public $data = null;

    /**
     * @var string
     */
    public static $contentType = null;

    /**
     * Response constructor.
     * @param array|object $data
     */
    public function __construct($data = null) {
        $this->data = $data;
    }
}
