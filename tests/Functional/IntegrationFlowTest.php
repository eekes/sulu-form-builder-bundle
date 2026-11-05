<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Event\IntegrationRunFinishedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\FlowValueMapper;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRunner;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Message\ExecuteIntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\RetryPolicy;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\RunStatus;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration\FakeCrmIntegration;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\RecordingHttpClient;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\RecordingMessageBus;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/**
 * A visitor sends a form; the flows of that form run, and every run is logged with what was sent
 * and what came back.
 */
#[CoversNothing]
final class IntegrationFlowTest extends WebsiteTestCase
{
    public function testAFlowSendsTheMappedValuesAndIsLogged(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', [
            'email' => ['source' => 'field', 'field' => 'email'],
            'name' => ['source' => 'field', 'field' => 'name'],
            'source' => ['source' => 'value', 'value' => 'Website: {{ form_title }}'],
        ], ['pipeline' => 'sales'], name: 'Lead to CRM');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $requests = self::service(FakeCrmIntegration::class)->requests;
        self::assertCount(1, $requests);
        self::assertSame(['email' => 'jane@example.com', 'name' => 'Jane', 'source' => 'Website: Contact'], $requests[0]->values);
        self::assertSame('sales', $requests[0]->stringSetting('pipeline'));

        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame(RunStatus::Succeeded, $runs[0]->status());
        self::assertSame('Lead to CRM', $runs[0]->flowName);
        self::assertSame('Lead 42 created.', $runs[0]->message);
        self::assertSame(['id' => 42], $runs[0]->response);
        self::assertSame(1, $runs[0]->attempts);
        self::assertSame(self::submissions()[0]->id, $runs[0]->submission->id);
        self::assertCount(1, self::recorder()->recorded(IntegrationRunFinishedEvent::class));
    }

    public function testTheRowsOfARepeaterAreMappedAsTheirReadableText(): void
    {
        $form = self::createForm('event', [['key' => 'step1', 'fields' => [
            ['key' => 'email', 'type' => 'email', 'required' => true, 'translations' => ['en' => ['label' => 'E-mail']]],
            ['key' => 'people', 'type' => 'repeater', 'translations' => ['en' => ['label' => 'Participants', 'fields' => [
                ['type' => 'text', 'label' => 'Name', 'key' => 'name'],
            ]]]],
        ]]]);
        self::createFlow($form, 'fake_crm', 'create_lead', [
            'email' => ['source' => 'field', 'field' => 'email'],
            'name' => ['source' => 'field', 'field' => 'people'],
        ], ['pipeline' => 'sales']);

        $this->submit($form, 'step1', ['email' => 'jane@example.com', 'people' => [['name' => 'Jane'], ['name' => 'Joe']]]);

        $requests = self::service(FakeCrmIntegration::class)->requests;
        self::assertCount(1, $requests);
        self::assertSame("1. Name: Jane\n2. Name: Joe", $requests[0]->string('name'));
    }

    public function testAFlowOnlyRunsWhenItsConditionsAreMet(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales'], new ConditionSet([
            new Condition('message', ConditionOperator::Contains, 'quote'),
            new Condition('message', ConditionOperator::Contains, 'price'),
        ], ConditionSet::ANY));

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'Just saying hi']);
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'What is the price?']);

        self::assertCount(1, self::service(FakeCrmIntegration::class)->requests);
        $runs = self::runs();
        self::assertSame([RunStatus::Skipped, RunStatus::Succeeded], array_map(static fn (IntegrationRun $run): RunStatus => $run->status(), $runs));
        self::assertSame('The conditions of the flow did not match this submission.', $runs[0]->message, 'What did not happen is logged too.');
        self::assertSame([], $runs[0]->attemptLog());
    }

    public function testADisabledFlowOrAnIntegrationThatIsNotEnabledDoesNotRun(): void
    {
        $form = self::createContactForm();
        $flow = self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        $flow->enabled = false;
        self::entityManager()->flush();
        self::createFlow($form, 'not_installed', 'whatever', []);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame([], self::service(FakeCrmIntegration::class)->requests);
        $runs = self::runs();
        self::assertSame([RunStatus::Skipped, RunStatus::Skipped], array_map(static fn (IntegrationRun $run): RunStatus => $run->status(), $runs));
        self::assertSame(['The flow is switched off.', 'The integration "not_installed" is not enabled.'], array_map(static fn (IntegrationRun $run): ?string => $run->message, $runs));
    }

    public function testARequiredValueThatIsMissingFailsTheRunWithoutCallingTheService(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'message']], ['pipeline' => 'sales']);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => '']);

        $run = self::runs()[0];
        self::assertSame(RunStatus::Failed, $run->status());
        self::assertStringContainsString('Required values are missing: "email"', (string) $run->message);
        self::assertSame([], self::service(FakeCrmIntegration::class)->requests);
        self::assertCount(1, self::submissions(), 'The visitor\'s submission is never affected.');
    }

    public function testARefusalIsLoggedWithWhatWasSentAndReceived(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        self::service(FakeCrmIntegration::class)->behaviour = 'refuse';

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $run = self::runs()[0];
        self::assertSame(RunStatus::Failed, $run->status());
        self::assertSame('The CRM refused the lead: duplicate e-mail.', $run->message);
        self::assertSame(['email' => 'jane@example.com'], $run->request);
        self::assertSame(['error' => 'duplicate'], $run->response);
    }

    public function testAnyOtherExceptionIsCaughtAndLoggedToo(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        self::service(FakeCrmIntegration::class)->behaviour = 'crash';

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame('LogicException: Connection reset.', self::runs()[0]->message);
    }

    public function testAFailedRunCanBeRetriedWithTheSameValues(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        self::service(FakeCrmIntegration::class)->behaviour = 'crash';
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);
        self::service(FakeCrmIntegration::class)->behaviour = 'ok';

        $run = self::service(IntegrationRunner::class)->execute(self::runs()[0]);

        self::assertSame(RunStatus::Succeeded, $run->status());
        self::assertSame(2, $run->attempts);
        self::assertSame('jane@example.com', self::service(FakeCrmIntegration::class)->requests[1]->string('email'));
    }

    public function testEveryAttemptIsLogged(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        self::service(FakeCrmIntegration::class)->behaviour = 'refuse';
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);
        self::service(FakeCrmIntegration::class)->behaviour = 'ok';

        self::service(IntegrationRunner::class)->execute(self::runs()[0], IntegrationRunAttempt::TRIGGER_EDITOR, 'editor@example.com');

        $attempts = self::runs()[0]->attemptLog();
        self::assertCount(2, $attempts);
        self::assertSame([1, 2], array_map(static fn (IntegrationRunAttempt $attempt): int => $attempt->number, $attempts));
        self::assertSame([IntegrationRunAttempt::TRIGGER_SUBMISSION, IntegrationRunAttempt::TRIGGER_EDITOR], array_map(static fn (IntegrationRunAttempt $attempt): string => $attempt->trigger, $attempts));
        self::assertSame('editor@example.com', $attempts[1]->triggeredBy);
        self::assertFalse($attempts[0]->succeeded);
        self::assertSame('The CRM refused the lead: duplicate e-mail.', $attempts[0]->message, 'The failure stays readable after the retry succeeded.');
        self::assertSame(['error' => 'duplicate'], $attempts[0]->response);
        self::assertFalse($attempts[0]->retryable, 'A refusal is not worth an automatic retry.');
        self::assertTrue($attempts[1]->succeeded);
        self::assertNotNull($attempts[1]->durationMs);
    }

    public function testAFailureThatMayPassLaterIsRetriedWithAGrowingDelay(): void
    {
        $bus = new RecordingMessageBus();
        $runner = $this->runner($bus, new RetryPolicy(maxAttempts: 3, delay: 60, multiplier: 2.0));
        $run = $this->storedRun('unavailable');

        $runner->execute($run);
        self::assertSame(RunStatus::Retrying, $run->status());
        self::assertNotNull($run->nextAttemptAt);
        self::assertEqualsWithDelta(time() + 60, $run->nextAttemptAt->getTimestamp(), 5);
        self::assertSame([60000], $this->delays($bus));
        self::assertTrue($run->lastAttempt()?->retryable);

        // The worker picks the message up after the delay.
        $runner->execute($run, IntegrationRunAttempt::TRIGGER_AUTOMATIC);
        self::assertSame([60000, 120000], $this->delays($bus));

        $runner->execute($run, IntegrationRunAttempt::TRIGGER_AUTOMATIC);
        self::assertSame(RunStatus::Failed, $run->status(), 'Out of attempts.');
        self::assertNull($run->nextAttemptAt);
        self::assertCount(2, $bus->envelopes);
        self::assertSame(IntegrationRunAttempt::TRIGGER_AUTOMATIC, $run->attemptLog()[2]->trigger);

        $message = $bus->envelopes[0]->getMessage();
        self::assertInstanceOf(ExecuteIntegrationRun::class, $message);
        self::assertSame($run->id, $message->runId);
        self::assertSame(IntegrationRunAttempt::TRIGGER_AUTOMATIC, $message->trigger);
    }

    public function testAServiceThatCannotBeReachedIsRetriedButARefusalIsNot(): void
    {
        $bus = new RecordingMessageBus();
        $runner = $this->runner($bus, new RetryPolicy());

        $timeout = $this->storedRun('timeout');
        $runner->execute($timeout);
        self::assertSame(RunStatus::Retrying, $timeout->status());
        self::assertStringContainsString('Idle timeout reached', (string) $timeout->message);

        $refused = $this->storedRun('refuse');
        $runner->execute($refused);
        self::assertSame(RunStatus::Failed, $refused->status());
        self::assertCount(1, $bus->envelopes, 'Sending the same again would only be refused again.');
    }

    public function testWithoutMessengerThereAreNoAutomaticRetries(): void
    {
        $run = $this->storedRun('unavailable');

        $this->runner(null, new RetryPolicy())->execute($run);

        self::assertSame(RunStatus::Failed, $run->status(), 'Retrying in the visitor\'s request would make them wait.');
    }

    public function testWithoutAnAsyncTransportThereAreNoAutomaticRetriesEither(): void
    {
        $bus = new RecordingMessageBus();
        $run = $this->storedRun('unavailable');

        $this->runner($bus, new RetryPolicy(), new SendersLocator([], new ServiceLocator([])))->execute($run);

        self::assertSame(RunStatus::Failed, $run->status(), 'Handled right away, a retry would hit the service again within the same request.');
        self::assertSame([], $bus->envelopes);
    }

    private ?Flow $flow = null;

    private function runner(?RecordingMessageBus $bus, RetryPolicy $policy, ?SendersLocator $senders = null): IntegrationRunner
    {
        return new IntegrationRunner(
            self::service(FlowRepository::class),
            self::service(IntegrationRunRepository::class),
            self::service(IntegrationRegistry::class),
            self::service(ConditionEvaluator::class),
            self::service(FlowValueMapper::class),
            self::service(EventDispatcherInterface::class),
            new NullLogger(),
            $bus,
            true,
            $policy,
            $senders,
        );
    }

    /**
     * A stored run of a stored submission, ready to execute against the fake CRM.
     */
    private function storedRun(string $behaviour): IntegrationRun
    {
        $this->flow ??= self::createFlow(self::createContactForm(), 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        $flow = $this->flow;
        $submission = new Submission($flow->form, 'en', [new SubmittedValue('email', 'E-mail', 'email', 'jane@example.com', 'jane@example.com')]);
        $run = new IntegrationRun($submission, $flow, ['email' => 'jane@example.com']);
        self::entityManager()->persist($submission);
        self::entityManager()->persist($run);
        self::entityManager()->flush();
        self::service(FakeCrmIntegration::class)->behaviour = $behaviour;

        return $run;
    }

    /**
     * @return list<null|int> the delay of every dispatched message, in milliseconds
     */
    private function delays(RecordingMessageBus $bus): array
    {
        return array_map(static fn (Envelope $envelope): ?int => $envelope->last(DelayStamp::class)?->getDelay(), $bus->envelopes);
    }

    public function testAFormThatDoesNotStoreSubmissionsStillRunsItsFlowsWithoutALog(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->storeSubmissions = false);
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertCount(1, self::service(FakeCrmIntegration::class)->requests);
        self::assertSame([], self::runs());
    }

    public function testSpamAndPreventedSubmissionsReachNoIntegration(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']], ['pipeline' => 'sales']);
        self::recorder()->on(SubmissionValidatedEvent::class, static function (SubmissionValidatedEvent $event): void {
            $event->submission->value('name') === 'Bot' ? $event->markAsSpam() : $event->preventIntegrations();
        });

        $this->submit($form, 'step1', ['name' => 'Bot', 'email' => 'bot@example.com']);
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame([], self::service(FakeCrmIntegration::class)->requests);
        self::assertSame(
            ['The submission was blocked as spam.', 'A listener kept this submission from the integrations.'],
            array_map(static fn (IntegrationRun $run): ?string => $run->message, self::runs()),
            'Both are logged as not run, with the reason.',
        );
    }

    public function testTheWebhookPostsTheSubmissionSignedToTheConfiguredEndpoint(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'webhook', 'send', ['lead_source' => ['source' => 'value', 'value' => 'website']], ['endpoint' => 'crm', 'includeAnswers' => true]);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $requests = self::service(RecordingHttpClient::class)->requests;
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://crm.example.com/hook', $requests[0]['url']);

        $body = $requests[0]['options']['body'] ?? null;
        self::assertIsString($body);
        $payload = Json::decode($body);
        self::assertSame('contact', $payload->at('form.key'));
        self::assertSame('website', $payload->at('fields.lead_source'));
        self::assertSame('jane@example.com', $payload->at('answers.email'));

        $headers = $requests[0]['options']['normalized_headers'] ?? [];
        self::assertIsArray($headers);
        self::assertSame(['X-Eekes-Signature: sha256='.hash_hmac('sha256', $body, 's3cret')], $headers['x-eekes-signature'] ?? null);
        self::assertSame(RunStatus::Succeeded, self::runs()[0]->status());
    }

    public function testAWebhookThatAnswersAnErrorFailsTheRun(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'webhook', 'send', [], ['endpoint' => 'zapier']);
        self::service(RecordingHttpClient::class)->status = 500;

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $run = self::runs()[0];
        self::assertSame(RunStatus::Failed, $run->status());
        self::assertSame('The webhook "zapier" answered 500.', $run->message);
        self::assertEquals(['status' => 500, 'body' => '{"ok":true}'], $run->response, 'MySQL keeps JSON objects in its own key order.');
        self::assertTrue($run->lastAttempt()?->retryable, 'A server error may pass later.');
    }

    public function testAWebhookEndpointThatIsNotConfiguredFailsTheRun(): void
    {
        $form = self::createContactForm();
        self::createFlow($form, 'webhook', 'send', [], ['endpoint' => 'https://evil.example.com']);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame('The webhook endpoint "https://evil.example.com" is not configured.', self::runs()[0]->message);
        self::assertSame([], self::service(RecordingHttpClient::class)->requests, 'Editors can never make the website post to a URL of their own.');
    }
}
