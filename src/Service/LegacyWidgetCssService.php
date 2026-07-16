<?php

namespace MelisReactOverride\Service;

/**
 * Builds a SCOPED copy of the legacy back-office stylesheets, for the React dashboard's AJAX widgets.
 *
 * The widgets inject legacy plugin HTML straight into the React DOM (no iframe), so they need the
 * legacy CSS — but that CSS is the whole back-office theme (Bootstrap 4.6 + the Melis admin skin).
 * Loaded as-is it would restyle the React shell: it resets `body`, `h1`-`h6`, `.btn`, `.card`,
 * `.badge`, `a`… — all class names Tailwind/shadcn also use. So every rule is rewritten to only
 * apply under a wrapper class:
 *
 *     .btn            →  .melis-legacy-widget .btn
 *     body            →  .melis-legacy-widget
 *     @media … { … }  →  @media … { .melis-legacy-widget … }
 *
 * `@keyframes` / `@font-face` / `@import` are copied verbatim (they carry no selectors to scope).
 * `url(...)` references are rewritten to absolute URLs, since the result is served from a different
 * path than the source files (relative fonts/images would 404 otherwise).
 *
 * The result is deterministic, so it is cached on disk and only rebuilt when a source file changes.
 */
class LegacyWidgetCssService
{
    /** Wrapper class the React widget container carries (see widgets.tsx). */
    public const SCOPE = '.melis-legacy-widget';

