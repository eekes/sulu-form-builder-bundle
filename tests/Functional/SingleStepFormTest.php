<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Event\FieldBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCreatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Rendering\FormRenderer;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Regex;

#[CoversNothing]
final class SingleStepFormTest extends WebsiteTestCase
{
    public function testTheFormIsRenderedWithTheGridTheEditorBuilt(): void
    {
        $html = $this->render(self::createContactForm(), 'https://example.com/contact');

        self::assertStringContainsString('id="eekes_form_contact"', $html);
        self::assertMatchesRegularExpression('/<div[^>]*class="col-12 col-md-6 col-lg-6 mb-3"[^>]*data-eekes-field="name"/', $html);
        self::assertMatchesRegularExpression('/<div[^>]*class="col-12 col-md-12 col-lg-12 mb-3"[^>]*data-eekes-field="message"/', $html);
        self::assertStringContainsString('name="eekes_form_contact[step1][email]"', $html);
        self::assertStringContainsString('>Name<', $html);
        self::assertStringContainsString('name="eekes_form_contact[navigator][finish]"', $html);
        self::assertStringNotContainsString('[navigator][next]', $html, 'A one-step form only has the finish button.');
        self::assertStringNotContainsString('eekes-form-progress', $html, 'Nor a progress bar.');
        self::assertStringNotContainsString('_token', $html, 'The page is cached, so a CSRF token would be shared by every visitor.');
        self::assertStringContainsString('bundles/eekessuluformbuilder/js/form-builder.js', $html);
    }

