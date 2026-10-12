<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

use AgencyDiscount\Admin\Html;

/**
 * The "Agency discount" field on the admin client profile: approval, note,
 * start date and a one-line summary of the stored spend and tier.
 *
 * @phpstan-import-type AgencyRow from Repository
 */
final class ProfileTab
{
    public const LABEL = 'Agency discount';
    public const FIELD_PRESENT = 'agencydiscount_present';
    public const FIELD_APPROVED = 'agencydiscount_approved';
    public const FIELD_NOTE = 'agencydiscount_note';
    public const FIELD_EFFECTIVE_FROM = 'agencydiscount_effective_from';
    private const NOTE_MAX = 255;

    public function __construct(private readonly Module $module)
    {
    }

    /** @return array<string,string> one entry: 'Agency discount' => html */
    public function fields(int $clientId): array
    {
        $row = $this->module->repo->agency($clientId);
        $approved = $row !== null && $row['approved'];

        $html = '<input type="hidden" name="' . self::FIELD_PRESENT . '" value="1">'
            . '<label class="checkbox-inline"><input type="checkbox" name="' . self::FIELD_APPROVED . '" value="1"'
            . ($approved ? ' checked' : '') . '> Approved agency</label>'
            . '<div>Note <input type="text" name="' . self::FIELD_NOTE . '" class="form-control input-300" maxlength="' . self::NOTE_MAX . '" value="'
            . Html::e($row['note'] ?? '') . '"></div>'
            . '<div>Discount starts on <input type="date" name="' . self::FIELD_EFFECTIVE_FROM . '" class="form-control input-150" value="'
            . Html::e($row['effectiveFrom'] ?? '') . '"> (empty = immediately)</div>'
            . '<div class="text-muted">' . Html::e(self::summary($row)) . '</div>';

        return [self::LABEL => $html];
    }

    /** @param array<string,mixed> $request the submitted profile form */
    public function save(int $clientId, array $request, string $actor): void
    {
        if (($request[self::FIELD_PRESENT] ?? null) !== '1') {
            return;
        }
        $approved = ($request[self::FIELD_APPROVED] ?? '') === '1';
        $note = self::truncate(trim(self::str($request[self::FIELD_NOTE] ?? '')));
        $now = $this->module->clock->now()->format('Y-m-d H:i:s');

        $existing = $this->module->repo->agency($clientId);
        $row = $existing ?? self::blank($clientId, $now);

        $effectiveFrom = $row['effectiveFrom'];
        if (array_key_exists(self::FIELD_EFFECTIVE_FROM, $request)) {
            $raw = trim(self::str($request[self::FIELD_EFFECTIVE_FROM]));
            if ($raw === '') {
                $effectiveFrom = null;
            } elseif (self::isDate($raw)) {
                $effectiveFrom = $raw;
            } else {
                $this->log($clientId, 'effective_from_invalid', ['value' => substr($raw, 0, 32)], $actor, $now);
            }
        }

        $wasApproved = $row['approved'];
        $noteChanged = $note !== $row['note'];
        $effectiveChanged = $effectiveFrom !== $row['effectiveFrom'];
        if ($approved === $wasApproved && !$noteChanged && !$effectiveChanged) {
            return;
        }

        $previous = $row;
        $row['approved'] = $approved;
        $row['note'] = $note;
        $row['effectiveFrom'] = $effectiveFrom;
        $row['updatedAt'] = $now;
        if ($approved && !$wasApproved) {
            $row['approvedBy'] = $actor;
            $row['approvedAt'] = $now;
        }
        $this->module->repo->saveAgency($row);

        if ($approved && !$wasApproved) {
            $this->log($clientId, 'approved', ['note' => $note], $actor, $now);
            $this->activity(sprintf('Agency Discount: client %d approved as agency', $clientId), $clientId);
        } elseif (!$approved && $wasApproved) {
            $this->log($clientId, 'revoked', ['note' => $note], $actor, $now);
            $this->activity(sprintf('Agency Discount: client %d agency approval revoked', $clientId), $clientId);
        }
        if ($noteChanged && !($approved && !$wasApproved)) {
            $this->log($clientId, 'note_changed', ['from' => $previous['note'], 'to' => $note], $actor, $now);
        }
        if ($effectiveChanged) {
            $this->log($clientId, 'effective_from_changed', ['from' => $previous['effectiveFrom'], 'to' => $effectiveFrom], $actor, $now);
        }

        if ($approved && !$wasApproved) {
            $this->module->recalculator()->recalculate($clientId, $actor);
        }
    }

    /** @param AgencyRow|null $row */
    private static function summary(?array $row): string
    {
        if ($row === null || !$row['approved']) {
            return 'Not an approved agency';
        }
        if ($row['calculatedAt'] === null) {
            $summary = 'Not calculated yet';
        } else {
            $tier = $row['tierIndex'] === null
                ? 'no tier'
                : 'tier ' . $row['tierIndex'] . ' (' . Money::formatPercent($row['tierPercent'] ?? '0') . '%)';
            $summary = sprintf(
                'Qualifying monthly spend %s %s, %s, %d items, recalculated %s',
                $row['currency'],
                $row['spend'],
                $tier,
                $row['countedServices'] + $row['countedAddons'],
                $row['calculatedAt'],
            );
        }
        if ($row['effectiveFrom'] !== null) {
            $summary .= ', starts ' . $row['effectiveFrom'];
        }
        return $summary;
    }

    /** @return AgencyRow */
    private static function blank(int $clientId, string $now): array
    {
        return [
            'clientId' => $clientId,
            'approved' => false,
            'note' => '',
            'approvedBy' => '',
            'approvedAt' => null,
            'effectiveFrom' => null,
            'currency' => '',
            'spend' => '0.00',
            'tierIndex' => null,
            'tierPercent' => null,
            'countedServices' => 0,
            'countedAddons' => 0,
            'calculatedAt' => null,
            'updatedAt' => $now,
        ];
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** First NOTE_MAX characters (not bytes) without needing mbstring; invalid UTF-8 becomes ''. */
    private static function truncate(string $value): string
    {
        return preg_match('/^.{0,' . self::NOTE_MAX . '}/us', $value, $m) === 1 ? $m[0] : '';
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string,mixed> $detail */
    private function log(int $clientId, string $event, array $detail, string $actor, string $now): void
    {
        $this->module->repo->log([
            'clientId' => $clientId,
            'invoiceId' => null,
            'event' => $event,
            'detail' => $detail,
            'actor' => $actor,
            'createdAt' => $now,
        ]);
    }

    private function activity(string $message, int $clientId): void
    {
        if ($this->module->config->activityLog) {
            $this->module->activity->log($message, $clientId);
        }
    }
}
