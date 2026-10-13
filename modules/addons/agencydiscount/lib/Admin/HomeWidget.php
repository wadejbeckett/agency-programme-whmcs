<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\Hooks;
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
     * The figures are income figures, so the admin needs the permission WHMCS's own income widgets
     * require; on top of that, getData() only answers for roles on the addon's access list.
     *
     * @var string
     */
    protected $requiredPermission = 'View Income Totals';

    public function __construct()
    {
        $this->title = Page::lang('title');
        $this->description = Page::lang('widget_description');
    }

    /** @return array<string, mixed> WidgetData, ['denied' => true] for a role outside the addon's access list, or ['error' => true] when it could not be built */
    public function getData(): array
    {
        try {
            return WidgetData::build(Module::boot(), Hooks::adminId());
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
            /** @var array{agencies:int, atTier:int, waiting:int, spend:array<string,string>, discount:array<string,string>, dryRun:bool, lastRecalculation:?string, missing:list<int>, rows:list<array{clientId:int, name:string, currency:string, spend:string, tier:?array{index:int, percent:string}, hasTiers:bool, nextInvoice:?array{date:string, base:string, discount:string}, startsOn:?string}>, days:int}|array{denied:true} $data */
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
