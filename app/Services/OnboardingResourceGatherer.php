<?php

namespace App\Services;

use App\Core\Context;
use Throwable;

class OnboardingResourceGatherer
{
    private Context $context;
    private ServiceFactory $serviceFactory;

    public function __construct(Context $context, ?ServiceFactory $serviceFactory = null)
    {
        $this->context = $context;
        $this->serviceFactory = $serviceFactory ?? new ServiceFactory($context);
    }

    /**
     * Execute the onboarding resource synchronization and return a simple boolean status.
     *
     * @param array<int, string>|null $resourceFilters
     */
    public function gather(string $businessId, ?array $resourceFilters = null): bool
    {
        $summary = $this->executeGather($businessId, $resourceFilters);

        return $summary['success'];
    }

    /**
     * Execute the onboarding resource synchronization and return a structured summary that
     * can be used by recovery tooling or diagnostics.
     *
     * @param array<int, string>|null $resourceFilters
     * @return array{
     *     success: bool,
     *     businessId: string,
     *     requestedFilters: array<int, string>,
     *     matchedResources: array<int, string>,
     *     resources: array<string, array{
     *         class: string,
     *         success: bool,
     *         skipped: bool,
     *         error?: string,
     *         message?: string
     *     }>,
     *     error?: string
     * }
     */
    public function gatherWithSummary(string $businessId, ?array $resourceFilters = null): array
    {
        return $this->executeGather($businessId, $resourceFilters);
    }

    /**
     * @param array<int, string>|null $resourceFilters
     * @return array{
     *     success: bool,
     *     businessId: string,
     *     requestedFilters: array<int, string>,
     *     matchedResources: array<int, string>,
     *     resources: array<string, array{
     *         class: string,
     *         success: bool,
     *         skipped: bool,
     *         error?: string,
     *         message?: string
     *     }>,
     *     error?: string
     * }
     */
    private function executeGather(string $businessId, ?array $resourceFilters = null): array
    {
        $requestedFilters = $this->sanitizeRequestedFilters($resourceFilters);

        $resources = $this->serviceFactory->onboardingResources($businessId);
        $filteredResources = $this->filterResources($resources, $requestedFilters);

        $summary = [
            'success' => true,
            'businessId' => $businessId,
            'requestedFilters' => $requestedFilters,
            'matchedResources' => array_keys($filteredResources),
            'resources' => [],
        ];

        if ($filteredResources === []) {
            $errorMessage = $requestedFilters === []
                ? sprintf('No onboarding resources are registered for business %s.', $businessId)
                : sprintf('No onboarding resources matched the provided filters for business %s.', $businessId);

            if ($requestedFilters !== []) {
                $this->context->getLog()->warning(
                    'OnboardingResourceGatherer::gather found no resources matching filters.',
                    [
                        'businessId' => $businessId,
                        'filters' => $requestedFilters,
                    ]
                );
            } else {
                $this->context->getLog()->warning(
                    'OnboardingResourceGatherer::gather found no resources to process.',
                    ['businessId' => $businessId]
                );
            }

            $summary['success'] = false;
            $summary['error'] = $errorMessage;

            return $summary;
        }

        $this->context->getLog()->info(
            sprintf('OnboardingResourceGatherer::gather starting for business %s.', $businessId),
            [
                'resources' => array_keys($filteredResources),
                'filters' => $requestedFilters,
            ]
        );

        foreach ($filteredResources as $resourceKey => $service) {
            $serviceClass = get_class($service);

            $this->context->getLog()->info(
                sprintf(
                    'OnboardingResourceGatherer::gather syncing %s (%s) for business %s.',
                    $resourceKey,
                    $serviceClass,
                    $businessId
                ),
                [
                    'resource' => $resourceKey,
                    'serviceClass' => $serviceClass,
                ]
            );

            $result = $this->syncResourceCollection($businessId, $service, $serviceClass);

            $resourceSummary = [
                'class' => $serviceClass,
                'success' => $result['success'],
                'skipped' => $result['skipped'],
            ];

            if (isset($result['error'])) {
                $resourceSummary['error'] = $result['error'];
            }

            if (isset($result['message'])) {
                $resourceSummary['message'] = $result['message'];
            }

            if (!$result['success']) {
                $summary['success'] = false;
                $this->context->getLog()->warning(
                    sprintf(
                        'OnboardingResourceGatherer::gather encountered issues while syncing %s (%s) for business %s.',
                        $resourceKey,
                        $serviceClass,
                        $businessId
                    ),
                    [
                        'resource' => $resourceKey,
                        'serviceClass' => $serviceClass,
                        'error' => $result['error'] ?? null,
                    ]
                );
            } elseif ($result['skipped']) {
                $this->context->getLog()->debug(
                    sprintf(
                        'OnboardingResourceGatherer::gather skipped %s (%s) for business %s because the service does not expose synchronization methods.',
                        $resourceKey,
                        $serviceClass,
                        $businessId
                    ),
                    [
                        'resource' => $resourceKey,
                        'serviceClass' => $serviceClass,
                    ]
                );
            } else {
                $this->context->getLog()->info(
                    sprintf(
                        'OnboardingResourceGatherer::gather completed %s (%s) for business %s successfully.',
                        $resourceKey,
                        $serviceClass,
                        $businessId
                    ),
                    [
                        'resource' => $resourceKey,
                        'serviceClass' => $serviceClass,
                    ]
                );
            }

            $summary['resources'][$resourceKey] = $resourceSummary;
        }

        $this->context->getLog()->info(
            sprintf(
                'OnboardingResourceGatherer::gather finished for business %s with status: %s.',
                $businessId,
                $summary['success'] ? 'success' : 'partial-failure'
            ),
            [
                'resources' => array_keys($filteredResources),
                'filters' => $requestedFilters,
            ]
        );

        return $summary;
    }

