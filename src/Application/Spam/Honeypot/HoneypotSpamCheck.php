<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam\Honeypot;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\AsSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckResult;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A hidden field a person never sees, so whatever fills it in is a bot. Needs no service, no
 * cookie and no JavaScript, which is why it is on for every form until an editor turns it off.
 */
#[AsSpamCheck(
    key: self::KEY,
    title: 'eekes_form_builder.spam_check.honeypot',
    description: 'eekes_form_builder.spam_check.honeypot_info',
    enabledByDefault: true,
)]
final readonly class HoneypotSpamCheck implements SpamCheckInterface
{
    public const string KEY = 'honeypot';

    /**
     * The name of the field. A field key starts with a letter, so this one never clashes.
     */
    public const string FIELD = '_website';

    public function buildStep(FormBuilderInterface $builder, FormDefinition $form, StepDefinition $step): void
    {
        $builder->add(self::FIELD, HoneypotType::class);
    }

    public function check(SpamCheckRequest $request): SpamCheckResult
    {
        return trim($request->value(self::FIELD)) === ''
            ? SpamCheckResult::passed()
            : SpamCheckResult::spam('The hidden honeypot field was filled in.');
    }
}
