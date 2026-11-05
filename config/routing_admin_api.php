<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The admin API of the bundle. A project imports this file once, under the /admin/api prefix.
 *
 * The controller directory is resolved from __DIR__ rather than by a path relative to this file,
 * so moving the file cannot silently produce an empty route collection.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__.'/../src/Infrastructure/Sulu/Controller/', 'attribute');
};
