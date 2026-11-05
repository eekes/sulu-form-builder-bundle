<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * A setting an editor fills in per flow, rendered by the admin from this description.
 */
final readonly class Setting
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_TEXTAREA = 'textarea';
    public const string TYPE_SELECT = 'select';
    public const string TYPE_CHECKBOX = 'checkbox';

    /**
     * @param string $label translation key in the "admin" domain, or literal text
     * @param list<array{value: string, label: string}> $options for a select with fixed choices
     * @param bool $dynamicOptions the choices come from IntegrationInterface::getOptions()
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TYPE_TEXT,
        public bool $required = false,
        public array $options = [],
        public bool $dynamicOptions = false,
        public ?string $help = null,
        public string|bool|null $default = null,
    ) {
    }
}
