<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;

#[AsFormFieldType(key: 'text', title: 'eekes_form_builder.field_type.text', icon: 'su-pen', group: 'input', position: 10)]
final class TextFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return TextType::class;
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        $constraints = parent::getConstraints($field);
        $min = $field->intOption('minLength');
        $max = $field->intOption('maxLength');
        $min = $min !== null && $min > 0 ? $min : null;
        $max = $max !== null && $max > 0 ? $max : null;

        if ($min !== null || $max !== null) {
            $constraints[] = new Length(min: $min, max: $max);
        }

        return $constraints;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $max = $field->intOption('maxLength');

        return $max === null ? [] : ['attr' => array_filter(['placeholder' => $field->placeholder, 'maxlength' => $max])];
    }
}
