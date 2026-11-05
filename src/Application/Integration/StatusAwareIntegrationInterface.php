<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * An integration that can tell whether it is ready to run: configured, connected, reachable.
 * Settings → Form builder integrations shows the status with the steps that are still needed, and the
 * flow modal warns an editor who picks an integration that is not ready.
 *
 * An integration without this interface is always considered ready.
 */
interface StatusAwareIntegrationInterface extends IntegrationInterface
{
    /**
     * Called whenever the admin shows the status, so keep it cheap: read what is configured and
     * stored rather than calling the remote service.
     */
    public function getStatus(): IntegrationStatus;
}
