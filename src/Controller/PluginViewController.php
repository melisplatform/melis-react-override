<?php

namespace MelisReactOverride\Controller;

use Laminas\Session\Container as SessionContainer;
use Laminas\View\Model\JsonModel;

/**
 * React-aware override of MelisCore's PluginViewController.
 *
 * When called from the React app (X-Requested-With: XMLHttpRequest) this
 * post-processes the JSON response so that:
 *
 *  1. Inline <script> blocks are extracted from the HTML fragment and appended
 *     to jsCallbacks — the React side (ZonePage) loads the full platform bundle
 *     first and then runs all callbacks in order, so no script fires before its
 *     dependencies are ready.
 *
 *  2. <script src="..."> tags are extracted and returned as a separate
 *     jsFiles array so ZonePage can inject them in the correct order before
 *     running callbacks.
 *
 *  3. The cleaned HTML (no <script> tags) is returned so the browser does not
 *     execute scripts a second time when it parses the srcDoc.
 *
 *  4. An `assets` object is added to the response containing the platform-wide
 *     CSS/JS bundle URLs (all modules combined, locale-aware translations) so
 *     the React side does not need to hard-code Melis asset paths.
 */
class PluginViewController extends \MelisCore\Controller\PluginViewController
{
    /**
     * Dashboard row (MelisCoreDashboardsTable) dedicated to the React dashboard's PER-PLUGIN
     * CONFIG. Deliberately SEPARATE from the geometry/layout row ('react_dashboard', written by
     * MelisReactApiController::dashboardLayoutAction): both would otherwise share the same
     * (d_dashboard_id, d_user_id) d_content XML and clobber each other (the layout save rewrites
     * the whole blob with geometry-only nodes, wiping config; and vice-versa). Keeping config in
     * its own row lets each side save independently. Config nodes are keyed by plugin_id = the raw
     * plugin name (what the config dialog passes), consistent between read and write.
     */
    private const REACT_DASHBOARD_CONFIG_ID = 'react_dashboard_config';

