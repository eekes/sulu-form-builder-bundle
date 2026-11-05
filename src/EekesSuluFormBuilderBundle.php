<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler\FieldTypePass;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler\IntegrationPass;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler\SpamCheckPass;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\EekesSuluFormBuilderExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The bundle follows the directory layout Symfony recommends for new bundles: src/ holds nothing
 * but PHP, and config/, templates/, translations/, assets/ and public/ sit next to it at the root.
 * getPath() is what makes those discoverable - the '@EekesSuluFormBuilder' template namespace,
 * the routing file a project imports, the translations and assets:install all hang off it.
 */
class EekesSuluFormBuilderBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new FieldTypePass());
        $container->addCompilerPass(new IntegrationPass());
        $container->addCompilerPass(new SpamCheckPass());
    }

    /**
     * The container extension is Symfony glue like everything else under Infrastructure, so it does
     * not sit in the DependencyInjection/ folder the base class would look in.
     */
    public function getContainerExtension(): ExtensionInterface
    {
        return new EekesSuluFormBuilderExtension();
    }
}
