<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractContentFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;

/**
 * A heading between fields, to group them without starting a new step.
 */
#[AsFormFieldType(key: 'heading', title: 'eekes_form_builder.field_type.heading', icon: 'su-bold', group: 'content', position: 10)]
final class HeadingFieldType extends AbstractContentFieldType
{
    private const array LEVELS = ['h2', 'h3', 'h4', 'h5', 'h6'];

    protected function getHtml(FieldDefinition $field): string
    {
        $level = $field->stringOption('level', 'h3');
        $level = \in_array($level, self::LEVELS, true) ? $level : 'h3';

        return \sprintf('<%1$s class="eekes-form-heading">%2$s</%1$s>', $level, htmlspecialchars($field->label, \ENT_QUOTES));
    }
}
