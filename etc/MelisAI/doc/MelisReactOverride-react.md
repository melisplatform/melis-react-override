---
title: MelisReactOverride module — React back-office
package: melisplatform/melis-react-override
doc_type: module-documentation-react
audience: [users, developers, ai]
language: en
module_version: unversioned
last_reviewed: 2026-08-20
maintainer: Melis Technology
keywords: [react, back-office, infrastructure, iframe, tool-page, spa, shell, legacy-tool, toolpage-extensions, plugin-view, excluded-routes, platform-assets]
screenshots_dir: ./images/react
---

# MelisReactOverride (React back-office) — Functional & Technical Documentation (for AI)

> **What this is.** MelisReactOverride is a **React back-office infrastructure module** — it
> is not a tool, has **no brick, no React page, no `react-api` endpoints, no capabilities and
> no screens of its own**. It provides the two plumbing mechanisms that make the React
> back-office (`/melis-react`) work alongside the legacy `/melis`:
> 1. the **legacy-tool iframe mechanism** — any legacy jQuery/AJAX tool that has no dedicated
>    React page is rendered as a standalone HTML page (`/melis/react-tool-page?key=<melisKey>`)
>    and shown inside the React shell in an `<iframe>`; and
> 2. the **SPA fallback route** — it serves the React shell `index.html` for `/melis-react`
>    and every client-side deep link under it, and makes that route (plus a few read-only
>    boot endpoints) public.
>
> This document describes the module **as infrastructure of the React UI**. There is **no
> user-facing surface** and therefore **no screenshots**. For the underlying tool data
> models and services, see each tool's own module doc.
>
> **How this document is organised — two clearly separated parts:**
> - **[Part A — Functional Guide](#part-a--functional-guide)** — plain-language explanation of
>   *what you actually see* when a legacy tool opens inside `/melis-react`, and where the React
>   shell itself comes from.
> - **[Part B — Technical Reference](#part-b--technical-reference)** — for developers and AI
>   building inside the React UI: the exact routes, the `toolPageAction` / `buildToolPage`
>   flow, the documented pitfalls it solves, the SPA fallback, and the `toolpage_extensions`
>   hook — all with verified identifiers.
>
> **Audience**: consumed by the **MelisAI** MCP. **Status**: reviewed 2026-08-20.

---

## 0. Where this lives in the React back-office — read this first

**Brick kind: none — this is an infrastructure module.** MelisReactOverride draws **no
page**, adds **no menu entry**, ships **no `ui-react/` brick source**, **no
`public/ui-react/brick.manifest.json`**, **no `config/react-api.php`** and **no
`config/react.capabilities.php`**. It is a pure server-side (Laminas MVC) module: two
controllers and two services.

You do not *navigate to* MelisReactOverride — you meet it in two ways, invisibly:

- **Every legacy tool shown inside the React shell** goes through it. When the React shell
  opens a tool that has no native React page (the *iframe pool* / `/zone/:melisKey` shell
  slot, or a tool's *Old (iframe)* toggle), the iframe's `src` is
  **`/melis/react-tool-page?key=<melisKey>`**, which this module serves. So the tool you see
  is the real legacy tool, rendered by MelisReactOverride into a standalone page.
- **The React shell itself** (`/melis-react` and every deep link like `/melis-react/news/5`)
  is served by this module's **SpaController**.

**How this module overrides MelisCore.** It replaces MelisCore's `PluginView` controller with
its own React-aware version via a `controllers.invokables` alias — Laminas merges configs in
module-load order and this module loads **after** melis-core, so the override wins (see §B1).

Related infrastructure: the JSON API layer (`melis-react-api`) and the React shell + bricks
(`melis-core/ui-react`). Coupled example of a *consumer* of this module's extension point:
**MelisAICommunityExtensions** registers a `toolpage_extensions` implementation (see its React
doc, `../../../melis-ai-community-extensions/etc/MelisAI/doc/MelisAICommunityExtensions-react.md`).

---
---

# PART A — Functional Guide

## A1. What MelisReactOverride does for you (as a user)

You never open MelisReactOverride directly. It is the reason two things work:

- **Legacy tools look and behave normally inside `/melis-react`.** Any Melis tool that hasn't
  been rewritten as a native React page still appears in the new back-office — with its own
  DataTables, forms, modals, tabs, save buttons and export links — because MelisReactOverride
  renders that legacy tool as a self-contained page and the React shell shows it in a frame.
  It is designed so the tool inside the frame *looks and behaves exactly like direct `/melis`
  access*, including its own native notifications (gritter toasts, per-field validation
  modals).
- **The React back-office loads at `/melis-react`.** Typing `/melis-react` (or refreshing on a
  deep link such as `/melis-react/news/5`) loads the React app, because this module serves the
  app shell for those URLs.

> Rule of thumb: if you are inside `/melis-react` looking at an *old-style* tool screen (with
> the classic Bootstrap look), you are looking at a page produced by MelisReactOverride.

## A2. Finding it in /melis-react

There is **no dedicated screen and no sidebar entry** — nothing to navigate to. You experience
it indirectly:

- Open any tool that still uses its legacy UI from the left menu → the panel that appears is a
  frame served by `/melis/react-tool-page`.
- On a tool that offers a **New (React) / Old (iframe)** toggle, switching to **Old** loads the
  legacy tool through this same mechanism.

## A3. Key words explained

- **melisKey** — the identifier of a *renderable zone* (a tool) in Melis' app-config tree. It
  is the `key` passed to `/melis/react-tool-page?key=<melisKey>` (e.g. `meliscore_tool_user`,
  `meliscms_page`).
- **Zone** — a renderable app-config node (carrying a `forward`, often
  `follow_regular_rendering:false`). The iframe mechanism renders exactly one zone.
- **SPA shell** — the single `index.html` of the React app; the browser-side router then
  decides what to show under `/melis-react/*`.
- **Legacy tool** — a Melis tool still using its original jQuery/AJAX `.phtml` UI (as opposed
  to a native React page or a module brick).

## A4. Common tasks — "How do I…?"

- **…show a legacy tool inside the React shell?** Nothing to do here — the React shell already
  points the iframe at `/melis/react-tool-page?key=<melisKey>`. As a module author, you add
  tool-specific quirks through the `toolpage_extensions` hook (§B6), not by editing this
  module.
- **…make the React app load on a deep-link refresh?** It already does — `SpaController` serves
  the shell for anything under `/melis-react` (§B5).
- **…a legacy tool's buttons do nothing / its list is empty inside the frame?** That is almost
  always a missing module *ressource* (JS/CSS) — this module already injects a module's own
  resources into the tool page; if a newly-contributed tab or button is dead, see the resource
  injection notes in §B3.

---
---

# PART B — Technical Reference

## B1. React presence at a glance

| Property | Value |
|---|---|
| Module name (`extra.module-name`) | `MelisReactOverride` |
| Package | `melisplatform/melis-react-override` |
| Category (`extra.melis-module-category`) | `core` |
| Brick / `ui-react/` / `public/ui-react/` | **none** |
| `config/react-api.php` / `config/react.capabilities.php` | **none** |
| Kind | **React infrastructure module** (server-side Laminas MVC only) |
| Controllers | `PluginViewController` (aliased over `MelisCore\Controller\PluginView`), `SpaController` |
| Services | `PlatformAssetsService`, `LegacyWidgetCssService` |
| Extension interface | `PluginViewToolPageExtensionInterface` (`toolpage_extensions` hook) |

**Controller override (verified `config/module.config.php`):**

```php
'controllers' => [
    'invokables' => [
        // Override MelisCore's PluginViewController with our React-aware version.
        // Our module loads after melis-core, so this alias wins the config merge.
        'MelisCore\Controller\PluginView'    => \MelisReactOverride\Controller\PluginViewController::class,
        'MelisReactOverride\Controller\Spa'  => \MelisReactOverride\Controller\SpaController::class,
    ],
],
```

`src/Module.php` autoloads `MelisReactOverride\*` from `src/` via `StandardAutoloader`
(`getAutoloaderConfig`), because the module is loaded through `application.config.php`
(`module_paths` + `modules`), not composer autoload.

## B2. Routes it declares (verified `config/module.config.php`)

All tool/iframe routes are **child routes of `melis-backoffice`**, so they live under the
`/melis` prefix. The SPA route is a top-level regex route.

| Route name | URL | Controller / action | Purpose |
|---|---|---|---|
| `melis-backoffice/react-tool-page` | `/melis/react-tool-page` | `MelisCore\Controller\PluginView` → `toolPage` | **The core mechanism.** Renders a legacy tool zone as a standalone HTML page for an iframe. Takes `?key=<melisKey>` (and `?idPage=<id>` for the CMS page editor). |
| `melis-backoffice/react-dashboard-plugin` | `/melis/react-dashboard-plugin` | → `dashboardPluginPage` | A single legacy dashboard plugin as a minimal standalone page (iframe in the React dashboard). |
| `melis-backoffice/react-dashboard-plugin-config` | `/melis/react-dashboard-plugin-config` | → `dashboardPluginConfigPage` | A dashboard plugin's config form (cog button) as standalone HTML. |
| `melis-backoffice/react-dashboard-plugin-config-data` | `/melis/react-dashboard-plugin-config-data` | → `dashboardPluginConfigData` | JSON: the plugin config form as **data** (tabs + typed fields + values) so React renders it natively. |
| `melis-backoffice/react-dashboard-plugin-config-save` | `/melis/react-dashboard-plugin-config-save` | → `dashboardPluginConfigSave` | POST: validate + persist a dashboard plugin's config. |
| `melis-backoffice/react-dashboard-plugin-content` | `/melis/react-dashboard-plugin-content` | → `dashboardPluginContent` | JSON: HTML + scripts + jsCallbacks of a dashboard plugin for direct DOM injection (no iframe). |
| `melis-backoffice/react-platform-bundle` | `/melis/react-platform-bundle` | → `platformBundle` | Serves the concatenated asset bundle (`etc/bundles/…`) with the **correct MIME type**, replacing MelisCore's `/melis/get-{css,js}-bundles` (which answer an empty `text/html` when the bundle file is missing → the browser rejects the stylesheet). |
| `melis-backoffice/react-legacy-widget-css` | `/melis/react-legacy-widget-css` | → `legacyWidgetCss` | The legacy back-office stylesheets, every rule scoped under `.melis-legacy-widget` so they can't restyle the React shell. |
| `meliscore-melis-react-spa` | `/melis-react`, `/melis-react/*` | `MelisReactOverride\Controller\Spa` → `spa` | **SPA fallback.** Serves the React shell `index.html` for the entry point and every client-side deep link (§B5). Regex route, `priority => 1000`. |

**`excluded_routes` (public routes) — verified.** The module appends to MelisCore's
`plugins.meliscore.datas.excluded_routes` (numeric arrays merge by appending) so
`MelisCore\Module::checkIdentity()` lets these through without redirecting to `/melis/login`:

- `meliscore-melis-react-spa` — the shell is public because **the React app handles its own
  authentication** (it shows its own login screen).
- `melis-backoffice/react-platform-bundle` — so an expired session doesn't redirect a
  stylesheet to `/melis/login` (that would be HTML → the exact MIME error this route fixes).
- `melis-backoffice/melis-react-api/platformscheme-react-get` — the login-panel branding read,
  shown **before** authentication (GET only; the `/save` stays protected).
- `melis-backoffice/melis-react-api/langs` — the back-office language list the SPA loads at
  boot, including on the login screen (read only).

> Note: the last two `excluded_routes` entries are `melis-react-api` routes (owned by the
> **MelisReactApi** module); MelisReactOverride only makes them public here, it does not
> define them.

## B3. The iframe mechanism — `toolPageAction()` → `buildToolPage()`

The heart of the module is `PluginViewController::toolPageAction()`
(`src/Controller/PluginViewController.php`), which resolves a melisKey, renders its zone, and
hands the HTML to `buildToolPage()` to assemble a standalone document. Verified flow:

1. **Auth guard.** `denyIfUnauthenticated()` runs first (this page is *not* public).
2. **Resolve the melisKey → appConfig path.** `MelisCoreConfig->getMelisKeys()` maps
   `?key=<melisKey>` to an app-config path; the last path segment is the view key (`$keyView`).
3. **Force XHR mode.** It adds the header **`X-Requested-With: XMLHttpRequest`** to the request
   so `generateRec()` renders zones with `follow_regular_rendering:false` the same way the
   classic back-office AJAX path does (otherwise those tools fall through to the front and
   render a "404 MelisDemoCms").
4. **Pin the PHP session id.** `currentSessionId()` snapshots it before render and
   `pinSessionId()` restores it after — some legacy tools rotate the session id while
   rendering, which is harmless in `/melis` but fatal here.
5. **Render the zone** with `generateRec()` + `renderViewRec()`, capturing any **stray output**
   a legacy zone `echo`s straight to the stream (kept out of the markup as an HTML comment for
   diagnosis, not shown to the user).
6. **Run `adjustToolHtml()` extensions** (`toolpage_extensions`, §B6) on the rendered HTML.
7. **Build the platform assets** via `PlatformAssetsService::build()` (§B4) and **inject the
   module's own JS/CSS `ressources`** (§B3, resource injection).
8. **Run `adjustToolAssets()` extensions** (they may force some module JS into the `<head>`
   bucket and return `skipJsRoots` so the generic loop doesn't double-load it).
9. **Assemble** the page with `buildToolPage($html, $jsCallBacks, $assets, $zoneId, $key)` and
   return it as `text/html` with `X-Frame-Options: SAMEORIGIN`.

### Documented pitfalls `buildToolPage()` / `toolPageAction()` solve (verified in code)

Only the ones actually present in the source are listed:

- **Neutralise bundle.js's "Remove Envato Frame" guard.** Inside a sandboxed iframe the guard
  (`if (window.location != window.parent.location) top.location.href = …`) throws a
  `SecurityError` and kills all bundle.js execution after that line. The page captures the real
  parent first (`window.__melisRealParent = window.parent`) then redefines `window.parent` and
  `window.top` to return `window` itself, so the check evaluates false.
- **Load `melisDataTable.js` separately.** It is declared in `app.interface.php` but **absent
  from `bundle.js`**; `PlatformAssetsService` adds `/MelisCore/js/core/melisDataTable.js` to the
  JS queue (it exposes `window.melisDataTable`, used by the tools' DataTables).
- **Global `Proxy` shim.** For objects defined only inside bundle.js's `$(function(){…})` and
  not yet available when the tool's synchronous body scripts parse
  (`toolUserManagement`, `melisCoreTool`, `melisHelper`, `melisDataTable`) a no-op `Proxy` is
  installed so early property access doesn't throw before doc-ready.
- **Wrap jsCallbacks in try/catch.** App-config `jsCallbacks` (e.g. `setOnOff`,
  `iframeMarketplaceCallback`) are emitted as `<script>try { … } catch(e) { /* optional dep */ }`
  blocks so a callback whose dependency isn't loaded standalone doesn't break the page.
- **Force `X-Requested-With: XMLHttpRequest`** (see step 3 above).
- **Inject the module's JS/CSS `ressources`.** The core `bundle.js` only contains MelisCore's
  tools; module tools ship their own files (e.g. `news.tool.js` defines `window.initNewsList`).
  The plugin key is the **first path segment of the app-config path after trimming the leading
  slash**; `meliscore` is skipped (already bundled). The controller collects **every** root the
  tool relies on — its own plugin root, roots reached through `type` links (walked recursively,
  cycle-guarded), and roots reached through `forward` module nodes (case-insensitive
  module→root map) — and, for known composite editors (e.g. `meliscms_page`), a few extra roots
  contributed by other modules (all gated by `getItem()` returning null when the module is
  inactive → no phantom load). Missing resources otherwise cause `ReferenceError`s and empty
  DataTables / dead delegated `$('body')` handlers.
- **Head vs end-of-body JS ordering.** Platform JS (`bundle.js`/jQuery + core extras) loads in
  `<head>`; module `ressources` load **inside `<body>`, after the tab strip but before the tool
  HTML** — mirroring the classic BO — because several capture `$("body")` at load time to bind
  delegated handlers, and tool inline scripts register their own `$(function(){…})` in
  registration order.
- **Editor tab shell.** The page includes the classic tab framework anchors so classic edit
  flows (tabOpen / zoneReload / tabSwitch) work: the tab strip
  **`#melis-id-nav-bar-tabs`** (hidden via `display:none !important` — its tabs are mirrored to
  the React host's sub-tab bar), the content container **`#melis-id-body-content-load`** (its
  direct `.tab-pane` children are switched), and the global **`activeTabId`** initialised to
  the zone id so classic handlers reading it don't throw before any tab is opened.
- **Zone id / page id.** The initial zone id is `conf.id` (fallback `id_<keyView>`); for the
  CMS page editor (`key === 'meliscms_page'` with `?idPage`) it is prefixed with the page id
  (`<idPage>_<zoneId>`) to match the classic `activeTabId.split("_")[0]` convention.
- **`<base href="/">`** so relative tool AJAX URLs resolve from the site root (the page lives at
  `/melis/react-tool-page`, not `/melis`).
- **Modal mount point `#melis-modals-container`** and a modal self-heal watcher (clears a stray
  backdrop / scroll-lock left by legacy modals when no modal is actually shown).
- **Export fix.** Rebinds `melisCoreTool.exportData()` to an in-frame anchor click (the legacy
  `window.open` popup never lands the download inside a sandboxed iframe).
- **Tool-tab bridge & tool-result postMessage.** The hidden tab strip is mirrored to the host
  via `postMessage({ __melisToolTabs, … })` to `__melisRealParent`; a generic
  `{ __melisToolResult, url, data }` message is posted after a tool's JSON save so the host can
  react structurally (e.g. the CMS opening a newly created page). Legacy dashboard-bubble
  `/dashboard-plugin/` XHR polling is swallowed. No visible notification is bridged — legacy
  tools keep their own native feedback.

## B4. Platform assets — `PlatformAssetsService`

`PlatformAssetsService::build($sm)` returns `['css' => …, 'js' => …, 'inline' => …]` — the
platform asset list every tool iframe bootstraps with. Verified points:

- **CSS**: all module `bundle.css` files (loaded in parallel), prepended with Google Fonts and
  `/assets/css/schemes.css`, filtered to files that actually exist on disk.
- **JS queue** (order matters): `get-translations?locale=…`, `MelisCore/build/js/bundle.js`,
  then the non-bundled extras — `melisDataTable.js`, `loader.js` (`window.loader`),
  `findpage.tool.js` (`window.melisLinkTree`), `bootstrap-tagsinput.js`, `typeahead.bundle.js`,
  `moment/fr.js`, `melis_tinymce.js`.
- **Inline globals**: `basePath`, `primaryColor`, … read from the active platform scheme
  (`MelisCorePlatformSchemeService`), with Melis-default colours as fallback.
- **Bundle caching / self-healing** (`cachedModuleCss`, `regenerateBundles`): the expensive
  `MelisAssetManagerWebPack->getAssets(true)` call is cached (temp file, 600s TTL + in-process
  memo); if `etc/bundles/` was wiped by the Modules tool, it is regenerated (single-writer lock)
  or the concatenated route is swapped for the module's own `/melis/react-platform-bundle`.
- **`bust($url)`** appends a `?v=<mtime>` cache-buster to local asset URLs.

`LegacyWidgetCssService` backs the `react-legacy-widget-css` route (legacy BO CSS scoped under
`.melis-legacy-widget` so it can't leak onto the React shell — used by non-iframe legacy
widgets injected directly into the React DOM, e.g. dashboard plugin content).

## B5. SPA fallback — `SpaController`

`SpaController::spaAction()` serves the React shell for `/melis-react` and every client-side
deep link. Verified behaviour:

- It resolves `index.html` via **`$_SERVER['DOCUMENT_ROOT']`**, reading
  `…/vendor/melisplatform/melis-core/public/ui-react/index.html` (the React build lives in
  melis-core's `public/`, served at `/MelisCore/ui-react/`). It works regardless of where this
  module sits on disk.
- Returns `404` if the shell file is missing; otherwise returns the file contents as
  `text/html; charset=utf-8` with `Cache-Control: no-cache, no-store, must-revalidate` (the
  shell is never cached; the referenced assets are content-hashed).
- Real files (the SPA root `index.html` and the hashed assets) are streamed earlier by
  MelisAssetManager at bootstrap, so only **virtual client-side routes**
  (e.g. `/melis-react/news/5`) fall through to this controller.

The route (`meliscore-melis-react-spa`) is a **Regex** route with `priority => 1000` so it
wins over MelisFront's catch-all front route (which would otherwise resolve `/melis-react` as a
CMS page → 404). Its regex `'/melis-react(?<spa>/[a-zA-Z0-9_\-/~.]*)?'` includes `~` (composite
id separator, e.g. `mini-templates/<site>~<name>`) and `.` so those deep links resolve to the
SPA on a full-page reload instead of falling through to MelisFront.

## B6. The `toolpage_extensions` hook — how a module adds tool-specific quirks

Rather than hardcoding `if ($key === '…')` blocks in this module, tool-specific quirks live in
the **owning** module via an extension point. A module registers a service name under the
config key **`config('melis_react_override')['toolpage_extensions'][]`** and implements
`MelisReactOverride\Controller\PluginViewToolPageExtensionInterface` (verified):

```php
interface PluginViewToolPageExtensionInterface
{
    // Adjust the rendered zone HTML for a given melisKey before the page is assembled
    // (e.g. prepend a shared header/save-form so a standalone sub-tab still has a working Save).
    // Return $html unchanged for keys the extension doesn't care about.
    public function adjustToolHtml(string $key, string $html, array $jsCallBacks, PluginViewController $controller): string;

    // Adjust the platform asset bundle for a given melisKey. Return
    //   ['assets' => array, 'skipJsRoots' => array<string, true>]
    // 'skipJsRoots' lists plugin roots whose JS the extension already injected (e.g. into the
    // <head> bucket) so the generic end-of-body loop must NOT re-add them (double-load / double-bind).
    public function adjustToolAssets(string $key, string $html, array $assets, PluginViewController $controller): array;
}
```

`PluginViewController::toolPageExtensions()` (verified) resolves the registered names from
`$sm->get('Config')['melis_react_override']['toolpage_extensions']`, **silently skips** names
that aren't registered services or don't implement the interface (so an extension is fully
optional — module not installed → no-op), caches the list, and calls `adjustToolHtml()` (step 6)
and `adjustToolAssets()` (step 8) for each.

> **Why a config array and not a controller override:** contributions from different modules
> just **accumulate** regardless of module load order (unlike overriding a controller alias,
> where only the last-merged module wins).

**Example consumer:** MelisAICommunityExtensions registers
`MelisAICommunityExtensions\Controller\React\PluginViewToolPageExtension` under
`melis_react_override.toolpage_extensions` to inject its `tool.js`/`style.css` resources into
legacy tool pages served in the React "Old" view — see its React doc
(`../../../melis-ai-community-extensions/etc/MelisAI/doc/MelisAICommunityExtensions-react.md`).

## B7. Quick code map

```
melis-react-override/
├─ config/
│  └─ module.config.php                         # routes (react-tool-page, dashboard-*, react-platform-bundle,
│                                                #   react-legacy-widget-css, meliscore-melis-react-spa),
│                                                #   controller override, excluded_routes (public)
└─ src/
   ├─ Module.php                                # getConfig() + StandardAutoloader (loaded via application.config.php)
   ├─ Controller/
   │  ├─ PluginViewController.php               # toolPageAction() + buildToolPage() + dashboard-plugin actions,
   │  │                                         #   toolPageExtensions(), the documented iframe pitfalls
   │  ├─ SpaController.php                       # spaAction() → serves melis-core's ui-react/index.html
   │  └─ PluginViewToolPageExtensionInterface.php  # toolpage_extensions contract
   └─ Service/
      ├─ PlatformAssetsService.php              # build() (css/js/inline), bundle cache + regeneration, bust()
      └─ LegacyWidgetCssService.php             # legacy BO CSS scoped under .melis-legacy-widget
```

There is **no** `ui-react/`, **no** `public/ui-react/brick.manifest.json`, **no**
`config/react-api.php` and **no** `config/react.capabilities.php` — by design (this is
infrastructure, not a tool).

---

## Screenshot index

None — MelisReactOverride is an infrastructure module with **no UI of its own** and therefore
no React screenshots. (What appears on screen is the *legacy tool* it renders, or the *React
shell* it serves — both documented in their own modules.)

---

*Document for AI consumption (MelisAI MCP) — React back-office infrastructure of
`melisplatform/melis-react-override`. Part A = functional guide for users; Part B = technical
reference with verified identifiers for developers/AI. Last reviewed 2026-08-20.*
