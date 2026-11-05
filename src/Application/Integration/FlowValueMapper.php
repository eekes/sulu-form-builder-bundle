<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Eekes\Sulu\FormBuilderBundle\Application\Notification\PlaceholderRenderer;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * Fills the fields of an action from a submission, following the mapping of a flow: the raw
 * answer of a form field (the value of a choice, true for a ticked box), or a fixed value with
 * its placeholders replaced. An answer made of rows (a repeater) has no raw value a target field
 * can take, so it is passed as its readable text; the webhook sends the rows themselves.
 */
final readonly class FlowValueMapper
{
    public function __construct(
        private PlaceholderRenderer $placeholderRenderer,
    ) {
    }

    /**
     * @return array<string, mixed> by target field key
     */
    public function map(Flow $flow, Submission $submission): array
    {
        $values = [];

        foreach ($flow->mapping() as $target => $mapping) {
            $values[$target] = $mapping->isField()
                ? self::fieldValue($submission, $mapping->value)
                : $this->placeholderRenderer->render($mapping->value, $submission, false);
        }

        return $values;
    }

    private static function fieldValue(Submission $submission, string $key): mixed
    {
        $answer = $submission->get($key);

        if ($answer === null) {
            return null;
        }

        if (\is_array($answer->value) && array_filter($answer->value, 'is_array') !== []) {
            return $answer->display;
        }

        return $answer->value;
    }
}
