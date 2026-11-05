<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Twig;

use Eekes\Sulu\FormBuilderBundle\Application\Rendering\FormRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ eekes_form('contact') }} renders a form by key, id or resolved definition;
 * eekes_form_definition() gives the definition itself, for a template that renders the form its
 * own way.
 */
final class FormBuilderExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('eekes_form', [FormRenderer::class, 'render'], ['is_safe' => ['html']]),
            new TwigFunction('eekes_form_definition', [FormRenderer::class, 'definition']),
        ];
    }
}
