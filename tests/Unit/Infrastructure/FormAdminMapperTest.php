<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Infrastructure;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdminMapper;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\FieldTypes;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormAdminMapper::class)]
final class FormAdminMapperTest extends TestCase
{
    public function testTheBuilderSavesTheTextsOfTheLanguageBeingEdited(): void
    {
        $form = new Form('contact', 'Contact');
        $mapper = $this->mapper();

        $mapper->apply($form, ['builder' => ['steps' => [[
            'key' => 'step1',
            'title' => 'Details',
            'fields' => [[
                'key' => 'department',
                'type' => 'choice',
                'label' => 'Department',
                'placeholder' => 'Pick one',
                'width' => ['desktop' => 6],
                'required' => true,
                'showInList' => true,
                'options' => ['display' => 'radio', 'choices' => [['label' => 'Sales', 'value' => 'sales']]],
            ]],
        ]]]], 'en');

        $field = $form->field('department');
        self::assertNotNull($field);
        self::assertSame(['display' => 'radio'], $field->options, 'Choices are localized, so they are not shared.');
        self::assertSame(['label' => 'Department', 'placeholder' => 'Pick one', 'help' => null, 'choices' => [['label' => 'Sales', 'value' => 'sales']]], $field->translation('en'));
        self::assertSame(6, $field->width->desktop);
        self::assertTrue($field->required);
        self::assertTrue($field->showInList);
        self::assertSame(['en' => 'Details'], $form->structure()->steps[0]->titles);
    }

    public function testTheSubFieldsOfARepeaterShareEverythingButTheirLabels(): void
    {
        $form = new Form('event', 'Event');
        $mapper = $this->mapper();

        $mapper->apply($form, ['builder' => ['steps' => [['key' => 'step1', 'fields' => [[
            'key' => 'people',
            'type' => 'repeater',
            'label' => 'Participants',
            'options' => ['max' => 5, 'rowLabel' => 'Participant', 'fields' => [
                ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
                ['type' => 'email', 'label' => 'E-mail', 'key' => 'email'],
            ]],
        ]]]]]], 'en');

        $builder = Json::of($mapper->toArray($form, 'nl')['builder'])
            ->with('steps.0.fields.0.options.fields.0.label', 'Naam')
            ->with('steps.0.fields.0.options.rowLabel', 'Deelnemer');
        $mapper->apply($form, ['builder' => $builder->data], 'nl');

        // Back in English, the e-mail address becomes required.
        $builder = Json::of($mapper->toArray($form, 'en')['builder'])->with('steps.0.fields.0.options.fields.1.required', true);
        $mapper->apply($form, ['builder' => $builder->data], 'en');

        $field = $form->field('people');
        self::assertNotNull($field);
        self::assertSame(['max' => 5], $field->options);
        self::assertSame('Participant', $field->translation('en')['rowLabel']);
        self::assertSame('Deelnemer', $field->translation('nl')['rowLabel']);
        self::assertSame([
            ['type' => 'text', 'label' => 'Naam', 'key' => 'name', 'required' => true],
            ['type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'required' => true],
        ], $field->translation('nl')['fields'], 'Dutch follows the English change and keeps its own label.');
    }

    public function testEditingAnotherLanguageKeepsTheFirst(): void
    {
        $form = new Form('contact', 'Contact');
        $mapper = $this->mapper();

        $mapper->apply($form, ['builder' => ['steps' => [['key' => 'step1', 'title' => 'Details', 'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Name']]]]]], 'en');

        // What the admin loads in Dutch is what it sends back, with only the Dutch texts changed.
        $builder = Json::of($mapper->toArray($form, 'nl')['builder']);
        self::assertSame('Name', $builder->at('steps.0.fields.0.label'), 'An untranslated field starts from the existing language.');
        $builder = $builder->with('steps.0.title', 'Gegevens')->with('steps.0.fields.0.label', 'Naam');

        $mapper->apply($form, ['builder' => $builder->data], 'nl');

