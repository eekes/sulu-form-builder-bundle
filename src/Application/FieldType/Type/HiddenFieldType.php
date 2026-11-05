<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

/**
 * A value the visitor does not see, e.g. a campaign name. A listener on FieldBuildEvent can fill
 * it from the request.
 */
#[AsFormFieldType(key: 'hidden', title: 'eekes_form_builder.field_type.hidden', icon: 'su-hide', group: 'input', position: 90)]
final class HiddenFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return HiddenType::class;
    }

    public function getFormOptions(FieldDefinition $field): array
    {
        return [
            'data' => $field->stringOption('defaultValue'),
            'required' => false,
            'translation_domain' => false,
        ];
    }
}
