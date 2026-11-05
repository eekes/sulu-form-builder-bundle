<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Domain;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Form::class)]
#[CoversClass(Notification::class)]
final class EntityTest extends TestCase
{
    public function testANewFormHasOneEmptyStep(): void
    {
        $form = new Form('contact', 'Contact');

        self::assertCount(1, $form->structure()->steps);
        self::assertSame(AfterSubmit::Message, $form->afterSubmit());
        self::assertTrue($form->storeSubmissions);
    }

    public function testAFormKeyIsSafeInAUrlAndATemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Form('Contact Form', 'Contact');
    }

    public function testTextsAreStoredPerLocale(): void
    {
        $form = new Form('contact', 'Contact');
        $form->setText('en', 'submitLabel', 'Send');
        $form->setText('nl', 'submitLabel', 'Verzenden');
        $form->setText('de', 'submitLabel', '');

        self::assertSame('Send', $form->text('en', 'submitLabel'));
        self::assertSame('Verzenden', $form->text('nl', 'submitLabel'));
        self::assertNull($form->text('de', 'submitLabel'), 'An empty text means "use the default".');
        self::assertNull($form->text('fr', 'submitLabel'));
    }

    public function testOnlyKnownTextsCanBeSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Form('contact', 'Contact'))->setText('en', 'colour', 'red');
    }

    public function testACloneIsANewFormWithFreshTimestamps(): void
    {
        $form = new Form('contact', 'Contact');
        $form->id = 4;

        $copy = clone $form;

        self::assertFalse(isset($copy->id));
        self::assertSame('Contact', $copy->title);
    }

    public function testANotificationInALanguageNobodyWroteFallsBackToOneThatExists(): void
    {
        $notification = new Notification(new Form('contact', 'Contact'), 'Team');
        $notification->setSubject('nl', 'Nieuwe aanvraag');
        $notification->setBody('nl', '<p>Hallo</p>');

        self::assertSame('Nieuwe aanvraag', $notification->subject('fr'));
        self::assertSame('<p>Hallo</p>', $notification->body('fr'));
        self::assertSame(['subject' => null, 'body' => null], $notification->ownTexts('fr'), 'The admin shows what was stored, not the fallback.');
    }

    public function testConditionsOfANotificationAreValueObjects(): void
    {
        $notification = new Notification(new Form('contact', 'Contact'), 'Sales');
        $notification->setConditions(ConditionSet::of(new Condition('department', ConditionOperator::Equals, 'sales')));

        self::assertEquals(ConditionSet::of(new Condition('department', ConditionOperator::Equals, 'sales')), $notification->conditions());

        $notification->setConditions(null);
        self::assertNull($notification->conditions(), 'No conditions means always.');
    }
}
