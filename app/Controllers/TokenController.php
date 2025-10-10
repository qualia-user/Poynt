<?php

namespace App\Controllers;

use App\Core\Context;
use App\Services\BackgroundJobService;

class TokenController extends Controller
{
    private BackgroundJobService $backgroundJobService;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->backgroundJobService = new BackgroundJobService($this->context);
    }

    public function refreshTokens(): array
    {
        $this->backgroundJobService->refreshExpiringTokens();
        return ['status' => 'success'];
    }

    public function setBackgroundJobService(BackgroundJobService $backgroundJobService): void
    {
        $this->backgroundJobService = $backgroundJobService;
    }
}
