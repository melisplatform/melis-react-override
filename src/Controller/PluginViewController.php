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
     * Defense-in-depth authentication guard for the standalone tool / dashboard-plugin
     * renderers in this controller. Every action below is ALREADY protected globally by
     * MelisCore\Module::checkIdentity() (attached on EVENT_ROUTE), which redirects to
     * /melis/login or returns 401/404 for any unauthenticated request BEFORE the action
     * runs. This second, local barrier guarantees a future routing/excluded_routes
     * regression can never expose a legacy tool renderer to an anonymous caller — it
     * mirrors the per-action isAuthenticated() check already enforced in
     * MelisReactApiController. In normal flow it never triggers.
     *
     * @return \Laminas\Http\Response|null 401 response when anonymous, null when allowed.
     */
    private function denyIfUnauthenticated()
    {
        if (!$this->getServiceManager()->get('MelisCoreAuth')->hasIdentity()) {
            $response = $this->getResponse();
            $response->setStatusCode(401);
            $response->setContent('');
            return $response;
        }

        return null;
    }

    /** Current PHP session id, or '' when no session is active. */
    private function currentSessionId()
    {
        return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
    }

    /**
     * Restores the session id a legacy tool rotated while its zone was being rendered.
     *
     * A few legacy tools call `SessionManager::regenerateId()` in their renderToolAction — e.g.
     * the Dashboard / Templating Plugin Creators, which reuse the fresh id as the name of their
     * temp-thumbnail folder. Laminas defaults that call to `$deleteOldSession = true`, i.e.
     * `session_regenerate_id(true)`: the PREVIOUS session file is DELETED.
     *
     * In the classic back-office that is harmless — one request at a time, and the new cookie
     * lands before the next one. Here the tool zone renders inside an IFRAME of the React shell,
     * while the shell, its bricks and the pollers fire their own requests in parallel. Every one
     * of them still carries the OLD PHPSESSID, whose file no longer exists → PHP opens an empty
     * session → `isAuthenticated()` is false → **HTTP 401**, and their `Set-Cookie` overwrites the
     * good session cookie → the whole tab is logged out and bounced to /melis/login. It is a race,
     * so it strikes intermittently (typically after the Plugin Creator's post-generation reload,
     * when the stale tool iframe remounts next to a burst of API calls).
     *
     * We therefore re-open the ORIGINAL session id after the zone is rendered, carrying over
     * everything the tool just wrote (its container included). The session is left OPEN so the
     * rest of the request behaves normally, and PHP re-emits the cookie with the original id —
     * after the one `regenerateId()` sent, so the browser keeps the id it already had.
     *
     * The legacy tool is untouched: it still gets its unique id, only the auth session survives.
     */
    private function pinSessionId($expected)
    {
        if ($expected === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_id() === $expected || headers_sent()) {
            return;
        }

        $data = $_SESSION;          // état complet, y compris ce que le tool vient d'écrire
        session_write_close();      // obligatoire : on ne peut pas changer d'id session active
        session_id($expected);

        // `regenerateId()` a SUPPRIMÉ le fichier de l'ancien id ; avec use_strict_mode=1 (défaut
        // durci), PHP refuse de « ressusciter » un id sans fichier et en génère encore un autre.
        // On le désactive le temps du session_start(), puis on le remet.
        $strict = ini_get('session.use_strict_mode');
        ini_set('session.use_strict_mode', '0');
        session_start();            // recrée le fichier supprimé par regenerateId()
        ini_set('session.use_strict_mode', (string) $strict);

        $_SESSION = $data;          // écrit en fin de requête, comme d'habitude
    }

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
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

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

        // Some legacy tools rotate the PHP session id while rendering (see pinSessionId()).
        // Harmless in the classic back-office, fatal here — so we snapshot it first.
        $pinnedSessionId = $this->currentSessionId();

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

        $this->pinSessionId($pinnedSessionId);

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

        // A module can also declare its tool tree INLINE inside meliscore's toolstree, with no
        // `type` link at all (e.g. MelisCron: /meliscore/interface/…/meliscron_conf/meliscron_tool).
        // The plugin root then stays 'meliscore' — which we skip below as "already bundled" — and
        // the type walk finds nothing, so the module's own ressources never reach the iframe. For
        // MelisCron that meant tool.js was missing, and since every action there is a delegated
        // $('body') handler (.btnAddCron / .btnEditCron / .btnDeleteCron / .btnRerunCron /
        // .btnViewCronHistory), NOTHING was bound → clicking any button in the tool did nothing.
        // Derive the owning module from the `forward` nodes of the interface subtree instead, and
        // map each module name back to its app-config root. The lookup is case-insensitive because
        // roots are spelled inconsistently across modules ('meliscron', 'melisTipimail',
        // 'MelisCmsSlider'). Injecting a module's ressources is always a subset of what the classic
        // back-office layout does (it loads EVERY active module's ressources), and identical URLs
        // are de-duplicated below, so this cannot double-bind a handler. Modules that already use a
        // `type` link (MelisCalendar → melistoolcalendar) are covered above and simply gain nothing
        // new here.
        $configRoots = $melisAppConfig->getItem('/');
        $rootsByLowerName = [];
        foreach (array_keys(is_array($configRoots) ? $configRoots : []) as $configRoot) {
            $rootsByLowerName[strtolower($configRoot)] = $configRoot;
        }
        $collectForwardRoots = function ($node) use (&$collectForwardRoots, &$roots, $rootsByLowerName) {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === 'forward' && is_array($v) && !empty($v['module']) && is_string($v['module'])) {
                    $root = $rootsByLowerName[strtolower($v['module'])] ?? null;
                    if ($root !== null) {
                        $roots[$root] = true;
                    }
                } elseif (is_array($v)) {
                    $collectForwardRoots($v);
                }
            }
        };
        $collectForwardRoots($appsConfig);

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
            // The "Analytics" tab (module MelisCmsPageAnalytics, present only when installed) is
            // wired INLINE into the page editor (meliscms → interface/meliscms_page/…) via `forward`
            // links to module MelisCmsPageAnalytics — but the tab's scripts live under a SEPARATE
            // plugin root, `meliscms_page_analytics_tool_config`, whose name matches no module. Neither
            // the type walk nor the forward-by-name map reaches it, so pagehit.tool.js (which defines
            // window.setPageId, used by the analytics DataTable's serverSide `dataFunction`) is never
            // loaded → the table init calls setPageId → ReferenceError → empty analytics table.
            // Add that root for the page editor. FULLY MODULAR: getItem() below returns null when the
            // module is inactive → nothing injected (no phantom load).
            $roots['meliscms_page_analytics_tool_config'] = true;
            // Same shape for the "Send newsletter" action button (module MelisNewsletter, rendered
            // only when the page type is NEWSLETTER): it is contributed to the page editor's action
            // bar via a `forward`, but its scripts live under the plugin root
            // `melis_newsletter_tool_config` — a name that matches no module, so the forward-by-name
            // map above cannot resolve it, and the button sits behind the
            // `type => /meliscms/interface/meliscms_page_actions` link that the type walk only
            // harvests roots from. Result: melis.newsletter.send.js is never injected, its delegated
            // `.melis-newsletter-send-btn` handler is never bound, and clicking "Send newsletter"
            // does nothing at all — no modal, no console error. FULLY MODULAR: getItem() below
            // returns null when the module is inactive → nothing injected (no phantom load).
            $roots['melis_newsletter_tool_config'] = true;
        }

        // The Orders list's own appsConfig tree (fetched above) only covers the list zone
        // itself — an order's detail view (Invoice tab: regenerate/export buttons, forward
        // module MelisCommerceOrderInvoice) is loaded LATER via an internal tabOpen/zoneReload
        // AJAX call once the admin opens a specific order, so the `forward`-module walk above
        // never sees it. Without meliscommerceorderinvoice.js, its delegated $('body') handlers
        // (.regenerate-invoice / .export-invoice-pdf / .export-order-pdf) are never bound →
        // clicking any of those buttons does nothing. Same fix shape as the melisSB case above:
        // FULLY MODULAR, getItem() below returns null (no phantom load) if the module is absent.
        if ($key === 'meliscommerce_order_list_page') {
            $roots['meliscommerceorderinvoice'] = true;
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
        if ($melisKey === 'meliscore_user_profile') {
            // "My account" — the profile/messenger widget renders flush to the iframe edges and its
            // columns/cards are cramped. Inset the whole tool and add breathing room around the
            // profile card, the Profil/Messenger tabs, the form rows and the messenger panels.
            $mk = '[data-meliskey="meliscore_user_profile"]';
            $extraStyle = '
    /* "My account" — inset + tidy spacing (iframe-scoped, /melis untouched). */
    #melis-id-body-content-load > .tab-pane' . $mk . ' { padding: 20px 28px 32px; }
    ' . $mk . ' .widget-user { border: 1px solid #e4e7ea; border-radius: 6px; overflow: hidden; }
    ' . $mk . ' .widget-user .row-merge { margin: 0; }
    /* gutter + separator between the profile card (left) and the tabs/content (right) */
    ' . $mk . ' #id_meliscore_user_profile_left { padding: 24px 20px; }
    ' . $mk . ' #id_meliscore_user_profile_right { padding: 0; border-left: 1px solid #e4e7ea; }
    /* Profil / Messenger tab strip: give it padding and a bottom border */
    ' . $mk . ' #id_meliscore_user_profile_tabs > .nav-tabs { padding: 8px 16px 0; margin-bottom: 0; }
    ' . $mk . ' #id_meliscore_user_profile_tabs .tab-content { padding: 24px 28px; }
    /* form rows: consistent vertical rhythm */
    ' . $mk . ' .tab-content .form-group { margin-bottom: 18px; }
    /* messenger: space the Contacts / Chat panels and let them breathe */
    ' . $mk . ' .widget + .widget, ' . $mk . ' [class*="col-"] > .widget { margin-bottom: 16px; }
    ' . $mk . ' .row > [class*="col-"] { margin-bottom: 8px; }';
        }
        if ($melisKey === 'meliscommerce_categories_page') {
            // Catalogues/Catégories edit form: sticky header pinned 38px down (commerce-style.css:
            // 2071-2076, calibrated for classic BO's real fixed top chrome, absent in this standalone
            // iframe) plus its own baked-in top padding/margin (invisible in classic BO, under the
            // real navbar there) — both read as a floating gap above the header once actually stuck.
            // Scoped to .fix-cat (the stuck/scrolled state only) so classic /melis is untouched.
            $extraStyle = '
    #id_meliscommerce_categories_category.fix-cat .card-header,
    #id_meliscommerce_categories_category.fix-cat .panel-heading { top: 0 !important; padding-top: 0 !important; }
    #id_meliscommerce_categories_category.fix-cat .panel-heading-buttons { margin-top: 0 !important; }';
        }
        if ($melisKey === 'melisagent_tool') {
            // AI-agent scenario-step editor modal: melis-ai style.css forces
            // `#id_melisai_scenario_step_modal_container .modal-content { width: 150% }` to widen it
            // in the full-width classic BO. Inside this narrower tool iframe that 150% shoots past
            // the right edge (the dialog can no longer be centered/contained). Neutralise the 150%
            // and cap the dialog to the iframe viewport — the modal stays wide but fits and centers.
            $extraStyle = '
    #id_melisai_scenario_step_modal_container .modal-dialog { width: 100%; max-width: min(800px, calc(100vw - 2rem)); margin-left: auto; margin-right: auto; }
    #id_melisai_scenario_step_modal_container .modal-content { width: 100% !important; }';
        }
        if ($melisKey === 'melis_core_gdpr') {
            // GDPR "Banners" language switcher (English / Français): the legacy markup reuses
            // melis-commerce's .product-text-tab classes, but that styling isn't injected into this
            // standalone iframe and is scoped to .content-cont (absent here), so the pills render
            // unstyled and the platform theme's secondary color leaks onto the inactive tab. Restore
            // the intended pill look — iframe-scoped, the legacy .phtml / .css are untouched.
            $g = '.mcms-gdpr-banner-details .product-text-tab li.mcms-gdpr-banner-lang a';
            $extraStyle = "
    {$g} { padding: 0 !important; background: #ECEBEB !important; border-radius: 4px !important; color: #7D7B7B; display: flex !important; align-items: center; justify-content: space-between; margin: 0 0 10px; text-shadow: none; box-shadow: none; border: none !important; min-height: 36px; overflow: hidden; }
    {$g}.active, {$g}:hover, {$g}:focus { color: #fff; background: #e61c23 !important; font-weight: normal; text-decoration: none; }
    {$g}.active span, {$g}:hover span, {$g}:focus span { color: #fff; }
    {$g} span { display: inline-block; padding: 6px 7px 6px 15px; vertical-align: middle; color: #7D7B7B; }
    {$g} .imgDisplay { float: none !important; margin-right: 12px; max-width: 24px !important; max-height: 18px !important; width: auto; height: auto; }";
        }

        // Per-tool exception: the CMS page-actions sticky toolbar (melisCms.js) only activates when
        // melisCore.screenSize (= the iframe window width, set ONCE at load) is > 1120. That legacy
        // threshold assumed the full-width classic BO; in the React BO the iframe is narrower (the
        // page-tree sidebar takes ~256px), so on browser windows < ~1376px the iframe drops under
        // 1120 and the toolbar never sticks — it just scrolls off-screen. Nudge screenSize past the
        // gate so the EXISTING legacy sticky logic runs (no competing handler). Positioning still
        // uses the real $body.width(), and the iframe is always a desktop tool view → safe to force.
        // Commerce's Catalogues/Catégories tool (category.tool.js) has the SAME gate but at 768px,
        // and — unlike CMS's, which re-checks live on every scroll — it's evaluated ONCE at
        // script-parse time, so the nudge below must land before {$ressourceJs} (category.tool.js
        // itself), not after.
        $extraScript = '';
        if ($melisKey === 'meliscms_page') {
            $extraScript = "\n  try { if (window.melisCore && melisCore.screenSize <= 1120) melisCore.screenSize = 1121; } catch(e) {}";
        } elseif ($melisKey === 'meliscommerce_categories_page') {
            $extraScript = "\n  try { if (window.melisCore && melisCore.screenSize < 768) melisCore.screenSize = 768; } catch(e) {}";
        } elseif ($melisKey === 'melis_core_gdpr') {
            // The GDPR "Banners" tab content (banner-details.phtml, melis-cms) is AJAX-loaded when the
            // tab is clicked — AFTER the one-shot "activate first inner tab" pass below (line ~614)
            // already ran, so it never reaches the language switcher. That legacy view ships no
            // default-active class (the classic BO relies on a global script we don't run here), so
            // until a language is clicked no pane shows. Watch for the switcher appearing and activate
            // the first language if none is active. Scoped to this melisKey; the legacy .phtml is untouched.
            $extraScript = "\n  (function(){\n"
                . "    function ensure(){\n"
                . "      var box = document.querySelector('.mcms-gdpr-banner-details'); if (!box) return;\n"
                . "      var tabs = box.querySelectorAll('.product-text-tab .mcms-gdpr-banner-language'); if (!tabs.length) return;\n"
                . "      if (box.querySelector('.mcms-gdpr-banner-language.active')) return;\n"
                . "      var first = tabs[0]; first.classList.add('active');\n"
                . "      var sel = first.getAttribute('data-bs-target') || first.getAttribute('href') || '';\n"
                . "      if (sel.charAt(0) === '#') { var pane = document.getElementById(sel.slice(1)); if (pane) pane.classList.add('show','active'); }\n"
                . "    }\n"
                . "    try { new MutationObserver(ensure).observe(document.documentElement, { childList:true, subtree:true }); } catch(e) {}\n"
                . "    ensure();\n"
                . "  })();";
        }
        $cssLinks = implode("\n", array_map(
            static fn($h) => '  <link rel="stylesheet" href="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($h), ENT_QUOTES) . '" />',
            $assets['css'] ?? []
        ));

        $inlineGlobals = $assets['inline'] ?? '';

        // TinyMCE configs, resolved server-side and inlined (see the <script> block below).
        $tinyMceConfigsJs = $this->tinyMceConfigsScript();

        // Platform JS (bundle.js/jQuery + core extras) — in <head> so inline scripts inside the
        // tool HTML have jQuery & the platform globals at parse time.
        $platformJs = implode("\n", array_map(
            static fn($s) => '  <script src="' . htmlspecialchars(\MelisReactOverride\Service\PlatformAssetsService::bust($s), ENT_QUOTES) . '"></script>',
            $assets['js'] ?? []
        ));

        // Module ressources (e.g. melisCms.js) — loaded inside <body>, after the tab strip but
        // BEFORE the tool HTML (same bucket as the platform JS above), which is EXACTLY where the
        // classic back-office loads them from: layoutCore.phtml echoes headScript() inside <body>,
        // where $this->content is only the empty tab shell and every tool is AJAX-loaded afterwards.
        //
        // They must not go in <head>: like melisHelper, several capture $("body") at load time to
        // bind delegated handlers ($body.on(...)) and would bind to an empty set — dead buttons.
        // <body> already exists at this point, so those caches are fine here.
        //
        // They must not go at the END of <body> either (where they used to be), because the tool
        // HTML is INLINE in this standalone page — unlike the classic BO, where it arrives later by
        // AJAX. Loading them after the tool broke two things:
        //   • Parse-time globals: the page editor's drag&drop iframe carries
        //     onload="melisCms.iframeLoad(1)" and the zone emits an inline melisCms.disableCmsButtons(1),
        //     both running before an end-of-body <script> could define melisCms → ReferenceError,
        //     edition iframe never initialised.
        //   • jQuery-ready ORDER: many ressources define their globals INSIDE $(function(){…})
        //     (e.g. melispagehistoric.js → window.initHistoric). Tool inline scripts register their
        //     own ready callback too (DataTable init with `data: initHistoric`). jQuery fires ready
        //     callbacks in REGISTRATION order, so an end-of-body ressource always registered LAST and
        //     its globals were undefined when the tool's callback ran → ReferenceError, empty table.
        // Both orderings work in the classic BO precisely because the ressources load before any
        // tool HTML; loading them here reproduces that. A ressource that needed the tool HTML present
        // at load time could not work in the classic BO either, so nothing can depend on it.
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
    /* The tool page IS the scroll container of the iframe. The classic BO css keeps
       html/body at height:100% (they scroll an inner layout in the real back-office). Here the
       tool HTML is a plain document flow: with a 100%-tall body, a tool taller than the iframe
       (e.g. the GDPR anonymization form with its TinyMCE editors) overflows a body that cannot
       grow. Chrome papers over it by propagating the body's overflow to the viewport, but Firefox
       keeps the fixed-height body as the scroll box → the bottom of the form is unreachable.
       Let the document grow and scroll instead — same result in every engine. */
    html, body { margin: 0; padding: 0; background: transparent; height: auto !important; min-height: 100%; overflow: visible !important; }
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
    .sticky-pageactions { top: 0 !important; }
    /* Legacy Bootstrap modals were authored for the full-height classic back-office page; inside
       this fixed-height tool iframe a modal taller than the visible area overflows it — its body
       and the footer Save/Close buttons land off-screen and (depending on the loaded Bootstrap
       version, whose `.modal-open .modal{overflow-y:auto}` rule may not apply here) can't even be
       scrolled to. Cap every tool modal to the iframe viewport and scroll its body instead, so
       tall modals (e.g. the AI-agent scenario-step editor) stay fully usable. Short modals keep
       their natural height (max-height only caps). */
    .modal { overflow-x: hidden !important; overflow-y: auto !important; }
    .modal .modal-body { max-height: calc(100vh - 6rem); overflow-y: auto; }{$extraStyle}
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
<!-- Module ressources (e.g. melisCms.js, melispagehistoric.js) — HERE, right after the platform JS
     and still BEFORE the tool HTML, mirroring the classic back-office (which loads them while only
     the empty tab shell exists). <body> is present so their $("body") caches work, and they run —
     and register their \$(function(){…}) ready callbacks — before any inline script of the tool. See
     the \$ressourceJs comment in buildToolPage() for the two bugs the old end-of-body slot caused. -->
{$ressourceJs}
  <script>
  /* TinyMCE configs — needs melis_tinymce.js (loaded just above). The nested page-edition iframe
     reads window.parent.melisTinyMCE.tinyMceConfigs[type] (melis_tinymce.js:38); this page IS that
     parent. They are INLINED here (server-side, cf. tinyMceConfigsScript()) rather than fetched:
     melisTinyMCE.getTinyMceConfig() is an ASYNC \$.ajax, and in this standalone page everything —
     platform JS, tool HTML and the nested front iframe — parses in one shot, so the iframe reached
     tinymce.init() BEFORE the response landed. createTinyMCE() then read tinyMceConfigs[type] as
     undefined and built the editor from the bare dataString: no external_plugins, so every module
     TinyMCE override was silently dropped (e.g. MelisAICommunityExtensions' minitemplate plugin,
     which REGISTERS mini_templates_url — without it the mini-template dialog opened with an empty
     list). The classic BO never hit this: its preload fires at shell load, long before any tool.
     Inlining removes the race entirely; the async call stays as a fallback if inlining failed. */
{$tinyMceConfigsJs}
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
<script>{$extraScript}
</script>
<script>
  /* Initialise the active tab id so classic tool handlers (scroll, edit, categories…)
     that read the global activeTabId don't throw before any tab is opened. */
  try { window.activeTabId = {$zoneIdJs}; } catch(e) {}
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
  /* Self-heal legacy Bootstrap modals opened from INSIDE this standalone iframe. The CMS page-tree
     "site selector" (behind the GDPR "Validation page" field, opened by sites.tool.js via
     melisHelper.createModal) — like several legacy modals — rebinds its own hide.bs.modal handler
     that REMOVES the modal element and hand-manages the backdrop. On the iframe path this can leave
     a stray (often near-invisible white) .modal-backdrop still covering the viewport plus the body
     stuck in the modal-open scroll-lock: the scrollbar vanishes and the form below can no longer be
     scrolled, with NO modal actually visible. Watch for that settled state and clear it. Guarded by
     "no modal is really shown", so a legitimately-open modal is never touched. */
  (function(){
    function modalShown(){
      var m = document.querySelectorAll('.modal'), i, el;
      for (i = 0; i < m.length; i++) { el = m[i];
        if (el.classList.contains('show') || el.style.display === 'block') return true; }
      return false;
    }
    function heal(){
      if (modalShown()) return;
      var backs = document.querySelectorAll('.modal-backdrop'), i;
      for (i = 0; i < backs.length; i++) { try { backs[i].remove(); } catch(e) {} }
      var b = document.body; if (!b) return;
      b.classList.remove('modal-open');
      b.style.removeProperty('overflow');
      b.style.removeProperty('padding-right');
    }
    var t; function schedule(){ try { clearTimeout(t); } catch(e) {} t = setTimeout(heal, 400); }
    try { new MutationObserver(schedule).observe(document.body, { childList: true, attributes: true, attributeFilter: ['class','style'] }); } catch(e) {}
    /* Safety net for a state that has already settled (no further mutation to observe): any click
       re-checks and un-freezes if a stray backdrop/scroll-lock is still around and no modal is up. */
    document.addEventListener('click', schedule, true);
  })();
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
     * Rend un plugin dashboard : HTML + jsCallbacks + scripts propres.
     *
     * Partagé par le mode iframe (dashboardPluginPageAction) et le mode AJAX
     * (dashboardPluginContentAction) — les deux ont besoin exactement des mêmes trois choses.
     *
     * @return array{html: string, callbacks: string[], js: string[], css: string[]}|null
     */
    private function renderDashboardPlugin(string $pluginName): ?array
    {
        $sm             = $this->getServiceManager();
        $melisAppConfig = $sm->get('MelisCoreConfig');
        $melisKeys      = $melisAppConfig->getMelisKeys();
        $appConfigPath  = $melisKeys[$pluginName] ?? null;

        if (!$appConfigPath) {
            return null;
        }

        $parts   = explode('/', $appConfigPath);
        $keyView = $parts[count($parts) - 1];

        $appsConfig = $melisAppConfig->getItem($appConfigPath);
        [$jsCallBacks] = $melisAppConfig->getJsCallbacksDatas($appsConfig);

        // Legacy adds the plugin's own `datas.jscallback` on top of the forward's callbacks
        // (DashboardPluginsController::renderDashboardPluginAction) — it's what boots the plugin's
        // JS (charts, etc.). Without it the widget renders but stays inert.
        if (!empty($appsConfig['datas']['jscallback'])) {
            $jsCallBacks[] = $appsConfig['datas']['jscallback'];
        }

        $this->getRequest()->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');

        // Render through the plugin's own render() — the same path legacy uses. Rendering the zone
        // directly (generateRec) skips MelisCoreDashboardTemplatingPlugin::getPluginConfig(), so the
        // `pluginConfig` view variable stays null and templates reading $this->pluginConfig['datas']
        // [...] warn ("Trying to access array offset on null") and lose their settings. Passing the
        // React config row makes the per-plugin settings saved from the React dashboard apply too.
        $html = null;
        try {
            $melisPlugin = $sm->get('ControllerPluginManager')->get($pluginName);
            $pluginModel = $melisPlugin->render([
                'dashboard_id' => self::REACT_DASHBOARD_CONFIG_ID,
                'plugin_id'    => $pluginName,
            ]);

            // On garde le conteneur legacy ENTIER (plugin-container.phtml). Tentant de ne prendre que
            // `pluginView` (React dessine déjà son cadre), mais plusieurs plugins vont chercher leur
            // config dans le DOM du conteneur — MelisCommerceDashboardPluginOrdersNumber et le plugin
            // Prospects font `.closest('.grid-stack-item').find('… .dashboard-plugin-json-config')`
            // puis `JSON.parse()` : sans ce nœud → JSON.parse("") → "Unexpected end of JSON input" →
            // graphique jamais initialisé. On garde donc la structure et on masque en CSS l'en-tête
            // legacy (.widget-head : titre + engrenage + poubelle), redondant avec le cadre React.
            $html = $sm->get('ViewRenderer')->render($pluginModel);
        } catch (\Throwable $e) {
            $html = null;
        }

        // Fallback: plugins that aren't registered as controller plugins (or that blow up in
        // render()) still render as a plain zone, as before.
        if ($html === null) {
            $zoneView = $this->generateRec($keyView, $appConfigPath, $jsCallBacks, []);
            $zoneView->setVariable('zoneconfig', $appsConfig);
            $zoneView->setVariable('parameters', []);
            $zoneView->setVariable('keyInterface', $keyView);

            if (!empty($zoneView->getVariable('jsCallBacks')) && is_array($zoneView->getVariable('jsCallBacks'))) {
                $jsCallBacks = \Laminas\Stdlib\ArrayUtils::merge($zoneView->getVariable('jsCallBacks'), $jsCallBacks);
                $jsCallBacks = array_unique($jsCallBacks);
            }

            $html = $this->renderViewRec($zoneView);
        }

        $html = $this->hoistPluginConfigDatas($html);

        $jsCallBacks = array_values(array_unique($jsCallBacks));

        // Resources for this plugin. `ressources` is a MODULE-level key: Melis merges the blocks of
        // every config file of the module, so asking MelisCoreConfig for "/meliscommerce/ressources/js"
        // returns all ~44 MelisCommerce tool scripts (jstree, lightbox, product.tool.js…) — for ONE
        // widget, in ONE iframe, and there is one iframe per widget.
        //
        // When a plugin declares its own JS (`<module>/config/dashboard-plugins/<Plugin>.config.php`,
        // see pluginOwnResources), that file is self-contained — it defines the plugin's jscallback —
        // so load only it. When it declares none, the plugin RELIES on module-level scripts (e.g.
        // MelisCalendarEventsPlugin's `initDashboardCalendar()` lives in a meliscalendar module file),
        // so keep the module-wide list: pruning it there would break the widget.
        //
        // CSS stays module-wide either way: stylesheets load in parallel (no sequential cost) and
        // dropping them risks unstyled widgets for no real gain.
        $pluginKey = explode('/', ltrim($appConfigPath, '/'))[0] ?? '';
        $jsRes  = [];
        $cssRes = [];
        if ($pluginKey !== '' && strtolower($pluginKey) !== 'meliscore') {
            $own = $this->pluginOwnResources($pluginName);

            if ($own !== null && $own['js'] !== []) {
                $jsRes = $own['js'];
            } else {
                // Module-wide fallback. NB: a module may declare its scripts under a config key that
                // differs from the plugin's own path segment (MelisCalendar registers the plugin under
                // `meliscalendar` but its scripts — including the plugin's `initDashboardCalendar()`
                // callback — under `melistoolcalendar`), so asking for "/$pluginKey/ressources/js"
                // alone silently yields nothing and the widget renders inert. Union both.
                $resJs = $melisAppConfig->getItem("/$pluginKey/ressources/js");
                $jsRes = is_array($resJs) ? array_values($resJs) : [];
                $jsRes = array_values(array_unique(array_merge($jsRes, $this->moduleWideJs($pluginName))));
            }

            $resCss = $melisAppConfig->getItem("/$pluginKey/ressources/css");
            if (is_array($resCss)) {
                $cssRes = array_values($resCss);
            }
        }

        // Valeurs de config ENREGISTRÉES pour ce plugin (cf. la modale engrenage). Servent à
        // réaligner les contrôles de la vue sur la config réelle — plusieurs vues legacy codent
        // « en dur » l'option cochée par défaut (cf. le script de synchro dans la page iframe).
        //
        // ⚠️ Restreint aux champs DÉCLARÉS dans `modal_form` : `getFormData()` renvoie TOUTE la
        // config du plugin (name, icon, section, width: 6, height: 4…). Le script de synchro coche
        // le contrôle qui porte la valeur ; sans ce filtrage, un `width: 6` irait cocher un bouton
        // radio sans rapport valant « 6 ».
        $savedConfig = [];
        if (isset($melisPlugin)) {
            try {
                $d = $melisPlugin->getFormData();
                if (is_array($d)) {
                    $declared = $this->configFieldNames($pluginName);
                    $savedConfig = $declared === [] ? [] : array_intersect_key($d, array_flip($declared));
                }
            } catch (\Throwable) {}
        }


        return [
            'html'      => $html,
            'callbacks' => $jsCallBacks,
            'js'        => $jsRes,
            'css'       => $cssRes,
            'config'    => $savedConfig,
        ];
    }

    /**
     * Renders a single legacy dashboard plugin (e.g. CheckWsStatusPlugin) as a
     * minimal standalone HTML page for use in a React dashboard iframe widget.
     *
     * Usage:  GET /melis/react-dashboard-plugin?plugin=CheckWsStatusPlugin
     */
    /**
     * Valide une couleur reçue en query param, destinée à être écrite TELLE QUELLE dans une feuille
     * de style du document iframe — d'où le filtre strict : une chaîne libre serait une injection CSS.
     *
     * Formes acceptées : `rgb()`/`rgba()` (ce que `widgets.tsx` envoie — il résout le token du thème
     * en couleur calculée) et l'hexadécimal (accès direct à l'URL, mise au point). Un filtre hex SEUL
     * ne suffisait pas : le minifieur du build React réécrit `#ff0000` en `red` (mot-clé CSS), le
     * param était donc rejeté et le thème rouge retombait silencieusement sur le repli.
     */
    private function sanitizeCssColor($value, string $fallback): string
    {
        $value = trim((string) $value);
        $valid = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value)
            || preg_match('/^rgba?\(\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*(,\s*(0|1|0?\.[0-9]+)\s*)?\)$/', $value);

        return $valid ? $value : $fallback;
    }

    public function dashboardPluginPageAction()
    {
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

        $pluginName = $this->getRequest()->getQuery('plugin', '');
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return $this->getResponse();
        }

        $sm = $this->getServiceManager();

        // Rights gate: without it a forged ?plugin=... URL would render a plugin the user's
        // usr_rights doesn't grant. Same key/service as legacy (the plugin class name).
        try {
            $rightsSvc = $sm->get('MelisCoreDashboardPluginsService');
            if (!$rightsSvc->canAccess($pluginName)) {
                $this->getResponse()->setStatusCode(403);
                return $this->getResponse();
            }
        } catch (\Throwable) {}

        // ⚠️ PERF — free the PHP session lock BEFORE the (~1s+) plugin render below. The React
        // dashboard opens ONE iframe per widget (≈9 at once), and every same-cookie request blocks
        // on the single PHP session file lock: without this release the widget renders SERIALISE
        // (one plugin at a time → ~10s+ total) AND stall the dashboard's other requests (the layout
        // POST, stats, thumbnails…) behind them. This is a display-only render — the rights check
        // above already read the session, and nothing below writes it — so closing the session for
        // writing here is safe and lets the renders run concurrently (up to the php-fpm/CPU ceiling).
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // Couleur d'accent du shell React (rouge « platform » / bleu « studio »), transmise par la
        // tuile (`widgets.tsx`) : l'iframe est un document séparé, elle n'hérite pas des variables
        // CSS de l'hôte. FILTRE HEX STRICT obligatoire — la valeur est écrite telle quelle dans une
        // feuille de style, une chaîne libre serait une injection CSS. Repli : l'ancienne teinte en
        // dur, pour que la page reste correcte si le param manque (accès direct à l'URL).
        // Formes acceptées : `rgb()`/`rgba()` (ce que `widgets.tsx` envoie — il résout le token du
        // thème en couleur calculée) et l'hexadécimal (accès direct à l'URL, mise au point). Un
        // filtre hex SEUL ne suffisait pas : le minifieur du build React réécrit `#ff0000` en `red`,
        // le param était donc rejeté et le thème rouge retombait silencieusement sur le repli.
        $pluginPrimary = $this->sanitizeCssColor($this->getRequest()->getQuery('primary', ''), '#932e2a');

        // Mode sombre : le HTML legacy code en dur des surfaces blanches et des textes sombres, la
        // seule couleur d'accent ne suffit donc pas à le faire suivre le thème. La tuile envoie en
        // plus `scheme` + les tokens de surface de l'hôte (mêmes filtres que `primary`). Les replis
        // sont les valeurs CLAIRES : un accès direct à l'URL, sans params, doit rendre la page telle
        // qu'avant. La liste blanche sur `scheme` est ce qui garantit que le bloc de surcharges
        // sombres ne peut pas être activé par une valeur inattendue.
        $pluginScheme = $this->getRequest()->getQuery('scheme', '') === 'dark' ? 'dark' : 'light';
        // ── Tuile en mode MOBILE ──────────────────────────────────────────────────────────────
        // La largeur de l'IFRAME ne permet pas de décider : une tuile `col-4` d'un dashboard de
        // bureau fait elle aussi ~350px, et son plugin doit garder la mise en page « fenêtre large »
        // (c'est tout le rôle de $gridFix plus bas). C'est donc l'HÔTE qui tranche — il porte le
        // seul booléen responsive du BO React (`useIsNarrow`, largeur de la FENÊTRE) et nous le
        // transmet ici. Rendu en attribut sur <html> : le bloc de règles « étroit » du <style> y est
        // scopé, et l'attribut peut être basculé à chaud (postMessage `__melisNarrow`, cf. <head>)
        // sans recharger l'iframe — rotation d'un téléphone, redimensionnement de la fenêtre.
        $pluginNarrowAttr = $this->getRequest()->getQuery('narrow', '') === '1' ? ' data-melis-narrow="1"' : '';
        $pluginBg     = $this->sanitizeCssColor($this->getRequest()->getQuery('bg', ''), '#ffffff');
        $pluginFg     = $this->sanitizeCssColor($this->getRequest()->getQuery('fg', ''), '#333333');
        $pluginBorder = $this->sanitizeCssColor($this->getRequest()->getQuery('border', ''), '#f0f0f0');
        $pluginMuted  = $this->sanitizeCssColor($this->getRequest()->getQuery('muted', ''), '#888888');
        // Survol de ligne : pas un token de l'hôte (il n'en expose pas d'équivalent), donc dérivé du
        // MODE. N'est plus calculé ici mais porté par l'attribut `data-melis-scheme` (voir le <style>
        // plus bas) → il suit une bascule clair/sombre à chaud, sans recharger l'iframe.

        $render = $this->renderDashboardPlugin($pluginName);

        if ($render === null) {
            $this->getResponse()->setStatusCode(404);
            return $this->getResponse();
        }

        $html        = $render['html'];
        $jsCallBacks = $render['callbacks'];
        $jsRes       = $render['js'];

        $assets = \MelisReactOverride\Service\PlatformAssetsService::build($sm);
        if ($render['css'] !== []) {
            $assets['css'] = array_values(array_unique(array_merge($assets['css'], $render['css'])));
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
        // Run each jscallback on jQuery DOM-ready, NOT immediately. The plugin scripts define their
        // globals INSIDE `$(function(){ window.xxxInit = … })` (e.g. MelisCommerceDashboardPlugin
        // OrderMessages.js), so those globals only exist once the ready queue fires. An immediate IIFE
        // here ran BEFORE that → "commerceDashboardPluginOrderMessagesInit is not defined" (ticket
        // 0010863). jQuery runs ready handlers in registration order: the plugin's `<script src>`
        // (emitted just above, in $bodyJs) registers its handler first, so wrapping the callback in
        // jQuery(ready) guarantees the global is defined by the time the callback runs — exactly like
        // the classic back-office dashboard. `refreshWidget` re-runs these blocks after ready, where
        // jQuery(fn) executes fn synchronously, so reloads keep working too.
        $callbackBlocks = implode("\n", array_map(
            static fn($cb) => "<script>\n(function(run){\n"
                . "  if (window.jQuery) { window.jQuery(run); }\n"
                . "  else if (document.readyState !== 'loading') { run(); }\n"
                . "  else { document.addEventListener('DOMContentLoaded', run); }\n"
                . "})(function(){\ntry{\n{$cb}\n}catch(e){console.warn(e);}\n});\n</script>",
            $jsCallBacks
        ));

        $inlineGlobals = $assets['inline'] ?? '';

        // Conteneur du plugin + global `activeTabId`. Les plugins dashboard supposent la page du BO
        // legacy, où le contenu vit dans l'onglet actif et où ils le RETROUVENT via ce global — ex.
        // MelisCommerceDashboardPluginSalesRevenue plotte dans `$("#"+activeTabId).find(selector)`.
        // Sans lui : $("#undefined") → sélection VIDE → flot reçoit un conteneur sans dimensions →
        // "createLinearGradient: non-finite value" → aucun graphique. On recrée donc le strict
        // minimum : un wrapper portant cet id + le melisKey du dashboard (les handlers de filtre
        // testent `[data-melisKey="meliscore_dashboard"]`).
        $zoneId   = 'melis_react_dashboard_plugin';
        $zoneIdJs = json_encode($zoneId, JSON_UNESCAPED_SLASHES);
        $dashboardIdJs = json_encode(self::REACT_DASHBOARD_CONFIG_ID, JSON_UNESCAPED_SLASHES);
        // Config enregistrée du plugin, injectée pour la synchro des contrôles (voir plus bas).
        $savedConfigJs = json_encode(
            array_filter(($render['config'] ?? []), static fn($v) => is_scalar($v)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';

        // Grille Bootstrap : rétablit les largeurs `col-{sm,md,lg}-N` SANS media query.
        //
        // Les breakpoints s'évaluent sur la largeur du DOCUMENT — ici l'IFRAME du widget (~500-700px),
        // pas la fenêtre. Un `col-md-6` (≥768px) ne s'applique donc jamais : toutes les colonnes
        // s'empilent, là où le BO legacy (fenêtre large) les met côte à côte. Ex. le plugin
        // « Indicators » (MelisCms), prévu en 2×2, se retrouvait en une seule colonne.
        // On reproduit le rendu « fenêtre large » en figeant les pourcentages de la grille.
        $gridFix = '';
        foreach (['sm', 'md', 'lg', 'xl'] as $bp) {
            for ($n = 1; $n <= 12; $n++) {
                $pct = round($n / 12 * 100, 6);
                $gridFix .= "    .col-{$bp}-{$n} { flex: 0 0 auto !important; width: {$pct}% !important; max-width: {$pct}% !important; }\n";
            }
        }

        // ── Graphiques flot en mode sombre ────────────────────────────────────────────────────────
        // AUCUNE règle CSS ne peut repeindre le fond d'un graphique flot : il est PEINT DANS LE
        // CANVAS (les plugins codent en dur `grid.backgroundColor = { colors: ["#fff","#fff"] }`).
        // On enveloppe donc `$.plot` pour neutraliser ce fond et réaligner grille et bordures sur les
        // tokens de l'hôte — le fond de la tuile React transparaît alors sous le graphique.
        // Émis APRÈS les scripts du plugin (qui définissent `$.plot`) et AVANT les callbacks (qui
        // l'appellent). Le patch survit aux rechargements internes : `refreshWidget` réévalue les
        // callbacks, mais `$.plot` reste enveloppé.
        $flotPatch = $pluginScheme !== 'dark' ? '' : <<<'JS'
<script>
(function(){
  var jq = window.jQuery;
  if (!jq || !jq.plot) return;
  var original = jq.plot;
  var css = getComputedStyle(document.documentElement);
  var border = (css.getPropertyValue('--melis-plugin-border') || '').trim() || '#3f3f46';
  var patched = function(placeholder, data, options){
    var opts = options || {};
    opts = jq.extend({}, opts, { grid: jq.extend({}, opts.grid || {}, {
      backgroundColor: null, /* ← le fond blanc peint dans le canvas */
      color: border,
      borderColor: 'transparent',
      tickColor: border
    })});
    return original.call(this, placeholder, data, opts);
  };
  /* flot accroche des propriétés sur $.plot (notamment $.plot.plugins) — les conserver. */
  jq.extend(patched, original);
  jq.plot = patched;
})();
</script>
JS;

        // ── MelisCommerceDashboardPluginOrderMessages : clic sur les filtres All / Unanswered ─────
        // Bug du plugin legacy (NE PAS corriger dans melis-commerce : chantier 3 = isolé) : les radios
        // du filtre et les lignes de message portent la MÊME classe `commerce-dashboard-plugin-order-messages`
        // (view/dashboard-plugins/commerce-dashboard-plugin-order-messages.phtml:7 vs le `messageHtml`
        // construit dans le JS du plugin). Le handler `click` délégué sur `body` ne discrimine pas :
        // cliquer sur All / Unanswered appelle `openOrderMessages(<input radio>)`, où
        // `$(radio).find('.order-message-id')` est vide → `orderId === undefined` → les sélecteurs
        // dérivés matchent n'importe quoi et le code casse sur `specificOrderTab[0].trigger("click")`
        // (`[0]` est un élément DOM natif, `.trigger` est une méthode jQuery → TypeError).
        // On intercepte donc le clic en phase de CAPTURE, avant que jQuery ne le voie sur `body`, et on
        // l'arrête quand la cible n'est pas une ligne de message. `stopPropagation` n'empêche NI la
        // sélection du radio NI l'événement `change` (qui bubble séparément) : le filtre continue de
        // fonctionner, seul l'appel parasite à `openOrderMessages` disparaît.
        $orderMessagesPatch = $pluginName !== 'MelisCommerceDashboardPluginOrderMessages' ? '' : <<<'JS'
<script>
(function(){
  document.addEventListener('click', function(e){
    var el = e.target && e.target.closest ? e.target.closest('.commerce-dashboard-plugin-order-messages') : null;
    if (!el) return;
    /* Seules les lignes de message (`<a class="list-group-item …">`) ouvrent une commande. */
    if (el.classList.contains('list-group-item')) return;
    e.stopPropagation();
  }, true);
})();
</script>
JS;

        // ── Surcharges du mode sombre ─────────────────────────────────────────────────────────────
        // Le HTML legacy est écrit pour un thème CLAIR : surfaces blanches (`.bg-white`,
        // `.widget-body`) et textes sombres codés en dur. On les remplace par les tokens de l'hôte
        // pour que le contenu du plugin suive le thème. Émis en DERNIER dans le <style> — à
        // spécificité égale, la dernière règle gagne.
        // ⚠️ TOUJOURS émis désormais (avant : uniquement si `scheme=dark`). Gaté sous
        // `html[data-melis-scheme="dark"]` via l'imbrication CSS native : basculer cet attribut (à
        // chaud, cf. le listener `__melisRetheme` plus bas) active/désactive tout ce bloc SANS
        // recharger l'iframe — c'est ce qui rend la bascule clair/sombre instantanée. L'iframe tourne
        // le même moteur moderne que l'hôte React (Tailwind v4 exige déjà l'imbrication CSS) → sûr.
        $darkCss = <<<CSS
    /* ── Mode sombre (attribut `data-melis-scheme="dark"`, posé au chargement OU à chaud) ────── */
    html[data-melis-scheme="dark"] {
    /* Le fond du DOCUMENT iframe lui-même : la feuille legacy (bundle.css, chargée après) peint le
       body en BLANC. Sans le repeindre, les surfaces de widget passées en `transparent` ci-dessous
       laissent transparaître ce body blanc → la tuile reste claire (le vrai symptôme observé). On le
       met à la couleur de carte de l'hôte pour que le plugin repose sur un fond sombre cohérent. */
    background: var(--melis-plugin-bg) !important; /* l'élément <html> lui-même */
    body { background: var(--melis-plugin-bg) !important; }
    body, #{$zoneId} { color: var(--melis-plugin-fg); }

    /* ── Surfaces neutres → transparentes ─────────────────────────────────────────────────────
       Tous les conteneurs « blancs » du legacy : ils reposent alors sur le fond sombre du body.
       On NE touche PAS aux surfaces SÉMANTIQUES (`.bg-primary`, `.bg-info`, `.bg-success`,
       `.bg-inverse` — les tuiles colorées de « Page indicators » à texte blanc, lisibles telles
       quelles ; `thead.bg-primary` — l'en-tête d'accent des tables). */
    #{$zoneId} .bg-white,
    #{$zoneId} .widget,
    #{$zoneId} .widget-inverse,
    #{$zoneId} .widget-body,
    #{$zoneId} .widget-body-white,
    #{$zoneId} .widget-heading-simple,
    #{$zoneId} .widget-head,
    #{$zoneId} .innerAll,
    #{$zoneId} .panel,
    #{$zoneId} .tab-content,
    #{$zoneId} .col-app,
    #{$zoneId} .col-table-row,
    #{$zoneId} .list-group,
    #{$zoneId} .list-group-item,
    #{$zoneId} table { background: transparent !important; color: var(--melis-plugin-fg) !important; }

    /* ── « Page indicators » (MelisCms) : tuiles colorées, version sombre ──────────────────────
       Les 4 tuiles combinent `.innerAll` (repassé transparent juste au-dessus) AVEC une classe
       sémantique `.bg-*`. Comme `#{$zoneId} .innerAll` (id+1 classe) l'emporte sur le `.bg-*`
       simple de bundle.css, la couleur legacy disparaît → tuiles plates et ternes en sombre.
       On la RÉTABLIT ici, mais calibrée pour le fond sombre : teinte translucide + bordure
       assortie + icône d'accent (au lieu des aplats vifs #932e2a/#466baf/#72af46 qui « bavent »
       en dark). Spécificité id+2 classes → gagne sur la règle `.innerAll` transparente. */
    #{$zoneId} .cms-page-indicators-plugin .innerAll { border-radius: 8px; }
    #{$zoneId} .cms-page-indicators-plugin .bg-inverse { background: rgba(148,163,184,0.12) !important; border: 1px solid rgba(148,163,184,0.30) !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-info    { background: rgba(59,130,246,0.14) !important; border: 1px solid rgba(59,130,246,0.35) !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-success { background: rgba(34,197,94,0.14) !important; border: 1px solid rgba(34,197,94,0.35) !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-primary { background: rgba(239,68,68,0.14) !important; border: 1px solid rgba(239,68,68,0.35) !important; }
    /* Icônes teintées à la couleur d'accent de chaque tuile ; le texte reste clair (lisibilité). */
    #{$zoneId} .cms-page-indicators-plugin .bg-inverse i { color: #cbd5e1 !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-info i    { color: #60a5fa !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-success i { color: #4ade80 !important; }
    #{$zoneId} .cms-page-indicators-plugin .bg-primary i { color: #f87171 !important; }

    /* ── Textes ───────────────────────────────────────────────────────────────────────────────
       Titres / auteurs codés en couleur sombre dans le legacy. On NE force PAS `p`/`span`/`td`
       en bloc : cela écraserait les couleurs d'accent voulues (`.text-primary`, `.ra-username`,
       statuts de commande…). L'héritage depuis `#{$zoneId}` suffit pour le texte courant. */
    #{$zoneId} h1, #{$zoneId} h2, #{$zoneId} h3, #{$zoneId} h4, #{$zoneId} h5, #{$zoneId} h6,
    #{$zoneId} .author { color: var(--melis-plugin-fg) !important; }
    #{$zoneId} .text-muted, #{$zoneId} .muted, #{$zoneId} h4.muted,
    #{$zoneId} .type, #{$zoneId} .time { color: var(--melis-plugin-muted) !important; }

    /* ── Bordures ─────────────────────────────────────────────────────────────────────────── */
    #{$zoneId} .separator,
    #{$zoneId} hr,
    #{$zoneId} .border-bottom,
    #{$zoneId} .border-top,
    #{$zoneId} .border-left,
    #{$zoneId} .border-right,
    #{$zoneId} .widget-head,
    #{$zoneId} .list-group-item { border-color: var(--melis-plugin-border) !important; }

    /* ── Boutons (filtres Daily/Monthly/… en `.btn-default`) ──────────────────────────────────
       État actif : `.active`/`.focus` (orders-number & sales-revenue) et
       `.btn-check:checked + .btn-default` (prospects) → couleur d'accent du thème. */
    #{$zoneId} .btn-default { background: transparent !important; border-color: var(--melis-plugin-border) !important; color: var(--melis-plugin-fg) !important; }
    #{$zoneId} .btn-default.active,
    #{$zoneId} .btn-default.focus,
    #{$zoneId} .btn-check:checked + .btn-default { background: var(--melis-plugin-primary) !important; border-color: var(--melis-plugin-primary) !important; color: #fff !important; }

    /* ── Onglets (prospects, workflow) ────────────────────────────────────────────────────────
       Barre `.nav-tabs` : bordure et libellés atténués ; l'onglet actif reprend l'accent.
       (Le module SmallBusiness colore lui-même l'onglet actif du workflow en vert — laissé tel.) */
    #{$zoneId} .nav-tabs { border-color: var(--melis-plugin-border) !important; }
    #{$zoneId} .nav-tabs .nav-link { color: var(--melis-plugin-muted) !important; background: transparent !important; border-color: transparent !important; }
    /* Onglet actif : Bootstrap lui pose un fond BLANC — c'est la « boîte blanche » du sous-onglet
       PAGE du workflow. On le repeint en surface sombre + soulignement d'accent. On vise les DEUX
       conventions de classe active : `.nav-link.active` (BS4/5, classe sur le `<a>`) ET
       `li.active > a` (BS3, classe sur le `<li>` — cas des onglets glyphicons du workflow, sinon la
       règle ne matchait pas et la boîte restait blanche). */
    #{$zoneId} .nav-tabs .nav-link.active,
    #{$zoneId} .nav-tabs .nav-item.active > a,
    #{$zoneId} .nav-tabs > li.active > a { color: var(--melis-plugin-fg) !important; background: var(--melis-plugin-row-hover) !important; border-color: var(--melis-plugin-border) var(--melis-plugin-border) var(--melis-plugin-primary) !important; }
    /* Onglets à icône « glyphicons » (police, donc couleur pilotable en CSS) : icône atténuée au
       repos, accent quand l'onglet est actif. Le rouge Melis (#e61c23) du legacy est ainsi remplacé. */
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons i:before { color: var(--melis-plugin-muted) !important; }
    /* Actif : Bootstrap 5 déplace `.active` du `<li>` vers le `<a>` (`.nav-link.active`) à l'exécution
       — on vise donc les DEUX formes (`li.active > a.glyphicons` ET `a.glyphicons.active`), sinon la
       règle « au repos » ci-dessus (plus spécifique que la générique) l'emporterait et le texte
       resterait gris. */
    #{$zoneId} .widget-tabs .nav-tabs > li.active > a.glyphicons i:before,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons.active i:before,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons:hover i:before { color: var(--melis-plugin-primary) !important; }
    /* Le glyphe « file » de Glyphicons (\\E037) dessine sa PAGE en BLANC dans la police elle-même —
       aucun CSS ne peut recolorer une portion d'un glyphe, et son fond `<i>` est bien transparent
       (confirmé au DevTools). On abandonne donc la police : on VIDE le glyphe (`content:""`) et on
       redessine l'icône via un SVG en `mask` — la forme vient du SVG, la COULEUR de `background-color`
       (l'accent du thème). Résultat : icône document propre, monochrome, qui suit le thème, sans aucun
       blanc possible (un masque n'a pas de couleur propre). Vaut pour tous les onglets `workflow-type`. */
    #{$zoneId} .widget-tabs .nav-tabs a.glyphicons.workflow-type > i { display: inline-block !important; width: auto !important; height: auto !important; line-height: 1 !important; vertical-align: middle !important; background: transparent !important; }
    #{$zoneId} .widget-tabs .nav-tabs a.glyphicons.workflow-type > i:before {
      content: "" !important;
      display: inline-block !important;
      width: 15px !important;
      height: 15px !important;
      margin-right: 6px !important;
      vertical-align: -3px !important;
      background-color: var(--melis-plugin-primary) !important;
      color: transparent !important;
      -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/%3E%3Cpolyline points='14 2 14 8 20 8'/%3E%3C/svg%3E");
      mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/%3E%3Cpolyline points='14 2 14 8 20 8'/%3E%3C/svg%3E");
      -webkit-mask-repeat: no-repeat !important; mask-repeat: no-repeat !important;
      -webkit-mask-position: center !important; mask-position: center !important;
      -webkit-mask-size: contain !important; mask-size: contain !important;
    }
    /* Le LIBELLÉ de l'onglet (« PAGE ») : atténué au repos, clair quand l'onglet est actif. */
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons { color: var(--melis-plugin-muted) !important; }
    #{$zoneId} .widget.widget-tabs > .widget-head ul li.active a.glyphicons,
    #{$zoneId} .widget-tabs .nav-tabs > li.active > a.glyphicons,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons.active { color: var(--melis-plugin-fg) !important; }
    /* La « BOÎTE BLANCHE » de l'onglet : le thème pose un fond blanc sur ces onglets glyphicons
       (`…a.glyphicons:hover{background:#fff}` + fond de base du variant `widget-tabs-icons-only-2`).
       On force le fond TRANSPARENT dans TOUS les états (repos / actif que `.active` soit sur le `<li>`
       ou sur le `<a>` / survol) : l'onglet se fond dans l'en-tête sombre, l'état actif se lit à
       l'icône + libellé clairs et au soulignement d'accent (règle générique `.nav-link.active`). */
    #{$zoneId} .widget.widget-tabs-icons-only-2 > .widget-head ul li a.glyphicons,
    #{$zoneId} .widget.widget-tabs-icons-only-2 > .widget-head ul li.active a.glyphicons,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons:hover,
    #{$zoneId} .widget-tabs .nav-tabs > li > a.glyphicons.active,
    #{$zoneId} .widget-tabs .nav-tabs > li.active > a.glyphicons,
    #{$zoneId} .widget-tabs .nav-tabs > li.active > a.glyphicons:hover { background: transparent !important; }
    /* Filet de sécurité : la « boîte blanche » persiste car elle n'est PAS portée par le fond du
       `<a>` (déjà neutralisé ci-dessus) mais par un élément FRÈRE — le `<li>`, le `<i>` vide, ou une
       bordure/ombre claire du variant. On neutralise donc TOUT le sous-arbre de l'onglet interne
       (conteneur `.dashboard-workflow-tabs`) : fond transparent, ombre supprimée, bordure au ton du
       thème. Sélecteur très spécifique + id → l'emporte sur tout le legacy. */
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li,
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li.active,
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li > a,
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li.active > a,
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li > a.active,
    #{$zoneId} .dashboard-workflow-tabs > .widget-head .nav-tabs li > a > i { background: transparent !important; box-shadow: none !important; border-color: var(--melis-plugin-border) !important; }
    /* LA « boîte » claire de l'icône : `.glyphicons i { background:#e5e5e5 }` (bundle.css) pose un
       carré gris clair derrière le `<i>` vide de TOUT élément `.glyphicons` — c'est le fond blanc vu
       autour de l'icône de l'onglet PAGE. On le rend transparent directement à la source. */
    #{$zoneId} a.glyphicons > i,
    #{$zoneId} .glyphicons > i { background: transparent !important; box-shadow: none !important; }
    /* Exhaustif : la « paperasse » claire vue dans l'icône du fichier est l'espace négatif du glyphe
       laissant voir un fond clair. On neutralise TOUT fond (couleur ET image/sprite) et toute ombre
       sur l'onglet interne, son `<a>`, le `<i>` et ses deux pseudo-éléments — quel que soit l'élément
       fautif, il est couvert. NB : si le blanc PERSISTE après ça, c'est un style INLINE posé par JS
       (le HTML serveur a un `<i></i>` propre) → seule l'inspection DevTools le révélera. */
    #{$zoneId} .dashboard-workflow-tabs .nav-tabs li,
    #{$zoneId} .dashboard-workflow-tabs .nav-tabs li > a,
    #{$zoneId} .dashboard-workflow-tabs .nav-tabs li > a > i,
    #{$zoneId} .dashboard-workflow-tabs .nav-tabs li > a > i:before,
    #{$zoneId} .dashboard-workflow-tabs .nav-tabs li > a > i:after { background: transparent !important; background-color: transparent !important; background-image: none !important; box-shadow: none !important; }

    /* ── Survol de ligne des listes/tables ────────────────────────────────────────────────────
       Le plugin « orders-number » injecte SON PROPRE `<style>` (dans le body, donc APRÈS ce bloc)
       qui code `background-color:#f5f5f5` en clair sur `…orders-number-item:hover`. Notre règle est
       postérieure en cascade ? Non — la sienne l'est. On la bat donc avec `!important`. */
    #{$zoneId} .melis-commerce-dashboard-plugin-orders-number-item:hover,
    #{$zoneId} .melis-commerce-dashboard-plugin-orders-number-item:hover td,
    #{$zoneId} .list-group-item:hover { background: var(--melis-plugin-row-hover) !important; }
    /* Plugin « Recent page activity » (MelisCmsPageHistoric) : bundle.css peint la ligne survolée /
       `.highlight` (classe posée en JS) en gris CLAIR `#f2f2f2` → en sombre la ligne devient claire
       alors que le texte reste clair → illisible. On la repeint en surface de survol sombre du thème
       (le texte, hérité de `#{$zoneId}`, redevient lisible). */
    #{$zoneId} .widget-activity ul.list li:hover,
    #{$zoneId} .widget-activity ul.list li.highlight { background: var(--melis-plugin-row-hover) !important; }

    /* ── Encart neutre d'annonce (`.alert-gray`) ──────────────────────────────────────────────
       Neutre → surface discrète. Les alertes SÉMANTIQUES (`.alert-warning/-success/-danger/-info`,
       ex. « pas de données ») gardent leur couleur : elles portent une information. */
    #{$zoneId} .alert-gray { background: var(--melis-plugin-row-hover) !important; border-color: var(--melis-plugin-border) !important; color: var(--melis-plugin-fg) !important; }

    /* ── Frise chronologique (plugin Annonces) ────────────────────────────────────────────────
       Le rail vertical et les pastilles sont peints via des bordures claires (`.timeline` en
       ::before/::after). On les réaligne sur la bordure du thème. */
    #{$zoneId} .timeline:before,
    #{$zoneId} .timeline > li:before,
    #{$zoneId} .timeline > li:after { border-color: var(--melis-plugin-border) !important; background-color: var(--melis-plugin-bg) !important; }
    /* Variante `.layout-timeline` (plugin Annonces) : la ligne verticale active, ses pastilles et
       ses tirets de raccord sont codés en dur en ROUGE (#cb4040, module.admin.page.timelines.css).
       En sombre on les repasse à l'accent du thème (bleu Studio). Préfixé `.layout-timeline` →
       spécificité supérieure à la règle générique ci-dessus, il l'emporte. */
    #{$zoneId} .layout-timeline ul.timeline > li.active:before,
    #{$zoneId} .layout-timeline ul.timeline > li.active .type:before,
    #{$zoneId} .layout-timeline ul.timeline > li.active .type:after { background: var(--melis-plugin-primary) !important; background-color: var(--melis-plugin-primary) !important; border-color: var(--melis-plugin-primary) !important; }
    #{$zoneId} .layout-timeline ul.timeline > li.active .type,
    #{$zoneId} .layout-timeline ul.timeline > li.active .type i:before { color: var(--melis-plugin-primary) !important; }
    /* Pagination (BS `.pagination`) : page active peinte en ROUGE Melis, liens en rouge. En sombre
       → accent du thème (bleu Studio). `#{$zoneId}` (id) l'emporte sur les règles bundle (classes). */
    #{$zoneId} .pagination .page-item.active .page-link,
    #{$zoneId} .pagination .page-item.active > a { background-color: var(--melis-plugin-primary) !important; border-color: var(--melis-plugin-primary) !important; color: #fff !important; }
    #{$zoneId} .pagination .page-link { color: var(--melis-plugin-primary) !important; background-color: transparent !important; border-color: var(--melis-plugin-border) !important; }
    #{$zoneId} .pagination .page-item.disabled .page-link { color: var(--melis-plugin-muted) !important; }

    /* ── Workflow (MelisSmallBusiness) ────────────────────────────────────────────────────────
       Le module dessine ses onglets HAUTS (« Users' demands » / « My demands ») en pastilles
       PLEINES : gris `#ECEBEB` inactif, `#72af46` vert actif (style.css), et le thème legacy colore
       le libellé actif en rouge Melis. On aligne sur le reste : inactif = texte atténué transparent
       (déjà via `.nav-link`), actif = pastille d'accent à texte blanc. `#{$zoneId}` (id) l'emporte
       sur les sélecteurs du module (classes seules). */
    #{$zoneId} .dashboard-workflow-container .nav-tabs > li.active > a,
    #{$zoneId} .dashboard-workflow-container .nav-tabs .nav-item.active > a,
    #{$zoneId} .dashboard-workflow-container .nav-tabs .nav-link.active { background: var(--melis-plugin-primary) !important; border-color: var(--melis-plugin-primary) !important; color: #fff !important; }
    #{$zoneId} .dashboard-workflow-container .nav-tabs .nav-item.active > a .a-text,
    #{$zoneId} .dashboard-workflow-container .nav-tabs .nav-item.active > a i,
    #{$zoneId} .dashboard-workflow-container .nav-tabs > li.active > a .a-text,
    #{$zoneId} .dashboard-workflow-container .nav-tabs > li.active > a i { color: #fff !important; }
    /* Petits fonds gris `#ECEBEB` internes (badge de compte, boutons d'action au survol). */
    #{$zoneId} .wd-cont span span,
    #{$zoneId} .wd-btn-cont span { background: var(--melis-plugin-row-hover) !important; color: var(--melis-plugin-fg) !important; }
    /* Pastilles d'état VALIDATED / REFUSED : on garde le vert / rouge (l'information de statut), mais
       en tons LÉGÈREMENT DÉSATURÉS pour ne pas éblouir sur fond sombre (le vert #72af46 / rouge
       #cb4040 vifs du legacy « bavent » en dark). Couvre les deux emplacements : la pastille de la
       ligne (`.wd-action-*`) et les boutons d'action au survol (`.wd-validate`/`.wd-refuse`). */
    #{$zoneId} .wd-action-validate,
    #{$zoneId} .wd-btn-cont .wd-validate,
    #{$zoneId} .d-workflow-action-cont .wd-action-validate { background: rgba(52,168,95,0.18) !important; color: #4ade80 !important; border: 1px solid rgba(52,168,95,0.55) !important; }
    #{$zoneId} .wd-action-refuse,
    #{$zoneId} .wd-btn-cont .wd-refuse,
    #{$zoneId} .d-workflow-action-cont .wd-action-refuse { background: rgba(220,68,68,0.18) !important; color: #f87171 !important; border: 1px solid rgba(220,68,68,0.55) !important; }
    /* Tooltip legacy (bulle « PAGE » au survol d'un onglet) : le thème clair la rend blanche. On la
       repeint sombre. NON scopée à l'id : la bulle est ajoutée au `<body>`, hors du wrapper de zone. */
    .tooltip-inner, .workflow-type-tooltip { background: #212121 !important; background-color: #212121 !important; color: #fff !important; border-color: #212121 !important; }

    /* ── Calendrier (datepicker du plugin Calendar) ───────────────────────────────────────────
       Le legacy peint en ROUGE Melis (#932e2a) l'en-tête des jours (`thead th.dow`, réglé dans
       bundle.css) et la sélection ; le module ajoute #e64444 (jours à événement) et #e61c23 (jour
       actif à événement). En sombre on les repeint à l'accent du thème (bleu Studio). Chaque
       sélecteur est préfixé par `#{$zoneId}` (un id) → il l'emporte sur les règles bundle (classes
       seules), même sur leurs `!important`. Ne matche QUE la tuile Calendar (ces éléments n'existent
       pas ailleurs) — inutile de conditionner au plugin. Le jaune « aujourd'hui » est laissé tel. */
    #{$zoneId} .datepicker thead th.dow { background: var(--melis-plugin-primary) !important; color: #fff !important; }
    #{$zoneId} .datepicker table tr td.active:hover,
    #{$zoneId} .datepicker table tr td span.active:hover,
    #{$zoneId} .datepicker table tr td.day.calendar-highlight,
    #{$zoneId} .calendar-highlight,
    #{$zoneId} .calendar-highlight:hover { background-color: var(--melis-plugin-primary) !important; color: #fff !important; }
    /* Jour actif PORTANT un événement : accent légèrement assombri pour rester distinct du jour à
       simple événement. */
    #{$zoneId} .datepicker table tr td.active.day.calendar-highlight.calendar-event { background-color: var(--melis-plugin-primary) !important; filter: brightness(0.82); }

    /* ── Activité récente des pages (plugin MelisCmsPageHistoric) ─────────────────────────────
       Les noms de page et d'utilisateur (`.ra-username`) sont codés en ROUGE Melis (#e61c23) dans
       styles.css. On NE les touche PAS en clair (accent voulu), mais en sombre on les réaligne sur
       l'accent du thème (bleu Studio) pour la cohérence avec le reste des tuiles. `#{$zoneId}` (id)
       l'emporte sur le sélecteur de classe seule du legacy. */
    #{$zoneId} .ra-username { color: var(--melis-plugin-primary) !important; }

    /* ── Graphique flot ───────────────────────────────────────────────────────────────────────
       Libellés d'axes / légende : flot pose leur couleur en style INLINE → !important obligatoire. */
    #{$zoneId} .flot-text,
    #{$zoneId} .flot-tick-label,
    #{$zoneId} .legend table,
    #{$zoneId} .legend .legendLabel { color: var(--melis-plugin-muted) !important; background: transparent !important; }
    /* Cadre de la boîte de légende : en clair, deux sources posent une « puce » habillée qui reste
       allumée en sombre et donne un encart gris/blanc écrasé (cf. section entourée) :
         • Prospects  → liseré `#e5e5e5` sur `.legend table` (`.cms-pros-dash-chart-line-graph …`) ;
         • orders-number → skin charts (`module.admin.page.charts`) qui habille les CELLULES
           (`.legend table tr td { background:#f9f9f9; border:1px solid #e5e5e5 }` + coins arrondis
           sur first/last-of-type).
       On unifie les deux : une seule pilule au liseré du thème sur `.legend table` (coins arrondis,
       cellules aérées) et on efface fond + bordure des cellules pour ne pas doubler le cadre ni
       laisser transparaître le gris clair. `#{$zoneId}` (id) l'emporte sur les sélecteurs de classe
       (même avec !important) des deux feuilles. */
    #{$zoneId} .legend table { border: 1px solid var(--melis-plugin-border) !important; border-radius: 6px !important; border-collapse: separate !important; }
    #{$zoneId} .legend table tr td { background: transparent !important; border: none !important; }
    #{$zoneId} .legend .legendColorBox { padding: 2px 3px 2px 6px !important; }
    #{$zoneId} .legend .legendLabel { padding: 2px 8px 2px 3px !important; white-space: nowrap !important; }
    /* Liseré clair (inline) du cadre des pastilles de légende. Ne viser que le cadre EXTÉRIEUR
       (`> div`) : la pastille intérieure exprime la couleur de la série via sa PROPRE bordure —
       la repeindre éteindrait les couleurs du graphique. */
    #{$zoneId} .legend .legendColorBox > div { border-color: var(--melis-plugin-border) !important; }
    } /* ← fin de html[data-melis-scheme="dark"] { … } (bloc de surcharges sombres, gaté par l'attribut) */
CSS;

        // ── Pop-up de confirmation « Valider / Refuser » (plugin Workflow) — habillage React ──────
        // Clic sur l'icône valider/refuser d'une demande → workflow.js appelle `melisCoreTool.confirm()`,
        // qui ouvre un BootstrapDialog `TYPE_WARNING` : bandeau orange, coins carrés, boutons rouge/vert
        // — l'esthétique du BO legacy. Dans la tuile React on le repeint aux tokens du shell : carte
        // arrondie, en-tête neutre, message discret, bouton « Non » sobre (contour) + « Oui » en couleur
        // de thème. Corrigé ICI (page iframe du dashboard React) et PAS dans melisCoreTool.js/workflow.js,
        // que le BO classique partage → chantier 3 isolé, le legacy n'est pas touché.
        // Tout est en `!important` (les feuilles bootstrap3-dialog sont chargées APRÈS ce <style>, cf.
        // `$cssLinks`) et strictement sous `.confirm-modal-header` — la `cssClass` que `confirm()` pose
        // sur la modale (melisCoreTool.js) — pour ne jamais toucher les AUTRES modales de cette page
        // (ex. la modale de commentaire ouverte juste après une validation). Gaté au seul plugin Workflow.
        $workflowDialogCss = $pluginName !== 'MelisSBWorkflowPlugin' ? '' : <<<'CSS'
    .confirm-modal-header .modal-content {
      border: 1px solid var(--melis-plugin-border) !important;
      border-radius: 14px !important;
      overflow: hidden !important;
      box-shadow: 0 24px 60px rgba(0, 0, 0, .28) !important;
      background: var(--melis-plugin-bg) !important;
      color: var(--melis-plugin-fg) !important;
    }
    /* En-tête : on neutralise le bandeau orange (`type-warning`) → surface neutre + filet. */
    .confirm-modal-header .modal-header {
      background: var(--melis-plugin-bg) !important;
      color: var(--melis-plugin-fg) !important;
      border-bottom: 1px solid var(--melis-plugin-border) !important;
      border-radius: 0 !important;
      padding: 18px 22px 14px !important;
    }
    .confirm-modal-header .modal-header .bootstrap-dialog-title {
      color: var(--melis-plugin-fg) !important;
      font-size: 16px !important;
      font-weight: 600 !important;
      line-height: 1.3 !important;
    }
    .confirm-modal-header .bootstrap-dialog-close-button {
      color: var(--melis-plugin-fg) !important;
      opacity: .55;
      text-shadow: none !important;
      font-size: 20px !important;
    }
    .confirm-modal-header .bootstrap-dialog-close-button:hover { opacity: 1; }
    .confirm-modal-header .modal-body {
      padding: 18px 22px 6px !important;
      color: var(--melis-plugin-muted) !important;
      font-size: 14px !important;
      line-height: 1.5 !important;
    }
    .confirm-modal-header .modal-footer {
      border-top: 0 !important;
      padding: 12px 22px 20px !important;
      display: flex !important;
      justify-content: flex-end !important;
      gap: 10px !important;
    }
    .confirm-modal-header .modal-footer .btn {
      float: none !important;
      margin: 0 !important;
      min-width: 96px !important;
      border-radius: 9px !important;
      padding: 9px 18px !important;
      font-size: 14px !important;
      font-weight: 500 !important;
      box-shadow: none !important;
      transition: filter .15s ease, background .15s ease !important;
    }
    /* « Non » : bouton sobre (contour) — c'est une annulation, pas une action destructrice. `order`
       verrouille sa place à gauche quel que soit le `pull-left`/`float` que le legacy lui remet. */
    .confirm-modal-header .modal-footer .btn-danger {
      order: 1;
      background: var(--melis-plugin-bg) !important;
      border: 1px solid var(--melis-plugin-border) !important;
      color: var(--melis-plugin-fg) !important;
    }
    .confirm-modal-header .modal-footer .btn-danger:hover { background: var(--melis-plugin-row-hover) !important; }
    /* « Oui » : bouton primaire en couleur de thème. */
    .confirm-modal-header .modal-footer .btn-success {
      order: 2;
      background: var(--melis-plugin-primary) !important;
      border: 1px solid var(--melis-plugin-primary) !important;
      color: #fff !important;
    }
    .confirm-modal-header .modal-footer .btn-success:hover { filter: brightness(1.08); }

    /* ── Modale « Ajouter un commentaire » (ouverte après une validation / un refus) ─────────────
       melisHelper.createModal ouvre ensuite cette modale (workflow-comment-modal) : le legacy
       l'affiche avec un bandeau ROUGE « Add comment », des coins carrés et des boutons rouge/vert.
       Même habillage React que la pop-up de confirmation, aux tokens du shell. Portée : le conteneur
       DÉDIÉ de cette modale (id déterministe posé par workflow.js) → aucune autre modale touchée.
       La cible du zoneReload (`#…_content`) porte DÉJÀ `.modal-content`, et le HTML chargé en
       réinjecte un SECOND : on habille l'extérieur en carte et on aplatit l'intérieur. */
    /* La modale est `position:fixed` DANS l'iframe de la tuile : elle ne peut donc pas être plus
       haute que la tuile → sur une petite tuile, une taille fixe débordait et se faisait rogner en
       haut ET en bas (en-tête et boutons hors champ). On borne la carte à la hauteur de l'iframe
       (`100vh` = hauteur de l'iframe ici) et on rend l'intérieur défilable : le chrome reste toujours
       atteignable et rien ne dépasse des coins arrondis, quelle que soit la taille de la tuile.
       `min()` sur la largeur : la modale suit aussi les tuiles étroites. */
    #id_melissb_dashboard_workflow_comment_modal_content_container.modal { overflow-y: auto !important; }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-dialog {
      max-width: min(460px, calc(100vw - 20px)) !important;
      margin: 10px auto !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content {
      display: flex !important;
      flex-direction: column !important;
      max-height: calc(100vh - 20px) !important;
      border: 1px solid var(--melis-plugin-border) !important;
      border-radius: 14px !important;
      overflow: hidden !important;
      box-shadow: 0 24px 60px rgba(0, 0, 0, .28) !important;
      background: var(--melis-plugin-bg) !important;
      color: var(--melis-plugin-fg) !important;
    }
    /* Le `.modal-content` chargé (2ᵉ couche) porte le défilement : `min-height:0` l'autorise à se
       réduire sous la hauteur de son contenu dans le conteneur flex, sinon il déborderait la carte. */
    #id_melissb_dashboard_workflow_comment_modal_content > .modal-content {
      flex: 1 1 auto !important;
      min-height: 0 !important;
      overflow-y: auto !important;
      border: 0 !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      background: transparent !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-body { padding: 0 !important; }
    /* Zone de saisie : plancher bas + plafond relatif à la tuile, pour qu'elle ne monopolise pas
       une petite modale (c'est la carte qui défile si besoin, pas le textarea qui écrase le reste). */
    #id_melissb_dashboard_workflow_comment_modal_content_container textarea {
      min-height: 84px !important;
      max-height: 40vh !important;
    }
    /* En-tête : on neutralise le bandeau rouge (widget-head + onglet actif). */
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-tabs .widget-head {
      background: var(--melis-plugin-bg) !important;
      border-bottom: 1px solid var(--melis-plugin-border) !important;
      padding: 4px 8px 0 !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-head ul.nav-tabs {
      border-bottom: 0 !important;
      margin: 0 !important;
      background: transparent !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-head ul.nav-tabs > li > a,
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-head ul.nav-tabs > li.active > a {
      background: transparent !important;
      border: 0 !important;
      color: var(--melis-plugin-fg) !important;
      font-weight: 600 !important;
      font-size: 15px !important;
      padding: 12px 14px !important;
      box-shadow: inset 0 -2px 0 var(--melis-plugin-primary) !important;
    }
    /* Glyphe « + » de l'onglet : en couleur de thème plutôt qu'en blanc sur rouge. */
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-head ul.nav-tabs > li > a.glyphicons i,
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-head ul.nav-tabs > li > a.glyphicons i:before {
      color: var(--melis-plugin-primary) !important;
    }
    /* Corps : libellé + zone de saisie aux tokens du shell. */
    #id_melissb_dashboard_workflow_comment_modal_content_container .widget-body { padding: 18px 20px !important; }
    #id_melissb_dashboard_workflow_comment_modal_content_container label,
    #id_melissb_dashboard_workflow_comment_modal_content_container .control-label {
      color: var(--melis-plugin-fg) !important;
      font-weight: 500 !important;
      font-size: 13px !important;
      margin-bottom: 6px !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container textarea,
    #id_melissb_dashboard_workflow_comment_modal_content_container input.form-control {
      background: var(--melis-plugin-bg) !important;
      color: var(--melis-plugin-fg) !important;
      border: 1px solid var(--melis-plugin-border) !important;
      border-radius: 9px !important;
      padding: 10px 12px !important;
      box-shadow: none !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container textarea:focus,
    #id_melissb_dashboard_workflow_comment_modal_content_container input.form-control:focus {
      border-color: var(--melis-plugin-primary) !important;
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--melis-plugin-primary) 22%, transparent) !important;
      outline: none !important;
    }
    /* Pied : filet + boutons alignés à droite (« Fermer » sobre, « Ajouter » en couleur de thème).
       Le legacy pose `justify-content-between` + `float-left` sur Fermer → on repasse en flex à
       droite, `order` verrouillant Fermer avant Ajouter. */
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer {
      border-top: 1px solid var(--melis-plugin-border) !important;
      padding: 14px 20px !important;
      margin-top: 14px !important;
      display: flex !important;
      flex-direction: row !important;
      justify-content: flex-end !important;
      gap: 10px !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer .btn {
      float: none !important;
      margin: 0 !important;
      min-width: 110px !important;
      border-radius: 9px !important;
      padding: 9px 16px !important;
      font-size: 14px !important;
      font-weight: 500 !important;
      box-shadow: none !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 6px !important;
      transition: filter .15s ease, background .15s ease !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer .btn-danger {
      order: 1;
      background: var(--melis-plugin-bg) !important;
      border: 1px solid var(--melis-plugin-border) !important;
      color: var(--melis-plugin-fg) !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer .btn-danger:hover { background: var(--melis-plugin-row-hover) !important; }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer .btn-success {
      order: 2;
      background: var(--melis-plugin-primary) !important;
      border: 1px solid var(--melis-plugin-primary) !important;
      color: #fff !important;
    }
    #id_melissb_dashboard_workflow_comment_modal_content_container .modal-footer .btn-success:hover { filter: brightness(1.08); }

    /* Voile de fond : le legacy le laisse gris/quasi opaque → on repasse à un noir translucide doux. */
    .modal-backdrop { background: #000 !important; }
    .modal-backdrop.in { opacity: .45 !important; }
CSS;

        // ── Mise en page MOBILE des plugins (tuile pleine largeur d'un petit écran) ───────────────
        //
        // Scopé à `html[data-melis-narrow="1"]`, posé UNIQUEMENT quand l'hôte React signale un écran
        // étroit (cf. $pluginNarrowAttr) — donc STRICTEMENT sans effet sur le dashboard de bureau,
        // y compris sur une petite tuile `col-4` dont l'iframe fait pourtant la même largeur qu'un
        // téléphone. C'est aussi pour ça que rien ici n'est une media query : les breakpoints
        // s'évaluent sur la largeur de l'IFRAME, qui ne distingue pas ces deux situations.
        //
        // Émis en DERNIER du <style> (après $gridFix et les correctifs par plugin) → à spécificité
        // supérieure ET postérieur dans la cascade, il l'emporte sans surenchère de !important.
        // Le bloc s'applique à TOUS les plugins : il n'y a pas de « plugin responsive » à traiter au
        // cas par cas, seulement des motifs legacy (grille figée, groupes de boutons flottants,
        // tables larges, frise à gouttière) qui reviennent d'un plugin à l'autre.
        $narrowCss = <<<'CSS'
    /* 1. Grille : on ANNULE le figeage de $gridFix. Sur un écran étroit, les colonnes doivent
       redevenir ce que Bootstrap en ferait sur mobile — une pile pleine largeur. Vaut pour les
       indicateurs CMS (2×2 → 4 lignes), les blocs KPI+table, les rangées de graphiques. */
    html[data-melis-narrow="1"] .row > [class*="col-"] { flex: 0 0 100% !important; width: 100% !important; max-width: 100% !important; }
    /* Gouttières négatives de `.row` : sur une tuile déjà au ras des bords, elles débordent. */
    html[data-melis-narrow="1"] .row { margin-left: 0 !important; margin-right: 0 !important; }
    /* `.innerAll` = le padding « BO large » du thème (15px de chaque côté) : ~9% de la largeur
       d'un téléphone pour rien. */
    html[data-melis-narrow="1"] .innerAll { padding: 8px !important; }

    /* 2. Blocs `row-merge` reconstruits plus haut en flex HORIZONTAL (prospects : KPI | table,
       et la rangée porte-graphique) : ils repassent en pile. Sans ça les colonnes remises à 100%
       ci-dessus resteraient côte à côte et déborderaient de la tuile. */
    html[data-melis-narrow="1"] .row-merge:has(.pros-dash-tbl),
    html[data-melis-narrow="1"] .row-merge:has(.flotchart-holder) { display: block !important; }
    html[data-melis-narrow="1"] .row-merge:has(.pros-dash-tbl) > [class*="col-"] { flex: none !important; width: 100% !important; max-width: none !important; }

    /* 3. Groupes de boutons de filtre (Hourly/Daily/Weekly/Monthly de MelisCommerce, Daily/Monthly/
       Yearly de Prospects) : `float-right` + 4 boutons côte à côte sortent de la tuile. On les met
       en flex qui PASSE À LA LIGNE, sur toute la largeur, sous le titre plutôt que flottants. */
    html[data-melis-narrow="1"] .btn-group.float-right,
    html[data-melis-narrow="1"] .btn-group.pull-right { float: none !important; }
    html[data-melis-narrow="1"] .btn-group { display: flex !important; flex-wrap: wrap !important; width: 100% !important; }
    html[data-melis-narrow="1"] .btn-group > .btn { flex: 1 1 auto !important; white-space: nowrap; }

    /* 4. Barres d'onglets d'un plugin : `nowrap` (règle générique plus haut, pensée pour le BO
       large) les faisait déborder. Elles passent à la ligne — tous les onglets restent visibles,
       ce qu'un défilement horizontal ne garantit pas. */
    html[data-melis-narrow="1"] .widget.widget-tabs > .widget-head ul.nav-tabs { flex-wrap: wrap !important; }

    /* 5.a Table LARGE → colonne ESSENTIELLE + « + » par ligne ────────────────────────────────
       Même principe que les listes du BO React (cf. ExpandToggle / HiddenColsRow) : plutôt que
       de faire défiler la table latéralement — où la moitié des colonnes est hors champ sans que
       rien ne l'indique — on ne garde QUE la colonne qui identifie la ligne, précédée d'un bouton
       « + » qui déplie les autres colonnes juste en dessous, en paires « LIBELLÉ : valeur ».
       La ligne fait alors [+][colonne essentielle] : elle tient dans n'importe quelle largeur,
       la table reste une table, et rien n'est caché — juste replié.
       N'est appliqué qu'aux tables qui débordent RÉELLEMENT (mesure à l'exécution, cf. le script
       plus bas qui pose `melis-exp`). */
    html[data-melis-narrow="1"] table.melis-exp { width: 100% !important; }
    /* Colonnes repliées : masquées dans la ligne, restituées dans le bloc déplié. */
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-hidden { display: none !important; }
    /* Colonne du bouton, à GAUCHE de tout : c'est là que l'œil la cherche (« déplier CETTE
       ligne »), pas noyée à droite au milieu des actions. */
    html[data-melis-narrow="1"] table.melis-exp th.melis-exp-th,
    html[data-melis-narrow="1"] table.melis-exp td.melis-exp-td {
      width: 34px !important;
      padding: 4px 2px 4px 8px !important;
      text-align: center;
      vertical-align: middle;
    }
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; padding: 0; margin: 0;
      border: 1px solid var(--melis-plugin-border); border-radius: 4px;
      background: transparent; color: var(--melis-plugin-fg);
      font-size: 14px; line-height: 1; cursor: pointer;
    }
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-btn:hover { background: var(--melis-plugin-row-hover); }
    /* La colonne essentielle prend toute la place restante et casse les longues valeurs plutôt
       que de repousser la ligne hors du cadre. */
    html[data-melis-narrow="1"] table.melis-exp tbody td:not(.melis-exp-td) { word-break: break-word; white-space: normal !important; }
    /* Bloc déplié : une ligne de table à part entière (colspan sur les 2 colonnes visibles), en
       retrait sous le bouton pour se rattacher visuellement à sa ligne. */
    html[data-melis-narrow="1"] table.melis-exp tr.melis-exp-row > td {
      border-top: 0 !important;
      padding: 0 10px 8px 44px !important;
      background: var(--melis-plugin-row-hover) !important;
    }
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-pair {
      display: flex; align-items: baseline; gap: 10px;
      padding: 3px 0; font-size: 12px; line-height: 1.4;
    }
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-k {
      flex: 0 0 auto; min-width: 78px;
      font-size: 11px; font-weight: 600; text-transform: uppercase;
      color: var(--melis-plugin-muted);
    }
    html[data-melis-narrow="1"] table.melis-exp .melis-exp-v { flex: 1 1 auto; min-width: 0; word-break: break-word; }
    /* Le wrapper ne défile plus : plus rien ne dépasse.
       ⚠️ `overflow` (les DEUX axes), pas `overflow-x` seul : quand un axe vaut autre chose que
       `visible`, la spec CSS transforme le `visible` de l'autre axe en `auto` — le legacy pose
       `overflow-y` sur `.overflow-x`, donc un `overflow-x: visible` isolé recalculait en `auto`
       (vérifié : `getComputedStyle(wrap).overflowX === 'auto'` malgré la règle). */
    html[data-melis-narrow="1"] .melis-exp-wrap { overflow: visible !important; }

    /* 5.b Tables qui tiennent encore dans la tuile : cellules resserrées (elles gardent leur
       défilement propre `.melis-scroll-x` en dernier recours). */
    html[data-melis-narrow="1"] .pros-dash-tbl thead th,
    html[data-melis-narrow="1"] .melis-commerce-dashboard-plugin-order-numbers-table thead th { padding: 6px 8px !important; font-size: 11px; }
    html[data-melis-narrow="1"] .pros-dash-tbl tbody td,
    html[data-melis-narrow="1"] .melis-commerce-dashboard-plugin-order-numbers-table tbody td { padding: 7px 8px !important; font-size: 12px; }
    html[data-melis-narrow="1"] .melis-scroll-x, html[data-melis-narrow="1"] .overflow-x { -webkit-overflow-scrolling: touch; }

    /* 6. Frise du plugin Annonces : le calibrage « gouttière + libellé de date accroché à
       `right:100%` » (cf. plus haut) réserve ~115px À GAUCHE du contenu. Sur un téléphone c'est le
       tiers de l'écran, et un libellé hors flux part carrément hors cadre. En étroit, la date
       revient DANS le flux, au-dessus de sa carte, et la gouttière disparaît. */
    html[data-melis-narrow="1"] .row:has(.layout-timeline) > [class*="col-"]:empty { display: none !important; }
    /* Le retrait de 50px de la liste (padding-left du thème) réservait la place du rail et des
       pastilles — supprimés juste en dessous : mesuré, il ne restait plus que 279px utiles sur
       390. Sans lui, les cartes d'annonce occupent toute la largeur de la tuile. */
    html[data-melis-narrow="1"] .layout-timeline ul.timeline { padding: 4px 2px !important; }
    html[data-melis-narrow="1"] .layout-timeline ul.timeline > li .type {
      position: static !important; right: auto !important; left: auto !important;
      margin: 0 0 6px 0 !important; padding: 0 !important; text-align: left !important;
      white-space: normal !important; width: auto !important; display: block !important;
    }
    /* Trait de liaison, pastille et rail : décorations calées sur la gouttière qu'on vient de
       supprimer — sans cible, elles se retrouveraient posées de travers sur le texte. */
    html[data-melis-narrow="1"] .layout-timeline ul.timeline > li .type::before,
    html[data-melis-narrow="1"] .layout-timeline ul.timeline > li .type::after,
    html[data-melis-narrow="1"] .layout-timeline ul.timeline > li.active::before { display: none !important; }
    /* L'heure était posée en absolu SOUS le libellé (top:24px) : en flux, elle le suit simplement. */
    html[data-melis-narrow="1"] .layout-timeline ul.timeline > li .type .time { position: static !important; display: block; }

    /* 7. Plugin Workflow (MelisSmallBusiness) : lignes et onglets resserrés. La tête (date -
       détails) et le bloc d'actions restent sur UNE ligne (cf. le `flex-basis: 0` plus haut, qui
       vaut aussi ici) ; c'est le padding « BO large » qui étranglait le texte sur un téléphone. */
    html[data-melis-narrow="1"] .melissb-dashboard-workflow .widget-body ul.list li { padding: 10px 10px !important; }
    html[data-melis-narrow="1"] .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul.nav-tabs { flex-wrap: wrap !important; }
    html[data-melis-narrow="1"] .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul li a.glyphicons { padding: 6px 8px !important; font-size: 11px; }
    html[data-melis-narrow="1"] .melissb-dashboard-workflow .dashboard-workflow-container .nav-tabs > li > a { padding: 8px 10px !important; font-size: 12px; }

    /* 8. Calendrier (MelisCalendar) : sa barre d'outils (titre + Préc./Suiv. + mois/semaine/jour)
       est une rangée unique, bien plus large qu'un téléphone. Elle passe à la ligne et ses boutons
       rétrécissent. */
    html[data-melis-narrow="1"] .fc-toolbar, html[data-melis-narrow="1"] .fc-header-toolbar { display: flex !important; flex-wrap: wrap !important; gap: 6px; }
    html[data-melis-narrow="1"] .fc-toolbar .fc-left, html[data-melis-narrow="1"] .fc-toolbar .fc-right, html[data-melis-narrow="1"] .fc-toolbar .fc-center { float: none !important; width: auto !important; }
    html[data-melis-narrow="1"] .fc-toolbar h2, html[data-melis-narrow="1"] .fc-toolbar-title { font-size: 15px !important; }
    html[data-melis-narrow="1"] .fc button, html[data-melis-narrow="1"] .fc .fc-button { padding: 2px 7px !important; font-size: 11px !important; height: auto !important; }

    /* 9. Typographie « lead » des tuiles de chiffres (indicateurs CMS, KPI prospects) : calibrée
       pour une colonne large, elle passait sur 3 lignes dans une carte de téléphone. */
    html[data-melis-narrow="1"] .lead { font-size: 14px !important; }
    html[data-melis-narrow="1"] .text-large { font-size: 20px !important; }

    /* 10. Plugin « Derniers commentaires » (MelisCmsComments) : avatar en COLONNE, pas en bandeau
       ── Cas 1 : vue initiale (latest-comments.phtml). L'avatar et le corps sont deux colonnes
       Bootstrap déclarées `col-xl-1 col-lg-1 col-12` / `col-xl-11 col-lg-11 col-12` : sous `lg`,
       c'est `col-12` qui gagne, soit 100 % CHACUNE — l'avatar occupe donc une ligne entière et le
       texte passe dessous. Dans une tuile étroite où la vignette fait 40px, il reste ~300px de
       vide à sa droite et elle paraît orpheline au-dessus du commentaire.
       Pour CE bloc on veut l'inverse de la règle générique §1 (qui empile les colonnes) : les
       deux côte à côte, avatar au strict nécessaire, corps élastique. */
    html[data-melis-narrow="1"] .mccom-comment > .row {
      display: flex !important;
      flex-wrap: nowrap !important;
      align-items: flex-start;
      gap: 10px;
      margin: 0 !important;
    }
    html[data-melis-narrow="1"] .mccom-comment .column-comment-profile-img {
      flex: 0 0 auto !important;
      width: auto !important;
      max-width: none !important;
      padding: 0 !important;
    }
    html[data-melis-narrow="1"] .mccom-comment .column-media-body {
      flex: 1 1 auto !important;
      width: auto !important;
      max-width: none !important;
      min-width: 0;
      padding: 0 !important;
      /* comments.css pose `margin-top: 1rem` sous 991px — calibré pour l'empilement, il décalait
         maintenant le texte d'un cran sous l'avatar. */
      margin-top: 0 !important;
    }
    /* ── Cas 2 : partielle rechargée après un changement de filtre (list.phtml). MÊME bloc, markup
       DIFFÉRENT — `<div class="float-left">` + `<div class="media-body">`, un « media object »
       Bootstrap 3 dont plus aucune règle ne subsiste (le module ne style que `.column-media-body`,
       la classe de l'AUTRE vue). Le corps s'habillerait autour du flottant, sauf qu'il contient
       deux `<div class="clearfix">` qui le NETTOIENT : seul le nom reste à côté de l'avatar, le
       titre/texte/date repartent sous lui. Même traitement, ciblé par `:has()` pour ne pas toucher
       la variante « row » ci-dessus. */
    html[data-melis-narrow="1"] li.mccom-comment:has(> .float-left) {
      display: flex !important;
      align-items: flex-start;
      gap: 10px;
    }
    html[data-melis-narrow="1"] .mccom-comment > .float-left { float: none !important; flex: 0 0 auto; margin: 0 !important; }
    html[data-melis-narrow="1"] .mccom-comment > .media-body { flex: 1 1 auto; min-width: 0; }
    html[data-melis-narrow="1"] .mccom-comment .clearfix { display: none !important; }
    /* ── Commun aux deux variantes : liste sans retrait de puces (l'avatar sert déjà de repère),
       interlignes resserrés, et un filet entre commentaires — une fois les cartes compactées,
       deux commentaires consécutifs se liraient sinon comme un seul bloc. */
    html[data-melis-narrow="1"] .mccom-list { padding-left: 0 !important; margin-bottom: 0; list-style: none; }
    html[data-melis-narrow="1"] .mccom-comment { padding: 8px 0 !important; border-bottom: 1px solid var(--melis-plugin-border); }
    html[data-melis-narrow="1"] .mccom-comment:last-child { border-bottom: 0; }
    html[data-melis-narrow="1"] .mccom-comment .media-heading { margin-bottom: 2px !important; }
    /* Le texte du commentaire hérite d'un `word-break: break-all` (feuille du module) : dans une
       colonne de ~250px il coupe les mots en plein milieu (« un pe / u long », constaté). On
       revient à une césure normale ; `overflow-wrap: break-word`, déjà posé sur la zone (§ « socle
       responsive »), reste le filet pour un vrai token insécable (URL, adresse e-mail). */
    html[data-melis-narrow="1"] .mccom-comment .mccom-text p { margin: 2px 0 !important; word-break: normal !important; }
CSS;

        $page = <<<HTML
<!DOCTYPE html>
<html data-melis-scheme="{$pluginScheme}"{$pluginNarrowAttr}>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Résout les URLs RELATIVES depuis la racine (comme toolPageAction). Sans ça, le plugin est
       chargé depuis "/melis/react-dashboard-plugin?plugin=X", donc un lien/AJAX relatif du legacy
       (ex. moxiemanager skin CSS, ou "melis/dashboard-plugin/…") se résout contre cette URL →
       "/melis/melis/dashboard-plugin/…" (404) ou une CSS renvoyant du HTML. <base href="/"> corrige. -->
  <base href="/" />
  <!-- Les surcharges de mise en page sont injectées APRES les feuilles du thème (voir
       $overrideCss plus bas) : à spécificité égale, c'est la dernière règle qui gagne. -->
  <script>
  /* Neutralise bundle.js "Remove Envato Frame" guard — same shim as toolPageAction. */
  try { window.__melisRealParent = window.parent; } catch(e) {}
  try {
    Object.defineProperty(window, 'parent', { get: function(){ return window; }, configurable: true });
    Object.defineProperty(window, 'top',    { get: function(){ return window; }, configurable: true });
  } catch(e) {}
  /* Défini AVANT les scripts du plugin (certains le lisent au chargement). melisCore.js le RECALCULE
     ensuite au ready depuis la barre d'onglets (voir le <ul> caché plus bas) — d'où les deux. */
  try { window.activeTabId = {$zoneIdJs}; } catch(e) {}
  /* bundle.js lance les plugins de BULLES (News/Notifications/Updates/Chat) partout → dans cette
     iframe ils POSTent .../dashboard-plugin/<BubblePlugin>/get* avec une mauvaise base → 404. Le
     dashboard React a sa PROPRE API de bulles ; ces appels ne sont jamais légitimes ici.

     ⚠️ Ne bloquer QUE les bulles, pas tout "/dashboard-plugin/" : c'est aussi l'URL par laquelle les
     widgets vont chercher LEURS données (ex. MelisCommerceDashboardPluginSalesRevenue POSTe vers
     /melis/dashboard-plugin/MelisCommerceDashboardPluginSalesRevenue/getDashboardSalesRevenueData).
     Un filtre large avalait ces requêtes → les graphiques flot ne recevaient jamais de données et
     restaient vides, d'où l'impression que "flotchart n'est pas chargé". */
  (function(){
    var BUBBLE_RE = /\/dashboard-plugin\/[^/]*Bubble[^/]*\//i;
    var _open = XMLHttpRequest.prototype.open;
    var _send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url){
      this.__melisBlocked = (typeof url === 'string' && BUBBLE_RE.test(url));
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
  /* ── Re-thème À CHAUD (sans rechargement) ───────────────────────────────────────────────────
     L'hôte React n'encode PLUS le thème dans l'URL de l'iframe : le faire changeait le `src` et
     rechargeait tout le bundle plateforme (jQuery/Bootstrap/flot/DataTable + refetch des données) →
     plusieurs secondes à chaque bascule clair/sombre. Il pousse désormais les tokens ici. On met à
     jour les 5 variables CSS de surface + l'attribut de scheme (qui pilote le bloc de surcharges
     sombres et le survol de ligne) : tout le rendu CSS suit instantanément, aucun rechargement.
     ⚠️ Les graphiques flot sont peints dans un <canvas> : ils ne se recolorent pas par CSS. Limite
     connue de cette v1 (le fond/la grille du graphe ne suivent qu'au prochain redraw). */
  try {
    window.addEventListener('message', function(e){
      var d = e && e.data;
      if (!d || !d.__melisRetheme) return;
      var el = document.documentElement, s = el.style;
      if (d.primary) s.setProperty('--melis-plugin-primary', d.primary);
      if (d.bg)      s.setProperty('--melis-plugin-bg', d.bg);
      if (d.fg)      s.setProperty('--melis-plugin-fg', d.fg);
      if (d.border)  s.setProperty('--melis-plugin-border', d.border);
      if (d.muted)   s.setProperty('--melis-plugin-muted', d.muted);
      if (d.scheme === 'dark' || d.scheme === 'light') {
        el.setAttribute('data-melis-scheme', d.scheme);
        s.colorScheme = d.scheme;
      }
    });
  } catch(e) {}
  /* ── Bascule MOBILE à chaud ───────────────────────────────────────────────────────────────────
     Même canal, même raison qu'au-dessus : recharger l'iframe pour changer un booléen de mise en
     page coûterait plusieurs secondes de bundle. L'hôte pousse son `useIsNarrow` (fenêtre du BO,
     PAS la largeur de cette iframe — cf. le commentaire côté PHP) et on ne fait que poser/retirer
     l'attribut auquel le bloc « étroit » du <style> est scopé. */
  try {
    window.addEventListener('message', function(e){
      var d = e && e.data;
      if (!d || !d.__melisNarrow) return;
      var el = document.documentElement;
      if (d.narrow) el.setAttribute('data-melis-narrow', '1');
      else el.removeAttribute('data-melis-narrow');
    });
  } catch(e) {}
{$inlineGlobals}
  </script>
{$cssLinks}
  <style>/* Tokens du thème hôte (cf. `?primary=`, `?bg=`… et `?scheme=`). En variables pour n'avoir
       qu'un point à changer, et pour que les règles ci-dessous restent lisibles — c'est ce qui
       permet aux règles COMMUNES (bordures de tables, survol de ligne) de valoir dans les deux
       modes sans avoir à les redéclarer dans le bloc sombre. `color-scheme` fait suivre ce que le
       navigateur peint lui-même : ascenseurs, champs de formulaire, `<select>`. */
    :root {
      --melis-plugin-primary: {$pluginPrimary};
      --melis-plugin-bg: {$pluginBg};
      --melis-plugin-fg: {$pluginFg};
      --melis-plugin-border: {$pluginBorder};
      --melis-plugin-muted: {$pluginMuted};
      color-scheme: {$pluginScheme};
    }
    /* Survol de ligne : dérivé du MODE (l'hôte n'expose pas de token équivalent), donc porté par
       l'attribut de scheme — un basculement live (JS flippe `data-melis-scheme`) reprend la bonne
       valeur sans recharger. Voile translucide en sombre (se pose sur le vrai fond, quel qu'il soit),
       gris discret en clair. Les 5 tokens ci-dessus, eux, sont réécrits à chaud par `__melisRetheme`. */
    html[data-melis-scheme="light"] { --melis-plugin-row-hover: #fafafa; }
    html[data-melis-scheme="dark"]  { --melis-plugin-row-hover: rgba(255,255,255,0.06); }
    /* !important + padding: le thème legacy (bundle.css, chargé APRÈS ce <style>) pose
       `body { padding-top: 47px }` — la réserve pour sa navbar fixe, qui n'existe pas ici. Sans ça
       le contenu du plugin démarre 47px trop bas dans la tuile React (grosse bande vide en haut). */
    body { margin: 0 !important; padding: 0 !important; overflow: auto; }
    /* ⚠️ Deux pièges vérifiés en mesurant la page réelle — ne pas retenter :
       - `html, body { height: auto }` : le thème legacy pose `height: 100%` et TOUTE la mise en
         page des plugins repose sur cette chaîne de pourcentages. La casser fait retomber les
         hauteurs en `%` à zéro (calendrier effondré à ~135px, `body.scrollHeight` = 0).
       - `overflow: auto !important` sur body : fait apparaître des barres de défilement DANS la
         tuile, et le rendu est plus dégradé encore que le contenu simplement rogné.
       Corollaire : `body` faisant toujours exactement la hauteur de l'iframe, `scrollHeight` y est
       plafonné — mesurer le document pour dimensionner la tuile est circulaire et ne peut pas
       fonctionner (la hauteur des tuiles vient donc de la config du plugin + redimensionnement
       manuel). */
    /* La zone porte `tab-pane active` (voir le <div> plus bas) uniquement pour que les scripts de
       plugin retrouvent leur graphique via `closest('.tab-pane')`. On annule le seul effet visuel
       de cette classe qui nous gêne : `.tab-pane.active { overflow: hidden }` (bundle.css) rognerait
       les décorations posées en NÉGATIF hors de la zone — libellés de date et rail de la frise du
       plugin Annonces (`right:100%`, `left:-20px`, cf. plus bas). Dans le BO legacy la zone est bien
       plus large que son contenu, ici elle en épouse les bords. */
    #{$zoneId}.tab-pane.active { overflow: visible !important; }
    .widget-header-content, .widget-header-actions { display: none !important; }
    #melis-id-nav-bar-tabs { display: none !important; }
    /* Le conteneur legacy du plugin est conservé (des plugins lisent leur config dans son DOM), mais
       il est prévu pour vivre DANS une grille gridstack : .grid-stack-item est positionné en absolu
       et l'en-tête .widget-head duplique le cadre React. On remet le tout en flux normal et on masque
       le chrome legacy — seul le contenu du plugin reste visible. */
    .grid-stack-item { position: static !important; width: auto !important; height: auto !important; left: auto !important; top: auto !important; }
    .grid-stack-item-content { position: static !important; overflow: visible !important; }
    /* ⚠️ Masquer SEULEMENT l'en-tête du CONTENEUR (plugin-container.phtml :
       .widget > .widget-parent > .widget-head), qui duplique le cadre React. Un `.widget-head`
       tout court emportait aussi les en-têtes que certains plugins rendent DANS leur propre vue —
       ex. MelisCmsProspectsStatisticsPlugin, dont la barre d'onglets (courbes / barres) vit dans
       un `.widget.widget-tabs > .widget-head` : les onglets disparaissaient purement et simplement. */
    .widget-parent > .widget-head { display: none !important; }
    .widget, .widget-inverse { margin: 0 !important; border: 0 !important; background: transparent !important; box-shadow: none !important; }

    /* Tab bar OF A PLUGIN'S OWN VIEW (kept visible by the rule above) — e.g.
       MelisCmsProspectsStatisticsPlugin's lines/bars switch. Its tabs kept stacking as one
       full-width row each, centered, instead of sitting side by side.
       The legacy theme lays these out through a chain of rules gated on ancestors and on media
       queries that the classic BO satisfies but this iframe does not (breakpoints resolve against
       the IFRAME width, cf. \$gridFix). Rather than replicate that chain, pin the bar directly:
       an explicit horizontal flex row, items at their natural width, left-aligned.
       Scoped to `.widget-tabs > .widget-head` so it can never touch the hidden container header
       nor the fake #melis-id-nav-bar-tabs strip in <body>. */
    .widget.widget-tabs > .widget-head ul.nav-tabs {
      display: flex !important;
      flex-direction: row !important;
      flex-wrap: nowrap !important;
      justify-content: flex-start !important;
      width: auto !important;
      max-width: none !important;
      margin: 0 !important;
      padding: 0 !important;
      text-align: left !important;
    }
    .widget.widget-tabs > .widget-head ul.nav-tabs > li {
      display: block !important;
      flex: 0 0 auto !important;
      width: auto !important;
      max-width: none !important;
      float: none !important;
    }

    /* Grille Bootstrap figée (cf. \$gridFix côté PHP) : les media queries s'évaluent sur la
       largeur de l'iframe, pas de la fenêtre → sans ça toutes les colonnes s'empilent. */
{$gridFix}
    /* Même piège que \$gridFix, côté ESPACEMENTS : les media queries s'évaluant sur la largeur de
       l'iframe (~500-700px), les règles « mobile » (max-width) des modules s'appliquent à tort et
       suppriment des marges prévues pour le petit écran. Cas vérifié : melis-cms
       (public/css/styles.css) pose `@media (max-width:768px){ .cms-page-indicators-plugin .innerAll
       { margin:0 } }` → dans le BO classique les 4 tuiles du plugin Indicateurs sont séparées
       (margin 5px 0), dans la tuile React elles se collent verticalement. On rétablit la valeur
       « fenêtre large ». */
    .cms-page-indicators-plugin .innerAll { margin: 5px 0 !important; }
    /* ── Responsive floor, applied to EVERY legacy plugin ─────────────────────────────────
       The frozen grid above keeps a plugin's proportions identical at any tile size, which is
       what we want — but proportions alone are not enough: a 25% column in a narrow tile is
       genuinely narrow, and legacy views were written for the wide classic BO. Anything with an
       intrinsic width then punches out of its box and the tile scrolls sideways (or clips).
       These rules are the floor that makes every plugin survive any width; per-plugin fixes
       below stay reserved for layouts that need real reshaping. */
    /* Media never wider than its column. `height: auto` keeps the aspect ratio — but it must NOT
       reach <canvas>: flot sizes its canvas itself (attribute width/height + inline style), and
       forcing `height: auto` there squashes the chart to zero. */
    img, svg, video { max-width: 100% !important; height: auto; }
    canvas { max-width: 100% !important; }
    /* Long unbreakable tokens — e-mail addresses, URLs, slugs — are the usual overflow culprit in
       plugin tables and lists. `break-word` only breaks when the word alone cannot fit, so normal
       text keeps wrapping normally. */
    #{$zoneId} { overflow-wrap: break-word; }
    /* A table cannot shrink below the intrinsic width of its content, so a wide table in a narrow
       tile WILL overflow whatever we do — the only sane answer is to let it scroll on its own,
       rather than pushing the whole plugin sideways. `.overflow-x` is the legacy theme's own hook
       for this; `.melis-scroll-x` is the wrapper we add (see script below) to the tables that have
       none. `max-width` keeps the table itself from stretching its wrapper. */
    table { max-width: 100%; }
    .overflow-x, .melis-scroll-x { overflow-x: auto !important; max-width: 100%; }

    /* Colonne d'ESPACEMENT vide — ex. le `<div class="col-md-2"></div>` de l'annonce, qui décale
       la frise dans la fenêtre large du BO legacy. Dans une tuile étroite elle mange ~17% de la
       largeur pour rien (et depuis que la grille ci-dessus ne s'empile plus, elle ne disparaît
       plus d'elle-même). On la retire et on laisse sa voisine occuper toute la place. */
    .row > [class*="col-"]:empty { display: none !important; }
    .row:has(> [class*="col-"]:empty) > [class*="col-"]:not(:empty) { flex: 1 1 auto !important; width: auto !important; max-width: none !important; }

    /* EXCEPTION à la règle ci-dessus : la frise (.layout-timeline, plugin Annonces) a besoin de sa
       gouttière — ses libellés de date sont en `position:absolute` à `left:-160px` et le rail rouge
       à `-45px`. Retirer la colonne vide les tronquait. On la conserve donc, mais calibrée au strict
       nécessaire au lieu des 2/12 de la largeur : sur une tuile large, c'est autant de place rendue
       au contenu, sans rien perdre du rail ni des dates.
       Calibrage : tout est suspendu au décalage du libellé. Le thème le pose à `left:-160px` avec
       35px de padding et un trait de liaison de 45px — soit ~115px à réserver À GAUCHE du contenu.
       Rogner les paddings internes ne sert à RIEN (ce qu'on leur retire, la gouttière doit le
       rendre) : on resserre donc la frise elle-même — libellé à -130px, trait et pastille
       raccourcis d'autant, rail remonté à -24px — puis on ramène la colonne à 92px.
       Gain net ≈ 30px vers la gauche. En dessous, « JUL 23, 2026 » passerait sur deux lignes. */
    .row:has(.layout-timeline) > [class*="col-"]:empty { display: block !important; flex: 0 0 76px !important; width: 76px !important; max-width: 76px !important; }
    /* Le libellé n'est plus une BOÎTE FIXE de 100px calée à `left:-160px` (elle réservait sa
       largeur même pour un texte court, d'où le retrait persistant) : on l'accroche au bord du
       contenu (`right:100%`) et on le laisse se dimensionner sur son texte. Trait de liaison,
       pastille et rail suivent ; la gouttière n'a plus qu'à couvrir le libellé le plus long. */
    /* `margin-right` = respiration entre le libellé et le RAIL (qui tombe 20px à gauche du
       contenu) : 40px de marge - 20px de rail = 20px d'air. Le trait de liaison est rallongé
       d'autant pour continuer à relier le libellé à la carte, et la pastille reste centrée
       sur le rail (bord droit du libellé + 24px - 8px de large → centre à -20px). */
    /* ⚠️ `position: absolute` EXPLICITE. Sous ~991px de large — ce qui arrive dès qu'on ZOOME,
       le zoom réduisant la largeur en pixels CSS — le thème bascule le libellé en
       `position: relative`. Or `right: 100%` sur un élément RELATIF le décale de 100% de la
       largeur du conteneur vers la gauche : les dates partaient hors écran (« les labels ont
       disparu »). Même raison pour la pastille/le trait, que ce même bloc masque. */
    .layout-timeline ul.timeline > li .type { position: absolute !important; left: auto !important; right: 100% !important; margin: 0 40px 0 0 !important; width: auto !important; white-space: nowrap !important; padding: 0 !important; text-align: right !important; }
    .layout-timeline ul.timeline > li .type::after { display: block !important; right: -40px !important; width: 34px !important; }
    .layout-timeline ul.timeline > li .type::before { display: block !important; right: -24px !important; }
    .layout-timeline ul.timeline > li .type .time { position: absolute !important; top: 24px !important; left: auto !important; right: 0 !important; }
    .layout-timeline ul.timeline > li.active::before { display: block !important; left: -20px !important; }

    /* ── Objet média (avatar + nom) de chaque annonce ─────────────────────────────────────────
       Le thème vise le rendu Bootstrap `.media{display:flex}` : la gouttière avatar↔nom vient de
       `.layout-timeline .media .media-body{margin-left:15px}`. Mais une règle legacy repasse
       `.media{display:block}` (dernière déclaration `display` du bundle) → l'avatar `float-left`
       de 50px AVALE ce margin-left et le nom vient coller l'image (symptôme signalé, visible
       surtout en sombre où le disque blanc de l'avatar tranche sur le fond). On rétablit donc le
       flex attendu par le thème : l'avatar redevient le 1er item, le margin-left du corps agit à
       nouveau comme gouttière. Scopé à la frise → n'affecte que le plugin Annonces. */
    .layout-timeline .media { display: flex !important; align-items: flex-start; }

    /* ── Reprise de mise en page : bloc « KPI + derniers prospects » (MelisCmsProspects) ──
       Le markup legacy juxtapose un `.col-sm-3` (icône + total) et un `.col-sm-9` (table) dans un
       `.row-merge` : les deux colonnes ne font pas la même hauteur, une hairline `.border-bottom`
       traîne sous le KPI, et surtout Bootstrap 5 REPEINT les cellules de `thead.bg-primary` via
       `--bs-table-bg` → l'en-tête de table perd son fond et devient illisible/plat.
       Corrigé ICI (page iframe du dashboard React) et pas dans la vue du module : le BO legacy
       n'est pas touché. `:has()` cible le bloc sans dépendre d'un id de plugin. */
    .row-merge:has(.pros-dash-tbl) { display: flex; align-items: stretch; }
    /* `flex: 1` + `min-width: 0` : sans ça la colonne table se dimensionne sur son CONTENU
       (largeur intrinsèque de la table) au lieu d'occuper la place restante — d'où le grand
       vide à droite. `min-width: 0` autorise en plus le rétrécissement sous cette largeur. */
    .row-merge:has(.pros-dash-tbl) > .col-sm-3 { flex: 0 0 25%; max-width: 25%; }
    /* Filet vertical entre colonnes « mergées », dessiné par le thème legacy en pseudo-élément
       (`.row-merge > [class*=col-] ~ [class*=col-]:after`) — pas une bordure, d'où l'impossibilité
       de le retirer côté colonne. */
    .row-merge:has(.pros-dash-tbl) > [class*="col-"]::after { display: none !important; }
    .row-merge:has(.pros-dash-tbl) > .col-sm-9 { flex: 1 1 auto; min-width: 0; max-width: none; }
    .row-merge:has(.pros-dash-tbl) > [class*="col-"] { display: flex; flex-direction: column; justify-content: center; }
    .row-merge:has(.pros-dash-tbl) > .col-sm-3 .border-bottom { border-bottom: 0 !important; }
    .row-merge:has(.pros-dash-tbl) .overflow-x { width: 100%; }
    /* Le graphique flot mesure SON conteneur : si la ligne/colonne ne fait pas toute la largeur,
       le canvas naît étroit. On force la rangée du graphe et son porte-graphe à 100%. */
    .row-merge:has(.flotchart-holder) > [class*="col-"] { flex: 1 1 auto; width: 100%; max-width: none; }
    .flotchart-holder { width: 100% !important; }
    /* ── Habillage commun des tables de plugin ────────────────────────────────────────────────
       Même skin pour toutes les tables listées ici : en-tête plein (le `thead.bg-primary` du
       markup legacy, que Bootstrap 5 aplatit via `--bs-table-bg`), lignes aérées, survol discret.
       Ajouter une table = ajouter sa classe aux 4 règles ci-dessous, rien d'autre.
         .pros-dash-tbl                                        derniers prospects (MelisCmsProspects)
         .melis-commerce-dashboard-plugin-order-numbers-table   dernières commandes (MelisCommerce) */
    .pros-dash-tbl,
    .melis-commerce-dashboard-plugin-order-numbers-table { width: 100%; margin: 0 !important; border-top: 0 !important; }
    .pros-dash-tbl thead th,
    .melis-commerce-dashboard-plugin-order-numbers-table thead th { background: var(--melis-plugin-primary) !important; color: #fff !important; border: 0 !important; font-weight: 600; padding: 8px 12px !important; white-space: nowrap; }
    .pros-dash-tbl tbody td,
    .melis-commerce-dashboard-plugin-order-numbers-table tbody td { padding: 9px 12px !important; border-top: 1px solid var(--melis-plugin-border) !important; vertical-align: middle; }
    /* Survol de ligne : conservé pour les commandes, mais volontairement ABSENT pour les prospects
       (`.pros-dash-tbl`) — demande explicite « pas d'effet au survol » sur cette table. */
    .melis-commerce-dashboard-plugin-order-numbers-table tbody tr:hover td { background: var(--melis-plugin-row-hover) !important; }
    .pros-dash-tbl .pros-dash-lbl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* ── Onglets d'en-tête du plugin Workflow (MelisSmallBusiness) ─────────────────────────────
       Le module fige la hauteur des onglets à 54px sous 767px
       (melis-small-business/public/css/style.css, `@media only screen and (max-width: 767px)`).
       Cette règle visait le BO classique sur mobile ; ici elle s'applique TOUJOURS, parce que la
       media query est évaluée contre la largeur de l'IFRAME (celle de la tuile), pas de la fenêtre
       — même piège que le `$gridFix` plus haut. Les onglets n'ont besoin que des ~32px de leur
       padding (`.nav-tabs > li > a`), d'où une bande verte à moitié vide sous les libellés.
       `height: auto` rend la hauteur au padding, et laisse le libellé passer à deux lignes s'il
       est long. Corrigé ICI et pas dans la vue du module : le BO legacy n'est pas touché.
       Même spécificité que la règle du module, mais postérieure dans la cascade → l'emporte. */
    .dashboard-workflow-container .nav-tabs li { height: auto !important; }
    /* ── Barre des TYPES de demande (PAGE / TEAM …) ────────────────────────────────────────────
       C'est le `.widget-head` sous les onglets verts : une rangée d'onglets « icône seule »
       (`widget-tabs-icons-only-2` rogne le libellé à 38px → il ne reste que le glyphe). Le thème
       lui met DEUX filets qui se cumulent en un trait dur pleine largeur — celui de `ul.nav-tabs`
       (bundle) ET celui de `a.glyphicons` (styles.css) — collé au sommet de la 1ʳᵉ ligne, d'où
       l'impression que le glyphe « flotte » sur un trait qui coupe le contenu.
       On ne garde qu'UN filet, en couleur de thème, et on l'espace du contenu. On ne touche PAS à
       la couleur des glyphes (i:before) : le legacy est conservé. */
    .melissb-dashboard-workflow .dashboard-workflow-tabs > .widget-head { padding: 6px 14px 0 !important; margin: 0 !important; background: transparent !important; }
    .melissb-dashboard-workflow .dashboard-workflow-tabs > .widget-head ul.nav-tabs { margin: 0 !important; border-bottom: 1px solid var(--melis-plugin-border) !important; }
    .melissb-dashboard-workflow .dashboard-workflow-tabs > .widget-head ul.nav-tabs > li > a.glyphicons { border-bottom: 0 !important; }
    /* Le thème « icons-only-2 » enferme l'onglet dans une boîte de 38px (`a.glyphicons{width:38px}`,
       `i{display:block;line-height:40px}`), ce qui EJECTE le libellé du type (PAGE / TEAM …) hors du
       cadre → il ne reste qu'un glyphe orphelin. On rend la boîte élastique et on remet glyphe +
       libellé EN LIGNE : le nom du type redevient lisible. Couleurs de glyphe INCHANGÉES (le legacy
       peint `i:before` en #cbcbcb / #505050 actif — on n'y touche pas). */
    .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul li a.glyphicons { width: auto !important; padding: 7px 12px !important; display: inline-flex !important; align-items: center; font-size: 12px; }
    .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul li a.glyphicons i { width: auto !important; display: inline-block !important; line-height: 1 !important; margin-right: 7px; }
    .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul li a.glyphicons i:before { width: auto !important; display: inline-block !important; line-height: 1 !important; position: static !important; }
    /* Onglet de type actif : léger fond + souligné en couleur de thème pour le repérer (le glyphe
       actif reste #505050, on n'ajoute qu'un repère de fond/soulignement — pas une couleur de police). */
    .melissb-dashboard-workflow .widget-tabs-icons-only-2 > .widget-head ul li.active a.glyphicons { background: var(--melis-plugin-row-hover) !important; border-radius: 6px 6px 0 0 !important; box-shadow: inset 0 -2px 0 var(--melis-plugin-primary); }
    /* Corps des demandes : un peu d'air sous le filet de la barre de types. */
    .melissb-dashboard-workflow .dashboard-workflow-tabs > .widget-body { padding-top: 6px !important; }
    /* ── Liste des demandes du plugin Workflow (MelisSmallBusiness) ────────────────────────────
       Même piège que ci-dessus, mais sur les LIGNES : le module positionne le bloc d'actions
       (validate / refuse / badge VALIDATED / œil) en `position:absolute; right:0; top:-12px`, puis
       le repasse en `position:relative; float:right` sous 991px. La media query s'évaluant contre
       la largeur de l'IFRAME, c'est TOUJOURS la variante « mobile » qui s'applique ici : les
       actions retombent dans le flux, le `top:-12px` les fait mordre sur la ligne du dessus, et
       elles se collent au texte date/détails — d'où des lignes serrées et qui se chevauchent.
       On remet la ligne en flux normal, en flex : flèche, tête (date - détails) extensible, bloc
       d'actions poussé à droite, et le détail dépliable (`.wd-cont-content`, affiché au clic) sur
       sa propre ligne pleine largeur. Corrigé ICI et pas dans la vue du module : le BO legacy
       n'est pas touché. */
    /* ⚠️ Cause racine du « texte rogné » : le thème (bundle.css, `.widget-activity ul.list li`)
       impose aux lignes une HAUTEUR fixe avec `line-height: 39px` et `overflow: hidden`. Avec
       `box-sizing: border-box`, notre padding de 16px ne fait que réduire la zone de contenu à
       ~7px → la ligne (≈47px) est coupée à une lichette. On rend donc la hauteur au contenu :
       `height/min-height: auto`, `line-height: normal`, `overflow: visible`. C'est CE bloc qui
       débloque l'affichage — le padding seul ne suffisait pas. */
    .melissb-dashboard-workflow .widget-body ul.list li { padding: 14px 18px !important; height: auto !important; min-height: 0 !important; line-height: normal !important; overflow: visible !important; border-bottom: 1px solid var(--melis-plugin-border); }
    .melissb-dashboard-workflow .widget-body ul.list li:last-child { border-bottom: 0; }
    .melissb-dashboard-workflow .wd-cont { display: flex; flex-wrap: wrap; align-items: center; column-gap: 12px; row-gap: 6px; line-height: 1.45; }
    .melissb-dashboard-workflow .wd-cont span { line-height: 1.45; }
    /* La flèche est en `float:left` + marges dans le thème du module : inutile (et nuisible) en flex. */
    .melissb-dashboard-workflow .wd-cont > span.fa-arrow-down { float: none !important; margin: 0 !important; order: 0; }
    /* ⚠️ `flex-basis: 0` et NON `auto` — c'est ce qui garde le bloc d'actions (l'œil) SUR LA MÊME
       LIGNE que la date quand la tuile est étroite. Le passage à la ligne d'un conteneur flex se
       décide sur la taille HYPOTHÉTIQUE des items (= leur flex-basis, donc `max-content` quand elle
       vaut `auto`), AVANT toute réduction : avec `1 1 auto`, la tête faisait 391px de texte dans une
       ligne de 409px → l'œil ne rentrait plus et tombait à la ligne suivante (mesuré). `min-width:0`
       n'y change rien : il n'agit qu'APRÈS la répartition en lignes. Avec une base à 0, la tête
       n'occupe plus de place hypothétique, tout tient sur une ligne, et elle reprend l'espace
       restant par `flex-grow` (le titre s'ellipse déjà, cf. plus bas). */
    .melissb-dashboard-workflow .wd-cont > .wd-cont-head { order: 1; flex: 1 1 0%; min-width: 0; }
    /* `flex: 0 0 auto` : le bloc d'actions garde sa taille — c'est la tête qui absorbe la réduction. */
    .melissb-dashboard-workflow .wd-cont > .d-workflow-action-cont { order: 2; flex: 0 0 auto; position: static !important; float: none !important; top: auto !important; right: auto !important; height: auto !important; margin-left: auto; display: flex; align-items: center; gap: 6px; }
    .melissb-dashboard-workflow .wd-cont > .wd-cont-content { order: 3; flex: 0 0 100%; }
    /* Les icônes d'action portent `padding: 13px 10px` (calibré pour une ligne haute du BO
       classique) : dans la tuile elles gonflent la ligne et se touchent. */
    .melissb-dashboard-workflow .dashboard-widget-workflow ul.list li .d-workflow-action-cont i { padding: 4px 6px !important; }
    .melissb-dashboard-workflow .dashboard-widget-workflow ul.list li span:last-child i { padding-right: 6px !important; }
    /* Le module plafonne la zone à 287px : dans une tuile redimensionnable, ça laisse un grand vide
       en bas (tuile haute) ou un ascenseur imbriqué. La tuile gère déjà son propre défilement. */
    .melissb-dashboard-workflow .dashboard-widget-workflow { max-height: none !important; }
    /* Alignement de la tête (date - détails) : simple mise en ligne, SANS toucher aux couleurs de
       police legacy (le module garde son `uppercase; bold`). On laisse juste le libellé se tronquer
       proprement plutôt que de pousser le bloc d'actions hors de la tuile. */
    .melissb-dashboard-workflow .wd-cont-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 6px; }
    /* Tuile vraiment étroite : la date passe seule sur sa ligne (le titre suit) plutôt que de
       déborder sur l'œil. `min-width:0` + ellipse = filet de sécurité pour les cas extrêmes. */
    .melissb-dashboard-workflow .wd-cont-head .wd-date { white-space: nowrap; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .melissb-dashboard-workflow .wd-cont-head .wd-info-cont:not(.wd-date) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* Le détail dépliable respire et se démarque de la tête. */
    .melissb-dashboard-workflow .dashboard-widget-workflow ul.list li .wd-cont-content { padding: 8px 0 2px !important; border-top: 1px solid var(--melis-plugin-border); }
    .melissb-dashboard-workflow .wd-cont-content p + p { margin-top: 4px !important; }
{$workflowDialogCss}
{$darkCss}
{$narrowCss}</style>
</head>
<body>
<!-- Barre d'onglets factice (cachée). melisCore.js, à son init, ÉCRASE le global activeTabId avec
     `\$navTabs.find("li.active").children("a").data("id")` (melisCore.js:939) : sans ce strip il
     repasse à `undefined` juste après notre affectation, et les plugins qui ciblent leur conteneur
     via $("#"+activeTabId) plottent alors dans une sélection VIDE (→ flot sans dimensions →
     "createLinearGradient: non-finite"). Le `li` DOIT porter la classe `active` (c'est `li.active`
     qui est cherché, pas le `<a>`). -->
<ul class="nav nav-tabs navbar-nav tabsbar" id="melis-id-nav-bar-tabs" role="tablist">
  <li class="nav-item active" data-tool-id="{$zoneId}" data-tool-meliskey="meliscore_dashboard" role="presentation">
    <a data-bs-toggle="tab" class="nav-link tab-element active" href="#{$zoneId}" data-id="{$zoneId}">dashboard</a>
  </li>
</ul>
<!-- Le JS plateforme est chargé ICI — dans <body>, APRÈS le strip d'onglets — et NON dans <head>,
     pour la même raison que toolPageAction : melisCore.js est une IIFE qui CACHE ses sélecteurs au
     chargement (`var \$navTabs = \$("#melis-id-nav-bar-tabs")`). Chargé depuis <head>, ce cache est
     VIDE (pas encore de <body>) → activeTabId retombe à undefined quoi qu'on fasse. -->
{$headJs}
<script>
/* ── Never let flot draw into a holder that has no dimensions ──────────────────────────────
   A tile's iframe is not always laid out when its plugin draws: the widget is mounted (and its
   document runs) while the grid item is still being placed/re-parented by GridStack, or while
   the tile is collapsed. flot MEASURES its holder at draw time and THROWS on a zero box —
   `Uncaught Error: Invalid dimensions for plot, width = 0, height = 0` (seen from
   commerceDashboardOrdersLineGraphInit, MelisCommerceDashboardPluginOrdersNumber.js). Legacy
   plugins draw from an AJAX callback and never re-try, so the chart then stays EMPTY for good —
   the resize observer further down only tracks holders that ALREADY carry a flot instance.

   So we wrap `\$.plot`: when the holder has no box yet, the call is QUEUED and replayed as soon
   as the element gets one (ResizeObserver, exact and immediate; timeouts as a safety net for
   holders that are re-attached without a size change). Deferred calls return `null`, which is
   what every legacy plugin here stores in `charts.<x>.plot` — none of them chains on the return
   value, and their `init()` guards on `plot == null`, so a queued draw is harmless.

   Generic: no per-plugin knowledge, every flot chart of every plugin page benefits. */
(function(){
  var jq = window.jQuery;
  if (!jq || typeof jq.plot !== 'function' || !window.ResizeObserver) return;

  var original = jq.plot;
  var queued   = [];

  function sized(el){ return !!el && el.clientWidth > 0 && el.clientHeight > 0; }

  var ro = new ResizeObserver(function(){ flush(); });

  function flush(){
    for (var i = queued.length - 1; i >= 0; i--) {
      var q = queued[i];
      /* Holder removed from the document (widget refreshed/closed): drop the pending draw. */
      if (!q.el.isConnected) { queued.splice(i, 1); try { ro.unobserve(q.el); } catch(e) {} continue; }
      if (!sized(q.el)) continue;
      queued.splice(i, 1);
      try { ro.unobserve(q.el); } catch(e) {}
      try {
        original.call(jq, jq(q.el), q.data, q.options);
        /* Tell the resize observer below to pick up this brand-new chart: its own rescans stop
           at 3 s, and a chart drawn later would then never be redrawn when the tile is resized. */
        document.dispatchEvent(new CustomEvent('melis:flot-drawn'));
      }
      catch(e) { console.warn('[melis] deferred flot draw failed', e); }
    }
  }

  var patched = function(placeholder, data, options){
    var el = jq(placeholder)[0];
    if (el && !sized(el)) {
      queued.push({ el: el, data: data, options: options });
      try { ro.observe(el); } catch(e) {}
      return null;
    }
    return original.apply(this, arguments);
  };

  /* flot hangs its own API on \$.plot (\$.plot.plugins, \$.plot.formatDate…) — keep it all. */
  for (var k in original) {
    if (Object.prototype.hasOwnProperty.call(original, k)) patched[k] = original[k];
  }
  jq.plot = patched;

  [200, 600, 1500, 3000, 6000].forEach(function(ms){ window.setTimeout(flush, ms); });
})();
</script>
<!-- `container-level-a`: the legacy theme gates part of its dashboard rules on this ancestor —
     the classic BO zone carries it (render-dashboard-plugins.phtml). Without it, a plugin's tab
     bar (`.widget-tabs-responsive`, e.g. MelisCmsProspectsStatisticsPlugin) loses its
     `display:inline-block` (styles.css) and the tabs stack as full-width rows instead of sitting
     side by side. The class is only a HOOK (no rule targets it on its own).

     `tab-pane active`: the classic BO zone is a Bootstrap tab pane, and plugin scripts LOCATE THEIR
     OWN CHART through that ancestor — the filter buttons (Hourly/Daily/Weekly/Monthly of
     MelisCommerceDashboardPluginSalesRevenue, Daily/Monthly/Yearly of MelisCmsProspectsStatisticsPlugin)
     all do `\$(this).closest('.tab-pane[.active]').find('.flotchart-holder')` to get the placeholder id
     they must redraw. Without the class that lookup returns an EMPTY set → the id is `undefined`,
     `data-activefilter` is written nowhere and the redraw targets `\$('#undefined')`: clicking a
     filter did strictly nothing. The pair MUST be added together — `tab-pane` alone is
     `display:none` (bundle.css), `active` brings it back; this is exactly what the legacy BO and
     our own tool page (`buildToolPage`) carry. -->
<div id="{$zoneId}" class="container-level-a tab-pane active" data-melisKey="meliscore_dashboard">
{$html}
</div>
<!-- Conteneur des modales legacy. `melisHelper.createModal()` (melisHelper.js) fait
     `\$("#melis-modals-container").append(html)` PUIS `new bootstrap.Modal('#'+id)`. Absent de cette
     page iframe, l'append était un no-op → la modale n'entrait jamais dans le DOM → Bootstrap 5 ne
     trouvait pas l'élément et son BaseComponent sort sans poser `_config` → `_initializeBackDrop`
     plante sur « this._config is undefined ». Déclencheur : plugin Workflow — valider/refuser une
     demande ouvre ensuite une modale de commentaire (workflow.js). Le BO classique porte ce
     conteneur dans son layout ; on le recrée ici (comme le strip d'onglets factice plus haut). -->
<div id="melis-modals-container"></div>
{$bodyJs}
{$flotPatch}
{$orderMessagesPatch}
{$callbackBlocks}
<script>
/* ── Modale « Ajouter un commentaire » du plugin Workflow → rendue par l'HÔTE React ───────────
   Le legacy ouvre cette modale DANS l'iframe de la tuile (melisHelper.createModal → workflow.js),
   où elle est fatalement rognée par une petite tuile (position:fixed = viewport de l'iframe). On
   intercepte donc son ouverture — et UNIQUEMENT pour ce melisKey — pour demander au shell React de
   l'afficher en overlay plein écran, centré sur la page (même principe que la modale d'engrenage).
   Toutes les autres modales (autres melisKey, autres plugins) retombent sur le comportement original.
   Les paramètres (action valider/refuser + id page/news/blog) sont ceux que workflow.js passait à
   createModal : le dialog React POST ensuite vers le MÊME endpoint (addWorkflowComments). */
(function(){
  var h = window.melisHelper;
  if (!h || typeof h.createModal !== 'function') return;
  var orig = h.createModal;
  h.createModal = function(zoneId, melisKey, hasCloseBtn, parameters){
    if (melisKey === 'melissb_dashboard_workflow_comment_modal_content') {
      var target = window.__melisRealParent || window.parent;
      if (target && target !== window) {
        try { target.postMessage({ __melisWorkflowComment: true, params: parameters || {} }, '*'); } catch(e) {}
        return; /* ne pas ouvrir la modale legacy dans l'iframe */
      }
    }
    return orig.apply(this, arguments);
  };
})();
</script>
<script>
/* ── Pop-up de confirmation « Valider / Refuser » → rendue par l'HÔTE React (centrée) ─────────
   Même problème que la modale de commentaire : melisCoreTool.confirm ouvre un BootstrapDialog DANS
   l'iframe de la tuile, rogné sur une petite tuile. On l'affiche donc en overlay React plein écran.
   MAIS le callback « Oui » doit s'exécuter DANS l'iframe (il POST saveWorkflowActions puis enchaîne
   sur la modale de commentaire, avec les variables scopées du tool) — d'où un ALLER-RETOUR : on
   demande à l'hôte d'afficher la boîte, il renvoie le choix, et on rejoue ICI le bon callback.
   Générique (tout confirm de plugin dashboard passe par là) ; repli sur le legacy si l'hôte manque. */
(function(){
  var tool = window.melisCoreTool;
  if (!tool || typeof tool.confirm !== 'function') return;
  var orig = tool.confirm, pending = {}, seq = 0;
  tool.confirm = function(textOk, textNo, title, msg, onYes, onNo){
    var target = window.__melisRealParent || window.parent;
    if (target && target !== window) {
      var id = ++seq;
      pending[id] = { onYes: onYes, onNo: onNo };
      try {
        target.postMessage({ __melisConfirm: true, id: id, title: title, message: msg, textOk: textOk, textNo: textNo }, '*');
        return;
      } catch(e) { delete pending[id]; }
    }
    return orig.apply(this, arguments);
  };
  window.addEventListener('message', function(e){
    var d = e.data;
    if (!d || !d.__melisConfirmResult) return;
    if (e.source !== (window.__melisRealParent || window.parent)) return;
    var p = pending[d.id];
    if (!p) return;
    delete pending[d.id];
    try {
      if (d.result === 'yes') { if (typeof p.onYes === 'function') p.onYes(); }
      else if (d.result === 'no') { if (typeof p.onNo === 'function') p.onNo(); }
      /* 'dismiss' (croix / fond / Échap) = ne rejoue NI l'un NI l'autre, comme la croix du legacy. */
    } catch(err) { console.warn(err); }
  });
})();
</script>
<script>
/* ── `melisDashBoardDragnDrop.refreshWidget` : version iframe ─────────────────────────────
   C'est LE point d'entrée par lequel un plugin legacy se recharge lui-même sans recharger la
   page — pagination du plugin Annonces (`.announcement-pagination .page-link`, announcement.tools.js),
   et tout autre contrôle du même genre.

   L'original (gridstack.init.js) est écrit pour le dashboard legacy : il POSTe bien vers
   `getPlugin`, mais réinjecte ensuite le HTML via la GRILLE gridstack
   (`\$('#'+activeTabId+' .grid-stack').data('gridstack')` → `removeWidget`/`addWidget`). Ici il n'y a
   pas de grille — chaque plugin vit seul dans son iframe, la grille est côté React. `grid` est donc
   `undefined`, les appels sont court-circuités par l'optional chaining, et le clic ne produit
   RIEN : la requête part, la réponse est jetée. D'où « la pagination ne marche pas ».

   On garde donc l'échange réseau à l'identique (même URL, mêmes paramètres, `additionalParam`
   compris — c'est lui qui porte le `next` de la pagination) et on remplace uniquement la pose du
   résultat : le HTML retourné écrase le contenu de la zone, sur place.

   `dashboard_id` : l'original envoie `activeTabId`, qui dans cette page est l'id du wrapper, pas un
   dashboard. On envoie l'id de config du dashboard React — le même qu'au rendu initial — pour que
   le plugin retrouve SES réglages enregistrés au rechargement. */
(function(){
  var jq = window.jQuery;
  if (!jq || !window.melisDashBoardDragnDrop) return;

  window.melisDashBoardDragnDrop.refreshWidget = function(el, additionalParam){
    var host = document.getElementById({$zoneIdJs});
    if (!host) return;
    var zone = jq(host);
    var cfgTxt = zone.find('.dashboard-plugin-json-config').first().text();
    if (!cfgTxt) return;

    var cfg;
    try { cfg = JSON.parse(cfgTxt); } catch (e) { return; }

    var fields = [{ name: 'dashboard_id', value: {$dashboardIdJs} }];
    jq.each(cfg, function(name, value){
      fields.push({
        name: name,
        value: (value !== null && typeof value === 'object') ? JSON.stringify(value) : value
      });
    });
    jq.each(additionalParam || {}, function(name, value){ fields.push({ name: name, value: value }); });

    zone.css('opacity', 0.5);
    jq.post('/melis/MelisCore/DashboardPlugins/getPlugin', fields)
      .done(function(data){
        if (data && data.html) {
          zone.html(data.html);
          /* Le plugin rechargé réinitialise son JS via ses callbacks (graphiques, handlers non
             délégués). `eval` comme dans l'original — le contenu vient du serveur. */
          jq.each(data.jsCallbacks || [], function(i, cb){
            try { eval(cb); } catch (e) { console.warn(e); }
          });
        }
      })
      .always(function(){ zone.css('opacity', ''); });
  };

  /* ── `melisDashBoardDragnDrop.saveCurrentDashboard` : version iframe ────────────────────
     Second point d'entrée legacy : un plugin qui veut PERSISTER un réglage choisi dans sa vue
     écrit la nouvelle valeur dans son `.dashboard-plugin-json-config` puis appelle
     `saveCurrentDashboard(\$(this))` — c'est le cas des boutons Hourly/Daily/Weekly/Monthly de
     MelisCommerceDashboardPluginOrdersNumber (`.com-orders-dash-chart-line`).

     L'original suppose la grille :
         \$item  = el.closest('.grid-stack-item').data('_gridstack_node');
         \$items = \$item._grid.container[0].children;
     Ici il n'y a pas de gridstack (la grille est côté React) → `_gridstack_node` est `undefined`
     et la 3ᵉ ligne lève « can't access property "_grid" … is undefined ». L'exception remonte
     dans le handler `change` du plugin : le réglage n'est jamais enregistré, et au rechargement
     le widget revient au filtre précédent.

     On NE rejoue PAS `serializeWidgetMap` : il POSTe vers `saveDashboardPlugins`, qui RÉÉCRIT
     INTÉGRALEMENT le XML de la ligne à partir des seuls plugins reçus
     (MelisCoreDashboardDragDropZonePlugin::savePlugins). Dans le BO legacy c'est sans risque —
     la grille envoie TOUS ses widgets d'un coup. Ici chaque plugin vit seul dans son iframe et
     ne connaît que lui-même : sauver le filtre de Orders EFFACERAIT la config de tous les autres
     plugins de la ligne (vérifié : le `monthly` de SalesRevenue disparaissait).

     On passe donc par notre propre endpoint, `dashboardPluginConfigSaveAction`, écrit exactement
     pour ça : il remplace le SEUL noeud `<plugin>` concerné et re-sérialise les autres tels quels.
     C'est aussi celui qu'utilise la modale de config React — un seul chemin d'écriture.

     La géométrie n'est pas envoyée : cet iframe ignore la position du widget, et la géométrie du
     dashboard React est sauvée à part par /react-api/dashboard/layout. */
  window.melisDashBoardDragnDrop.saveCurrentDashboard = function(el){
    var host = document.getElementById({$zoneIdJs});
    if (!host) return;

    /* La config du plugin courant : celle que le plugin vient de mettre à jour. On part de `el`
       (comme l'original) et on retombe sur la zone si le nœud n'est pas dans un .grid-stack-item. */
    var \$cfgNode = el && el.closest ? el.closest('.grid-stack-item').find('.dashboard-plugin-json-config').first() : jq();
    if (!\$cfgNode.length) \$cfgNode = jq(host).find('.dashboard-plugin-json-config').first();

    var cfgTxt = \$cfgNode.text();
    if (!cfgTxt) return;

    var cfg;
    try { cfg = JSON.parse(cfgTxt); } catch (e) { return; }

    var pluginName = (cfg.conf && cfg.conf.name) || cfg.plugin_id;
    if (!pluginName) return;

    /* `savePluginConfigToXml(\$post)` lit ses champs À PLAT (\$config['activeFilter']). On envoie
       donc les scalaires de `datas` PUIS ceux de la racine — la racine gagne, car c'est là que le
       plugin écrit la valeur qu'il vient de choisir (`pluginConfig.activeFilter = …`). */
    var fields = {};
    function flatten(src){
      if (!src) return;
      jq.each(src, function(k, v){
        if (v === null || typeof v !== 'object') fields[k] = v;
      });
    }
    flatten(cfg.datas);
    flatten(cfg);
    /* Posé EN DERNIER : la config porte elle aussi une clé `plugin`, et c'est ce champ que
       l'action lit pour identifier le plugin — il ne doit pas être écrasé par l'aplatissement. */
    fields.plugin = pluginName;

    jq.post('/melis/react-dashboard-plugin-config-save', fields);
  };
})();
</script>
<script>
/* ── Synchronise les contrôles de la vue avec la config ENREGISTRÉE ───────────────────────
   Plusieurs vues de plugins legacy codent EN DUR l'option cochée par défaut, p.ex.
   commerce-dashboard-plugin-sales-revenue.phtml :

       <input … value="hourly" checked>                 ← toujours coché
       <label class="… <?= (\$activeFilter=='hourly') ? 'focus' : '' ?>">

   La config choisie n'ajoute qu'une classe `focus` discrète (et un `data-activefilter` que le JS
   lit pour tracer la BONNE série). Résultat : le graphique affiche « weekly » pendant que le
   bouton « Hourly » reste surligné — l'utilisateur lit une valeur fausse.

   On réaligne donc les contrôles sur la config réelle. Générique : on ne connaît pas les noms de
   champs des plugins, mais un contrôle qui PORTE la valeur enregistrée est le contrôle à activer.
   On ne déclenche PAS d'évènement `change` : la vue a déjà été rendue avec la bonne donnée, un
   change relancerait un tracé inutile. */
(function(){
  var cfg = {$savedConfigJs};
  var host = document.getElementById({$zoneIdJs});
  if (!host || !cfg) return;
  /* PHP sérialise un tableau vide en `[]` : on repart d'un objet pour pouvoir le compléter. */
  if (Array.isArray(cfg)) cfg = {};

  /* `\$savedConfigJs` est restreint aux champs déclarés dans `modal_form` (sinon un `width: 6`
     irait cocher un radio valant « 6 »). Or un plugin peut avoir une option SANS entrée de
     modale : MelisCommerceDashboardPluginOrdersNumber n'expose `activeFilter` que dans ses
     boutons Hourly/Daily/Weekly/Monthly — sa config n'a pas de `modal_form`, donc `cfg` est vide,
     rien n'est réaligné, et le `checked` codé en dur sur « hourly » l'emporte à chaque
     rechargement (le graphique, lui, est bien tracé sur la valeur enregistrée : le JS du plugin
     la lit dans `.dashboard-plugin-json-config`).

     On complète donc `cfg` avec les `datas` de ce même noeud DOM — la config réellement en base.
     Filtrées par une LISTE BLANCHE inverse : on écarte les clés de structure du conteneur
     (géométrie, libellés, callback…), communes à TOUS les plugins ; ne restent que les options
     propres au plugin. C'est ce qui rend la correction générique sans rouvrir la porte au
     `width: 6`. */
  var STRUCT_KEYS = ['plugin_id','plugin','module','name','description','icon','thumbnail',
                     'jscallback','jsdatas','max_lines','height','width','x-axis','y-axis',
                     'section','dashboard_id','conf','forward'];
  try {
    var node = host.querySelector('.dashboard-plugin-json-config');
    var datas = node ? (JSON.parse(node.textContent) || {}).datas : null;
    Object.keys(datas || {}).forEach(function(k){
      var v = datas[k];
      if (v === null || typeof v === 'object') return;
      if (STRUCT_KEYS.indexOf(k) !== -1) return;
      /* La config déclarée dans `modal_form` reste prioritaire. */
      if (!Object.prototype.hasOwnProperty.call(cfg, k)) cfg[k] = v;
    });
  } catch (e) {}

  function sync(){
    Object.keys(cfg).forEach(function(key){
      var value = String(cfg[key]);
      if (value === '') return;
      /* Radios/cases portant cette valeur → cocher, et décocher le reste du groupe. */
      var inputs = host.querySelectorAll('input[type=radio][value="' + (window.CSS && CSS.escape ? CSS.escape(value) : value) + '"]');
      Array.prototype.forEach.call(inputs, function(input){
        if (input.name) {
          Array.prototype.forEach.call(host.querySelectorAll('input[type=radio][name="' + input.name + '"]'), function(sib){
            sib.checked = false;
            /* Le thème marque le bouton actif via la classe du <label> associé. */
            var lbl = sib.id ? host.querySelector('label[for="' + sib.id + '"]') : null;
            if (lbl) lbl.classList.remove('active', 'focus');
          });
        }
        input.checked = true;
        var lbl = input.id ? host.querySelector('label[for="' + input.id + '"]') : null;
        if (lbl) lbl.classList.add('active', 'focus');
      });
      /* <select> proposant cette valeur. */
      Array.prototype.forEach.call(host.querySelectorAll('select'), function(sel){
        if (sel.value !== value && Array.prototype.some.call(sel.options, function(o){ return o.value === value; })) {
          sel.value = value;
        }
      });
    });
  }
  /* ── Le surlignage doit SUIVRE les clics suivants ────────────────────────────────────────
     `sync()` pose `active`/`focus` sur le label de l'option enregistrée. Ces classes sont posées
     À LA MAIN : quand l'utilisateur clique ensuite un AUTRE bouton, le navigateur ne fait que
     déplacer le `checked` du radio — la classe, elle, reste sur l'ancien label. D'où « Weekly
     reste surligné alors que je clique Daily ».

     On repeint donc le groupe à chaque `change` depuis l'état réel des radios, ce qui rend les
     classes cohérentes quelle que soit leur origine (nôtres ou celles de la vue). */
  function repaintGroup(name){
    Array.prototype.forEach.call(
      host.querySelectorAll('input[type=radio][name="' + name + '"]'),
      function(input){
        var lbl = input.id ? host.querySelector('label[for="' + input.id + '"]') : null;
        if (!lbl) return;
        lbl.classList.toggle('active', input.checked);
        lbl.classList.toggle('focus', input.checked);
      }
    );
  }

  var userPicked = false;
  host.addEventListener('change', function(e){
    var t = e.target;
    if (!t || t.type !== 'radio' || !t.name) return;
    /* À partir du premier choix de l'utilisateur, sa sélection prime sur la config enregistrée :
       les re-syncs différés ci-dessous ne doivent plus la ramener en arrière. */
    userPicked = true;
    repaintGroup(t.name);
  });

  sync();
  /* Certaines vues (re)dessinent leurs boutons dans un jsCallback : on repasse après coup. */
  [150, 600, 1500].forEach(function(ms){
    window.setTimeout(function(){ if (!userPicked) sync(); }, ms);
  });
})();
</script>
<script>
/* ── Give every plugin table its own horizontal scroller ──────────────────────────────────
   A table cannot shrink below its content's intrinsic width. In the wide classic BO that never
   shows; in a narrow tile the table pushes the whole plugin sideways. Some legacy views already
   wrap their table in the theme's `.overflow-x` (prospects, commerce orders) — most do not
   (bubble-updates, bubble-notifications…). We add the missing wrapper so the table scrolls
   inside its own box instead of dragging the plugin with it.

   Only tables with no scrollable ancestor INSIDE the plugin are wrapped, so a view that already
   handles it keeps its own markup untouched. Tables can arrive later (AJAX, `refreshWidget`),
   hence the rescans — same cadence as the chart observer below. */
(function(){
  var host = document.getElementById({$zoneIdJs});
  if (!host) return;

  function hasScroller(el){
    for (var p = el.parentNode; p && p !== host; p = p.parentNode) {
      if (p.nodeType !== 1) continue;
      if (p.classList.contains('overflow-x') || p.classList.contains('melis-scroll-x')) return true;
      var ox = window.getComputedStyle(p).overflowX;
      if (ox === 'auto' || ox === 'scroll') return true;
    }
    return false;
  }

  function wrap(){
    var tables = host.getElementsByTagName('table');
    /* Live HTMLCollection + we reparent as we go: snapshot first. */
    var list = Array.prototype.slice.call(tables);
    for (var i = 0; i < list.length; i++) {
      var t = list[i];
      if (!t.parentNode || hasScroller(t)) continue;
      var box = document.createElement('div');
      box.className = 'melis-scroll-x';
      t.parentNode.insertBefore(box, t);
      box.appendChild(t);
    }
  }

  wrap();
  [200, 600, 1500, 3000].forEach(function(ms){ window.setTimeout(wrap, ms); });
})();
</script>
<script>
/* ── Tuile MOBILE : table LARGE → colonne essentielle + « + » qui déplie le reste ──────────
   Pendant, côté plugin legacy, du motif des listes du BO React (ExpandToggle / HiddenColsRow) :
   sur un téléphone, on ne garde que la colonne qui IDENTIFIE la ligne, précédée d'un bouton
   « + » ; les autres colonnes sont repliées dans un bloc « LIBELLÉ : valeur » sous la ligne.
   La table reste une table (en-tête, tri, handlers de ligne intacts) — c'est la seule chose qui
   change par rapport au défilement horizontal : plus rien n'est hors champ, tout est à un clic.

   ⚠️ Appliqué UNIQUEMENT aux tables qui débordent RÉELLEMENT — `scrollWidth` de la table contre
   la largeur de son conteneur. Une table de 3 colonnes courtes qui tient déjà (ex. Prospects)
   n'a rien à gagner à être repliée, on n'y touche pas. C'est ce test qui rend la règle sûre pour
   TOUS les plugins sans liste blanche par plugin.

   ⚠️ On ne DÉPLACE ni ne réécrit aucune cellule d'origine : les colonnes repliées sont seulement
   masquées (classe), et le bloc déplié affiche des COPIES (cloneNode) de leur contenu. Tout code
   de plugin qui lit `td.textContent` ou cible une cellule continue donc de fonctionner.

   ⚠️⚠️ LE PIÈGE (corrigé ici) : la mesure de débordement exige une table DÉPLIÉE, donc une
   première version démontait/remontait la table à chaque passage de `scan()`. Or déplier une
   ligne CHANGE LA HAUTEUR du document → le ResizeObserver tirait `scan()` → démontage/remontage
   → la ligne se refermait et la table clignotait à chaque clic sur « + ». Pire, la barre de
   défilement verticale de la tuile apparaît/disparaît avec la hauteur, ce qui fait varier la
   LARGEUR de quelques pixels → re-mesure → oscillation sans fin. Trois garde-fous :
     1. le ResizeObserver ne déclenche `scan()` que si la LARGEUR a bougé (la hauteur ne change
        rien au débordement) ;
     2. une table déjà repliée n'est PAS démontée tant que la largeur disponible n'a pas bougé de
        plus de HYSTERESIS px — ce seuil absorbe précisément le va-et-vient d'une barre de
        défilement ; `collapse()` est alors incrémental (il ne traite que les lignes nouvelles,
        arrivées en AJAX) ;
     3. si une re-mesure est vraiment nécessaire, les lignes OUVERTES sont mémorisées puis
        rouvertes après remontage — l'utilisateur ne perd pas ce qu'il consultait. */
(function(){
  var host = document.getElementById({$zoneIdJs});
  if (!host) return;

  var EXP = 'melis-exp', HID = 'melis-exp-hidden', WRAP = 'melis-exp-wrap';
  /* Marge de tolérance sur la largeur, en px. Doit couvrir la largeur d'une barre de défilement
     (~15px) : c'est elle qui apparaît/disparaît quand on déplie une ligne. */
  var HYSTERESIS = 24;

  /* Par table : index de la colonne gardée, libellés d'en-tête, cellules d'en-tête (pour les
     libellés-icônes) et largeur à laquelle le repli a été décidé. */
  var state = new WeakMap();

  function isNarrow(){ return document.documentElement.getAttribute('data-melis-narrow') === '1'; }

  function scrollWrapOf(table){
    for (var p = table.parentNode; p && p !== host; p = p.parentNode) {
      if (p.nodeType !== 1) continue;
      if (p.classList.contains('melis-scroll-x') || p.classList.contains('overflow-x')) return p;
    }
    return null;
  }

  function headerRow(table){
    var h = table.tHead;
    return (h && h.rows.length) ? h.rows[0] : null;
  }

  function availWidth(table){
    var box = scrollWrapOf(table) || table.parentNode;
    return (box && box.clientWidth) ? box.clientWidth : 0;
  }

  /* Colonne à GARDER visible : celle qui identifie la ligne. On cherche un libellé d'en-tête
     « parlant », par ordre de préférence — une référence de commande identifie mieux qu'un nom,
     un nom mieux qu'un statut. Sans aucune correspondance on prend la 2ᵉ colonne : la 1ʳᵉ est
     presque toujours un id numérique, qui n'aide pas à reconnaître la ligne. */
  var PRIORITY = ['reference', 'référence', 'ref', 'title', 'titre', 'subject', 'objet',
                  'name', 'nom', 'label', 'libell', 'email', 'login'];
  function essentialIndex(labels){
    for (var p = 0; p < PRIORITY.length; p++) {
      for (var i = 0; i < labels.length; i++) {
        if (labels[i] && labels[i].toLowerCase().indexOf(PRIORITY[p]) !== -1) return i;
      }
    }
    return labels.length > 1 ? 1 : 0;
  }

  function detailOf(row){
    var d = row.nextSibling;
    while (d && d.nodeType !== 1) d = d.nextSibling;
    return (d && d.classList.contains('melis-exp-row')) ? d : null;
  }

  /* Lignes de données (hors blocs dépliés), dans l'ordre — l'index sert de repère stable pour
     mémoriser/restaurer ce qui était ouvert. */
  function dataRows(table){
    var out = [], bodies = table.tBodies;
    for (var b = 0; b < bodies.length; b++) {
      var rows = bodies[b].rows;
      for (var r = 0; r < rows.length; r++) {
        if (!rows[r].classList.contains('melis-exp-row')) out.push(rows[r]);
      }
    }
    return out;
  }

  function openIndices(table){
    var open = [], rows = dataRows(table);
    for (var i = 0; i < rows.length; i++) {
      var d = detailOf(rows[i]);
      if (d && d.style.display !== 'none') open.push(i);
    }
    return open;
  }

  function setOpen(row, open){
    var d = detailOf(row);
    if (!d) return;
    d.style.display = open ? '' : 'none';
    var btn = row.querySelector('.melis-exp-btn');
    if (btn) {
      btn.textContent = open ? '−' : '+';   /* − = U+2212, pas un trait d'union */
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }

  function restoreOpen(table, indices){
    if (!indices || !indices.length) return;
    var rows = dataRows(table);
    for (var i = 0; i < indices.length; i++) {
      if (rows[indices[i]]) setOpen(rows[indices[i]], true);
    }
  }

  /* Idempotent ET incrémental : au 1ᵉʳ appel il prépare l'en-tête, aux suivants il ne traite que
     les lignes pas encore équipées (contenu réinjecté en AJAX). Ne démonte jamais rien. */
  function collapse(table, avail){
    var st = state.get(table);

    if (!table.classList.contains(EXP)) {
      var hrow = headerRow(table);
      if (!hrow) return;                     /* sans en-tête, pas de libellé à afficher : on laisse */
      /* Snapshot AVANT insertion : `cells` est une collection VIVANTE, les index glissent dès
         qu'on insère la colonne du bouton. */
      var hcells = Array.prototype.slice.call(hrow.cells);
      var labels = hcells.map(function(c){ return (c.textContent || '').replace(/\s+/g, ' ').trim(); });
      st = { ess: essentialIndex(labels), labels: labels, hcells: hcells, width: avail };
      var th = document.createElement('th');
      th.className = 'melis-exp-th';
      hrow.insertBefore(th, hrow.cells[0] || null);
      hcells.forEach(function(c, i){ if (i !== st.ess) c.classList.add(HID); });
      state.set(table, st);
      table.classList.add(EXP);
      var w = scrollWrapOf(table);
      if (w) w.classList.add(WRAP);
    } else {
      if (!st) return;
      st.width = avail;
    }

    var rows = dataRows(table);
    for (var r = 0; r < rows.length; r++) {
      var row = rows[r];
      if (row.querySelector('.melis-exp-td')) continue;      /* déjà équipée */
      var cells = Array.prototype.slice.call(row.cells);
      if (cells.length < 2) continue;                        /* ligne « aucune donnée » : rien à replier */

      var td = document.createElement('td');
      td.className = 'melis-exp-td';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'melis-exp-btn';
      btn.setAttribute('aria-expanded', 'false');
      btn.textContent = '+';
      td.appendChild(btn);
      row.insertBefore(td, row.cells[0] || null);
      cells.forEach(function(c, i){ if (i !== st.ess) c.classList.add(HID); });

      /* Bloc déplié, masqué au départ. Une VRAIE ligne de table (et non un div hors flux) pour
         que la largeur suive la table et que le HTML reste valide. */
      var det = document.createElement('tr');
      det.className = 'melis-exp-row';
      det.style.display = 'none';
      var dtd = document.createElement('td');
      dtd.colSpan = 2;                       /* les 2 seules colonnes visibles : bouton + essentielle */
      cells.forEach(function(c, i){
        if (i === st.ess) return;
        var pair = document.createElement('div');
        pair.className = 'melis-exp-pair';
        /* Libellé : le texte de l'en-tête, ou — quand l'en-tête n'est QU'UNE ICÔNE (colonne
           quantité/expédition des commandes, par ex.) — une copie de cette icône. Sans ce
           repli, la valeur s'affichait toute seule, sans dire de quoi il s'agit. */
        var lbl  = st.labels[i];
        var icon = lbl ? null : (st.hcells[i] ? st.hcells[i].querySelector('i, img, svg') : null);
        if (lbl || icon) {
          var k = document.createElement('span');
          k.className = 'melis-exp-k';
          if (lbl) k.textContent = lbl;
          else k.appendChild(icon.cloneNode(true));
          pair.appendChild(k);
        }
        var v = document.createElement('span');
        v.className = 'melis-exp-v';
        /* COPIE du contenu : la cellule d'origine reste intacte (cf. avertissement en tête). */
        for (var n = 0; n < c.childNodes.length; n++) v.appendChild(c.childNodes[n].cloneNode(true));
        pair.appendChild(v);
        dtd.appendChild(pair);
      });
      det.appendChild(dtd);
      row.parentNode.insertBefore(det, row.nextSibling);
    }
  }

  function expand(table){
    if (!table.classList.contains(EXP)) return;
    var i, list;
    list = Array.prototype.slice.call(table.querySelectorAll('tr.melis-exp-row'));
    for (i = 0; i < list.length; i++) list[i].parentNode.removeChild(list[i]);
    list = Array.prototype.slice.call(table.querySelectorAll('th.melis-exp-th, td.melis-exp-td'));
    for (i = 0; i < list.length; i++) list[i].parentNode.removeChild(list[i]);
    list = Array.prototype.slice.call(table.querySelectorAll('.' + HID));
    for (i = 0; i < list.length; i++) list[i].classList.remove(HID);
    table.classList.remove(EXP);
    state.delete(table);
    var w = scrollWrapOf(table);
    if (w) w.classList.remove(WRAP);
  }

  function scan(){
    var tables = Array.prototype.slice.call(host.getElementsByTagName('table'));
    for (var i = 0; i < tables.length; i++) {
      var t = tables[i];
      if (!isNarrow()) { expand(t); continue; }

      var avail = availWidth(t);
      if (!avail) continue;                                  /* pas encore mis en page */
      var st = state.get(t);

      if (t.classList.contains(EXP) && st) {
        /* Largeur stable → on NE DÉMONTE PAS (sinon : clignotement + perte des lignes ouvertes).
           On repasse quand même en incrémental pour équiper d'éventuelles lignes AJAX. */
        if (Math.abs(avail - st.width) <= HYSTERESIS) { collapse(t, avail); continue; }
        /* Vrai changement de largeur → re-mesure, table dépliée, état d'ouverture préservé. */
        var open = openIndices(t);
        expand(t);
        if (t.scrollWidth > avail + 1) { collapse(t, avail); restoreOpen(t, open); }
        continue;
      }

      if (t.scrollWidth > avail + 1) collapse(t, avail);
    }
  }

  /* Un seul écouteur délégué. `stopPropagation` est OBLIGATOIRE : plusieurs plugins posent un
     handler de clic sur la LIGNE (ex. les commandes ouvrent la commande) — sans ça, déplier une
     ligne l'ouvrirait aussi. */
  host.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('.melis-exp-btn') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var row = btn.closest('tr');
    var det = row ? detailOf(row) : null;
    if (!det) return;
    setOpen(row, det.style.display === 'none');
  });

  scan();
  /* Relances : le contenu arrive souvent en AJAX (getMessages, refreshWidget, changement de
     filtre). Désormais sans coût visible — une table déjà repliée n'est plus démontée. */
  [250, 700, 1600, 3200].forEach(function(ms){ window.setTimeout(scan, ms); });
  document.addEventListener('change', function(){ window.setTimeout(scan, 350); }, true);
  /* Bascule étroit ↔ large poussée par l'hôte (postMessage `__melisNarrow`). */
  try {
    new MutationObserver(function(){ scan(); })
      .observe(document.documentElement, { attributes: true, attributeFilter: ['data-melis-narrow'] });
  } catch (e) {}
  /* Redimensionnement de la tuile : SEULE la largeur peut changer le verdict de débordement.
     Ignorer la hauteur est ce qui empêche « déplier une ligne » de relancer une re-mesure. */
  if (window.ResizeObserver && document.body) {
    var lastW = document.body.clientWidth, pending = 0;
    new ResizeObserver(function(){
      var w = document.body.clientWidth;
      if (w === lastW) return;
      lastW = w;
      if (pending) return;
      pending = requestAnimationFrame(function(){ pending = 0; scan(); });
    }).observe(document.body);
  }
})();
</script>
<script>
/* ── Redraw flot charts when the tile is resized ──────────────────────────────────────────
   A flot chart is a CANVAS painted ONCE, at the holder's dimensions at draw time. Widening the
   tile widens the holder (`.flotchart-holder { width: 100% }`) but NOT the already-painted
   canvas: the chart keeps its original width with empty space to its right (observed on
   MelisCmsProspectsStatisticsPlugin).

   `jquery.flot.resize` IS loaded, but it relies on Ben Alman's "jQuery resize event" plugin,
   which POLLS elements through requestAnimationFrame/setTimeout: inside this iframe that loop
   does not reliably catch the tile being resized. So we observe the holder ourselves
   (ResizeObserver — exact and immediate) and ask flot to repaint, running the very same
   sequence as the official plugin (`resize` + `setupGrid` + `draw`).

   Generic, with no per-plugin knowledge: a flot holder is the PARENT of a `canvas.flot-base`,
   and flot stores its instance there under `data('plot')`. Charts are drawn well after `load`
   (AJAX) — and a hidden tab only draws when opened — so we rescan periodically to pick up
   newcomers. */
(function(){
  var jq = window.jQuery;
  if (!jq || !window.ResizeObserver) return;

  var seen = new WeakSet();
  var sizes = new WeakMap();
  var pending = 0;
  var dirty = [];

  function redraw(el){
    /* Hidden (inactive tab) or not laid out yet: flot cannot draw without dimensions, and the
       drawing would have to be redone on display anyway. */
    if (!el.clientWidth || !el.clientHeight) return;
    var plot = jq(el).data('plot');
    if (!plot) return;
    try { plot.resize(); plot.setupGrid(); plot.draw(); } catch (e) {}
  }

  var ro = new ResizeObserver(function(entries){
    for (var i = 0; i < entries.length; i++) {
      var el = entries[i].target;
      var w = el.clientWidth, h = el.clientHeight;
      var prev = sizes.get(el);
      /* The redraw only touches the inner canvas, never the holder: no feedback loop. The
         comparison still skips redraws at unchanged size (0 → 0 when a tab opens, say). */
      if (prev && prev.w === w && prev.h === h) continue;
      sizes.set(el, { w: w, h: h });
      if (dirty.indexOf(el) === -1) dirty.push(el);
    }
    if (pending || !dirty.length) return;
    /* Coalesced into one frame: a resize drag fires dozens of events. */
    pending = requestAnimationFrame(function(){
      pending = 0;
      var todo = dirty; dirty = [];
      todo.forEach(redraw);
    });
  });

  function scan(){
    var canvases = document.getElementsByTagName('canvas');
    for (var i = 0; i < canvases.length; i++) {
      var holder = canvases[i].parentNode;
      if (!holder || holder.nodeType !== 1 || seen.has(holder)) continue;
      if (!jq(holder).data('plot')) continue;
      seen.add(holder);
      sizes.set(holder, { w: holder.clientWidth, h: holder.clientHeight });
      ro.observe(holder);
    }
  }

  scan();
  [200, 600, 1500, 3000].forEach(function(ms){ window.setTimeout(scan, ms); });
  /* A plugin may draw a chart much later (Daily/Monthly/Yearly filter change, tab opening,
     `refreshWidget`): rescan after every interaction in the page. */
  document.addEventListener('click', function(){ window.setTimeout(scan, 300); }, true);
  document.addEventListener('change', function(){ window.setTimeout(scan, 300); }, true);
  /* A chart whose draw was DEFERRED (holder without dimensions, see the \$.plot guard above) can
     appear long after those rescans — it announces itself. */
  document.addEventListener('melis:flot-drawn', function(){ window.setTimeout(scan, 50); });
})();
</script>
<script>
/* ── Hauteur réelle du contenu, remontée à la tuile React ─────────────────────────────────
   Mesurer le DOCUMENT ne marche pas : le thème legacy pose `body { height: 100% }`, donc
   `scrollHeight` vaut toujours exactement la hauteur de l'iframe — la mesure suit la tuile au
   lieu de la déterminer (circulaire). Le CONTENEUR du plugin n'est pas fiable non plus : son
   contenu legacy est souvent hors flux, il retombe alors à 0 (cas du calendrier).

   On balaie donc les ÉLÉMENTS et on retient le bord inférieur le plus bas. Un rectangle reste
   exact même quand l'ancêtre s'effondre ou rogne (`getBoundingClientRect` décrit la géométrie,
   indépendamment de tout `overflow:hidden`). Vérifié sur le plugin Prospects : 802px mesurés
   aussi bien dans une iframe de 319px que de 2400px — la valeur est STABLE, donc exploitable.

   ⚠️ `window.parent` est shimmé vers `window` en tête de page (garde anti frame-buster) :
   poster dessus reviendrait à se parler à soi-même. Le vrai parent est dans `__melisRealParent`. */
(function(){
  function measure(){
    var body = document.body;
    if (!body) return 0;
    var top = body.getBoundingClientRect().top, max = 0;
    var els = body.getElementsByTagName('*');
    for (var i = 0; i < els.length; i++) {
      var el = els[i], r = el.getBoundingClientRect();
      if (!r.height) continue;
      var s = window.getComputedStyle(el);
      /* `fixed` = surcouches (enjoyhint…), jamais du contenu de plugin. */
      if (s.position === 'fixed' || s.display === 'none' || s.visibility === 'hidden') continue;
      var bottom = r.bottom - top + (parseFloat(s.marginBottom) || 0);
      if (bottom > max) max = bottom;
    }
    return Math.ceil(max);
  }
  var last = 0;
  function report(){
    var px = measure();
    if (px <= 0 || px === last) return;
    last = px;
    try {
      var target = window.__melisRealParent;
      if (target && target !== window) target.postMessage({ __melisPluginHeight: true, px: px }, '*');
    } catch (e) {}
  }
  report();
  if (window.ResizeObserver && document.body) {
    var pending = 0;
    var ro = new ResizeObserver(function(){
      /* Coalescé en une frame : un redessin de graphique émet des dizaines de mutations. */
      if (pending) return;
      pending = requestAnimationFrame(function(){ pending = 0; report(); });
    });
    ro.observe(document.body);
    var host = document.getElementById({$zoneIdJs});
    if (host) ro.observe(host);
  }
  /* Filet : les graphiques flot sont dessinés bien après `load`, et certains redimensionnements
     internes ne font bouger aucun élément observé. */
  [200, 600, 1500, 3000].forEach(function(ms){ window.setTimeout(report, ms); });
})();
</script>
<script>
/* ── Plugin Workflow (MelisSmallBusiness) : ouvrir la demande dans un ONGLET du BO React ───────
   L'icône œil (.wd-see) porte un onclick legacy `melisHelper.tabOpen(...)` qui ne sait ouvrir un
   onglet QUE dans le document de son propre iframe — inopérant ici. On l'intercepte (capture, pour
   passer AVANT le onclick inline) et on demande à l'hôte React d'ouvrir l'outil comme un vrai onglet,
   via le pont `__melisOpenTool` déjà écouté par App.tsx. La cible se déduit du `data-wf-opening-js`
   de la ligne : `tabOpen('titre','icone','tabId','<toolKey>',{ idPage: N })` (l'onglet NEWS porte
   `'meliscmsnews_page',{ newsId: N }`). Map toolKey→route BO ; l'id est lu quel que soit son nom. */
(function(){
  if (!document.querySelector('.melissb-dashboard-workflow')) return;
  var ROUTE_BY_TOOLKEY = {
    'meliscms_page':     '/melis-cms/page',
    'meliscmsnews_page': '/melis-cms/news'
  };
  document.addEventListener('click', function(e){
    var see = e.target && e.target.closest ? e.target.closest('.wd-see') : null;
    if (!see) return;
    var cont = see.closest('.wd-cont');
    var openjs = (cont && cont.getAttribute('data-wf-opening-js')) || '';
    var mk = openjs.match(/tabOpen\s*\([^,]*,[^,]*,[^,]*,\s*'([^']+)'/);
    var toolKey = mk ? mk[1] : '';
    var base = ROUTE_BY_TOOLKEY[toolKey];
    if (!base) return; /* type non mappé → on laisse le handler legacy (inoffensif) */
    e.preventDefault();
    e.stopPropagation();
    /* Le paramètre porte un nom PROPRE À L'OUTIL (`idPage` pour les pages CMS, `newsId` pour les
       actualités…) → on prend la 1re clé numérique de l'objet passé à tabOpen, sans la nommer. */
    var idm = openjs.match(/\{[^}]*?[A-Za-z_]\w*\s*:\s*(\d+)/);
    var path = idm ? base + '/' + idm[1] : base;
    /* 1er argument de tabOpen = le NOM affiché (nom de la page) → on le passe pour que l'onglet
       s'ouvre directement avec le bon libellé (pas de « Page N » qui clignote avant renommage). */
    var lm = openjs.match(/tabOpen\s*\(\s*'([^']*)'/);
    var label = lm ? lm[1] : null;
    var host = window.__melisRealParent || window.parent;
    try { if (host && host !== window) host.postMessage({ __melisOpenTool: true, path: path, label: label }, '*'); } catch (err) {}
  }, true);
})();
</script>
<script>
/* ── Plugin « Recent page activity » (MelisCmsPageHistoric) : ouvrir la page dans un ONGLET React ─
   Chaque ligne accessible porte `.melis-openrecenthistoric` ; un handler jQuery délégué
   (melispagehistoric.js) y appelle `melisHelper.tabOpen(...)`, qui ne sait ouvrir un onglet QUE dans
   le document de son propre iframe — inopérant ici. On intercepte le clic (capture + stopPropagation,
   AVANT le handler délégué de bulle) et on demande à l'hôte React d'ouvrir l'éditeur de page comme un
   vrai onglet, via le pont `__melisOpenTool` (App.tsx) — même mécanisme que l'œil du Workflow. L'id et
   le nom de la page sont sur la ligne (`data-page-id` / `data-page-title`) ; l'historique ne concerne
   QUE des pages → route fixe `/melis-cms/page/:id`. */
(function(){
  if (!document.querySelector('.melis-openrecenthistoric')) return;
  document.addEventListener('click', function(e){
    var row = e.target && e.target.closest ? e.target.closest('.melis-openrecenthistoric') : null;
    if (!row) return;
    var pageId = row.getAttribute('data-page-id');
    if (!pageId) return;
    e.preventDefault();
    e.stopPropagation();
    /* 1er argument de tabOpen = le NOM de la page → on le passe pour que l'onglet s'ouvre directement
       avec le bon libellé (pas de « Page N » qui clignote avant renommage). */
    var label = row.getAttribute('data-page-title') || null;
    var path = '/melis-cms/page/' + pageId;
    var host = window.__melisRealParent || window.parent;
    try { if (host && host !== window) host.postMessage({ __melisOpenTool: true, path: path, label: label }, '*'); } catch (err) {}
  }, true);
})();
</script>
</body>
</html>
HTML;

        $response = $this->getResponse();
        $response->setContent($page);
        $response->getHeaders()
            ->addHeaderLine('Content-Type',  'text/html; charset=utf-8')
            // Document d'iframe à URL FIXE : sans ça le navigateur en ressert volontiers une copie
            // en cache, et toute correction de mise en page semble « ne rien changer » tant qu'on
            // ne vide pas le cache. Le contenu dépend en plus de l'utilisateur connecté.
            ->addHeaderLine('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->addHeaderLine('Pragma', 'no-cache')
            ->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Inline JS that fills melisTinyMCE.tinyMceConfigs synchronously.
     *
     * Same source of truth as the classic back-office: MelisTinyMceController::preloadTinyMceConfig,
     * whose `meliscore_tinymce_config` event is what lets modules inject their own TinyMCE settings
     * (external_plugins, toolbars…). We call the action directly through the ControllerManager —
     * NOT `new MelisTinyMceController()` — because the manager's initializer injects the shared
     * EventManager; a hand-built controller would trigger the event on a private one and silently
     * lose every module override, which is exactly the bug this method exists to fix.
     *
     * Falls back to the original async fetch if anything goes wrong (missing controller, throwing
     * listener…), so a failure degrades to the previous behaviour instead of breaking the tool page.
     */
    private function tinyMceConfigsScript(): string
    {
        $fallback = '  try { if (window.melisTinyMCE && melisTinyMCE.getTinyMceConfig) melisTinyMCE.getTinyMceConfig(); } catch(e) {}';

        try {
            $controller = $this->getEvent()->getApplication()->getServiceManager()
                ->get('ControllerManager')->get('MelisCore\Controller\MelisTinyMce');
            $controller->setEvent($this->getEvent());

            $result  = $controller->preloadTinyMceConfigAction();
            $configs = $result instanceof JsonModel ? $result->getVariables() : null;
            if ($configs instanceof \Traversable) {
                $configs = iterator_to_array($configs);
            }
            if (!is_array($configs) || $configs === []) {
                return $fallback;
            }

            // JSON_HEX_* keeps the payload safe inside <script> (no </script> break-out).
            $json = json_encode(
                $configs,
                JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            if ($json === false) {
                return $fallback;
            }

            return "  try {\n"
                 . "    var melisReactTinyMceConfigs = {$json};\n"
                 . "    if (window.melisTinyMCE && melisTinyMCE.tinyMceConfigs) {\n"
                 . "      for (var k in melisReactTinyMceConfigs) melisTinyMCE.tinyMceConfigs[k] = melisReactTinyMceConfigs[k];\n"
                 . "    }\n"
                 . "  } catch(e) {\n"
                 . $fallback . "\n"
                 . "  }";
        } catch (\Throwable) {
            return $fallback;
        }
    }

    // ─── Rendu AJAX (widgets du dashboard React SANS iframe) ─────────────────

    /**
     * Curated JS the AJAX widgets need — deliberately NOT `bundle.js`.
     *
     * bundle.js is the whole back-office application: melisCore.js (session polling, flash
     * messenger, tab framework, `activeTabId` recomputed from a tab bar that doesn't exist here),
     * gridstack.init.js, the bubble plugins, TinyMCE… Loading it into the React shell would run all
     * of that against React's DOM. The widgets only actually need jQuery, flot and moment, so we
     * load those files directly from their sources (the same ones webpack.mix.js concatenates).
     */
    private const AJAX_WIDGET_JS = [
        '/MelisCore/assets/components/library/jquery/jquery.min.js',
        '/MelisCore/assets/components/library/moment/moment.js',
        '/MelisCore/js/moment/fr.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/excanvas.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/jquery.flot.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/jquery.flot.resize.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/jquery.flot.time.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/plugins/jquery.flot.tooltip.min.js',
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/lib/jquery.flot.stack.js',
        // Définit le global `charts`, sur lequel les plugins branchent leurs graphiques.
        '/MelisCore/assets/components/modules/admin/charts/flot/assets/custom/js/flotcharts.common.js',
    ];

    /**
     * Scripts de module à NE JAMAIS charger dans le DOCUMENT DU SHELL React.
     *
     * Les widgets AJAX sont injectés dans le document du shell (pas dans une iframe) : les scripts
     * de leur module s'exécutent donc sur le DOM de React. La plupart se contentent de brancher des
     * handlers délégués (inertes s'il n'y a pas de bouton) — mais la « media library » de
     * MelisSmallBusiness, elle, MODIFIE la page au DOM-ready : media_upload_field.js fait
     * `$body.append(<div class="modal">…<iframe src="moxiemanager/index.php">)`. Dans une page
     * d'outil legacy (buildToolPage) c'est invisible — le CSS Bootstrap met `.modal` en
     * `display:none`. Dans le shell React, le CSS legacy est scopé à `.melis-legacy-widget` : la
     * modale s'affiche donc EN CLAIR sous la page, son iframe charge MoxieManager (requêtes
     * api.php, 500 sur icomoon.woff), et son `onload="loadModalIframe()"` lit un `$body` qui n'est
     * défini que dans la closure du fichier → « ReferenceError: $body is not defined ».
     *
     * Aucun widget dashboard n'utilise la media library → on retire cette famille de scripts de la
     * liste module-wide envoyée au shell. Les pages d'outils (iframe) ne sont pas concernées.
     */
    private const SHELL_UNSAFE_JS = [
        '#/media_upload_field\.js$#',
        '#/medialib\.js$#',
        '#/moxiemanager/#',
    ];

    /** Retire les scripts dangereux pour le document du shell (cf. SHELL_UNSAFE_JS). */
    private static function stripShellUnsafeJs(array $js): array
    {
        return array_values(array_filter($js, static function (string $url): bool {
            foreach (self::SHELL_UNSAFE_JS as $pattern) {
                if (preg_match($pattern, parse_url($url, PHP_URL_PATH) ?: $url)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Contenu d'un widget dashboard pour une injection AJAX dans le DOM React (sans iframe).
     *
     * Renvoie le HTML du plugin + de quoi le faire vivre : ses scripts et ses jsCallbacks. Le HTML
     * est celui du chemin legacy (cf. dashboardPluginPageAction) — conteneur compris, car des
     * plugins y lisent leur config.
     *
     * GET /melis/react-dashboard-plugin-content?plugin=<PluginName>
     * → { success, html, callbacks: string[], js: string[], css: string, coreJs: string[] }
     */
    public function dashboardPluginContentAction()
    {
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

        $pluginName = $this->getRequest()->getQuery('plugin', '');
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['success' => false, 'error' => 'Invalid plugin']);
        }

        // Même garde de droits que dashboardPluginPageAction (clé = nom de classe du plugin).
        try {
            $rightsSvc = $this->getServiceManager()->get('MelisCoreDashboardPluginsService');
            if (!$rightsSvc->canAccess($pluginName)) {
                $this->getResponse()->setStatusCode(403);
                return new JsonModel(['success' => false, 'error' => 'Forbidden']);
            }
        } catch (\Throwable) {}

        $render = $this->renderDashboardPlugin($pluginName);
        if ($render === null) {
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(['success' => false, 'error' => 'Unknown plugin']);
        }

        $bust = static fn(string $u): string => \MelisReactOverride\Service\PlatformAssetsService::bust($u);

        // Les traductions viennent EN PREMIER : elles définissent les globals `translations` et
        // `melisLangId`, que les plugins lisent pour leurs libellés et formats de date (ex. les
        // séries du graphique Commerce sont nommées via translations.tr_melis_commerce_…).
        $session = new SessionContainer('meliscore');
        $locale  = $session['melis-lang-locale'] ?? 'en_EN';
        $coreJs  = array_merge(
            ['/melis/get-translations?locale=' . urlencode($locale)],
            self::AJAX_WIDGET_JS
        );

        // Globals que le layout du BO legacy déclare (primaryColor, themerPrimaryColor, basePath…).
        // flotcharts.common.js les lit AU CHARGEMENT pour construire le global `charts` : sans eux il
        // lève "themerPrimaryColor is not defined", `charts` n'existe jamais, et le plugin échoue sur
        // `charts.xxx = {…}` (« Cannot set properties of undefined »). Donc aucun graphique.
        $assets = \MelisReactOverride\Service\PlatformAssetsService::build($this->getServiceManager());

        return new JsonModel([
            'success'   => true,
            'html'      => self::stripLegacyWidgetHead($render['html']),
            'callbacks' => $render['callbacks'],
            'globals'   => $assets['inline'] ?? '',
            'coreJs'    => array_map($bust, $coreJs),
            'js'        => array_map($bust, self::stripShellUnsafeJs($render['js'])),
            'css'       => '/melis/react-legacy-widget-css',
        ]);
    }

    /**
     * Retire l'EN-TÊTE du conteneur legacy (plugin-container.phtml `.widget-head` : titre + engrenage
     * + poubelle + refresh) du HTML d'un widget.
     *
     * En AJAX, le widget est injecté DANS le cadre React, qui affiche déjà son propre titre et ses
     * propres boutons : garder l'en-tête legacy dupliquerait tout ça (et ses boutons, qui pilotent
     * gridstack, ne sont branchés sur rien ici).
     *
     * On ne retire QUE l'en-tête, pas le conteneur : les nœuds `.grid-stack-item` /
     * `.dashboard-plugin-json-config` restent nécessaires — plusieurs plugins y lisent leur config
     * (`.closest('.grid-stack-item').find('… .dashboard-plugin-json-config')`).
     */
    private static function stripLegacyWidgetHead(string $html): string
    {
        if ($html === '' || !str_contains($html, 'widget-head')) {
            return $html;
        }

        $doc = new \DOMDocument();
        // Le HTML est un FRAGMENT : sans ces flags, DOMDocument lui ajoute <html><body>. Le préfixe
        // XML force l'UTF-8 (sinon les accents des libellés Melis sortent en mojibake).
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return $html; // HTML non parsable → on préfère l'en-tête en trop à un widget vide.
        }

        $xpath = new \DOMXPath($doc);
        $heads = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' widget-head ')]");
        if ($heads !== false) {
            foreach ($heads as $head) {
                $head->parentNode?->removeChild($head);
            }
        }

        $out = '';
        foreach ($doc->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out !== '' ? $out : $html;
    }

    /**
     * Feuille de style legacy SCOPÉE (cf. LegacyWidgetCssService) pour les widgets AJAX.
     *
     * Servie telle quelle au shell React : toutes ses règles sont préfixées par
     * `.melis-legacy-widget`, donc elle ne peut pas repeindre le back-office React.
     *
     * GET /melis/react-legacy-widget-css
     */
    public function legacyWidgetCssAction()
    {
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

        $assets = \MelisReactOverride\Service\PlatformAssetsService::build($this->getServiceManager());
        $built  = \MelisReactOverride\Service\LegacyWidgetCssService::build($assets['css'] ?? []);

        $response = $this->getResponse();
        $response->setContent($built['css']);
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/css; charset=utf-8')
            // Le contenu est déterministe et versionné par le mtime des sources → cache long + ETag.
            ->addHeaderLine('Cache-Control', 'public, max-age=86400')
            ->addHeaderLine('ETag', '"' . $built['version'] . '"');

        return $response;
    }

    /**
     * JS/CSS declared by ONE dashboard plugin, as opposed to its whole module.
     *
     * Melis merges every config file of a module into a single module-level `ressources` node, so
     * the config service cannot tell us which files belong to which plugin. The source files can:
     * a dashboard plugin always ships as `<module>/config/dashboard-plugins/<PluginName>.config.php`
     * (the convention every module's Module.php includes by that exact path), and that file carries
     * only its own `ressources`. We locate the module directory from the plugin's controller-plugin
     * class and read that one file.
     *
     * @return array{js: string[], css: string[]}|null  null when the file can't be resolved
     *                                                   (caller then falls back to module-wide).
     */
    private function pluginOwnResources(string $pluginName): ?array
    {
        $dir = $this->pluginModuleDir($pluginName);
        if ($dir === null) {
            return null;
        }

        $configFile = $dir . '/config/dashboard-plugins/' . $pluginName . '.config.php';
        if (!is_file($configFile)) {
            return null;
        }

        return self::collectResources(include $configFile);
    }

    /**
     * All JS declared by the plugin's MODULE, across every config key of its app.interface.php.
     *
     * Needed because a module's scripts are not always filed under the same config key as its
     * plugins (MelisCalendar: plugin under `meliscalendar`, scripts under `melistoolcalendar`), so
     * the plugin's own path segment is not a reliable place to look them up.
     *
     * @return string[]
     */
    private function moduleWideJs(string $pluginName): array
    {
        $dir = $this->pluginModuleDir($pluginName);
        if ($dir === null || !is_file($dir . '/config/app.interface.php')) {
            return [];
        }

        return self::collectResources(include $dir . '/config/app.interface.php')['js'];
    }

    /** Root directory of the module owning a dashboard plugin, via its controller-plugin class. */
    private function pluginModuleDir(string $pluginName): ?string
    {
        // Dashboard plugins register as controller_plugins, not controllers.
        $cp    = $this->getServiceManager()->get('config')['controller_plugins'] ?? [];
        $class = ($cp['factories'] ?? [])[$pluginName]
            ?? ($cp['invokables'] ?? [])[$pluginName]
            ?? ($cp['aliases'] ?? [])[$pluginName]
            ?? null;

        if (!is_string($class) || !class_exists($class)) {
            return null;
        }

        try {
            $classFile = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return null;
        }

        // …/<module>/src/Controller/DashboardPlugins/<Plugin>.php → …/<module>
        $srcPos = $classFile ? strrpos($classFile, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR) : false;

        return $srcPos === false ? null : substr($classFile, 0, $srcPos);
    }

    /**
     * Flattens the `ressources` js/css of every module key of a Melis config array.
     *
     * @return array{js: string[], css: string[]}
     */
    private static function collectResources(mixed $cfg): array
    {
        $out = ['js' => [], 'css' => []];
        if (!is_array($cfg) || !is_array($cfg['plugins'] ?? null)) {
            return $out;
        }

        foreach ($cfg['plugins'] as $moduleCfg) {
            foreach (['js', 'css'] as $type) {
                foreach ((array) ($moduleCfg['ressources'][$type] ?? []) as $file) {
                    if (is_string($file) && $file !== '') {
                        $out[$type][] = $file;
                    }
                }
            }
        }

        $out['js']  = array_values(array_unique($out['js']));
        $out['css'] = array_values(array_unique($out['css']));

        return $out;
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
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

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
        //
        // ⚠️ L'id est VOLONTAIREMENT différent du legacy (`dashboard-plugin-properties-save`) :
        // gridstack.init.js (chargé via bundle.js) pose un handler délégué sur <body> pour cet id,
        // qui appelle dashboardPluginModalSubmit(). Celui-ci lit ses données dans
        // `#id_meliscore_dashboard_plugin_modal_container` et `.modal-content form` — deux éléments
        // qui n'existent PAS dans cette page → il POSTait des données VIDES vers
        // /melis/MelisCore/DashboardPlugins/validateDashboardPluginModal?validate. Avec un id distinct,
        // le handler legacy ne matche plus et seul notre submit (ci-dessous) tourne.
        $saveBtn = $allEmpty ? '' :
            '<button id="react-dashboard-plugin-config-save" class="btn btn-success float-right"><i class="fa fa-save"></i> '
            . htmlspecialchars($tr('tr_meliscore_plugins_modal_apply'), ENT_QUOTES) . '</button>';

        $pluginJs = json_encode($pluginName, JSON_UNESCAPED_SLASHES);
        $errTitle = json_encode($tr('tr_meliscore_error_message'), JSON_UNESCAPED_UNICODE);

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
  /* Le vrai parent (le shell React) DOIT être capturé avant que `window.parent` ne soit masqué
     ci-dessous — sinon plus aucun moyen de prévenir l'hôte que la config a été enregistrée. */
  var __melisHostWindow = window.parent;
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
<script>
(function(){
  var PLUGIN = {$pluginJs};
  var btn = document.getElementById('react-dashboard-plugin-config-save');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    e.preventDefault();

    /* Sérialise TOUS les champs des onglets. Les cases décochées ne sont pas envoyées par un form
       classique : le legacy (dashboardPluginModalSubmit) les pousse explicitement à 0 pour qu'un
       décochage soit persisté — même chose ici, sinon on ne peut jamais DÉSACTIVER une option. */
    var data = new FormData();
    data.append('plugin', PLUGIN);

    var fields = document.querySelectorAll('.tab-content input, .tab-content select, .tab-content textarea');
    Array.prototype.forEach.call(fields, function(f){
      if (!f.name || f.disabled) return;
      if (f.type === 'checkbox' || f.type === 'radio') {
        if (f.checked) data.append(f.name, f.value);
        else if (f.type === 'checkbox') data.append(f.name, '0');
      } else if (f.multiple && f.selectedOptions) {
        Array.prototype.forEach.call(f.selectedOptions, function(o){ data.append(f.name, o.value); });
      } else {
        data.append(f.name, f.value);
      }
    });

    btn.disabled = true;
    fetch('/melis/react-dashboard-plugin-config-save', {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function(r){ return r.json(); })
      .then(function(res){
        btn.disabled = false;
        if (res && res.success) {
          /* Prévient l'hôte React : il ferme la modale et recharge l'iframe du widget pour que la
             nouvelle config s'applique (le rendu lit la config au chargement). */
          try { __melisHostWindow.postMessage({ type: 'melis-plugin-config-saved', plugin: PLUGIN }, '*'); } catch(err) {}
        } else {
          var errs = (res && res.errors) || {};
          var msgs = [];
          Object.keys(errs).forEach(function(k){
            var e2 = errs[k];
            if (typeof e2 === 'string') msgs.push(e2);
            else if (e2 && typeof e2 === 'object') Object.keys(e2).forEach(function(k2){ msgs.push(e2[k2]); });
          });
          alert(msgs.length ? msgs.join('\\n') : {$errTitle});
        }
      })
      .catch(function(){ btn.disabled = false; alert({$errTitle}); });
  });
})();
</script>
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
    /**
     * Noms des champs réellement DÉCLARÉS dans le formulaire de config d'un plugin (`modal_form`).
     *
     * Sert à distinguer les vraies options utilisateur du reste de la config du plugin (libellés,
     * icône, géométrie…), que `getFormData()` renvoie pêle-mêle.
     *
     * @return string[]
     */
    private function configFieldNames(string $pluginName): array
    {
        try {
            $conf = $this->getServiceManager()->get('MelisCoreConfig')->getItem(
                '/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section/interface/' . $pluginName
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach (($conf['modal_form'] ?? []) as $tabConf) {
            foreach (($tabConf['elements'] ?? []) as $el) {
                if (!empty($el['spec']['name'])) $names[] = (string) $el['spec']['name'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Remonte les options de `datas` À LA RACINE du JSON de config posé dans le DOM.
     *
     * Le conteneur legacy sérialise la config ENTIÈRE (`json_encode($this->pluginConfig)`,
     * MelisCoreDashboardTemplatingPlugin::sendViewResult) : les options du plugin vivent donc sous
     * la clé `datas`. Or plusieurs plugins les relisent À LA RACINE :
     *
     *     chartFor = JSON.parse(pluginConfig).activeFilter;   // MelisCommerceDashboardPluginOrdersNumber
     *
     * → `undefined` au premier rendu. Pour Orders, aucune des branches hourly/daily/weekly/monthly
     * ne matche : les libellés de l'axe X restent des chaînes VIDES et le POST part sans `chartFor`.
     * Le graphique se dessine quand même — sans aucune graduation. Ça ne se voyait qu'après un
     * rechargement, parce qu'un CLIC sur un filtre passe par un autre chemin (l'élément cliqué),
     * et que le handler écrit, lui, `pluginConfig.activeFilter` à la racine : le clic « réparait »
     * la config jusqu'au rechargement suivant.
     *
     * ⚠️ Le bug est en amont, dans le plugin — il touche AUSSI le back-office classique. On le
     * corrige ici et pas dans melis-commerce pour garder le chantier React isolé du legacy : on
     * n'ajoute que des clés ABSENTES à la racine, donc un plugin qui lit déjà correctement sa
     * config n'est pas affecté.
     *
     * Générique : on ne connaît pas les noms d'options des plugins, mais on sait que la racine
     * porte la structure (conf/datas/forward/plugin_id…) et `datas` les valeurs.
     */
    private function hoistPluginConfigDatas(?string $html): ?string
    {
        if ($html === null || !str_contains($html, 'dashboard-plugin-json-config')) {
            return $html;
        }

        return preg_replace_callback(
            '#(<div[^>]*class="[^"]*dashboard-plugin-json-config[^"]*"[^>]*>)(.*?)(</div>)#s',
            static function (array $m): string {
                $cfg = json_decode(trim($m[2]), true);
                if (!is_array($cfg) || !is_array($cfg['datas'] ?? null)) {
                    return $m[0]; // Noeud vide ou inattendu : on n'y touche pas.
                }

                foreach ($cfg['datas'] as $key => $value) {
                    // Scalaires seulement, et jamais d'écrasement d'une clé déjà présente.
                    if (is_array($value) || is_object($value)) continue;
                    if (array_key_exists($key, $cfg)) continue;
                    $cfg[$key] = $value;
                }

                return $m[1] . json_encode($cfg) . $m[3];
            },
            $html
        ) ?? $html;
    }

    /**
     * Config d'un plugin dashboard exposée en DONNÉES (et non en HTML).
     *
     * La modale legacy rend les formulaires Laminas en HTML puis les affiche dans une iframe. Ici
     * on renvoie la SPEC : onglets + champs typés + valeurs courantes, pour que la modale React
     * dessine le formulaire avec ses propres composants (même look que le reste du back-office).
     *
     * Source : le même noeud de config que `getPluginConfig()` (`$this->pluginConfig` étant
     * protégé, on relit l'item plutôt que d'y accéder par réflexion), + `getFormData()` pour les
     * valeurs enregistrées. Les libellés `tr_*` sont traduits ici — le front n'a pas à les connaître.
     *
     * L'enregistrement reste inchangé (`dashboardPluginConfigSaveAction`) : les champs portent les
     * mêmes `name` que le formulaire legacy, donc le POST est identique et toute la validation
     * Laminas continue de s'appliquer.
     *
     * Usage : GET /melis/react-dashboard-plugin-config-data?plugin=MelisCommerceDashboardPluginSalesRevenue
     */
    public function dashboardPluginConfigDataAction()
    {
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

        $pluginName = $this->getRequest()->getQuery('plugin', '');
        if (!$pluginName || !preg_match('/^[A-Za-z0-9_-]+$/', $pluginName)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['success' => false, 'error' => 'Invalid plugin']);
        }

        $sm = $this->getServiceManager();

        $translator = null;
        try { $translator = $sm->get('translator'); } catch (\Throwable) {}
        $tr = static function ($key) use ($translator) {
            $key = (string) $key;
            // Seules les clés `tr_*` sont des libellés traduisibles ; le reste est déjà littéral.
            if ($key === '' || !$translator || !str_starts_with($key, 'tr_')) return $key;
            try { $t = (string) $translator->translate($key); return $t !== '' ? $t : $key; } catch (\Throwable) { return $key; }
        };

        // Valeurs enregistrées. `getFormData()` a besoin que la config du plugin ait été chargée.
        $values = [];
        try {
            $melisPlugin = $sm->get('ControllerPluginManager')->get($pluginName);
            $melisPlugin->setUpdatesPluginConfig(['dashboard_id' => self::REACT_DASHBOARD_CONFIG_ID, 'plugin_id' => $pluginName]);
            $melisPlugin->getPluginConfig();
            $data = $melisPlugin->getFormData();
            if (is_array($data)) $values = $data;
        } catch (\Throwable) {
            // Plugin non instanciable : on rendra quand même les champs, sans valeurs.
        }

        $conf = [];
        try {
            $conf = $sm->get('MelisCoreConfig')->getItem(
                '/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section/interface/' . $pluginName
            ) ?: [];
        } catch (\Throwable) {}

        $tabs = [];
        foreach (($conf['modal_form'] ?? []) as $tabKey => $tabConf) {
            if (!is_array($tabConf)) continue;
            $required = [];
            foreach (($tabConf['input_filter'] ?? []) as $fName => $fConf) {
                $required[$fName] = !empty($fConf['required']);
            }

            $fields = [];
            foreach (($tabConf['elements'] ?? []) as $el) {
                $spec = $el['spec'] ?? null;
                if (!is_array($spec) || empty($spec['name'])) continue;
                $name = (string) $spec['name'];
                $type = strtolower((string) ($spec['type'] ?? 'text'));

                // `value_options` d'un Select/Radio : [valeur => libellé], libellés traduisibles.
                $options = [];
                foreach (($spec['options']['value_options'] ?? []) as $optValue => $optLabel) {
                    $options[] = ['value' => (string) $optValue, 'label' => $tr($optLabel)];
                }

                $fields[] = [
                    'name'     => $name,
                    'type'     => $type,
                    'label'    => $tr($spec['options']['label'] ?? $name),
                    'value'    => array_key_exists($name, $values) ? $values[$name] : ($spec['attributes']['value'] ?? ''),
                    'options'  => $options,
                    'required' => $required[$name] ?? false,
                    'rows'     => (int) ($spec['attributes']['rows'] ?? 0),
                ];
            }

            $tabs[] = [
                'id'     => (string) $tabKey,
                'name'   => $tr($tabConf['tab_title'] ?? $tabKey),
                'icon'   => (string) ($tabConf['tab_icon'] ?? 'fa fa-cog'),
                'fields' => $fields,
            ];
        }

        // « Vide » = aucun champ éditable, tous onglets confondus → la modale affiche un message
        // et masque le bouton d'enregistrement (même règle que la modale legacy).
        $hasFields = false;
        foreach ($tabs as $t) { if ($t['fields'] !== []) { $hasFields = true; break; } }

        return new JsonModel([
            'success' => true,
            'data'    => [
                'empty'      => !$hasFields,
                'tabs'       => $tabs,
                'emptyLabel' => $tr('tr_meliscore_dashboard_plugin_common_tab_properties'),
                'saveLabel'  => $tr('tr_meliscore_plugins_modal_apply'),
            ],
        ]);
    }

    public function dashboardPluginConfigSaveAction()
    {
        if ($denied = $this->denyIfUnauthenticated()) {
            return $denied;
        }

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
        // The login page itself renders through THIS action: MelisAuthController::loginpageAction()
        // forwards here with appconfigpath=/meliscore_login — that is the one legitimate anonymous
        // caller (it's how an unauthenticated visitor sees the login form at all). Every other
        // caller of this generic zone renderer is already gated by MelisCore\Module::checkIdentity()
        // (attached on EVENT_ROUTE) upstream; this local guard is defense-in-depth for tool-renderer
        // callers (see denyIfUnauthenticated() docblock), not for the public login zone tree.
        $appconfigpath = $this->params()->fromRoute('appconfigpath', '');
        if ($appconfigpath !== '/meliscore_login' && ($denied = $this->denyIfUnauthenticated())) {
            return $denied;
        }

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
