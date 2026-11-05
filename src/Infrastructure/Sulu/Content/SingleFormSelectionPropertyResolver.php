<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;

/**
 * Lets a page template, block or snippet pick a form:
 *
 * ```xml
 * <property name="form" type="single_eekes_form_selection"/>
 * ```
 *
 * The template then receives the FormDefinition and renders it with {{ eekes_form(content.form) }}.
 * Registered as a reference, so Sulu knows which pages use which form.
 */
final class SingleFormSelectionPropertyResolver implements PropertyResolverInterface
{
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        if (!is_numeric($data)) {
            return ContentView::create(null, ['id' => null, ...$params]);
        }

        return ContentView::createResolvableWithReferences(
            (int) $data,
            FormResourceLoader::getKey(),
            Form::RESOURCE_KEY,
            ['id' => (int) $data, ...$params],
        );
    }

    public static function getType(): string
    {
        return 'single_eekes_form_selection';
    }
}
