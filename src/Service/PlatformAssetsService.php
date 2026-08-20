<?php

namespace MelisReactOverride\Service;

use Laminas\ServiceManager\ServiceManager;
use Laminas\Session\Container as SessionContainer;

/**
 * Builds the platform asset list the React app needs to bootstrap Melis tool iframes.
 *
 * CSS: all module bundle.css files (browser loads them in parallel — no perf cost).
 * JS:  only translations + MelisCore bundle.js (jQuery/Bootstrap/DataTables).
 *      Loading 30 module bundle.js files sequentially takes 5+ minutes in dev; the
 *      MelisCore bundle already contains everything needed to render back-office tools.
 */
class PlatformAssetsService
{
    /** Our own bundle route (MelisCore's answers `text/html` when the bundle file is missing). */
    private const BUNDLE_ROUTE = '/melis/react-platform-bundle';

    public static function build(ServiceManager $sm): array
    {
        $session = new SessionContainer('meliscore');
        $locale  = $session['melis-lang-locale'] ?? 'en_EN';

        // Mirror MelisAssetManager::displayFile() to verify module assets exist on disk.
        $docRoot        = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $modulePathFile = $docRoot . '/../config/melis.modules.path.php';
        $modulesPath    = file_exists($modulePathFile) ? (require $modulePathFile) : [];
        $exists = static function (string $url) use ($docRoot, $modulesPath): bool {
            if (str_starts_with($url, 'http') || str_starts_with($url, '/melis')) {
                return true;
            }
            if (file_exists($docRoot . $url)) {
                return true;
            }
            $parts = explode('/', ltrim($url, '/'));
            if (count($parts) > 1 && !empty($modulesPath[$parts[0]])) {
                $modulePath = $modulesPath[$parts[0]];
                if (!str_contains($modulePath, $docRoot)) {
                    $modulePath = $docRoot . '/..' . $modulePath;
                }
                return file_exists($modulePath . '/public/' . implode('/', array_slice($parts, 1)));
            }
            return false;
        };

        // CSS: collect all module bundle.css files (loaded in parallel by the browser).
        $cssFiles = [];
        try {
            $cssFiles = array_values(array_filter(self::cachedModuleCss($sm), $exists));
        } catch (\Throwable) {}

        // JS load queue:
        //   1. Translations (locale strings)
        //   2. MelisCore bundle.js (jQuery, Bootstrap, DataTables, core tools)
        //   3. Non-bundled plugins declared in app.interface.php but absent from
        //      webpack.mix.js — bootstrap-tagsinput, typeahead, moment locale.
        //      These are served by the Vite melisModuleAssetsPlugin from disk.
        $js = [
            '/melis/get-translations?locale=' . urlencode($locale),
            '/MelisCore/build/js/bundle.js',
            // melisDataTable.js est déclaré dans app.interface.php mais absent de bundle.js.
            // Il expose window.melisDataTable (objet avec tableLanguage utilisé par les DataTables).
            '/MelisCore/js/core/melisDataTable.js',
            // loader.js defines the global `loader` (page-edition loading overlay). It's a MelisCore
            // ressource (app.interface meliscore) NOT in bundle.js, and buildToolPage skips meliscore
            // ressources → without it window.loader is undefined. Tool handlers call
            // window.parent.loader.addActivePageEditionLoading(...) on their FIRST line (e.g. the CMS
            // page Save/Publish/Delete buttons), so a missing `loader` throws and the button silently
            // does nothing. Load it so window.loader exists. (translations are loaded just above.)
            '/MelisCore/js/core/loader.js',
            // findpage.tool.js defines the SHARED page picker `window.melisLinkTree`
            // (melisLinkTree.createInputTreeModal opens the "select a page" tree modal). It lives in
            // MelisCms but is reused by OTHER modules' tools (MelisCmsSlider, MelisCommerce…). Those
            // tool pages only inject their own plugin ressources, so without this they throw
            // "melisLinkTree is not defined" on the page-id picker button. Load it for every tool
            // page (only needs jQuery + melisHelper at load; the modal's tree uses fancytree from
            // bundle.js, and its relative AJAX URL resolves via the <base href="/">).
            '/MelisCms/js/tools/findpage.tool.js',
            '/MelisCore/js/library/bootstrap-tags/bootstrap-tagsinput.js',
            '/MelisCore/js/library/bootstrap-tags/typeahead.bundle.js',
            '/MelisCore/js/moment/fr.js',
            // melis_tinymce.js définit l'objet window.melisTinyMCE et, dans la fenêtre top,
            // précharge les configs TinyMCE (GET preloadTinyMceConfig) dans melisTinyMCE.tinyMceConfigs.
            // L'iframe d'édition de page (front en renderMode/melis) lit la config via
            // window.parent.melisTinyMCE.tinyMceConfigs[type] (melis_tinymce.js:38). Ici la page
            // de l'outil (buildToolPage) EST ce parent : sans cet objet, la config est undefined et
            // TinyMCE retombe sur sa barre par défaut (menubar "File Edit…" + "Upgrade") au lieu de
            // la barre riche Melis (html.php). Le shim Envato de buildToolPage force window.top===window,
            // donc le test `window.self === window.top` (melis_tinymce.js:708) passe → préchargement OK.
            // jQuery est déjà chargé (bundle.js) ; au chargement ce fichier n'utilise pas la lib tinyMCE.
            '/MelisCore/js/tinyMCE/melis_tinymce.js',
        ];

        // Prepend Google Fonts (same as layoutCore.phtml hard-codes it above the bundle).
        $css = array_merge(
            ['https://fonts.googleapis.com/css?family=Open+Sans:400,300,600,700|Roboto:400,300,700|Montserrat:300,400,700'],
            $cssFiles,
            ['/assets/css/schemes.css']
        );

        // Colour palette — read from active platform scheme, fall back to Melis defaults.
        $primaryColor = '#cb4040'; $dangerColor  = '#b55151';
        $infoColor    = '#466baf'; $successColor = '#8baf46';
        $warningColor = '#ab7a4b'; $inverseColor = '#45484d';
        try {
            $schemes = $sm->get('MelisCorePlatformSchemeService');
            $active  = $schemes->getActiveScheme();
            if (!empty($active)) {
                $primaryColor = $active->getColor('primary') ?: $primaryColor;
                $dangerColor  = $active->getColor('danger')  ?: $dangerColor;
                $infoColor    = $active->getColor('info')    ?: $infoColor;
                $successColor = $active->getColor('success') ?: $successColor;
                $warningColor = $active->getColor('warning') ?: $warningColor;
                $inverseColor = $active->getColor('inverse') ?: $inverseColor;
            }
        } catch (\Throwable) {}

        $inline = implode("\n", [
            "var basePath        = '';",
            "var commonPath      = '../assets/';",
            "var rootPath        = '../';",
            "var DEV             = false;",
            "var componentsPath  = '../assets/components/';",
            "var primaryColor    = '{$primaryColor}';",
            "var dangerColor     = '{$dangerColor}';",
            "var infoColor       = '{$infoColor}';",
            "var successColor    = '{$successColor}';",
            "var warningColor    = '{$warningColor}';",
            "var inverseColor    = '{$inverseColor}';",
            "var themerPrimaryColor = primaryColor;",
        ]);

        return ['css' => $css, 'js' => $js, 'inline' => $inline];
    }

