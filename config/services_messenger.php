<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\Message\ExecuteIntegrationRunHandler;

// Loaded when symfony/messenger is installed: the handler that executes integration runs.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ExecuteIntegrationRunHandler::class)
        ->autowire()
        ->tag('messenger.message_handler');
};
