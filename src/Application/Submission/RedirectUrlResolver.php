<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Submission;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Sulu\Bundle\MarkupBundle\Markup\Link\LinkProviderPoolInterface;
use Symfony\Component\HttpFoundation\UrlHelper;

/**
 * Where to send a visitor after a form that redirects: an external URL wins over a page, and a
 * page is resolved the way a link in rich text is, so it lands in the right language.
 */
final readonly class RedirectUrlResolver
{
    public function __construct(
        private ?LinkProviderPoolInterface $linkProviderPool,
        private UrlHelper $urlHelper,
    ) {
    }

    public function resolve(FormDefinition $definition): ?string
    {
        if ($definition->redirectUrl !== null && $definition->redirectUrl !== '') {
            return $definition->redirectUrl;
        }

        if ($definition->redirectPageUuid === null || $this->linkProviderPool === null || !$this->linkProviderPool->hasProvider('page')) {
            return null;
        }

        foreach ($this->linkProviderPool->getProvider('page')->preload([$definition->redirectPageUuid], $definition->locale) as $item) {
            return $this->urlHelper->getAbsoluteUrl($item->getUrl());
        }

        return null;
    }
}
