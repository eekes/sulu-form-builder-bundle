<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * Something an integration can do, as the editor picks it: "Subscribe to a list", "Create a
 * ticket".
 */
final readonly class IntegrationAction
{
    /**
     * @param string $title translation key in the "admin" domain, or literal text
     * @param list<TargetField> $fields the values the action needs; the editor maps each one to an
     *                                  answer or a fixed value
     * @param list<Setting> $settings what the editor configures per flow, e.g. which list
     * @param bool $allowCustomFields whether the editor may add fields of their own besides
     *                                $fields, e.g. extra merge fields or a webhook payload
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $fields = [],
        public array $settings = [],
        public bool $allowCustomFields = false,
        public ?string $description = null,
    ) {
    }

    public function field(string $key): ?TargetField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function setting(string $key): ?Setting
    {
        foreach ($this->settings as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        return null;
    }
}
