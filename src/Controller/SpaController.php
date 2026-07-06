<?php

namespace MelisReactOverride\Controller;

use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Serves the React SPA shell (index.html) for client-side deep links under
 * /melis-react/*.
 *
 * Real files (the SPA root index.html and the hashed assets) are streamed earlier
 * by MelisAssetManager at bootstrap, so they never reach the MVC router. Only
 * virtual client-side routes (e.g. /melis-react/news/5) fall through here,
 * and we return the shell so the browser-side router can take over.
 */
class SpaController extends AbstractActionController
{
    public function spaAction()
    {
        // The React build lives in melis-core's public/ (served at /MelisCore/ui-react/).
        // Resolve from DOCUMENT_ROOT (the skeleton's public/) so this works regardless of
        // where this module sits on disk (it now lives in the skeleton's /module).
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $index   = realpath($docRoot . '/../vendor/melisplatform/melis-core/public/ui-react/index.html');

        $response = $this->getResponse();
        if ($index === false || !is_file($index)) {
            return $response->setStatusCode(404);
        }

        $response->setContent(file_get_contents($index));
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/html; charset=utf-8')
            // Never cache the HTML shell; the referenced assets are content-hashed.
            ->addHeaderLine('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }
}
