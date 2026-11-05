<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints\Email;

#[AsFormFieldType(key: 'email', title: 'eekes_form_builder.field_type.email', icon: 'su-envelope', group: 'input', position: 20)]
final class EmailFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return EmailType::class;
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        return [...parent::getConstraints($field), new Email(mode: Email::VALIDATION_MODE_HTML5)];
    }
}
