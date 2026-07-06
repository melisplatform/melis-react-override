<?php

namespace MelisReactOverride\Controller;

/**
 * Extension point for PluginViewController::toolPageAction() so module-specific quirks (extra
 * header HTML, module JS forced into the <head> bucket, etc.) live in the OWNING module instead
 * of being hardcoded here as `if ($key === '...')` blocks. Register implementations under
 * config('melis_react_override')['toolpage_extensions'][] (array of service-manager names) — any
 * module can add entries there without editing this module. See MelisAI's
 * PluginViewToolPageExtension for a full example (composite admin tool header/save-form
 * injection, forced head-bucket JS for inline-script globals).
 */
interface PluginViewToolPageExtensionInterface
{
    /**
     * Adjust the rendered zone HTML for a given melisKey before the page is assembled
     * (e.g. prepend a shared header zone / hidden form so a sub-tab loaded standalone still has
     * a working Save button). Return $html unchanged for keys the extension doesn't care about.
     */
    public function adjustToolHtml(string $key, string $html, array $jsCallBacks, PluginViewController $controller): string;

    /**
     * Adjust the platform asset bundle for a given melisKey. $html is the FINAL assembled zone
     * HTML (after adjustToolHtml()) — extensions needing to know whether a specific inline
     * <script> call is present (e.g. "does this page embed the chat widget?") should sniff $html
     * rather than hardcode a list of container melisKeys: a composite tool nesting a sub-zone
     * (e.g. the Agent tool embedding the chat tester, or a future composite tool doing the same)
     * would otherwise need this module's key-list extended every time, defeating the point of
     * this being an extension point. Return:
     *   ['assets' => array, 'skipJsRoots' => array<string, true>]
     * 'assets' is the (possibly modified) $assets array; 'skipJsRoots' lists plugin roots whose
     * JS the caller already injected (e.g. into the <head> bucket) so the generic end-of-body
     * jsRessources loop must NOT re-add them (that would double-load the script and double-bind
     * any delegated jQuery handlers it registers). Return $assets unchanged / an empty
     * skipJsRoots for keys the extension doesn't care about.
     */
    public function adjustToolAssets(string $key, string $html, array $assets, PluginViewController $controller): array;
}
