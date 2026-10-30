<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

use AgencyDiscount\Admin\Html;

/**
 * Turns a change-log row into one plain sentence for the overview's recent activity and the
 * change log tab. Plain text: the caller escapes it.
 *
 * @phpstan-type Entry array{id:int, clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}
 */
final class LogEntry
{
    /** Every event the module writes, in the order the Change log's event filter offers them. */
    public const EVENTS = ['approved', 'revoked', 'note_changed', 'effective_from_changed', 'effective_from_invalid', 'tier_changed', 'no_tier', 'applied', 'dry_run', 'already_processed', 'not_yet_effective', 'no_qualifying_lines', 'zero_amount', 'invoice_not_found', 'client_missing', 'recalculate_failed', 'settings_changed', 'mode_changed', 'log_pruned'];

    /**
     * @param Entry $entry
     * @param array<int, array{name:string, currency:string}> $clients known client names and currencies by id; an unknown client is "client N"
     */
    public static function sentence(array $entry, array $clients = []): string
    {
        $d = $entry['detail'];
        $clientId = $entry['clientId'];
        $client = $clientId !== null && isset($clients[$clientId]) ? $clients[$clientId]['name'] : 'client ' . ($clientId ?? '?');
        $currency = $clientId !== null && isset($clients[$clientId]) ? $clients[$clientId]['currency'] : self::str($d['currency'] ?? '');
        $invoice = 'invoice #' . ($entry['invoiceId'] ?? '?');
        $actor = $entry['actor'];

        return match ($entry['event']) {
            'approved' => ucfirst($client) . ' approved as an agency by ' . $actor . (self::str($d['note'] ?? '') === '' ? '' : ' (note: "' . self::str($d['note']) . '")') . '.',
            'revoked' => ucfirst($client) . '\'s agency approval revoked by ' . $actor . '.',
            'note_changed' => 'Note for ' . $client . ' changed to "' . self::str($d['to'] ?? '') . '".',
            'effective_from_changed' => 'Discount start date for ' . $client . (($d['to'] ?? null) === null ? ' cleared.' : ' set to ' . self::str($d['to']) . '.'),
            'effective_from_invalid' => 'Invalid discount start date "' . self::str($d['value'] ?? '') . '" for ' . $client . ' ignored.',
            'tier_changed' => ucfirst($client) . ' moved from ' . self::tier($d['from'] ?? null, $d['fromPercent'] ?? null) . ' to ' . self::tier($d['to'] ?? null, $d['toPercent'] ?? null)
                . ' on monthly spend ' . self::money(self::str($d['currency'] ?? $currency), self::str($d['spend'] ?? '0')) . '.',
            'no_tier' => ucfirst($client) . ' has no tier: no tiers are configured for ' . self::str($d['currency'] ?? $currency)
                . ' (monthly spend ' . self::money(self::str($d['currency'] ?? $currency), self::str($d['spend'] ?? '0')) . ').',
            'applied' => 'Discount of ' . self::money($currency, self::str($d['amount'] ?? '0')) . ' (' . self::percent($d['percent'] ?? null) . ') added to ' . $invoice . ' for ' . $client . self::split($d, $currency) . '.',
            'dry_run' => 'Dry run: ' . self::money($currency, self::str($d['amount'] ?? '0')) . ' (' . self::percent($d['percent'] ?? null) . ') would have come off ' . $invoice . ' for ' . $client . self::split($d, $currency) . '.',
            'already_processed' => ucfirst($invoice) . ' for ' . $client . ' was already handled; nothing changed.',
            'not_yet_effective' => ucfirst($invoice) . ' for ' . $client . ' is due before the discount start date ' . self::str($d['effectiveFrom'] ?? '') . '; no discount.',
            'no_qualifying_lines' => ucfirst($invoice) . ' for ' . $client . ' had no discountable lines.',
            'zero_amount' => 'Discount on ' . $invoice . ' for ' . $client . ' rounded to nothing.',
            'invoice_not_found' => ucfirst($invoice) . ' was not found.',
            'client_missing' => ucfirst($client) . ' no longer exists in WHMCS.',
            'recalculate_failed' => 'Recalculating ' . $client . ' failed: ' . self::str($d['message'] ?? '') . '.',
            'settings_changed' => 'Settings changed by ' . $actor . ': ' . implode(', ', array_map('strval', array_keys(is_array($d['changed'] ?? null) ? $d['changed'] : []))) . '.',
            'mode_changed' => 'Switched to ' . self::str($d['to'] ?? '') . ' mode by ' . $actor . '.',
            'log_pruned' => self::str($d['count'] ?? '0') . ' change log entries older than ' . substr(self::str($d['before'] ?? ''), 0, 10) . ' removed (retention ' . self::str($d['months'] ?? '?') . ' months).',
            default => $entry['event'] . ' for ' . $client . ' by ' . $actor . ': ' . self::json($d),
        };
    }

    /**
     * ", as 2 lines (untaxed X, taxed Y)" when an invoice mixing tax treatments got one line per
     * treatment; '' for single-line entries, whose detail has no lines array.
     *
     * @param array<string,mixed> $d
     */
    private static function split(array $d, string $currency): string
    {
        $lines = $d['lines'] ?? null;
        if (!is_array($lines) || count($lines) < 2) {
            return '';
        }
        $parts = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $parts[] = (($line['taxed'] ?? 0) === 1 ? 'taxed ' : 'untaxed ') . self::money($currency, self::str($line['amount'] ?? '0'));
            }
        }
        return ', as ' . count($lines) . ' lines (' . implode(', ', $parts) . ')';
    }

    private static function tier(mixed $index, mixed $percent): string
    {
        if ($index === null) {
            return 'no tier';
        }
        return 'tier ' . self::str($index) . ($percent === null ? '' : ' (' . self::percent($percent) . ')');
    }

    private static function percent(mixed $value): string
    {
        return (is_numeric($value) ? Money::formatPercent((string) $value) : '0') . '%';
    }

    private static function money(string $currency, string $amount): string
    {
        return Html::money($currency, is_numeric($amount) ? $amount : '0');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string,mixed> $detail */
    private static function json(array $detail): string
    {
        $json = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{}' : $json;
    }
}
