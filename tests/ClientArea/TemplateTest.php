<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\ClientArea;

use AgencyDiscount\ClientArea\PageData;
use AgencyDiscount\ClientAreaTemplate;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The client-area template rendered by Smarty 4 (the major version WHMCS 9 bundles) under a policy
 * that allows only the escape modifier and the if and foreach tags: narrower than WHMCS's, so a
 * template that renders here renders under WHMCS.
 */
final class TemplateTest extends TestCase
{
    private function module(): Module
    {
        $repo = Fixtures::agencyRepo();
        $repo->saveAgency(['clientId' => 131, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        $repo->clients[131]['name'] = '<b>Evil</b> & Co';
        return new Module($repo, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    public function testApprovedAgencyPageRendersUnderTheNarrowPolicy(): void
    {
        $html = ClientAreaTemplate::render(PageData::build($this->module(), 131));
        self::assertStringContainsString('<div class="agencydiscount-clientarea">', $html);
        self::assertStringContainsString('Tier 1 (5% off)', $html);
        self::assertStringContainsString('ZAR 1,012.92', $html);
        self::assertStringContainsString('<tr class="table-success">', $html);
        self::assertStringContainsString('You are here', $html);
        self::assertStringContainsString('Edge Security (WAF, CDN &amp; DDoS)', $html);
        self::assertStringNotContainsString('<b>Evil', $html);
        self::assertStringNotContainsString('{$', $html, 'every variable rendered');
        self::assertStringNotContainsString('{if', $html);
    }

    public function testAnAgencyWaitingForItsStartDate(): void
    {
        $module = $this->module();
        $module->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = ClientAreaTemplate::render(PageData::build($module, 131));
        self::assertStringContainsString('<div class="alert alert-info">Your discount starts on 2027-01-01: invoices due before that date are not discounted.</div>', $html);
        self::assertStringContainsString('No discount on the 2026-10-25 invoice of ZAR 850.00', $html);
        self::assertStringContainsString('Tier 1 (5% off)', $html, 'the tier is shown while the discount waits');
    }

    public function testAClientOutsideTheProgrammeGetsOneLine(): void
    {
        $html = ClientAreaTemplate::render(PageData::build($this->module(), null));
        self::assertStringContainsString('<div class="alert alert-info">This account is not part of the agency programme.</div>', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    /**
     * Smarty's policy covers plugin tags and modifiers, PHP functions and static classes; its own
     * syntax (assignment, literal and strip blocks, nofilter, object calls) it does not. So every
     * {...} block of the template is checked here: a tag must be if/foreach (or their else and
     * closing forms), a modifier must be escape, and no block may call or assign anything.
     */
    public function testTheTemplateUsesOnlyTheAllowedTagsAndModifiers(): void
    {
        $template = (string) file_get_contents(ClientAreaTemplate::templateDir() . '/clientarea.tpl');
        $source = (string) preg_replace('/\{\*.*?\*\}/s', '', $template);
        preg_match_all('/\{[^{}]*\}/', $source, $blocks);
        self::assertNotSame([], $blocks[0]);
        $tags = [];
        $modifiers = [];
        foreach ($blocks[0] as $block) {
            if (preg_match('/^\{\/?([a-z_]+)\b/', $block, $m) === 1) {
                $tags[] = $m[1];
            } elseif (preg_match('/^\{\$[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)*\|escape\}$/', $block) !== 1) {
                self::fail('not an escaped variable output: ' . $block);
            }
            preg_match_all('/\|([a-z_]+)/', $block, $m);
            array_push($modifiers, ...$m[1]);
            self::assertDoesNotMatchRegularExpression(self::CALL_OR_ASSIGNMENT, $block, 'a call, assignment or filter switch in ' . $block);
        }
        $used = array_values(array_unique($tags));
        self::assertSame([], array_diff($used, ClientAreaTemplate::ALLOWED_TAGS), 'tags used by the template: ' . implode(', ', $used));
        self::assertSame(['escape'], array_values(array_unique($modifiers)));
    }

    /** What the scan above rejects inside a block: a call, a static or object access, an assignment or a filter switch. */
    private const CALL_OR_ASSIGNMENT = '/::|->|\(|\bnofilter\b|(?<![=!<>])=(?!=)/';

    public function testTheScanCatchesWhatThePolicyWouldNot(): void
    {
        foreach (['{$z=5}', '{$x|escape nofilter}', '{if count($a) > 1}', '{Foo::bar()}', '{$o->m()}', '{assign var=x value=1}'] as $block) {
            self::assertMatchesRegularExpression(self::CALL_OR_ASSIGNMENT, $block, $block);
        }
        foreach (['{if $itemCount == 0}', '{if $a != $b}', '{if $a >= 1}', '{$text.tier|escape}', '{foreach $tiers as $row}'] as $block) {
            self::assertDoesNotMatchRegularExpression(self::CALL_OR_ASSIGNMENT, $block, $block);
        }
    }
}
