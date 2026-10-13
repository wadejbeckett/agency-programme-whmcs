<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Integration;

use AgencyDiscount\CapsuleRepository;
use AgencyDiscount\Hooks;
use AgencyDiscount\Money;
use AgencyDiscount\Schema;
use AgencyDiscount\Tests\Support\WhmcsCalls;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real module entry points (activate, profile save, InvoiceCreation,
 * the daily recalculation, deactivate) end to end against a MariaDB sandbox
 * copied from a WHMCS database by tools/sandbox.sh, through Illuminate's
 * Capsule aliased as WHMCS\Database\Capsule.
 *
 * Skipped unless AGENCYDISCOUNT_SANDBOX_DSN is set, e.g.
 * "mysql:unix_socket=/path/to/mysql.sock;dbname=agencydiscount_sandbox"
 * (AGENCYDISCOUNT_SANDBOX_USER defaults to root, AGENCYDISCOUNT_SANDBOX_PASS to empty).
 * Needs PHP's pdo_mysql extension. Refuses to run when the database name does
 * not contain "sandbox". Leaves no module tables and no rows it created.
 */
final class SandboxTest extends TestCase
{
    private const MODULE = 'agencydiscount';
    /** tblinvoices.notes of every invoice this test inserts, so leftovers of an interrupted run can be found */
    private const TAG = 'agencydiscount-sandbox-test';
    /** tblinvoices.paymentmethod of the inserted invoices; the discount line must carry it */
    private const PAYMENT_METHOD = 'banktransfer';

    /** @var list<int> */
    private array $invoiceIds = [];
    private bool $connected = false;

    protected function setUp(): void
    {
        $dsn = getenv('AGENCYDISCOUNT_SANDBOX_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set AGENCYDISCOUNT_SANDBOX_DSN to run against a sandbox copy of a WHMCS database (see tools/sandbox.sh).');
        }
        $config = self::connectionConfig($dsn);
        if (!str_contains($config['database'], 'sandbox')) {
            self::fail('Refusing to run: the database name in AGENCYDISCOUNT_SANDBOX_DSN must contain "sandbox", got "' . $config['database'] . '"');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::fail('pdo_mysql is not loaded in this PHP (the sandbox test needs it; set PHP_INI_SCAN_DIR or install the extension)');
        }

        $capsule = new Manager();
        $capsule->addConnection($config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        if (!class_exists('WHMCS\Database\Capsule')) {
            class_alias(Manager::class, 'WHMCS\Database\Capsule');
        }
        $this->connected = true;
        $this->cleanUp(); // leftovers from an interrupted run, found by TAG

        require_once __DIR__ . '/../../modules/addons/agencydiscount/agencydiscount.php';
        WhmcsCalls::reset();
        Hooks::reset();
    }

    protected function tearDown(): void
    {
        Hooks::reset();
        if ($this->connected) {
            $this->cleanUp();
        }
    }

