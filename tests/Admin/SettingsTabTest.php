<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\Page;
use AgencyDiscount\Admin\SettingsForm;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** The Settings tab of the admin page, rendered and posted through Page so the token, nav and banner are covered too. */
final class SettingsTabTest extends TestCase
{
    private const TOKEN = '<input type="hidden" name="token" value="t">';

    private ArrayRepository $repo;
    private RecordingActivityLog $activity;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->repo->settings = Fixtures::settings();
        $this->activity = new RecordingActivityLog();
        foreach ([131, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    /** @param array<string,string> $override */
    private function page(array $override = []): Page
    {
        return Page::of(new Module($this->repo, Config::fromSettings($override + $this->repo->settings), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity));
    }

    /** @param array<string,mixed> $post */
    private function render(array $post = [], Page $page = null): string
    {
        return ($page ?? $this->page())->render('addonmodules.php?module=agencydiscount', ['action' => 'settings'], $post, 'admin:1', self::TOKEN);
    }

    /** @return array<string,mixed> a valid, complete form post built from the fixture settings */
    private static function validPost(): array
    {
        return [
            'tiers' => ['ZAR' => [
                ['above' => '1000.00', 'percent' => '5'],
                ['above' => '2000.00', 'percent' => '10'],
                ['above' => '3000.00', 'percent' => '15'],
            ]],
            'product_groups' => ['1', '23', '3', '6'],
            'products' => [],
            'addons' => ['12', '15'],
            'statuses' => ['Active'],
            'new_orders' => 'on',
            'activity_log' => 'on',
            'description' => 'Agency discount {percent}% on {count} qualifying {items}',
        ];
    }

    public function testTabRendersTheCurrentConfiguration(): void
    {
        $html = $this->render();

        // nav: Settings sits between Overview and Change log
        self::assertMatchesRegularExpression('#>Overview</a>.*>Settings</a>.*>Change log</a>.*>Configuration check</a>#s', $html);
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=agencydiscount&amp;action=settings">Settings</a></li>', $html);

        // mode card: live, with the switch posting action=mode
        self::assertStringContainsString('<span class="label label-success">Live</span>', $html);
        self::assertStringContainsString('<input type="hidden" name="action" value="mode"><input type="hidden" name="dry_run" value="on">', $html);
        self::assertStringContainsString('Switch to dry-run', $html);
        self::assertStringContainsString('Last recalculation: never', $html);
        self::assertStringContainsString('Invoices checked since activation: 0', $html);
        self::assertSame(2, substr_count($html, self::TOKEN), 'the mode form and the settings form each carry the token');

        // tiers: one sub-tab per WHMCS currency, rows from the configuration, copy button on the non-default one
        self::assertStringContainsString('data-agencydiscount-tab="ZAR">ZAR (default currency)</a>', $html);
        self::assertStringContainsString('data-agencydiscount-tab="EUR">EUR</a>', $html);
        self::assertStringContainsString('name="tiers[ZAR][0][above]" value="1000.00"', $html);
        self::assertStringContainsString('name="tiers[ZAR][0][percent]" value="5"', $html);
        self::assertStringContainsString('name="tiers[ZAR][2][above]" value="3000.00"', $html);
        self::assertStringContainsString('data-agencydiscount-add="ZAR" data-next="3"', $html);
        self::assertStringContainsString('name="copy_to" value="EUR">Copy ZAR tiers to EUR at the WHMCS rate (0.05389)</button>', $html);
        self::assertStringNotContainsString('name="copy_to" value="ZAR"', $html);
        self::assertStringContainsString('No tiers: agencies in this currency get no discount.', $html);
        self::assertStringContainsString('name="tiers[__CODE__][__N__][above]"', $html);
        self::assertStringContainsString('+ Add tier', $html);

        // band preview with a worked example per band
        self::assertStringContainsString('<td>1</td><td>above ZAR 1,000.00 up to ZAR 2,000.00</td><td>5%</td><td>a ZAR 1,500.00 invoice is reduced by ZAR 75.00</td>', $html);
        self::assertStringContainsString('<td>3</td><td>above ZAR 3,000.00</td><td>15%</td><td>a ZAR 4,500.00 invoice is reduced by ZAR 675.00</td>', $html);

        // what counts: groups with active counts, hidden last; products by group; add-ons
        self::assertStringContainsString('name="product_groups[]" value="23" checked> Premium Shared <span class="text-muted">(5 active)</span>', $html);
        self::assertStringContainsString('name="product_groups[]" value="15"> Domains <span class="text-muted">(1 active)</span>', $html);
        self::assertStringContainsString('name="product_groups[]" value="12"> Software <span class="text-muted">(hidden, 0 active)</span>', $html);
        self::assertGreaterThan(strpos($html, 'value="33"'), strpos($html, 'value="12"> Software'), 'hidden groups are listed last');
        self::assertStringContainsString('<h5>Premium Shared</h5><label><input type="checkbox" name="products[]" value="113"> sPremium-1</label>', $html);
        self::assertStringContainsString('name="addons[]" value="12" checked> Advanced Email Security', $html);
        self::assertStringContainsString('name="addons[]" value="15" checked> Edge Security (WAF, CDN &amp; DDoS)', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringContainsString('data-agencydiscount-filter="products"', $html);
        self::assertStringContainsString('data-agencydiscount-filter="addons"', $html);

        // statuses and behaviour
        self::assertStringContainsString('name="statuses[]" value="Active" checked> Active', $html);
        self::assertStringContainsString('name="statuses[]" value="Suspended"> Suspended', $html);
        self::assertStringContainsString('name="statuses[]" value="Completed"> Completed', $html);
        self::assertStringContainsString('name="new_orders" value="on" checked>', $html);
        self::assertStringContainsString('name="stack_with_promotions" value="on">', $html);
        self::assertStringContainsString('name="activity_log" value="on" checked>', $html);
        self::assertStringContainsString('name="delete_data" value="on">', $html);
        self::assertStringContainsString('name="description" class="form-control" value="Agency discount {percent}% on {count} qualifying {items}"', $html);
        self::assertStringContainsString('<code>Agency discount 10% on 3 qualifying items</code>', $html);

        // actions and the advanced link
        self::assertStringContainsString('name="action" value="settings_preview" class="btn btn-default">Preview impact</button>', $html);
        self::assertStringContainsString('name="action" value="settings_save" class="btn btn-primary">Save settings</button>', $html);
        self::assertStringContainsString('<a href="configaddonmods.php#agencydiscount">Advanced: edit as JSON</a>', $html);
        self::assertStringNotContainsString(' I ', $html, 'organisation voice');

        // a GET changes nothing
        self::assertSame(Fixtures::settings(), $this->repo->settings);
        self::assertSame([], $this->repo->logs);
        self::assertSame([], $this->activity->entries);
    }

    public function testDryRunModeCardAndBanner(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->agencies[131]['calculatedAt'] = '2026-10-12 03:00:00';
        $this->repo->agencies[500]['calculatedAt'] = '2026-10-11 03:00:00';
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'dry_run', 'detail' => [], 'actor' => 'system', 'createdAt' => '2026-10-12 03:00:00']);
        $html = $this->render();
        self::assertStringContainsString('Dry-run mode is on', $html);
        self::assertStringContainsString('<span class="label label-warning">Dry-run</span>', $html);
        self::assertStringContainsString('name="dry_run" value=""', $html);
        self::assertStringContainsString('onclick="return confirm(&quot;Switch to live?', $html);
        self::assertStringContainsString('>Switch to live</button>', $html);
        self::assertStringContainsString('Last recalculation: 2026-10-12 03:00:00', $html);
        self::assertStringContainsString('Invoices checked since activation: 1', $html);
    }

    public function testModeSwitchSavesLogsAndRebuildsThePage(): void
    {
        $html = $this->render(['action' => 'mode', 'dry_run' => 'on']);
        self::assertStringContainsString('Switched to Dry-run mode.', $html);
        self::assertStringContainsString('Dry-run mode is on', $html);
        self::assertStringContainsString('>Switch to live</button>', $html);
        self::assertSame('on', $this->repo->settings['dry_run']);
        self::assertSame(Fixtures::settings()['tiers'], $this->repo->settings['tiers'], 'only dry_run was written');
        $log = $this->repo->logEntries(null, 1)[0];
        self::assertSame(['mode_changed', null, 'admin:1', ['from' => 'live', 'to' => 'dry-run'], '2026-10-12 09:00:00'], [$log['event'], $log['clientId'], $log['actor'], $log['detail'], $log['createdAt']]);
        self::assertSame([['message' => 'Agency Discount: switched to dry-run mode', 'clientId' => 0]], $this->activity->entries);

        $back = $this->render(['action' => 'mode', 'dry_run' => ''], $this->page());
        self::assertStringContainsString('Switched to Live mode.', $back);
        self::assertStringNotContainsString('Dry-run mode is on', $back);
        self::assertSame('', $this->repo->settings['dry_run']);
        self::assertSame('live', $this->repo->logEntries(null, 1)[0]['detail']['to']);

        $again = $this->render(['action' => 'mode', 'dry_run' => ''], $this->page());
        self::assertStringContainsString('Already in Live mode.', $again);
        self::assertCount(2, $this->repo->logs);
    }

    public function testSaveRejectsProblemsInlineAndKeepsWhatWasTyped(): void
    {
        $post = self::validPost();
        $post['tiers']['ZAR'] = [['above' => '2000', 'percent' => '10'], ['above' => '1000', 'percent' => 'x']];
        $post['statuses'] = [];
        $post['description'] = '  ';
        $html = $this->render(['action' => 'settings_save'] + $post);

        self::assertStringContainsString('The settings were not saved. Fix these and try again:', $html);
        self::assertStringContainsString('<li>tiers: ZAR tier 2 &quot;above&quot; must be greater than tier 1</li>', $html);
        self::assertStringContainsString('<li>tiers: ZAR tier 2 &quot;percent&quot; must be a number</li>', $html);
        self::assertStringContainsString('<li>statuses: at least one status is required</li>', $html);
        self::assertStringContainsString('<li>description: must not be empty</li>', $html);
        self::assertSame(2, substr_count($html, 'tiers: ZAR tier 2 &quot;above&quot;'), 'listed at the top and on the ZAR pane');
        self::assertStringContainsString('name="tiers[ZAR][0][above]" value="2000"', $html);
        self::assertStringContainsString('name="tiers[ZAR][1][percent]" value="x"', $html);
        self::assertStringContainsString('name="statuses[]" value="Active"> Active', $html);
        self::assertStringNotContainsString('id="agencydiscount-impact"', $html);

        self::assertSame(Fixtures::settings(), $this->repo->settings);
        self::assertSame([], $this->repo->logs);
        self::assertSame([], $this->activity->entries);
    }

    public function testSaveWritesTheSettingKeysAndLogsTheDiff(): void
    {
        $post = self::validPost();
        $post['tiers'] = ['ZAR' => [['above' => '0', 'percent' => '5'], ['above' => '10000', 'percent' => '10.50']], 'EUR' => [['above' => '', 'percent' => '']]];
        $post['product_groups'] = ['1', '23', 'abc', '23'];
        $post['products'] = ['161'];
        $post['addons'] = ['12'];
        $post['statuses'] = ['Active', 'Suspended', 'Weird'];
        $post['delete_data'] = 'on';
        unset($post['activity_log']);
        $post['description'] = 'Agency discount {percent}% ({count} items)';
        $html = $this->render(['action' => 'settings_save'] + $post);

        self::assertStringContainsString('Settings saved. Changed: tiers, product_groups, products, addons, statuses, activity_log, delete_data, description.', $html);
        self::assertSame([
            'tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":10000,"percent":10.5}]}',
            'product_groups' => '1,23',
            'products' => '161',
            'addons' => '12',
            'statuses' => 'Active,Suspended,Weird',
            'dry_run' => '',
            'description' => 'Agency discount {percent}% ({count} items)',
            'delete_data' => 'on',
            'activity_log' => '',
            'stack_with_promotions' => '',
            'access' => '1,2', // WHMCS's own access list is not the tab's to write
            'new_orders' => 'on',
        ], $this->repo->settings);

        $log = $this->repo->logEntries(null, 1)[0];
        self::assertSame('settings_changed', $log['event']);
        self::assertNull($log['clientId']);
        self::assertSame('admin:1', $log['actor']);
        self::assertSame(['tiers', 'product_groups', 'products', 'addons', 'statuses', 'activity_log', 'delete_data', 'description'], array_keys($log['detail']['changed']));
        self::assertSame(['from' => '', 'to' => '161'], $log['detail']['changed']['products']);
        self::assertSame(['from' => 'on', 'to' => ''], $log['detail']['changed']['activity_log']);
        self::assertSame([['message' => 'Agency Discount: settings changed: tiers, product_groups, products, addons, statuses, activity_log, delete_data, description', 'clientId' => 0]], $this->activity->entries);

        // the page now shows the saved values, and the engine reads them back unchanged
        self::assertStringContainsString('name="tiers[ZAR][0][above]" value="0.00"', $html);
        self::assertStringContainsString('name="tiers[ZAR][1][percent]" value="10.5"', $html);
        self::assertStringContainsString('name="statuses[]" value="Weird" checked> Weird', $html);
        self::assertStringContainsString('name="delete_data" value="on" checked>', $html);
        self::assertStringContainsString('<code>Agency discount 10% (3 items)</code>', $html);
        $config = Config::fromSettings($this->repo->settings);
        self::assertSame('10.50', $config->tiers->forCurrency('ZAR')[1]->percent);
        self::assertSame([161], $config->productIds);
        self::assertTrue($config->deleteData);
        self::assertFalse($config->activityLog);
    }

    public function testSavingTheSameValuesWritesAndLogsNothing(): void
    {
        $html = $this->render(['action' => 'settings_save'] + self::validPost());
        self::assertStringContainsString('No changes to save.', $html);
        self::assertSame(Fixtures::settings(), $this->repo->settings);
        self::assertSame([], $this->repo->logs);
        self::assertSame([], $this->activity->entries);
    }

    public function testPreviewShowsTierNowAndAfterWithoutSaving(): void
    {
        $post = self::validPost();
        $post['tiers'] = ['ZAR' => [['above' => '0', 'percent' => '5'], ['above' => '1000', 'percent' => '10']], 'EUR' => [['above' => '0', 'percent' => '7.5']]];
        $html = $this->render(['action' => 'settings_preview'] + $post);

        self::assertStringContainsString('id="agencydiscount-impact"', $html);
        self::assertStringContainsString('<tr class="warning"><td><a href="clientssummary.php?userid=131">Agency One</a></td><td>ZAR</td><td>ZAR 1,012.92</td><td>1 (5%)</td><td>2 (10%)</td><td>2026-10-25: ZAR 42.50</td><td>2026-10-25: ZAR 85.00</td></tr>', $html);
        self::assertStringContainsString('<td><a href="clientssummary.php?userid=500">Euro Agency</a></td><td>EUR</td><td>EUR 300.00</td><td><span class="text-danger">no tiers for EUR</span></td><td>1 (7.5%)</td><td>2026-10-20: EUR 0.00</td><td>2026-10-20: EUR 22.50</td></tr>', $html);
        self::assertStringNotContainsString('Settings saved', $html);
        self::assertStringContainsString('name="tiers[EUR][0][percent]" value="7.5"', $html, 'the unsaved values stay on the form');

        self::assertSame(Fixtures::settings(), $this->repo->settings);
        self::assertSame([], $this->repo->logs);
        self::assertSame([], $this->activity->entries);
    }

    public function testPreviewWithNoTierChangeSaysSo(): void
    {
        $html = $this->render(['action' => 'settings_preview'] + self::validPost());
        self::assertStringContainsString('No agency changes tier under these values.', $html);
        self::assertStringNotContainsString('<tr class="warning">', $html);
    }

    public function testPreviewWithASmallerCountedSetShowsTheSpendChange(): void
    {
        $post = self::validPost();
        $post['product_groups'] = ['23'];
        $post['addons'] = [];
        $html = $this->render(['action' => 'settings_preview'] + $post);
        // 170 + 340 + 170 (promotion service still counts) = 680.00: below the first threshold
        self::assertStringContainsString('<td>ZAR 1,012.92 &rarr; ZAR 680.00</td><td>1 (5%)</td><td>none</td>', $html);
    }

    public function testCopyTiersConvertsThresholdsAtTheRate(): void
    {
        $post = self::validPost();
        $post['tiers']['ZAR'] = [['above' => '0', 'percent' => '5'], ['above' => '10000', 'percent' => '10'], ['above' => '15000', 'percent' => '15']];
        $html = $this->render(['copy_to' => 'EUR'] + $post);

        self::assertStringContainsString('EUR tiers filled from ZAR at rate 0.05389. Not saved yet: check them, then save.', $html);
        self::assertStringContainsString('name="tiers[EUR][0][above]" value="0.00"', $html);
        self::assertStringContainsString('name="tiers[EUR][0][percent]" value="5"', $html);
        self::assertStringContainsString('name="tiers[EUR][1][above]" value="538.90"', $html);
        self::assertStringContainsString('name="tiers[EUR][2][above]" value="808.35"', $html);
        self::assertStringContainsString('name="tiers[EUR][2][percent]" value="15"', $html);
        self::assertStringContainsString('name="tiers[ZAR][1][above]" value="10000"', $html, 'the source rows stay as typed');
        self::assertStringContainsString('<td>1</td><td>above EUR 0.00 up to EUR 538.90</td><td>5%</td>', $html);
        self::assertSame(Fixtures::settings(), $this->repo->settings);
        self::assertSame([], $this->repo->logs);
    }

    public function testCopyTiersRefusesABadSourceOrTarget(): void
    {
        $post = self::validPost();
        $post['tiers']['ZAR'] = [['above' => 'x', 'percent' => '5']];
        $html = $this->render(['copy_to' => 'EUR'] + $post);
        self::assertStringContainsString('Fix the ZAR tiers before copying them.', $html);
        self::assertStringContainsString('tiers: ZAR tier 1 &quot;above&quot; must be a number', $html);
        self::assertStringNotContainsString('name="tiers[EUR][0]', $html);

        $html = $this->render(['copy_to' => 'USD'] + self::validPost());
        self::assertStringContainsString('USD has no exchange rate in WHMCS.', $html);

        $post = self::validPost();
        $post['tiers'] = [];
        $html = $this->render(['copy_to' => 'EUR'] + $post);
        self::assertStringContainsString('ZAR has no tiers to copy.', $html);
    }

    public function testConfiguredIdsWhmcsNoLongerHasStayVisibleAndTicked(): void
    {
        $this->repo->settings['products'] = '113,777';
        $this->repo->settings['addons'] = '12,888';
        $this->repo->settings['product_groups'] = '23,999';
        $this->repo->settings['tiers'] = '{"ZAR":[{"above":0,"percent":5}],"USD":[{"above":0,"percent":5}]}';
        $html = $this->render();
        self::assertStringContainsString('name="products[]" value="777" checked> #777 <span class="text-muted">(not found in WHMCS)</span>', $html);
        self::assertStringContainsString('name="addons[]" value="888" checked> #888', $html);
        self::assertStringContainsString('name="product_groups[]" value="999" checked> #999', $html);
        self::assertStringContainsString('data-agencydiscount-tab="USD">USD (not a WHMCS currency)</a>', $html);
        self::assertStringContainsString('name="tiers[USD][0][above]" value="0.00"', $html);
        self::assertStringNotContainsString('name="copy_to" value="USD"', $html);
    }

    public function testNoCurrenciesAtAll(): void
    {
        $this->repo = new ArrayRepository(['settings' => Fixtures::settings()]);
        $this->repo->settings['tiers'] = '{}';
        $html = $this->render();
        self::assertStringContainsString('No tiers: agencies in this currency get no discount.', $html);
        self::assertStringContainsString('No approved agencies yet.', $this->render(['action' => 'settings_preview'] + self::validPost() + ['tiers' => []]));
    }

    public function testEveryDynamicStringIsEscaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b> & Co';
        $post = self::validPost();
        $post['description'] = '<script>alert(1)</script> {percent}%';
        $post['statuses'] = ['Active', '<i>x</i>'];
        $post['tiers']['ZAR'][] = ['above' => '<img>', 'percent' => '1'];
        $html = $this->render(['action' => 'settings_preview'] + $post);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<i>x</i>', $html);
        self::assertStringNotContainsString('<img>', $html);
        self::assertStringNotContainsString('<b>Evil', $html);
        self::assertStringContainsString('value="&lt;img&gt;"', $html);
    }