    /**
     * Returns a complete, self-contained HTML page for a Melis tool zone.
     *
     * The React app loads this URL directly in an <iframe src="...">.  No
     * client-side script extraction, JSON encoding, or eval() is required —
     * the browser handles asset loading and script execution naturally, exactly
     * as the classic back-office does.
     *
     * Usage:  GET /melis/react-tool-page?key=meliscore_tool_user
     */
    public function toolPageAction()
    {
        $request = $this->getRequest();
        $key     = $request->getQuery('key', '');

        if (!$key) {
            $this->getResponse()->setStatusCode(400);
            return $this->getResponse();
        }

        // ── Resolve the melisKey → appConfig path ────────────────────────────
        $melisAppConfig = $this->getServiceManager()->get('MelisCoreConfig');
        $melisKeys      = $melisAppConfig->getMelisKeys();
        $appConfigPath  = !empty($melisKeys[$key]) ? $melisKeys[$key] : $key;
        $parts          = explode('/', $appConfigPath);
        $keyView        = $parts[count($parts) - 1];

        $appsConfig = $melisAppConfig->getItem($appConfigPath);
        [$jsCallBacks, $datasCallback] = $melisAppConfig->getJsCallbacksDatas($appsConfig);

        // Force XHR mode so generateRec() renders zones with follow_regular_rendering:false
        // (e.g. meliscore_tool_user). Iframes are not XHR by nature but we want the same
        // full rendering path the classic back-office AJAX calls use.
        $this->getRequest()->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');

        // ── Render the zone HTML (with inline <script> tags intact) ──────────
        $zoneView = $this->generateRec($keyView, $appConfigPath, $jsCallBacks, []);
        $zoneView->setVariable('zoneconfig', $appsConfig);
        $zoneView->setVariable('parameters', []);
        $zoneView->setVariable('keyInterface', $keyView);

        if (!empty($zoneView->getVariable('jsCallBacks')) && is_array($zoneView->getVariable('jsCallBacks'))) {
            $jsCallBacks = \Laminas\Stdlib\ArrayUtils::merge($zoneView->getVariable('jsCallBacks'), $jsCallBacks);
            $jsCallBacks = array_unique($jsCallBacks);
        }

        $html = $this->renderViewRec($zoneView);

        // Module-owned HTML adjustments (e.g. MelisAI prepending its shared admin header/save-form
        // to a standalone sub-tab). See PluginViewToolPageExtensionInterface for the contract —
        // modules register themselves via config('melis_react_override')['toolpage_extensions'],
        // no edit to this file needed for new module-specific cases.
        foreach ($this->toolPageExtensions() as $extension) {
            $html = $extension->adjustToolHtml($key, $html, $jsCallBacks, $this);
        }

        // ── Build the page ────────────────────────────────────────────────────
        $assets = \MelisReactOverride\Service\PlatformAssetsService::build($this->getServiceManager());

        // Inject the tool's MODULE-specific JS/CSS resources (declared in the module's
        // app.interface.php 'ressources'). The core bundle.js only contains MelisCore
        // tools; module tools ship their own files (e.g. news.tool.js defines
        // window.initNewsList). Without them, tool-specific globals are undefined and the
        // DataTable init throws a ReferenceError (e.g. ajax `data: initNewsList`) → empty
        // list. The first path segment of the appConfig path is the plugin key. We skip
        // 'meliscore' because its resources are already in bundle.js (avoid double-load).
        // appConfigPath starts with a leading slash (e.g. "/meliscmsnews/interface/…"),
        // so the plugin key is the first segment AFTER trimming it.
        // Module ressources (jsRessources) are kept SEPARATE from the base platform JS: the base
        // (bundle.js/jQuery + core extras) loads in <head> so inline scripts inside the tool HTML
        // work at parse time, while module ressources (e.g. melisCms.js) load at the END of <body>
        // — they capture $("body") on load to bind delegated handlers, so <body> must exist first.
        // We need the ressources of EVERY app-config root this tool relies on — not just its own
        // plugin root, but also any root reached through a `type` link inside the interface tree.
        // Example: the Sites tool (root `meliscms`) wires its "Modules" tab via
        // `'type' => 'melistoolsitesmoduleload/interface/…'`; that root's sitesModuleLoad.tool.js
        // defines the jsCallback (moduleLoadJsCallback) + the activation-switch handlers. Injecting
        // only the first plugin root left those scripts out → the jsCallback was undefined (and
        // silently swallowed) → the activation toggles stayed raw checkboxes. Collect every root.
        // `type` links are resolved by generateRec at render time, so getItem() returns them
        // UNRESOLVED (just the path string). We must FOLLOW them recursively: the Sites tool chains
        // meliscms_tool_sites → (type) …_edit_site → tabs → (type) melistoolsitesmoduleload, and the
        // ressources we need (sitesModuleLoad.tool.js) live at that last root. Walk into each
        // referenced subtree (guarded against cycles) and remember every root we reach.
        $assets['jsRessources'] = [];
        $roots = [];
        $pluginKey = explode('/', ltrim($appConfigPath, '/'))[0] ?? '';
        if ($pluginKey !== '') {
            $roots[$pluginKey] = true;
        }
        $visited = [];
        $collectTypeRoots = function ($node) use (&$collectTypeRoots, &$roots, &$visited, $melisAppConfig) {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === 'type' && is_string($v) && $v !== '') {
                    $path = ltrim($v, '/');
                    $root = explode('/', $path)[0] ?? '';
                    if ($root !== '') {
                        $roots[$root] = true;
                    }
                    if (!isset($visited[$path])) {
                        $visited[$path] = true;
                        $sub = $melisAppConfig->getItem('/' . $path);
                        if (is_array($sub)) {
                            $collectTypeRoots($sub);
                        }
                    }
                } elseif (is_array($v)) {
                    $collectTypeRoots($v);
                }
            }
        };
        $collectTypeRoots($appsConfig);

        // MelisSmallBusiness contributes action buttons to the CMS page editor (page-lock
        // unlock, versioning, comments, workflow) through `forward` links — which the `type`
        // walk above cannot reach. Their click handlers live in the melisSB ressources
        // (e.g. pagelock.tool.js, whose delegated `a.btn-unlock-page` handler is otherwise
        // never bound in the iframe → the "Débloquer la page" button does nothing).
        // Add the melisSB root for the page editor so those buttons work. FULLY MODULAR: if
        // MelisSmallBusiness is not installed, the button isn't rendered and the getItem()
        // below returns null → no resource is injected (no phantom load).
        if ($key === 'meliscms_page') {
            $roots['melisSB'] = true;
        }

        // Module-owned asset adjustments (e.g. MelisAI forcing its module JS into the <head>
        // bucket for inline scripts that need the globals at parse time). $skipJsRoots lets an
        // extension mark a plugin root as "already injected" so the generic end-of-body loop
        // below doesn't re-add its JS — re-adding would double the <script> tag and double-bind
        // any delegated jQuery handlers it registers (e.g. two AJAX calls per click). See
        // PluginViewToolPageExtensionInterface — no edit to this file needed for new cases.
        $skipJsRoots = [];
        foreach ($this->toolPageExtensions() as $extension) {
            $result      = $extension->adjustToolAssets($key, $html, $assets, $this);
            $assets      = $result['assets'] ?? $assets;
            $skipJsRoots = array_merge($skipJsRoots, $result['skipJsRoots'] ?? []);
        }

        $jsRes = [];
        foreach (array_keys($roots) as $root) {
            // 'meliscore' ressources (JS + CSS) are already bundled in bundle.js → skip entirely
            // to avoid double-load.
            if (strtolower($root) === 'meliscore') {
                continue;
            }
            $resCss = $melisAppConfig->getItem("/$root/ressources/css");
            if (is_array($resCss)) {
                $assets['css'] = array_values(array_unique(array_merge($assets['css'], array_values($resCss))));
            }
            if (isset($skipJsRoots[$root])) {
                continue;
            }
            $resJs = $melisAppConfig->getItem("/$root/ressources/js");
            if (is_array($resJs)) {
                $jsRes = array_merge($jsRes, array_values($resJs));
            }
        }
        // MelisSmallBusiness's workflow validation button is contributed to several tools (pages,
        // news, blog) — and, crucially, often lives in content loaded LATER inside the iframe: the
        // news "Old" view loads the LIST tool page (meliscmsnews_left_menu, no button yet) and opens
        // the edit form via internal AJAX (tabOpen/zoneReload), which never re-runs buildToolPage.
        // So the workflow button appears without its handler script → DEMAND / Send Request /
        // Validate-Refuse (all delegated $body handlers in workflow.js) never fire. Inject workflow.js
        // on EVERY tool page when MelisSmallBusiness is active: as a delegated-handler script it is a
        // no-op where no button exists, and it catches the button wherever/whenever it is rendered.
        // FULLY MODULAR: getItem() returns null when SB is inactive → nothing injected.
        $sbJs = $melisAppConfig->getItem('/melisSB/ressources/js');
        if (is_array($sbJs)) {
            foreach ($sbJs as $sbScript) {
                if (is_string($sbScript) && preg_match('#/workflow\.js$#', $sbScript)) {
                    $jsRes[] = $sbScript;
                }
            }
        }

        $assets['jsRessources'] = array_values(array_unique($jsRes));

        // Zone id of the initial tool (used as the first tab-pane id so the classic
        // tab framework — tabOpen/zoneReload/tabSwitch — can open edit tabs alongside it).
        $zoneId = $appsConfig['conf']['id'] ?? ('id_' . $keyView);
        // Legacy page tools derive the page id from the tab/zone id via activeTabId.split("_")[0],
        // because the classic back-office opens a page edit in a tab whose pane id is
        // "<idPage>_id_meliscms_page". The standalone iframe renders the tool directly with the bare
        // conf.id ("id_meliscms_page"), so that split yielded "id" → wrong page id → e.g. the
        // "Débloquer la page" handler (pagelock.tool.js) called isPageLock with a bogus id and
        // silently died. Prefix the zone id with idPage to match the classic convention; DOM
        // container id, activeTabId and zoneReload target all use $zoneId so they stay consistent.
        $idPageParam = $request->getQuery('idPage', '');
        if ($key === 'meliscms_page' && $idPageParam !== '' && $idPageParam !== null) {
            $zoneId = $idPageParam . '_' . $zoneId;
        }
        $page   = $this->buildToolPage($html, $jsCallBacks, $assets, $zoneId, $key);

        $response = $this->getResponse();
        $response->setContent($page);
        $response->getHeaders()
            ->addHeaderLine('Content-Type',  'text/html; charset=utf-8')
            ->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /** @var PluginViewToolPageExtensionInterface[]|null */
    private ?array $toolPageExtensionsCache = null;

    /**
     * Resolves the module-registered toolPageAction() extensions (see
     * PluginViewToolPageExtensionInterface). Modules opt in via
     * config('melis_react_override')['toolpage_extensions'][] = '<service manager name>' — a
     * plain config array entry, so contributions from different modules just accumulate
     * regardless of module load order (unlike overriding a controller alias, which only the
     * last-merged module wins). Silently skips names that aren't registered services or don't
     * implement the interface, so an extension is fully optional (module not installed → no-op).
     *
     * @return PluginViewToolPageExtensionInterface[]
     */
    private function toolPageExtensions(): array
    {
        if ($this->toolPageExtensionsCache !== null) {
            return $this->toolPageExtensionsCache;
        }
        $sm    = $this->getServiceManager();
        $names = $sm->get('Config')['melis_react_override']['toolpage_extensions'] ?? [];
        $extensions = [];
        foreach ((array) $names as $name) {
            if (!is_string($name) || !$sm->has($name)) {
                continue;
            }
            $extension = $sm->get($name);
            if ($extension instanceof PluginViewToolPageExtensionInterface) {
                $extensions[] = $extension;
            }
        }
        return $this->toolPageExtensionsCache = $extensions;
    }

    /**
     * Assembles the standalone HTML document for toolPageAction().
     *
     * Assets are loaded as normal <link> / <script src> tags so the browser
     * executes them in order without any eval() trickery.  Inline jsCallbacks
     * (setOnOff, iframeMarketplaceCallback, …) are appended as raw <script>
     * blocks at the bottom of <body> — after the tool HTML — so the DOM exists
     * when they run.
     */
    private function buildToolPage(string $html, array $jsCallbacks, array $assets, string $zoneId = '', string $melisKey = ''): string
    {
        $zoneId    = $zoneId !== '' ? $zoneId : 'melis-zone-root-pane';
        $zoneIdJs  = json_encode($zoneId);
        $melisKeyJs = json_encode($melisKey);
        $melisKeyAttr = htmlspecialchars($melisKey, ENT_QUOTES);
        $zoneIdAttr   = htmlspecialchars($zoneId, ENT_QUOTES);

        // Per-tool exceptions (NOT a generic rule): a few legacy tools render their content flush
        // against the iframe edges. Add breathing room only for those, scoped by melisKey.
        // `>` direct-child so it hits the single active pane in both states (initial render has the
        // generateRec wrapper nested inside the buildToolPage pane; after a zoneReload+unwrap the
        // wrapper IS the pane). Attribute names are case-insensitive, so [data-meliskey] matches
        // both the buildToolPage `data-meliskey` and generateRec's `data-melisKey`.
        $extraStyle = '';
        if ($melisKey === 'meliscore_tool_other_config') {
            $extraStyle = '
    /* "Autres Configurations" — content sits flush to the edges; inset it. */
    #melis-id-body-content-load > .tab-pane[data-meliskey="meliscore_tool_other_config"] { padding: 12px 24px 24px; }';
        }

        // Per-tool exception: the CMS page-actions sticky toolbar (melisCms.js) only activates when
        // melisCore.screenSize (= the iframe window width, set ONCE at load) is > 1120. That legacy
        // threshold assumed the full-width classic BO; in the React BO the iframe is narrower (the
        // page-tree sidebar takes ~256px), so on browser windows < ~1376px the iframe drops under
        // 1120 and the toolbar never sticks — it just scrolls off-screen. Nudge screenSize past the
        // gate so the EXISTING legacy sticky logic runs (no competing handler). Positioning still
        // uses the real $body.width(), and the iframe is always a desktop tool view → safe to force.
        $extraScript = '';
        if ($melisKey === 'meliscms_page') {
            $extraScript = "\n  try { if (window.melisCore && melisCore.screenSize <= 1120) melisCore.screenSize = 1121; } catch(e) {}";
        }
        $cssLinks = implode("\n", array_map(
            static fn($h) => '  <link rel="stylesheet" href="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '" />',
            $assets['css'] ?? []
        ));

        $inlineGlobals = $assets['inline'] ?? '';

        // Platform JS (bundle.js/jQuery + core extras) — in <head> so inline scripts inside the
        // tool HTML have jQuery & the platform globals at parse time.
        $platformJs = implode("\n", array_map(
            static fn($s) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($s), ENT_QUOTES) . '"></script>',
            $assets['js'] ?? []
        ));

        // Module ressources (e.g. melisCms.js) — at the END of <body>: they capture $("body") on
        // load to bind delegated handlers, so <body> (and the tool HTML) must already exist, else
        // the handlers attach to an empty set and the tool's buttons are dead.
        $ressourceJs = implode("\n", array_map(
            static fn($s) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($s), ENT_QUOTES) . '"></script>',
            $assets['jsRessources'] ?? []
        ));

        // App-config jsCallbacks (e.g. setOnOff(), iframeMarketplaceCallback())
        // Wrapped in try/catch: some callbacks call functions that may not be loaded
        // in this standalone context (e.g. iframeMarketplaceCallback from MelisMarketPlace).
        $callbackBlocks = implode("\n", array_map(
            static fn($cb) => "<script>\ntry {\n" . $cb . "\n} catch(e) { /* optional dep */ }\n</script>",
            array_filter((array) $jsCallbacks)
        ));

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Resolve RELATIVE tool URLs from the site root, like the classic back-office does. Legacy tool
       JS sometimes builds AJAX URLs without a leading slash (e.g. MelisCmsStyle's modal:
       "melis/MelisCms/ToolStyle/renderToolStyleModalContainer"). In the classic BO the page lives at
       "/melis" (so relative → "/melis/..."), but this tool page lives at "/melis/react-tool-page",
       so the same relative URL would resolve to "/melis/melis/..." → 404 (the front catches it and
       renders the demo-site 404 under the tool). A <base href="/"> makes relatives resolve from root
       → "/melis/..." again. Absolute URLs (our assets, most tool AJAX) are unaffected. -->
  <base href="/" />
{$cssLinks}
  <script>
  /* Neutralise bundle.js's "Remove Envato Frame" guard (line 35561):
       if (window.location != window.parent.location) top.location.href = ...
     The guard throws a SecurityError inside our sandboxed iframe, killing all
     bundle.js execution after that line.  Making window.top/parent look like
     window itself causes the check to evaluate to false and the redirect is
     never attempted. */
  /* Capture the REAL parent window BEFORE the shim below overrides window.parent — the tool-tab
     bridge must postMessage to the actual host (React shell); after the shim window.parent
     returns `window` itself, so a post would go nowhere. */
  try { window.__melisRealParent = window.parent; } catch(e) {}
  try {
    Object.defineProperty(window, 'parent', { get: function(){ return window; }, configurable: true });
    Object.defineProperty(window, 'top',    { get: function(){ return window; }, configurable: true });
  } catch(e) {}
  /* Global proxy shim: absorbs any synchronous tool-script calls to objects
     (e.g. toolUserManagement.makeSwitch) that are defined only inside
     bundle.js's $(function(){...}) and therefore not yet available when
     the body scripts parse synchronously.  The Proxy returns a no-op for
     every property access, so callers don't throw even before doc-ready. */
  (function(){
    var _shim = function(name){
      if (window[name] && !(window[name].__isShim)) return;
      var handler = { get: function(t,p){ return typeof p==='string' ? function(){} : undefined; } };
      var p = new Proxy({}, handler);
      p.__isShim = true;
      window[name] = p;
    };
    ['toolUserManagement','melisCoreTool','melisHelper','melisDataTable'].forEach(_shim);
  })();
  /* bundle.js runs the dashboard bubble plugins' \$(document).ready everywhere,
     so inside a tool iframe they fire POST .../dashboard-plugin/<Plugin>/get*
     with a wrong base path → 404 noise. These calls are never legitimate in a
     standalone tool page, so we swallow them at the XHR layer (jQuery uses XHR). */
  (function(){
    var _open = XMLHttpRequest.prototype.open;
    var _send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url){
      this.__melisBlocked = (typeof url === 'string' && url.indexOf('/dashboard-plugin/') !== -1);
      return _open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function(){
      if (this.__melisBlocked) return; // drop legacy dashboard bubble polling
      // No toast forwarding here by design: legacy tools shown in an iframe must look and behave
      // exactly like direct /melis access, including their own native feedback (gritter toasts,
      // melisKoNotification's per-field modal, etc) — none of that is bridged to the React host
      // chrome. We still forward the raw tool-action result (below) purely so the host can react
      // structurally to a save (e.g. the CMS opening a newly created page + refreshing its tree) —
      // that postMessage carries no visible notification of its own.
      var xhr = this;
      this.addEventListener('load', function(){
        try {
          var ct = xhr.getResponseHeader && (xhr.getResponseHeader('Content-Type') || '');
          if (ct && ct.indexOf('json') === -1) return;
          var data = JSON.parse(xhr.responseText);
          if (!data || data.success === undefined) return;
          var host = window.__melisRealParent || window.parent;
          // Generic tool-action result: the host can react to a tool's save (e.g. the CMS opens the
          // newly created page + refreshes its tree). Carries the request URL + parsed JSON.
          try { host.postMessage({ __melisToolResult: true, url: (xhr.responseURL || ''), data: data }, '*'); } catch(e) {}
        } catch(e) {}
      });
      return _send.apply(this, arguments);
    };
  })();
{$inlineGlobals}
  </script>
  <style>
    html, body { margin: 0; padding: 0; background: transparent; }
    #content { margin-left: 0 !important; padding-left: 0 !important; width: 100% !important; }
    /* Tab framework (classic back-office) — pane switching driven by tabOpen/tabSwitch.
       The initial tool pane is .active; opening an edit tab toggles .active so only one
       pane shows at a time (same behaviour as the real back-office). */
    #melis-id-body-content-load > .tab-pane { display: none; }
    #melis-id-body-content-load > .tab-pane.active { display: block; }
    /* NESTED bootstrap tab widgets INSIDE a tool (e.g. the user-profile tabs Profil / Messenger):
       hide inactive panes. The rule above only covers the shell's OWN direct-child panes; nested
       .tab-content panes rely on Bootstrap's .tab-content>.tab-pane{display:none}, which isn't in
       the iframe CSS, so without this every nested tab pane showed at once (all tabs stacked). */
    .tab-content > .tab-pane:not(.active) { display: none; }
    .tab-content > .tab-pane.active { display: block; }
    /* The classic in-iframe tool tab strip is NOT part of the new UI — hide it entirely,
       everywhere (lists, sub-lists, sub-sub-lists, pages, every tool). The tab framework still
       works underneath: panes switch via tabOpen/tabSwitch, and edit screens return to the list
       through the tool's own save/cancel buttons (which call tabClose). Only the visible strip
       is removed. !important beats any inline display the framework toggles. */
    #melis-id-nav-bar-tabs { display: none !important; }
    /* The CMS page-actions toolbar becomes position:fixed (.sticky-pageactions) on scroll with
       top:47px — calibrated for the classic BO's 47px fixed top header, which does NOT exist inside
       this standalone iframe (the React shell header is outside the iframe). So the bar floated 47px
       below the top. Pin it to the iframe top. Scoped to .sticky-pageactions → no effect on other tools. */
    .sticky-pageactions { top: 0 !important; }{$extraStyle}
  </style>
