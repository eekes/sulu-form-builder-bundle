<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversNothing]
final class PruneSpamCommandTest extends FunctionalTestCase
{
    public function testOldBlockedSubmissionsAreDeletedAndRealOnesKept(): void
    {
        $form = self::createContactForm();
        $this->store($form, '-40 days', spam: true);
        $this->store($form, '-5 days', spam: true);
        $this->store($form, '-400 days', spam: false);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('1 blocked submissions older than 30 days would be deleted', $tester->getDisplay());
        self::assertSame(3, $this->remaining());

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertSame(2, $this->remaining(), 'The retention defaults to 30 days, and a real submission is never pruned.');

        $tester->execute(['--days' => '1']);
        self::assertSame(1, $this->remaining());
        self::assertFalse(self::submissions()[0]->isSpam());
    }

    public function testDaysMustBeAWholeNumber(): void
    {
        $tester = $this->tester();

        self::assertSame(2, $tester->execute(['--days' => 'soon']));
    }

    private function remaining(): int
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Submission::class)->count([]);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('eekes:form-builder:prune-spam'));
    }

    private function store(Form $form, string $age, bool $spam): void
    {
        $submission = new Submission(self::entityManager()->getReference(Form::class, $form->id) ?? $form, 'en', []);
        $submission->createdAt = new \DateTimeImmutable($age);

        if ($spam) {
            $submission->markAsSpam('honeypot', 'Filled in.');
        }

        self::entityManager()->persist($submission);
        self::entityManager()->flush();
    }
}