    public function testFormFromStoredSettingsReadsThemAsFarAsTheyParse(): void
    {
        $form = SettingsForm::fromSettings([
            'tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":"5k"},"junk",null],"usd":7}',
            'product_groups' => ' 23, premium ,23,0',
            'products' => '',
            'addons' => '12,15',
            'statuses' => 'Active, ,Suspended',
            'description' => ' Agency {percent}% ',
            'delete_data' => 'maybe',
            'stack_with_promotions' => 'on',
        ]);
        self::assertSame(['ZAR' => [['above' => '0', 'percent' => '5'], ['above' => '5k', 'percent' => ''], ['above' => 'junk', 'percent' => ''], ['above' => '', 'percent' => '']], 'usd' => []], $form->tiers);
        self::assertNull($form->unreadableTiers);
        self::assertSame([23], $form->groupIds);
        self::assertSame([], $form->productIds);
        self::assertSame([12, 15], $form->addonIds);
        self::assertSame(['Active', 'Suspended'], $form->statuses);
        self::assertSame('Agency {percent}%', $form->description);
        // switches read as Config does: a missing key takes the default, an unexpected value the safe side
        self::assertSame(['new_orders' => true, 'stack_with_promotions' => true, 'activity_log' => true, 'delete_data' => false], $form->switches);

        $unreadable = SettingsForm::fromSettings(['tiers' => '[1,2]'] + Fixtures::settings());
        self::assertSame([], $unreadable->tiers);
        self::assertSame('[1,2]', $unreadable->unreadableTiers);
        self::assertSame('[1,2]', $unreadable->withTiers('ZAR', [['above' => '0', 'percent' => '5']])->unreadableTiers);
        self::assertSame('not json', SettingsForm::fromSettings(['tiers' => 'not json'])->unreadableTiers);

        // a valid stored set gives the same stored keys back as the form built from its Config
        $fromConfig = SettingsForm::fromConfig(Config::fromSettings(Fixtures::settings()))->toSettings();
        self::assertSame($fromConfig, SettingsForm::fromSettings(Fixtures::settings())->toSettings());
    }

    public function testDecodeInputDecodesEveryStringOnce(): void
    {
        $decoded = Page::decodeInput([
            'description' => 'A &amp; B &quot;C&quot; &#039;D&#039;',
            'tiers' => ['ZAR' => [['above' => '1&#039;000', 'percent' => 5]]],
            'plain' => 'A & B',
            'n' => 7,
        ]);
        self::assertSame([
            'description' => 'A & B "C" \'D\'',
            'tiers' => ['ZAR' => [['above' => '1\'000', 'percent' => 5]]],
            'plain' => 'A & B',
            'n' => 7,
        ], $decoded);
    }
}
