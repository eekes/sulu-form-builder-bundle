<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * One thing an integration needs, checked: "Client ID - set TEAMLEADER_CLIENT_ID". Settings →
 * Form builder integrations shows them as a checklist, so whoever sets up the site sees exactly what is
 * missing instead of a general "not configured".
 *
 * The label and the hint are translation keys in the "admin" domain, or literal text; both get
 * the parameters, e.g. ['%env%' => 'TEAMLEADER_CLIENT_ID']. Never put a secret in them.
 */
final readonly class IntegrationRequirement
{
    /**
     * @param string $hint what to do when it is not met, or where it comes from when it is
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $label,
        public bool $met,
        public ?string $hint = null,
        public array $parameters = [],
    ) {
    }

    /**
     * A configuration value: met when it is filled in. The hint names the environment variable it
     * comes from when there is one, so the message says "TEAMLEADER_CLIENT_ID" instead of a
     * configuration path.
     *
     * @param null|string $env the environment variable behind the value, see the bundle's
     *                         IntegrationConfig::envName()
     * @param string $path the configuration path, for when no environment variable is used
     */
    public static function config(string $label, ?string $value, ?string $env, string $path): self
    {
        $met = $value !== null && $value !== '';

        if ($env !== null) {
            return new self($label, $met, $met ? 'eekes_form_builder.requirement.from_env' : 'eekes_form_builder.requirement.set_env', ['%env%' => $env]);
        }

        return new self($label, $met, $met ? null : 'eekes_form_builder.requirement.set_config', ['%path%' => $path]);
    }
}
