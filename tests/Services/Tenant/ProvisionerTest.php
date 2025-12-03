<?php

declare(strict_types=1);

namespace Tests\Services\Tenant;

use App\Core\Context;
use App\Services\Tenant\Provisioner;
use Doctrine\DBAL\Connection;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class ProvisionerTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testGetTemplateBaseNamesParsesTemplateFile(): void
    {
        $templatePath = $this->createTemplateFile(<<<SQL
            -- comment that should be ignored
            CREATE TABLE IF NOT EXISTS store_template (store_id INT);
            CREATE TABLE IF NOT EXISTS terminal_template (terminal_id INT);
            SQL
        );

        [$context] = $this->buildContextWithConnection();
        $provisioner = new Provisioner($context, $templatePath);

        self::assertSame(['store', 'terminal'], $provisioner->getTemplateBaseNames());
    }

    public function testProvisionExecutesStatementsAndRecordsVersion(): void
    {
        $templatePath = $this->createTemplateFile(<<<SQL
            CREATE TABLE IF NOT EXISTS store_template (store_id INT);
            CREATE INDEX idx_store_template_id ON store_template (store_id);
            CREATE TABLE IF NOT EXISTS terminal_template (terminal_id INT REFERENCES store_template(store_id));
            SQL
        );

        $statements = [];
        $registryInserts = [];
        [$context] = $this->buildContextWithConnection($statements, $registryInserts);

        $provisioner = new Provisioner($context, $templatePath);

        $result = $provisioner->provision('DemoTenant', ['store', 'terminal']);

        self::assertTrue($result['success']);
        self::assertSame(2025120201, $result['templateVersion']);
        self::assertSame(['store', 'terminal'], $result['registeredTables']);

        self::assertSame(
            [
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                'CREATE TABLE IF NOT EXISTS demotenant_store (store_id INT)',
                'CREATE INDEX idx_demotenant_store_id ON demotenant_store (store_id)',
                'CREATE TABLE IF NOT EXISTS demotenant_terminal (terminal_id INT REFERENCES demotenant_store(store_id))',
                'DELETE FROM tenant_table_registry WHERE business_id = ?',
            ],
            array_slice($statements, 0, 5)
        );

        self::assertStringContainsString('INSERT INTO tenant_schema_version', end($statements));

        self::assertSame(
            [
                [
                    'table' => 'tenant_table_registry',
                    'data' => [
                        'business_id' => 'demotenant',
                        'table_name' => 'demotenant_store',
                        'template_version' => 2025120201,
                    ],
                ],
                [
                    'table' => 'tenant_table_registry',
                    'data' => [
                        'business_id' => 'demotenant',
                        'table_name' => 'demotenant_terminal',
                        'template_version' => 2025120201,
                    ],
                ],
            ],
            $registryInserts
        );
    }

    public function testDropRemovesTenantTablesAndVersionRow(): void
    {
        $templatePath = $this->createTemplateFile('CREATE TABLE IF NOT EXISTS store_template (store_id INT);');

        $statements = [];
        [$context] = $this->buildContextWithConnection($statements);

        $provisioner = new Provisioner($context, $templatePath);
        $result = $provisioner->drop('DemoTenant');

        self::assertTrue($result['success']);
        self::assertSame(2025120201, $result['templateVersion']);

        self::assertSame('SELECT pg_advisory_xact_lock(hashtext(?))', $statements[0]);
        self::assertStringStartsWith('DROP TABLE IF EXISTS public.demotenant_transaction_receipt', $statements[1]);
        self::assertStringStartsWith('DROP TABLE IF EXISTS public.demotenant_store', $statements[count($statements) - 2]);
        self::assertStringContainsString('DELETE FROM tenant_schema_version', end($statements));
    }

    /**
     * @param array<int, string> $statements
     * @param array<int, array<string, mixed>> $registryInserts
     *
     * @return array{Context}
     */
    private function buildContextWithConnection(array &$statements = [], array &$registryInserts = []): array
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willReturn(true);
        $connection->method('commit')->willReturn(true);
        $connection->method('rollBack')->willReturn(null);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            }
        );
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$registryInserts): int {
                $registryInserts[] = [
                    'table' => $table,
                    'data' => $data,
                ];

                return 1;
            }
        );

        $context = $this->createMock(Context::class);
        $context->method('getConn')->willReturn($connection);
        $context->method('getLog')->willReturn($logger);

        return [$context];
    }

    private function createTemplateFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'poynt_template_');

        if ($path === false) {
            self::fail('Unable to create temporary template file');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
