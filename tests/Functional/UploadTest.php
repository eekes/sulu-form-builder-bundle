<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

#[CoversNothing]
final class UploadTest extends WebsiteTestCase
{
    public function testAnUploadIsKeptPrivatelyWithItsSubmission(): void
    {
        $form = $this->applicationForm();

        $this->submit($form, 'step1', ['name' => 'Jane'], files: ['cv' => $this->upload('cv.pdf')]);

        $value = self::submissions()[0]->get('cv');
        self::assertNotNull($value);
        self::assertSame(['cv.pdf'], $value->value);
        self::assertSame('cv.pdf', $value->display);
        self::assertCount(1, $value->files);
        self::assertStringNotContainsString('cv', $value->files[0]->path, 'The name a visitor chose never becomes a path.');
        self::assertFileExists(self::service(UploadStorageInterface::class)->absolutePath($value->files[0]));
    }

    public function testAnUploadOfAnEarlierStepSurvivesUntilTheEnd(): void
    {
        $form = self::createForm('application', [
            ['key' => 'documents', 'fields' => [['key' => 'cv', 'type' => 'file', 'required' => true, 'translations' => ['en' => ['label' => 'CV']]]]],
            ['key' => 'about', 'fields' => [['key' => 'name', 'type' => 'text', 'translations' => ['en' => ['label' => 'Name']]]]],
        ]);

        $this->submit($form, 'documents', [], 'next', ['cv' => $this->upload('cv.pdf')]);
        $this->submit($form, 'about', ['name' => 'Jane'], 'finish');

        $value = self::submissions()[0]->get('cv');
        self::assertSame(['cv.pdf'], $value?->value);
        self::assertFileExists(self::service(UploadStorageInterface::class)->absolutePath($value->files[0]));
    }

    public function testAFileOfTheWrongTypeIsRefused(): void
    {
        $form = $this->applicationForm();

        $this->submit($form, 'step1', ['name' => 'Jane'], files: ['cv' => $this->upload('cv.exe', 'MZ')]);

        self::assertSame([], self::submissions());
    }

    public function testUploadsCanBeAttachedToANotification(): void
    {
        $form = $this->applicationForm();
        self::createNotification($form, 'hr@example.com', static fn (Notification $notification) => $notification->attachUploads = true);

        $this->submit($form, 'step1', ['name' => 'Jane'], files: ['cv' => $this->upload('cv.pdf')]);

        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertCount(1, $email->getAttachments());
        self::assertSame('cv.pdf', $email->getAttachments()[0]->getFilename());
    }

    public function testUploadsOfASubmissionThatIsNotKeptAreDeletedOnceMailed(): void
    {
        $form = $this->applicationForm(static fn (Form $form) => $form->storeSubmissions = false);
        $paths = [];
        self::recorder()->on(\Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCreatedEvent::class, static function ($event) use (&$paths): void {
            foreach ($event->submission->files() as $file) {
                $paths[] = self::service(UploadStorageInterface::class)->absolutePath($file);
            }
        });

        $this->submit($form, 'step1', ['name' => 'Jane'], files: ['cv' => $this->upload('cv.pdf')]);

        self::assertCount(1, $paths);
        self::assertFileDoesNotExist($paths[0]);
    }

    public function testBackOnTheStepTheUploadIsKeptUnlessReplaced(): void
    {
        $form = $this->twoStepApplication();
        $this->submit($form, 'documents', [], 'next', ['cv' => $this->upload('cv.pdf')]);
        $this->submit($form, 'about', [], 'previous');

        $html = $this->render($form);
        self::assertMatchesRegularExpression('/<input type="checkbox" id="eekes_form_application_documents__keep_cv" name="eekes_form_application\[documents\]\[_keep_cv\]"[^>]*checked="checked"/', $html);
        self::assertStringContainsString('Keep cv.pdf', $html);
        self::assertDoesNotMatchRegularExpression('/name="eekes_form_application\[documents\]\[cv\]"[^>]*required/', $html, 'The file is there already, so a new one is not required.');

        // Ticked and no new file: the visitor moves on with the earlier upload.
        $this->submit($form, 'documents', [FlowData::KEEP_PREFIX.'cv' => '1'], 'next');
        $this->submit($form, 'about', ['name' => 'Jane']);

        $cv = self::submissions()[0]->get('cv');
        self::assertSame(['cv.pdf'], $cv?->value);
        self::assertFileExists($this->path($cv->files[0]));
        self::assertFalse(self::submissions()[0]->has(FlowData::KEEP_PREFIX.'cv'), 'The checkbox is never an answer.');
    }