</head>
<body>
<ul class="nav nav-tabs navbar-nav tabsbar" id="melis-id-nav-bar-tabs" role="tablist">
  <li class="nav-item" data-tool-id="{$zoneIdAttr}" data-tool-meliskey="{$melisKeyAttr}" role="presentation">
    <a data-bs-toggle="tab" class="nav-link tab-element active" href="#{$zoneIdAttr}" data-id="{$zoneIdAttr}">
      <i class="fa fa-list-alt"></i><span class="navtab-pagename">Liste</span>
    </a>
  </li>
</ul>
<a id="close-all-tab" style="display:none"></a>
<!-- Base platform JS (bundle.js/jQuery + core extras) is loaded HERE — inside <body>, AFTER the
     #melis-id-nav-bar-tabs strip but BEFORE the tool HTML — NOT in <head>. melisHelper (in bundle.js)
     is an IIFE that CACHES selectors at load time (`var \$body = \$("body"); var \$navTabs =
     \$("#melis-id-nav-bar-tabs");`). Loaded from <head> those caches are EMPTY (no <body> yet), which
     silently breaks the tab framework: e.g. tabClose() reads \$navTabs.children("li").length (→ 0, so
     it skips re-activating the previous tab) and \$navTabs.position().left (→ throws), leaving NO
     active pane → blank tool after a save+zoneReload (e.g. the Emails tool). Placing it after the
     strip fixes the caches; placing it before the tool HTML keeps jQuery available for the tool's
     own inline <script> blocks. -->
{$platformJs}
  <script>
  /* TinyMCE config preload — needs melis_tinymce.js (loaded just above). The nested page-edition
     iframe reads window.parent.melisTinyMCE.tinyMceConfigs[type]; this page IS that parent.
     melis_tinymce.js only auto-preloads when window.self === window.top (our Envato shim can fail
     that test in Chrome), so trigger it explicitly. Idempotent; harmless if already preloaded. */
  try { if (window.melisTinyMCE && melisTinyMCE.getTinyMceConfig) melisTinyMCE.getTinyMceConfig(); } catch(e) {}
  </script>
