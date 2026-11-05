<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Twig\FormComponent;

// Loaded only when symfony/ux-twig-component is installed.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(FormComponent::class)
        ->share(false)
        ->tag('twig.component', [
            'key' => FormComponent::NAME,
            'template' => '@EekesSuluFormBuilder/components/Form.html.twig',
            'expose_public_props' => true,
            'attributes_var' => 'attributes',
        ]);
};