    public function testModuleEntryPointsEndToEnd(): void
    {
        // a. activate creates the three tables
        self::assertSame('success', \agencydiscount_activate()['status']);
        self::assertSame(Schema::TABLES, array_values(array_filter(Schema::TABLES, fn (string $t): bool => $this->tableExists($t))));

        // c. a client with one active, counted, promotion-free service
        $service = Manager::table('tblhosting AS h')
            ->join('tblclients AS c', 'c.id', '=', 'h.userid')
            ->join('tblproducts AS p', 'p.id', '=', 'h.packageid')
            ->join('tblcurrencies AS cu', 'cu.id', '=', 'c.currency')
            ->where('h.domainstatus', 'Active')
            ->where('h.amount', '>', 0)
            ->where('h.promoid', 0)
            ->whereIn('h.billingcycle', ['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'Triennially'])
            ->orderBy('h.id')
            ->select(['h.id', 'h.userid', 'h.amount', 'h.domain', 'cu.code'])
            ->first();
        self::assertNotNull($service, 'the sandbox has no active priced service');
        $clientId = (int) $service->userid;
        $serviceId = (int) $service->id;
        $amount = bcadd((string) $service->amount, '0', 2);
        $domain = (string) $service->domain;
        $currency = (string) $service->code;
        $discount = Money::percentOf($amount, '10.00');

        // b. settings: one 10% tier above 1, every group and add-on counts, dry-run on; stored
        //    HTML-encoded, as WHMCS saves request input
        $groupIds = array_map('intval', Manager::table('tblproductgroups')->orderBy('id')->pluck('id')->all());
        $addonIds = array_map('intval', Manager::table('tbladdons')->orderBy('id')->pluck('id')->all());
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->delete();
        $settings = [
            'tiers' => '{"' . $currency . '":[{"above":1,"percent":10}]}',
            'product_groups' => implode(',', $groupIds),
            'products' => '',
            'addons' => implode(',', $addonIds),
            'statuses' => 'Active',
            'dry_run' => 'on',
            'stack_with_promotions' => '',
            'new_orders' => 'on',
            'description' => 'Agency discount {percent}% on {count} qualifying items',
            'keep_data' => '',
            'activity_log' => 'on',
        ];
        foreach ($settings as $setting => $value) {
            Manager::table('tbladdonmodules')->insert(['module' => self::MODULE, 'setting' => $setting, 'value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')]);
        }
        self::assertStringContainsString('&quot;', (string) Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'tiers')->value('value'));
        self::assertSame($settings, array_intersect_key((new CapsuleRepository())->settings(), $settings));

        // d. approve through the profile save hook (input arrives HTML-encoded, as WHMCS delivers it)
        Hooks::reset();
        Hooks::profileSave(['userid' => $clientId], ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'sandbox &amp; test']);
        $this->assertNoHookError();
        $repo = new CapsuleRepository();
        $agency = $repo->agency($clientId);
        self::assertNotNull($agency);
        self::assertTrue($agency['approved']);
        self::assertSame('sandbox & test', $agency['note']);
        self::assertSame(1, $agency['tierIndex']);
        self::assertSame('10.00', $agency['tierPercent']);
        self::assertNull($agency['effectiveFrom']);
        self::assertSame($currency, $agency['currency']);
        self::assertSame('admin', $agency['approvedBy']);
        self::assertContains($clientId, array_column($repo->approvedAgencies(), 'clientId'));
        $log = $repo->logEntries($clientId, 10);
        self::assertSame(['tier_changed', 'approved'], array_column($log, 'event'));
        self::assertSame(['note' => 'sandbox & test'], $log[1]['detail']);
        self::assertSame($log[1], $repo->logEntries($clientId, 1, 1)[0]);

        // e. dry run: recorded and logged, invoice untouched
        $first = $this->insertInvoice($clientId, $serviceId, $amount, $domain);
        Hooks::reset();
        Hooks::invoiceCreation(['invoiceid' => $first, 'source' => 'api', 'user' => 'system', 'status' => 'Unpaid']);
        $this->assertNoHookError();
        self::assertSame(0, Manager::table('tblinvoiceitems')->where('invoiceid', $first)->where('type', 'AgencyDiscount')->count());
        self::assertSame(2, Manager::table('tblinvoiceitems')->where('invoiceid', $first)->count());
        $record = $repo->invoiceRecord($first);
        self::assertNotNull($record);
        self::assertTrue($record['dryRun']);
        self::assertSame($discount, $record['amount']);
        self::assertSame($amount, $record['base']);
        self::assertSame('10.00', $record['percent']);
        self::assertSame(1, $record['tierIndex']);
        self::assertSame($clientId, $record['clientId']);
        self::assertNull($record['itemId']);
        $newest = $repo->logEntries($clientId, 1)[0];
        self::assertSame(['dry_run', $first, 'system'], [$newest['event'], $newest['invoiceId'], $newest['actor']]);
        self::assertSame($discount, $newest['detail']['amount']);

