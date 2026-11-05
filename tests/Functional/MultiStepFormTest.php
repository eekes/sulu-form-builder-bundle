<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Event\StepCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A three-step quote request: who you are, what you need (with a budget only for companies),
 * and a final step that is skipped unless the visitor wants a call back.
 */
#[CoversNothing]
final class MultiStepFormTest extends WebsiteTestCase
{
    public function testTheFirstStepIsShownWithAProgressIndicator(): void
    {
        $html = $this->render($this->quoteForm(), 'https://example.com/quote');

        self::assertStringContainsString('eekes-form-progress', $html);
        self::assertStringContainsString('About you', $html);
        self::assertStringContainsString('[about][name]', $html);
        self::assertStringNotContainsString('[project]', $html, 'Only the current step is rendered.');
        self::assertStringContainsString('[navigator][next]', $html);
        self::assertStringNotContainsString('[navigator][previous]', $html);
        self::assertStringNotContainsString('[navigator][finish]', $html);
    }

    public function testTheVisitorWalksThroughEveryStepBeforeTheFormIsSent(): void
    {
        $form = $this->quoteForm();

        self::assertNull($this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next'));
        self::assertSame([], self::submissions(), 'Nothing is stored halfway.');

        $html = $this->render($form);
        self::assertStringContainsString('[project][budget]', $html);
        self::assertStringContainsString('[navigator][previous]', $html);

        // The call-back step is skipped because nobody asked for a call, so "next" sends the form.
        $response = $this->submit($form, 'project', ['description' => 'A website', 'budget' => '5000'], 'next');
        self::assertInstanceOf(RedirectResponse::class, $response);

        $submissions = self::submissions();
        self::assertCount(1, $submissions);
        self::assertSame(['name' => 'Jane', 'type' => 'company', 'description' => 'A website', 'budget' => 5000, 'callback' => false], $submissions[0]->toArray());
    }

    public function testEnterInAFieldGoesForwardInsteadOfBack(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');

        $html = $this->render($form);
        $next = strpos($html, '[navigator][next]');
        $previous = strpos($html, '[navigator][previous]');

        self::assertNotFalse($next);
        self::assertNotFalse($previous);
        self::assertLessThan($previous, $next, 'A browser presses the first submit button of a form on enter.');
        self::assertStringContainsString('flex-row-reverse', $html, '"Previous" is still shown on the left.');
    }

    public function testEveryCompletedStepIsAnnouncedWithTheAnswersSoFar(): void
    {
        $form = $this->quoteForm();

        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');

        $events = self::recorder()->recorded(StepCompletedEvent::class);
        self::assertCount(1, $events);
        self::assertSame('about', $events[0]->step->key);
        self::assertSame(['name' => 'Jane', 'type' => 'company'], $events[0]->values);
    }

    public function testAnInvalidStepStaysPut(): void
    {
        $form = $this->quoteForm();

        $this->submit($form, 'about', ['name' => '', 'type' => 'company'], 'next');

        $html = $this->render($form);
        self::assertStringContainsString('[about][name]', $html);
        self::assertStringContainsString('is-invalid', $html);
        self::assertSame([], self::recorder()->recorded(StepCompletedEvent::class));
    }

    public function testGoingBackKeepsWhatWasFilledIn(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');

        $this->submit($form, 'project', [], 'previous');

        $html = $this->render($form);
        self::assertStringContainsString('[about][name]', $html);
        self::assertStringContainsString('value="Jane"', $html);
    }

    public function testTheRowsOfARepeaterSurviveTheStepsAndDecideALaterStep(): void
    {
        $form = self::createForm('event', [
            ['key' => 'people', 'fields' => [
                ['key' => 'participants', 'type' => 'repeater', 'required' => true, 'translations' => ['en' => ['label' => 'Participants', 'fields' => [
                    ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
                    ['type' => 'checkbox', 'label' => 'Vegetarian', 'key' => 'vegetarian'],
                ]]]],
            ]],
            ['key' => 'food', 'visibleIf' => ['field' => 'participants', 'operator' => 'contains', 'value' => '1'], 'fields' => [
                ['key' => 'menu', 'type' => 'textarea', 'translations' => ['en' => ['label' => 'Wishes for the vegetarian menu']]],
            ]],
            ['key' => 'confirm', 'fields' => [
                ['key' => 'remarks', 'type' => 'text', 'translations' => ['en' => ['label' => 'Remarks']]],
            ]],
        ]);

        $this->submit($form, 'people', ['participants' => [['name' => 'Jane', 'vegetarian' => '1'], ['name' => 'Joe']]], 'next');
        self::assertStringContainsString('[food][menu]', $this->render($form), 'A ticked box in any row counts.');

        $this->submit($form, 'food', [], 'previous');

        $html = $this->render($form);
        self::assertStringContainsString('name="eekes_form_event[people][participants][1][name]"', $html, 'Back on the step, both rows are there.');
        self::assertStringContainsString('value="Joe"', $html);

        $this->submit($form, 'people', ['participants' => [['name' => 'Jane'], ['name' => 'Joe']]], 'next');
        self::assertStringContainsString('[confirm][remarks]', $this->render($form), 'Nobody vegetarian any more: the food step is skipped.');

        $this->submit($form, 'confirm', ['remarks' => ''], 'finish');

        $participants = self::submissions()[0]->get('participants');
        self::assertNotNull($participants);
        self::assertSame([['name' => 'Jane', 'vegetarian' => false], ['name' => 'Joe', 'vegetarian' => false]], $participants->value);
    }

    public function testAFieldWhoseConditionIsNotMetIsNeitherValidatedNorStored(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'private'], 'next');

