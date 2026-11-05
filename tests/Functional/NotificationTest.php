<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Event\NotificationSendingEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener\NotificationDeliveryListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[CoversNothing]
final class NotificationTest extends WebsiteTestCase
{
    public function testTheTeamGetsAnEMailWithTheAnswers(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => '<b>Hi</b>']);

        self::assertEmailCount(1);
        $email = $this->email(0);
        self::assertSame(['team@example.com'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('website@example.com', $email->getFrom()[0]->getAddress(), 'The sender configured for the bundle.');
        self::assertSame('Example website', $email->getFrom()[0]->getName());
        self::assertSame('New message from Jane', $email->getSubject());

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('<p>Jane wrote:</p>', $html);
        self::assertStringContainsString('E-mail', $html);
        self::assertStringContainsString('jane@example.com', $html);
        self::assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt;', $html, 'What a visitor typed is never HTML.');
    }

    public function testAConfirmationGoesToTheAddressTheVisitorFilledIn(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, '{{ email }}', static function (Notification $notification): void {
            $notification->replyTo = 'info@example.com';
            $notification->fromEmail = 'noreply@example.com';
            $notification->fromName = 'Example';
            $notification->includeValues = false;
        });

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $email = $this->email(0);
        self::assertSame('jane@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('info@example.com', $email->getReplyTo()[0]->getAddress());
        self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
        self::assertStringNotContainsString('E-mail', (string) $email->getHtmlBody(), 'The answers are left out.');
    }

    public function testANotificationOnlyGoesOutWhenItsConditionsAreMet(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'sales@example.com', static fn (Notification $notification) => $notification->setConditions(ConditionSet::of(new Condition('message', ConditionOperator::Contains, 'price'))));
        self::createNotification($form, 'support@example.com', static fn (Notification $notification) => $notification->setConditions(ConditionSet::of(new Condition('message', ConditionOperator::NotContains, 'price'))));

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'What is the PRICE?']);

        self::assertEmailCount(1);
        self::assertSame('sales@example.com', $this->email(0)->getTo()[0]->getAddress());
    }

    public function testADisabledNotificationIsNotSent(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com', static fn (Notification $notification) => $notification->enabled = false);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertEmailCount(0);
    }

    public function testAnAddressAVisitorMistypedIsDroppedButTheRestStillGoesOut(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com; {{ name }}, boss@example.com');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame(['team@example.com', 'boss@example.com'], array_map(static fn (Address $address): string => $address->getAddress(), $this->email(0)->getTo()));
    }

    public function testANotificationWithoutAnyValidRecipientIsSkipped(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, '{{ name }}');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertEmailCount(0);
        self::assertCount(1, self::submissions(), 'The submission is stored all the same.');
    }

    public function testAListenerCanChangeOrCancelAnEMail(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::createNotification($form, 'other@example.com');
        self::recorder()->on(NotificationSendingEvent::class, static function (NotificationSendingEvent $event): void {
            if ($event->email->getTo()[0]->getAddress() === 'other@example.com') {
                $event->cancel();

                return;
            }

            $event->email->addBcc('archive@example.com');
        });

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertEmailCount(1);
        self::assertSame('archive@example.com', $this->email(0)->getBcc()[0]->getAddress());
    }

    public function testSpamIsNotMailed(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::recorder()->on(SubmissionValidatedEvent::class, static fn ($event) => $event->markAsSpam());

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertEmailCount(0);
    }

    public function testTheSubjectAndTextFollowTheLanguageOfTheSubmission(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->title = 'Contact');
        self::createNotification($form, 'team@example.com', static function (Notification $notification): void {
            $notification->setSubject('en', '');
            $notification->setSubject('nl', 'Nieuw bericht van {{ name }}');
        });

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame('Nieuw bericht van Jane', $this->email(0)->getSubject(), 'Without an English subject, another language is used rather than none.');
    }

    public function testTheDefaultEMailCarriesTheBrandingAndTheAnswers(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => "Hi\nthere"]);

        $html = (string) $this->email(0)->getHtmlBody();
        self::assertStringContainsString('Example Ltd', $html, 'The configured name at the top.');
        self::assertStringContainsString('#ff6600', $html, 'The configured accent colour.');
        self::assertStringContainsString('Main Street 1, Ghent', $html, 'The configured footer.');
        self::assertStringContainsString('Your answers', $html);
        self::assertStringContainsString('Hi<br />', $html, 'Line breaks of an answer survive.');
        self::assertStringContainsString('Jane wrote:', strip_tags($html), 'The preview line of the inbox is the start of the text.');
        self::assertNotEmpty($this->email(0)->getTextBody(), 'A text version for clients without HTML.');
    }

    public function testEveryEMailIsLoggedAsItWasSent(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(NotificationDelivery::SENT, $deliveries[0]->status, 'The mailer reported it as sent.');
        self::assertSame('New message from Jane', $deliveries[0]->subject);
        self::assertSame(['to' => ['team@example.com']], $deliveries[0]->recipients);
        self::assertSame('card', $deliveries[0]->template);
        self::assertStringContainsString('Jane', (string) $deliveries[0]->htmlBody, 'Exactly what was sent.');
        self::assertNotNull($deliveries[0]->sentAt);
    }

    public function testNotificationsThatWereNotSentAreLoggedWithTheReason(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'off@example.com', static fn (Notification $notification) => $notification->enabled = false);
        self::createNotification($form, 'sales@example.com', static fn (Notification $notification) => $notification->setConditions(ConditionSet::of(new Condition('message', ConditionOperator::Contains, 'price'))));
        self::createNotification($form, '{{ phone }}');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'Hello']);

        self::assertEmailCount(0);
        $deliveries = $this->deliveries();
        self::assertSame([NotificationDelivery::SKIPPED, NotificationDelivery::SKIPPED, NotificationDelivery::SKIPPED], array_map(static fn (NotificationDelivery $delivery): string => $delivery->status, $deliveries));
        self::assertSame('The notification is switched off.', $deliveries[0]->message);
        self::assertSame('The conditions of the notification did not match this submission.', $deliveries[1]->message);
        self::assertStringStartsWith('No valid recipient', (string) $deliveries[2]->message);
    }

    public function testATemplateIsPickedPerNotification(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com', static fn (Notification $notification) => $notification->template = 'summary');
        self::createNotification($form, 'jane@example.com', static fn (Notification $notification) => $notification->template = 'letter');

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        $summary = (string) $this->email(0)->getHtmlBody();
        self::assertStringContainsString('New submission', $summary);
        self::assertStringContainsString('https://example.com/admin/#/forms/en/'.$form->id.'/submissions', $summary, 'A button to the submissions in the admin.');

        self::assertStringContainsString('Dear reader,', (string) $this->email(1)->getHtmlBody(), 'A template of the application, by configuration alone.');
        self::assertSame(['summary', 'letter'], array_map(static fn (NotificationDelivery $delivery): ?string => $delivery->template, $this->deliveries()));
    }

    public function testAnEMailIsSentAgainExactlyAsItWas(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);
        $original = $this->deliveries()[0];

        $again = self::service(NotificationSender::class)->resend($original, 'editor@example.com');

        self::assertEmailCount(2);
        $resent = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $resent);
        self::assertSame($original->subject, $resent->getSubject());
        self::assertSame($original->htmlBody, $resent->getHtmlBody(), 'The stored e-mail, not a new rendering.');
        self::assertSame(NotificationDelivery::TRIGGER_RESEND, $again->trigger);
        self::assertSame('editor@example.com', $again->triggeredBy);
        self::assertSame(NotificationDelivery::SENT, $again->status);
        self::assertCount(2, $this->deliveries());
    }

    public function testSpamIsLoggedAsNotSent(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        self::recorder()->on(SubmissionValidatedEvent::class, static fn (SubmissionValidatedEvent $event) => $event->markAsSpam());

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertEmailCount(0);
        self::assertSame('The submission was blocked as spam.', $this->deliveries()[0]->message);
    }

    public function testAFailureOfTheMailerIsLogged(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);
        $delivery = $this->deliveries()[0];

        // What the mailer dispatches when the server refuses - in the request, or in a worker.
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->addTextHeader(NotificationDelivery::HEADER, (string) $delivery->id);
        self::service(NotificationDeliveryListener::class)->onFailed(new FailedMessageEvent($email, new TransportException('550 Mailbox unavailable')));

        $failed = $this->deliveries()[0];
        self::assertSame(NotificationDelivery::FAILED, $failed->status);
        self::assertSame('550 Mailbox unavailable', $failed->message);
    }

    /**
     * @return list<NotificationDelivery>
     */
    private function deliveries(): array
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(NotificationDelivery::class)->findBy([], ['id' => 'ASC']);
    }

    private function email(int $index): Email
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(TemplatedEmail::class, $email);

        return $email;
    }
}
