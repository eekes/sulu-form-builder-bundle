<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Submission;

use Sulu\Component\Webspace\Analyzer\Attributes\RequestAttributes;
use Sulu\Component\Webspace\Webspace;
use Symfony\Component\HttpFoundation\Request;

/**
 * Where a form was sent from. Every event of a submission carries one, so a listener never has
 * to reach for the request stack - and works the same when a submission is processed without a
 * request, in a test or a command.
 */
final readonly class SubmissionContext
{
    public function __construct(
        public string $locale,
        public ?Request $request = null,
        public ?string $webspaceKey = null,
        public ?string $url = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $attributes = $request->attributes->get('_sulu');
        $webspace = $attributes instanceof RequestAttributes ? $attributes->getAttribute('webspace') : null;

        return new self(
            locale: $request->getLocale(),
            request: $request,
            webspaceKey: $webspace instanceof Webspace ? $webspace->getKey() : null,
            url: $request->getSchemeAndHttpHost().$request->getPathInfo(),
        );
    }
}
