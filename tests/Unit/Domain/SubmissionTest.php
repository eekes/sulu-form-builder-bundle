<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Domain;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Submission::class)]
#[CoversClass(SubmittedValue::class)]
#[CoversClass(StoredFile::class)]
final class SubmissionTest extends TestCase
{
    public function testAnswersAreReadByKey(): void
    {
        $submission = $this->submission();

        self::assertSame('jane@example.com', $submission->value('email'));
        self::assertSame(['sales', 'support'], $submission->value('topics'));
        self::assertSame('Sales, Support', $submission->get('topics')?->display);
        self::assertNull($submission->value('unknown'));
        self::assertTrue($submission->has('email'));
        self::assertFalse($submission->has('unknown'));
    }

    public function testTheLabelTheVisitorSawIsKeptWithTheAnswer(): void
    {
        self::assertSame('E-mail address', $this->submission()->get('email')?->label);
    }

    public function testTheAnswersCanBeSearchedWithoutQueryingJson(): void
    {
        self::assertSame('jane@example.com sales, support cv.pdf', $this->submission()->searchText());
    }

    public function testFilesOfAllAnswersAreCollected(): void
    {
        $files = $this->submission()->files();

        self::assertCount(1, $files);
        self::assertSame('cv.pdf', $files[0]->originalName);
    }

    public function testRawValuesByKeyAreWhatConditionsAndPlaceholdersSee(): void
    {
        self::assertSame(['email' => 'jane@example.com', 'topics' => ['sales', 'support'], 'cv' => ['cv.pdf']], $this->submission()->toArray());
    }

    public function testASubmissionIsOnlyPersistedOnceItHasAnId(): void
    {
        $submission = $this->submission();
        self::assertFalse($submission->isPersisted());

        $submission->id = 3;
        self::assertTrue($submission->isPersisted());
    }

    private function submission(): Submission
    {
        return new Submission(new Form('contact', 'Contact'), 'en', [
            new SubmittedValue('email', 'E-mail address', 'email', 'jane@example.com', 'jane@example.com', true),
            new SubmittedValue('topics', 'Topics', 'choice', ['sales', 'support'], 'Sales, Support'),
            new SubmittedValue('cv', 'CV', 'file', ['cv.pdf'], 'cv.pdf', files: [new StoredFile('2026/10/abc.pdf', 'cv.pdf', 'application/pdf', 1024)]),
        ]);
    }
}
