<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\AsFormIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationMetadata;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every integration - the bundle's webhook and those of other packages or the
 * application - into the registry, as a service locator so only the integrations a run needs
 * are built.
 */
final class IntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(IntegrationRegistry::class)) {
            return;
        }

        $services = [];
        $metadata = [];

        foreach ($container->findTaggedServiceIds(AsFormIntegration::TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                if (!\is_array($tag) || !\is_string($tag['key'] ?? null) || $tag['key'] === '') {
                    throw new \InvalidArgumentException(\sprintf('The service "%s" is tagged "%s" without a "key".', $id, AsFormIntegration::TAG));
                }

                $key = $tag['key'];

                if (isset($services[$key])) {
                    throw new \InvalidArgumentException(\sprintf('The integration "%s" is registered twice, by "%s" and "%s".', $key, (string) $services[$key], $id));
                }

                $services[$key] = new Reference($id);
                $metadata[$key] = new Definition(IntegrationMetadata::class, [
                    $key,
                    \is_string($tag['title'] ?? null) ? $tag['title'] : $key,
                    \is_string($tag['icon'] ?? null) ? $tag['icon'] : 'su-share',
                ]);
            }
        }

        $container->getDefinition(IntegrationRegistry::class)
            ->setArgument('$integrations', ServiceLocatorTagPass::register($container, $services))
            ->setArgument('$metadata', $metadata);
    }
}
