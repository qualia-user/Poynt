<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Context;
use App\Services\OnboardingResourceGatherer;

class OnboardingController extends Controller
{
    private OnboardingResourceGatherer $resourceGatherer;

    public function __construct(Context $context, ?OnboardingResourceGatherer $resourceGatherer = null)
    {
        parent::__construct($context);

        $this->resourceGatherer = $resourceGatherer ?? new OnboardingResourceGatherer($this->context);
    }

    /**
     * Trigger a targeted onboarding resource gather for a business.
     *
     * Accepts either query parameters or JSON body payloads with the following structure:
     * {
     *     "businessId": "...",
     *     "resources": ["business", "store"]
     * }
     */
    public function gather(): array
    {
        $api = $this->context->getApi();
        $businessId = (string) ($api->getParam('businessId') ?? '');

        if ($businessId === '') {
            return [
                'success' => false,
                'error' => 'The businessId parameter is required.',
            ];
        }

        $filters = $this->collectFilters($api->getParam('resources'));
        $filters = array_merge($filters, $this->collectFilters($api->getParam('resource')));
        $filters = array_merge($filters, $this->collectFilters($api->getParam('filters')));
        $filters = array_values(array_unique($filters));

        $summary = $this->resourceGatherer->gatherWithSummary($businessId, $filters === [] ? null : $filters);

        $response = [
            'success' => $summary['success'],
            'businessId' => $summary['businessId'],
            'requestedFilters' => $summary['requestedFilters'],
            'matchedResources' => $summary['matchedResources'],
            'resources' => $summary['resources'],
        ];

        if (isset($summary['error'])) {
            $response['error'] = $summary['error'];
        }

        return $response;
    }

    /**
     * Normalize filter parameters from request payloads.
     *
     * @param mixed $raw
     * @return array<int, string>
     */
    private function collectFilters(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $candidates = array_map('trim', explode(',', $raw));
        } elseif (is_array($raw)) {
            $candidates = [];
            foreach ($raw as $value) {
                if (is_string($value)) {
                    foreach (explode(',', $value) as $chunk) {
                        $candidates[] = trim($chunk);
                    }
                }
            }
        } else {
            $candidates = [trim((string) $raw)];
        }

        $filters = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $filters[] = $candidate;
        }

        if ($filters === []) {
            return [];
        }

        return array_values(array_unique($filters));
    }
}
