<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Offline render harness: renders the admin home widget (several fixture scenarios), the
 * Overview, Settings, agency detail and Change log pages, the client summary card and the
 * client-area page into complete HTML pages wrapped in a WHMCS theme's frames, so they can be
 * screenshotted without WHMCS.
 *
 *   php tools/preview/render.php --theme-dir=<dir holding iris/, blend/ and twenty-one/> --out=<dir> [--prefix=after]
 *
 * The Overview and Settings pages come from the edge scenario (dry-run, a start date, missing
 * tiers and ids); the agency detail, Change log and client-area pages from the demo scenario (live,
 * with history), which is the screenshot set.
 *
 * The theme directory holds copies of <whmcs>/admin/templates/<theme>/css (and fonts) and of
 * <whmcs>/templates/twenty-one/css; those files are WHMCS's and are referenced by path, never
 * copied into this repository. See README.md. The client-area page is rendered through Smarty
 * (ClientAreaTemplate, a dev dependency) under a restrictive security policy.
 */

use AgencyDiscount\Admin\ClientSummary;
use AgencyDiscount\Admin\Html;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\Admin\WidgetData;
use AgencyDiscount\ClientArea\PageData;
use AgencyDiscount\ClientAreaTemplate;
use AgencyDiscount\PreviewHarness\Scenarios;

$root = dirname(__DIR__, 2);
require_once $root . '/bin/lib/bootstrap.php';
require_once __DIR__ . '/Scenarios.php';
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php'; // Smarty, for the client-area page
}

$options = getopt('', ['theme-dir:', 'out:', 'prefix::']);
$themeDir = is_string($options['theme-dir'] ?? null) ? rtrim($options['theme-dir'], '/') : (getenv('WHMCS_ADMIN_THEMES') ?: '');
$out = is_string($options['out'] ?? null) ? rtrim($options['out'], '/') : '';
$prefix = is_string($options['prefix'] ?? null) ? $options['prefix'] : 'preview';
if ($themeDir === '' || $out === '' || !is_dir($themeDir)) {
    fwrite(STDERR, "usage: php tools/preview/render.php --theme-dir=<dir with iris/, blend/ and twenty-one/> --out=<dir> [--prefix=name]\n");
    exit(2);
}
$themeDir = (string) realpath($themeDir);
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

/** A complete admin page in the given theme: the theme's stylesheets by file URL and the content area. */
function frame(string $themeDir, string $theme, string $title, string $content): string
{
    $css = '';
    foreach (['all.css', 'theme.min.css'] as $file) {
        $path = $themeDir . '/' . $theme . '/css/' . $file;
        if (is_file($path)) {
            $css .= '<link rel="stylesheet" href="file://' . Html::e($path) . '">' . "\n";
        }
    }
    $bodyClass = $theme === 'iris' ? 'iris page-index no-sidebar' : 'no-sidebar';
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . '<title>' . Html::e($title) . "</title>\n" . $css
        // Font Awesome is not part of the copied theme; keep the harness free of external assets.
        . '<style>.contentarea{overflow-x:hidden}.preview-caption{clear:both;margin:8px 0 6px;font-size:12px;color:#888}</style>'
        . '</head><body class="' . $bodyClass . '"><div class="navigation"></div>'
        . '<div class="contentarea" id="contentarea"><div style="float:left;width:100%;">'
        . '<h1>' . Html::e($title) . '</h1>' . $content
        . '</div></div></body></html>';
}

/**
 * A client-area page in the Twenty-One theme: the theme's stylesheets by file URL, the body class
 * and the main-body container from header.tpl, the page title the way clientarea pages carry it,
 * and the module's template output. The header bar and sidebars are left out.
 */
