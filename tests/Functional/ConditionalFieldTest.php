<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Event\FieldBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Visibility and requirement that depend on other answers: rule groups with "all" and "any",
 * decided by the browser within a step and by the server on submit.
 */
#[CoversNothing]
final class ConditionalFieldTest extends WebsiteTestCase
{
    public function testTheBrowserGetsTheRuleSetOfAFieldOnTheSameStep(): void
    {
        $html = $this->render($this->form(), 'https://example.com/contact');

        self::assertStringContainsString('data-eekes-condition="{&quot;match&quot;:&quot;any&quot;,&quot;rules&quot;:[{&quot;field&quot;:&quot;topic&quot;', $html);
        self::assertStringContainsString('data-eekes-required-if="{&quot;match&quot;:&quot;all&quot;,&quot;rules&quot;:[{&quot;field&quot;:&quot;callback&quot;', $html);
    }

    public function testAFieldIsVisibleWhenAnyRuleMatches(): void
    {
        $form = $this->form();

        $this->submit($form, 'step1', ['topic' => 'support', 'company' => 'Acme', 'email' => 'jane@example.com']);

        self::assertSame('Acme', self::submissions()[0]->value('company'));
    }

    public function testAFieldIsLeftOutWhenNoRuleMatches(): void
    {
        $form = $this->form();

        $this->submit($form, 'step1', ['topic' => 'billing', 'company' => 'Acme', 'email' => 'jane@example.com']);

        self::assertFalse(self::submissions()[0]->has('company'));
    }

    public function testAGroupMatchesWhenAllOfItsRulesDo(): void
    {
        $form = $this->form();

        // (topic is other AND email contains "@acme") is the second rule of the "any" set.
        $this->submit($form, 'step1', ['topic' => 'other', 'company' => 'Acme', 'email' => 'jane@acme.com']);
        $this->submit($form, 'step1', ['topic' => 'other', 'company' => 'Acme', 'email' => 'jane@example.com']);

        $submissions = self::submissions();
        self::assertSame('Acme', $submissions[0]->value('company'));
        self::assertFalse($submissions[1]->has('company'));
    }

    public function testAFieldBecomesRequiredWhenItsRulesMatch(): void
    {
        $form = $this->form();

        $this->submit($form, 'step1', ['topic' => 'billing', 'email' => 'jane@example.com', 'callback' => '1', 'phone' => '']);

        self::assertSame([], self::submissions(), 'The phone number is required once a call back was asked for.');
        self::assertStringContainsString('is-invalid', $this->render($form));

        $this->submit($form, 'step1', ['topic' => 'billing', 'email' => 'jane@example.com', 'callback' => '1', 'phone' => '+32 470 12 34 56']);
        self::assertCount(1, self::submissions());
    }

    public function testAFieldStaysOptionalWhenItsRulesDoNotMatch(): void
    {
        $form = $this->form();

        $this->submit($form, 'step1', ['topic' => 'billing', 'email' => 'jane@example.com', 'phone' => '']);

        self::assertCount(1, self::submissions());
    }

    public function testListenersSeeTheFieldAsRequiredWhenItIsAddedAgain(): void
    {
        $form = $this->form();
        $required = [];
        self::recorder()->on(FieldBuildEvent::class, static function (FieldBuildEvent $event) use (&$required): void {
            if ($event->field->key === 'phone') {
                $required[] = $event->field->required;
            }
        });

        $this->submit($form, 'step1', ['topic' => 'billing', 'email' => 'jane@example.com', 'callback' => '1', 'phone' => '0470']);

        self::assertSame([false, true], $required);
    }

    public function testARequirementOnAnEarlierStepIsDecidedByTheServer(): void
    {
        $form = self::createForm('signup', [
            ['key' => 'one', 'fields' => [['key' => 'type', 'type' => 'text', 'translations' => ['en' => ['label' => 'Type']]]]],
            ['key' => 'two', 'fields' => [['key' => 'vat', 'type' => 'text', 'requiredIf' => ['match' => 'all', 'rules' => [['field' => 'type', 'operator' => 'equals', 'value' => 'company']]], 'translations' => ['en' => ['label' => 'VAT']]]]],
        ]);

        $this->submit($form, 'one', ['type' => 'company'], 'next');
        $html = $this->render($form);

        self::assertMatchesRegularExpression('/<input[^>]*name="eekes_form_signup\[two\]\[vat\]"[^>]*required="required"/', $html);
        self::assertStringNotContainsString('data-eekes-required-if', $html);
    }

    private function form(): Form
    {
        return self::createForm('contact', [['key' => 'step1', 'fields' => [
            ['key' => 'topic', 'type' => 'text', 'translations' => ['en' => ['label' => 'Topic']]],
            ['key' => 'email', 'type' => 'email', 'required' => true, 'translations' => ['en' => ['label' => 'E-mail']]],
            ['key' => 'company', 'type' => 'text', 'translations' => ['en' => ['label' => 'Company']], 'visibleIf' => ['match' => 'any', 'rules' => [
                ['field' => 'topic', 'operator' => 'equals', 'value' => 'sales'],
                ['match' => 'all', 'rules' => [
                    ['field' => 'topic', 'operator' => 'equals', 'value' => 'other'],
                    ['field' => 'email', 'operator' => 'contains', 'value' => '@acme'],
                ]],
                ['field' => 'topic', 'operator' => 'equals', 'value' => 'support'],
            ]]],
            ['key' => 'callback', 'type' => 'checkbox', 'translations' => ['en' => ['label' => 'Call me back']]],
            ['key' => 'phone', 'type' => 'phone', 'translations' => ['en' => ['label' => 'Phone']], 'requiredIf' => ['match' => 'all', 'rules' => [
                ['field' => 'callback', 'operator' => 'filled'],
            ]]],
        ]]]);
    }
}
