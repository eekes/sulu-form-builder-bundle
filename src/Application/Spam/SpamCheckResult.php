<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

/**
 * The verdict of a check:
 *
 * - passed: nothing suspicious;
 * - spam: answered exactly like a real submission, so a bot never learns it was caught, but
 *   logged as blocked instead of stored, sent or passed to integrations;
 * - retry: the check could not decide (a token expired, the service did not answer) and the
 *   visitor gets the step back with the message, to send it again.
 */
final readonly class SpamCheckResult
{
    private const string PASSED = 'passed';
    private const string SPAM = 'spam';
    private const string RETRY = 'retry';

    /**
     * @param string $message why it is spam (for the log), or what the visitor is told on a retry
     */
    private function __construct(
        public string $verdict,
        public string $message = '',
        public ?float $score = null,
    ) {
    }

    public static function passed(): self
    {
        return new self(self::PASSED);
    }

    /**
     * @param string $reason shown to editors in the log of blocked submissions
     * @param null|float $score what a scoring service gave the submission, when it gave one
     */
    public static function spam(string $reason, ?float $score = null): self
    {
        return new self(self::SPAM, $reason, $score);
    }

    /**
     * @param string $message shown to the visitor, in their language
     */
    public static function retry(string $message): self
    {
        return new self(self::RETRY, $message);
    }

    public function isPassed(): bool
    {
        return $this->verdict === self::PASSED;
    }

    public function isSpam(): bool
    {
        return $this->verdict === self::SPAM;
    }

    public function isRetry(): bool
    {
        return $this->verdict === self::RETRY;
    }
}