function clientFrame(string $themeDir, string $title, string $content): string
{
    $css = '';
    foreach (['all.min.css', 'theme.min.css', 'custom.css'] as $file) {
        $path = $themeDir . '/twenty-one/css/' . $file;
        if (is_file($path)) {
            $css .= '<link rel="stylesheet" href="file://' . Html::e($path) . '">' . "\n";
        }
    }
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . '<title>' . Html::e($title) . "</title>\n" . $css
        . '</head><body class="primary-bg-color"><header id="header" class="header"></header>'
        . '<section id="main-body"><div class="container"><div class="row"><div class="col-12 main-content">'
        . '<h1 class="page-title">' . Html::e($title) . '</h1>' . $content
        . '</div></div></div></section></body></html>';
}

/** One sortable widget exactly as homepage.tpl wraps it. */
function widget(string $id, string $title, string $inner, int $columns = 1): string
{
    return '<div class="dashboard-panel-item dashboard-panel-item-columns-' . $columns . '" data-widget="' . Html::e($id) . '">'
        . '<div class="panel panel-default widget-' . Html::e(strtolower($id)) . '" data-widget="' . Html::e($id) . '">'
        . '<div class="panel-heading"><div class="widget-tools"><a href="#" class="widget-refresh"></a><a href="#" class="widget-minimise"></a><a href="#" class="widget-hide"></a></div>'
        . '<h3 class="panel-title">' . Html::e($title) . '</h3></div>'
        . '<div class="panel-body">' . $inner . '</div></div></div>';
}

/** Stand-ins for two built-in widgets, using the class names WHMCS's own widgets use, for comparison. */
function nativeBilling(): string
{
    $item = static fn (string $class, string $amount, string $note): string => '<div class="col-sm-6 ' . $class . '"><div class="item"><div class="data color-green">' . $amount . '</div><div class="note">' . $note . '</div></div></div>';
    return '<div class="row">' . $item('bordered-right', 'R0.00', 'Today') . $item('', 'R18,412.50', 'This Month')
        . $item('bordered-right bordered-top', 'R212,904.12', 'This Year') . $item('bordered-top', 'R1,104,220.87', 'All Time') . '</div>';
}

/** The Support widget's markup (icon-stats, a list, a footer), the pattern the module's widget follows. */
function nativeSupport(): string
{
    $item = static fn (string $colour, string $icon, string $note, string $n): string => '<div class="col-sm-6"><div class="item"><div class="icon-holder text-center ' . $colour . '"><a href="#"><i class="' . $icon . '"></i></a></div>'
        . '<div class="data"><div class="note"><a href="#">' . $note . '</a></div><div class="number"><a href="#"><span class="' . $colour . '">' . $n . '</span> <span class="unit">Tickets</span></a></div></div></div></div>';
    $tickets = '';
    foreach (['#48213 - Mailbox quota' => '2 hours ago', '#48210 - DNS change' => '5 hours ago', '#48199 - Renewal query' => '1 day ago'] as $subject => $age) {
        $tickets .= '<div class="ticket"><div class="pull-right color-blue">' . $age . '</div><a href="#">' . $subject . '</a></div>';
    }
    return '<div class="icon-stats"><div class="row">' . $item('color-blue', 'pe-7s-ticket', 'Awaiting Reply', '3') . $item('color-pink', 'pe-7s-flag', 'Assigned To You', '1') . '</div></div>'
        . '<div class="tickets-list">' . $tickets . '</div>'
        . '<div class="footer"><a href="#">View All Tickets</a><a href="#">View My Tickets</a><a href="#">Open New Ticket</a></div>';
}

function widgetInner(string $scenario): string
{
    return match ($scenario) {
        'denied' => WidgetData::render(['denied' => true]),
        'unavailable' => WidgetData::unavailable(),
        default => WidgetData::render(WidgetData::build(Scenarios::module($scenario), 1)),
    };
}

$written = [];
$put = static function (string $name, string $html) use ($out, &$written): void {
    file_put_contents($out . '/' . $name, $html);
    $written[] = $out . '/' . $name;
};

