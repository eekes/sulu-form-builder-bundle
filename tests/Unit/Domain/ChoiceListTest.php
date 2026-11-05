<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Domain;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\ChoiceList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChoiceList::class)]
final class ChoiceListTest extends TestCase
{
    private const array ENGLISH = [
        ['type' => 'choice', 'label' => 'Sales', 'value' => 'sales'],
        ['type' => 'choice', 'label' => 'Support', 'value' => 'support'],
    ];

    private const array DUTCH = [
        ['type' => 'choice', 'label' => 'Verkoop', 'value' => 'sales'],
        ['type' => 'choice', 'label' => 'Ondersteuning', 'value' => 'support'],
    ];

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'choices' => [self::ENGLISH, true];
        yield 'text' => ['<p>text</p>', false];
        yield 'without values' => [[['label' => 'No value']], false];
        yield 'empty' => [[], false];
    }

    #[DataProvider('candidates')]
    public function testOnlyAListOfChoicesIsOne(mixed $value, bool $isChoiceList): void
    {
        self::assertSame($isChoiceList, ChoiceList::isChoiceList($value));
    }

    public function testAnotherLanguageFollowsTheValuesOrderAndChoicesOfTheEditedOne(): void
    {
        // Edited in English: "support" renamed to "help", "partners" added, the order swapped.
        $edited = [
            ['type' => 'choice', 'label' => 'Help', 'value' => 'help'],
            ['type' => 'choice', 'label' => 'Sales', 'value' => 'sales'],
            ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners'],
        ];
        $before = [self::ENGLISH[1], self::ENGLISH[0]];

        self::assertSame([
            ['type' => 'choice', 'label' => 'Ondersteuning', 'value' => 'help'],
            ['type' => 'choice', 'label' => 'Verkoop', 'value' => 'sales'],
            ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners'],
        ], ChoiceList::follow($edited, $before, self::DUTCH), 'Labels stay translated; a new choice starts with the label it was given.');
    }

    public function testARemovedChoiceIsRemovedInEveryLanguage(): void
    {
        self::assertSame([self::DUTCH[0]], ChoiceList::follow([self::ENGLISH[0]], self::ENGLISH, self::DUTCH));
    }

    public function testValuesAreReadFromOneLanguageForEveryLanguage(): void
    {
        // Stored before values were shared: the Dutch list was given values of its own.
        $dutch = [
            ['type' => 'choice', 'label' => 'Verkoop', 'value' => 'verkoop'],
            ['type' => 'choice', 'label' => 'Ondersteuning', 'value' => 'ondersteuning'],
        ];

        self::assertSame(self::DUTCH, ChoiceList::withSharedValues($dutch, self::ENGLISH), 'A translated copy of the list keeps its labels and takes the values.');
        self::assertSame([...self::DUTCH, ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners']], ChoiceList::withSharedValues(self::DUTCH, [...self::ENGLISH, ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners']]), 'A choice the language does not have yet shows the label of the other.');
    }

    public function testSubFieldsAreFollowedByTheirKey(): void
    {
        $english = [
            ['type' => 'text', 'label' => 'First name', 'key' => 'first_name', 'required' => true],
            ['type' => 'email', 'label' => 'E-mail', 'key' => 'email'],
        ];
        $dutch = [
            ['type' => 'text', 'label' => 'Voornaam', 'key' => 'first_name', 'required' => true],
            ['type' => 'email', 'label' => 'E-mailadres', 'key' => 'email'],
        ];
        // Edited in English: the e-mail address moved up and became required, the key was renamed.
        $edited = [
            ['type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'required' => true],
            ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
        ];

        self::assertSame(ChoiceList::KEY, ChoiceList::identifier($english));
        self::assertSame(ChoiceList::VALUE, ChoiceList::identifier(self::ENGLISH));
        self::assertNull(ChoiceList::identifier('text'));
        self::assertSame([
            ['type' => 'email', 'label' => 'E-mailadres', 'key' => 'email', 'required' => true],
            ['type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
        ], ChoiceList::follow($edited, $english, $dutch, ChoiceList::KEY), 'Only the labels stay Dutch; a renamed key at the same place is not recognised when another key took that place.');
        self::assertSame($dutch, ChoiceList::withSharedValues($dutch, $english, ChoiceList::KEY));
    }
}
