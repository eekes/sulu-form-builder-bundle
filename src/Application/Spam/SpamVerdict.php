<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

/**
 * Which check caught a submission, and why.
 */
final readonly class SpamVerdict
{
    public function __construct(
        public string $check,
        public string $reason,
        public ?float $score = null,
    ) {
    }
}