    public function testAValidSubmissionIsStoredAndTheVisitorRedirectedToTheMessage(): void
    {
        $form = self::createContactForm();

        $response = $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => "Hi\nthere"], query: 'utm=x');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.com/contact?utm=x&submitted=contact#eekes_form_contact', $response->getTargetUrl());

        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertSame('Jane', $submissions[0]->value('name'));
        self::assertSame("Hi\nthere", $submissions[0]->value('message'));
        $email = $submissions[0]->get('email');
        self::assertNotNull($email);
        self::assertSame('E-mail', $email->label);
        self::assertTrue($email->showInList);
        self::assertSame('en', $submissions[0]->locale);
        self::assertSame('https://example.com/contact', $submissions[0]->url);
    }

    public function testTheSuccessMessageReplacesTheFormAfterTheRedirect(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->setText('en', 'successMessage', '<p>Thanks, we will be in touch.</p>'));

        $html = $this->render($form, 'https://example.com/contact?submitted=contact');

        self::assertStringContainsString('<p>Thanks, we will be in touch.</p>', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testWithoutAMessageTheDefaultOneIsShown(): void
    {
        $html = $this->render(self::createContactForm(), 'https://example.com/contact?submitted=contact');

        self::assertStringContainsString('eekes-form-success', $html);
        self::assertStringContainsString('Thank you', $html);
    }

    public function testTheMessageOfAnotherFormDoesNotReplaceThisOne(): void
    {
        $html = $this->render(self::createContactForm(), 'https://example.com/contact?submitted=newsletter');

        self::assertStringContainsString('<form', $html);
    }

    public function testAnInvalidSubmissionIsRenderedWithItsErrorsAndA422(): void
    {
        $form = self::createContactForm();

        $response = $this->submit($form, 'step1', ['name' => '', 'email' => 'not an address']);

        self::assertNull($response, 'The page renders as usual, with the errors in the form.');
        self::assertSame([], self::submissions());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->pageStatus());

        $html = $this->render($form);
        self::assertStringContainsString('is-invalid', $html);
        self::assertStringContainsString('value="not an address"', $html, 'What the visitor typed is kept.');
    }

    public function testAFormCanRedirectToAnExternalUrl(): void
    {
        $form = self::createContactForm(static function (Form $form): void {
            $form->setAfterSubmit(AfterSubmit::Redirect);
            $form->redirectUrl = 'https://example.com/thank-you';
        });

        $response = $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.com/thank-you', $response->getTargetUrl());
    }

    public function testAFormThatDoesNotStoreSubmissionsStillTellsItsListeners(): void
    {
        $form = self::createContactForm(static fn (Form $form) => $form->storeSubmissions = false);

        $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertSame([], self::submissions());
        $created = self::recorder()->recorded(SubmissionCreatedEvent::class);
        self::assertCount(1, $created);
        self::assertFalse($created[0]->submission->isPersisted());
        self::assertSame('jane@example.com', $created[0]->submission->value('email'));
    }

    public function testSpamIsAnsweredLikeASuccessButOnlyKeptAsBlocked(): void
    {
        $form = self::createContactForm();
        self::recorder()->on(SubmissionValidatedEvent::class, static fn (SubmissionValidatedEvent $event) => $event->markAsSpam('Known spammer.'));

        $response = $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('submitted=contact', $response->getTargetUrl());
        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertTrue($submissions[0]->isSpam());
        self::assertSame('listener', $submissions[0]->spamCheck());
        self::assertSame('Known spammer.', $submissions[0]->spamReason());
        self::assertSame([], self::recorder()->recorded(SubmissionCreatedEvent::class));
        self::assertTrue(self::recorder()->recorded(SubmissionCompletedEvent::class)[0]->spam);
    }

    public function testAListenerCanAnswerTheVisitorItself(): void
    {
        $form = self::createContactForm();
        self::recorder()->on(SubmissionCompletedEvent::class, static fn (SubmissionCompletedEvent $event) => $event->setResponse(new RedirectResponse('https://pay.example.com')));

        $response = $this->submit($form, 'step1', ['name' => 'Jane', 'email' => 'jane@example.com']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://pay.example.com', $response->getTargetUrl());
    }

    public function testAListenerCanChangeAFieldWithNativeSymfonyOptions(): void
    {
        $form = self::createContactForm();
        self::recorder()->on(FieldBuildEvent::class, static function (FieldBuildEvent $event): void {
            if ($event->field->key === 'name') {
                $event->setOption('constraints', [new Regex(pattern: '/^[A-Z]/', message: 'Starts with a capital.')]);
            }

            if ($event->field->key === 'message') {
                $event->remove();
            }
        });

        self::assertStringNotContainsString('[message]', $this->render($form, 'https://example.com/contact'));

        $this->submit($form, 'step1', ['name' => 'jane', 'email' => 'jane@example.com']);

        self::assertSame([], self::submissions());
        self::assertStringContainsString('Starts with a capital.', $this->render($form));
    }

    public function testAFieldTypeOfTheApplicationWorksLikeABuiltInOne(): void
    {
        $form = self::createForm('feedback', [['key' => 'step1', 'fields' => [
            ['key' => 'score', 'type' => 'grade', 'required' => true, 'options' => ['max' => 3], 'translations' => ['en' => ['label' => 'Score']]],
        ]]]);

        self::assertStringContainsString('name="eekes_form_feedback[step1][score]"', $this->render($form, 'https://example.com/feedback'));

        $this->submit($form, 'step1', ['score' => '3']);

        $score = self::submissions()[0]->get('score');
        self::assertNotNull($score);
        self::assertSame('***', $score->display);
    }

    public function testTheScriptUrlChangesWithTheScript(): void
    {
        $html = $this->render(self::createContactForm(), 'https://example.com/contact');

        self::assertSame(8, \strlen(FormRenderer::scriptVersion()));
        self::assertStringContainsString('form-builder.js?v='.FormRenderer::scriptVersion().'"', $html, 'A browser with an older version cached loads the new one.');
    }

    public function testTimesAndRatingsAreRenderedNativelyAndStoredAsTyped(): void
    {
        $form = self::createForm('appointment', [['key' => 'step1', 'fields' => [
            ['key' => 'time', 'type' => 'time', 'required' => true, 'translations' => ['en' => ['label' => 'Time']]],
            ['key' => 'moment', 'type' => 'datetime', 'translations' => ['en' => ['label' => 'Moment']]],
            ['key' => 'stars', 'type' => 'rating', 'required' => true, 'options' => ['max' => 3], 'translations' => ['en' => ['label' => 'Stars']]],
            ['key' => 'nps', 'type' => 'rating', 'options' => ['display' => 'scale', 'min' => '0', 'max' => 10], 'translations' => ['en' => ['label' => 'Recommend us?', 'lowLabel' => 'Not likely', 'highLabel' => 'Very likely']]],
        ]]]);

        $html = $this->render($form, 'https://example.com/appointment');

        self::assertStringContainsString('type="time" id="eekes_form_appointment_step1_time"', $html);
        self::assertStringContainsString('type="datetime-local"', $html);
        self::assertStringContainsString('eekes-form-rating eekes-form-rating-stars', $html);
        self::assertSame(3, substr_count($html, 'name="eekes_form_appointment[step1][stars]"'));
        self::assertStringContainsString('<span aria-hidden="true">★</span><span class="visually-hidden">3</span>', $html);
        self::assertSame(11, substr_count($html, 'name="eekes_form_appointment[step1][nps]"'), 'A scale from 0 to 10.');
        self::assertStringContainsString('Not likely', $html);
        self::assertStringContainsString('Very likely', $html);

        $this->submit($form, 'step1', ['time' => '09:15', 'moment' => '2026-12-24T18:00', 'stars' => '9']);

        self::assertSame([], self::submissions(), 'Nine is not one of three stars.');

        $this->submit($form, 'step1', ['time' => '09:15', 'moment' => '2026-12-24T18:00', 'stars' => '2', 'nps' => '0']);

        $submission = self::submissions()[0];
        self::assertSame('09:15', $submission->get('time')?->value);
        self::assertSame('2026-12-24 18:00', $submission->get('moment')?->value);
        self::assertSame(2, $submission->get('stars')?->value);
        self::assertSame('2 / 3', $submission->get('stars')->display);
        self::assertSame(0, $submission->get('nps')?->value, 'Zero is an answer, not an empty field.');
    }

    public function testARepeaterTakesAsManyRowsAsTheVisitorFillsIn(): void
    {
        $form = self::createForm('event', [['key' => 'step1', 'fields' => [
            ['key' => 'name', 'type' => 'text', 'translations' => ['en' => ['label' => 'Your name']]],
            ['key' => 'people', 'type' => 'repeater', 'required' => true, 'options' => ['max' => 3], 'translations' => ['en' => [
                'label' => 'Participants',
                'rowLabel' => 'Participant',
                'addLabel' => 'Add a participant',
                'fields' => [
                    ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true, 'width' => 6],
                    ['type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'width' => 6],
                ],
            ]]],
        ]]]);

        $html = $this->render($form, 'https://example.com/event');

        self::assertStringContainsString('data-eekes-repeater="people"', $html);
        self::assertStringContainsString('data-eekes-repeater-max="3"', $html);
        self::assertStringContainsString('name="eekes_form_event[step1][people][0][name]"', $html, 'A required repeater starts with one row, also without the script.');
        self::assertStringNotContainsString('[people][1]', $html);
        self::assertStringContainsString('name=&quot;eekes_form_event[step1][people][__row__][email]&quot;', $html, 'The prototype the script copies.');
        self::assertStringContainsString('Participant <span data-eekes-repeater-number>1</span>', $html);
        self::assertStringContainsString('Add a participant', $html);
        self::assertMatchesRegularExpression('/<input type="text"[^>]*name="eekes_form_event\[step1\]\[people\]\[0\]\[name\]"[^>]*required="required"/', $html, 'A required sub-field is required in the browser too.');
        self::assertStringContainsString('col-md-6', $html);

        // The only row left empty: the repeater is required, and so is the name in a row.
        $this->submit($form, 'step1', ['name' => 'Organiser', 'people' => [['name' => '', 'email' => '']]]);
        self::assertSame([], self::submissions());

        // A row with only an e-mail address misses its name.
        $this->submit($form, 'step1', ['name' => 'Organiser', 'people' => [['name' => '', 'email' => 'jane@example.com']]]);
        self::assertSame([], self::submissions());

        // More rows than allowed.
        $this->submit($form, 'step1', ['name' => 'Organiser', 'people' => array_fill(0, 4, ['name' => 'Jane', 'email' => ''])]);
        self::assertSame([], self::submissions());

        // Two rows filled, one left empty, and the indexes have a gap after a row was removed.
        $this->submit($form, 'step1', ['name' => 'Organiser', 'people' => [
            0 => ['name' => 'Jane', 'email' => 'jane@example.com'],
            2 => ['name' => '', 'email' => ''],
            5 => ['name' => 'Joe', 'email' => ''],
        ]]);

        $people = self::submissions()[0]->get('people');
        self::assertNotNull($people);
        self::assertSame([['name' => 'Jane', 'email' => 'jane@example.com'], ['name' => 'Joe', 'email' => null]], $people->value);
        self::assertSame("1. Name: Jane, E-mail: jane@example.com\n2. Name: Joe", $people->display);
        self::assertSame('Organiser', self::submissions()[0]->get('name')?->value, 'A sub-field with the key of a field of the form does not mix with it.');
    }

    public function testAFieldOfARemovedTypeIsSkippedInsteadOfBreakingThePage(): void
    {
        $form = self::createForm('legacy', [['key' => 'step1', 'fields' => [
            ['key' => 'old', 'type' => 'signature'],
            ['key' => 'name', 'type' => 'text'],
        ]]]);

        $html = $this->render($form, 'https://example.com/legacy');

        self::assertStringContainsString('[step1][name]', $html);
        self::assertStringNotContainsString('[step1][old]', $html);
    }

    public function testAFormCanLiveInATurboFrame(): void
    {
        $form = self::createContactForm();
        $renderer = self::service(FormRenderer::class);
        $this->render($form, 'https://example.com/contact');

        $html = $renderer->render('contact', ['turbo_frame' => true]);

        self::assertStringContainsString('<turbo-frame class="eekes-form-wrapper" id="eekes_form_contact"', $html);
        self::assertStringContainsString('</turbo-frame>', $html);
        self::assertStringNotContainsString('data-turbo-frame="_top"', $html);
    }

    public function testTheSuccessMessageReplacesTheSameTurboFrame(): void
    {
        $form = self::createContactForm();
        $this->render($form, 'https://example.com/contact?submitted=contact');

        $html = self::service(FormRenderer::class)->render('contact', ['turbo_frame' => true]);

        self::assertStringContainsString('<turbo-frame class="eekes-form-wrapper" id="eekes_form_contact"', $html);
        self::assertStringContainsString('eekes-form-success', $html);
    }

    public function testAFormThatRedirectsBreaksOutOfItsTurboFrame(): void
    {
        $form = self::createContactForm(static function (Form $form): void {
            $form->setAfterSubmit(AfterSubmit::Redirect);
            $form->redirectUrl = 'https://example.com/thank-you';
        });
        $this->render($form, 'https://example.com/contact');

        $html = self::service(FormRenderer::class)->render('contact', ['turbo_frame' => true]);

        self::assertStringContainsString('data-turbo-frame="_top"', $html);
    }

    public function testAnUnknownFormRendersNothing(): void
    {
        self::assertSame('', self::service(FormRenderer::class)->render('nope'));
    }
}
