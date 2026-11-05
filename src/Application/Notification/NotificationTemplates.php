<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Notification;

/**
 * The e-mail templates editors can pick per notification: the three of the bundle and those a
 * project configures under eekes_sulu_form_builder.notifications.templates.
 */
final readonly class NotificationTemplates
{
    public const string CARD = 'card';
    public const string PLAIN = 'plain';
    public const string SUMMARY = 'summary';

    private const array BUILT_IN = [
        self::CARD => ['label' => 'eekes_form_builder.email_template.card', 'template' => '@EekesSuluFormBuilder/email/card.html.twig'],
        self::PLAIN => ['label' => 'eekes_form_builder.email_template.plain', 'template' => '@EekesSuluFormBuilder/email/plain.html.twig'],
        self::SUMMARY => ['label' => 'eekes_form_builder.email_template.summary', 'template' => '@EekesSuluFormBuilder/email/summary.html.twig'],
    ];

    /**
     * @var array<string, NotificationTemplate>
     */
    private array $templates;

    /**
     * @param array<string, array{label: string, template: string}> $custom
     */
    public function __construct(
        array $custom = [],
        private string $defaultKey = self::CARD,
    ) {
        $templates = [];

        foreach ([...self::BUILT_IN, ...$custom] as $key => $template) {
            $templates[$key] = new NotificationTemplate($key, $template['label'], $template['template']);
        }

        $this->templates = $templates;
    }

    /**
     * @return list<NotificationTemplate>
     */
    public function all(): array
    {
        return array_values($this->templates);
    }

    public function defaultKey(): string
    {
        return isset($this->templates[$this->defaultKey]) ? $this->defaultKey : self::CARD;
    }

    /**
     * The template of a notification; one it picked that no longer exists falls back to the default.
     */
    public function get(?string $key): NotificationTemplate
    {
        return $this->templates[$key ?? ''] ?? $this->templates[$this->defaultKey()];
    }
}
