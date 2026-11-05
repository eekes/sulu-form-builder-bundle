<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCreatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\Honeypot\HoneypotSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\ValueCollector;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration\FakeCrmIntegration;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Spam\FakeCaptchaCheck;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The spam checks of a form, played as a visitor and as a bot: what is rendered, what is caught,
 * and what a caught submission leaves behind.
 */
#[CoversNothing]
final class SpamProtectionTest extends WebsiteTestCase
{
    public function testEveryFormHasAHoneypotUntilAnEditorTurnsItOff(): void
    {
        $html = $this->render(self::createContactForm(), 'https://example.com/contact');

        self::assertStringContainsString('name="eekes_form_contact[step1][_website]"', $html);
        self::assertMatchesRegularExpression('/<div class="eekes-form-hp" aria-hidden="true" style="position: absolute; left: -10000px;[^"]*">/', $html);
        self::assertStringContainsString('tabindex="-1"', $html);

        $off = self::createForm('newsletter', [['key' => 'step1', 'fields' => [['key' => 'email', 'type' => 'email']]]], static fn (Form $form) => $form->setSpamChecks([]));
        self::assertStringNotContainsString('_website', $this->render($off));
    }

    public function testAPersonLeavesTheHoneypotEmpty(): void
    {
        $this->submit(self::createContactForm(), 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', HoneypotSpamCheck::FIELD => '']);

        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertFalse($submissions[0]->isSpam());
        self::assertNull($submissions[0]->ipAddress, 'The IP address of a real submission is not kept.');
        self::assertFalse($submissions[0]->has(HoneypotSpamCheck::FIELD), 'A field of a spam check is never an answer.');
    }

    public function testABotThatFillsInTheHoneypotIsAnsweredLikeAPersonButBlocked(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::createFlow($form, 'fake_crm', 'create_lead', ['email' => ['source' => 'field', 'field' => 'email']]);

        $response = $this->submit($form, 'step1', ['name' => 'Bot', 'email' => 'bot@example.com', HoneypotSpamCheck::FIELD => 'https://spam.example.com']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('submitted=contact', $response->getTargetUrl(), 'A bot never learns it was caught.');

        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertTrue($submissions[0]->isSpam());
        self::assertSame(HoneypotSpamCheck::KEY, $submissions[0]->spamCheck());
        self::assertSame('The hidden honeypot field was filled in.', $submissions[0]->spamReason());
        self::assertSame('bot@example.com', $submissions[0]->value('email'), 'What it sent is kept, so a person caught by mistake can be released.');
        self::assertSame('127.0.0.1', $submissions[0]->ipAddress);

        self::assertEmailCount(0);
        self::assertSame([], self::service(FakeCrmIntegration::class)->requests, 'No integration sees spam.');
        self::assertSame('The submission was blocked as spam.', self::runs()[0]->message);
        self::assertSame([], self::recorder()->recorded(SubmissionCreatedEvent::class));
        self::assertTrue(self::recorder()->recorded(SubmissionCompletedEvent::class)[0]->spam);
        self::assertSame(HoneypotSpamCheck::KEY, self::recorder()->recorded(SubmissionValidatedEvent::class)[0]->getSpamVerdict()?->check, 'Listeners know a check caught it.');
    }

    public function testABlockedSubmissionKeepsNoFiles(): void
    {
        $form = self::createForm('apply', [['key' => 'step1', 'fields' => [
            ['key' => 'name', 'type' => 'text'],
            ['key' => 'cv', 'type' => 'file'],
        ]]]);
        $path = tempnam(sys_get_temp_dir(), 'cv');
        self::assertIsString($path);
        file_put_contents($path, '%PDF');

        $stored = [];
        self::recorder()->on(SubmissionValidatedEvent::class, static function (SubmissionValidatedEvent $event) use (&$stored): void {
            $stored = $event->submission->files();
        });

        $this->submit($form, 'step1', ['name' => 'Bot', HoneypotSpamCheck::FIELD => 'x'], files: ['cv' => new UploadedFile($path, 'cv.pdf', 'application/pdf', null, true)]);

        $submission = self::submissions()[0];
        self::assertTrue($submission->isSpam());
        self::assertSame([], $submission->files());
        self::assertSame('cv.pdf', $submission->get('cv')?->display, 'Only the name of the file is kept.');

        self::assertCount(1, $stored);
        self::assertFileDoesNotExist(self::service(UploadStorageInterface::class)->absolutePath($stored[0]), 'The file itself is gone.');
    }

    public function testACheckOfTheApplicationOnlyDecidesWhenTheVisitorFinishes(): void
    {
        $form = self::createForm('quote', [
            ['key' => 'about', 'fields' => [['key' => 'name', 'type' => 'text']]],
            ['key' => 'project', 'fields' => [['key' => 'description', 'type' => 'textarea']]],
        ], static fn (Form $form) => $form->setSpamChecks(['fake_captcha']));

        self::assertStringContainsString('[about][_captcha]', $this->render($form, 'https://example.com/contact'));
        self::assertStringNotContainsString('_website', $this->render($form), 'Only the checks the form uses.');

        $this->submit($form, 'about', ['name' => 'Bot', FakeCaptchaCheck::FIELD => 'bot'], 'next');
        self::assertSame(0, $this->captcha()->checks, 'Never on "next": a token can only be verified once.');

        $this->submit($form, 'project', ['description' => 'Buy now', FakeCaptchaCheck::FIELD => 'bot']);
        self::assertSame(1, $this->captcha()->checks);

        $submission = self::submissions()[0];
        self::assertTrue($submission->isSpam());
        self::assertSame('fake_captcha', $submission->spamCheck());
        self::assertSame(0.1, $submission->spamScore());
    }

    public function testNextFinishesTheFormWhenTheRemainingStepsAreSkipped(): void
    {
        $form = self::createForm('quote', [
            ['key' => 'about', 'fields' => [['key' => 'name', 'type' => 'text'], ['key' => 'more', 'type' => 'checkbox']]],
            ['key' => 'details', 'visibleIf' => ['field' => 'more', 'operator' => 'filled'], 'fields' => [['key' => 'description', 'type' => 'textarea']]],
        ], static fn (Form $form) => $form->setSpamChecks(['fake_captcha']));

        $this->submit($form, 'about', ['name' => 'Bot', FakeCaptchaCheck::FIELD => 'bot'], 'next');

        self::assertSame(1, $this->captcha()->checks);
        self::assertTrue(self::submissions()[0]->isSpam());
    }

    public function testACheckThatCannotDecideGivesTheVisitorTheStepBack(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->setSpamChecks(['fake_captcha']));

        $response = $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', FakeCaptchaCheck::FIELD => 'expired']);

        self::assertNull($response);
        self::assertSame(422, $this->pageStatus());
        self::assertStringContainsString('The check expired. Please send the form again.', $this->render($form));
        self::assertCount(0, self::submissions());

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', FakeCaptchaCheck::FIELD => 'fresh']);
        self::assertCount(1, self::submissions(), 'Sending again works.');
        self::assertFalse(self::submissions()[0]->isSpam());
    }

    public function testAnInvalidStepIsNotChecked(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->setSpamChecks(['fake_captcha']));

        $this->submit($form, 'step1', ['name' => '', FakeCaptchaCheck::FIELD => 'bot']);

        self::assertSame(0, $this->captcha()->checks, 'The visitor first fixes the step; the check runs once it is valid.');
    }

