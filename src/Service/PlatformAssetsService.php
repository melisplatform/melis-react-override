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
            $raw      = $sm->get('MelisAssetManagerWebPack')->getAssets(true);
            $cssFiles = array_values(array_filter((array) ($raw['css'] ?? []), $exists));
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
}
