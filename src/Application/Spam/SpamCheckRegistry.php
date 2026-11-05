<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

use Psr\Container\ContainerInterface;

/**
 * Every installed spam check. Installing one is the developer's choice; which of them a form uses
 * is the editor's (see Form::spamChecks()).
 */
final readonly class SpamCheckRegistry
{
    /**
     * @param ContainerInterface $checks locator of SpamCheckInterface services by key
     * @param array<string, SpamCheckMetadata> $metadata
     */
    public function __construct(
        private ContainerInterface $checks,
        private array $metadata,
    ) {
    }

    public function has(string $key): bool
    {
        return isset($this->metadata[$key]) && $this->checks->has($key);
    }

    public function get(string $key): SpamCheckInterface
    {
        if (!$this->has($key)) {
            throw new \InvalidArgumentException(\sprintf('There is no spam check "%s". Known checks are "%s".', $key, implode('", "', array_keys($this->metadata))));
        }

        $check = $this->checks->get($key);

        if (!$check instanceof SpamCheckInterface) {
            throw new \LogicException(\sprintf('The spam check "%s" does not implement %s.', $key, SpamCheckInterface::class));
        }

        return $check;
    }

    /**
     * @return list<SpamCheckMetadata>
     */
    public function all(): array
    {
        return array_values($this->metadata);
    }

    /**
     * The checks a form uses until an editor decides otherwise.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        return array_values(array_map(
            static fn (SpamCheckMetadata $metadata): string => $metadata->key,
            array_filter($this->metadata, static fn (SpamCheckMetadata $metadata): bool => $metadata->enabledByDefault),
        ));
    }

    /**
     * What a form uses: the keys it chose that are still installed, or the defaults when it never
     * chose.
     *
     * @param null|list<string> $chosen
     *
     * @return list<string>
     */
    public function resolve(?array $chosen): array
    {
        return $chosen === null ? $this->defaults() : array_values(array_filter($chosen, $this->has(...)));
    }
}
