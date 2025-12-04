<?php

declare(strict_types=1);

namespace Tests\Services\Support;

use App\Services\Support\TableNamer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

class TableNamerTest extends TestCase
{
    public function testQuotesTenantTableWhenRegistryMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(false);

        $namer = new TableNamer($connection);

        self::assertSame(
            '"3fb53611-061a-4464-8ca7-0b91ca7c98cf_app_token"',
            $namer->for('3fb53611-061a-4464-8ca7-0b91ca7c98cf', 'app_token')
        );
    }

    public function testUsesRegistryTableNameAndCachesQuotedIdentifier(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn('registered_app_token_v2');

        $namer = new TableNamer($connection);

        self::assertSame('"registered_app_token_v2"', $namer->for('demo', 'app_token'));
        self::assertSame('"registered_app_token_v2"', $namer->for('demo', 'app_token'));
    }
}