<div id="content">
  <div class="tab-content" id="melis-id-body-content-load">
    <div id="{$zoneIdAttr}" data-meliskey="{$melisKeyAttr}" class="tab-pane container-level-a active">
{$html}
    </div>
  </div>
</div>
<!-- Modal mount point: melisHelper.createModal() appends modals here ($("#melis-modals-container").append).
     The classic BO layout provides it; our standalone tool page must too, or modal-based edits
     (e.g. editing a slide in MelisCmsSlider) silently fail (appended to an empty selector). -->
<div id="melis-modals-container"></div>
{$ressourceJs}
<script>
  /* Initialise the active tab id so classic tool handlers (scroll, edit, categories…)
     that read the global activeTabId don't throw before any tab is opened. */
  try { window.activeTabId = {$zoneIdJs}; } catch(e) {}{$extraScript}
  /* Activate the first inner tab + its pane of each tab group, exactly like the classic zone
     loader does after a zoneReload (melisHelper.js:725-726). Tools/pages render their .nav-tabs
     with NO active tab in the markup (render-pagetab.phtml) and rely on this JS — without it the
     first tab's content (e.g. the page-edition iframe pane) stays hidden until manually clicked. */
  try {
    if (window.jQuery) {
      var z = jQuery('#' + {$zoneIdJs});
      z.find('.nav-tabs > li:first-child').addClass('active');
      z.find('.nav-tabs > li:first-child > a, .nav-tabs > li:first-child > .nav-link').addClass('active');
      z.find('.tab-content > div:first-child').addClass('active');
    }
  } catch(e) {}
  /* NESTED tab widgets inside a tool (e.g. user-profile Profil/Messenger tabs): drive pane
     switching explicitly. Bootstrap's tab toggle is unreliable in this standalone iframe — it
     adds .active to the clicked pane but doesn't always remove it from the previously-active one,
     so every visited tab stayed visible (all stacked). We don't preventDefault, so Bootstrap's own
     shown.bs.tab (which legacy tools listen to, e.g. to lazy-load content) still fires. Capture
     phase + idempotent: target pane/link active, siblings in the SAME group cleared. The hidden
     shell strip (#melis-id-nav-bar-tabs) is never clicked, so this doesn't touch shell tabs. */
  document.addEventListener('click', function(e){
    var a = e.target && e.target.closest && e.target.closest('a[data-bs-toggle="tab"], a[data-toggle="tab"]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '#') return;
    var pane = document.getElementById(href.slice(1));
    var tc = pane && pane.parentElement;
    if (!tc) return;
    Array.prototype.forEach.call(tc.children, function(c){
      if (c.classList && c.classList.contains('tab-pane')) c.classList.remove('active', 'show');
    });
    pane.classList.add('active', 'show');
    var li = a.closest('li');
    var ul = li && li.parentElement;
    if (ul) Array.prototype.forEach.call(ul.children, function(el){ if (el.classList) el.classList.remove('active'); });
    a.classList.add('active');
    if (li) li.classList.add('active');
  }, true);
  /* Tool-tab bridge: the in-iframe tab strip (#melis-id-nav-bar-tabs) is hidden (CSS above);
     mirror its tabs to the React host's sub-tab bar (under the topbar, grouped under the tool)
     and drive pane switching from there. The host shows the tabs and posts back activate/close
     commands; we run them through the classic API (tabSwitch/tabClose) so each × closes ONLY
     that tab. Posts to __melisRealParent (the shim made window.parent === window). */
  (function(){
    var bar = document.getElementById('melis-id-nav-bar-tabs');
    if (!bar) return;
    var melisKey = {$melisKeyJs};
    function report(){
      // Collect ALL tab elements, including ones nested in a .nav-group-dropdown — some tools
      // (e.g. the Sites tool) open edit tabs grouped UNDER the primary tab instead of as direct
      // siblings, so iterating only bar's direct <li> children would miss them.
      // window.activeTabId is Melis' authoritative "current tab" (the .active class on the <a>
      // is NOT reliable for record tabs — e.g. the slider keeps it on the list). Fall back to
      // the class only if the global is unavailable.
      var act = ''; try { act = window.activeTabId || ''; } catch(e) {}
      var tabs = [], els = bar.querySelectorAll('a.tab-element'), i, a, span, label, did;
      for (i = 0; i < els.length; i++) {
        a = els[i];
        span = a.querySelector('.navtab-pagename');
        did = a.getAttribute('data-id') || '';
        label = ((span ? span.textContent : a.getAttribute('title')) || did || '').trim();
        tabs.push({ id: did, label: label,
                    active: act ? (did === act) : a.classList.contains('active'), primary: (tabs.length === 0) });
      }
      var host = window.__melisRealParent || window.parent;
      try { host.postMessage({ __melisToolTabs: true, melisKey: melisKey, tabs: tabs }, '*'); } catch(e) {}
    }
    // Debounce: a single tab action fires several mutations + an intermediate state where the
    // list is briefly active again — coalesce them so the host settles on the final active tab
    // (no URL flicker).
    var _rt; function scheduleReport(){ try { clearTimeout(_rt); } catch(e) {} _rt = setTimeout(report, 60); }
    try { new MutationObserver(scheduleReport).observe(bar, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] }); } catch(e) {}
    report();
    window.addEventListener('message', function(e){
      var d = e.data || {};
      if (!d.__melisToolTabCmd || d.melisKey !== melisKey || !d.id) return;
      if (d.cmd === 'activate') {
        try { if (window.melisHelper && melisHelper.tabSwitch) melisHelper.tabSwitch(d.id);
          else { var t = bar.querySelector("a.tab-element[data-id='" + d.id + "']"); if (t) t.click(); } } catch(err) {}
      } else if (d.cmd === 'close') {
        // Separate try/catch so a tabClose error doesn't block the follow-up tabSwitch.
        try { if (window.melisHelper && melisHelper.tabClose) melisHelper.tabClose(d.id);
          else { var c = bar.querySelector("a.close-tab[data-id='" + d.id + "']"); if (c) c.click(); } } catch(err) {}
        try { if (d.next && window.melisHelper && melisHelper.tabSwitch) melisHelper.tabSwitch(d.next); } catch(err) {}
      }
      // A pure tab switch may not mutate the observed bar (active class can stay put) → report
      // explicitly so the host's active tab (and the URL) follows.
      try { scheduleReport(); } catch(err) {}
    });
  })();
  /* Fix legacy data export (CSV/Excel) inside this standalone iframe. melisCoreTool.exportData()
     does `window.open(url,"_blank")` then `newWindow.onload = () => newWindow.close()`. In the
     sandboxed iframe that popup opens a blank about:blank tab and the download never lands (the
     attachment response fires no load event; the popup inherits the sandbox). Replace it with an
     in-frame anchor click: the URL is same-origin and returns Content-Disposition: attachment, so
     the browser downloads it directly (needs the iframe `allow-downloads` sandbox flag) with the
     server-provided filename and no stray tab. Generic — benefits every legacy tool's export. */
  try {
    var __melisDownload = function(url){
      var a = document.createElement('a');
      a.href = url;
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      setTimeout(function(){ try { a.remove(); } catch(e) {} }, 1000);
    };
    if (window.melisCoreTool) window.melisCoreTool.exportData = function(url){ __melisDownload(url); };
  } catch(e) {}
  /* No notification bridge here by design: legacy tools shown in an iframe must look and behave
     exactly like direct /melis access — melisOkNotification (gritter, renders inside the iframe)
     and melisKoNotification (native centered per-field modal) are both left completely untouched.
     Nothing from a legacy tool's own notifications is forwarded to the React host chrome. */
