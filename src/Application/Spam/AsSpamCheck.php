<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

/**
 * Registers a class as a spam check editors can switch on per form, in the "Spam protection"
 * section of the form's settings.
 *
 * ```php
 * #[AsSpamCheck(key: 'acme_captcha', title: 'Acme captcha')]
 * final class AcmeCaptchaCheck implements SpamCheckInterface { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsSpamCheck
{
    public const string TAG = 'eekes_form_builder.spam_check';

    /**
     * @param string $title translation key in the "admin" domain, or literal text
     * @param null|string $description translation key in the "admin" domain, or literal text;
     *                                 shown below the switch
     * @param bool $enabledByDefault whether a form uses the check until an editor decides otherwise
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description = null,
        public bool $enabledByDefault = false,
    ) {
    }
}
