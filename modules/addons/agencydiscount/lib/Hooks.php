<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * WHMCS hook entry points. None of them ever throws: a failure is written to
 * the WHMCS activity log and the hook returns, so invoicing and the client
 * profile keep working whatever happens in this module.
 */
final class Hooks
{
    /** @var (callable(): Module)|null tests inject a factory returning a Module built on ArrayRepository */
    public static $factory = null;

    private static ?Module $module = null;
    private static bool $configLogged = false;

    /** Clears the memoised module, the factory and the once-per-request configuration notice. */
    public static function reset(): void
    {
        self::$module = null;
        self::$factory = null;
        self::$configLogged = false;
    }

    /** @param array<string, mixed> $vars InvoiceCreation parameters: invoiceid, source, user, status */
    public static function invoiceCreation(array $vars): void
    {
        try {
            $invoiceId = (int) ($vars['invoiceid'] ?? 0);
            $module = self::module();
            if ($invoiceId <= 0 || $module === null) {
                return;
            }
            $source = $vars['source'] ?? 'unknown';
            $module->discounter()->apply(
                $invoiceId,
                is_scalar($source) ? (string) $source : 'unknown',
                self::actorFrom($vars['user'] ?? 'system'),
            );
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    public static function recalculateAll(string $actor): void
    {
        try {
            self::module()?->recalculator()->recalculateAll($actor);
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    /**
     * Approval grants a recurring discount, so the field is shown only to admin roles on the addon's
     * access list, the same check as the client summary card.
     *
     * @param array<string, mixed> $vars AdminClientProfileTabFields parameters: userid
     * @return array<string, string> label => field HTML; empty for a role outside the access list
     */
    public static function profileFields(array $vars): array
    {
        try {
            $clientId = (int) ($vars['userid'] ?? 0);
            $module = self::module();
            if ($clientId <= 0 || $module === null || !Admin\WidgetData::roleAllowed($module->repo, self::adminId())) {
                return [];
            }
            return (new ProfileTab($module))->fields($clientId);
        } catch (\Throwable $e) {
            self::report($e);
            return [];
        }
    }

    /**
     * A save from an admin role outside the addon's access list is ignored and noted in the activity
     * log, so a forged form field cannot approve an agency.
     *
     * @param array<string, mixed> $vars AdminClientProfileTabFieldsSave parameters: userid
     * @param array<string, mixed> $request the submitted form ($_REQUEST). WHMCS HTML-encodes request
     *        input at init, so the free-text fields are decoded here before they are stored; ProfileTab
     *        escapes on output.
     */
    public static function profileSave(array $vars, array $request): void
    {
        try {
            $clientId = (int) ($vars['userid'] ?? 0);
            if ($clientId <= 0 || ($request[ProfileTab::FIELD_PRESENT] ?? null) !== '1') {
                return;
            }
            $module = self::module();
            if ($module === null) {
                return;
            }
            if (!Admin\WidgetData::roleAllowed($module->repo, self::adminId())) {
                self::activity('Agency Discount: profile save for client ' . $clientId . ' by ' . self::adminActor() . ' ignored: the admin role is not on the addon\'s access list');
                return;
            }
            foreach ([ProfileTab::FIELD_NOTE, ProfileTab::FIELD_EFFECTIVE_FROM] as $field) {
                if (array_key_exists($field, $request) && is_scalar($request[$field])) {
                    $request[$field] = html_entity_decode((string) $request[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            (new ProfileTab($module))->save($clientId, $request, self::adminActor());
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    /**
     * @param array<string, mixed> $vars AdminAreaClientSummaryPage parameters: userid
     * @return string the summary card for an approved agency, '' for any other client, for an admin role
     *         outside the addon's access list, and on any failure
     */
    public static function clientSummary(array $vars): string
    {
        try {
            $clientId = (int) ($vars['userid'] ?? 0);
            $module = self::module();
            if ($clientId <= 0 || $module === null || !Admin\WidgetData::roleAllowed($module->repo, self::adminId())) {
                return '';
            }
            return Admin\ClientSummary::render($module, $clientId);
        } catch (\Throwable $e) {
            self::report($e);
            return '';
        }
    }

    /**
     * ClientAreaHomepagePanels: adds "Your agency programme" for a logged-in approved agency, with the
     * tier, spend, next-invoice discount, saved this year and the amount to the next tier, and a button
     * to the module page. Any other client, and any failure, leaves the home page untouched.
     */
    public static function clientHomePanels(\WHMCS\View\Menu\Item $panels): void
    {
        try {
            $module = self::module();
            $clientId = self::clientId();
            if ($module === null || $clientId === null) {
                return;
            }
            $vars = ClientArea\PageData::build($module, $clientId);
            $body = ClientArea\PageData::panelHtml($vars);
            if ($body === '') {
                return;
            }
            $panels->addChild('agencydiscount', [
                'name' => 'Agency Programme',
                'label' => $vars['text']['panel_title'],
                'icon' => 'fas fa-handshake',
                'order' => 10,
                'extras' => [
                    'color' => 'green',
                    'btn-link' => ClientArea\PageData::MODULE_PAGE,
                    'btn-text' => $vars['text']['panel_button'],
                    'btn-icon' => 'fas fa-arrow-right',
                ],
                'bodyHtml' => $body,
            ]);
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    /** The logged-in client's id from the WHMCS session ('uid'), or null when nobody is logged in. */
    public static function clientId(): ?int
    {
        $uid = class_exists(\WHMCS\Session::class) ? \WHMCS\Session::get('uid') : null;
        return is_numeric($uid) && (int) $uid > 0 ? (int) $uid : null;
    }

    /** The logged-in admin's id from the WHMCS session, or null when it cannot be read. */
    public static function adminId(): ?int
    {
        $adminId = class_exists(\WHMCS\Session::class) ? \WHMCS\Session::get('adminid') : null;
        return is_numeric($adminId) && (int) $adminId > 0 ? (int) $adminId : null;
    }

    /** 'system' or nothing → 'system'; an admin id (int or numeric string) → 'admin:<id>'; any other string as given. */
    public static function actorFrom(mixed $user): string
    {
        if (is_int($user) || (is_string($user) && ctype_digit($user))) {
            return 'admin:' . (int) $user;
        }
        if (is_string($user) && trim($user) !== '') {
            return trim($user);
        }
        return 'system';
    }

    /** The module for this request, or null (logged once) when the configuration is invalid. */
    private static function module(): ?Module
    {
        if (self::$module !== null) {
            return self::$module;
        }
        try {
            $factory = self::$factory ?? Module::boot(...);
            return self::$module = $factory();
        } catch (ConfigException $e) {
            if (!self::$configLogged) {
                self::$configLogged = true;
                self::activity('Agency Discount: configuration problems: ' . implode('; ', $e->problems()) . '; hook skipped');
            }
            return null;
        }
    }

    /** The logged-in admin from the WHMCS session, as 'admin:<id>', or 'admin' when it cannot be read. */
    private static function adminActor(): string
    {
        $adminId = self::adminId();
        return $adminId === null ? 'admin' : 'admin:' . $adminId;
    }

    private static function report(\Throwable $e): void
    {
        self::activity('Agency Discount: ' . get_class($e) . ': ' . $e->getMessage());
    }

    /** Even the activity log can fail (database gone); fall back to the PHP error log rather than throw. */
    private static function activity(string $message): void
    {
        try {
            \logActivity($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
