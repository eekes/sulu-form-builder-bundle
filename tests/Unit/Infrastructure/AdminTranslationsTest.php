<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Infrastructure;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\PlaceholderText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sulu's admin parses every text of the "admin" domain as an ICU message when it starts; a text
 * that is not valid ICU - "{{ email }}" - fills the browser console with errors. A placeholder in
 * an admin text is written as %placeholder:email% instead (see PlaceholderText).
 */
#[CoversClass(PlaceholderText::class)]
final class AdminTranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3).'/translations/admin.*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryAdminTextIsAValidIcuMessage(string $path): void
    {
        $messages = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);

        foreach ($messages as $key => $message) {
            self::assertIsString($message);
            self::assertTrue(self::isIcu($message), \sprintf('"%s" in %s is not a valid ICU message: %s', $key, basename($path), $message));
        }
    }

    private static function isIcu(string $message): bool
    {
        try {
            new \MessageFormatter('en', $message);

            return true;
        } catch (\IntlException) {
            return false;
        }
    }

    public function testAPlaceholderTokenBecomesThePlaceholderEditorsType(): void
    {
        self::assertSame('{{ email }} sends to the visitor.', PlaceholderText::expand('%placeholder:email% sends to the visitor.'));
        self::assertSame("Render it with {{ eekes_form('key') }}.", PlaceholderText::expand("Render it with %placeholder:eekes_form('key')%."));
        self::assertSame("Gibt es mit {{ eekes_form('schlüssel') }} aus.", PlaceholderText::expand("Gibt es mit %placeholder:eekes_form('schlüssel')% aus."));
        self::assertSame('Costs 10%, %unknown% stays.', PlaceholderText::expand('Costs 10%, %unknown% stays.'));
    }
}
