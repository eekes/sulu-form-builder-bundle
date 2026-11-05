<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A way to tell a bot from a person: a honeypot, a captcha, a score from a service.
 *
 * A check adds what it needs to every step of a form that uses it, and decides once, when the
 * visitor finishes the form and everything else is valid. It never decides on "next" or
 * "previous", so a token that can only be verified once is verified exactly once.
 *
 * What a check adds is never an answer: add children with "mapped" set to false, so they stay out
 * of submissions, e-mails, conditions and integrations. Name them with a leading underscore - a
 * field key always starts with a letter - so they never clash with a field of the form.
 */
interface SpamCheckInterface
{
    /**
     * @param FormBuilderInterface<null|array<string, mixed>> $builder the step being built
     */
    public function buildStep(FormBuilderInterface $builder, FormDefinition $form, StepDefinition $step): void;

    public function check(SpamCheckRequest $request): SpamCheckResult;
}
