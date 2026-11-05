<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Notification;

final readonly class NotificationTemplate
{
    /**
     * @param string $label translation key in the "admin" domain, or literal text
     * @param string $template a Twig template, e.g. "@EekesSuluFormBuilder/email/card.html.twig"
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $template,
    ) {
    }
}