    /**
     * Scoped stylesheet for the given asset URLs, from cache when possible.
     *
     * @param string[] $urls
     * @return array{css: string, version: string}
     */
    public static function build(array $urls): array
    {
        $files = [];
        foreach ($urls as $url) {
            // External stylesheets (Google Fonts) carry no selectors we could clash on — skip.
            if ($url === '' || str_starts_with($url, 'http')) {
                continue;
            }
            $path = PlatformAssetsService::resolvePath($url);
            if ($path !== null) {
                $files[$url] = $path;
            }
        }

        // Version = the newest mtime across sources + a bump when the scoping logic itself changes.
        $stamp = 'v2';
        foreach ($files as $path) {
            $stamp .= '|' . $path . ':' . (@filemtime($path) ?: 0);
        }
        $version = substr(sha1($stamp), 0, 12);

        $cacheFile = sys_get_temp_dir() . '/melis-legacy-widget-css-' . $version . '.css';
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') {
                return ['css' => $cached, 'version' => $version];
            }
        }

        $out = [];
        foreach ($files as $url => $path) {
            $css = @file_get_contents($path);
            if (!is_string($css) || $css === '') {
                continue;
            }
            // Base dir of THIS file — url() is relative to it, not to where we serve the result.
            $baseDir = rtrim(dirname($url), '/');
            $out[] = self::scope(self::absolutizeUrls($css, $baseDir), self::SCOPE);
        }

        $css = implode("\n", $out);
        @file_put_contents($cacheFile, $css);

        return ['css' => $css, 'version' => $version];
    }

    /** Rewrites relative url(...) to absolute, relative to the stylesheet's own directory. */
    private static function absolutizeUrls(string $css, string $baseDir): string
    {
        return preg_replace_callback(
            '#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
            static function (array $m) use ($baseDir): string {
                $quote = $m[1];
                $ref   = trim($m[2]);

                // Already absolute / data: / protocol-relative → leave alone.
                if ($ref === '' || preg_match('#^(?:/|data:|https?:|//|\#)#i', $ref)) {
                    return $m[0];
                }

                $path = $baseDir . '/' . $ref;
                // Collapse ../ segments so the browser gets a clean absolute path.
                $segments = [];
                foreach (explode('/', $path) as $seg) {
                    if ($seg === '' || $seg === '.') {
                        continue;
                    }
                    if ($seg === '..') {
                        array_pop($segments);
                        continue;
                    }
                    $segments[] = $seg;
                }

                return 'url(' . $quote . '/' . implode('/', $segments) . $quote . ')';
            },
            $css
        ) ?? $css;
    }

    /**
     * Prefixes every selector of a stylesheet with $scope.
     *
     * Hand-rolled rather than regex-per-rule: selectors and at-rules nest, and a naive
     * "split on }" mangles @media blocks. This walks the source once, tracking brace depth.
     */
    private static function scope(string $css, string $scope): string
    {
        $css = self::stripComments($css);
        $len = strlen($css);
        $out = '';
        $buf = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $css[$i];

            if ($ch === '{') {
                $prelude = trim($buf);
                $buf = '';

                if ($prelude !== '' && $prelude[0] === '@') {
                    $atRule = strtolower(strtok(substr($prelude, 1), " \t\n\r("));

                    // Conditional groups CONTAIN rules → recurse into the block and scope those.
                    if (in_array($atRule, ['media', 'supports', 'document', 'layer', 'container'], true)) {
                        $block = self::readBlock($css, $i); // $i left on the closing brace
                        $out .= $prelude . '{' . self::scope($block, $scope) . '}';
                        continue;
                    }

                    // @keyframes / @font-face / @page… → no selectors to scope, copy verbatim.
                    $block = self::readBlock($css, $i);
                    $out .= $prelude . '{' . $block . '}';
                    continue;
                }

                $block = self::readBlock($css, $i);
                $scoped = self::scopeSelectorList($prelude, $scope);
                if ($scoped !== '') {
                    $out .= $scoped . '{' . $block . '}';
                }
                continue;
            }

            $buf .= $ch;
        }

        // Trailing @charset/@import and the like, which end on ';' rather than a block.
        $tail = trim($buf);
        if ($tail !== '') {
            $out .= $tail;
        }

        return $out;
    }

    /**
     * Reads the balanced block starting at the '{' at $i. Leaves $i on the matching '}'.
     * Brace-counting is quote-aware so a '}' inside content:"…" doesn't close the block early.
     */
    private static function readBlock(string $css, int &$i): string
    {
        $len   = strlen($css);
        $depth = 0;
        $start = $i + 1;
        $quote = '';

        for (; $i < $len; $i++) {
            $ch = $css[$i];

            if ($quote !== '') {
                if ($ch === '\\') {
                    $i++;            // escaped char inside a string
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $i - $start);
                }
            }
        }

        // Unbalanced source — take what's left rather than dropping the rest of the file.
        return substr($css, $start);
    }

    /** Scopes a comma-separated selector list (commas inside :not(...) etc. are not separators). */
    private static function scopeSelectorList(string $selectors, string $scope): string
    {
        $parts = self::splitTopLevel($selectors);
        $out   = [];

        foreach ($parts as $sel) {
            $sel = trim($sel);
            if ($sel === '') {
                continue;
            }

            // Page-level selectors ARE the widget container once scoped: `body .x` → `.scope .x`,
            // and a bare `body` → `.scope`. Without this they'd become `.scope body`, which never
            // matches (the wrapper is inside body, not the other way round).
            $rewritten = preg_replace('/^(?:html|body|:root)\b/i', '', $sel, 1, $count);
            if ($count > 0) {
                $rewritten = trim((string) $rewritten);
                // Leftovers like `body.modal-open` → `.scope.modal-open` (no space).
                $out[] = $rewritten === ''
                    ? $scope
                    : ($rewritten[0] === '.' || $rewritten[0] === '#' || $rewritten[0] === ':' || $rewritten[0] === '['
                        ? $scope . $rewritten
                        : $scope . ' ' . $rewritten);
                continue;
            }

            $out[] = $scope . ' ' . $sel;
        }

        return implode(',', $out);
    }

    /** Splits on commas that are not inside (), [] or quotes. */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $buf   = '';
        $depth = 0;
        $quote = '';
        $len   = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $s[++$i];
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buf  .= $ch;
                continue;
            }

            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }

            if ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }

    /** Strips /* … *\/ comments (quote-aware, so a "/*" inside a string survives). */
    private static function stripComments(string $css): string
    {
        $out   = '';
        $len   = strlen($css);
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $css[$i];

            if ($quote !== '') {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $out .= $css[++$i];
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $out  .= $ch;
                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }
}
