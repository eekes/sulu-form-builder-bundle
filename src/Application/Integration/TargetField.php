<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * A value an action needs. A required one has to be mapped before a flow can be saved, and a run
 * without a value for it fails with a clear message instead of calling the remote service.
 */
final readonly class TargetField
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_EMAIL = 'email';
    public const string TYPE_PHONE = 'phone';
    public const string TYPE_BOOLEAN = 'boolean';
    public const string TYPE_NUMBER = 'number';

    /**
     * @param string $label translation key in the "admin" domain, or literal text
     * @param string $type a hint for the editor (which answers fit), not enforced
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public string $type = self::TYPE_TEXT,
        public ?string $help = null,
    ) {
    }
}
