<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content\FormResourceLoader;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content\SingleFormSelectionPropertyResolver;

/*
 * Loaded only when Sulu's content bundle is registered: the single_eekes_form_selection property
 * type for page templates, blocks and snippets.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SingleFormSelectionPropertyResolver::class)
        ->tag('sulu_content.property_resolver');

    $services->set(FormResourceLoader::class)
        ->args([service(FormDefinitionProvider::class)])
        ->tag('sulu_content.resource_loader', ['key' => FormResourceLoader::RESOURCE_LOADER_KEY]);
};
