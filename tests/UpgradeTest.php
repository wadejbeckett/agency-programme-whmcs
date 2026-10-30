<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\Module;
use AgencyDiscount\Schema;
use AgencyDiscount\Upgrade;
use PHPUnit\Framework\TestCase;

final class UpgradeTest extends TestCase
{
    private const RESHAPED = 'mod_agencydiscount_invoices now holds one row per discount line (columns id, line and taxed added)';
    private const TAXED = 'records written before 0.2.1 took the taxed flag of their invoice line';
    private const INDEXED = 'index idx_client (client_id) added to mod_agencydiscount_invoices';
    private const RETIRED = 'the unread keep_data setting row deleted (replaced by delete_data in 0.2.1)';
    private const MOVED = 'tiers, qualifying items, statuses, mode, behaviour and invoice line text are now edited on the Settings tab only (the Configure form keeps the access list and the housekeeping settings); every stored value is kept';
    private const RETEXTED = 'invoice line text changed from the old default "Agency discount {percent}% on {count} qualifying items" to "Agency discount {percent}% on {count} qualifying {items}"';

    /** @return array<string,string> a full 0.2.x stored set with the retired row and the old default text */
    private static function stored(): array
    {
        return [
            'tiers' => '{"ZAR":[{"above":0,"percent":5}]}', 'product_groups' => '1,23', 'products' => '', 'addons' => '12', 'statuses' => 'Active',
            'dry_run' => '', 'stack_with_promotions' => 'on', 'new_orders' => 'on', 'description' => Config::OLD_DEFAULT_DESCRIPTION,
            'activity_log' => 'on', 'log_retention_months' => '24', 'delete_data' => '', 'keep_data' => '', 'access' => '1', 'version' => '0.2.0',
        ];
    }

    public function testFrom020ReshapesIndexesRetiresAndRetexts(): void
    {
        $repo = new ArrayRepository(['settings' => self::stored()]);
        $changes = Upgrade::run($repo, '0.2.0');
        self::assertSame([self::RESHAPED, self::TAXED, self::INDEXED, self::RETIRED, self::RETEXTED], $changes);
        self::assertSame([self::MOVED], Upgrade::notes('0.2.0'));
        self::assertSame(Schema::upgradeStatements('0.2.0'), $repo->executed);
        self::assertSame(Schema::INVOICES_0_3_0_INDEX, $repo->executed[2]);
        $expected = self::stored();
        unset($expected['keep_data']);
        $expected['description'] = Config::DEFAULT_DESCRIPTION;
        self::assertSame($expected, $repo->settings, 'every other value is kept');
    }

    public function testFromBelow030ALoneZeroIsDroppedFromTheIdLists(): void
    {
        $stored = ['product_groups' => '0', 'products' => ' 0, 113 ,00', 'addons' => '12,15'] + self::stored();
        $repo = new ArrayRepository(['settings' => $stored]);
        $changes = Upgrade::run($repo, '0.2.2');
        self::assertContains('a "0" removed from product_groups, products (it matched nothing; 0.3.0 would reject it)', $changes);
        self::assertSame(['', '113', '12,15'], [$repo->settings['product_groups'], $repo->settings['products'], $repo->settings['addons']]);
        self::assertSame([], Config::fromSettings($repo->settings)->missingKeys, 'the cleaned rows still exist');
        self::assertSame([113], Config::fromSettings($repo->settings)->productIds, 'the cleaned settings parse');
        // a second run changes nothing
        self::assertStringNotContainsString('a "0" removed', implode(' ', Upgrade::run($repo, '0.2.2')));
        // lists without a zero are untouched
        $clean = new ArrayRepository(['settings' => self::stored()]);
        Upgrade::run($clean, '0.2.2');
        self::assertSame(self::stored()['products'], $clean->settings['products']);
    }

    public function testFrom021And022AddTheIndexAndDeleteTheRetiredRow(): void
    {
        foreach (['0.2.1', '0.2.2'] as $from) {
            $stored = ['description' => 'Custom {percent}% text'] + self::stored();
            $repo = new ArrayRepository(['settings' => $stored]);
            self::assertSame([self::INDEXED, self::RETIRED], Upgrade::run($repo, $from), $from);
            self::assertSame([self::MOVED], Upgrade::notes($from), $from);
            self::assertSame([Schema::INVOICES_0_3_0_INDEX], $repo->executed, $from);
            unset($stored['keep_data']);
            self::assertSame($stored, $repo->settings, $from . ': the custom text and every moved value are kept');
        }
    }

