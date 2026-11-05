<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

/**
 * A date with a time of day, stored as "Y-m-d H:i" and shown in the notation of the submission's
 * language. No time zone is converted: the visitor's answer is stored as they typed it.
 */
#[AsFormFieldType(key: 'datetime', title: 'eekes_form_builder.field_type.datetime', icon: 'su-calendar', group: 'input', position: 74)]
final class DateTimeFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return DateTimeType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'widget' => 'single_text',
            // A string keeps the value serialisable between the steps of a flow.
            'input' => 'string',
            'input_format' => 'Y-m-d H:i',
            'with_seconds' => false,
        ];
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);

        if ($dateTime === false) {
            return $value;
        }

        return DateFormat::format($dateTime, $locale, 'medium', 'short', 'Y-m-d H:i');
    }
}
