<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a check gets to decide on: the form, the step the visitor finished it on (holding the
 * children the check added in buildStep()), and the request when there is one.
 */
final readonly class SpamCheckRequest
{
    /**
     * @param FormInterface<mixed> $step
     */
    public function __construct(
        public FormDefinition $form,
        public FormInterface $step,
        public ?Request $request = null,
    ) {
    }

    /**
     * The submitted value of a child the check added to the step, as a string.
     */
    public function value(string $child): string
    {
        if (!$this->step->has($child)) {
            return '';
        }

        $value = $this->step->get($child)->getData();

        return is_scalar($value) ? (string) $value : '';
    }
}