        // f. live: one negative line, then idempotent
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'dry_run')->update(['value' => '']);
        Hooks::reset();
        $second = $this->insertInvoice($clientId, $serviceId, $amount, $domain);
        Hooks::invoiceCreation(['invoiceid' => $second, 'source' => 'api', 'user' => 'system', 'status' => 'Unpaid']);
        $this->assertNoHookError();
        $lines = Manager::table('tblinvoiceitems')->where('invoiceid', $second)->where('type', 'AgencyDiscount')->get()->all();
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame($clientId, (int) $line->relid);
        self::assertSame($clientId, (int) $line->userid);
        self::assertSame(Money::negate($discount), bcadd((string) $line->amount, '0', 2));
        self::assertSame(0, (int) $line->taxed);
        self::assertSame(self::PAYMENT_METHOD, (string) $line->paymentmethod);
        self::assertSame('Agency discount 10% on 1 qualifying items', (string) $line->description);
        self::assertSame(date('Y-m-d'), (string) $line->duedate);
        $record = $repo->invoiceRecord($second);
        self::assertNotNull($record);
        self::assertFalse($record['dryRun']);
        self::assertSame((int) $line->id, $record['itemId']);
        self::assertSame($discount, $record['amount']);
        self::assertSame('applied', $repo->logEntries($clientId, 1)[0]['event']);
        self::assertContains(
            sprintf('Agency Discount: 10%% (%s %s) added to invoice #%d as line %d', $currency, $discount, $second, (int) $line->id),
            array_column(WhmcsCalls::$activity, 'message'),
        );

        Hooks::invoiceCreation(['invoiceid' => $second, 'source' => 'api', 'user' => 'system', 'status' => 'Unpaid']);
        $this->assertNoHookError();
        self::assertSame(1, Manager::table('tblinvoiceitems')->where('invoiceid', $second)->where('type', 'AgencyDiscount')->count());
        $newest = $repo->logEntries($clientId, 1)[0];
        self::assertSame(['already_processed', $second], [$newest['event'], $newest['invoiceId']]);

        // g. claimInvoice is first-writer-wins on the real primary key
        $now = date('Y-m-d H:i:s');
        self::assertFalse($repo->claimInvoice($second, $clientId, $now));
        $unused = (int) Manager::table('tblinvoices')->max('id') + 1000;
        self::assertTrue($repo->claimInvoice($unused, $clientId, $now));
        self::assertFalse($repo->claimInvoice($unused, $clientId, $now));
        $claimed = $repo->invoiceRecord($unused);
        self::assertNotNull($claimed);
        self::assertSame([null, 0, '0.00', false], [$claimed['itemId'], $claimed['tierIndex'], $claimed['amount'], $claimed['dryRun']]);
        Manager::table('mod_agencydiscount_invoices')->where('invoice_id', $unused)->delete();
        self::assertNull($repo->invoiceRecord($unused));

        // h. daily recalculation
        Hooks::recalculateAll('cron:daily');
        $this->assertNoHookError();
        $agency = $repo->agency($clientId);
        self::assertNotNull($agency);
        self::assertNotNull($agency['calculatedAt']);
        self::assertSame(1, $agency['tierIndex']);

        // h1. the client summary card renders for an admin whose role is on the addon's access list only
        $admin = Manager::table('tbladmins')->orderBy('id')->select(['id', 'roleid'])->first();
        self::assertNotNull($admin, 'the sandbox has no admin');
        $adminId = (int) $admin->id;
        $roleId = (int) $admin->roleid;
        self::assertSame($roleId, $repo->adminRoleId($adminId));
        self::assertNull($repo->adminRoleId((int) Manager::table('tbladmins')->max('id') + 1000));
        Manager::table('tbladdonmodules')->insert(['module' => self::MODULE, 'setting' => 'access', 'value' => (string) ($roleId + 1000)]);
        \WHMCS\Session::$values['adminid'] = $adminId;
        Hooks::reset();
        self::assertSame('', Hooks::clientSummary(['userid' => $clientId]), 'role not on the access list');
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'access')->update(['value' => (string) $roleId]);
        Hooks::reset();
        $card = Hooks::clientSummary(['userid' => $clientId]);
        $this->assertNoHookError();
        self::assertStringContainsString('Agency Programme', $card);
        self::assertStringContainsString('Tier 1 (10%)', $card);
        self::assertStringContainsString('href="clientsprofile.php?userid=' . $clientId . '"', $card);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=log&amp;client=' . $clientId . '"', $card);
        self::assertSame('', Hooks::clientSummary(['userid' => $clientId + 1000000]), 'not an agency');
        \WHMCS\Session::$values['adminid'] = null;
        $profile = Hooks::profileFields(['userid' => $clientId]);
        self::assertStringContainsString('Right now', $profile['Agency discount']);
        self::assertStringContainsString('Approved by admin on', $profile['Agency discount']);

        // h2. an over-long actor is cut to the column width; a corrupt detail is shown raw
        $repo->log(['clientId' => $clientId, 'invoiceId' => null, 'event' => 'sandbox_actor', 'detail' => [], 'actor' => str_repeat('x', 100), 'createdAt' => $now]);
        self::assertSame(str_repeat('x', 64), $repo->logEntries($clientId, 1)[0]['actor']);
        Manager::table('mod_agencydiscount_log')->insert(['client_id' => $clientId, 'invoice_id' => null, 'event' => 'sandbox_corrupt', 'detail' => '{broken', 'actor' => 'test', 'created_at' => $now]);
        $corrupt = $repo->logEntries($clientId, 1)[0];
        self::assertSame(['sandbox_corrupt', ['_raw' => '{broken']], [$corrupt['event'], $corrupt['detail']]);

        // i. lookups and the catalogue
        $names = $repo->lookupNames('group', array_merge($groupIds, [999999]));
        self::assertSame($groupIds, array_keys($names));
        self::assertNotSame([], $repo->currencyCodes());
        self::assertContains($currency, $repo->currencyCodes());
        $groups = $repo->productGroups();
        $byId = array_column($groups, null, 'id');
        ksort($byId);
        self::assertSame($groupIds, array_keys($byId));
        self::assertSame($names, array_combine($groupIds, array_column(array_values($byId), 'name')));
        $activeByGroup = Manager::table('tblhosting AS h')->join('tblproducts AS p', 'p.id', '=', 'h.packageid')->where('h.domainstatus', 'Active')->groupBy('p.gid')->selectRaw('p.gid, COUNT(*) AS n')->pluck('n', 'gid')->all();
        foreach ($groups as $group) {
            self::assertSame((int) ($activeByGroup[$group['id']] ?? 0), $group['activeServices'], 'active services of group ' . $group['id']);
        }
        $catalogIds = array_column($repo->addonCatalog(), 'id');
        sort($catalogIds);
        self::assertSame($addonIds, $catalogIds);
        $products = $repo->products();
        self::assertSame((int) Manager::table('tblproducts')->count(), count($products));
        self::assertContains((int) Manager::table('tblhosting')->where('id', $serviceId)->value('packageid'), array_column($products, 'id'));
        $currencies = $repo->currencies();
        self::assertSame($repo->currencyCodes(), array_column($currencies, 'code'));
        $defaults = array_values(array_filter($currencies, static fn (array $c): bool => $c['isDefault']));
        self::assertCount(1, $defaults);
        self::assertSame('1.00000', $defaults[0]['rate']);
        foreach (['group' => $names, 'addon' => array_column($repo->addonCatalog(), 'name', 'id')] as $kind => $decoded) {
            foreach ($decoded as $id => $name) {
                self::assertStringNotContainsString('&amp;', $name, $kind . ' ' . $id . ' is still HTML-encoded');
            }
        }

        // i2. settings written by the module are stored encoded, as WHMCS stores its own form, and read back decoded
        $text = 'Agency "discount" & more: {percent}% on {count} items';
        $repo->saveSettings(['description' => $text, 'products' => '1,2']);
        $repo->saveSettings(['products' => '']);
        $stored = (string) Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'description')->value('value');
        self::assertStringContainsString('&quot;discount&quot; &amp; more', $stored);
        self::assertSame(1, Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'products')->count());
        $settings = $repo->settings();
        self::assertSame($text, $settings['description']);
        self::assertSame('', $settings['products']);
        self::assertSame('{"' . $currency . '":[{"above":1,"percent":10}]}', $settings['tiers']);
        self::assertSame(2, $repo->invoicesChecked());

        // j. deactivate drops the tables; clean up and prove nothing is left
        self::assertSame('success', \agencydiscount_deactivate()['status']);
        foreach (Schema::TABLES as $table) {
            self::assertFalse($this->tableExists($table), $table . ' still exists after deactivate');
        }
        $this->cleanUp();
        self::assertSame(0, Manager::table('tblinvoices')->whereIn('id', [$first, $second])->count());
        self::assertSame(0, Manager::table('tblinvoiceitems')->whereIn('invoiceid', [$first, $second])->count());
        self::assertSame(0, Manager::table('tbladdonmodules')->where('module', self::MODULE)->count());
        self::assertSame(0, Manager::table('tblinvoices')->where('notes', self::TAG)->count());
    }

    /** Inserts an Unpaid invoice tagged with TAG and dated today with a Hosting line for the service and a Domain line, supplying every NOT NULL column without a default. */
    private function insertInvoice(int $clientId, int $serviceId, string $amount, string $domain): int
    {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $total = bcadd($amount, '99.00', 2);
        $id = (int) Manager::table('tblinvoices')->insertGetId([
            'userid' => $clientId,
            'invoicenum' => '',
            'date' => $today,
            'duedate' => $today,
            'subtotal' => $total,
            'credit' => '0.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => $total,
            'status' => 'Unpaid',
            'paymentmethod' => self::PAYMENT_METHOD,
            'notes' => self::TAG,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->invoiceIds[] = $id;
        $item = ['invoiceid' => $id, 'userid' => $clientId, 'taxed' => 0, 'duedate' => $today, 'paymentmethod' => self::PAYMENT_METHOD, 'notes' => ''];
        Manager::table('tblinvoiceitems')->insert([
            $item + ['type' => 'Hosting', 'relid' => $serviceId, 'description' => 'Sandbox service - ' . $domain, 'amount' => $amount],
            $item + ['type' => 'Domain', 'relid' => 0, 'description' => 'Sandbox domain', 'amount' => '99.00'],
        ]);
        return $id;
    }

    /** No hook has reported an exception or a configuration problem through logActivity. */
    private function assertNoHookError(): void
    {
        foreach (WhmcsCalls::$activity as $entry) {
            self::assertDoesNotMatchRegularExpression('/^Agency Discount: ([\w\\\\]+: |configuration problems)/', $entry['message']);
        }
    }

    private function tableExists(string $table): bool
    {
        return Manager::schema()->hasTable($table);
    }

    private function cleanUp(): void
    {
        $ids = array_values(array_unique(array_merge(
            $this->invoiceIds,
            array_map('intval', Manager::table('tblinvoices')->where('notes', self::TAG)->pluck('id')->all()),
        )));
        if ($ids !== []) {
            Manager::table('tblinvoiceitems')->whereIn('invoiceid', $ids)->delete();
            Manager::table('tblinvoices')->whereIn('id', $ids)->delete();
        }
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->delete();
        foreach (Schema::dropStatements() as $statement) {
            Manager::connection()->statement($statement);
        }
    }

    /**
     * Turns a PDO MySQL DSN into an Illuminate connection. Strict mode is off, as
     * WHMCS runs it (WHMCS does not support MySQL strict mode).
     *
     * @return array<string, mixed>&array{database: string}
     */
    private static function connectionConfig(string $dsn): array
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            self::fail('AGENCYDISCOUNT_SANDBOX_DSN must be a mysql: PDO DSN');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $parts[trim($key)] = trim($value);
            }
        }
        $user = getenv('AGENCYDISCOUNT_SANDBOX_USER');
        $pass = getenv('AGENCYDISCOUNT_SANDBOX_PASS');
        return array_filter([
            'driver' => 'mysql',
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'unix_socket' => $parts['unix_socket'] ?? null,
            'database' => $parts['dbname'] ?? '',
            'username' => is_string($user) && $user !== '' ? $user : 'root',
            'password' => is_string($pass) ? $pass : '',
            'charset' => $parts['charset'] ?? 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
