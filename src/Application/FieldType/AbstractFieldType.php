<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The common ground of every input field: label, help text, placeholder and "required" are
 * handled here, so a field type only adds what is specific to it.
 *
 * Override getTypeOptions() and getConstraints() to add to the defaults; override
 * getFormOptions() only to replace them.
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function getFormOptions(FieldDefinition $field): array
    {
        $attr = [];

        if ($field->placeholder !== null && $field->placeholder !== '') {
            $attr['placeholder'] = $field->placeholder;
        }

        return array_replace([
            'label' => $field->label !== '' ? $field->label : false,
            'required' => $field->required,
            'help' => $field->help !== '' ? $field->help : null,
            'attr' => $attr,
            'constraints' => $this->getConstraints($field),
            // Labels are already in the language of the visitor: editors typed them that way.
            'translation_domain' => false,
        ], $this->getTypeOptions($field));
    }

    public function isInput(): bool
    {
        return true;
    }

    public function getLocalizedOptions(): array
    {
        return [];
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        return self::normalizeScalar($data);
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Options specific to the type, merged over the common ones.
     *
     * @return array<string, mixed>
     */
    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [];
    }

    /**
     * @return list<Constraint>
     */
    protected function getConstraints(FieldDefinition $field): array
    {
        return $field->required ? [new NotBlank()] : [];
    }

    protected static function normalizeScalar(mixed $data): mixed
    {
        if ($data instanceof \DateTimeInterface) {
            return $data->format('Y-m-d');
        }

        if ($data instanceof \Stringable) {
            return (string) $data;
        }

        if ($data instanceof \BackedEnum) {
            return $data->value;
        }

        if (\is_array($data)) {
            return array_values(array_map(static fn (mixed $item): mixed => is_scalar($item) ? $item : null, $data));
        }

        return is_scalar($data) || $data === null ? $data : null;
    }
}
