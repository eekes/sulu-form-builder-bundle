<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A submission the way the admin shows it: a row of a list, with one column per answer, and the
 * detail in the modal. Shared by the real submissions and the blocked ones.
 */
final readonly class SubmissionPresenter
{
    /**
     * Prefix of the list columns that hold an answer, so a field key can never clash with "id".
     */
    public const string COLUMN_PREFIX = 'field_';

    /**
     * Who caught a submission a listener marked as spam.
     */
    public const string LISTENER = 'listener';

    public function __construct(
        private TranslatorInterface $translator,
        private ?SpamCheckRegistry $spamCheckRegistry = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Submission $submission, string $locale): array
    {
        $row = [
            'id' => $submission->id,
            'createdAt' => $submission->createdAt->format(\DATE_ATOM),
            'locale' => $submission->locale,
        ];

        if ($submission->isSpam()) {
            $row += $this->spam($submission, $locale);
        }

        foreach ($submission->values() as $value) {
            $row[self::COLUMN_PREFIX.$value->key] = $value->display;
        }

        return $row;
    }

    /**
     * @param \Closure(Submission, SubmittedValue, int): string $fileUrl
     *
     * @return array<string, mixed>
     */
    public function detail(Submission $submission, string $locale, \Closure $fileUrl): array
    {
        $detail = [
            'id' => $submission->id,
            'formId' => $submission->form->id,
            'formTitle' => $submission->form->title,
            'locale' => $submission->locale,
            'url' => $submission->url,
            'createdAt' => $submission->createdAt->format(\DATE_ATOM),
            'spam' => $submission->isSpam(),
            'values' => array_map(static fn (SubmittedValue $value): array => [
                'key' => $value->key,
                'label' => $value->label,
                'type' => $value->type,
                'display' => $value->display,
                'files' => array_map(static fn (StoredFile $file, int $index): array => [
                    'name' => $file->originalName,
                    'size' => $file->size,
                    'url' => $fileUrl($submission, $value, $index),
                ], $value->files, array_keys($value->files)),
            ], $submission->values()),
        ];

        if ($submission->isSpam()) {
            $detail += $this->spam($submission, $locale);
            // Without the answers there is nothing a release could send.
            $detail['releasable'] = $submission->values() !== [];
        }

        return $detail;
    }

    /**
     * The title of the check that caught it, in the language of the admin.
     */
    public function checkTitle(?string $check, string $locale): string
    {
        $metadata = null;

        foreach ($this->spamCheckRegistry?->all() ?? [] as $candidate) {
            if ($candidate->key === $check) {
                $metadata = $candidate;
            }
        }

        if ($metadata !== null) {
            return $this->translator->trans($metadata->title, [], 'admin', $locale);
        }

        return $check === self::LISTENER || $check === null
            ? $this->translator->trans('eekes_form_builder.spam_check.listener', [], 'admin', $locale)
            : $check;
    }

    /**
     * @return array<string, mixed>
     */
    private function spam(Submission $submission, string $locale): array
    {
        return [
            'spamCheck' => $this->checkTitle($submission->spamCheck(), $locale),
            'spamReason' => $submission->spamReason(),
            'spamScore' => $submission->spamScore(),
            'ipAddress' => $submission->ipAddress,
            'userAgent' => $submission->userAgent,
        ];
    }
}
