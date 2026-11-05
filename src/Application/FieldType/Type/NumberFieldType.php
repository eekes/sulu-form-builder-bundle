<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints\Range;

#[AsFormFieldType(key: 'number', title: 'eekes_form_builder.field_type.number', icon: 'su-percent', group: 'input', position: 40)]
final class NumberFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return NumberType::class;
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        $constraints = parent::getConstraints($field);
        $min = $this->bound($field, 'min');
        $max = $this->bound($field, 'max');

        if ($min !== null || $max !== null) {
            $constraints[] = new Range(min: $min, max: $max);
        }

        return $constraints;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'html5' => true,
            'scale' => $field->intOption('decimals', 0),
            'attr' => array_filter([
                'placeholder' => $field->placeholder,
                'min' => $this->bound($field, 'min'),
                'max' => $this->bound($field, 'max'),
                'step' => $field->intOption('decimals', 0) > 0 ? 'any' : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        if (!is_numeric($data)) {
            return null;
        }

        return $field->intOption('decimals', 0) > 0 ? (float) $data : (int) $data;
    }

    private function bound(FieldDefinition $field, string $name): int|float|null
    {
        $value = $field->option($name);

        if (!is_numeric($value)) {
            return null;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }
}
