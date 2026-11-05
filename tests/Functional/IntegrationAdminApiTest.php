<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration\FakeCrmIntegration;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The "Integrations" tab and the run log in the submission modal, through the admin API.
 */
#[CoversNothing]
final class IntegrationAdminApiTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createAuthenticatedClient();
        // The fake integration is changed between requests; a reboot would throw that away.
        $this->client->disableReboot();

        parent::setUp();
    }

    /**
     * The fake of the kernel the client talks to, which is not the one self::getContainer() boots.
     */
    private function crm(): FakeCrmIntegration
    {
        $crm = $this->client->getContainer()->get(FakeCrmIntegration::class);
        self::assertInstanceOf(FakeCrmIntegration::class, $crm);

        return $crm;
    }

    public function testOnlyEnabledAndInstalledIntegrationsAreOffered(): void
    {
        $catalog = $this->request('GET', '/admin/api/form-integrations?locale=en');

        self::assertSame(['webhook', 'fake_crm'], $catalog->column('_embedded.eekes_form_integrations', 'key'), '"not_installed" is enabled but does not exist.');

        $crm = $catalog->find('_embedded.eekes_form_integrations', 'key', 'fake_crm');
        self::assertSame('Fake CRM', $crm->at('title'));
        self::assertSame(['email', 'name', 'source'], $crm->column('actions.0.fields', 'key'));
        self::assertTrue($crm->at('actions.0.fields.0.required'));
        self::assertSame(['pipeline', 'notify'], $crm->column('actions.0.settings', 'key'));
        self::assertTrue($crm->at('actions.0.settings.0.dynamicOptions'));
        self::assertTrue($crm->at('actions.0.allowCustomFields'));
        self::assertTrue($crm->at('actions.0.dynamicFields'));

        $webhook = $catalog->find('_embedded.eekes_form_integrations', 'key', 'webhook');
        self::assertSame('Webhook', $webhook->at('title'));
        self::assertFalse($webhook->at('actions.0.dynamicFields'), 'The webhook has no fields of the remote account.');
    }

    public function testTheChoicesOfASettingComeFromTheService(): void
    {
        self::assertSame(['sales', 'partners'], $this->request('GET', '/admin/api/form-integrations/fake_crm/options?action=create_lead&setting=pipeline')->column('options', 'value'));
        self::assertSame(['zapier', 'crm'], $this->request('GET', '/admin/api/form-integrations/webhook/options?action=send&setting=endpoint')->column('options', 'value'));
    }

    public function testAServiceThatRefusesExplainsWhyTheChoicesStayEmpty(): void
    {
        $this->crm()->behaviour = 'refuse';

        $response = $this->request('GET', '/admin/api/form-integrations/fake_crm/options?action=create_lead&setting=pipeline');

        self::assertResponseStatusCodeSame(502);
        self::assertSame('The API key is not valid.', $response->at('detail'));
    }

    public function testTheFieldsOfTheRemoteAccountDependOnTheSettings(): void
    {
        $fields = $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=create_lead&settings='.rawurlencode('{"pipeline":"sales"}'));

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['key' => 'budget', 'label' => 'Budget', 'required' => false, 'type' => 'number', 'help' => 'In euro'],
            ['key' => 'vat_number', 'label' => 'VAT number', 'required' => true, 'type' => 'text', 'help' => null],
        ], $fields->at('fields'), 'A field the action already declares is left out.');

        self::assertSame([], $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=create_lead&settings='.rawurlencode('{"pipeline":"partners"}'))->at('fields'));
        self::assertSame([], $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=create_lead')->at('fields'), 'Before a setting is picked.');
        self::assertSame([], $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=unknown&settings='.rawurlencode('{"pipeline":"sales"}'))->at('fields'));
        self::assertSame([], $this->request('GET', '/admin/api/form-integrations/webhook/fields?action=send')->at('fields'), 'An integration without them has none.');
    }

    public function testFieldsThatCannotBeLoadedExplainWhy(): void
    {
        $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=create_lead&settings=not-json');
        self::assertResponseStatusCodeSame(400);

        $this->request('GET', '/admin/api/form-integrations/not_installed/fields?action=create_lead');
        self::assertResponseStatusCodeSame(404);

        $this->crm()->behaviour = 'refuse';
        $response = $this->request('GET', '/admin/api/form-integrations/fake_crm/fields?action=create_lead&settings='.rawurlencode('{"pipeline":"sales"}'));

        self::assertResponseStatusCodeSame(502);
        self::assertSame('The API key is not valid.', $response->at('detail'));
    }

    public function testAFlowCannotBeSavedWithoutItsRequiredMappingAndSettings(): void
    {
        $form = self::createContactForm();

        $response = $this->request('POST', '/admin/api/form-flows?formId='.$form->id.'&locale=en', [
            'name' => 'Lead',
            'flow' => ['integration' => 'fake_crm', 'action' => 'create_lead', 'settings' => [], 'mapping' => ['name' => ['source' => 'field', 'field' => 'name']]],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['The setting "pipeline" is required.', 'The field "email" is required: map it to an answer or a fixed value.'], $response->at('problems'));
        self::assertSame([], self::entityManager()->getRepository(Flow::class)->findAll());
    }

    public function testAFlowIsSavedReadAndListed(): void
    {
        $form = self::createContactForm();

        $created = $this->request('POST', '/admin/api/form-flows?formId='.$form->id.'&locale=en', [
            'name' => 'Lead to CRM',
            'enabled' => true,
            'flow' => [
                'integration' => 'fake_crm',
                'action' => 'create_lead',
                'settings' => ['pipeline' => 'sales', 'notify' => true],
                'mapping' => [
                    'email' => ['source' => 'field', 'field' => 'email'],
                    'source' => ['source' => 'value', 'value' => 'website'],
                    'custom_score' => ['source' => 'value', 'value' => '10'],
                    'broken' => ['source' => 'field', 'field' => ''],
                ],
            ],
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'message', 'operator' => 'filled']]],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['email' => ['source' => 'field', 'field' => 'email'], 'source' => ['source' => 'value', 'value' => 'website'], 'custom_score' => ['source' => 'value', 'value' => '10']], $created->at('flow.mapping'));
        self::assertSame([], $created->at('problems'));

        $list = $this->request('GET', '/admin/api/form-flows?formId='.$form->id.'&locale=en');
        self::assertSame('Fake CRM: Create lead', $list->at('_embedded.eekes_form_flows.0.integration'));

        $builder = $this->request('GET', '/admin/api/forms/'.$form->id.'?locale=en');
        self::assertSame(['Lead to CRM'], $builder->at('integrationUsage.email'), 'The builder marks fields a flow reads.');
        self::assertSame(['Lead to CRM'], $builder->at('integrationUsage.message'), 'Fields in conditions count too.');
        self::assertSame(['email' => ['Lead to CRM']], $builder->at('requiredByIntegrations'), 'Only a field mapped to a required target makes a run fail when it is left empty.');
    }

    public function testADuplicatedFormKeepsItsFlows(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales'], name: 'Lead');

        $copy = $this->request('POST', '/admin/api/forms/'.$form->id.'?action=copy&locale=en');

        $flows = $this->request('GET', '/admin/api/form-flows?formId='.$copy->int('id').'&locale=en');
        self::assertSame(1, $flows->at('total'));
        self::assertSame('Lead', $flows->at('_embedded.eekes_form_flows.0.name'));
        self::assertCount(2, self::entityManager()->getRepository(Flow::class)->findAll(), 'The original keeps its own flow.');
    }

    public function testTheSubmissionModalShowsEveryRunAndARunCanBeRetried(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales'], name: 'Lead');
        $this->crm()->behaviour = 'refuse';
        $this->submitDirectly($form);

        $submission = $this->request('GET', '/admin/api/form-submissions/'.self::submissions()[0]->id.'?locale=en');
        $run = $submission->get('runs.0');
        self::assertSame('Lead', $run->at('flowName'));
        self::assertSame('Fake CRM', $run->at('integration'));
        self::assertSame('Create lead', $run->at('action'));
        self::assertSame('failed', $run->at('status'));
        self::assertSame('The CRM refused the lead: duplicate e-mail.', $run->at('message'));
        self::assertSame(['error' => 'duplicate'], $run->at('response'));
        self::assertTrue($run->at('retryable'));

        $this->crm()->behaviour = 'ok';
        $retried = $this->request('POST', '/admin/api/form-integration-runs/'.$run->int('id').'?action=retry');

        self::assertSame('succeeded', $retried->at('status'));
        self::assertSame(2, $retried->at('attempts'));
        self::assertSame('Lead 42 created.', $retried->at('message'));
        self::assertSame(['submission', 'editor'], $retried->column('attemptLog', 'trigger'), 'Every attempt is in the log.');
        self::assertSame('test', $retried->at('attemptLog.1.triggeredBy'), 'With the editor who clicked "Try again".');
        self::assertSame('The CRM refused the lead: duplicate e-mail.', $retried->at('attemptLog.0.message'));
        self::assertFalse($retried->at('attemptLog.0.succeeded'));
    }

    public function testTheIntegrationsTabIsRegistered(): void
    {
        $this->client->request('GET', '/admin/config');

        self::assertStringContainsString('eekes_form_builder.forms.edit_form.integrations', (string) $this->client->getResponse()->getContent());

        foreach (['eekes_form_flow_details'] as $key) {
            $this->client->request('GET', '/admin/metadata/form/'.$key);
            self::assertResponseIsSuccessful($key);
        }

        $this->client->request('GET', '/admin/metadata/list/eekes_form_flows');
        self::assertResponseIsSuccessful();
    }

    /**
     * A submission straight through the processor, as the request listener would hand it over.
     */
    private function submitDirectly(\Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form $form): void
    {
        $processor = $this->client->getContainer()->get(\Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor::class);
        self::assertInstanceOf(\Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor::class, $processor);
        $processor->process(
            self::definition($form),
            ['_step' => 'step1', 'step1' => ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => '']],
            new \Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext('en'),
        );
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
