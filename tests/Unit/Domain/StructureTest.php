<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Domain;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormStructure::class)]
#[CoversClass(StepStructure::class)]
#[CoversClass(FieldStructure::class)]
#[CoversClass(Condition::class)]
final class StructureTest extends TestCase
{
    public function testAStructureSurvivesTheRoundTripThroughJson(): void
    {
        $structure = new FormStructure([
            new StepStructure('contact', ['en' => 'Contact', 'nl' => 'Contact'], [
                new FieldStructure(
                    key: 'department',
                    type: 'choice',
                    width: new ColumnWidths(6, 6, 12),
                    required: true,
                    showInList: true,
                    options: ['display' => 'radio'],
                    translations: ['en' => ['label' => 'Department', 'choices' => [['label' => 'Sales', 'value' => 'sales']]]],
                ),
                new FieldStructure(
                    'budget',
                    'number',
                    visibleIf: ConditionSet::of(new Condition('department', ConditionOperator::Equals, 'sales')),
                    requiredIf: new ConditionSet([
                        new Condition('department', ConditionOperator::Equals, 'sales'),
                        ConditionSet::of(new Condition('company', ConditionOperator::Filled)),
                    ], ConditionSet::ANY),
                ),
            ]),
            new StepStructure('extra', visibleIf: ConditionSet::of(new Condition('department', ConditionOperator::Filled))),
        ]);

        $json = json_decode(json_encode($structure->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        self::assertEquals($structure, FormStructure::fromArray($json));
    }

    public function testAFormWithoutStepsStillHasOneToPutFieldsIn(): void
    {
        $structure = FormStructure::fromArray([]);

        self::assertCount(1, $structure->steps);
        self::assertSame('step1', $structure->steps[0]->key);
    }

    public function testBrokenEntriesAreSkippedInsteadOfBreakingTheWholeForm(): void
    {
        $structure = FormStructure::fromArray(['steps' => [
            'not a step',
            ['key' => '1-invalid'],
            ['key' => 'ok', 'fields' => [
                ['key' => 'name', 'type' => 'text'],
                ['key' => 'no type'],
                ['type' => 'text'],
                ['key' => 'has space', 'type' => 'text'],
                42,
            ]],
        ]]);

        self::assertCount(1, $structure->steps);
        self::assertSame(['name'], array_map(static fn (FieldStructure $field): string => $field->key, $structure->fields()));
    }

    public function testAFieldCanBeFoundAcrossSteps(): void
    {
        $structure = new FormStructure([
            new StepStructure('one', fields: [new FieldStructure('name', 'text')]),
            new StepStructure('two', fields: [new FieldStructure('email', 'email')]),
        ]);

        self::assertSame('email', $structure->field('email')?->type);
        self::assertNull($structure->field('phone'));
    }

    public function testAHalfFilledConditionMeansAlwaysInsteadOfNever(): void
    {
        self::assertNull(Condition::fromArray(['field' => '', 'operator' => 'equals']));
        self::assertNull(Condition::fromArray(['field' => 'name', 'operator' => 'unknown']));
        self::assertNull(Condition::fromArray('name equals x'));
        self::assertEquals(new Condition('age', ConditionOperator::Equals, '18'), Condition::fromArray(['field' => 'age', 'operator' => 'equals', 'value' => 18]));
    }

    public function testTheEarlierSingleConditionShapeIsStillRead(): void
    {
        $field = FieldStructure::fromArray(['key' => 'budget', 'type' => 'number', 'visibleIf' => ['field' => 'type', 'operator' => 'equals', 'value' => 'company']]);

        self::assertEquals(ConditionSet::of(new Condition('type', ConditionOperator::Equals, 'company')), $field?->visibleIf);
    }

    public function testASetWithoutUsableRulesMeansAlways(): void
    {
        self::assertNull(ConditionSet::fromArray(['match' => 'any', 'rules' => [['field' => ''], ['match' => 'all', 'rules' => []]]]));
        self::assertNull(ConditionSet::fromArray([]));
    }

    public function testRenamingAFieldFollowsNestedRules(): void
    {
        $set = new ConditionSet([ConditionSet::of(new Condition('type', ConditionOperator::Filled))], ConditionSet::ANY);

        self::assertSame(['customer_type'], $set->withRenamedField('type', 'customer_type')->fields());
    }

    public function testSeveralFieldsAreRenamedAtOnceSoKeysCanBeSwapped(): void
    {
        $set = ConditionSet::of(new Condition('a', ConditionOperator::Filled), new Condition('b', ConditionOperator::Filled));

        self::assertSame(['b', 'a'], $set->withRenamedFields(['a' => 'b', 'b' => 'a'])->fields());
    }

    public function testAFieldStoredWithoutAnIdIsIdentifiedByItsKey(): void
    {
        $legacy = FieldStructure::fromArray(['key' => 'email', 'type' => 'email']);
        $new = FieldStructure::fromArray(['id' => 'ab12-cd34', 'key' => 'email', 'type' => 'email']);

        self::assertNotNull($legacy);
        self::assertNotNull($new);
        self::assertSame('email', $legacy->id);
        self::assertSame('ab12-cd34', $new->id);
        self::assertSame('ab12-cd34', $new->toArray()['id']);
        self::assertStringContainsString('-', FieldStructure::newId());
        self::assertFalse(FieldStructure::isValidKey(FieldStructure::newId()), 'A new id can never be a key.');
    }

    public function testRenamedFieldsAreFoundByTheirId(): void
    {
        $before = new FormStructure([new StepStructure('step1', [], [
            new FieldStructure('email', 'email'),
            new FieldStructure('name', 'text', id: 'ab12-cd34'),
            new FieldStructure('gone', 'text'),
        ])]);
        $after = new FormStructure([new StepStructure('step1', [], [
            new FieldStructure('mail', 'email', id: 'email'),
            new FieldStructure('name', 'text', id: 'ab12-cd34'),
            new FieldStructure('gone', 'text', id: 'ef56-ab78'),
        ])]);

        self::assertSame(['email' => 'mail'], $after->renamedFieldKeys($before), 'Unchanged keys and new fields are no renames.');
    }

    public function testKeysAreSafeAsFormNamesPlaceholdersAndConditions(): void
    {
        self::assertTrue(FieldStructure::isValidKey('first_name'));
        self::assertTrue(FieldStructure::isValidKey('Email2'));
        self::assertFalse(FieldStructure::isValidKey('2email'));
        self::assertFalse(FieldStructure::isValidKey('first-name'));
        self::assertFalse(FieldStructure::isValidKey('{{ x }}'));
        self::assertFalse(FieldStructure::isValidKey(str_repeat('a', 65)));
    }
}
