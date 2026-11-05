<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

/**
 * Registers a class as a field type editors can add in the form builder.
 *
 * ```php
 * #[AsFormFieldType(key: 'iban', title: 'app.form_field.iban', icon: 'su-euro')]
 * final class IbanFieldType extends AbstractFieldType { ... }
 * ```
 *
 * The settings editors fill in for a field of this type come from a Sulu form XML with the key in
 * $adminFormKey, by default "eekes_form_builder_field_<key>". Put it in any directory registered
 * under sulu_admin.forms.directories; see docs/field-types.md.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsFormFieldType
{
    public const string TAG = 'eekes_form_builder.field_type';

    /**
     * @param string $title translation key in the "admin" domain, or literal text
     * @param string $icon a Sulu icon name, shown in the builder palette
     * @param string $group "input", "choice" or "content", or any group of your own
     * @param int $position order within the group in the palette
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $icon = 'su-pen',
        public string $group = 'input',
        public int $position = 0,
        public ?string $adminFormKey = null,
    ) {
    }

    /**
     * @return array{key: string, title: string, icon: string, group: string, position: int, admin_form_key: string}
     */
    public function toTagAttributes(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'icon' => $this->icon,
            'group' => $this->group,
            'position' => $this->position,
            'admin_form_key' => $this->adminFormKey ?? FieldTypeMetadata::defaultAdminFormKey($this->key),
        ];
    }
}