    public function testWhatIsKeptOfABlockedSubmissionIsConfigurable(): void
    {
        $form = self::createContactForm();
        $request = Request::create('https://example.com/contact', 'POST', server: ['REMOTE_ADDR' => '203.0.113.77', 'HTTP_USER_AGENT' => 'Bot/1.0']);
        $data = ['step1' => ['name' => 'Bot', 'email' => 'bot@example.com']];
        self::recorder()->on(SubmissionValidatedEvent::class, static fn (SubmissionValidatedEvent $event) => $event->markAsSpam());

        $this->processor(storePayload: false, anonymizeIp: true)->process(self::definition($form), $data, SubmissionContext::fromRequest($request));

        $blocked = self::submissions()[0];
        self::assertSame([], $blocked->values(), 'Without the payload only the request details are kept.');
        self::assertSame('203.0.113.0', $blocked->ipAddress);
        self::assertSame('Bot/1.0', $blocked->userAgent);

        $this->processor(log: false)->process(self::definition($form), $data, SubmissionContext::fromRequest($request));
        self::assertCount(1, self::submissions(), 'Without the log nothing more is stored.');
    }

    private function processor(bool $log = true, bool $storePayload = true, bool $anonymizeIp = false): SubmissionProcessor
    {
        return new SubmissionProcessor(
            self::service(ValueCollector::class),
            self::service(FormRepository::class),
            self::service(SubmissionRepository::class),
            self::service(NotificationSender::class),
            self::service(UploadStorageInterface::class),
            self::service(EventDispatcherInterface::class),
            logSpam: $log,
            storeSpamPayload: $storePayload,
            anonymizeIp: $anonymizeIp,
        );
    }

    private function captcha(): FakeCaptchaCheck
    {
        return self::service(FakeCaptchaCheck::class);
    }
}
