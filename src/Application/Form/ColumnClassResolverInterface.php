<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;

/**
 * Turns the widths an editor picked into the CSS classes of the grid the site uses.
 *
 * The default speaks Bootstrap 5. A site on another grid replaces the service; see
 * docs/rendering.md.
 */
interface ColumnClassResolverInterface
{
    public function resolve(ColumnWidths $width): string;
}
