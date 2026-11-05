<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * Every registered integration, and which of them a developer enabled. An integration that is
 * registered but not enabled does not exist as far as editors are concerned: it is not offered,
 * and flows that use it do not run.
 */
final readonly class IntegrationRegistry
{
    /**
     * @param ContainerInterface $integrations locator of IntegrationInterface services by key
     * @param array<string, IntegrationMetadata> $metadata
     * @param list<string> $enabled
     */
    public function __construct(
        private ContainerInterface $integrations,
        private array $metadata,
        private array $enabled,
    ) {
    }

    public function isEnabled(string $key): bool
    {
        return \in_array($key, $this->enabled, true) && isset($this->metadata[$key]) && $this->integrations->has($key);
    }

    public function get(string $key): IntegrationInterface
    {
        if (!$this->isEnabled($key)) {
            throw new \InvalidArgumentException(\sprintf('The integration "%s" is not enabled. Enabled integrations are "%s".', $key, implode('", "', $this->enabledKeys())));
        }

        $integration = $this->integrations->get($key);

        if (!$integration instanceof IntegrationInterface) {
            throw new \LogicException(\sprintf('The integration "%s" does not implement %s.', $key, IntegrationInterface::class));
        }

        return $integration;
    }

    public function action(string $integration, string $action): ?IntegrationAction
    {
        if (!$this->isEnabled($integration)) {
            return null;
        }

        foreach ($this->get($integration)->getActions() as $candidate) {
            if ($candidate->key === $action) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<IntegrationMetadata>
     */
    public function enabled(): array
    {
        return array_map(fn (string $key): IntegrationMetadata => $this->metadata[$key], $this->enabledKeys());
    }

    /**
     * @return list<string>
     */
    private function enabledKeys(): array
    {
        return array_values(array_filter($this->enabled, fn (string $key): bool => isset($this->metadata[$key]) && $this->integrations->has($key)));
    }

    /**
     * Whether an integration can run. An integration that does not report a status is ready; one
     * whose status cannot be read is not, with the reason.
     */
    public function status(string $key): IntegrationStatus
    {
        try {
            $integration = $this->get($key);

            return $integration instanceof StatusAwareIntegrationInterface ? $integration->getStatus() : IntegrationStatus::ready();
        } catch (EnvNotFoundException $exception) {
            // '%env(X)%' with X not defined at all: the service cannot even be built.
            $env = preg_match('/"([^"]+)"/', $exception->getMessage(), $match) === 1 ? $match[1] : '?';

            return IntegrationStatus::actionRequired('eekes_form_builder.integration_not_buildable', [], [], [
                new IntegrationRequirement($env, false, 'eekes_form_builder.requirement.env_missing', ['%env%' => $env]),
            ]);
        } catch (\Throwable $exception) {
            return IntegrationStatus::actionRequired($exception->getMessage());
        }
    }

    /**
     * The integration, or null when it cannot be built - typically an environment variable its
     * configuration needs is not defined. status() says why.
     */
    public function find(string $key): ?IntegrationInterface
    {
        try {
            return $this->get($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
