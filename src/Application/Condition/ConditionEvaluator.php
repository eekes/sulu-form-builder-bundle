<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Condition;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;

/**
 * Decides whether a condition holds for a set of answers.
 *
 * Mirrors public/js/form-builder.js: a field the browser hides is a field the server ignores, so
 * a hidden required field can never block a submission. Comparisons are on trimmed strings and
 * case-insensitive, because editors type the value by hand.
 */
final class ConditionEvaluator
{
    /**
     * @param array<string, mixed> $values answers by field key
     */
    public function isMet(Condition $condition, array $values): bool
    {
        $actual = self::strings($values[$condition->field] ?? null);
        $expected = mb_strtolower(trim($condition->value ?? ''));

        return match ($condition->operator) {
            ConditionOperator::Filled => $actual !== [],
            ConditionOperator::Empty => $actual === [],
            ConditionOperator::Equals => \in_array($expected, $actual, true),
            ConditionOperator::NotEquals => !\in_array($expected, $actual, true),
            ConditionOperator::Contains => self::contains($actual, $expected),
            ConditionOperator::NotContains => !self::contains($actual, $expected),
        };
    }

    /**
     * Whether a set holds: all of its rules for "all", at least one for "any". No set at all
     * means "always".
     *
     * @param array<string, mixed> $values answers by field key
     */
    public function isSetMet(?ConditionSet $set, array $values): bool
    {
        if ($set === null) {
            return true;
        }

        foreach ($set->rules as $rule) {
            $met = $rule instanceof Condition ? $this->isMet($rule, $values) : $this->isSetMet($rule, $values);

            if ($set->match === ConditionSet::ANY && $met) {
                return true;
            }

            if ($set->match === ConditionSet::ALL && !$met) {
                return false;
            }
        }

        return $set->match === ConditionSet::ALL;
    }

    /**
     * Every answer as a list of non-empty, lowercase strings: a checkbox group gives several, a
     * text field one, an empty field none, a repeater every answer of every row. A ticked
     * checkbox counts as "1", like in the browser.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];
        $strings = [];

        foreach ($values as $item) {
            if (\is_array($item)) {
                array_push($strings, ...self::strings(array_values($item)));

                continue;
            }

            if ($item === true) {
                $item = '1';
            }

            if (!is_scalar($item) || $item === false) {
                continue;
            }

            $item = mb_strtolower(trim((string) $item));

            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param list<string> $actual
     */
    private static function contains(array $actual, string $expected): bool
    {
        foreach ($actual as $item) {
            if ($expected === '' || str_contains($item, $expected)) {
                return true;
            }
        }

        return false;
    }
}
