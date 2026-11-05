<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    public function testTheDelayGrowsUpToTheCeiling(): void
    {
        $policy = new RetryPolicy(maxAttempts: 10, delay: 60, multiplier: 2.0, maxDelay: 300);

        self::assertSame([60, 120, 240, 300, 300], array_map(static fn (int $attempt): int => $policy->delay('crm', $attempt), [1, 2, 3, 4, 5]));
    }

    public function testEveryAttemptCountsTheFirstIncluded(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);

        self::assertTrue($policy->allowsAnotherAttempt('crm', 2));
        self::assertFalse($policy->allowsAnotherAttempt('crm', 3));
        self::assertFalse((new RetryPolicy(maxAttempts: 1))->allowsAnotherAttempt('crm', 1), 'One attempt means never again.');
    }

    public function testAnIntegrationCanHaveItsOwnPolicy(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, delay: 60, integrations: ['teamleader' => ['max_attempts' => 5, 'delay' => 10]]);

        self::assertSame(5, $policy->maxAttempts('teamleader'));
        self::assertSame(10, $policy->delay('teamleader', 1));
        self::assertSame(20, $policy->delay('teamleader', 2), 'What is not overridden comes from the defaults.');
        self::assertSame(3, $policy->maxAttempts('mailchimp'));
    }
}