        // The budget is required for companies, and hidden for everybody else.
        $this->submit($form, 'project', ['description' => 'A garden', 'budget' => '', 'callback' => '1'], 'next');

        $html = $this->render($form);
        self::assertStringContainsString('[callback_details][phone]', $html, 'The budget did not block the step.');

        $this->submit($form, 'callback_details', ['phone' => '+32 470 12 34 56'], 'finish');

        $submission = self::submissions()[0];
        self::assertFalse($submission->has('budget'));
        self::assertSame('+32 470 12 34 56', $submission->value('phone'));
    }

    public function testAnAnswerHiddenByAChangeOnAnEarlierStepIsDropped(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');
        $this->submit($form, 'project', ['description' => 'Shop', 'budget' => '9000'], 'previous');
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'private'], 'next');

        $this->submit($form, 'project', ['description' => 'Shop'], 'next');

        self::assertFalse(self::submissions()[0]->has('budget'), 'The visitor can no longer see the budget they filled in.');
    }

    public function testAConditionOnAnEarlierStepIsDecidedByTheServer(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');

        $html = $this->render($form);

        self::assertStringContainsString('[project][budget]', $html);
        self::assertStringNotContainsString('data-eekes-condition', $html, 'Nothing is left for the browser to decide.');
        self::assertStringContainsString('data-eekes-values="{&quot;name&quot;:&quot;Jane&quot;,&quot;type&quot;:&quot;company&quot;}"', $html);
    }

    public function testAVisitorWhoComesBackToTheCachedFirstStepStartsOver(): void
    {
        $form = $this->quoteForm();
        $this->submit($form, 'about', ['name' => 'Jane', 'type' => 'company'], 'next');

        // The page is cached, so the visitor sees step one again while the session remembers step two.
        $this->submit($form, 'about', ['name' => 'John', 'type' => 'private'], 'next');

        $html = $this->render($form);
        self::assertStringContainsString('[project][description]', $html);
        self::assertStringNotContainsString('[project][budget]', $html);
    }

    private function quoteForm(): Form
    {
        return self::createForm('quote', [
            ['key' => 'about', 'titles' => ['en' => 'About you'], 'fields' => [
                ['key' => 'name', 'type' => 'text', 'required' => true, 'translations' => ['en' => ['label' => 'Name']]],
                ['key' => 'type', 'type' => 'choice', 'required' => true, 'options' => ['display' => 'radio'], 'translations' => ['en' => ['label' => 'You are', 'choices' => [['label' => 'A company', 'value' => 'company'], ['label' => 'A private person', 'value' => 'private']]]]],
            ]],
            ['key' => 'project', 'titles' => ['en' => 'Your project'], 'fields' => [
                ['key' => 'description', 'type' => 'textarea', 'required' => true, 'translations' => ['en' => ['label' => 'Description']]],
                ['key' => 'budget', 'type' => 'number', 'required' => true, 'visibleIf' => ['field' => 'type', 'operator' => 'equals', 'value' => 'company'], 'translations' => ['en' => ['label' => 'Budget']]],
                ['key' => 'callback', 'type' => 'checkbox', 'translations' => ['en' => ['label' => 'Call me back']]],
            ]],
            ['key' => 'callback_details', 'titles' => ['en' => 'Call back'], 'visibleIf' => ['field' => 'callback', 'operator' => 'filled'], 'fields' => [
                ['key' => 'phone', 'type' => 'phone', 'required' => true, 'translations' => ['en' => ['label' => 'Phone']]],
            ]],
        ]);
    }
}
