<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

use AgencyDiscount\Admin\Preview;

/**
 * bin/agency-discount: rehearses the module against a database snapshot
 * (tools/snapshot.sh) with an in-memory repository. Nothing is ever written
 * to a database; invoice runs are forced dry-runs.
 */
final class Cli
{
    private const USAGE = [
        'Usage:',
        '  agency-discount rehearse --snapshot DIR --settings FILE [--approve ID,ID] [--days N] [--invoice N] [--today YYYY-MM-DD]',
        '  agency-discount help',
        '',
        '  --snapshot DIR   JSON table exports written by tools/snapshot.sh',
        '  --settings FILE  JSON object of addon settings (the keys of the module configuration)',
        '  --approve IDS    client ids to treat as approved agencies, comma-separated',
        '  --days N         how far ahead to list upcoming invoices (default 35)',
        '  --invoice N      also run the discount on invoice N, always as a dry run',
        '  --today DATE     the day the rehearsal runs on (default today)',
    ];

    private const OPTIONS = ['snapshot', 'settings', 'approve', 'days', 'invoice', 'today'];

    /**
     * @param list<string> $argv the program name, then the command and its options
     * @param callable(string): void $write receives one output line without a trailing newline
     * @return int exit code: 0 success, 1 configuration or snapshot problem, 2 usage error
     */
    public static function run(array $argv, callable $write): int
    {
        $command = $argv[1] ?? null;
        if ($command === 'help' || $command === '--help' || $command === '-h') {
            self::usage($write);
            return 0;
        }
        if ($command !== 'rehearse') {
            return self::usageError($write, $command === null ? 'no command given' : 'unknown command "' . $command . '"');
        }

        $options = self::options(array_slice($argv, 2));
        if (is_string($options)) {
            return self::usageError($write, $options);
        }
        return self::rehearse($options, $write);
    }

    /**
     * @param array{snapshot:string, settings:array<string,mixed>, approve:list<int>, days:int, invoice:?int, today:\DateTimeImmutable} $o
     * @param callable(string): void $write
     */
    private static function rehearse(array $o, callable $write): int
    {
        try {
            $config = Config::fromSettings($o['settings']);
        } catch (ConfigException $e) {
            foreach ($e->problems() as $problem) {
                $write($problem);
            }
            return 1;
        }
        try {
            $repo = SnapshotLoader::load($o['snapshot']);
        } catch (\RuntimeException $e) {
            $write($e->getMessage());
            return 1;
        }

        $today = $o['today'];
        $now = new \DateTimeImmutable($today->format('Y-m-d') . ' ' . date('H:i:s'));
        $module = new Module($repo, $config, new FrozenClock($now), new NullActivityLog());

        $write(sprintf(
            'Rehearsal on %s, upcoming invoices to %s. Dry run: nothing is written.',
            $today->format('Y-m-d'),
            $today->modify('+' . $o['days'] . ' days')->format('Y-m-d'),
        ));

        if ($o['approve'] === []) {
            $write('No clients approved: pass --approve ID,ID to rehearse agencies.');
        }
        foreach ($o['approve'] as $clientId) {
            if ($repo->client($clientId) === null) {
                $write('Client ' . $clientId . ': not found in snapshot');
                continue;
            }
            $repo->saveAgency([
                'clientId' => $clientId,
                'approved' => true,
                'approvedBy' => 'cli',
                'approvedAt' => $now->format('Y-m-d H:i:s'),
                'updatedAt' => $now->format('Y-m-d H:i:s'),
            ]);
            $report = $module->recalculator()->recalculate($clientId, 'cli');
            $write(self::clientLine($report));
            foreach (Preview::upcoming($report, $today, $o['days']) as $row) {
                $write(sprintf('  %s: %d items, base %s, discount %s', $row['date'], $row['items'], self::amount($row['base']), self::amount($row['discount'])));
            }
        }

        if ($o['invoice'] !== null) {
            $invoiceId = $o['invoice'];
            try {
                $outcome = $module->discounter()->apply($invoiceId, 'cli', 'cli', true);
            } catch (ClientNotFound $e) {
                $write('Invoice ' . $invoiceId . ': ' . $e->getMessage() . ' in snapshot');
                return 1;
            }
            if ($outcome->status === DiscountOutcome::SKIPPED) {
                $write(sprintf('Invoice %d: skipped (%s)', $invoiceId, $outcome->reason));
            } else {
                $percent = Money::formatPercent((string) $outcome->percent);
                $summary = sprintf(
                    'Invoice %d: %s - %s%% of %s = %s on %d lines',
                    $invoiceId,
                    $outcome->status,
                    $percent,
                    self::amount((string) $outcome->base),
                    self::amount((string) $outcome->amount),
                    $outcome->countedLines,
                );
                if (count($outcome->lines) > 1) {
                    // A mix of taxed and untaxed qualifying lines gets one discount line per tax treatment.
                    $write($summary . ', as ' . count($outcome->lines) . ' discount lines, one per tax treatment');
                    foreach ($outcome->lines as $line) {
                        $write(sprintf(
                            '  %s: %s of %s on %d lines, line text "%s"',
                            $line['taxed'] === 1 ? 'taxed' : 'untaxed',
                            self::amount($line['amount']),
                            self::amount($line['base']),
                            $line['countedLines'],
                            $line['description'],
                        ));
                    }
                } else {
                    $write($summary . ', line text "' . ($outcome->lines[0]['description'] ?? $config->description($percent, $outcome->countedLines)) . '"');
                }
            }
        }
        return 0;
    }

