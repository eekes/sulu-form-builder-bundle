<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\AsSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckMetadata;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every spam check - the bundle's honeypot and those of other packages or the
 * application - into the registry, as a service locator so only the checks a form uses are built.
 */
final class SpamCheckPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SpamCheckRegistry::class)) {
            return;
        }

        $services = [];
        $metadata = [];

        foreach ($container->findTaggedServiceIds(AsSpamCheck::TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                if (!\is_array($tag) || !\is_string($tag['key'] ?? null) || $tag['key'] === '') {
                    throw new \InvalidArgumentException(\sprintf('The service "%s" is tagged "%s" without a "key".', $id, AsSpamCheck::TAG));
                }

                $key = $tag['key'];

                if (isset($services[$key])) {
                    throw new \InvalidArgumentException(\sprintf('The spam check "%s" is registered twice, by "%s" and "%s".', $key, (string) $services[$key], $id));
                }

                $services[$key] = new Reference($id);
                $metadata[$key] = new Definition(SpamCheckMetadata::class, [
                    $key,
                    \is_string($tag['title'] ?? null) ? $tag['title'] : $key,
                    \is_string($tag['description'] ?? null) ? $tag['description'] : null,
                    (bool) ($tag['enabledByDefault'] ?? false),
                ]);
            }
        }

        $container->getDefinition(SpamCheckRegistry::class)
            ->setArgument('$checks', ServiceLocatorTagPass::register($container, $services))
            ->setArgument('$metadata', $metadata);
    }
}
