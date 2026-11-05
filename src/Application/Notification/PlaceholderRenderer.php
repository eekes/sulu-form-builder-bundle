<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Notification;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * Replaces {{ field_key }} in texts editors wrote with the answers of a submission.
 *
 * Deliberately not Twig: editors write these texts in the admin, and running their input as a
 * template would hand everybody with access to a form a way to execute code. Besides the field
 * keys, {{ form_title }}, {{ url }} and {{ date }} are known. Anything unknown becomes empty.
 */
final class PlaceholderRenderer
{
    private const string PATTERN = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/';

    public function render(string $text, Submission $submission, bool $html): string
    {
        $replacements = $this->replacements($submission);

        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($replacements, $html): string {
            $value = $replacements[$match[1]] ?? '';

            return $html ? nl2br(htmlspecialchars($value, \ENT_QUOTES)) : $value;
        }, $text);
    }

    /**
     * The same text with the placeholders of renamed fields pointing at their new key. Everything
     * else, the spacing inside the braces included, stays as the editor wrote it.
     *
     * @param array<string, string> $renamed new key by old key
     */
    public function renamePlaceholders(string $text, array $renamed): string
    {
        if ($renamed === []) {
            return $text;
        }

        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($renamed): string {
            $key = $renamed[$match[1]] ?? null;

            return $key === null ? $match[0] : str_replace($match[1], $key, $match[0]);
        }, $text);
    }

    /**
     * @return list<string> the placeholders a text uses, e.g. to warn about typos
     */
    public function placeholders(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, string>
     */
    private function replacements(Submission $submission): array
    {
        $replacements = [
            'form_title' => $submission->form->title,
            'url' => $submission->url ?? '',
            'date' => $submission->createdAt->format('Y-m-d H:i'),
        ];

        foreach ($submission->values() as $value) {
            $replacements[$value->key] = $value->display;
        }

        return $replacements;
    }
}
