<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration\Webhook;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\AsFormIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationAction;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationException;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationResult;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationStatus;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Setting;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\StatusAwareIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TestableIntegrationInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts a submission as JSON to an endpoint a developer configured - enough for Zapier, Make,
 * n8n or an own API.
 *
 * Editors pick an endpoint by name and never type a URL: a URL in the admin would let anyone with
 * access to a form send its submissions anywhere. An endpoint with a secret gets an
 * X-Eekes-Signature header (sha256 HMAC of the body), so the receiver can check where it came from.
 */
#[AsFormIntegration(key: 'webhook', title: 'eekes_form_builder.integration.webhook', icon: 'su-link2')]
final readonly class WebhookIntegration implements StatusAwareIntegrationInterface, TestableIntegrationInterface
{
    public const string ACTION_SEND = 'send';

    /**
     * @param array<string, array{url: string, secret: null|string}> $endpoints by name
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private array $endpoints,
        private float $timeout = 10.0,
    ) {
    }

    public function getActions(): array
    {
        return [
            new IntegrationAction(
                key: self::ACTION_SEND,
                title: 'eekes_form_builder.integration.webhook_send',
                settings: [
                    new Setting('endpoint', 'eekes_form_builder.integration.webhook_endpoint', Setting::TYPE_SELECT, required: true, dynamicOptions: true),
                    new Setting('includeAnswers', 'eekes_form_builder.integration.webhook_include_answers', Setting::TYPE_CHECKBOX, default: true),
                ],
                allowCustomFields: true,
                description: 'eekes_form_builder.integration.webhook_description',
            ),
        ];
    }

    public function getOptions(string $action, string $setting): array
    {
        return array_map(static fn (string $name): array => ['value' => $name, 'label' => $name], array_keys($this->endpoints));
    }

    public function execute(IntegrationRequest $request): IntegrationResult
    {
        $name = $request->stringSetting('endpoint') ?? '';
        $endpoint = $this->endpoints[$name] ?? throw new IntegrationException(\sprintf('The webhook endpoint "%s" is not configured.', $name));
        $submission = $request->submission;

        $payload = [
            'form' => ['key' => $submission->form->key, 'title' => $submission->form->title],
            'submission' => [
                'id' => $submission->isPersisted() ? $submission->id : null,
                'locale' => $submission->locale,
                'url' => $submission->url,
                'createdAt' => $submission->createdAt->format(\DATE_ATOM),
            ],
            'fields' => $request->values,
        ];

        if ($request->boolSetting('includeAnswers', true)) {
            $payload['answers'] = $submission->toArray();
        }

        [$status, $logged] = $this->post($endpoint, $payload);

        if ($status >= 300) {
            // A server error or "too many requests" may pass later; anything else is a refusal.
            return IntegrationResult::failure(\sprintf('The webhook "%s" answered %d.', $name, $status), $payload, $logged, $status >= 500 || $status === 429);
        }

        return IntegrationResult::success(\sprintf('Sent to "%s".', $name), $payload, $logged);
    }

    /**
     * Posts a payload marked as a test ("test": true) to every endpoint, so the receiving side can
     * recognise it and leave it alone.
     */
    public function testConnection(): IntegrationStatus
    {
        if ($this->endpoints === []) {
            return $this->getStatus();
        }

        $details = [];
        $failed = [];

        foreach ($this->endpoints as $name => $endpoint) {
            try {
                [$status] = $this->post($endpoint, ['test' => true, 'message' => 'A test from the Sulu Form Builder. It is not a submission.']);
                $details[$name] = (string) $status;

                if ($status >= 300) {
                    $failed[] = $name;
                }
            } catch (IntegrationException $exception) {
                $details[$name] = $exception->getMessage();
                $failed[] = $name;
            }
        }

        return $failed === []
            ? IntegrationStatus::ready('eekes_form_builder.webhook.test_passed', $details)
            : IntegrationStatus::actionRequired('eekes_form_builder.webhook.test_failed', [], $details);
    }

    public function testSendsData(): bool
    {
        return true;
    }

    /**
     * @param array{url: string, secret: null|string} $endpoint
     * @param array<string, mixed> $payload
     *
     * @return array{int, array{status: int, body: string}}
     */
    private function post(array $endpoint, array $payload): array
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json'];

        if ($endpoint['secret'] !== null && $endpoint['secret'] !== '') {
            $headers['X-Eekes-Signature'] = 'sha256='.hash_hmac('sha256', $body, $endpoint['secret']);
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint['url'], ['headers' => $headers, 'body' => $body, 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new IntegrationException('The webhook could not be reached: '.$exception->getMessage(), $payload, null, $exception, retryable: true);
        }

        return [$status, ['status' => $status, 'body' => mb_substr($content, 0, 5000)]];
    }

    public function getStatus(): IntegrationStatus
    {
        if ($this->endpoints === []) {
            return IntegrationStatus::actionRequired('eekes_form_builder.webhook.no_endpoints', ['eekes_form_builder.webhook.no_endpoints_step']);
        }

        return IntegrationStatus::ready(null, ['eekes_form_builder.webhook.endpoints' => implode(', ', array_keys($this->endpoints))]);
    }
}
