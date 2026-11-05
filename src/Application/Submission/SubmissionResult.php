<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Submission;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Symfony\Component\HttpFoundation\Response;

final readonly class SubmissionResult
{
    public function __construct(
        public Submission $submission,
        public bool $spam = false,
        public ?Response $response = null,
    ) {
    }
}
