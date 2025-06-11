<?php
namespace App\Core;

class Response
{
    const STATUS_OK = 200;

    const STATUS_BAD_REQUEST = 400;
    const StatusUnauthorized = 401;
    const StatusForbidden = 403;
    const StatusNotFound = 404;
    const STATUS_METHOD_NOT_ALLOWED = 405;
    const StatusConflict = 409;
    const StatusRequestEntityTooLarge = 413;
    const StatusSubscriptionPlanLimit = 441;
    const StatusEmailAlreadyExists = 442;
    const StatusFreeTrialFinished = 443;
    const StatusModelCreationLimit = 445;
    const StatusApiRateLimitExceeded = 429;
    const STATUS_INTERNAL_SERVER_ERROR = 500;
    const StatusNotImplemented = 501;

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
