<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration\FakeCrmIntegration;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Settings → Form builder integrations: the status of every integration, and connecting an account from
 * the admin the way an OAuth integration such as Teamleader does.
 */
#[CoversNothing]
final class IntegrationConnectionTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createAuthenticatedClient();
        // The fake integration and the session are kept between requests.
        $this->client->disableReboot();

        parent::setUp();
    }

    public function testEveryEnabledIntegrationShowsWhetherItIsReady(): void
    {
        $list = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en');
        $path = '_embedded.eekes_form_integration_connections';

        self::assertSame(['webhook', 'fake_crm'], $list->column($path, 'key'), 'Only enabled integrations that are installed.');

        $webhook = $list->find($path, 'key', 'webhook');
        self::assertTrue($webhook->at('ready'));
        self::assertFalse($webhook->at('connectable'));
        self::assertSame([['label' => 'Endpoints', 'value' => 'zapier, crm']], $webhook->at('details'));

        $crm = $list->find($path, 'key', 'fake_crm');
        self::assertTrue($crm->at('connectable'));
        self::assertTrue($crm->at('connected'));
        self::assertSame([['label' => 'Connected as', 'value' => 'Acme']], $crm->at('details'));
        self::assertSame('http://localhost/admin/api/form-integration-connections/fake_crm/callback', $crm->at('redirectUri'), 'The address to register with the service.');
    }

    public function testAnAccountIsConnectedThroughTheServiceAndBackToTheAdmin(): void
    {
        $this->crm()->account = null;
        $notConnected = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en')->find('_embedded.eekes_form_integration_connections', 'key', 'fake_crm');
        self::assertFalse($notConnected->at('ready'));
        self::assertSame('Fake CRM is not connected yet.', $notConnected->at('message'));
        self::assertSame(['Connect it below.'], $notConnected->at('steps'));
        self::assertSame([
            ['label' => 'API token', 'met' => true, 'hint' => 'From the environment variable CRM_TOKEN.'],
            ['label' => 'Account connected', 'met' => false, 'hint' => 'Click "Connect".'],
        ], $notConnected->at('requirements'), 'Exactly what is missing, with the variable to set.');

        $start = $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=connect');
        $url = $start->string('url');
        self::assertStringStartsWith('https://crm.example.com/oauth?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        // The service sends the user back with a code and the state.
        $this->client->request('GET', '/admin/api/form-integration-connections/fake_crm/callback?'.http_build_query(['code' => 'good', 'state' => $query['state']]));
        self::assertResponseRedirects('/admin/#/'.FormAdmin::INTEGRATIONS_PATH);

        self::assertTrue($this->crm()->isConnected());
        self::assertSame('test', $this->crm()->connectedBy);

        $list = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en');
        self::assertSame(['key' => 'fake_crm', 'success' => true, 'message' => null], $list->at('result'), 'The admin says how it went, once.');
        self::assertNull($this->request('GET', '/admin/api/form-integration-connections?userLocale=en')->at('result'));
    }

    public function testACallbackThatThisSessionDidNotStartConnectsNothing(): void
    {
        $this->crm()->account = null;
        $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=connect');

        $this->client->request('GET', '/admin/api/form-integration-connections/fake_crm/callback?code=good&state=forged');

        self::assertResponseRedirects();
        self::assertFalse($this->crm()->isConnected());
        $result = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en')->get('result');
        self::assertFalse($result->at('success'));
        self::assertStringContainsString('not started from this admin session', $result->string('message'));
    }

    public function testARefusalOfTheServiceIsShown(): void
    {
        $start = $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=connect');
        parse_str((string) parse_url($start->string('url'), \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        $this->client->request('GET', '/admin/api/form-integration-connections/fake_crm/callback?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]));

        $result = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en')->get('result');
        self::assertFalse($result->at('success'));
        self::assertSame('The service did not approve the connection: access_denied', $result->at('message'));
    }

    public function testAnAccountIsDisconnected(): void
    {
        $crm = $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=disconnect&userLocale=en');

        self::assertFalse($crm->at('connected'));
        self::assertSame('action_required', $crm->at('state'));
        self::assertFalse($this->crm()->isConnected());
    }

    public function testOnlyAConnectableIntegrationCanBeConnected(): void
    {
        $this->client->jsonRequest('POST', '/admin/api/form-integration-connections/webhook?action=connect');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAConnectionIsTestedAndTheLastTestKept(): void
    {
        $crm = $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=test&userLocale=en');

        self::assertTrue($crm->at('testable'));
        self::assertFalse($crm->at('testSendsData'));
        self::assertTrue($crm->at('lastTest.passed'));
        self::assertSame('The CRM answered.', $crm->at('lastTest.message'));
        self::assertSame([['label' => 'Account', 'value' => 'Acme']], $crm->at('lastTest.details'));
        self::assertSame('test', $crm->at('lastTest.testedBy'));

        $this->crm()->behaviour = 'refuse';
        $this->request('POST', '/admin/api/form-integration-connections/fake_crm?action=test&userLocale=en');

        $listed = $this->request('GET', '/admin/api/form-integration-connections?userLocale=en')->find('_embedded.eekes_form_integration_connections', 'key', 'fake_crm');
        self::assertFalse($listed->at('lastTest.passed'), 'The last test is kept, also when it failed.');
        self::assertSame('The CRM refused the credentials.', $listed->at('lastTest.message'));
    }

    public function testAWebhookIsTestedByPostingATestToEveryEndpoint(): void
    {
        $http = $this->client->getContainer()->get(RecordingHttpClient::class);
        self::assertInstanceOf(RecordingHttpClient::class, $http);

        $webhook = $this->request('POST', '/admin/api/form-integration-connections/webhook?action=test&userLocale=en');

        self::assertTrue($webhook->at('testSendsData'), 'So the admin asks before it sends.');
        self::assertTrue($webhook->at('lastTest.passed'));
        self::assertSame([['label' => 'zapier', 'value' => '200'], ['label' => 'crm', 'value' => '200']], $webhook->at('lastTest.details'));
        self::assertCount(2, $http->requests);
        $body = $http->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertTrue(Json::decode($body)->at('test'), 'Marked as a test, so the other side can leave it alone.');
    }

    public function testTheFlowModalKnowsWhetherAnIntegrationIsReady(): void
    {
        $this->crm()->account = null;

        $catalog = $this->request('GET', '/admin/api/form-integrations?userLocale=en');

        self::assertSame(['ready' => false, 'message' => 'Fake CRM is not connected yet.'], $catalog->find('_embedded.eekes_form_integrations', 'key', 'fake_crm')->at('status'));
        self::assertTrue($catalog->at('mayManage'), 'So it can link to Settings → Form builder integrations.');

        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']]);
        $list = $this->request('GET', '/admin/api/form-flows?formId='.$form->id.'&locale=en');
        self::assertSame('Fake CRM: Create lead ⚠ not ready', $list->at('_embedded.eekes_form_flows.0.integration'), 'The list of flows shows it too.');
    }

    public function testTheSettingsPageAndItsPermissionAreRegistered(): void
    {
        $this->client->request('GET', '/admin/config');
        $config = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(FormAdmin::INTEGRATIONS_VIEW, $config);
        self::assertStringContainsString(FormAdmin::INTEGRATIONS_SECURITY_CONTEXT, $config);
        self::assertStringContainsString('eekes_form_builder.form_integrations', $config, 'In the Settings navigation.');
    }

    private function crm(): FakeCrmIntegration
    {
        $crm = $this->client->getContainer()->get(FakeCrmIntegration::class);
        self::assertInstanceOf(FakeCrmIntegration::class, $crm);

        return $crm;
    }

    /**
     * @param null|array<mixed> $payload
     */
    private function request(string $method, string $uri, ?array $payload = null): Json
    {
        $this->client->jsonRequest($method, $uri, $payload ?? []);

        return Json::decode((string) $this->client->getResponse()->getContent());
    }
}
