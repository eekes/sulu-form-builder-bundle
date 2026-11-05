<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Helps an integration's bundle tell where its configuration comes from, for the requirements it
 * reports (see IntegrationRequirement::config()).
 */
final class IntegrationConfig
{
    /**
     * The environment variable a configured value comes from: "TEAMLEADER_CLIENT_ID" for
     * '%env(TEAMLEADER_CLIENT_ID)%', also behind processors like '%env(trim:...)%'. Null when the
     * value is written in the configuration itself. Call it while the container is built, e.g. in
     * AbstractBundle::loadExtension().
     */
    public static function envName(ContainerBuilder $builder, mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $used = [];
        $builder->resolveEnvPlaceholders($value, null, $used);

        foreach (\is_array($used) ? array_keys($used) : [] as $name) {
            if (!\is_string($name)) {
                continue;
            }

            // "trim:TEAMLEADER_CLIENT_ID" or "default::TEAMLEADER_CLIENT_ID": the variable is the last part.
            $parts = explode(':', $name);
            $last = end($parts);

            return $last !== '' ? $last : null;
        }

        return null;
    }
}