foreach (['iris', 'blend'] as $theme) {
    if (!is_dir($themeDir . '/' . $theme)) {
        continue;
    }
    $title = Page::lang('title');
    // Row 1: the live widget between two built-in widgets. Further rows: the edge states.
    $dashboard = '<div class="home-widgets-container"><div class="dashboard-panel-sizer"></div>'
        . widget('Billing', 'Billing', nativeBilling())
        . widget('AgencyProgramme', $title, widgetInner('live'))
        . widget('Support', 'Support', nativeSupport())
        . '</div><div class="clearfix"></div>';
    $put($prefix . '-dashboard-' . $theme . '.html', frame($themeDir, $theme, 'Dashboard', $dashboard));

    $states = '';
    foreach (array_chunk(Scenarios::widgetScenarios(), 3) as $chunk) {
        $states .= '<div class="home-widgets-container"><div class="dashboard-panel-sizer"></div>';
        foreach ($chunk as $scenario) {
            $states .= widget('AgencyProgramme', $title . ' (' . $scenario . ')', widgetInner($scenario));
        }
        $states .= '</div><div class="clearfix"></div>';
    }
    $put($prefix . '-states-' . $theme . '.html', frame($themeDir, $theme, 'Dashboard', $states));

    $page = Scenarios::page(Scenarios::PAGES);
    $token = '<input type="hidden" name="token" value="preview">';
    $put($prefix . '-overview-' . $theme . '.html', frame($themeDir, $theme, $title, $page->render(WidgetData::MODULE_LINK, [], [], 'admin:1', $token)));
    $put($prefix . '-settings-' . $theme . '.html', frame($themeDir, $theme, $title, $page->render(WidgetData::MODULE_LINK, ['action' => 'settings'], [], 'admin:1', $token)));
    $demo = Scenarios::page(Scenarios::DEMO);
    $put($prefix . '-overview-demo-' . $theme . '.html', frame($themeDir, $theme, $title, $demo->render(WidgetData::MODULE_LINK, [], [], 'admin:1', $token)));
    $put($prefix . '-agency-' . $theme . '.html', frame($themeDir, $theme, $title, $demo->render(WidgetData::MODULE_LINK, ['action' => 'agency', 'id' => (string) Scenarios::DETAIL_CLIENT], [], 'admin:1', $token)));
    $put($prefix . '-log-' . $theme . '.html', frame($themeDir, $theme, $title, $demo->render(WidgetData::MODULE_LINK, ['action' => 'log'], [], 'admin:1', $token)));

    // The repair page: stored settings that do not parse (Scenarios::brokenSettings()).
    $broken = Scenarios::page('broken');
    $put($prefix . '-overview-broken-' . $theme . '.html', frame($themeDir, $theme, $title, $broken->render(WidgetData::MODULE_LINK, [], [], 'admin:1', $token)));
    $put($prefix . '-settings-broken-' . $theme . '.html', frame($themeDir, $theme, $title, $broken->render(WidgetData::MODULE_LINK, ['action' => 'settings'], [], 'admin:1', $token)));

    $edge = Scenarios::module('edge');
    $summary = '<div class="row"><div class="col-xs-12"><div class="addon-html-output-container">' . ClientSummary::render($edge, 101) . '</div>'
        . '<div class="addon-html-output-container">' . ClientSummary::render($edge, 103) . '</div></div></div>';
    $put($prefix . '-clientsummary-' . $theme . '.html', frame($themeDir, $theme, 'Client Profile', $summary));
}

if (!is_dir($themeDir . '/twenty-one')) {
    fwrite(STDERR, "twenty-one: skipped, no twenty-one/ folder in the theme directory\n");
} elseif (!class_exists(\Smarty::class)) {
    fwrite(STDERR, "twenty-one: skipped, Smarty is not installed (run composer install)\n");
} else {
    $vars = PageData::build(Scenarios::module(Scenarios::DEMO), Scenarios::DETAIL_CLIENT);
    $put($prefix . '-clientarea-twenty-one.html', clientFrame($themeDir, $vars['text']['title'], ClientAreaTemplate::render($vars)));
    $none = PageData::build(Scenarios::module(Scenarios::DEMO), null);
    $put($prefix . '-clientarea-notmember-twenty-one.html', clientFrame($themeDir, $none['text']['title'], ClientAreaTemplate::render($none)));
}

echo implode("\n", $written), "\n";
