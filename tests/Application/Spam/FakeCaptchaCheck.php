<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Application\Spam;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\AsSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckResult;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A spam check of the application, registered exactly the way a consuming project or a package
 * would: the attribute and the interface, nothing else. The token the visitor posts decides.
 */
#[AsSpamCheck(key: 'fake_captcha', title: 'Fake captcha')]
final class FakeCaptchaCheck implements SpamCheckInterface
{
    public const string FIELD = '_captcha';

    public int $checks = 0;

    public function buildStep(FormBuilderInterface $builder, FormDefinition $form, StepDefinition $step): void
    {
        $builder->add(self::FIELD, HiddenType::class, ['mapped' => false]);
    }

    public function check(SpamCheckRequest $request): SpamCheckResult
    {
        ++$this->checks;

        return match ($request->value(self::FIELD)) {
            'bot' => SpamCheckResult::spam('The captcha said it is a bot.', 0.1),
            'expired' => SpamCheckResult::retry('The check expired. Please send the form again.'),
            default => SpamCheckResult::passed(),
        };
    }
}
