<?php

namespace App\Services\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class TableNamer
{
    private Connection $conn;

    private AbstractPlatform $platform;

    private bool $tenantConnectionScoped;

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function __construct(Connection $conn, ?bool $tenantConnectionScoped = null)
    {
        $this->conn = $conn;
        $this->platform = $conn->getDatabasePlatform();
        $this->tenantConnectionScoped = $tenantConnectionScoped ?? $this->resolveTenantConnectionScope();
    }

    public function for(?string $businessId, string $baseName): string
    {
        $baseName = strtolower(trim($baseName));

        if (!preg_match('/^[a-z0-9_]+$/', $baseName)) {
            throw new \InvalidArgumentException(sprintf('Invalid table base name: %s', $baseName));
        }

        if ($this->tenantConnectionScoped || $businessId === null || $businessId === '') {
            return $this->quoteIdentifier($baseName);
        }

        $cacheKey = sprintf('%s:%s', $businessId, $baseName);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $defaultName = sprintf('%s_%s', $businessId, $baseName);
        $registered = $this->conn->fetchOne(
            'SELECT table_name FROM tenant_table_registry WHERE business_id = :businessId AND table_name LIKE :tablePattern ORDER BY template_version DESC LIMIT 1',
            [
                'businessId' => $businessId,
                'tablePattern' => sprintf('%s%%', $defaultName),
            ],
            [
                'businessId' => ParameterType::STRING,
                'tablePattern' => ParameterType::STRING,
            ]
        );

        $resolvedName = $defaultName;

        if (is_string($registered) && $registered !== '') {
            $resolvedName = $registered;
        }

        $quotedName = $this->quoteIdentifier($resolvedName);
        $this->cache[$cacheKey] = $quotedName;

        return $quotedName;
    }

    private function resolveTenantConnectionScope(): bool
    {
        $flag = $_ENV['TENANT_CONNECTION_SCOPED'] ?? getenv('TENANT_CONNECTION_SCOPED');

        if ($flag === false || $flag === null || $flag === '') {
            return false;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return $this->platform->quoteIdentifier($identifier);
    }
}
