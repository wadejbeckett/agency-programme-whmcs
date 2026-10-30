<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Integration;

use AgencyDiscount\Admin\AgencyTab;
use AgencyDiscount\Admin\Html;
use AgencyDiscount\CapsuleRepository;
use AgencyDiscount\Config;
use AgencyDiscount\Hooks;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\Schema;
use AgencyDiscount\Tests\Support\WhmcsCalls;
use AgencyDiscount\Upgrade;
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
    /** The invoices table as 0.2.0 created it, so the upgrade can be run against the real shape. */
    private const INVOICES_0_2_0 = 'CREATE TABLE mod_agencydiscount_invoices (invoice_id INT NOT NULL PRIMARY KEY, client_id INT NOT NULL, item_id INT NULL, tier_index INT NOT NULL, percent DECIMAL(5,2) NOT NULL, base DECIMAL(16,2) NOT NULL, amount DECIMAL(16,2) NOT NULL, dry_run TINYINT(1) NOT NULL, created_at DATETIME NOT NULL) DEFAULT CHARSET=utf8mb4';

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
        $admin = Manager::table('tbladmins')->orderBy('id')->select(['id', 'roleid'])->first();
        self::assertNotNull($admin, 'the sandbox has no admin');
        $adminId = (int) $admin->id;
        $roleId = (int) $admin->roleid;

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
            'description' => 'Agency discount {percent}% on {count} qualifying {items}',
            'delete_data' => '',
            'activity_log' => 'on',
            'access' => (string) $roleId,
        ];
        foreach ($settings as $setting => $value) {
            Manager::table('tbladdonmodules')->insert(['module' => self::MODULE, 'setting' => $setting, 'value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')]);
        }
        self::assertStringContainsString('&quot;', (string) Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'tiers')->value('value'));
        self::assertSame($settings, array_intersect_key((new CapsuleRepository())->settings(), $settings));

        // d. approve through the profile save hook (input arrives HTML-encoded, as WHMCS delivers it), as an
        //    admin whose role is on the addon's access list; a save without an admin is ignored
        Hooks::reset();
        Hooks::profileSave(['userid' => $clientId], ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'ignored']);
        self::assertNull((new CapsuleRepository())->agency($clientId));
        self::assertStringContainsString('ignored: the admin role is not on the addon', (string) end(WhmcsCalls::$activity)['message']);
        WhmcsCalls::$activity = [];
        \WHMCS\Session::$values['adminid'] = $adminId;
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
        self::assertSame('admin:' . $adminId, $agency['approvedBy']);
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
        self::assertSame('Agency discount 10% on 1 qualifying item', (string) $line->description);
        self::assertSame(date('Y-m-d'), (string) $line->duedate);
        $record = $repo->invoiceRecord($second);
        self::assertNotNull($record);
        self::assertFalse($record['dryRun']);
        self::assertSame((int) $line->id, $record['itemId']);
        self::assertSame($discount, $record['amount']);
        self::assertFalse($record['taxed']);
        self::assertSame(0, $record['line']);
        self::assertCount(1, $repo->invoiceRecordLines($second));
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

        // g. claimInvoice is first-writer-wins on the real unique (invoice_id, line) key
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

        // h1. the client summary card and the profile field render for an admin whose role is on the addon's access list only
        self::assertSame($roleId, $repo->adminRoleId($adminId));
        self::assertNull($repo->adminRoleId((int) Manager::table('tbladmins')->max('id') + 1000));
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'access')->update(['value' => (string) ($roleId + 1000)]);
        \WHMCS\Session::$values['adminid'] = $adminId;
        Hooks::reset();
        self::assertSame('', Hooks::clientSummary(['userid' => $clientId]), 'role not on the access list');
        self::assertSame([], Hooks::profileFields(['userid' => $clientId]), 'role not on the access list');
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'access')->update(['value' => (string) $roleId]);
        Hooks::reset();
        $card = Hooks::clientSummary(['userid' => $clientId]);
        $this->assertNoHookError();
        self::assertStringContainsString('Agency Programme', $card);
        self::assertStringContainsString('Tier 1 (10%)', $card);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=' . $clientId . '">Agency detail</a>', $card);
        self::assertStringNotContainsString('clientsprofile.php', $card, 'the card carries one link: the detail page');
        self::assertStringNotContainsString('action=log', $card);
        self::assertSame('', Hooks::clientSummary(['userid' => $clientId + 1000000]), 'not an agency');
        $profile = Hooks::profileFields(['userid' => $clientId]);
        self::assertStringNotContainsString('Right now', $profile['Agency discount'], 'the profile field holds the approval only');
        self::assertStringContainsString('Approved by admin:' . $adminId . ' on', $profile['Agency discount']);
        \WHMCS\Session::$values['adminid'] = null;

        // h1b. the client area: the discount saved this year counts the live line only; the page and the home panel
        //      show it to the logged-in approved agency and nothing to anyone else
        self::assertSame($discount, $repo->appliedDiscountTotal($clientId, date('Y') . '-01-01'));
        self::assertSame('0.00', $repo->appliedDiscountTotal($clientId, date('Y-m-d', strtotime('+1 day'))));
        self::assertSame('0.00', $repo->appliedDiscountTotal($clientId + 1000000, '2000-01-01'));
        \WHMCS\Session::$values['uid'] = $clientId;
        Hooks::reset();
        $page = \agencydiscount_clientarea([]);
        $this->assertNoHookError();
        self::assertSame(['clientarea', true], [$page['templatefile'], $page['requirelogin']]);
        self::assertTrue($page['vars']['approved']);
        self::assertSame(Html::money($currency, $agency['spend']), $page['vars']['spend']);
        self::assertSame('Tier 1 (10% off)', $page['vars']['tier']['label']);
        self::assertSame(Html::money($currency, $discount), $page['vars']['savedThisYear']);
        self::assertSame($agency['countedServices'] + $agency['countedAddons'], $page['vars']['itemCount']);
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertArrayHasKey('agencydiscount', $panels->children);
        self::assertStringContainsString('Tier 1 (10% off)', $panels->children['agencydiscount']['bodyHtml']);
        \WHMCS\Session::$values['uid'] = null;
        Hooks::reset();
        self::assertFalse(\agencydiscount_clientarea([])['vars']['approved']);
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children);

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

        // i3. the upgrade from 0.2.0 turns a 0.2.0 invoices table into the current shape (the 0.2.1 reshape, then the
        //     0.3.0 client index), keeps its rows, replaces the old default invoice line text and deletes the retired
        //     keep_data row; from 0.2.2 only the index is added; a repeated upgrade adds nothing and raises nothing
        $ddl = static fn (): string => (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ((object) Manager::connection()->select('SHOW CREATE TABLE mod_agencydiscount_invoices')[0])->{'Create Table'});
        $fresh = $ddl();
        $columns = ['invoice_id', 'client_id', 'item_id', 'tier_index', 'percent', 'base', 'amount', 'dry_run', 'created_at'];
        $rows = array_map(static fn (object $row): array => (array) $row, Manager::table('mod_agencydiscount_invoices')->orderBy('invoice_id')->get($columns)->all());
        Manager::connection()->statement('DROP TABLE mod_agencydiscount_invoices');
        Manager::connection()->statement(self::INVOICES_0_2_0);
        Manager::table('mod_agencydiscount_invoices')->insert($rows);
        Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'description')->update(['value' => htmlspecialchars(Config::OLD_DEFAULT_DESCRIPTION, ENT_QUOTES, 'UTF-8')]);
        Manager::table('tbladdonmodules')->insert(['module' => self::MODULE, 'setting' => 'keep_data', 'value' => '']);
        $result = \agencydiscount_upgrade(['version' => '0.2.0']);
        self::assertSame('success', $result['status'], $result['description']);
        self::assertSame($fresh, $ddl(), 'an upgraded table equals a fresh one, client index included');
        self::assertStringContainsString('KEY `idx_client` (`client_id`)', $fresh);
        self::assertSame(0, Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'keep_data')->count(), 'the retired row is gone');
        self::assertSame(1, Manager::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', 'tiers')->count(), 'the moved rows stay');
        self::assertSame($rows, array_map(static fn (object $row): array => (array) $row, Manager::table('mod_agencydiscount_invoices')->orderBy('invoice_id')->get($columns)->all()));
        $record = $repo->invoiceRecord($second);
        self::assertNotNull($record);
        self::assertSame([0, false, (int) $line->id], [$record['line'], $record['taxed'], $record['itemId']]);
        self::assertSame(Config::DEFAULT_DESCRIPTION, $repo->settings()['description']);
        self::assertContains('Agency Programme upgraded from 0.2.0 to ' . Module::VERSION . ' (5 changes)', array_column(WhmcsCalls::$activity, 'message'));
        self::assertContains('Agency Programme upgrade to ' . Module::VERSION . ', note: ' . Upgrade::notes('0.2.0')[0], array_column(WhmcsCalls::$activity, 'message'));
        // a repeat from 0.2.0 (WHMCS re-running after a failure before the version row is written): the reshape and the
        // index are skipped, only the taxed backfill runs again, nothing raises
        $retry = \agencydiscount_upgrade(['version' => '0.2.0']);
        self::assertSame('success', $retry['status'], $retry['description']);
        self::assertSame($fresh, $ddl());
        self::assertStringNotContainsString('columns id, line', $retry['description']);
        self::assertStringNotContainsString('idx_client', $retry['description']);
        self::assertStringContainsString('taxed flag', $retry['description']);
        // from 0.2.2 on a table without the index: the index is added and nothing else changes
        Manager::connection()->statement('ALTER TABLE mod_agencydiscount_invoices DROP KEY idx_client');
        self::assertNotSame($fresh, $ddl());
        $result = \agencydiscount_upgrade(['version' => '0.2.2']);
        self::assertSame('success', $result['status'], $result['description']);
        self::assertSame($fresh, $ddl());
        self::assertStringContainsString('index idx_client (client_id) added', $result['description']);
        // a repeated upgrade from the same version (WHMCS re-running before the version row is written) adds no index and raises nothing
        $again = \agencydiscount_upgrade(['version' => '0.2.2']);
        self::assertSame('success', $again['status'], $again['description']);
        self::assertStringNotContainsString('idx_client', $again['description']);
        self::assertSame($fresh, $ddl());
        self::assertContains('Agency Programme upgraded from 0.2.2 to ' . Module::VERSION . ' (1 change)', array_column(WhmcsCalls::$activity, 'message'));
        self::assertContains('Agency Programme upgraded from 0.2.2 to ' . Module::VERSION . ' (no schema change)', array_column(WhmcsCalls::$activity, 'message'));

        // i4. after the upgrade (the 0.2.0 table above has a primary key on invoice_id, so a line-1 record must not
        //     exist before it): the repository's writes and the "Saved in <year>" query on the real tables
        // i4a. the saved total counts a discount line only while it is on an invoice that is not Cancelled or
        //      Refunded, counts both lines of a mixed-tax invoice, and leaves out dry runs
        $other = $clientId + 1000000; // no other record belongs to this client id
        $record = function (int $invoiceId, int $lineNo, ?int $itemId, string $amount, int $taxed, bool $dryRun = false) use ($repo, $other): void {
            $repo->saveInvoiceRecord(['invoiceId' => $invoiceId, 'line' => $lineNo, 'clientId' => $other, 'itemId' => $itemId, 'taxed' => $taxed === 1, 'tierIndex' => 1, 'percent' => '10.00', 'base' => '100.00', 'amount' => $amount, 'dryRun' => $dryRun, 'createdAt' => date('Y-m-d H:i:s')]);
        };
        $paid = $this->insertTaggedInvoice($other, 'Paid');
        $record($paid, 0, $this->insertDiscountLine($paid, $other, '-10.00', 0), '10.00', 0);
        $cancelled = $this->insertTaggedInvoice($other, 'Cancelled');
        $record($cancelled, 0, $this->insertDiscountLine($cancelled, $other, '-20.00', 0), '20.00', 0);
        $refunded = $this->insertTaggedInvoice($other, 'Refunded');
        $record($refunded, 0, $this->insertDiscountLine($refunded, $other, '-30.00', 0), '30.00', 0);
        $removed = $this->insertTaggedInvoice($other, 'Paid');
        $removedLine = $this->insertDiscountLine($removed, $other, '-40.00', 0);
        $record($removed, 0, $removedLine, '40.00', 0);
        Manager::table('tblinvoiceitems')->where('id', $removedLine)->delete();
        $mixed = $this->insertTaggedInvoice($other, 'Paid');
        $record($mixed, 0, $this->insertDiscountLine($mixed, $other, '-5.00', 0), '5.00', 0);
        $record($mixed, 1, $this->insertDiscountLine($mixed, $other, '-7.00', 1), '7.00', 1);
        $dry = $this->insertTaggedInvoice($other, 'Paid');
        $record($dry, 0, $this->insertDiscountLine($dry, $other, '-99.00', 0), '99.00', 0, true);
        self::assertSame('22.00', $repo->appliedDiscountTotal($other, date('Y') . '-01-01'));
        $logged = $other + 1; // its own client id, removed again below so the prune check later sees only its own rows
        $repo->log(['clientId' => $logged, 'invoiceId' => null, 'event' => 'note_changed', 'detail' => ['to' => 'x'], 'actor' => 'admin:1', 'createdAt' => date('Y-m-d H:i:s')]);
        $repo->log(['clientId' => $logged, 'invoiceId' => null, 'event' => 'revoked', 'detail' => ['note' => ''], 'actor' => 'admin:1', 'createdAt' => date('Y-m-d H:i:s')]);
        self::assertSame(['note_changed'], array_column($repo->logEntries($logged, 10, 0, 'note_changed'), 'event'), 'the event filter on the real table');
        self::assertSame(['revoked', 'note_changed'], array_column($repo->logEntries($logged, 10), 'event'));
        Manager::table('mod_agencydiscount_log')->where('client_id', $logged)->delete();
        // i4a'. the detail page's records query sees the same rows with the invoice status and whether the line is
        //       still there, newest first, and its per-record state agrees with the total: the counted rows sum to it
        $records = $repo->clientInvoiceRecords($other);
        self::assertSame([[$dry, 0], [$mixed, 0], [$mixed, 1], [$removed, 0], [$refunded, 0], [$cancelled, 0], [$paid, 0]], array_map(static fn (array $r): array => [$r['invoiceId'], $r['line']], $records), 'newest first, then by invoice id, then line');
        $states = [];
        $counted = '0.00';
        foreach ($records as $r) {
            $states[$r['invoiceId'] . '/' . $r['line']] = [$r['invoiceStatus'], $r['lineOnInvoice'], AgencyTab::state($r, (int) date('Y'))];
            if (AgencyTab::state($r, (int) date('Y')) === 'counted') {
                $counted = bcadd($counted, $r['amount'], 2);
            }
        }
        self::assertSame([
            $dry . '/0' => ['Paid', true, 'dry_run'],
            $mixed . '/0' => ['Paid', true, 'counted'],
            $mixed . '/1' => ['Paid', true, 'counted'],
            $removed . '/0' => ['Paid', false, 'line_removed'],
            $refunded . '/0' => ['Refunded', true, 'invoice_status'],
            $cancelled . '/0' => ['Cancelled', true, 'invoice_status'],
            $paid . '/0' => ['Paid', true, 'counted'],
        ], $states);
        self::assertSame('22.00', $counted, 'the rows marked counted sum to the saved total');
        Manager::table('tblinvoices')->where('id', $paid)->delete();
        self::assertSame([null, true, 'invoice_gone'], array_values(array_filter(array_map(static fn (array $r): array => [$r['invoiceStatus'], $r['lineOnInvoice'], AgencyTab::state($r, (int) date('Y'))], $repo->clientInvoiceRecords($other)), static fn (array $s): bool => $s[0] === null))[0]);
        self::assertSame('12.00', $repo->appliedDiscountTotal($other, date('Y') . '-01-01'), 'a deleted invoice leaves the total too');

        // i4b. a throw inside the transaction after the claim and a discount line leaves neither behind
        $rolledBack = $this->insertTaggedInvoice($other, 'Unpaid');
        try {
            $repo->transaction(function () use ($repo, $rolledBack, $other): void {
                self::assertTrue($repo->claimInvoice($rolledBack, $other, date('Y-m-d H:i:s')));
                $repo->addInvoiceItem($rolledBack, $other, 'AgencyDiscount', $other, 'rolled back', '-1.00', 0, date('Y-m-d'), self::PAYMENT_METHOD);
                throw new \RuntimeException('stop');
            });
            self::fail('the exception must be rethrown');
        } catch (\RuntimeException $e) {
            self::assertSame('stop', $e->getMessage());
        }
        self::assertSame(0, Manager::table('mod_agencydiscount_invoices')->where('invoice_id', $rolledBack)->count());
        self::assertSame(0, Manager::table('tblinvoiceitems')->where('invoiceid', $rolledBack)->count());
        self::assertSame('done', $repo->transaction(static fn (): string => 'done'));

        // i4c. saveCalculation writes the computed columns only
        $before = $repo->agency($clientId);
        self::assertNotNull($before);
        $repo->saveCalculation($clientId, ['currency' => $currency, 'spend' => '123.45', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 7, 'countedAddons' => 3, 'calculatedAt' => '2026-01-02 03:04:05', 'updatedAt' => '2026-01-02 03:04:05']);
        $after = $repo->agency($clientId);
        self::assertNotNull($after);
        foreach (['approved', 'note', 'effectiveFrom', 'approvedBy', 'approvedAt'] as $key) {
            self::assertSame($before[$key], $after[$key], $key . ' changed');
        }
        self::assertSame(['123.45', null, 7, 3, '2026-01-02 03:04:05'], [$after['spend'], $after['tierIndex'], $after['countedServices'], $after['countedAddons'], $after['calculatedAt']]);
        $repo->saveCalculation($other, ['currency' => $currency, 'spend' => '1.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => '2026-01-02 03:04:05', 'updatedAt' => '2026-01-02 03:04:05']);
        self::assertNull($repo->agency($other), 'no row is created for an unknown client');

        // i4d. pruneLog deletes only entries created before the cut-off
        $event = ['clientId' => $other, 'invoiceId' => null, 'event' => 'tier_changed', 'detail' => [], 'actor' => 'sandbox'];
        $repo->log($event + ['createdAt' => '2000-12-31 23:59:59']);
        $repo->log($event + ['createdAt' => '2001-01-01 00:00:00']);
        $repo->log($event + ['createdAt' => '2000-06-30 00:00:00']);
        $repo->log(['clientId' => null, 'invoiceId' => null, 'event' => 'log_pruned', 'detail' => ['count' => 1], 'actor' => 'cron:daily', 'createdAt' => '2000-01-01 00:00:00']);
        $total = Manager::table('mod_agencydiscount_log')->count();
        self::assertSame(1, $repo->pruneLog('2001-01-01 00:00:00', 1), 'a batch deletes at most its limit');
        self::assertSame(1, $repo->pruneLog('2001-01-01 00:00:00', 1000), 'the old log_pruned row is not counted');
        self::assertSame($total - 2, Manager::table('mod_agencydiscount_log')->count());
        $repo->prunePruneRecords('2001-01-01 00:00:00');
        self::assertSame($total - 3, Manager::table('mod_agencydiscount_log')->count());
        self::assertSame(['2001-01-01 00:00:00'], array_column($repo->logEntries($other, 10), 'createdAt'));
        self::assertSame(0, $repo->pruneLog('2001-01-01 00:00:00', 1000));

        // j. deactivate keeps the tables unless "Delete module data on deactivate" is ticked; then it drops them.
        //    Clean up and prove nothing is left
        self::assertSame('success', \agencydiscount_deactivate()['status']);
        foreach (Schema::TABLES as $table) {
            self::assertTrue($this->tableExists($table), $table . ' was dropped although delete_data is off');
        }
        $repo->saveSettings(['delete_data' => 'on']);
        self::assertSame('success', \agencydiscount_deactivate()['status']);
        foreach (Schema::TABLES as $table) {
            self::assertFalse($this->tableExists($table), $table . ' still exists after deactivate with delete_data on');
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

    /** Inserts an invoice tagged with TAG, dated today, with the given status and no lines. */
    private function insertTaggedInvoice(int $clientId, string $status): int
    {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $id = (int) Manager::table('tblinvoices')->insertGetId([
            'userid' => $clientId,
            'invoicenum' => '',
            'date' => $today,
            'duedate' => $today,
            'subtotal' => '100.00',
            'credit' => '0.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => '100.00',
            'status' => $status,
            'paymentmethod' => self::PAYMENT_METHOD,
            'notes' => self::TAG,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->invoiceIds[] = $id;
        return $id;
    }

    /** Adds an AgencyDiscount line to an invoice inserted by this test and returns its id. */
    private function insertDiscountLine(int $invoiceId, int $clientId, string $amount, int $taxed): int
    {
        return (int) Manager::table('tblinvoiceitems')->insertGetId([
            'invoiceid' => $invoiceId,
            'userid' => $clientId,
            'type' => 'AgencyDiscount',
            'relid' => $clientId,
            'description' => 'Sandbox discount',
            'amount' => $amount,
            'taxed' => $taxed,
            'duedate' => date('Y-m-d'),
            'paymentmethod' => self::PAYMENT_METHOD,
            'notes' => '',
        ]);
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
