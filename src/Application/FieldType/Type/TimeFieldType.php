<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\TimeType;

/**
 * A time of day, stored as "H:i" and shown in the notation of the submission's language.
 */
#[AsFormFieldType(key: 'time', title: 'eekes_form_builder.field_type.time', icon: 'su-clock', group: 'input', position: 72)]
final class TimeFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return TimeType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'widget' => 'single_text',
            // A string keeps the value serialisable between the steps of a flow.
            'input' => 'string',
            'input_format' => 'H:i',
            'with_seconds' => false,
        ];
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        $time = \DateTimeImmutable::createFromFormat('!H:i', $value);

        if ($time === false) {
            return $value;
        }

        return DateFormat::format($time, $locale, 'none', 'short', 'H:i');
    }
}
