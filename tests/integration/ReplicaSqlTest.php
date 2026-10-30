<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Integration;

use AgencyDiscount\Schema;
use AgencyDiscount\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Runs every Sql statement against a real WHMCS database through the mariadb
 * client and checks the column names, then round-trips the module schema in a
 * scratch database. Read-only on the WHMCS database itself.
 *
 * Skipped unless AGENCYDISCOUNT_MARIADB_ARGS (client options, e.g.
 * "--defaults-file=/path/to/my.cnf -u root") and AGENCYDISCOUNT_MARIADB_DB are set.
 */
final class ReplicaSqlTest extends TestCase
{
    private const SCRATCH_DB = 'agencydiscount_scratch';

    /** @var list<string> */
    private array $args = [];
    private string $db = '';

    protected function setUp(): void
    {
        $args = getenv('AGENCYDISCOUNT_MARIADB_ARGS');
        $db = getenv('AGENCYDISCOUNT_MARIADB_DB');
        if (!is_string($args) || trim($args) === '' || !is_string($db) || $db === '') {
            self::markTestSkipped('Set AGENCYDISCOUNT_MARIADB_ARGS and AGENCYDISCOUNT_MARIADB_DB to run against a WHMCS database.');
        }
        $this->args = array_values(array_filter(preg_split('/\s+/', trim($args)) ?: [], static fn (string $a): bool => $a !== ''));
        $this->db = $db;
    }

    public function testEveryStatementReturnsTheExpectedColumns(): void
    {
        $clientWithServices = $this->scalar('SELECT MIN(h.userid) FROM tblhosting h JOIN tblproducts p ON p.id = h.packageid');
        $clientWithAddons = $this->scalar('SELECT MIN(h.userid) FROM tblhostingaddons a JOIN tbladdons ad ON ad.id = a.addonid JOIN tblhosting h ON h.id = a.hostingid');
        $bindings = [
            'CLIENT' => [$this->scalar('SELECT MIN(id) FROM tblclients')],
            'SERVICES' => [$clientWithServices],
            'ADDONS' => [$clientWithAddons, $clientWithAddons],
            'INVOICE' => [$this->scalar('SELECT MIN(id) FROM tblinvoices')],
            'INVOICE_ITEMS' => [$this->scalar('SELECT MIN(invoiceid) FROM tblinvoiceitems WHERE invoiceid IN (SELECT id FROM tblinvoices)')],
            'GROUP_NAMES' => [$this->scalar('SELECT MIN(id) FROM tblproductgroups')],
            'PRODUCT_NAMES' => [$this->scalar('SELECT MIN(id) FROM tblproducts')],
            'ADDON_NAMES' => [$this->scalar('SELECT MIN(id) FROM tbladdons')],
            'CURRENCIES' => [],
            'PRODUCT_GROUPS' => [],
            'PRODUCTS' => [],
            'ADDON_CATALOG' => [],
            'SETTINGS' => [$this->scalar('SELECT module FROM tbladdonmodules ORDER BY id LIMIT 1')],
            'INDEX_EXISTS' => ['tbladdonmodules', 'PRIMARY'],
            'COLUMN_EXISTS' => ['tbladdonmodules', 'setting'],
            'ADMIN_ROLE' => [$this->scalar('SELECT MIN(id) FROM tbladmins')],
        ];

        $sql = new \ReflectionClass(Sql::class);
        $checked = [];
        $skipped = 0;
        foreach (Sql::expectedColumns() as $name => $columns) {
            $statement = (string) $sql->getConstant($name);
            if (Sql::onModuleTables($statement)) {
                $skipped++;
                continue; // the module's own tables do not exist on the replica; covered by SandboxTest
            }
            self::assertArrayHasKey($name, $bindings, 'no bindings for Sql::' . $name);
            if (str_contains($statement, '%s')) {
                $statement = sprintf($statement, '?');
            }
            $statement = $this->bind($statement, $bindings[$name]);
            [$code, $stdout, $stderr] = $this->mariadb([$this->db, '--batch', '-e', $statement]);
            self::assertSame(0, $code, 'Sql::' . $name . ' failed: ' . $stderr);
            $header = strtok($stdout, "\n");
            self::assertNotFalse($header, 'Sql::' . $name . ' returned no rows, so no header to check');
            self::assertSame($columns, explode("\t", $header), 'Sql::' . $name . ' columns');
            $checked[] = $name;
        }
        self::assertSame(2, $skipped, 'CLAIM_INVOICE and APPLIED_DISCOUNT_TOTAL are on module tables');
        self::assertCount(count(Sql::expectedColumns()) - $skipped, $checked);
    }

    public function testSchemaInstallsAndDropsCleanly(): void
    {
        [$code, , $stderr] = $this->mariadb(['-e', 'CREATE DATABASE IF NOT EXISTS ' . self::SCRATCH_DB]);
        self::assertSame(0, $code, $stderr);
        try {
            foreach (array_merge(Schema::createStatements(), Schema::createStatements(), Schema::dropStatements()) as $statement) {
                [$code, , $stderr] = $this->mariadb([self::SCRATCH_DB, '-e', $statement]);
                self::assertSame(0, $code, $statement . ': ' . $stderr);
            }
            [$code, $stdout] = $this->mariadb([self::SCRATCH_DB, '--batch', '--skip-column-names', '-e', 'SHOW TABLES']);
            self::assertSame(0, $code);
            self::assertSame('', trim($stdout), 'drop statements leave no tables');
        } finally {
            [$code, , $stderr] = $this->mariadb(['-e', 'DROP DATABASE ' . self::SCRATCH_DB]);
            self::assertSame(0, $code, $stderr);
        }
    }