    /**
     * `MelisAssetManagerWebPack::getAssets(true)['css']` — the platform's module CSS bundle list —
     * costs ~840ms: getAssets() calls getMergedAssets() FOUR times, each walking every module's
     * config + filesystem. The list is GLOBAL and deterministic per deploy (it changes only when
     * assets are rebuilt or a module is (de)activated), yet build() runs on EVERY tool/dashboard
     * iframe render — ~9 per dashboard load, so ~7.5s of pure redundant work. Cache it cross-request
     * (file in the system temp dir, short TTL) plus an in-process memo. First render after a fresh
     * container pays the ~840ms once; every render for the next TTL reads the cache (~0ms).
     *
     * Cache the RAW list only (not the whole build()): the locale-dependent JS list and the scheme
     * colours are ~0ms to recompute, so they stay always-fresh — no staleness on a scheme change.
     */
    private static function cachedModuleCss(ServiceManager $sm): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $file = sys_get_temp_dir() . '/melis-react-platform-css.json';
        if (is_file($file) && (time() - (int) filemtime($file)) < 600) {
            $cached = json_decode((string) @file_get_contents($file), true);
            // Re-validate a cached CONCATENATED bundle URL: `etc/bundles/` is wiped whenever the
            // Modules tool is saved, so within the TTL the cache can still name a bundle that no
            // longer exists — the iframe then links a route answering an empty document and the
            // tool renders unstyled. Recompute instead (and fall back to the per-module list).
            if (is_array($cached)
                && !(count($cached) === 1
                     && self::isConcatBundleUrl($cached[0])
                     && (self::concatBundlePath($cached[0]) === null
                         || @filesize(self::concatBundlePath($cached[0])) <= 0))) {
                return $memo = $cached;
            }
        }
        $raw = $sm->get('MelisAssetManagerWebPack')->getAssets(true);
        $css = array_values((array) ($raw['css'] ?? []));

