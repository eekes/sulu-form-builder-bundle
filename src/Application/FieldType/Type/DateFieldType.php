<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\DateType;

#[AsFormFieldType(key: 'date', title: 'eekes_form_builder.field_type.date', icon: 'su-calendar', group: 'input', position: 70)]
final class DateFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return DateType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'widget' => 'single_text',
            // A string keeps the value serialisable between the steps of a flow.
            'input' => 'string',
            'input_format' => 'Y-m-d',
        ];
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false) {
            return $value;
        }

        return DateFormat::format($date, $locale, 'medium', 'none', 'Y-m-d');
    }
}
