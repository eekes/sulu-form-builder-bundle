<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionFactory;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepStructure;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\FieldTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormDefinitionFactory::class)]
final class FormDefinitionFactoryTest extends TestCase
{
    public function testAFormIsReadInOneLanguage(): void
    {
        $definition = (new FormDefinitionFactory(FieldTypes::registry()))->create($this->form(), 'nl');

        self::assertSame('contact', $definition->key);
        self::assertSame('nl', $definition->locale);
        self::assertSame('Gegevens', $definition->steps[0]->title);
        self::assertSame('Afdeling', $definition->field('department')?->label);
        self::assertSame('Verzenden', $definition->submitLabel);
        self::assertSame(AfterSubmit::Redirect, $definition->afterSubmit);
        self::assertSame('/thanks', $definition->redirectUrl);
    }

    public function testTheLocalizedOptionsOfATypeAreMergedIntoItsOptions(): void
    {
        $field = (new FormDefinitionFactory(FieldTypes::registry()))->create($this->form(), 'nl')->field('department');

        self::assertNotNull($field);
        self::assertSame('radio', $field->option('display'));
        self::assertSame([['label' => 'Verkoop', 'value' => 'sales']], $field->option('choices'));
    }

    public function testALanguageThatWasNeverTranslatedFallsBackToOneThatWas(): void
    {
        $definition = (new FormDefinitionFactory(FieldTypes::registry()))->create($this->form(), 'fr');

        self::assertSame('Gegevens', $definition->steps[0]->title);
        self::assertSame('Afdeling', $definition->field('department')?->label);
        self::assertNull($definition->submitLabel, 'Button labels fall back to the translations of the bundle instead.');
    }

    public function testEveryFieldKnowsItsStep(): void
    {
        $definition = (new FormDefinitionFactory(FieldTypes::registry()))->create($this->form(), 'en');

        self::assertSame('details', $definition->field('department')?->stepKey);
        self::assertSame('more', $definition->field('message')?->stepKey);
        self::assertTrue($definition->isMultiStep());
        self::assertSame(['department', 'message'], array_map(static fn ($field): string => $field->key, $definition->fields()));
    }

    private function form(): Form
    {
        $form = new Form('contact', 'Contact');
        $form->id = 7;
        $form->setAfterSubmit(AfterSubmit::Redirect);
        $form->redirectUrl = '/thanks';
        $form->setText('nl', 'submitLabel', 'Verzenden');
        $form->setStructure(new FormStructure([
            new StepStructure('details', ['nl' => 'Gegevens'], [
                new FieldStructure('department', 'choice', options: ['display' => 'radio'], translations: [
                    'nl' => ['label' => 'Afdeling', 'choices' => [['label' => 'Verkoop', 'value' => 'sales']]],
                    'en' => ['label' => 'Department', 'choices' => [['label' => 'Sales', 'value' => 'sales']]],
                ]),
            ]),
            new StepStructure('more', ['en' => 'More'], [new FieldStructure('message', 'textarea')]),
        ]));

        return $form;
    }
}
