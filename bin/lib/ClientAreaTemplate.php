<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Renders the module's client-area template (templates/clientarea.tpl) outside WHMCS, for the
 * tests and the preview harness, with Smarty 4.5.x (WHMCS 9.1 bundles 4.5.3) under a security
 * policy that allows, of Smarty's plugin tags and modifiers, only the escape modifier and the if
 * and foreach tags, and no PHP function, static class, super-global or constant. Smarty's own
 * syntax (variable output, array access, comparisons, assignment, literal and strip blocks) is
 * outside any policy; tests/ClientArea/TemplateTest.php scans the template for it. WHMCS's own
 * policy for addon templates is not published here, so "renders under WHMCS" rests on the
 * template using nothing but this. Development only: WHMCS renders the template itself.
 */
final class ClientAreaTemplate
{
    /** @var list<string> the only template tags the module's template may use (Smarty checks the closing tags as "ifclose" and "foreachclose") */
    public const ALLOWED_TAGS = ['if', 'elseif', 'else', 'ifclose', 'foreach', 'foreachelse', 'foreachclose'];
    /** @var list<string> the only modifiers the module's template may use */
    public const ALLOWED_MODIFIERS = ['escape'];

    public static function templateDir(): string
    {
        return dirname(__DIR__, 2) . '/modules/addons/agencydiscount/templates';
    }

    /**
     * @param array<string, mixed> $vars from ClientArea\PageData::build()
     * @param string|null $compileDir where Smarty writes its compiled template; by default .phpunit.cache/smarty in the
     *                                clone (ignored by git), never a shared temporary directory
     */
    public static function render(array $vars, ?string $compileDir = null): string
    {
        $compileDir ??= dirname(__DIR__, 2) . '/.phpunit.cache/smarty';
        if (!is_dir($compileDir)) {
            mkdir($compileDir, 0700, true);
        }
        $smarty = new \Smarty();
        $smarty->setTemplateDir(self::templateDir());
        $smarty->setCompileDir($compileDir);
        $smarty->caching = \Smarty::CACHING_OFF;
        $smarty->force_compile = true;
        $policy = new \Smarty_Security($smarty);
        $policy->allowed_tags = self::ALLOWED_TAGS;
        $policy->allowed_modifiers = self::ALLOWED_MODIFIERS;
        $policy->php_functions = [];
        $policy->php_modifiers = [];
        // An empty list would allow every static class (Smarty treats empty as "all"); a name no class has allows none.
        $policy->static_classes = ['NoStaticClassIsAllowed'];
        $policy->allow_super_globals = false;
        $policy->allow_constants = false;
        $smarty->enableSecurity($policy);
        $smarty->assign($vars);
        return $smarty->fetch('clientarea.tpl');
    }
}
