# Offline render preview

Renders the admin surfaces of the addon into complete HTML pages wrapped in a WHMCS admin theme, so they can be looked at and screenshotted without a WHMCS install, a session or a database. The figures come from fixture data in `Scenarios.php` (generic agency names, `ArrayRepository`, a frozen clock).

What it renders, for each theme found:

- `<prefix>-dashboard-<theme>.html`: the home widget with live-shaped data (six ZAR agencies), between stand-ins built from the markup of WHMCS's own Billing and Support widgets (`modules/widgets/` in a WHMCS install, shipped unencoded; the module's widget follows the Support widget's pattern), inside the dashboard's widget frame from `homepage.tpl`.
- `<prefix>-states-<theme>.html`: the widget in every state: `live`, `edge` (dry-run, an agency with a start date, a currency without tiers), `long` (twenty agencies), `empty`, `denied` and `unavailable`.
- `<prefix>-overview-<theme>.html` and `<prefix>-settings-<theme>.html`: the addon page's Overview and Settings tabs.
- `<prefix>-clientsummary-<theme>.html`: the client summary card, for an agency at a tier and one waiting for its start date.

## Theme files

The theme stylesheets belong to WHMCS and are not in this repository. Copy them from your WHMCS install into a directory of your own, one folder per theme:

```
<dir>/iris/css/all.css         from <whmcs>/admin/templates/iris/css/all.min.css (or all.css)
<dir>/iris/css/theme.min.css   from <whmcs>/admin/templates/iris/css/theme.min.css
<dir>/iris/fonts/              from <whmcs>/admin/templates/iris/fonts/ (Inter, Figtree, Pe-icon-7-stroke)
<dir>/blend/fonts/             from <whmcs>/admin/templates/blend/fonts/ (Pe-icon-7-stroke)
<dir>/blend/css/all.css        from <whmcs>/admin/templates/blend/css/all.min.css (or all.css)
<dir>/blend/css/theme.min.css  from <whmcs>/admin/templates/blend/css/theme.min.css
```

A missing theme folder is skipped. The pages link the stylesheets by `file://` path, so keep the directory where it is after rendering. Font Awesome is not loaded, so the widget tool icons and the title glyph render as empty boxes.

## Usage

```
php tools/preview/render.php --theme-dir=<dir> --out=<output dir> [--prefix=after]
tools/preview/screenshot.sh <output dir> '<prefix>-*.html' [height]
```

`--theme-dir` can also be given as the `WHMCS_ADMIN_THEMES` environment variable. `screenshot.sh` runs headless Chromium (`/usr/bin/chromium`, or `$CHROMIUM`) at 1440 and 1920 pixels wide and writes a PNG beside each page. For one other width:

```
chromium --headless=new --no-sandbox --disable-gpu --screenshot=out.png --window-size=1280,1100 file:///path/to/page.html
```

## Limits

This is an approximation of the dashboard, not WHMCS:

- The masonry layout is emulated with the theme's float widths (one column is a third of the content area, full width below 950px); widgets of different heights do not pack the way masonry packs them.
- The widget frame is copied from `homepage.tpl`. The panel class `widget-homewidget` assumes WHMCS uses the widget class's short name as its id.
- `HomeWidget` (the `AbstractWidget` wrapper) is not loaded; the harness calls `WidgetData` directly, which is what `HomeWidget::generateOutput()` returns.
- The page shell (navigation, sidebar, header bar) is reduced to the content area.
