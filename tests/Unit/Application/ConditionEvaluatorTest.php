<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * public/js/form-builder.js implements the same table; keep the two in step.
 */
#[CoversClass(ConditionEvaluator::class)]
final class ConditionEvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{ConditionOperator, null|string, mixed, bool}>
     */
    public static function cases(): iterable
    {
        yield 'equals' => [ConditionOperator::Equals, 'sales', 'sales', true];
        yield 'equals ignores case and spaces' => [ConditionOperator::Equals, ' Sales ', 'SALES', true];
        yield 'equals another value' => [ConditionOperator::Equals, 'sales', 'support', false];
        yield 'equals one of several checkboxes' => [ConditionOperator::Equals, 'sales', ['support', 'sales'], true];
        yield 'equals on an unanswered field' => [ConditionOperator::Equals, 'sales', null, false];
        yield 'equals a ticked checkbox' => [ConditionOperator::Equals, '1', true, true];
        yield 'equals an unticked checkbox' => [ConditionOperator::Equals, '1', false, false];
        yield 'equals a number' => [ConditionOperator::Equals, '18', 18, true];
        yield 'not equals' => [ConditionOperator::NotEquals, 'sales', 'support', true];
        yield 'not equals the same' => [ConditionOperator::NotEquals, 'sales', 'Sales', false];
        yield 'not equals on an unanswered field' => [ConditionOperator::NotEquals, 'sales', null, true];
        yield 'contains' => [ConditionOperator::Contains, 'gmail', 'jane@GMAIL.com', true];
        yield 'contains not' => [ConditionOperator::Contains, 'gmail', 'jane@example.com', false];
        yield 'contains in a list' => [ConditionOperator::Contains, 'port', ['sales', 'support'], true];
        yield 'not contains' => [ConditionOperator::NotContains, 'gmail', 'jane@example.com', true];
        yield 'filled' => [ConditionOperator::Filled, null, 'x', true];
        yield 'filled with spaces only' => [ConditionOperator::Filled, null, '   ', false];
        yield 'filled with nothing ticked' => [ConditionOperator::Filled, null, [], false];
        yield 'filled with zero' => [ConditionOperator::Filled, null, 0, true];
        yield 'empty' => [ConditionOperator::Empty, null, '', true];
        yield 'empty on a missing field' => [ConditionOperator::Empty, null, null, true];
        yield 'empty with an answer' => [ConditionOperator::Empty, null, 'x', false];
        yield 'filled repeater' => [ConditionOperator::Filled, null, [['name' => 'Jane', 'age' => null]], true];
        yield 'filled repeater with empty rows' => [ConditionOperator::Filled, null, [['name' => ' ', 'agree' => false]], false];
        yield 'empty repeater' => [ConditionOperator::Empty, null, [], true];
        yield 'contains in a repeater' => [ConditionOperator::Contains, 'jane', [['name' => 'Joe'], ['name' => 'Jane', 'agree' => true]], true];
        yield 'equals a ticked checkbox in a repeater' => [ConditionOperator::Equals, '1', [['agree' => true]], true];
    }

    #[DataProvider('cases')]
    public function testOperator(ConditionOperator $operator, ?string $expected, mixed $actual, bool $met): void
    {
        $condition = new Condition('field', $operator, $expected);

        self::assertSame($met, (new ConditionEvaluator())->isMet($condition, ['field' => $actual]));
    }

    public function testAllRulesOfAnAllSetHaveToBeMet(): void
    {
        $evaluator = new ConditionEvaluator();
        $set = ConditionSet::of(
            new Condition('department', ConditionOperator::Equals, 'sales'),
            new Condition('budget', ConditionOperator::Filled),
        );

        self::assertTrue($evaluator->isSetMet($set, ['department' => 'sales', 'budget' => '1000']));
        self::assertFalse($evaluator->isSetMet($set, ['department' => 'sales']));
    }

    public function testOneRuleOfAnAnySetIsEnough(): void
    {
        $evaluator = new ConditionEvaluator();
        $set = new ConditionSet([
            new Condition('department', ConditionOperator::Equals, 'sales'),
            new Condition('department', ConditionOperator::Equals, 'support'),
        ], ConditionSet::ANY);

        self::assertTrue($evaluator->isSetMet($set, ['department' => 'support']));
        self::assertFalse($evaluator->isSetMet($set, ['department' => 'billing']));
    }

    /**
     * (topic is sales AND budget is filled) OR company is filled.
     */
    public function testGroupsCombineAndWithOr(): void
    {
        $evaluator = new ConditionEvaluator();
        $set = new ConditionSet([
            ConditionSet::of(new Condition('topic', ConditionOperator::Equals, 'sales'), new Condition('budget', ConditionOperator::Filled)),
            new Condition('company', ConditionOperator::Filled),
        ], ConditionSet::ANY);

        self::assertTrue($evaluator->isSetMet($set, ['topic' => 'sales', 'budget' => '5']));
        self::assertTrue($evaluator->isSetMet($set, ['company' => 'Acme']));
        self::assertFalse($evaluator->isSetMet($set, ['topic' => 'sales']));
        self::assertFalse($evaluator->isSetMet($set, ['topic' => 'support', 'budget' => '5']));
    }

    public function testNoSetMeansAlways(): void
    {
        self::assertTrue((new ConditionEvaluator())->isSetMet(null, []));
    }
}
