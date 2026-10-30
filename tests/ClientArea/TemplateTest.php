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

    public function testAClientOutsideTheProgrammeGetsOneLine(): void
    {
        $html = ClientAreaTemplate::render(PageData::build($this->module(), null));
        self::assertStringContainsString('<div class="alert alert-info">This account is not part of the agency programme.</div>', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testTheTemplateUsesOnlyTheAllowedTagsAndModifiers(): void
    {
        $template = (string) file_get_contents(ClientAreaTemplate::templateDir() . '/clientarea.tpl');
        $source = (string) preg_replace('/\\{\\*.*?\\*\\}/s', '', $template);
        preg_match_all('/\\{(\\/?)([a-z]+)\\b/', $source, $tags);
        $used = array_values(array_unique($tags[2]));
        self::assertSame([], array_diff($used, ClientAreaTemplate::ALLOWED_TAGS), 'tags used by the template: ' . implode(', ', $used));
        preg_match_all('/\\|([a-z_]+)/', $source, $modifiers);
        self::assertSame([], array_diff(array_unique($modifiers[1]), ClientAreaTemplate::ALLOWED_MODIFIERS));
    }
}