</script>
{$callbackBlocks}
</body>
</html>
HTML;
    }

    /**
     * Renders a single legacy dashboard plugin (e.g. CheckWsStatusPlugin) as a
     * minimal standalone HTML page for use in a React dashboard iframe widget.
     *
     * Usage:  GET /melis/react-dashboard-plugin?plugin=CheckWsStatusPlugin
     */
    public function dashboardPluginPageAction()
    {
        $pluginName = $this->getRequest()->getQuery('plugin', '');
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return $this->getResponse();
        }

        $sm             = $this->getServiceManager();
        $melisAppConfig = $sm->get('MelisCoreConfig');
        $melisKeys      = $melisAppConfig->getMelisKeys();
        $appConfigPath  = $melisKeys[$pluginName] ?? null;

        if (!$appConfigPath) {
            $this->getResponse()->setStatusCode(404);
            return $this->getResponse();
        }

        $parts   = explode('/', $appConfigPath);
        $keyView = $parts[count($parts) - 1];

        $appsConfig = $melisAppConfig->getItem($appConfigPath);
        [$jsCallBacks] = $melisAppConfig->getJsCallbacksDatas($appsConfig);

        $this->getRequest()->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');

        $zoneView = $this->generateRec($keyView, $appConfigPath, $jsCallBacks, []);
        $zoneView->setVariable('zoneconfig', $appsConfig);
        $zoneView->setVariable('parameters', []);
        $zoneView->setVariable('keyInterface', $keyView);

        if (!empty($zoneView->getVariable('jsCallBacks')) && is_array($zoneView->getVariable('jsCallBacks'))) {
            $jsCallBacks = \Laminas\Stdlib\ArrayUtils::merge($zoneView->getVariable('jsCallBacks'), $jsCallBacks);
            $jsCallBacks = array_unique($jsCallBacks);
        }

        $html   = $this->renderViewRec($zoneView);
        $assets = \MelisReactOverride\Service\PlatformAssetsService::build($sm);

        // Collect module-specific resources (same logic as toolPageAction).
        $pluginKey = explode('/', ltrim($appConfigPath, '/'))[0] ?? '';
        $jsRes = [];
        if ($pluginKey !== '' && strtolower($pluginKey) !== 'meliscore') {
            $resJs  = $melisAppConfig->getItem("/$pluginKey/ressources/js");
            $resCss = $melisAppConfig->getItem("/$pluginKey/ressources/css");
            if (is_array($resJs))  { $jsRes = array_values($resJs); }
            if (is_array($resCss)) {
                $assets['css'] = array_values(array_unique(array_merge($assets['css'], array_values($resCss))));
            }
        }

        $cssLinks = implode("\n", array_map(
            static fn($h) => '  <link rel="stylesheet" href="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '" />',
            $assets['css'] ?? []
        ));
        $headJs = implode("\n", array_map(
            static fn($h) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '"></script>',
            $assets['js'] ?? []
        ));
        $bodyJs = implode("\n", array_map(
            static fn($h) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '"></script>',
            $jsRes
        ));
        $callbackBlocks = implode("\n", array_map(
            static fn($cb) => "<script>\n(function(){\ntry{\n{$cb}\n}catch(e){console.warn(e);}\n})();\n</script>",
            $jsCallBacks
        ));

        $inlineGlobals = $assets['inline'] ?? '';

        $page = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Résout les URLs RELATIVES depuis la racine (comme toolPageAction). Sans ça, le plugin est
       chargé depuis "/melis/react-dashboard-plugin?plugin=X", donc un lien/AJAX relatif du legacy
       (ex. moxiemanager skin CSS, ou "melis/dashboard-plugin/…") se résout contre cette URL →
       "/melis/melis/dashboard-plugin/…" (404) ou une CSS renvoyant du HTML. <base href="/"> corrige. -->
  <base href="/" />
  <style>body { margin: 0; overflow: auto; } .widget-header-content, .widget-header-actions { display: none !important; }</style>
  <script>
  /* Neutralise bundle.js "Remove Envato Frame" guard — same shim as toolPageAction. */
  try { window.__melisRealParent = window.parent; } catch(e) {}
  try {
    Object.defineProperty(window, 'parent', { get: function(){ return window; }, configurable: true });
    Object.defineProperty(window, 'top',    { get: function(){ return window; }, configurable: true });
  } catch(e) {}
  /* bundle.js lance les plugins de bulles (News/Notifications/Chat) partout → dans cette iframe
     ils POSTent .../dashboard-plugin/<Plugin>/get* avec une mauvaise base → 404. Le dashboard React
     a sa PROPRE API de bulles ; ces appels ne sont jamais légitimes ici → on les avale au niveau XHR. */
  (function(){
    var _open = XMLHttpRequest.prototype.open;
    var _send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url){
      this.__melisBlocked = (typeof url === 'string' && url.indexOf('/dashboard-plugin/') !== -1);
      return _open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function(){
      if (this.__melisBlocked) return;
      return _send.apply(this, arguments);
    };
  })();
  /* moxiemanager (chargé par des widgets à base de TinyMCE, ex. MelisSBWorkflowPlugin) calcule l'URL
     de sa skin CSS lui-même et IGNORE <base> → il injecte une <link> cassée pointant vers
     "…react-dashboard-plugin?plugin=…/skins/*.css" (renvoie du HTML → "Refused to apply style").
     Une telle feuille de style n'est jamais valide → on retire ces <link> dès leur insertion. */
  try {
    var _isBad = function(n){ return n && n.tagName === 'LINK' && (n.getAttribute('href') || '').indexOf('react-dashboard-plugin?plugin=') !== -1; };
    var _kill  = function(n){ if (_isBad(n)) { try { n.remove(); } catch(e) {} } };
    /* Empêche AUSSI la création de la <link> cassée en amont (moxman fait souvent
       document.createElement('link') + setAttribute('href', …)) : on intercepte setAttribute. */
    var _setAttr = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name, value){
      if (this.tagName === 'LINK' && name === 'href' && typeof value === 'string' && value.indexOf('react-dashboard-plugin?plugin=') !== -1) return;
      return _setAttr.apply(this, arguments);
    };
    new MutationObserver(function(muts){
      muts.forEach(function(m){
        if (m.type === 'attributes') { _kill(m.target); }
        if (m.addedNodes) { Array.prototype.forEach.call(m.addedNodes, _kill); }
      });
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
  } catch(e) {}
{$inlineGlobals}
  </script>
{$cssLinks}
{$headJs}
</head>
<body>
{$html}
{$bodyJs}
{$callbackBlocks}
</body>
</html>
HTML;

        $response = $this->getResponse();
        $response->setContent($page);
        $response->getHeaders()
            ->addHeaderLine('Content-Type',  'text/html; charset=utf-8')
            ->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Page HTML autonome (pour iframe dans une modale React) affichant le FORMULAIRE DE
     * CONFIGURATION d'un plugin dashboard legacy — équivalent React du bouton engrenage
     * (`dashboard-plugin-properties` → renderDashboardPluginModal) de gridstack.init.js.
     *
     * On réutilise `createOptionsForms()` du plugin (via ControllerPluginManager) pour obtenir
     * les onglets de config, exactement comme renderDashboardPluginModalAction. Beaucoup de
     * plugins n'ont AUCUNE option (onglet `empty`) → on affiche alors un message "aucune option".
     * Les valeurs déjà enregistrées sont relues depuis la ligne de dashboard dédiée
     * `react_dashboard_config` (cf. self::REACT_DASHBOARD_CONFIG_ID / dashboardPluginConfigSaveAction) :
     * getPluginConfig() → getPluginValueFromDb() charge le nœud <plugin plugin_id="<PluginName>">
     * de cette ligne dans pluginConfig['datas'], que createOptionsForms() utilise pour préremplir.
     *
     * GET /melis/react-dashboard-plugin-config?plugin=<PluginName>
     */
    public function dashboardPluginConfigPageAction()
    {
        $pluginName = $this->getRequest()->getQuery('plugin', '');
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return $this->getResponse();
        }

        $sm = $this->getServiceManager();

        // Récupère les onglets de config du plugin (même chemin que la modale legacy).
        $tabs = [];
        try {
            $melisPlugin = $sm->get('ControllerPluginManager')->get($pluginName);
            $melisPlugin->setUpdatesPluginConfig(['dashboard_id' => self::REACT_DASHBOARD_CONFIG_ID, 'plugin_id' => $pluginName]);
            $melisPlugin->getPluginConfig();
            $tabs = $melisPlugin->createOptionsForms();
        } catch (\Throwable $e) {
            $tabs = [];
        }

        $translator = null;
        try { $translator = $sm->get('translator'); } catch (\Throwable) {}
        $tr = static function (string $key) use ($translator): string {
            if (!$translator) return $key;
            try { $t = (string) $translator->translate($key); return $t !== '' ? $t : $key; } catch (\Throwable) { return $key; }
        };

        // Assemble le HTML des onglets (barre d'onglets si >1) + repère si toute la config est vide.
        $allEmpty = true;
        // Reproduit EXACTEMENT la structure de la modale legacy (render-dashboard-plugin-modal.phtml) :
        // wizard > widget widget-tabs widget-tabs-double … — c'est ce que le CSS Melis stylise. Sans ce
        // wrapper, les onglets/formulaires n'avaient pas le rendu Melis attendu.
        $navHtml = '';
        $paneHtml = '';
        foreach ($tabs as $i => $tab) {
            if (empty($tab['empty'])) { $allEmpty = false; }
            $name   = htmlspecialchars((string) ($tab['name'] ?? ('Tab ' . ($i + 1))), ENT_QUOTES);
            $icon   = !empty($tab['icon']) ? '<i class="' . htmlspecialchars((string) $tab['icon'], ENT_QUOTES) . '"></i> ' : '';
            $active = $i === 0 ? ' active' : '';
            $navHtml  .= "<li class=\"nav-item{$active}\"><a href=\"#plugin_modal_id_tab_{$i}\" class=\"nav-link f-awesome{$active}\" data-bs-toggle=\"tab\" aria-expanded=\"true\" title=\"{$name}\">{$icon}{$name}</a></li>";
            $paneHtml .= "<div class=\"tab-pane{$active}\" id=\"plugin_modal_id_tab_{$i}\"><div class=\"row\"><div class=\"col-md-12\"><div class=\"plugin-container\">" . ($tab['html'] ?? '') . "</div></div></div></div>";
        }
        if ($tabs === []) {
            $navHtml  = '<li class="nav-item active"><a href="#plugin_modal_id_tab_0" class="nav-link f-awesome active" data-bs-toggle="tab"><i class="fa fa-cog"></i> '
                . htmlspecialchars($tr('tr_meliscore_dashboard_plugin_common_tab_properties'), ENT_QUOTES) . '</a></li>';
            $paneHtml = '<div class="tab-pane active" id="plugin_modal_id_tab_0"><div class="row"><div class="col-md-12"><div class="plugin-container"><p class="text-muted" style="padding:8px 4px;">'
                . htmlspecialchars($tr('tr_meliscore_dashboard_plugin_common_tab_properties'), ENT_QUOTES) . '</p></div></div></div></div>';
        }

        // Bouton "Appliquer" uniquement si le plugin a de vraies options (comme la modale legacy).
        $saveBtn = $allEmpty ? '' :
            '<button id="dashboard-plugin-properties-save" class="btn btn-success float-right"><i class="fa fa-save"></i> '
            . htmlspecialchars($tr('tr_meliscore_plugins_modal_apply'), ENT_QUOTES) . '</button>';

        $assets   = \MelisReactOverride\Service\PlatformAssetsService::build($sm);
        $cssLinks = implode("\n", array_map(
            static fn($h) => '  <link rel="stylesheet" href="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '" />',
            $assets['css'] ?? []
        ));
        $headJs = implode("\n", array_map(
            static fn($h) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '"></script>',
            $assets['js'] ?? []
        ));
        $inlineGlobals = $assets['inline'] ?? '';

        $page = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="/" />
  <style>body{margin:0;padding:0;background:#fff}.widget-dnd-modal{box-shadow:none!important}</style>
  <script>
  try { Object.defineProperty(window,'parent',{get:function(){return window;},configurable:true}); } catch(e){}
{$inlineGlobals}
  </script>
{$cssLinks}
{$headJs}
</head>
<body>
  <div class="wizard">
    <div class="widget widget-tabs widget-tabs-double widget-tabs-responsive margin-none border-none widget-dnd-modal">
      <div class="widget-head">
        <span class="widget-melis-tabprev"><i class="fa fa-angle-left"></i></span>
        <div class="melis-whead-box">
          <ul class="nav nav-tabs">{$navHtml}</ul>
        </div>
        <span class="widget-melis-tabnext"><i class="fa fa-angle-right"></i></span>
      </div>
      <div class="widget-body innerAll inner-2x">
        <div class="tab-content page-evolution-content">{$paneHtml}</div>
        <br>
        <div class="clearfix">{$saveBtn}</div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;

        $response = $this->getResponse();
        $response->setContent($page);
        $response->getHeaders()
            ->addHeaderLine('Content-Type',  'text/html; charset=utf-8')
            ->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Persists a legacy dashboard plugin's config (the Save button of the React config dialog).
     *
     * Mirrors the classic flow (gridstack.init.js dashboardPluginModalSubmit →
     * validateDashboardPluginModal → savePlugins) but scoped to a single plugin and to the
     * dedicated REACT_DASHBOARD_CONFIG_ID row, so it never touches the React geometry row:
     *
     *  1. Instantiate the plugin, load its config from that row (getPluginConfig →
     *     getPluginValueFromDb, so existing values are the merge base).
     *  2. Validate the posted form via createOptionsForms() in `validate` mode — same code path as
     *     DashboardPluginsController::validateDashboardPluginModalAction (returns per-tab success +
     *     field errors). We flip the request to `?validate` so plugins take that branch.
     *  3. On success, serialize the plugin's config with its own savePluginConfigToXml($post) and
     *     REPLACE that plugin's <plugin> node inside the config row's XML, PRESERVING every other
     *     plugin's node (so saving plugin A never wipes plugin B).
     *
     * POST /melis/react-dashboard-plugin-config-save   (form-urlencoded: plugin + form fields)
     * Response: { success: bool, errors?: array }
     */
    public function dashboardPluginConfigSaveAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Method not allowed']);
        }

        $post       = $request->getPost()->toArray();
        $pluginName = (string) ($post['plugin'] ?? $post['pluginName'] ?? $request->getQuery('plugin', ''));
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['success' => false, 'error' => 'Invalid plugin']);
        }

        $sm = $this->getServiceManager();

        // Current user id (config is stored per user, like every dashboard row).
        $userId = 0;
        try { $userId = (int) $sm->get('MelisCoreAuth')->getStorage()->read()->usr_id; } catch (\Throwable) {}

        // ── Instantiate the plugin + validate the posted form ────────────────────
        // createOptionsForms() reads the `validate` flag from the QUERY and the values from the
        // POST (see MelisCommerce…SalesRevenue::createOptionsForms). Flip the shared request to
        // validate mode, exactly like the classic ?validate URL.
        $request->getQuery()->set('validate', 1);

        $errorsTabs = [];
        try {
            $melisPlugin = $sm->get('ControllerPluginManager')->get($pluginName);
            $melisPlugin->setUpdatesPluginConfig(['dashboard_id' => self::REACT_DASHBOARD_CONFIG_ID, 'plugin_id' => $pluginName]);
            $melisPlugin->getPluginConfig();
            $errorsTabs = $melisPlugin->createOptionsForms();
        } catch (\Throwable $e) {
            return new JsonModel(['success' => false, 'error' => 'Plugin cannot be created']);
        }

        // A tab is a failure only when it explicitly reports success=false (empty/no-option tabs
        // carry no 'success' key → treated as OK, matching the legacy validate action).
        $success = true;
        if (is_array($errorsTabs)) {
            foreach ($errorsTabs as $tab) {
                if (is_array($tab) && array_key_exists('success', $tab) && !$tab['success']) {
                    $success = false;
                }
            }
        }
        if (!$success) {
            return new JsonModel(['success' => false, 'errors' => $errorsTabs]);
        }

        // ── Persist: replace this plugin's node, keep the others ─────────────────
        $configFragment = '';
        try { $configFragment = (string) $melisPlugin->savePluginConfigToXml($post); } catch (\Throwable) {}

        $newNode = '<plugin plugin="' . htmlspecialchars($pluginName, ENT_QUOTES)
            . '" plugin_id="' . htmlspecialchars($pluginName, ENT_QUOTES) . '">' . "\n"
            . $configFragment . '</plugin>' . "\n";

        try {
            $tbl        = $sm->get('MelisCoreDashboardsTable');
            $existing   = $tbl->getDashboardPlugins(self::REACT_DASHBOARD_CONFIG_ID, $userId)->current();
            $existingId = $existing ? ($existing->d_id ?? null) : null;

            // Re-serialize every OTHER plugin's node untouched (asXML preserves it verbatim).
            $otherNodes = '';
            if ($existing && !empty($existing->d_content)) {
                $doc = @simplexml_load_string($existing->d_content);
                if ($doc !== false && isset($doc->plugin)) {
                    foreach ($doc->plugin as $p) {
                        if ((string) $p['plugin_id'] !== $pluginName) {
                            $otherNodes .= $p->asXML() . "\n";
                        }
                    }
                }
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Plugins>\n" . $otherNodes . $newNode . '</Plugins>';
            $tbl->save(
                ['d_dashboard_id' => self::REACT_DASHBOARD_CONFIG_ID, 'd_user_id' => $userId, 'd_content' => $xml],
                $existingId
            );
        } catch (\Throwable $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }

        return new JsonModel(['success' => true]);
    }

    public function generateAction()
    {
        $result = parent::generateAction();

        // Only post-process AJAX JSON responses
        if (!($result instanceof JsonModel)) {
            return $result;
        }

        // Always inject platform assets so the React app can build its srcDoc.
        $result->setVariable('assets', $this->getPlatformAssets());

        // Script extraction is React-only: the classic back-office expects scripts
        // to remain inline in `html` so DataTables and other init code runs via
        // its own jQuery.html() injector. Only strip scripts when the React app
        // explicitly signals this request with the X-Melis-React header.
        $isReact = $this->getRequest()->getHeader('X-Melis-React') !== false;
        if (!$isReact) {
            return $result;
        }

        $html        = (string) ($result->getVariable('html')        ?? '');
        $jsCallbacks = (array)  ($result->getVariable('jsCallbacks') ?? []);
        $jsDatas     = $result->getVariable('jsDatas');

        [$cleanHtml, $inlineScripts, $srcScripts] = $this->extractScripts($html);

        $jsCallbacks = array_values(array_unique(array_merge($jsCallbacks, $inlineScripts)));

        $result->setVariables([
            'html'        => $cleanHtml,
            'jsCallbacks' => $jsCallbacks,
            'jsFiles'     => $srcScripts,
            'jsDatas'     => $jsDatas,
        ]);

        return $result;
    }

    /**
     * Returns every asset the Melis layout (layoutCore.phtml) loads, so the
     * React ZonePage can replicate the exact same environment:
     *
     *  css     — Google Fonts → all module CSS (from ressources/css) → platform colour scheme
     *  js      — locale translations → all module JS (from ressources/js)
     *  inline  — JS globals that Melis tool scripts expect before any bundle runs
     *
     * Uses MelisCoreHeadPluginHelper directly (same as layoutCore.phtml) to collect
     * every file declared in every installed module's app.interface.php.
     */
    private function getPlatformAssets(): array
    {
        return \MelisReactOverride\Service\PlatformAssetsService::build($this->getServiceManager());
    }

    /**
     * Parses $html and returns:
     *   [0] string  — HTML with all <script> tags removed
     *   [1] string[] — inline script bodies in document order
     *   [2] string[] — external script src URLs in document order
     *
     * Uses a positional scanner rather than a regex so that script bodies
     * containing the literal string </script> (e.g. inside a string or comment)
     * are captured intact instead of being truncated at the first match.
     */
    private function extractScripts(string $html): array
    {
        if (empty($html)) {
            return [$html, [], []];
        }

        $inline    = [];
        $srcs      = [];
        $cleanHtml = '';
        $pos       = 0;
        $len       = strlen($html);

        while ($pos < $len) {
            $scriptStart = stripos($html, '<script', $pos);
            if ($scriptStart === false) {
                $cleanHtml .= substr($html, $pos);
                break;
            }

            // Append HTML before this <script
            $cleanHtml .= substr($html, $pos, $scriptStart - $pos);

            // Find end of opening tag
            $tagEnd = strpos($html, '>', $scriptStart);
            if ($tagEnd === false) {
                $cleanHtml .= substr($html, $scriptStart);
                break;
            }

            $openTag = substr($html, $scriptStart, $tagEnd - $scriptStart + 1);
            $pos     = $tagEnd + 1;

            // Find matching </script> (first occurrence after the opener)
            $closeStart = stripos($html, '</script>', $pos);
            if ($closeStart === false) {
                break; // malformed — no closing tag
            }

            $body = trim(substr($html, $pos, $closeStart - $pos));
            $pos  = $closeStart + strlen('</script>');

            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/', $openTag, $sm)) {
                $srcs[] = $sm[1];
            } elseif ($body !== '') {
                $inline[] = $body;
            }
        }

        return [$cleanHtml, $inline, $srcs];
    }
}
