<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Dispatched for every field just before it is added to its step, with the Symfony form type and
 * options the field type produced. Change them to add a constraint, prefill a value or swap the
 * type; call remove() to leave the field out.
 *
 * ```php
 * #[AsEventListener]
 * public function __invoke(FieldBuildEvent $event): void
 * {
 *     if ($event->field->key === 'campaign') {
 *         $event->setOption('data', $this->requestStack->getCurrentRequest()?->query->get('utm_campaign'));
 *     }
 * }
 * ```
 */
final class FieldBuildEvent
{
    private bool $removed = false;

    /**
     * @param class-string<FormTypeInterface<mixed>> $formType
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly FormDefinition $definition,
        public readonly FieldDefinition $field,
        private string $formType,
        private array $options,
    ) {
    }

    /**
     * @return class-string<FormTypeInterface<mixed>>
     */
    public function getFormType(): string
    {
        return $this->formType;
    }

    /**
     * @param class-string<FormTypeInterface<mixed>> $formType
     */
    public function setFormType(string $formType): void
    {
        $this->formType = $formType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function remove(): void
    {
        $this->removed = true;
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }
}