        $field = $form->field('name');
        self::assertNotNull($field);
        self::assertSame('Name', $field->translation('en')['label']);
        self::assertSame('Naam', $field->translation('nl')['label']);
        self::assertSame(['en' => 'Details', 'nl' => 'Gegevens'], $form->structure()->steps[0]->titles);
    }

    public function testRenamingAFieldKeepsTheTranslationsOfOtherLanguages(): void
    {
        $form = new Form('contact', 'Contact');
        $mapper = $this->mapper();
        $mapper->apply($form, ['builder' => ['steps' => [['fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Name']]]]]], 'en');
        $mapper->apply($form, ['builder' => ['steps' => [['fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Naam', 'translations' => $form->field('name')?->translations]]]]]], 'nl');

        $mapper->apply($form, ['builder' => ['steps' => [['fields' => [['key' => 'full_name', 'type' => 'text', 'label' => 'Full name', 'translations' => $form->field('name')?->translations]]]]]], 'en');

        self::assertSame('Naam', $form->field('full_name')?->translation('nl')['label']);
    }

    public function testFieldsWithoutAUsableKeyGetOneFromTheirLabel(): void
    {
        $form = new Form('contact', 'Contact');

        $this->mapper()->apply($form, ['builder' => ['steps' => [['fields' => [
            ['type' => 'text', 'label' => 'Your e-mail address'],
            ['key' => 'has spaces', 'type' => 'text', 'label' => 'Your e-mail address'],
            ['key' => '', 'type' => 'text', 'label' => '2 children?'],
            ['type' => 'text', 'label' => ''],
            ['key' => 'no_type'],
        ]]]]], 'en');

        self::assertSame(
            ['your_e_mail_address', 'has_spaces', 'f_2_children', 'text'],
            array_map(static fn ($field): string => $field->key, $form->structure()->fields()),
        );
    }

    public function testKeysAreUniqueAcrossSteps(): void
    {
        $form = new Form('contact', 'Contact');

        $this->mapper()->apply($form, ['builder' => ['steps' => [
            ['key' => 'one', 'fields' => [['key' => 'name', 'type' => 'text']]],
            ['key' => 'one', 'fields' => [['key' => 'name', 'type' => 'text']]],
        ]]], 'en');

        self::assertSame(['one', 'one_2'], array_map(static fn ($step): string => $step->key, $form->structure()->steps));
        self::assertSame(['name', 'name_2'], array_map(static fn ($field): string => $field->key, $form->structure()->fields()));
    }

    public function testSettingsOnlyChangeWhatWasSent(): void
    {
        $form = new Form('contact', 'Contact');
        $form->redirectUrl = '/thanks';
        $mapper = $this->mapper();

        $mapper->apply($form, ['afterSubmit' => 'redirect', 'submitLabel' => 'Send', 'storeSubmissions' => false], 'en');

        self::assertSame(AfterSubmit::Redirect, $form->afterSubmit());
        self::assertSame('/thanks', $form->redirectUrl);
        self::assertSame('Send', $form->text('en', 'submitLabel'));
        self::assertFalse($form->storeSubmissions);
        self::assertSame('contact', $form->key);
    }

    public function testAFormKeyIsDerivedFromTheTitleWhenItIsMissing(): void
    {
        $form = new Form('form', 'Contact');

        $this->mapper()->apply($form, ['title' => 'Request a Quote!', 'key' => ''], 'en');

        self::assertSame('request-a-quote', $form->key);
    }

    public function testTheAdminGetsTheFormInTheLanguageItEdits(): void
    {
        $form = new Form('contact', 'Contact');
        $form->id = 3;
        $form->setText('nl', 'successMessage', '<p>Bedankt</p>');

        $data = $this->mapper()->toArray($form, 'nl');

        self::assertSame(3, $data['id']);
        self::assertSame('contact', $data['key']);
        self::assertSame('<p>Bedankt</p>', $data['successMessage']);
        self::assertSame('message', $data['afterSubmit']);
        self::assertSame('{"steps":[{"key":"step1","title":"","titles":{},"visibleIf":null,"fields":[]}]}', json_encode($data['builder']));
    }

    private function mapper(): FormAdminMapper
    {
        $repository = $this->createStub(FormRepository::class);
        $repository->method('nextFreeKey')->willReturnArgument(0);

        return new FormAdminMapper(FieldTypes::registry(), $repository);
    }
}
