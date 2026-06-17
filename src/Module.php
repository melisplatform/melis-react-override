<?php

namespace MelisReactOverride;

use Laminas\Mvc\ModuleRouteListener;
use Laminas\Mvc\MvcEvent;

class Module
{
    public function onBootstrap(MvcEvent $e): void
    {
        $eventManager        = $e->getApplication()->getEventManager();
        $moduleRouteListener = new ModuleRouteListener();
        $moduleRouteListener->attach($eventManager);
    }

    public function getConfig(): array
    {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function getAutoloaderConfig(): array
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    // Module.php lives in src/, so __DIR__ === src/ →
                    // MelisReactOverride\Controller\Foo maps to src/Controller/Foo.php.
                    __NAMESPACE__ => __DIR__,
                ],
            ],
        ];
    }
}
