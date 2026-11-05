<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractContentFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;

/**
 * A paragraph of rich text between fields: an explanation, a privacy notice, ...
 *
 * The HTML comes from the rich text editor of the admin and is rendered as is, like any other
 * text_editor content in Sulu.
 */
#[AsFormFieldType(key: 'text_block', title: 'eekes_form_builder.field_type.text_block', icon: 'su-align-left', group: 'content', position: 20)]
final class TextBlockFieldType extends AbstractContentFieldType
{
    public function getLocalizedOptions(): array
    {
        return ['content'];
    }

    protected function getHtml(FieldDefinition $field): string
    {
        return \sprintf('<div class="eekes-form-text">%s</div>', $field->stringOption('content', ''));
    }
}