    private static function clientLine(SpendReport $report): string
    {
        $line = sprintf(
            'Client %d %s (%s): qualifying monthly spend %s, ',
            $report->clientId,
            $report->clientName,
            $report->currency,
            self::amount($report->monthlySpend),
        );
        $counts = sprintf('%d services + %d add-ons', count($report->services), count($report->addons));
        if (!$report->currencyHasTiers) {
            return $line . 'no tiers configured for ' . ($report->currency === '' ? 'its currency' : $report->currency) . ', ' . $counts;
        }
        $tier = $report->tier === null
            ? 'no tier'
            : 'tier ' . $report->tier->index . ' (' . Money::formatPercent($report->tier->percent) . '%)';
        $shortfall = $report->shortfallToNextTier();
        return $line . $tier . ', ' . $counts . ', ' . ($shortfall === null ? 'top tier' : 'to next tier ' . self::amount($shortfall));
    }

    /**
     * @param list<string> $args
     * @return array{snapshot:string, settings:array<string,mixed>, approve:list<int>, days:int, invoice:?int, today:\DateTimeImmutable}|string the options, or what is wrong with them
     */
    private static function options(array $args): array|string
    {
        $raw = [];
        for ($i = 0; $i < count($args); $i += 2) {
            $name = $args[$i];
            if (!str_starts_with($name, '--') || !in_array(substr($name, 2), self::OPTIONS, true)) {
                return 'unknown option "' . $name . '"';
            }
            if (!isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--')) {
                return $name . ' needs a value';
            }
            $raw[substr($name, 2)] = $args[$i + 1];
        }

        if (!isset($raw['snapshot'], $raw['settings'])) {
            return '--snapshot and --settings are required';
        }
        if (!is_dir($raw['snapshot'])) {
            return '--snapshot: ' . $raw['snapshot'] . ' is not a directory';
        }
        $settings = self::settings($raw['settings']);
        if (is_string($settings)) {
            return $settings;
        }

        $approve = [];
        foreach (explode(',', $raw['approve'] ?? '') as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
                return '--approve: "' . $id . '" is not a client id';
            }
            $approve[] = (int) $id;
        }

        $days = $raw['days'] ?? '35';
        if (preg_match('/^\d{1,4}$/', $days) !== 1) {
            return '--days must be a whole number of days';
        }

        $invoice = $raw['invoice'] ?? null;
        if ($invoice !== null && preg_match('/^[1-9]\d*$/', $invoice) !== 1) {
            return '--invoice must be an invoice id';
        }

        $todayText = $raw['today'] ?? date('Y-m-d');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $todayText, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '--today must be a date as YYYY-MM-DD';
        }

        return [
            'snapshot' => $raw['snapshot'],
            'settings' => $settings,
            'approve' => array_values(array_unique($approve)),
            'days' => (int) $days,
            'invoice' => $invoice === null ? null : (int) $invoice,
            'today' => new \DateTimeImmutable($todayText . ' 00:00:00'),
        ];
    }

    /**
     * Reads the settings file: a JSON object of setting => value. A tiers value given
     * as a JSON object instead of a string is accepted and re-encoded.
     *
     * @return array<string,mixed>|string the settings, or what is wrong with the file
     */
    private static function settings(string $file): array|string
    {
        $json = is_file($file) ? file_get_contents($file) : false;
        if ($json === false) {
            return '--settings: cannot read ' . $file;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return '--settings: ' . $file . ' must contain a JSON object of setting => value';
        }
        $settings = [];
        foreach ($data as $key => $value) {
            $settings[(string) $key] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }
        return $settings;
    }

    /** Two-place amount with thousands separators, from a decimal string (no floats). */
    private static function amount(string $value): string
    {
        $value = bcadd($value, '0', 2);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = explode('.', ltrim($value, '-'));
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));
        return ($negative ? '-' : '') . $grouped . '.' . $fraction;
    }

    /** @param callable(string): void $write */
    private static function usage(callable $write): void
    {
        foreach (self::USAGE as $line) {
            $write($line);
        }
    }

    /** @param callable(string): void $write */
    private static function usageError(callable $write, string $problem): int
    {
        $write('agency-discount: ' . $problem);
        self::usage($write);
        return 2;
    }
}
