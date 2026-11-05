<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * One field in one language: what a field type, an event listener or a template works with.
 *
 * The localized options of the field type are already merged into $options, so code reading an
 * option never has to know whether it is translated.
 */
final readonly class FieldDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public ?string $placeholder = null,
        public ?string $help = null,
        public bool $required = false,
        public ColumnWidths $width = new ColumnWidths(),
        public bool $showInList = false,
        public ?ConditionSet $visibleIf = null,
        public array $options = [],
        public string $stepKey = 'step1',
        public ?ConditionSet $requiredIf = null,
    ) {
    }

    /**
     * The same field, required or not: how a conditional requirement is applied once its
     * conditions are known.
     */
    public function withRequired(bool $required): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->label,
            $this->placeholder,
            $this->help,
            $required,
            $this->width,
            $this->showInList,
            $this->visibleIf,
            $this->options,
            $this->stepKey,
            $this->requiredIf,
        );
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function stringOption(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        if (\is_string($value) && $value !== '') {
            return $value;
        }

        return is_numeric($value) ? (string) $value : $default;
    }

    public function intOption(string $name, ?int $default = null): ?int
    {
        $value = $this->options[$name] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolOption(string $name, bool $default = false): bool
    {
        $value = $this->options[$name] ?? null;

        return $value === null ? $default : (bool) $value;
    }

    /**
     * The label, or the key when the editor left it empty, so a submission never shows a column
     * without a name.
     */
    public function displayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->key;
    }
}
