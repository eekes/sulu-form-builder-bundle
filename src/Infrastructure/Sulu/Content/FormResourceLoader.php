<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;

/**
 * Loads the forms a page refers to, as definitions in the language of the page.
 */
final readonly class FormResourceLoader implements ResourceLoaderInterface
{
    public const string RESOURCE_LOADER_KEY = 'eekes_form';

    public function __construct(
        private FormDefinitionProvider $formDefinitionProvider,
    ) {
    }

    public function load(array $ids, ?string $locale, array $params = []): array
    {
        $result = [];

        foreach ($ids as $id) {
            $definition = $this->formDefinitionProvider->getById((int) $id, $locale ?? 'en');

            if ($definition !== null) {
                $result[$definition->id] = $definition;
            }
        }

        return $result;
    }

    public static function getKey(): string
    {
        return self::RESOURCE_LOADER_KEY;
    }
}
