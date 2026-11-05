<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

use Psr\Container\ContainerInterface;

/**
 * Every field type of the application, by key.
 */
final readonly class FieldTypeRegistry
{
    /**
     * @param ContainerInterface $fieldTypes locator of FieldTypeInterface services by key
     * @param array<string, FieldTypeMetadata> $metadata
     */
    public function __construct(
        private ContainerInterface $fieldTypes,
        private array $metadata,
    ) {
    }

    public function has(string $key): bool
    {
        return isset($this->metadata[$key]) && $this->fieldTypes->has($key);
    }

    public function get(string $key): FieldTypeInterface
    {
        if (!$this->has($key)) {
            throw new \InvalidArgumentException(\sprintf('The field type "%s" is not registered. Known types are "%s".', $key, implode('", "', array_keys($this->metadata))));
        }

        $fieldType = $this->fieldTypes->get($key);

        if (!$fieldType instanceof FieldTypeInterface) {
            throw new \LogicException(\sprintf('The field type "%s" does not implement %s.', $key, FieldTypeInterface::class));
        }

        return $fieldType;
    }

    public function metadata(string $key): FieldTypeMetadata
    {
        return $this->metadata[$key] ?? throw new \InvalidArgumentException(\sprintf('The field type "%s" is not registered.', $key));
    }

    /**
     * Ordered the way the palette shows them: by group, then by position.
     *
     * @return list<FieldTypeMetadata>
     */
    public function all(): array
    {
        $metadata = array_values($this->metadata);

        usort($metadata, static fn (FieldTypeMetadata $a, FieldTypeMetadata $b): int => [self::groupOrder($a->group), $a->group, $a->position, $a->key] <=> [self::groupOrder($b->group), $b->group, $b->position, $b->key]);

        return $metadata;
    }

    /**
     * The groups of the bundle first, in the order an editor reaches for them; custom groups after.
     */
    private static function groupOrder(string $group): int
    {
        return match ($group) {
            'input' => 0,
            'choice' => 1,
            'content' => 2,
            default => 3,
        };
    }
}
