<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * The comparisons a condition can make. The same set is implemented in the website JavaScript,
 * so a field hides in the browser exactly when the server would ignore it.
 */
enum ConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Filled = 'filled';
    case Empty = 'empty';

    /**
     * Whether the condition compares against a value an editor entered.
     */
    public function needsValue(): bool
    {
        return match ($this) {
            self::Filled, self::Empty => false,
            default => true,
        };
    }
}
