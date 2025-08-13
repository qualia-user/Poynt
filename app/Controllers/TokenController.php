<?php

namespace App\Controllers;

use App\Core\Context;
use App\Services\BackgroundJobService;

class TokenController extends Controller
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function refreshTokens(): array
    {
        $jobService = new BackgroundJobService($this->context);
        $jobService->refreshExpiringTokens();
        return ['status' => 'success'];
    }
}
