<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * Whether an integration can run, as Settings → Form builder integrations and the flow modal show it.
 *
 * Every text is a translation key in the "admin" domain, or literal text - like the titles of
 * actions and settings. Never put a secret in it: it is shown to editors.
 */
final readonly class IntegrationStatus
{
    public const string READY = 'ready';
    public const string ACTION_REQUIRED = 'action_required';

    /**
     * @param list<string> $steps what a developer or administrator still has to do, in order, for
     *                            what cannot be checked (e.g. "register the redirect URI")
     * @param array<string, string> $details facts by label, e.g. "Connected as" => "Acme"
     * @param list<IntegrationRequirement> $requirements what it needs, each checked
     */
    private function __construct(
        public string $state,
        public ?string $message,
        public array $steps,
        public array $details,
        public array $requirements = [],
    ) {
    }

    /**
     * @param array<string, string> $details
     * @param list<IntegrationRequirement> $requirements
     */
    public static function ready(?string $message = null, array $details = [], array $requirements = []): self
    {
        return new self(self::READY, $message, [], $details, $requirements);
    }

    /**
     * @param string $message why it cannot run, e.g. "Teamleader is not connected yet."
     * @param list<string> $steps what to do about it that cannot be checked
     * @param array<string, string> $details
     * @param list<IntegrationRequirement> $requirements what it needs, each checked
     */
    public static function actionRequired(string $message, array $steps = [], array $details = [], array $requirements = []): self
    {
        return new self(self::ACTION_REQUIRED, $message, $steps, $details, $requirements);
    }

    /**
     * Ready when every requirement is met, otherwise action required with the message.
     *
     * @param list<IntegrationRequirement> $requirements
     * @param list<string> $steps
     * @param array<string, string> $details
     */
    public static function fromRequirements(array $requirements, string $readyMessage, string $notReadyMessage, array $steps = [], array $details = []): self
    {
        foreach ($requirements as $requirement) {
            if (!$requirement->met) {
                return self::actionRequired($notReadyMessage, $steps, $details, $requirements);
            }
        }

        return self::ready($readyMessage, $details, $requirements);
    }

    public function isReady(): bool
    {
        return $this->state === self::READY;
    }
}