    public function testWithoutTheRetiredRowOnlyTheIndexAndTheNoteAreReported(): void
    {
        $stored = self::stored();
        unset($stored['keep_data']);
        $repo = new ArrayRepository(['settings' => $stored]);
        self::assertSame([self::INDEXED], Upgrade::run($repo, '0.2.2'));
    }

    public function testARepeatedUpgradeFromTheSameVersionAddsNoIndexAndRaisesNothing(): void
    {
        $repo = new ArrayRepository(['settings' => self::stored()]);
        Upgrade::run($repo, '0.2.2');
        self::assertTrue($repo->hasIndex('mod_agencydiscount_invoices', 'idx_client'));
        $again = Upgrade::run($repo, '0.2.2');
        self::assertSame([], $again, 'no index change and no retired row the second time');
        self::assertSame([self::MOVED], Upgrade::notes('0.2.2'), 'the note is still given, not counted as a change');
        self::assertCount(1, $repo->executed, 'the ALTER TABLE ran once');

        // a table that already has the index (a fresh 0.3.0 table reported as 0.2.2 by a stale version row) gets no statement
        $fresh = new ArrayRepository(['settings' => self::stored()]);
        $fresh->indexes = ['mod_agencydiscount_invoices.idx_client'];
        self::assertSame([self::RETIRED], Upgrade::run($fresh, '0.2.2'));
        self::assertSame([], $fresh->executed);

        // from 0.2.0 after a partial failure (the reshape committed, the rest did not): the reshape is skipped, the rest runs
        $partial = new ArrayRepository(['settings' => self::stored()]);
        $partial->columns = ['mod_agencydiscount_invoices.line'];
        self::assertSame([self::TAXED, self::INDEXED, self::RETIRED, self::RETEXTED], Upgrade::run($partial, '0.2.0'));
        self::assertSame([Schema::upgradeStatements('0.2.0')[1], Schema::INVOICES_0_3_0_INDEX], $partial->executed);
        // and a full repeat from 0.2.0 re-runs only the backfill UPDATE, which is safe to repeat
        $full = new ArrayRepository(['settings' => self::stored()]);
        Upgrade::run($full, '0.2.0');
        self::assertSame([self::TAXED], Upgrade::run($full, '0.2.0'));
        self::assertCount(4, $full->executed);
    }

    public function testACustomisedTextIsKept(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => 'Partner rebate {percent}% ({count} items)']]);
        $changes = Upgrade::run($repo, '0.2.0');
        self::assertSame('Partner rebate {percent}% ({count} items)', $repo->settings['description']);
        self::assertSame([self::RESHAPED, self::TAXED, self::INDEXED], $changes);
    }

    public function testNoStoredTextIsLeftToTheDefault(): void
    {
        $repo = new ArrayRepository();
        Upgrade::run($repo, '0.1.0');
        self::assertSame([], $repo->settings);
        self::assertSame(Config::DEFAULT_DESCRIPTION, Config::defaults()['description']);
    }

    public function testFromTheCurrentVersionNothingChanges(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => Config::OLD_DEFAULT_DESCRIPTION, 'keep_data' => '']]);
        self::assertSame([], Schema::upgradeSteps(Module::VERSION));
        self::assertSame([], Upgrade::run($repo, Module::VERSION));
        self::assertSame([], Upgrade::notes(Module::VERSION));
        self::assertSame([], $repo->executed);
        self::assertSame(['description' => Config::OLD_DEFAULT_DESCRIPTION, 'keep_data' => ''], $repo->settings);
    }

    public function testTheFreshTableCarriesTheClientIndex(): void
    {
        self::assertStringContainsString('KEY idx_client (client_id)', Schema::createStatements()[2]);
    }

    public function testAnUnknownVersionChangesNothing(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => Config::OLD_DEFAULT_DESCRIPTION]]);
        try {
            Upgrade::run($repo, 'unknown');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"unknown" is not a version number', $e->getMessage());
        }
        self::assertSame([], $repo->executed);
        self::assertSame(Config::OLD_DEFAULT_DESCRIPTION, $repo->settings['description']);
    }
}