    /**
     * @param array<int, string> $requestedFilters
     * @return array<string, object>
     */
    private function filterResources(array $resources, array $requestedFilters): array
    {
        if ($requestedFilters === []) {
            return $resources;
        }

        $normalize = static function (string $value): string {
            return strtolower((string) preg_replace('/[^a-z0-9]+/', '', $value));
        };

        $normalizedKeys = [];
        $classFilters = [];

        foreach ($requestedFilters as $filterString) {
            $trimmed = ltrim($filterString, '\\');
            if ($trimmed === '') {
                continue;
            }

            if (str_contains($trimmed, '\\')) {
                $classFilters[] = $trimmed;
            } else {
                $normalizedKeys[] = $normalize($trimmed);
            }
        }

        $filtered = [];

        foreach ($resources as $key => $service) {
            $serviceClass = get_class($service);
            $shortClass = ($pos = strrpos($serviceClass, '\\')) !== false
                ? substr($serviceClass, $pos + 1)
                : $serviceClass;
            $normalizedKey = $normalize($key);
            $normalizedShortClass = $normalize($shortClass);

            $matches = false;
            if (in_array($normalizedKey, $normalizedKeys, true)) {
                $matches = true;
            } elseif (in_array($normalizedShortClass, $normalizedKeys, true)) {
                $matches = true;
            } elseif (in_array($serviceClass, $classFilters, true)) {
                $matches = true;
            }

            if ($matches) {
                $filtered[$key] = $service;
            }
        }

        return $filtered;
    }

    /**
     * Normalize any incoming filter values so the summary can report what was requested.
     *
     * @param array<int, string>|null $resourceFilters
     * @return array<int, string>
     */
    private function sanitizeRequestedFilters(?array $resourceFilters): array
    {
        if ($resourceFilters === null) {
            return [];
        }

        $sanitized = [];
        foreach ($resourceFilters as $filter) {
            if (!is_string($filter)) {
                continue;
            }

            $trimmed = trim($filter);
            if ($trimmed === '') {
                continue;
            }

            $sanitized[] = $trimmed;
        }

        if ($sanitized === []) {
            return [];
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param object $service
     * @return array{success: bool, skipped: bool, error?: string, message?: string}
     */
    private function syncResourceCollection(string $businessId, object $service, ?string $serviceClass = null): array
    {
        $serviceClass = $serviceClass ?? get_class($service);

        if (!method_exists($service, 'fetchByBusinessId') || !method_exists($service, 'upsert')) {
            $this->context->getLog()->debug(
                sprintf(
                    'OnboardingResourceGatherer::syncResourceCollection skipping %s for business %s due to missing interface methods.',
                    $serviceClass,
                    $businessId
                )
            );

            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Service does not implement fetchByBusinessId/upsert; nothing to synchronize.',
            ];
        }

        try {
            $this->context->getLog()->debug(
                sprintf(
                    'OnboardingResourceGatherer::syncResourceCollection fetching data from %s for business %s.',
                    $serviceClass,
                    $businessId
                )
            );
            $raw = $service->fetchByBusinessId($businessId);
        } catch (Throwable $e) {
            $errorMessage = sprintf(
                'Failed to fetch onboarding resources via %s for business %s: %s',
                $serviceClass,
                $businessId,
                $e->getMessage()
            );

            $this->context->getLog()->error($errorMessage, ['exception' => $e]);

            return [
                'success' => false,
                'skipped' => false,
                'error' => $errorMessage,
            ];
        }

        if (!is_array($raw)) {
            $this->context->getLog()->debug(
                sprintf(
                    'OnboardingResourceGatherer::syncResourceCollection received non-array payload from %s for business %s, normalizing.',
                    $serviceClass,
                    $businessId
                )
            );
            $raw = $raw !== null ? [$raw] : [];
        }

        try {
            $service->upsert($raw, $businessId);
        } catch (Throwable $e) {
            $errorMessage = sprintf(
                'Failed to persist onboarding resources via %s for business %s: %s',
                $serviceClass,
                $businessId,
                $e->getMessage()
            );

            $this->context->getLog()->error($errorMessage, ['exception' => $e]);

            return [
                'success' => false,
                'skipped' => false,
                'error' => $errorMessage,
            ];
        }

        return [
            'success' => true,
            'skipped' => false,
        ];
    }
}

