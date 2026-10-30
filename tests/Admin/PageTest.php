<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        foreach ([131, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'effectiveFrom' => null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    /** @param array<string,string> $override */
    private function page(array $override = []): Page
    {
        return Page::of(new Module($this->repo, Config::fromSettings($override + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog()));
    }

    public function testOverviewListsAgenciesWithLiveFigures(): void
    {
        $html = $this->page(['dry_run' => 'on'])->render('addonmodules.php?module=agencydiscount', [], [], 'admin:1', '<input type="hidden" name="token" value="t">');
        self::assertStringContainsString('Dry-run mode is on', $html);
        self::assertStringContainsString('Agency One', $html);
        self::assertStringContainsString('ZAR 1,012.92', $html);
        self::assertStringContainsString('1 (5%)', $html);
        self::assertStringContainsString('<span class="agencydiscount-nowrap">2026-10-25</span><br><small class="agencydiscount-nowrap">ZAR 42.50 off ZAR 850.00</small>', $html);
        self::assertStringContainsString('ZAR 987.09', $html);
        self::assertStringContainsString('clientssummary.php?userid=131', $html);
        self::assertStringContainsString('name="token" value="t"', $html);
        self::assertStringNotContainsString('Retail Client', $html);
        self::assertNull($this->repo->agency(131)['calculatedAt']);
        self::assertSame([], $this->repo->logEntries(131, 10));
        $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertNull($this->repo->agency(131)['calculatedAt']);
        self::assertSame([], $this->repo->logEntries(131, 10));
    }

    public function testUpcomingInvoicesBeforeStartDateShowNoDiscount(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2026-11-01';
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('<span class="agencydiscount-nowrap">2026-10-25</span><br><small class="agencydiscount-nowrap">no discount on ZAR 850.00</small>', $html);
        $detail = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('<td>2026-10-25</td><td>5</td><td>ZAR 850.00</td><td>ZAR 0.00</td>', $detail);
        self::assertStringContainsString('<td>2026-11-03</td><td>2</td><td>ZAR 1,955.00</td><td>ZAR 97.75</td>', $detail);
    }

    public function testOverviewFlagsCurrencyWithoutTiers(): void
    {
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('no tiers for EUR', $html);
        self::assertStringNotContainsString('Dry-run mode is on', $html);
    }

    public function testRecalculatePostStoresAndReports(): void
    {
        $html = $this->page()->render('m', [], ['action' => 'recalculate'], 'admin:1', '');
        self::assertStringContainsString('Recalculated 2 agencies.', $html);
        self::assertSame(1, $this->repo->agency(131)['tierIndex'] ?? null);
        self::assertSame('admin:1', $this->repo->logEntries(131, 1)[0]['actor']);
    }

    public function testAgencyDetailAndUnknown(): void
    {
        $html = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('a.example', $html);
        self::assertStringContainsString('Advanced Email Security', $html);
        self::assertStringContainsString('2026-11-03', $html);
        self::assertStringContainsString('ZAR 97.75', $html);
        self::assertStringNotContainsString('e.example', $html);
        self::assertStringContainsString('<a href="m&amp;action=log&amp;client=131">Change log for this client</a>', $html);
        $bad = $this->page()->render('m', ['action' => 'agency', 'id' => '600'], [], 'admin:1', '');
        self::assertStringContainsString('Client 600 is not an approved agency', $bad);
    }

    public function testLogViewEscapesAndPaginates(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->repo->log(['clientId' => 131, 'invoiceId' => null, 'event' => 'note_changed', 'detail' => ['to' => "<script>$i"], 'actor' => 'admin:1', 'createdAt' => '2026-10-12 09:00:00']);
        }
        $html = $this->page()->render('m', ['action' => 'log'], [], 'admin:1', '');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;60', $html);
        self::assertStringNotContainsString('&lt;script&gt;10"', $html);
        self::assertStringContainsString('page=2', $html);
        $page2 = $this->page()->render('m', ['action' => 'log', 'page' => '2'], [], 'admin:1', '');
        self::assertStringContainsString('&lt;script&gt;10', $page2);
    }

    public function testThreeTabsAndAnOldCheckLinkOpensTheOverview(): void
    {
        $html = $this->page()->render('m', ['action' => 'check'], [], 'admin:1', '');
        self::assertStringStartsWith('<ul class="nav nav-tabs"><li class="active"><a href="m">Overview</a></li><li><a href="m&amp;action=settings">Settings</a></li><li><a href="m&amp;action=log">Change log</a></li></ul>', $html);
        self::assertStringNotContainsString('Configuration check', $html);
        self::assertStringNotContainsString('action=check', $html);
        self::assertStringContainsString('Agency One', $html);
        self::assertStringContainsString('Needs attention', $html);
    }

    public function testCatalogueNamesAreEscapedExactlyOnce(): void
    {
        $detail = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('Edge Security (WAF, CDN &amp; DDoS)', $detail);
        self::assertStringNotContainsString('&amp;amp;', $detail);
    }

    public function testConfigProblemsBannerPointsToTheSettingsTab(): void
    {
        $html = Page::configProblems('m', ['tiers: invalid JSON', 'statuses: <b>']);
        self::assertStringContainsString('<div class="alert alert-danger"><p>The stored settings have problems, so no discount is applied until they are fixed. <a href="m&amp;action=settings">Fix them on the Settings tab</a>.</p><ul><li>tiers: invalid JSON</li><li>statuses: &lt;b&gt;</li></ul></div>', $html);
        self::assertStringNotContainsString('Addon Modules', $html);
        self::assertStringContainsString('<p>The stored settings have problems, so no discount is applied until they are fixed and saved below.</p>', Page::configProblems('m', ['tiers: invalid JSON'], true));
    }

    /** @return array<string,mixed> a valid, complete Settings form post */
    private static function validSettingsPost(): array
    {
        return [
            'action' => 'settings_save',
            'tiers' => ['ZAR' => [['above' => '1000.00', 'percent' => '5'], ['above' => '2000.00', 'percent' => '10'], ['above' => '3000.00', 'percent' => '15']]],
            'product_groups' => ['1', '23', '3', '6'],
            'products' => [],
            'addons' => ['12', '15'],
            'statuses' => ['Active'],
            'new_orders' => 'on',
            'activity_log' => 'on',
            'description' => 'Agency discount {percent}% on {count} qualifying {items}',
        ];
    }

    /** @param array<string,string> $stored the stored settings, as SQL or an import could leave them */
    private function repairPage(array $stored): Page
    {
        $this->repo->settings = $stored;
        return Page::forStoredSettings($this->repo, new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    public function testStoredSettingsThatDoNotParseGiveTheRepairPage(): void
    {
        $token = '<input type="hidden" name="token" value="t">';
        $page = $this->repairPage(['tiers' => '{"ZAR":[', 'product_groups' => '23,premium'] + Fixtures::settings());
        $this->repo->log(['clientId' => 131, 'invoiceId' => null, 'event' => 'note_changed', 'detail' => ['to' => 'hello'], 'actor' => 'admin:1', 'createdAt' => '2026-10-12 08:00:00']);

        // Overview, agency detail and an old check link: the banner alone, linking to Settings; nothing is computed
        foreach ([[], ['action' => 'agency', 'id' => '131'], ['action' => 'check']] as $get) {
            $html = $page->render('m', $get, [], 'admin:1', $token);
            self::assertStringStartsWith('<ul class="nav nav-tabs"><li', $html);
            self::assertStringContainsString('no discount is applied until they are fixed. <a href="m&amp;action=settings">Fix them on the Settings tab</a>.</p><ul><li>tiers: invalid JSON</li><li>product_groups: &quot;premium&quot; is not a number</li></ul></div>', $html);
            self::assertStringEndsWith('</ul></div>', $html, 'the banner is the whole view');
            self::assertStringNotContainsString('Agency One', $html);
            self::assertStringNotContainsString('Addon Modules', $html);
        }

        // Change log: works, with client names, under the banner
        $log = $page->render('m', ['action' => 'log'], [], 'admin:1', $token);
        self::assertStringContainsString('<li>tiers: invalid JSON</li>', $log);
        self::assertStringContainsString('<h3>Change log</h3>', $log);
        self::assertStringContainsString('<td>note_changed</td>', $log);
        self::assertStringContainsString('Agency One', $log);

        // Settings: the form from the stored values, each problem beside its section, nothing saved by a GET
        $settings = $page->render('m', ['action' => 'settings'], [], 'admin:1', $token);
        self::assertStringContainsString('<p>The stored settings have problems, so no discount is applied until they are fixed and saved below.</p><ul><li>tiers: invalid JSON</li><li>product_groups: &quot;premium&quot; is not a number</li></ul>', $settings);
        self::assertStringNotContainsString('The settings were not saved', $settings);
        self::assertStringContainsString('<span class="label label-success">Live</span>', $settings);
        self::assertSame(1, preg_match('#<h4>Discount tiers</h4>.*?<ul><li>tiers: invalid JSON</li></ul></div><p class="text-danger">The stored tiers are not a JSON object, so no tier is shown; saving replaces them. Stored value: <code>\{&quot;ZAR&quot;:\[</code></p>.*?<h4>What counts</h4>.*?<h5>Product groups</h5><div class="alert alert-danger"><ul><li>product_groups: &quot;premium&quot; is not a number</li></ul></div>#s', $settings));
        self::assertStringContainsString('data-agencydiscount-tab="ZAR">ZAR (default currency)</a>', $settings);
        self::assertStringNotContainsString('name="tiers[ZAR][0][above]"', $settings);
        self::assertStringContainsString('name="product_groups[]" value="23" checked>', $settings);
        self::assertStringContainsString('name="product_groups[]" value="1"> Legacy', $settings);
        self::assertStringContainsString('name="addons[]" value="12" checked>', $settings);
        self::assertStringContainsString('name="statuses[]" value="Active" checked>', $settings);
        self::assertStringContainsString('name="activity_log" value="on" checked>', $settings);
        self::assertStringContainsString('name="description" class="form-control" value="Agency discount {percent}% on {count} qualifying {items}"', $settings);
        self::assertSame('23,premium', $this->repo->settings['product_groups']);

        // Saving valid values repairs the configuration: the same request renders the normal page, and so does the next
        $saved = $page->render('m', ['action' => 'settings'], self::validSettingsPost(), 'admin:1', $token);
        self::assertStringContainsString('Settings saved. Changed: tiers, product_groups.', $saved);
        self::assertStringNotContainsString('no discount is applied', $saved);
        self::assertStringContainsString('name="tiers[ZAR][0][above]" value="1000.00"', $saved);
        self::assertSame(Fixtures::settings()['tiers'], $this->repo->settings['tiers']);
        self::assertSame('1,23,3,6', $this->repo->settings['product_groups']);
        $entry = $this->repo->logEntries(null, 1)[0];
        self::assertSame('settings_changed', $entry['event']);
        self::assertSame(['tiers', 'product_groups'], array_keys($entry['detail']['changed']));
        self::assertSame(['tiers: invalid JSON', 'product_groups: "premium" is not a number'], $entry['detail']['repaired']);
        $overview = Page::forStoredSettings($this->repo, new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog())->render('m', [], [], 'admin:1', $token);
        self::assertStringContainsString('Agency One', $overview);
        self::assertStringNotContainsString('alert-danger', $overview);
    }

    public function testRepairPageShowsABadTierCellInItsRowAndRejectsAnInvalidSave(): void
    {
        $token = '<input type="hidden" name="token" value="t">';
        $page = $this->repairPage(['tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":"5k","percent":10}],"EUR":[]}', 'statuses' => ''] + Fixtures::settings());
        $settings = $page->render('m', ['action' => 'settings'], [], 'admin:1', $token);
        self::assertStringContainsString('name="tiers[ZAR][1][above]" value="5k"', $settings);
        self::assertStringContainsString('name="tiers[ZAR][1][percent]" value="10"', $settings);
        self::assertStringContainsString('<li>tiers: ZAR tier 2 &quot;above&quot; must be a number</li>', $settings);
        self::assertStringContainsString('<li>tiers: EUR must list at least one tier</li>', $settings);
        self::assertStringNotContainsString('The stored tiers are not a JSON object', $settings);
        self::assertSame(1, preg_match('#<h4>Counted statuses</h4>.*?<li>statuses: at least one status is required</li>#s', $settings));
        self::assertStringNotContainsString('name="statuses[]" value="Active" checked', $settings);

        // a save that still has a problem keeps what was typed, saves nothing, and stays on the repair page
        $post = self::validSettingsPost();
        $post['tiers']['ZAR'][1]['above'] = 'x';
        $html = $page->render('m', ['action' => 'settings'], $post, 'admin:1', $token);
        self::assertStringContainsString('fixed and saved below.', $html);
        self::assertStringContainsString('The settings were not saved. Fix these and try again:', $html);
        self::assertStringContainsString('name="tiers[ZAR][1][above]" value="x"', $html);
        self::assertSame('', $this->repo->settings['statuses']);

        // a mode switch works on the repair page and the page stays in repair
        $html = $page->render('m', ['action' => 'settings'], ['action' => 'mode', 'dry_run' => 'on'], 'admin:1', $token);
        self::assertStringContainsString('Switched to Dry-run mode.', $html);
        self::assertStringContainsString('<span class="label label-warning">Dry-run</span>', $html);
        self::assertStringContainsString('fixed and saved below.', $html);
        self::assertSame('on', $this->repo->settings['dry_run']);
        self::assertStringContainsString('name="tiers[ZAR][1][above]" value="5k"', $html);

        // a preview on the repair page compares with nothing: the "now" cells are empty
        $preview = $page->render('m', ['action' => 'settings'], ['action' => 'settings_preview'] + self::validSettingsPost(), 'admin:1', $token);
        self::assertStringContainsString('<td>&mdash;</td><td>1 (5%)</td><td>&mdash;</td><td>2026-10-25: ZAR 42.50</td>', $preview);
        self::assertStringContainsString('<tr class="warning">', $preview);
    }

    public function testRepairPageSurvivesStoredCurrencyKeysThatAreNotCodes(): void
    {
        // "123" comes back from json_decode as an int key; a key with quotes must never reach a field name or the row template
        $page = $this->repairPage(['tiers' => '{"123":[{"above":0,"percent":5}],"a\\"],b[c":[{"above":0,"percent":5}],"zar":[{"above":0,"percent":5}]}'] + Fixtures::settings());
        $html = $page->render('m', ['action' => 'settings'], [], 'admin:1', '');
        self::assertStringContainsString('<li>tiers: currency &quot;123&quot; must be a three-letter uppercase code</li>', $html);
        self::assertStringContainsString('data-agencydiscount-tab="123">123 (not a WHMCS currency)</a>', $html);
        self::assertStringContainsString('name="tiers[123][0][above]" value="0"', $html);
        self::assertStringContainsString('data-agencydiscount-tab="zar">zar (not a WHMCS currency)</a>', $html);
        self::assertStringNotContainsString('data-agencydiscount-tab="a', $html, 'a key that is not letters and digits gets no pane');
        self::assertStringNotContainsString('name="tiers[a', $html);
        self::assertStringNotContainsString('data-agencydiscount-add="a', $html);
        self::assertStringContainsString('<li>tiers: currency &quot;a&quot;],b[c&quot; must be a three-letter uppercase code</li>', $html);
        // the open pane is the first with a problem on it
        $page = $this->repairPage(['tiers' => '{"ZAR":[{"above":0,"percent":5}],"EUR":[{"above":"x","percent":5}]}'] + Fixtures::settings());
        $html = $page->render('m', ['action' => 'settings'], [], 'admin:1', '');
        self::assertStringContainsString('<li class="active"><a href="#agencydiscount-tiers-EUR"', $html);
        self::assertStringContainsString('<div class="tab-pane active" id="agencydiscount-tiers-EUR"', $html);
        self::assertStringContainsString('<li><a href="#agencydiscount-tiers-ZAR"', $html);
    }

    public function testStoredSettingsThatParseGiveTheNormalPage(): void
    {
        $html = $this->repairPage(Fixtures::settings())->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('Agency One', $html);
        self::assertStringNotContainsString('alert-danger', $html);
    }

    public function testOverviewShowsStartDate(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('starts 2027-01-01', $html);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $html);
        self::assertSame(1, preg_match('#<tr[^>]*>(?:(?!</tr>).)*userid=500(?:(?!</tr>).)*</tr>#s', $html, $row));
        self::assertStringContainsString('active', $row[0]);
        self::assertStringNotContainsString('starts', $row[0]);
    }

    public function testAgencyDetailShowsStartDate(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('starts 2027-01-01', $html);
    }

    public function testNoClientNameIsEverUnescaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b> & Co';
        foreach ([[], ['action' => 'agency', 'id' => '131'], ['action' => 'log']] as $get) {
            $html = $this->page()->render('m', $get, [], 'admin:1', '');
            self::assertStringNotContainsString('<b>Evil', $html);
        }
        self::assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $this->page()->render('m', [], [], 'admin:1', ''));
    }
}
