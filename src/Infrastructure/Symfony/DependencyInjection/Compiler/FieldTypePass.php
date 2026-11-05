<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeMetadata;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every field type - the bundle's and the application's - into the registry: a service
 * locator so a type is only built when a form uses it, and the palette metadata as plain value
 * objects so listing the types builds none of them.
 */
final class FieldTypePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FieldTypeRegistry::class)) {
            return;
        }

        $services = [];
        $metadata = [];

        foreach ($container->findTaggedServiceIds(AsFormFieldType::TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }

                $key = $tag['key'] ?? null;

                if (!\is_string($key) || $key === '') {
                    throw new \InvalidArgumentException(\sprintf('The service "%s" is tagged "%s" without a "key".', $id, AsFormFieldType::TAG));
                }

                if (isset($services[$key])) {
                    throw new \InvalidArgumentException(\sprintf('The field type "%s" is registered twice, by "%s" and "%s".', $key, (string) $services[$key], $id));
                }

                $services[$key] = new Reference($id);
                $metadata[$key] = new Definition(FieldTypeMetadata::class, [
                    $key,
                    self::string($tag, 'title', $key),
                    self::string($tag, 'icon', 'su-pen'),
                    self::string($tag, 'group', 'input'),
                    \is_int($tag['position'] ?? null) ? $tag['position'] : 0,
                    self::string($tag, 'admin_form_key', FieldTypeMetadata::defaultAdminFormKey($key)),
                ]);
            }
        }

        $container->getDefinition(FieldTypeRegistry::class)
            ->setArgument('$fieldTypes', ServiceLocatorTagPass::register($container, $services))
            ->setArgument('$metadata', $metadata);
    }

    /**
     * @param array<mixed> $tag
     */
    private static function string(array $tag, string $name, string $default): string
    {
        $value = $tag[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
