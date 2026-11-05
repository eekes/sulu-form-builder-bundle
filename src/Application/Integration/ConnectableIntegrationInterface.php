<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * An integration that is connected to an account through OAuth from the admin: "Connect" in
 * Settings → Form builder integrations sends the user to the service, which sends them back to the admin
 * with a code. The client id and secret stay in configuration; only the approval happens here.
 *
 * The bundle provides the redirect URI (an admin route, behind the admin login) and checks the
 * state parameter, so an integration only builds the URL and trades the code for tokens.
 */
interface ConnectableIntegrationInterface extends StatusAwareIntegrationInterface
{
    /**
     * Where to send the user to approve the connection.
     *
     * @throws IntegrationException when the integration is not configured well enough to connect
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string;

    /**
     * Completes the connection with what the service put in the redirect URL (usually "code").
     *
     * @param array<string, string> $query the query parameters of the redirect
     * @param null|string $connectedBy who connected it, for the status
     *
     * @throws IntegrationException when the service refused
     */
    public function connect(array $query, string $redirectUri, ?string $connectedBy = null): void;

    public function isConnected(): bool;

    /**
     * Forgets the connection: flows of this integration fail until it is connected again.
     */
    public function disconnect(): void;
}
