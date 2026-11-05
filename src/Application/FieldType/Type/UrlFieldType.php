<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Validator\Constraints\Url;

#[AsFormFieldType(key: 'url', title: 'eekes_form_builder.field_type.url', icon: 'su-link', group: 'input', position: 60)]
final class UrlFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return UrlType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return ['default_protocol' => 'https'];
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        return [...parent::getConstraints($field), new Url(requireTld: true)];
    }
}
