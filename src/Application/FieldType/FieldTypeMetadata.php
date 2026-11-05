<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

/**
 * What the builder palette shows about a field type, collected from the AsFormFieldType attribute
 * at compile time so listing the types does not instantiate every one of them.
 */
final readonly class FieldTypeMetadata
{
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
        public string $group,
        public int $position,
        public string $adminFormKey,
    ) {
    }

    public static function defaultAdminFormKey(string $key): string
    {
        return 'eekes_form_builder_field_'.$key;
    }
}
