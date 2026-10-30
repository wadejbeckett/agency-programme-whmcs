<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Renders the module's client-area template (templates/clientarea.tpl) outside WHMCS, for the
 * tests and the preview harness, with Smarty at the major version WHMCS 9 bundles (4.x) and a
 * security policy narrower than WHMCS's: only the escape modifier and the if and foreach tags.
 * A template that renders here renders under WHMCS's policy too. Development only: WHMCS
 * renders the template itself.
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
     * @param string|null $compileDir where Smarty writes its compiled template; a fresh temporary directory by default
     */
    public static function render(array $vars, ?string $compileDir = null): string
    {
        $compileDir ??= sys_get_temp_dir() . '/agencydiscount-smarty-' . getmypid();
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
        $policy->static_classes = [];
        $policy->allow_super_globals = false;
        $policy->allow_constants = false;
        $smarty->enableSecurity($policy);
        $smarty->assign($vars);
        return $smarty->fetch('clientarea.tpl');
    }
}
