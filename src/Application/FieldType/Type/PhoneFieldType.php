<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Validator\Constraints\Regex;

#[AsFormFieldType(key: 'phone', title: 'eekes_form_builder.field_type.phone', icon: 'su-phone', group: 'input', position: 50)]
final class PhoneFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return TelType::class;
    }

    /**
     * Deliberately loose: digits, spaces and the usual separators. Phone formats differ too much
     * per country for anything stricter to help more than it hurts.
     */
    protected function getConstraints(FieldDefinition $field): array
    {
        return [...parent::getConstraints($field), new Regex(pattern: '/^\+?[0-9\s().\/-]{6,}$/')];
    }
}
