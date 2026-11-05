<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The spam protection in the admin: the switches in the settings of a form, the Spam tab, and
 * releasing a submission that was caught by mistake.
 */
#[CoversNothing]
final class SpamAdminApiTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createAuthenticatedClient();

        parent::setUp();
    }

    public function testTheSettingsHaveASwitchPerSpamCheck(): void
    {
        $metadata = $this->request('GET', '/admin/metadata/form/eekes_form_settings?locale=en');

        self::assertSame('section', $metadata->at('form.spam_protection.type'));
        self::assertSame('checkbox', $metadata->at('form.spam_protection.items.spamCheck_honeypot.type'));
        self::assertSame('Honeypot', $metadata->at('form.spam_protection.items.spamCheck_honeypot.options.label.title'));
        self::assertTrue($metadata->at('form.spam_protection.items.spamCheck_honeypot.options.default_value.value'), 'A new form has a honeypot.');
        self::assertFalse($metadata->at('form.spam_protection.items.spamCheck_fake_captcha.options.default_value.value'));
        self::assertSame('Keep submissions in the admin', $metadata->at('form.storage.items.storeSubmissions.options.label.title'), 'The text next to a switch is the title of its label option.');
    }

    public function testAFormSavesWhichChecksItUses(): void
    {
        $form = self::createContactForm();

        $loaded = $this->request('GET', '/admin/api/forms/'.$form->id.'?locale=en');
        self::assertTrue($loaded->at('spamCheck_honeypot'), 'A form that never chose uses the defaults.');
        self::assertFalse($loaded->at('spamCheck_fake_captcha'));

        $saved = $this->request('PUT', '/admin/api/forms/'.$form->id.'?locale=en', ['spamCheck_honeypot' => false, 'spamCheck_fake_captcha' => true]);
        self::assertFalse($saved->at('spamCheck_honeypot'));
        self::assertTrue($saved->at('spamCheck_fake_captcha'));

        $this->request('PUT', '/admin/api/forms/'.$form->id.'?locale=en', ['title' => 'Contact us']);
        self::entityManager()->clear();
        self::assertSame(['fake_captcha'], self::entityManager()->find(Form::class, $form->id)?->spamChecks(), 'Another tab does not touch the choice.');
    }

    public function testTheSpamTabListsOnlyBlockedSubmissions(): void
    {
        $form = self::createContactForm();
        $this->store($form, 'jane@example.com');
        $spam = $this->store($form, 'bot@example.com', spam: true);

        $list = $this->request('GET', '/admin/api/form-spam?formId='.$form->id.'&locale=en&page=1&limit=10');
        self::assertSame(1, $list->at('total'));
        self::assertSame('bot@example.com', $list->at('_embedded.'.Submission::SPAM_RESOURCE_KEY.'.0.field_email'));
        self::assertSame('Honeypot', $list->at('_embedded.'.Submission::SPAM_RESOURCE_KEY.'.0.spamCheck'));
        self::assertSame('203.0.113.7', $list->at('_embedded.'.Submission::SPAM_RESOURCE_KEY.'.0.ipAddress'));

        $real = $this->request('GET', '/admin/api/form-submissions?formId='.$form->id.'&locale=en&page=1&limit=10');
        self::assertSame(1, $real->at('total'), 'Spam is not among the real submissions.');
        self::assertSame('jane@example.com', $real->at('_embedded.'.Submission::RESOURCE_KEY.'.0.field_email'));

        $this->client->request('GET', '/admin/api/form-submissions/'.$spam->id.'?locale=en');
        self::assertResponseStatusCodeSame(404, 'A blocked submission is only reachable with the permissions of the spam tab.');

        $detail = $this->request('GET', '/admin/api/form-spam/'.$spam->id.'?locale=en');
        self::assertTrue($detail->at('spam'));
        self::assertTrue($detail->at('releasable'));
        self::assertSame('The hidden honeypot field was filled in.', $detail->at('spamReason'));
        self::assertSame('Mozilla/5.0 (bot)', $detail->at('userAgent'));

        $columns = $this->request('GET', '/admin/metadata/list/'.Submission::SPAM_LIST_KEY.'?formId='.$form->id.'&locale=en');
        self::assertSame('always', $columns->at('ipAddress.visibility'));
        self::assertSame('always', $columns->at('field_email.visibility'));
    }

    public function testNotSpamReleasesASubmissionWithItsNotificationsAndFlows(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']]);
        $spam = $this->store($form, 'jane@example.com', spam: true);

        $this->request('POST', '/admin/api/form-spam/'.$spam->id.'?action=release&locale=en');
        self::assertResponseIsSuccessful();

        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertFalse($submissions[0]->isSpam());
        self::assertNull($submissions[0]->ipAddress, 'A real submission does not keep the IP address.');
        self::assertEmailCount(1);
        self::assertCount(1, self::runs());

        $this->client->request('POST', '/admin/api/form-spam/'.$spam->id.'?action=release&locale=en');
        self::assertResponseStatusCodeSame(404, 'Once released, it is no longer spam.');
    }

    public function testASubmissionStoredWithoutItsAnswersCannotBeReleased(): void
    {
        $spam = $this->store(self::createContactForm(), 'jane@example.com', spam: true, withAnswers: false);

        self::assertFalse($this->request('GET', '/admin/api/form-spam/'.$spam->id.'?locale=en')->at('releasable'));

        $this->client->request('POST', '/admin/api/form-spam/'.$spam->id.'?action=release&locale=en');
        self::assertResponseStatusCodeSame(400);
    }

    public function testBlockedSubmissionsAreDeleted(): void
    {
        $form = self::createContactForm();
        $real = $this->store($form, 'jane@example.com');
        $spam = $this->store($form, 'bot@example.com', spam: true);

        $this->client->request('DELETE', '/admin/api/form-spam?ids='.$spam->id.','.$real->id);
        self::assertResponseStatusCodeSame(204);

        $left = self::submissions();
        self::assertCount(1, $left, 'Only blocked submissions are deleted through the spam tab.');
        self::assertSame($real->id, $left[0]->id);
    }

    public function testTheSpamTabHasItsOwnPermissions(): void
    {
        $this->client->request('GET', '/admin/config');
        $config = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('eekes_form_builder.forms.edit_form.spam', $config);
        self::assertStringContainsString(Submission::SPAM_SECURITY_CONTEXT, $config);
    }

    private function store(Form $form, string $email, bool $spam = false, bool $withAnswers = true): Submission
    {
        $submission = new Submission(self::entityManager()->getReference(Form::class, $form->id) ?? $form, 'en', $withAnswers ? [new SubmittedValue('email', 'E-mail', 'email', $email, $email, true)] : []);

        if ($spam) {
            $submission->markAsSpam('honeypot', 'The hidden honeypot field was filled in.');
            $submission->ipAddress = '203.0.113.7';
            $submission->userAgent = 'Mozilla/5.0 (bot)';
        }

        self::entityManager()->persist($submission);
        self::entityManager()->flush();

        return $submission;
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
