<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints\Length;

#[AsFormFieldType(key: 'textarea', title: 'eekes_form_builder.field_type.textarea', icon: 'su-align-justify', group: 'input', position: 30)]
final class TextareaFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return TextareaType::class;
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        $constraints = parent::getConstraints($field);
        $max = $field->intOption('maxLength');

        if ($max !== null && $max > 0) {
            $constraints[] = new Length(max: $max);
        }

        return $constraints;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'attr' => array_filter([
                'placeholder' => $field->placeholder,
                'rows' => $field->intOption('rows', 5),
                'maxlength' => $field->intOption('maxLength'),
            ]),
        ];
    }
}
