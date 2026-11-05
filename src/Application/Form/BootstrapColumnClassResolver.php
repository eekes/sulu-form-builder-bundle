<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;

/**
 * Mobile is the base column, tablet starts at "md" and desktop at "lg" - the same breakpoints the
 * builder previews.
 */
final readonly class BootstrapColumnClassResolver implements ColumnClassResolverInterface
{
    public function __construct(
        private string $extraClasses = 'mb-3',
    ) {
    }

    public function resolve(ColumnWidths $width): string
    {
        return trim(\sprintf('col-%d col-md-%d col-lg-%d %s', $width->mobile, $width->tablet, $width->desktop, $this->extraClasses));
    }
}
