<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\Module;
use WHMCS\Module\AbstractWidget;

/**
 * The admin home widget, returned by the AdminHomeWidgets hook. Only loaded inside WHMCS (Bootstrap
 * checks for AbstractWidget); the numbers and markup are WidgetData's, so they are unit-tested
 * without this class. Any failure renders one line and goes to the activity log: the dashboard
 * never breaks because of this module.
 */
final class HomeWidget extends AbstractWidget
{
    /** @var string */
    protected $title = 'Agency Programme';
    /** @var string */
    protected $description = 'Approved agencies, their qualifying spend and the discount expected in the next 35 days.';
    /** @var int */
    protected $weight = 150;
    /** @var int */
    protected $columns = 1;
    /** @var bool */
    protected $cache = false;
    /** @var int */
    protected $cacheExpiry = 120;
    /**
     * WHMCS has no named admin permission for an addon module's access list (that is a role setting on
     * the addon), so no permission is required; the widget is read-only.
     *
     * @var string
     */
    protected $requiredPermission = '';

    /** @return array<string, mixed> WidgetData, or ['error' => true] when it could not be built */
    public function getData(): array
    {
        try {
            return WidgetData::build(Module::boot());
        } catch (\Throwable $e) {
            self::report($e);
            return ['error' => true];
        }
    }

    public function generateOutput(mixed $data): string
    {
        try {
            if (!is_array($data) || ($data['error'] ?? false) === true) {
                return self::unavailable();
            }
            /** @var array{agencies:int, atTier:int, waiting:int, spend:array<string,string>, discount:array<string,string>, dryRun:bool, lastRecalculation:?string, missing:list<int>, rows:list<array{clientId:int, name:string, currency:string, spend:string, tier:?array{index:int, percent:string}, hasTiers:bool, nextInvoice:?array{date:string, base:string, discount:string}, startsOn:?string}>, days:int} $data */
            return WidgetData::render($data);
        } catch (\Throwable $e) {
            self::report($e);
            return self::unavailable();
        }
    }

    private static function unavailable(): string
    {
        return '<div class="widget-content-padded">' . Html::e(Page::lang('widget_unavailable')) . '</div>';
    }

    private static function report(\Throwable $e): void
    {
        try {
            \logActivity('Agency Discount: home widget: ' . get_class($e) . ': ' . $e->getMessage());
        } catch (\Throwable) {
            error_log('Agency Discount: home widget: ' . $e->getMessage());
        }
    }
}
