<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

/**
 * Hands the verdict of the spam checks from the flow, where they run while the last step is
 * validated, to SubmissionProcessor, which only gets the data of the flow.
 */
final class SpamVerdicts
{
    /**
     * @var array<string, SpamVerdict>
     */
    private array $verdicts = [];

    public function set(string $formKey, SpamVerdict $verdict): void
    {
        $this->verdicts[$formKey] = $verdict;
    }

    /**
     * The verdict for a form, once: taking it removes it.
     */
    public function take(string $formKey): ?SpamVerdict
    {
        $verdict = $this->verdicts[$formKey] ?? null;
        unset($this->verdicts[$formKey]);

        return $verdict;
    }

    public function reset(): void
    {
        $this->verdicts = [];
    }
}
