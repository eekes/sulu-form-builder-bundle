<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Domain;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnWidths::class)]
final class ColumnWidthsTest extends TestCase
{
    public function testAFieldSpansTheFullWidthByDefault(): void
    {
        self::assertSame(['desktop' => 12, 'tablet' => 12, 'mobile' => 12], (new ColumnWidths())->toArray());
    }

    public function testTabletFollowsDesktopWhenItWasNeverSet(): void
    {
        $width = ColumnWidths::fromArray(['desktop' => 6]);

        self::assertSame(6, $width->desktop);
        self::assertSame(6, $width->tablet);
        self::assertSame(12, $width->mobile, 'Two fields side by side on a phone are almost never intended.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{desktop: int, tablet: int, mobile: int}}>
     */
    public static function storedWidths(): iterable
    {
        yield 'too wide' => [['desktop' => 20, 'tablet' => 13, 'mobile' => 99], ['desktop' => 12, 'tablet' => 12, 'mobile' => 12]];
        yield 'too narrow' => [['desktop' => 0, 'tablet' => -3, 'mobile' => 0], ['desktop' => 1, 'tablet' => 1, 'mobile' => 1]];
        yield 'numeric strings' => [['desktop' => '4', 'tablet' => '6', 'mobile' => '12'], ['desktop' => 4, 'tablet' => 6, 'mobile' => 12]];
        yield 'garbage' => [['desktop' => 'wide', 'tablet' => null, 'mobile' => []], ['desktop' => 12, 'tablet' => 12, 'mobile' => 12]];
    }

    /**
     * @param array<string, mixed> $stored
     * @param array{desktop: int, tablet: int, mobile: int} $expected
     */
    #[DataProvider('storedWidths')]
    public function testWhatAnEditorStoredIsClampedInsteadOfBreakingTheForm(array $stored, array $expected): void
    {
        self::assertSame($expected, ColumnWidths::fromArray($stored)->toArray());
    }

    public function testCodeCannotCreateAnImpossibleWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ColumnWidths(13);
    }
}
