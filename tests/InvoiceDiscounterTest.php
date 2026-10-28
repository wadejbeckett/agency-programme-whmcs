<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\DiscountOutcome;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\InvoiceDiscounter;
use AgencyDiscount\Recalculator;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class InvoiceDiscounterTest extends TestCase
{
    private ArrayRepository $repo;
    private RecordingActivityLog $activity;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->activity = new RecordingActivityLog();
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    /** @param array<string,string> $override */
    private function discounter(array $override = []): InvoiceDiscounter
    {
        $config = Config::fromSettings($override + Fixtures::settings());
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-18 02:00:00'));
        return new InvoiceDiscounter($this->repo, $config, new Recalculator($this->repo, $config, $clock, $this->activity), $clock, $this->activity);
    }

    public function testCountedServiceWithNegativeAmountDoesNotStopTheDiscount(): void
    {
        $this->repo->services[1007] = ['clientId' => 131, 'id' => 1007, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'g.example', 'amount' => '-10.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0];
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('595.00', $outcome->base);
        self::assertSame('29.75', $outcome->amount);
        self::assertSame('1002.92', $this->repo->agency(131)['spend'] ?? null);
    }

    public function testAppliesNamedNegativeLineOnQualifyingItemsOnly(): void
    {
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        // 170 + 340 + 85 (addon 51); DNS Only, Other addon, service 1006 (its PromoHosting line is on this invoice), PromoHosting and Item are not counted
        self::assertSame('595.00', $outcome->base);
        self::assertSame('29.75', $outcome->amount);
        self::assertSame('5.00', $outcome->percent);
        self::assertSame(3, $outcome->countedLines);
        self::assertSame(12, $outcome->itemId);
        $line = $this->repo->invoiceItems(9001)[8];
        self::assertSame('AgencyDiscount', $line['type']);
        self::assertSame(131, $line['relId']);
        self::assertSame('-29.75', $line['amount']);
        self::assertSame(0, $line['taxed']);
        self::assertSame('Agency discount 5% on 3 qualifying items', $line['description']);
        self::assertSame('banktransfer', $this->repo->itemPaymentMethods[12], 'the line takes the invoice payment method');
        $record = $this->repo->invoiceRecord(9001);
        self::assertSame(12, $record['itemId'] ?? null);
        self::assertFalse($record['dryRun'] ?? true);
        self::assertSame('595.00', $record['base'] ?? null);
        self::assertSame(1, $record['tierIndex'] ?? null);
        self::assertSame('29.75', $record['amount'] ?? null);
        $log = $this->repo->logEntries(131, 1)[0];
        self::assertSame('applied', $log['event']);
        self::assertSame(9001, $log['invoiceId']);
        self::assertSame('autogen', $log['detail']['source']);
        $messages = array_column($this->activity->entries, 'message');
        self::assertStringContainsString('5% (ZAR 29.75) added to invoice #9001 as line 12', end($messages));
    }

    public function testStackingSettingIncludesPromotedService(): void
    {
        $outcome = $this->discounter(['stack_with_promotions' => 'on'])->apply(9001, 'autogen', 'system');
        self::assertSame('765.00', $outcome->base);
        self::assertSame('38.25', $outcome->amount);
        self::assertSame(4, $outcome->countedLines);
    }

    public function testPromotionIdWithoutPromotionLineOnTheInvoiceIsDiscounted(): void
    {
        // service 1006 still has promoId 8, but the promotion no longer reduces this invoice
        self::assertSame(8, $this->repo->services[1006]['promoId']);
        $this->repo->invoiceItems[9001] = array_values(array_filter($this->repo->invoiceItems[9001], static fn (array $i): bool => $i['type'] !== 'PromoHosting'));
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('765.00', $outcome->base);
        self::assertSame('38.25', $outcome->amount);
        self::assertSame(4, $outcome->countedLines);
    }

    public function testAllTaxedQualifyingLinesGetOneTaxedLine(): void
    {
        foreach (array_keys($this->repo->invoiceItems[9001]) as $i) {
            $this->repo->invoiceItems[9001][$i]['taxed'] = 1;
        }
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame('29.75', $outcome->amount);
        self::assertCount(1, $outcome->lines);
        $items = $this->repo->invoiceItems(9001);
        self::assertCount(9, $items);
        self::assertSame(1, end($items)['taxed']);
        self::assertSame('-29.75', end($items)['amount']);
        $records = $this->repo->invoiceRecordLines(9001);
        self::assertCount(1, $records);
        self::assertTrue($records[0]['taxed']);
        self::assertSame(0, $records[0]['line']);
        $detail = $this->repo->logEntries(131, 1)[0]['detail'];
        self::assertArrayNotHasKey('lines', $detail, 'a single line keeps the log detail shape of earlier releases');
        self::assertSame(12, $detail['itemId']);
        self::assertSame('Agency discount 5% on 3 qualifying items', $detail['description']);
    }

    public function testMixedTaxedAndUntaxedLinesGetOneDiscountLinePerTreatment(): void
    {
        // the 170.00 line of service 1001 is taxed; the 340.00 service line and the 85.00 add-on line are not
        $this->repo->invoiceItems[9001][0]['taxed'] = 1;
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('595.00', $outcome->base);
        self::assertSame('29.75', $outcome->amount);
        self::assertSame(3, $outcome->countedLines);
        self::assertSame(12, $outcome->itemId);
        self::assertCount(2, $outcome->lines);

        $items = $this->repo->invoiceItems(9001);
        self::assertCount(10, $items);
        [$untaxed, $taxed] = array_slice($items, 8);
        self::assertSame(['id' => 12, 'type' => 'AgencyDiscount', 'relId' => 131, 'description' => 'Agency discount 5% on 2 qualifying items', 'amount' => '-21.25', 'taxed' => 0], $untaxed);
        self::assertSame(['id' => 13, 'type' => 'AgencyDiscount', 'relId' => 131, 'description' => 'Agency discount 5% on 1 qualifying item', 'amount' => '-8.50', 'taxed' => 1], $taxed);
        self::assertSame('banktransfer', $this->repo->itemPaymentMethods[13]);

        $records = $this->repo->invoiceRecordLines(9001);
        self::assertCount(2, $records);
        self::assertSame([0, 1], array_column($records, 'line'));
        self::assertSame([12, 13], array_column($records, 'itemId'));
        self::assertSame([false, true], array_column($records, 'taxed'));
        self::assertSame(['425.00', '170.00'], array_column($records, 'base'));
        self::assertSame(['21.25', '8.50'], array_column($records, 'amount'));
        self::assertSame([false, false], array_column($records, 'dryRun'));
        self::assertSame('29.75', $this->repo->appliedDiscountTotal(131, '2026-01-01'));

        $entries = $this->repo->logEntries(131, 10);
        self::assertCount(1, array_filter($entries, static fn (array $e): bool => $e['event'] === 'applied'), 'one applied entry for both lines');
        $detail = $entries[0]['detail'];
        self::assertSame('29.75', $detail['amount']);
        self::assertArrayNotHasKey('itemId', $detail);
        self::assertArrayNotHasKey('description', $detail);
        self::assertSame([
            ['taxed' => 0, 'base' => '425.00', 'amount' => '21.25', 'countedLines' => 2, 'description' => 'Agency discount 5% on 2 qualifying items', 'itemId' => 12],
            ['taxed' => 1, 'base' => '170.00', 'amount' => '8.50', 'countedLines' => 1, 'description' => 'Agency discount 5% on 1 qualifying item', 'itemId' => 13],
        ], $detail['lines']);
        $messages = array_column($this->activity->entries, 'message');
        self::assertStringContainsString('5% (ZAR 29.75) added to invoice #9001 as lines 12 and 13', end($messages));
    }

    public function testMixedInvoiceIsDiscountedOnlyOnce(): void
    {
        $this->repo->invoiceItems[9001][0]['taxed'] = 1;
        $this->discounter()->apply(9001, 'autogen', 'system');
        $again = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $again->status);
        self::assertSame('already_processed', $again->reason);
        self::assertCount(10, $this->repo->invoiceItems(9001));
        self::assertCount(2, $this->repo->invoiceRecordLines(9001));
    }

    public function testMixedInvoiceInDryRunRecordsBothLines(): void
    {
        $this->repo->invoiceItems[9001][0]['taxed'] = 1;
        $outcome = $this->discounter(['dry_run' => 'on'])->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::DRY_RUN, $outcome->status);
        self::assertCount(8, $this->repo->invoiceItems(9001));
        $records = $this->repo->invoiceRecordLines(9001);
        self::assertSame([true, true], array_column($records, 'dryRun'));
        self::assertSame([null, null], array_column($records, 'itemId'));
        self::assertCount(2, $this->repo->logEntries(131, 1)[0]['detail']['lines']);
        self::assertSame('already_processed', $this->discounter()->apply(9001, 'autogen', 'system')->reason);
    }

    public function testMixedInvoiceDropsALineThatRoundsToNothing(): void
    {
        $this->repo->invoiceItems[9001][] = ['id' => 60, 'type' => 'Addon', 'relId' => 51, 'description' => 'tiny taxed', 'amount' => '0.09', 'taxed' => 1];
        $this->repo->invoiceItems[9001] = array_values(array_filter($this->repo->invoiceItems[9001], static fn (array $i): bool => $i['id'] !== 4));
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertCount(1, $outcome->lines, 'the taxed 0.09 line gives 0.00 and gets no discount line');
        self::assertSame('510.00', $outcome->base);
        self::assertSame(2, $outcome->countedLines);
        self::assertSame(0, $outcome->lines[0]['taxed']);
        self::assertCount(1, $this->repo->invoiceRecordLines(9001));
    }

    public function testDryRunRecordsButDoesNotInsert(): void
    {
        $outcome = $this->discounter(['dry_run' => 'on'])->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::DRY_RUN, $outcome->status);
        self::assertSame('29.75', $outcome->amount);
        self::assertNull($outcome->itemId);
        self::assertCount(8, $this->repo->invoiceItems(9001));
        self::assertTrue($this->repo->invoiceRecord(9001)['dryRun'] ?? false);
        self::assertSame('dry_run', $this->repo->logEntries(131, 1)[0]['event']);
        self::assertFalse($this->repo->logEntries(131, 1)[0]['detail']['forced']);
    }

    public function testForcedDryRunLeavesNoRecordSoARealRunCanFollow(): void
    {
        $first = $this->discounter()->apply(9001, 'preview', 'admin:1', true);
        self::assertSame(DiscountOutcome::DRY_RUN, $first->status);
        self::assertNull($this->repo->invoiceRecord(9001));
        self::assertTrue($this->repo->logEntries(131, 1)[0]['detail']['forced']);
        $second = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $second->status);
    }

    public function testSecondApplyToSameInvoiceIsSkipped(): void
    {
        $this->discounter()->apply(9001, 'autogen', 'system');
        $again = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $again->status);
        self::assertSame('already_processed', $again->reason);
        self::assertCount(9, $this->repo->invoiceItems(9001));
        $afterDryRun = $this->discounter(['dry_run' => 'on'])->apply(9001, 'autogen', 'system');
        self::assertSame('already_processed', $afterDryRun->reason);
    }

    public function testInvoiceWithoutQualifyingLinesGetsNoDiscount(): void
    {
        $outcome = $this->discounter()->apply(9002, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('no_qualifying_lines', $outcome->reason);
        self::assertCount(1, $this->repo->invoiceItems(9002));
        self::assertSame('no_qualifying_lines', $this->repo->logEntries(131, 1)[0]['event']);
        self::assertSame(['Domain'], $this->repo->logEntries(131, 1)[0]['detail']['itemTypes']);
        self::assertNull($this->repo->invoiceRecord(9002));
    }

    public function testClientInCurrencyWithoutTiersIsSkipped(): void
    {
        $outcome = $this->discounter()->apply(9003, 'autogen', 'system');
        self::assertSame('no_tier', $outcome->reason);
        self::assertCount(1, $this->repo->invoiceItems(9003));
        $log = $this->repo->logEntries(500, 5);
        self::assertSame('no_tier', $log[0]['event']);
        self::assertSame(9003, $log[0]['invoiceId']);
        self::assertFalse($log[0]['detail']['currencyHasTiers']);
    }

    public function testRetailClientIsSkippedSilently(): void
    {
        $outcome = $this->discounter()->apply(9004, 'autogen', 'system');
        self::assertSame('not_approved', $outcome->reason);
        self::assertSame([], $this->repo->logEntries(600, 5));
        self::assertCount(1, $this->repo->invoiceItems(9004));
    }

    public function testUnknownInvoice(): void
    {
        $outcome = $this->discounter()->apply(1, 'autogen', 'system');
        self::assertSame('invoice_not_found', $outcome->reason);
        self::assertSame('invoice_not_found', $this->repo->logEntries(null, 1)[0]['event']);
        self::assertNull($this->repo->logEntries(null, 1)[0]['clientId']);
    }

    public function testTierIsRecalculatedLiveAtInvoiceTime(): void
    {
        $this->repo->services[2001]['amount'] = '2500.00';
        $this->repo->invoices[9005] = ['id' => 9005, 'clientId' => 404, 'status' => 'Unpaid', 'dueDate' => '2026-10-20'];
        $this->repo->invoiceItems[9005] = [['id' => 90, 'type' => 'Hosting', 'relId' => 2001, 'description' => 'Reseller 1', 'amount' => '2500.00', 'taxed' => 0]];
        $outcome = $this->discounter()->apply(9005, 'autogen', 'system');
        self::assertSame('10.00', $outcome->percent);
        self::assertSame('250.00', $outcome->amount);
        self::assertSame(2, $this->repo->agency(404)['tierIndex'] ?? null);
    }

    public function testBeforeStartDateNoLineIsAdded(): void
    {
        // invoice 9001 is due 2026-10-25, the day before the start date
        $this->repo->agencies[131]['effectiveFrom'] = '2026-10-26';
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('not_yet_effective', $outcome->reason);
        self::assertCount(8, $this->repo->invoiceItems(9001));
        self::assertNull($this->repo->invoiceRecord(9001));
        $log = $this->repo->logEntries(131, 1)[0];
        self::assertSame('not_yet_effective', $log['event']);
        self::assertSame(9001, $log['invoiceId']);
        self::assertSame(['effectiveFrom' => '2026-10-26', 'dueDate' => '2026-10-25', 'today' => '2026-10-18', 'source' => 'autogen'], $log['detail']);
        self::assertSame(1, $this->repo->agency(131)['tierIndex'] ?? null);
    }

    public function testOnStartDateLineIsAdded(): void
    {
        // the clock says 2026-10-18, but the invoice is due on the start date
        $this->repo->agencies[131]['effectiveFrom'] = '2026-10-25';
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
    }

    public function testExistingDiscountLineWithoutRecordIsSkipped(): void
    {
        $this->repo->invoiceItems[9001][] = ['id' => 50, 'type' => 'AgencyDiscount', 'relId' => 131, 'description' => 'x', 'amount' => '-1.00', 'taxed' => 0];
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('already_processed', $outcome->reason);
        self::assertCount(9, $this->repo->invoiceItems(9001));
        self::assertTrue($this->repo->logEntries(131, 1)[0]['detail']['existingLine']);
    }

    public function testLostClaimIsSkipped(): void
    {
        self::assertTrue($this->repo->claimInvoice(9001, 131, '2026-10-18 02:00:00'));
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('already_processed', $outcome->reason);
        self::assertCount(8, $this->repo->invoiceItems(9001));
    }

    public function testLostClaimRaceIsSkipped(): void
    {
        // another request claims the invoice between the record check and the claim
        $racing = new class (Fixtures::data()) extends ArrayRepository {
            public function invoiceRecord(int $invoiceId): ?array
            {
                return null;
            }

            public function claimInvoice(int $invoiceId, int $clientId, string $createdAt): bool
            {
                return false;
            }
        };
        $racing->agencies = $this->repo->agencies;
        $this->repo = $racing;
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('already_processed', $outcome->reason);
        self::assertCount(8, $racing->invoiceItems(9001));
        self::assertSame([], $racing->itemPaymentMethods);
        $log = $racing->logEntries(131, 1)[0];
        self::assertSame('already_processed', $log['event']);
        self::assertSame(['claimed' => false], $log['detail']);
    }

    public function testFailedSecondLineRollsBackTheClaimAndTheFirstLine(): void
    {
        // the insert of the second (taxed) discount line fails after the claim and the first line
        $failing = new class (Fixtures::data()) extends ArrayRepository {
            public int $inserts = 0;
            public bool $fail = true;

            public function addInvoiceItem(int $invoiceId, int $clientId, string $type, int $relId, string $description, string $amount, int $taxed, ?string $dueDate, string $paymentMethod): int
            {
                if ($this->fail && ++$this->inserts === 2) {
                    throw new \RuntimeException('insert failed');
                }
                return parent::addInvoiceItem($invoiceId, $clientId, $type, $relId, $description, $amount, $taxed, $dueDate, $paymentMethod);
            }
        };
        $failing->agencies = $this->repo->agencies;
        $failing->invoiceItems[9001][0]['taxed'] = 1;
        $this->repo = $failing;
        try {
            $this->discounter()->apply(9001, 'autogen', 'system');
            self::fail('the insert failure must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('insert failed', $e->getMessage());
        }
        self::assertCount(8, $failing->invoiceItems(9001), 'the first discount line is rolled back');
        self::assertSame([], $failing->invoiceRecordLines(9001), 'no placeholder claim is left behind');
        self::assertSame([], $failing->itemPaymentMethods);
        self::assertSame([], array_filter($failing->logEntries(131, 10), static fn (array $e): bool => $e['event'] === 'applied'));

        // the invoice is not blocked: a later attempt applies both lines
        $failing->fail = false;
        $outcome = $this->discounter()->apply(9001, 'manual', 'admin:1');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertCount(10, $failing->invoiceItems(9001));
        self::assertCount(2, $failing->invoiceRecordLines(9001));
    }

    public function testFailedRecordSaveRollsBackTheLine(): void
    {
        $failing = new class (Fixtures::data()) extends ArrayRepository {
            public function saveInvoiceRecord(array $row): void
            {
                throw new \RuntimeException('record failed');
            }
        };
        $failing->agencies = $this->repo->agencies;
        $this->repo = $failing;
        try {
            $this->discounter()->apply(9001, 'autogen', 'system');
            self::fail('the record failure must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('record failed', $e->getMessage());
        }
        self::assertCount(8, $failing->invoiceItems(9001));
        self::assertNull($failing->invoiceRecord(9001));
    }

    public function testSubCentDiscountIsSkipped(): void
    {
        $this->repo->invoices[9006] = ['id' => 9006, 'clientId' => 131, 'status' => 'Unpaid', 'dueDate' => '2026-10-27'];
        $this->repo->invoiceItems[9006] = [['id' => 91, 'type' => 'Hosting', 'relId' => 1001, 'description' => 'tiny', 'amount' => '0.09', 'taxed' => 0]];
        $outcome = $this->discounter()->apply(9006, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('zero_amount', $outcome->reason);
        self::assertCount(1, $this->repo->invoiceItems(9006));
        self::assertNull($this->repo->invoiceRecord(9006));
        self::assertSame('zero_amount', $this->repo->logEntries(131, 1)[0]['event']);
    }

    /**
     * A new order of client 131: a pending sPremium-1 service (1000.00 monthly), a pending Advanced Email
     * Security add-on on it (85.00 monthly) and a pending DNS Only service (not a counted product), invoiced
     * together on invoice 9007 while all three are still Pending.
     */
    private function addNewOrder(string $serviceAmount = '1000.00'): void
    {
        $this->repo->services[1007] = ['clientId' => 131, 'id' => 1007, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'g.example', 'amount' => $serviceAmount, 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        $this->repo->services[1008] = ['clientId' => 131, 'id' => 1008, 'productId' => 120, 'groupId' => 15, 'productName' => 'DNS Only', 'domain' => 'h.example', 'amount' => '68.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        $this->repo->addons[56] = ['clientId' => 131, 'id' => 56, 'hostingId' => 1007, 'addonId' => 12, 'addonName' => 'Advanced Email Security', 'domain' => 'g.example', 'recurring' => '85.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18'];
        $this->repo->invoices[9007] = ['id' => 9007, 'clientId' => 131, 'status' => 'Unpaid', 'dueDate' => '2026-10-18', 'paymentMethod' => 'banktransfer'];
        $this->repo->invoiceItems[9007] = [
            ['id' => 92, 'type' => 'Hosting', 'relId' => 1007, 'description' => 'sPremium-1 - g.example', 'amount' => $serviceAmount, 'taxed' => 0],
            ['id' => 93, 'type' => 'Addon', 'relId' => 56, 'description' => 'Addon (g.example) - Advanced Email Security', 'amount' => '85.00', 'taxed' => 0],
            ['id' => 94, 'type' => 'Hosting', 'relId' => 1008, 'description' => 'DNS Only - h.example', 'amount' => '68.00', 'taxed' => 0],
        ];
    }

    public function testNewOrderIsDiscountedAtTheTierItsOwnSpendEarns(): void
    {
        // counted spend 1012.92 (tier 1, 5%) + pending 1000.00 + 85.00 = 2097.92: tier 2, 10%
        $this->addNewOrder();
        $outcome = $this->discounter()->apply(9007, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('1085.00', $outcome->base, 'the pending DNS Only line is not a counted product');
        self::assertSame('10.00', $outcome->percent);
        self::assertSame('108.50', $outcome->amount);
        self::assertSame(2, $outcome->countedLines);
        $items = $this->repo->invoiceItems(9007);
        self::assertCount(4, $items);
        self::assertSame('-108.50', end($items)['amount']);
        self::assertSame('Agency discount 10% on 2 qualifying items', end($items)['description']);
        $record = $this->repo->invoiceRecord(9007);
        self::assertSame(2, $record['tierIndex'] ?? null);
        self::assertSame('1085.00', $record['base'] ?? null);
        self::assertSame(1, $this->repo->agency(131)['tierIndex'] ?? null, 'the stored agency tier still reflects counted spend only');
        $log = $this->repo->logEntries(131, 1)[0];
        self::assertSame('applied', $log['event']);
        self::assertSame(2, $log['detail']['pendingLines']);
        self::assertSame('1085.00', $log['detail']['pendingSpend']);
        self::assertSame(2, $log['detail']['tierIndex']);
    }

    public function testNewOrderThatDoesNotLiftTheTierGetsTheCurrentTier(): void
    {
        // counted spend 1012.92 + pending 500.00 + 85.00 = 1597.92: still tier 1, 5%
        $this->addNewOrder('500.00');
        $outcome = $this->discounter()->apply(9007, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('585.00', $outcome->base);
        self::assertSame('5.00', $outcome->percent);
        self::assertSame('29.25', $outcome->amount);
        self::assertSame('585.00', $this->repo->logEntries(131, 1)[0]['detail']['pendingSpend']);
    }

    public function testNewOrderAloneCanEarnATier(): void
    {
        // client 404 has 6000.00 counted (tier 3); client 600 is retail. Give agency 500 (EUR, no tiers) nothing
        // and build a fresh ZAR agency 700 with no counted spend at all: the first order alone earns tier 2.
        $this->repo->clients[700] = ['id' => 700, 'name' => 'New Agency', 'currency' => 'ZAR', 'currencyId' => 1];
        $this->repo->saveAgency(['clientId' => 700, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'effectiveFrom' => null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        $this->repo->services[7001] = ['clientId' => 700, 'id' => 7001, 'productId' => 108, 'groupId' => 3, 'productName' => 'Reseller 1', 'domain' => 'n.example', 'amount' => '30000.00', 'billingCycle' => 'Annually', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        $this->repo->invoices[9008] = ['id' => 9008, 'clientId' => 700, 'status' => 'Unpaid', 'dueDate' => '2026-10-18', 'paymentMethod' => 'banktransfer'];
        $this->repo->invoiceItems[9008] = [['id' => 95, 'type' => 'Hosting', 'relId' => 7001, 'description' => 'Reseller 1 - n.example', 'amount' => '30000.00', 'taxed' => 0]];
        $outcome = $this->discounter()->apply(9008, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        // 30000 a year is 2500.00 a month: above 2000, tier 2 at 10% of the full annual amount
        self::assertSame('10.00', $outcome->percent);
        self::assertSame('3000.00', $outcome->amount);
        self::assertSame('2500.00', $this->repo->logEntries(700, 1)[0]['detail']['pendingSpend']);
        self::assertNull($this->repo->agency(700)['tierIndex'] ?? null);
    }

    public function testNewOrderIsNotDiscountedWhenTheSettingIsOff(): void
    {
        $this->addNewOrder();
        $outcome = $this->discounter(['new_orders' => ''])->apply(9007, 'autogen', 'system');
        self::assertSame(DiscountOutcome::SKIPPED, $outcome->status);
        self::assertSame('no_qualifying_lines', $outcome->reason);
        self::assertCount(3, $this->repo->invoiceItems(9007));
        self::assertNull($this->repo->invoiceRecord(9007));
    }

    public function testNewOrderBelowTheFirstTierGetsNoTier(): void
    {
        $this->repo->services[2001]['status'] = 'Terminated';
        $this->repo->services[2002] = ['clientId' => 404, 'id' => 2002, 'productId' => 108, 'groupId' => 3, 'productName' => 'Reseller 1', 'domain' => 's.example', 'amount' => '900.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        $this->repo->invoices[9009] = ['id' => 9009, 'clientId' => 404, 'status' => 'Unpaid', 'dueDate' => '2026-10-18', 'paymentMethod' => ''];
        $this->repo->invoiceItems[9009] = [['id' => 96, 'type' => 'Hosting', 'relId' => 2002, 'description' => 'Reseller 1 - s.example', 'amount' => '900.00', 'taxed' => 0]];
        $outcome = $this->discounter()->apply(9009, 'autogen', 'system');
        self::assertSame('no_tier', $outcome->reason);
        $log = $this->repo->logEntries(404, 1)[0];
        self::assertSame('no_tier', $log['event']);
        self::assertSame('0.00', $log['detail']['spend']);
        self::assertSame('900.00', $log['detail']['pendingSpend']);
    }

    public function testPendingLineWithAPromotionLineIsExcludedButStillCountsTowardTheTier(): void
    {
        $this->addNewOrder();
        $this->repo->invoiceItems[9007][] = ['id' => 97, 'type' => 'PromoHosting', 'relId' => 1007, 'description' => 'Promo', 'amount' => '-100.00', 'taxed' => 0];
        $outcome = $this->discounter()->apply(9007, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('85.00', $outcome->base, 'only the pending add-on line is discounted');
        self::assertSame('10.00', $outcome->percent, 'the promoted pending service still lifts the tier');
        self::assertSame('8.50', $outcome->amount);
        self::assertSame(1, $outcome->countedLines);
        $detail = $this->repo->logEntries(131, 1)[0]['detail'];
        self::assertSame(1, $detail['pendingLines']);
        self::assertSame('1085.00', $detail['pendingSpend']);

        $this->repo->invoiceRecords = [];
        $this->repo->invoiceItems[9007] = array_slice($this->repo->invoiceItems[9007], 0, 4);
        $stacked = $this->discounter(['stack_with_promotions' => 'on'])->apply(9007, 'autogen', 'system');
        self::assertSame('1085.00', $stacked->base);
    }

    public function testPendingStatusInCountedStatusesTakesTheOrdinaryRouteWithoutDoubleCounting(): void
    {
        $this->addNewOrder();
        $outcome = $this->discounter(['statuses' => 'Active,Pending'])->apply(9007, 'autogen', 'system');
        self::assertSame(DiscountOutcome::APPLIED, $outcome->status);
        self::assertSame('1085.00', $outcome->base);
        self::assertSame('10.00', $outcome->percent);
        self::assertSame('108.50', $outcome->amount);
        self::assertSame(2, $outcome->countedLines);
        $detail = $this->repo->logEntries(131, 1)[0]['detail'];
        self::assertSame(0, $detail['pendingLines']);
        self::assertSame('0.00', $detail['pendingSpend']);
        self::assertSame(2, $this->repo->agency(131)['tierIndex'] ?? null, 'counted pending spend is stored on the agency');
    }

    public function testRenewalInvoiceIsUnchangedByTheNewOrdersSetting(): void
    {
        $this->addNewOrder();
        $outcome = $this->discounter()->apply(9001, 'autogen', 'system');
        self::assertSame('595.00', $outcome->base);
        self::assertSame('5.00', $outcome->percent, 'pending items not on this invoice do not lift its tier');
        $detail = $this->repo->logEntries(131, 1)[0]['detail'];
        self::assertSame(0, $detail['pendingLines']);
        self::assertSame('0.00', $detail['pendingSpend']);
    }

    public function testStartDateAsDatetimeStillAppliesOnThatDay(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2026-10-25 00:00:00';
        self::assertSame(DiscountOutcome::APPLIED, $this->discounter()->apply(9001, 'autogen', 'system')->status);
    }

    public function testStartDateFallsBackToTodayWithoutDueDate(): void
    {
        $this->repo->invoices[9001]['dueDate'] = null;
        $this->repo->agencies[131]['effectiveFrom'] = '2026-10-19';
        self::assertSame('not_yet_effective', $this->discounter()->apply(9001, 'autogen', 'system')->reason);
        self::assertNull($this->repo->logEntries(131, 1)[0]['detail']['dueDate']);
        $this->repo->agencies[131]['effectiveFrom'] = '2026-10-18';
        self::assertSame(DiscountOutcome::APPLIED, $this->discounter()->apply(9001, 'autogen', 'system')->status);
    }
}
