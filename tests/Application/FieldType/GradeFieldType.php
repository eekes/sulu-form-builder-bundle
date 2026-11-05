<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Application\FieldType;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * A field type of the application: proves that a project adds one with nothing but the attribute.
 */
#[AsFormFieldType(key: 'grade', title: 'Grade', icon: 'su-heart', group: 'custom')]
final class GradeFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return ChoiceType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $max = $field->intOption('max', 5) ?? 5;

        return [
            'choices' => array_combine(range(1, $max), range(1, $max)),
            'expanded' => true,
        ];
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        return is_numeric($value) ? str_repeat('*', (int) $value) : '';
    }
}
