<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\PlaceholderText;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

/**
 * Shows the placeholders in the info texts of the bundle's forms - settings, notifications, the
 * modal of every field type - as editors type them, {{ email }} (see PlaceholderText).
 */
final readonly class PlaceholderTextFormMetadataVisitor implements FormMetadataVisitorInterface
{
    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        if (!str_starts_with($formMetadata->getKey(), 'eekes_form')) {
            return;
        }

        $items = $formMetadata->getItems();

        foreach ($items as $item) {
            self::expand($item);
        }

        $formMetadata->setItems($items);
    }

    private static function expand(ItemMetadata $item): void
    {
        $item->setDescriptions(array_map(PlaceholderText::expand(...), $item->getDescriptions()));

        if ($item instanceof SectionMetadata) {
            foreach ($item->getItems() as $child) {
                self::expand($child);
            }
        }
    }
}
