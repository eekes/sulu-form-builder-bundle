<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\ConnectableIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequirement;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationStatus;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TestableIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationCheck;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationCheckRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Form builder integrations: the status of every enabled integration, with the steps that are
 * still needed, and connecting or disconnecting an account through OAuth.
 *
 * Connecting is a round trip through the service: "connect" stores a random state in the session
 * and answers with the URL to send the user to; the service sends them back to the callback, which
 * checks the state, lets the integration trade the code for tokens, and returns to the admin.
 */
final class IntegrationConnectionController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_integration_connections';
    public const string CALLBACK_ROUTE = 'eekes_form_builder.integration_connection_callback';
    public const string RESOURCE_KEY = 'eekes_form_integration_connections';

    private const string STATE_SESSION_KEY = 'eekes_form_builder.integration_state.';
    private const string RESULT_SESSION_KEY = 'eekes_form_builder.integration_result';

    public function __construct(
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly TranslatorInterface $translator,
        private readonly IntegrationCheckRepository $integrationCheckRepository,
    ) {
    }

    public function getSecurityContext(): string
    {
        return FormAdmin::INTEGRATIONS_SECURITY_CONTEXT;
    }

    #[Route(path: '/form-integration-connections', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $locale = $request->query->getString('userLocale') ?: null;
        $connections = array_map(
            fn (string $key): array => $this->connection($key, $locale),
            array_map(static fn ($metadata): string => $metadata->key, $this->integrationRegistry->enabled()),
        );

        // What the last round trip to a service came back with, shown once.
        $result = $request->hasSession() ? $request->getSession()->remove(self::RESULT_SESSION_KEY) : null;

        return $this->json([
            '_embedded' => [self::RESOURCE_KEY => $connections],
            'total' => \count($connections),
            'result' => \is_array($result) ? $result : null,
        ]);
    }

    /**
     * "connect" answers with the URL to send the user to; "disconnect" forgets the account; "test"
     * asks the service whether it really works, and keeps the outcome.
     */
    #[Route(path: '/form-integration-connections/{key}', name: 'eekes_form_builder.post_integration_connection_trigger', methods: ['POST'])]
    public function postTrigger(string $key, Request $request): Response
    {
        if ($request->query->getString('action') === 'test') {
            return $this->json($this->test($key, $request->query->getString('userLocale') ?: null));
        }

        $integration = $this->connectable($key);

        switch ($request->query->getString('action')) {
            case 'connect':
                $state = bin2hex(random_bytes(16));
                $request->getSession()->set(self::STATE_SESSION_KEY.$key, $state);

                try {
                    return $this->json(['url' => $integration->getAuthorizationUrl($this->redirectUri($key), $state)]);
                } catch (\Throwable $exception) {
                    return $this->json(['title' => $exception->getMessage(), 'detail' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
                }

            case 'disconnect':
                $integration->disconnect();

                return $this->json($this->connection($key, $request->query->getString('userLocale') ?: null));
            default:
                throw new BadRequestHttpException(\sprintf('Unknown action "%s".', $request->query->getString('action')));
        }
    }

    /**
     * Where the service sends the user back to. It is an admin route, so the user is still logged
     * in, and it only completes a connection the same session started.
     */
    #[Route(path: '/form-integration-connections/{key}/callback', name: self::CALLBACK_ROUTE, methods: ['GET'])]
    public function callback(string $key, Request $request): Response
    {
        $integration = $this->connectable($key);
        $session = $request->getSession();
        $expected = $session->remove(self::STATE_SESSION_KEY.$key);
        $query = array_filter($request->query->all(), 'is_string');

        try {
            if (!\is_string($expected) || !hash_equals($expected, $query['state'] ?? '')) {
                throw new \RuntimeException('The connection was not started from this admin session. Start it again from Settings → Form builder integrations.');
            }

            if (isset($query['error'])) {
                throw new \RuntimeException(\sprintf('The service did not approve the connection: %s', $query['error_description'] ?? $query['error']));
            }

            $integration->connect($query, $this->redirectUri($key), $this->getUser()?->getUserIdentifier());
            $session->set(self::RESULT_SESSION_KEY, ['key' => $key, 'success' => true, 'message' => null]);
        } catch (\Throwable $exception) {
            $session->set(self::RESULT_SESSION_KEY, ['key' => $key, 'success' => false, 'message' => $exception->getMessage()]);
        }

        return new RedirectResponse($this->generateUrl('sulu_admin').'#/'.FormAdmin::INTEGRATIONS_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function test(string $key, ?string $locale): array
    {
        $integration = $this->integrationRegistry->isEnabled($key) ? $this->integrationRegistry->find($key) : null;

        if (!$integration instanceof TestableIntegrationInterface) {
            throw new NotFoundHttpException(\sprintf('The integration "%s" is not enabled or cannot be tested.', $key));
        }

        try {
            $outcome = $integration->testConnection();
        } catch (\Throwable $exception) {
            $outcome = IntegrationStatus::actionRequired($exception->getMessage());
        }

        $check = $this->integrationCheckRepository->findByIntegration($key) ?? new IntegrationCheck($key);
        // Stored translated: the last test reads the same for everybody, also after a deploy changed the texts.
        $check->record(
            $outcome->isReady(),
            $outcome->message !== null ? $this->trans($outcome->message, $locale) : null,
            $this->translatedDetails($outcome->details, $locale),
            $this->getUser()?->getUserIdentifier(),
        );
        $this->integrationCheckRepository->save($check);

        return $this->connection($key, $locale);
    }

    /**
     * @param array<string, string> $details
     *
     * @return array<string, string>
     */
    private function translatedDetails(array $details, ?string $locale): array
    {
        $translated = [];

        foreach ($details as $label => $value) {
            $translated[$this->trans($label, $locale)] = $value;
        }

        return $translated;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(string $key, ?string $locale): array
    {
        $metadata = null;

        foreach ($this->integrationRegistry->enabled() as $candidate) {
            if ($candidate->key === $key) {
                $metadata = $candidate;
            }
        }

        // Null when it cannot be built (an environment variable is missing): the status says why.
        $integration = $this->integrationRegistry->find($key);
        $status = $this->integrationRegistry->status($key);
        $connectable = $integration instanceof ConnectableIntegrationInterface;

        return [
            'key' => $key,
            'title' => $this->trans($metadata->title ?? $key, $locale),
            'icon' => $metadata->icon ?? 'su-share',
            'state' => $status->state,
            'ready' => $status->isReady(),
            'message' => $status->message !== null ? $this->trans($status->message, $locale) : null,
            'steps' => array_map(fn (string $step): string => $this->trans($step, $locale), $status->steps),
            'requirements' => array_map(fn (IntegrationRequirement $requirement): array => [
                'label' => $this->translator->trans($requirement->label, $requirement->parameters, 'admin', $locale),
                'met' => $requirement->met,
                'hint' => $requirement->hint !== null ? $this->translator->trans($requirement->hint, $requirement->parameters, 'admin', $locale) : null,
            ], $status->requirements),
            'details' => array_map(
                fn (string $label, string $value): array => ['label' => $this->trans($label, $locale), 'value' => $value],
                array_keys($status->details),
                array_values($status->details),
            ),
            'connectable' => $connectable,
            'connected' => $connectable && $integration->isConnected(),
            // The address to register as redirect URI with the service, for a connectable one.
            'redirectUri' => $connectable ? $this->redirectUri($key) : null,
            'testable' => $integration instanceof TestableIntegrationInterface,
            'testSendsData' => $integration instanceof TestableIntegrationInterface && $integration->testSendsData(),
            'lastTest' => $this->lastTest($key),
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function lastTest(string $key): ?array
    {
        $check = $this->integrationCheckRepository->findByIntegration($key);

        return $check === null ? null : [
            'testedAt' => $check->testedAt->format(\DATE_ATOM),
            'testedBy' => $check->testedBy,
            'passed' => $check->passed,
            'message' => $check->message,
            'details' => array_map(
                static fn (string $label, string $value): array => ['label' => $label, 'value' => $value],
                array_keys($check->details),
                array_values($check->details),
            ),
        ];
    }

    private function connectable(string $key): ConnectableIntegrationInterface
    {
        $integration = $this->integrationRegistry->isEnabled($key) ? $this->integrationRegistry->find($key) : null;

        if (!$integration instanceof ConnectableIntegrationInterface) {
            throw new NotFoundHttpException(\sprintf('The integration "%s" is not enabled or cannot be connected from the admin.', $key));
        }

        return $integration;
    }

    private function redirectUri(string $key): string
    {
        return $this->generateUrl(self::CALLBACK_ROUTE, ['key' => $key], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function trans(string $text, ?string $locale): string
    {
        return $this->translator->trans($text, [], 'admin', $locale);
    }
}