    /**
     * A 0.2.0 invoices table with one record, upgraded with Schema::upgradeStatements('0.2.0'), ends with
     * the same CREATE TABLE as a fresh 0.2.1 install, and the record takes its line's taxed flag.
     */
    public function testSchemaUpgradesFrom020ToTheFreshShape(): void
    {
        $ddl = function (): string {
            [$code, $stdout, $stderr] = $this->mariadb([self::SCRATCH_DB, '--batch', '--skip-column-names', '-e', 'SHOW CREATE TABLE mod_agencydiscount_invoices']);
            self::assertSame(0, $code, $stderr);
            return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $stdout);
        };
        $run = function (string $statement): void {
            [$code, , $stderr] = $this->mariadb([self::SCRATCH_DB, '-e', $statement]);
            self::assertSame(0, $code, $statement . ': ' . $stderr);
        };
        [$code, , $stderr] = $this->mariadb(['-e', 'CREATE DATABASE IF NOT EXISTS ' . self::SCRATCH_DB]);
        self::assertSame(0, $code, $stderr);
        try {
            $run(Schema::createStatements()[2]);
            $fresh = $ddl();
            $run('DROP TABLE mod_agencydiscount_invoices');
            $run('CREATE TABLE mod_agencydiscount_invoices (invoice_id INT NOT NULL PRIMARY KEY, client_id INT NOT NULL, item_id INT NULL, tier_index INT NOT NULL, percent DECIMAL(5,2) NOT NULL, base DECIMAL(16,2) NOT NULL, amount DECIMAL(16,2) NOT NULL, dry_run TINYINT(1) NOT NULL, created_at DATETIME NOT NULL) DEFAULT CHARSET=utf8mb4');
            $run('CREATE TABLE tblinvoiceitems (id INT NOT NULL PRIMARY KEY, taxed TINYINT(1) NOT NULL DEFAULT 0)');
            $run('INSERT INTO tblinvoiceitems (id, taxed) VALUES (5, 1)');
            $run("INSERT INTO mod_agencydiscount_invoices VALUES (9, 1, 5, 1, 5.00, 100.00, 5.00, 0, '2026-10-25 02:00:00'), (10, 1, NULL, 0, 0.00, 0.00, 0.00, 0, '2026-10-25 02:00:00')");
            foreach (Schema::upgradeStatements('0.2.0') as $statement) {
                $run($statement);
            }
            self::assertSame($fresh, $ddl());
            [$code, $stdout] = $this->mariadb([self::SCRATCH_DB, '--batch', '--skip-column-names', '-e', 'SELECT invoice_id, line, taxed FROM mod_agencydiscount_invoices ORDER BY invoice_id']);
            self::assertSame(0, $code);
            self::assertSame("9\t0\t1\n10\t0\t0", trim($stdout));
            $run("INSERT INTO mod_agencydiscount_invoices (invoice_id, line, client_id, item_id, taxed, tier_index, percent, base, amount, dry_run, created_at) VALUES (9, 1, 1, 6, 0, 1, 5.00, 50.00, 2.50, 0, '2026-10-25 02:00:00')");
            [$code, $stdout] = $this->mariadb([self::SCRATCH_DB, '--batch', '--skip-column-names', '-e', "INSERT IGNORE INTO mod_agencydiscount_invoices (invoice_id, line, client_id, item_id, taxed, tier_index, percent, base, amount, dry_run, created_at) VALUES (9, 0, 1, NULL, 0, 0, 0.00, 0.00, 0.00, 0, '2026-10-25 02:00:00'); SELECT ROW_COUNT()"]);
            self::assertSame(0, $code);
            self::assertSame('0', trim($stdout), 'line 0 of an invoice can be claimed once');
        } finally {
            [$code, , $stderr] = $this->mariadb(['-e', 'DROP DATABASE ' . self::SCRATCH_DB]);
            self::assertSame(0, $code, $stderr);
        }
    }

    private function scalar(string $query): string
    {
        [$code, $stdout, $stderr] = $this->mariadb([$this->db, '--batch', '--skip-column-names', '-e', $query]);
        self::assertSame(0, $code, $query . ': ' . $stderr);
        $value = trim($stdout);
        self::assertNotSame('', $value, $query . ' returned nothing');
        self::assertNotSame('NULL', $value, $query . ' returned NULL');
        return $value;
    }

    /** @param list<string> $values replace each "?" in order: digits as numbers, anything else as a quoted string */
    private function bind(string $statement, array $values): string
    {
        foreach ($values as $value) {
            $literal = ctype_digit($value) ? $value : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
            $position = strpos($statement, '?');
            self::assertNotFalse($position, 'more bindings than placeholders');
            $statement = substr_replace($statement, $literal, $position, 1);
        }
        self::assertStringNotContainsString('?', $statement, 'fewer bindings than placeholders');
        return $statement;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function mariadb(array $arguments): array
    {
        $process = proc_open(
            array_merge(['mariadb'], $this->args, $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'could not start the mariadb client');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
