<?php

namespace App\Services\Support;

use GuzzleHttp\ClientInterface;

final class PaginatedRequest
{
    private function __construct()
    {
    }

    /**
     * Ensure the entity list in the provided payload includes all pages when pagination is enabled.
     *
     * @param ClientInterface $httpClient
     * @param array $initialData
     * @param string $initialUrl
     * @param array $options
     * @param string $entityKey
     * @return array
     */
    public static function collect(
        ClientInterface $httpClient,
        array $initialData,
        string $initialUrl,
        array $options,
        string $entityKey
    ): array {
        $items = [];
        if (isset($initialData[$entityKey]) && is_array($initialData[$entityKey])) {
            $items = $initialData[$entityKey];
        }

        $origin = self::buildOrigin($initialUrl);
        $visited = [];
        $nextUrl = self::extractNextUrl($initialData, $origin);

        $totalCount = self::extractCount($initialData);
        $shouldPaginate = $nextUrl !== null;
        if ($totalCount !== null && $totalCount > count($items)) {
            $shouldPaginate = true;
        }

        if ($shouldPaginate === false) {
            if ($totalCount !== null) {
                $initialData['count'] = $totalCount;
            }

            return $initialData;
        }

        while ($nextUrl !== null) {
            if (isset($visited[$nextUrl])) {
                break;
            }
            $visited[$nextUrl] = true;

            $pageResponse = $httpClient->get($nextUrl, $options);
            $pageData = json_decode((string) $pageResponse->getBody(), true);
            if (!is_array($pageData)) {
                break;
            }

            $pageItems = $pageData[$entityKey] ?? [];
            if (is_array($pageItems) && $pageItems !== []) {
                $items = array_merge($items, $pageItems);
            }

            $pageCount = self::extractCount($pageData);
            if ($pageCount !== null) {
                $totalCount = max($totalCount, $pageCount);
            }

            if ($totalCount !== null && count($items) >= $totalCount) {
                break;
            }

            $nextUrl = self::extractNextUrl($pageData, $origin);
        }

        $initialData[$entityKey] = $items;
        if ($totalCount !== null) {
            $initialData['count'] = $totalCount;
        }

        return $initialData;
    }

    private static function extractCount(array $data): ?int
    {
        if (!array_key_exists('count', $data)) {
            return null;
        }

        $count = $data['count'];
        if (is_int($count)) {
            return $count;
        }

        if (is_numeric($count)) {
            return (int) $count;
        }

        return null;
    }

    private static function extractNextUrl(array $data, string $origin): ?string
    {
        foreach (self::normaliseLinks($data) as $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? $link['type'] ?? null;
            if (!is_string($rel) || strtolower($rel) !== 'next') {
                continue;
            }

            $href = $link['href'] ?? $link['uri'] ?? $link['url'] ?? null;
            if (!is_string($href) || $href === '') {
                continue;
            }

            $href = trim($href);
            if (parse_url($href, PHP_URL_SCHEME) === null && $origin !== '') {
                $href = rtrim($origin, '/') . '/' . ltrim($href, '/');
            }

            return $href;
        }

        return null;
    }

    private static function normaliseLinks(array $data): array
    {
        $links = [];

        foreach (['links', 'link'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $links = array_merge($links, self::parseLinkValue($value));
        }

        return $links;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function parseLinkValue($value): array
    {
        $links = [];

        if (is_array($value)) {
            if (self::isAssoc($value) && self::looksLikeSingleLink($value)) {
                $links[] = $value;
                return $links;
            }

            if (self::isAssoc($value) && !self::looksLikeSingleLink($value)) {
                foreach ($value as $rel => $linkValue) {
                    if (is_array($linkValue)) {
                        $link = $linkValue;
                        if (!isset($link['rel']) && is_string($rel)) {
                            $link['rel'] = $rel;
                        }
                        $links[] = $link;
                    } elseif (is_string($linkValue)) {
                        $links[] = [
                            'rel' => is_string($rel) ? $rel : 'next',
                            'href' => $linkValue,
                        ];
                    }
                }
                return $links;
            }

            foreach ($value as $link) {
                if (is_array($link)) {
                    $links[] = $link;
                }
            }

            return $links;
        }

        if (is_string($value) && $value !== '') {
            $links[] = [
                'rel' => 'next',
                'href' => $value,
            ];
        }

        return $links;
    }

    private static function looksLikeSingleLink(array $value): bool
    {
        return array_key_exists('rel', $value) || array_key_exists('href', $value) || array_key_exists('uri', $value);
    }

    private static function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function buildOrigin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
