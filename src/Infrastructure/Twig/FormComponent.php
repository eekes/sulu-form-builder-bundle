<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Twig;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;

/**
 * <twig:EekesFormBuilder:Form form="contact"/>, for projects that use Twig components. Only
 * registered when symfony/ux-twig-component is installed; it renders exactly what
 * {{ eekes_form('contact') }} renders.
 */
final class FormComponent
{
    public const string NAME = 'EekesFormBuilder:Form';

    /**
     * A form key, id or resolved definition (e.g. from a single_eekes_form_selection property).
     */
    public FormDefinition|string|int|null $form = null;
}
