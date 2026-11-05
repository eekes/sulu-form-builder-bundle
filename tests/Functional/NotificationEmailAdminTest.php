<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * The e-mails of notifications in the admin: the template per notification, the preview and the
 * test e-mail in the modal, and the e-mail log of a submission with "Send again".
 */
#[CoversNothing]
final class NotificationEmailAdminTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createAuthenticatedClient();
        $this->client->disableReboot();

        parent::setUp();
    }

    public function testTheModalOffersEveryTemplateAndAPreview(): void
    {
        $metadata = $this->request('GET', '/admin/metadata/form/'.Notification::FORM_KEY.'?locale=en');

        $template = $metadata->get('form.message.items.template');
        self::assertSame('single_select', $template->at('type'));
        self::assertSame(['card', 'plain', 'summary', 'letter'], $template->column('options.values.value', 'name'), 'The three of the bundle and the one of the application.');
        self::assertSame('card', $template->at('options.default_value.value'));
        self::assertSame('eekes_form_builder_notification_preview', $metadata->at('form.preview_section.items.preview.type'));
        self::assertStringContainsString('{{ email }} sends to', $metadata->string('form.recipients.description'), 'Placeholders in info texts read as editors type them.');
    }

    public function testThePreviewShowsTheEMailAsItIsBeingEditedWithSampleAnswers(): void
    {
        $form = self::createContactForm();

        $preview = $this->request('POST', '/admin/api/form-notifications/preview?formId='.$form->id.'&locale=en', [
            'to' => '{{ email }}',
            'subject' => 'New message from {{ name }}',
            'body' => '<p>{{ name }} wrote:</p>',
            'includeValues' => true,
            'template' => 'summary',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('New message from Jane Doe', $preview->at('subject'), 'Placeholders filled with sample answers.');
        self::assertSame('"Example website" <website@example.com>', $preview->at('from'));
        $html = $preview->string('html');
        self::assertStringContainsString('New submission', $html, 'In the template that was picked.');
        self::assertStringContainsString('jane.doe@example.com', $html);
        self::assertEmailCount(0, null, 'A preview sends nothing.');
        self::assertCount(0, self::submissions(), 'Nor stores anything.');
    }

    public function testThePreviewFillsTheNewFieldTypesToo(): void
    {
        $form = self::createForm('event', [['key' => 'step1', 'fields' => [
            ['key' => 'score', 'type' => 'rating', 'options' => ['max' => 5], 'translations' => ['en' => ['label' => 'Score']]],
            ['key' => 'arrival', 'type' => 'time', 'translations' => ['en' => ['label' => 'Arrival']]],
            ['key' => 'people', 'type' => 'repeater', 'translations' => ['en' => ['label' => 'Participants', 'fields' => [
                ['type' => 'text', 'label' => 'First name', 'key' => 'first_name'],
                ['type' => 'email', 'label' => 'E-mail', 'key' => 'email'],
            ]]]],
        ]]]);

        $preview = $this->request('POST', '/admin/api/form-notifications/preview?formId='.$form->id.'&locale=en', [
            'to' => 'team@example.com',
            'subject' => 'Score {{ score }}',
            'body' => '<p>Hi</p>',
            'includeValues' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Score 4 / 5', $preview->at('subject'));
        $html = $preview->string('html');
        self::assertStringContainsString('1. First name: Jane, E-mail: jane.doe@example.com<br />', $html, 'One line per row.');
        self::assertStringContainsString('2:30', $html);
    }

    public function testATestEMailGoesToOneAddressOnly(): void
    {
        $form = self::createContactForm();
        $data = ['to' => 'team@example.com', 'cc' => 'boss@example.com', 'subject' => 'Hello {{ name }}', 'body' => '<p>Hi</p>'];

        $sent = $this->request('POST', '/admin/api/form-notifications/preview?formId='.$form->id.'&locale=en&action=send', [...$data, 'testRecipient' => 'me@example.com']);

        self::assertTrue($sent->at('sent'));
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('[Test] Hello Jane Doe', $email->getSubject());
        self::assertSame(['me@example.com'], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
        self::assertSame([], $email->getCc(), 'Not to the real recipients.');

        $this->client->jsonRequest('POST', '/admin/api/form-notifications/preview?formId='.$form->id.'&locale=en&action=send', [...$data, 'testRecipient' => 'not an address']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testATemplateIsSavedWithTheNotification(): void
    {
        $form = self::createContactForm();

        $created = $this->request('POST', '/admin/api/form-notifications?formId='.$form->id.'&locale=en', ['name' => 'Team', 'to' => 'team@example.com', 'subject' => 'Hi', 'template' => 'plain']);

        self::assertSame('plain', $created->at('template'));
        self::assertSame('plain', $this->request('GET', '/admin/api/form-notifications/'.$created->int('id').'?formId='.$form->id.'&locale=en')->at('template'));
    }

    public function testTheSubmissionShowsItsEMailsAndOneIsSentAgain(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::createNotification($form, 'off@example.com', static fn (Notification $notification) => $notification->enabled = false);
        $this->submitDirectly($form);
        $submissionId = self::submissions()[0]->id;

        $submission = $this->request('GET', '/admin/api/form-submissions/'.$submissionId.'?locale=en');
        self::assertSame(['sent', 'skipped'], $submission->column('deliveries', 'status'));
        self::assertSame(['to' => ['team@example.com']], $submission->at('deliveries.0.recipients'));
        self::assertSame('The notification is switched off.', $submission->at('deliveries.1.message'));
        self::assertFalse($submission->at('deliveries.1.resendable'));

        $id = $submission->int('deliveries.0.id');
        $shown = $this->request('GET', '/admin/api/form-notification-deliveries/'.$id);
        self::assertStringContainsString('Example Ltd', $shown->string('html'), 'The e-mail as it was sent.');

        $again = $this->request('POST', '/admin/api/form-notification-deliveries/'.$id.'?action=resend');
        self::assertSame('resend', $again->at('trigger'));
        self::assertSame('test', $again->at('triggeredBy'));
        self::assertSame('sent', $again->at('status'));
        self::assertSame(['sent', 'skipped', 'sent'], $this->request('GET', '/admin/api/form-submissions/'.$submissionId.'?locale=en')->column('deliveries', 'status'));
    }

    private function submitDirectly(Form $form): void
    {
        $container = $this->client->getContainer();
        $provider = $container->get(FormDefinitionProvider::class);
        $processor = $container->get(SubmissionProcessor::class);
        self::assertInstanceOf(FormDefinitionProvider::class, $provider);
        self::assertInstanceOf(SubmissionProcessor::class, $processor);
        $definition = $provider->getById($form->id, 'en');
        self::assertNotNull($definition);
        $processor->process($definition, ['step1' => ['name' => 'Jane', 'email' => 'jane@example.com']], new SubmissionContext('en', url: 'https://example.com/contact'));
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