    public function testANewUploadReplacesTheEarlierOne(): void
    {
        $form = $this->twoStepApplication();
        $old = $this->uploadOnFirstStep($form, 'old.pdf');
        $this->submit($form, 'about', [], 'previous');

        $this->submit($form, 'documents', [FlowData::KEEP_PREFIX.'cv' => '1'], 'next', ['cv' => $this->upload('new.pdf')]);
        $this->submit($form, 'about', ['name' => 'Jane']);

        self::assertSame(['new.pdf'], self::submissions()[0]->value('cv'));
        self::assertFileDoesNotExist($old, 'The replaced file is deleted.');
    }

    public function testUntickingKeepLetsTheUploadGoAndAsksForANewOneWhenRequired(): void
    {
        $form = $this->twoStepApplication();
        $this->submit($form, 'documents', [], 'next', ['cv' => $this->upload('cv.pdf')]);
        $this->submit($form, 'about', [], 'previous');

        $this->submit($form, 'documents', [], 'next');

        self::assertSame(422, $this->pageStatus(), 'Without the earlier file, the required field needs a new one.');

        $optional = $this->twoStepApplication('optional', required: false);
        $stored = $this->uploadOnFirstStep($optional, 'cv.pdf');
        $this->submit($optional, 'about', [], 'previous');
        $this->submit($optional, 'documents', [], 'next');
        $this->submit($optional, 'about', ['name' => 'Jane']);

        $submission = self::submissions()[0];
        self::assertSame([], $submission->files());
        self::assertFileDoesNotExist($stored);
    }

    public function testTheStepWithTheUploadCanAlsoFinishTheForm(): void
    {
        $form = self::createForm('application', [
            ['key' => 'documents', 'fields' => [
                ['key' => 'cv', 'type' => 'file', 'translations' => ['en' => ['label' => 'CV']]],
                ['key' => 'more', 'type' => 'checkbox', 'translations' => ['en' => ['label' => 'Tell more']]],
            ]],
            ['key' => 'about', 'visibleIf' => ['field' => 'more', 'operator' => 'filled'], 'fields' => [['key' => 'name', 'type' => 'text']]],
        ]);
        $before = $this->uploadedFiles();
        $this->submit($form, 'documents', ['more' => '1'], 'next', ['cv' => $this->upload('cv.pdf')]);
        $stored = array_values(array_diff($this->uploadedFiles(), $before));
        $this->submit($form, 'about', [], 'previous');

        // "more" unticked: the remaining step is skipped, so "next" finishes - with "keep" unticked too.
        $this->submit($form, 'documents', [], 'next');

        self::assertCount(1, self::submissions());
        self::assertSame([], self::submissions()[0]->files());
        self::assertCount(1, $stored);
        self::assertFileDoesNotExist($stored[0], 'The file the visitor let go of is deleted.');
    }

    private function twoStepApplication(string $key = 'application', bool $required = true): Form
    {
        return self::createForm($key, [
            ['key' => 'documents', 'fields' => [['key' => 'cv', 'type' => 'file', 'required' => $required, 'translations' => ['en' => ['label' => 'CV']]]]],
            ['key' => 'about', 'fields' => [['key' => 'name', 'type' => 'text', 'translations' => ['en' => ['label' => 'Name']]]]],
        ]);
    }

    private function path(StoredFile $file): string
    {
        return self::service(UploadStorageInterface::class)->absolutePath($file);
    }

    /**
     * Every file in the upload directory, to see which one an upload added.
     *
     * @return list<string>
     */
    private function uploadedFiles(): array
    {
        $directory = self::getContainer()->getParameter('eekes_sulu_form_builder.upload_directory');
        self::assertIsString($directory);

        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Submits the first step with an upload, and returns the path the upload was stored under.
     */
    private function uploadOnFirstStep(Form $form, string $name): string
    {
        $before = $this->uploadedFiles();
        $this->submit($form, 'documents', [], 'next', ['cv' => $this->upload($name)]);
        $added = array_values(array_diff($this->uploadedFiles(), $before));
        self::assertCount(1, $added);

        return $added[0];
    }

    private function applicationForm(?\Closure $configure = null): Form
    {
        return self::createForm('application', [['key' => 'step1', 'fields' => [
            ['key' => 'name', 'type' => 'text', 'translations' => ['en' => ['label' => 'Name']]],
            ['key' => 'cv', 'type' => 'file', 'required' => true, 'options' => ['extensions' => 'pdf', 'maxSize' => 2], 'translations' => ['en' => ['label' => 'CV']]],
        ]]], $configure);
    }

    private function upload(string $name, string $content = "%PDF-1.4\n%%EOF\n"): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'eekes_upload');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
