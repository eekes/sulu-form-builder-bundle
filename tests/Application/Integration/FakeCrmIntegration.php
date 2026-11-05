<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\AsFormIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\ConnectableIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\DynamicFieldsIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationAction;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationException;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequirement;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationResult;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationStatus;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Setting;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TargetField;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TestableIntegrationInterface;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * An integration of the application, the way a project would write one for its ERP: it proves
 * the public API is enough, and it records what it was asked to do.
 */
#[AsFormIntegration(key: 'fake_crm', title: 'Fake CRM', icon: 'su-industry')]
final class FakeCrmIntegration implements ConnectableIntegrationInterface, TestableIntegrationInterface, DynamicFieldsIntegrationInterface
{
    /**
     * @var list<IntegrationRequest>
     */
    public array $requests = [];

    /**
     * "ok", "refuse" (IntegrationException), "unavailable" (a retryable IntegrationException, like
     * a 503), "timeout" (the HTTP client could not reach the service) or "crash" (any other exception).
     */
    public string $behaviour = 'ok';

    /**
     * The account it is connected to through the admin, like an OAuth integration; null when it
     * is not connected.
     */
    public ?string $account = 'Acme';

    public ?string $connectedBy = null;

    public function getActions(): array
    {
        return [
            new IntegrationAction(
                key: 'create_lead',
                title: 'Create lead',
                fields: [
                    new TargetField('email', 'E-mail', required: true, type: TargetField::TYPE_EMAIL),
                    new TargetField('name', 'Name'),
                    new TargetField('source', 'Source'),
                ],
                settings: [
                    new Setting('pipeline', 'Pipeline', Setting::TYPE_SELECT, required: true, dynamicOptions: true),
                    new Setting('notify', 'Notify sales', Setting::TYPE_CHECKBOX),
                ],
                allowCustomFields: true,
            ),
        ];
    }

    public function getOptions(string $action, string $setting): array
    {
        if ($this->behaviour === 'refuse') {
            throw new \RuntimeException('The API key is not valid.');
        }

        return [['value' => 'sales', 'label' => 'Sales'], ['value' => 'partners', 'label' => 'Partners']];
    }

    /**
     * The custom fields of a pipeline, the way a CRM lets every account define its own.
     */
    public function getDynamicFields(string $action, array $settings): array
    {
        if ($this->behaviour === 'refuse') {
            throw new \RuntimeException('The API key is not valid.');
        }

        return match ($settings['pipeline'] ?? null) {
            'sales' => [
                new TargetField('budget', 'Budget', type: TargetField::TYPE_NUMBER, help: 'In euro'),
                new TargetField('vat_number', 'VAT number', required: true),
                // Already declared by the action: the declared field wins.
                new TargetField('email', 'E-mail (custom)'),
            ],
            default => [],
        };
    }

    public function execute(IntegrationRequest $request): IntegrationResult
    {
        $this->requests[] = $request;

        return match ($this->behaviour) {
            'refuse' => throw new IntegrationException('The CRM refused the lead: duplicate e-mail.', ['email' => $request->string('email')], ['error' => 'duplicate']),
            'unavailable' => throw new IntegrationException('The CRM answered 503.', null, ['status' => 503], retryable: true),
            'timeout' => throw new TransportException('Idle timeout reached for "https://crm.example.com".'),
            'crash' => throw new \LogicException('Connection reset.'),
            default => IntegrationResult::success('Lead 42 created.', $request->values, ['id' => 42]),
        };
    }

    public function getStatus(): IntegrationStatus
    {
        return $this->account === null
            ? IntegrationStatus::actionRequired('Fake CRM is not connected yet.', ['Connect it below.'], [], [
                new IntegrationRequirement('API token', true, 'eekes_form_builder.requirement.from_env', ['%env%' => 'CRM_TOKEN']),
                new IntegrationRequirement('Account connected', false, 'Click "Connect".'),
            ])
            : IntegrationStatus::ready('Connected.', ['Connected as' => $this->account]);
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://crm.example.com/oauth?'.http_build_query(['redirect_uri' => $redirectUri, 'state' => $state]);
    }

    public function connect(array $query, string $redirectUri, ?string $connectedBy = null): void
    {
        if (($query['code'] ?? null) !== 'good') {
            throw new IntegrationException('The CRM did not accept the code.');
        }

        $this->account = 'Acme';
        $this->connectedBy = $connectedBy;
    }

    public function isConnected(): bool
    {
        return $this->account !== null;
    }

    public function disconnect(): void
    {
        $this->account = null;
    }

    public function testConnection(): IntegrationStatus
    {
        if ($this->behaviour === 'refuse') {
            throw new IntegrationException('The CRM refused the credentials.');
        }

        return IntegrationStatus::ready('The CRM answered.', ['Account' => $this->account ?? 'none']);
    }

    public function testSendsData(): bool
    {
        return false;
    }
}
