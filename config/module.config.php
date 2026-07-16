<?php

return [
    'router' => [
        'routes' => [
            'melis-backoffice' => [
                'child_routes' => [
                    // Renders a full standalone HTML tool page so the React app can load it
                    // directly in an <iframe src="..."> without any client-side script extraction.
                    'react-tool-page' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-tool-page',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'toolPage',
                            ],
                        ],
                    ],
                    // Renders a single legacy dashboard plugin as a minimal standalone HTML page
                    // for use in an iframe inside the React dashboard.
                    'react-dashboard-plugin' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-dashboard-plugin',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'dashboardPluginPage',
                            ],
                        ],
                    ],
                    // Config form (cog button) of a legacy dashboard plugin — standalone HTML
                    // for an iframe inside the React config dialog.
                    'react-dashboard-plugin-config' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-dashboard-plugin-config',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'dashboardPluginConfigPage',
                            ],
                        ],
                    ],
                    // POST endpoint that validates + persists a legacy dashboard plugin's config
                    // (the Save button of the React config dialog). Isolated storage row so it
                    // never collides with the React layout/geometry save.
                    'react-dashboard-plugin-config-save' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-dashboard-plugin-config-save',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'dashboardPluginConfigSave',
                            ],
                        ],
                    ],
                    // JSON: HTML + scripts + jsCallbacks of a legacy dashboard plugin, for the React
                    // dashboard to inject it straight into its DOM (AJAX widgets — no iframe).
                    'react-dashboard-plugin-content' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-dashboard-plugin-content',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'dashboardPluginContent',
                            ],
                        ],
                    ],
                    // The legacy back-office stylesheets, every rule scoped under
                    // `.melis-legacy-widget` so they cannot restyle the React shell.
                    'react-legacy-widget-css' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => 'react-legacy-widget-css',
                            'defaults' => [
                                'controller' => 'MelisCore\Controller\PluginView',
                                'action'     => 'legacyWidgetCss',
                            ],
                        ],
                    ],
                ],
            ],

            // React back-office access URL: /melis-react (parallel to the legacy /melis).
            // This route serves the SPA shell (index.html) for the entry point and every
            // client-side deep link (/melis-react/news/5, …). The hashed assets referenced
            // by the shell load from /MelisCore/ui-react/* and are served by MelisAssetManager.
            'meliscore-melis-react-spa' => [
                'type'    => 'Regex',
                // High priority so it wins over MelisFront's catch-all front route
                // (which otherwise resolves /melis-react as a CMS page → 404) when the
                // CMS trio is active.
                'priority' => 1000,
                'options' => [
                    'regex'    => '/melis-react(?<spa>/[a-zA-Z0-9_\-/]*)?',
                    'spec'     => '/melis-react%spa%',
                    'defaults' => [
                        'controller' => 'MelisReactOverride\Controller\Spa',
                        'action'     => 'spa',
                    ],
                ],
            ],
        ],
    ],

    'controllers' => [
        'invokables' => [
            // Override MelisCore's PluginViewController with our React-aware version.
            // Laminas merges configs in module-load order; our module loads after melis-core
            // (./module/ is appended after getModuleComponents()), so this wins.
            'MelisCore\Controller\PluginView' => \MelisReactOverride\Controller\PluginViewController::class,
            // Serves the React SPA shell for deep links under /MelisCore/ui-react/*.
            'MelisReactOverride\Controller\Spa' => \MelisReactOverride\Controller\SpaController::class,
        ],
    ],

    // Make the SPA shell route public: the React app handles authentication itself
    // (it shows its own login screen). Appended to MelisCore's excluded_routes list
    // (numeric arrays merge by appending), so MelisCore\Module::checkIdentity() lets
    // /MelisCore/ui-react/* through without redirecting to /melis/login.
    'plugins' => [
        'meliscore' => [
            'datas' => [
                'excluded_routes' => [
                    'meliscore-melis-react-spa',
                    // Lecture publique du thème React (branding du panneau gauche du login,
                    // affiché AVANT authentification). Seul le GET est exclu ; le /save reste protégé.
                    'melis-backoffice/melis-react-api/platformscheme-react-get',
                ],
            ],
        ],
    ],
];
