<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Form\BootstrapColumnClassResolver;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootstrapColumnClassResolver::class)]
final class ColumnClassResolverTest extends TestCase
{
    public function testTheBreakpointsAreTheOnesTheBuilderPreviews(): void
    {
        self::assertSame('col-12 col-md-6 col-lg-4 mb-3', (new BootstrapColumnClassResolver())->resolve(new ColumnWidths(4, 6, 12)));
    }

    public function testExtraClassesAreConfigurable(): void
    {
        self::assertSame('col-12 col-md-12 col-lg-12', (new BootstrapColumnClassResolver(''))->resolve(new ColumnWidths()));
    }
}