        // No concatenated bundle in the list = `etc/bundles/` is empty. It is wiped on every
        // Modules-tool save and MelisCore only rebuilds it when `/melis` or `/melis/login` is hit
        // — which a session living in `/melis-react` never does, so the back-office would stay on
        // the per-module list (30+ requests per iframe) for good. Rebuild it here instead.
        if (!(count($css) === 1 && self::isConcatBundleUrl($css[0])) && self::regenerateBundles($sm)) {
            $css = array_values((array) ($sm->get('MelisAssetManagerWebPack')->getAssets(true)['css'] ?? []));
        }

        // With a CONCATENATED bundle, getAssets(true) returns that single route
        // (`/melis/get-css-bundles`) instead of the per-module list. The browser is happy with a
        // route, but LegacyWidgetCssService needs FILES on disk — and the bundle is produced by the
        // Modules tool, which can leave `etc/bundles/css/bundle-all.css` EMPTY (0 byte after a fresh
        // install — observed). Either case leaves the React widgets with almost no legacy CSS:
        // unstyled Bootstrap grid/media/alert, and `.hidden` undefined, so a plugin's hidden JSON
        // config node shows up as raw text. Fall back to the per-module list, which always exists.
        // Decided here rather than in build() so the (expensive) getAssets call is cached too.
        if (count($css) === 1 && self::isConcatBundleUrl($css[0])) {
            $path = self::concatBundlePath($css[0]);
            $css = ($path === null)
                ? array_values((array) ($sm->get('MelisAssetManagerWebPack')->getAssets(false)['css'] ?? []))
                // Serve the bundle through OUR route: MelisCore's answers an empty `text/html`
                // body once `etc/bundles/` has been wiped (Modules tool save), and the iframe HTML
                // linking it can outlive the file. Ours always answers with the right MIME type.
                : [self::toOwnBundleRoute($css[0])];
        }
        if ($css !== []) {
            @file_put_contents($file, json_encode($css), LOCK_EX);
        }
        return $memo = $css;
    }

    /**
     * Rebuilds `etc/bundles/` (the same MelisCore call its own listener makes on `/melis`), so the
     * React back-office is not stuck on the per-module asset list once the Modules tool has wiped
     * the bundle. Returns true when a bundle was actually produced.
     *
     * Only one process rebuilds at a time — the others fall through to the per-module list rather
     * than piling up on a job that takes seconds — and a failing rebuild is not retried more than
     * once a minute, so it can never turn every iframe render into a full rebuild.
     */
    private static function regenerateBundles(ServiceManager $sm): bool
    {
        try {
            $platform    = getenv('MELIS_PLATFORM');
            $buildBundle = $sm->get('MelisCoreConfig')->getItem('/meliscore/datas/')[$platform]['build_bundle'] ?? true;
        } catch (\Throwable) {
            return false;
        }

        if (!$buildBundle) {
            return false;
        }

        $dir      = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/../etc/bundles';
        $lockFile = $dir . '/.generate.lock';

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        clearstatcache(true, $lockFile);
        if (is_file($lockFile) && (time() - (int) filemtime($lockFile)) < 60) {
            return false;
        }

        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            return false;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        @touch($lockFile);

        try {
            $sm->get('ModulesService')->generateBundle();
        } catch (\Throwable) {
            // A missing bundle is not fatal: the caller keeps the per-module list.
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::bundleFile('css', $sm) !== null;
    }

    /**
     * Resolve a local asset URL (e.g. "/MelisCms/css/tools/sites/sites.tool.css") to its file on
     * disk, mirroring MelisAssetManager's URL→module mapping. Returns null for external URLs or
     * anything not found. The DOCUMENT_ROOT + modules-path map are loaded once per request.
     */
    /** `/melis/get-css-bundles?v=…` / `/melis/get-js-bundles?v=…` — the concatenated-bundle routes. */
    public static function isConcatBundleUrl(string $url): bool
    {
        return str_starts_with($url, '/melis/get-css-bundles')
            || str_starts_with($url, '/melis/get-js-bundles')
            || str_starts_with($url, self::BUNDLE_ROUTE);
    }

    /**
     * File served by those routes (`etc/bundles/{css,js}/bundle-all.{css,js}`, cf. MelisCore
     * ModulesController::get{Css,Js}BundlesAction). Returns null when absent.
     */
    public static function concatBundlePath(string $url): ?string
    {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $isJs    = str_starts_with($url, '/melis/get-js-bundles')
            || (str_starts_with($url, self::BUNDLE_ROUTE) && str_contains($url, 't=js'));
        $path    = $docRoot . '/../etc/bundles/' . ($isJs ? 'js/bundle-all.js' : 'css/bundle-all.css');

        return is_file($path) && filesize($path) > 0 ? $path : null;
    }

    /**
     * The bundle file to serve for $type ('css'|'js'), or null when there is none (missing or
     * empty). The login variant is used for a visitor without an identity, exactly like MelisCore's
     * own bundle routes do.
     */
    public static function bundleFile(string $type, ServiceManager $sm): ?string
    {
        $type = ($type === 'js') ? 'js' : 'css';

        $identity = false;
        try {
            $identity = (bool) $sm->get('MelisCoreAuth')->hasIdentity();
        } catch (\Throwable) {}

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $path    = $docRoot . '/../etc/bundles/' . $type . '/bundle-all' . ($identity ? '' : '-login') . '.' . $type;

        return is_file($path) && filesize($path) > 0 ? $path : null;
    }

    /**
     * Swap MelisCore's bundle route for ours, keeping the `?v=` cache buster.
     * @see \MelisReactOverride\Controller\PluginViewController::platformBundleAction()
     */
    private static function toOwnBundleRoute(string $url): string
    {
        $query = (($pos = strpos($url, '?')) !== false) ? substr($url, $pos + 1) : '';
        $isJs  = str_starts_with($url, '/melis/get-js-bundles');

        return self::BUNDLE_ROUTE
            . '?' . ($isJs ? 't=js' : 't=css')
            . ($query !== '' ? '&' . $query : '');
    }

    public static function resolvePath(string $url): ?string
    {
        // Route, not a file: map it to the bundle it serves, so callers that read from disk
        // (LegacyWidgetCssService) see the same bytes the browser would download.
        if (self::isConcatBundleUrl($url)) {
            return self::concatBundlePath($url);
        }

        static $docRoot = null, $modulesPath = null;
        if ($docRoot === null) {
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
            $modulePathFile = $docRoot . '/../config/melis.modules.path.php';
            $modulesPath = file_exists($modulePathFile) ? (require $modulePathFile) : [];
        }
        if ($url === '' || str_starts_with($url, 'http')) {
            return null;
        }
        if (is_file($docRoot . $url)) {
            return $docRoot . $url;
        }
        $parts = explode('/', ltrim($url, '/'));
        if (count($parts) > 1 && !empty($modulesPath[$parts[0]])) {
            $modulePath = $modulesPath[$parts[0]];
            if (!str_contains($modulePath, $docRoot)) {
                $modulePath = $docRoot . '/..' . $modulePath;
            }
            $cand = $modulePath . '/public/' . implode('/', array_slice($parts, 1));
            if (is_file($cand)) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * Append a mtime cache-buster (?v=…) to a local asset URL so the browser refetches it after an
     * edit while still caching between edits. Tool CSS/JS are served with a 1-day max-age AND loaded
     * inside the tool iframe, where a parent hard-refresh doesn't reliably revalidate subresources —
     * without this, an edited tool stylesheet/script stays stale for up to a day. External URLs,
     * URLs already carrying a query (e.g. /melis/get-translations?locale=…), and unresolvable paths
     * are returned unchanged.
     */
    public static function bust(string $url): string
    {
        if ($url === '' || str_contains($url, '?') || str_starts_with($url, 'http')) {
            return $url;
        }
        $path = self::resolvePath($url);
        if ($path === null) {
            return $url;
        }
        $mt = @filemtime($path);
        return $mt ? $url . '?v=' . $mt : $url;
    }
}
